<?php

namespace Tests\Feature\Phase6B;

use App\Enums\Role;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\InvoiceChangeProposal;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Services\Import\ImportException;
use App\Services\Import\WorkbookImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 6B-A: الحسم في الحركة اليومية (القراءة، الحساب، التحقق، البصمة، المتانة).
 */
class DiscountImportTest extends TestCase
{
    use RefreshDatabase, Workbooks;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function parse(string $path): array
    {
        return app(WorkbookImporter::class)->parse($path);
    }

    public function test_invoice_13722_net_is_2945_75(): void
    {
        $result = $this->importFiles($this->buildDiscountWorkbook([$this->invoice13722()]));
        $this->assertSame([], array_filter($result['reports'] ?? [], fn ($r) => ! ($r['success'] ?? true)));

        $invoice = Invoice::where('invoice_number', 'ع G: 13722')->sole();
        $this->assertEquals(2945.75, (float) $invoice->invoice_amount);
        $lines = $invoice->lines()->orderBy('line_no')->get();
        $this->assertSame(['306.00', '510.00', '1020.00', '348.00', '512.00', '399.00'], $lines->pluck('line_total')->all());
        $this->assertSame(['10.000', '10.000', '0.000', '16.000', '0.000', '3.000'], $lines->pluck('discount_percent')->all());
        $this->assertSame(['30.60', '51.00', '0.00', '55.68', '0.00', '11.97'], $lines->pluck('discount_amount')->all());
        $this->assertEqualsWithDelta(3095.00, $lines->sum(fn ($l) => (float) $l->line_total), 0.001);
        $this->assertEqualsWithDelta(149.25, $lines->sum(fn ($l) => (float) $l->discount_amount), 0.001);
        $this->assertEqualsWithDelta(2945.75, $lines->sum(fn ($l) => (float) $l->line_net), 0.001);
        $this->assertFalse($invoice->net_uncertain);

        $batch = ImportBatch::latest('created_at')->first();
        $this->assertEquals(['gross' => 3095.0, 'discount' => 149.25, 'net' => 2945.75, 'net_uncertain_invoices' => 0], $batch->stat('totals'));
    }

    public function test_percent_reading_text_percent_cell_plain_negative_and_over_100(): void
    {
        $parsed = $this->parse($this->buildDiscountWorkbook([[
            'number' => 'ع G: 1', 'customer' => 'صيدلية', 'lines' => [
                ['item' => 'نص', 'qty' => 1, 'unit' => 100, 'discount' => '10.00%'],
                ['item' => 'خلية نسبة', 'qty' => 1, 'unit' => 100, 'discount' => ['pct' => 0.1]],
                ['item' => 'رقم', 'qty' => 1, 'unit' => 100, 'discount' => 10],
                ['item' => 'سالب', 'qty' => 1, 'unit' => 100, 'discount' => -5],
                ['item' => 'أكبر', 'qty' => 1, 'unit' => 100, 'discount' => 150],
            ],
        ]], null, ['summary_discount' => 30]));

        $lines = $parsed['daily']['invoices']['ع G: 1']['lines'];
        $this->assertSame([10.0, 10.0, 10.0, 0.0, 0.0], array_column($lines, 'discount_percent'));
        $this->assertSame([10.0, 10.0, 10.0, 0.0, 0.0], array_column($lines, 'discount_amount'));
        $this->assertSame(470.0, $parsed['daily']['invoices']['ع G: 1']['total']);
        $warnings = implode("\n", $parsed['warnings']);
        $this->assertStringContainsString('خارج المجال (-5)', $warnings);
        $this->assertStringContainsString('خارج المجال (150)', $warnings);
    }

    public function test_fraction_warning_when_all_plain_values_are_at_most_one(): void
    {
        $parsed = $this->parse($this->buildDiscountWorkbook([[
            'number' => 'ع G: 1', 'customer' => 'صيدلية', 'lines' => [
                ['item' => 'أ', 'qty' => 1, 'unit' => 100, 'discount' => 0.1],
                ['item' => 'ب', 'qty' => 1, 'unit' => 100, 'discount' => 0.5],
            ],
        ]], null, ['summary_discount' => 0.6]));
        $this->assertStringContainsString('النسبة قد تكون مخزنة كسراً', implode("\n", $parsed['warnings']));
        // الحساب لم يتغير: 0.1% و0.5%
        $this->assertSame([0.1, 0.5], array_column($parsed['daily']['invoices']['ع G: 1']['lines'], 'discount_amount'));
    }

    public function test_old_sample_without_discount_is_unchanged_and_hashes_are_stable(): void
    {
        $parsed = $this->parse($this->fixturePath());
        $this->assertFalse($parsed['daily']['has_discount']);
        foreach ($parsed['daily']['invoices'] as $invoice) {
            foreach ($invoice['lines'] as $line) {
                $this->assertNull($line['discount_percent']);
                $this->assertSame($line['line_total'], $line['line_net']);
            }
            $this->assertSame(round(array_sum(array_column($invoice['lines'], 'line_total')), 2), $invoice['total']);
        }

        // البصمة بلا حقول الحسم = البصمة نفسها قبل المرحلة
        $first = reset($parsed['daily']['invoices']);
        $withoutDiscountKeys = array_map(fn ($l) => array_diff_key($l, array_flip(['discount_percent', 'discount_amount', 'line_net'])), $first['lines']);
        $this->assertSame(
            \App\Support\InvoiceFingerprint::hash($first['customer_name'], $first['invoice_date'], $first['delivery_date'], $first['delivery_order_no'], $first['cost_center_code'], $first['cost_center_name'], $withoutDiscountKeys),
            \App\Support\InvoiceFingerprint::hash($first['customer_name'], $first['invoice_date'], $first['delivery_date'], $first['delivery_order_no'], $first['cost_center_code'], $first['cost_center_name'], $first['lines']),
        );
    }

    public function test_without_entered_by_and_additions_and_with_gift_discount_marks_net_uncertain(): void
    {
        $path = $this->buildDiscountWorkbook([[
            'number' => 'ع G: 5', 'customer' => 'صيدلية', 'lines' => [
                ['item' => 'أ', 'qty' => 2, 'unit' => 50, 'discount' => 10, 'gift_discount' => 3],
            ],
        ], [
            'number' => 'ع G: 6', 'customer' => 'صيدلية أخرى', 'lines' => [['item' => 'ب', 'qty' => 1, 'unit' => 40, 'additions' => 2]],
        ]], null, ['gift_discount' => true, 'additions' => true, 'summary_additions' => 2]);

        $parsed = $this->parse($path);
        $this->assertTrue($parsed['daily']['invoices']['ع G: 5']['net_uncertain']);
        $this->assertTrue($parsed['daily']['invoices']['ع G: 6']['net_uncertain']);
        $warnings = implode("\n", $parsed['warnings']);
        $this->assertStringContainsString('حسم هدايا', $warnings);
        $this->assertStringContainsString('"الإضافات" في الجدول الملخص غير صفري', $warnings);

        $this->importFiles($path);
        $this->assertTrue(Invoice::where('invoice_number', 'ع G: 5')->sole()->net_uncertain);
        $this->assertEquals(90.0, (float) Invoice::where('invoice_number', 'ع G: 5')->value('invoice_amount'));
    }

    public function test_header_variant_sier_al_ijmali_is_read(): void
    {
        $parsed = $this->parse($this->buildDiscountWorkbook([$this->invoice13722()], null, ['header_total' => 'سعر الإجمالي']));
        $this->assertSame(2945.75, $parsed['daily']['invoices']['ع G: 13722']['total']);
        $this->assertSame(3095.0, $parsed['daily']['lines_total']);
    }

    public function test_summary_checks_pass_and_fail_clearly(): void
    {
        $this->assertSame(149.25, $this->parse($this->buildDiscountWorkbook([$this->invoice13722()]))['daily']['discount_total']);

        try {
            $this->parse($this->buildDiscountWorkbook([$this->invoice13722()], null, ['summary_discount' => 150.00]));
            $this->fail('كان يجب أن يُرفض');
        } catch (ImportException $e) {
            $this->assertStringContainsString('لا يطابق عمود "الحسم"', $e->getMessage());
        }
        try {
            $this->parse($this->buildDiscountWorkbook([$this->invoice13722()], null, ['summary_total' => 2945.75]));
            $this->fail('كان يجب أن يُرفض');
        } catch (ImportException $e) {
            $this->assertStringContainsString('لا يطابق صف "الإجمالي"', $e->getMessage());
        }

        // الملفات القديمة بلا عمودي الحسم والإضافات تُتحقق كما كانت
        $this->assertNotNull($this->parse($this->buildWorkbook([['number' => 'ع G: 1', 'customer' => 'ص', 'lines' => [['صنف', 100]]]]))['daily']);
    }

    public function test_robustness_text_quantity_invalid_orders_notes_and_single_sheet(): void
    {
        $path = $this->buildDiscountWorkbook([
            ['number' => 'ع G: 10', 'customer' => 'صيدلية 1', 'note' => 0, 'lines' => [
                ['item' => 'هدية', 'qty' => '0', 'unit' => 0, 'gift' => 10, 'total' => 0],
                ['item' => 'صنف', 'qty' => '1,020.50', 'unit' => 1, 'total' => 1020.5],
            ]],
            ['number' => 'ع G: 11', 'customer' => 'صيدلية 2', 'order' => '840*', 'note' => '***', 'lines' => [['item' => 'أ', 'qty' => 1, 'unit' => 5]]],
            ['number' => 'ع G: 12', 'customer' => 'صيدلية 3', 'order' => '0', 'note' => '   ', 'lines' => [['item' => 'ب', 'qty' => 1, 'unit' => 6]]],
        ]);

        $parsed = $this->parse($path);
        $this->assertNull($parsed['daily']['invoices']['ع G: 10']['invoice_note']);
        $this->assertSame('***', $parsed['daily']['invoices']['ع G: 11']['invoice_note']);
        $this->assertNull($parsed['daily']['invoices']['ع G: 12']['invoice_note']);
        $this->assertSame(1, $parsed['daily']['star_notes']);
        $this->assertNull($parsed['daily']['invoices']['ع G: 11']['delivery_order_no']);
        $this->assertStringContainsString('«840*» غير صالح', implode("\n", $parsed['warnings']));
        $this->assertSame(0.0, $parsed['daily']['invoices']['ع G: 10']['lines'][0]['quantity']);
        $this->assertNull($parsed['balances']);   // صفحة واحدة

        $this->importFiles($path);
        $this->assertSame(Invoice::UNASSIGNED, Invoice::where('invoice_number', 'ع G: 11')->value('placement'));
        $this->assertSame(Invoice::UNASSIGNED, Invoice::where('invoice_number', 'ع G: 12')->value('placement'));
    }

    public function test_reupload_of_published_invoice_with_discount_creates_proposal(): void
    {
        $plain = ['number' => 'ع G: 13722', 'customer' => 'صيدلية الحسم', 'lines' => array_map(
            fn ($l) => ['item' => $l['item'], 'qty' => $l['qty'], 'unit' => $l['unit'], 'gift' => $l['gift'] ?? 0],
            $this->invoice13722()['lines'],
        )];
        $sheet = $this->publishWorkbook($this->buildDiscountWorkbook([$plain], null, ['no_discount_column' => true]));
        $invoice = Invoice::where('invoice_number', 'ع G: 13722')->sole();
        $this->assertEquals(3095.0, (float) $invoice->invoice_amount);

        // الملف نفسه بصيغة جديدة بلا حسم فعلي: لا اقتراح (البصمة كما هي)
        $this->importFiles($this->buildDiscountWorkbook([$plain]));
        $this->assertSame(0, InvoiceChangeProposal::count());

        $this->importFiles($this->buildDiscountWorkbook([$this->invoice13722()]));
        $this->assertSame(1, InvoiceChangeProposal::where('invoice_id', $invoice->id)->count());
        $this->assertEquals(3095.0, (float) $invoice->fresh()->invoice_amount);   // لا تعديل صامت
        $this->assertSame($sheet->id, $invoice->fresh()->distribution_sheet_id);
    }

    /**
     * اختبار القبول على الملف الحقيقي (A8) إن وضعه صاحب المشروع في tests/Support/Workbooks/daily_movement_discount.xlsx.
     */
    public function test_acceptance_on_real_daily_movement_file(): void
    {
        $path = base_path('tests/Support/Workbooks/daily_movement_discount.xlsx');
        if (! is_file($path)) {
            $this->markTestSkipped('الملف الحقيقي daily_movement_discount.xlsx غير موجود؛ الحالات مغطاة بملفات مبنية في هذا الصنف.');
        }

        $parsed = $this->parse($path);
        $daily = $parsed['daily'];
        $this->assertCount(211, $daily['invoices']);
        $this->assertSame(519, $daily['lines_count']);
        $this->assertSame(392134.95, $daily['lines_total']);
        $this->assertSame(1349.16, $daily['discount_total']);
        $this->assertSame(390785.79, $daily['net_total']);
        $discounted = collect($daily['invoices'])->flatMap(fn ($i) => $i['lines'])->filter(fn ($l) => $l['discount_amount'] > 0);
        $this->assertCount(14, $discounted);
        $this->assertSame(2945.75, $daily['invoices']['ع G: 13722']['total'] ?? collect($daily['invoices'])->first(fn ($i) => str_contains($i['invoice_number'], '13722'))['total']);
        $this->assertNull($parsed['balances']);

        $user = User::factory()->role(Role::SheetManager)->create();
        $this->importFiles($path, '2026-10-17', $user);
        $this->assertSame(73, Invoice::where('placement', Invoice::DEFERRED)->count());
        foreach (['3956', '3957', '3969', '3955', '3977'] as $order) {
            $this->assertArrayHasKey($order, $daily['delivery_orders']);
        }
        // الأمران غير الصالحين (0 و840*): بلا أمر مع تحذير يذكر القيمة. في الملف الحقيقي تسليم الفاتورتين بعد 17-10
        // (13794 في 24-10 و13824 في 18-10)، فهما مؤجلتان ضمن الـ73 بحسب قاعدة التأجيل القائمة، لا «بلا كشف» في هذا التاريخ.
        $warnings = implode("\n", $parsed['warnings']);
        foreach (['ع G: 13794' => '«0»', 'ع G: 13824' => '«840*»'] as $number => $raw) {
            $this->assertNull($daily['invoices'][$number]['delivery_order_no']);
            $this->assertStringContainsString("الفاتورة {$number}: رقم أمر التسليم {$raw} غير صالح", $warnings);
            $this->assertSame(Invoice::DEFERRED, Invoice::where('invoice_number', $number)->value('placement'));
            $this->assertNull(Invoice::where('invoice_number', $number)->value('distribution_sheet_id'));
        }
        // وعند رفع الملف بتاريخ تسليمهما يصيران «بلا كشف» (لا أمر تسليم)
        $this->importFiles($path, '2026-10-24', $user);
        $this->assertSame(Invoice::UNASSIGNED, Invoice::where('invoice_number', 'ع G: 13794')->value('placement'));
        $this->assertSame('***', Invoice::where('invoice_number', 'like', '%13823%')->value('invoice_note'));
        $this->assertGreaterThan(0, InvoiceLine::count());
    }
}
