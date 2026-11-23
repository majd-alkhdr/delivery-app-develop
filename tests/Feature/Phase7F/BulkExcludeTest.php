<?php

namespace Tests\Feature\Phase7F;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\InvoiceBulkOperation;
use App\Models\User;
use App\Services\BulkInvoiceActionService;
use App\Services\SheetInvoiceEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 7F-2/3.6/3.7: الاستبعاد الجماعي (المحدد وكل نتائج التصفية)، الاستثناء بأسبابه، السجل، الذرّية،
 * إعادة الإدراج، الإلغاء الجماعي للمؤجلة، «مستبعَدة سابقاً» عند إعادة الرفع، والأداء.
 */
class BulkExcludeTest extends TestCase
{
    use RefreshDatabase, Workbooks, RollbackFixture;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpRollback();
    }

    /** ملف فيه $n فاتورة «بلا كشف» و$deferred مؤجلة */
    private function file(int $n, int $deferred = 0, int $offset = 1000): string
    {
        $invoices = [];
        foreach (range(1, $n) as $i) {
            $invoices[] = ['number' => 'ع G: '.($offset + $i), 'customer' => 'صيدلية '.($i % 25), 'delivery' => '13-10-2026', 'order' => 0, 'lines' => [['صنف', 100 + $i]]];
        }
        foreach ($deferred > 0 ? range(1, $deferred) : [] as $i) {
            $invoices[] = ['number' => 'ع D: '.($offset + $i), 'customer' => 'صيدلية '.($i % 25), 'delivery' => '25-10-2026', 'order' => 0, 'lines' => [['صنف', 50]]];
        }

        return $this->buildWorkbook($invoices);
    }

    private function bulk(array $data, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->manager)->post(route('bulk-invoices.store'), $data);
    }

    public function test_select_all_filtered_results_across_pages_with_skips_and_logs(): void
    {
        $batch = $this->upload($this->file(60), '2026-10-13');
        $printed = Invoice::where('invoice_number', 'ع G: 1001')->firstOrFail();
        DB::table('print_events')->insert(['client_uuid' => (string) Str::uuid(), 'user_id' => $this->admin->id, 'kind' => 'invoice',
            'invoice_id' => $printed->id, 'mode' => 'browser', 'status' => 'success', 'created_at' => now()]);

        // الصفحة تعرض 20 فقط، والشريط يعرض كل نتائج التصفية (60)
        $page = $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $batch, 'tab' => 'sheets']))->assertOk()->getContent();
        $this->assertSame(20, substr_count($page, 'form="bulk-batch_unassigned"'));
        $this->assertStringContainsString('تحديد كل نتائج التصفية (60 فاتورة)', $page);

        // المعاينة: 59 مؤهَّلة، وواحدة مستثناة بسببها ومثالها
        $preview = $this->actingAs($this->manager)->get(route('bulk-invoices.preview', ['scope' => 'batch_unassigned', 'batch' => $batch->id, 'all' => 1]))->assertOk();
        $preview->assertSee('data-bulk-eligible>59<', false)->assertSee('طُبعت للصيدلية')->assertSee('ع G: 1001');

        // السبب المشترك إلزامي
        $this->bulk(['scope' => 'batch_unassigned', 'batch' => $batch->id, 'all' => 1])->assertSessionHasErrors('reason');
        $this->bulk(['scope' => 'batch_unassigned', 'batch' => $batch->id, 'all' => 1, 'reason_choice' => '__other', 'reason_other' => ''])->assertSessionHasErrors('reason');
        $this->assertSame(0, Invoice::where('placement', Invoice::EXCLUDED)->count());

        $this->bulk(['scope' => 'batch_unassigned', 'batch' => $batch->id, 'all' => 1, 'reason_choice' => 'ملف رُفع بالخطأ'])->assertSessionHasNoErrors();
        $this->assertSame(59, Invoice::where('placement', Invoice::EXCLUDED)->where('exclusion_reason', 'ملف رُفع بالخطأ')->count());
        $this->assertSame(Invoice::UNASSIGNED, $printed->fresh()->placement);

        $operation = InvoiceBulkOperation::sole();
        $this->assertSame(59, $operation->count);
        $this->assertSame('طُبعت للصيدلية:', $operation->skipped[0]['text']);
        $logs = ActivityLog::where('action', 'invoice.excluded')->get();
        $this->assertCount(59, $logs);
        $log = $logs->first();
        $this->assertSame($operation->uuid, $log->meta['bulk_operation_id']);
        $this->assertSame(Invoice::UNASSIGNED, $log->old_values['placement']);
        $this->assertSame(Invoice::EXCLUDED, $log->new_values['placement']);
        $this->assertSame('ملف رُفع بالخطأ', $log->new_values['reason']);
        $this->assertSame(Invoice::class, $log->subject_type);
        $summary = ActivityLog::where('action', 'invoices.bulk_excluded')->sole();
        $this->assertSame(59, $summary->new_values['count']);
        $this->assertSame(1, $summary->meta['skipped'][0]['count']);
    }

    public function test_explicit_selection_and_wrong_state_is_skipped(): void
    {
        $batch = $this->upload($this->file(5, 2), '2026-10-13');
        $ids = Invoice::where('placement', Invoice::UNASSIGNED)->orderBy('id')->limit(3)->pluck('id')->all();
        $deferred = Invoice::where('placement', Invoice::DEFERRED)->value('id');

        $this->bulk(['scope' => 'batch_unassigned', 'batch' => $batch->id, 'ids' => [...$ids, $deferred], 'reason_choice' => 'فواتير مكررة'])->assertSessionHasNoErrors();
        $this->assertSame($ids, Invoice::where('placement', Invoice::EXCLUDED)->orderBy('id')->pluck('id')->all());
        $this->assertSame(Invoice::DEFERRED, Invoice::find($deferred)->placement);
        $this->assertStringContainsString('ليست في الحالة المطلوبة', InvoiceBulkOperation::sole()->skipped[0]['text']);
        // بلا تحديد
        $this->bulk(['scope' => 'batch_unassigned', 'batch' => $batch->id, 'reason_choice' => 'فواتير مكررة'])->assertSessionHasErrors('ids');
    }

    public function test_atomic_bulk_exclusion(): void
    {
        $batch = $this->upload($this->file(30), '2026-10-13');
        $deps = [app(\App\Services\SheetFiguresService::class), app(\App\Services\SheetActivityGuard::class), app(\App\Services\Import\InvoiceReconciler::class),
            app(\App\Services\VisitService::class), app(\App\Services\SheetChangeNotifier::class)];
        $this->app->instance(SheetInvoiceEditor::class, new class(...$deps) extends SheetInvoiceEditor
        {
            public function excludeMany(Collection $invoices, string $reason, User $user, string $operationId): int
            {
                parent::excludeMany($invoices, $reason, $user, $operationId);
                throw new \RuntimeException('فشل بعد الكتابة');
            }
        });
        try {
            app(BulkInvoiceActionService::class)->execute('batch_unassigned', ['batch' => $batch->id], null, 'سبب مشترك', $this->manager);
            $this->fail('كان يجب أن يفشل');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, Invoice::where('placement', Invoice::EXCLUDED)->count());
        $this->assertSame(0, InvoiceBulkOperation::count());
        $this->assertSame(0, ActivityLog::where('action', 'invoice.excluded')->count());
    }

    public function test_bulk_reinsertion_with_preview_and_changed_invoices(): void
    {
        $batch = $this->upload($this->file(10), '2026-10-13');
        $this->bulk(['scope' => 'batch_unassigned', 'batch' => $batch->id, 'all' => 1, 'reason_choice' => 'ملف رُفع بالخطأ'])->assertSessionHasNoErrors();
        $operation = InvoiceBulkOperation::sole();
        // فاتورة أُعيدت يدوياً بعد العملية: لا تُعدّ ضمن الإعادة
        $one = Invoice::where('placement', Invoice::EXCLUDED)->orderBy('id')->first();
        app(SheetInvoiceEditor::class)->restore($one, $this->manager);

        $this->actingAs($this->manager)->get(route('bulk-invoices.undo.preview', $operation))->assertOk()
            ->assertSee('data-undo-eligible>9<', false)->assertSee('تغيّرت حالتها أو سببها');
        $this->actingAs($this->manager)->post(route('bulk-invoices.undo', $operation))->assertSessionHasErrors('confirm');
        $this->actingAs($this->manager)->post(route('bulk-invoices.undo', $operation), ['confirm' => 1])->assertSessionHasNoErrors();

        $this->assertSame(10, Invoice::where('placement', Invoice::UNASSIGNED)->whereNull('exclusion_reason')->count());
        $this->assertNotNull($operation->fresh()->undone_at);
        $undo = InvoiceBulkOperation::where('undoes_id', $operation->id)->sole();
        $this->assertSame(9, $undo->count);
        $this->assertSame(9, ActivityLog::where('action', 'invoice.restored')->where('meta->bulk_operation_id', $undo->uuid)->count());
        // لا تُعاد مرتين
        $this->actingAs($this->manager)->post(route('bulk-invoices.undo', $operation), ['confirm' => 1])->assertSessionHasErrors('operation');
        $this->actingAs($this->manager)->get(route('bulk-invoices.undo.preview', $operation))->assertOk()->assertSee('أُعيد إدراج هذه العملية سابقاً');
    }

    public function test_bulk_cancel_deferred_and_reinsert(): void
    {
        $batch = $this->upload($this->file(2, 6), '2026-10-13');
        $this->assertSame(6, Invoice::where('placement', Invoice::DEFERRED)->count());
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $batch, 'tab' => 'deferred']))->assertOk()
            ->assertSee('تحديد كل نتائج التصفية (6 فاتورة)');
        $this->actingAs($this->manager)->get(route('invoices.deferred'))->assertOk()->assertSee('form="bulk-deferred"', false);

        $this->bulk(['scope' => 'batch_deferred', 'batch' => $batch->id, 'all' => 1, 'reason_choice' => 'أُلغيت في المحاسبة'])->assertSessionHasNoErrors();
        $this->assertSame(6, Invoice::where('placement', Invoice::CANCELLED)->where('is_deferred', false)->count());
        $this->assertSame(6, ActivityLog::where('action', 'invoice.cancelled')->count());

        $operation = InvoiceBulkOperation::sole();
        $this->actingAs($this->manager)->post(route('bulk-invoices.undo', $operation), ['confirm' => 1])->assertSessionHasNoErrors();
        $this->assertSame(6, Invoice::where('placement', Invoice::DEFERRED)->where('is_deferred', true)->whereNull('cancelled_reason')->count());
        $this->assertSame(6, ActivityLog::where('action', 'invoice.uncancelled')->count());
    }

    public function test_reupload_shows_previously_excluded_as_info_with_bulk_reinsert(): void
    {
        $first = $this->upload($this->file(8), '2026-10-13');
        $this->bulk(['scope' => 'batch_unassigned', 'batch' => $first->id, 'all' => 1, 'reason_choice' => 'ملف رُفع بالخطأ'])->assertSessionHasNoErrors();

        $second = $this->upload($this->file(8), '2026-10-13');
        $this->assertSame(8, Invoice::count(), 'لا تكرار للفواتير');
        $this->assertSame(0, \App\Models\ImportBatchItem::where('batch_id', $second->id)->where('status', 'pending')->count(), 'لا أخطاء ولا قرارات معلّقة');
        $page = $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', $second))->assertOk()->getContent();
        $this->assertStringContainsString('8 فاتورة في هذا الملف مستبعَدة سابقاً', $page);
        $this->assertStringContainsString('إبقاء الاستبعاد', $page);

        $this->bulk(['scope' => 'batch_excluded', 'batch' => $second->id, 'all' => 1])->assertSessionHasNoErrors();
        $this->assertSame(8, Invoice::where('placement', Invoice::UNASSIGNED)->count());
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', $second))->assertDontSee('مستبعَدة سابقاً');
    }

    public function test_permissions(): void
    {
        $batch = $this->upload($this->file(3), '2026-10-13');
        foreach ([Role::Supervisor, Role::Accountant, Role::RepSupervisor, Role::Distributor, Role::Rep] as $role) {
            $user = User::factory()->role($role)->create();
            $this->actingAs($user)->get(route('bulk-invoices.preview', ['scope' => 'batch_unassigned', 'batch' => $batch->id, 'all' => 1]))->assertForbidden();
            $this->bulk(['scope' => 'batch_unassigned', 'batch' => $batch->id, 'all' => 1, 'reason_choice' => 'سبب'], $user)->assertForbidden();
        }
        $this->bulk(['scope' => 'batch_unassigned', 'batch' => $batch->id, 'all' => 1, 'reason_choice' => 'ملف رُفع بالخطأ'], $this->admin)->assertSessionHasNoErrors();
        $this->assertSame(3, Invoice::where('placement', Invoice::EXCLUDED)->count());
    }

    public function test_query_count_does_not_grow_with_invoices(): void
    {
        $measure = function (int $n, int $offset): int {
            $batch = $this->upload($this->file($n, 0, $offset), '2026-10-13');
            $queries = 0;
            $listening = true;
            DB::listen(function () use (&$queries, &$listening) {
                if ($listening) {
                    $queries++;
                }
            });
            app(BulkInvoiceActionService::class)->execute('batch_unassigned', ['batch' => $batch->id], null, 'قياس الأداء', $this->manager);
            $listening = false;

            return $queries;
        };
        $small = $measure(300, 10000);
        $large = $measure(1200, 20000);
        if (getenv('PHASE7F_PRINT')) {
            fwrite(STDERR, "bulk exclude queries: 300 => {$small}, 1200 => {$large}\n");
        }
        $this->assertSame(1500, Invoice::where('placement', Invoice::EXCLUDED)->count());
        $this->assertLessThanOrEqual($small + 25, $large, "300: {$small}، 1,200: {$large}");
    }
}
