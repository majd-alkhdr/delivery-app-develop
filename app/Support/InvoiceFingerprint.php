<?php

namespace App\Support;

/**
 * هوية الفاتورة وبصمة محتواها.
 * - المفتاح (invoice_key): السلسلة + الرقم بعد التطبيع، مثل "g:12830". لا يتكرر أبداً.
 * - البصمة (content_hash): sha256 لمحتوى الفاتورة لاكتشاف تعديلها بين ملف وآخر.
 */
class InvoiceFingerprint
{
    /**
     * سلسلة الفاتورة ورقمها من صيغ مثل "ع G: 12830" و"ارجيفيت: 2571".
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function split(string $invoiceNumber): array
    {
        $text = trim($invoiceNumber);
        $text = preg_replace('/^ع\s+/u', '', $text);

        if (! str_contains($text, ':')) {
            return [null, $text !== '' ? $text : null];
        }

        [$series, $number] = explode(':', $text, 2);
        $series = trim($series);

        return [$series !== '' ? $series : null, trim($number) !== '' ? trim($number) : null];
    }

    public static function key(?string $series, ?string $number): ?string
    {
        $number = $number !== null ? ArabicNormalizer::normalize($number) : '';
        if ($number === '') {
            return null;
        }

        return mb_substr(ArabicNormalizer::normalize($series ?? '').':'.$number, 0, 191);
    }

    public static function keyFromNumber(string $invoiceNumber): ?string
    {
        [$series, $number] = self::split($invoiceNumber);

        return self::key($series, $number);
    }

    /**
     * @param  array<int, array{item_name: string, quantity: mixed, gift_quantity: mixed, unit_price: mixed, line_total: mixed}>  $lines
     */
    public static function hash(
        string $customerName,
        ?string $invoiceDate,
        ?string $deliveryDate,
        ?string $deliveryOrder,
        ?string $costCenterCode,
        ?string $costCenterName,
        array $lines
    ): string {
        $normalizedLines = array_map(function ($line) {
            $normalized = [
                ArabicNormalizer::normalize((string) $line['item_name']),
                sprintf('%.3f', (float) $line['quantity']),
                sprintf('%.3f', (float) $line['gift_quantity']),
                sprintf('%.2f', (float) $line['unit_price']),
                sprintf('%.2f', (float) $line['line_total']),
            ];
            // المرحلة 6B: الحسم يدخل البصمة فقط حين يوجد فعلاً (> 0)، فبصمات الفواتير القديمة لا تتغير
            if ((float) ($line['discount_percent'] ?? 0) > 0) {
                $normalized[] = 'd'.sprintf('%.3f', (float) $line['discount_percent']);
            }

            return $normalized;
        }, $lines);
        sort($normalizedLines);

        $costCenter = trim(($costCenterCode ?? '').'|'.($costCenterName ?? ''), '|');

        return hash('sha256', json_encode([
            ArabicNormalizer::normalize($customerName),
            $invoiceDate ? substr($invoiceDate, 0, 10) : null,
            $deliveryDate ? substr($deliveryDate, 0, 10) : null,
            self::order($deliveryOrder),
            ArabicNormalizer::normalize($costCenter),
            $normalizedLines,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * رقم أمر التسليم: 0 والفراغ تعني "بلا أمر" (null).
     */
    public static function order(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_float($value) && floor($value) == $value) {
            $value = (int) $value;
        }
        $text = trim((string) $value);

        return ($text === '' || $text === '0') ? null : $text;
    }
}
