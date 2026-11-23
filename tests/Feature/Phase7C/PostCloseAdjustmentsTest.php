<?php

namespace Tests\Feature\Phase7C;

use App\Models\ActivityLog;
use App\Models\CustomerBalanceSnapshot;
use App\Models\ImportBatchItem;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\InvoiceChangeProposal;
use App\Models\SheetChangeNotice;
use App\Services\Finance\FinanceService;
use App\Services\Finance\PostCloseAdjustmentService;
use App\Services\Finance\RepReceivableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Phase7B\BalancesFixture;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 7C: «تعديل لاحق» بعد إغلاق الكشف وأثره على ذمة المندوب (G1–G10).
 * السيناريو (من 7B): G:1=1000 (مندوب A) وG:2=500 (مندوب B) لـ«صيدلية التجربة» في أمر 1580، رصيد 5000، دفعة موزع 900
 * (600 على G:1 و300 على G:2) ⇒ ذمة A على الصيدلية 400 وذمة B 200.
 */
class PostCloseAdjustmentsTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity, BalancesFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBalances();
        $this->publish();
    }

    private function g1(): Invoice
    {
        return Invoice::where('invoice_number', 'ع G: 1')->sole();
    }

    /** دفعة 900، إغلاق، تصدير */
    private function closeAndExport(): void
    {
        $this->pay(900);
        $this->actingAs($this->supervisor)->post(route('supervisor.complete', $this->sheet->id))->assertSessionHas('success');
        $this->exportSheet();
    }

    /** ملف حركة لليوم نفسه بقيمة جديدة لـG:1 وأرصدة معطاة ⇒ اقتراح post_close */
    private function fileChange(float $g1, float $balance): InvoiceChangeProposal
    {
        $this->upload($this->dailyFile($g1, ['صيدلية التجربة' => $balance] + $this->others()));

        return InvoiceChangeProposal::where('type', InvoiceChangeProposal::TYPE_POST_CLOSE)->where('status', InvoiceChangeProposal::AWAITING_ADJUSTMENT)->sole();
    }

    private function repDebt($rep): float
    {
        return app(RepReceivableService::class)->knownDebtCents($rep, $this->pharmacy->id) / 100;
    }

    private function frozenFinance(): array
    {
        return [
            DB::table('finance_exports')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('finance_export_rows')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('payment_allocations')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('payments')->orderBy('id')->get(['id', 'amount', 'amount_syp', 'status', 'exported_at', 'finance_export_id', 'voided_at'])->map(fn ($r) => (array) $r)->all(),
        ];
    }

    // G1 + G6 + G7
    public function test_management_scenario_end_to_end(): void
    {
        $this->closeAndExport();
        $this->assertSame([400.0, 200.0], [$this->repDebt($this->repA), $this->repDebt($this->repB)]);
        $frozen = $this->frozenFinance();
        $notices = SheetChangeNotice::count();
        $revision = (int) $this->sheet->fresh()->revision;

        $proposal = $this->fileChange(800, 4800);

        // عنصر closed_changed معلّق بلا تطبيق، ولقطة −200 يذكر تفسيرها الفاتورة والمندوب
        $item = ImportBatchItem::where('proposal_id', $proposal->id)->sole();
        $this->assertSame([ImportBatchItem::CLOSED_CHANGED, ImportBatchItem::INFO], [$item->kind, $item->status]);
        $this->assertSame(1000.0, (float) $this->g1()->invoice_amount);
        $snapshot = CustomerBalanceSnapshot::where('customer_id', $this->pharmacy->id)->latest('id')->first();
        $this->assertSame(-200.0, (float) $snapshot->delta);
        $this->assertStringContainsString('تغيّر مُقترح من الملف لفاتورة G: 1 (مندوب مندوب أ) −200', $snapshot->explanation);

        // الإحالة من مراجعة الدفعة، وعدّاد المحاسب
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $item->batch_id, 'tab' => 'invoices']))
            ->assertSee('انتقل إلى التعديلات اللاحقة')->assertSee('حُفظ التغيير للتسوية بعد الإغلاق في «التعديلات اللاحقة»');
        $this->actingAs($this->accountant)->get(route('accountant.index'))->assertSee('تعديلات بانتظار القرار: 1');

        // الشاشة: نافذة التأكيد بالأرقام
        $this->actingAs($this->accountant)->get(route('finance.adjustments'))->assertOk()
            ->assertSee('مندوب أ')->assertSee('655231-مندوب أ')
            ->assertSee('ذمة المندوب مندوب أ ستنقص من 400 إلى 200');

        $this->actingAs($this->accountant)->post(route('finance.adjustments.apply', $proposal), ['note' => 'مرتجع صنف'])->assertSessionHas('success');

        // ذمة A: 400 ← 200، وB ثابتة
        $this->assertSame([200.0, 200.0], [$this->repDebt($this->repA), $this->repDebt($this->repB)]);

        $adjustment = InvoiceAdjustment::sole();
        $this->assertSame(
            ['file', 1000.0, 800.0, -200.0, 400.0, 200.0, 0.0, 'applied', $this->repA->id, $this->sheet->id, $snapshot->id, $proposal->batch_id],
            [$adjustment->kind, (float) $adjustment->old_amount, (float) $adjustment->new_amount, (float) $adjustment->delta, (float) $adjustment->rep_debt_before,
                (float) $adjustment->rep_debt_after, (float) $adjustment->surplus, $adjustment->status, $adjustment->rep_user_id, $adjustment->sheet_id,
                $adjustment->balance_snapshot_id, $adjustment->source_batch_id]);
        $this->assertStringContainsString('مرتجع صنف', $adjustment->reason_text);
        $this->assertSame('file', $adjustment->reason_code);

        // الفاتورة: ترويسة وأسطر جديدة، ونسخة قديمة في invoice_revisions، وتبقى في كشفها المكتمل
        $invoice = $this->g1();
        $this->assertSame([800.0, $this->sheet->id, Invoice::IN_SHEET], [(float) $invoice->invoice_amount, $invoice->distribution_sheet_id, $invoice->placement]);
        $this->assertSame(800.0, (float) $invoice->lines()->sum('line_total'));
        $this->assertSame(1000.0, (float) $invoice->revisions()->latest('id')->first()->header['invoice_amount']);
        $this->assertSame('completed', $this->sheet->fresh()->status);
        $this->assertSame($revision, (int) $this->sheet->fresh()->revision);
        $this->assertSame($notices, SheetChangeNotice::count());                // لا إشعار للموزع (الكشف مغلق)

        // عنصر 7A والاقتراح
        $this->assertSame([ImportBatchItem::APPLIED, PostCloseAdjustmentService::APPLIED_NOTE], [$item->fresh()->status, $item->fresh()->decision_note]);
        $this->assertSame(InvoiceChangeProposal::APPLIED, $proposal->fresh()->status);

        // التفسير: «تعديل لاحق» مرة واحدة (لا «مقترح» معه)
        $snapshot->refresh();
        $this->assertStringContainsString('تعديل لاحق −200 على الفاتورة G: 1 (مندوب مندوب أ)', $snapshot->explanation);
        $this->assertStringNotContainsString('مُقترح من الملف', $snapshot->explanation);
        $this->assertSame(-200.0, (float) $snapshot->invoice_effect_sum);

        // ActivityLog بالقيم قبل/بعد
        $log = ActivityLog::where('action', 'invoice.adjustment_applied')->sole();
        $this->assertEquals([1000, 400], [$log->old_values['amount'], $log->old_values['rep_debt']]);
        $this->assertEquals([800, 200, -200], [$log->new_values['amount'], $log->new_values['rep_debt'], $log->new_values['delta']]);
        $this->assertSame([$this->repA->name, $adjustment->id], [$log->meta['rep'], $log->meta['adjustment_id']]);

        // المندوب A يرى الوسم والإنذار؛ B لا
        $this->actingAs($this->repA)->get(route('rep.sheets.show', $this->sheet))->assertSee('تعديل لاحق: من 1,000 إلى 800')->assertSee('مرتجع صنف');
        $this->actingAs($this->repA)->get(route('rep.index'))->assertSee('تغيّرت ذمتك بسبب تعديل لاحق')->assertSee('تعديلات لاحقة على ذمتي');
        $this->actingAs($this->repA)->get(route('rep.sheets.index'))->assertSee('تغيّرت ذمتك بسبب تعديل لاحق');
        $this->actingAs($this->repB)->get(route('rep.index'))->assertDontSee('تغيّرت ذمتك بسبب تعديل لاحق');
        $lines = $this->actingAs($this->repA)->getJson(route('rep.sheets.lines', $this->sheet))->json('invoices.0');
        $this->assertSame('تعديل لاحق: من 1,000 إلى 800', $lines['adjustment']['label']);
        $this->actingAs($this->repA)->post(route('rep.adjustment-seen', $adjustment))->assertRedirect();
        $this->actingAs($this->repA)->get(route('rep.index'))->assertDontSee('تغيّرت ذمتك بسبب تعديل لاحق');
        $this->actingAs($this->repB)->post(route('rep.adjustment-seen', $adjustment))->assertForbidden();

        // التصديرات والتوزيعات والدفعات السابقة لم تتغيّر
        $this->assertSame($frozen, $this->frozenFinance());

        // ملف أرصدة لاحق (الدفعة لم تنعكس بعد): التعديل لا يُحسب مرة ثانية
        $this->upload($this->balancesFile(['صيدلية التجربة' => 4800] + $this->others()));
        $later = CustomerBalanceSnapshot::where('customer_id', $this->pharmacy->id)->latest('id')->first();
        $this->assertNotSame($snapshot->id, $later->id);
        $this->assertSame(0.0, (float) $later->invoice_effect_sum);
    }

    // G2
    public function test_reduction_below_collected_shows_surplus_not_silent_zero(): void
    {
        $this->closeAndExport();
        $proposal = $this->fileChange(500, 4500);

        $this->actingAs($this->accountant)->get(route('finance.adjustments'))->assertSee('ستنقص من 400 إلى 0، وزيادة تحصيل 100 تحتاج قراراً');
        $this->actingAs($this->accountant)->post(route('finance.adjustments.apply', $proposal))->assertSessionHas('success');

        $adjustment = InvoiceAdjustment::sole();
        $this->assertSame([400.0, 0.0, 100.0], [(float) $adjustment->rep_debt_before, (float) $adjustment->rep_debt_after, (float) $adjustment->surplus]);

        $state = app(RepReceivableService::class)->state($this->repA, [$this->pharmacy->id])->get($this->pharmacy->id);
        $this->assertSame(10000, $state['over_collected_cents']);
        $text = 'زيادة تحصيل على الفاتورة G: 1 بمبلغ 100 بعد التعديل: يحتاج قراراً';
        $this->assertSame($text, $state['over_collected'][0]['text']);
        $this->actingAs($this->repA)->get(route('rep.index'))->assertSee($text);
        $this->actingAs($this->accountant)->get(route('finance.rep-receivables'))->assertSee($text)->assertSee('زيادة تحصيل بعد تعديل لاحق: 100.00');
    }

    // G3
    public function test_manual_negative_delta_changes_effective_amount_and_debt_only(): void
    {
        $this->closeAndExport();
        $invoice = $this->g1();
        $hash = $invoice->content_hash;

        $this->actingAs($this->accountant)->get(route('finance.adjustments.create', ['invoice' => $invoice->id, 'delta' => -150]))->assertOk()
            ->assertSee('ذمة المندوب مندوب أ ستنقص من 400 إلى 250');
        $this->actingAs($this->accountant)->post(route('finance.adjustments.store'), ['invoice' => $invoice->id, 'mode' => 'delta', 'delta' => -150, 'reason_code' => 'return'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $invoice->refresh();
        $this->assertSame([1000.0, 850.0, $hash], [(float) $invoice->invoice_amount, $invoice->effectiveAmount(), $invoice->content_hash]);
        $this->assertSame(1000.0, (float) $invoice->lines()->sum('line_total'));    // الأسطر لم تُمس
        $this->assertSame(0, $invoice->revisions()->count());
        $this->assertSame(250.0, $this->repDebt($this->repA));
        $this->assertSame(200.0, $this->repDebt($this->repB));
        $adjustment = InvoiceAdjustment::sole();
        $this->assertSame(['manual', 'return', 1000.0, 850.0, -150.0], [$adjustment->kind, $adjustment->reason_code, (float) $adjustment->old_amount, (float) $adjustment->new_amount, (float) $adjustment->delta]);
        $this->assertSame(InvoiceAdjustment::NO_SNAPSHOT, $adjustment->snapshot_note);

        // «أخرى» بلا نص، والسبب الفارغ: مرفوضان
        $this->actingAs($this->accountant)->post(route('finance.adjustments.store'), ['invoice' => $invoice->id, 'mode' => 'delta', 'delta' => -10, 'reason_code' => 'other'])->assertSessionHasErrors('reason_text');
        $this->actingAs($this->accountant)->post(route('finance.adjustments.store'), ['invoice' => $invoice->id, 'mode' => 'delta', 'delta' => -10])->assertSessionHasErrors('reason_code');
        // والمبلغ الجديد بدل الفرق
        $this->actingAs($this->accountant)->post(route('finance.adjustments.store'), ['invoice' => $invoice->id, 'mode' => 'new_amount', 'new_amount' => 800, 'reason_code' => 'price'])->assertSessionHasNoErrors();
        $this->assertSame(800.0, $invoice->fresh()->effectiveAmount());
        $this->assertSame(-50.0, (float) InvoiceAdjustment::latest('id')->first()->delta);
    }

    public function test_manual_adjustment_is_a_new_explainer_source(): void
    {
        $this->actingAs($this->supervisor)->post(route('supervisor.complete', $this->sheet->id))->assertSessionHas('success');
        $this->actingAs($this->admin)->post(route('finance.adjustments.store'), ['invoice' => $this->g1()->id, 'mode' => 'delta', 'delta' => -100, 'reason_code' => 'voucher'])->assertSessionHasNoErrors();

        $this->upload($this->balancesFile(['صيدلية التجربة' => 4900] + $this->others()));
        $s = CustomerBalanceSnapshot::where('customer_id', $this->pharmacy->id)->latest('id')->first();
        $this->assertSame('ok', $s->status);
        $this->assertStringStartsWith('انخفض الرصيد 100: دفعات مصدَّرة 0، تعديلات فواتير −100، غير مفسَّر 0', $s->explanation);
        $this->assertStringContainsString('تعديل لاحق −100 على الفاتورة G: 1 (مندوب مندوب أ)', $s->explanation);
    }

    // G4
    public function test_amount_to_zero_is_cancelled_not_deleted(): void
    {
        $this->closeAndExport();
        $invoice = $this->g1();
        $this->actingAs($this->accountant)->post(route('finance.adjustments.store'), ['invoice' => $invoice->id, 'mode' => 'new_amount', 'new_amount' => 0, 'reason_code' => 'cancel'])->assertSessionHasNoErrors();

        $this->assertNotNull(Invoice::find($invoice->id));
        $this->assertSame([0.0, Invoice::IN_SHEET, $this->sheet->id], [$invoice->fresh()->effectiveAmount(), $invoice->fresh()->placement, $invoice->fresh()->distribution_sheet_id]);
        $adjustment = InvoiceAdjustment::sole();
        $this->assertSame('تعديل لاحق: من 1,000 إلى 0 (ألغيت في المحاسبة)', $adjustment->tagLabel());
        $this->assertSame([400.0, 0.0, 600.0], [(float) $adjustment->rep_debt_before, (float) $adjustment->rep_debt_after, (float) $adjustment->surplus]);
        $log = ActivityLog::where('action', 'invoice.adjustment_applied')->sole();
        $this->assertTrue($log->meta['cancelled_in_accounting']);
        $this->assertStringContainsString('ألغيت في المحاسبة', $log->meta['note']);
        $this->actingAs($this->repA)->get(route('rep.sheets.show', $this->sheet))->assertSee('ألغيت في المحاسبة');
        $this->actingAs($this->accountant)->get(route('finance.adjustments', ['tab' => 'applied']))->assertSee('ألغيت في المحاسبة');
    }

    // G5
    public function test_permissions(): void
    {
        $this->closeAndExport();
        $proposal = $this->fileChange(800, 4800);
        $invoice = $this->g1();

        foreach ([$this->manager, $this->supervisor, $this->repSupervisor, $this->d1, $this->repA] as $user) {
            $this->actingAs($user)->post(route('finance.adjustments.apply', $proposal))->assertForbidden();
            $this->actingAs($user)->post(route('finance.adjustments.ignore', $proposal), ['reason' => 'لا'])->assertForbidden();
            $this->actingAs($user)->post(route('finance.adjustments.store'), ['invoice' => $invoice->id, 'mode' => 'delta', 'delta' => -1, 'reason_code' => 'return'])->assertForbidden();
            $this->actingAs($user)->get(route('finance.adjustments.create', ['invoice' => $invoice->id]))->assertForbidden();
        }
        foreach ([$this->d1, $this->repA] as $user) {
            $this->actingAs($user)->get(route('finance.adjustments'))->assertForbidden();
        }
        foreach ([$this->manager, $this->supervisor, $this->repSupervisor] as $user) {
            $this->actingAs($user)->get(route('finance.adjustments'))->assertOk()->assertSee('للعرض فقط')->assertDontSee('تجاهل مع سبب');
        }
        $this->assertSame(0, InvoiceAdjustment::count());

        $this->actingAs($this->admin)->post(route('finance.adjustments.apply', $proposal))->assertSessionHas('success');
        $adjustment = InvoiceAdjustment::sole();
        $this->actingAs($this->accountant)->post(route('finance.adjustments.revert', $adjustment), ['reason' => 'خطأ'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('finance.adjustments.revert', $adjustment), [])->assertSessionHasErrors('reason');
        $this->assertTrue($adjustment->fresh()->isApplied());

        // «تغيّرات الأرصدة»: زر «تعديل لاحق» فعال للمحاسب (رابط النموذج بالفاتورة والفرق واللقطة)، ولا يظهر للمشرف
        $snapshot = CustomerBalanceSnapshot::where('customer_id', $this->pharmacy->id)->latest('id')->first();
        $this->actingAs($this->accountant)->get(route('finance.balance-changes'))
            ->assertSee(e(route('finance.adjustments.create', ['invoice' => $invoice->id, 'delta' => -200.0, 'snapshot' => $snapshot->id])), false);
        $this->actingAs($this->supervisor)->get(route('finance.balance-changes'))->assertDontSee('post-close-adjust', false);
    }

    public function test_ignore_with_reason_keeps_record_visible(): void
    {
        $this->closeAndExport();
        $proposal = $this->fileChange(800, 4800);

        $this->actingAs($this->accountant)->post(route('finance.adjustments.ignore', $proposal), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->accountant)->post(route('finance.adjustments.ignore', $proposal), ['reason' => 'خطأ في ملف المحاسبة'])->assertSessionHas('success');

        $this->assertSame([InvoiceChangeProposal::REJECTED, 'خطأ في ملف المحاسبة'], [$proposal->fresh()->status, $proposal->fresh()->decision_note]);
        $this->assertSame(1000.0, (float) $this->g1()->invoice_amount);
        $this->assertSame(400.0, $this->repDebt($this->repA));
        $this->assertDatabaseHas('activity_logs', ['action' => 'invoice.adjustment_ignored']);
        $this->actingAs($this->accountant)->get(route('finance.adjustments'))->assertSee('تُجوهلت بقرار (1)')->assertSee('خطأ في ملف المحاسبة');
    }

    // G8
    public function test_revert_restores_values_and_debt_and_is_logged(): void
    {
        $this->closeAndExport();
        $proposal = $this->fileChange(800, 4800);
        $this->actingAs($this->accountant)->post(route('finance.adjustments.apply', $proposal))->assertSessionHas('success');
        $adjustment = InvoiceAdjustment::sole();
        $this->assertSame(200.0, $this->repDebt($this->repA));

        $this->actingAs($this->admin)->post(route('finance.adjustments.revert', $adjustment), ['reason' => 'طُبّق بالخطأ'])->assertSessionHas('success');

        $invoice = $this->g1();
        $this->assertSame([1000.0, 1000.0, $this->sheet->id], [(float) $invoice->invoice_amount, (float) $invoice->lines()->sum('line_total'), $invoice->distribution_sheet_id]);
        $this->assertSame(400.0, $this->repDebt($this->repA));
        $adjustment->refresh();
        $this->assertSame([InvoiceAdjustment::REVERTED, $this->admin->id, 'طُبّق بالخطأ'], [$adjustment->status, $adjustment->reverted_by, $adjustment->revert_reason]);
        $this->assertSame(InvoiceChangeProposal::AWAITING_ADJUSTMENT, $proposal->fresh()->status);
        $log = ActivityLog::where('action', 'invoice.adjustment_reverted')->sole();
        $this->assertEquals([800, 200], [$log->old_values['amount'], $log->old_values['rep_debt']]);
        $this->assertEquals([1000, 400], [$log->new_values['amount'], $log->new_values['rep_debt']]);
        // المندوب يُنبَّه بالتراجع
        $this->actingAs($this->repA)->get(route('rep.index'))->assertSee('تُراجع عن التعديل: طُبّق بالخطأ');
        // التفسير يعود «مقترح من الملف»
        $this->assertStringContainsString('تغيّر مُقترح من الملف لفاتورة G: 1', CustomerBalanceSnapshot::find($adjustment->balance_snapshot_id)->explanation);

        // يدوي: التراجع يُلغي أثر الـdelta
        $this->actingAs($this->accountant)->post(route('finance.adjustments.store'), ['invoice' => $invoice->id, 'mode' => 'delta', 'delta' => -300, 'reason_code' => 'price'])->assertSessionHasNoErrors();
        $this->assertSame(700.0, $invoice->fresh()->effectiveAmount());
        $manual = InvoiceAdjustment::latest('id')->first();
        $this->actingAs($this->admin)->post(route('finance.adjustments.revert', $manual), ['reason' => 'تراجع المحاسبة'])->assertSessionHas('success');
        $this->assertSame(1000.0, $invoice->fresh()->effectiveAmount());
        $this->assertSame(400.0, $this->repDebt($this->repA));
    }

    // G9
    public function test_engine_clipping_is_visible_in_preview_and_log(): void
    {
        // دفعة 900 تُصدَّر والكشف منشور، ثم دفعة 100 معلّقة، ثم إغلاق وتخفيض G:1 إلى 500 (الموزَّع عليها سابقاً 600)
        $this->pay(900);
        $this->exportSheet();
        $this->pay(100);
        $this->actingAs($this->supervisor)->post(route('supervisor.complete', $this->sheet->id))->assertSessionHas('success');
        $this->actingAs($this->admin)->post(route('finance.adjustments.store'), ['invoice' => $this->g1()->id, 'mode' => 'new_amount', 'new_amount' => 500, 'reason_code' => 'return'])->assertSessionHasNoErrors();

        $preview = app(FinanceService::class)->preview($this->sheet->fresh());
        $this->assertCount(1, $preview['clipped']);
        $this->assertSame([10000, 60000, 50000], [$preview['clipped'][0]['clipped_cents'], $preview['clipped'][0]['previous_cents'], $preview['clipped'][0]['total_cents']]);
        $result = $preview['customers']->firstWhere('customer.id', $this->pharmacy->id)['result'];
        $this->assertTrue($result->allocationClipped());
        $this->actingAs($this->accountant)->get(route('finance.sheets.show', $this->sheet))->assertSee('allocation_clipped')->assertSee('قُصّ 100.00');

        $this->exportSheet();
        $log = ActivityLog::where('action', 'finance.allocation_clipped')->sole();
        $this->assertTrue($log->meta['allocation_clipped']);
        $this->assertEquals(100, $log->meta['total_clipped']);
        $this->assertEquals(['ع G: 1' => 100], $log->new_values['clipped']);
    }

    // G10
    public function test_rep_debt_is_consistent_across_financial_screens(): void
    {
        $this->closeAndExport();
        $this->actingAs($this->accountant)->post(route('finance.adjustments.store'), ['invoice' => $this->g1()->id, 'mode' => 'delta', 'delta' => -200, 'reason_code' => 'return'])->assertSessionHasNoErrors();
        $g1 = $this->g1()->id;

        // ذمم المندوبين (المالية)
        $report = $this->actingAs($this->accountant)->get(route('finance.rep-receivables'))->assertOk()->viewData('report');
        $staff = $report['groups']->firstWhere('rep_id', $this->repA->id)['pharmacies']->first()['invoices']->firstWhere('invoice_id', $g1);
        // ذممي (المندوب)
        $mine = $this->actingAs($this->repA)->get(route('rep.index'))->viewData('pharmacies')->first()['invoices']->firstWhere('invoice_id', $g1);
        // كشوفي (المندوب)
        $sheetRow = $this->actingAs($this->repA)->get(route('rep.sheets.show', $this->sheet))->viewData('pharmacies')->first()['invoices']->first();
        // غير المسدد
        $unpaid = $this->actingAs($this->accountant)->get(route('finance.unpaid', ['from' => '2026-10-13', 'to' => '2026-10-13']))->viewData('rows')->firstWhere('invoice', 'G: 1');
        // معاينة المالية للكشف
        $preview = app(FinanceService::class)->preview($this->sheet->fresh())['customers']->firstWhere('customer.id', $this->pharmacy->id)['result'];
        $previewRow = collect($preview->invoiceRows)->firstWhere('invoice_id', $g1);

        $this->assertSame(
            [800.0, 800.0, 800.0, 800.0, 800.0],
            [(float) ($staff['total_cents'] / 100), (float) ($mine['total_cents'] / 100), (float) $sheetRow['amount'], (float) $unpaid['total'], (float) $previewRow['total']]
        );
        $this->assertSame(
            [200.0, 200.0, 200.0, 200.0, 200.0],
            [(float) ($staff['remaining_cents'] / 100), (float) ($mine['remaining_cents'] / 100), (float) $sheetRow['remaining'], (float) $unpaid['unpaid'], (float) $previewRow['unpaid']]
        );
    }
}
