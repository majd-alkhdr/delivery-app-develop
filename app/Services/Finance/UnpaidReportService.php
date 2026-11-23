<?php

namespace App\Services\Finance;

use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\FinanceExport;
use App\Models\FinanceSurplusChoice;
use App\Models\Payment;
use App\Models\SheetVisit;
use App\Support\ArabicNormalizer;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * تقرير الطلبيات غير المسددة (المرحلة 5-G): أرقام حيّة من المحرك نفسه.
 * المسدَّد لكل فاتورة = ما في لقطات التصدير السابقة + توزيع الدفعات pending الحالية.
 * لا مؤجل ولا ملغى ولا مستبعد ولا صفري، ولا رصيد خام.
 */
class UnpaidReportService
{
    public const STATUSES = ['unpaid' => 'غير مسددة', 'partial' => 'مسددة جزئياً'];

    public function __construct(private PaymentAllocationEngine $engine)
    {
    }

    /**
     * @param  array{from?: ?string, to?: ?string, cost_center?: ?string, area_key?: ?string, distributor_id?: ?int, status?: ?string, order?: ?string, q?: ?string}  $filters
     * @return array{rows: Collection, all_rows: Collection, totals: array, options: array, from: string, to: string}
     */
    public function build(array $filters, ?\App\Services\Reps\RepViewerScope $repScope = null): array
    {
        $today = now('Asia/Damascus')->toDateString();
        $from = ! empty($filters['from']) ? Carbon::parse($filters['from'])->toDateString() : $today;
        $to = ! empty($filters['to']) ? Carbon::parse($filters['to'])->toDateString() : $from;
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        $sheets = DistributionSheet::query()
            ->where('format', 'v2')
            ->whereNotIn('status', ['preparing', 'draft'])
            ->whereDate('distribution_date', '>=', $from)
            ->whereDate('distribution_date', '<=', $to)
            ->with('assignments.distributor:id,name')
            ->orderBy('distribution_date')->orderBy('id')
            ->get();

        $rows = collect();
        $surplusCents = 0;
        foreach ($sheets as $sheet) {
            [$sheetRows, $sheetSurplus] = $this->sheetRows($sheet);
            $rows = $rows->merge($sheetRows);
            $surplusCents += $sheetSurplus;
        }
        // فائض الدفعات التصديرية السابقة لهذه الكشوف
        $surplusCents += PaymentAllocationEngine::toCents(FinanceExport::whereIn('distribution_sheet_id', $sheets->pluck('id'))->sum('surplus_syp'));

        // المرحلة 8A: مشرف المندوبين بحصر مفعّل: فواتير نطاقه فقط، والمحصَّل من صيدليات نطاقه، والفائض (مستوى الكشف) لا يُعرض
        $scopedCustomers = null;
        if ($repScope !== null) {
            $rows = $rows->filter(fn ($r) => $repScope->allowsInvoiceId($r['invoice_id']))->values();
            $scopedCustomers = $rows->pluck('customer_id')->unique()->values()->all();
            $surplusCents = 0;
        }

        $options = [
            'cost_centers' => $rows->pluck('cost_center')->filter()->unique()->sort()->values()->all(),
            'areas' => $rows->mapWithKeys(fn ($r) => [$r['area_key'] ?? '' => $r['area']])->filter(fn ($v, $k) => $k !== '')->sort()->all(),
            'distributors' => $sheets->flatMap(fn ($s) => $s->assignments->pluck('distributor'))->filter()->unique('id')->sortBy('name')->mapWithKeys(fn ($u) => [$u->id => $u->name])->all(),
            'orders' => $sheets->pluck('delivery_order_no')->filter()->unique()->sort()->values()->all(),
        ];

        $filtered = $this->applyFilters($rows, $filters)->values();

        $payments = Payment::active()
            ->whereIn('distribution_sheet_id', $sheets->pluck('id'))
            ->when(! empty($filters['distributor_id']), fn ($q) => $q->where('entered_by', (int) $filters['distributor_id']))
            ->when($scopedCustomers !== null, fn ($q) => $q->whereIn('customer_id', $scopedCustomers ?: [0]))
            ->get(['id', 'currency', 'amount', 'amount_syp']);

        return [
            'rows' => $filtered,
            'scope_label' => $repScope?->label(),
            'from' => $from,
            'to' => $to,
            'options' => $options,
            'totals' => [
                'count' => $filtered->count(),
                'unpaid' => $filtered->sum('unpaid_cents') / 100,
                'collected_syp' => round((float) $payments->where('currency', 'SYP')->sum('amount'), 2),
                'collected_usd' => round((float) $payments->where('currency', 'USD')->sum('amount'), 2),
                'collected_usd_syp' => round((float) $payments->where('currency', 'USD')->sum('amount_syp'), 2),
                'collected_total_syp' => round((float) $payments->sum('amount_syp'), 2),
                'surplus' => $surplusCents / 100,
                'awaiting_rate' => $payments->where('currency', 'USD')->whereNull('amount_syp')->count(),
            ],
        ];
    }

    /**
     * صفوف مجمّعة (حسب المنطقة أو مركز الكلفة) بالإجماليات.
     */
    public function grouped(Collection $rows, string $by): Collection
    {
        $field = $by === 'cost_center' ? 'cost_center' : 'area';

        return $rows->groupBy(fn ($r) => (string) ($r[$field] ?? ''))
            ->map(fn (Collection $group, $key) => [
                'label' => $key !== '' ? $key : ($field === 'area' ? 'بلا منطقة' : 'بلا مركز'),
                'count' => $group->count(),
                'total' => $group->sum('total_cents') / 100,
                'allocated' => $group->sum('allocated_cents') / 100,
                'unpaid' => $group->sum('unpaid_cents') / 100,
            ])
            ->sortBy('label', SORT_NATURAL)
            ->values();
    }

    /**
     * التوزيع الحي لفواتير كشف (أو زبائن منه): لقطات الموزعين السابقة + توزيع دفعات الموزع المعلّقة.
     * المصدر الوحيد لـ«غير المسدد بعد الموزع» (يستخدمه هذا التقرير وذمم المندوب في المرحلة 6).
     *
     * @return array{invoices: Collection, results: array<int, AllocationResult>}
     */
    public function liveAllocation(DistributionSheet $sheet, ?array $customerIds = null): array
    {
        $invoices = $this->engine->sheetInvoices($sheet, $customerIds)->get();
        if ($invoices->isEmpty()) {
            return ['invoices' => $invoices, 'results' => []];
        }
        $previous = $this->engine->previousAllocatedCents($invoices->pluck('id')->all());
        $pending = Payment::active()->where('distribution_sheet_id', $sheet->id)->where('status', 'pending')
            ->when($customerIds !== null, fn ($q) => $q->whereIn('customer_id', $customerIds ?: [0]))
            ->orderBy('id')->get()->groupBy('customer_id');
        $choices = FinanceSurplusChoice::where('distribution_sheet_id', $sheet->id)->pluck('cost_center', 'customer_id');

        $results = [];
        foreach ($invoices->groupBy('customer_id') as $customerId => $customerInvoices) {
            $results[(int) $customerId] = $this->engine->compute(
                (int) $customerId,
                $customerInvoices->map(fn ($i) => $this->engine->invoiceInput($i))->all(),
                $pending->get($customerId, collect())->map(fn ($p) => $this->engine->paymentInput($p))->all(),
                $previous,
                $choices[$customerId] ?? null,
            );
        }

        return ['invoices' => $invoices, 'results' => $results];
    }

    /**
     * @return array{0: Collection, 1: int}
     */
    private function sheetRows(DistributionSheet $sheet): array
    {
        ['invoices' => $invoices, 'results' => $results] = $this->liveAllocation($sheet);
        if ($invoices->isEmpty()) {
            return [collect(), 0];
        }
        $customers = Customer::whereIn('id', $invoices->pluck('customer_id')->unique())->get(['id', 'name'])->keyBy('id');
        $visits = SheetVisit::active()->where('distribution_sheet_id', $sheet->id)->get()->keyBy('customer_id');
        $paidCustomers = Payment::active()->where('distribution_sheet_id', $sheet->id)->distinct()->pluck('customer_id')->flip();
        $byId = $invoices->keyBy('id');

        $rows = collect();
        $surplus = 0;
        foreach ($results as $customerId => $result) {
            $surplus += $result->surplusCents;

            foreach ($result->invoiceRows as $row) {
                if ($row['unpaid_cents'] <= 0) {
                    continue;
                }
                $invoice = $byId[$row['invoice_id']];
                $paidCents = $row['previous_cents'] + $row['allocated_cents'];
                $visit = $visits->get($customerId);

                $rows->push([
                    'sheet_id' => $sheet->id,
                    'date' => $sheet->distribution_date?->toDateString(),
                    'order' => $sheet->orderLabel(),
                    'customer_id' => (int) $customerId,
                    'pharmacy' => $customers[$customerId]->name ?? $invoice->customer_name,
                    'area' => $invoice->area ?: 'بلا منطقة',
                    'area_key' => $invoice->area_key,
                    'invoice' => $row['label'],
                    'cost_center' => $row['cost_center'],
                    // المرحلة 8A: لحصر مشرف المندوبين بالفاتورة
                    'invoice_id' => (int) $row['invoice_id'],
                    'total' => $row['total'],
                    'total_cents' => $row['total_cents'],
                    'allocated' => $paidCents / 100,
                    'allocated_cents' => $paidCents,
                    'unpaid' => $row['unpaid'],
                    'unpaid_cents' => $row['unpaid_cents'],
                    'status' => $paidCents > 0 ? 'partial' : 'unpaid',
                    'awaiting_rate' => $result->isIncomplete(),
                    'visit' => $this->visitLabel($visit, isset($paidCustomers[$customerId])),
                    'distributor_ids' => $this->distributorsFor($sheet, $invoice->area_key)->keys()->all(),
                    'distributor' => $this->distributorsFor($sheet, $invoice->area_key)->implode(' + ') ?: '—',
                ]);
            }
        }

        return [$rows, $surplus];
    }

    private function applyFilters(Collection $rows, array $filters): Collection
    {
        $q = ! empty($filters['q']) ? ArabicNormalizer::normalize((string) $filters['q']) : '';

        return $rows
            ->when(! empty($filters['cost_center']), fn ($c) => $c->where('cost_center', $filters['cost_center']))
            ->when(! empty($filters['area_key']), fn ($c) => $c->where('area_key', $filters['area_key']))
            ->when(! empty($filters['distributor_id']), fn ($c) => $c->filter(fn ($r) => in_array((int) $filters['distributor_id'], $r['distributor_ids'], true)))
            ->when(! empty($filters['status']), fn ($c) => $c->where('status', $filters['status']))
            ->when(! empty($filters['order']), fn ($c) => $c->filter(fn ($r) => (string) $r['order'] === (string) $filters['order']))
            ->when($q !== '', fn ($c) => $c->filter(fn ($r) => str_contains(
                ArabicNormalizer::normalize($r['pharmacy'].' '.$r['invoice'].' '.$r['cost_center'].' '.$r['area'].' '.$r['order']), $q)));
    }

    /**
     * @return Collection<int, string> الموزعون المسندون لمنطقة الفاتورة (أو لكل الكشف)
     */
    public function distributorsFor(DistributionSheet $sheet, ?string $areaKey): Collection
    {
        return $sheet->assignments
            ->filter(fn ($a) => $a->area_key === null || $a->area_key === $areaKey)
            ->mapWithKeys(fn ($a) => [$a->distributor_id => $a->distributor?->name])
            ->filter();
    }

    private function visitLabel(?SheetVisit $visit, bool $paid): string
    {
        if ($paid) {
            return 'تمت الزيارة (دفعة)';
        }

        return match ($visit?->status) {
            'visited' => 'تمت الزيارة',
            'not_visited' => 'لم يتم التسليم'.($visit->reason ? ': '.$visit->reason : ''),
            default => 'لم تتم الزيارة',
        };
    }
}
