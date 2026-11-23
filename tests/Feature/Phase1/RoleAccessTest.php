<?php

namespace Tests\Feature\Phase1;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * المسار => الأدوار المسموح لها
     */
    public static function routeMatrix(): array
    {
        return [
            'supervisor.index' => ['/supervisor', [Role::Admin, Role::Supervisor]],
            'sheet-manager.index' => ['/sheet-manager', [Role::Admin, Role::SheetManager]],
            'sheet-manager.sheets' => ['/sheet-manager/sheets', [Role::Admin, Role::SheetManager]],
            'invoices.unassigned' => ['/invoices/unassigned', [Role::Admin, Role::SheetManager]],
            'invoices.deferred' => ['/invoices/deferred', [Role::Admin, Role::SheetManager, Role::Supervisor, Role::Accountant]],
            'customers.review' => ['/customers/review', [Role::Admin, Role::SheetManager]],
            'customers.index' => ['/customers', [Role::Admin, Role::SheetManager, Role::Supervisor]],
            'users' => ['/supervisor/users', [Role::Admin]],
            // المرحلة 8C: سجل النشاط لكل الأدوار (المرئي وحده يختلف)
            'activity' => ['/activity', [Role::Admin, Role::Supervisor, Role::SheetManager, Role::Distributor, Role::Accountant]],
            'accountant.index' => ['/accountant', [Role::Admin, Role::Accountant]],
            'distributor.index' => ['/distributor', [Role::Distributor]],
        ];
    }

    #[DataProvider('routeMatrix')]
    public function test_each_role_reaches_only_allowed_screens(string $uri, array $allowed): void
    {
        foreach ([Role::Admin, Role::SheetManager, Role::Supervisor, Role::Distributor, Role::Accountant] as $role) {
            $user = User::factory()->role($role)->create();
            $response = $this->actingAs($user)->get($uri);

            if (in_array($role, $allowed, true)) {
                $response->assertOk();
            } else {
                $response->assertForbidden();
            }
        }
    }

    /**
     * المرحلة 7A (G): قرارات «مراجعة الفروق» (تطبيق، تجاهل، تطبيق الكل) لمسؤول الكشوف والمدير فقط.
     */
    public function test_import_review_decisions_are_for_sheet_manager_and_admin_only(): void
    {
        $batch = \App\Models\ImportBatch::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'stats' => ['items_version' => 1], 'warnings' => []]);
        $item = \App\Models\ImportBatchItem::create([
            'batch_id' => $batch->id, 'kind' => 'unchanged', 'status' => 'info', 'invoice_number' => 'ع G: 1', 'reason' => 'للاختبار',
        ]);
        $routes = [
            route('sheet-manager.items.apply', $item),
            route('sheet-manager.items.reject', $item),
            route('sheet-manager.batches.apply-many', $batch),
        ];

        foreach (Role::cases() as $role) {
            $user = User::factory()->role($role)->create(in_array($role, [Role::Rep], true) ? ['cost_center_code' => '1', 'cost_center_label' => '1-x'] : []);
            foreach ($routes as $uri) {
                $response = $this->actingAs($user)->post($uri);
                in_array($role, [Role::Admin, Role::SheetManager], true)
                    ? $this->assertNotSame(403, $response->status(), "{$role->value} {$uri}")
                    : $response->assertForbidden();
            }
        }
    }

    /**
     * المرحلة 6: صار للمندوب واجهته (/rep)، فمسارات الأدوار الأخرى 403 له، و/no-interface تحوّله إلى واجهته.
     */
    public function test_rep_has_its_own_interface_and_other_routes_are_forbidden(): void
    {
        $rep = User::factory()->role(Role::Rep)->create();

        $this->actingAs($rep)->get('/supervisor')->assertForbidden();
        $this->actingAs($rep)->get('/')->assertRedirect(route('rep.index'));
        $this->actingAs($rep)->get(route('no-interface'))->assertRedirect(route('rep.index'));
        $this->actingAs($rep)->get(route('rep.index'))->assertOk();
    }

    public function test_home_and_dashboard_redirect_by_role(): void
    {
        $expected = [
            'admin' => 'supervisor.index',
            'sheet_manager' => 'sheet-manager.index',
            'supervisor' => 'supervisor.index',
            'distributor' => 'distributor.index',
            'accountant' => 'accountant.index',
        ];

        foreach ($expected as $role => $routeName) {
            $user = User::factory()->role(Role::from($role))->create();
            $this->actingAs($user)->get('/')->assertRedirect(route($routeName));
            $this->actingAs($user)->get('/dashboard')->assertRedirect(route($routeName));
        }
    }

    public function test_login_redirects_admin_to_supervisor_screen(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('supervisor.index', absolute: false));
    }

    public function test_sheet_manager_label_and_login_redirect(): void
    {
        $manager = User::factory()->role(Role::SheetManager)->create();

        $this->assertSame('مسؤول الكشوف', Role::SheetManager->label());
        $this->post('/login', ['email' => $manager->email, 'password' => 'password'])
            ->assertRedirect(route('sheet-manager.index', absolute: false));
        $this->actingAs($manager)->get('/sheet-manager')->assertSee('مسؤول الكشوف')->assertSee(route('invoices.unassigned'));
    }

    public function test_supervisor_does_not_see_users_link_but_admin_does(): void
    {
        $supervisor = User::factory()->role(Role::Supervisor)->create();
        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($supervisor)->get('/supervisor')->assertDontSee(route('supervisor.users.index'));
        $this->actingAs($admin)->get('/supervisor')->assertSee(route('supervisor.users.index'));
    }

    public function test_public_registration_and_debug_route_are_removed(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'x', 'email' => 'x@example.com', 'password' => 'password', 'password_confirmation' => 'password',
        ])->assertNotFound();
        $this->get('/debug-sheet-download/1')->assertNotFound();
        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        foreach (['/supervisor', '/activity', '/accountant', '/distributor', '/supervisor/users'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
    }
}
