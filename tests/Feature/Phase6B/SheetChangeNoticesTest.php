<?php

namespace Tests\Feature\Phase6B;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\InvoiceChangeProposal;
use App\Models\Payment;
use App\Models\PrintEvent;
use App\Models\SheetChangeNotice;
use App\Models\SheetVisit;
use App\Models\User;
use App\Services\SheetInvoiceEditor;
use App\Support\ArabicNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 6B-D: تنبيهات تعديل الكشف بعد نشره، وحماية الطابور دون اتصال، وتنبيه الطباعة.
 */
class SheetChangeNoticesTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity;

    private User $manager;
    private User $admin;
    private User $supervisor;
    private User $d1;
    private User $d2;
    private DistributionSheet $sheet;
    private DistributionSheet $other;

    private array $rows = [
        ['number' => 'ع G: 1', 'customer' => 'صيدلية الشمال', 'order' => 3918, 'lines' => [['صنف', 1500]]],
        ['number' => 'ع G: 2', 'customer' => 'صيدلية الجنوب', 'order' => 3918, 'lines' => [['صنف', 2222]]],
        ['number' => 'ع G: 3', 'customer' => 'صيدلية الشمال الثانية', 'order' => 3918, 'lines' => [['صنف', 300]]],
        ['number' => 'ع G: 4', 'customer' => 'صيدلية الشرق', 'order' => 3919, 'lines' => [['صنف', 444]]],
        ['number' => 'ع G: 6', 'customer' => 'صيدلية الشمال', 'order' => 3918, 'delivery' => '20-10-2026', 'lines' => [['مؤجل', 9999]]],
    ];

    private array $balances = [
        ['صيدلية الشمال', 'كتلة', 'منطقة الشمال', 1000, 0],
        ['صيدلية الجنوب', 'كتلة', 'منطقة الجنوب', 1000, 0],
        ['صيدلية الشمال الثانية', 'كتلة', 'منطقة الشمال', 1000, 0],
        ['صيدلية الشرق', 'كتلة', 'منطقة الشرق', 1000, 0],
        ['صيدلية بلا أمر', 'كتلة', 'منطقة الشمال', 1000, 0],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->manager = User::factory()->role(Role::SheetManager)->create();
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->supervisor = User::factory()->role(Role::Supervisor)->create();
        $this->d1 = User::factory()->role(Role::Distributor)->create();
        $this->d2 = User::factory()->role(Role::Distributor)->create();

        $this->sheet = $this->publishWorkbook($this->buildWorkbook($this->rows, $this->balances));
        $this->other = DistributionSheet::where('delivery_order_no', '3919')->sole();
        // فاتورة «بلا كشف» من ملف لاحق (إرسال الدفعة يمنع وجود «بلا كشف» فيها)
        $this->importFiles($this->buildWorkbook([['number' => 'ع G: 5', 'customer' => 'صيدلية بلا أمر', 'order' => 0, 'lines' => [['صنف', 555]]]], $this->balances));
        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $this->sheet), ['rows' => [
            ['area_key' => ArabicNormalizer::normalize('منطقة الشمال'), 'distributor_id' => $this->d1->id],
            ['area_key' => ArabicNormalizer::normalize('منطقة الجنوب'), 'distributor_id' => $this->d2->id],
        ]])->assertSessionHasNoErrors();
        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $this->other), ['all_distributor_id' => $this->d2->id])->assertSessionHasNoErrors();
    }

    private function editor(): SheetInvoiceEditor
    {
        return app(SheetInvoiceEditor::class);
    }

    private function inv(string $number): Invoice
    {
        return Invoice::where('invoice_number', $number)->sole();
    }

    public function test_place_move_remove_create_notices_and_bump_revision_only_when_published(): void
    {
        $this->assertSame('pending', $this->sheet->status);
        $this->editor()->place($this->inv('ع G: 5'), $this->sheet, $this->manager);
        $this->assertSame(1, $this->sheet->fresh()->revision);
        $notice = SheetChangeNotice::sole();
        $this->assertSame('added', $notice->type);
        $this->assertStringContainsString('G: 5', $notice->summary);
        $this->assertStringContainsString('صيدلية بلا أمر', $notice->summary);
        $this->assertStringNotContainsString('555', $notice->summary);   // بلا مبالغ

        // نقل بين كشفين: زوج إشعارات
        $this->editor()->move($this->inv('ع G: 3'), $this->other, $this->manager);
        $this->assertSame(['moved_out'], SheetChangeNotice::where('distribution_sheet_id', $this->sheet->id)->where('type', '!=', 'added')->pluck('type')->all());
        $this->assertSame(['moved_in'], SheetChangeNotice::where('distribution_sheet_id', $this->other->id)->pluck('type')->all());
        $this->assertSame(1, $this->other->fresh()->revision);

        $this->editor()->remove($this->inv('ع G: 2'), 'ليست لهذا اليوم', $this->manager);
        $this->assertSame('removed', SheetChangeNotice::latest('id')->first()->type);
        $this->assertSame(3, $this->sheet->fresh()->revision);

        // لا إشعار أثناء التجهيز
        $this->sheet->update(['status' => 'preparing']);
        $before = SheetChangeNotice::count();
        $this->editor()->remove($this->inv('ع G: 1'), 'تجربة', $this->manager);
        $this->assertSame($before, SheetChangeNotice::count());
        $this->assertSame(3, $this->sheet->fresh()->revision);

        // لا إشعار يكشف مؤجلة ولا مبالغ
        foreach (SheetChangeNotice::pluck('summary') as $summary) {
            $this->assertStringNotContainsString('G: 6', $summary);
            $this->assertDoesNotMatchRegularExpression('/\d{3,},?\d*\.\d{2}/', $summary);
        }
    }

    public function test_distributor_sees_only_his_area_notices_and_seen_hides_them(): void
    {
        $this->editor()->place($this->inv('ع G: 5'), $this->sheet, $this->manager);          // منطقة الشمال (d1)
        $this->editor()->remove($this->inv('ع G: 2'), 'ليست لهذا اليوم', $this->manager);    // منطقة الجنوب (d2)

        $html = $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->assertOk()->getContent();
        $this->assertStringContainsString('تعديلات جديدة على هذا الكشف (1)', $html);
        $this->assertStringContainsString('صيدلية بلا أمر', $html);
        $this->assertStringNotContainsString('أُخرجت فاتورة', $html);
        $this->assertStringContainsString('تعديل جديد (1)', $this->actingAs($this->d1)->get(route('distributor.index'))->getContent());

        $this->actingAs($this->d1)->post(route('sheets.notices.seen', $this->sheet))->assertRedirect();
        $html = $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->getContent();
        $this->assertStringNotContainsString('تعديلات جديدة على هذا الكشف', $html);
        $this->assertStringNotContainsString('تعديل جديد (', $this->actingAs($this->d1)->get(route('distributor.index'))->getContent());

        // d2 يرى إشعار منطقته فقط
        $html = $this->actingAs($this->d2)->get(route('distributor.sheet', $this->sheet->id))->getContent();
        $this->assertStringContainsString('أُخرجت فاتورة', $html);
        $this->assertStringNotContainsString('صيدلية بلا أمر', $html);

        // المشرف ومسؤول الكشوف: «آخر التعديلات» والشارة
        $this->actingAs($this->supervisor)->get(route('supervisor.sheet.show', $this->sheet->id))->assertSee('آخر التعديلات بعد النشر (2)');
        $this->actingAs($this->supervisor)->get(route('supervisor.index'))->assertSee('تعديل جديد (2)');
        $this->actingAs($this->manager)->get(route('sheet-manager.sheets.show', $this->sheet))->assertSee('آخر التعديلات بعد النشر (2)');
    }

    public function test_applying_change_proposal_creates_updated_notice_and_revision_reaches_json(): void
    {
        $etag = $this->actingAs($this->d1)->getJson(route('distributor.invoice-lines', $this->sheet))->assertJsonPath('revision', 0)->headers->get('ETag');

        $rows = $this->rows;
        $rows[0]['lines'] = [['صنف', 1600]];
        $this->importFiles($this->buildWorkbook($rows, $this->balances));
        $proposal = InvoiceChangeProposal::where('invoice_id', $this->inv('ع G: 1')->id)->sole();
        $this->actingAs($this->manager)->post(route('sheet-manager.proposals.apply', $proposal))->assertSessionHasNoErrors();

        $this->assertSame('updated', SheetChangeNotice::sole()->type);
        $response = $this->actingAs($this->d1)->getJson(route('distributor.invoice-lines', $this->sheet))->assertJsonPath('revision', 1);
        $this->assertNotSame($etag, $response->headers->get('ETag'));
    }

    public function test_offline_payment_and_visit_for_pharmacy_removed_later_are_saved_with_flag(): void
    {
        $south = Customer::where('name', 'صيدلية الجنوب')->sole();
        // قبل الإخراج: صيدلية خارج مناطق d1 مرفوضة كما كانت
        $this->payApi($this->d1, $this->sheet, $south->id, 10)->assertForbidden();

        $this->editor()->remove($this->inv('ع G: 2'), 'ليست لهذا اليوم', $this->manager);

        // طابور d2 دون اتصال: دفعة وزيارة سُجّلتا قبل الإخراج وتُزامنان بعده
        // المرحلة 7H: العنصر من الطابور يحمل offline=1 (الطلب الحي على صيدلية لها إشعار «removed» غير مُطَّلَع عليه ⇒ 409)
        $this->payApi($this->d2, $this->sheet, $south->id, 700, ['offline' => 1])->assertCreated();
        $payment = Payment::sole();
        $this->assertTrue($payment->outside_sheet);
        $this->assertTrue($payment->recorded_before_ack);
        $this->assertSame($south->id, $payment->customer_id);
        $this->assertSame($this->d2->id, $payment->entered_by);

        $north2 = Customer::where('name', 'صيدلية الشمال الثانية')->sole();
        $this->editor()->move($this->inv('ع G: 3'), $this->other, $this->manager);
        $this->visitApi($this->d1, $this->sheet, $north2->id, ['status' => 'not_visited', 'reason' => 'الصيدلية مغلقة', 'offline' => 1])->assertCreated();
        $this->assertTrue(SheetVisit::where('customer_id', $north2->id)->sole()->outside_sheet);

        // تظهر العلامة للمشرف
        $this->actingAs($this->supervisor)->get(route('supervisor.sheet.show', $this->sheet->id))->assertSee('صيدلية خارج الكشف');
    }

    public function test_applying_change_on_printed_invoice_needs_extra_confirmation(): void
    {
        $invoice = $this->inv('ع G: 1');
        PrintEvent::create([
            'client_uuid' => (string) Str::uuid(), 'user_id' => $this->d1->id, 'kind' => 'invoice', 'invoice_id' => $invoice->id,
            'distribution_sheet_id' => $this->sheet->id, 'customer_id' => $invoice->customer_id, 'mode' => 'ble', 'status' => 'success', 'printed_at' => now(),
        ]);
        $rows = $this->rows;
        $rows[0]['lines'] = [['صنف', 1700]];
        $result = $this->importFiles($this->buildWorkbook($rows, $this->balances));
        $proposal = InvoiceChangeProposal::where('invoice_id', $invoice->id)->sole();

        $this->actingAs($this->admin)->get(route('sheet-manager.batches.show', ['batch' => $result['batch_id'], 'tab' => 'invoices']))->assertSee('طُبعت سابقاً للصيدلية');

        $this->actingAs($this->admin)->post(route('sheet-manager.proposals.apply', $proposal))->assertSessionHas('warning');
        $this->assertSame(InvoiceChangeProposal::PENDING, $proposal->fresh()->status);

        $this->actingAs($this->admin)->post(route('sheet-manager.proposals.apply', $proposal), ['confirm_printed' => 1])->assertSessionHasNoErrors();
        $this->assertSame(InvoiceChangeProposal::APPLIED, $proposal->fresh()->status);
        $this->assertDatabaseHas('activity_logs', ['action' => 'invoice.change_applied_after_print', 'subject_id' => $invoice->id]);
    }
}
