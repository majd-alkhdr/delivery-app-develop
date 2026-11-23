<?php

namespace App\Services\Reps;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\RepSupervisorAssignment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * المرحلة 8A: إنشاء الإسناد وإنهاؤه ونقله، وتفعيل الحصر. الأدمن وحده (من المسارات)، وكل تغيير في ActivityLog بالقيم قبل/بعد.
 */
class RepAssignmentService
{
    /** المرحلة 8B: بنود الخطة التي نُقلت/تعطّلت في آخر نقل/إنهاء (لرسالة الأدمن) */
    public int $planItemsAffected = 0;

    public function __construct(private RepScope $scope, private ?RepPlanService $plans = null)
    {
        $this->plans ??= new RepPlanService($scope);
    }

    /**
     * إسناد مندوب لمشرف بنطاق واحد أو عدة مناطق/كتل دفعة واحدة (كلها أو لا شيء).
     *
     * @param  array<int, string>  $values  القيم الأصلية أو المطبَّعة (تُطبَّع هنا)؛ تُتجاهل لـ all
     * @return Collection<int, RepSupervisorAssignment>
     */
    public function create(User $rep, User $supervisor, string $type, array $values, User $by): Collection
    {
        $this->assertRoles($rep, $supervisor);
        $targets = $this->targets($type, $values);

        // التحقق من الكل قبل الكتابة، مع احتساب ما سيُنشأ في الطلب نفسه
        return DB::transaction(function () use ($rep, $supervisor, $type, $targets, $by) {
            $created = collect();
            $errors = [];
            foreach ($targets as [$value, $label]) {
                $conflict = $this->scope->conflictFor($rep->id, $supervisor->id, $type, $value);
                if ($conflict !== null) {
                    $errors[] = $conflict;
                    continue;
                }
                $assignment = RepSupervisorAssignment::create([
                    'rep_user_id' => $rep->id,
                    'supervisor_user_id' => $supervisor->id,
                    'scope_type' => $type,
                    'scope_value' => $value,
                    'scope_label' => $label,
                    'created_by' => $by->id,
                ]);
                $created->push($assignment);
            }
            if ($errors !== []) {
                throw ValidationException::withMessages(['scope' => $errors]);
            }
            foreach ($created as $assignment) {
                ActivityLog::record('rep_assignment.created', $assignment, [], $assignment->logValues(), $this->meta($assignment, $rep, $supervisor));
            }

            return $created;
        });
    }

    public function end(RepSupervisorAssignment $assignment, User $by): void
    {
        if (! $assignment->isActive()) {
            throw ValidationException::withMessages(['assignment' => 'هذا الإسناد منتهٍ أصلاً.']);
        }
        DB::transaction(function () use ($assignment, $by) {
            $old = $assignment->logValues();
            $assignment->update(['ended_at' => now(), 'ended_by' => $by->id]);
            ActivityLog::record('rep_assignment.ended', $assignment, $old, $assignment->logValues(), $this->meta($assignment));
            // المرحلة 8B: بنود الخطة المستقبلية لهذا النطاق تتعطّل (لا تُحذف)
            $this->planItemsAffected = $this->plans->disableItems($assignment, $by);
        });
    }

    /**
     * نقل نطاق من مشرف لآخر: إنهاء القديم وإنشاء الجديد في معاملة واحدة.
     */
    public function move(RepSupervisorAssignment $assignment, User $to, User $by): RepSupervisorAssignment
    {
        if (! $assignment->isActive()) {
            throw ValidationException::withMessages(['assignment' => 'لا يُنقل إسناد منتهٍ.']);
        }
        if ($to->role !== Role::RepSupervisor) {
            throw ValidationException::withMessages(['supervisor_id' => 'المستخدم المختار ليس مشرف مندوبين.']);
        }
        if ((int) $assignment->supervisor_user_id === $to->id) {
            throw ValidationException::withMessages(['supervisor_id' => 'الإسناد عند هذا المشرف أصلاً.']);
        }
        $conflict = $this->scope->conflictFor((int) $assignment->rep_user_id, $to->id, $assignment->scope_type, $assignment->scope_value, $assignment->id);
        if ($conflict !== null) {
            throw ValidationException::withMessages(['supervisor_id' => $conflict]);
        }

        return DB::transaction(function () use ($assignment, $to, $by) {
            $old = $assignment->logValues();
            $from = $assignment->supervisor;
            $assignment->update(['ended_at' => now(), 'ended_by' => $by->id]);
            $new = RepSupervisorAssignment::create([
                'rep_user_id' => $assignment->rep_user_id,
                'supervisor_user_id' => $to->id,
                'scope_type' => $assignment->scope_type,
                'scope_value' => $assignment->scope_value,
                'scope_label' => $assignment->scope_label,
                'created_by' => $by->id,
            ]);
            ActivityLog::record('rep_assignment.moved', $new, $old, $new->logValues(), $this->meta($new) + [
                'from_supervisor' => $from?->name,
                'to_supervisor' => $to->name,
                'ended_assignment_id' => $assignment->id,
            ]);
            // المرحلة 8B: بنود الخطة المستقبلية تنتقل للمشرف الجديد، والزيارات السابقة تبقى لمالكها
            $this->planItemsAffected = $this->plans->moveItems($assignment, $new, $by);

            return $new;
        });
    }

    /**
     * عدد الصيدليات التي يغطيها إسناد (من صيدليات المندوب الفعلية لـ all، ومن جدول الزبائن للمنطقة/الكتلة).
     */
    public function pharmacyCount(RepSupervisorAssignment $assignment): int
    {
        if ($assignment->scope_type === RepSupervisorAssignment::ALL) {
            return $assignment->rep ? count($this->scope->pharmaciesOfRep($assignment->rep)) : 0;
        }

        return count($this->scope->customersOf($assignment->scope_type, $assignment->scope_value));
    }

    /**
     * تفعيل/إيقاف الحصر (يُسجَّل بعدد المندوبين بلا مشرف لحظتها).
     */
    public function setScoping(bool $enabled, int $repsWithoutSupervisor): void
    {
        $old = RepScope::enabled();
        Setting::set(RepScope::SETTING, $enabled);
        ActivityLog::record($enabled ? 'rep_scoping.enabled' : 'rep_scoping.disabled', null,
            [RepScope::SETTING => $old], [RepScope::SETTING => $enabled], ['reps_without_supervisor' => $repsWithoutSupervisor]);
    }

    private function assertRoles(User $rep, User $supervisor): void
    {
        if ($rep->role !== Role::Rep) {
            throw ValidationException::withMessages(['rep_id' => 'المستخدم المختار ليس مندوباً.']);
        }
        if ($supervisor->role !== Role::RepSupervisor) {
            throw ValidationException::withMessages(['supervisor_id' => 'المستخدم المختار ليس مشرف مندوبين.']);
        }
    }

    /**
     * @return array<int, array{0: ?string, 1: ?string}> [القيمة المطبَّعة، النص للعرض]
     */
    private function targets(string $type, array $values): array
    {
        if ($type === RepSupervisorAssignment::ALL) {
            return [[null, null]];
        }
        if (! in_array($type, [RepSupervisorAssignment::AREA, RepSupervisorAssignment::BLOCK], true)) {
            throw ValidationException::withMessages(['scope_type' => 'نوع النطاق غير صالح.']);
        }
        $index = $this->scope->customerIndex()[$type];
        $targets = [];
        foreach ($values as $raw) {
            $value = RepScope::normalizeValue((string) $raw);
            if ($value === null) {
                continue;
            }
            if (! isset($index[$value])) {
                throw ValidationException::withMessages(['scope_values' => ($type === RepSupervisorAssignment::AREA ? 'المنطقة' : 'الكتلة').' «'.$raw.'» غير موجودة في جدول الزبائن.']);
            }
            $targets[$value] = [$value, $index[$value]['label']];
        }
        if ($targets === []) {
            throw ValidationException::withMessages(['scope_values' => $type === RepSupervisorAssignment::AREA ? 'اختر منطقة واحدة على الأقل.' : 'اختر كتلة واحدة على الأقل.']);
        }

        return array_values($targets);
    }

    private function meta(RepSupervisorAssignment $assignment, ?User $rep = null, ?User $supervisor = null): array
    {
        $rep ??= $assignment->rep;
        $supervisor ??= $assignment->supervisor;

        return [
            'rep' => $rep?->name,
            'supervisor' => $supervisor?->name,
            'scope' => $assignment->scopeText(),
            'pharmacies' => $this->pharmacyCount($assignment->setRelation('rep', $rep)),
        ];
    }
}
