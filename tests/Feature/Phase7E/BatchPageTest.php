<?php

namespace Tests\Feature\Phase7E;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\CustomerBalanceSnapshot;
use App\Models\DistributionSheet;
use App\Models\ImportBatch;
use App\Models\ImportBatchItem;
use App\Models\User;
use App\Services\Balances\SnapshotScope;
use App\Services\Import\WorkbookImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Phase7B\BalancesFixture;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 7E: صفحة «معاينة الدفعة» للملفات الكبيرة — التبويبات، المرشّح الافتراضي، العرض المجمَّع، الأجزاء الكسولة،
 * غياب عمود «مركز الكلفة»، دمج التحذيرات، تصنيف flagged الجديد، واتساق «المؤجل».
 */
class BatchPageTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity, BalancesFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBalances();
    }

    private function page(ImportBatch|string $batch, array $query = [])
    {
        return $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $batch] + $query))->assertOk();
    }

    /**
     * ملف كبير: 1,050 فاتورة جديدة في 3 أوامر تسليم، منها 60 مؤجلة (40 بأمر و20 بلا أمر).
     */
    private function largeBatch(): ImportBatch
    {
        $invoices = [];
        for ($i = 1; $i <= 1050; $i++) {
            $deferred = $i > 990;
            $invoices[] = ['number' => "ع G: {$i}", 'customer' => 'صيدلية '.($i % 300), 'order' => $deferred && $i > 1030 ? 0 : 4000 + ($i % 3),
                'delivery' => $deferred ? '20-10-2026' : '13-10-2026', 'lines' => [['صنف '.$i, 100 + $i]]];
        }
        $result = $this->upload($this->buildWorkbook($invoices));
        $this->assertTrue($result['reports'][0]['success'], (string) $result['reports'][0]['error']);

        return $result['batch'];
    }

    public function test_large_batch_first_page_is_light_and_new_invoices_are_grouped(): void
    {
        $batch = $this->largeBatch();

        // الصفحة الأولى (تبويب الكشوف): لا صفوف فواتير، وحجمها دون الحدّ، وعدد استعلامات محدود
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $page = $this->page($batch);
        $html = $page->getContent();
        $this->assertLessThan(300 * 1024, strlen($html), 'حجم الصفحة الأولى: '.strlen($html));
        $this->assertStringNotContainsString('review-item', $html);
        $this->assertStringNotContainsString('ع G: 500<', $html);
        $this->assertLessThan(60, $queries, "استعلامات الصفحة الأولى: {$queries}");
        $page->assertSee('#4000')->assertSee('#4001')->assertSee('#4002')
            ->assertDontSee('فواتير بلا كشف (0)');

        // تبويب الفواتير بالمرشّح الافتراضي: لا شيء يحتاج قراراً، وعدّاد «الجديدة» صحيح
        $invoices = $this->page($batch, ['tab' => 'invoices']);
        $invoices->assertSee('لا توجد فواتير تحتاج قراراً')->assertSee('الجديدة (1050)');
        $this->assertLessThan(300 * 1024, strlen($invoices->getContent()));

        // «الجديدة» مجمَّعة حسب الموقع بالأعداد الصحيحة
        $grouped = $this->page($batch, ['tab' => 'invoices', 'kind' => ImportBatchItem::CREATED]);
        $groups = collect($grouped->viewData('groups'))->keyBy('label');
        $this->assertSame(1050, $groups->sum('count'));
        $this->assertSame(60, $groups['مؤجلة']['count']);
        $this->assertSame(330, $groups['أمر 4000']['count']);
        $this->assertNull($grouped->viewData('items'));

        // توسيع مجموعة: صفحة واحدة من 50 فاتورة بترقيم
        $part = $this->page($batch, ['partial' => 'group', 'kind' => ImportBatchItem::CREATED, 'loc' => $groups['أمر 4000']['key']]);
        $this->assertSame(330, $part->viewData('items')->total());
        $this->assertCount(50, $part->viewData('items')->items());
        $this->assertStringNotContainsString('<html', $part->getContent());

        // البحث يعمل عبر كل الأنواع
        $this->page($batch, ['tab' => 'invoices', 'q' => 'ع G: 777'])->assertSee('ع G: 777');

        // تبويب يُحمَّل وحده (بلا الصفحة كاملة)
        $fragment = $this->page($batch, ['tab' => 'deferred', 'partial' => 1]);
        $this->assertStringNotContainsString('<html', $fragment->getContent());
        $fragment->assertSee('الطلبيات المؤجلة في هذه الدفعة (60)');
    }

    public function test_nothing_is_lost_every_item_is_reachable_through_filters_and_pages(): void
    {
        $batch = $this->largeBatch();
        $total = ImportBatchItem::where('batch_id', $batch->id)->invoices()->count();
        $this->assertSame(1050, $total);

        // عبر «الكل» بالترقيم
        $seen = [];
        for ($p = 1; ; $p++) {
            $items = $this->page($batch, ['tab' => 'invoices', 'kind' => 'all', 'page' => $p])->viewData('items');
            foreach ($items as $item) {
                $seen[$item->id] = true;
            }
            if (! $items->hasMorePages()) {
                break;
            }
        }
        $this->assertCount($total, $seen);

        // وعبر كل نوع: المجموعات وتوسيعها صفحة صفحة
        $byKind = [];
        foreach (ImportBatchItem::where('batch_id', $batch->id)->invoices()->distinct()->pluck('kind') as $kind) {
            $response = $this->page($batch, ['tab' => 'invoices', 'kind' => $kind]);
            foreach ($response->viewData('groups') ?? [] as $group) {
                for ($p = 1; ; $p++) {
                    $items = $this->page($batch, ['partial' => 'group', 'kind' => $kind, 'loc' => $group['key'], 'page' => $p])->viewData('items');
                    foreach ($items as $item) {
                        $byKind[$item->id] = true;
                    }
                    if (! $items->hasMorePages()) {
                        break;
                    }
                }
            }
        }
        $this->assertCount($total, $byKind);
    }

    /** ملف بلا عمود «مركز الكلفة» (يُحذف العمود من ملف عادي) */
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

    public function test_missing_cost_center_column_is_one_critical_note_and_sending_needs_confirmation(): void
    {
        $path = $this->withoutCostCenterColumn($this->buildWorkbook([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية أ', 'order' => 1580, 'lines' => [['صنف', 100]]],
            ['number' => 'ع G: 2', 'customer' => 'صيدلية ب', 'order' => 1580, 'lines' => [['صنف', 200]]],
            ['number' => 'ع G: 3', 'customer' => 'صيدلية ج', 'order' => 1580, 'lines' => [['صنف', 300]]],
        ]));
        $result = $this->upload($path);
        $batch = $result['batch'];

        $notes = ImportBatchItem::where('batch_id', $batch->id)->where('kind', ImportBatchItem::NOTE)->get();
        $critical = $notes->filter(fn ($n) => ($n->new['code'] ?? null) === WorkbookImporter::COST_CENTER_COLUMN_MISSING);
        $this->assertCount(1, $critical);
        $this->assertSame('critical', $critical->first()->level);
        $this->assertSame(3, $critical->first()->new['count']);
        $this->assertTrue($notes->every(fn ($n) => ($n->new['code'] ?? null) !== 'cost_center_no_code'));

        $this->page($batch)->assertSee(WorkbookImporter::COST_CENTER_COLUMN_MISSING_TEXT)->assertSee('costCenterConfirm', false);

        // الإرسال بلا تأكيد لا يرسل
        $this->actingAs($this->manager)->post(route('sheet-manager.batches.send', $batch))->assertSessionHas('warning');
        $this->assertSame('preparing', DistributionSheet::where('batch_id', $batch->id)->value('status'));

        // بالتأكيد الصريح: يُرسل ويُسجَّل
        $this->actingAs($this->manager)->post(route('sheet-manager.batches.send', $batch), ['confirm_no_cost_center' => 1])->assertSessionHas('success');
        $this->assertSame('pending', DistributionSheet::where('batch_id', $batch->id)->value('status'));
        $log = ActivityLog::where('action', 'sheet.sent_without_cost_center')->sole();
        $this->assertSame($batch->id, $log->meta['batch_id']);
    }

    public function test_cost_center_column_present_with_empty_cells_keeps_grouped_note(): void
    {
        $result = $this->upload($this->buildWorkbook([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية أ', 'cc' => 'كول سنتر بلا كود', 'lines' => [['صنف', 100]]],
            ['number' => 'ع G: 2', 'customer' => 'صيدلية ب', 'lines' => [['صنف', 200]]],
        ]));
        $notes = ImportBatchItem::where('batch_id', $result['batch_id'])->where('kind', ImportBatchItem::NOTE)->get()->keyBy(fn ($n) => $n->new['code'] ?? '');
        $this->assertArrayNotHasKey(WorkbookImporter::COST_CENTER_COLUMN_MISSING, $notes);
        $this->assertSame(['warning', 1], [$notes['cost_center_no_code']->level, $notes['cost_center_no_code']->new['count']]);
        // الإرسال لا يطلب تأكيداً
        $this->actingAs($this->manager)->post(route('sheet-manager.batches.send', $result['batch']))->assertSessionHas('success');
        $this->assertSame(0, ActivityLog::where('action', 'sheet.sent_without_cost_center')->count());
    }

    public function test_repeated_invalid_order_warnings_become_one_row_with_full_list(): void
    {
        $invoices = [];
        for ($i = 1; $i <= 100; $i++) {
            $invoices[] = ['number' => "ع G: {$i}", 'customer' => "صيدلية {$i}", 'order' => 0, 'lines' => [['صنف', 10]]];
        }
        $batch = $this->upload($this->buildWorkbook($invoices))['batch'];
        $this->assertCount(100, array_filter($batch->warnings, fn ($w) => str_contains($w, 'رقم أمر التسليم «0» غير صالح')));

        $notes = $this->page($batch, ['tab' => 'notes']);
        $notes->assertSee('100 فاتورة أمر تسليمها 0 أو غير صالح')->assertSee('عرض الكل (100)');
        $this->assertSame(1, substr_count($notes->getContent(), 'data-key="invalid_order"'));
        $this->assertStringNotContainsString('الفاتورة ع G: 77: رقم أمر التسليم', $notes->getContent());

        $list = $this->page($batch, ['partial' => 'warnings', 'group' => 'invalid_order']);
        $this->assertSame(100, $list->viewData('rows')->total());
        $this->assertCount(50, $list->viewData('rows')->items());
        $page2 = $this->page($batch, ['partial' => 'warnings', 'group' => 'invalid_order', 'lpage' => 2]);
        $page2->assertSee('الفاتورة ع G: 77: رقم أمر التسليم «0» غير صالح');   // النص الأصلي لكل فاتورة محفوظ
        $this->assertSame(1, $this->page($batch, ['partial' => 'warnings', 'group' => 'invalid_order', 'lq' => 'ع G: 77:'])->viewData('rows')->total());
    }

    public function test_flagged_only_for_app_linked_or_unreflected_and_external_is_ok(): void
    {
        $this->publish();   // «صيدلية التجربة» لها فواتير في كشف منشور؛ الأخريات بلا أي حركة
        $result = $this->upload($this->balancesFile(['صيدلية التجربة' => 5700, 'صيدلية ثانية' => 9000, 'صيدلية ثالثة' => 3000, 'صيدلية رابعة' => 4000, 'صيدلية جديدة' => 100]));
        $import = $this->latestImport();
        $snap = fn (string $name) => CustomerBalanceSnapshot::where('balance_import_id', $import->id)->whereHas('customer', fn ($q) => $q->where('name', $name))->sole();

        $linked = $snap('صيدلية التجربة');
        $this->assertSame(['flagged', SnapshotScope::APP], [$linked->status, $linked->scope]);

        $external = $snap('صيدلية ثانية');
        $this->assertSame(['ok', SnapshotScope::EXTERNAL], [$external->status, $external->scope]);
        $this->assertStringContainsString(SnapshotScope::EXTERNAL_NOTE, $external->explanation);
        $this->assertStringNotContainsString('تحقق في المحاسبة', $external->explanation);

        $new = $snap('صيدلية جديدة');
        $this->assertSame(['created', 'ok'], [$new->kind, $new->status]);

        // العدّادات في تبويب الأرصدة وفي «تغيّرات الأرصدة» متطابقة
        $tab = $this->page($result['batch'], ['tab' => 'balances']);
        $this->assertSame(['flagged' => 1, 'external' => 1, 'created' => 1], array_intersect_key($tab->viewData('snap'), array_flip(['flagged', 'external', 'created'])));
        $tab->assertSee('اللقطات الخارجية لا تحتاج تحققاً');
        $changes = $this->actingAs($this->accountant)->get(route('finance.balance-changes', ['import' => $import->id]))->assertOk();
        $this->assertSame(1, $changes->viewData('summary')['flagged']);
        $this->assertSame(1, $changes->viewData('summary')['external']);
        // الافتراضي «مرتبط بالتطبيق + المشتبه بها»: الخارجي غير معروض، ومرشّح «خارجي» يعرضه
        $this->assertNotContains($external->id, $changes->viewData('snapshots')->pluck('id')->all());
        $this->assertContains($linked->id, $changes->viewData('snapshots')->pluck('id')->all());
        $externalOnly = $this->actingAs($this->accountant)->get(route('finance.balance-changes', ['import' => $import->id, 'scope' => 'external']))->assertOk();
        $this->assertSame([$external->id], $externalOnly->viewData('snapshots')->pluck('id')->all());
    }

    public function test_unreflected_exported_payment_is_always_flagged(): void
    {
        $this->publish();
        $this->payApi($this->d1, $this->sheet, $this->pharmacy->id, 900)->assertSuccessful();
        $this->exportSheet();
        $result = $this->upload($this->balancesFile(['صيدلية التجربة' => 5000] + $this->others()));

        $s = CustomerBalanceSnapshot::where('customer_id', $this->pharmacy->id)->where('balance_import_id', $this->latestImport()->id)->sole();
        $this->assertSame(['flagged', true, SnapshotScope::APP], [$s->status, $s->exported_mismatch, $s->scope]);
        $this->page($result['batch'], ['tab' => 'balances'])->assertSee('دفعات مصدَّرة لم تنعكس في هذا الملف (1)')->assertSee('الفرق = ');
        $this->page($result['batch'])->assertSee('دفعات مصدَّرة لم تنعكس في ملف الأرصدة');   // شريط «يحتاج انتباهك»
    }

    public function test_migration_backfills_scope_and_unflags_external_snapshots(): void
    {
        $this->publish();
        $this->upload($this->balancesFile(['صيدلية التجربة' => 5000, 'صيدلية ثانية' => 9000, 'صيدلية ثالثة' => 3000, 'صيدلية رابعة' => 4000]));
        $s = CustomerBalanceSnapshot::whereHas('customer', fn ($q) => $q->where('name', 'صيدلية ثانية'))->latest('id')->first();
        // لقطة قديمة بالتصنيف السابق
        $s->update(['status' => 'flagged', 'scope' => null, 'explanation' => 'ارتفع الرصيد 7,000 — غير مفسَّر: تحقق في المحاسبة (مرتجع؟ تعديل سند؟ إلغاء فاتورة؟)']);

        (require database_path('migrations/2026_11_15_000001_add_scope_to_customer_balance_snapshots.php'))->up();

        $s->refresh();
        $this->assertSame(['ok', SnapshotScope::EXTERNAL], [$s->status, $s->scope]);
        $this->assertSame('ارتفع الرصيد 7,000 — '.SnapshotScope::EXTERNAL_NOTE, $s->explanation);
    }

    public function test_deferred_count_is_consistent_between_card_tab_and_note(): void
    {
        $batch = $this->largeBatch();
        $this->assertSame(60, (int) $batch->stat('deferred.count'));

        $this->page($batch)->assertSee('المؤجل (60)');
        $tab = $this->page($batch, ['tab' => 'deferred']);
        $tab->assertSee('الطلبيات المؤجلة في هذه الدفعة (60)')
            ->assertSee('60 مؤجلة: 40 تاريخ تسليمها مستقبلي ولها أمر تسليم، و20 تاريخ تسليمها مستقبلي بلا أمر تسليم');
        $this->assertSame(60, $tab->viewData('deferredRows')->total());
        $this->page($batch, ['tab' => 'notes'])->assertSee('فواتير مؤجلة لها أمر تسليم لتاريخ مستقبلي')->assertSee('(40)')->assertSee('عرض في تبويب «المؤجلة»');
    }

    public function test_batch_page_and_fragments_keep_permissions(): void
    {
        $batch = $this->largeBatch();
        $distributor = User::factory()->role(Role::Distributor)->create();
        foreach ([[], ['tab' => 'notes', 'partial' => 1], ['partial' => 'group', 'kind' => 'created', 'loc' => 'label:مؤجلة'], ['partial' => 'warnings', 'group' => 'deferred_with_order']] as $q) {
            $this->actingAs($distributor)->get(route('sheet-manager.batches.show', ['batch' => $batch] + $q))->assertForbidden();
            $this->actingAs($this->admin)->get(route('sheet-manager.batches.show', ['batch' => $batch] + $q))->assertOk();
        }
    }
}
