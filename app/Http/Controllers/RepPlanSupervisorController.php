<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\RepPlanTemplate;
use App\Models\RepPlanVisit;
use App\Models\User;
use App\Services\Reps\RepCoverageService;
use App\Services\Reps\RepPlanService;
use App\Services\Reps\RepPlanVisitService;
use App\Services\Reps\RepScope;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * المرحلة 8B: «خطط المندوبين» للمشرف والأدمن: قائمة المندوبين (بإسناد 8A)، شبكة الخطة الأسبوعية، التعديل بتاريخ،
 * وتصحيح الزيارة بعد انتهاء يومها. المشرف يعدّل بنوده فقط ضمن نطاقاته، ويرى بنود المشرف الآخر «مشغول» بلا تفاصيل.
 */
class RepPlanSupervisorController extends Controller
{
    private RepPlanService $plans;

    public function callAction($method, $parameters)
    {
        $this->plans = new RepPlanService(app(RepScope::class));

        return $this->{$method}(...array_values($parameters));
    }

    public function index(Request $request)
    {
        $viewer = $request->user();
        $reps = $this->plans->repsFor($viewer);
        $today = RepPlanService::today();
        $coverage = (new RepCoverageService($this->plans))->build($viewer, ['from' => $today, 'to' => $today]);
        $items = RepPlanTemplate::current()->whereIn('rep_user_id', $reps->pluck('id')->all() ?: [0])
            ->when(! $viewer->isAdmin(), fn ($q) => $q->where('supervisor_user_id', $viewer->id))
            ->selectRaw('rep_user_id, COUNT(*) as n')->groupBy('rep_user_id')->pluck('n', 'rep_user_id');

        return view('rep-supervisor.plans', [
            'reps' => $reps,
            'items' => $items,
            'today' => $coverage['repTotals'],
            'disabled' => $viewer->isAdmin() ? RepPlanService::disabledCount() : 0,
        ]);
    }

    public function show(Request $request, User $rep)
    {
        $viewer = $request->user();
        abort_unless($this->plans->canManageRep($viewer, $rep), $rep->role === \App\Enums\Role::Rep ? 403 : 404, 'هذا المندوب خارج نطاقك.');
        $data = $request->validate(['date' => 'nullable|date_format:Y-m-d']);
        $today = RepPlanService::today();
        $date = $data['date'] ?? $today;

        $templates = RepPlanTemplate::with('supervisor:id,name')->current()->where('rep_user_id', $rep->id)->orderBy('id')->get();
        $ctx = $this->plans->context([$rep->id], $today, $today);
        $noLocation = array_fill_keys(Customer::whereNull('latitude')->orWhereNull('longitude')->pluck('id')->all(), true);
        $mine = fn ($ownerId) => $viewer->isAdmin() || (int) $ownerId === $viewer->id;

        $grid = [];
        foreach (RepPlanTemplate::WEEKDAYS as $wd => $name) {
            $grid[$wd] = ['name' => $name, 'items' => []];
        }
        foreach ($templates as $t) {
            $ids = $mine($t->supervisor_user_id) ? $this->plans->pharmaciesFor($ctx, $rep->id, $t->scope_type, $t->scope_value, $t->supervisor_user_id) : [];
            $grid[$t->weekday]['items'][] = [
                'template' => $t,
                'mine' => $mine($t->supervisor_user_id),
                'count' => count($ids),
                'no_location' => count(array_filter($ids, fn ($id) => isset($noLocation[$id]))),
                'days' => $templates->where('scope_type', $t->scope_type)->where('scope_value', $t->scope_value)->pluck('weekday')->unique()->count(),
            ];
        }

        // التعديل بتاريخ: خطة ذلك اليوم الفعّالة، وزياراته لكل بند
        $dayPlan = $this->plans->effective($rep, $date);
        $visitCounts = RepPlanVisit::where('rep_user_id', $rep->id)->whereDate('plan_date', $date)
            ->selectRaw('scope_type, scope_value, COUNT(*) as n')->groupBy('scope_type', 'scope_value')->get()
            ->mapWithKeys(fn ($r) => [$r->scope_type.'|'.$r->scope_value => (int) $r->n]);

        return view('rep-supervisor.plan', [
            'rep' => $rep,
            'grid' => $grid,
            'options' => $this->plans->scopeOptions($rep, $viewer),
            'date' => $date,
            'today' => $today,
            'dayPlan' => $dayPlan,
            'visitCounts' => $visitCounts,
            'canEditDate' => $viewer->isAdmin() || $date >= $today,
            'disabledTemplates' => RepPlanTemplate::with('supervisor:id,name')->where('rep_user_id', $rep->id)->where('active', false)->orderByDesc('id')->limit(20)->get(),
            'isAdmin' => $viewer->isAdmin(),
        ]);
    }

    public function storeTemplate(Request $request, User $rep)
    {
        abort_unless($this->plans->canManageRep($request->user(), $rep), 403, 'هذا المندوب خارج نطاقك.');
        $data = $request->validate([
            'weekday' => 'required|integer|between:0,6',
            'scope' => 'required|string|max:600',
        ], ['scope.required' => 'اختر منطقة أو كتلة.']);
        [$owner, $type, $value] = $this->parseScope($data['scope']);

        $result = $this->plans->addTemplate($rep, $request->user(), (int) $data['weekday'], $type, $value, $owner);

        return back()->with('success', 'أُضيفت '.$result['template']->scopeText().' إلى خطة يوم '.RepPlanTemplate::WEEKDAYS[(int) $data['weekday']].' (تسري من اليوم، ولا تتغيّر الزيارات السابقة).')
            ->with('warning', $result['warnings'] ? implode(' ', $result['warnings']) : null);
    }

    public function destroyTemplate(Request $request, RepPlanTemplate $template)
    {
        $rep = User::findOrFail($template->rep_user_id);
        abort_unless($this->plans->canManageRep($request->user(), $rep), 403, 'هذا المندوب خارج نطاقك.');
        if (! $template->active && $request->user()->isAdmin()) {
            // بند معطَّل (نطاق بلا مشرف): الأدمن يحذفه نهائياً
            $old = $template->logValues();
            $template->delete();
            \App\Models\ActivityLog::record('rep_plan.template_deleted', null, $old, [], ['rep' => $rep->name]);

            return back()->with('success', 'حُذف البند المعطَّل.');
        }

        return back()->with('success', $this->plans->removeTemplate($template, $request->user()));
    }

    public function storeOverride(Request $request, User $rep)
    {
        abort_unless($this->plans->canManageRep($request->user(), $rep), 403, 'هذا المندوب خارج نطاقك.');
        $data = $request->validate([
            'plan_date' => 'required|date_format:Y-m-d',
            'scope' => 'required|string|max:600',
            'reason' => 'nullable|string|max:500',
        ], ['scope.required' => 'اختر منطقة أو كتلة.']);
        [$owner, $type, $value] = $this->parseScope($data['scope']);

        return back()->with('success', $this->plans->addForDate($rep, $request->user(), $data['plan_date'], $type, $value, $data['reason'] ?? null, $owner));
    }

    public function removeOverride(Request $request, User $rep)
    {
        abort_unless($this->plans->canManageRep($request->user(), $rep), 403, 'هذا المندوب خارج نطاقك.');
        $data = $request->validate([
            'plan_date' => 'required|date_format:Y-m-d',
            'scope_type' => ['required', Rule::in(array_keys(RepPlanTemplate::TYPES))],
            'scope_value' => 'required|string|max:255',
            'reason' => 'nullable|string|max:500',
        ]);

        return back()->with('success', $this->plans->removeForDate($rep, $request->user(), $data['plan_date'], $data['scope_type'], $data['scope_value'], $data['reason'] ?? null));
    }

    public function correctVisit(Request $request, RepPlanVisit $visit, RepPlanVisitService $visits)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([RepPlanVisit::VISITED, RepPlanVisit::NOT_VISITED])],
            'reason_code' => ['nullable', Rule::in(array_keys(RepPlanVisit::REASONS))],
            'reason_text' => 'nullable|string|max:500',
            'note' => 'required|string|min:3|max:500',
        ], ['note.required' => 'اكتب سبب التصحيح.']);
        $visits->correctBySupervisor($visit, $request->user(), $data['status'], $data['reason_code'] ?? null, $data['reason_text'] ?? null, $data['note']);

        return back()->with('success', 'صُحّحت الزيارة وسُجّل التصحيح بسببه.');
    }

    /** «مالك|نوع|قيمة» من خيار القائمة */
    private function parseScope(string $scope): array
    {
        $parts = explode('|', $scope, 3);
        if (count($parts) !== 3 || ! isset(RepPlanTemplate::TYPES[$parts[1]])) {
            abort(422, 'نطاق غير صالح.');
        }

        return [(int) $parts[0], $parts[1], $parts[2]];
    }
}
