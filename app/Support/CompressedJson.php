<?php

namespace App\Support;

/**
 * المرحلة 7F: JSON مضغوط (gzip + base64) لنسخ التدقيق الكبيرة (آلاف الصفوف في عمود نصي واحد).
 */
class CompressedJson
{
    public static function encode(mixed $data): string
    {
        return base64_encode(gzcompress(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), 6));
    }

    public static function decode(?string $payload): mixed
    {
        if ($payload === null || $payload === '') {
            return null;
        }
        $raw = gzuncompress(base64_decode($payload, true) ?: '');

        return $raw === false ? null : json_decode($raw, true);
    }
}
