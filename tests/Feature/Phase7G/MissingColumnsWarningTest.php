<?php

namespace Tests\Feature\Phase7G;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\ImportBatch;
use App\Models\ImportBatchItem;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Import\ColumnsConfirmationRequired;
use App\Services\Import\DuplicateUploadNotice;
use App\Services\Import\SheetImportService;
use App\Services\Import\WorkbookImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 7G: تحذير غياب الأعمدة الحرجة قبل التطبيق، وتنبيه الملف المتكرر.
 */
class MissingColumnsWarningTest extends TestCase
{
    use RefreshDatabase, Workbooks;

    private User $manager;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->manager = User::factory()->role(Role::SheetManager)->create();
        $this->admin = User::factory()->role(Role::Admin)->create();
    }

    /** 3 فواتير تسليمها يوم التوزيع (13-10) و2 تسليمها 20-10، كلها بأمر 3918 */
    private function file(int $today = 3, int $later = 2): string
    {
        $invoices = [];
        for ($i = 1; $i <= $today + $later; $i++) {
            $invoices[] = ['number' => "ع G: {$i}", 'customer' => 'صيدلية '.$i, 'delivery' => $i <= $today ? '13-10-2026' : '20-10-2026',
                'order' => 3918, 'lines' => [['صنف '.$i, 100 * $i]]];
        }

        return $this->buildWorkbook($invoices);
    }

    /** حذف أعمدة من ترويسة «الحركة اليومية» (كما في ملف مصدَّر بلا هذه الأعمدة) */
    private function without(string $path, array $labels): string
    {
        $book = IOFactory::load($path);
        $sheet = $book->getSheetByName('الحركة اليومية');
        foreach ($labels as $label) {
            foreach ($sheet->getRowIterator(1, 6) as $row) {
                foreach ($row->getCellIterator() as $cell) {
                    if (trim((string) $cell->getValue()) === $label) {
                        $sheet->removeColumn($cell->getColumn());

                        continue 3;
                    }
                }
            }
        }
        $out = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($out);

        return $out;
    }

    private function postUpload(string $path, string $date = '2026-10-13')
    {
        return $this->actingAs($this->manager)->post(route('sheet-manager.upload'), [
            'excel_files' => [$this->uploaded($path, 'الحركة.xlsx')], 'distribution_date' => $date,
        ]);
    }

    private function token($response): string
    {
        $response->assertRedirect();
        $this->assertMatchesRegularExpression('#/sheet-manager/uploads/([^/]+)/confirm$#', $response->headers->get('Location'));
        preg_match('#/uploads/([^/]+)/confirm#', $response->headers->get('Location'), $m);

        return $m[1];
    }

    public function test_missing_order_column_needs_explicit_confirmation_and_shows_counts(): void
    {
        $token = $this->token($this->postUpload($this->without($this->file(), ['رقم امر التسليم'])));

        // لم يُطبَّق شيء قبل التأكيد
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, ImportBatch::count());

        $page = $this->actingAs($this->manager)->get(route('sheet-manager.uploads.confirm', $token))->assertOk();
        $page->assertSee('id="missing-columns"', false)
            ->assertSee('لن يُنشأ أي كشف')
            ->assertSee('3 فاتورة ستصبح بلا كشف')
            ->assertSee('2 ستصبح مؤجلة')
            ->assertSee('الأعمدة الموجودة فعلاً في الملف')
            ->assertSee('المدخل')
            ->assertSee('أفهم أن العمود «رقم امر التسليم» غير موجود في الملف وأريد المتابعة.');

        // التطبيق بلا خانة التأكيد يُرفض ولا يطبّق شيئاً
        $this->actingAs($this->manager)->post(route('sheet-manager.uploads.confirm.store', $token))
            ->assertSessionHasErrors('confirm_missing_columns');
        $this->assertSame(0, Invoice::count());

        // ومع الخانة يُطبَّق بالقواعد نفسها
        $response = $this->actingAs($this->manager)->post(route('sheet-manager.uploads.confirm.store', $token), ['confirm_missing_columns' => '1']);
        $batch = ImportBatch::sole();
        $response->assertRedirect(route('sheet-manager.batches.show', $batch->id));
        $this->assertSame(3, Invoice::where('placement', Invoice::UNASSIGNED)->count());
        $this->assertSame(2, Invoice::where('placement', Invoice::DEFERRED)->count());
        $this->assertSame(0, $batch->sheets()->count());

        $log = ActivityLog::where('action', 'import.columns_confirmed')->sole();
        $this->assertSame($this->manager->id, $log->user_id);
        $this->assertSame((string) $batch->id, (string) $log->subject_id);
        $this->assertSame('أفهم أن العمود «رقم امر التسليم» غير موجود في الملف وأريد المتابعة.', $log->meta['text']);
        $this->assertSame($this->manager->name, $log->meta['user_name']);

        // الملاحظة الحرجة في أعلى الدفعة وفي تبويب الملاحظات
        $note = ImportBatchItem::where('batch_id', $batch->id)->where('kind', ImportBatchItem::NOTE)->where('new->code', 'delivery_order_column_missing')->sole();
        $this->assertSame('critical', $note->level);
        $this->assertSame(5, $note->new['count']);
        $this->assertSame('رقم امر التسليم', $note->new['meta']['column']);
        $this->assertContains('تاريخ التسليم', $note->new['meta']['present']);

        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', $batch))->assertOk()
            ->assertSee('id="missing-columns"', false)
            ->assertSee('3 فاتورة صارت بلا كشف')
            ->assertSee('2 مؤجلة')
            ->assertSee('لن يُنشأ أي كشف');
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $batch, 'tab' => 'notes']))->assertOk()
            ->assertSee('data-code="delivery_order_column_missing"', false);

        // الرمز يُستهلك: لا تطبيق ثانٍ
        $this->actingAs($this->manager)->post(route('sheet-manager.uploads.confirm.store', $token), ['confirm_missing_columns' => '1'])
            ->assertRedirect(route('sheet-manager.index'));
        $this->assertSame(1, ImportBatch::count());
    }

    public function test_service_refuses_without_confirmation_and_keeps_the_file(): void
    {
        try {
            $this->importFiles($this->without($this->file(), ['رقم امر التسليم']), '2026-10-13', $this->manager);
            $this->fail('كان يجب أن يطلب التأكيد');
        } catch (ColumnsConfirmationRequired $e) {
            $this->assertSame(['invoices' => 5, 'sheets' => 0, 'unassigned' => 3, 'deferred' => 2, 'orders' => 0], $e->pending['estimate']);
            Storage::assertExists($e->pending['files'][0]['stored_path']);
            $this->assertSame(['رقم امر التسليم'], array_column($e->pending['columns'], 'column'));
        }
        $this->assertSame(0, Invoice::count());
    }

    public function test_file_with_all_columns_has_no_column_notes_and_no_confirmation(): void
    {
        $result = $this->importFiles($this->file(), '2026-10-13', $this->manager);
        $codes = ImportBatchItem::where('batch_id', $result['batch_id'])->where('kind', ImportBatchItem::NOTE)->get()->pluck('new.code')->all();
        $this->assertSame([], array_values(array_intersect($codes, WorkbookImporter::columnNoteCodes())));
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', $result['batch']))->assertOk()
            ->assertDontSee('id="missing-columns"', false)
            ->assertDontSee('id="duplicate-upload"', false);
    }

    public function test_cost_center_alone_keeps_phase_7e_behaviour(): void
    {
        // بلا تأكيد عند الرفع (التأكيد عند الإرسال كما في 7E)، والمربع نفسه بنص 7E
        $result = $this->importFiles($this->without($this->file(), ['مركز الكلفة']), '2026-10-13', $this->manager);
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', $result['batch']))->assertOk()
            ->assertSee('id="missing-columns"', false)
            ->assertSee(WorkbookImporter::COST_CENTER_COLUMN_MISSING_TEXT)
            ->assertSee('costCenterConfirm', false);
    }

    public function test_missing_quantity_and_unit_price_are_warnings_without_confirmation(): void
    {
        // لا حسم في الملف: القيم لا تتأثر، فتحذير لا يتطلب تأكيداً
        $result = $this->importFiles($this->without($this->file(), ['كمية', 'الإفرادي']), '2026-10-13', $this->manager);
        $notes = ImportBatchItem::where('batch_id', $result['batch_id'])->where('kind', ImportBatchItem::NOTE)
            ->whereIn('new->code', ['quantity_column_missing', 'unit_price_column_missing'])->get();
        $this->assertCount(2, $notes);
        $this->assertSame(['warning'], $notes->pluck('level')->unique()->values()->all());
        $this->assertSame(1500.0, round((float) Invoice::sum('invoice_amount'), 2));
    }

    public function test_several_missing_columns_are_one_grouped_box(): void
    {
        $token = $this->token($this->postUpload($this->without($this->file(), ['رقم امر التسليم', 'تاريخ التسليم', 'مركز الكلفة'])));

        $page = $this->actingAs($this->manager)->get(route('sheet-manager.uploads.confirm', $token))->assertOk();
        $html = $page->getContent();
        $this->assertSame(1, substr_count($html, 'id="missing-columns"'));
        $this->assertSame(3, substr_count($html, 'class="missing-column '));
        $page->assertSee('أعمدة حرجة غير موجودة في الملف (3)')
            ->assertSee('أفهم أن العمود «رقم امر التسليم»، «تاريخ التسليم»، «مركز الكلفة» غير موجود في الملف وأريد المتابعة.')
            // بلا تاريخ تسليم لا تؤجَّل أي فاتورة: الخمس «بلا كشف»
            ->assertSee('5 فاتورة ستصبح بلا كشف');

        $this->actingAs($this->manager)->post(route('sheet-manager.uploads.confirm.store', $token), ['confirm_missing_columns' => '1']);
        $batch = ImportBatch::sole();
        $this->assertSame(5, Invoice::where('placement', Invoice::UNASSIGNED)->count());

        $html = $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', $batch))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'id="missing-columns"'));
        $this->assertSame(3, substr_count($html, 'class="missing-column '));
        // صفّ حرج واحد في شريط الانتباه
        $this->assertSame(1, substr_count($html, 'أعمدة حرجة غير موجودة في ملف الدفعة'));
    }

    public function test_reupload_of_same_file_shows_previous_batch_and_reclassification(): void
    {
        $path = $this->file();
        $first = $this->importFiles($path, '2026-10-13', $this->manager)['batch'];
        $this->assertSame(64, strlen($first->fresh()->file_hash));

        // التاريخ نفسه: ملاحظة التكرار بلا إعادة تصنيف
        $this->travel(1)->minutes();
        $same = $this->importFiles($path, '2026-10-13', $this->manager)['batch'];
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', $same))->assertOk()
            ->assertSee('id="duplicate-upload"', false)
            ->assertSee('هذا الملف نفسه رُفع سابقاً في الدفعة '.substr($first->id, 0, 8))
            ->assertSee('بالمستخدم '.$this->manager->name)
            ->assertDontSee('id="duplicate-reclassify"', false);

        // تاريخ توزيع مختلف: ملاحظة إعادة التصنيف
        $this->travel(1)->minutes();
        $other = $this->importFiles($path, '2026-10-21', $this->manager)['batch'];
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', $other))->assertOk()
            ->assertSee('تاريخ التوزيع المختار الآن 2026-10-21 مقابل 2026-10-13')
            ->assertSee('id="duplicate-reclassify"', false);

        // الدفعة الأولى لا تُظهر اللاحقة (السابقة فقط)
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', $first))->assertOk()->assertDontSee('id="duplicate-upload"', false);
    }

    public function test_duplicate_notice_says_when_previous_batch_was_rolled_back(): void
    {
        $path = $this->file();
        $first = $this->importFiles($path, '2026-10-13', $this->manager)['batch'];
        $this->actingAs($this->admin)->post(route('sheet-manager.batches.rollback.store', $first), [
            'reason' => 'ملف رُفع بالخطأ', 'confirm_code' => substr($first->id, 0, 8),
        ])->assertSessionHasNoErrors();
        $this->assertTrue($first->fresh()->isRolledBack());

        $this->travel(1)->minutes();
        $again = $this->importFiles($path, '2026-10-20', $this->manager)['batch'];
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', $again))->assertOk()
            ->assertSee('وقد تُراجِع عنها')
            ->assertSee('متراجَع عنها');

        // وعلى شاشة التأكيد قبل التطبيق أيضاً (الملف نفسه بلا عمود الأمر يختلف ببصمته، فنقارن بالخدمة مباشرة)
        $notice = DuplicateUploadNotice::for($first->fresh()->file_hash, '2026-10-22');
        $this->assertSame($again->id, $notice['batch']->id);
    }

    public function test_old_batches_without_hash_cause_no_error(): void
    {
        $first = $this->importFiles($this->file(), '2026-10-13', $this->manager)['batch'];
        ImportBatch::query()->update(['file_hash' => null]);
        $this->travel(1)->minutes();
        $second = $this->importFiles($this->file(), '2026-10-13', $this->manager)['batch'];
        ImportBatch::whereKey($second->id)->update(['file_hash' => null]);

        $this->assertNull(DuplicateUploadNotice::for(null, '2026-10-13'));
        foreach ([$first, $second] as $batch) {
            $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', $batch))->assertOk()->assertDontSee('id="duplicate-upload"', false);
        }
    }

    public function test_batch_page_queries_do_not_grow_with_invoices(): void
    {
        $queries = 0;
        $listening = false;
        DB::listen(function () use (&$queries, &$listening) {
            if ($listening) {
                $queries++;
            }
        });
        $measure = function (int $n, string $prefix) use (&$queries, &$listening): int {
            $invoices = [];
            for ($i = 1; $i <= $n; $i++) {
                $invoices[] = ['number' => "{$prefix}: {$i}", 'customer' => "صيدلية {$prefix} {$i}", 'delivery' => $i % 4 ? '13-10-2026' : '20-10-2026', 'order' => 0, 'lines' => [['صنف', 10]]];
            }
            $path = $this->without($this->buildWorkbook($invoices), ['رقم امر التسليم', 'تاريخ التسليم']);
            $result = app(SheetImportService::class)->importBatch([$this->uploaded($path)], '2026-10-13', $this->manager, false, null, true);

            \Illuminate\Support\Facades\Cache::flush();
            $queries = 0;
            $listening = true;
            $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', $result['batch']))->assertOk()->assertSee('id="missing-columns"', false);
            $listening = false;

            return $queries;
        };
        $small = $measure(20, 'ع A');
        $large = $measure(200, 'ع B');
        if (getenv('PHASE7F_PRINT')) {
            fwrite(STDERR, "7G batch page queries: 20 => {$small}, 200 => {$large}\n");
        }
        $this->assertLessThanOrEqual($small + 5, $large, "20: {$small}، 200: {$large}");
    }
}
