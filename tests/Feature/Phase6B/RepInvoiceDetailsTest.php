<?php

namespace Tests\Feature\Phase6B;

use App\Enums\Role;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * PHASE_6B_FIX_REP_LINES: تفاصيل الفاتورة في كشوف المندوب (النافذة نفسها التي عند الموزع، قراءة فقط).
 */
class RepInvoiceDetailsTest extends TestCase
{
    use RefreshDatabase, Workbooks;

    private User $rep1;
    private User $rep2;
    private DistributionSheet $sheet;
    private DistributionSheet $otherSheet;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->rep1 = User::factory()->role(Role::Rep)->create(['cost_center_code' => '655231', 'cost_center_label' => '655231-كرم الخالدي']);
        $this->rep2 = User::factory()->role(Role::Rep)->create(['cost_center_code' => '700700', 'cost_center_label' => '700700-سامر']);

        $this->sheet = $this->publishWorkbook($this->buildDiscountWorkbook([
            $this->invoice13722(['cc' => '655231-كرم الخالدي', 'note' => 'تسليم صباحاً']),
            ['number' => 'ع G: 500', 'customer' => 'صيدلية الحسم', 'cc' => '655231-كرم الخالدي', 'lines' => [['item' => 'عادي', 'qty' => 2, 'unit' => 50]]],
            ['number' => 'ع G: 600', 'customer' => 'صيدلية الحسم', 'cc' => '700700-سامر', 'lines' => [['item' => 'سري لسامر', 'qty' => 1, 'unit' => 77]]],
            ['number' => 'ع G: 700', 'customer' => 'صيدلية الحسم', 'cc' => '655231-كرم الخالدي', 'delivery' => '20-10-2026', 'lines' => [['item' => 'مؤجل سري', 'qty' => 1, 'unit' => 999]]],
        ], [['صيدلية الحسم', 'كتلة', 'منطقة', 98765.43, 0]]));
        $this->otherSheet = $this->publishWorkbook($this->buildDiscountWorkbook([
            ['number' => 'ع G: 800', 'customer' => 'صيدلية أخرى', 'cc' => '700700-سامر', 'lines' => [['item' => 'ب', 'qty' => 1, 'unit' => 10]]],
        ]), '2026-10-14');
    }

    public function test_page_has_clickable_element_per_invoice_and_no_item_lines_in_html(): void
    {
        $html = $this->actingAs($this->rep1)->get(route('rep.sheets.show', $this->sheet))->assertOk()->getContent();

        foreach (['ع G: 13722', 'ع G: 500'] as $number) {
            $id = Invoice::where('invoice_number', $number)->value('id');
            $this->assertStringContainsString('data-rep-invoice-id="'.$id.'"', $html);
        }
        $this->assertStringNotContainsString('data-rep-invoice-id="'.Invoice::where('invoice_number', 'ع G: 600')->value('id').'"', $html);
        $this->assertStringContainsString('data-rep-lines-url="'.route('rep.sheets.lines', $this->sheet).'"', $html);
        $this->assertStringContainsString('id="invoiceModal"', $html);
        $this->assertStringContainsString('modal-fullscreen-sm-down', $html);
        $this->assertStringContainsString('js/rep/rep-sheet.js', $html);

        // لا أسطر أصناف في HTML، ولا طباعة ولا دفع ولا زيارة
        foreach (['صنف 1', 'صنف 6', 'سري لسامر', 'مؤجل سري', 'invoicePrintBtn', 'طباعة', 'data-print-', 'data-open-payment', 'data-open-visit', 'data-open-rep-payment', 'print-service'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent);
        }
    }

    public function test_endpoint_returns_discount_fields_and_footer_for_13722(): void
    {
        $response = $this->actingAs($this->rep1)->getJson(route('rep.sheets.lines', $this->sheet))->assertOk();
        $invoices = collect($response->json('invoices'))->keyBy('number');

        $this->assertEqualsCanonicalizing(['ع G: 13722', 'ع G: 500'], $invoices->keys()->all());
        $inv = $invoices['ع G: 13722'];
        $this->assertTrue($inv['has_discount']);
        $this->assertEquals(3095, $inv['gross_total']);
        $this->assertEquals(149.25, $inv['discount_total']);
        $this->assertEquals(2945.75, $inv['lines_total']);
        $this->assertEquals(2945.75, $inv['total']);
        $this->assertFalse($inv['mismatch']);
        $this->assertEquals([10, 10, 0, 16, 0, 3], array_map(fn ($l) => $l['discount_percent'], $inv['lines']));
        $this->assertSame('655231-كرم الخالدي', $inv['cost_center']);
        $this->assertSame('تسليم صباحاً', $inv['invoice_note']);
        $this->assertSame('2026-10-13', $inv['delivery_date']);
        $this->assertFalse($invoices['ع G: 500']['has_discount']);

        $json = $response->getContent();
        foreach (['سري لسامر', 'مؤجل سري', 'ع G: 600', 'ع G: 700', '98765'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, $secret);
        }
    }

    public function test_other_reps_invoices_and_disallowed_requests_are_blocked(): void
    {
        // rep2 يرى فاتورته وحدها في الكشف نفسه
        $numbers = collect($this->actingAs($this->rep2)->getJson(route('rep.sheets.lines', $this->sheet))->assertOk()->json('invoices'))->pluck('number')->all();
        $this->assertSame(['ع G: 600'], $numbers);

        // كشف ليس للمندوب فيه فاتورة ⇒ 404 (تعديل المعرّف يدوياً لا يكشف شيئاً)
        $this->actingAs($this->rep1)->getJson(route('rep.sheets.lines', $this->otherSheet))->assertNotFound();
        $this->actingAs($this->rep1)->getJson('/rep/sheets/999999/lines')->assertNotFound();
        // مسار أسطر الفاتورة الإداري ممنوع على المندوب
        $this->actingAs($this->rep1)->getJson(route('invoices.lines', Invoice::where('invoice_number', 'ع G: 600')->value('id')))->assertForbidden();
        $this->actingAs($this->rep1)->getJson(route('invoices.lines', Invoice::where('invoice_number', 'ع G: 13722')->value('id')))->assertForbidden();
        // كشف غير منشور
        $this->sheet->update(['status' => 'draft']);
        $this->actingAs($this->rep1)->getJson(route('rep.sheets.lines', $this->sheet))->assertNotFound();
    }

    public function test_distributor_page_still_uses_the_same_modal_with_print_button(): void
    {
        $d = User::factory()->role(Role::Distributor)->create();
        $supervisor = User::factory()->role(Role::Supervisor)->create();
        $this->actingAs($supervisor)->put(route('supervisor.sheet.assign', $this->sheet), ['all_distributor_id' => $d->id])->assertSessionHasNoErrors();

        $html = $this->actingAs($d)->get(route('distributor.sheet', $this->sheet->id))->assertOk()->getContent();
        $this->assertStringContainsString('id="invoiceModal"', $html);
        $this->assertStringContainsString('id="invoicePrintBtn"', $html);
        $this->assertStringContainsString('js/invoice-view.js', $html);
        $this->assertStringContainsString('js/distributor-app.js', $html);
    }
}
