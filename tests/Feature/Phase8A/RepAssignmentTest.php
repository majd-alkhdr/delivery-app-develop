<?php

namespace Tests\Feature\Phase8A;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\RepSupervisorAssignment;
use App\Models\User;
use App\Services\Reps\RepScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 8A-1/2/4/5.1/5.2/5.7: نموذج الإسناد وقواعد التعارض وشاشة الأدمن وRepScope.
 */
class RepAssignmentTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity, RepAssignmentFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssignments(false);
    }

    public function test_create_duplicate_and_same_scope_for_two_supervisors(): void
    {
        $this->assign($this->rep1, $this->repSupervisor, 'area', ['منطقة الشمال'])->assertSessionHasNoErrors();
        $a = $this->active()->sole();
        $this->assertSame($this->norm('منطقة الشمال'), $a->scope_value);
        $this->assertSame('منطقة الشمال', $a->scope_label);
        $this->assertSame($this->admin->id, $a->created_by);

        // التكرار
        $this->assign($this->rep1, $this->repSupervisor, 'area', ['منطقة الشمال'])->assertSessionHasErrors('scope');
        $this->assertStringContainsString('موجود مسبقاً', session('errors')->first('scope'));

        // النطاق نفسه لمشرف آخر: الرسالة تسمّي المشرف المتعارض
        $this->assign($this->rep1, $this->sup2, 'area', ['منطقة الشمال'])->assertSessionHasErrors('scope');
        $this->assertSame('منطقة منطقة الشمال مُسنَدة سابقاً للمشرف المشرف أحمد لهذا المندوب. انقلها إليه أولاً.', session('errors')->first('scope'));
        // لمندوب آخر مسموح
        $this->assign($this->rep2, $this->sup2, 'area', ['منطقة الشمال'])->assertSessionHasNoErrors();
        $this->assertCount(2, $this->active());

        $log = ActivityLog::where('action', 'rep_assignment.created')->latest('id')->first();
        $this->assertSame('area', $log->new_values['scope_type']);
        $this->assertSame(1, $log->meta['pharmacies']);
    }

    public function test_all_versus_scope_both_ways(): void
    {
        $this->assign($this->rep1, $this->repSupervisor, 'area', ['منطقة الشمال'])->assertSessionHasNoErrors();
        $this->assign($this->rep1, $this->sup2, 'all')->assertSessionHasErrors('scope');
        $this->assertStringContainsString('المشرف أحمد', session('errors')->first('scope'));

        $this->assign($this->rep2, $this->sup2, 'all')->assertSessionHasNoErrors();
        $this->assign($this->rep2, $this->repSupervisor, 'area', ['منطقة الشرق'])->assertSessionHasErrors('scope');
        $this->assertStringContainsString('بكل صيدلياته للمشرف المشرف باسل', session('errors')->first('scope'));
        $this->assertCount(2, $this->active());
    }

    public function test_block_area_overlap_counts_pharmacies(): void
    {
        // «كتلة» تضم الشمال والجنوب والشرق؛ منطقة الشمال تتقاطع معها في صيدلية واحدة
        $this->assign($this->rep1, $this->repSupervisor, 'area', ['منطقة الشمال'])->assertSessionHasNoErrors();
        $this->assign($this->rep1, $this->sup2, 'block', ['كتلة'])->assertSessionHasErrors('scope');
        $message = session('errors')->first('scope');
        $this->assertStringContainsString('في 1 صيدلية', $message);
        $this->assertStringContainsString('المشرف أحمد', $message);

        // منطقة بلا تقاطع مع كتلة أخرى: مقبولة
        Customer::create(['name' => 'صيدلية الغرب', 'area' => 'منطقة الغرب', 'block' => 'كتلة ثانية']);
        $this->assign($this->rep1, $this->sup2, 'block', ['كتلة ثانية'])->assertSessionHasNoErrors();
    }

    public function test_multiple_areas_at_once_all_or_nothing(): void
    {
        $this->assign($this->rep1, $this->repSupervisor, 'area', ['منطقة الشمال', 'منطقة الجنوب'])->assertSessionHasNoErrors();
        $this->assertSame(['منطقة الجنوب', 'منطقة الشمال'], $this->active()->pluck('scope_label')->sort()->values()->all());

        // واحدة متعارضة ⇒ لا يُنشأ شيء
        $this->assign($this->rep1, $this->sup2, 'area', ['منطقة الشرق', 'منطقة الجنوب'])->assertSessionHasErrors('scope');
        $this->assertCount(2, $this->active());
        // قيمة غير موجودة في جدول الزبائن
        $this->assign($this->rep2, $this->sup2, 'area', ['منطقة وهمية'])->assertSessionHasErrors('scope_values');
    }

    public function test_end_and_move_in_one_transaction_with_before_after(): void
    {
        $this->assign($this->rep1, $this->repSupervisor, 'area', ['منطقة الشمال'])->assertSessionHasNoErrors();
        $a = $this->active()->sole();

        $this->actingAs($this->admin)->post(route('admin.rep-assignments.move', $a), ['supervisor_id' => $this->sup2->id])->assertSessionHasErrors('confirm');
        $this->actingAs($this->admin)->post(route('admin.rep-assignments.move', $a), ['supervisor_id' => $this->sup2->id, 'confirm' => 1])->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertNotNull($a->ended_at);
        $this->assertSame($this->admin->id, $a->ended_by);
        $new = $this->active()->sole();
        $this->assertSame($this->sup2->id, $new->supervisor_user_id);
        $this->assertSame($a->scope_value, $new->scope_value);

        $log = ActivityLog::where('action', 'rep_assignment.moved')->sole();
        $this->assertSame($this->repSupervisor->id, $log->old_values['supervisor_user_id']);
        $this->assertSame($this->sup2->id, $log->new_values['supervisor_user_id']);
        $this->assertSame('المشرف أحمد', $log->meta['from_supervisor']);
        $this->assertSame('المشرف باسل', $log->meta['to_supervisor']);
        $this->assertSame(1, $log->meta['pharmacies']);

        // الإنهاء (لا حذف فعلي)
        $this->actingAs($this->admin)->post(route('admin.rep-assignments.end', $new))->assertSessionHasNoErrors();
        $this->assertCount(0, $this->active());
        $this->assertSame(2, RepSupervisorAssignment::count());
        $this->assertSame(1, ActivityLog::where('action', 'rep_assignment.ended')->count());
        $this->actingAs($this->admin)->post(route('admin.rep-assignments.end', $new))->assertSessionHasErrors('assignment');
    }

    public function test_move_rejected_on_conflict_leaves_everything_intact(): void
    {
        $this->assign($this->rep1, $this->repSupervisor, 'area', ['منطقة الشمال'])->assertSessionHasNoErrors();
        $this->assign($this->rep1, $this->sup2, 'all')->assertSessionHasErrors();
        $this->assign($this->rep1, $this->sup2, 'area', ['منطقة الجنوب'])->assertSessionHasNoErrors();
        $sup3 = User::factory()->role(Role::RepSupervisor)->create(['name' => 'المشرف جاد']);
        $this->assign($this->rep1, $sup3, 'block', ['كتلة ثانية'])->assertSessionHasErrors();

        // نقل الجنوب إلى المشرف أحمد: مقبول (المشرف نفسه يملك الشمال)
        $south = $this->active()->firstWhere('supervisor_user_id', $this->sup2->id);
        $this->actingAs($this->admin)->post(route('admin.rep-assignments.move', $south), ['supervisor_id' => $this->repSupervisor->id, 'confirm' => 1])->assertSessionHasNoErrors();
        $this->assertSame([$this->repSupervisor->id], $this->active()->pluck('supervisor_user_id')->unique()->values()->all());
    }

    public function test_rep_scope_shared_rep_unassigned_and_no_area_customers(): void
    {
        $this->assign($this->rep1, $this->repSupervisor, 'area', ['منطقة الشمال'])->assertSessionHasNoErrors();
        $this->assign($this->rep1, $this->sup2, 'area', ['منطقة الجنوب'])->assertSessionHasNoErrors();
        $west = Customer::create(['name' => 'صيدلية الغرب', 'area' => 'منطقة الغرب', 'block' => 'كتلة ثانية']);
        $nowhere = Customer::create(['name' => 'صيدلية بلا مكان']);

        $scope = app(RepScope::class);
        $s1 = $scope->forSupervisor($this->repSupervisor);
        $s2 = $scope->forSupervisor($this->sup2);
        $this->assertSame([$this->north->id], $s1->customerIdsFor($this->rep1->id));
        $this->assertSame([$this->south->id], $s2->customerIdsFor($this->rep1->id));
        $this->assertFalse($s1->allowsRep($this->rep2));
        $this->assertSame([], $s1->customerIdsFor($this->rep2->id));

        // لكل (مندوب، زبون) مشرف واحد على الأكثر
        $this->assertSame($this->repSupervisor->id, $scope->ownerOf($this->rep1->id, $this->north->id));
        $this->assertSame($this->sup2->id, $scope->ownerOf($this->rep1->id, $this->south->id));
        $this->assertNull($scope->ownerOf($this->rep1->id, $west->id));
        $this->assertNull($scope->ownerOf($this->rep1->id, $nowhere->id));
        $this->assertNull($scope->ownerOf($this->rep2->id, $this->east->id));
        $this->assertContains($nowhere->id, $scope->customerIndex()['none']);

        // التنبيهات في شاشة الأدمن
        $html = $this->actingAs($this->admin)->get(route('admin.rep-assignments.index'))->assertOk()->getContent();
        $this->assertStringContainsString('1 مندوباً بلا مشرف', $html);
        $this->assertStringContainsString('1 صيدلية بلا منطقة ولا كتلة (لا يمكن إسنادها)', $html);
        $this->actingAs($this->admin)->get(route('admin.rep-assignments.index', ['view' => 'no-scope']))->assertSee('صيدلية بلا مكان');
        // بحسب المندوب: سامر بلا مشرف وصيدليته غير مسنَدة
        $reps = $this->actingAs($this->admin)->get(route('admin.rep-assignments.index', ['view' => 'reps']))->assertOk()->getContent();
        $this->assertStringContainsString('بلا مشرف', $reps);
        $this->assertStringContainsString('منطقة الشرق: 1 صيدلية', $reps);
    }

    public function test_unassigned_area_alert_for_rep_with_supervisor(): void
    {
        $this->assign($this->rep1, $this->repSupervisor, 'area', ['منطقة الشمال'])->assertSessionHasNoErrors();
        $html = $this->actingAs($this->admin)->get(route('admin.rep-assignments.index'))->getContent();
        // الجنوب من صيدليات كرم ولا مشرف له
        $this->assertStringContainsString('1 صيدلية في مناطق غير مسنَدة لأي مشرف', $html);
    }

    public function test_only_admin_reaches_the_assignment_screen(): void
    {
        $this->actingAs($this->admin)->get(route('admin.rep-assignments.index'))->assertOk()->assertSee('إسناد المندوبين');
        foreach ([$this->repSupervisor, $this->sup2, $this->accountant, $this->supervisor, $this->manager, $this->d1, $this->rep1] as $user) {
            $this->actingAs($user)->get(route('admin.rep-assignments.index'))->assertForbidden();
            $this->actingAs($user)->post(route('admin.rep-assignments.store'), ['rep_id' => $this->rep1->id, 'supervisor_id' => $user->id, 'scope_type' => 'all'])->assertForbidden();
            $this->actingAs($user)->put(route('admin.rep-assignments.scoping'), ['enabled' => 1, 'confirm' => 1])->assertForbidden();
        }
        $this->assertCount(0, $this->active());
        $this->assertFalse(RepScope::enabled());
    }

    public function test_enabling_scoping_requires_confirmation_warns_and_logs(): void
    {
        $this->assign($this->rep1, $this->repSupervisor, 'all')->assertSessionHasNoErrors();
        $html = $this->actingAs($this->admin)->get(route('admin.rep-assignments.index'))->getContent();
        $this->assertStringContainsString('data-scoping="off"', $html);
        $this->assertMatchesRegularExpression('/data-scoping-warning>\s*<strong>1 مندوباً بلا مشرف<\/strong>/u', $html);

        $this->actingAs($this->admin)->put(route('admin.rep-assignments.scoping'), ['enabled' => 1])->assertSessionHasErrors('confirm');
        $this->assertFalse(RepScope::enabled());

        $this->actingAs($this->admin)->put(route('admin.rep-assignments.scoping'), ['enabled' => 1, 'confirm' => 1])
            ->assertSessionHas('success', fn ($m) => str_contains($m, '1 مندوباً بلا مشرف'));
        $this->assertTrue(RepScope::enabled());
        $log = ActivityLog::where('action', 'rep_scoping.enabled')->sole();
        $this->assertSame(1, $log->meta['reps_without_supervisor']);
        $this->assertFalse($log->old_values['rep_supervisor_scoping']);

        $this->actingAs($this->admin)->put(route('admin.rep-assignments.scoping'), ['enabled' => 0])->assertSessionHasNoErrors();
        $this->assertFalse(RepScope::enabled());
        $this->assertSame(1, ActivityLog::where('action', 'rep_scoping.disabled')->count());
        // آخر التغييرات تظهر في الشاشة
        $this->actingAs($this->admin)->get(route('admin.rep-assignments.index'))->assertSee('تفعيل حصر رؤية مشرفي المندوبين')->assertSee('إسناد مندوب لمشرف');
    }

    public function test_assignment_page_has_constant_query_count(): void
    {
        $n = 0;
        $listening = false;
        \DB::listen(function () use (&$n, &$listening) {
            if ($listening) {
                $n++;
            }
        });
        $count = function () use (&$n, &$listening): int {
            // شارات القائمة مخزّنة دقيقة: تُفرَّغ كي يتساوى القياسان
            \Illuminate\Support\Facades\Cache::flush();
            $n = 0;
            $listening = true;
            $this->actingAs($this->admin)->get(route('admin.rep-assignments.index', ['view' => 'reps']))->assertOk();
            $this->actingAs($this->admin)->get(route('admin.rep-assignments.index'))->assertOk();
            $listening = false;

            return $n;
        };
        $this->assign($this->rep1, $this->repSupervisor, 'area', ['منطقة الشمال'])->assertSessionHasNoErrors();
        $small = $count();

        // 300 مندوب و4,000 زبون و300 إسناد
        $now = now();
        foreach (array_chunk(range(1, 4000), 500) as $chunk) {
            \DB::table('customers')->insert(array_map(fn ($i) => [
                'name' => 'زبون '.$i, 'name_normalized' => 'زبون '.$i, 'area' => 'منطقة '.($i % 60), 'area_normalized' => $this->norm('منطقة '.($i % 60)),
                'block' => 'كتلة '.($i % 15), 'balance' => 0, 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }
        $sups = User::factory()->count(10)->role(Role::RepSupervisor)->create();
        $reps = User::factory()->count(297)->role(Role::Rep)->sequence(fn ($s) => ['cost_center_code' => (string) (800000 + $s->index), 'cost_center_label' => (800000 + $s->index).'-مندوب'])->create();
        \DB::table('rep_supervisor_assignments')->insert($reps->values()->map(fn ($r, $i) => [
            'rep_user_id' => $r->id, 'supervisor_user_id' => $sups[$i % 10]->id, 'scope_type' => 'area',
            'scope_value' => $this->norm('منطقة '.($i % 60)), 'scope_label' => 'منطقة '.($i % 60), 'created_at' => $now, 'updated_at' => $now,
        ])->all());

        \DB::flushQueryLog();
        $large = $count();
        $this->assertSame($small, $large, 'عدد الاستعلامات يجب ألا يتغيّر بحجم البيانات (N+1)');
        $this->assertLessThan(40, $large);
    }
}
