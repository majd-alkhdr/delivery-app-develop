<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\RepSupervisorAssignment;
use App\Models\User;
use App\Services\Reps\RepAssignmentService;
use App\Services\Reps\RepScope;
use App\Support\ArabicNormalizer;
use App\Support\CostCenter;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * المرحلة 8A: شاشة الأدمن «إسناد المندوبين» (/admin/rep-assignments): العرض بحسب المشرف وبحسب المندوب،
 * التنبيهات، الإسناد والإنهاء والنقل، وتفعيل الحصر. الأدمن وحده (مجموعة المسار role:admin).
 */
class RepAssignmentController extends Controller
{
    private RepScope $scope;

    private RepAssignmentService $service;

    /**
     * نسخ جديدة لكل طلب: الموجّه يحتفظ بنسخة المتحكم، وRepScope يحفظ فهرس الزبائن في نسخته.
     */
    public function callAction($method, $parameters)
    {
        $this->scope = app(RepScope::class);
        $this->service = new RepAssignmentService($this->scope);

        return $this->{$method}(...array_values($parameters));
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'view' => ['nullable', Rule::in(['supervisors', 'reps', 'no-scope'])],
            'q' => 'nullable|string|max:100',
            'sort' => ['nullable', Rule::in(['name', 'count'])],
        ]);
        $view = $data['view'] ?? 'supervisors';
        $q = ArabicNormalizer::normalize((string) ($data['q'] ?? ''));
        $sort = $data['sort'] ?? 'name';

        $supervisors = User::where('role', Role::RepSupervisor)->orderBy('name')->get(['id', 'name']);
        $reps = User::where('role', Role::Rep)->orderBy('name')->get(['id', 'name', 'role', 'cost_center_code', 'cost_center_label']);
        $usersById = $supervisors->concat($reps)->keyBy('id');
        $assignments = RepSupervisorAssignment::active()->orderBy('id')->get()
            ->each(function ($a) use ($usersById) {
                $a->setRelation('rep', $usersById[$a->rep_user_id] ?? null);
                $a->setRelation('supervisor', $usersById[$a->supervisor_user_id] ?? null);
            });
        $index = $this->scope->customerIndex();
        $pharmaciesByCode = $this->scope->pharmaciesByCode();
        $counts = $assignments->mapWithKeys(fn ($a) => [$a->id => $this->service->pharmacyCount($a)]);

        // بحسب المندوب: مشرفوه، وصيدلياته غير المسنَدة مجمّعة بالمنطقة/الكتلة
        $byRep = $reps->map(function (User $rep) use ($assignments, $pharmaciesByCode, $index) {
            $code = $rep->hasCostCenter() ? CostCenter::normalizeCode($rep->cost_center_code) : null;
            $pharmacies = $code !== null ? array_keys($pharmaciesByCode[$code] ?? []) : [];
            $mine = $assignments->where('rep_user_id', $rep->id)->values();
            $unassigned = [];
            foreach ($pharmacies as $customerId) {
                if ($mine->contains(fn ($a) => $this->scope->covers($a, $customerId))) {
                    continue;
                }
                $area = $index['areaOf'][$customerId] ?? null;
                $block = $index['blockOf'][$customerId] ?? null;
                $key = $area !== null ? 'area:'.$area : ($block !== null ? 'block:'.$block : 'none');
                $unassigned[$key] ??= [
                    'label' => $area !== null ? 'منطقة '.($index['area'][$area]['label'] ?? $area) : ($block !== null ? 'كتلة '.($index['block'][$block]['label'] ?? $block) : 'بلا منطقة ولا كتلة'),
                    'count' => 0,
                ];
                $unassigned[$key]['count']++;
                $unassigned[$key]['ids'][] = $customerId;
            }

            return [
                'rep' => $rep,
                'has_cost_center' => $code !== null,
                'pharmacies' => count($pharmacies),
                'assignments' => $mine,
                'supervisors' => $mine->pluck('supervisor')->filter()->unique('id')->values(),
                'unassigned' => collect($unassigned)->sortBy('label', SORT_NATURAL)->values(),
                'unassigned_count' => array_sum(array_column($unassigned, 'count')),
            ];
        });

        $bySupervisor = $supervisors->map(fn (User $s) => [
            'supervisor' => $s,
            'reps' => $assignments->where('supervisor_user_id', $s->id)->groupBy('rep_user_id')->map(fn ($rows) => [
                'rep' => $rows->first()->rep,
                'assignments' => $rows->values(),
                'pharmacies' => $rows->sum(fn ($a) => $counts[$a->id] ?? 0),
            ])->sortBy(fn ($r) => (string) $r['rep']?->name, SORT_NATURAL)->values(),
        ]);

        // التنبيهات (لا شيء يُتجاهل بصمت)
        $repsWithoutSupervisor = $byRep->filter(fn ($r) => $r['assignments']->isEmpty())->values();
        $unassignedCustomers = $byRep->filter(fn ($r) => $r['assignments']->isNotEmpty())
            ->flatMap(fn ($r) => $r['unassigned']->flatMap(fn ($u) => $u['ids']))->unique();
        $alerts = [
            'reps_without_supervisor' => $repsWithoutSupervisor->count(),
            'no_scope_customers' => count($index['none']),
            'unassigned_customers' => $unassignedCustomers->count(),
            'reps_without_cost_center' => $reps->reject->hasCostCenter()->count(),
        ];

        // البحث والترتيب
        $matches = fn (?string $name) => $q === '' || str_contains(ArabicNormalizer::normalize((string) $name), $q);
        $bySupervisor = $bySupervisor->filter(fn ($s) => $matches($s['supervisor']->name) || $s['reps']->contains(fn ($r) => $matches($r['rep']?->name)))->values();
        $byRep = $byRep->filter(fn ($r) => $matches($r['rep']->name) || $r['supervisors']->contains(fn ($s) => $matches($s->name)))->values();
        if ($sort === 'count') {
            $bySupervisor = $bySupervisor->sortByDesc(fn ($s) => $s['reps']->count())->values();
            $byRep = $byRep->sortByDesc('unassigned_count')->values();
        }

        $noScopeCustomers = $view === 'no-scope'
            ? Customer::whereIn('id', $index['none'] ?: [0])->orderBy('name')->get(['id', 'name', 'balance'])
            : collect();

        $logs = ActivityLog::visibleTo($request->user())->with('user:id,name')
            ->where(fn ($qq) => $qq->where('action', 'like', 'rep_assignment.%')->orWhere('action', 'like', 'rep_scoping.%'))
            ->orderByDesc('id')->limit(50)->get();

        return view('admin.rep-assignments', [
            'view' => $view,
            'filters' => ['q' => $data['q'] ?? null, 'sort' => $sort],
            'supervisors' => $supervisors,
            'reps' => $reps,
            'bySupervisor' => $bySupervisor,
            'byRep' => $byRep,
            'counts' => $counts,
            'alerts' => $alerts,
            'repsWithoutSupervisor' => $repsWithoutSupervisor,
            'areas' => collect($index['area'])->map(fn ($a, $key) => ['value' => $key, 'label' => $a['label'], 'count' => count($a['ids'])])->values(),
            'blocks' => collect($index['block'])->map(fn ($b, $key) => ['value' => $key, 'label' => $b['label'], 'count' => count($b['ids'])])->values(),
            'noScopeCustomers' => $noScopeCustomers,
            'logs' => $logs,
            'scopingEnabled' => RepScope::enabled(),
            'planCounts' => $this->planCounts($assignments),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'rep_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'supervisor_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'scope_type' => ['required', Rule::in(array_keys(RepSupervisorAssignment::TYPES))],
            'scope_values' => 'nullable|array',
            'scope_values.*' => 'nullable|string|max:255',
        ], [
            'rep_id.required' => 'اختر المندوب.',
            'supervisor_id.required' => 'اختر المشرف.',
            'scope_type.required' => 'اختر النطاق.',
        ]);

        $created = $this->service->create(User::findOrFail($data['rep_id']), User::findOrFail($data['supervisor_id']), $data['scope_type'], $data['scope_values'] ?? [], $request->user());

        return back()->with('success', 'أُسند المندوب: '.$created->map->scopeText()->implode('، ').'.');
    }

    public function end(Request $request, RepSupervisorAssignment $assignment)
    {
        $this->service->end($assignment, $request->user());
        $disabled = $this->service->planItemsAffected;

        return back()->with('success', 'أُنهي الإسناد ('.$assignment->scopeText().').')
            ->with('warning', $disabled > 0 ? $disabled.' بنداً في الخطة تعطّل لأن النطاق بلا مشرف (لم تُحذف؛ أعد إسناد النطاق ثم أضفها للخطة).' : null);
    }

    public function move(Request $request, RepSupervisorAssignment $assignment)
    {
        $data = $request->validate([
            'supervisor_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'confirm' => 'accepted',
        ], ['supervisor_id.required' => 'اختر المشرف الجديد.', 'confirm.accepted' => 'أكّد نقل النطاق.']);

        $new = $this->service->move($assignment, User::findOrFail($data['supervisor_id']), $request->user());

        $moved = $this->service->planItemsAffected;

        return back()->with('success', 'نُقل '.$new->scopeText().' للمندوب '.$new->rep?->name.' إلى المشرف '.$new->supervisor?->name.'.'
            .($moved > 0 ? ' وانتقل معه '.$moved.' بنداً من خطة المندوب، وأُشعر المندوب.' : ''));
    }

    public function scoping(Request $request)
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'confirm' => [Rule::requiredIf(fn () => $request->boolean('enabled')), 'accepted_if:enabled,1'],
        ], ['confirm.required' => 'أكّد تفعيل الحصر.', 'confirm.accepted' => 'أكّد تفعيل الحصر.']);

        $withAssignments = RepSupervisorAssignment::active()->distinct()->pluck('rep_user_id');
        $without = User::where('role', Role::Rep)->whereNotIn('id', $withAssignments->all() ?: [0])->count();
        $this->service->setScoping((bool) $data['enabled'], $without);

        return back()->with('success', $data['enabled']
            ? 'فُعّل حصر رؤية مشرفي المندوبين. '.($without > 0 ? $without.' مندوباً بلا مشرف يراهم الأدمن فقط.' : '')
            : 'أُوقف الحصر: مشرفو المندوبين يرون كل المندوبين.');
    }

    /**
     * المرحلة 8B: بنود الخطة الحالية/المستقبلية التي يحملها كل إسناد (تنتقل مع النقل وتتعطّل مع الإنهاء).
     *
     * @return array<int, int>
     */
    private function planCounts(Collection $assignments): array
    {
        return (new \App\Services\Reps\RepPlanService($this->scope))->countsForAssignments($assignments);
    }
}
