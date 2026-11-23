<?php

namespace Tests\Feature\Phase2B;

use App\Enums\Role;
use App\Models\DistributionSheet;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\SheetAssignment;
use App\Models\User;
use App\Support\ArabicNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Workbooks;
use Tests\Support\FieldActivity;
use Tests\TestCase;

/**
 * J) الدورة والصلاحيات: الرفع ← التجهيز ← الإرسال ← الإسناد، والسحب والإغلاق كمسودة.
 */
class DispatchCycleTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity;

    private User $admin;
    private User $manager;
    private User $supervisor;
    private User $accountant;
    private User $distributor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->manager = User::factory()->role(Role::SheetManager)->create();
        $this->supervisor = User::factory()->role(Role::Supervisor)->create();
        $this->accountant = User::factory()->role(Role::Accountant)->create();
        $this->distributor = User::factory()->role(Role::Distributor)->create();
    }

    private function wb(array $invoices): string
    {
        return $this->buildWorkbook($invoices, [
            ['صيدلية ألف', 'كتلة 1', 'منطقة أ', 10000, 0],
            ['صيدلية باء', 'كتلة 1', 'منطقة ب', 5000, 0],
        ]);
    }

    private function upload(string $path, string $date = '2026-10-13'): ImportBatch
    {
        $before = ImportBatch::pluck('id');
        $this->actingAs($this->manager)->post(route('sheet-manager.upload'), [
            'excel_files' => [$this->uploaded($path, 'daily.xlsx')],
            'distribution_date' => $date,
        ])->assertRedirect();

        return ImportBatch::whereNotIn('id', $before)->firstOrFail();
    }

    public function test_manager_uploads_and_sends_while_preparing_is_hidden_from_everyone_else(): void
    {
        $batch = $this->upload($this->wb([['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]]]));
        $sheet = DistributionSheet::firstOrFail();
        $this->assertSame('preparing', $sheet->status);

        // حتى لو وُجد إسناد سابق، الموزع لا يرى كشفاً قيد التجهيز
        SheetAssignment::create(['sheet_id' => $sheet->id, 'distributor_id' => $this->distributor->id]);

        $this->actingAs($this->supervisor)->get(route('supervisor.index'))
            ->assertOk()->assertViewHas('sheets', fn ($sheets) => $sheets->isEmpty());
        $this->actingAs($this->supervisor)->get(route('supervisor.sheet.show', $sheet->id))->assertNotFound();
        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $sheet), ['all_distributor_id' => $this->distributor->id])->assertNotFound();
        $this->actingAs($this->accountant)->get(route('accountant.index'))->assertViewHas('sheets', fn ($sheets) => $sheets->isEmpty());
        $this->actingAs($this->accountant)->get(route('accountant.sheet.show', $sheet->id))->assertNotFound();
        $this->actingAs($this->distributor)->get(route('distributor.index'))->assertViewHas('sheets', fn ($sheets) => $sheets->isEmpty());
        $this->actingAs($this->distributor)->get(route('distributor.sheet', $sheet->id))->assertForbidden();

        // المشرف لا يرفع ولا يرسل
        $this->actingAs($this->supervisor)->post(route('supervisor.upload'))->assertForbidden();
        $this->actingAs($this->supervisor)->post(route('sheet-manager.sheets.send', $sheet))->assertForbidden();
        $this->actingAs($this->manager)->get(route('supervisor.upload'))->assertRedirect(route('sheet-manager.index'));

        // مسؤول الكشوف يرى المعاينة والكشف ويرسل
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', $batch))->assertOk()->assertSee('#3918');
        $this->actingAs($this->manager)->get(route('sheet-manager.sheets.show', $sheet))->assertOk()->assertSee('ع G: 1');
        $this->actingAs($this->manager)->post(route('sheet-manager.sheets.send', $sheet))->assertSessionHasNoErrors();

        $sheet->refresh();
        $this->assertSame('pending', $sheet->status);
        $this->assertNotNull($sheet->sent_at);
        $this->assertSame($this->manager->id, $sheet->sent_by);
        $this->assertDatabaseHas('activity_logs', ['action' => 'sheet.sent', 'subject_id' => $sheet->id]);

        // بعد الإرسال: يظهر للمشرف والمحاسب
        $this->actingAs($this->supervisor)->get(route('supervisor.sheet.show', $sheet->id))->assertOk();
        $this->actingAs($this->accountant)->get(route('accountant.sheet.show', $sheet->id))->assertOk();
        $this->actingAs($this->distributor)->get(route('distributor.sheet', $sheet->id))->assertOk();
    }

    public function test_sent_sheet_without_assignment_shows_awaiting_badge(): void
    {
        $this->publishWorkbook($this->wb([['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]]]));

        $this->actingAs($this->supervisor)->get(route('supervisor.index'))
            ->assertSee('بانتظار الإسناد')
            ->assertSee('منطقة بلا موزع');
    }

    public function test_send_is_rejected_with_unassigned_invoices_until_resolved(): void
    {
        $batch = $this->upload($this->wb([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]],
            ['number' => 'ع G: 2', 'customer' => 'صيدلية باء', 'order' => 0, 'lines' => [['صنف', 50]]],
        ]));
        $sheet = DistributionSheet::firstOrFail();
        $loose = Invoice::where('invoice_number', 'ع G: 2')->first();

        $this->actingAs($this->manager)->post(route('sheet-manager.batches.send', $batch))->assertSessionHasErrors('send');
        $this->assertSame('preparing', $sheet->fresh()->status);

        $this->actingAs($this->manager)->post(route('sheet-manager.invoices.place', $loose), ['sheet_id' => $sheet->id])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('sheet-manager.batches.send', $batch))->assertSessionHasNoErrors();
        $this->assertSame('pending', $sheet->fresh()->status);
    }

    public function test_send_is_rejected_with_pending_items_of_that_sheet_until_decided(): void
    {
        // المرحلة 7A: العنصر المعلّق يمنع إرسال كشفه هو (قيد التجهيز)، لا كل كشوف الدفعة
        $this->publishWorkbook($this->wb([['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]]]));
        $this->upload($this->wb([['number' => 'ع G: 5', 'customer' => 'صيدلية باء', 'order' => 5000, 'lines' => [['صنف', 70]]]]));
        $new = DistributionSheet::where('delivery_order_no', '5000')->firstOrFail();

        // إعادة رفع: G:1 في كشف منشور تغيّرت (لا تمنع الكشف 5000)، وG:5 في الكشف 5000 تغيّرت (تمنعه)
        $batch = $this->upload($this->wb([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 130]]],
            ['number' => 'ع G: 5', 'customer' => 'صيدلية باء', 'order' => 5000, 'lines' => [['صنف', 75]]],
        ]));

        $this->actingAs($this->manager)->post(route('sheet-manager.sheets.send', $new))->assertSessionHasErrors('send');
        $this->actingAs($this->manager)->get(route('sheet-manager.sheets.show', $new))->assertSee('عرض العناصر المعلّقة لهذا الكشف');

        $item = \App\Models\ImportBatchItem::where('batch_id', $batch->id)->where('invoice_number', 'ع G: 5')->sole();
        $this->actingAs($this->manager)->post(route('sheet-manager.items.reject', $item));
        $this->actingAs($this->manager)->post(route('sheet-manager.sheets.send', $new))->assertSessionHasNoErrors();
        $this->assertSame('pending', $new->fresh()->status);
        $this->assertEquals(70, (float) \App\Models\Invoice::where('invoice_number', 'ع G: 5')->value('invoice_amount'));
    }

    public function test_customer_in_two_sheets_is_a_conflict_until_acknowledged(): void
    {
        $batch = $this->upload($this->wb([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]],
            ['number' => 'ع G: 2', 'customer' => 'صيدلية ألف', 'order' => 4000, 'lines' => [['صنف', 50]]],
        ]));

        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', $batch))
            ->assertOk()
            ->assertSee('زبائن لهم فواتير في أكثر من كشف')
            ->assertSee('أقرّ بهذه التعارضات');

        $this->actingAs($this->manager)->post(route('sheet-manager.batches.send', $batch))->assertSessionHasErrors('send');
        $this->assertSame(2, DistributionSheet::where('status', 'preparing')->count());

        $this->actingAs($this->manager)->post(route('sheet-manager.batches.acknowledge', $batch))->assertSessionHasNoErrors();
        $this->assertNotNull($batch->fresh()->conflicts_acknowledged_at);
        $this->assertSame($this->manager->id, $batch->fresh()->conflicts_acknowledged_by);
        $this->assertDatabaseHas('activity_logs', ['action' => 'batch.conflicts_acknowledged']);

        $this->actingAs($this->manager)->post(route('sheet-manager.batches.send', $batch))->assertSessionHasNoErrors();
        $this->assertSame(2, DistributionSheet::where('status', 'pending')->count());
    }

    public function test_conflict_is_resolved_by_moving_the_invoice(): void
    {
        $batch = $this->upload($this->wb([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]],
            ['number' => 'ع G: 2', 'customer' => 'صيدلية ألف', 'order' => 4000, 'lines' => [['صنف', 50]]],
        ]));
        $main = DistributionSheet::where('delivery_order_no', '3918')->first();

        $this->actingAs($this->manager)->post(route('sheet-manager.invoices.move'), [
            'invoice_ids' => [Invoice::where('invoice_number', 'ع G: 2')->first()->id],
            'sheet_id' => $main->id,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->post(route('sheet-manager.sheets.send', $main))->assertSessionHasNoErrors();
        $this->assertSame('pending', $main->fresh()->status);
    }

    public function test_recall_works_without_assignment_or_activity_and_is_admin_only_after(): void
    {
        $sheet = $this->publishWorkbook($this->wb([['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]]]));

        $this->actingAs($this->manager)->post(route('sheet-manager.sheets.recall', $sheet))->assertSessionHasNoErrors();
        $this->assertSame('preparing', $sheet->fresh()->status);
        $this->assertNull($sheet->fresh()->sent_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'sheet.recalled', 'subject_id' => $sheet->id]);

        $this->actingAs($this->manager)->post(route('sheet-manager.sheets.send', $sheet))->assertSessionHasNoErrors();
        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $sheet), ['all_distributor_id' => $this->distributor->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->post(route('sheet-manager.sheets.recall', $sheet))->assertSessionHasErrors('recall');
        $this->assertSame('pending', $sheet->fresh()->status);

        $this->actingAs($this->admin)->post(route('sheet-manager.sheets.recall', $sheet))->assertSessionHasNoErrors();
        $this->assertSame('preparing', $sheet->fresh()->status);
        $this->assertSame(1, $sheet->assignments()->count());   // الإسناد محفوظ
        $this->actingAs($this->distributor)->get(route('distributor.sheet', $sheet->id))->assertForbidden();
    }

    public function test_recall_is_blocked_after_activity_for_manager(): void
    {
        $sheet = $this->publishWorkbook($this->wb([['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]]]));
        $this->markActivity(Invoice::first());

        $this->actingAs($this->manager)->post(route('sheet-manager.sheets.recall', $sheet))->assertSessionHasErrors('recall');
        $this->assertSame('pending', $sheet->fresh()->status);
    }

    public function test_draft_requires_reason_and_releases_invoices_to_unassigned(): void
    {
        $batch = $this->upload($this->wb([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]],
            ['number' => 'ع G: 2', 'customer' => 'صيدلية باء', 'lines' => [['صنف', 50]]],
        ]));
        $sheet = DistributionSheet::firstOrFail();
        SheetAssignment::create(['sheet_id' => $sheet->id, 'distributor_id' => $this->distributor->id]);

        // المشرف لا يغلق كشفاً قيد التجهيز (لا يراه أصلاً)
        $this->actingAs($this->supervisor)->post(route('supervisor.sheet.draft', $sheet), ['reason' => 'خطأ'])->assertNotFound();

        $this->actingAs($this->manager)->post(route('sheet-manager.sheets.draft', $sheet), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame('preparing', $sheet->fresh()->status);

        $this->actingAs($this->manager)->post(route('sheet-manager.sheets.draft', $sheet), ['reason' => 'أمر تسليم خاطئ'])->assertSessionHasNoErrors();

        $sheet->refresh();
        $this->assertSame('draft', $sheet->status);
        $this->assertSame('أمر تسليم خاطئ', $sheet->status_reason);
        $this->assertSame(0, $sheet->invoices()->count());
        $this->assertSame(2, Invoice::where('placement', Invoice::UNASSIGNED)->whereNull('distribution_sheet_id')->count());
        $this->assertSame(1, $sheet->assignments()->count());   // الإسنادات لا تُحذف
        $this->assertNotNull(DistributionSheet::find($sheet->id)); // لا حذف للكشف

        // المسؤول لا يغلق كشفاً مُرسلاً (هذا للمشرف)
        $other = $this->publishWorkbook($this->wb([['number' => 'ع G: 9', 'customer' => 'صيدلية ألف', 'order' => 7000, 'lines' => [['صنف', 10]]]]));
        $this->actingAs($this->manager)->post(route('sheet-manager.sheets.draft', $other), ['reason' => 'سبب ما'])->assertSessionHas('error');
        $this->assertSame('pending', $other->fresh()->status);

        // والفواتير المُفرَّغة تظهر في "بلا كشف" بمعاينة الدفعة
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', $batch))->assertSee('فواتير بلا كشف (2)');
    }
}
