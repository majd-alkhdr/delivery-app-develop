<?php

namespace Tests\Feature\Phase6;

use App\Models\ActivityLog;
use App\Models\Payment;
use App\Models\PaymentImage;
use App\Models\PharmacyLock;
use App\Models\SheetVisit;
use App\Models\User;
use App\Services\PaymentImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 6-C/D: دفعة المندوب (رقم الدفتر، السعر، idempotency، لا قفل ولا زيارة) وصورة الإيصال الإلزامية.
 */
class RepPaymentImageTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity, RepFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReps();
    }

    private function storedFiles(): array
    {
        return Storage::disk('local')->allFiles('payment-images');
    }

    private function assertInvariant(): void
    {
        $this->assertSame(0, Payment::where('source', 'rep')->whereDoesntHave('image')->count(), 'كل دفعة مندوب لها صورة');
        $this->assertSame(PaymentImage::count(), count($this->storedFiles()), 'لكل سجل صورة ملف واحد ولا ملفات يتيمة');
    }

    // ------------------------------------------------------------------
    // الدفعة
    // ------------------------------------------------------------------

    public function test_valid_rep_payment_creates_payment_and_image_together(): void
    {
        $response = $this->repPay($this->rep1, $this->north, 1500, ['book_receipt_no' => '١٢٣', 'latitude' => 33.5, 'longitude' => 36.3])->assertCreated();

        $payment = Payment::sole();
        $this->assertSame('rep', $payment->source);
        $this->assertNull($payment->distribution_sheet_id);
        $this->assertSame('123', $payment->book_receipt_no);
        $this->assertSame('655231', $payment->cost_center_code);
        $this->assertSame('655231-كرم الخالدي', $payment->cost_center_label);
        $this->assertSame('none', $payment->fresh()->review_status);
        $this->assertSame('pending', $payment->status);
        $this->assertNotNull($payment->location_flag);
        $this->assertNull($response->json('warning'));   // لا تحذير «بعيد» للمندوب

        $image = $payment->image;
        $this->assertNotNull($image);
        $this->assertSame('image/jpeg', $image->mime);
        $this->assertStringStartsWith('payment-images/', $image->path);
        Storage::disk('local')->assertExists($image->path);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($image->path)), $image->sha256);
        // تخزين خاص (قرص local = storage/app/private)، ليس public
        $this->assertStringContainsString('private', str_replace('\\', '/', config('filesystems.disks.local.root')));

        // لا قفل صيدلية ولا زيارة للمندوب
        $this->assertSame(0, PharmacyLock::whereNotNull('user_id')->where('user_id', $this->rep1->id)->count());
        $this->assertSame(0, SheetVisit::where('user_id', $this->rep1->id)->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'payment.created', 'subject_id' => $payment->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'rep.image_uploaded', 'subject_id' => $payment->id]);
        $this->assertInvariant();
    }

    public function test_book_receipt_number_is_required_and_duplicates_need_confirmation(): void
    {
        $this->repPay($this->rep1, $this->north, 100, ['book_receipt_no' => ''])->assertStatus(422)->assertJsonValidationErrors('book_receipt_no');

        $this->repPay($this->rep1, $this->north, 100, ['book_receipt_no' => '77'])->assertCreated();
        $uuid = (string) Str::uuid();
        $this->repPay($this->rep1, $this->north, 50, ['client_uuid' => $uuid, 'book_receipt_no' => '77'])
            ->assertStatus(422)->assertJsonPath('requires_confirmation', true)->assertJsonPath('confirm_field', 'confirm_duplicate_book_no');
        $this->assertSame(1, Payment::count());

        $this->repPay($this->rep1, $this->north, 50, ['client_uuid' => $uuid, 'book_receipt_no' => '77', 'confirm_duplicate_book_no' => 1])->assertCreated();
        $this->assertTrue(Payment::where('client_uuid', $uuid)->sole()->duplicate_book_no);

        // رقم الدفتر نفسه لمندوب آخر ليس تكراراً
        $this->repPay($this->rep2, $this->east, 10, ['book_receipt_no' => '77'])->assertCreated();
        $this->assertInvariant();
    }

    public function test_usd_rate_is_mandatory_and_out_of_range_needs_confirmation(): void
    {
        $this->repPay($this->rep1, $this->north, 10, ['currency' => 'USD'])->assertStatus(422)->assertJsonValidationErrors('exchange_rate');
        $this->repPay($this->rep1, $this->north, 10, ['currency' => 'USD', 'exchange_rate' => 900])->assertStatus(422)->assertJsonPath('requires_confirmation', true);
        $this->repPay($this->rep1, $this->north, 10, ['currency' => 'USD', 'exchange_rate' => 139])->assertCreated();
        $this->assertSame('1390.00', Payment::sole()->amount_syp);
        $this->assertSame(0, count($this->storedFiles()) - 1);
    }

    public function test_idempotent_by_client_uuid_without_new_payment_or_image(): void
    {
        $uuid = (string) Str::uuid();
        $this->repPay($this->rep1, $this->north, 100, ['client_uuid' => $uuid, 'book_receipt_no' => '5'])->assertCreated();
        $this->repPay($this->rep1, $this->north, 100, ['client_uuid' => $uuid, 'book_receipt_no' => '5'])->assertOk()->assertJsonPath('duplicate', true);
        // إعادة إرسال المتابعة بلا صورة (طلب تثبيت الموقع) تعيد الموجودة كذلك
        $this->repPay($this->rep1, $this->north, 100, ['client_uuid' => $uuid, 'book_receipt_no' => '5'], false)->assertOk();

        $this->assertSame(1, Payment::count());
        $this->assertSame(1, PaymentImage::count());
        $this->assertInvariant();
    }

    public function test_no_lock_conflict_with_distributor_on_same_pharmacy_same_day(): void
    {
        $this->sheetC->update(['distribution_date' => now('Asia/Damascus')->toDateString(), 'status' => 'in_progress']);
        $this->payApi($this->d1, $this->sheetC, $this->north->id, 100)->assertCreated();
        $this->assertSame($this->d1->id, PharmacyLock::where('customer_id', $this->north->id)->value('user_id'));

        $this->repPay($this->rep1, $this->north, 100)->assertCreated();
        $this->assertSame(2, Payment::count());
    }

    public function test_far_flag_is_saved_but_not_shown_and_locations_screen_excludes_reps_by_default(): void
    {
        $this->north->update(['latitude' => 33.5, 'longitude' => 36.3, 'location_confirmed' => true, 'location_source' => 'supervisor']);
        $response = $this->repPay($this->rep1, $this->north, 100, ['latitude' => 34.5, 'longitude' => 37.3])->assertCreated();
        $this->assertNull($response->json('warning'));
        $this->assertSame('far', Payment::sole()->location_flag);

        $this->actingAs($this->supervisor)->get(route('supervisor.far-visits'))->assertOk()->assertSee('الدفعات (0)');
        // المرحلة 8C: أُلغي خيار «تضمين المندوبين»: المعامل القديم لا يفعل شيئاً
        $this->actingAs($this->supervisor)->get(route('supervisor.far-visits', ['include_reps' => 1]))->assertOk()->assertSee('الدفعات (0)')->assertDontSee('تضمين المندوبين');
    }

    public function test_rep_can_void_own_payment_same_day_before_export(): void
    {
        $id = $this->repPay($this->rep1, $this->north, 100)->json('payment.id');
        $this->actingAs($this->rep2)->postJson(route('payments.void', $id), ['reason' => 'خطأ إدخال'])->assertForbidden();
        $this->actingAs($this->rep1)->postJson(route('payments.void', $id), ['reason' => 'خطأ إدخال'])->assertOk();
        $this->assertNotNull(Payment::find($id)->voided_at);
    }

    // ------------------------------------------------------------------
    // الصورة
    // ------------------------------------------------------------------

    public function test_missing_image_is_rejected_and_nothing_is_created(): void
    {
        $this->repPay($this->rep1, $this->north, 100, [], false)->assertStatus(422)->assertJsonValidationErrors('image');
        $this->assertSame(0, Payment::count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_invalid_images_are_rejected_without_leftovers(): void
    {
        $text = UploadedFile::fake()->createWithContent('receipt.jpg', 'هذا ملف نصي وليس صورة');
        $this->repPay($this->rep1, $this->north, 100, ['image' => $text])->assertStatus(422)->assertJsonValidationErrors('image');

        $source = $this->receiptImage();
        $jpeg = file_get_contents($source->getRealPath());
        $broken = UploadedFile::fake()->createWithContent('broken.jpg', substr($jpeg, 0, 120));
        $this->repPay($this->rep1, $this->north, 100, ['image' => $broken])->assertStatus(422)->assertJsonValidationErrors('image');

        $big = UploadedFile::fake()->create('big.jpg', 3500, 'image/jpeg');
        $this->repPay($this->rep1, $this->north, 100, ['image' => $big])->assertStatus(422)->assertJsonValidationErrors('image');

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, PaymentImage::count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_storage_failure_inside_transaction_cancels_the_payment(): void
    {
        $this->app->instance(PaymentImageService::class, new class extends PaymentImageService {
            public function attach(\App\Models\Payment $payment, array $prepared, \App\Models\User $by): \App\Models\PaymentImage
            {
                parent::attach($payment, $prepared, $by);
                throw new RuntimeException('فشل تخزين مصطنع');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->repPay($this->rep1, $this->north, 100);
            $this->fail('كان يجب أن يفشل');
        } catch (RuntimeException $e) {
            $this->assertSame('فشل تخزين مصطنع', $e->getMessage());
        }

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, PaymentImage::count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_image_is_reencoded_without_exif_and_bounded(): void
    {
        // JPEG مع مقطع EXIF (APP1) مُحقن بعد SOI
        $source = UploadedFile::fake()->image('big.jpg', 3000, 1500);
        $jpeg = file_get_contents($source->getRealPath());
        $exif = "Exif\0\0".str_repeat('GPS-SECRET-', 20);
        $withExif = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
        $file = UploadedFile::fake()->createWithContent('exif.jpg', $withExif);

        $this->repPay($this->rep1, $this->north, 100, ['image' => $file])->assertCreated();
        $image = PaymentImage::sole();
        $stored = Storage::disk('local')->get($image->path);
        $this->assertStringNotContainsString('Exif', $stored);
        $this->assertStringNotContainsString('GPS-SECRET', $stored);
        $this->assertSame(2000, max($image->width, $image->height));
        $this->assertSame(1000, $image->height);
        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->buffer($stored));
    }

    public function test_replace_before_export_is_atomic_and_after_export_is_rejected(): void
    {
        $id = $this->repPay($this->rep1, $this->north, 100)->json('payment.id');
        $old = PaymentImage::sole();

        // صورة جديدة غير صالحة: تبقى القديمة كما هي
        $this->actingAs($this->rep1)->post(route('rep.payments.image', $id), ['image' => UploadedFile::fake()->createWithContent('x.jpg', 'nope')], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertSame($old->path, PaymentImage::sole()->path);
        Storage::disk('local')->assertExists($old->path);

        // مندوب آخر وموزع ممنوعان، والمحاسب مسموح
        $this->actingAs($this->rep2)->post(route('rep.payments.image', $id), ['image' => $this->receiptImage()], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($this->d1)->post(route('rep.payments.image', $id), ['image' => $this->receiptImage()], ['Accept' => 'application/json'])->assertForbidden();

        $this->actingAs($this->rep1)->post(route('rep.payments.image', $id), ['image' => $this->receiptImage('new.jpg', 640, 480)], ['Accept' => 'application/json'])->assertOk();
        $new = PaymentImage::sole();
        $this->assertNotSame($old->path, $new->path);
        Storage::disk('local')->assertMissing($old->path);
        Storage::disk('local')->assertExists($new->path);
        $this->assertDatabaseHas('activity_logs', ['action' => 'rep.image_replaced', 'subject_id' => $id]);

        $this->actingAs($this->accountant)->post(route('rep.payments.image', $id), ['image' => $this->receiptImage()], ['Accept' => 'application/json'])->assertOk();

        $this->repExport($this->accountant, [$this->rep1->id])->assertSessionHasNoErrors();
        $this->actingAs($this->rep1)->post(route('rep.payments.image', $id), ['image' => $this->receiptImage()], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertInvariant();
    }

    public function test_image_endpoint_authorization_and_headers(): void
    {
        $id = $this->repPay($this->rep1, $this->north, 100)->json('payment.id');

        foreach ([$this->accountant, $this->repSupervisor, $this->admin, $this->rep1] as $user) {
            $response = $this->actingAs($user)->get(route('payments.image', $id))->assertOk();
            $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        }
        foreach ([$this->rep2, $this->d1, $this->manager, $this->supervisor] as $user) {
            $this->actingAs($user)->get(route('payments.image', $id))->assertForbidden();
        }
    }

    public function test_log_labels_and_supervisor_prefixes(): void
    {
        // المرحلة 8C: أُلغيت قاعدة بادئات الأحداث (prefixesFor)؛ الرؤية بالمنفّذ (ActivityLogVisibility، اختبارات Phase8C)
        foreach (['rep.cost_center_set', 'rep.image_uploaded', 'rep.image_replaced', 'rep.payment_reviewed', 'finance.rep_exported',
            'finance.rep_export_downloaded', 'finance.rep_export_override', 'finance.rep_receivables_exported'] as $action) {
            $this->assertArrayHasKey($action, ActivityLog::ACTIONS);
        }
        $this->assertInstanceOf(User::class, $this->rep1);
    }
}
