<?php

namespace Tests\Feature\Phase2B;

use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * J) الاستيراد والتصنيف: كل أمر تسليم كشف، المؤجلة بلا كشف، أمر صفر = بلا كشف، ولا تكرار.
 * في ملف العيّنة: السطر 5 هو الفاتورة ع G: 12830 (سطر واحد بقيمة 3154)، العمود L تاريخ التسليم، والعمود N أمر التسليم.
 */
class ImportClassificationTest extends TestCase
{
    use RefreshDatabase, Workbooks;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_sample_file_creates_one_preparing_sheet(): void
    {
        $result = $this->importFiles($this->fixturePath(), '2026-10-13');
        $this->assertTrue($result['reports'][0]['success'], $result['reports'][0]['error'] ?? '');

        $this->assertSame(1, DistributionSheet::count());
        $sheet = DistributionSheet::first();
        $this->assertSame('preparing', $sheet->status);
        $this->assertSame('3918', $sheet->delivery_order_no);
        $this->assertSame($result['batch_id'], $sheet->batch_id);
        $this->assertSame(42, $sheet->invoices()->count());
        $this->assertSame(23, $sheet->invoices()->distinct()->count('customer_id'));
        $this->assertSame('105898.04', $sheet->total_all);
        $this->assertSame('105898.04', $sheet->total_effective);
        $this->assertSame(0, Invoice::where('placement', Invoice::DEFERRED)->count());
        $this->assertSame(0, Invoice::where('placement', Invoice::UNASSIGNED)->count());
        $this->assertSame(42, Invoice::whereNotNull('invoice_key')->distinct()->count('invoice_key'));

        // الأرصدة كما قبل
        $this->assertSame(1718, Customer::where('balance', '>', 0)->count());

        $batch = $result['batch'];
        $this->assertEqualsWithDelta(105898.04, $batch->stat('file_total'), 0.001);
        $this->assertEqualsWithDelta(105898.04, $batch->stat('sheets.total'), 0.001);
        $this->assertSame(42, $batch->stat('sheets.count'));
        $this->assertSame(0, $batch->stat('deferred.count'));
        $this->assertSame([$sheet->id], $batch->stat('created_sheet_ids'));
    }

    public function test_two_delivery_orders_create_two_sheets_summing_to_file_total(): void
    {
        $path = $this->modifiedFixture(fn (Spreadsheet $s) => $s->getSheet(0)->setCellValue('N5', 3919));

        $result = $this->importFiles($path, '2026-10-13');

        $this->assertSame(2, DistributionSheet::count());
        $small = DistributionSheet::where('delivery_order_no', '3919')->firstOrFail();
        $big = DistributionSheet::where('delivery_order_no', '3918')->firstOrFail();

        $this->assertSame(['ع G: 12830'], $small->invoices()->pluck('invoice_number')->all());
        $this->assertSame('3154.00', $small->total_all);
        $this->assertSame(41, $big->invoices()->count());
        $this->assertSame('102744.04', $big->total_all);
        $this->assertEqualsWithDelta(105898.04, (float) $small->total_all + (float) $big->total_all, 0.001);
        $this->assertEqualsWithDelta($result['batch']->stat('file_total'), $result['batch']->stat('sheets.total'), 0.001);
        $this->assertSame('3919', Invoice::where('invoice_number', 'ع G: 12830')->first()->delivery_order_no);
    }

    public function test_invoice_delivered_after_distribution_date_is_deferred_outside_any_sheet(): void
    {
        $path = $this->modifiedFixture(fn (Spreadsheet $s) => $s->getSheet(0)->setCellValue('L5', '14-10-2026'));

        $result = $this->importFiles($path, '2026-10-13');
        $this->assertTrue($result['reports'][0]['success'], $result['reports'][0]['error'] ?? '');

        $invoice = Invoice::where('invoice_number', 'ع G: 12830')->first();
        $this->assertSame(Invoice::DEFERRED, $invoice->placement);
        $this->assertTrue($invoice->is_deferred);
        $this->assertNull($invoice->distribution_sheet_id);
        $this->assertSame(41, DistributionSheet::first()->invoices()->count());

        // تحقق إجمالي الملف بقي سليماً: الكشوف + المؤجل = الملف
        $batch = $result['batch'];
        $this->assertEqualsWithDelta(105898.04, $batch->stat('file_total'), 0.001);
        $this->assertEqualsWithDelta(3154, $batch->stat('deferred.total'), 0.001);
        $this->assertEqualsWithDelta(105898.04, $batch->stat('sheets.total') + $batch->stat('deferred.total'), 0.001);

        // مؤجلة بأمر تسليم غير صفري: تحذير
        $this->assertTrue(collect($batch->warnings)->contains(fn ($w) => str_contains($w, 'أمر تسليم لتاريخ مستقبلي') && str_contains($w, 'ع G: 12830')));
    }

    public function test_zero_delivery_order_on_distribution_day_is_unassigned(): void
    {
        $path = $this->modifiedFixture(fn (Spreadsheet $s) => $s->getSheet(0)->setCellValue('N5', 0));

        $result = $this->importFiles($path, '2026-10-13');

        $invoice = Invoice::where('invoice_number', 'ع G: 12830')->first();
        $this->assertSame(Invoice::UNASSIGNED, $invoice->placement);
        $this->assertNull($invoice->distribution_sheet_id);
        $this->assertNull($invoice->delivery_order_no);
        $this->assertSame(1, $result['batch']->stat('unassigned.count'));
        $this->assertTrue(collect($result['batch']->warnings)->contains(fn ($w) => str_contains($w, 'بلا أمر تسليم')));
    }

    public function test_uploading_the_same_file_twice_creates_no_duplicates_and_no_changes(): void
    {
        $first = $this->importFiles($this->fixturePath(), '2026-10-13');
        $second = $this->importFiles($this->fixturePath(), '2026-10-13');

        $this->assertSame(42, Invoice::count());
        $this->assertSame(77, InvoiceLine::count());
        $this->assertSame(1, DistributionSheet::count());
        $this->assertSame([], $second['batch']->stat('updates'));
        $this->assertSame([], $second['batch']->stat('proposal_ids'));
        $this->assertSame([], $second['batch']->stat('created_sheet_ids'));
        $this->assertSame(42, Invoice::where('last_seen_batch_id', $second['batch_id'])->count());
        $this->assertNotSame($first['batch_id'], $second['batch_id']);
    }
}
