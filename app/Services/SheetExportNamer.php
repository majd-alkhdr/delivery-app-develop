<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\DistributionSheet;
use App\Models\User;

/**
 * اسم ملف تصدير الكشف (ملحق 2C):
 * - المحاسب: «{اسم الموزع} - {رقم أمر التسليم}.xlsx».
 * - المشرف ومسؤول الكشوف والأدمن: «{اسم الكتلة أو المنطقة} - {رقم أمر التسليم}.xlsx».
 * كل مسارات التصدير تستخدم هذه الخدمة وحدها.
 *
 * $scope:
 *   - distributor: ?User      التصدير مصفّى بموزع
 *   - area_key:    ?string    التصدير مصفّى بمنطقة
 *   - area_label:  ?string    اسم تلك المنطقة
 *   - rows:        array<int, array{block: ?string, area: ?string}>  صفوف الملف بترتيبها (المنطقة ثم الاسم)
 */
class SheetExportNamer
{
    public const MAX_LENGTH = 120;

    private const RESERVED = ['CON', 'PRN', 'AUX', 'NUL',
        'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
        'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9'];

    public function fileName(DistributionSheet $sheet, User $user, array $scope, ?string $suffix = null): string
    {
        return $this->baseName($sheet, $user, $scope, $suffix).'.xlsx';
    }

    /**
     * ملفات تصدير المندوبين (المرحلة 6): «{اسم المندوب أو مندوبون} - م-{seq} - {اللاحقة}.xlsx».
     *
     * @param  array<int, string>  $repNames
     */
    public function repFileName(array $repNames, int $sequence, string $suffix): string
    {
        $names = array_values(array_filter(array_map(fn ($n) => $this->sanitize((string) $n), $repNames), fn ($n) => $n !== ''));
        $first = count($names) === 1 ? $names[0] : 'مندوبون';
        $tail = ' - م-'.$sequence.' - '.$this->sanitize($suffix);

        $room = max(1, self::MAX_LENGTH - mb_strlen($tail));
        if (mb_strlen($first) > $room) {
            $first = rtrim(mb_substr($first, 0, $room), ' .') ?: 'مندوبون';
        }
        if (in_array(mb_strtoupper($first), self::RESERVED, true)) {
            $first .= '_';
        }

        return $first.$tail.'.xlsx';
    }

    /**
     * بديل ASCII لملفات المندوبين: reps_{seq}_{kind}.xlsx
     */
    public function repAsciiFallback(int $sequence, string $kind): string
    {
        return 'reps_'.$sequence.'_'.preg_replace('/[^A-Za-z0-9_-]/', '', $kind).'.xlsx';
    }

    /**
     * بديل ASCII آمن لترويسة Content-Disposition: sheet_{رقم الأمر}.xlsx
     */
    public function asciiFallback(DistributionSheet $sheet, ?string $suffix = null): string
    {
        $order = preg_replace('/[^A-Za-z0-9-]/', '', (string) $sheet->delivery_order_no);
        $suffix = $suffix !== null ? preg_replace('/[^A-Za-z0-9_-]/', '', $suffix) : '';

        return 'sheet_'.($order !== '' ? $order : $sheet->id).($sheet->isSupplement() ? '_s'.$sheet->supplement_no : '').($suffix !== '' ? '_'.$suffix : '').'.xlsx';
    }

    /**
     * الجزء الأول كما يُعرض داخل الملف: ['kind' => 'الموزع' | 'الكتلة/المنطقة', 'text' => ...]
     *
     * @return array{kind: string, text: string}
     */
    public function label(DistributionSheet $sheet, User $user, array $scope): array
    {
        if ($user->role === Role::Accountant) {
            $names = $this->distributorNames($sheet, $scope);
            if ($names !== null) {
                return ['kind' => 'الموزع', 'text' => $names];
            }
        }

        return ['kind' => 'الكتلة/المنطقة', 'text' => $this->blockOrArea($scope)];
    }

    /**
     * رقم أمر التسليم، أو #{id} إن كان فارغاً.
     */
    public function orderPart(DistributionSheet $sheet): string
    {
        $order = $this->sanitize((string) $sheet->delivery_order_no);

        // المرحلة 7H: «1580 — ملحق 1» (الاسم المعروض الواحد)
        return ($order !== '' ? $order : '#'.$sheet->id).($sheet->isSupplement() ? ' — ملحق '.$sheet->supplement_no : '');
    }

    private function baseName(DistributionSheet $sheet, User $user, array $scope, ?string $extra = null): string
    {
        $order = $this->orderPart($sheet);
        // لاحقة اختيارية بعد رقم الأمر (ملفات المالية: «ملف الاستيراد»، «كشف المقبوضات»، «الدولار»)
        $extra = $extra !== null ? $this->sanitize($extra) : '';
        $suffix = ' - '.$order.($extra !== '' ? ' - '.$extra : '');

        $first = $this->sanitize($this->label($sheet, $user, $scope)['text']);
        if ($first === '') {
            $first = 'كشف';
        }

        // الحد الأقصى للاسم الكامل بلا الامتداد: يُقتطع الجزء الأول لا رقم الأمر
        $room = max(1, self::MAX_LENGTH - mb_strlen($suffix));
        if (mb_strlen($first) > $room) {
            $first = rtrim(mb_substr($first, 0, $room), ' .');
            if ($first === '') {
                $first = 'كشف';
            }
        }

        if (in_array(mb_strtoupper($first), self::RESERVED, true)) {
            $first .= '_';
        }

        return $first.$suffix;
    }

    /**
     * أسماء الموزعين للمحاسب: الموزع المصفّى وحده، وإلا المسندون (اثنان بـ « + »، وأكثر: الأول والثاني ثم +N).
     * null = لا يوجد إسناد (فتُستخدم قاعدة الكتلة/المنطقة).
     */
    private function distributorNames(DistributionSheet $sheet, array $scope): ?string
    {
        if (($scope['distributor'] ?? null) instanceof User) {
            return $scope['distributor']->name;
        }

        $sheet->loadMissing('assignments.distributor');
        $names = $sheet->assignments
            ->map(fn ($a) => $a->distributor?->name)
            ->filter(fn ($n) => trim((string) $n) !== '')
            ->unique()
            ->values();

        if ($names->isEmpty()) {
            return null;
        }
        if ($names->count() > 2) {
            return $names[0].' + '.$names[1].' +'.($names->count() - 2);
        }

        return $names->implode(' + ');
    }

    /**
     * منطقة التصفية إن وُجدت؛ وإلا أول كتلة غير فارغة بترتيب صفوف الملف، ثم أول منطقة، ثم «بلا منطقة».
     */
    private function blockOrArea(array $scope): string
    {
        if (! empty($scope['area_key'])) {
            return (string) ($scope['area_label'] ?? 'بلا منطقة');
        }

        $rows = $scope['rows'] ?? [];
        foreach ($rows as $row) {
            if (trim((string) ($row['block'] ?? '')) !== '') {
                return (string) $row['block'];
            }
        }
        foreach ($rows as $row) {
            if (trim((string) ($row['area'] ?? '')) !== '') {
                return (string) $row['area'];
            }
        }

        return 'بلا منطقة';
    }

    /**
     * تنظيف جزء من اسم الملف: لا أحرف ممنوعة ولا أحرف تحكم ولا علامات اتجاه ولا مسارات.
     */
    public function sanitize(string $value): string
    {
        // أحرف تحكم + علامات الاتجاه (LRM/RLM/ALM، والتضمين والعزل)
        $value = preg_replace('/[\x{0000}-\x{001F}\x{007F}\x{200E}\x{200F}\x{061C}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $value) ?? '';
        // أحرف غير صالحة في أسماء ملفات Windows
        $value = str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|'], ' ', $value);
        // لا ".." (حقن مسار)
        $value = preg_replace('/\.{2,}/', '.', $value) ?? '';
        // طيّ المسافات وقطع المسافات والنقاط من الطرفين
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return trim($value, " .\t");
    }
}
