<?php

namespace App\Services\Reps;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\RepSupervisorAssignment;
use App\Models\Setting;
use App\Models\User;
use App\Support\ArabicNormalizer;
use App\Support\CostCenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * المرحلة 8A: المصدر الوحيد لحصر رؤية مشرف المندوبين.
 * - النطاق: كل صيدليات المندوب (all) أو منطقة (customers.area_normalized) أو كتلة (customers.block مطبَّعة بـ ArabicNormalizer).
 * - المعيار: لكل (مندوب، زبون) مشرف واحد على الأكثر (ownerOf)، وتضمنه قواعد التعارض (conflictFor).
 * - الحصر يُطبَّق فقط حين يكون الإعداد rep_supervisor_scoping مفعّلاً والمشاهِد rep_supervisor (forViewer).
 */
class RepScope
{
    public const SETTING = 'rep_supervisor_scoping';

    /** @var array{area: array<string, array{label: string, ids: array<int, int>}>, block: array<string, array{label: string, ids: array<int, int>}>, none: array<int, int>, areaOf: array<int, ?string>, blockOf: array<int, ?string>}|null */
    private ?array $index = null;

    /** @var array<string, array<int, true>>|null كود مطبَّع => صيدلياته */
    private ?array $pharmacies = null;

    public static function enabled(): bool
    {
        return (bool) Setting::get(self::SETTING, false);
    }

    /**
     * التطبيع الموحَّد لقيمة منطقة/كتلة (null للفارغ).
     */
    public static function normalizeValue(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $normalized = ArabicNormalizer::normalize($value);

        return $normalized !== '' ? $normalized : null;
    }

    // =====================================================================
    // جدول الزبائن: المناطق والكتل الموجودة فعلاً
    // =====================================================================

    /**
     * فهرس الزبائن بالمنطقة والكتلة (استعلام واحد، يُحفظ للنسخة).
     */
    public function customerIndex(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        $index = ['area' => [], 'block' => [], 'none' => [], 'areaOf' => [], 'blockOf' => []];
        foreach (Customer::query()->toBase()->get(['id', 'area', 'area_normalized', 'block']) as $c) {
            $id = (int) $c->id;
            $area = $c->area_normalized ?: self::normalizeValue($c->area);
            $block = self::normalizeValue($c->block);
            $index['areaOf'][$id] = $area;
            $index['blockOf'][$id] = $block;
            if ($area !== null) {
                $index['area'][$area]['label'] ??= trim((string) $c->area);
                $index['area'][$area]['ids'][] = $id;
            }
            if ($block !== null) {
                $index['block'][$block]['label'] ??= trim((string) $c->block);
                $index['block'][$block]['ids'][] = $id;
            }
            if ($area === null && $block === null) {
                $index['none'][] = $id;
            }
        }
        foreach (['area', 'block'] as $type) {
            uasort($index[$type], fn ($a, $b) => strnatcmp($a['label'], $b['label']));
        }

        return $this->index = $index;
    }

    /**
     * زبائن نطاق منطقة/كتلة (من جدول الزبائن).
     *
     * @return array<int, int>
     */
    public function customersOf(string $type, ?string $value): array
    {
        if ($value === null || ! in_array($type, [RepSupervisorAssignment::AREA, RepSupervisorAssignment::BLOCK], true)) {
            return [];
        }

        return $this->customerIndex()[$type][$value]['ids'] ?? [];
    }

    /**
     * صيدليات كل مندوب: الزبائن الذين لهم فواتير بمركز كلفته (استعلام واحد لكل المراكز).
     *
     * @return array<string, array<int, true>>
     */
    public function pharmaciesByCode(): array
    {
        if ($this->pharmacies !== null) {
            return $this->pharmacies;
        }
        $map = [];
        $pairs = Invoice::query()->whereNotNull('customer_id')->whereNotNull('cost_center_code')
            ->select(['cost_center_code', 'customer_id'])->distinct()->toBase()->get();
        foreach ($pairs as $pair) {
            $code = CostCenter::normalizeCode($pair->cost_center_code);
            if ($code !== null) {
                $map[$code][(int) $pair->customer_id] = true;
            }
        }

        return $this->pharmacies = $map;
    }

    /**
     * @return array<int, int>
     */
    public function pharmaciesOfRep(User $rep): array
    {
        $code = $rep->hasCostCenter() ? CostCenter::normalizeCode($rep->cost_center_code) : null;

        return $code !== null ? array_keys($this->pharmaciesByCode()[$code] ?? []) : [];
    }

    // =====================================================================
    // الملكية: لكل (مندوب، زبون) مشرف واحد على الأكثر
    // =====================================================================

    /**
     * المشرف المالك لصيدلية من صيدليات مندوب (null = غير مسنَدة).
     *
     * @param  Collection<int, RepSupervisorAssignment>|null  $assignments  إسنادات المندوب الفعّالة (تُحمَّل إن لم تُمرَّر)
     */
    public function ownerOf(int $repId, int $customerId, ?Collection $assignments = null): ?int
    {
        $assignments ??= RepSupervisorAssignment::active()->where('rep_user_id', $repId)->get();
        $index = $this->customerIndex();
        foreach ($assignments->where('rep_user_id', $repId) as $a) {
            $match = match ($a->scope_type) {
                RepSupervisorAssignment::ALL => true,
                RepSupervisorAssignment::AREA => ($index['areaOf'][$customerId] ?? null) === $a->scope_value,
                RepSupervisorAssignment::BLOCK => ($index['blockOf'][$customerId] ?? null) === $a->scope_value,
                default => false,
            };
            if ($match) {
                return (int) $a->supervisor_user_id;
            }
        }

        return null;
    }

    /**
     * هل الصيدلية ضمن نطاق إسناد معيّن؟
     */
    public function covers(RepSupervisorAssignment $a, int $customerId): bool
    {
        $index = $this->customerIndex();

        return match ($a->scope_type) {
            RepSupervisorAssignment::ALL => true,
            RepSupervisorAssignment::AREA => ($index['areaOf'][$customerId] ?? null) === $a->scope_value,
            RepSupervisorAssignment::BLOCK => ($index['blockOf'][$customerId] ?? null) === $a->scope_value,
            default => false,
        };
    }

    // =====================================================================
    // قواعد التعارض (الخادم دائماً)
    // =====================================================================

    /**
     * رسالة التعارض بالعربية (تسمّي الطرف المتعارض)، أو null إن كان الإسناد مقبولاً.
     */
    public function conflictFor(int $repId, int $supervisorId, string $type, ?string $value, ?int $ignoreId = null): ?string
    {
        $active = RepSupervisorAssignment::active()->with('supervisor:id,name')
            ->where('rep_user_id', $repId)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get();
        $label = fn (string $t, ?string $v) => match ($t) {
            RepSupervisorAssignment::ALL => 'كل صيدلياته',
            RepSupervisorAssignment::BLOCK => 'الكتلة '.($this->customerIndex()['block'][$v]['label'] ?? $v),
            default => 'منطقة '.($this->customerIndex()['area'][$v]['label'] ?? $v),
        };

        // 1) التكرار
        if ($active->first(fn ($a) => (int) $a->supervisor_user_id === $supervisorId && $a->scope_type === $type && $a->scope_value === $value)) {
            return 'هذا الإسناد موجود مسبقاً ('.$label($type, $value).') لهذا المشرف.';
        }

        foreach ($active as $a) {
            $sameSupervisor = (int) $a->supervisor_user_id === $supervisorId;
            $other = $a->supervisor?->name ?? ('#'.$a->supervisor_user_id);

            // المشرف نفسه يملك المندوب كاملاً: إسناد منطقة/كتلة له مشمول أصلاً
            if ($sameSupervisor) {
                if ($a->scope_type === RepSupervisorAssignment::ALL && $type !== RepSupervisorAssignment::ALL) {
                    return $label($type, $value).' مشمولة أصلاً بإسناد «كل صيدلياته» لهذا المشرف.';
                }
                continue;
            }

            // 3) all مقابل أي نطاق لمشرف آخر
            if ($type === RepSupervisorAssignment::ALL) {
                return 'لا يمكن إسناد كل صيدليات المندوب: '.$label($a->scope_type, $a->scope_value).' مُسنَدة للمشرف '.$other.' لهذا المندوب. انقلها إليه أولاً.';
            }
            if ($a->scope_type === RepSupervisorAssignment::ALL) {
                return 'المندوب مُسنَد بكل صيدلياته للمشرف '.$other.'. انقل الإسناد أو أنهِه أولاً.';
            }

            // 2) النطاق نفسه لمشرف آخر
            if ($a->scope_type === $type && $a->scope_value === $value) {
                return $label($type, $value).' مُسنَدة سابقاً للمشرف '.$other.' لهذا المندوب. انقلها إليه أولاً.';
            }

            // 4) تقاطع منطقة/كتلة بالصيدليات
            if ($a->scope_type !== $type) {
                $overlap = count(array_intersect($this->customersOf($type, $value), $this->customersOf($a->scope_type, $a->scope_value)));
                if ($overlap > 0) {
                    return $label($type, $value).' تتقاطع مع '.$label($a->scope_type, $a->scope_value).' المُسنَدة للمشرف '.$other
                        .' لهذا المندوب في '.$overlap.' صيدلية. الصيدلية الواحدة لا يملكها لمندوبها إلا مشرف واحد.';
                }
            }
        }

        return null;
    }

    // =====================================================================
    // نطاق المشاهِد
    // =====================================================================

    /**
     * نطاق المستخدم الحالي: null = يرى كل شيء (ليس rep_supervisor، أو الحصر غير مفعّل).
     * يُحفظ على الطلب الحالي كي لا يُعاد حسابه في الشاشة الواحدة.
     */
    public static function current(?User $viewer = null): ?RepViewerScope
    {
        $viewer ??= auth()->user();
        if (! $viewer instanceof User || $viewer->role !== Role::RepSupervisor) {
            return null;
        }
        $request = request();
        $key = 'rep_viewer_scope:'.$viewer->id;
        if ($request && $request->attributes->has($key)) {
            return $request->attributes->get($key);
        }
        $scope = self::enabled() ? app(self::class)->forSupervisor($viewer) : null;
        $request?->attributes->set($key, $scope);

        return $scope;
    }

    /**
     * الإسنادات الفعّالة لمشرف (أي نوع نطاق): مصدر «مندوبيه» لنطاق الشاشات ولسجل النشاط (8C2).
     */
    public static function activeAssignmentsOf(User|int $supervisor): Builder
    {
        return RepSupervisorAssignment::active()->where('supervisor_user_id', $supervisor instanceof User ? $supervisor->id : $supervisor);
    }

    /**
     * المرحلة 8C2: معرّفات مندوبي المشرف المسنَدين له حالياً كاستعلام فرعي (user_id IN …)، مستقل عن إعداد الحصر.
     */
    public static function repIdsQuery(User|int $supervisor): Builder
    {
        return self::activeAssignmentsOf($supervisor)->select('rep_user_id');
    }

    public function forSupervisor(User $supervisor): RepViewerScope
    {
        $assignments = self::activeAssignmentsOf($supervisor)->with('rep')->get();
        $index = $this->customerIndex();
        $reps = [];
        foreach ($assignments->groupBy('rep_user_id') as $repId => $rows) {
            $rep = $rows->first()->rep;
            if (! $rep) {
                continue;
            }
            $all = $rows->contains(fn ($a) => $a->scope_type === RepSupervisorAssignment::ALL);
            $customers = [];
            if (! $all) {
                foreach ($rows as $a) {
                    foreach ($this->customersOf($a->scope_type, $a->scope_value) as $id) {
                        $customers[$id] = true;
                    }
                }
            }
            $reps[(int) $repId] = [
                'rep' => $rep,
                'code' => $rep->hasCostCenter() ? CostCenter::normalizeCode($rep->cost_center_code) : null,
                'all' => $all,
                'customers' => $customers,
                'labels' => $all ? ['كل صيدليات '.$rep->name] : $rows->map(fn ($a) => $a->scope_label)->unique()->values()->all(),
                'count' => $all ? count($this->pharmaciesOfRep($rep)) : count($customers),
            ];
        }

        return new RepViewerScope($supervisor, $reps);
    }

    /**
     * أسطر «النطاق: …» لمندوب معيّن عند كل مشرفيه (لملف المندوب).
     *
     * @return Collection<int, array{supervisor: ?User, assignments: Collection, label: string}>
     */
    public function supervisorsOf(User $rep): Collection
    {
        return RepSupervisorAssignment::active()->with('supervisor:id,name')->where('rep_user_id', $rep->id)->orderBy('id')->get()
            ->groupBy('supervisor_user_id')
            ->map(fn ($rows) => [
                'supervisor' => $rows->first()->supervisor,
                'assignments' => $rows,
                'label' => $rows->map->scopeText()->implode('، '),
            ])->values();
    }
}
