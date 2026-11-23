<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // إجبار لارافيل على استخدام الـ HTML المتوافق مع بوتستراب 5 في الترقيم
        Paginator::useBootstrapFive();

        // المرحلة 6B: قدرات القسم المالي (/finance/* وكتل المال داخل صفحة الكشف)
        // viewFinance: عرض؛ exportFinanceReports: تصدير Excel للتقارير؛ manageFinance: التعديل و«التصدير للمحاسبة»
        Gate::define('viewFinance', fn (User $user) => $user->hasRole(Role::Admin, Role::Accountant, Role::RepSupervisor));
        Gate::define('exportFinanceReports', fn (User $user) => $user->hasRole(Role::Admin, Role::Accountant, Role::RepSupervisor));
        Gate::define('manageFinance', fn (User $user) => $user->hasRole(Role::Admin, Role::Accountant));
        // المرحلة 7B: «تغيّرات الأرصدة» — قراءة للمشرفين ومسؤول الكشوف، والمراجعة للمحاسب والأدمن
        Gate::define('viewBalanceChanges', fn (User $user) => $user->hasRole(Role::Admin, Role::Accountant, Role::Supervisor, Role::SheetManager, Role::RepSupervisor));
        Gate::define('reviewBalanceChanges', fn (User $user) => $user->hasRole(Role::Admin, Role::Accountant));
        // المرحلة 7C: «التعديلات اللاحقة» — قراءة للمشرفَين ومسؤول الكشوف، والتطبيق للمحاسب والأدمن، والتراجع للأدمن
        Gate::define('viewAdjustments', fn (User $user) => $user->hasRole(Role::Admin, Role::Accountant, Role::Supervisor, Role::SheetManager, Role::RepSupervisor));
        Gate::define('applyAdjustments', fn (User $user) => $user->hasRole(Role::Admin, Role::Accountant));
        Gate::define('revertAdjustments', fn (User $user) => $user->isAdmin());

        // سجل النشاط: الدخول والخروج
        Event::listen(function (Login $event) {
            if ($event->user instanceof \Illuminate\Database\Eloquent\Model) {
                ActivityLog::record('auth.login', $event->user, [], [], [
                    'via_cookie' => (bool) $event->remember && ! request()->routeIs('login'),
                ]);
            }
        });

        Event::listen(function (Logout $event) {
            if ($event->user instanceof \Illuminate\Database\Eloquent\Model) {
                ActivityLog::record('auth.logout', $event->user);
            }
        });
    }
}
