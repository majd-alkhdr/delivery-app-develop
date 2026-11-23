<?php

namespace Tests\Feature\Phase8C;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\RepSupervisorAssignment;
use App\Models\Setting;
use App\Models\User;
use App\Services\Reps\RepScope;
use App\Support\Navigation\AdminMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * المرحلة 8C (3): رؤية سجل النشاط حسب منفّذ الإجراء — المصفوفة كاملة لكل دور متصفح.
 */
class ActivityVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /** الدور المتصفح => أدوار المنفّذين المرئية (عدا نفسه)؛ null = الكل مع الآلي */
    private const MATRIX = [
        'admin' => null,
        'supervisor' => ['distributor'],
        // المرحلة 8C2: مندوبوه المسنَدون له حالياً فقط (كان: كل المندوبين)
        'rep_supervisor' => 'assigned',
        'rep' => [],
        'distributor' => [],
        'sheet_manager' => ['supervisor', 'distributor'],
        'accountant' => ['sheet_manager', 'supervisor', 'distributor', 'accountant', 'rep', 'rep_supervisor'],
    ];

    /** @var array<string, array<int, User>> مستخدمان من كل دور */
    private array $users = [];

    /** @var array<int, int> معرّف السجل => معرّف المنفّذ (0 = آلي) */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();

        // منفّذ آلي بلا مستخدم
        $this->logs[ActivityLog::record('import.failed', null, [], ['status' => 'failed'])->id] = 0;

        foreach (array_keys(self::MATRIX) as $role) {
            foreach ([1, 2] as $n) {
                $user = User::factory()->role(Role::from($role))->create(['name' => "{$role}-{$n}"]);
                $this->users[$role][] = $user;
                Auth::guard()->setUser($user);   // بلا حدث دخول (لا سجل auth.login)
                // سجل يذكر مستخدماً آخر في تفاصيله لا يغيّر شيئاً (المعيار المنفّذ)
                $this->logs[ActivityLog::record('sheet.assigned', null, [], ['distributor_id' => 1], ['by_role' => $role])->id] = $user->id;
            }
        }
        Auth::forgetGuards();

        // المرحلة 8C2: المندوب الأول مسنَد للمشرف الأول (كل صيدلياته)؛ الثاني بلا مشرف. إنشاء مباشر كي لا يُضاف سجل.
        RepSupervisorAssignment::create([
            'rep_user_id' => $this->users['rep'][0]->id, 'supervisor_user_id' => $this->users['rep_supervisor'][0]->id,
            'scope_type' => RepSupervisorAssignment::ALL, 'scope_value' => null, 'scope_label' => 'كل صيدلياته',
        ]);
    }

    /** @return array<int, int> معرّفات السجلات المتوقع أن يراها المستخدم */
    private function expectedFor(User $viewer): array
    {
        $roles = self::MATRIX[$viewer->role->value];
        $visibleUsers = match (true) {
            $roles === null => null,
            $roles === 'assigned' => RepSupervisorAssignment::active()->where('supervisor_user_id', $viewer->id)->pluck('rep_user_id')->push($viewer->id)->all(),
            default => collect($roles)->flatMap(fn ($r) => collect($this->users[$r])->pluck('id'))->push($viewer->id)->all(),
        };

        return collect($this->logs)
            ->filter(fn ($actor) => $visibleUsers === null || in_array($actor, $visibleUsers, true))
            ->keys()->sort()->values()->all();
    }

    private function listed(User $viewer, array $query = []): array
    {
        return $this->actingAs($viewer)->get(route('activity.index', $query))->assertOk()
            ->viewData('logs')->getCollection()->pluck('id')->sort()->values()->all();
    }

    public function test_list_details_and_dropdown_follow_the_matrix_for_every_role(): void
    {
        foreach (self::MATRIX as $role => $roles) {
            foreach ($this->users[$role] as $viewer) {
                $expected = $this->expectedFor($viewer);

                // القائمة (والعدّاد)
                $this->assertSame($expected, $this->listed($viewer), "قائمة {$viewer->name}");
                $this->actingAs($viewer)->get(route('activity.index'))->assertViewHas('logs', fn ($logs) => $logs->total() === count($expected));

                // التفاصيل: المرئي 200، وغيره 403
                foreach (array_keys($this->logs) as $logId) {
                    $this->actingAs($viewer)->get(route('activity.show', $logId))
                        ->assertStatus(in_array($logId, $expected, true) ? 200 : 403);
                }

                // القائمة المنسدلة: المرئيون فقط
                $expectedUsers = collect($this->logs)->only($expected)->filter()->unique()->sort()->values()->all();
                $dropdown = $this->actingAs($viewer)->get(route('activity.index'))->viewData('users')->pluck('id')->sort()->values()->all();
                $this->assertSame($expectedUsers, $dropdown, "قائمة مستخدمي {$viewer->name}");
            }
        }
    }

    public function test_specific_rules_from_the_spec(): void
    {
        $actors = fn (User $viewer) => collect($this->listed($viewer))->map(fn ($id) => $this->logs[$id])->unique()->values();

        // admin: الكل بما فيه الآلي
        $this->assertContains(0, $actors($this->users['admin'][0]));
        // لا أحد غير الأدمن يرى الآلي ولا إجراءات الأدمن، حتى المحاسب
        foreach (array_diff(array_keys(self::MATRIX), ['admin']) as $role) {
            $seen = $actors($this->users[$role][0]);
            $this->assertNotContains(0, $seen, $role);
            $this->assertNotContains($this->users['admin'][0]->id, $seen, $role);
            $this->assertNotContains($this->users['admin'][1]->id, $seen, $role);
        }
        // المندوب والموزع: نفسه فقط، لا زميله
        foreach (['rep', 'distributor'] as $role) {
            $this->assertSame([$this->users[$role][0]->id], $actors($this->users[$role][0])->all(), $role);
        }
        // المحاسب يرى محاسباً آخر
        $this->assertContains($this->users['accountant'][1]->id, $actors($this->users['accountant'][0]));
        // مسؤول الكشوف لا يرى المحاسب ولا مشرف المندوبين
        $seen = $actors($this->users['sheet_manager'][0]);
        $this->assertNotContains($this->users['accountant'][0]->id, $seen);
        $this->assertNotContains($this->users['rep_supervisor'][0]->id, $seen);
        $this->assertNotContains($this->users['sheet_manager'][1]->id, $seen);
    }

    /** المرحلة 8C2: كان «يرى كل المندوبين حتى مع تفعيل الحصر»؛ صار «مندوبيه المسنَدين فقط، مع الحصر وبدونه» */
    public function test_rep_supervisor_sees_only_assigned_reps_with_or_without_scoping(): void
    {
        [$mine, $unassigned] = $this->users['rep'];
        foreach ([false, true] as $scoping) {
            Setting::set(RepScope::SETTING, $scoping);
            $viewer = $this->users['rep_supervisor'][0];
            $this->assertSame($this->expectedFor($viewer), $this->listed($viewer));
            $this->assertSame(1, count($this->listed($viewer, ['user_id' => $mine->id])));
            // غير المسنَد يُتجاهل في المرشّح: يعود المرئي كله، بلا سجله
            $this->assertSame($this->expectedFor($viewer), $this->listed($viewer, ['user_id' => $unassigned->id]));
            // المشرف الآخر بلا إسنادات: نفسه فقط
            $other = $this->users['rep_supervisor'][1];
            $this->assertSame([array_search($other->id, $this->logs, true)], $this->listed($other));
        }
    }

    public function test_filters_never_reveal_invisible_records(): void
    {
        $viewer = $this->users['supervisor'][0];
        $expected = $this->expectedFor($viewer);

        // تصفية بمستخدم غير مرئي: تُتجاهل (تعرض المرئي فقط) ولا تكشف شيئاً
        foreach ([$this->users['admin'][0], $this->users['accountant'][0], $this->users['supervisor'][1]] as $hidden) {
            $this->assertSame($expected, $this->listed($viewer, ['user_id' => $hidden->id]));
        }
        // تصفية بمستخدم مرئي
        $distributor = $this->users['distributor'][1];
        $this->assertSame([array_search($distributor->id, $this->logs, true)], $this->listed($viewer, ['user_id' => $distributor->id]));

        // الحدث والتاريخ فوق الحصر
        $this->assertSame([], $this->listed($viewer, ['action' => 'import.failed']));
        $this->assertSame($expected, $this->listed($viewer, ['action' => 'sheet.assigned', 'date_from' => now()->toDateString()]));
        $this->assertSame([], $this->listed($viewer, ['date_from' => now()->addDay()->toDateString()]));

        // قائمة الأحداث من المرئي فقط (import.failed آلي)
        $actions = $this->actingAs($viewer)->get(route('activity.index'))->viewData('actions');
        $this->assertSame(['sheet.assigned'], $actions->keys()->all());
    }

    public function test_activity_link_for_every_role(): void
    {
        foreach (array_keys(self::MATRIX) as $role) {
            $user = $this->users[$role][0];
            $html = $this->actingAs($user)->get(route($user->role->homeRoute()))->assertOk()->getContent();
            $this->assertStringContainsString('href="'.route('activity.index').'"', $html, $role);
            if (AdminMenu::usesSidebar($user)) {
                $this->assertStringContainsString('data-menu-item="activity.index"', $html, $role);
            } else {
                $this->assertStringContainsString('نشاطي', $html, $role);
            }
        }
        // صفحة الموزع والمندوب بطاقات لا جدول عريض
        foreach (['rep', 'distributor'] as $role) {
            $this->actingAs($this->users[$role][0])->get(route('activity.index'))
                ->assertSee('id="activity-cards"', false)->assertDontSee('<table', false);
        }
    }

    public function test_query_count_is_constant_between_small_and_large_tables(): void
    {
        $viewer = $this->users['accountant'][0];
        $count = function () use ($viewer) {
            $this->actingAs($viewer)->get(route('activity.index'))->assertOk();   // تسخين (الشارات المخزّنة)
            $n = 0;
            DB::listen(function () use (&$n) {
                $n++;
            });
            $this->actingAs($viewer)->get(route('activity.index'))->assertOk();

            return $n;
        };
        $small = $count();

        // 20 ألف سجل لمنفّذين مختلفين (إدخال جماعي)
        $actors = collect($this->users)->flatten()->pluck('id')->push(null)->all();
        $now = now()->toDateTimeString();
        foreach (array_chunk(range(1, 20000), 1000) as $chunk) {
            DB::table('activity_logs')->insert(array_map(fn ($i) => [
                'user_id' => $actors[$i % count($actors)], 'action' => 'sheet.assigned', 'created_at' => $now,
            ], $chunk));
        }
        $large = (function () use ($viewer) {
            $n = 0;
            DB::listen(function () use (&$n) {
                $n++;
            });
            $response = $this->actingAs($viewer)->get(route('activity.index'))->assertOk();
            $this->assertGreaterThan(10000, $response->viewData('logs')->total());
            $this->assertCount(25, $response->viewData('logs')->items());   // صفحة واحدة تُجلب، لا السجل كله

            return $n;
        })();

        $this->assertSame($small, $large);
    }
}
