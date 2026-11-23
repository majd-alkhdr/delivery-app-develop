<?php

namespace Tests\Feature\Phase8C;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use App\Support\Navigation\AdminMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * المرحلة 8C (2): إلغاء قسم «البيانات» من قائمة مشرف التوزيع، وخيار «تضمين المندوبين» من شاشة المواقع.
 */
class SupervisorCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_data_section_is_gone_for_supervisor_but_kept_for_other_roles(): void
    {
        $supervisor = User::factory()->role(Role::Supervisor)->create();
        $html = $this->actingAs($supervisor)->get(route('supervisor.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('>البيانات<', $html);
        foreach (['customers.index', 'finance.balance-changes', 'finance.adjustments'] as $route) {
            $this->assertStringNotContainsString('data-menu-item="'.$route.'"', $html, $route);
        }
        $this->assertNotContains('data', array_column(AdminMenu::for($supervisor), 'key'));
        // بقية الصفحة تعمل (والرقم الشارد بعد سطر النطاق أُزيل)
        $this->assertStringContainsString('supervisorSheetsTable', $html);
        $this->assertDoesNotMatchRegularExpression('/<\/[a-z]+>\s*3\s*\n\s*\n/', $html);
        foreach (['supervisor.far-visits', 'invoices.deferred', 'settings.index', 'activity.index'] as $route) {
            $this->actingAs($supervisor)->get(route($route))->assertOk();
        }

        // الصفحات نفسها باقية لمن يملكها في قائمته
        $manager = User::factory()->role(Role::SheetManager)->create();
        $routes = collect(AdminMenu::for($manager))->flatMap(fn ($g) => array_column($g['items'], 'route'))->all();
        foreach (['customers.index', 'finance.balance-changes', 'finance.adjustments'] as $route) {
            $this->assertContains($route, $routes);
            $this->actingAs($manager)->get(route($route))->assertOk();
        }
        $admin = AdminMenu::for(User::factory()->role(Role::Admin)->create());
        $this->assertContains('data', array_column($admin, 'key'));
    }

    public function test_locations_screen_has_no_include_reps_option_and_never_shows_rep_payments(): void
    {
        $supervisor = User::factory()->role(Role::Supervisor)->create();
        $rep = User::factory()->role(Role::Rep)->create();
        $distributor = User::factory()->role(Role::Distributor)->create();
        $customer = Customer::create(['name' => 'صيدلية بعيدة', 'name_normalized' => 'صيدليه بعيده', 'latitude' => 33.5, 'longitude' => 36.3]);

        $make = fn (User $user, string $source, float $amount) => Payment::create([
            'client_uuid' => (string) Str::uuid(), 'receipt_number' => 'R-'.$amount, 'entered_role' => $user->role->value, 'customer_id' => $customer->id, 'amount' => $amount, 'currency' => 'SYP',
            'source' => $source, 'entered_by' => $user->id, 'collected_at' => now(), 'latitude' => 34.5, 'longitude' => 37.3, 'location_flag' => 'far',
        ]);
        $make($rep, 'rep', 111);
        $make($distributor, 'distributor', 222);

        foreach ([[], ['include_reps' => 1], ['include_reps' => 'true', 'flag' => 'far'], ['include_reps' => 1, 'user_id' => $rep->id]] as $query) {
            $response = $this->actingAs($supervisor)->get(route('supervisor.far-visits', $query))->assertOk()
                ->assertDontSee('تضمين المندوبين')->assertDontSee('name="include_reps"', false);
            $this->assertTrue($response->viewData('payments')->every(fn ($p) => $p->source !== 'rep'), json_encode($query));
        }
        $this->actingAs($supervisor)->get(route('supervisor.far-visits'))->assertSee('الدفعات (1)');

        // «مراجع ثبّتها الموزعون» تعمل كما هي
        $this->actingAs($supervisor)->get(route('supervisor.far-visits', ['recent_pins' => 1, 'include_reps' => 1]))->assertOk()->assertSee('recent-pins', false);
    }
}
