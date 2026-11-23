<?php

namespace Tests\Feature\Phase2B;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\ImportBatchItem;
use App\Models\Invoice;
use App\Models\InvoiceChangeProposal;
use App\Models\User;
use App\Services\CustomerBalanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Workbooks;
use Tests\Support\FieldActivity;
use Tests\TestCase;

/**
 * J) التحديث بين الملفات: المطابقة عبر invoice_key والبصمة (InvoiceReconciler).
 */
class ReconciliationTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity;

    private User $manager;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->manager = User::factory()->role(Role::SheetManager)->create();
        $this->admin = User::factory()->role(Role::Admin)->create();
    }

    private function wb(array $invoices): string
    {
        return $this->buildWorkbook($invoices, [
            ['صيدلية ألف', 'كتلة 1', 'منطقة أ', 10000, 0],
            ['صيدلية باء', 'كتلة 1', 'منطقة ب', 5000, 0],
        ]);
    }

    private function import(string $path, string $date = '2026-10-13'): array
    {
        return $this->importFiles($path, $date, $this->manager);
    }

    /** المرحلة 7A: عنصر الدفعة لفاتورة */
    private function item(array $result, string $number): ImportBatchItem
    {
        return ImportBatchItem::where('batch_id', $result['batch_id'])->where('invoice_number', $number)->sole();
    }

    public function test_deferred_yesterday_moves_to_its_order_sheet_today_without_duplication(): void
    {
        $file = $this->wb([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'delivery' => '14-10-2026', 'lines' => [['صنف', 100]]],
            ['number' => 'ع G: 2', 'customer' => 'صيدلية باء', 'delivery' => '13-10-2026', 'lines' => [['صنف', 200]]],
        ]);

        $this->import($file, '2026-10-13');
        $this->assertSame(Invoice::DEFERRED, Invoice::where('invoice_number', 'ع G: 1')->first()->placement);

        $this->import($file, '2026-10-14');

        $this->assertSame(1, Invoice::where('invoice_number', 'ع G: 1')->count());
        $invoice = Invoice::where('invoice_number', 'ع G: 1')->first();
        $this->assertSame(Invoice::IN_SHEET, $invoice->placement);
        $sheet = $invoice->distributionSheet;
        $this->assertSame('2026-10-14', $sheet->distribution_date->toDateString());
        $this->assertSame('3918', $sheet->delivery_order_no);
        $this->assertSame('preparing', $sheet->status);
        $this->assertSame(2, Invoice::count());
    }

    public function test_modified_deferred_invoice_is_updated_from_the_new_file(): void
    {
        $this->import($this->wb([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'delivery' => '20-10-2026', 'lines' => [['صنف', 100]]],
        ]));
        $result = $this->import($this->wb([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'delivery' => '20-10-2026', 'lines' => [['صنف', 100], ['صنف جديد', 50]]],
        ]));

        // المرحلة 7A: لا تحديث قبل القرار؛ عنصر «معدّلة» معلّق
        $invoice = Invoice::where('invoice_number', 'ع G: 1')->first();
        $this->assertEquals(100, (float) $invoice->invoice_amount);
        $item = $this->item($result, 'ع G: 1');
        $this->assertSame([ImportBatchItem::CHANGED, ImportBatchItem::PENDING], [$item->kind, $item->status]);

        $this->actingAs($this->manager)->post(route('sheet-manager.items.apply', $item))->assertSessionHasNoErrors();
        $invoice->refresh();
        $this->assertEquals(150, (float) $invoice->invoice_amount);
        $this->assertSame(2, $invoice->lines()->count());
        $this->assertSame(Invoice::DEFERRED, $invoice->placement);
        $this->assertSame(1, Invoice::count());
    }

    public function test_modified_invoice_in_preparing_sheet_is_updated_listed_and_moved_with_its_order(): void
    {
        $this->import($this->wb([['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]]]));
        $result = $this->import($this->wb([['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 175]]]]));

        // المرحلة 7A: لا تحديث صامت حتى في «قيد التجهيز»
        $invoice = Invoice::where('invoice_number', 'ع G: 1')->first();
        $this->assertEquals(100, (float) $invoice->invoice_amount);
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', $result['batch']))
            ->assertOk()->assertSee('مراجعة الفروق')->assertSee('175.00');
        $this->actingAs($this->manager)->post(route('sheet-manager.items.apply', $this->item($result, 'ع G: 1')))->assertSessionHasNoErrors();
        $invoice->refresh();
        $this->assertEquals(175, (float) $invoice->invoice_amount);
        $this->assertSame('175.00', $invoice->distributionSheet->total_all);

        // تغيّر أمر التسليم: بعد التطبيق تنتقل إلى كشف أمرها الجديد إن وُجد مفتوحاً (يوجد هنا لفاتورة أخرى بالأمر نفسه)
        $moved = $this->import($this->wb([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'order' => 4000, 'lines' => [['صنف', 175]]],
            ['number' => 'ع G: 7', 'customer' => 'صيدلية باء', 'order' => 4000, 'lines' => [['صنف', 10]]],
        ]));
        $this->assertSame(ImportBatchItem::ORDER_MOVED, $this->item($moved, 'ع G: 1')->kind);
        $this->actingAs($this->manager)->post(route('sheet-manager.items.apply', $this->item($moved, 'ع G: 1')))->assertSessionHasNoErrors();
        $invoice->refresh();
        $this->assertSame('4000', $invoice->distributionSheet->delivery_order_no);
        $this->assertSame('0.00', DistributionSheet::where('delivery_order_no', '3918')->first()->total_all);
        $this->assertSame(2, Invoice::count());
    }

    public function test_modified_invoice_in_published_sheet_creates_a_proposal(): void
    {
        $sheet = $this->publishWorkbook($this->wb([['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]]]));
        $this->assertSame('pending', $sheet->status);

        // الملف نفسه مرة أخرى: لا اقتراح
        $this->import($this->wb([['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]]]));
        $this->assertSame(0, InvoiceChangeProposal::count());

        $result = $this->import($this->wb([['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 130]]]]));

        $invoice = Invoice::where('invoice_number', 'ع G: 1')->first();
        $this->assertEquals(100, (float) $invoice->invoice_amount);       // لا تعديل تلقائي
        $proposal = InvoiceChangeProposal::firstOrFail();
        $this->assertSame(InvoiceChangeProposal::PENDING, $proposal->status);
        $this->assertSame($result['batch_id'], $proposal->batch_id);
        $this->assertSame($invoice->content_hash, $proposal->old_hash);

        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', $result['batch']))
            ->assertOk()->assertSee('مراجعة الفروق')->assertSee('بانتظار قرارك')->assertSee('130.00');

        // تطبيقه يعدّل الفاتورة (المسار القديم للاقتراح يمر الآن عبر عنصر الدفعة)
        $this->actingAs($this->manager)->post(route('sheet-manager.proposals.apply', $proposal))->assertSessionHasNoErrors();
        $this->assertEquals(130, (float) $invoice->fresh()->invoice_amount);
        $this->assertSame('130.00', $sheet->fresh()->total_all);
        $this->assertSame(InvoiceChangeProposal::APPLIED, $proposal->fresh()->status);
        $this->assertDatabaseHas('activity_logs', ['action' => 'import.item_applied', 'subject_id' => $invoice->id]);
    }

    public function test_applying_a_proposal_is_blocked_when_customer_has_activity_except_for_admin(): void
    {
        $sheet = $this->publishWorkbook($this->wb([['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]]]));
        $this->import($this->wb([['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 130]]]]));
        $invoice = Invoice::where('invoice_number', 'ع G: 1')->first();
        $visit = $this->markActivity($invoice);
        $proposal = InvoiceChangeProposal::firstOrFail();

        $this->actingAs($this->manager)->post(route('sheet-manager.proposals.apply', $proposal))->assertSessionHasErrors('invoice');
        $this->assertEquals(100, (float) $invoice->fresh()->invoice_amount);

        $this->actingAs($this->admin)->post(route('sheet-manager.proposals.apply', $proposal))->assertSessionHasNoErrors();
        $this->assertEquals(130, (float) $invoice->fresh()->invoice_amount);
        $this->assertNull($visit->fresh()->voided_at);   // النشاط المسجل لا يُمس
    }

    public function test_rejecting_a_proposal_keeps_the_invoice(): void
    {
        $this->publishWorkbook($this->wb([['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]]]));
        $this->import($this->wb([['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 130]]]]));
        $proposal = InvoiceChangeProposal::firstOrFail();

        $this->actingAs($this->manager)->post(route('sheet-manager.proposals.reject', $proposal))->assertSessionHasNoErrors();
        $this->assertSame(InvoiceChangeProposal::REJECTED, $proposal->fresh()->status);
        $this->assertEquals(100, (float) Invoice::first()->invoice_amount);
        $this->assertDatabaseHas('activity_logs', ['action' => 'import.item_rejected']);
    }

    public function test_invoice_in_completed_sheet_is_not_changed_and_difference_is_kept_for_adjustment(): void
    {
        $sheet = $this->publishWorkbook($this->wb([['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]]]));
        $sheet->update(['status' => 'completed']);

        $result = $this->import($this->wb([['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 120]]]]));

        $this->assertEquals(100, (float) Invoice::first()->invoice_amount);
        $this->assertSame($sheet->id, Invoice::first()->distribution_sheet_id);
        // المرحلة 7A: لا «تُجوهلت» بلا تمييز — عنصر «كشف مكتمل (مختلفة)» بالقيمتين وحمولة post_close
        $item = $this->item($result, 'ع G: 1');
        $this->assertSame([ImportBatchItem::CLOSED_CHANGED, ImportBatchItem::INFO], [$item->kind, $item->status]);
        $this->assertStringContainsString('100.00', $item->reason);
        $this->assertStringContainsString('120.00', $item->reason);
        $proposal = InvoiceChangeProposal::sole();
        $this->assertSame([InvoiceChangeProposal::TYPE_POST_CLOSE, InvoiceChangeProposal::AWAITING_ADJUSTMENT], [$proposal->type, $proposal->status]);
        $this->assertFalse(collect($result['batch']->warnings)->contains(fn ($w) => str_contains($w, 'تُجوهلت')));
    }

    public function test_excluded_invoice_stays_excluded_and_is_reported(): void
    {
        $this->import($this->wb([['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'order' => 0, 'lines' => [['صنف', 100]]]]));
        $invoice = Invoice::first();
        $this->actingAs($this->manager)->post(route('sheet-manager.invoices.exclude', $invoice), ['reason' => 'فاتورة تجريبية']);
        $this->assertSame(Invoice::EXCLUDED, $invoice->fresh()->placement);

        $result = $this->import($this->wb([['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]]]));

        $this->assertSame(Invoice::EXCLUDED, $invoice->fresh()->placement);
        // المرحلة 7A: الأمر تغيّر (0 ← 3918) ⇒ «مستبعدة (معدّلة)» بشرح يقول إنها تبقى مستبعدة
        $item = $this->item($result, 'ع G: 1');
        $this->assertSame([ImportBatchItem::EXCLUDED_CHANGED, ImportBatchItem::PENDING], [$item->kind, $item->status]);
        $this->assertStringContainsString('وتبقى مستبعدة', $item->reason);

        // والملف نفسه مرة أخرى بعد التطبيق: «بلا تغيير» بشرح «مستبعدة سابقاً»
        $this->actingAs($this->manager)->post(route('sheet-manager.items.apply', $item))->assertSessionHasNoErrors();
        $this->assertSame(Invoice::EXCLUDED, $invoice->fresh()->placement);
        $again = $this->import($this->wb([['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]]]));
        $this->assertStringContainsString('مستبعدة سابقاً', $this->item($again, 'ع G: 1')->reason);
    }

    public function test_missing_previous_deferred_is_listed_not_cancelled_and_cancel_removes_it_from_due(): void
    {
        $this->import($this->wb([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'delivery' => '20-10-2026', 'lines' => [['صنف', 400]]],
            ['number' => 'ع G: 2', 'customer' => 'صيدلية باء', 'lines' => [['صنف', 200]]],
        ]), '2026-10-13');

        $second = $this->import($this->wb([
            ['number' => 'ع G: 3', 'customer' => 'صيدلية باء', 'delivery' => '14-10-2026', 'lines' => [['صنف', 60]]],
        ]), '2026-10-14');

        $deferred = Invoice::where('invoice_number', 'ع G: 1')->first();
        $this->assertSame(Invoice::DEFERRED, $deferred->placement);   // لا إلغاء تلقائي

        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $second['batch'], 'tab' => 'deferred']))
            ->assertOk()
            ->assertSee('مؤجلة سابقة غير موجودة في هذا الملف')
            ->assertSee('ع G: 1')
            ->assertSee('اعتبرها ملغاة');

        $customer = Customer::where('name', 'صيدلية ألف')->first();
        $balances = app(CustomerBalanceService::class);
        $this->assertSame(9600.0, $balances->dueBalance($customer, Carbon::parse('2026-10-14')));

        $this->actingAs($this->manager)->post(route('sheet-manager.invoices.cancel', $deferred), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->manager)->post(route('sheet-manager.invoices.cancel', $deferred), ['reason' => 'لم تعد في ملف المحاسبة'])
            ->assertSessionHasNoErrors();

        $this->assertSame(Invoice::CANCELLED, $deferred->fresh()->placement);
        $this->assertSame(10000.0, $balances->dueBalance($customer, Carbon::parse('2026-10-14')));
        $this->assertDatabaseHas('activity_logs', ['action' => 'invoice.cancelled', 'subject_id' => $deferred->id]);

        // والملغاة إن ظهرت في ملف لاحق تُعاد وتُصنَّف من جديد مع تحذير
        $third = $this->import($this->wb([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'delivery' => '20-10-2026', 'lines' => [['صنف', 400]]],
        ]), '2026-10-14');
        // المرحلة 7A: تبقى ملغاة حتى القرار، والعنصر يشرح «كانت ملغاة»
        $item = $this->item($third, 'ع G: 1');
        $this->assertSame(Invoice::CANCELLED, $deferred->fresh()->placement);
        $this->assertSame(ImportBatchItem::PENDING, $item->status);
        $this->assertStringContainsString('كانت ملغاة', $item->reason);
        $this->actingAs($this->manager)->post(route('sheet-manager.items.apply', $item))->assertSessionHasNoErrors();
        $this->assertSame(Invoice::DEFERRED, $deferred->fresh()->placement);
        $this->assertNull($deferred->fresh()->cancelled_reason);
    }

    public function test_keep_hides_deferred_from_missing_section(): void
    {
        $this->import($this->wb([['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'delivery' => '20-10-2026', 'lines' => [['صنف', 400]]]]), '2026-10-13');
        $second = $this->import($this->wb([['number' => 'ع G: 2', 'customer' => 'صيدلية باء', 'lines' => [['صنف', 60]]]]), '2026-10-14');
        $deferred = Invoice::where('invoice_number', 'ع G: 1')->first();

        $this->actingAs($this->manager)->post(route('sheet-manager.invoices.keep', $deferred), ['batch_id' => $second['batch_id']])->assertSessionHasNoErrors();

        $this->assertSame(Invoice::DEFERRED, $deferred->fresh()->placement);
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $second['batch'], 'tab' => 'deferred']))->assertDontSee('مؤجلة سابقة غير موجودة في هذا الملف');
    }
}
