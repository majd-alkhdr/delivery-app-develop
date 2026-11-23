<?php

namespace Tests\Feature\Phase2;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\BalanceImport;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Services\Import\ImportException;
use App\Services\Import\SheetImportService;
use App\Services\Import\WorkbookImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Support\Workbooks;
use Tests\TestCase;

class WorkbookImportTest extends TestCase
{
    use RefreshDatabase, Workbooks;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->supervisor = User::factory()->role(Role::Supervisor)->create();
    }

    private function import(string $path, string $date = '2026-10-13'): array
    {
        $result = $this->importFiles($path, $date, $this->supervisor);

        return $result['reports'][0] + ['batch_id' => $result['batch_id'], 'batch' => $result['batch']];
    }

    private function batchSheet(array $report): DistributionSheet
    {
        return DistributionSheet::where('batch_id', $report['batch_id'])->orderBy('id')->firstOrFail();
    }

    // ------------------------------------------------------------------
    // ملف العيّنة: الحركة اليومية
    // ------------------------------------------------------------------

    public function test_sample_daily_sheet_parses_with_expected_counts(): void
    {
        $parsed = app(WorkbookImporter::class)->parse($this->fixturePath());
        $daily = $parsed['daily'];

        $this->assertSame(77, $daily['lines_count']);
        $this->assertCount(42, $daily['invoices']);

        $customers = collect($daily['invoices'])->countBy('customer_name');
        $this->assertCount(23, $customers);
        $this->assertSame(13, $customers->filter(fn ($n) => $n > 1)->count());

        $series = collect($daily['invoices'])->countBy(fn ($i) => mb_strtolower($i['series']))->sortKeys()->all();
        $this->assertSame(['a' => 13, 'b' => 8, 'c' => 6, 'g' => 8, 'm' => 5, 'ارجيفيت' => 2], $series);

        $this->assertEqualsWithDelta(105898.04, $daily['lines_total'], 0.001);
        $this->assertEqualsWithDelta(105898.04, $daily['summary_total'], 0.001);
        $this->assertSame('3918', $daily['delivery_order_no']);
        $this->assertSame(['ع B: 43386'], collect($daily['invoices'])->where('is_zero_value', true)->keys()->all());

        $first = $daily['invoices']['ع G: 12830'];
        $this->assertSame(['G', '12830'], [$first['series'], $first['number']]);
        $this->assertSame('2026-10-12', $first['invoice_date']);
        $this->assertSame(['201201', 'كول سنتر سارة غوراني'], [$first['cost_center_code'], $first['cost_center_name']]);
        $this->assertSame(['ارجيفيت', '2571'], app(WorkbookImporter::class)->parseInvoiceNumber('ارجيفيت: 2571'));
        $this->assertSame(['m', '49569'], app(WorkbookImporter::class)->parseInvoiceNumber('ع m: 49569'));
    }

    public function test_sample_balances_sheet_parses_with_expected_counts(): void
    {
        $balances = app(WorkbookImporter::class)->parse($this->fixturePath())['balances'];

        $this->assertCount(1718, $balances['rows']);
        $this->assertSame(1718, collect($balances['rows'])->where('balance', '>', 0)->count());
        $this->assertEqualsWithDelta(164991651.59, $balances['total_debit'], 0.001);
        $this->assertEqualsWithDelta(164991651.59, $balances['file_total'], 0.001);
        $this->assertSame('2023-04-05', $balances['as_of']);
        $this->assertFalse(collect($balances['rows'])->contains('name', 'الرصيد'));
        $this->assertSame(1841858.39, collect($balances['rows'])->firstWhere('name', 'صيدلية قتيبة مصري - ركن الدين جبل')['balance']);
    }

    public function test_sample_import_end_to_end_without_deferred(): void
    {
        $report = $this->import($this->fixturePath(), '2026-10-13');

        $this->assertTrue($report['success'], $report['error'] ?? '');
        $sheet = $this->batchSheet($report);

        $this->assertSame('v2', $sheet->format);
        $this->assertSame('preparing', $sheet->status);
        $this->assertSame('3918', $sheet->delivery_order_no);
        $this->assertSame('2026-10-13', $sheet->distribution_date->toDateString());
        $this->assertSame('105898.04', $sheet->total_all);
        $this->assertSame('0.00', $sheet->total_deferred);
        $this->assertSame('105898.04', $sheet->total_effective);
        $this->assertStringStartsWith('excel_sheets/v2/', $sheet->stored_path);
        Storage::disk('local')->assertExists($sheet->stored_path);

        $this->assertSame(42, Invoice::where('distribution_sheet_id', $sheet->id)->count());
        $this->assertSame(77, InvoiceLine::count());
        $this->assertSame(0, Invoice::where('is_deferred', true)->count());
        $this->assertTrue(Invoice::where('invoice_number', 'ع B: 43386')->first()->is_zero_value);
        $this->assertSame(23, Invoice::where('distribution_sheet_id', $sheet->id)->distinct()->count('customer_id'));

        $this->assertSame(1718, Customer::where('balance', '>', 0)->count());
        $this->assertSame('164991651.59', BalanceImport::first()->total_debit);
        $this->assertSame(23, $report['counts']['customers']);
        $this->assertSame($report['counts']['customers'], $report['counts']['matched'] + $report['counts']['new']);
        $this->assertSame(6, \App\Models\InvoiceSeries::count());

        $this->assertDatabaseHas('activity_logs', ['action' => 'sheet.uploaded']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'sheet.created_from_batch', 'subject_id' => $sheet->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'customers.balances_imported']);
        $this->assertSame($sheet->batch_id, ActivityLog::where('action', 'sheet.uploaded')->first()->meta['batch_id']);
    }

    // ------------------------------------------------------------------
    // المؤجل
    // ------------------------------------------------------------------

    public function test_invoice_delivered_after_distribution_date_is_deferred(): void
    {
        // الفاتورة ع G: 12830 (سطر واحد بقيمة 3154) تُسلَّم بعد تاريخ التوزيع بيوم
        $path = $this->modifiedFixture(fn (Spreadsheet $s) => $s->getSheet(0)->setCellValue('L5', '14-10-2026'));

        $report = $this->import($path, '2026-10-13');
        $this->assertTrue($report['success'], $report['error'] ?? '');

        // المرحلة 2B: المؤجلة خارج الكشف، وتحقق إجمالي الملف سليم
        $sheet = $this->batchSheet($report);
        $this->assertSame('102744.04', $sheet->total_all);
        $this->assertSame('0.00', $sheet->total_deferred);
        $this->assertSame('102744.04', $sheet->total_effective);
        $this->assertEqualsWithDelta(105898.04, $report['batch']->stat('file_total'), 0.001);
        $this->assertEqualsWithDelta(3154.00, $report['batch']->stat('deferred.total'), 0.001);

        $deferred = Invoice::where('invoice_number', 'ع G: 12830')->first();
        $this->assertTrue($deferred->is_deferred);
        $this->assertNull($deferred->distribution_sheet_id);
        // تاريخ التسليم المساوي لتاريخ التوزيع ليس مؤجلاً
        $this->assertFalse(Invoice::where('invoice_number', 'ع G: 12831')->first()->is_deferred);
        $this->assertSame(1, Invoice::where('is_deferred', true)->count());
    }

    // ------------------------------------------------------------------
    // الرفض الكامل (rollback)
    // ------------------------------------------------------------------

    public function test_total_mismatch_rejects_whole_file_and_rolls_back(): void
    {
        $path = $this->modifiedFixture(fn (Spreadsheet $s) => $s->getSheet(0)->setCellValue('I5', 3155));

        $report = $this->import($path);

        $this->assertFalse($report['success']);
        $this->assertStringContainsString('105,899.04', $report['error']);
        $this->assertStringContainsString('105,898.04', $report['error']);
        $this->assertSame(0, DistributionSheet::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Customer::count());          // الأرصدة في المعاملة نفسها أُلغيت أيضاً
        $this->assertSame(0, BalanceImport::count());
        $this->assertEmpty(Storage::disk('local')->allFiles('excel_sheets/v2'));
    }

    public function test_same_invoice_with_two_customers_is_rejected(): void
    {
        $path = $this->modifiedFixture(function (Spreadsheet $s) {
            $sheet = $s->getSheet(0);
            $sheet->setCellValue('A13', 'ع G: 12830');
            $sheet->setCellValue('B13', '13-10-2026');
            $sheet->setCellValue('C13', 'صيدلية مختلفة تماماً');
        });

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('ع G: 12830');
        app(WorkbookImporter::class)->parse($path);
    }

    public function test_multiple_delivery_orders_are_normal_and_not_warned(): void
    {
        $path = $this->modifiedFixture(fn (Spreadsheet $s) => $s->getSheet(0)->setCellValue('N5', 3919));

        $report = $this->import($path);

        $this->assertTrue($report['success']);
        $this->assertSame(2, DistributionSheet::count());
        $this->assertFalse(collect($report['batch']->warnings)->contains(fn ($w) => str_contains($w, 'أكثر من رقم أمر تسليم')));
    }

    // ------------------------------------------------------------------
    // التكرار ومطابقة الزبائن
    // ------------------------------------------------------------------

    public function test_previously_deferred_invoice_is_never_duplicated(): void
    {
        // يوم 13: الفاتورة 12830 مؤجلة
        $deferredFile = $this->modifiedFixture(fn (Spreadsheet $s) => $s->getSheet(0)->setCellValue('L5', '14-10-2026'));
        $this->assertTrue($this->import($deferredFile, '2026-10-13')['success']);

        // يوم 14: تظهر الفاتورة نفسها في ملف يوم تسليمها
        $report = $this->import($deferredFile, '2026-10-14');
        $this->assertTrue($report['success'], $report['error'] ?? '');

        $this->assertSame(42, Invoice::count());
        $this->assertSame(1, Invoice::where('invoice_number', 'ع G: 12830')->count());
        $this->assertSame(Invoice::IN_SHEET, Invoice::where('invoice_number', 'ع G: 12830')->first()->placement);
    }

    public function test_arnaba_spelling_variants_match_the_same_customer(): void
    {
        $existing = Customer::create(['name' => 'صيدلية الإسراء - خان أرنبة', 'area' => 'خان أرنبة']);

        $path = $this->buildWorkbook([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية الاسراء - خان ارنبة', 'lines' => [['صنف', 100]]],
        ]);
        $report = $this->import($path);

        $this->assertTrue($report['success'], $report['error'] ?? '');
        $this->assertSame(1, $report['counts']['matched']);
        $this->assertSame($existing->id, Invoice::first()->customer_id);
        $this->assertSame(1, Customer::count());
        $this->assertSame('خان أرنبة', Invoice::first()->area);
    }

    public function test_unknown_customer_is_created_for_review_with_suggestions(): void
    {
        Customer::create(['name' => 'صيدلية النور - القنيطرة', 'area' => 'القنيطرة']);

        $path = $this->buildWorkbook([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية النور الجديدة - القنيطرة', 'lines' => [['صنف', 100]]],
        ]);
        $report = $this->import($path);

        $new = Customer::where('needs_review', true)->first();
        $this->assertNotNull($new);
        $this->assertNull($new->area);
        $this->assertSame('__none__', Invoice::first()->area_key);
        $this->assertSame([$new->id], $report['batch']->stat('new_customer_ids'));
        $this->assertSame('صيدلية النور - القنيطرة', app(SheetImportService::class)->suggest($new)[0]['name']);
        $this->assertTrue(collect($report['batch']->warnings)->contains(fn ($w) => str_contains($w, 'بلا منطقة')));
    }

    public function test_balances_update_existing_and_zero_missing_customers(): void
    {
        $missing = Customer::create(['name' => 'زبون غير موجود في الملف', 'balance' => 500]);
        $existing = Customer::create(['name' => 'صيدلية ألف', 'balance' => 10]);

        $path = $this->buildWorkbook(
            [['number' => 'ع G: 1', 'customer' => 'صيدلية الف', 'lines' => [['صنف', 100]]]],
            [['صيدلية الف', 'كتلة 1', 'منطقة 1', 1841858.3900000001, 0], ['صيدلية باء', 'كتلة 2', 'منطقة 2', 300, 50]],
        );
        $report = $this->import($path);
        $this->assertTrue($report['success'], $report['error'] ?? '');

        // المرحلة 7B: تصفير 1 من 2 زبونين برصيد (50% > 30%) ⇒ لا تطبيق حتى تأكيد «الملف كامل»
        $import = BalanceImport::sole();
        $this->assertSame([BalanceImport::HELD, BalanceImport::HOLD_ZEROING], [$import->status, $import->hold_reason]);
        $this->assertSame('500.00', $missing->fresh()->balance);
        $this->actingAs(\App\Models\User::factory()->role(\App\Enums\Role::SheetManager)->create())
            ->post(route('sheet-manager.balances.apply', $import), ['confirm_zeroing' => 1])->assertSessionHasNoErrors();

        $this->assertSame('1841858.39', $existing->fresh()->balance);
        $this->assertSame('منطقة 1', $existing->fresh()->area);
        $this->assertSame('كتلة 1', $existing->fresh()->block);
        $this->assertSame('250.00', Customer::where('name', 'صيدلية باء')->first()->balance);
        $this->assertSame('0.00', $missing->fresh()->balance);       // لا حذف، فقط صفر
        $this->assertSame(3, Customer::count());
    }

    public function test_multi_file_upload_applies_newest_balances_only(): void
    {
        $older = $this->buildWorkbook(
            [['number' => 'ع G: 1', 'customer' => 'صيدلية الف', 'lines' => [['صنف', 100]]]],
            [['صيدلية الف', null, 'منطقة 1', 111, 0]], '1-10-2026'
        );
        $newer = $this->buildWorkbook(
            [['number' => 'ع G: 2', 'customer' => 'صيدلية الف', 'lines' => [['صنف', 200]]]],
            [['صيدلية الف', null, 'منطقة 1', 999, 0]], '12-10-2026'
        );

        $result = app(SheetImportService::class)->importBatch(
            [$this->uploaded($older, 'old.xlsx'), $this->uploaded($newer, 'new.xlsx')], '2026-10-13', $this->supervisor
        );

        $this->assertSame('999.00', Customer::where('name', 'صيدلية الف')->first()->balance);
        $this->assertSame(1, BalanceImport::count());
        // الملفان بأمر التسليم نفسه وتاريخ التوزيع نفسه: كشف واحد بفاتورتين
        $this->assertSame(1, DistributionSheet::where('batch_id', $result['batch_id'])->count());
        $this->assertSame(2, DistributionSheet::first()->invoices()->count());
        $this->assertStringContainsString('تم تجاهلها', implode(' ', $result['reports'][0]['notes']));
    }

    public function test_upload_screen_accepts_multiple_files_and_shows_preview(): void
    {
        $manager = User::factory()->role(Role::SheetManager)->create();
        $one = $this->buildWorkbook([['number' => 'ع G: 1', 'customer' => 'صيدلية الف', 'lines' => [['صنف', 100]]]]);
        $two = $this->buildWorkbook([['number' => 'ع G: 2', 'customer' => 'صيدلية الف', 'order' => 4000, 'lines' => [['صنف', 200]]]]);

        // المشرف فقد الرفع
        $this->actingAs($this->supervisor)
            ->post(route('sheet-manager.upload'), ['excel_files' => [$this->uploaded($one, 'one.xlsx')], 'distribution_date' => '2026-10-13'])
            ->assertForbidden();

        $response = $this->actingAs($manager)
            ->post(route('sheet-manager.upload'), [
                'excel_files' => [$this->uploaded($one, 'one.xlsx'), $this->uploaded($two, 'two.xlsx')],
                'distribution_date' => '2026-10-13',
            ]);
        $batch = \App\Models\ImportBatch::firstOrFail();
        $response->assertRedirect(route('sheet-manager.batches.show', $batch));

        $this->actingAs($manager)->get(route('sheet-manager.batches.show', $batch))
            ->assertOk()
            ->assertSee('معاينة الدفعة')
            ->assertSee('one.xlsx')
            ->assertSee('two.xlsx')
            ->assertSee('#3918')
            ->assertSee('#4000');

        $this->assertSame(2, DistributionSheet::count());
        $this->assertSame(1, DistributionSheet::distinct()->count('batch_id'));
        $this->assertSame(0, \App\Models\SheetAssignment::count());                 // لا إسناد تلقائي
        $this->assertSame(2, DistributionSheet::pluck('stored_path')->unique()->count()); // لا كتابة فوق ملف
    }

    public function test_timezone_is_damascus(): void
    {
        $this->assertSame('Asia/Damascus', config('app.timezone'));
    }
}
