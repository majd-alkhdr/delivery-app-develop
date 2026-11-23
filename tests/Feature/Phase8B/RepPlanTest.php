<?php

namespace Tests\Feature\Phase8B;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\RepPlanNotice;
use App\Models\RepPlanOverride;
use App\Models\RepPlanTemplate;
use App\Models\RepPlanVisit;
use App\Models\RepSupervisorAssignment;
use App\Models\User;
use App\Services\Reps\RepAssignmentService;
use App\Services\Reps\RepPlanService;
use App\Services\Reps\RepScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة 8B: الخطة الفعّالة، حصر المشرف، التعديل الديناميكي، نقل الإسناد، والصلاحيات.
 */
class RepPlanTest extends TestCase
{
    use PlanFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlan();
    }

    private function plans(): RepPlanService
    {
        return new RepPlanService(app(RepScope::class));
    }

    private function ids(array $plan): array
    {
        return array_merge([], ...array_column($plan, 'customer_ids'));
    }

    public function test_weekday_numbering_starts_saturday(): void
    {
        $this->assertSame(0, RepPlanTemplate::weekdayOf('2026-10-24'));   // السبت
        $this->assertSame(1, RepPlanTemplate::weekdayOf('2026-10-25'));   // الأحد
        $this->assertSame(6, RepPlanTemplate::weekdayOf('2026-10-30'));   // الجمعة
        $this->assertSame('الأحد', RepPlanService::dayName('2026-10-25'));
    }

    public function test_effective_plan_template_minus_remove_plus_add_and_weeks(): void
    {
        $this->addTemplate($this->sup1, 1, 'area', 'نهر عيشة')->assertSessionHasNoErrors();
        $this->addTemplate($this->sup2, 2, 'area', 'المزة')->assertSessionHasNoErrors();

        // الأحد: نهر عيشة (9 ظاهرة: بلا المخفية، وN10 بلا موقع تبقى في الخطة)
        $sunday = $this->plans()->effective($this->rep, self::TODAY);
        $this->assertCount(1, $sunday);
        $this->assertSame('المشرف أحمد', $sunday[0]['supervisor_name']);
        $this->assertCount(10, $sunday[0]['customer_ids']);
        // الاثنين: المزة من المشرف 2
        $this->assertSame([$this->south[1]->id, $this->south[2]->id, $this->south[3]->id], $this->ids($this->plans()->effective($this->rep, '2026-10-26')));
        // الأحد التالي من القالب نفسه
        $this->assertCount(10, $this->ids($this->plans()->effective($this->rep, '2026-11-01')));

        // تعديل بتاريخ: إزالة نهر عيشة وإضافة المزة يوم الأحد 01-11 فقط
        $this->actingAs($this->sup1)->post(route('rep-supervisor.plan.overrides.remove', $this->rep), [
            'plan_date' => '2026-11-01', 'scope_type' => 'area', 'scope_value' => $this->value('نهر عيشة'), 'reason' => 'إجازة',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->sup2)->post(route('rep-supervisor.plan.overrides.store', $this->rep), [
            'plan_date' => '2026-11-01', 'scope' => $this->sup2->id.'|area|'.$this->value('المزة'),
        ])->assertSessionHasNoErrors();
        $nov1 = $this->plans()->effective($this->rep, '2026-11-01');
        $this->assertSame(['المزة'], array_column($nov1, 'label'));
        $this->assertSame('override', $nov1[0]['source']);
        // الأحد الذي يليه: القالب كما هو
        $this->assertSame(['نهر عيشة'], array_column($this->plans()->effective($this->rep, '2026-11-08'), 'label'));
        $this->assertSame(2, RepPlanOverride::count());
    }

    public function test_area_and_block_overlap_lists_pharmacy_once_for_first_item(): void
    {
        $this->addTemplate($this->sup1, 1, 'area', 'نهر عيشة')->assertSessionHasNoErrors();
        $this->addTemplate($this->sup1, 1, 'block', 'كتلة الميدان')->assertSessionHasNoErrors();
        $plan = $this->plans()->effective($this->rep, self::TODAY);
        $this->assertCount(2, $plan);
        $this->assertCount(10, $plan[0]['customer_ids']);
        $this->assertSame([], $plan[1]['customer_ids']);   // N1..N3 نُسبت للبند الأول
        $this->assertCount(10, array_unique($this->ids($plan)));
    }

    public function test_supervisor_cannot_add_scope_outside_assignment_and_sees_other_as_busy(): void
    {
        $this->addTemplate($this->sup1, 1, 'area', 'المزة', $this->sup1)->assertForbidden();
        // ولا باسم مشرف آخر
        $this->addTemplate($this->sup1, 1, 'area', 'المزة', $this->sup2)->assertForbidden();
        $this->addTemplate($this->sup2, 1, 'area', 'المزة')->assertSessionHasNoErrors();
        $this->addTemplate($this->sup1, 1, 'area', 'نهر عيشة')->assertSessionHasNoErrors();

        // المشرف 1 يرى بند المشرف 2 «مشغول» بلا اسم المنطقة ولا أرقامها
        $page = $this->actingAs($this->sup1)->get(route('rep-supervisor.plan.show', $this->rep))->assertOk();
        $page->assertSee('مشغول — مشرف آخر')->assertSee('منطقة نهر عيشة');
        $this->assertStringNotContainsString('منطقة المزة', $page->getContent());
        // لا يحذف بند غيره
        $other = RepPlanTemplate::where('supervisor_user_id', $this->sup2->id)->first();
        $this->actingAs($this->sup1)->delete(route('rep-supervisor.plan.templates.destroy', $other))->assertForbidden();
        // مشرف بلا إسناد للمندوب
        $sup3 = User::factory()->role(Role::RepSupervisor)->create();
        $this->actingAs($sup3)->get(route('rep-supervisor.plan.show', $this->rep))->assertForbidden();

        // المندوب يرى الخطة مدموجة بوسمين
        $this->actingAs($this->rep)->get(route('rep.plan'))->assertOk()
            ->assertSee('المشرف أحمد')->assertSee('المشرف باسل')->assertSee('صيدلية جنوب 1')->assertSee('صيدلية شمال 1');
    }

    public function test_template_duplicate_day_warning_and_notice_and_log(): void
    {
        $this->addTemplate($this->sup1, 1, 'area', 'نهر عيشة')->assertSessionHasNoErrors();
        $this->addTemplate($this->sup1, 3, 'area', 'نهر عيشة')->assertSessionHas('warning', fn ($w) => str_contains($w, 'أكثر من يوم'));
        $this->addTemplate($this->sup1, 1, 'area', 'نهر عيشة')->assertSessionHasErrors('scope');
        $this->assertSame(2, RepPlanNotice::where('rep_user_id', $this->rep->id)->count());
        $this->assertSame(2, ActivityLog::where('action', 'rep_plan.template_added')->count());
        $this->assertStringContainsString('أضاف المشرف المشرف أحمد', RepPlanNotice::first()->text);

        // المندوب يرى الإشعار حتى «اطلعت»
        $this->actingAs($this->rep)->get(route('rep.plan'))->assertSee('عُدِّلت خطتك');
        foreach (RepPlanNotice::all() as $n) {
            $this->actingAs($this->rep)->post(route('rep.plan.notices.seen', $n))->assertRedirect();
        }
        $this->actingAs($this->rep)->get(route('rep.plan'))->assertDontSee('عُدِّلت خطتك');
        // مندوب آخر لا يعلّم إشعار غيره
        $this->actingAs($this->rep2)->post(route('rep.plan.notices.seen', RepPlanNotice::first()))->assertForbidden();
    }

    public function test_removing_area_today_after_visits_keeps_visits_and_drops_remaining(): void
    {
        $this->addTemplate($this->sup1, 1, 'area', 'نهر عيشة')->assertSessionHasNoErrors();
        $this->visit($this->north[1], $this->near($this->north[1], 20))->assertCreated();
        $this->visit($this->north[2], ['status' => 'not_visited', 'reason_code' => 'closed'])->assertCreated();

        $this->actingAs($this->sup1)->from(route('rep-supervisor.plan.show', $this->rep))->post(route('rep-supervisor.plan.overrides.remove', $this->rep), [
            'plan_date' => self::TODAY, 'scope_type' => 'area', 'scope_value' => $this->value('نهر عيشة'),
        ])->assertSessionHas('success', fn ($m) => str_contains($m, 'سُجّلت 2 زيارة؛ تبقى مسجَّلة ومحتسبة'));

        $page = $this->actingAs($this->rep)->get(route('rep.plan'))->assertOk();
        $page->assertSee('لا خطة لك اليوم')->assertSee('صيدلية شمال 1')->assertSee('صيدلية شمال 2')->assertDontSee('صيدلية شمال 5');
        $this->assertSame(1, RepPlanNotice::where('text', 'like', '%أزال المشرف%')->count());
        $this->assertTrue(ActivityLog::where('action', 'rep_plan.override_added')->exists());

        // التغطية: الزيارتان محتسبتان (المخطَّط = المزارة ∪ لم تتم)
        $coverage = (new \App\Services\Reps\RepCoverageService($this->plans()))->build($this->sup1, ['from' => self::TODAY, 'to' => self::TODAY]);
        $this->assertSame(2, $coverage['totals']['planned']);
        $this->assertSame(1, $coverage['totals']['visited']);
        $this->assertSame(1, $coverage['totals']['not_visited']);
    }

    public function test_template_removal_keeps_past_days(): void
    {
        $this->addTemplate($this->sup1, 1, 'area', 'نهر عيشة')->assertSessionHasNoErrors();
        RepPlanTemplate::query()->update(['starts_on' => '2026-10-01']);
        $template = RepPlanTemplate::first();
        $this->actingAs($this->sup1)->delete(route('rep-supervisor.plan.templates.destroy', $template))->assertRedirect();
        $this->assertSame('2026-10-24', $template->fresh()->ends_on->toDateString());
        // الأحد الماضي ما زال مخطَّطاً، واليوم لا
        $this->assertCount(10, $this->ids($this->plans()->effective($this->rep, '2026-10-18')));
        $this->assertSame([], $this->plans()->effective($this->rep, self::TODAY));
    }

    public function test_past_date_override_is_admin_only(): void
    {
        $this->actingAs($this->sup1)->post(route('rep-supervisor.plan.overrides.store', $this->rep), [
            'plan_date' => '2026-10-20', 'scope' => $this->sup1->id.'|area|'.$this->value('نهر عيشة'),
        ])->assertSessionHasErrors('plan_date');
        $this->actingAs($this->admin)->post(route('rep-supervisor.plan.overrides.store', $this->rep), [
            'plan_date' => '2026-10-20', 'scope' => $this->sup1->id.'|area|'.$this->value('نهر عيشة'),
        ])->assertSessionHasNoErrors();
        $this->assertSame($this->sup1->id, (int) RepPlanOverride::first()->supervisor_user_id);
    }

    public function test_moving_assignment_moves_future_items_and_keeps_past_visit_owner(): void
    {
        $this->addTemplate($this->sup1, 1, 'area', 'نهر عيشة')->assertSessionHasNoErrors();
        RepPlanTemplate::query()->update(['starts_on' => '2026-10-01']);
        $this->actingAs($this->sup1)->post(route('rep-supervisor.plan.overrides.store', $this->rep), [
            'plan_date' => '2026-10-28', 'scope' => $this->sup1->id.'|area|'.$this->value('نهر عيشة'),
        ])->assertSessionHasNoErrors();
        $this->visit($this->north[1], $this->near($this->north[1], 20))->assertCreated();

        // المشرف 3 يستلم نهر عيشة (تُنهى الكتلة المتقاطعة أولاً: قاعدة 8A مشرف واحد للصيدلية)
        RepSupervisorAssignment::where('scope_type', 'block')->update(['ended_at' => now()]);
        $sup3 = User::factory()->role(Role::RepSupervisor)->create(['name' => 'المشرف جاد']);
        $north = RepSupervisorAssignment::where('scope_type', 'area')->where('scope_value', $this->value('نهر عيشة'))->first();
        $this->actingAs($this->admin)->post(route('admin.rep-assignments.move', $north), ['supervisor_id' => $sup3->id, 'confirm' => 1])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'انتقل معه 2 بنداً'));

        // القالب القديم أُنهي أمس ونُسخ للمشرف الجديد من اليوم، والتعديل المستقبلي انتقل
        $this->assertSame('2026-10-24', RepPlanTemplate::where('supervisor_user_id', $this->sup1->id)->first()->ends_on->toDateString());
        $this->assertSame(1, RepPlanTemplate::current()->where('supervisor_user_id', $sup3->id)->count());
        $this->assertSame($sup3->id, (int) RepPlanOverride::first()->supervisor_user_id);
        $this->assertTrue(RepPlanNotice::where('text', 'like', '%انتقل إشراف%المشرف جاد%')->exists());
        // الزيارة السابقة تبقى لمالكها القديم
        $this->assertSame($this->sup1->id, (int) RepPlanVisit::first()->supervisor_user_id);
        // خطة اليوم الآن باسم المشرف الجديد
        $this->assertSame('المشرف جاد', $this->plans()->effective($this->rep, self::TODAY)[0]['supervisor_name']);
    }

    public function test_ending_assignment_disables_items_without_deleting(): void
    {
        $this->addTemplate($this->sup2, 2, 'area', 'المزة')->assertSessionHasNoErrors();
        $south = RepSupervisorAssignment::where('supervisor_user_id', $this->sup2->id)->first();
        $this->actingAs($this->admin)->post(route('admin.rep-assignments.end', $south))
            ->assertSessionHas('warning', fn ($w) => str_contains($w, '1 بنداً في الخطة تعطّل'));
        $this->assertSame(1, RepPlanTemplate::count());
        $this->assertFalse(RepPlanTemplate::first()->active);
        $this->assertSame([], $this->plans()->effective($this->rep, '2026-10-26'));
        $this->actingAs($this->admin)->get(route('rep-supervisor.plans'))->assertSee('بنداً في الخطط تعطّل');
    }

    public function test_assignment_screen_shows_plan_item_counts(): void
    {
        $this->addTemplate($this->sup1, 1, 'area', 'نهر عيشة')->assertSessionHasNoErrors();
        $this->addTemplate($this->sup1, 4, 'area', 'نهر عيشة')->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get(route('admin.rep-assignments.index'))->assertOk()->assertSee('و2 بند خطة');
    }

    /** مصفوفة المسار × الدور */
    public function test_permission_matrix(): void
    {
        $this->addTemplate($this->sup1, 1, 'area', 'نهر عيشة')->assertSessionHasNoErrors();
        $roles = [
            'accountant' => User::factory()->role(Role::Accountant)->create(),
            'distributor' => User::factory()->role(Role::Distributor)->create(),
            'supervisor' => User::factory()->role(Role::Supervisor)->create(),
            'sheet_manager' => User::factory()->role(Role::SheetManager)->create(),
        ];
        $supervisorRoutes = [
            route('rep-supervisor.plans'), route('rep-supervisor.plan.show', $this->rep), route('rep-supervisor.coverage'),
            route('rep-supervisor.coverage.export'), route('rep-supervisor.coverage.day', [$this->rep, self::TODAY]),
        ];
        foreach ($roles as $user) {
            foreach ($supervisorRoutes as $url) {
                $this->actingAs($user)->get($url)->assertForbidden();
            }
            $this->actingAs($user)->get(route('rep.plan'))->assertForbidden();
            $this->actingAs($user)->postJson(route('rep.plan.visits'), [])->assertForbidden();
        }
        // المندوب: خطته فقط، لا شاشات المشرف
        foreach ($supervisorRoutes as $url) {
            $this->actingAs($this->rep)->get($url)->assertForbidden();
        }
        $this->actingAs($this->rep2)->get(route('rep.plan'))->assertOk()->assertSee('لا خطة لك اليوم')->assertDontSee('صيدلية شمال 1');
        // المشرف ضمن نطاقه، والأدمن الكل
        $this->actingAs($this->sup1)->get(route('rep-supervisor.plans'))->assertOk()->assertSee('كرم')->assertDontSee('سامر');
        $this->actingAs($this->sup1)->get(route('rep-supervisor.plan.show', $this->rep2))->assertForbidden();
        $this->actingAs($this->admin)->get(route('rep-supervisor.plans'))->assertOk()->assertSee('كرم')->assertSee('سامر');
        $this->actingAs($this->admin)->get(route('rep-supervisor.plan.show', $this->rep))->assertOk()->assertSee('منطقة نهر عيشة');
        $this->actingAs($this->sup1)->get(route('rep-supervisor.reps.show', [$this->rep, 'tab' => 'plan']))->assertOk()->assertSee('الخطة والتغطية');
    }

    public function test_settings_section_admin_only_and_logged(): void
    {
        $this->actingAs($this->admin)->put(route('settings.rep-plan'), [
            'plan_location_max_distance_m' => 200, 'plan_min_gps_accuracy_m' => 80, 'rep_plan_backfill_days' => 2, 'plan_no_activity_hour' => 12,
        ])->assertSessionHasNoErrors();
        $this->assertSame(200, RepPlanService::setting('plan_location_max_distance_m'));
        $log = ActivityLog::where('action', 'settings.rep_plan_changed')->sole();
        $this->assertSame(150, $log->old_values['plan_location_max_distance_m']);
        $this->assertSame(2, $log->new_values['rep_plan_backfill_days']);
        $this->actingAs(User::factory()->role(Role::Supervisor)->create())->put(route('settings.rep-plan'), [])->assertForbidden();
        $this->actingAs($this->admin)->get(route('settings.index'))->assertSee('خطة المندوب');
    }
}
