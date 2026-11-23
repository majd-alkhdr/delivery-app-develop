<?php

namespace Tests\Feature\Phase6;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 6-A/E/F/H/I: الصلاحيات، واجهة المندوب، المراجعة، تقرير الذمم، وشاشة المستخدمين.
 */
class RepAccessReportTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity, RepFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReps();
    }

    public function test_permission_matrix(): void
    {
        // مسارات المندوب: المندوب فقط
        foreach ([$this->d1, $this->accountant, $this->supervisor, $this->repSupervisor, $this->manager, $this->admin] as $user) {
            foreach ([route('rep.index'), route('rep.customers'), route('rep.payments')] as $url) {
                $this->actingAs($user)->get($url)->assertForbidden();
            }
            $this->actingAs($user)->postJson(route('rep.payments.store'), [])->assertForbidden();
        }
        foreach ([route('rep.index'), route('rep.customers'), route('rep.payments')] as $url) {
            $this->actingAs($this->rep1)->get($url)->assertOk();
        }

        // المالية للمندوبين: المحاسب والأدمن والمشرف (قراءة)، وغيرهم 403
        foreach ([$this->accountant, $this->admin, $this->repSupervisor] as $user) {
            $this->actingAs($user)->get(route('finance.rep-payments'))->assertOk();
            $this->actingAs($user)->get(route('finance.rep-receivables'))->assertOk();
        }
        foreach ([$this->d1, $this->manager, $this->rep1, $this->supervisor] as $user) {
            $this->actingAs($user)->get(route('finance.rep-payments'))->assertForbidden();
            $this->actingAs($user)->get(route('finance.rep-receivables'))->assertForbidden();
            $this->actingAs($user)->get(route('finance.rep-receivables.export'))->assertForbidden();
        }

        // المشرف لا يراجع ولا يصدّر
        $id = $this->repPay($this->rep1, $this->north, 100)->json('payment.id');
        $this->actingAs($this->repSupervisor)->post(route('finance.rep-payments.review', $id), ['review_status' => 'verified'])->assertForbidden();
        $this->actingAs($this->repSupervisor)->post(route('finance.rep-payments.export'), ['reps' => [$this->rep1->id], 'signature' => 'x', 'confirm' => 1])->assertForbidden();
        $this->actingAs($this->repSupervisor)->get(route('finance.rep-payments'))->assertSee('عرض فقط')->assertDontSee(route('finance.rep-payments.export'), false);
    }

    public function test_rep_cannot_see_other_rep_data(): void
    {
        $id = $this->repPay($this->rep1, $this->north, 100)->json('payment.id');

        $this->actingAs($this->rep2)->get(route('payments.image', $id))->assertForbidden();
        $this->actingAs($this->rep2)->getJson(route('payments.receipt-data', $id))->assertForbidden();
        $html = $this->actingAs($this->rep2)->get(route('rep.payments'))->getContent();
        $this->assertSame(0, substr_count($html, 'class="card mb-2 rep-payment-row'));
        // ذمم rep2 لا تحوي صيدلية الشمال ولا الرصيد الخام ولا المؤجل
        $index = $this->actingAs($this->rep2)->get(route('rep.index'))->getContent();
        $this->assertStringContainsString('صيدلية الشرق', $index);
        $this->assertStringNotContainsString('صيدلية الشمال', $index);
        $mine = $this->actingAs($this->rep1)->get(route('rep.index'))->getContent();
        $this->assertStringNotContainsString('98,765', $mine);
        $this->assertStringNotContainsString('G: 15', $mine);
        $this->assertStringNotContainsString('A: 13', $mine);
    }

    public function test_rep_pages_do_not_load_print_module_and_show_debts(): void
    {
        $html = $this->actingAs($this->rep1)->get(route('rep.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('print-service', $html);
        $this->assertStringContainsString('js/rep/rep-app.js', $html);
        $this->assertStringContainsString('capture="environment"', $html);
        $this->assertStringContainsString('id="rep-total">2,700.00', $html);   // 1000 + 500 + 400 + 800

        $customers = $this->actingAs($this->rep1)->get(route('rep.customers', ['q' => 'الشرق']))->assertOk()->getContent();
        $this->assertStringContainsString('صيدلية الشرق', $customers);
        $this->assertStringContainsString('لا دَين معروف لك', $customers);
        $this->assertStringNotContainsString('print-service', $customers);
    }

    public function test_accountant_review_and_void_with_reason(): void
    {
        $id = $this->repPay($this->rep1, $this->north, 100)->json('payment.id');

        $this->actingAs($this->accountant)->post(route('finance.rep-payments.review', $id), ['review_status' => 'needs_review'])->assertSessionHasErrors('review_note');
        $this->actingAs($this->accountant)->post(route('finance.rep-payments.review', $id), ['review_status' => 'needs_review', 'review_note' => 'المبلغ غير واضح'])->assertSessionHasNoErrors();
        $this->assertSame('needs_review', Payment::find($id)->review_status);
        $this->actingAs($this->rep1)->get(route('rep.payments'))->assertSee('المبلغ غير واضح');

        $this->actingAs($this->accountant)->post(route('finance.rep-payments.review', $id), ['review_status' => 'verified'])->assertSessionHasNoErrors();
        $log = ActivityLog::where('action', 'rep.payment_reviewed')->latest('id')->first();
        $this->assertSame('needs_review', $log->old_values['review_status']);
        $this->assertSame('verified', $log->new_values['review_status']);

        $page = $this->actingAs($this->accountant)->get(route('finance.rep-payments'))->assertOk()->getContent();
        $this->assertStringContainsString(route('payments.image', $id), $page);

        $this->actingAs($this->accountant)->post(route('payments.void', $id), ['reason' => 'مكرر'])->assertSessionHasNoErrors();
        $this->assertSame('مكرر', Payment::find($id)->void_reason);
        $this->actingAs($this->rep1)->get(route('rep.payments'))->assertSee('ملغاة: مكرر');
    }

    public function test_receivables_report_filters_totals_export_and_no_raw_balance(): void
    {
        $this->repPay($this->rep1, $this->north, 1200)->assertCreated();
        $this->repExport($this->accountant, [$this->rep1->id])->assertSessionHasNoErrors();
        $this->repPay($this->rep1, $this->north, 100)->assertCreated();

        $html = $this->actingAs($this->accountant)->get(route('finance.rep-receivables'))->assertOk()->getContent();
        $this->assertStringNotContainsString('98,765', $html);
        $this->assertStringNotContainsString('G: 15', $html);
        $this->assertStringContainsString('بلا مندوب', $html);
        // كرم: ناشئ 2,700؛ مُصدَّر 1,200؛ معلّق 100؛ متبقٍّ 1,400
        $this->assertMatchesRegularExpression('/كرم.*?2,700\.00.*?1,200\.00.*?100\.00.*?1,400\.00/su', $html);

        $count = fn (array $q) => substr_count($this->actingAs($this->accountant)->get(route('finance.rep-receivables', $q))->getContent(), 'class="rr-invoice-row"');
        $this->assertSame(4, $count(['rep_id' => (string) $this->rep1->id]));
        $this->assertSame(1, $count(['rep_id' => 'none']));
        $this->assertSame(3, $count(['rep_id' => (string) $this->rep1->id, 'area' => 'منطقة الشمال']));
        $this->assertSame(1, $count(['rep_id' => (string) $this->rep1->id, 'status' => 'paid']));
        $this->assertSame(1, $count(['rep_id' => (string) $this->rep1->id, 'status' => 'partial']));
        $this->assertSame(1, $count(['q' => 'الشرق']));

        $response = $this->actingAs($this->repSupervisor)->get(route('finance.rep-receivables.export', ['rep_id' => (string) $this->rep1->id]))->assertOk();
        $ws = IOFactory::load($response->baseResponse->getFile()->getPathname())->getSheet(0);
        $this->assertTrue($ws->getRightToLeft());
        $this->assertEquals([2, 2], $ws->getPageSetup()->getRowsToRepeatAtTop());
        $this->assertCount(4, array_slice($ws->toArray(), 2));
        $this->assertStringContainsString('filename=rep_receivables_', $response->headers->get('content-disposition'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'finance.rep_receivables_exported', 'user_id' => $this->repSupervisor->id]);
    }

    public function test_age_bucket_filter(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 15));
        $groups = app(\App\Services\Finance\RepReceivableService::class)->receivablesForStaff(['rep_id' => (string) $this->rep1->id, 'age' => '0-7'])['groups'];
        $this->assertSame(2, $groups->first()['pharmacies']->count());   // أقدم دَين 5 أيام
        $groups = app(\App\Services\Finance\RepReceivableService::class)->receivablesForStaff(['rep_id' => (string) $this->rep1->id, 'age' => '8-30'])['groups'];
        $this->assertSame(0, $groups->first()['pharmacies']->count());
        $this->travelBack();
    }

    public function test_users_screen_rep_cost_center_rules(): void
    {
        $base = ['name' => 'مندوب جديد', 'email' => 'rep@example.com', 'password' => 'secret123', 'role' => 'rep'];
        $this->actingAs($this->admin)->post(route('supervisor.users.store'), $base)->assertSessionHasErrors('cost_center_label');
        $this->actingAs($this->admin)->post(route('supervisor.users.store'), $base + ['cost_center_label' => 'بلا كود'])->assertSessionHasErrors('cost_center_label');
        // الكود فريد بين المندوبين (ولو بأرقام عربية)
        $this->actingAs($this->admin)->post(route('supervisor.users.store'), $base + ['cost_center_label' => '٦٥٥٢٣١-مكرر'])->assertSessionHasErrors('cost_center_label');

        $this->actingAs($this->admin)->post(route('supervisor.users.store'), $base + ['cost_center_label' => ' ٨٠٠١٠٠ - أحمد  علي '])->assertSessionHasNoErrors();
        $rep = User::where('email', 'rep@example.com')->sole();
        $this->assertSame('800100', $rep->cost_center_code);
        $this->assertSame('800100-أحمد علي', $rep->cost_center_label);
        $this->assertDatabaseHas('activity_logs', ['action' => 'rep.cost_center_set', 'subject_id' => $rep->id]);

        // تعديل المركز يُسجَّل بقبل/بعد، وتحويل الدور لغير مندوب يفرّغه
        $this->actingAs($this->admin)->put(route('supervisor.users.update', $rep->id), ['name' => $rep->name, 'email' => $rep->email, 'role' => 'rep', 'cost_center_label' => '800101-أحمد'])->assertSessionHasNoErrors();
        $log = ActivityLog::where('action', 'rep.cost_center_set')->latest('id')->first();
        $this->assertSame('800100-أحمد علي', $log->old_values['cost_center_label']);
        $this->assertSame('800101-أحمد', $log->new_values['cost_center_label']);
        $this->actingAs($this->admin)->put(route('supervisor.users.update', $rep->id), ['name' => $rep->name, 'email' => $rep->email, 'role' => 'distributor'])->assertSessionHasNoErrors();
        $this->assertNull($rep->fresh()->cost_center_code);

        // غير المندوب لا يحتاج مركزاً
        $this->actingAs($this->admin)->post(route('supervisor.users.store'), ['name' => 'محاسب', 'email' => 'acc@example.com', 'password' => 'secret123', 'role' => 'accountant'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get(route('supervisor.users.index'))->assertSee('مركز الكلفة (كود-اسم)');
        $this->assertSame(Role::Rep, $this->rep1->role);
    }

    public function test_sidebar_items_for_finance_roles(): void
    {
        foreach ([$this->accountant, $this->admin, $this->repSupervisor] as $user) {
            $html = $this->actingAs($user)->get(route($user->role->homeRoute()))->getContent();
            $this->assertStringContainsString('data-menu-item="finance.rep-payments"', $html);
            $this->assertStringContainsString('data-menu-item="finance.rep-receivables"', $html);
        }
        $this->actingAs($this->manager)->get(route('sheet-manager.index'))->assertDontSee('data-menu-item="finance.rep-payments"', false);
        // المرحلة 6B: مشرف التوزيع خارج القسم المالي
        $this->actingAs($this->supervisor)->get(route("supervisor.index"))->assertDontSee("data-menu-item=\"finance.rep-payments\"", false)->assertDontSee("data-menu-item=\"finance.unpaid\"", false);
    }
}
