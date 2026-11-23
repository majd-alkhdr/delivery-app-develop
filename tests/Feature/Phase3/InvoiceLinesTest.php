<?php

namespace Tests\Feature\Phase3;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Services\CustomerBalanceService;
use App\Services\CustomerMergedInvoiceBuilder;
use App\Support\ArabicNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * J) تفاصيل الفواتير للموزع (endpoint + ETag)، الفاتورة المجمّعة، وendpoint الإدارة.
 */
class InvoiceLinesTest extends TestCase
{
    use RefreshDatabase, Workbooks;

    private User $supervisor;
    private User $d1;
    private User $d2;
    private DistributionSheet $sheet;
    private Customer $north;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->supervisor = User::factory()->role(Role::Supervisor)->create();
        $this->d1 = User::factory()->role(Role::Distributor)->create();
        $this->d2 = User::factory()->role(Role::Distributor)->create();

        $this->sheet = $this->publishWorkbook($this->buildWorkbook([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية الشمال', 'lines' => [['بانادول', 100], ['فيتامين سي', 50]]],
            ['number' => 'ع A: 2', 'customer' => 'صيدلية الشمال', 'lines' => [['بانادول', 100], ['شراب سعال', 30]]],
            ['number' => 'ع B: 3', 'customer' => 'صيدلية الشمال', 'lines' => [['بانادول', 120], ['شراب سعال', 0]]],
            ['number' => 'ع A: 4', 'customer' => 'صيدلية الشمال', 'delivery' => '20-10-2026', 'lines' => [['صنف مؤجل سري', 4321]]],
            ['number' => 'ع G: 5', 'customer' => 'صيدلية الجنوب', 'lines' => [['صنف جنوبي خاص', 2222]]],
            ['number' => 'ع m: 6', 'customer' => 'صيدلية الوسط', 'lines' => [['حليب أطفال', 70]]],
        ], [
            ['صيدلية الشمال', 'كتلة', 'منطقة الشمال', 98765.43, 0],
            ['صيدلية الجنوب', 'كتلة', 'منطقة الجنوب', 5000, 0],
            ['صيدلية الوسط', 'كتلة', 'منطقة الشمال', 900, 0],
        ]));

        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $this->sheet), ['rows' => [
            ['area_key' => ArabicNormalizer::normalize('منطقة الشمال'), 'distributor_id' => $this->d1->id],
            ['area_key' => ArabicNormalizer::normalize('منطقة الجنوب'), 'distributor_id' => $this->d2->id],
        ]])->assertSessionHasNoErrors();

        $this->north = Customer::where('name', 'صيدلية الشمال')->firstOrFail();
    }

    private function lines(User $user, array $headers = [])
    {
        return $this->actingAs($user)->withHeaders($headers)->getJson(route('distributor.invoice-lines', $this->sheet));
    }

    public function test_endpoint_returns_only_visible_invoices_with_prices_and_cost_center(): void
    {
        $response = $this->lines($this->d1)->assertOk()->assertHeader('ETag');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));

        $numbers = collect($response->json('invoices'))->pluck('number')->sort()->values()->all();
        $this->assertSame(['ع A: 2', 'ع B: 3', 'ع G: 1', 'ع m: 6'], $numbers);

        $first = collect($response->json('invoices'))->firstWhere('number', 'ع G: 1');
        $this->assertSame('كول سنتر', $first['cost_center_name']);
        $this->assertEquals(100, $first['lines'][0]['unit_price']);
        $this->assertSame($this->north->id, $first['customer_id']);

        // ETag (بصمة sha1) قد يحوي هذه الأرقام صدفة: يُستبعد قبل الفحص
        $content = preg_replace('/"etag":"(?:[^"\\\\]|\\\\.)*"/', '', $response->getContent());
        $this->assertStringNotContainsString('"etag"', $content);
        foreach (['صنف مؤجل سري', '4321', 'ع A: 4', 'صنف جنوبي خاص', '2222', '98765'] as $secret) {
            $this->assertStringNotContainsString($secret, $content, "تسرّب: {$secret}");
        }
    }

    public function test_other_distributor_preparing_and_draft_sheets_are_forbidden(): void
    {
        $stranger = User::factory()->role(Role::Distributor)->create();
        $this->lines($stranger)->assertForbidden();

        $this->sheet->update(['status' => 'preparing']);
        $this->lines($this->d1)->assertForbidden();

        $this->sheet->update(['status' => 'draft']);
        $this->lines($this->d1)->assertForbidden();

        $this->actingAs($this->supervisor)->getJson(route('distributor.invoice-lines', $this->sheet))->assertForbidden();
    }

    public function test_sheet_html_does_not_embed_item_lines(): void
    {
        $html = $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->assertOk()->getContent();

        $this->assertStringContainsString('ع G: 1', $html);
        foreach (['بانادول', 'فيتامين سي', 'شراب سعال', 'حليب أطفال'] as $item) {
            $this->assertStringNotContainsString($item, $html, "أسطر في HTML: {$item}");
        }
        $this->assertStringContainsString('data-print-customer="'.$this->north->id.'"', $html);
        $this->assertStringContainsString('data-print-invoice', $html);
        $this->assertStringContainsString('قريباً', $html);   // الطباعة معطّلة حتى المرحلة 4
    }

    public function test_merged_invoice_combines_lines_by_item_price_and_gift(): void
    {
        $merged = collect($this->lines($this->d1)->json('merged'))->firstWhere('customer_id', $this->north->id);

        $this->assertCount(3, $merged['invoice_ids']);
        $this->assertSame(['g-1', 'a-2', 'b-3'], $merged['invoice_labels']);

        $lines = collect($merged['lines']);
        // بانادول بنفس السعر في فاتورتين ⇒ سطر واحد بكمية مجموعة؛ وبسعر آخر ⇒ سطر منفصل
        $panadol100 = $lines->where('item_name', 'بانادول')->firstWhere('unit_price', 100);
        $this->assertEquals(2, $panadol100['quantity']);
        $this->assertEquals(200, $panadol100['line_total']);
        $this->assertCount(2, $lines->where('item_name', 'بانادول'));
        // هدية وصنف مدفوع بنفس الاسم ⇒ سطران
        $this->assertCount(2, $lines->where('item_name', 'شراب سعال'));
        $this->assertTrue($lines->where('item_name', 'شراب سعال')->contains('is_gift', true));

        $this->assertEquals(400, $merged['lines_total']);
        $this->assertEquals(400, $merged['source_total']);
        $this->assertFalse($merged['mismatch']);
        $this->assertCount(3, $merged['series_subtotals']);

        // due_balance = المستحق (لا الخام)
        $due = app(CustomerBalanceService::class)->dueBalance($this->north, $this->sheet->distribution_date);
        $this->assertEqualsWithDelta($due, $merged['due_balance'], 0.001);
        $this->assertEqualsWithDelta(94444.43, $merged['due_balance'], 0.001);
        $this->assertStringNotContainsString('98765', json_encode($merged));

        // لا تضم فاتورة مؤجلة ولا فاتورة منطقة غير مسندة
        $deferred = Invoice::where('invoice_number', 'ع A: 4')->first();
        $south = Invoice::where('invoice_number', 'ع G: 5')->first();
        $this->assertNotContains($deferred->id, $merged['invoice_ids']);
        $this->assertNull(collect($this->lines($this->d1)->json('merged'))->firstWhere('customer_id', $south->customer_id));
    }

    public function test_merged_mismatch_single_invoice_and_no_database_writes(): void
    {
        $builder = app(CustomerMergedInvoiceBuilder::class);
        $middle = Customer::where('name', 'صيدلية الوسط')->firstOrFail();

        DB::enableQueryLog();
        $single = $builder->build($this->sheet, $middle->id, $this->d1);
        $writes = collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/^\s*(insert|update|delete)/i', $q['query']));
        DB::disableQueryLog();
        $this->assertCount(0, $writes);   // تخطيط طباعة فقط
        $this->assertCount(1, $single['invoice_ids']);
        $this->assertSame(['m-6'], $single['invoice_labels']);

        // تلاعب بسطر ⇒ mismatch
        InvoiceLine::where('item_name', 'فيتامين سي')->update(['line_total' => 55]);
        $this->assertTrue($builder->build($this->sheet, $this->north->id, $this->d1)['mismatch']);
    }

    public function test_etag_returns_304_and_changes_when_an_invoice_changes(): void
    {
        $first = $this->lines($this->d1)->assertOk();
        $etag = $first->headers->get('ETag');

        $this->lines($this->d1, ['If-None-Match' => $etag])->assertStatus(304);

        $this->travel(5)->seconds();
        Invoice::where('invoice_number', 'ع G: 1')->first()->update(['invoice_note' => 'تعديل']);
        $second = $this->lines($this->d1, ['If-None-Match' => $etag])->assertOk();
        $this->assertNotSame($etag, $second->headers->get('ETag'));
    }

    public function test_admin_lines_endpoint_roles(): void
    {
        $invoice = Invoice::where('invoice_number', 'ع G: 1')->first();
        $deferred = Invoice::where('invoice_number', 'ع A: 4')->first();

        foreach ([Role::Admin, Role::SheetManager, Role::Supervisor, Role::Accountant] as $role) {
            $user = User::factory()->role($role)->create();
            $this->actingAs($user)->get(route('invoices.lines', $invoice))->assertOk()->assertSee('بانادول')->assertSee('مجموع الأسطر');
            $this->actingAs($user)->getJson(route('invoices.lines', $deferred))->assertOk()->assertJsonPath('lines.0.item_name', 'صنف مؤجل سري');
        }
        $this->actingAs($this->d1)->get(route('invoices.lines', $invoice))->assertForbidden();

        // المشرف والمحاسب: لا فواتير كشف قيد التجهيز
        $this->sheet->update(['status' => 'preparing']);
        $this->actingAs($this->supervisor)->get(route('invoices.lines', $invoice))->assertNotFound();
        $this->actingAs(User::factory()->role(Role::SheetManager)->create())->get(route('invoices.lines', $invoice))->assertOk();
    }

    public function test_deferred_page_and_sheet_table_use_the_shared_partial(): void
    {
        $this->actingAs($this->supervisor)->get(route('invoices.deferred'))->assertOk()->assertSee('صنف مؤجل سري')->assertSee('مجموع الأسطر');
        $this->actingAs($this->supervisor)->get(route('supervisor.sheet.show', $this->sheet->id))
            ->assertOk()
            ->assertSee(route('invoices.lines', Invoice::where('invoice_number', 'ع G: 1')->first()));
    }
}
