<?php

namespace App\Services\Reps;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\RepPlanNotice;
use App\Models\RepPlanOverride;
use App\Models\RepPlanTemplate;
use App\Models\RepPlanVisit;
use App\Models\RepSupervisorAssignment;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * المرحلة 8B: الخطة الفعّالة لمندوب وتعديلها.
 *
 * effective(rep, date) = بنود القالب السارية لـ weekday(date) − «إزالة» ذلك التاريخ + «إضافة» ذلك التاريخ.
 * صيدليات البند = زبائن المنطقة/الكتلة (المطابقة المطبَّعة من 8A: RepScope::customersOf) غير المخفيين،
 * محصورة بما يملكه مشرف البند لهذا المندوب (RepScope::ownerOf). الصيدلية في بندين تُنسب للأول.
 *
 * الحساب في الذاكرة من «سياق» يُحمَّل باستعلامات ثابتة العدد (القوالب، التعديلات، الإسنادات، الزبائن المخفيون/بلا موقع)
 * مهما كان عدد المندوبين والأيام (لوحة التغطية).
 */
class RepPlanService
{
    public const TZ = 'Asia/Damascus';

    /** @var array<string, array<int, int>> صيدليات (مندوب، بند، مشرف) */
    private array $pharmacyCache = [];

    public function __construct(private RepScope $scope)
    {
    }

    public static function today(): string
    {
        return now(self::TZ)->toDateString();
    }

    // =====================================================================
    // الإعدادات (القسم 8)
    // =====================================================================

    public const SETTINGS = [
        'plan_location_max_distance_m' => 150,
        'plan_min_gps_accuracy_m' => 100,
        'rep_plan_backfill_days' => 0,
        'plan_no_activity_hour' => 13,
    ];

    public static function setting(string $key): int
    {
        $default = self::SETTINGS[$key];
        if ($key === 'plan_location_max_distance_m') {
            $default = (int) Setting::get('location_radius_m', 150);
        }

        return (int) Setting::get($key, $default);
    }

    // =====================================================================
    // السياق والحساب
    // =====================================================================

    /**
     * @param  array<int, int>  $repIds
     */
    public function context(array $repIds, string $from, string $to): array
    {
        $repIds = array_values(array_unique(array_map('intval', $repIds))) ?: [0];

        return [
            'templates' => RepPlanTemplate::with('supervisor:id,name')->whereIn('rep_user_id', $repIds)
                ->whereDate('starts_on', '<=', $to)->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $from))
                ->orderBy('id')->get()->groupBy('rep_user_id'),
            'overrides' => RepPlanOverride::with('supervisor:id,name')->whereIn('rep_user_id', $repIds)
                ->whereDate('plan_date', '>=', $from)->whereDate('plan_date', '<=', $to)->orderBy('id')->get()
                ->groupBy(fn ($o) => $o->rep_user_id.'|'.$o->plan_date->toDateString()),
            'assignments' => RepSupervisorAssignment::active()->whereIn('rep_user_id', $repIds)->orderBy('id')->get()->groupBy('rep_user_id'),
            'hidden' => array_fill_keys(Customer::where('is_hidden', true)->pluck('id')->all(), true),
        ];
    }

    /**
     * بنود يوم لمندوب: [key, type, value, label, supervisor_id, supervisor_name, source (template|override), id].
     */
    public function itemsFor(array $ctx, int $repId, string $date): array
    {
        $weekday = RepPlanTemplate::weekdayOf($date);
        $items = [];
        foreach ($ctx['templates'][$repId] ?? [] as $t) {
            if ($t->weekday !== $weekday || $t->starts_on->toDateString() > $date || ($t->ends_on && $t->ends_on->toDateString() < $date)) {
                continue;
            }
            $key = $t->scope_type.'|'.$t->scope_value;
            $items[$key] ??= $this->item($key, $t, 'template');
        }
        $overrides = $ctx['overrides'][$repId.'|'.$date] ?? collect();
        foreach ($overrides->where('action', RepPlanOverride::REMOVE) as $o) {
            unset($items[$o->scope_type.'|'.$o->scope_value]);
        }
        foreach ($overrides->where('action', RepPlanOverride::ADD) as $o) {
            $key = $o->scope_type.'|'.$o->scope_value;
            $items[$key] ??= $this->item($key, $o, 'override');
        }

        return array_values($items);
    }

    private function item(string $key, RepPlanTemplate|RepPlanOverride $row, string $source): array
    {
        return [
            'key' => $key,
            'type' => $row->scope_type,
            'value' => $row->scope_value,
            'label' => $row->scope_label ?? $row->scope_value,
            'text' => $row->scopeText(),
            'supervisor_id' => $row->supervisor_user_id !== null ? (int) $row->supervisor_user_id : null,
            'supervisor_name' => $row->supervisor?->name,
            'source' => $source,
            'id' => $row->id,
        ];
    }

    /**
     * صيدليات بند لمندوب: زبائن النطاق غير المخفيين التي يملكها مشرف البند لهذا المندوب.
     *
     * @return array<int, int>
     */
    public function pharmaciesFor(array $ctx, int $repId, string $type, string $value, ?int $supervisorId): array
    {
        if ($supervisorId === null) {
            return [];
        }
        $cacheKey = $repId.'|'.$type.'|'.$value.'|'.$supervisorId;
        if (isset($this->pharmacyCache[$cacheKey])) {
            return $this->pharmacyCache[$cacheKey];
        }
        $assignments = $ctx['assignments'][$repId] ?? collect();
        $ids = [];
        foreach ($this->scope->customersOf($type, $value) as $id) {
            if (! isset($ctx['hidden'][$id]) && $this->scope->ownerOf($repId, $id, $assignments) === $supervisorId) {
                $ids[] = $id;
            }
        }

        return $this->pharmacyCache[$cacheKey] = $ids;
    }

    /**
     * خطة يوم: البنود مع صيدلياتها (الصيدلية مرة واحدة، للبند الأول). $ownerId يحصر البنود بمشرف واحد.
     *
     * @return array<int, array>
     */
    public function dayPlan(array $ctx, int $repId, string $date, ?int $ownerId = null): array
    {
        $seen = [];
        $plan = [];
        foreach ($this->itemsFor($ctx, $repId, $date) as $item) {
            $ids = [];
            foreach ($this->pharmaciesFor($ctx, $repId, $item['type'], $item['value'], $item['supervisor_id']) as $id) {
                if (! isset($seen[$id])) {
                    $seen[$id] = true;
                    $ids[] = $id;
                }
            }
            if ($ownerId === null || $item['supervisor_id'] === $ownerId) {
                $plan[] = $item + ['customer_ids' => $ids];
            }
        }

        return $plan;
    }

    public function effective(User $rep, string $date, ?int $ownerId = null): array
    {
        return $this->dayPlan($this->context([$rep->id], $date, $date), $rep->id, $date, $ownerId);
    }

    /**
     * البند الذي تقع فيه صيدلية في خطة يوم (null = ليست في الخطة).
     */
    public function itemOfCustomer(User $rep, string $date, int $customerId): ?array
    {
        foreach ($this->effective($rep, $date) as $item) {
            if (in_array($customerId, $item['customer_ids'], true)) {
                return $item;
            }
        }

        return null;
    }

    // =====================================================================
    // نطاقات المشرف (من إسناد 8A)
    // =====================================================================

    /**
     * النطاقات التي يحق لمشرف (أو للأدمن باسم مالكها) وضعها في خطة مندوب، مع عدد صيدلياتها وما بلا موقع منها.
     *
     * @return array<int, array{owner_id: int, owner_name: string, type: string, value: string, label: string, count: int, no_location: int}>
     */
    public function scopeOptions(User $rep, User $viewer): array
    {
        $assignments = RepSupervisorAssignment::active()->with('supervisor:id,name')->where('rep_user_id', $rep->id)
            ->when(! $viewer->isAdmin(), fn ($q) => $q->where('supervisor_user_id', $viewer->id))->orderBy('id')->get();
        $ctx = ['assignments' => [$rep->id => RepSupervisorAssignment::active()->where('rep_user_id', $rep->id)->get()],
            'hidden' => array_fill_keys(Customer::where('is_hidden', true)->pluck('id')->all(), true)];
        $noLocation = array_fill_keys(Customer::whereNull('latitude')->orWhereNull('longitude')->pluck('id')->all(), true);
        $index = $this->scope->customerIndex();

        $options = [];
        foreach ($assignments as $a) {
            $scopes = $a->scope_type === RepSupervisorAssignment::ALL
                ? array_merge(
                    array_map(fn ($v) => [RepPlanTemplate::AREA, $v], array_keys($index['area'])),
                    array_map(fn ($v) => [RepPlanTemplate::BLOCK, $v], array_keys($index['block'])))
                : [[$a->scope_type, $a->scope_value]];
            foreach ($scopes as [$type, $value]) {
                $ids = $this->pharmaciesFor($ctx, $rep->id, $type, (string) $value, (int) $a->supervisor_user_id);
                if ($ids === []) {
                    continue;
                }
                $options[$a->supervisor_user_id.'|'.$type.'|'.$value] = [
                    'owner_id' => (int) $a->supervisor_user_id,
                    'owner_name' => $a->supervisor?->name ?? '—',
                    'type' => $type,
                    'value' => (string) $value,
                    'label' => $index[$type][$value]['label'] ?? (string) $value,
                    'count' => count($ids),
                    'no_location' => count(array_filter($ids, fn ($id) => isset($noLocation[$id]))),
                ];
            }
        }

        return array_values($options);
    }

    /**
     * مالك نطاق مسموح لهذا المشاهِد، أو استثناء (403 لمن يحاول نطاقاً خارج إسناده).
     */
    public function authorizeScope(User $rep, User $viewer, string $type, string $value, ?int $ownerId = null): array
    {
        foreach ($this->scopeOptions($rep, $viewer) as $option) {
            if ($option['type'] === $type && $option['value'] === $value && ($ownerId === null || $option['owner_id'] === $ownerId)) {
                return $option;
            }
        }
        abort(403, 'هذا النطاق خارج إسنادك لهذا المندوب.');
    }

    // =====================================================================
    // تعديل القالب (يسري من اليوم)
    // =====================================================================

    /**
     * @return array{template: RepPlanTemplate, warnings: array<int, string>}
     */
    public function addTemplate(User $rep, User $by, int $weekday, string $type, string $value, ?int $ownerId = null): array
    {
        $option = $this->authorizeScope($rep, $by, $type, $value, $ownerId);
        $exists = RepPlanTemplate::current()->where('rep_user_id', $rep->id)->where('weekday', $weekday)
            ->where('scope_type', $type)->where('scope_value', $value)->exists();
        if ($exists) {
            throw ValidationException::withMessages(['scope' => RepPlanTemplate::TYPES[$type].' «'.$option['label'].'» موجودة أصلاً في خطة يوم '.RepPlanTemplate::WEEKDAYS[$weekday].'.']);
        }

        return DB::transaction(function () use ($rep, $by, $weekday, $type, $value, $option) {
            $today = self::today();
            // بند أُنهي اليوم نفسه (حُذف ثم أُعيد) يُعاد فتحه بدل صف جديد بالتاريخ نفسه
            $template = RepPlanTemplate::where('rep_user_id', $rep->id)->where('weekday', $weekday)->where('scope_type', $type)
                ->where('scope_value', $value)->whereDate('starts_on', $today)->first();
            $template ??= new RepPlanTemplate(['rep_user_id' => $rep->id, 'weekday' => $weekday, 'scope_type' => $type, 'scope_value' => $value, 'starts_on' => $today]);
            $template->fill(['supervisor_user_id' => $option['owner_id'], 'scope_label' => $option['label'], 'active' => true, 'ends_on' => null, 'created_by' => $by->id])->save();

            $owner = User::find($option['owner_id']);
            ActivityLog::record('rep_plan.template_added', $template, [], $template->logValues(), ['rep' => $rep->name, 'supervisor' => $owner?->name]);
            RepPlanNotice::notify($rep->id, 'عُدِّلت خطتك: أضاف المشرف '.($owner?->name ?? '—').' '.$template->scopeText().' إلى خطة يوم '
                .RepPlanTemplate::WEEKDAYS[$weekday].' الأسبوعية (تسري من اليوم '.$today.').', null, $by->id);

            $warnings = [];
            $otherDays = RepPlanTemplate::current()->where('rep_user_id', $rep->id)->where('scope_type', $type)->where('scope_value', $value)
                ->where('weekday', '!=', $weekday)->pluck('weekday')->map(fn ($d) => RepPlanTemplate::WEEKDAYS[$d])->all();
            if ($otherDays) {
                $warnings[] = 'تنبيه: '.$template->scopeText().' في خطة أكثر من يوم ('.implode('، ', $otherDays).' و'.RepPlanTemplate::WEEKDAYS[$weekday].').';
            }

            return ['template' => $template, 'warnings' => $warnings];
        });
    }

    /**
     * إزالة بند من القالب: يُنهى من اليوم (الأيام الماضية وتغطيتها وزياراتها كما هي).
     *
     * @return string رسالة النتيجة
     */
    public function removeTemplate(RepPlanTemplate $template, User $by): string
    {
        $this->authorizeOwner($template->supervisor_user_id, $by);
        $rep = User::findOrFail($template->rep_user_id);
        $today = self::today();
        $visitsToday = RepPlanTemplate::weekdayOf($today) === $template->weekday ? $this->visitsCount($rep->id, $today, $template->scope_type, $template->scope_value) : 0;

        DB::transaction(function () use ($template, $by, $rep, $today) {
            $old = $template->logValues();
            if ($template->starts_on->toDateString() >= $today) {
                $template->delete();
            } else {
                $template->update(['ends_on' => Carbon::parse($today)->subDay()->toDateString()]);
            }
            ActivityLog::record('rep_plan.template_removed', $template->exists ? $template : null, $old,
                $template->exists ? $template->logValues() : ['deleted' => true], ['rep' => $rep->name, 'template_id' => $template->id]);
            RepPlanNotice::notify($rep->id, 'عُدِّلت خطتك: أزال المشرف '.$by->name.' '.$template->scopeText().' من خطة يوم '
                .RepPlanTemplate::WEEKDAYS[$template->weekday].' الأسبوعية (من اليوم '.$today.').', null, $by->id);
        });

        return $this->removalMessage($template->scopeText(), $visitsToday);
    }

    // =====================================================================
    // تعديل بتاريخ محدد
    // =====================================================================

    public function addForDate(User $rep, User $by, string $date, string $type, string $value, ?string $reason = null, ?int $ownerId = null): string
    {
        $this->assertEditableDate($date, $by);
        $option = $this->authorizeScope($rep, $by, $type, $value, $ownerId);

        return DB::transaction(function () use ($rep, $by, $date, $type, $value, $reason, $option) {
            $removed = RepPlanOverride::where('rep_user_id', $rep->id)->whereDate('plan_date', $date)->where('action', RepPlanOverride::REMOVE)
                ->where('scope_type', $type)->where('scope_value', $value)->first();
            $scopeText = RepPlanTemplate::TYPES[$type].' '.$option['label'];
            if ($removed) {
                // كانت مُزالة لهذا اليوم من القالب: تُعاد
                $removed->delete();
                ActivityLog::record('rep_plan.override_removed', null, $removed->logValues(), [], ['rep' => $rep->name, 'restored' => true]);
            } else {
                $existing = collect($this->effective($rep, $date))->first(fn ($i) => $i['type'] === $type && $i['value'] === $value);
                if ($existing) {
                    throw ValidationException::withMessages(['scope' => $scopeText.' موجودة أصلاً في خطة '.$date.'.']);
                }
                $override = RepPlanOverride::create([
                    'rep_user_id' => $rep->id, 'plan_date' => $date, 'action' => RepPlanOverride::ADD, 'scope_type' => $type, 'scope_value' => $value,
                    'scope_label' => $option['label'], 'supervisor_user_id' => $option['owner_id'], 'reason' => $reason, 'created_by' => $by->id,
                ]);
                ActivityLog::record('rep_plan.override_added', $override, [], $override->logValues(), ['rep' => $rep->name]);
            }
            $owner = User::find($option['owner_id']);
            RepPlanNotice::notify($rep->id, 'عُدِّلت خطتك: أضاف المشرف '.($owner?->name ?? $by->name).' '.$scopeText.' ليوم '.self::dayName($date).' '.$date
                .($reason ? ' ('.$reason.')' : '').'.', $date, $by->id);

            return 'أُضيفت '.$scopeText.' إلى خطة '.self::dayName($date).' '.$date.' فقط.';
        });
    }

    public function removeForDate(User $rep, User $by, string $date, string $type, string $value, ?string $reason = null): string
    {
        $this->assertEditableDate($date, $by);
        $item = collect($this->effective($rep, $date))->first(fn ($i) => $i['type'] === $type && $i['value'] === $value);
        if (! $item) {
            throw ValidationException::withMessages(['scope' => 'هذا النطاق ليس في خطة '.$date.'.']);
        }
        $this->authorizeOwner($item['supervisor_id'], $by);
        $visits = $this->visitsCount($rep->id, $date, $type, $value);

        DB::transaction(function () use ($rep, $by, $date, $item, $reason) {
            if ($item['source'] === 'override') {
                $override = RepPlanOverride::findOrFail($item['id']);
                $override->delete();
                ActivityLog::record('rep_plan.override_removed', null, $override->logValues(), [], ['rep' => $rep->name]);
            } else {
                $override = RepPlanOverride::create([
                    'rep_user_id' => $rep->id, 'plan_date' => $date, 'action' => RepPlanOverride::REMOVE, 'scope_type' => $item['type'], 'scope_value' => $item['value'],
                    'scope_label' => $item['label'], 'supervisor_user_id' => $item['supervisor_id'], 'reason' => $reason, 'created_by' => $by->id,
                ]);
                ActivityLog::record('rep_plan.override_added', $override, [], $override->logValues(), ['rep' => $rep->name]);
            }
            RepPlanNotice::notify($rep->id, 'عُدِّلت خطتك: أزال المشرف '.$by->name.' '.$item['text'].' من خطة يوم '.self::dayName($date).' '.$date
                .($reason ? ' ('.$reason.')' : '').'.', $date, $by->id);
        });

        return $this->removalMessage($item['text'], $visits, $date);
    }

    private function removalMessage(string $scopeText, int $visits, ?string $date = null): string
    {
        $message = 'أُزيلت '.$scopeText.($date ? ' من خطة '.$date : ' من الخطة الأسبوعية من اليوم').'.';
        if ($visits > 0) {
            $message .= ' سُجّلت '.$visits.' زيارة؛ تبقى مسجَّلة ومحتسبة، وتُحذف فقط الصيدليات غير المزارة من قائمة المندوب.';
        }

        return $message;
    }

    /** زيارات مسجَّلة في نطاق ليوم (للتحذير قبل الإزالة) */
    public function visitsCount(int $repId, string $date, string $type, string $value): int
    {
        return RepPlanVisit::where('rep_user_id', $repId)->whereDate('plan_date', $date)->where('scope_type', $type)->where('scope_value', $value)->count();
    }

    private function assertEditableDate(string $date, User $by): void
    {
        if (! $by->isAdmin() && $date < self::today()) {
            throw ValidationException::withMessages(['plan_date' => 'لا يُعدَّل يوم ماضٍ إلا من الأدمن.']);
        }
    }

    private function authorizeOwner(?int $ownerId, User $by): void
    {
        if (! $by->isAdmin() && (int) $ownerId !== $by->id) {
            abort(403, 'هذا البند لمشرف آخر.');
        }
    }

    public static function dayName(string $date): string
    {
        return RepPlanTemplate::WEEKDAYS[RepPlanTemplate::weekdayOf($date)];
    }

    // =====================================================================
    // التكامل مع 8A (القسم 7)
    // =====================================================================

    /**
     * بنود الخطة الحالية/المستقبلية التي يغطيها إسناد (لنافذة تأكيد النقل/الإنهاء).
     *
     * @return array{templates: Collection<int, RepPlanTemplate>, overrides: Collection<int, RepPlanOverride>}
     */
    public function itemsOfAssignment(RepSupervisorAssignment $a): array
    {
        $match = fn ($q) => $q->where('rep_user_id', $a->rep_user_id)->where('supervisor_user_id', $a->supervisor_user_id)
            ->when($a->scope_type !== RepSupervisorAssignment::ALL, fn ($q) => $q->where('scope_type', $a->scope_type)->where('scope_value', $a->scope_value));

        return [
            'templates' => RepPlanTemplate::current()->where($match)->get(),
            'overrides' => RepPlanOverride::where($match)->where('action', RepPlanOverride::ADD)->whereDate('plan_date', '>=', self::today())->get(),
        ];
    }

    public function countForAssignment(RepSupervisorAssignment $a): int
    {
        return $this->countsForAssignments(collect([$a]))[$a->id] ?? 0;
    }

    /**
     * عدد بنود الخطة لكل إسناد دفعة واحدة (استعلامان مهما كان عدد الإسنادات، لشاشة الإسناد).
     *
     * @return array<int, int>
     */
    public function countsForAssignments(Collection $assignments): array
    {
        $rows = RepPlanTemplate::current()->get(['rep_user_id', 'supervisor_user_id', 'scope_type', 'scope_value'])
            ->concat(RepPlanOverride::where('action', RepPlanOverride::ADD)->whereDate('plan_date', '>=', self::today())
                ->get(['rep_user_id', 'supervisor_user_id', 'scope_type', 'scope_value']));
        $counts = [];
        foreach ($assignments as $a) {
            $counts[$a->id] = $rows->filter(fn ($r) => (int) $r->rep_user_id === (int) $a->rep_user_id && (int) $r->supervisor_user_id === (int) $a->supervisor_user_id
                && ($a->scope_type === RepSupervisorAssignment::ALL || ($r->scope_type === $a->scope_type && $r->scope_value === $a->scope_value)))->count();
        }

        return $counts;
    }

    /**
     * نقل الإشراف: بنود المشرف القديم المستقبلية لهذا النطاق تنتقل للجديد (القالب يُنهى أمس ويُنسخ من اليوم كي تبقى تغطية الماضي لمالكها).
     */
    public function moveItems(RepSupervisorAssignment $old, RepSupervisorAssignment $new, User $by): int
    {
        $items = $this->itemsOfAssignment($old);
        $today = self::today();
        $to = User::find($new->supervisor_user_id);
        foreach ($items['templates'] as $t) {
            $before = $t->logValues();
            if ($t->starts_on->toDateString() < $today) {
                $t->update(['ends_on' => Carbon::parse($today)->subDay()->toDateString()]);
                $copy = $t->replicate(['ends_on']);
                $copy->fill(['supervisor_user_id' => $new->supervisor_user_id, 'starts_on' => $today, 'ends_on' => null, 'created_by' => $by->id])->save();
                $after = $copy->logValues();
            } else {
                $t->update(['supervisor_user_id' => $new->supervisor_user_id]);
                $after = $t->logValues();
            }
            ActivityLog::record('rep_plan.template_moved', $t, $before, $after, ['assignment_id' => $new->id]);
        }
        foreach ($items['overrides'] as $o) {
            $before = $o->logValues();
            $o->update(['supervisor_user_id' => $new->supervisor_user_id]);
            ActivityLog::record('rep_plan.override_moved', $o, $before, $o->logValues(), ['assignment_id' => $new->id]);
        }
        $count = $items['templates']->count() + $items['overrides']->count();
        RepPlanNotice::notify($new->rep_user_id, 'انتقل إشراف '.$new->scopeText().' إلى المشرف '.($to?->name ?? '—')
            .($count ? ' ('.$count.' بنداً في خطتك انتقلت إليه).' : '.'), null, $by->id);

        return $count;
    }

    /**
     * إنهاء إسناد بلا بديل: البنود المستقبلية تتعطّل (active=false) ولا تُحذف.
     */
    public function disableItems(RepSupervisorAssignment $a, User $by): int
    {
        $items = $this->itemsOfAssignment($a);
        $yesterday = Carbon::parse(self::today())->subDay()->toDateString();
        foreach ($items['templates'] as $t) {
            $before = $t->logValues();
            $t->update(['active' => false, 'ends_on' => $yesterday]);
            ActivityLog::record('rep_plan.template_disabled', $t, $before, $t->logValues(), ['assignment_id' => $a->id]);
        }
        foreach ($items['overrides'] as $o) {
            $before = $o->logValues();
            $o->update(['supervisor_user_id' => null]);
            ActivityLog::record('rep_plan.override_disabled', $o, $before, $o->logValues(), ['assignment_id' => $a->id]);
        }
        $count = $items['templates']->count() + $items['overrides']->count();
        if ($count) {
            RepPlanNotice::notify($a->rep_user_id, 'انتهى إشراف '.$a->scopeText().' بلا مشرف بديل، فتعطّل '.$count.' بنداً من خطتك لهذا النطاق.', null, $by->id);
        }

        return $count;
    }

    /** بنود معطَّلة لأن نطاقها بلا مشرف (تنبيه للأدمن) */
    public static function disabledCount(): int
    {
        return RepPlanTemplate::where('active', false)->count()
            + RepPlanOverride::whereNull('supervisor_user_id')->where('action', RepPlanOverride::ADD)->whereDate('plan_date', '>=', self::today())->count();
    }

    /** المندوبون الذين يملك مشرف نطاقاً لهم (الأدمن: كل المندوبين) */
    public function repsFor(User $viewer): Collection
    {
        $query = User::where('role', Role::Rep->value)->orderBy('name');
        if (! $viewer->isAdmin()) {
            $query->whereIn('id', RepSupervisorAssignment::active()->where('supervisor_user_id', $viewer->id)->select('rep_user_id'));
        }

        return $query->get();
    }

    public function canManageRep(User $viewer, User $rep): bool
    {
        return $rep->role === Role::Rep && ($viewer->isAdmin()
            || RepSupervisorAssignment::active()->where('supervisor_user_id', $viewer->id)->where('rep_user_id', $rep->id)->exists());
    }
}
