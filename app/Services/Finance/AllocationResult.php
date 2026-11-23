<?php

namespace App\Services\Finance;

/**
 * نتيجة توزيع دفعات زبون على فواتيره في كشف (PaymentAllocationEngine).
 * المبالغ بالليرة (float بخانتين) ومعها نسخة بالقروش (int) للدقة.
 *
 * invoice_rows: [invoice_id, invoice_key, invoice_number, label, series, cost_center, group, group_label,
 *                total, previous, remaining, allocated, unpaid, status, *_cents]
 * excluded_rows: فواتير صفرية/سالبة (لا تدخل التوزيع وتُعرض بلا مبلغ)
 * cost_center_rows: [cost_center, amount, amount_cents] بترتيب مركز الكلفة
 * clipped_rows (المرحلة 7C، allocation_clipped): فواتير وُزِّع عليها سابقاً أكثر من قيمتها الحالية (خُفّضت بتعديل لاحق):
 *               [invoice_id, invoice_number, label, cost_center, total_cents, previous_cents, clipped_cents, clipped]
 *               المحرك يقصّ المتبقي عند الصفر كما كان، والمقصوص يظهر هنا بدل أن يختفي.
 */
final class AllocationResult
{
    public const INCOMPLETE_AWAITING_RATE = 'awaiting_rate';

    public function __construct(
        public readonly int $customerId,
        public readonly array $invoiceRows,
        public readonly array $excludedRows,
        public readonly array $costCenterRows,
        public readonly int $paidCents,
        public readonly int $surplusCents,
        public readonly ?string $surplusCostCenter,
        public readonly string $surplusCostCenterSource,   // chosen | suggested | none
        public readonly ?string $suggestedCostCenter,
        public readonly ?string $incompleteReason,
        public readonly int $awaitingRateCount,
        public readonly array $paymentIds,
        public readonly array $clippedRows = [],
    ) {
    }

    /** المرحلة 7C: هل قصّ المحرك مبلغاً موزَّعاً سابقاً على فاتورة خُفّضت؟ */
    public function allocationClipped(): bool
    {
        return $this->clippedRows !== [];
    }

    public function clippedCents(): int
    {
        return array_sum(array_column($this->clippedRows, 'clipped_cents'));
    }

    public function totalPaidSyp(): float
    {
        return $this->paidCents / 100;
    }

    public function surplus(): float
    {
        return $this->surplusCents / 100;
    }

    public function allocatedCents(): int
    {
        return array_sum(array_column($this->invoiceRows, 'allocated_cents'));
    }

    public function totalUnpaidCents(): int
    {
        return array_sum(array_column($this->invoiceRows, 'unpaid_cents'));
    }

    public function totalUnpaidSyp(): float
    {
        return $this->totalUnpaidCents() / 100;
    }

    public function isIncomplete(): bool
    {
        return $this->incompleteReason !== null;
    }

    public function hasPayments(): bool
    {
        return $this->paymentIds !== [];
    }

    /** دفع ولا فواتير له في الكشف (دفعة خارجية) */
    public function isExternalOnly(): bool
    {
        return $this->hasPayments() && $this->invoiceRows === [] && $this->excludedRows === [];
    }

    /**
     * المانعات التي تمنع التصدير لهذا الزبون.
     *
     * @return array<int, string> awaiting_rate | surplus_without_cost_center | allocation_without_cost_center
     */
    public function blockers(): array
    {
        $blockers = [];
        if ($this->awaitingRateCount > 0) {
            $blockers[] = 'awaiting_rate';
        }
        if ($this->surplusCents > 0 && $this->surplusCostCenter === null) {
            $blockers[] = 'surplus_without_cost_center';
        }
        foreach ($this->invoiceRows as $row) {
            if ($row['allocated_cents'] > 0 && $row['cost_center'] === null) {
                $blockers[] = 'allocation_without_cost_center';
                break;
            }
        }

        return $blockers;
    }
}
