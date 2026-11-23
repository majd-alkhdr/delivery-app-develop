<?php

namespace Tests\Feature\Phase2;

use App\Enums\Role;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SheetAssignment;
use App\Models\User;
use App\Support\ArabicNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Workbooks;
use Tests\TestCase;

class DistributorPrivacyTest extends TestCase
{
    use RefreshDatabase, Workbooks;

    private User $supervisor;
    private User $d1;
    private User $d2;
    private DistributionSheet $sheet;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->supervisor = User::factory()->role(Role::Supervisor)->create();
        $this->d1 = User::factory()->role(Role::Distributor)->create();
        $this->d2 = User::factory()->role(Role::Distributor)->create();

        $path = $this->buildWorkbook([
            ['number' => 'ع G: 7001', 'customer' => 'صيدلية ألف', 'lines' => [['صنف أ', 1500]]],
            ['number' => 'ع A: 7002', 'customer' => 'صيدلية ألف', 'delivery' => '15-10-2026', 'lines' => [['صنف ب', 4321]]],
            ['number' => 'ع B: 7003', 'customer' => 'صيدلية باء', 'lines' => [['صنف ج', 2222]]],
            ['number' => 'ع C: 7004', 'customer' => 'صيدلية جيم', 'delivery' => '16-10-2026', 'lines' => [['صنف د', 3333]]],
        ], [
            ['صيدلية ألف', 'كتلة الشمال', 'منطقة الشمال', 98765.43, 0],
            ['صيدلية باء', 'كتلة الجنوب', 'منطقة الجنوب', 5000, 0],
            ['صيدلية جيم', 'كتلة الشمال', 'منطقة الشمال', 7777, 0],
        ]);

        // المرحلة 2B: مسؤول الكشوف يرفع ويرسل، والمؤجلة لا تدخل الكشف أصلاً
        $this->sheet = $this->publishWorkbook($path);

        // الموزع الأول: منطقة الشمال فقط — الثاني: منطقة الجنوب فقط
        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $this->sheet), ['rows' => [
            ['area_key' => ArabicNormalizer::normalize('منطقة الشمال'), 'distributor_id' => $this->d1->id],
            ['area_key' => ArabicNormalizer::normalize('منطقة الجنوب'), 'distributor_id' => $this->d2->id],
        ]])->assertSessionHasNoErrors();
    }

    public function test_distributor_never_sees_deferred_invoices_or_raw_balance(): void
    {
        $html = $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->assertOk()->getContent();

        $this->assertStringContainsString('ع G: 7001', $html);
        $this->assertStringContainsString('94,444', $html);              // المستحق = 98,765.43 − 4,321
        $this->assertStringContainsString('إجمالي الفواتير المستحقة', $html);

        foreach (['ع A: 7002', '4,321', '4321', '98,765', '98765', 'ع C: 7004', '3,333', '7,777', 'صيدلية جيم'] as $secret) {
            $this->assertStringNotContainsString($secret, $html, "تسرّب في HTML: {$secret}");
        }

        // الزبون الذي كل فواتيره مؤجلة لا يُحتسب في عدّاد الصيدليات
        $this->assertMatchesRegularExpression('/id="stat-total-pharmacies"\s*>\s*1\s*</', $html);
    }

    public function test_distributor_index_and_json_do_not_leak(): void
    {
        $index = $this->actingAs($this->d1)->get(route('distributor.index'))->assertOk()->getContent();
        foreach (['4,321', '98,765', '7,777', '3,333'] as $secret) {
            $this->assertStringNotContainsString($secret, $index);
        }
        $this->assertStringContainsString('1,500', $index);

        // المرحلة 3: الدفعة عبر payments
        $invoice = Invoice::where('invoice_number', 'ع G: 7001')->first();
        $json = $this->actingAs($this->d1)
            ->postJson(route('distributor.payments.store', $this->sheet), $this->payment($invoice->customer_id, 1000))
            ->assertCreated()
            ->json();
        // المعرّفات العشوائية (UUID ورقم الإيصال) قد تحوي هذه الأرقام صدفة: تُستبعد قبل الفحص
        unset($json['payment']['client_uuid'], $json['payment']['receipt_number'], $json['payment']['receipt_data_url']);
        $json = json_encode($json, JSON_UNESCAPED_UNICODE);
        foreach (['7002', '4321', '4,321', '98765', '98,765'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_distributor_cannot_reach_deferred_invoice_or_other_areas(): void
    {
        // زبون كل فواتيره مؤجلة ليس في الكشف، وزبون منطقة أخرى خارج نطاق الموزع
        $deferredOnly = Invoice::where('invoice_number', 'ع C: 7004')->first();
        $this->actingAs($this->d1)
            ->postJson(route('distributor.payments.store', $this->sheet), $this->payment($deferredOnly->customer_id, 1))
            ->assertForbidden();

        $south = Invoice::where('invoice_number', 'ع B: 7003')->first();
        $this->actingAs($this->d1)
            ->postJson(route('distributor.payments.store', $this->sheet), $this->payment($south->customer_id, 1))
            ->assertForbidden();

        $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->assertDontSee('ع B: 7003');
        $this->actingAs($this->d2)->get(route('distributor.sheet', $this->sheet->id))
            ->assertSee('ع B: 7003')
            ->assertDontSee('ع G: 7001');
    }

    public function test_draft_sheet_is_hidden_from_distributors(): void
    {
        $this->actingAs($this->supervisor)
            ->post(route('supervisor.sheet.draft', $this->sheet), ['reason' => 'رُفع بالخطأ'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->d1)->get(route('distributor.index'))->assertDontSee('أمر التسليم #3918');
        $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->assertForbidden();
        $this->actingAs($this->d1)
            ->postJson(route('distributor.payments.store', $this->sheet), $this->payment(Invoice::where('invoice_number', 'ع G: 7001')->first()->customer_id, 1))
            ->assertForbidden();
    }

    public function test_supervisor_sees_sheet_total_link_to_deferred_and_breakdown(): void
    {
        $this->actingAs($this->supervisor)->get(route('supervisor.sheet.show', $this->sheet->id))
            ->assertOk()
            ->assertSee('إجمالي الكشف')
            ->assertSee('3,722.00')               // 1500 + 2222 (المؤجلة خارج الكشف)
            ->assertDontSee('ع A: 7002')
            ->assertSee(route('invoices.deferred'))
            // المرحلة 7B: شرح الرصيد الواحد (رصيد المحاسبة − المؤجل − الدفعات غير المنعكسة)
            ->assertSee('رصيد المحاسبة 98,765.43', false);

        $this->assertSame('3722.00', $this->sheet->fresh()->total_all);
        $this->assertSame('0.00', $this->sheet->fresh()->total_deferred);

        // الطلبيات المؤجلة في شاشتها المستقلة (قراءة للمشرف)
        $this->actingAs($this->supervisor)->get(route('invoices.deferred'))
            ->assertOk()->assertSee('ع A: 7002')->assertSee('ع C: 7004')->assertSee('4,321.00');
    }

    public function test_deferred_invoices_have_no_sheet(): void
    {
        foreach (['ع A: 7002', 'ع C: 7004'] as $number) {
            $invoice = Invoice::where('invoice_number', $number)->first();
            $this->assertNull($invoice->distribution_sheet_id);
            $this->assertSame(Invoice::DEFERRED, $invoice->placement);
            $this->assertTrue($invoice->is_deferred);
        }
        $this->actingAs($this->d1)->get(route('invoices.deferred'))->assertForbidden();
    }

    public function test_external_payment_on_v2_is_saved_without_touching_excel(): void
    {
        $before = Storage::disk('local')->get($this->sheet->stored_path);
        // زبون كل فواتيره مؤجلة: لا بطاقة له في الكشف، لكنه يقبل دفعة خارجية
        $customer = Invoice::where('invoice_number', 'ع C: 7004')->first()->customer;

        $this->actingAs($this->d1)
            ->postJson(route('distributor.payments.external'), $this->payment($customer->id, 500))
            ->assertCreated()
            ->assertJson(['success' => true]);

        $this->assertSame($before, Storage::disk('local')->get($this->sheet->stored_path));
        $payment = Payment::firstOrFail();
        $this->assertSame('external', $payment->source);
        $this->assertSame($this->sheet->id, $payment->distribution_sheet_id);
        $this->assertSame(0, Invoice::where('invoice_pattern', 'external')->count());   // لا فاتورة بنمط external

        // الموزع الثاني (الجنوب) لا يصل إلى زبون الشمال
        $this->actingAs($this->d2)->postJson(route('distributor.payments.external'), $this->payment($customer->id, 5))->assertForbidden();
    }

    private function payment(int $customerId, float $amount): array
    {
        return ['client_uuid' => (string) \Illuminate\Support\Str::uuid(), 'customer_id' => $customerId, 'amount' => $amount, 'currency' => 'SYP'];
    }
}
