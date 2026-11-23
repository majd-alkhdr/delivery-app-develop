<?php

namespace App\Services;

/**
 * الرصيد المعروض لزبون (المرحلة 7B): رقم واحد في كل الشاشات مع شرحه.
 * net = raw (رصيد المحاسبة) − deferred (طلبيات مؤجلة) − unreflected_payments (دفعات لم تنعكس بعد في ملف أرصدة).
 */
final class CurrentBalance
{
    public function __construct(
        public readonly int $customerId,
        public readonly float $raw,
        public readonly float $deferred,
        public readonly float $unreflectedPayments,
        public readonly float $net,
        public readonly ?string $asOf,
    ) {
    }

    /**
     * «رصيد المحاسبة 5,000 بتاريخ 2026-10-13، مؤجل 0، دفعات لم تنعكس 900 ← 4,100»
     */
    public function breakdown(): string
    {
        return 'رصيد المحاسبة '.self::money($this->raw).' بتاريخ '.($this->asOf ?? 'غير محدد')
            .'، مؤجل '.self::money($this->deferred)
            .'، دفعات لم تنعكس '.self::money($this->unreflectedPayments)
            .' ← '.self::money($this->net);
    }

    /**
     * شرح للموزع والمندوب بلا الرصيد الخام ولا المؤجل (قاعدة الخصوصية منذ المرحلة 2):
     * «الرصيد المستحق 5,000 بتاريخ 2026-10-13، دفعات لم تنعكس 900 ← 4,100»
     */
    public function publicBreakdown(): string
    {
        return 'الرصيد المستحق '.self::money(round($this->raw - $this->deferred, 2)).' بتاريخ '.($this->asOf ?? 'غير محدد')
            .'، دفعات لم تنعكس '.self::money($this->unreflectedPayments)
            .' ← '.self::money($this->net);
    }

    /**
     * «الرصيد حتى 13-10»
     */
    public function asOfLabel(): string
    {
        return $this->asOf ? 'الرصيد حتى '.\Carbon\Carbon::parse($this->asOf)->format('d-m') : 'لا تاريخ للأرصدة';
    }

    public function toArray(): array
    {
        return [
            'raw' => $this->raw,
            'deferred' => $this->deferred,
            'unreflected_payments' => $this->unreflectedPayments,
            'net' => $this->net,
            'as_of' => $this->asOf,
            'breakdown' => $this->breakdown(),
        ];
    }

    private static function money(float $value): string
    {
        return number_format($value, (abs($value - round($value)) < 0.005) ? 0 : 2);
    }
}
