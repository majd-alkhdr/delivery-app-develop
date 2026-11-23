<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\Payment;
use App\Models\SheetVisit;
use App\Services\CustomerBalanceService;
use App\Services\Finance\RepReceivableService;
use App\Services\Finance\UnpaidReportService;
use App\Support\InvoiceLinesView;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * كشوف المندوب (المرحلة 6B-C): قراءة فقط، بفواتيره هو فقط (مطابقة مركز الكلفة كما في المرحلة 6).
 *
 * - الكشوف pending وin_progress وcompleted فقط.
 * - «المبلغ المنسوب» من معاينة التوزيع الحيّة نفسها (UnpaidReportService::liveAllocation فوق PaymentAllocationEngine)
 *   دون أي كتابة: لقطات الموزعين + توزيع دفعاتهم المعلّقة.
 * - لا تظهر فواتير غيره ولا المؤجلة ولا الرصيد الخام ولا GPS ولا الصور ولا تفاصيل دفعات الموزعين.
 */
class RepSheetController extends Controller
{
    public const STATUSES = ['pending', 'in_progress', 'completed'];

    public function __construct(private RepReceivableService $receivables, private UnpaidReportService $unpaid)
    {
    }

    public function index(Request $request)
    {
        $invoices = $this->receivables->repInvoices($request->user(), self::STATUSES);
        $sheets = DistributionSheet::whereIn('id', $invoices->pluck('distribution_sheet_id')->unique())
            ->orderByDesc('distribution_date')->orderByDesc('id')->get();

        $rows = $sheets->map(function (DistributionSheet $sheet) use ($invoices) {
            $mine = $invoices->where('distribution_sheet_id', $sheet->id)->values();
            $figures = $this->figures($sheet, $mine);

            return [
                'sheet' => $sheet,
                'count' => $mine->count(),
                'total' => array_sum(array_column($figures, 'total_cents')) / 100,
                'collected' => array_sum(array_column($figures, 'paid_cents')) / 100,
                'remaining' => array_sum(array_column($figures, 'unpaid_cents')) / 100,
            ];
        });

        // المرحلة 7B: عدّاد الصيدليات التي تغيّر رصيدها ولم «يطّلع» عليه المندوب
        $changed = \App\Models\CustomerBalanceSnapshot::unseenFor($invoices->pluck('customer_id')->unique()->values()->all(), $request->user());

        return view('rep.sheets', [
            'rows' => $rows,
            'changedCount' => $changed->count(),
            // المرحلة 7C: «تغيّرت ذمتك بسبب تعديل لاحق» حتى «اطلعت»
            'adjustmentAlerts' => \App\Models\InvoiceAdjustment::unseenForRep($request->user()),
        ]);
    }

    public function show(Request $request, DistributionSheet $sheet, CustomerBalanceService $balances)
    {
        $mine = $this->mine($request, $sheet);
        $figures = $this->figures($sheet, $mine);
        $customers = Customer::whereIn('id', $mine->pluck('customer_id')->unique())->get()->keyBy('id');
        $customerIds = $customers->keys()->all();

        $visits = SheetVisit::active()->where('distribution_sheet_id', $sheet->id)->whereIn('customer_id', $customerIds)->get()->keyBy('customer_id');
        $payments = Payment::active()->where('distribution_sheet_id', $sheet->id)->whereIn('customer_id', $customerIds)->get(['id', 'customer_id', 'note'])->groupBy('customer_id');
        $sheet->loadMissing('assignments.distributor:id,name');

        $changes = \App\Models\CustomerBalanceSnapshot::unseenFor($customerIds, $request->user());
        // المرحلة 7C: التعديلات اللاحقة على فواتيره (وسم «تعديل لاحق: من … إلى …»)
        $adjustments = \App\Models\InvoiceAdjustment::whereIn('invoice_id', $mine->pluck('id'))->orderByDesc('id')->get()->groupBy('invoice_id');
        $pharmacies = $mine->groupBy('customer_id')->map(function (Collection $invoices, $customerId) use ($customers, $visits, $payments, $figures, $sheet, $balances, $changes, $adjustments) {
            $customer = $customers[$customerId];
            $visit = $visits->get($customerId);
            $paid = $payments->get($customerId, collect());

            return [
                'customer' => $customer,
                'area' => $invoices->first()->area ?: 'بلا منطقة',
                'invoices' => $invoices->map(fn ($i) => [
                    'invoice' => $i,
                    'cost_center' => InvoiceLinesView::costCenter($i),
                    'note' => InvoiceLinesView::note($i->invoice_note),
                    'distributor' => $this->unpaid->distributorsFor($sheet, $i->area_key)->implode(' + ') ?: '—',
                    'paid' => ($figures[$i->id]['paid_cents'] ?? 0) / 100,
                    'remaining' => ($figures[$i->id]['unpaid_cents'] ?? 0) / 100,
                    'amount' => $i->effectiveAmount(),
                    'adjustment' => $adjustments->get($i->id)?->firstWhere('status', \App\Models\InvoiceAdjustment::APPLIED),
                ])->values(),
                'visit' => $this->visitState($visit, $paid->isNotEmpty()),
                'notes' => $this->distributorNotes($visit, $paid),
                // الرصيد المستحق للصيدلية كلها (بعد طرح المؤجل)، لا الخام
                // المرحلة 7B: الرصيد المعروض الواحد
                'due_balance' => ($current = $balances->currentBalance($customer, $sheet))->net,
                'balance' => $current,
                'balance_change' => $changes->get($customerId),
                'attributed' => $invoices->sum(fn ($i) => ($figures[$i->id]['paid_cents'] ?? 0)) / 100,
                'remaining' => $invoices->sum(fn ($i) => ($figures[$i->id]['unpaid_cents'] ?? 0)) / 100,
            ];
        })->sortBy(fn ($p) => (string) $p['customer']->name, SORT_NATURAL)->values();

        return view('rep.sheet', ['sheet' => $sheet, 'pharmacies' => $pharmacies]);
    }

    /**
     * المرحلة 7B: «اطلعت» على تغيّر رصيد صيدلية (المندوب ليس له نظام إشعارات).
     */
    public function balanceSeen(Request $request, \App\Models\CustomerBalanceSnapshot $snapshot)
    {
        $snapshot->markSeenBy($request->user());

        return back()->with('success', 'تم.');
    }

    /**
     * أسطر فواتيره في الكشف (JSON بصيغة الموزع نفسها، مع الحسم).
     */
    public function lines(Request $request, DistributionSheet $sheet)
    {
        $mine = $this->mine($request, $sheet);
        $mine->load('lines');

        return response()->json([
            'sheet_id' => $sheet->id,
            'schema' => InvoiceLinesView::SCHEMA,
            'revision' => (int) $sheet->revision,
            'delivery_order_no' => $sheet->delivery_order_no,
            'distribution_date' => $sheet->distribution_date?->toDateString(),
            // المرحلة 7C: وسم التعديل اللاحق المطبَّق (الأسطر نفسها محدَّثة في تعديل «من الملف»)
            'invoices' => $mine->map(fn ($i) => InvoiceLinesView::payload($i) + ['adjustment' => ($a = \App\Models\InvoiceAdjustment::applied()->where('invoice_id', $i->id)->latest('id')->first()) ? [
                'label' => $a->tagLabel(), 'date' => $a->applied_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d'), 'reason' => $a->reasonLabel(),
                'effective_amount' => $i->effectiveAmount(),
            ] : null])->values(),
        ]);
    }

    // =====================================================================

    /**
     * فواتير المندوب في هذا الكشف؛ كشف بلا فاتورة له أو بحالة غير منشورة ⇒ 404.
     */
    private function mine(Request $request, DistributionSheet $sheet): Collection
    {
        if (! in_array($sheet->status, self::STATUSES, true)) {
            abort(404);
        }
        $mine = $this->receivables->repInvoices($request->user(), self::STATUSES, $sheet->id);
        if ($mine->isEmpty()) {
            abort(404);
        }

        return $mine;
    }

    /**
     * أرقام فواتيره من معاينة التوزيع الحيّة (بلا كتابة): invoice_id => [total_cents, paid_cents, unpaid_cents].
     *
     * @return array<int, array{total_cents: int, paid_cents: int, unpaid_cents: int}>
     */
    private function figures(DistributionSheet $sheet, Collection $mine): array
    {
        $live = $this->unpaid->liveAllocation($sheet, $mine->pluck('customer_id')->unique()->values()->all());
        $ids = array_flip($mine->pluck('id')->all());
        $figures = [];
        foreach ($live['results'] as $result) {
            foreach ($result->invoiceRows as $row) {
                if (isset($ids[$row['invoice_id']])) {
                    $figures[$row['invoice_id']] = [
                        'total_cents' => $row['total_cents'],
                        'paid_cents' => $row['previous_cents'] + $row['allocated_cents'],
                        'unpaid_cents' => $row['unpaid_cents'],
                    ];
                }
            }
        }

        return $figures;
    }

    private function visitState(?SheetVisit $visit, bool $paid): array
    {
        return match (true) {
            $paid => ['label' => 'زُيرت (تحصيل)', 'reason' => null],
            $visit?->status === 'visited' => ['label' => 'زُيرت', 'reason' => 'لا تحصيل في هذه الزيارة'],
            $visit?->status === 'not_visited' => ['label' => 'لم تُزَر', 'reason' => $visit->reason],
            default => ['label' => 'لم تُزَر', 'reason' => null],
        };
    }

    /**
     * ملاحظات الموزع النصية على الصيدلية في هذا الكشف (ملاحظات تحصيلاته)، كما كُتبت،
     * مع حذف ختم سعر الصرف الآلي («تم الحساب على 139») فلا تظهر أسعار صرف ولا مبالغ.
     *
     * @return array<int, string>
     */
    private function distributorNotes(?SheetVisit $visit, Collection $payments): array
    {
        return $payments->pluck('note')
            ->map(fn ($n) => trim((string) preg_replace('/\s*—?\s*تم الحساب على\s+[\d.,]+/u', '', (string) $n)))
            ->filter(fn ($n) => $n !== '')
            ->unique()
            ->values()
            ->all();
    }
}
