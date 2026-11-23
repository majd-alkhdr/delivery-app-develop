<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Payment;
use App\Models\PaymentImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * صورة إيصال المندوب (المرحلة 6-D): إلزامية، ورفع آمن.
 * - الحد 3 م.ب، النوع يُفحص بالمحتوى (finfo) لا بالامتداد، وتُعاد ترميزها بـ GD إلى JPEG
 *   (تُحذف البيانات الوصفية ومنها EXIF، والبعد الأقصى 2000 بكسل). بلا GD يُرفض الرفع (لا تخزين خام).
 * - التخزين خاص: storage/app/private/payment-images/YYYY/MM/{uuid}.jpg (قرص local)، ليس public.
 */
class PaymentImageService
{
    public const MAX_BYTES = 3 * 1024 * 1024;

    public const MAX_DIMENSION = 2000;

    public const JPEG_QUALITY = 82;

    public const DISK = 'local';

    private const ACCEPTED = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** آخر ملف كُتب (لتنظيفه إن فشل ما بعده داخل transaction الدفعة) */
    private ?string $lastWritten = null;

    public function lastWrittenPath(): ?string
    {
        return $this->lastWritten;
    }

    public function forgetLastWritten(): void
    {
        $this->lastWritten = null;
    }

    /**
     * التحقق الكامل وإعادة الترميز قبل أي كتابة.
     *
     * @return array{data: string, sha256: string, size: int, width: int, height: int, mime: string}
     */
    public function prepare(?UploadedFile $file): array
    {
        $fail = fn (string $m) => throw ValidationException::withMessages(['image' => $m]);

        if (! $file) {
            $fail('صورة الإيصال مطلوبة، ولا يُحفظ التحصيل بدونها.');
        }
        if (! $file->isValid()) {
            $fail('تعذّر رفع صورة الإيصال. أعد المحاولة.');
        }
        if ($file->getSize() === false || $file->getSize() <= 0 || $file->getSize() > self::MAX_BYTES) {
            $fail('صورة الإيصال يجب ألا تتجاوز 3 ميغابايت.');
        }
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            $fail('لا يمكن معالجة الصور على الخادم حالياً (مكتبة GD غير متوفرة). تواصل مع الإدارة.');
        }

        $raw = (string) file_get_contents($file->getRealPath());
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($raw) ?: '';
        if (! in_array($mime, self::ACCEPTED, true)) {
            $fail('الملف المرفوع ليس صورة صالحة.');
        }
        $info = @getimagesizefromstring($raw);
        $source = $info ? @imagecreatefromstring($raw) : false;
        if (! $info || ! $source) {
            $fail('صورة الإيصال تالفة أو غير صالحة.');
        }

        [$width, $height] = [imagesx($source), imagesy($source)];
        $scale = min(1, self::MAX_DIMENSION / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));

        // إعادة الرسم على خلفية بيضاء (للشفافية) ثم JPEG: لا بيانات وصفية في الناتج
        $canvas = imagecreatetruecolor($w, $h);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $w, $h, $width, $height);
        ob_start();
        imagejpeg($canvas, null, self::JPEG_QUALITY);
        $data = (string) ob_get_clean();
        imagedestroy($canvas);
        imagedestroy($source);
        if ($data === '') {
            $fail('تعذّرت معالجة صورة الإيصال.');
        }

        return ['data' => $data, 'sha256' => hash('sha256', $data), 'size' => strlen($data), 'width' => $w, 'height' => $h, 'mime' => 'image/jpeg'];
    }

    /**
     * كتابة الملف وسجل الصورة لدفعة (يُستدعى داخل transaction الدفعة).
     * عند الفشل يُرمى استثناء ويُحذف الملف المكتوب (والـtransaction يلغي السجلات).
     */
    public function attach(Payment $payment, array $prepared, User $by): PaymentImage
    {
        $path = $this->write($prepared);
        try {
            return PaymentImage::create([
                'payment_id' => $payment->id,
                'path' => $path,
                'sha256' => $prepared['sha256'],
                'size_bytes' => $prepared['size'],
                'mime' => $prepared['mime'],
                'width' => $prepared['width'],
                'height' => $prepared['height'],
                'uploaded_by' => $by->id,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            $this->deleteFile($path);
            throw $e;
        }
    }

    /**
     * استبدال ذرّي قبل التصدير: الجديدة تُكتب وتحلّ محل القديمة في السجل، ثم يُحذف الملف القديم.
     * إن فشل شيء تبقى القديمة كما هي.
     */
    public function replace(Payment $payment, ?UploadedFile $file, User $by): PaymentImage
    {
        if ($payment->source !== 'rep') {
            abort(404);
        }
        if ($payment->isExported() || $payment->isVoided()) {
            throw ValidationException::withMessages(['image' => 'لا يمكن استبدال صورة دفعة مُصدَّرة أو ملغاة.']);
        }
        $prepared = $this->prepare($file);
        $path = $this->write($prepared);

        try {
            [$image, $oldPath, $old] = DB::transaction(function () use ($payment, $prepared, $path, $by) {
                $image = PaymentImage::where('payment_id', $payment->id)->lockForUpdate()->firstOrFail();
                $old = $image->only(['path', 'sha256', 'size_bytes']);
                $image->update([
                    'path' => $path, 'sha256' => $prepared['sha256'], 'size_bytes' => $prepared['size'], 'mime' => $prepared['mime'],
                    'width' => $prepared['width'], 'height' => $prepared['height'], 'uploaded_by' => $by->id, 'created_at' => now(),
                ]);

                return [$image, $old['path'], $old];
            });
        } catch (Throwable $e) {
            $this->deleteFile($path);
            throw $e;
        }

        $this->deleteFile($oldPath);
        ActivityLog::record('rep.image_replaced', $payment, ['sha256' => $old['sha256'], 'size_bytes' => $old['size_bytes']], [
            'sha256' => $image->sha256, 'size_bytes' => $image->size_bytes,
        ], ['payment_id' => $payment->id, 'receipt_number' => $payment->receipt_number]);

        return $image;
    }

    public function deleteFile(?string $path): void
    {
        if ($path) {
            try {
                Storage::disk(self::DISK)->delete($path);
            } catch (Throwable) {
                // لا شيء
            }
        }
    }

    protected function write(array $prepared): string
    {
        $path = 'payment-images/'.now('Asia/Damascus')->format('Y/m').'/'.Str::uuid().'.jpg';
        if (! Storage::disk(self::DISK)->put($path, $prepared['data'])) {
            throw new RuntimeException('تعذّر حفظ صورة الإيصال.');
        }
        $this->lastWritten = $path;

        return $path;
    }
}
