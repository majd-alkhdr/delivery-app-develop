<?php

namespace App\Support;

/**
 * مركز الكلفة بصيغة «كود-اسم» (مثل 655231-كرم الخالدي). المطابقة بالكود بعد التطبيع لا بالاسم.
 */
class CostCenter
{
    /**
     * الكود المطبَّع: أرقام لاتينية، بلا مسافات ولا أحرف اتجاه.
     */
    public static function normalizeCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        $code = strtr($code, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $code = preg_replace('/[\s\x{200E}\x{200F}\x{061C}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', '', $code) ?? '';

        return $code !== '' ? $code : null;
    }

    /**
     * الكود من النص الكامل: ما قبل أول شرطة (بعد التطبيع). null إن لم يوجد كود.
     */
    public static function codeFromLabel(?string $label): ?string
    {
        $label = trim((string) $label);
        if ($label === '' || ! str_contains($label, '-')) {
            return null;
        }

        return self::normalizeCode(explode('-', $label, 2)[0]);
    }

    /**
     * تنويعات الكتابة الممكنة للكود في ملف الحركة (لاتيني/عربي/فارسي) لاستعلام whereIn، ثم تُطابَق بالتطبيع.
     *
     * @return array<int, string>
     */
    public static function codeVariants(string $normalized): array
    {
        $arabic = strtr($normalized, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
        $persian = strtr($normalized, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);

        return array_values(array_unique([$normalized, $arabic, $persian]));
    }
}
