<?php

namespace Tests\Feature\Phase7F;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\BalanceImport;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\ImportBatch;
use App\Models\ImportBatchItem;
use App\Models\ImportBatchRollback;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Import\BatchRollbackService;
use App\Services\SheetDispatchService;
use App\Services\SheetFiguresService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 7F-1/3: التراجع عن دفعة بالكامل — التكافؤ، إعادة الرفع، حارس الملف الأقدم، الموانع، الذرّية، الصلاحيات، الأداء.
 */
class BatchRollbackTest extends TestCase
{
    use RefreshDatabase, Workbooks, RollbackFixture;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpRollback();
    }

    private function rollback(ImportBatch $batch, ?User $user = null, array $data = [])
    {
        return $this->actingAs($user ?? $this->admin)->post(route('sheet-manager.batches.rollback.store', $batch), $data + [
            'reason' => 'ملف رُفع بالخطأ', 'confirm_code' => substr($batch->id, 0, 8),
        ]);
    }

    public function test_full_rollback_restores_database_and_reupload_is_clean(): void
    {
        $this->upload($this->baseFile(), '2026-10-10');
        $before = $this->dbState();

        $wrong = $this->upload($this->wrongFile(), '2026-10-13');
        $firstItems = ImportBatchItem::where('batch_id', $wrong->id)->selectRaw('kind, status, COUNT(*) c')->groupBy('kind', 'status')->pluck('c', 'kind')->all();
        $this->assertSame('in_sheet', Invoice::where('invoice_key', 'like', '%G%5')->value('placement'));   // نُقلت من «مؤجلة»
        $this->assertNotEquals($before, $this->dbState());

        // المعاينة بأرقام فعلية
        $page = $this->actingAs($this->admin)->get(route('sheet-manager.batches.rollback', $wrong))->assertOk()->getContent();
        $this->assertStringContainsString('ماذا سيحدث', $page);
        $counts = app(BatchRollbackService::class)->analyze($wrong)['counts'];
        $this->assertSame(4, $counts['invoices_deleted']);         // G:2 G:3 G:4 G:7
        $this->assertSame(1, $counts['deferred_removed']);         // G:4
        $this->assertSame(1, $counts['invoices_restored']);        // G:5 تعود مؤجلة
        $this->assertSame(1, $counts['sheets_deleted']);           // كشف 3918 بتاريخ 13-10
        $this->assertSame(2, $counts['customers_deleted']);        // ج (من الأرصدة) ود (من الحركة)
        $this->assertGreaterThan(0, $counts['customers_balance_restored']);
        $this->assertGreaterThan(0, $counts['snapshots_deleted']);

        // التأكيد: سبب ورقم الدفعة إلزاميان
        $this->rollback($wrong, null, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->rollback($wrong, null, ['confirm_code' => 'xxxx'])->assertSessionHasErrors('confirm_code');
        $this->rollback($wrong)->assertSessionHasNoErrors()->assertRedirect(route('sheet-manager.batches.show', $wrong));

        $this->assertEquals($before, $this->dbState(), 'القاعدة بعد التراجع يجب أن تطابق ما قبل الدفعة');
        $wrong->refresh();
        $this->assertTrue($wrong->isRolledBack());
        $this->assertSame('ملف رُفع بالخطأ', $wrong->rollback_reason);
        $this->assertSame(BalanceImport::ROLLED_BACK, BalanceImport::where('batch_id', $wrong->id)->value('status'));
        $log = ActivityLog::where('action', 'import.rolled_back')->sole();
        $this->assertSame($wrong->id, $log->meta['batch_id']);
        $this->assertSame(4, $log->new_values['invoices_deleted']);
        $audit = ImportBatchRollback::sole()->auditData();
        $this->assertCount(4, $audit['deleted_invoices']);
        $this->assertCount(2, $audit['deleted_customers']);
        // صفحة الدفعة تبقى للاطلاع بالشارة، بلا زر تراجع
        $this->actingAs($this->admin)->get(route('sheet-manager.batches.show', $wrong))->assertOk()
            ->assertSee('تم التراجع عن هذه الدفعة')->assertDontSee('id="batch-rollback"', false);
        $this->actingAs($this->admin)->get(route('sheet-manager.batches.show', ['batch' => $wrong, 'tab' => 'invoices', 'kind' => 'all']))->assertOk();

        // إعادة الرفع: كأول مرة (لا تكرار، لا «مكرر»)
        $again = $this->upload($this->wrongFile(), '2026-10-13');
        $this->assertSame($firstItems, ImportBatchItem::where('batch_id', $again->id)->selectRaw('kind, status, COUNT(*) c')->groupBy('kind', 'status')->pluck('c', 'kind')->all());
        $this->assertSame(4, ImportBatchItem::where('batch_id', $again->id)->where('kind', ImportBatchItem::CREATED)->count());
        $this->assertSame('applied', BalanceImport::where('batch_id', $again->id)->value('status'));
        $this->assertSame(1, Invoice::where('invoice_key', 'like', '%G%2')->count());
    }

    public function test_older_balance_file_is_accepted_after_rollback(): void
    {
        $this->upload($this->baseFile(), '2026-10-10');
        $later = $this->upload($this->wrongFile('15-10-2026'), '2026-10-13');
        $this->assertSame('applied', BalanceImport::where('batch_id', $later->id)->value('status'));

        // بلا تراجع: ملف أرصدة أقدم من المطبَّق يُحجز
        $this->rollback($later)->assertSessionHasNoErrors();
        $older = $this->upload($this->wrongFile('12-10-2026'), '2026-10-13');
        $import = BalanceImport::where('batch_id', $older->id)->sole();
        $this->assertSame('applied', $import->status, 'ملف الدفعة المتراجع عنها لا يُحتسب في حارس الملف الأقدم');
        $this->assertNull($import->hold_reason);
        $this->assertSame('2026-10-12', Customer::where('name', 'صيدلية أ')->first()->balance_as_of->toDateString());
        $this->assertSame($import->id, BalanceImport::latestApplied()->id);
    }

    public function test_newer_batch_blocks_and_nothing_changes(): void
    {
        $first = $this->upload($this->baseFile(), '2026-10-10');
        $second = $this->upload($this->wrongFile(), '2026-10-13');
        $state = $this->dbState();

        $analysis = app(BatchRollbackService::class)->analyze($first);
        $this->assertFalse($analysis['eligible']);
        $blocker = collect($analysis['blockers'])->firstWhere('code', 'newer_batch');
        $this->assertSame(1, $blocker['count']);
        $this->assertStringContainsString('2026-10-13', $blocker['text']);
        $this->rollback($first)->assertSessionHasErrors('rollback');
        $this->actingAs($this->admin)->get(route('sheet-manager.batches.rollback', $first))->assertOk()
            ->assertSee('لا يمكن التراجع')->assertSee('الاستبعاد الجماعي');
        $this->assertEquals($state, $this->dbState());

        // بالترتيب العكسي يُقبل
        $this->rollback($second)->assertSessionHasNoErrors();
        $this->rollback($first)->assertSessionHasNoErrors();
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Customer::count());
        $this->assertSame(0, DistributionSheet::count());
    }

    public static function blockerCases(): array
    {
        return ['كشف منشور' => ['in_published_sheet'], 'تصدير مالي' => ['finance'], 'تعديل لاحق' => ['adjustments'], 'طباعة' => ['printed']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blockerCases')]
    public function test_activity_blockers_each_prevent_rollback(string $code): void
    {
        $this->upload($this->baseFile(), '2026-10-10');
        $wrong = $this->upload($this->wrongFile(), '2026-10-13');
        match ($code) {
            // الإرسال الفعلي يتطلب معالجة «بلا كشف» أولاً؛ هنا يكفي أن يصبح كشف الدفعة منشوراً
            'in_published_sheet' => DistributionSheet::where('batch_id', $wrong->id)->update(['status' => 'pending']),
            'finance' => (function () {
                $invoice = Invoice::where('invoice_key', 'like', '%G%3')->firstOrFail();
                $sheet = DistributionSheet::firstOrFail();
                $export = DB::table('finance_exports')->insertGetId(['distribution_sheet_id' => $sheet->id, 'sequence' => 1, 'exported_by' => $this->admin->id,
                    'created_at' => now(), 'updated_at' => now()]);
                DB::table('payment_allocations')->insert([
                    'finance_export_id' => $export, 'distribution_sheet_id' => $sheet->id, 'customer_id' => $invoice->customer_id, 'invoice_id' => $invoice->id,
                    'allocation_group' => 'g', 'invoice_total' => 300, 'remaining_before' => 300, 'allocated' => 100, 'unpaid' => 200, 'status' => 'partial',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            })(),
            'adjustments' => DB::table('invoice_adjustments')->insert(['invoice_id' => Invoice::where('invoice_key', 'like', '%G%2')->value('id'), 'kind' => 'manual',
                'old_amount' => 220, 'new_amount' => 200, 'delta' => -20, 'reason_code' => 'other', 'status' => 'applied', 'created_at' => now(), 'updated_at' => now()]),
            'printed' => DB::table('print_events')->insert(['client_uuid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $this->admin->id, 'kind' => 'invoice',
                'invoice_id' => Invoice::where('invoice_key', 'like', '%G%7')->value('id'), 'mode' => 'browser', 'status' => 'success', 'created_at' => now()]),
        };
        $state = $this->dbState();

        $analysis = app(BatchRollbackService::class)->analyze($wrong);
        $blocker = collect($analysis['blockers'])->firstWhere('code', $code);
        $this->assertNotNull($blocker, "المانع {$code}");
        $this->assertGreaterThan(0, $blocker['count']);
        $this->assertNotEmpty($blocker['examples']);
        $this->rollback($wrong)->assertSessionHasErrors('rollback');
        $this->assertEquals($state, $this->dbState());
        $this->assertFalse($wrong->fresh()->isRolledBack());
    }

    public function test_payment_on_published_sheet_blocks(): void
    {
        $this->upload($this->baseFile(), '2026-10-10');
        $wrong = $this->upload($this->wrongFile(), '2026-10-13');
        $sheet = DistributionSheet::where('batch_id', $wrong->id)->firstOrFail();
        $sheet->update(['status' => 'in_progress']);
        $invoice = Invoice::where('distribution_sheet_id', $sheet->id)->firstOrFail();
        DB::table('payments')->insert(['client_uuid' => (string) \Illuminate\Support\Str::uuid(), 'receipt_number' => 'R-1', 'distribution_sheet_id' => $sheet->id,
            'customer_id' => $invoice->customer_id, 'entered_by' => $this->admin->id, 'entered_role' => 'distributor', 'source' => 'sheet', 'amount' => 50,
            'currency' => 'SYP', 'amount_syp' => 50, 'status' => 'pending', 'location_flag' => 'no_gps', 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $codes = collect(app(BatchRollbackService::class)->analyze($wrong)['blockers'])->pluck('code')->all();
        $this->assertContains('sheet_activity_customer', $codes);
        $this->assertContains('in_published_sheet', $codes);
    }

    public function test_atomic_when_failure_in_the_middle(): void
    {
        $this->upload($this->baseFile(), '2026-10-10');
        $wrong = $this->upload($this->wrongFile(), '2026-10-13');
        $state = $this->dbState();

        $this->app->instance(SheetFiguresService::class, new class extends SheetFiguresService
        {
            public function __construct()
            {
            }

            public function refreshMany(iterable $sheetIds): void
            {
                throw new \RuntimeException('فشل مصطنع في منتصف التراجع');
            }
        });
        try {
            app(BatchRollbackService::class)->rollback($wrong, $this->admin, 'سبب');
            $this->fail('كان يجب أن يفشل');
        } catch (\RuntimeException $e) {
            $this->assertSame('فشل مصطنع في منتصف التراجع', $e->getMessage());
        }
        $this->assertEquals($state, $this->dbState());
        $this->assertFalse($wrong->fresh()->isRolledBack());
        $this->assertSame(0, ImportBatchRollback::count());
        $this->assertSame(0, ActivityLog::where('action', 'import.rolled_back')->count());
    }

    public function test_only_admin_can_preview_or_execute(): void
    {
        $wrong = $this->upload($this->wrongFile(), '2026-10-13');
        foreach ([Role::SheetManager, Role::Supervisor, Role::Accountant, Role::RepSupervisor, Role::Distributor, Role::Rep] as $role) {
            $user = User::factory()->role($role)->create();
            $this->actingAs($user)->get(route('sheet-manager.batches.rollback', $wrong))->assertForbidden();
            $this->rollback($wrong, $user)->assertForbidden();
        }
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', $wrong))->assertOk()->assertDontSee('id="batch-rollback"', false);
        $this->actingAs($this->admin)->get(route('sheet-manager.batches.show', $wrong))->assertSee('id="batch-rollback"', false);
        $this->assertFalse($wrong->fresh()->isRolledBack());
    }

    public function test_query_count_does_not_grow_with_invoices(): void
    {
        $customers = array_map(fn ($i) => ['صيدلية رقم '.$i, 'كتلة', 'منطقة '.($i % 40), 100 + $i, 0], range(1, 4500));
        $measure = function (int $n, int $offset) use ($customers): int {
            $invoices = array_map(fn ($i) => ['number' => 'ع G: '.($offset + $i), 'customer' => 'صيدلية رقم '.(($i % 4500) + 1), 'delivery' => '13-10-2026',
                'order' => $i % 3 === 0 ? 3918 : 0, 'lines' => [['صنف', 10 + $i]]], range(1, $n));
            $batch = $this->upload($this->buildWorkbook($invoices, $customers, '13-10-2026'), '2026-10-13');
            $this->assertSame($n, Invoice::count());
            $queries = 0;
            $listening = true;
            DB::listen(function () use (&$queries, &$listening) {
                if ($listening) {
                    $queries++;
                }
            });
            $start = microtime(true);
            app(BatchRollbackService::class)->rollback($batch, $this->admin, 'قياس');
            $listening = false;
            $this->assertLessThan(30, microtime(true) - $start, 'التراجع يجب أن يتم في ثوانٍ');
            $this->assertSame(0, Invoice::count());
            $this->assertSame(0, Customer::count());

            return $queries;
        };
        $small = $measure(300, 10000);
        $large = $measure(1200, 20000);
        if (getenv('PHASE7F_PRINT')) {
            fwrite(STDERR, "rollback queries: 300 => {$small}, 1200 => {$large}
");
        }
        // دفعات 500: فرق بضعة استعلامات لكل دفعة إضافية، لا استعلام لكل فاتورة
        $this->assertLessThanOrEqual($small + 40, $large, "300 فاتورة: {$small} استعلاماً، 1,200: {$large}");
    }
}
