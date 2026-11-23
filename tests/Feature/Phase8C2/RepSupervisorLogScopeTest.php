<?php

namespace Tests\Feature\Phase8C2;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\RepSupervisorAssignment;
use App\Models\Setting;
use App\Models\User;
use App\Services\Reps\RepAssignmentService;
use App\Services\Reps\RepScope;
use App\Support\ArabicNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * المرحلة 8C2: مشرف المندوبين يرى في سجل النشاط نفسه + المندوبين المسنَدين له حالياً فقط
 * (إسناد فعّال من 8A بأي نطاق)، دائماً ومستقلاً عن إعداد الحصر.
 */
class RepSupervisorLogScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $s1;
    private User $s2;
    private User $areaRep;      // مسنَد لـ s1 بنطاق منطقة
    private User $allRep;       // مسنَد لـ s1 بنطاق all
    private User $otherRep;     // مسنَد لـ s2
    private User $freeRep;      // بلا مشرف
    private User $sharedRep;    // مشترك: منطقة عند s1 ومنطقة أخرى عند s2
    private User $distributor;
    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['منطقة أ' => 'كتلة 1', 'منطقة ب' => 'كتلة 2'] as $area => $block) {
            Customer::create(['name' => 'صيدلية '.$area, 'name_normalized' => ArabicNormalizer::normalize('صيدلية '.$area),
                'area' => $area, 'area_normalized' => ArabicNormalizer::normalize($area), 'block' => $block]);
        }

        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->s1 = User::factory()->role(Role::RepSupervisor)->create(['name' => 'المشرف الأول']);
        $this->s2 = User::factory()->role(Role::RepSupervisor)->create(['name' => 'المشرف الثاني']);
        foreach (['areaRep', 'allRep', 'otherRep', 'freeRep', 'sharedRep'] as $key) {
            $this->{$key} = User::factory()->role(Role::Rep)->create(['name' => $key]);
        }
        $this->distributor = User::factory()->role(Role::Distributor)->create();
        $this->accountant = User::factory()->role(Role::Accountant)->create();

        $this->actingAs($this->admin);
        $this->assign($this->areaRep, $this->s1, 'area', ['منطقة أ']);
        $this->assign($this->allRep, $this->s1, 'all');
        $this->assign($this->otherRep, $this->s2, 'all');
        $this->assign($this->sharedRep, $this->s1, 'area', ['منطقة أ']);
        $this->assign($this->sharedRep, $this->s2, 'area', ['منطقة ب']);

        // سجلات من كل منفّذ (المندوب المشترك سجلان: أحدهما يخص منطقة المشرف الآخر)
        foreach ([$this->s1, $this->s2, $this->areaRep, $this->allRep, $this->otherRep, $this->freeRep, $this->distributor, $this->accountant] as $user) {
            $this->recordAs($user, 'rep.image_uploaded', ['by' => $user->name]);
        }
        $this->recordAs($this->sharedRep, 'rep_plan.visit_recorded', ['area' => 'منطقة أ']);
        $this->recordAs($this->sharedRep, 'rep.payment_reviewed', ['area' => 'منطقة ب']);
        Auth::forgetGuards();
    }

    private function assign(User $rep, User $supervisor, string $type, array $values = []): RepSupervisorAssignment
    {
        return app(RepAssignmentService::class)->create($rep, $supervisor, $type, $values, $this->admin)->first();
    }

    private function recordAs(User $user, string $action, array $new): void
    {
        Auth::guard()->setUser($user);
        ActivityLog::record($action, null, [], $new);
    }

    /** منفّذو السجلات الظاهرة في الصفحة (كلها في صفحة واحدة هنا) */
    private function actors(User $viewer, array $query = []): array
    {
        $logs = $this->actingAs($viewer)->get(route('activity.index', $query))->assertOk()->viewData('logs');
        $this->assertLessThanOrEqual(25, $logs->total());

        return $logs->getCollection()->pluck('user_id')->unique()->sort()->values()->all();
    }

    private function ids(User ...$users): array
    {
        return collect($users)->pluck('id')->sort()->values()->all();
    }

    public function test_sees_self_and_assigned_reps_only(): void
    {
        $this->assertSame($this->ids($this->s1, $this->areaRep, $this->allRep, $this->sharedRep), $this->actors($this->s1));
        $this->assertSame($this->ids($this->s2, $this->otherRep, $this->sharedRep), $this->actors($this->s2));

        // القائمة المنسدلة للمرئيين فقط
        $dropdown = $this->actingAs($this->s1)->get(route('activity.index'))->viewData('users')->pluck('id')->sort()->values()->all();
        $this->assertSame($this->ids($this->s1, $this->areaRep, $this->allRep, $this->sharedRep), $dropdown);
    }

    public function test_shared_rep_full_log_appears_for_both_supervisors(): void
    {
        foreach ([$this->s1, $this->s2] as $viewer) {
            $logs = $this->actingAs($viewer)->get(route('activity.index', ['user_id' => $this->sharedRep->id]))->viewData('logs');
            $this->assertSame(['rep.payment_reviewed', 'rep_plan.visit_recorded'], $logs->getCollection()->pluck('action')->sort()->values()->all(), $viewer->name);
        }
    }

    public function test_ending_or_moving_an_assignment_takes_effect_immediately(): void
    {
        $service = app(RepAssignmentService::class);
        $allRepAssignment = RepSupervisorAssignment::active()->where('rep_user_id', $this->allRep->id)->sole();

        $service->end($allRepAssignment, $this->admin);
        $this->assertNotContains($this->allRep->id, $this->actors($this->s1));
        // يغيب سجله كله، حتى ما سجّله أثناء إسناده (حد معروف)
        $log = ActivityLog::where('user_id', $this->allRep->id)->sole();
        $this->actingAs($this->s1)->get(route('activity.show', $log))->assertForbidden();

        // النقل: يظهر عند الجديد ويغيب عن القديم
        $areaAssignment = RepSupervisorAssignment::active()->where('rep_user_id', $this->areaRep->id)->sole();
        $service->move($areaAssignment, $this->s2, $this->admin);
        $this->assertNotContains($this->areaRep->id, $this->actors($this->s1));
        $this->assertContains($this->areaRep->id, $this->actors($this->s2));
        $this->actingAs($this->s2)->get(route('activity.show', ActivityLog::where('user_id', $this->areaRep->id)->sole()))->assertOk();
    }

    public function test_same_result_with_scoping_off_and_on(): void
    {
        Setting::set(RepScope::SETTING, false);
        $off = [$this->actors($this->s1), $this->actors($this->s2)];
        Setting::set(RepScope::SETTING, true);
        $on = [$this->actors($this->s1), $this->actors($this->s2)];

        $this->assertSame($off, $on);
        $this->assertSame($this->ids($this->s1, $this->areaRep, $this->allRep, $this->sharedRep), $on[0]);
    }

    public function test_unassigned_rep_is_hidden_everywhere(): void
    {
        foreach ([$this->otherRep, $this->freeRep, $this->distributor] as $hidden) {
            // التفاصيل 403
            $this->actingAs($this->s1)->get(route('activity.show', ActivityLog::where('user_id', $hidden->id)->sole()))->assertForbidden();
            // المرشّح يُتجاهل: يعود المرئي كله بلا سجل المخفي
            $actors = $this->actors($this->s1, ['user_id' => $hidden->id]);
            $this->assertNotContains($hidden->id, $actors);
            $this->assertSame($this->ids($this->s1, $this->areaRep, $this->allRep, $this->sharedRep), $actors);
        }

        // قائمة أنواع الأحداث من المرئي فقط (rep.payment_reviewed للمشترك مرئي، rep_assignment.* للأدمن غير مرئي)
        $actions = $this->actingAs($this->s1)->get(route('activity.index'))->viewData('actions')->keys()->sort()->values()->all();
        $this->assertSame(['rep.image_uploaded', 'rep.payment_reviewed', 'rep_plan.visit_recorded'], $actions);

        // المندوب بلا مشرف: يراه الأدمن والمحاسب فقط
        foreach ([$this->admin, $this->accountant] as $viewer) {
            $this->assertContains($this->freeRep->id, $this->actors($viewer));
        }
        foreach ([$this->s1, $this->s2] as $viewer) {
            $this->assertNotContains($this->freeRep->id, $this->actors($viewer));
        }
    }

    public function test_deleted_rep_drops_out_with_its_assignment(): void
    {
        // لا حذف ناعم ولا تعطيل للحسابات: الحذف نهائي ويحذف إسناداته (cascade)، فلا يبقى إسناد فعّال
        $this->assertNotContains(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive(User::class));
        $this->allRep->delete();
        $this->assertSame(0, RepSupervisorAssignment::where('rep_user_id', $this->allRep->id)->count());
        $this->assertNotContains($this->allRep->id, $this->actors($this->s1));
    }

    public function test_query_count_is_constant_from_one_rep_to_a_hundred_with_a_large_log(): void
    {
        $small = User::factory()->role(Role::RepSupervisor)->create();
        $big = User::factory()->role(Role::RepSupervisor)->create();
        $reps = User::factory()->count(100)->role(Role::Rep)->create();
        $now = now()->toDateTimeString();
        $rows = $reps->map(fn ($rep, $i) => [
            'rep_user_id' => $rep->id, 'supervisor_user_id' => $i === 0 ? $small->id : $big->id, 'scope_type' => 'all',
            'scope_label' => 'كل صيدلياته', 'created_at' => $now, 'updated_at' => $now,
        ])->all();
        // المندوب الأول عند الصغير والكبير معاً، فيملك الكبير 100
        $rows[] = ['rep_user_id' => $reps[0]->id, 'supervisor_user_id' => $big->id, 'scope_type' => 'all', 'scope_label' => 'كل صيدلياته', 'created_at' => $now, 'updated_at' => $now];
        DB::table('rep_supervisor_assignments')->insert($rows);

        $actors = $reps->pluck('id')->push(null)->push($this->distributor->id)->all();
        foreach (array_chunk(range(1, 20000), 1000) as $chunk) {
            DB::table('activity_logs')->insert(array_map(fn ($i) => ['user_id' => $actors[$i % count($actors)], 'action' => 'rep.image_uploaded', 'created_at' => $now], $chunk));
        }

        $count = function (User $viewer) {
            $this->actingAs($viewer)->get(route('activity.index'))->assertOk();   // تسخين
            $n = 0;
            DB::listen(function () use (&$n) {
                $n++;
            });
            $response = $this->actingAs($viewer)->get(route('activity.index'))->assertOk();
            $this->assertCount(25, $response->viewData('logs')->items());

            return [$n, $response->viewData('logs')->total()];
        };
        [$smallQueries, $smallTotal] = $count($small);
        [$bigQueries, $bigTotal] = $count($big);

        $this->assertSame($smallQueries, $bigQueries);
        $this->assertGreaterThan($smallTotal * 50, $bigTotal);
    }
}
