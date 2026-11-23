<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\CustomerBalanceSnapshot;
use App\Models\ImportBatchItem;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\InvoiceChangeProposal;
use App\Models\User;
use App\Services\Finance\PostCloseAdjustmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * شاشة «التعديلات اللاحقة» (المرحلة 7C): بانتظار التطبيق (من الملف) والمطبَّقة، ونموذج التعديل اليدوي.
 * عرض: الأدمن والمحاسب والمشرفان ومسؤول الكشوف (viewAdjustments). تطبيق/تجاهل: المحاسب والأدمن. تراجع: الأدمن.
 */
class InvoiceAdjustmentController extends Controller
{
    public function __construct(private PostCloseAdjustmentService $service)
    {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'tab' => 'nullable|in:pending,applied',
            'proposal' => 'nullable|integer',
            'rep_id' => 'nullable|integer',
            'q' => 'nullable|string|max:100',
        ]);
        $tab = $filters['tab'] ?? 'pending';
        $user = $request->user();
        $canApply = PostCloseAdjustmentService::canApply($user);
        // المرحلة 8A: مشرف المندوبين بحصر مفعّل: تعديلات فواتير نطاقه فقط
        $repScope = \App\Services\Reps\RepScope::current($user);
        if ($repScope && ! empty($filters['rep_id']) && ! $repScope->allowsRep((int) $filters['rep_id'])) {
            abort(403, 'هذا المندوب خارج نطاقك.');
        }
        $scopedInvoices = $repScope ? ($repScope->invoiceIds() ?: [0]) : null;

        $pending = $this->service->pendingQuery()
            ->with(['invoice.distributionSheet:id,delivery_order_no,distribution_date,status', 'invoice.customer:id,name'])
            ->when($filters['proposal'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when($scopedInvoices !== null, fn ($q) => $q->whereIn('invoice_id', $scopedInvoices))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->whereHas('invoice', fn ($i) => $i->where('customer_name', 'like', '%'.trim($term).'%')->orWhere('invoice_number', 'like', '%'.trim($term).'%')))
            ->orderBy('id')->get()
            ->map(fn (InvoiceChangeProposal $p) => $this->pendingRow($p, $user, $canApply))
            ->when($filters['rep_id'] ?? null, fn ($c, $repId) => $c->filter(fn ($r) => (int) ($r['rep']?->id) === (int) $repId))
            ->values();

        $ignored = InvoiceChangeProposal::with(['invoice:id,invoice_number,customer_name', 'decider:id,name'])
            ->where('type', InvoiceChangeProposal::TYPE_POST_CLOSE)->where('status', InvoiceChangeProposal::REJECTED)
            ->when($scopedInvoices !== null, fn ($q) => $q->whereIn('invoice_id', $scopedInvoices))
            ->latest('decided_at')->limit(50)->get();

        $applied = InvoiceAdjustment::with(['invoice:id,invoice_number,customer_name,invoice_amount', 'sheet:id,delivery_order_no', 'customer:id,name', 'rep:id,name,cost_center_label', 'snapshot', 'applier:id,name', 'reverter:id,name'])
            ->when($filters['rep_id'] ?? null, fn ($q, $repId) => $q->where('rep_user_id', $repId))
            ->when($scopedInvoices !== null, fn ($q) => $q->whereIn('invoice_id', $scopedInvoices))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->whereHas('invoice', fn ($i) => $i->where('customer_name', 'like', '%'.trim($term).'%')->orWhere('invoice_number', 'like', '%'.trim($term).'%')))
            ->orderByDesc('id')->paginate(50, ['*'], 'page')->withQueryString();

        return view('finance.adjustments', [
            'tab' => $tab,
            'pending' => $pending,
            'ignored' => $ignored,
            'applied' => $applied,
            'canApply' => $canApply,
            'canRevert' => PostCloseAdjustmentService::canRevert($user),
            'reps' => User::where('role', Role::Rep)->orderBy('name')->get(['id', 'name'])
                ->when($repScope !== null, fn ($c) => $c->filter(fn ($r) => $repScope->allowsRep($r))->values()),
        ]);
    }

    /**
     * نموذج التعديل اليدوي (من «تغيّرات الأرصدة»: الفاتورة والفرق المقترح واللقطة مُعبَّأة) مع معاينة الأثر.
     */
    public function create(Request $request)
    {
        $data = $request->validate([
            'invoice' => 'required|integer|exists:invoices,id',
            'delta' => 'nullable|numeric',
            'new_amount' => 'nullable|numeric|min:0',
            'snapshot' => 'nullable|integer|exists:customer_balance_snapshots,id',
        ]);
        $invoice = Invoice::with('distributionSheet', 'customer:id,name')->findOrFail($data['invoice']);
        $current = $invoice->effectiveAmount();
        $delta = isset($data['new_amount']) && $data['new_amount'] !== null ? round((float) $data['new_amount'] - $current, 2) : (isset($data['delta']) ? (float) $data['delta'] : null);
        $rep = $this->service->repFor($invoice->cost_center_code);

        $preview = null;
        $error = null;
        if ($delta !== null && abs($delta) >= 0.005) {
            try {
                $preview = $this->service->previewManual($invoice, $delta, $request->user());
            } catch (\Illuminate\Validation\ValidationException $e) {
                $error = collect($e->errors())->flatten()->first();
            }
        }

        return view('finance.adjustment-form', [
            'invoice' => $invoice,
            'current' => $current,
            'delta' => $delta,
            'rep' => $rep,
            'now' => $this->service->figures($invoice, $rep),
            'preview' => $preview,
            'error' => $error,
            'snapshot' => isset($data['snapshot']) ? CustomerBalanceSnapshot::find($data['snapshot']) : null,
            'closed' => $invoice->placement === Invoice::IN_SHEET && $invoice->distributionSheet?->status === 'completed',
            'history' => InvoiceAdjustment::with('applier:id,name')->where('invoice_id', $invoice->id)->orderByDesc('id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'invoice' => 'required|integer|exists:invoices,id',
            'mode' => 'required|in:delta,new_amount',
            'delta' => 'nullable|required_if:mode,delta|numeric',
            'new_amount' => 'nullable|required_if:mode,new_amount|numeric|min:0',
            'reason_code' => ['required', Rule::in(InvoiceAdjustment::MANUAL_REASONS)],
            'reason_text' => 'nullable|required_if:reason_code,other|string|max:500',
            'snapshot' => 'nullable|integer|exists:customer_balance_snapshots,id',
        ], [
            'reason_code.required' => 'سبب التعديل إلزامي.',
            'reason_text.required_if' => 'اكتب السبب عند اختيار «أخرى».',
        ]);
        $invoice = Invoice::findOrFail($data['invoice']);
        $delta = $data['mode'] === 'new_amount' ? round((float) $data['new_amount'] - $invoice->effectiveAmount(), 2) : (float) $data['delta'];

        $adjustment = $this->service->applyManual($invoice, $delta, $data['reason_code'], $data['reason_text'] ?? null, $request->user(), $data['snapshot'] ?? null);

        return redirect()->route('finance.adjustments', ['tab' => 'applied'])
            ->with('success', 'طُبّق التعديل اللاحق على الفاتورة '.\App\Services\Finance\PaymentAllocationEngine::invoiceLabel((string) $invoice->invoice_number).': '
                .PostCloseAdjustmentService::impactMessage($adjustment->rep?->name, $adjustment->rep_debt_before !== null ? (float) $adjustment->rep_debt_before : null,
                    $adjustment->rep_debt_after !== null ? (float) $adjustment->rep_debt_after : null, (float) $adjustment->surplus, false).'.');
    }

    public function apply(Request $request, InvoiceChangeProposal $proposal)
    {
        $data = $request->validate(['note' => 'nullable|string|max:500']);
        $adjustment = $this->service->applyProposal($proposal, $request->user(), isset($data['note']) && trim($data['note']) !== '' ? trim($data['note']) : null);

        return redirect()->route('finance.adjustments', ['tab' => 'applied'])
            ->with('success', 'طُبّق التعديل اللاحق: '.PostCloseAdjustmentService::impactMessage($adjustment->rep?->name,
                $adjustment->rep_debt_before !== null ? (float) $adjustment->rep_debt_before : null,
                $adjustment->rep_debt_after !== null ? (float) $adjustment->rep_debt_after : null, (float) $adjustment->surplus, false).'.');
    }

    public function ignore(Request $request, InvoiceChangeProposal $proposal)
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:500'], ['reason.required' => 'سبب التجاهل إلزامي.', 'reason.min' => 'سبب التجاهل إلزامي (3 أحرف على الأقل).']);
        $this->service->ignore($proposal, $data['reason'], $request->user());

        return back()->with('success', 'تُجوهل التعديل وسُجّل السبب، ويبقى ظاهراً في القائمة.');
    }

    public function revert(Request $request, InvoiceAdjustment $adjustment)
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:500'], ['reason.required' => 'سبب التراجع إلزامي.', 'reason.min' => 'سبب التراجع إلزامي (3 أحرف على الأقل).']);
        $this->service->revert($adjustment, $data['reason'], $request->user());

        return back()->with('success', 'تم التراجع عن التعديل وأُعيدت القيم.');
    }

    /** المندوب: «اطلعت» على تعديل لاحق غيّر ذمته */
    public function seen(Request $request, InvoiceAdjustment $adjustment)
    {
        $this->service->markSeen($adjustment, $request->user());

        return back()->with('success', 'تم.');
    }

    // =====================================================================

    private function pendingRow(InvoiceChangeProposal $proposal, User $user, bool $canApply): array
    {
        $invoice = $proposal->invoice;
        $rep = $this->service->repFor($invoice->cost_center_code);
        $item = ImportBatchItem::where('proposal_id', $proposal->id)->latest('id')->first();
        $preview = null;
        $error = null;
        try {
            // المعاينة تحتاج صلاحية التطبيق؛ للقراءة فقط تُعرض الذمة الحالية
            $preview = $canApply ? $this->service->previewProposal($proposal, $user) : null;
        } catch (\Illuminate\Validation\ValidationException $e) {
            $error = collect($e->errors())->flatten()->first();
        }
        $now = $this->service->figures($invoice, $rep);
        $delta = round((float) ($proposal->payload['invoice_amount'] ?? 0) - (float) $invoice->invoice_amount, 2);
        $snapshot = CustomerBalanceSnapshot::where('customer_id', $invoice->customer_id)->where('created_at', '>=', now()->subDays(PostCloseAdjustmentService::SNAPSHOT_WINDOW_DAYS))
            ->orderByDesc('id')->get()
            ->first(fn ($s) => abs((float) $s->delta - $delta) < 0.01 || collect($s->details ?? [])->contains(fn ($d) => ($d['invoice_id'] ?? null) === $invoice->id));

        return [
            'proposal' => $proposal,
            'invoice' => $invoice,
            'sheet' => $invoice->distributionSheet,
            'rep' => $rep,
            'item' => $item,
            'old' => (float) $invoice->invoice_amount,
            'new' => (float) ($proposal->payload['invoice_amount'] ?? 0),
            'delta' => $delta,
            'debt_now' => $now['debt'],
            'preview' => $preview,
            'error' => $error,
            'snapshot' => $snapshot,
            'manual_applied' => InvoiceAdjustment::applied()->where('invoice_id', $invoice->id)->where('kind', InvoiceAdjustment::KIND_MANUAL)->exists(),
            'reason' => $item?->reason,
        ];
    }
}
