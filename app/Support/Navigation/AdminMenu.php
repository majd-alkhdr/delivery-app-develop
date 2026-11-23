<?php

namespace App\Support\Navigation;

use App\Enums\Role;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * مصدر واحد للقائمة الجانبية للأدوار الإدارية (admin وsupervisor وsheet_manager وaccountant).
 * يعيد للمستخدم الحالي المجموعات والعناصر المسموح بها فقط، والمجموعة الفارغة تُحذف.
 * شروط ظهور كل عنصر منقولة حرفياً من الشريط العلوي السابق (layouts/panel)؛ الـBlade لا يحوي شروط أدوار.
 *
 * لإضافة عنصر في مرحلة قادمة: سطر واحد في items() بالأدوار المسموحة.
 */
class AdminMenu
{
    /** الأدوار التي تستخدم القائمة الجانبية (الموزع والمندوب يبقيان على شريط الجوال) */
    public const SIDEBAR_ROLES = [Role::Admin, Role::Supervisor, Role::SheetManager, Role::Accountant, Role::RepSupervisor];

    /** مدة تخزين أعداد الشارات (ثوانٍ) لكل مستخدم */
    public const BADGE_TTL = 60;

    public const GROUPS = [
        'daily' => 'التشغيل اليومي',
        'data' => 'البيانات',
        'finance' => 'المالية',
        'admin' => 'الإدارة',
    ];

    public static function usesSidebar(?User $user): bool
    {
        return $user !== null && $user->hasRole(...self::SIDEBAR_ROLES);
    }

    /**
     * @return array<int, array{key: string, label: string, items: array<int, array{label: string, route: string, icon: string, active: array<int, string>, badge: ?int}>}>
     */
    public static function for(User $user): array
    {
        if (! self::usesSidebar($user)) {
            return [];
        }

        $badges = null;
        $groups = [];

        foreach (self::items($user) as $item) {
            if (! $user->hasRole(...$item['roles'])) {
                continue;
            }

            $badge = null;
            if (isset($item['badge'])) {
                $badges ??= self::badgeCounts($user);
                $badge = ($badges[$item['badge']] ?? 0) ?: null;
            }

            // مشرف المندوبين (6B): القسم المالي له عرض فقط
            $label = $item['group'] === 'finance' && $user->role === Role::RepSupervisor ? 'المالية (عرض)' : self::GROUPS[$item['group']];
            $groups[$item['group']] ??= ['key' => $item['group'], 'label' => $label, 'items' => []];
            $groups[$item['group']]['items'][] = [
                'label' => $item['label'],
                'route' => $item['route'],
                'icon' => $item['icon'],
                'active' => $item['active'],
                'badge' => $badge,
            ];
        }

        // ترتيب المجموعات ثابت، والفارغة لا تظهر
        return array_values(array_filter(array_map(fn ($key) => $groups[$key] ?? null, array_keys(self::GROUPS))));
    }

    /**
     * كل العناصر وشروط ظهورها (الأدوار) كما كانت في الشريط العلوي.
     *
     * @return array<int, array{group: string, label: string, route: string, icon: string, active: array<int, string>, roles: array<int, Role>, badge?: string}>
     */
    private static function items(User $user): array
    {
        $isAdmin = $user->isAdmin();

        return [
            // التشغيل اليومي
            ['group' => 'daily', 'label' => 'الرفع', 'route' => 'sheet-manager.index', 'icon' => 'fa-cloud-arrow-up',
                'active' => ['sheet-manager.index', 'sheet-manager.batches.*'], 'roles' => [Role::Admin, Role::SheetManager]],
            ['group' => 'daily', 'label' => $isAdmin ? 'تجهيز الكشوف' : 'الكشوف', 'route' => 'sheet-manager.sheets.index', 'icon' => 'fa-layer-group',
                'active' => ['sheet-manager.sheets.*'], 'roles' => [Role::Admin, Role::SheetManager]],
            ['group' => 'daily', 'label' => $isAdmin ? 'التوزيع' : ($user->role === Role::RepSupervisor ? 'الكشوف' : 'الكشوفات'), 'route' => 'supervisor.index', 'icon' => 'fa-table-list',
                'active' => ['supervisor.index', 'supervisor.sheet.show'], 'roles' => [Role::Admin, Role::Supervisor, Role::RepSupervisor]],
            ['group' => 'daily', 'label' => 'بلا كشف', 'route' => 'invoices.unassigned', 'icon' => 'fa-inbox',
                'active' => ['invoices.unassigned'], 'roles' => [Role::Admin, Role::SheetManager], 'badge' => 'unassigned'],
            ['group' => 'daily', 'label' => 'الطلبيات المؤجلة', 'route' => 'invoices.deferred', 'icon' => 'fa-clock',
                'active' => ['invoices.deferred'], 'roles' => [Role::Admin, Role::SheetManager, Role::Supervisor, Role::Accountant], 'badge' => 'deferred'],
            ['group' => 'daily', 'label' => 'المواقع', 'route' => 'supervisor.far-visits', 'icon' => 'fa-location-crosshairs',
                'active' => ['supervisor.far-visits'], 'roles' => [Role::Admin, Role::Supervisor]],
            // المرحلة 8B: خطة المندوب والتغطية
            ['group' => 'daily', 'label' => 'خطط المندوبين', 'route' => 'rep-supervisor.plans', 'icon' => 'fa-route',
                'active' => ['rep-supervisor.plans', 'rep-supervisor.plan.*'], 'roles' => [Role::Admin, Role::RepSupervisor]],
            ['group' => 'daily', 'label' => 'تغطية المندوبين', 'route' => 'rep-supervisor.coverage', 'icon' => 'fa-chart-column',
                'active' => ['rep-supervisor.coverage*'], 'roles' => [Role::Admin, Role::RepSupervisor]],

            // البيانات (المرحلة 8C: أُزيلت من قائمة مشرف التوزيع؛ الصفحات باقية للأدوار الأخرى)
            ['group' => 'data', 'label' => 'الزبائن', 'route' => 'customers.index', 'icon' => 'fa-store',
                'active' => ['customers.index'], 'roles' => [Role::Admin, Role::SheetManager]],
            ['group' => 'data', 'label' => 'مطابقة الزبائن', 'route' => 'customers.review', 'icon' => 'fa-user-check',
                'active' => ['customers.review'], 'roles' => [Role::Admin, Role::SheetManager]],
            ['group' => 'data', 'label' => 'السلاسل', 'route' => 'admin.series.index', 'icon' => 'fa-tags',
                'active' => ['admin.series.*'], 'roles' => [Role::Admin]],
            // المرحلة 7B: «تغيّرات الأرصدة» قراءة لمشرف التوزيع ومسؤول الكشوف (خارج القسم المالي)
            ['group' => 'data', 'label' => 'تغيّرات الأرصدة', 'route' => 'finance.balance-changes', 'icon' => 'fa-scale-balanced',
                'active' => ['finance.balance-changes*'], 'roles' => [Role::SheetManager]],
            // المرحلة 7C: «التعديلات اللاحقة» قراءة لمشرف التوزيع ومسؤول الكشوف
            ['group' => 'data', 'label' => 'التعديلات اللاحقة', 'route' => 'finance.adjustments', 'icon' => 'fa-file-pen',
                'active' => ['finance.adjustments*'], 'roles' => [Role::SheetManager], 'badge' => 'adjustments'],

            // المالية
            ['group' => 'finance', 'label' => $isAdmin ? 'شاشة المحاسب' : 'الكشوفات', 'route' => 'accountant.index', 'icon' => 'fa-calculator',
                'active' => ['accountant.*', 'finance.sheets.*'], 'roles' => [Role::Admin, Role::Accountant]],
            // المرحلة 6B: القسم المالي للأدمن والمحاسب ومشرف المندوبين (عرض)، وليس لمشرف التوزيع
            ['group' => 'finance', 'label' => 'دفعات المندوبين', 'route' => 'finance.rep-payments', 'icon' => 'fa-receipt', 'active' => ['finance.rep-payments*'], 'roles' => [Role::Admin, Role::Accountant, Role::RepSupervisor]],
            ['group' => 'finance', 'label' => 'ذمم المندوبين', 'route' => 'finance.rep-receivables', 'icon' => 'fa-hand-holding-dollar', 'active' => ['finance.rep-receivables*'], 'roles' => [Role::Admin, Role::Accountant, Role::RepSupervisor]],
            ['group' => 'finance', 'label' => 'غير المسدد', 'route' => 'finance.unpaid', 'icon' => 'fa-file-invoice-dollar', 'active' => ['finance.unpaid*'], 'roles' => [Role::Admin, Role::Accountant, Role::RepSupervisor]],
            ['group' => 'finance', 'label' => 'تغيّرات الأرصدة', 'route' => 'finance.balance-changes', 'icon' => 'fa-scale-balanced', 'active' => ['finance.balance-changes*'], 'roles' => [Role::Admin, Role::Accountant, Role::RepSupervisor]],
            // المرحلة 7C: شارة «تعديلات بانتظار القرار»
            ['group' => 'finance', 'label' => 'التعديلات اللاحقة', 'route' => 'finance.adjustments', 'icon' => 'fa-file-pen', 'active' => ['finance.adjustments*'], 'roles' => [Role::Admin, Role::Accountant, Role::RepSupervisor], 'badge' => 'adjustments'],

            // الإدارة
            ['group' => 'admin', 'label' => 'المستخدمون', 'route' => 'supervisor.users.index', 'icon' => 'fa-users-gear',
                'active' => ['supervisor.users.*'], 'roles' => [Role::Admin]],
            // المرحلة 8A: إسناد المندوبين لمشرفي المندوبين (الأدمن وحده)
            ['group' => 'admin', 'label' => 'إسناد المندوبين', 'route' => 'admin.rep-assignments.index', 'icon' => 'fa-people-arrows',
                'active' => ['admin.rep-assignments.*'], 'roles' => [Role::Admin]],
            ['group' => 'admin', 'label' => 'الإعدادات', 'route' => 'settings.index', 'icon' => 'fa-sliders',
                'active' => ['settings.*'], 'roles' => [Role::Admin, Role::Supervisor]],
            ['group' => 'admin', 'label' => 'سجل النشاط', 'route' => 'activity.index', 'icon' => 'fa-clock-rotate-left',
                'active' => ['activity.*'], 'roles' => [Role::Admin, Role::SheetManager, Role::Supervisor, Role::Accountant, Role::RepSupervisor]],
        ];
    }

    /**
     * أعداد الشارات في استعلام واحد، مخزَّنة دقيقة لكل مستخدم.
     *
     * @return array{unassigned: int, deferred: int, adjustments: int}
     */
    public static function badgeCounts(User $user): array
    {
        return Cache::remember('admin-menu-badges:'.$user->id, self::BADGE_TTL, function () {
            $row = Invoice::query()
                ->whereIn('placement', [Invoice::UNASSIGNED, Invoice::DEFERRED])
                ->selectRaw('SUM(CASE WHEN placement = ? THEN 1 ELSE 0 END) as unassigned', [Invoice::UNASSIGNED])
                ->selectRaw('SUM(CASE WHEN placement = ? THEN 1 ELSE 0 END) as deferred', [Invoice::DEFERRED])
                ->first();

            return [
                'unassigned' => (int) ($row->unassigned ?? 0),
                'deferred' => (int) ($row->deferred ?? 0),
                // المرحلة 7C: تعديلات لاحقة (من الملف) بانتظار القرار
                'adjustments' => \App\Models\InvoiceChangeProposal::where('type', \App\Models\InvoiceChangeProposal::TYPE_POST_CLOSE)
                    ->where('status', \App\Models\InvoiceChangeProposal::AWAITING_ADJUSTMENT)->count(),
            ];
        });
    }
}
