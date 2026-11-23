<?php

namespace Tests\Feature\Phase2B;

use App\Enums\Role;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\User;
use App\Support\ArabicNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * J) الخصوصية بعد 2B: الموزع يرى فواتير كشفه الموضوعة فيه فقط (in_sheet) ومن مناطقه،
 * ولا يرى مؤجلة ولا قيمتها ولا الرصيد الخام، ولا كشفاً قيد التجهيز.
 */
class DistributorVisibilityTest extends TestCase
{
    use RefreshDatabase, Workbooks;

    private User $supervisor;
    private User $d1;
    private DistributionSheet $sheet;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->supervisor = User::factory()->role(Role::Supervisor)->create();
        $this->d1 = User::factory()->role(Role::Distributor)->create();

        $result = $this->importFiles($this->buildWorkbook([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 1500]]],
            ['number' => 'ع A: 2', 'customer' => 'صيدلية ألف', 'delivery' => '18-10-2026', 'lines' => [['صنف', 4321]]],
            ['number' => 'ع B: 3', 'customer' => 'صيدلية ألف', 'order' => 0, 'lines' => [['صنف', 777]]],
        ], [
            ['صيدلية ألف', 'كتلة', 'منطقة الشمال', 98765.43, 0],
        ]));
        $this->sheet = DistributionSheet::where('batch_id', $result['batch_id'])->firstOrFail();

        // الفاتورة ع B: 3 تبقى "بلا كشف" (فتمنع الإرسال الاعتيادي)؛ نحاكي كشفاً منشوراً مباشرة
        $this->sheet->update(['status' => 'pending', 'sent_at' => now()]);

        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $this->sheet), ['rows' => [
            ['area_key' => ArabicNormalizer::normalize('منطقة الشمال'), 'distributor_id' => $this->d1->id],
        ]])->assertSessionHasNoErrors();
    }

    public function test_distributor_sees_only_in_sheet_invoices_of_his_areas(): void
    {
        $visible = $this->sheet->invoicesVisibleTo($this->d1)->pluck('invoice_number')->all();
        $this->assertSame(['ع G: 1'], $visible);

        $html = $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->assertOk()->getContent();
        // أرقام كسر الكاش (?v=filemtime) قد تحوي هذه الأرقام صدفة: تُستبعد قبل الفحص
        $html = preg_replace('/\?v=\d+/', '', $html);
        $this->assertStringContainsString('ع G: 1', $html);
        $this->assertStringContainsString('94,444', $html);     // 98,765.43 − 4,321 (المؤجلة)
        foreach (['ع A: 2', '4,321', '98,765', 'ع B: 3', '777'] as $secret) {
            $this->assertStringNotContainsString($secret, $html, "تسرّب: {$secret}");
        }

        // فاتورة بلا كشف لا يصل إليها الموزع حتى لو كانت في منطقته
        $loose = Invoice::where('invoice_number', 'ع B: 3')->first();
        // المرحلة 3: الزبون نفسه له فاتورة مرئية، لكن الفاتورة بلا كشف لا تظهر ولا تدخل أي مجموع
        $this->assertFalse($this->sheet->invoicesVisibleTo($this->d1)->whereKey($loose->id)->exists());

        $json = $this->actingAs($this->d1)
            ->postJson(route('distributor.payments.store', $this->sheet), ['client_uuid' => (string) \Illuminate\Support\Str::uuid(), 'customer_id' => Invoice::where('invoice_number', 'ع G: 1')->first()->customer_id, 'amount' => 100, 'currency' => 'SYP'])
            ->assertCreated()->getContent();
        foreach (['4321', '98765', '777'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_completed_sheet_is_visible_but_not_writable_for_distributor(): void
    {
        $this->sheet->update(['status' => 'completed']);

        $this->actingAs($this->d1)->get(route('distributor.index'))->assertSee('أمر التسليم #3918');
        $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->assertOk();
        $this->actingAs($this->d1)
            ->postJson(route('distributor.payments.store', $this->sheet), ['client_uuid' => (string) \Illuminate\Support\Str::uuid(), 'customer_id' => Invoice::where('invoice_number', 'ع G: 1')->first()->customer_id, 'amount' => 1, 'currency' => 'SYP'])
            ->assertForbidden();
    }
}
