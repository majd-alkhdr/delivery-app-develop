<?php

namespace Tests\Feature\Phase6;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\Finance\FinanceService;
use App\Services\Finance\PaymentAllocationEngine;
use App\Services\Finance\RepReceivableService;
use App\Services\SheetActivityGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 6-B: ذمم المندوب (بعد الموزع)، FIFO، وعدم التداخل مع لقطات الموزع (انحدار المرحلة 5).
 */
class RepReceivablesTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity, RepFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReps();
    }

    private function service(): RepReceivableService
    {
        return app(RepReceivableService::class);
    }

    private function invoiceRows(int $customerId, $rep = null): array
    {
        $state = $this->service()->state($rep ?? $this->rep1)->get($customerId);

        return $state ? $state['invoices']->mapWithKeys(fn ($i) => [$i['label'] => (float) ($i['remaining_cents'] / 100)])->all() : [];
    }

    public function test_debt_is_remaining_after_distributor_on_completed_sheets_only(): void
    {
        // الموزع دفع 900 في الكشف A: بالتناسب 1000:500:300 ⇒ 500 + 250 + 150
        $this->distributorPay($this->sheetA, $this->north, 900);

        $rows = $this->invoiceRows($this->north->id);
        $this->assertSame(['G: 11' => 500.0, 'G: 12' => 250.0, 'G: 31' => 400.0], $rows);
        // A:13 لمركز كول سنتر، G:14 صفرية، G:15 مؤجلة، G:41 في كشف غير مغلق: كلها خارج الدَين
        $this->assertArrayNotHasKey('A: 13', $rows);
        $this->assertArrayNotHasKey('G: 14', $rows);
        $this->assertArrayNotHasKey('G: 15', $rows);
        $this->assertArrayNotHasKey('G: 41', $rows);

        // إغلاق الكشف C يُدخل فاتورته
        $this->sheetC->update(['status' => 'completed']);
        $this->assertArrayHasKey('G: 41', $this->invoiceRows($this->north->id));
    }

    public function test_cancelled_and_excluded_invoices_do_not_count(): void
    {
        Invoice::where('invoice_number', 'ع G: 12')->update(['placement' => Invoice::CANCELLED]);
        Invoice::where('invoice_number', 'ع G: 31')->update(['placement' => Invoice::EXCLUDED]);
        $this->assertSame(['G: 11' => 1000.0], $this->invoiceRows($this->north->id));
    }

    public function test_code_matching_ignores_name_spelling_and_arabic_digits(): void
    {
        $this->assertSame(['B: 21' => 800.0], $this->invoiceRows($this->south->id));
        // rep2 لا يرى فواتير rep1
        $this->assertSame([], $this->invoiceRows($this->south->id, $this->rep2));
        $this->assertSame(['G: 22' => 600.0], $this->invoiceRows($this->east->id, $this->rep2));
    }

    public function test_rep_without_cost_center_has_no_receivables_and_cannot_record(): void
    {
        $this->rep1->update(['cost_center_code' => null, 'cost_center_label' => null]);
        $this->assertTrue($this->service()->receivablesForRep($this->rep1->fresh())->isEmpty());

        $this->actingAs($this->rep1)->get(route('rep.index'))->assertOk()->assertSee('لم يُضبط مركز الكلفة لحسابك، راجع الإدارة');
        $this->repPay($this->rep1->fresh(), $this->north, 100)->assertForbidden();
        $this->assertSame(0, Payment::where('source', 'rep')->count());
    }

    public function test_fifo_oldest_first_then_partial_and_surplus_not_carried(): void
    {
        // دَين الشمال: G:11=1000 (2026-10-10) ثم G:12=500 ثم G:31=400 (2026-10-12)
        $this->repPay($this->rep1, $this->north, 1200)->assertCreated()
            ->assertJsonPath('breakdown.rows.0.invoice', 'G: 11')
            ->assertJsonPath('breakdown.rows.1.invoice', 'G: 12')
            ->assertJsonPath('breakdown.remaining', 700);
        $this->assertSame(['G: 11' => 0.0, 'G: 12' => 300.0, 'G: 31' => 400.0], $this->invoiceRows($this->north->id));

        // دفعة أكبر من المتبقي: الفائض لا يُحمَّل لصيدلية أخرى ولا لفاتورة مركز آخر
        $response = $this->repPay($this->rep1, $this->north, 1000)->assertCreated();
        $this->assertTrue($response->json('payment.exceeds_known_debt'));
        $state = $this->service()->state($this->rep1)->get($this->north->id);
        $this->assertSame(0, $state['remaining_cents']);
        $this->assertSame(30000, $state['surplus_cents']);
        $this->assertSame(['B: 21' => 800.0], $this->invoiceRows($this->south->id));
        $this->assertTrue((bool) Payment::latest('id')->first()->exceeds_known_debt);
    }

    public function test_voided_rep_payment_does_not_count_and_usd_counts_by_rate(): void
    {
        $id = $this->repPay($this->rep1, $this->north, 500)->json('payment.id');
        Payment::find($id)->update(['voided_at' => now(), 'void_reason' => 'خطأ']);
        $this->assertSame(['G: 11' => 1000.0, 'G: 12' => 500.0, 'G: 31' => 400.0], $this->invoiceRows($this->north->id));

        $this->repPay($this->rep1, $this->north, 5, ['currency' => 'USD', 'exchange_rate' => 139])->assertCreated();   // 695
        $this->assertSame(['G: 11' => 305.0, 'G: 12' => 500.0, 'G: 31' => 400.0], $this->invoiceRows($this->north->id));
    }

    public function test_exported_payments_consume_from_snapshot_and_pending_from_remaining(): void
    {
        $this->repPay($this->rep1, $this->north, 1200)->assertCreated();
        $this->repExport($this->accountant, [$this->rep1->id])->assertSessionHasNoErrors();

        $snap = PaymentAllocation::where('source', 'rep')->orderBy('id')->get();
        $this->assertSame([['ع G: 11', 1000.0], ['ع G: 12', 200.0]], $snap->map(fn ($s) => [$s->invoice_number, (float) $s->allocated])->all());

        // تعديل فاتورة بعد التصدير لا يغيّر اللقطة؛ ودفعة معلّقة جديدة تستهلك المتبقي بعد اللقطة
        $this->repPay($this->rep1, $this->north, 350)->assertCreated();
        $this->assertSame(['G: 11' => 0.0, 'G: 12' => 0.0, 'G: 31' => 350.0], $this->invoiceRows($this->north->id));
        $this->assertSame(1200.0, (float) PaymentAllocation::where('source', 'rep')->sum('allocated'));
    }

    public function test_rep_snapshots_do_not_touch_distributor_engine_preview_or_unpaid_report(): void
    {
        $this->distributorPay($this->sheetA, $this->north, 900);
        $before = app(PaymentAllocationEngine::class)->previousAllocatedCents(Invoice::pluck('id')->all());
        $previewBefore = app(FinanceService::class)->preview($this->sheetA->fresh())['totals'];

        $this->repPay($this->rep1, $this->north, 600)->assertCreated();
        $this->repExport($this->accountant, [$this->rep1->id])->assertSessionHasNoErrors();
        $this->assertGreaterThan(0, PaymentAllocation::where('source', 'rep')->count());

        $this->assertSame($before, app(PaymentAllocationEngine::class)->previousAllocatedCents(Invoice::pluck('id')->all()));
        $this->assertEquals($previewBefore, app(FinanceService::class)->preview($this->sheetA->fresh())['totals']);

        // تقرير غير المسدد (المرحلة 5) لا يتأثر بدفعات المندوب
        $html = $this->actingAs($this->accountant)->get(route('finance.unpaid', ['from' => '2026-10-10']))->getContent();
        $this->assertStringContainsString('500.00', $html);

        // دفعة المندوب خارج نشاط الكشف وخارج تصدير الكشف
        $guard = app(SheetActivityGuard::class);
        $this->assertFalse($guard->customerHasActivity($this->sheetB, $this->north->id));
        $this->assertSame(0, Payment::where('source', 'rep')->whereNotNull('distribution_sheet_id')->count());
    }

    public function test_staff_report_groups_reps_and_no_rep_centers(): void
    {
        $report = $this->service()->receivablesForStaff();
        $names = $report['groups']->pluck('name')->all();
        $this->assertContains('كرم', $names);
        $this->assertContains('سامر', $names);
        $this->assertContains(RepReceivableService::NO_REP, $names);

        $noRep = $report['groups']->firstWhere('name', RepReceivableService::NO_REP);
        $this->assertSame(30000, $noRep['remaining_cents']);   // A:13 كول سنتر
        $kareem = $report['groups']->firstWhere('name', 'كرم');
        $this->assertSame(270000, $kareem['remaining_cents']);   // 1000 + 500 + 400 + 800

        $filtered = $this->service()->receivablesForStaff(['rep_id' => (string) $this->rep2->id]);
        $this->assertSame(['سامر'], $filtered['groups']->pluck('name')->all());
    }
}
