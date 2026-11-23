<?php

namespace Tests\Feature\Phase7A;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\ImportBatchItem;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 7A (F، H11، H12): كل ما لم يُقرأ أو عُدِّل بقاعدة تلقائية يظهر ملاحظةً بنصها في صفحة الدفعة.
 */
class FileNotesTest extends TestCase
{
    use RefreshDatabase, Workbooks, NoSilence;

    private User $manager;

    private const HEADER = ['الفاتورة', 'التاريخ', 'اسم الزبون', 'اسم المادة', 'المجموعة', 'كمية', 'الهدايا', 'الإفرادي', 'السعر الإجمالي', 'مركز الكلفة', 'بيان الفاتورة', 'تاريخ التسليم', 'المدخل', 'رقم امر التسليم'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->manager = User::factory()->role(Role::SheetManager)->create();
    }

    /** سطر بالترويسة القياسية: [فاتورة، تاريخ، زبون، مادة، كمية، إفرادي، إجمالي، مركز، بيان، تسليم، أمر] */
    private function row(mixed $inv, mixed $date, mixed $customer, string $item, mixed $qty, mixed $unit, float $total, string $cc = '201201-كول سنتر', mixed $note = 0, string $delivery = '13-10-2026', mixed $order = 1580): array
    {
        return [$inv, $date, $customer, $item, 'مجموعة', $qty, 0, $unit, $total, $cc, $note, $delivery, 'مدخل', $order];
    }

    /**
     * @param  array<int, array>  $lines  أسطر بعد الترويسة
     * @param  array<int, array>|null  $balances  صفوف الأرصدة (بلا الترويسة)
     */
    private function workbook(array $lines, ?array $balances = null, array $options = []): string
    {
        $spreadsheet = new Spreadsheet;
        $daily = $spreadsheet->getActiveSheet()->setTitle('الحركة اليومية');
        $header = array_merge(self::HEADER, $options['extra_columns'] ?? []);
        $total = array_sum(array_map(fn ($l) => $l[3] !== '' ? (float) $l[8] : 0, $lines));
        $withDiscount = $options['summary_discount_column'] ?? false;
        $rows = [
            ['الحركة اليومية'], [' - تفصيلي'],
            [($options['no_range'] ?? false) ? 'تقرير بلا مدى' : 'اعتباراً من  1-1-2026 ولغاية  13-10-2026'],
            $header,
            ...$lines,
            $withDiscount ? ['الفاتورة', 'المجموع', 'مجموع النقدي', 'مجموع الآجل', 'الحسم'] : ['الفاتورة', 'المجموع', 'مجموع النقدي', 'مجموع الآجل'],
            $withDiscount ? ['الإجمالي', '-'.number_format($total, 2), 0, 0, null] : ['الإجمالي', '-'.number_format($total, 2), 0, 0],
        ];
        if ($options['two_totals'] ?? false) {
            $rows[] = ['الإجمالي', '-'.number_format($total, 2), 0, 0];
        }
        $daily->fromArray($rows, null, 'A1', true);

        if ($balances !== null) {
            $sheet = $spreadsheet->createSheet()->setTitle('الأرصدة');
            $sheet->fromArray(array_merge([
                ['أرصدة الزبائن'], ['x'], ['اعتباراً من  1-1-2023 ولغاية  13-10-2026'],
                ['اسم العميل', 'الكتلة', 'المنطقة', 'مدين', 'دائن'],
            ], $balances), null, 'A1', true);
        }
        foreach ($options['extra_sheets'] ?? [] as $title => $content) {
            $spreadsheet->createSheet()->setTitle($title)->fromArray($content, null, 'A1', true);
        }

        return $this->save($spreadsheet);
    }

    private function importAndPage(string $path): array
    {
        $result = $this->importFiles($path, '2026-10-13', $this->manager);
        // المرحلة 7E: الملاحظات في تبويب «ملاحظات وتحذيرات»
        $page = $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $result['batch_id'], 'tab' => 'notes']))->assertOk();
        $page->assertSee('ما لم يُقرأ أو عُدِّل بقاعدة تلقائية');

        return [$result, $page];
    }

    private function note(array $result, string $code): ImportBatchItem
    {
        return ImportBatchItem::where('batch_id', $result['batch_id'])->where('kind', ImportBatchItem::NOTE)
            ->get()->first(fn ($n) => ($n->new['code'] ?? null) === $code) ?? $this->fail("لا ملاحظة بالرمز {$code}");
    }

    public function test_unread_sheets_and_unknown_and_ignored_columns(): void
    {
        $path = $this->workbook([$this->row('ع G: 1', '12-10-2026', 'صيدلية ألف', 'صنف', 1, 100, 100)], null, [
            'extra_columns' => ['عمود غريب'],
            'extra_sheets' => ['ملاحظات المحاسب' => [['نص حر'], ['شيء آخر']]],
        ]);
        [$result, $page] = $this->importAndPage($path);

        $page->assertSee('صفحات في الملف لم تُقرأ')->assertSee('«ملاحظات المحاسب»');
        $page->assertSee('أعمدة غير معروفة لم تُقرأ')->assertSee('«عمود غريب»');
        $page->assertSee('أعمدة معروفة لا يدخلها النظام في الحساب')->assertSee('«المدخل»');
        $this->assertNoSilence($result, 1);
    }

    public function test_duplicate_header_mixed_dates_and_notes_and_header_without_items(): void
    {
        $path = $this->workbook([
            $this->row('ع G: 1', '12-10-2026', 'صيدلية ألف', 'صنف 1', 1, 100, 100, note: 'بيان أول'),
            $this->row(0, 0, 0, 'صنف 2', 1, 50, 50, note: 'بيان ثانٍ', delivery: '14-10-2026'),
            $this->row('ع G: 2', '12-10-2026', 'صيدلية باء', 'صنف 3', 1, 10, 10),
            $this->row('ع G: 1', '11-10-2026', 'صيدلية ألف', 'صنف 4', 1, 20, 20),   // ترويسة مكررة بتاريخ مختلف
            $this->row('ع G: 3', '12-10-2026', 'صيدلية جيم', '', '', '', 0),         // ترويسة بلا أصناف
        ]);
        [$result, $page] = $this->importAndPage($path);

        $page->assertSee('ترويسة فاتورة مكررة لنفس الزبون ببيانات مختلفة')->assertSee('التاريخ 2026-10-11 بدل 2026-10-12');
        $page->assertSee('أسطر فاتورة بتواريخ تسليم مختلفة (اعتُمد الأحدث)')->assertSee('2026-10-13، 2026-10-14');
        $page->assertSee('أسطر فاتورة ببيانات (بيان الفاتورة) مختلفة')->assertSee('«بيان أول» و«بيان ثانٍ»');
        $page->assertSee('ترويسة فاتورة بلا أي صنف');
        $this->assertNoSilence($result, 3);
    }

    public function test_non_numeric_values_cost_center_without_code_and_two_totals(): void
    {
        $path = $this->workbook([
            $this->row('ع G: 1', '12-10-2026', 'صيدلية ألف', 'صنف', 'abc', 100, 100, cc: 'كول سنتر بلا كود'),
            $this->row('ع G: 2', '12-10-2026', 'صيدلية باء', 'صنف', '1,020.50', 1, 1020.5),   // نص رقمي: بلا تحذير
        ], null, ['two_totals' => true]);
        [$result, $page] = $this->importAndPage($path);

        $page->assertSee('قيم غير رقمية في الكمية أو الإفرادي أو الهدايا عوملت صفراً')->assertSee('الكمية «abc»');
        $this->assertSame(1, $this->note($result, 'non_numeric')->new['count']);
        $page->assertSee('مركز كلفتها بلا كود')->assertSee('«كول سنتر بلا كود»');
        $page->assertSee('أكثر من صف «الإجمالي» في الجدول الملخص');
    }

    public function test_missing_summary_discount_cell_note(): void
    {
        [$result, $page] = $this->importAndPage($this->workbook([$this->row('ع G: 1', '12-10-2026', 'صيدلية ألف', 'صنف', 1, 100, 100)], null, ['summary_discount_column' => true]));

        $page->assertSee('خلية «الحسم» في صف «الإجمالي» فارغة أو غير رقمية (اعتُبرت صفراً)');
        $this->assertSame('warning', $this->note($result, 'summary_discount_missing')->level);
    }

    public function test_invoice_in_two_files_of_the_same_batch_appears_once_in_unchanged_list(): void
    {
        $line = [$this->row('ع G: 1', '12-10-2026', 'صيدلية ألف', 'صنف', 1, 100, 100)];
        $paths = [$this->workbook($line), $this->workbook($line)];
        $result = $this->importFiles($paths, '2026-10-13', $this->manager);

        $items = ImportBatchItem::where('batch_id', $result['batch_id'])->invoices()->get();
        $this->assertCount(2, $items);   // عنصر لكل ملف (ثابت «لا صامت» لكل ملف)
        $this->assertSame(ImportBatchItem::CREATED, $items[0]->kind);
        $this->assertStringContainsString('ظهرت أيضاً في ملف آخر من الدفعة نفسها', $items[1]->reason);
        $page = $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $result['batch_id'], 'tab' => 'invoices']))->assertOk();
        $page->assertSee('بلا تغيير (0)')->assertDontSee('ظهرت أيضاً في ملف آخر');
    }

    public function test_missing_report_range_note(): void
    {
        [$result, $page] = $this->importAndPage($this->workbook([$this->row('ع G: 1', '12-10-2026', 'صيدلية ألف', 'صنف', 1, 100, 100)], null, ['no_range' => true]));

        $page->assertSee('مدى الملف مجهول، فلا يعمل فحص الفواتير الغائبة');
        $this->assertSame('warning', $this->note($result, 'no_report_range')->level);
    }

    public function test_balance_notes_skipped_zero_zeroed_stats_and_not_compared(): void
    {
        Customer::create(['name' => 'صيدلية قديمة', 'balance' => 5000, 'needs_review' => false]);
        $path = $this->workbook([$this->row('ع G: 1', '12-10-2026', 'صيدلية ألف', 'صنف', 1, 100, 100)], [
            ['صيدلية ألف', 'كتلة', 'منطقة أ', 1000, 0],
            ['صيدلية صفرية', 'كتلة', 'منطقة أ', 0, 0],
            ['صيدلية نصية', 'كتلة', 'منطقة أ', 'لا يوجد', 'لا يوجد'],
            [null, null, null, 300, 0],
        ]);
        [$result, $page] = $this->importAndPage($path);

        $page->assertSee('صفوف في صفحة الأرصدة تُخطّيت')->assertSee('«صيدلية نصية»: المدين والدائن غير رقميين')->assertSee('بلا اسم عميل');
        $page->assertSee('صفوف أرصدة بمدين ودائن صفريين')->assertSee('«صيدلية صفرية»');

        // المرحلة 7B (C): تصفير 100% من ذوي الرصيد ⇒ إيقاف وتأكيد «هل الملف كامل؟»، ثم قائمة المصفَّرين ونتيجة التطبيق
        // المرحلة 7E: قرار الملف المحجوز في تبويب «الأرصدة»
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $result['batch_id'], 'tab' => 'balances']))
            ->assertSee('سيُصفَّر رصيد 1 صيدلية')->assertSee('نعم، الملف كامل');
        $this->assertEquals(5000, (float) Customer::where('name', 'صيدلية قديمة')->value('balance'));
        $import = \App\Models\BalanceImport::sole();
        $this->actingAs($this->manager)->post(route('sheet-manager.balances.apply', $import), ['confirm_zeroing' => 1])->assertSessionHasNoErrors();

        $page = $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $result['batch_id'], 'tab' => 'balances']))->assertOk();
        $page->assertSee('الأرصدة المطبَّقة')->assertSee('صُفّر رصيد');
        $page->assertSee('زبائن غابوا عن ملف الأرصدة فصُفِّر رصيدهم (1)')->assertSee('صيدلية قديمة: من');
        $this->assertEquals(0, (float) Customer::where('name', 'صيدلية قديمة')->value('balance'));
    }

    public function test_notes_show_ten_examples_with_show_all(): void
    {
        $lines = [];
        for ($i = 1; $i <= 12; $i++) {
            $lines[] = $this->row("ع G: {$i}", '12-10-2026', "صيدلية {$i}", 'صنف', 1, 10, 10, cc: "مركز {$i}");
        }
        [$result, $page] = $this->importAndPage($this->workbook($lines));

        $this->assertSame(12, $this->note($result, 'cost_center_no_code')->new['count']);
        // المرحلة 7E: 5 أمثلة في الصفحة، والقائمة كاملة تُحمَّل عند «عرض الكل»
        $page->assertSee('عرض الكل (12)')->assertSee('«مركز 5»')->assertDontSee('«مركز 12»');
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $result['batch_id'], 'partial' => 'note', 'code' => 'cost_center_no_code']))
            ->assertOk()->assertSee('«مركز 12»')->assertSee('العدد: 12');
    }

    public function test_real_file_reupload_is_all_unchanged_with_locations_and_ignored_columns(): void
    {
        $path = base_path('tests/Support/Workbooks/daily_movement_discount.xlsx');
        if (! is_file($path)) {
            $this->markTestSkipped('الملف الحقيقي غير موجود.');
        }
        $this->importFiles($path, '2026-10-17', $this->manager);
        $result = $this->importFiles($path, '2026-10-17', $this->manager);

        $this->assertNoSilence($result, 211);
        $items = ImportBatchItem::where('batch_id', $result['batch_id'])->invoices()->get();
        $this->assertSame(211, $items->where('kind', ImportBatchItem::UNCHANGED)->count());
        $this->assertSame(73, $items->where('sheet_label', 'مؤجلة')->count());
        $this->assertSame(73, Invoice::where('placement', Invoice::DEFERRED)->count());
        $this->assertTrue($items->where('sheet_label', '!=', 'مؤجلة')->every(fn ($i) => str_starts_with($i->sheet_label, 'أمر ') || $i->sheet_label === 'بلا كشف'));

        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $result['batch_id'], 'tab' => 'invoices']))->assertOk()->assertSee('بلا تغيير (211)');
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $result['batch_id'], 'tab' => 'notes']))->assertOk()
            ->assertSee('«حسم هدايا»')->assertSee('«الإضافات»')->assertSee('«المدخل»');
    }
}
