<?php

namespace Tests\Feature\UI;

use App\Enums\Role;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Navigation\AdminMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * القائمة الجانبية للأدوار الإدارية: نفس عناصر الشريط العلوي السابق لكل دور حرفياً.
 */
class SidebarTest extends TestCase
{
    use RefreshDatabase;

    /**
     * جدول «قبل التعديل»: مسارات عناصر الشريط العلوي لكل دور كما كانت في layouts/panel (بلا «حسابي»).
     * يجب أن تطابقه القائمة الجانبية تماماً.
     */
    private const BEFORE = [
        'admin' => [
            'sheet-manager.index', 'sheet-manager.sheets.index', 'supervisor.index', 'invoices.deferred', 'invoices.unassigned',
            'customers.review', 'customers.index', 'supervisor.users.index', 'admin.series.index', 'supervisor.far-visits',
            'settings.index', 'activity.index', 'accountant.index', 'finance.unpaid', 'finance.rep-payments', 'finance.rep-receivables',
            // المرحلة 7B
            'finance.balance-changes',
            // المرحلة 7C
            'finance.adjustments',
            // المرحلة 8A: إسناد المندوبين (الأدمن وحده)
            'admin.rep-assignments.index',
            // المرحلة 8B: خطط المندوبين والتغطية
            'rep-supervisor.plans', 'rep-supervisor.coverage',
        ],
        // المرحلة 6B: مشرف التوزيع خارج القسم المالي كله
        // المرحلة 8C: أُزيلت مجموعة «البيانات» (الزبائن، تغيّرات الأرصدة، التعديلات اللاحقة) من قائمة مشرف التوزيع
        'supervisor' => ['supervisor.index', 'invoices.deferred', 'supervisor.far-visits', 'settings.index', 'activity.index'],
        // المرحلة 6B: مشرف المندوبين: «الكشوف» و«المالية (عرض)» فقط
        // المرحلة 8B: + خطط المندوبين والتغطية
        // المرحلة 8C: + سجل النشاط
        'rep_supervisor' => ['supervisor.index', 'rep-supervisor.plans', 'rep-supervisor.coverage', 'finance.unpaid', 'finance.rep-payments', 'finance.rep-receivables', 'finance.balance-changes', 'finance.adjustments', 'activity.index'],
        'sheet_manager' => ['sheet-manager.index', 'sheet-manager.sheets.index', 'invoices.deferred', 'invoices.unassigned', 'customers.review', 'customers.index', 'activity.index', 'finance.balance-changes', 'finance.adjustments'],
        // المرحلة 6: «دفعات المندوبين» و«ذمم المندوبين» للأدمن والمحاسب (ومشرف المندوبين منذ 6B)
        // المرحلة 8C: + سجل النشاط
        'accountant' => ['invoices.deferred', 'accountant.index', 'finance.unpaid', 'finance.rep-payments', 'finance.rep-receivables', 'finance.balance-changes', 'finance.adjustments', 'activity.index'],
    ];

    /** كل مسارات القائمة الإدارية */
    private const ALL = [
        'sheet-manager.index', 'sheet-manager.sheets.index', 'supervisor.index', 'invoices.deferred', 'invoices.unassigned',
        'customers.review', 'customers.index', 'supervisor.users.index', 'admin.series.index', 'supervisor.far-visits',
        'settings.index', 'activity.index', 'accountant.index', 'finance.unpaid', 'finance.rep-payments', 'finance.rep-receivables',
        'finance.balance-changes', 'finance.adjustments', 'admin.rep-assignments.index', 'rep-supervisor.plans', 'rep-supervisor.coverage',
    ];

    private function menuRoutes(string $html): array
    {
        preg_match_all('/data-menu-item="([^"]+)"/', $html, $m);

        return $m[1];
    }

    public function test_each_admin_role_sees_exactly_its_previous_items(): void
    {
        foreach (self::BEFORE as $role => $expected) {
            $user = User::factory()->role(Role::from($role))->create();
            $html = $this->actingAs($user)->get(route($user->role->homeRoute()))->assertOk()->getContent();

            $this->assertStringContainsString('<nav class="app-sidebar-nav" aria-label="القائمة الرئيسية">', $html, $role);
            $this->assertEqualsCanonicalizing($expected, $this->menuRoutes($html), "عناصر الدور {$role}");

            // لا عنصر لدور آخر (حتى خارج القائمة)
            foreach (array_diff(self::ALL, $expected) as $forbidden) {
                $this->assertStringNotContainsString('href="'.route($forbidden).'"', $html, "{$role} يرى {$forbidden}");
            }
            // لا يبقى الشريط العلوي القديم للإداريين
            $this->assertStringNotContainsString('app-navbar', $html, $role);
        }
    }

    public function test_examples_from_the_spec(): void
    {
        $supervisor = User::factory()->role(Role::Supervisor)->create();
        $manager = User::factory()->role(Role::SheetManager)->create();
        $accountant = User::factory()->role(Role::Accountant)->create();

        $this->actingAs($supervisor)->get('/supervisor')->assertDontSee('تجهيز الكشوف')->assertDontSee('data-menu-item="sheet-manager.sheets.index"', false);
        $this->actingAs($manager)->get('/sheet-manager')->assertDontSee('المستخدمون')->assertDontSee('data-menu-item="supervisor.users.index"', false);
        $this->actingAs($accountant)->get('/accountant')->assertDontSee('data-menu-item="settings.index"', false);
    }

    public function test_groups_are_ordered_and_empty_groups_are_removed(): void
    {
        $accountant = AdminMenu::for(User::factory()->role(Role::Accountant)->create());
        $this->assertSame(['daily', 'finance', 'admin'], array_column($accountant, 'key'));   // لا «البيانات»؛ «الإدارة» لسجل النشاط (8C)

        $admin = AdminMenu::for(User::factory()->role(Role::Admin)->create());
        $this->assertSame(['daily', 'data', 'finance', 'admin'], array_column($admin, 'key'));
        $this->assertSame(['التشغيل اليومي', 'البيانات', 'المالية', 'الإدارة'], array_column($admin, 'label'));

        $supervisor = AdminMenu::for(User::factory()->role(Role::Supervisor)->create());
        $this->assertSame(['daily', 'admin'], array_column($supervisor, 'key'));   // المرحلة 8C: بلا «البيانات»

        $repSupervisor = AdminMenu::for(User::factory()->role(Role::RepSupervisor)->create());
        $this->assertSame(['daily', 'finance', 'admin'], array_column($repSupervisor, 'key'));   // المرحلة 8C: «الإدارة» لسجل النشاط
        $this->assertSame(['التشغيل اليومي', 'المالية (عرض)', 'الإدارة'], array_column($repSupervisor, 'label'));
        $this->assertSame('الكشوف', $repSupervisor[0]['items'][0]['label']);
        $routes = collect($supervisor)->flatMap(fn ($g) => array_column($g['items'], 'route'))->all();
        $this->assertNotContains('supervisor.users.index', $routes);
        $this->assertNotContains('invoices.unassigned', $routes);

        $this->assertSame([], AdminMenu::for(User::factory()->role(Role::Distributor)->create()));
        $this->assertSame([], AdminMenu::for(User::factory()->role(Role::Rep)->create()));
    }

    public function test_distributor_and_rep_keep_the_old_mobile_bar(): void
    {
        $distributor = User::factory()->role(Role::Distributor)->create();
        $this->actingAs($distributor)->get('/distributor')
            ->assertOk()
            ->assertDontSee('app-sidebar', false)
            ->assertSee('app-navbar', false)
            ->assertSee(route('distributor.customers'))
            ->assertSee('كشوفاتي');

        $rep = User::factory()->role(Role::Rep)->create();
        $this->actingAs($rep)->get(route('rep.index'))
            ->assertOk()
            ->assertDontSee('app-sidebar', false)
            ->assertSee('app-navbar', false)
            ->assertSee(route('rep.customers'))
            ->assertSee('ذممي');
    }

    public function test_active_item_has_aria_current(): void
    {
        $manager = User::factory()->role(Role::SheetManager)->create();
        $html = $this->actingAs($manager)->get(route('sheet-manager.sheets.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/class="app-sidebar-link active"\s+aria-current="page"\s+data-menu-item="sheet-manager.sheets.index"/', $html);
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));

        // الملف الشخصي يستخدم القالب الجديد وعنصره نشط
        $profile = $this->actingAs($manager)->get(route('profile.edit'))->assertOk()->assertSee('app-sidebar', false)->getContent();
        $this->assertMatchesRegularExpression('/aria-current="page"\s+title="الملف الشخصي"/u', $profile);
    }

    public function test_logout_from_sidebar_is_a_post_form_and_works(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $html = $this->actingAs($admin)->get('/supervisor')->getContent();

        $this->assertMatchesRegularExpression('/<form method="POST" action="'.preg_quote(route('logout'), '/').'"[^>]*data-logout-form/', $html);
        $this->assertStringNotContainsString('<a href="'.route('logout').'"', $html);

        $this->post(route('logout'))->assertRedirect();   // السلوك الحالي كما هو
        $this->assertGuest();
    }

    public function test_badges_respect_permissions_and_are_cached(): void
    {
        Cache::flush();
        $base = ['invoice_number' => 'x', 'customer_name' => 'ز', 'invoice_amount' => 10];
        Invoice::create($base + ['invoice_number' => 'ع G: 1', 'placement' => Invoice::UNASSIGNED]);
        Invoice::create($base + ['invoice_number' => 'ع G: 2', 'placement' => Invoice::UNASSIGNED]);
        Invoice::create($base + ['invoice_number' => 'ع G: 3', 'placement' => Invoice::DEFERRED]);

        $manager = User::factory()->role(Role::SheetManager)->create();
        $html = $this->actingAs($manager)->get('/sheet-manager')->getContent();
        $this->assertMatchesRegularExpression('/data-menu-badge="invoices.unassigned">2</', $html);
        $this->assertMatchesRegularExpression('/data-menu-badge="invoices.deferred">1</', $html);

        // المحاسب والمشرف لا يرون شارة «بلا كشف» (لا يملكون العنصر)
        foreach ([Role::Accountant, Role::Supervisor] as $role) {
            $user = User::factory()->role($role)->create();
            $html = $this->actingAs($user)->get(route($user->role->homeRoute()))->getContent();
            $this->assertStringNotContainsString('data-menu-badge="invoices.unassigned"', $html, $role->value);
            $this->assertMatchesRegularExpression('/data-menu-badge="invoices.deferred">1</', $html);
        }

        // التخزين المؤقت: صفحتان متتاليتان لا تكرران استعلام الأعداد
        $admin = User::factory()->role(Role::Admin)->create();
        $badgeQueries = 0;
        DB::listen(function ($query) use (&$badgeQueries) {
            if (str_contains($query->sql, 'SUM(CASE WHEN placement')) {
                $badgeQueries++;
            }
        });
        $this->actingAs($admin)->get('/supervisor')->assertOk();
        $this->actingAs($admin)->get('/invoices/deferred')->assertOk();
        $this->assertSame(1, $badgeQueries);

        $this->assertSame(['unassigned' => 2, 'deferred' => 1, 'adjustments' => 0], Cache::get('admin-menu-badges:'.$admin->id));
    }

    public function test_print_css_hides_sidebar_and_bars(): void
    {
        $css = file_get_contents(public_path('css/app-panel.css'));
        $print = substr($css, strpos($css, '@media print'));

        foreach (['.app-sidebar', '.app-topbar', '.app-navbar'] as $selector) {
            $this->assertStringContainsString($selector, $print);
        }
        $this->assertStringContainsString('display: none !important', $print);
    }
}
