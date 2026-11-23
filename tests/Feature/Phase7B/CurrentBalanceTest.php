<?php

namespace Tests\Feature\Phase7B;

use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 7B (I4–I6): الرصيد المعروض الواحد، اتساقه في كل الشاشات، والإيصال الثابت.
 */
class CurrentBalanceTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity, BalancesFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBalances();
        $this->publish();
    }

    public function test_current_balance_cases_without_double_subtraction(): void
    {
        $this->assertSame(5000.0, $this->net());                     // بلا دفعات

        $payment = $this->pay(900);
        $this->assertSame(4100.0, $this->net());                     // دفعة غير مصدَّرة تُطرح

        $this->exportSheet();
        $this->assertSame('exported', $payment->fresh()->status);
        $this->assertSame(4100.0, $this->net());                     // مصدَّرة قبل ملف الأرصدة: تُطرح

        // ملف أرصدة لاحق انعكست فيه الدفعة (5000 − 900)
        $this->upload($this->balancesFile(['صيدلية التجربة' => 4100] + $this->others()));
        $this->assertNotNull($payment->fresh()->reflected_at);
        $this->assertSame(4100.0, $this->net());                     // لا تُطرح ثانية (كانت حالة الفحص 5000 − 900 مرتين)

        // المرحلة 7H: ملف الأرصدة أنشأ إشعار «تغيّر الرصيد» على الصيدلية (إلزامي): يطّلع الموزع قبل التسديد التالي
        $this->payApi($this->d1, $this->sheet, $this->pharmacy->id, 200)->assertStatus(409);
        $this->actingAs($this->d1)->post(route('distributor.balance-seen', [$this->sheet, $this->pharmacy->id]));

        // دفعة ملغاة لا تُطرح
        $voided = $this->pay(200);
        $this->assertSame(3900.0, $this->net());
        $this->actingAs($this->d1)->postJson(route('payments.void', $voided), ['reason' => 'خطأ إدخال'])->assertSuccessful();
        $this->assertSame(4100.0, $this->net());

        $b = app(\App\Services\CustomerBalanceService::class)->currentBalance($this->pharmacy->fresh(), $this->sheet);
        $this->assertSame('رصيد المحاسبة 4,100 بتاريخ 2026-10-13، مؤجل 0، دفعات لم تنعكس 0 ← 4,100', $b->breakdown());
    }

    public function test_deferred_invoice_is_subtracted(): void
    {
        $this->upload($this->dailyFile(1000, null, '13-10-2026', [
            ['number' => 'ع G: 3', 'customer' => 'صيدلية التجربة', 'cc' => '655231-مندوب أ', 'order' => 1580, 'delivery' => '20-10-2026', 'lines' => [['مؤجل', 300]]],
        ]));
        $this->pay(100);
        $b = app(\App\Services\CustomerBalanceService::class)->currentBalance($this->pharmacy->fresh(), $this->sheet);
        $this->assertSame([5000.0, 300.0, 100.0, 4600.0], [$b->raw, $b->deferred, $b->unreflectedPayments, $b->net]);
    }

    public function test_every_screen_shows_the_same_number(): void
    {
        $payment = $this->pay(900);
        $this->assertSameEverywhere(4100.0, $payment);

        $this->exportSheet();
        $this->upload($this->balancesFile(['صيدلية التجربة' => 4100] + $this->others()));
        $this->assertSameEverywhere(4100.0, $payment);
    }

    private function assertSameEverywhere(float $expected, Payment $payment): void
    {
        $cards = $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->assertOk()->viewData('cards');
        $card = $cards->firstWhere('customer_id', $this->pharmacy->id);
        $merged = collect($this->actingAs($this->d1)->getJson(route('distributor.invoice-lines', $this->sheet))->json('merged'))->firstWhere('customer_id', $this->pharmacy->id);
        $supervisor = $this->actingAs($this->supervisor)->get(route('supervisor.sheet.show', $this->sheet->id))->assertOk()->viewData('balanceBreakdown')[$this->pharmacy->id]->net;
        $rep = $this->actingAs($this->repA)->get(route('rep.sheets.show', $this->sheet))->assertOk()->viewData('pharmacies')->first()['due_balance'];
        $receipt = $this->actingAs($this->d1)->getJson(route('payments.receipt-data', $payment))->json();
        $customers = $this->actingAs($this->supervisor)->get(route('customers.index'))->viewData('due')[$this->pharmacy->id];

        $this->assertSame([$expected, $expected, $expected, $expected, $expected, $expected, $expected],
            [(float) $card['due'], (float) $card['remaining'], (float) $merged['due_balance'], (float) $supervisor, (float) $rep, (float) $receipt['due_after'], (float) $customers]);
    }

    public function test_receipt_keeps_stored_values_and_adds_current_line(): void
    {
        $payment = $this->pay(900);
        $this->assertSame(['5000.00', '4100.00', 'server'], [$payment->balance_before, $payment->balance_after, $payment->balance_source]);

        // تغيّر الرصيد بعد الدفعة (الدفعة لم تُصدَّر فتبقى مطروحة)
        $this->upload($this->balancesFile(['صيدلية التجربة' => 4800] + $this->others()));
        $receipt = $this->actingAs($this->d1)->getJson(route('payments.receipt-data', $payment))->json();
        $this->assertSame([5000.0, 4100.0, 3900.0, false], [(float) $receipt['due_before'], (float) $receipt['due_after'], (float) $receipt['current_balance'], $receipt['computed_at_print']]);

        // دفعة قديمة بلا قيم مخزّنة: «محسوب عند الطباعة»
        $payment->update(['balance_before' => null, 'balance_after' => null]);
        $this->assertTrue($this->actingAs($this->d1)->getJson(route('payments.receipt-data', $payment))->json('computed_at_print'));
    }

    public function test_offline_device_values_are_kept_and_mismatch_logged(): void
    {
        $this->payApi($this->d1, $this->sheet, $this->pharmacy->id, 900, ['device_balance_before' => 5100, 'device_balance_after' => 4200])->assertSuccessful();
        $payment = Payment::latest('id')->first();

        $this->assertSame(['5100.00', '4200.00', 'device'], [$payment->balance_before, $payment->balance_after, $payment->balance_source]);
        $log = \App\Models\ActivityLog::where('action', 'payment.balance_mismatch')->sole();
        $this->assertEquals(5100, $log->old_values['balance_before']);
        $this->assertEquals(5000, $log->new_values['balance_before']);
    }
}
