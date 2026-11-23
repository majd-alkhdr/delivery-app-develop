<?php

namespace Tests\Feature\Phase7B;

use App\Models\CustomerBalanceSnapshot;
use App\Models\ImportBatchItem;
use App\Models\Payment;
use App\Models\SheetChangeNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 7B (I7–I11): تنبيه «تغيّر الرصيد»، المفسِّر، الدفعات المصدَّرة غير المنعكسة، صلاحيات الشاشة، وسيناريو الإدارة.
 */
class NoticesAndExplainerTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity, BalancesFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBalances();
        $this->publish();
    }

    private function snapshot(): CustomerBalanceSnapshot
    {
        return CustomerBalanceSnapshot::where('customer_id', $this->pharmacy->id)->where('balance_import_id', $this->latestImport()->id)->sole();
    }

    private function balances(float $value): array
    {
        return $this->upload($this->balancesFile(['صيدلية التجربة' => $value] + $this->others()));
    }

    /** يُطبّق تعديل G:1 من 1000 إلى 800 عبر مراجعة 7A */
    private function applyInvoiceChange(): void
    {
        $result = $this->upload($this->dailyFile(800));
        $item = ImportBatchItem::where('batch_id', $result['batch_id'])->where('invoice_number', 'ع G: 1')->sole();
        $this->actingAs($this->admin)->post(route('sheet-manager.items.apply', $item))->assertSessionHasNoErrors();   // قد يكون على الصيدلية نشاط
    }

    public function test_balance_change_notifies_distributor_and_rep_until_seen(): void
    {
        $this->visitApi($this->d1, $this->sheet, $this->pharmacy->id)->assertSuccessful();   // in_progress
        $revision = (int) $this->sheet->fresh()->revision;

        $this->balances(4800);

        $notice = SheetChangeNotice::where('type', 'balance_changed')->sole();
        $this->assertSame($this->pharmacy->id, $notice->customer_id);
        $this->assertSame($revision + 1, (int) $this->sheet->fresh()->revision);
        $this->actingAs($this->d1)->getJson(route('distributor.invoice-lines', $this->sheet))->assertJsonPath('revision', $revision + 1);

        $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->assertSee('تغيّر الرصيد من 5,000 إلى 4,800');
        $this->actingAs($this->d1)->post(route('distributor.balance-seen', [$this->sheet, $this->pharmacy]))->assertRedirect();
        $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->assertDontSee('تغيّر الرصيد من 5,000 إلى 4,800');

        $this->actingAs($this->repA)->get(route('rep.sheets.index'))->assertSee('1 صيدليات تغيّر رصيدها');
        $this->actingAs($this->repA)->get(route('rep.sheets.show', $this->sheet))->assertSee('تغيّر الرصيد من 5,000 إلى 4,800');
        $this->actingAs($this->repA)->post(route('rep.balance-seen', $this->snapshot()))->assertRedirect();
        $this->actingAs($this->repA)->get(route('rep.sheets.show', $this->sheet))->assertDontSee('تغيّر الرصيد من 5,000 إلى 4,800');
        $this->actingAs($this->repA)->get(route('rep.sheets.index'))->assertDontSee('صيدليات تغيّر رصيدها');
    }

    public function test_financially_closed_sheet_gets_no_notice_but_change_is_listed(): void
    {
        $this->actingAs($this->supervisor)->post(route('supervisor.complete', $this->sheet->id))->assertSessionHas('success');
        $this->balances(4700);

        $this->assertSame(0, SheetChangeNotice::where('type', 'balance_changed')->count());
        $this->actingAs($this->accountant)->get(route('finance.balance-changes'))->assertOk()->assertSee('صيدلية التجربة')->assertSee('−300');
    }

    public function test_change_below_threshold_is_counted_not_notified(): void
    {
        $this->visitApi($this->d1, $this->sheet, $this->pharmacy->id)->assertSuccessful();
        $result = $this->balances(4999.5);

        $this->assertSame(0, SheetChangeNotice::where('type', 'balance_changed')->count());
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $result['batch_id'], 'tab' => 'balances']))->assertSee('1 تغيّراً في الأرصدة أقل من الحدّ');
    }

    public function test_explainer_exported_payment_only(): void
    {
        $payment = $this->pay(900);
        $this->exportSheet();
        $this->balances(4100);

        $s = $this->snapshot();
        $this->assertSame('ok', $s->status);
        $this->assertStringStartsWith('انخفض الرصيد 900: دفعات مصدَّرة 900، تعديلات فواتير 0، غير مفسَّر 0', $s->explanation);
        $this->assertSame($s->id, $payment->fresh()->reflected_snapshot_id);
    }

    public function test_explainer_invoice_change_only(): void
    {
        $this->applyInvoiceChange();
        $this->balances(4800);

        $s = $this->snapshot();
        $this->assertSame('ok', $s->status);
        $this->assertStringStartsWith('انخفض الرصيد 200: دفعات مصدَّرة 0، تعديلات فواتير −200، غير مفسَّر 0', $s->explanation);
        $this->assertStringContainsString('تعديل فاتورة G: 1 (مندوب مندوب أ) −200', $s->explanation);
    }

    public function test_explainer_unexplained_is_flagged(): void
    {
        $this->balances(4700);

        $s = $this->snapshot();
        $this->assertSame('flagged', $s->status);
        $this->assertStringContainsString('غير مفسَّر −300', $s->explanation);
        $this->assertStringContainsString('غير مفسَّر: تحقق في المحاسبة (مرتجع؟ تعديل سند؟ إلغاء فاتورة؟)', $s->explanation);
    }

    public function test_explainer_combines_payment_and_invoice_change(): void
    {
        $this->pay(900);
        $this->exportSheet();
        $this->applyInvoiceChange();
        $this->balances(3900);

        $s = $this->snapshot();
        $this->assertSame('ok', $s->status);
        $this->assertStringStartsWith('انخفض الرصيد 1,100: دفعات مصدَّرة 900، تعديلات فواتير −200، غير مفسَّر 0', $s->explanation);
    }

    public function test_exported_payment_not_reflected_is_flagged_until_a_later_file(): void
    {
        $payment = $this->pay(900);
        $this->exportSheet();
        $result = $this->balances(5000);   // المحاسبة لم ترحّل الدفعة

        $s = $this->snapshot();
        $this->assertSame(['unchanged', 'flagged', true], [$s->kind, $s->status, $s->exported_mismatch]);
        $this->assertStringContainsString('دفعات مصدَّرة بمبلغ 900 ولم ينخفض الرصيد بما يقابلها (الفرق +900): تحقق أن القيود رُحّلت في المحاسبة', $s->explanation);
        $this->assertNull($payment->fresh()->reflected_at);
        $this->assertSame(4100.0, $this->net());   // تبقى مطروحة
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $result['batch_id'], 'tab' => 'balances']))->assertSee('دفعات مصدَّرة لم تنعكس في هذا الملف (1)');
        $this->actingAs($this->accountant)->get(route('accountant.index'))->assertSee('لم ينخفض الرصيد بما يقابلها');
        $this->assertDatabaseHas('activity_logs', ['action' => 'customers.exported_unreflected']);

        // ملف لاحق انعكست فيه الدفعة
        $this->balances(4100);
        $this->assertNotNull($payment->fresh()->reflected_at);
        $this->assertNotNull($s->fresh()->reviewed_at);
        $this->assertSame(4100.0, $this->net());
        $this->actingAs($this->accountant)->get(route('accountant.index'))->assertDontSee('لم ينخفض الرصيد بما يقابلها');
    }

    public function test_review_clears_unreflected_warning(): void
    {
        $payment = $this->pay(900);
        $this->exportSheet();
        $this->balances(5000);
        $s = $this->snapshot();

        $this->actingAs($this->supervisor)->post(route('finance.balance-changes.review', $s), ['note' => 'x'])->assertForbidden();
        $this->actingAs($this->accountant)->post(route('finance.balance-changes.review', $s), ['note' => 'رُحّل يدوياً'])->assertSessionHas('success');
        $this->assertSame('رُحّل يدوياً', $s->fresh()->review_note);
        $this->assertNotNull($payment->fresh()->reflected_at);
        $this->assertSame(5000.0, $this->net());
    }

    public function test_balance_changes_screen_permissions(): void
    {
        $this->balances(4800);
        foreach ([$this->admin, $this->accountant, $this->supervisor, $this->manager, $this->repSupervisor] as $user) {
            $this->actingAs($user)->get(route('finance.balance-changes'))->assertOk()->assertSee('تغيّرات الأرصدة')->assertSee('التفسير تقديري');
        }
        $this->actingAs($this->supervisor)->get(route('finance.balance-changes'))->assertDontSee('تمّت المراجعة</button>', false);
        $this->actingAs($this->accountant)->get(route('finance.balance-changes'))->assertSee('تمّت المراجعة');
        $this->actingAs($this->d1)->get(route('finance.balance-changes'))->assertForbidden();
        $this->actingAs($this->repA)->get(route('finance.balance-changes'))->assertForbidden();
    }

    public function test_management_scenario_closed_changed_explains_balance_before_applying(): void
    {
        // بعد إغلاق وتصدير: ملف حركة بالفاتورة 800 وأرصدة 4800 في الملف نفسه
        $this->pay(900);
        $this->actingAs($this->supervisor)->post(route('supervisor.complete', $this->sheet->id))->assertSessionHas('success');
        $this->exportSheet();
        $result = $this->upload($this->dailyFile(800, ['صيدلية التجربة' => 4800] + $this->others()));

        $this->assertSame(ImportBatchItem::CLOSED_CHANGED, ImportBatchItem::where('batch_id', $result['batch_id'])->where('invoice_number', 'ع G: 1')->value('kind'));
        $s = $this->snapshot();
        $this->assertSame(-200.0, (float) $s->delta);
        $this->assertSame(-200.0, (float) $s->invoice_effect_sum);
        $this->assertStringContainsString('تغيّر مُقترح من الملف لفاتورة G: 1 (مندوب مندوب أ) −200 (كشف مكتمل، بانتظار التسوية)', $s->explanation);
        // والدفعة المصدَّرة 900 لم تنعكس في 4800 (5000 − 200) فيُنبَّه عليها أيضاً
        $this->assertTrue($s->exported_mismatch);
        $this->actingAs($this->accountant)->get(route('finance.balance-changes'))->assertSee('مقترح من الملف')->assertSee('مندوب أ')->assertSee('تعديل لاحق');
    }
}
