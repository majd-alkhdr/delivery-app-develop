<?php

namespace Tests\Feature\Phase8A;

use App\Enums\Role;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Finance\RepReceivableService;
use App\Services\Reps\RepScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 8A-3/5.3–5.6: الحصر الفعلي (مصفوفة مسار × دور)، أرقام الذمم بالجمع، الحصر المُطفأ، والتصدير.
 * المشرف 1 (أحمد): منطقة الشمال لكرم. المشرف 2 (باسل): منطقة الجنوب لكرم. سامر بلا مشرف.
 */
class RepScopingTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity, RepAssignmentFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssignments();
    }

    private function invoice(string $number): Invoice
    {
        return Invoice::where('invoice_number', 'like', '%'.$number)->firstOrFail();
    }

    public function test_disabled_scoping_keeps_previous_behaviour_and_shows_badge(): void
    {
        $this->assertFalse(RepScope::enabled());
        $html = $this->actingAs($this->repSupervisor)->get(route('supervisor.sheet.show', $this->sheetA->id))->assertOk()->getContent();
        $this->assertStringContainsString('صيدلية الجنوب', $html);
        $this->assertStringContainsString('صيدلية الشرق', $html);
        $this->assertStringContainsString('الحصر غير مفعّل: ترى كل المندوبين', $html);
        $this->actingAs($this->repSupervisor)->get(route('finance.rep-receivables'))->assertOk()->assertSee('صيدلية الشرق')->assertSee('الحصر غير مفعّل');
        $this->actingAs($this->repSupervisor)->get(route('rep-supervisor.reps.show', $this->rep2))->assertOk();
        // لا شارة لغير مشرف المندوبين
        $this->actingAs($this->accountant)->get(route('finance.rep-receivables'))->assertDontSee('الحصر غير مفعّل');
    }

    public function test_sheets_lines_and_finance_sheet_matrix(): void
    {
        $this->enableScoping();
        [$s1, $s2] = [$this->repSupervisor, $this->sup2];

        // قائمة الكشوف: B فيه الشمال فقط
        $this->actingAs($s1)->get(route('supervisor.index'))->assertOk()->assertSee('data-rep-scope-line', false);
        foreach ([[$s1, $this->sheetB, 200], [$s2, $this->sheetB, 403], [$s1, $this->sheetA, 200], [$s2, $this->sheetA, 200]] as [$user, $sheet, $status]) {
            $this->actingAs($user)->get(route('supervisor.sheet.show', $sheet->id))->assertStatus($status);
            $this->actingAs($user)->get(route('finance.sheets.show', $sheet))->assertStatus($status);
            $this->actingAs($user)->get(route('sheets.export', $sheet))->assertStatus($status);
        }
        $this->actingAs($s2)->get(route('supervisor.sheet.show', $this->sheetB->id))->assertSee('هذا الكشف خارج نطاقك', false);

        // داخل الكشف A: فواتير النطاق فقط
        $a1 = $this->actingAs($s1)->get(route('supervisor.sheet.show', $this->sheetA->id))->getContent();
        $this->assertStringContainsString('صيدلية الشمال', $a1);
        $this->assertStringNotContainsString('صيدلية الجنوب', $a1);
        $this->assertStringNotContainsString('صيدلية الشرق', $a1);
        $this->assertStringNotContainsString('A: 13', $a1);   // كول سنتر على صيدلية الشمال: ليس لكرم
        $this->assertStringContainsString('النطاق: منطقة الشمال (1 صيدلية)', $a1);
        $a2 = $this->actingAs($s2)->get(route('supervisor.sheet.show', $this->sheetA->id))->getContent();
        $this->assertStringContainsString('صيدلية الجنوب', $a2);
        $this->assertStringNotContainsString('صيدلية الشمال', $a2);
        $this->actingAs($s1)->get(route('finance.sheets.show', $this->sheetA))->assertSee('العرض المقيَّد بنطاقك');

        // أسطر الفاتورة
        foreach ([[$s1, 'G: 11', 200], [$s1, 'B: 21', 403], [$s2, 'B: 21', 200], [$s2, 'G: 11', 403], [$s1, 'G: 22', 403], [$s1, 'A: 13', 403]] as [$user, $number, $status]) {
            $this->actingAs($user)->getJson(route('invoices.lines', $this->invoice($number)))->assertStatus($status);
        }
        $this->actingAs($s1)->get(route('invoices.lines', $this->invoice('B: 21')))->assertSee('هذه الفاتورة خارج نطاقك');

        // الأدمن والمحاسب والمشرف التوزيعي كما كانوا
        foreach ([[$this->admin, 'supervisor.sheet.show'], [$this->accountant, 'accountant.sheet.show'], [$this->supervisor, 'supervisor.sheet.show']] as [$user, $route]) {
            $html = $this->actingAs($user)->get(route($route, $this->sheetA->id))->assertOk()->getContent();
            $this->assertStringContainsString('صيدلية الجنوب', $html);
            $this->assertStringContainsString('صيدلية الشرق', $html);
            $this->actingAs($user)->getJson(route('invoices.lines', $this->invoice('G: 22')))->assertOk();
        }
    }

    public function test_receivables_payments_and_reports_matrix(): void
    {
        $this->enableScoping();
        $southPayment = $this->repPay($this->rep1, $this->south, 300)->assertCreated()->json('payment.id');
        $northPayment = $this->repPay($this->rep1, $this->north, 200)->assertCreated()->json('payment.id');

        // ذمم المندوبين
        $r1 = $this->actingAs($this->repSupervisor)->get(route('finance.rep-receivables'))->assertOk()->getContent();
        $this->assertStringContainsString('صيدلية الشمال', $r1);
        $this->assertStringNotContainsString('صيدلية الجنوب', $r1);
        $this->assertStringNotContainsString('صيدلية الشرق', $r1);
        $this->assertStringNotContainsString(RepReceivableService::NO_REP, $r1);
        $this->actingAs($this->repSupervisor)->get(route('finance.rep-receivables', ['rep_id' => $this->rep2->id]))->assertForbidden();
        $this->actingAs($this->sup2)->get(route('finance.rep-receivables'))->assertSee('صيدلية الجنوب')->assertDontSee('صيدلية الشمال');

        // تسديدات المندوب: حصة صيدليات النطاق مع النص الصريح
        $p1 = $this->actingAs($this->repSupervisor)->get(route('finance.rep-payments', ['status' => 'pending']))->assertOk()->getContent();
        $this->assertStringContainsString('تُوزَّع تسديدات المندوب على فواتيره بالأقدم؛ تظهر هنا حصة فواتير مناطقك فقط.', $p1);
        $this->assertStringContainsString('صيدلية الشمال', $p1);
        $this->assertStringNotContainsString('صيدلية الجنوب', $p1);
        $this->actingAs($this->repSupervisor)->get(route('payments.image', $southPayment))->assertForbidden();
        $this->actingAs($this->sup2)->get(route('payments.image', $southPayment))->assertOk();
        $this->actingAs($this->sup2)->get(route('payments.image', $northPayment))->assertForbidden();
        $this->actingAs($this->admin)->get(route('payments.image', $southPayment))->assertOk();

        // غير المسدد
        $u1 = $this->actingAs($this->repSupervisor)->get(route('finance.unpaid', ['from' => '2026-10-10', 'to' => '2026-10-12']))->assertOk()->getContent();
        $this->assertStringContainsString('G: 11', $u1);
        $this->assertStringNotContainsString('B: 21', $u1);
        $this->assertStringNotContainsString('G: 22', $u1);
        $this->assertStringNotContainsString('A: 13', $u1);
        $acc = $this->actingAs($this->accountant)->get(route('finance.unpaid', ['from' => '2026-10-10', 'to' => '2026-10-12']))->getContent();
        $this->assertStringContainsString('B: 21', $acc);
        $this->assertStringContainsString('A: 13', $acc);

        // تغيّرات الأرصدة والتعديلات اللاحقة: تفتح، والمندوب خارج النطاق 403
        $this->actingAs($this->repSupervisor)->get(route('finance.balance-changes'))->assertOk();
        $this->actingAs($this->repSupervisor)->get(route('finance.balance-changes', ['rep_id' => $this->rep2->id]))->assertForbidden();
        $this->actingAs($this->repSupervisor)->get(route('finance.adjustments'))->assertOk();
        $this->actingAs($this->repSupervisor)->get(route('finance.adjustments', ['rep_id' => $this->rep2->id]))->assertForbidden();

        // ملف المندوب
        $this->actingAs($this->repSupervisor)->get(route('rep-supervisor.reps.show', $this->rep2))->assertForbidden()->assertSee('هذا المندوب خارج نطاقك');
        $profile = $this->actingAs($this->repSupervisor)->get(route('rep-supervisor.reps.show', [$this->rep1, 'tab' => 'payments']))->assertOk()->getContent();
        $this->assertStringContainsString('data-payment="'.$northPayment.'"', $profile);
        $this->assertStringNotContainsString('data-payment="'.$southPayment.'"', $profile);
        $this->assertStringContainsString('تُوزَّع تسديدات المندوب على فواتيره بالأقدم', $profile);
        $this->actingAs($this->repSupervisor)->get(route('rep-supervisor.reps.show', [$this->rep1, 'tab' => 'sheets']))->assertOk()
            ->assertSee('#3910')->assertSee('#3912');
        $this->actingAs($this->sup2)->get(route('rep-supervisor.reps.show', [$this->rep1, 'tab' => 'sheets']))->assertOk()->assertDontSee('#3912');
        $admin = $this->actingAs($this->admin)->get(route('rep-supervisor.reps.show', [$this->rep1, 'tab' => 'payments']))->assertOk()->getContent();
        $this->assertStringContainsString('المشرف أحمد', $admin);
        $this->assertStringContainsString('المشرف باسل', $admin);
        $this->assertStringContainsString('data-payment="'.$southPayment.'"', $admin);
        foreach ([$this->accountant, $this->supervisor, $this->d1, $this->rep1, $this->manager] as $user) {
            $this->actingAs($user)->get(route('rep-supervisor.reps.show', $this->rep1))->assertForbidden();
        }

        // المندوب نفسه لا يتغيّر ما يراه
        $mine = $this->actingAs($this->rep1)->get(route('rep.index'))->assertOk()->getContent();
        $this->assertStringContainsString('صيدلية الشمال', $mine);
        $this->assertStringContainsString('صيدلية الجنوب', $mine);
    }

    public function test_all_scope_sees_whole_rep(): void
    {
        $this->enableScoping();
        $sup3 = User::factory()->role(Role::RepSupervisor)->create(['name' => 'المشرف جاد']);
        $this->assign($this->rep2, $sup3, 'all')->assertSessionHasNoErrors();
        $html = $this->actingAs($sup3)->get(route('finance.rep-receivables'))->assertOk()->getContent();
        $this->assertStringContainsString('صيدلية الشرق', $html);
        $this->assertStringNotContainsString('صيدلية الشمال', $html);
        $this->assertStringContainsString('كل صيدليات سامر', $html);
        $this->actingAs($sup3)->getJson(route('invoices.lines', $this->invoice('G: 22')))->assertOk();
        // مشرف بلا إسنادات: لا مندوبين ولا كشوف
        $none = User::factory()->role(Role::RepSupervisor)->create();
        $this->actingAs($none)->get(route('finance.rep-receivables'))->assertOk()->assertDontSee('صيدلية الشرق')->assertSee('لا مندوبين مُسنَدين لك بعد');
        $this->actingAs($none)->get(route('supervisor.sheet.show', $this->sheetA->id))->assertForbidden();
    }

    public function test_receivables_sum_of_supervisors_equals_admin_total(): void
    {
        $this->repPay($this->rep1, $this->south, 300)->assertCreated();
        $this->repPay($this->rep1, $this->north, 1200)->assertCreated();
        $service = app(RepReceivableService::class);
        $scopes = app(RepScope::class);
        $remaining = fn (array $report) => (int) round($report['groups']->where('rep_id', $this->rep1->id)->sum('remaining_cents'));
        $pending = fn (array $report) => (int) round($report['groups']->where('rep_id', $this->rep1->id)->sum('pending_cents'));

        $full = $service->receivablesForStaff(['rep_id' => (string) $this->rep1->id]);
        $one = $service->receivablesForStaff([], $scopes->forSupervisor($this->repSupervisor));
        $two = $service->receivablesForStaff([], $scopes->forSupervisor($this->sup2));
        $this->assertGreaterThan(0, $remaining($full));
        $this->assertSame($remaining($full), $remaining($one) + $remaining($two));
        $this->assertSame($pending($full), $pending($one) + $pending($two));
        $this->assertSame(120000, $pending($one));

        // مع صيدلية غير مسنَدة: مجموع المشرفين + غير المسنَد = الكامل
        $south = $this->active()->firstWhere('supervisor_user_id', $this->sup2->id);
        $this->actingAs($this->admin)->post(route('admin.rep-assignments.end', $south))->assertSessionHasNoErrors();
        $one = $service->receivablesForStaff([], app(RepScope::class)->forSupervisor($this->repSupervisor));
        $two = $service->receivablesForStaff([], app(RepScope::class)->forSupervisor($this->sup2));
        $unassigned = (int) $service->state($this->rep1, [$this->south->id])->sum('remaining_cents');
        $this->assertSame(0, $remaining($two));
        $this->assertGreaterThan(0, $unassigned);
        $this->assertSame($remaining($full), $remaining($one) + $remaining($two) + $unassigned);
    }

    public function test_exports_are_scoped_and_labelled(): void
    {
        $this->enableScoping();
        $this->repPay($this->rep1, $this->south, 300)->assertCreated();

        $response = $this->actingAs($this->repSupervisor)->get(route('finance.rep-receivables.export'))->assertOk();
        $ws = IOFactory::load($response->baseResponse->getFile()->getPathname())->getSheet(0);
        $this->assertStringContainsString('مقيَّد بنطاق: منطقة الشمال', (string) $ws->getCell('A1')->getValue());
        $pharmacies = collect(range(3, $ws->getHighestRow()))->map(fn ($r) => (string) $ws->getCell("B{$r}")->getValue())->filter()->unique()->values()->all();
        $this->assertSame(['صيدلية الشمال'], $pharmacies);

        $response = $this->actingAs($this->repSupervisor)->get(route('finance.rep-payments.list-export'))->assertOk();
        $ws = IOFactory::load($response->baseResponse->getFile()->getPathname())->getSheet(0);
        $this->assertStringContainsString('مقيَّد بنطاق', (string) $ws->getCell('A1')->getValue());
        $this->assertSame('', (string) $ws->getCell('C3')->getValue());   // دفعة الجنوب خارج نطاقه

        $response = $this->actingAs($this->repSupervisor)->get(route('finance.unpaid.export', ['from' => '2026-10-10', 'to' => '2026-10-12']))->assertOk();
        $ws = IOFactory::load($response->baseResponse->getFile()->getPathname())->getSheet(0);
        $this->assertStringContainsString('مقيَّد بنطاق', (string) $ws->getCell('A1')->getValue());
        $invoices = collect(range(4, $ws->getHighestRow()))->map(fn ($r) => (string) $ws->getCell("E{$r}")->getValue())->filter()->implode('|');
        $this->assertStringNotContainsString('B: 21', $invoices);

        $response = $this->actingAs($this->repSupervisor)->get(route('sheets.export', $this->sheetA))->assertOk();
        $book = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $text = collect($book->getAllSheets())->flatMap(fn ($s) => collect($s->toArray())->flatten())->filter()->implode('|');
        $this->assertStringContainsString('مقيَّد بنطاق', $text);
        $this->assertStringContainsString('صيدلية الشمال', $text);
        $this->assertStringNotContainsString('صيدلية الجنوب', $text);

        // الأدمن: بلا قيد
        $response = $this->actingAs($this->admin)->get(route('finance.rep-receivables.export'))->assertOk();
        $ws = IOFactory::load($response->baseResponse->getFile()->getPathname())->getSheet(0);
        $this->assertStringNotContainsString('مقيَّد', (string) $ws->getCell('A1')->getValue());
    }
}
