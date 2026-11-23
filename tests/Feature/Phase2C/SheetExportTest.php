<?php

namespace Tests\Feature\Phase2C;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\User;
use App\Support\ArabicNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * E/D) تصدير الكشف إلى Excel للطباعة A4.
 */
class SheetExportTest extends TestCase
{
    use RefreshDatabase, Workbooks;

    /** صف ترويسة الجدول في ورقة «الكشف» (العنوان + 5 أسطر معلومات + سطر فارغ) */
    private const HEADER_ROW = 8;

    private User $admin;
    private User $manager;
    private User $supervisor;
    private User $accountant;

    private string $lastPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->manager = User::factory()->role(Role::SheetManager)->create();
        $this->supervisor = User::factory()->role(Role::Supervisor)->create();
        $this->accountant = User::factory()->role(Role::Accountant)->create();
    }

    private function download(TestResponse $response): Spreadsheet
    {
        $response->assertOk();
        $path = $this->lastPath = $response->baseResponse->getFile()->getPathname();

        return IOFactory::load($path);
    }

    /** صفوف الزبائن في ورقة «الكشف»: ما كان عمود «م» فيها رقماً */
    private function customerRows(Worksheet $ws): array
    {
        $rows = [];
        for ($r = self::HEADER_ROW + 1, $max = $ws->getHighestRow(); $r <= $max; $r++) {
            if (is_numeric($ws->getCell("A{$r}")->getValue())) {
                $rows[] = $r;
            }
        }

        return $rows;
    }

    private function allText(Spreadsheet $book): string
    {
        $text = '';
        foreach ($book->getAllSheets() as $ws) {
            foreach ($ws->toArray(null, false, false) as $row) {
                $text .= implode('|', array_map(fn ($v) => (string) $v, $row))."\n";
            }
        }

        return $text;
    }

    public function test_sample_sheet_exports_23_customers_and_42_invoices(): void
    {
        $sheet = $this->publishWorkbook($this->fixturePath());

        $response = $this->actingAs($this->supervisor)->get(route('sheets.export', $sheet));
        $this->assertStringContainsString('filename=sheet_3918.xlsx', $response->headers->get('content-disposition'));
        $this->assertStringContainsStringIgnoringCase("filename*=UTF-8''", $response->headers->get('content-disposition'));
        $book = $this->download($response);

        $this->assertSame(['الكشف', 'الفواتير'], $book->getSheetNames());
        $ws = $book->getSheetByName('الكشف');
        $this->assertStringContainsString('كشف توزيع — أمر التسليم #3918', $ws->getCell('A1')->getValue());

        $rows = $this->customerRows($ws);
        $this->assertCount(23, $rows);
        $sum = array_sum(array_map(fn ($r) => (float) $ws->getCell("E{$r}")->getValue(), $rows));
        $this->assertEqualsWithDelta(105898.04, $sum, 0.001);
        $this->assertSame('#,##0.00', $ws->getStyle("E{$rows[0]}")->getNumberFormat()->getFormatCode());
        $this->assertTrue(is_float($ws->getCell("E{$rows[0]}")->getValue()) || is_int($ws->getCell("E{$rows[0]}")->getValue()));

        // صف الإجمالي في النهاية
        $last = $ws->getHighestRow();
        $this->assertSame('الإجمالي', $ws->getCell("B{$last}")->getValue());
        $this->assertEqualsWithDelta(105898.04, (float) $ws->getCell("E{$last}")->getValue(), 0.001);

        // صفوف عنوان المناطق
        $this->assertStringContainsString('المنطقة: ', $ws->getCell('A'.(self::HEADER_ROW + 1))->getValue());

        $invoices = $book->getSheetByName('الفواتير');
        $this->assertSame(43, $invoices->getHighestRow());   // ترويسة + 42
    }

    public function test_deferred_invoices_raw_balance_and_other_sheets_never_appear(): void
    {
        // G: 12830 مؤجلة، و G: 12831 في كشف أمر آخر (3919)
        $path = $this->modifiedFixture(function (Spreadsheet $s) {
            $s->getSheet(0)->setCellValue('L5', '20-10-2026');
            $s->getSheet(0)->setCellValue('N6', 3919);
        });
        $result = $this->importFiles($path, '2026-10-13', $this->manager);
        $sheet = DistributionSheet::where('delivery_order_no', '3918')->firstOrFail();
        $deferred = Invoice::where('invoice_number', 'ع G: 12830')->first();
        $other = Invoice::where('placement', Invoice::IN_SHEET)->where('distribution_sheet_id', '!=', $sheet->id)->first();
        $this->assertNotNull($other);

        $book = $this->download($this->actingAs($this->manager)->get(route('sheets.export', $sheet)));
        $text = $this->allText($book);

        $this->assertStringNotContainsString('12830', $text);
        $this->assertStringNotContainsString(explode(': ', $other->invoice_number)[1], $text);

        // الرصيد الخام لزبون المؤجلة لا يظهر، والمستحق يظهر
        $customer = Customer::find($deferred->customer_id);
        $ws = $book->getSheetByName('الكشف');
        $dueCells = array_map(fn ($r) => round((float) $ws->getCell("F{$r}")->getValue(), 2), $this->customerRows($ws));
        $this->assertNotContains(round((float) $customer->balance, 2), $dueCells);
        $this->assertContains(round((float) $customer->balance - (float) $deferred->invoice_amount, 2), $dueCells);
        $this->assertTrue($result['reports'][0]['success']);
    }

    public function test_filter_by_distributor_area_and_all_areas_assignment(): void
    {
        $d1 = User::factory()->role(Role::Distributor)->create();
        $d2 = User::factory()->role(Role::Distributor)->create();
        $balances = [['صيدلية الشمال', 'ك', 'منطقة الشمال', 10, 0], ['صيدلية الجنوب', 'ك', 'منطقة الجنوب', 10, 0]];
        $sheet = $this->publishWorkbook($this->buildWorkbook([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية الشمال', 'lines' => [['صنف', 100]]],
            ['number' => 'ع G: 2', 'customer' => 'صيدلية الجنوب', 'lines' => [['صنف', 200]]],
        ], $balances));
        $north = ArabicNormalizer::normalize('منطقة الشمال');
        $south = ArabicNormalizer::normalize('منطقة الجنوب');
        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $sheet), ['rows' => [
            ['area_key' => $north, 'distributor_id' => $d1->id],
            ['area_key' => $south, 'distributor_id' => $d2->id],
        ]])->assertSessionHasNoErrors();

        $byDistributor = $this->actingAs($this->supervisor)->get(route('sheets.export', ['sheet' => $sheet, 'distributor_id' => $d1->id]));
        $this->assertStringContainsString(rawurlencode('ك - 3918.xlsx'), $byDistributor->headers->get('content-disposition'));
        $text = $this->allText($this->download($byDistributor));
        $this->assertStringContainsString('صيدلية الشمال', $text);
        $this->assertStringNotContainsString('صيدلية الجنوب', $text);

        $text = $this->allText($this->download($this->actingAs($this->supervisor)->get(route('sheets.export', ['sheet' => $sheet, 'area_key' => $south]))));
        $this->assertStringContainsString('صيدلية الجنوب', $text);
        $this->assertStringNotContainsString('صيدلية الشمال', $text);

        // موزع غير مسند في الكشف، ومنطقة ليست فيه: مرفوضان
        $stranger = User::factory()->role(Role::Distributor)->create();
        $this->actingAs($this->supervisor)->get(route('sheets.export', ['sheet' => $sheet, 'distributor_id' => $stranger->id]))->assertSessionHasErrors('distributor_id');
        $this->actingAs($this->supervisor)->get(route('sheets.export', ['sheet' => $sheet, 'area_key' => 'غير موجودة']))->assertSessionHasErrors('area_key');

        // من أُسند "كل المناطق" يُصدَّر له الكل
        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $sheet), ['all_distributor_id' => $d1->id])->assertSessionHasNoErrors();
        $text = $this->allText($this->download($this->actingAs($this->supervisor)->get(route('sheets.export', ['sheet' => $sheet, 'distributor_id' => $d1->id]))));
        $this->assertStringContainsString('صيدلية الشمال', $text);
        $this->assertStringContainsString('صيدلية الجنوب', $text);
    }

    public function test_with_lines_adds_items_sheet_matching_invoice_total(): void
    {
        $sheet = $this->publishWorkbook($this->fixturePath());

        $book = $this->download($this->actingAs($this->accountant)->get(route('sheets.export', ['sheet' => $sheet, 'with_lines' => 1])));

        $this->assertSame(['الكشف', 'الفواتير', 'الأصناف'], $book->getSheetNames());
        $lines = $book->getSheetByName('الأصناف');
        $this->assertSame(78, $lines->getHighestRow());   // ترويسة + 77 سطراً
        $sum = 0.0;
        for ($r = 2; $r <= 78; $r++) {
            $sum += (float) $lines->getCell("H{$r}")->getValue();
        }
        $this->assertEqualsWithDelta(105898.04, $sum, 0.001);
    }

    public function test_page_setup_is_a4_landscape_fit_to_width_with_repeated_header_and_rtl(): void
    {
        $sheet = $this->publishWorkbook($this->fixturePath());
        $book = $this->download($this->actingAs($this->supervisor)->get(route('sheets.export', ['sheet' => $sheet, 'with_lines' => 1])));

        foreach ($book->getAllSheets() as $ws) {
            $setup = $ws->getPageSetup();
            $this->assertSame(PageSetup::PAPERSIZE_A4, $setup->getPaperSize(), $ws->getTitle());
            $this->assertSame(PageSetup::ORIENTATION_LANDSCAPE, $setup->getOrientation());
            $this->assertTrue($setup->getFitToPage());
            $this->assertSame(1, (int) $setup->getFitToWidth());
            $this->assertSame(0, (int) $setup->getFitToHeight());
            $this->assertTrue($setup->getHorizontalCentered());
            $this->assertTrue($setup->isPrintAreaSet());
            $this->assertTrue($ws->getRightToLeft());
            $this->assertStringContainsString('&P', $ws->getHeaderFooter()->getOddFooter());
            $this->assertStringContainsString('&N', $ws->getHeaderFooter()->getOddFooter());
            $this->assertStringContainsString('3918', $ws->getHeaderFooter()->getOddHeader());
        }
        // بلا خطوط شبكة: يُفحص XML الملف مباشرة (قارئ PhpSpreadsheet 1.x يعيد تفعيلها من printOptions عند القراءة)
        $zip = new \ZipArchive;
        $zip->open($this->lastPath);
        foreach ([1, 2, 3] as $i) {
            $this->assertStringContainsString('showGridLines="false"', $zip->getFromName("xl/worksheets/sheet{$i}.xml"));
        }
        $zip->close();

        $this->assertEquals([self::HEADER_ROW, self::HEADER_ROW], $book->getSheetByName('الكشف')->getPageSetup()->getRowsToRepeatAtTop());
        $this->assertEquals([1, 1], $book->getSheetByName('الفواتير')->getPageSetup()->getRowsToRepeatAtTop());
        $this->assertSame('Arial', $book->getDefaultStyle()->getFont()->getName());
        $this->assertTrue($book->getSheetByName('الكشف')->getStyle('D'.(self::HEADER_ROW + 2))->getAlignment()->getWrapText());
    }

    public function test_formula_like_text_is_written_as_plain_text(): void
    {
        $sheet = $this->publishWorkbook($this->buildWorkbook([['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]]]));
        Invoice::first()->update(['customer_name' => '=HYPERLINK("http://evil","x")', 'invoice_note' => '+1+2']);

        $book = $this->download($this->actingAs($this->supervisor)->get(route('sheets.export', $sheet)));

        $ws = $book->getSheetByName('الكشف');
        $row = $this->customerRows($ws)[0];
        $cell = $ws->getCell("B{$row}");
        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        $this->assertSame('=HYPERLINK("http://evil","x")', $cell->getValue());
        $this->assertTrue($ws->getStyle("B{$row}")->getQuotePrefix());

        $note = $book->getSheetByName('الفواتير')->getCell('H2');
        $this->assertSame(DataType::TYPE_STRING, $note->getDataType());
        $this->assertSame('+1+2', $note->getValue());
    }

    public function test_permissions_and_activity_log(): void
    {
        $published = $this->publishWorkbook($this->buildWorkbook([['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]]]));
        $result = $this->importFiles($this->buildWorkbook([['number' => 'ع G: 2', 'customer' => 'صيدلية باء', 'order' => 7000, 'lines' => [['صنف', 50]]]]), '2026-10-13', $this->manager);
        $preparing = DistributionSheet::where('batch_id', $result['batch_id'])->firstOrFail();
        $this->assertSame('preparing', $preparing->status);

        $distributor = User::factory()->role(Role::Distributor)->create();
        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $published), ['all_distributor_id' => $distributor->id]);
        $this->actingAs($distributor)->get(route('sheets.export', $published))->assertForbidden();

        $this->actingAs($this->supervisor)->get(route('sheets.export', $preparing))->assertNotFound();
        $this->actingAs($this->accountant)->get(route('sheets.export', $preparing))->assertNotFound();
        $this->actingAs($this->manager)->get(route('sheets.export', $preparing))->assertOk();
        $this->actingAs($this->admin)->get(route('sheets.export', $preparing))->assertOk();

        // كشوف v1 لا تستخدم المسار الجديد
        $v1 = DistributionSheet::create(['distributor_id' => $distributor->id, 'supervisor_id' => $this->supervisor->id, 'file_name' => 'old.xlsx', 'status' => 'pending']);
        $this->actingAs($this->supervisor)->get(route('sheets.export', $v1))->assertNotFound();

        $this->actingAs($this->accountant)->get(route('sheets.export', ['sheet' => $published, 'with_lines' => 1]))->assertOk();
        $this->assertDatabaseHas('activity_logs', ['action' => 'sheet.exported', 'subject_id' => $published->id, 'user_id' => $this->accountant->id]);
        $log = \App\Models\ActivityLog::where('action', 'sheet.exported')->where('user_id', $this->accountant->id)->first();
        $this->assertSame('كل الكشف', $log->new_values['scope']);
        $this->assertTrue($log->new_values['with_lines']);

        // الزر يظهر في صفحة الكشف وقوائم المشرف ومسؤول الكشوف ومعاينة الدفعة
        $this->actingAs($this->supervisor)->get(route('supervisor.sheet.show', $published->id))->assertSee(route('sheets.export', $published));
        $this->actingAs($this->supervisor)->get(route('supervisor.index'))->assertSee(route('sheets.export', $published));
        $this->actingAs($this->accountant)->get(route('accountant.sheet.show', $published->id))->assertSee(route('sheets.export', $published));
        $this->actingAs($this->manager)->get(route('sheet-manager.sheets.show', $preparing))->assertSee(route('sheets.export', $preparing));
        $this->actingAs($this->manager)->get(route('sheet-manager.sheets.index'))->assertSee(route('sheets.export', $preparing));
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', $result['batch']))->assertSee(route('sheets.export', $preparing));
    }
}
