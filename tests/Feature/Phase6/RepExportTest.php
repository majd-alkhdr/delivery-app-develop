<?php

namespace Tests\Feature\Phase6;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\FinanceExport;
use App\Models\FinanceExportRow;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\Finance\FinanceExportFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Testing\TestResponse;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 6-G: تصدير دفعات المندوبين المنفصل (ملف واحد، م-{n}، البيان، المراجعة، اللقطة).
 */
class RepExportTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity, RepFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReps();
    }

    private function rows(TestResponse $response): array
    {
        $response->assertOk();
        $book = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $rows = $book->getSheet(0)->toArray(null, false, false, false);
        array_shift($rows);

        return $rows;
    }

    private function fileName(TestResponse $response): string
    {
        preg_match("/filename\\*=UTF-8''([^;]+)/i", (string) $response->headers->get('content-disposition'), $m);

        return rawurldecode($m[1] ?? '');
    }

    public function test_two_reps_in_one_file_sorted_by_rep_then_customer_with_literal_statement(): void
    {
        $this->repPay($this->rep1, $this->south, 300)->assertCreated();
        $this->repPay($this->rep1, $this->north, 1000)->assertCreated();
        $this->repPay($this->rep1, $this->north, 200.5)->assertCreated();
        $this->repPay($this->rep2, $this->east, 100, ['currency' => 'USD', 'exchange_rate' => 139])->assertCreated();

        $this->repExport($this->accountant, [$this->rep1->id, $this->rep2->id])->assertRedirect(route('finance.rep-payments'))->assertSessionHasNoErrors();
        $export = FinanceExport::sole();
        $this->assertSame('rep', $export->kind);
        $this->assertSame('rep', $export->scope_key);
        $this->assertNull($export->distribution_sheet_id);
        $this->assertSame('م-1', $export->repOrder());
        $this->assertSame(4, Payment::where('status', 'exported')->where('finance_export_id', $export->id)->count());

        $response = $this->actingAs($this->accountant)->get(route('finance.exports.download', [$export, 'import']));
        $book = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $this->assertSame(1, $book->getSheetCount());
        $ws = $book->getSheet(0);
        $this->assertTrue($ws->getRightToLeft());
        $this->assertSame(FinanceExportFiles::IMPORT_HEADERS, array_slice($ws->toArray()[0], 0, 4));

        $rows = $this->rows($response);
        $rep1 = 'بيد السيد 655231-كرم الخالدي إيصال إيصال مالية كشف رقم م-1يومية ';
        $rep2 = 'بيد السيد 700700-سامر إيصال إيصال مالية كشف رقم م-1يومية ';
        // سامر قبل كرم أبجدياً؛ وداخل كرم: الجنوب ثم الشمال
        $this->assertSame([
            ['صيدلية الشرق', $rep2, 13900.0, '700700-سامر'],
            ['صيدلية الجنوب', $rep1, 300.0, '655231-كرم الخالدي'],
            ['صيدلية الشمال', $rep1, 1200.5, '655231-كرم الخالدي'],
        ], array_map(fn ($r) => [$r[0], $r[1], (float) $r[2], $r[3]], $rows));
        foreach ([2, 3, 4] as $r) {
            $this->assertSame(DataType::TYPE_NUMERIC, $ws->getCell("C{$r}")->getDataType());
            $this->assertSame('0.00', $ws->getStyle("C{$r}")->getNumberFormat()->getFormatCode());
        }
        $this->assertEqualsWithDelta(13900 + 300 + 1200.5, array_sum(array_map(fn ($r) => (float) $r[2], $rows)), 0.001);

        // المقبوضات: صف لكل دفعة بالترتيب نفسه، والدولار
        $receipts = $this->rows($this->actingAs($this->accountant)->get(route('finance.exports.download', [$export, 'receipts'])));
        $this->assertCount(4, $receipts);
        $this->assertSame('صيدلية الشرق', $receipts[0][0]);
        $usd = $this->rows($this->actingAs($this->accountant)->get(route('finance.exports.download', [$export, 'usd'])));
        $this->assertCount(1, $usd);
        $this->assertSame([100.0, 139.0, 13900.0], [(float) $usd[0][2], (float) $usd[0][3], (float) $usd[0][4]]);

        $this->assertSame('مندوبون - م-1 - ملف الاستيراد.xlsx', $this->fileName($response));
        $this->assertStringContainsString('filename=reps_1_import.xlsx', $response->headers->get('content-disposition'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'finance.rep_exported', 'subject_id' => $export->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'finance.rep_export_downloaded', 'subject_id' => $export->id]);
    }

    public function test_single_rep_export_and_file_name_with_rep_name(): void
    {
        $this->repPay($this->rep1, $this->north, 100)->assertCreated();
        $this->repPay($this->rep2, $this->east, 50)->assertCreated();

        $this->repExport($this->admin, [$this->rep2->id])->assertSessionHasNoErrors();
        $export = FinanceExport::sole();
        $this->assertSame(1, $export->payments_count);
        $this->assertSame('pending', Payment::where('entered_by', $this->rep1->id)->value('status'));
        $this->assertSame('سامر - م-1 - كشف المقبوضات.xlsx', $this->fileName($this->actingAs($this->admin)->get(route('finance.exports.download', [$export, 'receipts']))));
        $this->actingAs($this->admin)->get(route('finance.exports.download', [$export, 'usd']))->assertNotFound();
    }

    public function test_needs_review_excluded_by_default_and_included_with_reason(): void
    {
        $ok = $this->repPay($this->rep1, $this->north, 100)->json('payment.id');
        $flagged = $this->repPay($this->rep1, $this->south, 200)->json('payment.id');
        $this->actingAs($this->accountant)->post(route('finance.rep-payments.review', $flagged), ['review_status' => 'needs_review', 'review_note' => 'الصورة غير واضحة'])->assertSessionHasNoErrors();

        $this->repExport($this->accountant, [$this->rep1->id])->assertSessionHasNoErrors();
        $this->assertSame('exported', Payment::find($ok)->status);
        $this->assertSame('pending', Payment::find($flagged)->status);

        // الشمول بسبب قصير مرفوض، وبسبب صحيح يُسجَّل
        $this->repExport($this->accountant, [$this->rep1->id], [$flagged => 'x'])->assertSessionHasErrors('include');
        $this->repExport($this->accountant, [$this->rep1->id], [$flagged => 'تأكدت هاتفياً من الصيدلية'])->assertSessionHasNoErrors();
        $second = FinanceExport::where('sequence', 2)->sole();
        $this->assertSame('م-2', $second->repOrder());
        $this->assertSame('exported', Payment::find($flagged)->status);
        $this->assertSame(['تأكدت هاتفياً من الصيدلية'], array_values($second->meta['overrides']));
        $this->assertDatabaseHas('activity_logs', ['action' => 'finance.rep_export_override', 'subject_id' => $flagged]);

        // لا تكرار صفوف: الثانية فيها الدفعة الجديدة وحدها
        $this->assertSame(1, FinanceExportRow::where('finance_export_id', $second->id)->where('row_type', 'allocation')->count());
    }

    public function test_double_submit_does_not_duplicate_sequence_or_rows_and_nothing_left_is_rejected(): void
    {
        $this->repPay($this->rep1, $this->north, 100)->assertCreated();
        $signature = $this->repSignature([$this->rep1->id]);
        $this->repExport($this->accountant, [$this->rep1->id], [], $signature)->assertSessionHasNoErrors();
        $this->repExport($this->accountant, [$this->rep1->id], [], $signature)->assertSessionHasErrors('export');

        $this->assertSame(1, FinanceExport::where('kind', 'rep')->count());
        $this->actingAs($this->accountant)->get(route('finance.rep-payments', ['reps' => [$this->rep1->id]]))->assertSee('لا دفعات جديدة');
    }

    public function test_changed_data_after_preview_and_usd_without_rate_block_export(): void
    {
        $this->repPay($this->rep1, $this->north, 100)->assertCreated();
        $signature = $this->repSignature([$this->rep1->id]);
        $this->repPay($this->rep1, $this->north, 50)->assertCreated();
        $this->repExport($this->accountant, [$this->rep1->id], [], $signature)->assertSessionHasErrors('export');

        // دولار قديم بلا سعر يمنع حتى يُضبط سعره
        $legacy = $this->repPay($this->rep1, $this->north, 10, ['currency' => 'USD', 'legacy_no_rate' => 1])->assertCreated()->json('payment.id');
        $this->repExport($this->accountant, [$this->rep1->id])->assertSessionHasErrors('export');
        $this->actingAs($this->accountant)->post(route('finance.payments.rate', $legacy), ['exchange_rate' => 139])->assertSessionHasNoErrors();
        $this->assertSame('1390.00', Payment::find($legacy)->amount_syp);
        $this->assertDatabaseHas('activity_logs', ['action' => 'finance.rate_set', 'subject_id' => $legacy]);
        $this->repExport($this->accountant, [$this->rep1->id])->assertSessionHasNoErrors();
    }

    public function test_snapshot_is_stable_and_void_after_export_is_forbidden(): void
    {
        $id = $this->repPay($this->rep1, $this->north, 1200)->json('payment.id');
        $this->repExport($this->accountant, [$this->rep1->id])->assertSessionHasNoErrors();
        $export = FinanceExport::sole();

        Customer::whereKey($this->north->id)->update(['name' => 'اسم جديد']);
        $this->rep1->update(['cost_center_label' => '655231-اسم معدّل']);
        Invoice::where('invoice_number', 'ع G: 11')->update(['invoice_amount' => 9999]);

        $rows = $this->rows($this->actingAs($this->accountant)->get(route('finance.exports.download', [$export, 'import'])));
        $this->assertSame(['صيدلية الشمال', '655231-كرم الخالدي', 1200.0], [$rows[0][0], $rows[0][3], (float) $rows[0][2]]);
        $this->assertSame(['655231-كرم الخالدي'], array_values($export->meta['rep_labels']));

        $this->actingAs($this->accountant)->postJson(route('payments.void', $id), ['reason' => 'خطأ إدخال'])->assertForbidden();
        $this->actingAs($this->rep1)->postJson(route('payments.void', $id), ['reason' => 'خطأ إدخال'])->assertForbidden();
    }

    public function test_rep_statement_setting_is_literal_without_trim(): void
    {
        $this->assertSame('بيد السيد {user} إيصال إيصال مالية كشف رقم {order}يومية ', Setting::get('finance_rep_statement_template'));

        $template = ' تحصيل {user} رقم {order}يومية ';
        $this->actingAs($this->admin)->put(route('settings.finance-rep'), ['finance_rep_statement_template' => $template])->assertSessionHasNoErrors();
        $this->assertSame($template, Setting::get('finance_rep_statement_template'));
        $this->actingAs($this->supervisor)->put(route('settings.finance-rep'), ['finance_rep_statement_template' => 'x'])->assertForbidden();
        $this->actingAs($this->admin)->get(route('settings.index'))->assertSee('finance_rep_statement_template');

        $this->repPay($this->rep1, $this->north, 100)->assertCreated();
        $this->repExport($this->accountant, [$this->rep1->id])->assertSessionHasNoErrors();
        $this->assertSame(' تحصيل 655231-كرم الخالدي رقم م-1يومية ', FinanceExportRow::where('row_type', 'allocation')->value('statement'));
    }

    public function test_formula_injection_in_rep_files(): void
    {
        $this->north->update(['name' => '=HYPERLINK("x")']);
        $this->repPay($this->rep1, $this->north, 100)->assertCreated();
        $this->repExport($this->accountant, [$this->rep1->id])->assertSessionHasNoErrors();

        $response = $this->actingAs($this->accountant)->get(route('finance.exports.download', [FinanceExport::sole(), 'import']));
        $ws = IOFactory::load($response->baseResponse->getFile()->getPathname())->getSheet(0);
        $this->assertSame('=HYPERLINK("x")', $ws->getCell('A2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $ws->getCell('A2')->getDataType());
        $this->assertTrue($ws->getStyle('A2')->getQuotePrefix());
    }

    public function test_sheet_exports_keep_their_own_sequence(): void
    {
        $this->repPay($this->rep1, $this->north, 100)->assertCreated();
        $this->repExport($this->accountant, [$this->rep1->id])->assertSessionHasNoErrors();
        $this->assertSame(1, ActivityLog::where('action', 'finance.rep_exported')->count());
        // صفحة الكشف لا ترى دفعة المندوبين ضمن دفعاتها التصديرية
        $this->sheetA->update(['status' => 'in_progress']);
        $this->actingAs($this->accountant)->get(route('finance.sheets.show', $this->sheetA))->assertOk()->assertSee('لم يُصدَّر هذا الكشف بعد');
    }
}
