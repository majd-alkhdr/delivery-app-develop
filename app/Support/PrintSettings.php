<?php

namespace App\Support;

use App\Models\Setting;

/**
 * إعدادات الطباعة: الأنواع والمجالات والقيم الافتراضية في مكان واحد
 * (شاشة الإعدادات، والتحقق، واستجابة GET /print/config).
 */
class PrintSettings
{
    /** المفتاح => [النوع، الافتراضي، الحد الأدنى، الحد الأعلى، التسمية] */
    public const FIELDS = [
        'printing_enabled' => ['bool', false, null, null, 'تفعيل الطباعة'],
        'print_paper_dots' => ['int', 576, 200, 832, 'عرض الصورة بالنقاط (80 مم ≈ 576، 58 مم ≈ 384)'],
        'print_chunk_bytes' => ['int', 100, 20, 512, 'حجم الحزمة لكل كتابة BLE (بايت)'],
        'print_chunk_delay_ms' => ['int', 20, 0, 300, 'التأخير بين الحزم (ملي ثانية)'],
        'print_slice_rows' => ['int', 128, 8, 512, 'ارتفاع شريحة الصورة (صفوف)'],
        'print_threshold' => ['int', 160, 1, 254, 'عتبة الأبيض/الأسود'],
        'print_feed_lines' => ['int', 4, 0, 20, 'أسطر فارغة بعد الطباعة'],
        'print_cut' => ['bool', false, null, null, 'قص جزئي بعد الطباعة (GS V 1)'],
        'print_header_title' => ['string', 'مستودع نيو هبة للأدوية', 1, 100, 'العنوان أعلى الإيصال والفاتورة'],
        'print_footer_text' => ['string', 'شكراً لتعاملكم معنا', 0, 200, 'الذيل'],
        'print_mark_duplicates' => ['bool', true, null, null, 'وسم «نسخة مكررة»'],
    ];

    /**
     * @return array<string, mixed> القيم الحالية (مع الافتراضي إن غابت)
     */
    public static function values(): array
    {
        $values = [];
        foreach (self::FIELDS as $key => [$type, $default]) {
            $value = Setting::get($key, $default);
            $values[$key] = match ($type) {
                'bool' => (bool) $value,
                'int' => (int) $value,
                default => (string) $value,
            };
        }

        return $values;
    }

    /**
     * @return array<string, array{min: int, max: int}> مجالات الأرقام (لتحقق الواجهة وصفحة الاختبار)
     */
    public static function ranges(): array
    {
        $ranges = [];
        foreach (self::FIELDS as $key => [$type, , $min, $max]) {
            if ($type === 'int') {
                $ranges[$key] = ['min' => $min, 'max' => $max];
            }
        }

        return $ranges;
    }

    /**
     * قواعد التحقق لنموذج قسم «الطباعة».
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        $rules = [];
        foreach (self::FIELDS as $key => [$type, , $min, $max]) {
            $rules[$key] = match ($type) {
                'bool' => ['nullable', 'boolean'],
                'int' => ['required', 'integer', "min:{$min}", "max:{$max}"],
                default => [$min > 0 ? 'required' : 'nullable', 'string', "max:{$max}"],
            };
        }

        return $rules;
    }

    public static function label(string $key): string
    {
        return self::FIELDS[$key][4] ?? $key;
    }
}
