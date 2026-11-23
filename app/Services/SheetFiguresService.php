<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Support\BulkWrite;
use Illuminate\Database\Eloquent\Builder;

/**
 * أرقام كشوف v2 المحفوظة على الفواتير والكشف (الأعمدة القديمة التي تقرؤها الواجهات):
 * - final_balance = الرصيد المستحق للزبون بتاريخ توزيع الكشف.
 * - customer_total_invoices = مجموع فواتير الزبون الموضوعة في هذا الكشف (in_sheet غير المؤجلة).
 * - total_all = total_effective = مجموع فواتير الكشف الحالية، وtotal_deferred = 0.
 */
class SheetFiguresService
{
    public function __construct(private CustomerBalanceService $balances)
    {
    }

    /**
     * @return array<int, array{name: string, due: float}> الزبائن الذين أصبح مستحقهم سالباً
     */
    public function refresh(DistributionSheet $sheet): array
    {
        if (! $sheet->isV2() || ! $sheet->distribution_date) {
            return [];
        }

        $invoices = Invoice::where('distribution_sheet_id', $sheet->id)
            ->where('placement', Invoice::IN_SHEET)
            ->get(['id', 'customer_id', 'invoice_amount', 'is_deferred', 'invoice_pattern']);

        $total = round((float) $invoices->sum('invoice_amount'), 2);
        $sheet->update(['total_all' => $total, 'total_deferred' => 0, 'total_effective' => $total]);

        $withCustomer = $invoices->whereNotNull('customer_id');
        $customers = Customer::whereIn('id', $withCustomer->pluck('customer_id')->unique())->get();
        $due = $this->balances->dueBalances($customers, $sheet->distribution_date);

        $negative = [];
        $rows = [];
        $now = (new Invoice)->freshTimestampString();
        foreach ($withCustomer->groupBy('customer_id') as $customerId => $customerInvoices) {
            $effectiveTotal = round((float) $customerInvoices->where('is_deferred', false)->sum('invoice_amount'), 2);
            $customerDue = $due[$customerId] ?? 0.0;

            foreach ($customerInvoices->pluck('id') as $id) {
                $rows[$id] = ['final_balance' => $customerDue, 'customer_total_invoices' => $effectiveTotal, 'updated_at' => $now];
            }

            if ($customerDue < 0) {
                $negative[] = ['name' => $customers->firstWhere('id', $customerId)?->name ?? '#'.$customerId, 'due' => $customerDue];
            }
        }
        // المرحلة 7D: تحديث جماعي بدل استعلام لكل زبون (القيم نفسها)
        BulkWrite::updateById('invoices', $rows);

        return $negative;
    }

    /**
     * إعادة حساب عدة كشوف (بالمعرّف) مع تجاهل غير الموجود.
     *
     * @param  iterable<int|null>  $sheetIds
     */
    public function refreshMany(iterable $sheetIds): void
    {
        $ids = collect($sheetIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }
        DistributionSheet::whereIn('id', $ids)->get()->each(fn ($sheet) => $this->refresh($sheet));
    }

    /**
     * مزامنة منطقة الزبون على فواتيره التي لم تُوزَّع بعد: بلا كشف أو مؤجلة أو في كشف
     * قيد التجهيز أو منشور. لا تعديل بأثر رجعي على المكتملة، ولا على فاتورة عُدّلت منطقتها يدوياً.
     */
    public function syncCustomerArea(Customer $customer): void
    {
        Invoice::where('customer_id', $customer->id)
            ->where('area_overridden', false)
            ->where(fn (Builder $q) => $q->whereNull('distribution_sheet_id')
                ->orWhereHas('distributionSheet', fn ($s) => $s->whereIn('status', DistributionSheet::EDITABLE_STATUSES)))
            ->update([
                'area' => $customer->area,
                'area_key' => $customer->areaKey(),
            ]);
    }
}
