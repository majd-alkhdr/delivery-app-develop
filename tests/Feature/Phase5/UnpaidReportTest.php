<?php

namespace Tests\Feature\Phase5;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\SheetVisit;
use App\Services\Finance\PaymentAllocationEngine;
use App\Support\ArabicNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 5-G: تقرير الطلبيات غير المسددة (أرقام حيّة من المحرك، فلاتر، تصدير Excel).
 */
class UnpaidReportTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity, FinanceFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
        $this->pay($this->north, 1500);
        SheetVisit::create([
            'client_uuid' => (string) Str::uuid(), 'distribution_sheet_id' => $this->sheet->id, 'customer_id' => $this->south->id,
            'user_id' => $this->d2->id, 'status' => 'not_visited', 'reason' => 'الصيدلية مغلقة', 'visited_at' => now(), 'location_flag' => 'no_gps',
        ]);
    }

    private function report(array $params = []): array
    {
        $html = $this->actingAs($this->accountant)->get(route('finance.unpaid', ['from' => '2026-10-13'] + $params))->assertOk()->getContent();
        preg_match_all('/<tr class="unpaid-row">(.*?)<\/tr>/s', $html, $m);

        return array_map(fn ($row) => trim(preg_replace('/\s+/u', ' ', strip_tags($row))), $m[1]);
    }

    public function test_rows_match_engine_and_exclude_deferred_zero_and_paid(): void
    {
        $rows = $this->report();
        $this->assertCount(4, $rows);
        $all = implode("\n", $rows);

        // الحليب مسدد كاملاً فلا يظهر؛ ارجيفيت جزئي 100؛ G:301 غير مسدد 400؛ الجنوب 2222؛ بلا زيارة 777
        $this->assertStringNotContainsString('M: 101', $all);
        $this->assertStringNotContainsString('G: 302', $all);   // صفرية
        $this->assertStringNotContainsString('G: 303', $all);   // مؤجلة
        $this->assertStringContainsString('ارجيفيت: 201', $all);
        $this->assertMatchesRegularExpression('/ارجيفيت: 201 201201-كول سنتر 600\.00 500\.00 100\.00 مسددة جزئياً/u', $all);
        $this->assertMatchesRegularExpression('/G: 301 201201-كول سنتر 400\.00 0\.00 400\.00 غير مسددة/u', $all);
        $this->assertMatchesRegularExpression('/صيدلية بلا زيارة منطقة الجنوب B: 501 700100-الجنوب 777\.00 0\.00 777\.00 غير مسددة لم تتم الزيارة الموزع الثاني/u', $all);
        $this->assertStringContainsString('لم يتم التسليم: الصيدلية مغلقة', $all);

        $html = $this->actingAs($this->accountant)->get(route('finance.unpaid', ['from' => '2026-10-13']))->getContent();
        $this->assertStringContainsString('أرقام حيّة', $html);
        $this->assertMatchesRegularExpression('/id="t-count">4</', $html);
        $this->assertStringContainsString('3,499.00', $html);   // 100 + 400 + 2222 + 777
        $this->assertStringNotContainsString('98,765', $html);   // لا رصيد خام
        $this->assertStringNotContainsString('2,000.00', $html);   // لا مؤجل

        // موافق للمحرك
        $engine = app(PaymentAllocationEngine::class)->allocate($this->sheet, $this->north->id);
        $this->assertSame(50000, $engine->totalUnpaidCents());   // 100 + 400
    }

    public function test_filters_cost_center_area_distributor_status_order_and_search(): void
    {
        $this->assertCount(2, $this->report(['cost_center' => '700100-الجنوب']));
        $this->assertCount(2, $this->report(['cost_center' => '201201-كول سنتر']));
        $this->assertCount(2, $this->report(['area_key' => ArabicNormalizer::normalize('منطقة الجنوب')]));
        $this->assertCount(2, $this->report(['distributor_id' => $this->d1->id]));
        $this->assertCount(2, $this->report(['distributor_id' => $this->d2->id]));
        $partial = $this->report(['status' => 'partial']);
        $this->assertCount(1, $partial);
        $this->assertStringContainsString('ارجيفيت: 201', $partial[0]);
        $this->assertCount(3, $this->report(['status' => 'unpaid']));
        $this->assertCount(4, $this->report(['order' => '3918']));
        $this->assertCount(0, $this->report(['order' => '1']));
        $this->assertCount(1, $this->report(['q' => 'بلا زياره']));   // بحث مطبَّع (ة/ه)

        // الإجماليات المجمّعة
        $html = $this->actingAs($this->accountant)->get(route('finance.unpaid', ['from' => '2026-10-13', 'group' => 'area']))->getContent();
        $this->assertMatchesRegularExpression('/منطقة الجنوب<\/td><td>2<\/td>.*?2,999\.00/su', $html);
    }

    public function test_customer_who_never_paid_or_was_visited_is_fully_unpaid_and_cancelled_is_excluded(): void
    {
        Invoice::where('invoice_number', 'ع G: 401')->update(['placement' => Invoice::CANCELLED]);
        $rows = $this->report();
        $this->assertCount(3, $rows);
        $this->assertStringNotContainsString('G: 401', implode("\n", $rows));
        $this->assertStringContainsString('777.00', implode("\n", $rows));
    }

    public function test_exported_snapshot_counts_as_paid_in_live_numbers(): void
    {
        $this->exportAs($this->accountant)->assertSessionHasNoErrors();
        $this->pay($this->north, 300);   // pending بعد التصدير: 100 ارجيفيت + 200 على G:301

        $rows = $this->report(['q' => 'الشمال']);
        $this->assertCount(1, $rows);
        $this->assertMatchesRegularExpression('/G: 301 201201-كول سنتر 400\.00 200\.00 200\.00 مسددة جزئياً/u', $rows[0]);
    }

    public function test_all_three_roles_can_view_and_export_excel(): void
    {
        foreach ([$this->accountant, $this->admin, $this->repSupervisor] as $user) {
            $this->actingAs($user)->get(route('finance.unpaid', ['from' => '2026-10-13']))->assertOk();
        }

        $response = $this->actingAs($this->repSupervisor)->get(route('finance.unpaid.export', ['from' => '2026-10-13', 'area_key' => ArabicNormalizer::normalize('منطقة الجنوب')]));
        $book = $this->book($response);
        $ws = $book->getSheet(0);
        $this->assertTrue($ws->getRightToLeft());
        $this->assertSame(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE, $ws->getPageSetup()->getOrientation());
        $this->assertEquals([3, 3], $ws->getPageSetup()->getRowsToRepeatAtTop());
        $data = array_slice($ws->toArray(null, false, false, false), 3);
        $this->assertCount(2, $data);
        $this->assertSame('غير المسدد - 2026-10-13.xlsx', $this->fileName($response));
        $this->assertStringContainsString('filename=unpaid_2026-10-13.xlsx', $response->headers->get('content-disposition'));
        $this->assertStringNotContainsString('98765', json_encode($ws->toArray(), JSON_UNESCAPED_UNICODE));
        $this->assertDatabaseHas('activity_logs', ['action' => 'finance.unpaid_exported', 'user_id' => $this->repSupervisor->id]);
    }

    public function test_formula_injection_in_unpaid_excel(): void
    {
        $this->unvisited->update(['name' => '+cmd|calc']);
        $ws = $this->book($this->actingAs($this->accountant)->get(route('finance.unpaid.export', ['from' => '2026-10-13', 'q' => 'cmd'])))->getSheet(0);
        $this->assertSame('+cmd|calc', $ws->getCell('C4')->getValue());
        $this->assertTrue($ws->getStyle('C4')->getQuotePrefix());
    }

    public function test_sidebar_item_for_finance_roles(): void
    {
        foreach ([$this->accountant, $this->admin, $this->repSupervisor] as $user) {
            $this->actingAs($user)->get(route($user->role->homeRoute()))->assertSee('data-menu-item="finance.unpaid"', false);
        }
        $this->actingAs($this->manager)->get(route('sheet-manager.index'))->assertDontSee('data-menu-item="finance.unpaid"', false);
        $this->assertSame(0, ActivityLog::where('action', 'finance.unpaid_exported')->count());
    }
}
