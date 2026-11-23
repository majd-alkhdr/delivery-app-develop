<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\RepPlanTemplate;
use App\Models\User;
use App\Services\Reps\RepCoverageExcel;
use App\Services\Reps\RepCoverageService;
use App\Services\Reps\RepPlanService;
use App\Services\Reps\RepScope;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * المرحلة 8B: لوحة التغطية (/rep-supervisor/coverage) وتفاصيل اليوم والتصدير. المشرف: بنوده وزياراته فقط؛ الأدمن الكل أو مشرف يختاره.
 */
class RepCoverageController extends Controller
{
    public const PERIODS = ['today' => 'اليوم', 'week' => 'هذا الأسبوع', 'month' => 'هذا الشهر', 'range' => 'نطاق تواريخ'];

    private RepPlanService $plans;

    private RepCoverageService $coverage;

    public function callAction($method, $parameters)
    {
        $this->plans = new RepPlanService(app(RepScope::class));
        $this->coverage = new RepCoverageService($this->plans);

        return $this->{$method}(...array_values($parameters));
    }

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $data = $this->coverage->build($request->user(), $filters);

        return view('rep-supervisor.coverage', $data + [
            'filters' => $filters,
            'repOptions' => $this->plans->repsFor($request->user()),
            'supervisorOptions' => $request->user()->isAdmin() ? User::where('role', Role::RepSupervisor->value)->orderBy('name')->get(['id', 'name']) : collect(),
            'scopeOptions' => $this->scopeOptions($request->user(), $filters['supervisor_id']),
            'periods' => self::PERIODS,
        ]);
    }

    public function day(Request $request, User $rep, string $date)
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1, 404);
        $supervisorId = $request->user()->isAdmin() ? $request->integer('supervisor_id') ?: null : null;
        $data = $this->coverage->dayDetails($request->user(), $rep->id, $date, $supervisorId);
        abort_unless($data['reps']->has($rep->id), 403, 'هذا المندوب خارج نطاقك.');

        return view('rep-supervisor.coverage-day', $data + ['rep' => $rep, 'date' => $date, 'canCorrect' => true]);
    }

    public function export(Request $request, RepCoverageExcel $excel)
    {
        $filters = $this->filters($request);
        $viewer = $request->user();
        $data = $this->coverage->build($viewer, $filters, true);
        $scopeText = $viewer->isAdmin()
            ? ($filters['supervisor_id'] ? 'مقيَّد بنطاق المشرف '.(User::find($filters['supervisor_id'])?->name ?? '—') : 'كل المشرفين (الأدمن)')
            : 'مقيَّد بنطاق المشرف '.$viewer->name;
        $path = $excel->build($data, $scopeText);

        return response()->download($path, 'تغطية-المندوبين-'.$filters['from'].'-'.$filters['to'].'.xlsx')->deleteFileAfterSend();
    }

    /**
     * @return array{rep_id: ?int, supervisor_id: ?int, period: string, from: string, to: string, scope: ?string}
     */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'rep_id' => 'nullable|integer',
            'supervisor_id' => 'nullable|integer',
            'period' => ['nullable', Rule::in(array_keys(self::PERIODS))],
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'scope' => 'nullable|string|max:300',
        ]);
        $today = Carbon::parse(RepPlanService::today());
        $period = $data['period'] ?? 'week';
        [$from, $to] = match ($period) {
            'today' => [$today, $today],
            'month' => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
            'range' => [Carbon::parse($data['from'] ?? $today), Carbon::parse($data['to'] ?? $data['from'] ?? $today)],
            default => [$today->copy()->subDays(RepPlanTemplate::weekdayOf($today)), $today->copy()->subDays(RepPlanTemplate::weekdayOf($today))->addDays(6)],
        };
        if ($from->diffInDays($to) > 92) {
            abort(422, 'أقصى فترة 93 يوماً.');
        }

        return [
            'rep_id' => $data['rep_id'] ?? null,
            'supervisor_id' => $request->user()->isAdmin() ? ($data['supervisor_id'] ?? null) : null,
            'period' => $period,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'scope' => $data['scope'] ?? null,
        ];
    }

    /** نطاقات الخطط المرئية للمشاهِد (لمرشّح النطاق) */
    private function scopeOptions(User $viewer, ?int $supervisorId): array
    {
        $owner = $viewer->isAdmin() ? $supervisorId : $viewer->id;

        return RepPlanTemplate::query()->when($owner !== null, fn ($q) => $q->where('supervisor_user_id', $owner))
            ->select('scope_type', 'scope_value', 'scope_label')->distinct()->orderBy('scope_label')->get()
            ->mapWithKeys(fn ($t) => [$t->scope_type.'|'.$t->scope_value => RepPlanTemplate::TYPES[$t->scope_type].' '.($t->scope_label ?? $t->scope_value)])->all();
    }
}
