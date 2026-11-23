<?php

namespace Tests\Feature\Phase5;

use App\Models\Payment;
use App\Models\SheetVisit;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 5-H: «المدفوع اليوم» في الفاتورة المجمّعة، فلتر المراجع المثبّتة حديثاً، والكشوف المفتوحة قديماً.
 */
class DeferredItemsTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity, FinanceFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_paid_today_syp_in_merged_block(): void
    {
        $this->payApi($this->d1, $this->sheet, $this->north->id, 1500)->assertCreated();
        $voided = $this->pay($this->north, 200);
        $voided->update(['voided_at' => now(), 'void_reason' => 'خطأ']);
        $this->pay($this->north, 10, 'USD');   // بلا سعر: لا قيمة بالليرة

        // كشف آخر في اليوم نفسه يُحسب، وكشف بتاريخ آخر لا يُحسب
        // (نسخ مباشرة: رفع ملف ثانٍ لليوم نفسه يمنعه فحص تعارض الزبائن)
        $sameDay = tap($this->sheet->replicate()->fill(['delivery_order_no' => '3919']))->save();
        $otherDay = tap($this->sheet->replicate()->fill(['delivery_order_no' => '3920', 'distribution_date' => '2026-10-12']))->save();
        $this->pay($this->north, 50, 'SYP', null, $this->d1, $sameDay);
        $this->pay($this->north, 999, 'SYP', null, $this->d1, $otherDay);

        $response = $this->actingAs($this->d1)->getJson(route('distributor.invoice-lines', $this->sheet))->assertOk();
        $merged = collect($response->json('merged'))->firstWhere('customer_id', $this->north->id);

        $this->assertEquals(1550, $merged['paid_today_syp']);
        $this->assertCount(3, $merged['paid_today_uuids']);   // 1500 + دولار بلا سعر + كشف اليوم نفسه
        $json = $response->getContent();
        $this->assertStringNotContainsString('98765', $json);
        $this->assertStringNotContainsString('ع G: 303', $json);

        // ETag يتغير بدفعة جديدة
        $etag = $response->headers->get('ETag');
        $this->pay($this->north, 5);
        $this->assertNotSame($etag, $this->actingAs($this->d1)->getJson(route('distributor.invoice-lines', $this->sheet))->headers->get('ETag'));
    }

    public function test_recent_distributor_pins_filter(): void
    {
        $this->north->update(['latitude' => 33.5, 'longitude' => 36.3, 'location_confirmed' => true, 'location_source' => 'distributor', 'location_pinned_by' => $this->d1->id, 'location_pinned_at' => now()->subDays(2), 'location_accuracy_m' => 12]);
        $this->south->update(['latitude' => 33.6, 'longitude' => 36.4, 'location_confirmed' => true, 'location_source' => 'distributor', 'location_pinned_by' => $this->d2->id, 'location_pinned_at' => now()->subDays(10)]);
        $this->unvisited->update(['latitude' => 33.7, 'longitude' => 36.5, 'location_confirmed' => true, 'location_source' => 'supervisor', 'location_pinned_at' => now()->subDay()]);
        $visit = SheetVisit::create([
            'client_uuid' => (string) Str::uuid(), 'distribution_sheet_id' => $this->sheet->id, 'customer_id' => $this->north->id, 'user_id' => $this->d1->id,
            'status' => 'visited', 'visited_at' => now(), 'latitude' => 33.501, 'longitude' => 36.301, 'location_flag' => 'ok',
        ]);

        $html = $this->actingAs($this->supervisor)->get(route('supervisor.far-visits', ['recent_pins' => 1]))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'class="recent-pin-row"'));
        $this->assertStringContainsString('صيدلية الشمال', $html);
        $this->assertStringContainsString('إعادة تثبيت', $html);
        $this->assertStringContainsString('name="id" value="'.$visit->id.'"', $html);

        $html = $this->actingAs($this->supervisor)->get(route('supervisor.far-visits', ['recent_pins' => 1, 'days' => 30]))->getContent();
        $this->assertSame(2, substr_count($html, 'class="recent-pin-row"'));

        // إعادة التثبيت بالزر الموجود تُسجَّل بالحدث المعتاد
        $this->actingAs($this->supervisor)->post(route('supervisor.locations.pin'), ['type' => 'visit', 'id' => $visit->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('activity_logs', ['action' => 'customer.location_pinned', 'subject_id' => $this->north->id]);
    }

    public function test_stale_open_sheets_are_listed_for_supervisor_accountant_and_admin(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 10:00', 'Asia/Damascus'));
        $this->pay($this->north, 100);
        $this->pay($this->south, 100, 'SYP', null, $this->d2);
        $this->assertContains($this->sheet->fresh()->status, ['pending', 'in_progress']);

        foreach ([[$this->supervisor, 'supervisor.index'], [$this->accountant, 'accountant.index'], [$this->admin, 'supervisor.index']] as [$user, $route]) {
            $html = $this->actingAs($user)->get(route($route))->assertOk()->getContent();
            $this->assertStringContainsString('كشوف مفتوحة منذ أكثر من يوم', $html);
            $this->assertMatchesRegularExpression('/أمر التسليم #3918.*?متأخر 2 يوم.*?2 دفعة/su', $html);
        }

        // كشف اليوم نفسه أو المغلق لا يظهر
        Carbon::setTestNow(Carbon::parse('2026-10-13 18:00', 'Asia/Damascus'));
        $this->actingAs($this->supervisor)->get(route('supervisor.index'))->assertDontSee('كشوف مفتوحة منذ أكثر من يوم');
        Carbon::setTestNow(Carbon::parse('2026-10-15 10:00', 'Asia/Damascus'));
        $this->sheet->update(['status' => 'completed']);
        $this->actingAs($this->supervisor)->get(route('supervisor.index'))->assertDontSee('كشوف مفتوحة منذ أكثر من يوم');
        $this->assertSame(2, Payment::count());
    }
}
