<?php

namespace App\Support;

/**
 * تحويل مدخلات الأرقام من المستخدم: الأرقام العربية والفارسية، وفواصل الآلاف، والفاصلة العشرية العربية.
 */
class Numbers
{
    public static function parse(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (! is_string($value)) {
            return null;
        }

        $text = strtr(trim($value), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٫' => '.', '٬' => '', ',' => '', ' ' => '', "\u{00A0}" => '',
        ]);

        return ($text !== '' && is_numeric($text)) ? (float) $text : null;
    }
}
