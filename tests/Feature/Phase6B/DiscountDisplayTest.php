<?php

namespace Tests\Feature\Phase6B;

use App\Enums\Role;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 6B-A4: عرض الحسم (الجزئية المشتركة، /invoices/{invoice}/lines، endpoint الموزع).
 */
class DiscountDisplayTest extends TestCase
{
    use RefreshDatabase, Workbooks;

    private User $admin;
    private User $d1;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->d1 = User::factory()->role(Role::Distributor)->create();
        $supervisor = User::factory()->role(Role::Supervisor)->create();

        $this->sheet = $this->publishWorkbook($this->buildDiscountWorkbook([
            $this->invoice13722(),
            ['number' => 'ع G: 500', 'customer' => 'صيدلية بلا حسم', 'lines' => [['item' => 'أ', 'qty' => 2, 'unit' => 50]]],
            ['number' => 'ع G: 501', 'customer' => 'صيدلية الحسم', 'delivery' => '20-10-2026', 'lines' => [['item' => 'مؤجل', 'qty' => 1, 'unit' => 777]]],
        ], [
            ['صيدلية الحسم', 'كتلة', 'منطقة', 98765.43, 0],
            ['صيدلية بلا حسم', 'كتلة', 'منطقة', 100, 0],
        ]));
        $this->actingAs($supervisor)->put(route('supervisor.sheet.assign', $this->sheet), ['all_distributor_id' => $this->d1->id])->assertSessionHasNoErrors();
    }

    private $sheet;

    public function test_partial_shows_discount_column_and_footer_only_when_discounted(): void
    {
        $discounted = Invoice::where('invoice_number', 'ع G: 13722')->sole();
        $html = $this->actingAs($this->admin)->get(route('invoices.lines', $discounted))->assertOk()->getContent();
        $this->assertStringContainsString('الحسم %', $html);
        $this->assertStringContainsString('المجموع قبل الحسم', $html);
        $this->assertStringContainsString('حسم الأقلام', $html);
        $this->assertMatchesRegularExpression('/lines-net-total">2,945\.75</', $html);
        $this->assertMatchesRegularExpression('/lines-discount-total">149\.25</', $html);
        $this->assertStringContainsString('10%', $html);
        $this->assertStringNotContainsString('مجموع الأسطر لا يطابق', $html);

        $plain = Invoice::where('invoice_number', 'ع G: 500')->sole();
        $html = $this->actingAs($this->admin)->get(route('invoices.lines', $plain))->assertOk()->getContent();
        $this->assertStringNotContainsString('الحسم %', $html);
        $this->assertStringNotContainsString('حسم الأقلام', $html);
        $this->assertStringContainsString('مجموع الأسطر', $html);

        $json = $this->actingAs($this->admin)->getJson(route('invoices.lines', $discounted))->assertOk();
        $json->assertJsonPath('has_discount', true)->assertJsonPath('net_total', 2945.75)->assertJsonPath('discount_total', 149.25)->assertJsonPath('gross_total', 3095);
        $json->assertJsonPath('lines.0.discount_percent', 10)->assertJsonPath('lines.0.discount_amount', 30.6)->assertJsonPath('lines.0.line_total', 306);
    }

    public function test_distributor_endpoint_returns_discount_fields_without_deferred_or_raw_balance(): void
    {
        $response = $this->actingAs($this->d1)->getJson(route('distributor.invoice-lines', $this->sheet))->assertOk();
        $response->assertJsonPath('schema', 2);
        $invoice = collect($response->json('invoices'))->firstWhere('number', 'ع G: 13722');
        $this->assertTrue($invoice['has_discount']);
        $this->assertEquals(2945.75, $invoice['lines_total']);
        $this->assertEquals(2945.75, $invoice['total']);
        $this->assertEquals(149.25, $invoice['discount_total']);
        $this->assertEquals(3095, $invoice['gross_total']);
        $this->assertFalse($invoice['mismatch']);
        $this->assertEquals(16, $invoice['lines'][3]['discount_percent']);

        $merged = collect($response->json('merged'))->firstWhere('customer_name', 'صيدلية الحسم');
        $this->assertEquals(2945.75, $merged['lines_total']);
        $this->assertEquals(149.25, $merged['discount_total']);
        $this->assertTrue($merged['has_discount']);
        $this->assertFalse($merged['mismatch']);

        $json = $response->getContent();
        $this->assertStringNotContainsString('ع G: 501', $json);
        $this->assertStringNotContainsString('98765', $json);
    }
}
