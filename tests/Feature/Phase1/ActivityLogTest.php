<?php

namespace Tests\Feature\Phase1;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_and_logout_are_logged(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/logout');

        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.login', 'user_id' => $user->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.logout', 'user_id' => $user->id]);
    }

    public function test_record_strips_sensitive_values(): void
    {
        $log = ActivityLog::record('user.updated', null, ['password' => 'x'], [
            'name' => 'ok',
            'remember_token' => 'y',
            'nested' => ['api_token' => 'z', 'keep' => 1],
        ]);

        $this->assertSame(['name' => 'ok', 'nested' => ['keep' => 1]], $log->new_values);
        $this->assertNull($log->old_values);
    }

    public function test_supervisor_sees_only_sheet_and_payment_events(): void
    {
        // المرحلة 8C: المعيار منفّذ الإجراء لا نوع الحدث: المشرف يرى نفسه والموزعين، لا الأدمن ولا الآلي
        $supervisor = User::factory()->role(Role::Supervisor)->create();
        $distributor = User::factory()->role(Role::Distributor)->create();
        $admin = User::factory()->role(Role::Admin)->create();
        ActivityLog::record('sheet.uploaded', null, [], ['file_name' => 'a.xlsx']);   // آلي بلا منفّذ
        $this->actingAs($admin);
        ActivityLog::record('user.created', null, [], ['name' => 'x']);
        $this->actingAs($distributor);
        ActivityLog::record('payment.recorded', null, [], ['collected_amount' => 5]);
        $this->actingAs($supervisor);
        ActivityLog::record('sheet.completed', null, [], ['status' => 'completed']);

        $this->actingAs($supervisor)->get(route('activity.index'))
            ->assertOk()
            ->assertViewHas('logs', function ($logs) {
                $actions = collect($logs->items())->pluck('action')->sort()->values()->all();

                return $actions === ['payment.recorded', 'sheet.completed'];
            });

        // حتى لو طلب فلتر حدث غير مرئي لا تظهر النتائج
        $this->actingAs($supervisor)->get(route('activity.index', ['action' => 'user.created']))
            ->assertViewHas('logs', fn ($logs) => $logs->total() === 0);
    }

    public function test_admin_sees_all_events_and_can_filter(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        ActivityLog::record('user.created', null, [], ['name' => 'x']);
        ActivityLog::record('sheet.uploaded', null, [], ['file_name' => 'a.xlsx']);

        $this->actingAs($admin)->get(route('activity.index'))
            ->assertOk()
            ->assertViewHas('logs', fn ($logs) => $logs->total() === 2);
        $this->actingAs($admin)->get(route('activity.index', ['action' => 'user.created']))
            ->assertViewHas('logs', fn ($logs) => $logs->total() === 1);

        $this->actingAs($admin)->get(route('activity.index', ['date_from' => now()->addDay()->toDateString()]))
            ->assertViewHas('logs', fn ($logs) => $logs->total() === 0);
    }
}
