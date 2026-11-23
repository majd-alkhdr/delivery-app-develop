<?php

namespace Tests\Feature\Phase1;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_is_cast_to_enum(): void
    {
        $user = User::factory()->role(Role::Accountant)->create();

        $this->assertSame(Role::Accountant, $user->fresh()->role);
        $this->assertSame('محاسب', $user->role->label());
    }

    public function test_supervisor_cannot_manage_users(): void
    {
        $supervisor = User::factory()->role(Role::Supervisor)->create();

        $this->actingAs($supervisor)->post(route('supervisor.users.store'), [
            'name' => 'x', 'email' => 'new@example.com', 'password' => '12345678', 'role' => 'admin',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_admin_creates_user_with_min_8_password_and_no_password_in_log(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($admin)->post(route('supervisor.users.store'), [
            'name' => 'قصير', 'email' => 'short@example.com', 'password' => '1234567', 'role' => 'distributor',
        ])->assertSessionHasErrors('password');

        $this->actingAs($admin)->post(route('supervisor.users.store'), [
            'name' => 'موزع', 'email' => 'new@example.com', 'password' => 'secret-pass-123', 'role' => 'distributor',
        ])->assertSessionHasNoErrors();

        $created = User::where('email', 'new@example.com')->first();
        $this->assertSame(Role::Distributor, $created->role);

        $log = ActivityLog::where('action', 'user.created')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertStringNotContainsString('secret-pass-123', json_encode($log->toArray()));
        $this->assertArrayNotHasKey('password', $log->new_values);
    }

    public function test_password_change_on_update_is_not_logged(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->put(route('supervisor.users.update', $user->id), [
            'name' => $user->name, 'email' => $user->email, 'role' => 'accountant', 'password' => 'another-secret-99',
        ])->assertSessionHasNoErrors();

        $this->assertSame(Role::Accountant, $user->fresh()->role);
        $log = ActivityLog::where('action', 'user.updated')->firstOrFail();
        $this->assertStringNotContainsString('another-secret-99', json_encode($log->toArray()));
        $this->assertSame(['role' => 'distributor'], array_intersect_key($log->old_values, ['role' => 1]));
    }

    public function test_last_admin_cannot_be_deleted_or_demoted(): void
    {
        $onlyAdmin = User::factory()->role(Role::Admin)->create();

        // مستخدم أدمن في الذاكرة فقط (غير محفوظ) حتى يبقى في القاعدة أدمن واحد
        $actor = new User(['name' => 'actor', 'email' => 'actor@example.com', 'role' => Role::Admin]);
        $actor->id = 999999;

        $this->actingAs($actor)->delete(route('supervisor.users.delete', $onlyAdmin->id))
            ->assertSessionHas('error');
        $this->assertNotNull($onlyAdmin->fresh());

        $this->actingAs($actor)->put(route('supervisor.users.update', $onlyAdmin->id), [
            'name' => $onlyAdmin->name, 'email' => $onlyAdmin->email, 'role' => 'supervisor',
        ])->assertSessionHas('error');
        $this->assertSame(Role::Admin, $onlyAdmin->fresh()->role);
    }

    public function test_user_cannot_delete_self_or_change_own_role(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        User::factory()->role(Role::Admin)->create();

        $this->actingAs($admin)->delete(route('supervisor.users.delete', $admin->id))->assertSessionHas('error');
        $this->actingAs($admin)->put(route('supervisor.users.update', $admin->id), [
            'name' => $admin->name, 'email' => $admin->email, 'role' => 'distributor',
        ])->assertSessionHas('error');

        $this->assertSame(Role::Admin, $admin->fresh()->role);
    }

    public function test_make_admin_command_promotes_existing_user(): void
    {
        $user = User::factory()->role(Role::Supervisor)->create(['email' => 'boss@example.com']);

        $this->artisan('user:make-admin', ['email' => 'boss@example.com'])->assertSuccessful();
        $this->assertSame(Role::Admin, $user->fresh()->role);

        $this->artisan('user:make-admin', ['email' => 'missing@example.com'])->assertFailed();
    }
}
