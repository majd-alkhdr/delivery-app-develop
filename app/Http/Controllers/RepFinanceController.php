<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\FinanceExport;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\Finance\RepFinanceService;
use App\Services\Finance\RepReceivableService;
use App\Services\Finance\RepPaymentsListExcel;
use App\Services\Finance\RepReceivablesExcel;
use App\Services\PaymentService;
use App\Support\ArabicNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * المالية للمندوبين (المرحلة 6-F/G/H): مراجعة دفعات المندوبين وتصديرها، وتقرير ذمم المندوبين.
 * المحاسب والأدمن يعدّلان ويصدّران؛ المشرف عرض فقط.
 */
class RepFinanceController extends Controller
{
    public const PER_PAGE = 500;

    public function __construct(private RepFinanceService $finance)
    {
    }

    // =====================================================================
    // دفعات المندوبين
    // =====================================================================

    public function payments(Request $request, PaymentService $paymentService)
    {
        $data = $request->validate([
            'rep_id' => 'nullable|integer',
            'date' => 'nullable|date',
            'status' => ['nullable', Rule::in(['pending', 'needs_review', 'verified', 'exported'])],
            'q' => 'nullable|string|max:100',
            'reps' => 'nullable|array',
            'reps.*' => 'integer',
            'include' => 'nullable|array',
            'include.*' => 'nullable|string|max:500',
        ]);
        $user = $request->user();
        $canManage = Gate::forUser($user)->allows('manageFinance');
        $q = ArabicNormalizer::normalize((string) ($data['q'] ?? ''));
        $status = $data['status'] ?? 'pending';

        $payments = Payment::with(['customer:id,name,area', 'enteredBy:id,name,cost_center_label', 'reviewer:id,name', 'image:id,payment_id', 'voider:id,name'])
            ->where('source', 'rep')
            ->when(! empty($data['rep_id']), fn ($qq) => $qq->where('entered_by', (int) $data['rep_id']))
            ->when(! empty($data['date']), fn ($qq) => $qq->whereDate('collected_at', $data['date']))
            ->when($status === 'pending', fn ($qq) => $qq->where('status', 'pending'))
            ->when($status === 'exported', fn ($qq) => $qq->where('status', '!=', 'pending'))
            ->when(in_array($status, ['needs_review', 'verified'], true), fn ($qq) => $qq->where('status', 'pending')->where('review_status', $status))
            ->orderBy('entered_by')->orderBy('collected_at')->orderBy('id')
            ->get()
            ->when($q !== '', fn ($c) => $c->filter(fn ($p) => str_contains(ArabicNormalizer::normalize((string) $p->customer?->name), $q)))
            ->values();
        // المرحلة 8A: حصة تسديدات مندوبيه على صيدليات نطاقه فقط (الدفعة مسجَّلة على صيدلية، فتُنسب لفواتيرها بالأقدم)
        $scope = $this->repScope($request, $data['rep_id'] ?? null);
        if ($scope) {
            $payments = $payments->filter(fn ($p) => $scope->allowsPayment($p))->values();
        }

        $selected = ! empty($data['reps']) ? array_map('intval', $data['reps']) : null;
        $overrides = array_filter($data['include'] ?? [], fn ($r) => trim((string) $r) !== '');
        $preview = $this->finance->preview($selected, $overrides);

        $exports = FinanceExport::with('exporter:id,name')->where('kind', FinanceExport::KIND_REP)->orderByDesc('sequence')->limit(50)->get();
        $usdExports = $exports->mapWithKeys(fn ($e) => [$e->id => $e->rows()->where('row_type', 'receipt')->where('currency', 'USD')->exists()]);

        return view('finance.rep-payments', [
            'payments' => $payments->groupBy('entered_by'),
            'count' => $payments->count(),
            'reps' => $this->finance->reps(),
            'allReps' => User::where('role', Role::Rep)->orderBy('name')->get(['id', 'name', 'cost_center_label'])
                ->when($scope !== null, fn ($c) => $c->filter(fn ($r) => $scope->allowsRep($r))->values()),
            'restricted' => $scope !== null,
            'filters' => ['rep_id' => $data['rep_id'] ?? null, 'date' => $data['date'] ?? null, 'status' => $status, 'q' => $data['q'] ?? null],
            'selected' => $selected,
            'preview' => $preview,
            'exports' => $exports,
            'usdExports' => $usdExports,
            'canManage' => $canManage,
            'canExportReports' => Gate::forUser($user)->allows('exportFinanceReports'),
            'voidable' => $payments->mapWithKeys(fn ($p) => [$p->id => $canManage && $paymentService->canVoid($p, $user)]),
            'rateRange' => [(float) Setting::get('usd_rate_min', 50), (float) Setting::get('usd_rate_max', 500), (float) Setting::get('usd_rate_default', 139)],
        ]);
    }

    /**
     * قائمة دفعات المندوبين بالفلاتر نفسها إلى Excel (تقرير للقراءة؛ لا يغيّر حالة أي دفعة).
     */
    public function paymentsListExport(Request $request, RepPaymentsListExcel $excel)
    {
        $data = $request->validate([
            'rep_id' => 'nullable|integer',
            'date' => 'nullable|date',
            'status' => ['nullable', Rule::in(['pending', 'needs_review', 'verified', 'exported'])],
            'q' => 'nullable|string|max:100',
        ]);
        $status = $data['status'] ?? 'pending';
        $q = ArabicNormalizer::normalize((string) ($data['q'] ?? ''));

        $payments = Payment::with(['customer:id,name,area', 'enteredBy:id,name,cost_center_label'])
            ->where('source', 'rep')
            ->when(! empty($data['rep_id']), fn ($qq) => $qq->where('entered_by', (int) $data['rep_id']))
            ->when(! empty($data['date']), fn ($qq) => $qq->whereDate('collected_at', $data['date']))
            ->when($status === 'pending', fn ($qq) => $qq->where('status', 'pending'))
            ->when($status === 'exported', fn ($qq) => $qq->where('status', '!=', 'pending'))
            ->when(in_array($status, ['needs_review', 'verified'], true), fn ($qq) => $qq->where('status', 'pending')->where('review_status', $status))
            ->orderBy('entered_by')->orderBy('collected_at')->orderBy('id')
            ->get()
            ->when($q !== '', fn ($c) => $c->filter(fn ($p) => str_contains(ArabicNormalizer::normalize((string) $p->customer?->name), $q)))
            ->values();
        $scope = $this->repScope($request, $data['rep_id'] ?? null);
        if ($scope) {
            $payments = $payments->filter(fn ($p) => $scope->allowsPayment($p))->values();
        }

        $result = $excel->build($payments, $request->user(), $scope?->label());
        $path = $excel->save($result['spreadsheet']);
        ActivityLog::record('finance.rep_payments_list_exported', null, [], [], ['rows' => $payments->count(), 'status' => $status]);

        $response = response()->download($path, null, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
        $response->setContentDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $result['filename'], $result['ascii_filename']);

        return $response;
    }

    public function review(Request $request, Payment $payment)
    {
        abort_unless($payment->isRep(), 404);
        $data = $request->validate([
            'review_status' => ['required', Rule::in(['verified', 'needs_review'])],
            'review_note' => ['nullable', 'string', 'max:500', Rule::requiredIf(fn () => $request->input('review_status') === 'needs_review')],
        ], ['review_note.required' => 'اكتب ملاحظة توضّح ما يحتاج مراجعة.']);

        if ($payment->isExported() || $payment->isVoided()) {
            return back()->withErrors(['review_status' => 'لا تُراجَع دفعة مُصدَّرة أو ملغاة.']);
        }

        $old = ['review_status' => $payment->review_status, 'review_note' => $payment->review_note];
        $payment->update([
            'review_status' => $data['review_status'],
            'review_note' => $data['review_status'] === 'needs_review' ? trim((string) $data['review_note']) : (trim((string) ($data['review_note'] ?? '')) ?: null),
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        ActivityLog::record('rep.payment_reviewed', $payment, $old, [
            'review_status' => $payment->review_status,
            'review_note' => $payment->review_note,
        ], ['receipt_number' => $payment->receipt_number, 'book_receipt_no' => $payment->book_receipt_no]);

        return back()->with('success', $data['review_status'] === 'verified' ? 'عُلّمت الدفعة «تم التحقق».' : 'عُلّمت الدفعة «بحاجة مراجعة».');
    }

    public function export(Request $request)
    {
        $data = $request->validate([
            'reps' => 'required|array|min:1',
            'reps.*' => 'integer',
            'include' => 'nullable|array',
            'include.*' => 'nullable|string|max:500',
            'signature' => 'required|string|max:128',
            'confirm' => 'accepted',
        ], [
            'reps.required' => 'اختر مندوباً واحداً على الأقل.',
            'confirm.accepted' => 'أكّد أن هذه الدفعات ستُسدَّد الآن في البرنامج المحاسبي.',
        ]);

        // شمول «بحاجة مراجعة»: السبب إلزامي (3 أحرف على الأقل)
        $overrides = [];
        foreach ($data['include'] ?? [] as $paymentId => $reason) {
            $reason = trim((string) $reason);
            if ($reason === '') {
                continue;
            }
            if (mb_strlen($reason) < 3) {
                return back()->withErrors(['include' => 'سبب شمول دفعة «بحاجة مراجعة» إلزامي (3 أحرف على الأقل).'])->withInput();
            }
            $overrides[(int) $paymentId] = $reason;
        }

        $export = $this->finance->export($request->user(), array_map('intval', $data['reps']), $overrides, $data['signature']);

        return redirect()->route('finance.rep-payments')
            ->with('success', "تم تصدير دفعات المندوبين ({$export->repOrder()}). نزّل الملفات أدناه.")
            ->with('finance_export_id', $export->id);
    }

    // =====================================================================
    // ذمم المندوبين
    // =====================================================================

    public function receivables(Request $request, RepReceivableService $service)
    {
        $filters = $this->receivableFilters($request);
        $scope = $this->repScope($request, $filters['rep_id'] ?? null);
        $report = $service->receivablesForStaff($filters, $scope);

        // تقسيم صفحات 500 صف (صفوف الفواتير)
        $flat = $report['groups']->flatMap(fn ($g) => $g['pharmacies']->flatMap(fn ($p) => $p['invoices']->map(fn ($i) => ['group' => $g, 'pharmacy' => $p, 'invoice' => $i])));
        $page = max(1, (int) $request->input('page', 1));
        $rows = new LengthAwarePaginator($flat->forPage($page, self::PER_PAGE)->values(), $flat->count(), self::PER_PAGE, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return view('finance.rep-receivables', [
            'report' => $report,
            'rows' => $rows,
            'filters' => $filters,
            'reps' => User::where('role', Role::Rep)->orderBy('name')->get(['id', 'name'])
                ->when($scope !== null, fn ($c) => $c->filter(fn ($r) => $scope->allowsRep($r))->values()),
            'areas' => $report['groups']->flatMap(fn ($g) => $g['pharmacies']->pluck('area'))->unique()->sort()->values(),
            // المرحلة 7C: التعديلات اللاحقة على الذمم (بمرشّح المندوب)
            'adjustments' => \App\Models\InvoiceAdjustment::with('invoice:id,invoice_number', 'customer:id,name', 'rep:id,name')
                ->when(is_numeric($filters['rep_id'] ?? null), fn ($q) => $q->where('rep_user_id', (int) $filters['rep_id']))
                ->when(($filters['rep_id'] ?? null) === 'none', fn ($q) => $q->whereNull('rep_user_id'))
                ->orderByDesc('id')->limit(100)->get()
                // المرحلة 8A: تعديلات فواتير نطاقه فقط
                ->when($scope !== null, fn ($c) => $c->filter(fn ($a) => $scope->allowsInvoiceId((int) $a->invoice_id))->values()),
        ]);
    }

    public function receivablesExport(Request $request, RepReceivableService $service, RepReceivablesExcel $excel)
    {
        $filters = $this->receivableFilters($request);
        $scope = $this->repScope($request, $filters['rep_id'] ?? null);
        $report = $service->receivablesForStaff($filters, $scope);
        $result = $excel->build($report, $request->user());
        $path = $excel->save($result['spreadsheet']);

        ActivityLog::record('finance.rep_receivables_exported', null, [], [], [
            'filters' => array_filter($filters, fn ($v) => $v !== null && $v !== ''),
            'remaining' => $report['totals']['remaining'],
        ]);

        $response = response()->download($path, null, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
        $response->setContentDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $result['filename'], $result['ascii_filename']);

        return $response;
    }

    private function receivableFilters(Request $request): array
    {
        $data = $request->validate([
            'rep_id' => 'nullable|string|max:20',
            'area' => 'nullable|string|max:255',
            'q' => 'nullable|string|max:100',
            'age' => ['nullable', Rule::in(array_keys(RepReceivableService::AGE_BUCKETS))],
            'status' => ['nullable', Rule::in(['paid', 'partial', 'unpaid'])],
        ]);

        return $data + ['rep_id' => null, 'area' => null, 'q' => null, 'age' => null, 'status' => null];
    }

    /**
     * المرحلة 8A: نطاق مشرف المندوبين (null لغيره أو حين الحصر مُطفأ)، و403 لمندوب خارج النطاق.
     */
    private function repScope(Request $request, int|string|null $repId): ?\App\Services\Reps\RepViewerScope
    {
        $scope = \App\Services\Reps\RepScope::current($request->user());
        if ($scope && is_numeric($repId) && ! $scope->allowsRep((int) $repId)) {
            abort(403, 'هذا المندوب خارج نطاقك.');
        }

        return $scope;
    }
}
