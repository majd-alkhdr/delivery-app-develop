<?php

namespace App\Services;

use App\Models\DistributionSheet;
use App\Models\InvoiceSeries;
use App\Models\User;
use App\Support\ArabicNormalizer;
use App\Support\InvoiceFingerprint;
use Illuminate\Support\Collection;

/**
 * «الفاتورة المجمّعة» لصيدلية في كشف: تخطيط طباعة فقط (لا تُخزَّن، ولا تغيّر أي فاتورة أو رصيد).
 * المصدر: فواتير الزبون المرئية للموزع عبر DistributionSheet::invoicesVisibleTo() حصراً.
 * دمج الأسطر بالمفتاح (الصنف + الإفرادي + هل هو هدية)، والذيل بالرصيد المستحق وحده (لا الخام).
 */
class CustomerMergedInvoiceBuilder
{
    public function __construct(private CustomerBalanceService $balances)
    {
    }

    /**
     * @param  User|null  $viewer  من يرى الفواتير (افتراضياً المستخدم الحالي)
     */
    public function build(DistributionSheet $sheet, int $customerId, ?User $viewer = null): array
    {
        $viewer ??= auth()->user();

        $invoices = $sheet->invoicesVisibleTo($viewer)
            ->where('customer_id', $customerId)
            ->where(fn ($q) => $q->whereNull('invoice_pattern')->orWhere('invoice_pattern', '!=', 'external'))
            ->with(['lines', 'customer'])
            ->orderBy('id')
            ->get();

        $customer = $invoices->first()?->customer;
        // المرحلة 7B: الرصيد المعروض الواحد (رصيد المحاسبة − المؤجل − الدفعات غير المنعكسة)
        $due = $customer ? $this->balances->currentBalance($customer, $sheet)->net : 0.0;

        return $this->fromInvoices($sheet, $customerId, $invoices, $due);
    }

    /**
     * من فواتير محمّلة مسبقاً بأسطرها (يستخدمه endpoint الأسطر لتفادي استعلام لكل زبون).
     */
    public function fromInvoices(DistributionSheet $sheet, int $customerId, Collection $invoices, float $dueBalance): array
    {
        $first = $invoices->first();

        $merged = [];
        foreach ($invoices as $invoice) {
            foreach ($invoice->lines as $line) {
                $isGift = abs((float) $line->line_total) < 0.005;
                $unit = round((float) $line->unit_price, 2);
                // المرحلة 6B: سطران بحسمين مختلفين لا يُدمجان
                $percent = round((float) ($line->discount_percent ?? 0), 3);
                $key = ArabicNormalizer::normalize((string) $line->item_name).'|'.number_format($unit, 2, '.', '').'|'.($isGift ? 'g' : 'p').'|'.number_format($percent, 3, '.', '');

                $merged[$key] ??= [
                    'item_name' => $line->item_name,
                    'item_group' => $line->item_group,
                    'unit_price' => $unit,
                    'is_gift' => $isGift,
                    'quantity' => 0.0,
                    'gift_quantity' => 0.0,
                    'line_total' => 0.0,
                    'discount_percent' => $percent > 0 ? $percent : null,
                    'discount_amount' => 0.0,
                    'line_net' => 0.0,
                ];
                $merged[$key]['quantity'] = round($merged[$key]['quantity'] + (float) $line->quantity, 3);
                $merged[$key]['gift_quantity'] = round($merged[$key]['gift_quantity'] + (float) $line->gift_quantity, 3);
                $merged[$key]['line_total'] = round($merged[$key]['line_total'] + (float) $line->line_total, 2);
                $discount = round((float) ($line->discount_amount ?? 0), 2);
                $merged[$key]['discount_amount'] = round($merged[$key]['discount_amount'] + $discount, 2);
                $merged[$key]['line_net'] = round($merged[$key]['line_net'] + ((float) $line->line_total - $discount), 2);
            }
        }

        $lines = collect($merged)
            ->sortBy(fn ($l) => [(string) $l['item_group'], (string) $l['item_name']])
            ->values()
            ->all();

        // الصافي (يقارَن بإجمالي الفواتير = invoice_amount)، والمجموع قبل الحسم والحسم للتذييل
        $linesTotal = round(array_sum(array_column($lines, 'line_net')), 2);
        $grossTotal = round(array_sum(array_column($lines, 'line_total')), 2);
        $discountTotal = round(array_sum(array_column($lines, 'discount_amount')), 2);
        $sourceTotal = round((float) $invoices->sum('invoice_amount'), 2);

        $seriesSubtotals = $invoices
            ->groupBy(fn ($i) => InvoiceSeries::keyFor($i->series) ?? '')
            ->map(fn ($group, $key) => [
                'series' => $group->first()->series,
                'label' => InvoiceSeries::labelFor($group->first()->series) ?? $group->first()->series,
                'count' => $group->count(),
                'total' => round((float) $group->sum('invoice_amount'), 2),
            ])
            ->values()
            ->all();

        return [
            'customer_id' => $customerId,
            'customer_name' => $first?->customer_name,
            'area' => $first?->area ?: 'بلا منطقة',
            'delivery_order_no' => $sheet->delivery_order_no,
            'distribution_date' => $sheet->distribution_date?->toDateString(),
            'invoice_ids' => $invoices->pluck('id')->values()->all(),
            'invoice_labels' => $invoices->map(fn ($i) => $this->label($i->invoice_number))->values()->all(),
            'lines' => $lines,
            'lines_count' => count($lines),
            'lines_total' => $linesTotal,
            'gross_total' => $grossTotal,
            'discount_total' => $discountTotal,
            'has_discount' => $discountTotal > 0,
            'source_total' => $sourceTotal,
            'mismatch' => abs($linesTotal - $sourceTotal) >= 0.01,
            'series_subtotals' => $seriesSubtotals,
            'due_balance' => round($dueBalance, 2),
        ];
    }

    /**
     * «g-1234» من «ع G: 1234».
     */
    public function label(string $invoiceNumber): string
    {
        [$series, $number] = InvoiceFingerprint::split($invoiceNumber);

        return $series !== null ? mb_strtolower($series).'-'.$number : (string) $number;
    }
}
