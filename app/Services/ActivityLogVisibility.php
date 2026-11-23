<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Reps\RepScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * المرحلة 8C: المصدر الوحيد لقاعدة «من يرى ماذا» في سجل النشاط. المعيار منفّذ الإجراء (user_id) لا نوع الحدث:
 * الأدمن يرى الكل (بما فيه الآلي بلا منفّذ)، وكل دور آخر يرى نفسه + منفّذين آخرين حسب VISIBLE_ROLES.
 * الحصر داخل الاستعلام (whereIn user_id باستعلام فرعي) فلا يُجلب السجل ثم يُصفّى في PHP.
 */
class ActivityLogVisibility
{
    /** المرحلة 8C2: «المندوبون المسنَدون له حالياً» (إسناد فعّال من 8A بأي نطاق) بدل قائمة أدوار */
    public const ASSIGNED_REPS = 'assigned_reps';

    /**
     * المنفّذون المرئيون لكل دور متصفح (إضافة إلى نفسه): قائمة أدوار، أو ASSIGNED_REPS، أو null = الكل (الأدمن).
     * مشرف المندوبين (8C2): مندوبوه المسنَدون له فقط، دائماً، مستقلاً عن إعداد الحصر rep_supervisor_scoping.
     */
    public const VISIBLE_ROLES = [
        'admin' => null,
        'supervisor' => [Role::Distributor],
        'rep_supervisor' => self::ASSIGNED_REPS,
        'rep' => [],
        'distributor' => [],
        'sheet_manager' => [Role::Supervisor, Role::Distributor],
        'accountant' => [Role::SheetManager, Role::Supervisor, Role::Distributor, Role::Accountant, Role::Rep, Role::RepSupervisor],
    ];

    /** @return array<int, Role>|string|null قاعدة المتصفح (دور خارج المصفوفة إن أُضيف لاحقاً يرى نفسه فقط) */
    public static function rule(User $viewer): array|string|null
    {
        return array_key_exists($viewer->role->value, self::VISIBLE_ROLES) ? self::VISIBLE_ROLES[$viewer->role->value] : [];
    }

    /**
     * معرّفات المنفّذين المرئيين غير المتصفح نفسه كاستعلام فرعي على عمود واحد.
     * null = الكل (لا حصر)، false = نفسه فقط.
     */
    private static function others(User $viewer): Builder|false|null
    {
        $rule = self::rule($viewer);

        return match (true) {
            $rule === null => null,
            $rule === self::ASSIGNED_REPS => RepScope::repIdsQuery($viewer),
            $rule === [] => false,
            default => User::query()->whereIn('role', array_map(fn (Role $r) => $r->value, $rule))->select('id'),
        };
    }

    /** يحصر استعلاماً على activity_logs بما يراه المستخدم */
    public static function apply(Builder $query, User $viewer): Builder
    {
        return self::restrict($query, $query->getModel()->qualifyColumn('user_id'), $viewer);
    }

    /** المستخدمون الذين يرى المتصفح سجلهم (لقائمة المرشّح المنسدلة ومرشّح user_id) */
    public static function users(User $viewer): Builder
    {
        $query = User::query();

        return self::restrict($query, $query->getModel()->qualifyColumn('id'), $viewer);
    }

    public static function canSee(User $viewer, ActivityLog $log): bool
    {
        return self::apply(ActivityLog::query()->whereKey($log->id), $viewer)->exists();
    }

    public static function canSeeUser(User $viewer, int $userId): bool
    {
        return self::users($viewer)->whereKey($userId)->exists();
    }

    /** القاعدة نفسها على عمود منفّذ (السجل) أو معرّف مستخدم (القائمة المنسدلة) */
    private static function restrict(Builder $query, string $column, User $viewer): Builder
    {
        $others = self::others($viewer);
        if ($others === null) {
            return $query;
        }
        if ($others === false) {
            return $query->where($column, $viewer->id);
        }

        return $query->where(fn ($w) => $w->where($column, $viewer->id)->orWhereIn($column, $others));
    }
}
