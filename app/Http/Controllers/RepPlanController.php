<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\RepPlanNotice;
use App\Models\RepPlanTemplate;
use App\Models\RepPlanVisit;
use App\Services\Finance\RepReceivableService;
use App\Services\Reps\RepPlanService;
use App\Services\Reps\RepPlanVisitService;
use App\Services\Reps\RepScope;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * المرحلة 8B: «خطتي» للمندوب (/rep/plan): صيدليات خطة اليوم مجمَّعة بالبند ووسم المشرف، وتسجيل الزيارة.
 * الرصيد بقاعدة خصوصية المندوب نفسها في شاشاته: دَينه المعروف عند الصيدلية من فواتير مركز كلفته فقط (RepReceivableService).
 */
class RepPlanController extends Controller
{
    private RepPlanService $plans;

    private RepPlanVisitService $visits;

    /** نسخ جديدة لكل طلب (RepScope يحفظ فهرس الزبائن في نسخته، والموجّه يحتفظ بنسخة المتحكم) */
    public function callAction($method, $parameters)
    {
        $this->plans = new RepPlanService(app(RepScope::class));
        $this->visits = new RepPlanVisitService($this->plans, app(\App\Services\LocationService::class));

        return $this->{$method}(...array_values($parameters));
    }

    public function show(Request $request, RepReceivableService $receivables)
    {
        $rep = $request->user();
        $data = $request->validate(['date' => 'nullable|date_format:Y-m-d']);
        $today = RepPlanService::today();
        $date = $data['date'] ?? $today;

        $plan = $this->plans->effective($rep, $date);
        $ids = array_merge([], ...array_column($plan, 'customer_ids'));
        $customers = Customer::whereIn('id', $ids ?: [0])->get(['id', 'name', 'area', 'block', 'latitude', 'longitude'])->keyBy('id');
        // toBase: except/only في مجموعة Eloquent تعمل على المفتاح الأساسي للنموذج لا على مفاتيح المجموعة
        $visits = RepPlanVisit::where('rep_user_id', $rep->id)->whereDate('plan_date', $date)->get()->keyBy('customer_id')->toBase();
        $debts = $rep->hasCostCenter() && $ids ? $receivables->state($rep, $ids)->map(fn ($s) => $s['remaining_cents'] / 100)->all() : [];

        // زيارات مسجَّلة لصيدليات أُزيلت من خطة اليوم: تبقى ظاهرة ومحتسبة
        $orphans = $visits->except($ids);
        if ($orphans->isNotEmpty()) {
            $customers = $customers->union(Customer::whereIn('id', $orphans->keys())->get(['id', 'name', 'area', 'block', 'latitude', 'longitude'])->keyBy('id'));
        }

        $planned = count($ids);
        $visited = $visits->only($ids)->where('status', RepPlanVisit::VISITED)->count();
        $notVisited = $visits->only($ids)->where('status', RepPlanVisit::NOT_VISITED)->count();

        $weekStart = Carbon::parse($date)->subDays(RepPlanTemplate::weekdayOf($date));
        $week = collect(range(0, 6))->map(fn ($d) => $weekStart->copy()->addDays($d)->toDateString());

        return view('rep.plan', [
            'date' => $date,
            'today' => $today,
            'isToday' => $date === $today,
            'canRecord' => $this->visits->repCanRecord($date),
            'isFuture' => $date > $today,
            'plan' => $plan,
            'customers' => $customers,
            'visits' => $visits,
            'orphans' => $orphans,
            'debts' => $debts,
            'summary' => ['planned' => $planned, 'visited' => $visited, 'not_visited' => $notVisited, 'remaining' => $planned - $visited - $notVisited],
            'week' => $week,
            'prevWeek' => $weekStart->copy()->subDays(7)->toDateString(),
            'nextWeek' => $weekStart->copy()->addDays(7)->toDateString(),
            'notices' => RepPlanNotice::where('rep_user_id', $rep->id)->whereNull('seen_at')->orderByDesc('id')->limit(10)->get(),
            'config' => [
                'url' => route('rep.plan.visits'),
                'date' => $date,
                'minAccuracy' => RepPlanService::setting('plan_min_gps_accuracy_m'),
                'reasons' => RepPlanVisit::REASONS,
            ],
        ]);
    }

    /**
     * تسجيل/تصحيح زيارة (JSON). 201 حفظ، 200 مكرر/تصحيح، 409 يحتاج تأكيداً أو مسجَّلة مسبقاً، 422 غير مسموح.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'client_uuid' => 'required|uuid',
            'customer_id' => 'required|integer',
            'plan_date' => 'required|date_format:Y-m-d',
            'status' => ['required', Rule::in([RepPlanVisit::VISITED, RepPlanVisit::NOT_VISITED])],
            'reason_code' => ['nullable', Rule::in(array_keys(RepPlanVisit::REASONS))],
            'reason_text' => 'nullable|string|max:500',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0|max:100000',
            'confirm' => ['nullable', Rule::in([RepPlanVisit::NO_GPS, RepPlanVisit::LOW_ACCURACY, RepPlanVisit::MISMATCH])],
            'correction' => 'nullable|boolean',
        ]);
        $data['correction'] = (bool) ($data['correction'] ?? false);

        $result = $this->visits->record($request->user(), $data);
        $visit = $result['visit'] ?? null;

        return response()->json([
            'result' => $result['result'],
            'message' => $result['message'],
            'confirm' => $result['confirm'] ?? null,
            'distance_m' => $result['distance_m'] ?? $visit?->distance_m,
            'accuracy_m' => $result['accuracy_m'] ?? $visit?->accuracy_m,
            'visit' => $visit ? [
                'customer_id' => $visit->customer_id,
                'status' => $visit->status,
                'location_status' => $visit->location_status,
                'text' => $visit->statusText(),
            ] : null,
        ], $result['http']);
    }

    public function seen(Request $request, RepPlanNotice $notice)
    {
        abort_unless((int) $notice->rep_user_id === $request->user()->id, 403);
        $notice->update(['seen_at' => now()]);

        return back();
    }
}
