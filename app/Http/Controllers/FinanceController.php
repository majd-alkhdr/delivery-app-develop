<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\CashCount;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\FinanceExport;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\Finance\FinanceExportFiles;
use App\Services\Finance\FinanceService;
use App\Services\Finance\UnpaidReportExcel;
use App\Services\Finance\UnpaidReportService;
use App\Services\PaymentService;
use App\Services\Reps\RepScope;
use App\Support\Numbers;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * المالية (المرحلة 5): صفحة الكشف للمحاسب (المقبوضات، مطابقة الصندوق، السعر الناقص، معاينة التوزيع، التصدير)،
 * وتقرير الطلبيات غير المسددة. المحاسب والأدمن يعدّلان ويصدّران؛ المشرف عرض فقط.
 */
class FinanceController extends Controller
{
    public const UNPAID_PER_PAGE = 500;

    public function __construct(private FinanceService $finance)
    {
    }

    // =====================================================================
    // صفحة الكشف
    // =====================================================================

    public function show(Request $request, DistributionSheet $sheet, PaymentService $paymentService)
    {
        Gate::authorize('view', $sheet);
        $request->validate([
            'distributor_id' => 'nullable|integer',
            'area' => 'nullable|string|max:255',
            'flag' => ['nullable', Rule::in(['ok', 'far', 'no_reference', 'no_gps'])],
        ]);

        $user = $request->user();
        $canManage = Gate::forUser($user)->allows('manageFinance');

        $allPayments = Payment::with(['customer:id,name,area', 'enteredBy:id,name', 'voider:id,name'])
            ->where('distribution_sheet_id', $sheet->id)
            ->orderBy('id')
            ->get();
        // المرحلة 8A: مشرف المندوبين بحصر مفعّل: دفعات صيدليات نطاقه في هذا الكشف فقط
        $repScope = \App\Services\Reps\RepScope::current($user);
        if ($repScope) {
            $scopedCustomers = $repScope->invoiceRows()->where('distribution_sheet_id', $sheet->id)->where('placement', \App\Models\Invoice::IN_SHEET)
                ->pluck('customer_id')->map(fn ($id) => (int) $id)->unique()->all();
            $allPayments = $allPayments->filter(fn ($p) => in_array((int) $p->customer_id, $scopedCustomers, true))->values();
        }
        $payments = $allPayments
            ->when($request->filled('distributor_id'), fn ($c) => $c->where('entered_by', $request->integer('distributor_id')))
            ->when($request->filled('area'), fn ($c) => $c->filter(fn ($p) => (string) $p->customer?->area === $request->input('area')))
            ->when($request->filled('flag'), fn ($c) => $c->where('location_flag', $request->input('flag')))
            ->values();
        $active = $payments->whereNull('voided_at');
        $paymentTotals = [
            'syp' => round((float) $active->where('currency', 'SYP')->sum('amount'), 2),
            'usd' => round((float) $active->where('currency', 'USD')->sum('amount'), 2),
            'usd_syp' => round((float) $active->where('currency', 'USD')->sum('amount_syp'), 2),
            'equivalent' => round((float) $active->sum('amount_syp'), 2),
        ];
        $paymentFilters = [
            'distributors' => $allPayments->pluck('enteredBy')->filter()->unique('id')->sortBy('name')->values(),
            'areas' => $allPayments->pluck('customer.area')->filter()->unique()->sort()->values(),
        ];
        $voidable = $allPayments->mapWithKeys(fn ($p) => [$p->id => $canManage && $paymentService->canVoid($p, $user)]);

        $preview = $this->finance->preview($sheet);
        $cash = $this->cashRows($sheet, $allPayments->whereNull('voided_at'));
        $exports = FinanceExport::with('exporter:id,name')->where('distribution_sheet_id', $sheet->id)->orderByDesc('sequence')->get();
        $files = app(FinanceExportFiles::class);
        $usdExports = $exports->mapWithKeys(fn ($e) => [$e->id => $files->hasUsd($e)]);

        return view('finance.sheet', [
            'sheet' => $sheet,
            'payments' => $payments,
            'paymentTotals' => $paymentTotals,
            'paymentFilters' => $paymentFilters,
            'voidable' => $voidable,
            'preview' => $preview,
            'cash' => $cash,
            'exports' => $exports,
            'usdExports' => $usdExports,
            'canManage' => $canManage,
            'rateRange' => [(float) Setting::get('usd_rate_min', 50), (float) Setting::get('usd_rate_max', 500), (float) Setting::get('usd_rate_default', 139)],
            'restricted' => $repScope !== null,
        ]);
    }

    /**
     * مطابقة الصندوق: لكل (موزع، عملة) المحصَّل المسجَّل والمستلم فعلياً والفرق.
     */
    private function cashRows(DistributionSheet $sheet, $activePayments): array
    {
        $counts = CashCount::with('enteredBy:id,name')->where('distribution_sheet_id', $sheet->id)->get()
            ->keyBy(fn ($c) => $c->distributor_id.'|'.$c->currency);

        // toBase: مجموعة Eloquent فارغة لا تقبل دمج نصوص (مطابقة صندوق بلا دفعات)
        $keys = $activePayments->toBase()->map(fn ($p) => $p->entered_by.'|'.$p->currency)
            ->merge($counts->keys())->unique()->values();
        $users = \App\Models\User::whereIn('id', $keys->map(fn ($k) => (int) explode('|', $k)[0]))->pluck('name', 'id');

        return $keys->map(function ($key) use ($activePayments, $counts, $users) {
            [$userId, $currency] = explode('|', $key);
            $recorded = round((float) $activePayments->where('entered_by', (int) $userId)->where('currency', $currency)->sum('amount'), 2);
            $count = $counts->get($key);

            return [
                'distributor_id' => (int) $userId,
                'distributor' => $users[(int) $userId] ?? '—',
                'currency' => $currency,
                'recorded' => $recorded,
                'counted' => $count ? (float) $count->amount : null,
                'difference' => $count ? round((float) $count->amount - $recorded, 2) : null,
                'note' => $count?->note,
                'entered_by' => $count?->enteredBy?->name,
                'updated_at' => $count?->updated_at,
            ];
        })->sortBy(fn ($r) => [$r['distributor'], $r['currency']])->values()->all();
    }

    public function storeCashCount(Request $request, DistributionSheet $sheet)
    {
        Gate::authorize('view', $sheet);
        $request->merge(['amount' => $request->filled('amount') ? (Numbers::parse($request->input('amount')) ?? $request->input('amount')) : null]);
        $data = $request->validate([
            'distributor_id' => 'required|integer|exists:users,id',
            'currency' => ['required', Rule::in(['SYP', 'USD'])],
            'amount' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:500',
        ], ['amount.required' => 'أدخل المبلغ المستلم فعلياً.', 'amount.numeric' => 'المبلغ غير صالح.']);

        $existing = CashCount::where('distribution_sheet_id', $sheet->id)
            ->where('distributor_id', $data['distributor_id'])->where('currency', $data['currency'])->first();
        $old = $existing ? ['amount' => (float) $existing->amount, 'note' => $existing->note] : [];

        $count = CashCount::updateOrCreate(
            ['distribution_sheet_id' => $sheet->id, 'distributor_id' => $data['distributor_id'], 'currency' => $data['currency']],
            ['amount' => round((float) $data['amount'], 2), 'note' => $data['note'] ?? null, 'entered_by' => $request->user()->id],
        );

        $recorded = round((float) Payment::active()->where('distribution_sheet_id', $sheet->id)
            ->where('entered_by', $data['distributor_id'])->where('currency', $data['currency'])->sum('amount'), 2);

        ActivityLog::record('cash.counted', $count, $old, [
            'amount' => (float) $count->amount,
            'note' => $count->note,
        ], [
            'sheet_id' => $sheet->id,
            'distributor_id' => (int) $data['distributor_id'],
            'currency' => $data['currency'],
            'recorded' => $recorded,
            'difference' => round((float) $count->amount - $recorded, 2),
        ]);

        return back()->with('success', 'حُفظ المبلغ المستلم فعلياً.');
    }

    /**
     * السعر الناقص لدفعة دولار قديمة بلا amount_syp (قبل التصدير فقط).
     */
    public function setRate(Request $request, Payment $payment)
    {
        $sheet = $payment->sheet;
        // دفعة المندوب بلا كشف (المرحلة 6)؛ غيرها يمر بصلاحية الكشف
        abort_unless($sheet || $payment->isRep(), 404);
        if ($sheet) {
            Gate::authorize('view', $sheet);
        }

        $request->merge(['exchange_rate' => $request->filled('exchange_rate') ? (Numbers::parse($request->input('exchange_rate')) ?? $request->input('exchange_rate')) : null]);
        $data = $request->validate([
            'exchange_rate' => 'required|numeric|gt:0',
            'rate_confirmed' => 'nullable|boolean',
        ], ['exchange_rate.required' => 'أدخل سعر الصرف.', 'exchange_rate.gt' => 'سعر الصرف يجب أن يكون أكبر من صفر.']);

        if ($payment->currency !== 'USD' || $payment->amount_syp !== null || $payment->isVoided() || $payment->isExported()) {
            throw ValidationException::withMessages(['exchange_rate' => 'هذه الدفعة ليست دفعة دولار بانتظار السعر.']);
        }

        $rate = (float) $data['exchange_rate'];
        $min = (float) Setting::get('usd_rate_min', 50);
        $max = (float) Setting::get('usd_rate_max', 500);
        if (($rate < $min || $rate > $max) && ! $request->boolean('rate_confirmed')) {
            return back()->withInput()->with('rate_confirm_payment', $payment->id)->withErrors([
                'exchange_rate' => "سعر الصرف {$rate} خارج النطاق المعتاد ({$min} – {$max}). أكّد السعر ثم احفظ.",
            ]);
        }

        $old = ['exchange_rate' => null, 'amount_syp' => null, 'rate_is_estimate' => (bool) $payment->rate_is_estimate];
        $payment->update([
            'exchange_rate' => $rate,
            'amount_syp' => round((float) $payment->amount * $rate, 2),
            'rate_is_estimate' => false,
        ]);

        ActivityLog::record('finance.rate_set', $payment, $old, [
            'exchange_rate' => $rate,
            'amount_syp' => (float) $payment->amount_syp,
            'rate_is_estimate' => false,
        ], [
            'sheet_id' => $sheet?->id,
            'source' => $payment->source,
            'receipt_number' => $payment->receipt_number,
            'amount_usd' => (float) $payment->amount,
        ]);

        return back()->with('success', "ضُبط سعر الدفعة {$payment->receipt_number}.");
    }

    public function setSurplus(Request $request, DistributionSheet $sheet)
    {
        Gate::authorize('view', $sheet);
        $data = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'cost_center' => 'required|string|max:255',
        ]);

        $this->finance->setSurplusCostCenter($sheet, Customer::findOrFail($data['customer_id']), $data['cost_center'], $request->user());

        return back()->with('success', 'حُفظ مركز كلفة الفائض.');
    }

    public function export(Request $request, DistributionSheet $sheet)
    {
        Gate::authorize('view', $sheet);
        $data = $request->validate(['signature' => 'required|string|max:128', 'confirm' => 'accepted'], [
            'confirm.accepted' => 'أكّد أن هذا الكشف سيُسدَّد الآن في البرنامج المحاسبي.',
        ]);

        $export = $this->finance->export($sheet, $request->user(), $data['signature']);

        return redirect()->route('finance.sheets.show', $sheet)
            ->with('success', "تم التصدير للمالية (الدفعة التصديرية رقم {$export->sequence}). نزّل الملفات أدناه.")
            ->with('finance_export_id', $export->id);
    }

    /**
     * تنزيل ملف من دفعة تصديرية (من اللقطة، لا إعادة حساب).
     */
    public function download(Request $request, FinanceExport $export, string $kind, FinanceExportFiles $files)
    {
        // دفعة تصدير المندوبين (المرحلة 6) بلا كشف: المسار نفسه للمحاسب والأدمن فقط (role middleware)
        if (! $export->isRep()) {
            Gate::authorize('view', $export->sheet);
        }
        abort_unless(array_key_exists($kind, FinanceExportFiles::KINDS), 404);
        if ($kind === 'usd' && ! $files->hasUsd($export)) {
            abort(404, 'لا دفعات دولار في هذه الدفعة التصديرية.');
        }

        $result = $files->build($export, $kind, $request->user());
        $path = $files->save($result['spreadsheet']);

        ActivityLog::record($export->isRep() ? 'finance.rep_export_downloaded' : 'finance.export_downloaded', $export, [], [], [
            'order' => $export->isRep() ? $export->repOrder() : null,
            'sheet_id' => $export->distribution_sheet_id,
            'sequence' => $export->sequence,
            'file' => $kind,
            'file_name' => $result['filename'],
        ]);

        return $this->xlsx($path, $result['filename'], $result['ascii_filename']);
    }

    // =====================================================================
    // تقرير غير المسدد
    // =====================================================================

    public function unpaid(Request $request, UnpaidReportService $reports)
    {
        $filters = $this->unpaidFilters($request);
        $report = $reports->build($filters, RepScope::current($request->user()));

        $page = max(1, (int) $request->input('page', 1));
        $paginator = new LengthAwarePaginator(
            $report['rows']->forPage($page, self::UNPAID_PER_PAGE)->values(),
            $report['rows']->count(),
            self::UNPAID_PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
        $group = in_array($request->input('group'), ['area', 'cost_center'], true) ? $request->input('group') : null;

        return view('finance.unpaid', [
            'report' => $report,
            'rows' => $paginator,
            'group' => $group,
            'groups' => $group ? $reports->grouped($report['rows'], $group) : collect(),
            'filters' => $filters,
        ]);
    }

    public function unpaidExport(Request $request, UnpaidReportService $reports, UnpaidReportExcel $excel)
    {
        $filters = $this->unpaidFilters($request);
        $report = $reports->build($filters, RepScope::current($request->user()));
        $result = $excel->build($report, $request->user());
        $path = $excel->save($result['spreadsheet']);

        ActivityLog::record('finance.unpaid_exported', null, [], [], array_filter([
            'from' => $report['from'],
            'to' => $report['to'],
            'rows' => $report['totals']['count'],
            'unpaid' => $report['totals']['unpaid'],
            'filters' => array_filter($filters, fn ($v) => $v !== null && $v !== ''),
        ], fn ($v) => $v !== null));

        return $this->xlsx($path, $result['filename'], $result['ascii_filename']);
    }

    private function unpaidFilters(Request $request): array
    {
        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'cost_center' => 'nullable|string|max:255',
            'area_key' => 'nullable|string|max:255',
            'distributor_id' => 'nullable|integer',
            'status' => ['nullable', Rule::in(array_keys(UnpaidReportService::STATUSES))],
            'order' => 'nullable|string|max:50',
            'q' => 'nullable|string|max:100',
        ]);

        return $data + ['from' => null, 'to' => null, 'cost_center' => null, 'area_key' => null, 'distributor_id' => null, 'status' => null, 'order' => null, 'q' => null];
    }

    private function xlsx(string $path, string $filename, string $ascii)
    {
        $response = response()->download($path, null, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
        $response->setContentDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, $ascii);

        return $response;
    }
}
