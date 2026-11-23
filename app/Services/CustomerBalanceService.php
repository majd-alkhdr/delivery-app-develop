<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Support\InvoiceFingerprint;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * الرصيد المستحق = الرصيد الخام (من ملف الأرصدة) − الطلبيات المؤجلة.
 *
 * الرصيد في ملف الأرصدة يتضمن قيمة الطلبيات المؤجلة، والمؤجل لا يجب أن يظهر للموزع أبداً.
 * المؤجل هنا: كل فاتورة للزبون تاريخ تسليمها بعد $asOf وليست ملغاة، في أي مكان كانت
 * (مؤجلة بلا كشف، أو في أي كشف بأي حالة). الفاتورة لا تُطرح مرتين (invoice_key فريد،
 * ولفواتير المرحلة 2 المكررة بلا مفتاح يُزال التكرار برقم الفاتورة).
 */
class CustomerBalanceService
{
    // =====================================================================
    // المرحلة 7B: الرصيد المعروض الواحد
    // =====================================================================

    /**
     * الرصيد المعروض لزبون في كل الشاشات: رصيد المحاسبة − المؤجل − الدفعات التي لم تنعكس بعد.
     * الدفعة «منعكسة» إذا صارت مصدَّرة ثم وصل بعد تصديرها ملف أرصدة وانعكست فيه (payments.reflected_at)،
     * وإلا فهي تُطرح (معلّقة أو مصدَّرة بانتظار ملف أرصدة) — فلا طرح مزدوج.
     */
    public function currentBalance(Customer $customer, ?\App\Models\DistributionSheet $sheet = null): CurrentBalance
    {
        return $this->currentBalances(collect([$customer]), $sheet?->distribution_date)[$customer->id];
    }

    /**
     * @param  Collection<int, Customer>  $customers
     * @return array<int, CurrentBalance> customer_id => الرصيد
     */
    public function currentBalances(Collection $customers, ?CarbonInterface $deferredAfter = null): array
    {
        $deferredAfter ??= now('Asia/Damascus');
        $ids = $customers->pluck('id')->all();
        $deferred = $this->deferredTotals($ids, $deferredAfter);
        $unreflected = $this->unreflectedPayments($ids);

        $result = [];
        foreach ($customers as $customer) {
            $raw = round((float) $customer->balance, 2);
            $d = $deferred[$customer->id] ?? 0.0;
            $u = $unreflected[$customer->id] ?? 0.0;
            $result[$customer->id] = new CurrentBalance($customer->id, $raw, $d, $u, round($raw - $d - $u, 2), $customer->balance_as_of?->toDateString());
        }

        return $result;
    }

    /**
     * مجموع amount_syp للدفعات غير الملغاة التي لم تنعكس بعد (دفعة دولار بلا سعر لا قيمة لها بالليرة بعد).
     *
     * @param  array<int, int>  $customerIds
     * @return array<int, float>
     */
    public function unreflectedPayments(array $customerIds): array
    {
        if (! $customerIds) {
            return [];
        }

        return \App\Models\Payment::active()
            ->whereIn('customer_id', $customerIds)
            ->whereNull('reflected_at')
            ->whereNotNull('amount_syp')
            ->selectRaw('customer_id, SUM(amount_syp) as s')
            ->groupBy('customer_id')
            ->pluck('s', 'customer_id')
            ->map(fn ($s) => round((float) $s, 2))
            ->all();
    }

    /**
     * عمر أحدث أرصدة مطبَّقة بالأيام (null إن لم تُطبَّق أرصدة أبداً) — لشريط «آخر تحديث للأرصدة منذ N يوم».
     *
     * @return array{as_of: ?string, days: ?int, stale: bool}
     */
    public function staleness(): array
    {
        $latest = \App\Models\BalanceImport::latestApplied();
        if (! $latest?->as_of) {
            return ['as_of' => null, 'days' => null, 'stale' => false];
        }
        $days = (int) $latest->as_of->copy()->startOfDay()->diffInDays(now('Asia/Damascus')->startOfDay());
        $limit = (int) \App\Models\Setting::get('balances_stale_days', 2);

        return ['as_of' => $latest->as_of->toDateString(), 'days' => $days, 'stale' => $days > $limit];
    }

    public function dueBalance(Customer $customer, CarbonInterface $asOf): float
    {
        return $this->dueBalances(collect([$customer]), $asOf)[$customer->id] ?? 0.0;
    }

    public function deferredTotal(Customer $customer, CarbonInterface $asOf): float
    {
        return $this->deferredTotals([$customer->id], $asOf)[$customer->id] ?? 0.0;
    }

    /**
     * @param  Collection<int, Customer>  $customers
     * @return array<int, float> customer_id => الرصيد المستحق
     */
    public function dueBalances(Collection $customers, CarbonInterface $asOf): array
    {
        $deferred = $this->deferredTotals($customers->pluck('id')->all(), $asOf);

        $result = [];
        foreach ($customers as $customer) {
            $result[$customer->id] = round((float) $customer->balance - ($deferred[$customer->id] ?? 0.0), 2);
        }

        return $result;
    }

    /**
     * مجموع الفواتير المؤجلة لكل زبون بعد تاريخ معيّن.
     *
     * @param  array<int, int>  $customerIds
     * @return array<int, float>
     */
    public function deferredTotals(array $customerIds, CarbonInterface $asOf): array
    {
        if (! $customerIds) {
            return [];
        }

        $totals = [];
        foreach (array_chunk(array_values(array_unique($customerIds)), 500) as $chunk) {
            $rows = Invoice::query()
                ->whereIn('customer_id', $chunk)
                ->whereNotNull('delivery_date')
                ->whereDate('delivery_date', '>', $asOf->toDateString())
                ->where('placement', '!=', Invoice::CANCELLED)
                ->orderBy('id')
                ->get(['id', 'customer_id', 'invoice_key', 'invoice_number', 'invoice_amount']);

            foreach ($rows->groupBy('customer_id') as $customerId => $invoices) {
                $totals[$customerId] = round(
                    (float) $invoices->unique(fn ($i) => $i->invoice_key ?? InvoiceFingerprint::keyFromNumber((string) $i->invoice_number) ?? $i->invoice_number)
                        ->sum(fn ($i) => (float) $i->invoice_amount),
                    2
                );
            }
        }

        return $totals;
    }
}
