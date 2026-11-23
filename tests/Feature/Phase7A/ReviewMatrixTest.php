<?php

namespace Tests\Feature\Phase7A;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\ImportBatchItem;
use App\Models\Invoice;
use App\Models\InvoiceChangeProposal;
use App\Models\InvoiceRevision;
use App\Models\SheetChangeNotice;
use App\Models\User;
use App\Services\SheetDispatchService;
use App\Services\SheetInvoiceEditor;
use App\Support\ArabicNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 7A (H2–H6، H8، H9): مطابقة إعادة الرفع تبني عناصر ولا تعدّل أي فاتورة قبل القرار.
 * الإعداد: G:1=1000 لـ«صيدلية واحد» (منطقة واحد، موزع 1)، G:2=700 لـ«صيدلية اثنان» (منطقة اثنان، موزع 2)، أمر 1580.
 */
class ReviewMatrixTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity, NoSilence;

    private User $admin;
    private User $manager;
    private User $supervisor;
    private User $accountant;
    private User $d1;
    private User $d2;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->manager = User::factory()->role(Role::SheetManager)->create();
        $this->supervisor = User::factory()->role(Role::Supervisor)->create();
        $this->accountant = User::factory()->role(Role::Accountant)->create();
        $this->d1 = User::factory()->role(Role::Distributor)->create();
        $this->d2 = User::factory()->role(Role::Distributor)->create();
    }

    private function file(array $rows): string
    {
        return $this->buildWorkbook(array_map(fn ($r) => [
            'number' => $r[0], 'customer' => $r[1], 'order' => $r[3], 'cc' => '655231-مندوب', 'lines' => [['صنف '.$r[0], $r[2]]],
        ], $rows), [
            ['صيدلية واحد', 'كتلة', 'منطقة واحد', 10000, 0],
            ['صيدلية اثنان', 'كتلة', 'منطقة اثنان', 10000, 0],
        ]);
    }

    private function base(): array
    {
        return [['ع G: 1', 'صيدلية واحد', 1000, 1580], ['ع G: 2', 'صيدلية اثنان', 700, 1580]];
    }

    private function import(array $rows): array
    {
        $result = $this->importFiles($this->file($rows), '2026-10-13', $this->manager);
        $this->assertNoSilence($result, count($rows));

        return $result;
    }

    /** الكشف 1580 بالحالة المطلوبة (visit: other = زيارة صيدلية اثنان، own = زيارة صيدلية واحد) */
    private function prepare(string $status, ?string $visit = null): DistributionSheet
    {
        $result = $this->import($this->base());
        $sheet = DistributionSheet::where('delivery_order_no', '1580')->sole();
        if ($status === 'preparing') {
            return $sheet;
        }
        app(SheetDispatchService::class)->sendBatch($result['batch'], $this->manager);
        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $sheet), ['rows' => [
            ['area_key' => ArabicNormalizer::normalize('منطقة واحد'), 'distributor_id' => $this->d1->id],
            ['area_key' => ArabicNormalizer::normalize('منطقة اثنان'), 'distributor_id' => $this->d2->id],
        ]])->assertSessionHasNoErrors();
        if ($visit === 'other') {
            $this->visitApi($this->d2, $sheet, Customer::where('name', 'صيدلية اثنان')->value('id'))->assertSuccessful();
        } elseif ($visit === 'own') {
            $this->visitApi($this->d1, $sheet, Customer::where('name', 'صيدلية واحد')->value('id'))->assertSuccessful();
        }
        if ($status === 'completed') {
            $sheet->update(['status' => 'completed']);
        }

        return $sheet->fresh();
    }

    private function item(array $result, string $number): ImportBatchItem
    {
        return ImportBatchItem::where('batch_id', $result['batch_id'])->where('invoice_number', $number)->sole();
    }

    private function inv(string $number): Invoice
    {
        return Invoice::where('invoice_number', $number)->sole();
    }

    public static function states(): array
    {
        return [
            'preparing' => ['preparing', null],
            'pending' => ['pending', null],
            'in_progress بعد زيارة صيدلية أخرى' => ['in_progress', 'other'],
            'in_progress بعد زيارة صيدليتها' => ['in_progress', 'own'],
            'completed' => ['completed', null],
        ];
    }

    /** من يطبّق: الأدمن إن كان على الزبون نشاط، وإلا مسؤول الكشوف */
    private function decider(?string $visit): User
    {
        return $visit === 'own' ? $this->admin : $this->manager;
    }

    #[DataProvider('states')]
    public function test_unchanged_reupload(string $status, ?string $visit): void
    {
        $sheet = $this->prepare($status, $visit);
        $result = $this->import($this->base());

        $item = $this->item($result, 'ع G: 1');
        $this->assertSame($status === 'completed' ? ImportBatchItem::CLOSED_UNCHANGED : ImportBatchItem::UNCHANGED, $item->kind);
        $this->assertSame(ImportBatchItem::INFO, $item->status);
        $this->assertSame('أمر 1580', $item->sheet_label);
        $this->assertSame($sheet->id, $item->sheet_id);
        $this->assertSame(0, InvoiceChangeProposal::count());
    }

    #[DataProvider('states')]
    public function test_amount_changed(string $status, ?string $visit): void
    {
        $sheet = $this->prepare($status, $visit);
        $rows = $this->base();
        $rows[0][2] = 900;
        $result = $this->import($rows);
        $item = $this->item($result, 'ع G: 1');

        $this->assertEquals(1000, (float) $this->inv('ع G: 1')->invoice_amount);   // لا تعديل قبل القرار
        if ($status === 'completed') {
            $this->assertSame([ImportBatchItem::CLOSED_CHANGED, ImportBatchItem::INFO], [$item->kind, $item->status]);

            return;
        }
        $this->assertSame([ImportBatchItem::CHANGED, ImportBatchItem::PENDING], [$item->kind, $item->status]);
        $this->assertSame(1000.0, (float) $item->old['amount']);
        $this->assertSame(900.0, (float) $item->new['amount']);
        $this->assertStringContainsString('1,000.00 ← 900.00', $item->reason);

        $this->actingAs($this->decider($visit))->post(route('sheet-manager.items.apply', $item))->assertSessionHasNoErrors();
        $invoice = $this->inv('ع G: 1');
        $this->assertEquals(900, (float) $invoice->invoice_amount);
        $this->assertSame($sheet->id, $invoice->distribution_sheet_id);
        $this->assertSame(ImportBatchItem::APPLIED, $item->fresh()->status);
        $this->assertSame('1600.00', $sheet->fresh()->total_all);
        $this->assertDatabaseHas('activity_logs', ['action' => 'import.item_applied', 'subject_id' => $invoice->id]);
        if ($status !== 'preparing') {
            $this->assertSame('updated', SheetChangeNotice::where('invoice_id', $invoice->id)->value('type'));
        }
    }

    #[DataProvider('states')]
    public function test_order_removed(string $status, ?string $visit): void
    {
        $sheet = $this->prepare($status, $visit);
        $rows = $this->base();
        $rows[0][3] = 0;
        $result = $this->import($rows);
        $item = $this->item($result, 'ع G: 1');

        $this->assertSame($sheet->id, $this->inv('ع G: 1')->distribution_sheet_id);
        if ($status === 'completed') {
            $this->assertSame(ImportBatchItem::CLOSED_CHANGED, $item->kind);

            return;
        }
        $this->assertSame([ImportBatchItem::ORDER_REMOVED, ImportBatchItem::PENDING], [$item->kind, $item->status]);
        $this->assertStringContainsString('أمر التسليم تغيّر من 1580', $item->reason);

        $this->actingAs($this->decider($visit))->post(route('sheet-manager.items.apply', $item))->assertSessionHasNoErrors();
        $invoice = $this->inv('ع G: 1');
        $this->assertSame(Invoice::UNASSIGNED, $invoice->placement);
        $this->assertNull($invoice->distribution_sheet_id);
        $this->assertNull($invoice->delivery_order_no);
        $this->assertStringContainsString('أمر التسليم تغيّر من 1580', $item->fresh()->decision_note);
        if ($status !== 'preparing') {
            $this->assertSame('removed', SheetChangeNotice::where('invoice_id', $invoice->id)->value('type'));
        }
    }

    #[DataProvider('states')]
    public function test_order_moved_to_another_sheet(string $status, ?string $visit): void
    {
        $sheet = $this->prepare($status, $visit);
        $rows = $this->base();
        $rows[0][3] = 1600;
        array_unshift($rows, ['ع G: 9', 'صيدلية اثنان', 50, 1600]);   // أولاً: يُنشئ كشف الأمر 1600 (قيد التجهيز)
        $result = $this->import($rows);
        $item = $this->item($result, 'ع G: 1');
        $target = DistributionSheet::where('delivery_order_no', '1600')->sole();

        $this->assertSame($sheet->id, $this->inv('ع G: 1')->distribution_sheet_id);
        if ($status === 'completed') {
            $this->assertSame(ImportBatchItem::CLOSED_CHANGED, $item->kind);

            return;
        }
        $this->assertSame([ImportBatchItem::ORDER_MOVED, ImportBatchItem::PENDING], [$item->kind, $item->status]);
        $this->assertSame($target->id, $item->target_sheet_id);

        $this->actingAs($this->decider($visit))->post(route('sheet-manager.items.apply', $item))->assertSessionHasNoErrors();
        $invoice = $this->inv('ع G: 1');
        $this->assertSame(Invoice::IN_SHEET, $invoice->placement);
        $this->assertSame($target->id, $invoice->distribution_sheet_id);   // نُقلت فعلاً
        $this->assertSame('1600', $invoice->delivery_order_no);
    }

    public function test_order_moved_without_open_sheet_goes_to_unassigned_with_explanation(): void
    {
        $this->prepare('pending');
        $rows = $this->base();
        $rows[0][3] = 1600;
        $result = $this->import($rows);
        $item = $this->item($result, 'ع G: 1');
        $this->assertStringContainsString('لا يوجد كشف مفتوح للأمر 1600', $item->reason);

        $this->actingAs($this->manager)->post(route('sheet-manager.items.apply', $item))->assertSessionHasNoErrors();
        $this->assertSame(Invoice::UNASSIGNED, $this->inv('ع G: 1')->placement);
        $this->assertStringContainsString('لا يوجد كشف مفتوح للأمر 1600', $item->fresh()->decision_note);
    }

    #[DataProvider('states')]
    public function test_new_invoice_for_existing_order(string $status, ?string $visit): void
    {
        $sheet = $this->prepare($status, $visit);
        $rows = $this->base();
        $rows[] = ['ع G: 3', 'صيدلية واحد', 250, 1580];
        $result = $this->import($rows);
        $item = $this->item($result, 'ع G: 3');
        $invoice = $this->inv('ع G: 3');
        $this->assertSame(ImportBatchItem::CREATED, $item->kind);

        if ($status === 'preparing') {
            $this->assertSame(ImportBatchItem::INFO, $item->status);
            $this->assertSame($sheet->id, $invoice->distribution_sheet_id);

            return;
        }
        if ($status === 'completed') {
            // المرحلة 7H: كانت «بلا كشف»؛ صارت تدخل «ملحق 1» قيد التجهيز، والأصل المكتمل لا يُمس
            $this->assertSame(ImportBatchItem::INFO, $item->status);
            $this->assertSame(Invoice::IN_SHEET, $invoice->placement);
            $this->assertNotSame($sheet->id, $invoice->distribution_sheet_id);
            $this->assertSame(1, $invoice->distributionSheet->supplement_no);
            $this->assertSame($sheet->id, $invoice->distributionSheet->parent_sheet_id);
            $this->assertStringContainsString('كشفه مكتمل، فأُنشئ ملحق 1', $item->reason);

            return;
        }

        // H6: كشف منشور ⇒ معلّقة، وتبقى «بلا كشف» حتى القرار
        $this->assertSame(ImportBatchItem::PENDING, $item->status);
        $this->assertSame(Invoice::UNASSIGNED, $invoice->placement);
        $this->assertSame($sheet->id, $item->target_sheet_id);
        $this->assertSame(0, SheetChangeNotice::count());

        $this->actingAs($this->decider($visit))->post(route('sheet-manager.items.apply', $item))->assertSessionHasNoErrors();
        $invoice->refresh();
        $this->assertSame(Invoice::IN_SHEET, $invoice->placement);
        $this->assertSame($sheet->id, $invoice->distribution_sheet_id);
        $this->assertSame('added', SheetChangeNotice::where('invoice_id', $invoice->id)->value('type'));
        $this->assertSame((int) $sheet->revision + 1, (int) $sheet->fresh()->revision);
    }

    public function test_guard_is_per_customer_and_admin_only_after_activity(): void
    {
        $sheet = $this->prepare('in_progress', 'own');
        $rows = $this->base();
        $rows[0][2] = 900;   // صيدلية واحد (عليها نشاط)
        $rows[1][2] = 750;   // صيدلية اثنان (بلا نشاط)
        $result = $this->import($rows);
        $own = $this->item($result, 'ع G: 1');
        $other = $this->item($result, 'ع G: 2');

        // العرض: محجوز وزر التطبيق معطّل لمسؤول الكشوف
        $page = $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $result['batch'], 'tab' => 'invoices']))->assertOk();
        $page->assertSee('محجوز: سُجّل نشاط على هذه الصيدلية، يطبّقه المدير فقط.');

        // مسؤول الكشوف يطبّق لزبون بلا نشاط
        $this->actingAs($this->manager)->post(route('sheet-manager.items.apply', $other))->assertSessionHasNoErrors();
        $this->assertEquals(750, (float) $this->inv('ع G: 2')->invoice_amount);

        // ولا يطبّق لزبون عليه نشاط: العنصر يصبح «محجوز»
        $this->actingAs($this->manager)->post(route('sheet-manager.items.apply', $own))->assertSessionHasErrors('item');
        $this->assertSame(ImportBatchItem::BLOCKED, $own->fresh()->status);
        $this->assertEquals(1000, (float) $this->inv('ع G: 1')->invoice_amount);

        // المشرف والمحاسب 403
        $this->actingAs($this->supervisor)->post(route('sheet-manager.items.apply', $own))->assertForbidden();
        $this->actingAs($this->accountant)->post(route('sheet-manager.items.apply', $own))->assertForbidden();

        // الأدمن يطبّق
        $this->actingAs($this->admin)->post(route('sheet-manager.items.apply', $own))->assertSessionHasNoErrors();
        $this->assertEquals(900, (float) $this->inv('ع G: 1')->invoice_amount);
        $this->assertSame(ImportBatchItem::APPLIED, $own->fresh()->status);
    }

    public function test_completed_distinguishes_matching_and_different_and_keeps_post_close_payload(): void
    {
        $this->prepare('completed');
        $rows = $this->base();
        $rows[0][2] = 800;
        $result = $this->import($rows);

        $changed = $this->item($result, 'ع G: 1');
        $same = $this->item($result, 'ع G: 2');
        $this->assertSame(ImportBatchItem::CLOSED_CHANGED, $changed->kind);
        $this->assertSame(ImportBatchItem::CLOSED_UNCHANGED, $same->kind);
        $this->assertStringContainsString('المخزَّن 1,000.00 والجديد 800.00', $changed->reason);

        $proposal = InvoiceChangeProposal::sole();
        $this->assertSame([InvoiceChangeProposal::TYPE_POST_CLOSE, InvoiceChangeProposal::AWAITING_ADJUSTMENT], [$proposal->type, $proposal->status]);
        $this->assertEquals(800, $proposal->payload['invoice_amount']);
        $this->assertSame($changed->proposal_id, $proposal->id);

        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $result['batch'], 'tab' => 'invoices']))
            ->assertOk()->assertSee('كشف مكتمل (مختلفة)')->assertSee('1,000.00')->assertSee('800.00')->assertDontSee('تُجوهلت');
    }

    public function test_manual_placement_is_updated_and_stays_in_place(): void
    {
        $sheet = $this->prepare('preparing');
        // فاتورة «بلا كشف» وُضعت يدوياً في الكشف 1580، وأخرى أُخرجت يدوياً إلى «بلا كشف»
        $this->import([['ع G: 5', 'صيدلية واحد', 300, 0], ['ع G: 6', 'صيدلية اثنان', 60, 0]]);
        $editor = app(SheetInvoiceEditor::class);
        $editor->place($this->inv('ع G: 5'), $sheet, $this->manager);
        $editor->place($this->inv('ع G: 6'), $sheet, $this->manager);
        $editor->remove($this->inv('ع G: 6'), 'ليست لهذا اليوم', $this->manager);
        $this->assertTrue($this->inv('ع G: 5')->manual_placement);

        $result = $this->import([['ع G: 5', 'صيدلية واحد', 350, 0], ['ع G: 6', 'صيدلية اثنان', 65, 0]]);
        $inSheet = $this->item($result, 'ع G: 5');
        $held = $this->item($result, 'ع G: 6');
        $this->assertSame(ImportBatchItem::CHANGED, $inSheet->kind);
        $this->assertStringContainsString('موضوعة يدوياً', $inSheet->reason);
        $this->assertStringContainsString('وتبقى في مكانها', $inSheet->reason);
        $this->assertSame(ImportBatchItem::HELD_CHANGED, $held->kind);
        $this->assertStringContainsString('وتبقى في مكانها', $held->reason);

        $this->actingAs($this->manager)->post(route('sheet-manager.items.apply', $inSheet))->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('sheet-manager.items.apply', $held))->assertSessionHasNoErrors();
        $this->assertEquals(350, (float) $this->inv('ع G: 5')->invoice_amount);
        $this->assertSame($sheet->id, $this->inv('ع G: 5')->distribution_sheet_id);
        $this->assertStringContainsString('لأنها موضوعة يدوياً', $inSheet->fresh()->decision_note);
        $this->assertEquals(65, (float) $this->inv('ع G: 6')->invoice_amount);
        $this->assertSame(Invoice::UNASSIGNED, $this->inv('ع G: 6')->placement);
    }

    public function test_reupload_supersedes_pending_item_and_keeps_older_payload(): void
    {
        $this->prepare('pending');
        $rows = $this->base();
        $rows[0][2] = 900;
        $first = $this->import($rows);
        $rows[0][2] = 950;
        $second = $this->import($rows);

        $old = $this->item($first, 'ع G: 1');
        $new = $this->item($second, 'ع G: 1');
        $this->assertSame(ImportBatchItem::SUPERSEDED, $old->status);
        $this->assertSame($new->id, $old->superseded_by_id);
        $this->assertSame(ImportBatchItem::PENDING, $new->status);
        $this->assertSame(InvoiceChangeProposal::SUPERSEDED, $old->proposal->status);
        $this->assertEquals(900, $old->proposal->payload['invoice_amount']);   // الحمولة الأقدم محفوظة
        $this->assertEquals(950, $new->proposal->payload['invoice_amount']);

        // المرحلة 7E: «حلّ محله أحدث» لا يحتاج قراراً فلا يظهر في المرشّح الافتراضي، ويُصل إليه بمرشّح «الكل»
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $first['batch'], 'tab' => 'invoices', 'kind' => 'all']))
            ->assertOk()->assertSee('حلّ محله عنصر في دفعة أحدث');
        $this->actingAs($this->manager)->post(route('sheet-manager.items.apply', $old))->assertSessionHasErrors('item');
        $this->assertEquals(1000, (float) $this->inv('ع G: 1')->invoice_amount);
    }

    public function test_revision_keeps_old_header_and_lines(): void
    {
        $this->prepare('pending');
        $rows = $this->base();
        $rows[0][2] = 900;
        $result = $this->import($rows);
        $item = $this->item($result, 'ع G: 1');
        $this->actingAs($this->manager)->post(route('sheet-manager.items.apply', $item))->assertSessionHasNoErrors();

        $revision = InvoiceRevision::sole();
        $this->assertSame($this->inv('ع G: 1')->id, $revision->invoice_id);
        $this->assertSame(1, $revision->revision_no);
        $this->assertEquals(1000, $revision->header['invoice_amount']);
        $this->assertSame('1580', $revision->header['delivery_order_no']);
        $this->assertSame('صنف ع G: 1', $revision->lines[0]['item_name']);
        $this->assertEquals(1000, $revision->lines[0]['line_total']);
        $this->assertSame($result['batch_id'], $revision->batch_id);
        $this->assertSame($item->id, $revision->import_batch_item_id);
    }

    public function test_reject_keeps_invoice_and_note(): void
    {
        $this->prepare('pending');
        $rows = $this->base();
        $rows[0][2] = 900;
        $result = $this->import($rows);
        $item = $this->item($result, 'ع G: 1');

        $this->actingAs($this->manager)->post(route('sheet-manager.items.reject', $item), ['note' => 'خطأ في الملف'])->assertSessionHasNoErrors();
        $this->assertSame(ImportBatchItem::REJECTED, $item->fresh()->status);
        $this->assertSame('خطأ في الملف', $item->fresh()->decision_note);
        $this->assertSame(InvoiceChangeProposal::REJECTED, $item->proposal->fresh()->status);
        $this->assertEquals(1000, (float) $this->inv('ع G: 1')->invoice_amount);
        $this->assertDatabaseHas('activity_logs', ['action' => 'import.item_rejected']);
    }

    public function test_apply_all_applies_permitted_and_skips_reserved(): void
    {
        $this->prepare('in_progress', 'own');
        $rows = $this->base();
        $rows[0][2] = 900;
        $rows[1][2] = 750;
        $rows[] = ['ع G: 3', 'صيدلية اثنان', 40, 1580];
        $result = $this->import($rows);

        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $result['batch'], 'tab' => 'invoices']))->assertSee('تطبيق الكل');
        $response = $this->actingAs($this->manager)->post(route('sheet-manager.batches.apply-many', $result['batch']));
        $response->assertSessionHas('apply_results');
        $results = collect(session('apply_results'));
        $this->assertSame(2, $results->where('ok', true)->count());
        $this->assertSame(1, $results->where('status', 'activity')->count());

        $this->assertEquals(750, (float) $this->inv('ع G: 2')->invoice_amount);
        $this->assertSame(Invoice::IN_SHEET, $this->inv('ع G: 3')->placement);
        $this->assertEquals(1000, (float) $this->inv('ع G: 1')->invoice_amount);   // محجوزة: تُخطّيت
        $this->assertSame(ImportBatchItem::PENDING, $this->item($result, 'ع G: 1')->status);
    }
}
