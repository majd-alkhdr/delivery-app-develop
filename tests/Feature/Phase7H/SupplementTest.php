<?php

namespace Tests\Feature\Phase7H;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\DistributionSheet;
use App\Models\ImportBatchItem;
use App\Models\Invoice;
use App\Models\SheetChangeNotice;
use App\Models\User;
use App\Services\Finance\FinanceService;
use App\Services\SheetDispatchService;
use App\Services\SheetExportNamer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 7H (1): كشف «ملحق» لفاتورة جديدة أمرها لكشف مكتمل، ووسم «أُضيفت بعد الإرسال» للكشف المفتوح.
 */
class SupplementTest extends TestCase
{
    use RefreshDatabase, Workbooks;

    private User $manager;
    private User $supervisor;
    private User $d1;
    private DistributionSheet $sheet;

    private array $balances = [
        ['صيدلية واحد', 'كتلة', 'منطقة أ', 5000, 0],
        ['صيدلية اثنان', 'كتلة', 'منطقة أ', 5000, 0],
    ];

    private array $rows = [
        ['number' => 'ع G: 1', 'customer' => 'صيدلية واحد', 'order' => 1580, 'lines' => [['صنف', 1000]]],
        ['number' => 'ع G: 2', 'customer' => 'صيدلية اثنان', 'order' => 1580, 'lines' => [['صنف', 500]]],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->manager = User::factory()->role(Role::SheetManager)->create();
        $this->supervisor = User::factory()->role(Role::Supervisor)->create();
        $this->d1 = User::factory()->role(Role::Distributor)->create();
        $this->sheet = $this->publishWorkbook($this->buildWorkbook($this->rows, $this->balances));
        $this->assign($this->sheet);
    }

    private function assign(DistributionSheet $sheet): void
    {
        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $sheet), ['all_distributor_id' => $this->d1->id])->assertSessionHasNoErrors();
    }

    private function complete(DistributionSheet $sheet): void
    {
        $this->actingAs($this->supervisor)->post(route('supervisor.complete', $sheet->id), ['confirm_pending' => 1, 'confirm_unacked' => 1])->assertSessionHas('success');
    }

    private function upload(array $extra): array
    {
        return $this->importFiles($this->buildWorkbook(array_merge($this->rows, $extra), $this->balances), '2026-10-13', $this->manager);
    }

    private function inv(string $number): Invoice
    {
        return Invoice::where('invoice_number', $number)->sole();
    }

    private function item(array $result, string $number): ImportBatchItem
    {
        return ImportBatchItem::where('batch_id', $result['batch_id'])->where('invoice_number', $number)->sole();
    }

    private function supplements()
    {
        return DistributionSheet::whereNotNull('supplement_no')->orderBy('supplement_no')->get();
    }

    public function test_new_invoice_for_completed_order_creates_supplement_and_follows_the_normal_cycle(): void
    {
        $this->complete($this->sheet);
        $originalInvoices = Invoice::where('distribution_sheet_id', $this->sheet->id)->orderBy('id')->get(['id', 'invoice_amount', 'content_hash'])->toArray();

        // رفع فيه فاتورة جديدة لأمر 1580 المكتمل ⇒ «ملحق 1» قيد التجهيز
        $result = $this->upload([['number' => 'ع G: 3', 'customer' => 'صيدلية واحد', 'order' => 1580, 'lines' => [['صنف', 250]]]]);
        $supplement = $this->supplements()->sole();
        $this->assertSame(1, $supplement->supplement_no);
        $this->assertSame($this->sheet->id, $supplement->parent_sheet_id);
        $this->assertSame('preparing', $supplement->status);
        $this->assertNull($supplement->sent_at);
        $this->assertSame('1580', $supplement->delivery_order_no);
        $this->assertSame($supplement->id, $this->inv('ع G: 3')->distribution_sheet_id);
        $this->assertSame(Invoice::IN_SHEET, $this->inv('ع G: 3')->placement);

        // الأصل لم يتغيّر
        $this->assertSame('completed', $this->sheet->fresh()->status);
        $this->assertSame($originalInvoices, Invoice::where('distribution_sheet_id', $this->sheet->id)->orderBy('id')->get(['id', 'invoice_amount', 'content_hash'])->toArray());

        // عنصر الدفعة والسجل
        $item = $this->item($result, 'ع G: 3');
        $this->assertSame(ImportBatchItem::INFO, $item->status);
        $this->assertStringContainsString('أمر 1580 كشفه مكتمل، فأُنشئ ملحق 1 لهذه الفاتورة', $item->reason);
        $log = ActivityLog::where('action', 'sheet.supplement_created')->sole();
        $this->assertSame($supplement->id, (int) $log->subject_id);
        $this->assertSame(['status' => 'preparing', 'supplement_no' => 1, 'delivery_order_no' => '1580', 'parent_sheet_id' => $this->sheet->id], $log->new_values);
        $this->assertSame(['ع G: 3'], $log->meta['invoices']);
        $this->assertSame($result['batch_id'], $log->meta['batch_id']);
        $this->assertSame([['sheet_id' => $supplement->id, 'parent_id' => $this->sheet->id, 'order' => '1580', 'no' => 1, 'invoices' => ['ع G: 3']]], $result['batch']->stat('supplements'));

        // الاسم المعروض الواحد
        $this->assertSame('1580 — ملحق 1', $supplement->orderLabel());
        $this->assertSame('أمر 1580 — ملحق 1', $supplement->shortLabel());
        $this->assertSame('أمر التسليم #1580 — ملحق 1 — 2026-10-13', $supplement->displayTitle());
        $this->assertSame('1580 — ملحق 1', app(SheetExportNamer::class)->orderPart($supplement));
        $this->assertSame('أمر 1580 — ملحق 1', FinanceService::statement('أمر {order}', [], $supplement));

        // الشاشات: الدفعة، قائمة الكشوف، صفحة الكشف (رابط الأصل)، والأصل يذكر ملحقه
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', $result['batch_id']))->assertOk()
            ->assertSee('أُنشئ 1 ملحقاً لأوامر كشوفها مكتملة')->assertSee(route('sheet-manager.sheets.show', $supplement));
        $this->actingAs($this->manager)->get(route('sheet-manager.sheets.index'))->assertOk()->assertSee('أمر التسليم #1580 — ملحق 1');
        $this->actingAs($this->manager)->get(route('sheet-manager.sheets.show', $supplement))->assertOk()
            ->assertSee('ملحق 1 لكشف')->assertSee(route('sheet-manager.sheets.show', $this->sheet->id));
        $this->actingAs($this->manager)->get(route('sheet-manager.sheets.show', $this->sheet))->assertOk()->assertSee('ملاحق هذا الأمر');

        // رفع آخر بفاتورة جديدة أخرى ⇒ الملحق نفسه (المفتوح قيد التجهيز)
        $second = $this->upload([
            ['number' => 'ع G: 3', 'customer' => 'صيدلية واحد', 'order' => 1580, 'lines' => [['صنف', 250]]],
            ['number' => 'ع G: 4', 'customer' => 'صيدلية اثنان', 'order' => 1580, 'lines' => [['صنف', 125]]],
        ]);
        $this->assertCount(1, $this->supplements());
        $this->assertSame($supplement->id, $this->inv('ع G: 4')->distribution_sheet_id);
        $this->assertStringContainsString('فأُضيفت إلى ملحق 1 المفتوح', $this->item($second, 'ع G: 4')->reason);

        // إعادة رفع الملف نفسه: لا تكرار (المطابق يُتجاهل)
        $invoiceCount = Invoice::count();
        $this->upload([
            ['number' => 'ع G: 3', 'customer' => 'صيدلية واحد', 'order' => 1580, 'lines' => [['صنف', 250]]],
            ['number' => 'ع G: 4', 'customer' => 'صيدلية اثنان', 'order' => 1580, 'lines' => [['صنف', 125]]],
        ]);
        $this->assertSame($invoiceCount, Invoice::count());
        $this->assertCount(1, $this->supplements());
        $this->assertSame(2, Invoice::where('distribution_sheet_id', $supplement->id)->count());

        // الإرسال: الصيدلية نفسها في الأصل والملحق ليست «تعارضاً»
        $this->assertSame([], app(SheetDispatchService::class)->conflicts('2026-10-13', [$supplement->id]));
        $this->actingAs($this->manager)->post(route('sheet-manager.sheets.send', $supplement))->assertSessionHasNoErrors();
        $this->assertSame('pending', $supplement->fresh()->status);
        $this->assign($supplement);

        // الاسم في شاشات المشرف والموزع
        $this->actingAs($this->supervisor)->get(route('supervisor.index'))->assertOk()->assertSee('أمر التسليم #1580 — ملحق 1');
        $this->actingAs($this->supervisor)->get(route('supervisor.sheet.show', $supplement->id))->assertOk()->assertSee('1580 — ملحق 1')
            ->assertSee(route('supervisor.sheet.show', $this->sheet->id));
        $this->actingAs($this->d1)->get(route('distributor.index'))->assertOk()->assertSee('أمر التسليم #1580 — ملحق 1');
        $this->actingAs($this->d1)->get(route('distributor.sheet', $supplement->id))->assertOk()->assertSee('ملحق 1 لكشف أمر 1580');

        // بعد الإرسال: فاتورة جديدة ⇒ مسار «كشف مفتوح» على الملحق (قرار + وسم + إشعار)
        $third = $this->upload([['number' => 'ع G: 5', 'customer' => 'صيدلية اثنان', 'order' => 1580, 'lines' => [['صنف', 75]]]]);
        $item = $this->item($third, 'ع G: 5');
        $this->assertSame(ImportBatchItem::PENDING, $item->status);
        $this->assertSame($supplement->id, $item->target_sheet_id);
        $this->actingAs($this->manager)->post(route('sheet-manager.items.apply', $item))->assertSessionHasNoErrors();
        $this->assertSame($supplement->id, $this->inv('ع G: 5')->distribution_sheet_id);
        $this->assertNotNull($this->inv('ع G: 5')->added_after_send_at);

        // اكتمال الملحق ثم فاتورة جديدة ⇒ «ملحق 2» وأصله الكشف الأصلي
        $this->complete($supplement);
        $this->upload([['number' => 'ع G: 6', 'customer' => 'صيدلية واحد', 'order' => 1580, 'lines' => [['صنف', 60]]]]);
        $supplements = $this->supplements();
        $this->assertSame([1, 2], $supplements->pluck('supplement_no')->all());
        $this->assertSame($this->sheet->id, $supplements[1]->parent_sheet_id);
        $this->assertSame($supplements[1]->id, $this->inv('ع G: 6')->distribution_sheet_id);
        $this->assertSame('أمر 1580 — ملحق 2', $supplements[1]->shortLabel());
    }

    public function test_changed_invoice_on_completed_sheet_keeps_its_path_and_unchanged_existing_invoices_get_no_supplement(): void
    {
        $this->complete($this->sheet);
        $rows = $this->rows;
        $rows[0]['lines'] = [['صنف', 1100]];
        $result = $this->importFiles($this->buildWorkbook($rows, $this->balances), '2026-10-13', $this->manager);

        $this->assertSame(ImportBatchItem::CLOSED_CHANGED, $this->item($result, 'ع G: 1')->kind);
        $this->assertSame(ImportBatchItem::CLOSED_UNCHANGED, $this->item($result, 'ع G: 2')->kind);
        $this->assertCount(0, $this->supplements());
        $this->assertSame(0, ActivityLog::where('action', 'sheet.supplement_created')->count());
    }

    public function test_no_supplement_without_a_completed_sheet_on_the_same_date(): void
    {
        $this->complete($this->sheet);
        // تاريخ توزيع آخر: لا كشف مكتمل للأمر بهذا التاريخ ⇒ كشف جديد عادي
        $this->importFiles($this->buildWorkbook([['number' => 'ع G: 7', 'customer' => 'صيدلية واحد', 'order' => 1580, 'lines' => [['صنف', 90]]]], $this->balances), '2026-10-14', $this->manager);

        $sheet = $this->inv('ع G: 7')->distributionSheet;
        $this->assertNull($sheet->supplement_no);
        $this->assertNull($sheet->parent_sheet_id);
        $this->assertSame('أمر التسليم #1580', $sheet->title());
        $this->assertCount(0, $this->supplements());
    }

    public function test_new_invoice_for_sent_sheet_waits_for_decision_then_is_tagged_added_after_send(): void
    {
        // كشف قيد التحصيل
        $this->sheet->update(['status' => 'in_progress']);
        $revision = (int) $this->sheet->fresh()->revision;

        $result = $this->upload([['number' => 'ع G: 3', 'customer' => 'صيدلية واحد', 'order' => 1580, 'lines' => [['صنف', 250]]]]);
        $item = $this->item($result, 'ع G: 3');
        $this->assertSame(ImportBatchItem::PENDING, $item->status);
        $this->assertSame(Invoice::UNASSIGNED, $this->inv('ع G: 3')->placement);
        $this->assertNull($this->inv('ع G: 3')->added_after_send_at);
        $this->assertSame(0, SheetChangeNotice::count());
        $this->assertCount(0, $this->supplements());

        $this->actingAs($this->manager)->post(route('sheet-manager.items.apply', $item))->assertSessionHasNoErrors();
        $invoice = $this->inv('ع G: 3');
        $this->assertSame($this->sheet->id, $invoice->distribution_sheet_id);
        $this->assertNotNull($invoice->added_after_send_at);
        $this->assertSame($result['batch_id'], $invoice->added_after_send_batch_id);
        $this->assertSame('added', SheetChangeNotice::sole()->type);
        $this->assertSame($revision + 1, (int) $this->sheet->fresh()->revision);
        $this->assertTrue(ActivityLog::where('action', 'import.item_applied')->latest('id')->first()->new_values['added_after_send']);

        // الوسم عند الموزع ومسؤول الكشوف والمشرف
        $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->assertOk()->assertSee('أُضيفت بعد الإرسال');
        $this->actingAs($this->manager)->get(route('sheet-manager.sheets.show', $this->sheet))->assertOk()->assertSee('أُضيفت بعد الإرسال');
        $this->actingAs($this->supervisor)->get(route('supervisor.sheet.show', $this->sheet->id))->assertOk()->assertSee('أُضيفت بعد الإرسال');
        // الفواتير الأصلية بلا وسم
        $this->assertNull($this->inv('ع G: 1')->added_after_send_at);
    }

    public function test_new_invoice_for_preparing_sheet_enters_automatically_without_tag_or_supplement(): void
    {
        $result = $this->importFiles($this->buildWorkbook([['number' => 'ع G: 8', 'customer' => 'صيدلية واحد', 'order' => 1590, 'lines' => [['صنف', 80]]]], $this->balances), '2026-10-13', $this->manager);
        $preparing = $this->inv('ع G: 8')->distributionSheet;
        $this->assertSame('preparing', $preparing->status);

        $this->importFiles($this->buildWorkbook([
            ['number' => 'ع G: 8', 'customer' => 'صيدلية واحد', 'order' => 1590, 'lines' => [['صنف', 80]]],
            ['number' => 'ع G: 9', 'customer' => 'صيدلية اثنان', 'order' => 1590, 'lines' => [['صنف', 70]]],
        ], $this->balances), '2026-10-13', $this->manager);

        $invoice = $this->inv('ع G: 9');
        $this->assertSame($preparing->id, $invoice->distribution_sheet_id);
        $this->assertNull($invoice->added_after_send_at);
        $this->assertCount(0, $this->supplements());
        $this->assertSame(0, SheetChangeNotice::count());
        $this->assertSame(ImportBatchItem::INFO, $this->item($result, 'ع G: 8')->status);
    }
}
