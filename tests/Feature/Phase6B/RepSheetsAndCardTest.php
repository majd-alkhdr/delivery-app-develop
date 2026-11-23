<?php

namespace Tests\Feature\Phase6B;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Phase6\RepFixture;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 6B-C: كشوف المندوب (قراءة فقط، فواتيره وحده)، و6B-G: مركز الكلفة والبيان في بطاقة الصيدلية.
 */
class RepSheetsAndCardTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity, RepFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReps();
    }

    public function test_rep_sees_only_his_sheets_and_404_elsewhere(): void
    {
        $html = $this->actingAs($this->rep1)->get(route('rep.sheets.index'))->assertOk()->getContent();
        foreach (['#3910', '#3912', '#3913'] as $order) {
            $this->assertStringContainsString($order, $html);
        }
        $this->assertStringContainsString('تُعتمد عند إغلاق الكشف', $html);

        $html = $this->actingAs($this->rep2)->get(route('rep.sheets.index'))->getContent();
        $this->assertStringContainsString('#3910', $html);
        $this->assertStringNotContainsString('#3912', $html);
        $this->actingAs($this->rep2)->get(route('rep.sheets.show', $this->sheetB))->assertNotFound();
        $this->actingAs($this->rep2)->getJson(route('rep.sheets.lines', $this->sheetB))->assertNotFound();

        // قيد التجهيز والمسودة لا يظهران
        $this->sheetC->update(['status' => 'draft']);
        $this->assertStringNotContainsString('#3913', $this->actingAs($this->rep1)->get(route('rep.sheets.index'))->getContent());
        $this->actingAs($this->rep1)->get(route('rep.sheets.show', $this->sheetC))->assertNotFound();
        $this->sheetC->update(['status' => 'preparing']);
        $this->actingAs($this->rep1)->get(route('rep.sheets.show', $this->sheetC))->assertNotFound();
    }

    public function test_other_reps_invoices_deferred_and_raw_balance_never_show(): void
    {
        $this->sheetA->update(['status' => 'in_progress']);
        $html = $this->actingAs($this->rep1)->get(route('rep.sheets.show', $this->sheetA))->assertOk()->getContent();
        $this->assertStringContainsString('G: 11', $html);
        $this->assertStringContainsString('G: 12', $html);
        $this->assertStringContainsString('B: 21', $html);
        $this->assertStringNotContainsString('G: 22', $html);      // مندوب آخر
        $this->assertStringNotContainsString('صيدلية الشرق', $html);
        $this->assertStringNotContainsString('A: 13', $html);      // فاتورة الصيدلية لمركز آخر
        $this->assertStringNotContainsString('G: 15', $html);      // مؤجلة
        $this->assertStringNotContainsString('98,765.43', $html);  // الرصيد الخام
        // المستحق بتاريخ الكشف (10-10) = الخام − المؤجل حينها (G:15 = 700 وG:41 = 999 تسليمها بعده)
        $this->assertStringContainsString('96,666.43', $html);
        $this->assertStringContainsString('رصيد الصيدلية الكلي', $html);
        $this->assertStringNotContainsString('latitude', $html);
        $this->assertStringNotContainsString('payments/', $html);   // لا صور إيصالات
        $this->assertStringNotContainsString('<table class="table table-sm table-bordered', $html);   // لا أسطر أصناف في HTML

        $json = $this->actingAs($this->rep1)->getJson(route('rep.sheets.lines', $this->sheetA))->assertOk();
        $numbers = collect($json->json('invoices'))->pluck('number')->all();
        $this->assertEqualsCanonicalizing(['ع G: 11', 'ع G: 12', 'ع B: 21'], $numbers);
        $this->assertArrayHasKey('discount_total', $json->json('invoices.0'));
        $this->assertArrayHasKey('lines', $json->json('invoices.0'));
        $this->assertStringNotContainsString('98765', $json->getContent());
    }

    public function test_attributed_amounts_match_engine_preview_and_notes_show_only_for_his_pharmacies(): void
    {
        $this->sheetA->update(['status' => 'in_progress']);
        // الموزع دفع 900 للشمال: بالتناسب 1000:500:300 ⇒ G:11 = 500 وG:12 = 250 (وA:13 = 150 ليست له)
        $p = $this->distributorPay($this->sheetA, $this->north, 900);
        $p->update(['note' => 'الصيدلي طلب الاتصال مساءً — تم الحساب على 139']);
        $this->distributorPay($this->sheetA, $this->east, 10)->update(['note' => 'ملاحظة صيدلية الشرق']);
        \App\Models\SheetVisit::create([
            'client_uuid' => (string) Str::uuid(), 'distribution_sheet_id' => $this->sheetA->id, 'customer_id' => $this->south->id,
            'user_id' => $this->d1->id, 'status' => 'not_visited', 'reason' => 'الصيدلية مغلقة', 'visited_at' => now(), 'location_flag' => 'no_gps',
        ]);

        $html = $this->actingAs($this->rep1)->get(route('rep.sheets.show', $this->sheetA))->getContent();
        $this->assertMatchesRegularExpression('/data-customer-id="'.$this->north->id.'".*?حُصِّل\): <span[^>]*>750\.00<.*?المتبقي من فواتيرك: <span[^>]*>750\.00</su', $html);
        $this->assertStringContainsString('الصيدلي طلب الاتصال مساءً', $html);
        $this->assertStringNotContainsString('تم الحساب على 139', $html);   // لا أسعار صرف
        $this->assertStringNotContainsString('ملاحظة صيدلية الشرق', $html);
        $this->assertStringContainsString('الصيدلية مغلقة', $html);
        $this->assertStringContainsString('الموزع الأول', $html);           // الموزع المسؤول عن الفاتورة

        $list = $this->actingAs($this->rep1)->get(route('rep.sheets.index'))->getContent();
        $this->assertMatchesRegularExpression('/#3910.*?2,300\.00.*?750\.00.*?1,550\.00/su', $list);   // 1000+500+800، حُصِّل 750
        $this->assertSame(0, \App\Models\PaymentAllocation::count());   // معاينة بلا كتابة
    }

    public function test_other_roles_are_forbidden_from_rep_sheets(): void
    {
        foreach ([$this->d1, $this->accountant, $this->admin, $this->supervisor, $this->repSupervisor, $this->manager] as $user) {
            $this->actingAs($user)->get(route('rep.sheets.index'))->assertForbidden();
            $this->actingAs($user)->getJson(route('rep.sheets.lines', $this->sheetA))->assertForbidden();
        }
        $this->actingAs($this->rep1)->get(route('rep.index'))->assertSee(route('rep.sheets.index'), false);
    }

    // ------------------------------------------------------------------
    // G) مركز الكلفة والبيان في بطاقة الصيدلية
    // ------------------------------------------------------------------

    public function test_card_shows_cost_center_and_note_per_invoice_and_hides_empty_or_zero(): void
    {
        $this->sheetA->update(['status' => 'in_progress']);
        Invoice::where('invoice_number', 'ع G: 11')->update(['invoice_note' => 'يرجى التسليم صباحاً']);
        Invoice::where('invoice_number', 'ع A: 13')->update(['invoice_note' => '0']);

        // المشرف (وبقية الإدارة) — الجدول المشترك
        $html = $this->actingAs($this->supervisor)->get(route('supervisor.sheet.show', $this->sheetA->id))->assertOk()->getContent();
        $this->assertStringContainsString('655231-كرم الخالدي', $html);   // فاتورة المندوب باسمه في مركز الكلفة
        $this->assertStringContainsString('201201-كول سنتر', $html);
        $this->assertStringContainsString('يرجى التسليم صباحاً', $html);
        $this->assertStringContainsString('مركز الكلفة والبيان', $html);
        $this->assertDoesNotMatchRegularExpression('/invoice-note[^>]*>\s*<span[^>]*>0</', $html);
        $this->assertStringNotContainsString('صنف — ', $html);   // البيان المركّب السابق أُزيل

        // الموزع
        $html = $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheetA->id))->assertOk()->getContent();
        $this->assertStringContainsString('655231-كرم الخالدي', $html);
        $this->assertStringContainsString('يرجى التسليم صباحاً', $html);
        $this->assertSame(1, substr_count($html, 'class="small invoice-note'));

        // JSON الموزع والكاش: المركز والبيان، و«0» = لا بيان
        $invoices = collect($this->actingAs($this->d1)->getJson(route('distributor.invoice-lines', $this->sheetA))->json('invoices'))->keyBy('number');
        $this->assertSame('655231-كرم الخالدي', $invoices['ع G: 11']['cost_center']);
        $this->assertSame('يرجى التسليم صباحاً', $invoices['ع G: 11']['invoice_note']);
        $this->assertNull($invoices['ع A: 13']['invoice_note']);
        $this->assertArrayNotHasKey('ع G: 15', $invoices->all());

        // المندوب في كشوفه
        $html = $this->actingAs($this->rep1)->get(route('rep.sheets.show', $this->sheetA))->getContent();
        $this->assertStringContainsString('655231-كرم الخالدي', $html);
        $this->assertStringContainsString('يرجى التسليم صباحاً', $html);
        $this->assertSame(0, Payment::where('source', 'rep')->count());
    }
}
