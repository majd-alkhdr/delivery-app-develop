<?php

namespace Tests\Feature\Phase7A;

use App\Enums\Role;
use App\Models\DistributionSheet;
use App\Models\ImportBatchItem;
use App\Models\Invoice;
use App\Models\InvoiceChangeProposal;
use App\Models\User;
use App\Services\Import\ImportReviewService;
use App\Services\Import\SheetImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 7A: الفواتير الغائبة (D، H7) وإغلاق كشف بعناصر معلّقة (E7، H10).
 */
class MissingAndCloseTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity, NoSilence;

    private User $manager;
    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->manager = User::factory()->role(Role::SheetManager)->create();
        $this->supervisor = User::factory()->role(Role::Supervisor)->create();
    }

    private function file(array $rows): string
    {
        return $this->buildWorkbook(array_map(fn ($r) => ['number' => $r[0], 'customer' => $r[1], 'order' => 1580, 'lines' => [['صنف', $r[2]]]], $rows), [
            ['صيدلية واحد', 'كتلة', 'منطقة واحد', 10000, 0],
            ['صيدلية اثنان', 'كتلة', 'منطقة اثنان', 10000, 0],
        ]);
    }

    private function upload(array $rows, bool $checkMissing): array
    {
        $path = $this->file($rows);

        return app(SheetImportService::class)->importBatch([$this->uploaded($path, basename($path))], '2026-10-13', $this->manager, $checkMissing);
    }

    public function test_missing_check_off_creates_no_missing_items_and_says_so(): void
    {
        $this->upload([['ع G: 1', 'صيدلية واحد', 1000], ['ع G: 2', 'صيدلية اثنان', 700]], false);
        $result = $this->upload([['ع G: 2', 'صيدلية اثنان', 700]], false);

        $this->assertNoSilence($result, 1);
        $this->assertSame(0, ImportBatchItem::where('kind', ImportBatchItem::MISSING)->count());
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $result['batch_id'], 'tab' => 'notes']))
            ->assertOk()->assertSee('لم يُفحص غياب الفواتير لأن الملف جزئي');
    }

    public function test_missing_check_on_lists_only_missing_and_apply_moves_to_unassigned(): void
    {
        $this->upload([['ع G: 1', 'صيدلية واحد', 1000], ['ع G: 2', 'صيدلية اثنان', 700]], false);
        $result = $this->upload([['ع G: 2', 'صيدلية اثنان', 700]], true);

        $missing = ImportBatchItem::where('batch_id', $result['batch_id'])->where('kind', ImportBatchItem::MISSING)->get();
        $this->assertCount(1, $missing);
        $item = $missing->sole();
        $this->assertSame('ع G: 1', $item->invoice_number);
        $this->assertSame(ImportBatchItem::PENDING, $item->status);
        $this->assertStringContainsString('ولم تظهر فيه', $item->reason);
        $this->assertStringContainsString('ربما أُلغيت في المحاسبة', $item->reason);
        $this->assertSame(Invoice::IN_SHEET, Invoice::where('invoice_number', 'ع G: 1')->value('placement'));

        $this->actingAs($this->manager)->post(route('sheet-manager.items.apply', $item))->assertSessionHasNoErrors();
        $invoice = Invoice::where('invoice_number', 'ع G: 1')->sole();
        $this->assertSame(Invoice::UNASSIGNED, $invoice->placement);   // لا إلغاء ولا حذف
        $this->assertNull($invoice->distribution_sheet_id);
        $this->assertStringContainsString('غابت عن الملف الأحدث', $item->fresh()->decision_note);
    }

    public function test_missing_ignore_keeps_invoice(): void
    {
        $this->upload([['ع G: 1', 'صيدلية واحد', 1000], ['ع G: 2', 'صيدلية اثنان', 700]], false);
        $result = $this->upload([['ع G: 2', 'صيدلية اثنان', 700]], true);
        $item = ImportBatchItem::where('batch_id', $result['batch_id'])->where('kind', ImportBatchItem::MISSING)->sole();

        $this->actingAs($this->manager)->post(route('sheet-manager.items.reject', $item))->assertSessionHasNoErrors();
        $this->assertSame(Invoice::IN_SHEET, Invoice::where('invoice_number', 'ع G: 1')->value('placement'));
    }

    public function test_closing_sheet_with_pending_items_needs_confirmation_then_keeps_them_for_adjustment(): void
    {
        $first = $this->upload([['ع G: 1', 'صيدلية واحد', 1000]], false);
        app(\App\Services\SheetDispatchService::class)->sendBatch($first['batch'], $this->manager);
        $sheet = DistributionSheet::sole();
        $result = $this->upload([['ع G: 1', 'صيدلية واحد', 900]], false);
        $item = ImportBatchItem::where('batch_id', $result['batch_id'])->where('invoice_number', 'ع G: 1')->sole();
        $this->assertSame(ImportBatchItem::PENDING, $item->status);

        // صفحة الكشف للمشرف تعرض التأكيد بالقائمة
        $this->actingAs($this->supervisor)->get(route('supervisor.sheet.show', $sheet->id))->assertOk()->assertSee('تغيير ينتظر القرار')->assertSee('ع G: 1');

        // بلا تأكيد: لا إغلاق
        $this->actingAs($this->supervisor)->post(route('supervisor.complete', $sheet->id))->assertSessionHas('warning');
        $this->assertSame('pending', $sheet->fresh()->status);

        // بالتأكيد: يُغلق والتغيير يُحفظ للتسوية بعد الإغلاق
        $this->actingAs($this->supervisor)->post(route('supervisor.complete', $sheet->id), ['confirm_pending' => 1])->assertSessionHas('success');
        $this->assertSame('completed', $sheet->fresh()->status);
        $item->refresh();
        $this->assertSame(ImportBatchItem::BLOCKED, $item->status);
        $this->assertSame(ImportReviewService::CLOSED_BLOCK, $item->blocked_reason);
        $this->assertSame([InvoiceChangeProposal::TYPE_POST_CLOSE, InvoiceChangeProposal::AWAITING_ADJUSTMENT], [$item->proposal->type, $item->proposal->status]);
        $this->assertEquals(1000, (float) Invoice::where('invoice_number', 'ع G: 1')->value('invoice_amount'));

        // ولا يمكن تطبيقه بعد الإغلاق
        $admin = User::factory()->role(Role::Admin)->create();
        $this->actingAs($admin)->post(route('sheet-manager.items.apply', $item))->assertSessionHasErrors('item');
        $this->assertEquals(1000, (float) Invoice::where('invoice_number', 'ع G: 1')->value('invoice_amount'));
    }
}
