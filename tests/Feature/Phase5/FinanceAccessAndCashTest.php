<?php

namespace Tests\Feature\Phase5;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\CashCount;
use App\Models\FinanceExport;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 5-B/C/D: الصلاحيات، مطابقة الصندوق، وسعر الدولار (إلزامي للجديد، والقديم يضبطه المحاسب).
 */
class FinanceAccessAndCashTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity, FinanceFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinance();
    }

    public function test_accountant_and_admin_export_rep_supervisor_views_only_and_supervisor_has_no_finance(): void
    {
        $payment = $this->pay($this->north, 1500);

        foreach ([$this->accountant, $this->admin, $this->repSupervisor] as $user) {
            $this->actingAs($user)->get(route('finance.sheets.show', $this->sheet))->assertOk()->assertSee('المقبوضات');
        }
        $this->actingAs($this->repSupervisor)->get(route('finance.sheets.show', $this->sheet))
            ->assertSee('عرض فقط')
            ->assertDontSee(route('finance.sheets.export', $this->sheet), false)
            ->assertDontSee(route('finance.cash-counts.store', $this->sheet), false);

        $signature = $this->signature();
        $this->actingAs($this->repSupervisor)->post(route('finance.sheets.export', $this->sheet), ['signature' => $signature, 'confirm' => 1])->assertForbidden();
        $this->actingAs($this->repSupervisor)->post(route('finance.cash-counts.store', $this->sheet), ['distributor_id' => $this->d1->id, 'currency' => 'SYP', 'amount' => 1])->assertForbidden();
        $this->actingAs($this->repSupervisor)->post(route('finance.sheets.surplus', $this->sheet), ['customer_id' => $this->north->id, 'cost_center' => '201201-كول سنتر'])->assertForbidden();
        $this->actingAs($this->repSupervisor)->post(route('finance.payments.rate', $payment), ['exchange_rate' => 139])->assertForbidden();
        $this->assertSame(0, FinanceExport::count());

        $this->exportAs($this->admin)->assertSessionHasNoErrors();
        $export = FinanceExport::sole();
        $this->actingAs($this->repSupervisor)->get(route('finance.exports.download', [$export, 'import']))->assertForbidden();
        $this->actingAs($this->accountant)->get(route('finance.exports.download', [$export, 'import']))->assertOk();
    }

    public function test_sheet_manager_distributor_and_rep_are_forbidden(): void
    {
        $rep = User::factory()->role(Role::Rep)->create();
        foreach ([$this->manager, $this->d1, $rep] as $user) {
            $this->actingAs($user)->getJson(route('finance.sheets.show', $this->sheet))->assertForbidden();
            $this->actingAs($user)->getJson(route('finance.unpaid'))->assertForbidden();
            $this->actingAs($user)->getJson(route('finance.unpaid.export'))->assertForbidden();
            $this->actingAs($user)->postJson(route('finance.sheets.export', $this->sheet), ['signature' => 'x', 'confirm' => 1])->assertForbidden();
        }
    }

    public function test_finance_events_are_visible_to_supervisor_in_activity_log(): void
    {
        // المرحلة 8C: الرؤية بمنفّذ الإجراء: تصدير المحاسب يراه المحاسب نفسه، ومشرف التوزيع (نفسه + الموزعون) لم يعد يراه
        $this->pay($this->north, 1500);
        $this->exportAs($this->accountant)->assertSessionHasNoErrors();

        $this->actingAs($this->accountant)->get(route('activity.index'))->assertOk()->assertSee('تصدير للمالية');
        $this->actingAs($this->supervisor)->get(route('activity.index'))->assertOk()->assertDontSee('تصدير للمالية');
    }

    // ------------------------------------------------------------------
    // مطابقة الصندوق
    // ------------------------------------------------------------------

    public function test_cash_count_records_updates_and_shows_difference(): void
    {
        $this->pay($this->north, 1500);
        $this->pay($this->north, 500);
        $this->pay($this->north, 20, 'USD', 139);
        $voided = $this->pay($this->north, 999);
        $voided->update(['voided_at' => now(), 'void_reason' => 'خطأ']);

        $this->actingAs($this->accountant)->post(route('finance.cash-counts.store', $this->sheet), [
            'distributor_id' => $this->d1->id, 'currency' => 'SYP', 'amount' => '١٬٩٠٠', 'note' => 'نقص',
        ])->assertSessionHasNoErrors();

        $count = CashCount::sole();
        $this->assertEquals(1900, (float) $count->amount);
        $log = ActivityLog::where('action', 'cash.counted')->sole();
        $this->assertSame(-100, (int) $log->meta['difference']);
        $this->assertSame(2000, (int) $log->meta['recorded']);   // الملغاة لا تُحسب

        $html = $this->actingAs($this->accountant)->get(route('finance.sheets.show', $this->sheet))->getContent();
        $this->assertMatchesRegularExpression('/text-bg-danger font-monospace cash-diff">-100\.00/', $html);

        // تعديل: نفس الصف (unique) وسجل بقيمة قبل/بعد
        $this->actingAs($this->accountant)->post(route('finance.cash-counts.store', $this->sheet), [
            'distributor_id' => $this->d1->id, 'currency' => 'SYP', 'amount' => 2000,
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, CashCount::count());
        $last = ActivityLog::where('action', 'cash.counted')->latest('id')->first();
        $this->assertEquals(1900, $last->old_values['amount']);
        $this->assertEquals(2000, $last->new_values['amount']);
        $html = $this->actingAs($this->accountant)->get(route('finance.sheets.show', $this->sheet))->getContent();
        $this->assertMatchesRegularExpression('/text-bg-success font-monospace cash-diff">0\.00/', $html);

        // الدولار خانة مستقلة
        $this->actingAs($this->accountant)->post(route('finance.cash-counts.store', $this->sheet), [
            'distributor_id' => $this->d1->id, 'currency' => 'USD', 'amount' => 20,
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, CashCount::count());

        // لا يمنع التصدير
        $this->exportAs($this->accountant)->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------------------
    // سعر الدولار
    // ------------------------------------------------------------------

    public function test_new_usd_without_rate_is_rejected_and_out_of_range_needs_confirmation(): void
    {
        $this->payApi($this->d1, $this->sheet, $this->north->id, 100, ['currency' => 'USD'])
            ->assertStatus(422)->assertJsonValidationErrors('exchange_rate');
        $this->payApi($this->d1, $this->sheet, $this->north->id, 100, ['currency' => 'USD', 'exchange_rate' => 900])
            ->assertStatus(422)->assertJsonPath('requires_confirmation', true);
        $this->assertSame(0, Payment::count());

        $this->payApi($this->d1, $this->sheet, $this->north->id, 100, ['currency' => 'USD', 'exchange_rate' => 139, 'form_version' => 5])->assertCreated();
        $this->assertSame('13900.00', Payment::sole()->amount_syp);
    }

    public function test_legacy_usd_without_rate_blocks_export_until_accountant_sets_rate(): void
    {
        $uuid = (string) Str::uuid();
        $this->payApi($this->d1, $this->sheet, $this->north->id, 100, ['client_uuid' => $uuid, 'currency' => 'USD', 'legacy_no_rate' => 1])->assertCreated();
        $payment = Payment::where('client_uuid', $uuid)->sole();
        $this->assertNull($payment->amount_syp);
        $this->assertTrue($payment->rate_is_estimate);

        $this->actingAs($this->accountant)->get(route('finance.sheets.show', $this->sheet))
            ->assertSee('بانتظار السعر (1)')
            ->assertSee('1 دفعة دولار بانتظار السعر');
        $this->exportAs($this->accountant)->assertSessionHasErrors('export');

        // خارج المجال بلا تأكيد ⇒ لا يُحفظ ويُطلب التأكيد
        $this->actingAs($this->accountant)->post(route('finance.payments.rate', $payment), ['exchange_rate' => 900])
            ->assertSessionHasErrors('exchange_rate')->assertSessionHas('rate_confirm_payment', $payment->id);
        $this->assertNull($payment->fresh()->amount_syp);

        $this->actingAs($this->accountant)->post(route('finance.payments.rate', $payment), ['exchange_rate' => '139'])->assertSessionHasNoErrors();
        $payment->refresh();
        $this->assertSame('13900.00', $payment->amount_syp);
        $this->assertSame('139.0000', $payment->exchange_rate);
        $this->assertFalse($payment->rate_is_estimate);
        $log = ActivityLog::where('action', 'finance.rate_set')->sole();
        $this->assertSame($this->accountant->id, $log->user_id);
        $this->assertNull($log->old_values['amount_syp']);
        $this->assertEquals(13900, $log->new_values['amount_syp']);

        // مرة ثانية: لم تعد بانتظار السعر
        $this->actingAs($this->accountant)->post(route('finance.payments.rate', $payment), ['exchange_rate' => 140])->assertSessionHasErrors('exchange_rate');

        $this->exportAs($this->accountant)->assertSessionHasNoErrors();
        $this->assertSame('exported', $payment->fresh()->status);
    }

    public function test_out_of_range_rate_with_confirmation_is_saved(): void
    {
        $payment = $this->pay($this->north, 10, 'USD');
        $this->actingAs($this->admin)->post(route('finance.payments.rate', $payment), ['exchange_rate' => 900, 'rate_confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame('9000.00', $payment->fresh()->amount_syp);
    }
}
