<?php

namespace Tests\Feature\Phase5;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\FinanceExport;
use App\Models\FinanceExportRow;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Setting;
use App\Services\Finance\FinanceExportFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 5-E/F: التصدير للمالية (اللقطة، idempotency، التصدير المتأخر، إعادة التنزيل) وملفات Excel الثلاثة.
 */
class FinanceExportTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity, FinanceFixture;

    private const STATEMENT = 'بيد السيد الموزع الأول إيصال إيصال مالية كشف رقم 3918يومية ';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
    }

    public function test_preview_page_is_live_and_writes_nothing(): void
    {
        $this->pay($this->north, 1500);
        $logs = ActivityLog::count();

        $this->actingAs($this->accountant)->get(route('finance.sheets.show', $this->sheet))
            ->assertOk()
            ->assertSee('معاينة التوزيع')
            ->assertSee('655231-كرم الخالدي')
            ->assertSee('تصدير للمالية')
            ->assertDontSee('98,765.43');

        $this->assertSame(0, FinanceExport::count());
        $this->assertSame($logs, ActivityLog::count());
        $this->assertSame('pending', Payment::first()->status);
    }

    public function test_export_creates_snapshot_and_marks_payments_exported(): void
    {
        $payment = $this->pay($this->north, 1500);

        $this->exportAs($this->accountant)->assertRedirect(route('finance.sheets.show', $this->sheet))->assertSessionHasNoErrors();

        $export = FinanceExport::sole();
        $this->assertSame(1, $export->sequence);
        $this->assertSame($this->accountant->id, $export->exported_by);
        $this->assertSame(1, $export->payments_count);
        $this->assertEquals(1500, (float) $export->total_syp);
        $this->assertSame(hash('sha256', \App\Services\Finance\FinanceService::DEFAULT_TEMPLATE), $export->template_signature);
        $this->assertSame(\App\Services\Finance\FinanceService::DEFAULT_TEMPLATE, $export->statement_template);

        $payment->refresh();
        $this->assertSame('exported', $payment->status);
        $this->assertNotNull($payment->exported_at);
        $this->assertSame($export->id, $payment->finance_export_id);

        // الحليب كاملاً ثم ارجيفيت جزئياً، والبقية بلا شيء؛ الصفرية والمؤجلة خارج اللقطة
        $alloc = PaymentAllocation::where('finance_export_id', $export->id)->get()->keyBy('invoice_number');
        $this->assertEquals(1000, (float) $alloc['ع M: 101']->allocated);
        $this->assertSame('paid', $alloc['ع M: 101']->status);
        $this->assertEquals(500, (float) $alloc['ارجيفيت: 201']->allocated);
        $this->assertEquals(100, (float) $alloc['ارجيفيت: 201']->unpaid);
        $this->assertSame('partial', $alloc['ارجيفيت: 201']->status);
        $this->assertSame('argivit', $alloc['ارجيفيت: 201']->allocation_group);
        $this->assertEquals(0, (float) $alloc['ع G: 301']->allocated);
        $this->assertSame('unpaid', $alloc['ع G: 301']->status);
        $this->assertArrayNotHasKey('ع G: 302', $alloc->all());
        $this->assertArrayNotHasKey('ع G: 303', $alloc->all());
        $this->assertNotNull($alloc['ع M: 101']->invoice_key);

        $rows = FinanceExportRow::where('finance_export_id', $export->id)->whereIn('row_type', ['allocation', 'surplus'])->orderBy('sort')->get();
        $this->assertSame([['201201-كول سنتر', 500.0], ['655231-كرم الخالدي', 1000.0]], $rows->map(fn ($r) => [$r->cost_center, (float) $r->amount])->all());
        $this->assertSame('صيدلية الشمال', $rows[0]->customer_name);
        $this->assertSame(self::STATEMENT, $rows[0]->statement);

        $this->assertDatabaseHas('activity_logs', ['action' => 'finance.exported', 'subject_id' => $export->id, 'user_id' => $this->accountant->id]);
    }

    public function test_double_click_does_not_create_two_exports(): void
    {
        $this->pay($this->north, 1500);
        $signature = $this->signature();

        $this->exportAs($this->accountant, null, $signature)->assertSessionHasNoErrors();
        $this->exportAs($this->accountant, null, $signature)->assertSessionHasErrors('export');

        $this->assertSame(1, FinanceExport::count());
        $this->assertSame(1, ActivityLog::where('action', 'finance.exported')->count());
    }

    public function test_changed_data_since_preview_is_rejected(): void
    {
        $this->pay($this->north, 1500);
        $signature = $this->signature();
        $this->pay($this->north, 50);   // دفعة جديدة بعد المعاينة

        $this->exportAs($this->accountant, null, $signature)->assertSessionHasErrors('export');
        $this->assertSame(0, FinanceExport::count());
    }

    public function test_snapshot_does_not_change_after_invoice_edit_and_redownload_uses_it(): void
    {
        $this->pay($this->north, 1500);
        $this->exportAs($this->accountant)->assertSessionHasNoErrors();
        $export = FinanceExport::sole();

        Invoice::where('invoice_number', 'ع M: 101')->update(['invoice_amount' => 5000, 'cost_center_name' => 'تغيّر']);

        $this->assertEquals(1000, (float) PaymentAllocation::where('invoice_number', 'ع M: 101')->value('invoice_total'));
        $rows = $this->rows($this->book($this->actingAs($this->accountant)->get(route('finance.exports.download', [$export, 'import']))));
        $this->assertSame([['صيدلية الشمال', self::STATEMENT, 500.0, '201201-كول سنتر'], ['صيدلية الشمال', self::STATEMENT, 1000.0, '655231-كرم الخالدي']],
            array_map(fn ($r) => [$r[0], $r[1], (float) $r[2], $r[3]], $rows));
        $this->assertDatabaseHas('activity_logs', ['action' => 'finance.export_downloaded', 'subject_id' => $export->id]);
    }

    public function test_late_export_uses_remaining_after_previous_snapshot_without_repeating_rows(): void
    {
        $this->pay($this->north, 1500);
        $this->exportAs($this->accountant)->assertSessionHasNoErrors();

        // بلا pending ⇒ مرفوض («لا دفعات جديدة»)
        $this->exportAs($this->accountant)->assertSessionHasErrors('export');
        $this->actingAs($this->accountant)->get(route('finance.sheets.show', $this->sheet))->assertSee('لا دفعات جديدة');

        // دفعة متأخرة 700: ارجيفيت المتبقي 100 ثم G:301 = 400، والفائض 200 على مركز أعلى فاتورة رقماً (G:302)
        $late = $this->pay($this->north, 700);
        $this->exportAs($this->accountant)->assertSessionHasNoErrors();

        $second = FinanceExport::where('sequence', 2)->sole();
        $this->assertSame(1, $second->payments_count);
        $this->assertSame($second->id, $late->fresh()->finance_export_id);

        $rows = FinanceExportRow::where('finance_export_id', $second->id)->whereIn('row_type', ['allocation', 'surplus'])->orderBy('sort')->get();
        $this->assertSame([['allocation', '201201-كول سنتر', 500.0], ['surplus', '201201-كول سنتر', 200.0]],
            $rows->map(fn ($r) => [$r->row_type, $r->cost_center, (float) $r->amount])->all());
        $this->assertEquals(700, $rows->sum(fn ($r) => (float) $r->amount));

        $alloc = PaymentAllocation::where('finance_export_id', $second->id)->get()->keyBy('invoice_number');
        $this->assertEquals(0, (float) $alloc['ع M: 101']->allocated);
        $this->assertEquals(100, (float) $alloc['ارجيفيت: 201']->allocated);
        $this->assertEquals(400, (float) $alloc['ع G: 301']->allocated);
        $this->assertEquals(200, (float) $second->surplus_syp);

        // ملف الدفعة الثانية لا يكرر صفوف الأولى
        $file = $this->rows($this->book($this->actingAs($this->accountant)->get(route('finance.exports.download', [$second, 'import']))));
        $this->assertCount(2, $file);
    }

    public function test_void_after_export_is_forbidden(): void
    {
        $payment = $this->pay($this->north, 1500);
        $this->exportAs($this->accountant)->assertSessionHasNoErrors();

        $this->actingAs($this->accountant)->postJson(route('payments.void', $payment), ['reason' => 'خطأ إدخال'])->assertForbidden();
        $this->assertNull($payment->fresh()->voided_at);
    }

    public function test_draft_preparing_and_v1_sheets_cannot_be_exported(): void
    {
        $this->pay($this->north, 1500);

        $this->sheet->update(['status' => 'draft']);
        $this->exportAs($this->accountant)->assertSessionHasErrors('export');
        $this->actingAs($this->accountant)->get(route('finance.sheets.show', $this->sheet))->assertOk()->assertSee('الكشف مسودة');

        $this->sheet->update(['status' => 'preparing']);
        $this->exportAs($this->admin)->assertSessionHasErrors('export');
        $this->actingAs($this->accountant)->get(route('finance.sheets.show', $this->sheet))->assertNotFound();

        $this->sheet->update(['status' => 'in_progress', 'format' => 'v1']);
        $this->exportAs($this->accountant)->assertSessionHasErrors('export');
        $this->actingAs($this->accountant)->get(route('finance.sheets.show', $this->sheet))->assertSee('للقراءة فقط');

        $this->assertSame(0, FinanceExport::count());
        $this->assertSame('pending', Payment::first()->status);
    }

    // ------------------------------------------------------------------
    // الملفات
    // ------------------------------------------------------------------

    public function test_import_file_is_one_sheet_with_literal_headers_numeric_amounts_and_matching_total(): void
    {
        $this->pay($this->north, 1500);
        $this->pay($this->north, 100, 'USD', 139);                 // 13,900
        $this->pay($this->south, 2000.5, 'SYP', null, $this->d2);
        $this->exportAs($this->accountant)->assertSessionHasNoErrors();
        $export = FinanceExport::sole();

        $response = $this->actingAs($this->accountant)->get(route('finance.exports.download', [$export, 'import']));
        $book = $this->book($response);

        $this->assertSame(1, $book->getSheetCount());
        $ws = $book->getSheet(0);
        $this->assertTrue($ws->getRightToLeft());
        $this->assertSame(FinanceExportFiles::IMPORT_HEADERS, [$ws->getCell('A1')->getValue(), $ws->getCell('B1')->getValue(), $ws->getCell('C1')->getValue(), $ws->getCell('D1')->getValue()]);
        $this->assertSame('', (string) $ws->getCell('E1')->getValue());

        $rows = $this->rows($book);
        $total = 0.0;
        foreach ($rows as $i => $row) {
            $cell = $ws->getCell('C'.($i + 2));
            $this->assertSame(DataType::TYPE_NUMERIC, $cell->getDataType());
            $this->assertSame('0.00', $ws->getStyle('C'.($i + 2))->getNumberFormat()->getFormatCode());
            $this->assertNotSame('', trim((string) $row[3]), 'كل صف له مركز كلفة');
            $total += (float) $row[2];
        }
        $this->assertEqualsWithDelta(1500 + 13900 + 2000.5, $total, 0.001);
        // لا صف إجمالي: آخر صف لزبون حقيقي
        $this->assertContains(end($rows)[0], ['صيدلية الشمال', 'صيدلية الجنوب']);
        // الترتيب: اسم الزبون ثم مركز الكلفة
        $keys = array_map(fn ($r) => [$r[0], $r[3]], $rows);
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys);

        // كشف المقبوضات: صف لكل دفعة (المكافئ بالليرة)
        $receipts = $this->rows($this->book($this->actingAs($this->accountant)->get(route('finance.exports.download', [$export, 'receipts']))));
        $this->assertCount(3, $receipts);
        $this->assertEqualsCanonicalizing([1500.0, 13900.0, 2000.5], array_map(fn ($r) => (float) $r[2], $receipts));

        // الدولار: الدفعة الدولارية فقط بسعرها
        $usdBook = $this->book($this->actingAs($this->accountant)->get(route('finance.exports.download', [$export, 'usd'])));
        $this->assertSame(FinanceExportFiles::USD_HEADERS, array_slice($usdBook->getSheet(0)->toArray()[0], 0, 6));
        $usd = $this->rows($usdBook);
        $this->assertCount(1, $usd);
        $this->assertSame([100.0, 139.0, 13900.0], [(float) $usd[0][2], (float) $usd[0][3], (float) $usd[0][4]]);
        $this->assertSame('TST-20261013-2', $usd[0][5]);
    }

    public function test_usd_file_exists_only_with_usd_payments(): void
    {
        $this->pay($this->north, 1500);
        $this->exportAs($this->accountant)->assertSessionHasNoErrors();

        $this->actingAs($this->accountant)->get(route('finance.exports.download', [FinanceExport::sole(), 'usd']))->assertNotFound();
        $this->actingAs($this->accountant)->get(route('finance.sheets.show', $this->sheet))->assertDontSee('/download/usd', false);
    }

    public function test_statement_is_literal_and_settings_do_not_trim(): void
    {
        $this->assertSame(self::STATEMENT, Setting::get('finance_statement_template') === null ? null : str_replace(['{user}', '{order}'], ['الموزع الأول', '3918'], Setting::get('finance_statement_template')));

        // حفظ قالب بمسافة في البداية والنهاية من /settings: يُحفظ حرفياً
        $template = ' بيان {user} و{order}يومية ';
        $this->actingAs($this->admin)->put(route('settings.finance'), ['finance_statement_template' => $template])->assertSessionHasNoErrors();
        $this->assertSame($template, Setting::get('finance_statement_template'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'settings.finance_changed']);

        $this->pay($this->north, 1500);
        $this->pay($this->north, 10, 'SYP', null, $this->d2);   // مُدخِلان لصف مجمّع
        $this->exportAs($this->accountant)->assertSessionHasNoErrors();
        $rows = $this->rows($this->book($this->actingAs($this->accountant)->get(route('finance.exports.download', [FinanceExport::sole(), 'import']))));
        $this->assertSame(' بيان الموزع الأول و الموزع الثاني و3918يومية ', $rows[0][1]);

        $receipts = $this->rows($this->book($this->actingAs($this->accountant)->get(route('finance.exports.download', [FinanceExport::sole(), 'receipts']))));
        $this->assertSame([' بيان الموزع الأول و3918يومية ', ' بيان الموزع الثاني و3918يومية '], array_column($receipts, 1));

        // المشرف لا يرى قسم المالية في /settings ولا يحفظه
        $this->actingAs($this->supervisor)->get(route('settings.index'))->assertOk()->assertDontSee('finance_statement_template');
        $this->actingAs($this->supervisor)->put(route('settings.finance'), ['finance_statement_template' => 'x'])->assertForbidden();
        $this->actingAs($this->admin)->get(route('settings.index'))->assertSee('finance_statement_template');
    }

    public function test_default_statement_with_empty_delivery_order_uses_sheet_id(): void
    {
        $this->sheet->update(['delivery_order_no' => null]);
        $this->pay($this->north, 1500);
        $this->exportAs($this->accountant)->assertSessionHasNoErrors();

        $row = FinanceExportRow::where('row_type', 'allocation')->first();
        $this->assertSame('بيد السيد الموزع الأول إيصال إيصال مالية كشف رقم #'.$this->sheet->id.'يومية ', $row->statement);
    }

    public function test_formula_injection_is_neutralised_without_changing_text(): void
    {
        $this->north->update(['name' => '=HYPERLINK("http://x")']);
        $this->pay($this->north, 1500);
        $this->exportAs($this->accountant)->assertSessionHasNoErrors();

        $ws = $this->book($this->actingAs($this->accountant)->get(route('finance.exports.download', [FinanceExport::sole(), 'import'])))->getSheet(0);
        $this->assertSame('=HYPERLINK("http://x")', $ws->getCell('A2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $ws->getCell('A2')->getDataType());
        $this->assertTrue($ws->getStyle('A2')->getQuotePrefix());
        $this->assertFalse($ws->getStyle('B2')->getQuotePrefix());   // النص المعتاد كما هو
    }

    public function test_file_names_follow_the_namer_with_suffix(): void
    {
        $this->pay($this->north, 1500);
        $this->exportAs($this->accountant)->assertSessionHasNoErrors();
        $export = FinanceExport::sole();

        $response = $this->actingAs($this->accountant)->get(route('finance.exports.download', [$export, 'import']));
        // المحاسب: أسماء الموزعين المسندين (بترتيب الإسناد كما في SheetExportNamer) ثم رقم الأمر ثم اللاحقة
        $names = $this->fileName($response);
        $this->assertContains($names, ['الموزع الأول + الموزع الثاني - 3918 - ملف الاستيراد.xlsx', 'الموزع الثاني + الموزع الأول - 3918 - ملف الاستيراد.xlsx']);
        $this->assertStringContainsString('filename=sheet_3918_import.xlsx', $response->headers->get('content-disposition'));

        $receipts = $this->actingAs($this->accountant)->get(route('finance.exports.download', [$export, 'receipts']));
        $this->assertSame(str_replace('ملف الاستيراد', 'كشف المقبوضات', $names), $this->fileName($receipts));

        // الدفعة التصديرية الثانية تحمل رقمها
        $this->pay($this->north, 10);
        $this->exportAs($this->admin)->assertSessionHasNoErrors();
        $second = FinanceExport::where('sequence', 2)->sole();
        $response = $this->actingAs($this->admin)->get(route('finance.exports.download', [$second, 'import']));
        $this->assertStringEndsWith(' - 3918 - ملف الاستيراد 2.xlsx', $this->fileName($response));
        $this->assertStringContainsString('filename=sheet_3918_import_2.xlsx', $response->headers->get('content-disposition'));
    }

    // ------------------------------------------------------------------
    // الفائض
    // ------------------------------------------------------------------

    public function test_surplus_without_invoices_blocks_until_accountant_chooses_center(): void
    {
        $external = Customer::create(['name' => 'صيدلية خارجية', 'area' => 'منطقة الشمال']);
        $this->pay($external, 300);

        $this->actingAs($this->accountant)->get(route('finance.sheets.show', $this->sheet))
            ->assertSee('فائض بلا مركز كلفة')
            ->assertSee('دفعة خارجية');
        $this->exportAs($this->accountant)->assertSessionHasErrors('export');

        // مركز من خارج الكشف مرفوض
        $this->actingAs($this->accountant)->post(route('finance.sheets.surplus', $this->sheet), ['customer_id' => $external->id, 'cost_center' => '999-غريب'])
            ->assertSessionHasErrors('cost_center');

        $this->actingAs($this->accountant)->post(route('finance.sheets.surplus', $this->sheet), ['customer_id' => $external->id, 'cost_center' => '700100-الجنوب'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('activity_logs', ['action' => 'finance.surplus_cost_center_set', 'user_id' => $this->accountant->id]);

        $this->exportAs($this->accountant)->assertSessionHasNoErrors();
        $row = FinanceExportRow::where('row_type', 'surplus')->sole();
        $this->assertSame(['صيدلية خارجية', '700100-الجنوب', 300.0], [$row->customer_name, $row->cost_center, (float) $row->amount]);
    }

    public function test_suggested_surplus_center_can_be_overridden(): void
    {
        $this->pay($this->south, 3000, 'SYP', null, $this->d2);   // 2222 + فائض 778

        $html = $this->actingAs($this->accountant)->get(route('finance.sheets.show', $this->sheet))->getContent();
        $this->assertStringContainsString('(مقترح)', $html);

        $this->actingAs($this->accountant)->post(route('finance.sheets.surplus', $this->sheet), ['customer_id' => $this->south->id, 'cost_center' => '655231-كرم الخالدي'])->assertSessionHasNoErrors();
        $log = ActivityLog::where('action', 'finance.surplus_cost_center_set')->sole();
        $this->assertNull($log->old_values['cost_center'] ?? null);
        $this->assertSame('655231-كرم الخالدي', $log->new_values['cost_center']);

        $this->exportAs($this->accountant)->assertSessionHasNoErrors();
        $this->assertSame('655231-كرم الخالدي', FinanceExportRow::where('row_type', 'surplus')->value('cost_center'));
        $this->assertEquals(778, (float) FinanceExportRow::where('row_type', 'surplus')->value('amount'));
    }
}
