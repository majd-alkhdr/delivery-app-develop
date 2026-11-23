<?php

namespace App\Services\Reps;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\RepPlanTemplate;
use App\Models\RepPlanVisit;
use App\Models\RepSupervisorAssignment;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/**
 * المرحلة 8B: التغطية.
 *
 * المخطَّط لـ(مندوب، يوم، نطاق) = صيدليات البند في خطة ذلك اليوم ∪ الصيدليات التي سُجّلت لها زيارة في النطاق نفسه ذلك اليوم
 * (إزالة نطاق بعد زيارات لا تُسقط الزيارات من الحساب، فلا تتجاوز النسبة 100%).
 * نسبة التغطية = المزارة ÷ المخطَّط، والمطابقة = matched ÷ المزارة. حالات الموقع الخمس تُعدّ كلٌّ على حدة.
 * المشرف يرى بنوده وزياراته فقط (supervisor_user_id للبند/لقطة الزيارة)، والأدمن الكل أو مشرفاً يختاره.
 * الاستعلامات ثابتة العدد مهما كان عدد المندوبين والأيام.
 */
class RepCoverageService
{
    public function __construct(private RepPlanService $plans)
    {
    }

    /**
     * @param  array{rep_id?: ?int, supervisor_id?: ?int, from: string, to: string, scope?: ?string}  $filters
     */
    public function build(User $viewer, array $filters, bool $withRows = false): array
    {
        $ownerId = $viewer->isAdmin() ? ($filters['supervisor_id'] ?? null) : $viewer->id;
        $ownerId = $ownerId !== null ? (int) $ownerId : null;
        [$from, $to] = [$filters['from'], $filters['to']];
        $scopeFilter = $filters['scope'] ?? null;

        // المندوبون: من يملك المشرف نطاقاً له الآن، أو له زيارات للمشرف في الفترة (التاريخ يبقى لمالكه)
        $repIds = $this->repIds($ownerId, $from, $to);
        if (! empty($filters['rep_id'])) {
            $repIds = array_values(array_intersect($repIds, [(int) $filters['rep_id']]));
        }
        $reps = User::whereIn('id', $repIds ?: [0])->orderBy('name')->get(['id', 'name'])->keyBy('id');

        $ctx = $this->plans->context($repIds, $from, $to);
        $visits = RepPlanVisit::whereIn('rep_user_id', $repIds ?: [0])
            ->whereDate('plan_date', '>=', $from)->whereDate('plan_date', '<=', $to)
            ->when($ownerId !== null, fn ($q) => $q->where('supervisor_user_id', $ownerId))
            ->get()->groupBy(fn ($v) => $v->rep_user_id.'|'.$v->plan_date->toDateString());

        $empty = fn () => ['planned' => 0, 'visited' => 0, 'not_visited' => 0, 'remaining' => 0,
            'location' => array_fill_keys(array_keys(RepPlanVisit::LOCATION_LABELS), 0), 'reasons' => []];
        $cards = [];      // rep|scopeKey
        $repTotals = [];  // rep
        $daily = [];      // date|rep
        $weekly = [];     // week start
        $plannedIds = [];
        $rows = [];

        foreach ($repIds as $repId) {
            foreach (CarbonPeriod::create($from, $to) as $day) {
                $date = $day->toDateString();
                $dayVisits = $visits[$repId.'|'.$date] ?? collect();
                $byScope = [];
                foreach ($this->plans->dayPlan($ctx, $repId, $date, $ownerId) as $item) {
                    $byScope[$item['key']] = ['label' => $item['text'], 'supervisor' => $item['supervisor_name'], 'ids' => array_fill_keys($item['customer_ids'], true)];
                }
                foreach ($dayVisits as $v) {
                    $key = $v->scope_type.'|'.$v->scope_value;
                    $byScope[$key] ??= ['label' => (RepPlanTemplate::TYPES[$v->scope_type] ?? '').' '.$v->scope_value.' (أُزيلت من الخطة)', 'supervisor' => null, 'ids' => []];
                    $byScope[$key]['ids'][$v->customer_id] = true;
                }
                if ($scopeFilter !== null) {
                    $byScope = array_intersect_key($byScope, [$scopeFilter => true]);
                }
                if ($byScope === []) {
                    continue;
                }
                $visitOf = $dayVisits->keyBy('customer_id');
                $dayStats = $empty();
                foreach ($byScope as $key => $scope) {
                    $stats = $empty();
                    foreach (array_keys($scope['ids']) as $customerId) {
                        $plannedIds[$customerId] = true;
                        $v = $visitOf[$customerId] ?? null;
                        $this->count($stats, $v);
                        if ($withRows) {
                            $rows[] = ['date' => $date, 'rep_id' => $repId, 'scope' => $scope['label'], 'supervisor' => $scope['supervisor'], 'customer_id' => $customerId, 'visit' => $v];
                        }
                    }
                    $cardKey = $repId.'|'.$key;
                    $cards[$cardKey] ??= ['rep_id' => $repId, 'scope' => $scope['label'], 'scope_key' => $key] + $empty();
                    $this->merge($cards[$cardKey], $stats);
                    $this->merge($dayStats, $stats);
                }
                $daily[$date.'|'.$repId] = ['date' => $date, 'rep_id' => $repId, 'scopes' => array_column($byScope, 'label')] + $dayStats;
                $repTotals[$repId] ??= ['rep_id' => $repId] + $empty();
                $this->merge($repTotals[$repId], $dayStats);
                $week = $this->weekStart($date);
                $weekly[$week] ??= ['week' => $week] + $empty();
                $this->merge($weekly[$week], $dayStats);
            }
        }

        $noLocation = $this->noLocation(array_keys($plannedIds));
        $totals = $empty();
        foreach ($repTotals as $t) {
            $this->merge($totals, $t);
        }
        ksort($weekly);
        krsort($daily);

        return [
            'from' => $from, 'to' => $to, 'owner_id' => $ownerId,
            'reps' => $reps,
            'cards' => array_values($cards),
            'repTotals' => $repTotals,
            'totals' => $totals,
            'daily' => array_values($daily),
            'weekly' => array_values($weekly),
            'noLocation' => $noLocation,
            'alerts' => $this->alerts($repTotals, $daily, $reps, $noLocation),
            'rows' => $rows,
        ];
    }

    private function repIds(?int $ownerId, string $from, string $to): array
    {
        if ($ownerId === null) {
            return User::where('role', Role::Rep->value)->orderBy('name')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }
        $assigned = RepSupervisorAssignment::active()->where('supervisor_user_id', $ownerId)->pluck('rep_user_id')->all();
        $visited = RepPlanVisit::where('supervisor_user_id', $ownerId)->whereDate('plan_date', '>=', $from)->whereDate('plan_date', '<=', $to)
            ->distinct()->pluck('rep_user_id')->all();

        return array_values(array_unique(array_map('intval', array_merge($assigned, $visited))));
    }

    private function count(array &$stats, ?RepPlanVisit $v): void
    {
        $stats['planned']++;
        if (! $v) {
            $stats['remaining']++;
        } elseif ($v->status === RepPlanVisit::VISITED) {
            $stats['visited']++;
            if (isset($stats['location'][$v->location_status])) {
                $stats['location'][$v->location_status]++;
            }
        } else {
            $stats['not_visited']++;
            $stats['reasons'][$v->reason_code] = ($stats['reasons'][$v->reason_code] ?? 0) + 1;
        }
    }

    private function merge(array &$into, array $stats): void
    {
        foreach (['planned', 'visited', 'not_visited', 'remaining'] as $k) {
            $into[$k] += $stats[$k];
        }
        foreach ($stats['location'] as $k => $c) {
            $into['location'][$k] += $c;
        }
        foreach ($stats['reasons'] as $k => $c) {
            $into['reasons'][$k] = ($into['reasons'][$k] ?? 0) + $c;
        }
    }

    public static function pct(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part * 100 / $whole, 1) : null;
    }

    /** بداية الأسبوع (السبت) */
    private function weekStart(string $date): string
    {
        return Carbon::parse($date)->subDays(RepPlanTemplate::weekdayOf($date))->toDateString();
    }

    /** الصيدليات المخطَّطة بلا موقع محفوظ (استعلام على دفعات) */
    private function noLocation(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (Customer::whereIn('id', $chunk)->where(fn ($q) => $q->whereNull('latitude')->orWhereNull('longitude'))->orderBy('name')->get(['id', 'name', 'area']) as $c) {
                $out[$c->id] = ['id' => $c->id, 'name' => $c->name, 'area' => $c->area];
            }
        }

        return array_values($out);
    }

    /**
     * تنبيهات: لا نشاط بعد ساعة الإعداد اليوم، بعيدة > 30% من زيارات اليوم، صيدليات بلا موقع في الخطة.
     */
    private function alerts(array $repTotals, array $daily, $reps, array $noLocation): array
    {
        $alerts = [];
        $today = RepPlanService::today();
        $hour = RepPlanService::setting('plan_no_activity_hour');
        $now = now(RepPlanService::TZ);
        foreach ($daily as $d) {
            if ($d['date'] !== $today) {
                continue;
            }
            $name = $reps[$d['rep_id']]->name ?? '#'.$d['rep_id'];
            $recorded = $d['visited'] + $d['not_visited'];
            if ($d['planned'] > 0 && $recorded === 0 && $now->hour >= $hour) {
                $alerts[] = ['level' => 'danger', 'text' => 'المندوب '.$name.' لديه خطة اليوم ('.$d['planned'].' صيدلية) ولم يسجّل شيئاً بعد الساعة '.$hour.':00.'];
            }
            $mismatch = $d['location'][RepPlanVisit::MISMATCH];
            if ($d['visited'] > 0 && $mismatch / $d['visited'] > 0.3) {
                $alerts[] = ['level' => 'warning', 'text' => 'المندوب '.$name.': '.$mismatch.' من '.$d['visited'].' زيارة اليوم بعيدة عن موقع الصيدلية (أكثر من 30%).'];
            }
        }
        if ($noLocation) {
            $alerts[] = ['level' => 'info', 'text' => count($noLocation).' صيدلية في الخطة بلا موقع محفوظ، فلا تُطابَق زياراتها. القائمة أسفل الصفحة.'];
        }

        return $alerts;
    }

    /**
     * تفاصيل يوم لمندوب: الصيدليات بحالتها (من build بالصفوف).
     */
    public function dayDetails(User $viewer, int $repId, string $date, ?int $supervisorId = null): array
    {
        $data = $this->build($viewer, ['rep_id' => $repId, 'supervisor_id' => $supervisorId, 'from' => $date, 'to' => $date], true);
        $customers = Customer::whereIn('id', array_column($data['rows'], 'customer_id') ?: [0])->get(['id', 'name', 'area', 'latitude', 'longitude'])->keyBy('id');

        return $data + ['customers' => $customers];
    }
}
