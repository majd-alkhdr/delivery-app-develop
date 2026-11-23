<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\CustomerBalanceSnapshot;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\Balances\SnapshotScope;
use App\Models\User;
use App\Support\CostCenter;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * شاشة «تغيّرات الأرصدة» (المرحلة 7B): لقطات تغيّر رصيد الزبائن مع التفسير، والمندوبين المحتملين،
 * و«تمّت المراجعة» للمحاسب والأدمن. قراءة للمشرفَين ومسؤول الكشوف ومشرف المندوبين.
 */
class BalanceChangesController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => 'nullable|in:ok,flagged',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'q' => 'nullable|string|max:100',
            'rep_id' => 'nullable|integer',
            'unreviewed' => 'nullable|boolean',
            'import' => 'nullable|integer',
            'scope' => 'nullable|in:app,external,all',
            'kind' => 'nullable|in:changed,created,zeroed,unchanged',
        ]);

        // المرحلة 7E: الافتراضي «مرتبط بالتطبيق + المشتبه بها»؛ الخارجية تبقى مسجّلة ومرشّحها «خارجي»
        $scope = $filters['scope'] ?? null;
        $query = CustomerBalanceSnapshot::query()
            ->when($filters['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('as_of', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('as_of', '<=', $d))
            ->when($filters['import'] ?? null, fn ($q, $id) => $q->where('balance_import_id', $id))
            ->when($request->boolean('unreviewed'), fn ($q) => $q->whereNull('reviewed_at'))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->whereHas('customer', fn ($c) => $c->where('name', 'like', '%'.trim($term).'%')))
            ->when($filters['rep_id'] ?? null, function ($q, $repId) {
                $rep = User::find($repId);
                $variants = $rep?->cost_center_code ? CostCenter::codeVariants((string) CostCenter::normalizeCode($rep->cost_center_code)) : ['__none__'];
                $q->whereIn('customer_id', Invoice::whereIn('cost_center_code', $variants)->select('customer_id'));
            });

        // المرحلة 8A: مشرف المندوبين بحصر مفعّل: صيدليات لها فواتير لمندوبيه ضمن نطاقه فقط
        $repScope = \App\Services\Reps\RepScope::current($request->user());
        if ($repScope) {
            if (! empty($filters['rep_id']) && ! $repScope->allowsRep((int) $filters['rep_id'])) {
                abort(403, 'هذا المندوب خارج نطاقك.');
            }
            $query->whereIn('customer_id', $repScope->customerIds() ?: [0]);
        }

        $external = (clone $query)->where('scope', SnapshotScope::EXTERNAL)->count();
        match ($scope) {
            'all' => null,
            'app' => $query->where('scope', SnapshotScope::APP),
            'external' => $query->where('scope', SnapshotScope::EXTERNAL),
            default => $query->where(fn ($q) => $q->where('scope', '!=', SnapshotScope::EXTERNAL)->orWhereNull('scope')->orWhere('status', CustomerBalanceSnapshot::FLAGGED)),
        };

        $summary = [
            'external' => $external,
            'count' => (clone $query)->count(),
            'increase' => round((float) (clone $query)->where('delta', '>', 0)->sum('delta'), 2),
            'decrease' => round((float) (clone $query)->where('delta', '<', 0)->sum('delta'), 2),
            'flagged' => (clone $query)->where('status', CustomerBalanceSnapshot::FLAGGED)->count(),
        ];

        $snapshots = $query->with(['customer:id,name,area', 'reviewer:id,name', 'balanceImport:id,file_name'])
            ->orderByDesc('id')->paginate(50)->withQueryString();
        $candidates = $this->candidates($snapshots->getCollection()->pluck('customer_id')->unique()->all());
        $reps = User::where('role', Role::Rep)->orderBy('name')->get(['id', 'name', 'cost_center_label'])
            ->when($repScope !== null, fn ($c) => $c->filter(fn ($r) => $repScope->allowsRep($r))->values());

        return view('finance.balance-changes', [
            'snapshots' => $snapshots,
            'summary' => $summary,
            'candidates' => $candidates,
            'reps' => $reps,
            'canReview' => $request->user()->can('reviewBalanceChanges'),
            'canAdjust' => $request->user()->can('applyAdjustments'),
            'unreflected' => self::scopeUnreflected(self::unreflectedPayments(), $repScope),
        ]);
    }

    public function review(Request $request, CustomerBalanceSnapshot $snapshot)
    {
        $data = $request->validate(['note' => 'nullable|string|max:500']);
        if ($snapshot->reviewed_at) {
            return back()->with('warning', 'تمّت مراجعة هذا التغيّر مسبقاً.');
        }
        $note = isset($data['note']) && trim($data['note']) !== '' ? trim($data['note']) : null;
        $snapshot->update(['reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_note' => $note]);

        // دفعات مصدَّرة لم تنعكس: المراجعة تعدّها منعكسة (يزول التحذير ولا تُطرح بعد الآن)
        $paymentIds = collect($snapshot->details ?? [])->where('type', 'exported_payment')->pluck('payment_id')->filter()->all();
        $reflected = $snapshot->exported_mismatch && $paymentIds
            ? Payment::whereIn('id', $paymentIds)->whereNull('reflected_at')->update(['reflected_at' => now(), 'reflected_snapshot_id' => $snapshot->id])
            : 0;

        ActivityLog::record('customers.balance_change_reviewed', $snapshot, ['reviewed_at' => null], [
            'reviewed_at' => $snapshot->reviewed_at->toDateTimeString(), 'note' => $note,
        ], ['customer_id' => $snapshot->customer_id, 'delta' => (float) $snapshot->delta, 'payments_marked_reflected' => $reflected]);

        return back()->with('success', 'تمّت مراجعة التغيّر'.($reflected ? " وعُدّت {$reflected} دفعة مصدَّرة منعكسة." : '.'));
    }

    /**
     * المندوبون/مراكز الكلفة المحتملة: فواتير الزبون في كشوف مغلقة خلال آخر 60 يوماً.
     *
     * @return Collection<int, Collection> customer_id => صفوف
     */
    private function candidates(array $customerIds): Collection
    {
        if (! $customerIds) {
            return collect();
        }
        $reps = User::where('role', Role::Rep)->whereNotNull('cost_center_code')->get(['name', 'cost_center_code'])
            ->mapWithKeys(fn ($u) => [CostCenter::normalizeCode($u->cost_center_code) => $u->name]);

        return Invoice::with('distributionSheet:id,delivery_order_no,distribution_date,status')
            ->whereIn('customer_id', $customerIds)
            ->where('placement', Invoice::IN_SHEET)
            ->whereHas('distributionSheet', fn ($q) => $q->where('status', 'completed')->whereDate('distribution_date', '>=', now()->subDays(60)->toDateString()))
            ->orderByDesc('id')
            ->get()
            ->map(fn ($i) => [
                'customer_id' => $i->customer_id,
                'invoice_id' => $i->id,
                'label' => \App\Services\Finance\PaymentAllocationEngine::invoiceLabel((string) $i->invoice_number),
                'amount' => $i->effectiveAmount(),   // المرحلة 7C: القيمة الفعالة
                'cost_center' => trim(($i->cost_center_code ?? '').'-'.($i->cost_center_name ?? ''), '-') ?: null,
                'rep' => $reps[CostCenter::normalizeCode($i->cost_center_code)] ?? null,
                'sheet_id' => $i->distribution_sheet_id,
                'order' => $i->distributionSheet?->delivery_order_no,
            ])
            ->groupBy('customer_id');
    }

    /**
     * «دفعات مصدَّرة لم تنعكس» (H): بانتظار ملف أرصدة، أو لم ينخفض الرصيد بما يقابلها (لقطة غير مراجَعة).
     *
     * @return array{awaiting: Collection, mismatched: Collection, limit_days: int}
     */
    public static function unreflectedPayments(): array
    {
        $limit = (int) Setting::get('exported_unreflected_days', 3);
        $awaiting = Payment::active()->with(['customer:id,name', 'financeExport:id,sequence'])
            ->where('status', '!=', 'pending')->whereNotNull('exported_at')->whereNull('reflected_at')
            ->orderBy('exported_at')->get()
            ->map(fn ($p) => [
                'payment' => $p,
                'age_days' => (int) $p->exported_at->copy()->startOfDay()->diffInDays(now()->startOfDay()),
            ])
            ->map(fn ($row) => $row + ['overdue' => $row['age_days'] > $limit]);
        $mismatched = CustomerBalanceSnapshot::with('customer:id,name')->where('exported_mismatch', true)->whereNull('reviewed_at')->latest('id')->get();

        return ['awaiting' => $awaiting, 'mismatched' => $mismatched, 'limit_days' => $limit];
    }

    /**
     * المرحلة 8A: «غير المنعكسة» لصيدليات نطاق مشرف المندوبين فقط.
     */
    private static function scopeUnreflected(array $data, ?\App\Services\Reps\RepViewerScope $scope): array
    {
        if ($scope === null) {
            return $data;
        }
        $customers = array_flip($scope->customerIds());
        $data['awaiting'] = $data['awaiting']->filter(fn ($row) => isset($customers[(int) $row['payment']->customer_id]))->values();
        $data['mismatched'] = $data['mismatched']->filter(fn ($s) => isset($customers[(int) $s->customer_id]))->values();

        return $data;
    }
}
