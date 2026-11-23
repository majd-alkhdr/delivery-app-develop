<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\DistributionSheet;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\RepReceivableService;
use App\Services\Reps\RepScope;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * المرحلة 8A: «ملف المندوب» (/rep-supervisor/reps/{rep}) للمشرف والأدمن: ترويسة بالمندوب ومشرفيه ونطاق المشاهِد،
 * وتبويبات الكشوف والذمم والتسديدات — كلها محصورة بـ RepScope (لا نسخة ثانية لمنطق الذمم: نطاق يُمرَّر لـ state()).
 */
class RepProfileController extends Controller
{
    public const TABS = ['sheets' => 'الكشوف', 'receivables' => 'الذمم', 'payments' => 'التسديدات', 'plan' => 'الخطة والتغطية'];

    public function show(Request $request, User $rep, RepReceivableService $receivables, RepScope $repScope)
    {
        abort_unless($rep->role === Role::Rep, 404);
        $data = $request->validate(['tab' => ['nullable', Rule::in(array_keys(self::TABS))]]);
        $tab = $data['tab'] ?? 'sheets';

        $scope = RepScope::current($request->user());
        if ($scope && ! $scope->allowsRep($rep)) {
            abort(403, 'هذا المندوب خارج نطاقك.');
        }
        // null = كل صيدلياته (الأدمن، أو الحصر مُطفأ، أو نطاق all)
        $customerIds = $scope?->customerIdsFor($rep->id);

        $view = [
            'rep' => $rep,
            'tab' => $tab,
            'scope' => $scope,
            'supervisors' => $repScope->supervisorsOf($rep),
            'scopeLabel' => $scope?->label($rep->id),
            'partial' => $scope !== null && $customerIds !== null,
        ];

        if ($tab === 'sheets') {
            $invoices = $receivables->repInvoices($rep)
                ->when($customerIds !== null, fn ($c) => $c->whereIn('customer_id', $customerIds))
                ->values();
            $sheets = DistributionSheet::whereIn('id', $invoices->pluck('distribution_sheet_id')->unique()->all() ?: [0])
                ->orderByDesc('distribution_date')->orderByDesc('id')->get();
            $view['sheets'] = $sheets->map(fn ($sheet) => [
                'sheet' => $sheet,
                'invoices' => $invoices->where('distribution_sheet_id', $sheet->id)->count(),
                'pharmacies' => $invoices->where('distribution_sheet_id', $sheet->id)->pluck('customer_id')->unique()->count(),
                'total' => round((float) $invoices->where('distribution_sheet_id', $sheet->id)->sum(fn ($i) => $i->effectiveAmount()), 2),
            ]);
        } elseif ($tab === 'receivables') {
            $state = $receivables->state($rep, $customerIds)
                ->filter(fn ($s) => $s['invoices']->isNotEmpty() || $s['pending_cents'] > 0 || $s['exported_cents'] > 0)
                ->sortBy('name', SORT_NATURAL)->values();
            $view['state'] = $state;
            $view['totals'] = [
                'arising' => $state->sum('arising_cents') / 100,
                'exported' => $state->sum('exported_cents') / 100,
                'pending' => $state->sum('pending_cents') / 100,
                'remaining' => $state->sum('remaining_cents') / 100,
                'surplus' => $state->sum('surplus_cents') / 100,
            ];
        } elseif ($tab === 'plan') {
            // المرحلة 8B: تغطية هذا الأسبوع (السبت–الجمعة) لبنود المشاهِد وزياراته
            $today = \Carbon\Carbon::parse(\App\Services\Reps\RepPlanService::today());
            $start = $today->copy()->subDays(\App\Models\RepPlanTemplate::weekdayOf($today));
            $view['coverage'] = (new \App\Services\Reps\RepCoverageService(new \App\Services\Reps\RepPlanService($repScope)))
                ->build($request->user(), ['rep_id' => $rep->id, 'from' => $start->toDateString(), 'to' => $start->copy()->addDays(6)->toDateString()]);
        } else {
            $view['payments'] = Payment::with(['customer:id,name,area', 'image:id,payment_id'])
                ->where('source', 'rep')->where('entered_by', $rep->id)
                ->when($customerIds !== null, fn ($q) => $q->whereIn('customer_id', $customerIds ?: [0]))
                ->orderByDesc('collected_at')->orderByDesc('id')
                ->paginate(100)->withQueryString();
        }

        return view('rep-supervisor.rep-profile', $view);
    }
}
