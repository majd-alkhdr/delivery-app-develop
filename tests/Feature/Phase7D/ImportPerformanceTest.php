<?php

namespace Tests\Feature\Phase7D;

use App\Models\ActivityLog;
use App\Models\BalanceImport;
use App\Models\Customer;
use App\Models\CustomerBalanceSnapshot;
use App\Models\ImportBatch;
use App\Models\ImportBatchItem;
use App\Models\Invoice;
use App\Models\SheetChangeNotice;
use App\Services\Balances\BalanceDeltaExplainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Phase7B\BalancesFixture;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 7D: رفع ملف كبير بعدد استعلامات لا يتبع عدد الزبائن، والرفع ذرّي عند الفشل.
 */
class ImportPerformanceTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity, BalancesFixture;

    private const OLD = 196;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBalances();
    }

    /** كشف 1580 منشور لـ«صيدلية التجربة»، و200 زبون سابق برصيد (13-10) */
    private function existing(): void
    {
        $this->publish();
        $this->upload($this->balancesFile(['صيدلية التجربة' => 5000] + $this->others() + $this->oldBalances(0)));
        $this->assertSame(200, Customer::count());
    }

    private function oldBalances(float $plus): array
    {
        $rows = [];
        for ($i = 1; $i <= self::OLD; $i++) {
            $rows["زبون قديم {$i}"] = 1000 + $i + $plus;
        }

        return $rows;
    }

    /**
     * ملف كبير بتاريخ 20-10: كل السابقين بأرصدة تغيّرت +10 عدا اثنين غابا، و$new زبوناً جديداً، ونحو 150 فاتورة.
     */
    private function largeFile(int $new): string
    {
        $balances = ['صيدلية التجربة' => 5010] + array_map(fn ($v) => $v + 10, $this->others()) + $this->oldBalances(10);
        unset($balances['زبون قديم 1'], $balances['زبون قديم 2']);
        for ($i = 1; $i <= $new; $i++) {
            $balances["زبون جديد {$i}"] = 500 + $i;
        }

        $invoices = [
            ['number' => 'ع G: 1', 'customer' => 'صيدلية التجربة', 'cc' => '655231-مندوب أ', 'order' => 1580, 'lines' => [['صنف أ', 1000]]],
            ['number' => 'ع G: 2', 'customer' => 'صيدلية التجربة', 'cc' => '700700-مندوب ب', 'order' => 1580, 'lines' => [['صنف ب', 500]]],
        ];
        for ($i = 0; $i < 148; $i++) {
            $invoices[] = ['number' => 'ع G: '.(5000 + $i), 'customer' => $i < 100 ? 'زبون جديد '.($i + 1) : 'زبون قديم '.($i - 90),
                'order' => 2000 + ($i % 3), 'lines' => [['صنف '.$i, 100 + $i], ['صنف إضافي', 50]]];
        }

        return $this->buildWorkbook($invoices, array_map(fn ($name, $v) => [$name, 'كتلة', 'منطقة التجربة', $v, 0], array_keys($balances), $balances), '20-10-2026');
    }

    /**
     * رفع الملف الكبير مع عدّ الاستعلامات والتحقق من النتيجة الوظيفية.
     */
    private function uploadLarge(int $new): int
    {
        $path = $this->largeFile($new);
        $queries = 0;
        $counting = true;
        DB::listen(function () use (&$queries, &$counting) {
            $queries += $counting ? 1 : 0;
        });
        $result = $this->upload($path);
        $counting = false;

        $this->assertTrue($result['reports'][0]['success'], (string) $result['reports'][0]['error']);
        $import = BalanceImport::where('batch_id', $result['batch_id'])->sole();
        $this->assertSame(BalanceImport::APPLIED, $import->status);
        $this->assertSame(200 + $new, Customer::count());

        $kinds = CustomerBalanceSnapshot::where('balance_import_id', $import->id)->selectRaw('kind, COUNT(*) c')->groupBy('kind')->pluck('c', 'kind')->map(fn ($c) => (int) $c)->all();
        ksort($kinds);
        $this->assertSame(['changed' => 198, 'created' => $new, 'zeroed' => 2], $kinds);

        // التفسير لعيّنة
        $snap = fn (string $name) => CustomerBalanceSnapshot::where('balance_import_id', $import->id)->whereHas('customer', fn ($q) => $q->where('name', $name))->sole();
        $this->assertStringStartsWith('ارتفع الرصيد 10: دفعات مصدَّرة 0، تعديلات فواتير 0، غير مفسَّر +10', $snap('زبون قديم 50')->explanation);
        $this->assertSame('زبون جديد في ملف الأرصدة برصيد 507.', $snap('زبون جديد 7')->explanation);
        $this->assertStringStartsWith('غاب عن ملف الأرصدة فصُفِّر. — انخفض الرصيد 1,001', $snap('زبون قديم 1')->explanation);

        // الإشعار لمن له كشف منشور فقط
        $notices = SheetChangeNotice::where('type', 'balance_changed')->get()
            ->filter(fn ($n) => CustomerBalanceSnapshot::find($n->meta['snapshot_id'])?->balance_import_id === $import->id);
        $this->assertSame([$this->pharmacy->id], $notices->pluck('customer_id')->all());
        $this->assertSame($this->sheet->id, $notices->first()->distribution_sheet_id);
        $this->assertStringContainsString('تغيّر رصيد صيدلية التجربة من 5,000 إلى 5,010', $notices->first()->summary);

        // الفواتير: 148 جديدة، و1،2 بلا تغيير
        $items = ImportBatchItem::where('batch_id', $result['batch_id'])->where('kind', '!=', ImportBatchItem::NOTE)->get();
        $this->assertSame(148, $items->where('kind', ImportBatchItem::CREATED)->count());
        $this->assertSame(2, $items->where('kind', ImportBatchItem::UNCHANGED)->count());
        $this->assertSame(150, Invoice::count());

        return $queries;
    }

    public function test_large_upload_query_count_does_not_grow_with_customers(): void
    {
        $this->existing();

        // 1,000 زبون جديد ثم التراجع عنه، ثم 3,000 على الحالة نفسها
        DB::beginTransaction();
        $small = $this->uploadLarge(1000);
        DB::rollBack();
        $large = $this->uploadLarge(3000);

        $this->assertLessThan(600, $large, "عدد الاستعلامات للملف الكبير: {$large}");
        $this->assertLessThan(60, $large - $small, "الفرق بين 1,000 و3,000 زبون: {$small} ← {$large}");
    }

    private function balancesSnapshot(): array
    {
        return [
            Customer::count(),
            CustomerBalanceSnapshot::count(),
            Invoice::count(),
            ImportBatchItem::count(),
            Customer::orderBy('id')->pluck('balance', 'name')->map(fn ($b) => (float) $b)->all(),
        ];
    }

    private function failingUpload(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->manager)->post(route('sheet-manager.upload'), [
            'excel_files' => [$this->uploaded($this->largeFile(50), 'كبير.xlsx')],
            'distribution_date' => '2026-10-13',
        ]);
    }

    public function test_failure_in_finalize_rolls_back_balances_snapshots_invoices_and_items(): void
    {
        $this->existing();
        $before = $this->balancesSnapshot();
        $this->app->bind(BalanceDeltaExplainer::class, fn () => new class extends BalanceDeltaExplainer
        {
            public function explainMany(Collection $snapshots, ?\Carbon\CarbonInterface $since, ?\Carbon\CarbonInterface $until): void
            {
                throw new RuntimeException('فشل محقون في التفسير');
            }
        });

        $response = $this->failingUpload();
        $batch = ImportBatch::latest('created_at')->first();
        $response->assertRedirect(route('sheet-manager.batches.show', $batch->id));

        $this->assertSame($before, $this->balancesSnapshot());
        $this->assertSame(0, BalanceImport::where('batch_id', $batch->id)->count());
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', $batch->id))->assertOk()
            ->assertSee('تعذّر إكمال الرفع ولم يُطبَّق أي شيء من هذا الملف، يمكنك إعادة المحاولة.');

        $log = ActivityLog::where('action', 'import.failed')->sole();
        $this->assertSame('finalize', $log->meta['stage']);
        $this->assertSame('فشل محقون في التفسير', $log->meta['reason']);
        $this->assertSame('كبير.xlsx', $log->meta['file_name']);
        \Illuminate\Support\Facades\Storage::assertExists($log->meta['stored_path']);   // الملف المرفوع يبقى لإعادة المحاولة
    }

    public function test_failure_while_saving_items_rolls_back_everything(): void
    {
        $this->existing();
        $before = $this->balancesSnapshot();
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'insert into "import_batch_items"') && str_contains($query->sql, '"invoice_id"')) {
                throw new RuntimeException('انقطاع أثناء حفظ العناصر');
            }
        });

        $this->failingUpload();

        $this->assertSame($before, $this->balancesSnapshot());
        $this->assertSame('items', ActivityLog::where('action', 'import.failed')->sole()->meta['stage']);
    }

    public function test_exception_escaping_the_batch_rolls_back_all_and_shows_arabic_message(): void
    {
        $this->existing();
        $before = $this->balancesSnapshot();
        $batches = ImportBatch::count();
        ImportBatch::creating(fn () => throw new RuntimeException('تعذّر حفظ الدفعة'));

        $response = $this->failingUpload();

        $response->assertRedirect(route('sheet-manager.index'));
        $response->assertSessionHas('error', fn ($m) => str_starts_with($m, 'تعذّر إكمال الرفع ولم يُطبَّق أي شيء، يمكنك إعادة المحاولة.'));
        $this->assertSame($before, $this->balancesSnapshot());
        $this->assertSame($batches, ImportBatch::count());
        $this->assertSame(0, BalanceImport::where('file_name', 'كبير.xlsx')->count());

        $log = ActivityLog::where('action', 'import.failed')->sole();
        $this->assertSame('batch', $log->meta['stage']);
        $this->assertSame('تعذّر حفظ الدفعة', $log->meta['reason']);
        $this->assertSame(['كبير.xlsx'], $log->meta['file_names']);
    }

    public function test_real_file_uploads_and_reupload_is_unchanged(): void
    {
        $path = base_path('tests/Support/Workbooks/real_large_upload.xlsx');
        if (! is_file($path)) {
            $this->markTestSkipped('الملف الحقيقي غير موجود في tests/Support/Workbooks/real_large_upload.xlsx');
        }

        $first = app(\App\Services\Import\SheetImportService::class)->importBatch([$this->uploaded($path, 'real.xlsx')], '2026-10-21', $this->manager);
        $this->assertTrue($first['reports'][0]['success'], (string) $first['reports'][0]['error']);
        $this->assertSame(BalanceImport::APPLIED, BalanceImport::where('batch_id', $first['batch_id'])->value('status'));
        $customers = Customer::count();
        $invoices = Invoice::count();

        $second = app(\App\Services\Import\SheetImportService::class)->importBatch([$this->uploaded($path, 'real.xlsx')], '2026-10-21', $this->manager);
        $this->assertTrue($second['reports'][0]['success'], (string) $second['reports'][0]['error']);
        $import = BalanceImport::where('batch_id', $second['batch_id'])->sole();
        $this->assertSame(BalanceImport::APPLIED, $import->status);
        $this->assertSame(0, CustomerBalanceSnapshot::where('balance_import_id', $import->id)->count());
        $this->assertSame($customers, $import->stats['unchanged']);
        $this->assertSame([$customers, $invoices], [Customer::count(), Invoice::count()]);

        $kinds = ImportBatchItem::where('batch_id', $second['batch_id'])->where('kind', '!=', ImportBatchItem::NOTE)->distinct()->pluck('kind')->all();
        $this->assertSame([ImportBatchItem::UNCHANGED], $kinds);
        $this->assertSame($invoices, ImportBatchItem::where('batch_id', $second['batch_id'])->where('kind', ImportBatchItem::UNCHANGED)->count());
    }
}
