<?php

namespace Tests\Feature\Phase8B;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\RepPlanTemplate;
use App\Models\RepPlanVisit;
use App\Models\RepSupervisorAssignment;
use App\Models\User;
use App\Services\Reps\RepCoverageService;
use App\Services\Reps\RepPlanService;
use App\Services\Reps\RepScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * المرحلة 8B: تسجيل الزيارة ومطابقة الموقع، التصحيح، التغطية، التصدير، والأداء.
 */
class RepPlanVisitTest extends TestCase
{
    use PlanFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlan();
        // الأحد: نهر عيشة (المشرف أحمد) والمزة (المشرف باسل)
        $this->addTemplate($this->sup1, 1, 'area', 'نهر عيشة')->assertSessionHasNoErrors();
        $this->addTemplate($this->sup2, 1, 'area', 'المزة')->assertSessionHasNoErrors();
    }

    private function coverage(User $viewer, array $filters = []): array
    {
        return (new RepCoverageService(new RepPlanService(app(RepScope::class))))->build($viewer, $filters + ['from' => self::TODAY, 'to' => self::TODAY]);
    }

    public function test_matched_within_limit(): void
    {
        $this->visit($this->north[1], $this->near($this->north[1], 40))->assertCreated()
            ->assertJsonPath('visit.location_status', 'matched');
        $v = RepPlanVisit::sole();
        $this->assertSame(40, $v->distance_m);
        $this->assertSame($this->sup1->id, (int) $v->supervisor_user_id);
        $this->assertEqualsWithDelta(self::LAT + 0.01, $v->pharmacy_lat, 0.0000001);
        // موقع الصيدلية لم يتغيّر
        $this->assertEqualsWithDelta(self::LAT + 0.01, (float) $this->north[1]->fresh()->latitude, 0.0000001);
        $this->assertTrue(ActivityLog::where('action', 'rep_plan.visit_recorded')->exists());
    }

    public function test_mismatch_needs_confirmation_cancel_saves_nothing(): void
    {
        $uuid = (string) Str::uuid();
        $res = $this->visit($this->north[1], ['client_uuid' => $uuid] + $this->near($this->north[1], 340))->assertStatus(409);
        $res->assertJsonPath('confirm', 'mismatch')->assertJsonPath('distance_m', 340);
        $this->assertStringContainsString('موقعك يبعد 340 م عن الصيدلية. هل أنت في الصيدلية؟', $res->json('message'));
        $this->assertSame(0, RepPlanVisit::count());   // «إلغاء» = لا شيء محفوظ

        $this->visit($this->north[1], ['client_uuid' => $uuid, 'confirm' => 'mismatch'] + $this->near($this->north[1], 340))->assertCreated()
            ->assertJsonPath('visit.location_status', 'mismatch');
        $this->assertSame(340, RepPlanVisit::sole()->distance_m);
    }

    public function test_low_accuracy_needs_confirmation_or_retry(): void
    {
        $this->visit($this->north[1], $this->near($this->north[1], 20, 250))->assertStatus(409)->assertJsonPath('confirm', 'low_accuracy');
        $this->assertSame(0, RepPlanVisit::count());
        // «أعد المحاولة» بقراءة أدق ⇒ مطابقة
        $this->visit($this->north[1], $this->near($this->north[1], 20, 15))->assertCreated()->assertJsonPath('visit.location_status', 'matched');
        // أو الحفظ بالوسم
        $this->visit($this->north[2], ['confirm' => 'low_accuracy'] + $this->near($this->north[2], 20, 250))->assertCreated()
            ->assertJsonPath('visit.location_status', 'low_accuracy');
    }

    public function test_no_pharmacy_location_and_no_gps(): void
    {
        $this->visit($this->north[10], $this->near($this->north[1], 0))->assertCreated()
            ->assertJsonPath('visit.location_status', 'no_pharmacy_location')
            ->assertJsonPath('visit.text', 'تمت الزيارة — لا يوجد موقع محفوظ لهذه الصيدلية، تعذّرت المطابقة');
        $this->assertNull($this->north[10]->fresh()->latitude);   // لا مرجع تلقائي

        $this->visit($this->north[2])->assertStatus(409)->assertJsonPath('confirm', 'no_gps')
            ->assertJsonPath('message', 'تعذّر تحديد موقعك. سيُسجَّل أنه لم يُتحقق من الموقع.');
        $this->visit($this->north[2], ['confirm' => 'no_gps'])->assertCreated()->assertJsonPath('visit.location_status', 'no_gps');
    }

    public function test_not_visited_reason_rules(): void
    {
        $this->visit($this->north[1], ['status' => 'not_visited'])->assertUnprocessable()->assertJsonValidationErrors('reason_code');
        $this->visit($this->north[1], ['status' => 'not_visited', 'reason_code' => 'other'])->assertUnprocessable()->assertJsonValidationErrors('reason_text');
        $this->visit($this->north[1], ['status' => 'not_visited', 'reason_code' => 'other', 'reason_text' => 'طريق مغلق'])->assertCreated()
            ->assertJsonPath('visit.text', 'لم تتم: طريق مغلق');
        $this->assertNull(RepPlanVisit::sole()->lat);
    }

    public function test_uniqueness_uuid_future_and_out_of_plan(): void
    {
        $uuid = (string) Str::uuid();
        $this->visit($this->north[1], ['client_uuid' => $uuid] + $this->near($this->north[1], 10))->assertCreated();
        // إعادة الإرسال بنفس المعرّف: لا تكرار
        $this->visit($this->north[1], ['client_uuid' => $uuid] + $this->near($this->north[1], 10))->assertOk()->assertJsonPath('result', 'duplicate');
        // معرّف جديد للصيدلية نفسها: مسجَّلة مسبقاً (409) بلا تصحيح صريح
        $this->visit($this->north[1], $this->near($this->north[1], 10))->assertStatus(409)->assertJsonPath('result', 'exists');
        $this->assertSame(1, RepPlanVisit::count());

        $this->visit($this->north[2], ['plan_date' => '2026-10-26'] + $this->near($this->north[2], 10))->assertUnprocessable()
            ->assertJsonValidationErrors(['plan_date' => 'لا تُسجَّل زيارة بتاريخ مستقبلي.']);
        $outside = Customer::create(['name' => 'صيدلية خارج الخطة', 'area' => 'الميدان']);
        $this->visit($outside)->assertUnprocessable()->assertJsonValidationErrors(['customer_id' => 'هذه الصيدلية ليست في خطتك لهذا اليوم.']);
        // مندوب آخر لا يسجّل على خطة كرم
        $this->visit($this->north[3], $this->near($this->north[3], 10), $this->rep2)->assertUnprocessable();
        // يوم ماضٍ (الإعداد 0 = اليوم فقط)
        $this->visit($this->north[3], ['plan_date' => '2026-10-18'])->assertUnprocessable()->assertJsonValidationErrors('plan_date');
    }

    public function test_rep_correction_same_day_then_locked_supervisor_corrects_with_reason(): void
    {
        $this->visit($this->north[1], ['status' => 'not_visited', 'reason_code' => 'closed'])->assertCreated();
        $this->visit($this->north[1], ['correction' => true] + $this->near($this->north[1], 10))->assertOk()->assertJsonPath('result', 'corrected');
        $v = RepPlanVisit::sole();
        $this->assertSame('visited', $v->status);
        $this->assertNotNull($v->corrected_at);
        $log = ActivityLog::where('action', 'rep_plan.visit_corrected')->sole();
        $this->assertSame('not_visited', $log->old_values['status']);
        $this->assertSame('visited', $log->new_values['status']);

        // اليوم التالي: مقفل على المندوب (حتى مع backfill لا تصحيح لليوم الماضي)
        $this->travelTo(\Carbon\Carbon::parse('2026-10-26 09:00', 'Asia/Damascus'));
        \App\Models\Setting::set('rep_plan_backfill_days', 2);
        $this->visit($this->north[1], ['correction' => true, 'status' => 'not_visited', 'reason_code' => 'closed'])->assertUnprocessable()
            ->assertJsonValidationErrors('plan_date');
        // المشرف الآخر لا يصحّح زيارة ليست لنطاقه، وصاحب النطاق يصحّح بسبب
        $this->actingAs($this->sup2)->post(route('rep-supervisor.plan.visits.correct', $v), ['status' => 'not_visited', 'reason_code' => 'closed', 'note' => 'خطأ'])->assertForbidden();
        $this->actingAs($this->sup1)->post(route('rep-supervisor.plan.visits.correct', $v), ['status' => 'not_visited', 'reason_code' => 'closed'])->assertSessionHasErrors('note');
        $this->actingAs($this->sup1)->post(route('rep-supervisor.plan.visits.correct', $v), ['status' => 'not_visited', 'reason_code' => 'closed', 'note' => 'المندوب أبلغ خطأً'])
            ->assertSessionHasNoErrors();
        $this->assertSame('not_visited', $v->fresh()->status);
        $this->assertSame('المندوب أبلغ خطأً', ActivityLog::where('action', 'rep_plan.visit_corrected')->latest('id')->first()->meta['note']);
    }

    /**
     * 10 مخطَّطة: 6 مزارة (4 مطابقة، 1 بعيدة، 1 بلا GPS)، 2 لم تتم، 2 متبقية ⇒ 60% تغطية، 66.7% مطابقة.
     */
    public function test_coverage_numbers_and_counters(): void
    {
        foreach ([1, 2, 3, 4] as $i) {
            $this->visit($this->north[$i], $this->near($this->north[$i], 30))->assertCreated();
        }
        $this->visit($this->north[5], ['confirm' => 'mismatch'] + $this->near($this->north[5], 500))->assertCreated();
        $this->visit($this->north[6], ['confirm' => 'no_gps'])->assertCreated();
        $this->visit($this->north[7], ['status' => 'not_visited', 'reason_code' => 'closed'])->assertCreated();
        $this->visit($this->north[8], ['status' => 'not_visited', 'reason_code' => 'owner_absent'])->assertCreated();

        $data = $this->coverage($this->sup1);
        $card = collect($data['cards'])->sole();
        $this->assertSame(['planned' => 10, 'visited' => 6, 'not_visited' => 2, 'remaining' => 2],
            array_intersect_key($card, array_flip(['planned', 'visited', 'not_visited', 'remaining'])));
        $this->assertSame(60.0, RepCoverageService::pct($card['visited'], $card['planned']));
        $this->assertSame(66.7, RepCoverageService::pct($card['location']['matched'], $card['visited']));
        $this->assertSame(['matched' => 4, 'mismatch' => 1, 'no_gps' => 1, 'low_accuracy' => 0, 'no_pharmacy_location' => 0], $card['location']);
        $this->assertSame(['closed' => 1, 'owner_absent' => 1], $card['reasons']);

        $page = $this->actingAs($this->sup1)->get(route('rep-supervisor.coverage', ['period' => 'today']))->assertOk();
        $page->assertSee('نسبة التغطية: <span data-k="coverage">60</span>%', false)->assertSee('66.7')
            ->assertSee('بعيدة عن الموقع: 1')->assertSee('بلا تحديد موقع: 1')->assertSee('دقة موقع ضعيفة: 0')->assertSee('الصيدلية بلا موقع محفوظ: 0')
            ->assertSee('صيدلية شمال 10');   // بلا موقع في الخطة
        // المشرف 2 لا يرى زيارات نهر عيشة ولا نسبها
        $other = $this->coverage($this->sup2);
        $this->assertSame(['المزة'], collect($other['cards'])->pluck('scope')->map(fn ($s) => trim(str_replace('منطقة', '', $s)))->all());
        $this->assertSame(0, $other['totals']['visited']);
        $this->actingAs($this->sup2)->get(route('rep-supervisor.coverage', ['period' => 'today']))->assertOk()->assertDontSee('نهر عيشة');
        // الأدمن يرى الكل (13 مخطَّطة)
        $this->assertSame(13, $this->coverage($this->admin)['totals']['planned']);
        $this->assertSame(10, $this->coverage($this->admin, ['supervisor_id' => $this->sup1->id])['totals']['planned']);

        // تفاصيل اليوم: الحالة والمسافة ورابط الخريطة
        $this->actingAs($this->sup1)->get(route('rep-supervisor.coverage.day', [$this->rep, self::TODAY]))->assertOk()
            ->assertSee('بعيدة عن الصيدلية 500 م')->assertSee('google.com/maps/dir', false)->assertSee('متبقية (لم يُسجَّل لها شيء)');
    }

    public function test_alerts_no_activity_and_mismatch(): void
    {
        $this->travelTo(\Carbon\Carbon::parse(self::TODAY.' 14:00', 'Asia/Damascus'));
        $alerts = collect($this->coverage($this->sup1)['alerts'])->pluck('text')->implode(' | ');
        $this->assertStringContainsString('لم يسجّل شيئاً بعد الساعة 13:00', $alerts);

        $this->visit($this->north[1], ['confirm' => 'mismatch'] + $this->near($this->north[1], 600))->assertCreated();
        $this->visit($this->north[2], $this->near($this->north[2], 10))->assertCreated();
        $alerts = collect($this->coverage($this->sup1)['alerts'])->pluck('text')->implode(' | ');
        $this->assertStringNotContainsString('لم يسجّل شيئاً', $alerts);
        $this->assertStringContainsString('1 من 2 زيارة اليوم بعيدة', $alerts);
        $this->assertStringContainsString('صيدلية في الخطة بلا موقع محفوظ', $alerts);
    }

    public function test_export_is_scoped_and_has_all_fields(): void
    {
        $this->visit($this->north[1], ['confirm' => 'mismatch'] + $this->near($this->north[1], 400))->assertCreated();
        $this->visit($this->south[1], $this->near($this->south[1], 10))->assertCreated();

        $response = $this->actingAs($this->sup1)->get(route('rep-supervisor.coverage.export', ['period' => 'today']))->assertOk();
        $book = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $summary = $book->getSheetByName('الملخص');
        $this->assertStringContainsString('مقيَّد بنطاق المشرف المشرف أحمد', (string) $summary->getCell('A1')->getValue());
        $detail = $book->getSheetByName('التفصيل')->toArray();
        $this->assertSame(['التاريخ', 'اليوم', 'المندوب', 'المشرف', 'النطاق', 'الصيدلية', 'المنطقة', 'الحالة', 'سبب عدم الزيارة', 'وقت الزيارة',
            'حالة الموقع', 'المسافة (م)', 'دقة GPS (م)', 'خط عرض الزيارة', 'خط طول الزيارة', 'خط عرض الصيدلية', 'خط طول الصيدلية', 'ملاحظة الموقع', 'صُحّحت'], $detail[1]);
        $rows = array_slice($detail, 2);
        $this->assertCount(10, $rows);   // صيدليات المشرف 1 فقط
        $names = array_column($rows, 5);
        $this->assertContains('صيدلية شمال 1', $names);
        $this->assertNotContains('صيدلية جنوب 1', $names);
        $row = $rows[array_search('صيدلية شمال 1', $names, true)];
        $this->assertSame('بعيدة عن الموقع', $row[10]);
        $this->assertSame(400, (int) $row[11]);
    }

    public function test_coverage_query_count_does_not_grow_with_reps(): void
    {
        $measure = function (int $reps): int {
            $sup = User::factory()->role(Role::RepSupervisor)->create();
            for ($r = 0; $r < $reps; $r++) {
                $rep = User::factory()->role(Role::Rep)->create();
                $label = 'منطقة قياس '.$reps.'-'.$r;
                for ($i = 0; $i < 5; $i++) {
                    Customer::create(['name' => "ص {$label} {$i}", 'area' => $label, 'latitude' => self::LAT, 'longitude' => self::LNG]);
                }
                RepSupervisorAssignment::create(['rep_user_id' => $rep->id, 'supervisor_user_id' => $sup->id, 'scope_type' => 'area',
                    'scope_value' => $this->value($label), 'scope_label' => $label]);
                foreach ([0, 1, 2, 3, 4, 5, 6] as $wd) {
                    RepPlanTemplate::create(['rep_user_id' => $rep->id, 'supervisor_user_id' => $sup->id, 'weekday' => $wd, 'scope_type' => 'area',
                        'scope_value' => $this->value($label), 'scope_label' => $label, 'starts_on' => '2026-09-01']);
                }
            }
            $count = 0;
            DB::listen(function () use (&$count) {
                $count++;
            });
            $data = (new RepCoverageService(new RepPlanService(app(RepScope::class))))->build($sup, ['from' => '2026-10-01', 'to' => '2026-10-31']);
            $this->assertSame($reps * 5 * 31, $data['totals']['planned']);

            return $count;
        };
        // كل قياس بمستمع جديد يعدّ من لحظة تسجيله (استعلامات build وحدها)
        $five = $measure(5);
        $twenty = $measure(20);
        if (getenv('PHASE7F_PRINT')) {
            fwrite(STDERR, "8B coverage queries: 5 reps x 31 days => {$five}, 20 reps x 31 days => {$twenty}\n");
        }
        $this->assertLessThanOrEqual(15, $five);
        $this->assertSame($five, $twenty);
    }
}
