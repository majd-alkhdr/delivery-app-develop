<?php

namespace Tests\Feature\Phase7E2;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\CustomerBalanceSnapshot;
use App\Models\DistributionSheet;
use App\Models\ImportBatch;
use App\Models\ImportBatchItem;
use App\Models\Invoice;
use App\Models\InvoiceChangeProposal;
use App\Models\InvoiceRevision;
use App\Models\SheetChangeNotice;
use App\Models\User;
use App\Services\Import\ImportReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Phase7B\BalancesFixture;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 7E2: إعادة رفع الملف نفسه بعد تصحيحه — «تغيّر وصفي فقط» مجموعة مستقلة تُطبَّق دفعة واحدة.
 */
class DescriptiveChangesTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity, BalancesFixture;

    private const CC = '62021-حسين قواص';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBalances();
    }

    private function invoices(int $count, ?string $cc, array $amounts = []): array
    {
        return array_map(fn ($i) => ['number' => "ع G: {$i}", 'customer' => 'صيدلية '.($i % 10), 'order' => 1580, 'cc' => $cc ?? '0',
            'lines' => [['صنف '.$i, $amounts[$i] ?? 100 + $i], ['صنف إضافي', 50]]], range(1, $count));
    }

    /** ملف بلا عمود «مركز الكلفة» إطلاقاً (الحالة الحقيقية) */
    private function withoutCostCenterColumn(string $path): string
    {
        $book = IOFactory::load($path);
        $sheet = $book->getSheetByName('الحركة اليومية');
        foreach ($sheet->getRowIterator(1, 6) as $row) {
            foreach ($row->getCellIterator() as $cell) {
                if (trim((string) $cell->getValue()) === 'مركز الكلفة') {
                    $sheet->removeColumn($cell->getColumn());

                    break 2;
                }
            }
        }
        $out = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($out);

        return $out;
    }

    private function lines(): array
    {
        return Invoice::with('lines')->orderBy('id')->get()->mapWithKeys(fn ($i) => [$i->invoice_number => [(float) $i->invoice_amount,
            $i->lines->map(fn ($l) => [$l->item_name, (float) $l->quantity, (float) $l->line_total])->all()]])->all();
    }

    public function test_reupload_with_cost_centers_is_one_descriptive_group_applied_at_once(): void
    {
        $first = $this->upload($this->withoutCostCenterColumn($this->buildWorkbook($this->invoices(50, null))));
        $this->assertTrue($first['reports'][0]['success']);
        $before = $this->lines();
        $second = $this->upload($this->buildWorkbook($this->invoices(50, self::CC)));
        $batch = $second['batch'];

        $pending = ImportBatchItem::where('batch_id', $batch->id)->where('status', ImportBatchItem::PENDING)->get();
        $this->assertCount(50, $pending);
        $this->assertTrue($pending->every(fn ($i) => $i->kind === ImportBatchItem::CHANGED && $i->descriptiveFields() === ['cost_center']));
        $this->assertSame('تغيّر مركز الكلفة من «بلا» إلى «'.self::CC.'». لن يتغير المبلغ ولا الأصناف، وسيُنسب دَين الفاتورة لهذا المندوب. عند التطبيق: تبقى في كشف أمر 1580. لن تُعدَّل قبل قرارك.',
            $pending->first()->reason);

        // صفحة الدفعة: مجموعة واحدة بالعدد 50، والقائمة الافتراضية لا تكرر عناصرها، والإرسال ممنوع حتى القرار
        $page = $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $batch, 'tab' => 'invoices']))->assertOk();
        $page->assertSee('50 فاتورة تغيّر فيها مركز الكلفة فقط (من «بلا» إلى كود المندوب)')->assertSee('تطبيق هذه المجموعة')
            ->assertSee('لا توجد فواتير أخرى تحتاج قراراً غير مجموعة');
        $this->assertSame(1, substr_count($page->getContent(), 'id="descriptive-group"'));
        $this->assertSame(0, $page->viewData('items')->total());
        $this->assertSame(50, $page->viewData('descriptive')['count']);
        $group = $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $batch, 'partial' => 'group', 'kind' => 'descriptive']))->assertOk();
        $this->assertSame(50, $group->viewData('items')->total());
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', $batch))->assertSee('حقول وصفية فقط');
        $this->assertNotEmpty(app(\App\Services\SheetDispatchService::class)->blockers(DistributionSheet::where('status', 'preparing')->get()));

        // «تطبيق هذه المجموعة»
        $this->actingAs($this->manager)->post(route('sheet-manager.batches.apply-descriptive', $batch), ['confirm_printed' => 1])
            ->assertSessionHas('success', 'تطبيق مجموعة «تغيّر وصفي فقط»: طُبّق 50 من 50 عنصراً.');

        $this->assertSame(50, ImportBatchItem::where('batch_id', $batch->id)->where('status', ImportBatchItem::APPLIED)->count());
        $this->assertSame(50, InvoiceChangeProposal::where('batch_id', $batch->id)->where('status', InvoiceChangeProposal::APPLIED)->count());
        $this->assertSame(['62021'], Invoice::distinct()->pluck('cost_center_code')->all());
        $this->assertSame(['حسين قواص'], Invoice::distinct()->pluck('cost_center_name')->all());
        $this->assertSame($before, $this->lines());   // المبالغ والأسطر ثابتة
        $this->assertSame(50, InvoiceRevision::count());
        $this->assertNull(InvoiceRevision::first()->header['cost_center_code']);

        $logs = ActivityLog::where('action', 'import.item_applied')->get();
        $this->assertCount(50, $logs);
        $this->assertNull($logs->first()->old_values['cost_center']);
        $this->assertSame(self::CC, $logs->first()->new_values['cost_center']);
        $this->assertSame($logs->first()->old_values['amount'], $logs->first()->new_values['amount']);

        // المانع زال: الإرسال يعمل، والدفعة الأولى (صاحبة الكشف) لم تعد تطلب تأكيد «بلا مركز كلفة» بعد التصحيح
        $this->assertSame([], app(\App\Services\SheetDispatchService::class)->blockers(DistributionSheet::where('status', 'preparing')->get()));
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', $first['batch']))->assertDontSee('costCenterConfirm', false);
        $this->actingAs($this->manager)->post(route('sheet-manager.batches.send', $first['batch']))->assertSessionHas('success');
        $this->assertSame(0, ActivityLog::where('action', 'sheet.sent_without_cost_center')->count());
        $this->assertSame('pending', DistributionSheet::where('delivery_order_no', '1580')->value('status'));
    }

    public function test_amount_change_with_cost_center_is_not_descriptive(): void
    {
        $this->upload($this->buildWorkbook($this->invoices(5, null)));
        $result = $this->upload($this->buildWorkbook($this->invoices(5, self::CC, [3 => 999])));

        $items = ImportBatchItem::where('batch_id', $result['batch_id'])->where('status', ImportBatchItem::PENDING)->get()->keyBy('invoice_number');
        $this->assertCount(5, $items);
        $this->assertNull($items['ع G: 3']->descriptiveFields());
        $this->assertStringContainsString('المبلغ', $items['ع G: 3']->reason);
        $this->assertSame(4, ImportBatchItem::where('batch_id', $result['batch_id'])->descriptivePending()->count());

        $page = $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $result['batch'], 'tab' => 'invoices']))->assertOk();
        $page->assertSee('4 فاتورة تغيّر فيها مركز الكلفة فقط');
        $this->assertSame(['ع G: 3'], collect($page->viewData('items')->items())->pluck('invoice_number')->all());

        // التطبيق الجماعي لا يمسّها
        app(ImportReviewService::class)->applyDescriptive($result['batch'], $this->manager, true);
        $this->assertSame(ImportBatchItem::PENDING, $items['ع G: 3']->fresh()->status);
        $this->assertNull(Invoice::where('invoice_number', 'ع G: 3')->value('cost_center_code'));
    }

    /**
     * تكافؤ: «تطبيق هذه المجموعة» يعطي الحالة النهائية نفسها لتطبيق كل عنصر بـapply() (مع كشف منشور وتنبيهاته).
     */
    public function test_group_apply_matches_item_by_item_apply(): void
    {
        $this->publish();   // كشف 1580 منشور: ع G: 1 (655231) وع G: 2 (700700)
        $extra = fn (?string $cc) => array_map(fn ($i) => ['number' => "ع G: {$i}", 'customer' => 'صيدلية '.($i % 7), 'order' => 2000, 'cc' => $cc ?? '0',
            'lines' => [['صنف '.$i, 10 * $i]]], range(100, 129));
        $published = fn (string $cc1) => [
            ['number' => 'ع G: 1', 'customer' => 'صيدلية التجربة', 'cc' => $cc1, 'order' => 1580, 'lines' => [['صنف أ', 1000]]],
            ['number' => 'ع G: 2', 'customer' => 'صيدلية التجربة', 'cc' => '700700-مندوب ب', 'order' => 1580, 'lines' => [['صنف ب', 500]]],
        ];
        $this->upload($this->buildWorkbook(array_merge($published('655231-مندوب أ'), $extra(null))));
        $batch = $this->upload($this->buildWorkbook(array_merge($published('700700-مندوب ب'), $extra(self::CC))))['batch'];
        $this->assertSame(31, ImportBatchItem::where('batch_id', $batch->id)->descriptivePending()->count());

        DB::beginTransaction();
        $items = ImportBatchItem::where('batch_id', $batch->id)->descriptivePending()->orderBy('id')->get();
        app(ImportReviewService::class)->applyMany($items, $this->manager, true);
        $one = $this->state();
        DB::rollBack();

        $results = app(ImportReviewService::class)->applyDescriptive(ImportBatch::find($batch->id), $this->manager, true);
        $this->assertCount(31, $results);
        $this->assertTrue(collect($results)->every(fn ($r) => $r['ok']));
        $this->assertSame($one, $this->state());
        $this->assertSame(1, SheetChangeNotice::where('type', 'updated')->count());   // ع G: 1 في الكشف المنشور
    }

    /** الحالة النهائية للجداول التي يمسّها التطبيق (بلا الطوابع الزمنية) */
    private function state(): array
    {
        $strip = fn ($rows, array $drop = []) => collect($rows)->map(fn ($r) => collect((array) $r)->reject(fn ($v, $k) => str_ends_with($k, '_at') || in_array($k, $drop, true))->all())->all();

        return [
            'invoices' => $strip(DB::table('invoices')->orderBy('id')->get()),
            'lines' => $strip(DB::table('invoice_lines')->orderBy('invoice_id')->orderBy('line_no')->get(), ['id']),
            'items' => $strip(DB::table('import_batch_items')->orderBy('id')->get()),
            'proposals' => $strip(DB::table('invoice_change_proposals')->orderBy('id')->get()),
            'revisions' => $strip(DB::table('invoice_revisions')->orderBy('invoice_id')->orderBy('revision_no')->get(), ['id']),
            'notices' => $strip(DB::table('sheet_change_notices')->orderBy('id')->get(), ['id']),
            'sheets' => $strip(DB::table('distribution_sheets')->orderBy('id')->get()),
            'logs' => collect($strip(DB::table('activity_logs')->get(), ['id']))->sortBy(fn ($r) => $r['action'].'|'.$r['subject_id'].'|'.$r['meta'])->values()->all(),
        ];
    }

    public function test_same_date_same_values_balances_reupload_creates_no_snapshots(): void
    {
        $balances = ['صيدلية التجربة' => 5000] + $this->others();
        $this->upload($this->balancesFile($balances, '21-10-2026'));
        $result = $this->upload($this->balancesFile($balances, '21-10-2026'));
        $import = $this->latestImport();

        $this->assertSame('applied', $import->status);
        $this->assertSame(0, CustomerBalanceSnapshot::where('balance_import_id', $import->id)->count());
        $this->assertSame(0, SheetChangeNotice::where('type', 'balance_changed')->count());
        $codes = ImportBatchItem::where('batch_id', $result['batch_id'])->where('kind', ImportBatchItem::NOTE)->get()->pluck('new.code')->all();
        $this->assertContains('balances_same_date_unchanged', $codes);
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $result['batch_id'], 'tab' => 'balances']))
            ->assertSee('طُبّق ملف بالتاريخ نفسه سابقاً؛ الأرصدة بلا تغيير.');
    }

    public function test_apply_group_route_keeps_permissions(): void
    {
        $this->upload($this->buildWorkbook($this->invoices(3, null)));
        $batch = $this->upload($this->buildWorkbook($this->invoices(3, self::CC)))['batch'];

        $this->actingAs(User::factory()->role(Role::Distributor)->create())->post(route('sheet-manager.batches.apply-descriptive', $batch))->assertForbidden();
        $this->actingAs(User::factory()->role(Role::Supervisor)->create())->post(route('sheet-manager.batches.apply-descriptive', $batch))->assertForbidden();
        $this->assertSame(3, ImportBatchItem::where('batch_id', $batch->id)->descriptivePending()->count());
        $this->actingAs($this->admin)->post(route('sheet-manager.batches.apply-descriptive', $batch))->assertSessionHas('success');
        $this->assertSame(0, ImportBatchItem::where('batch_id', $batch->id)->descriptivePending()->count());
    }
}
