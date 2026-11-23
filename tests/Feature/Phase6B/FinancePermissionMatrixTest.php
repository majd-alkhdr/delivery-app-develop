<?php

namespace Tests\Feature\Phase6B;

use App\Enums\Role;
use App\Models\FinanceExport;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase6\RepFixture;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 6B-B5: مصفوفة الصلاحيات (دور × مسار) للقسم المالي وصفحات الكشف وأسطر الفواتير والتصدير.
 */
class FinancePermissionMatrixTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity, RepFixture;

    private int $paymentId;
    private FinanceExport $export;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReps();
        $this->sheetA->update(['status' => 'in_progress']);
        $this->paymentId = $this->repPay($this->rep1, $this->north, 100)->json('payment.id');
        $this->repPay($this->rep1, $this->south, 50)->assertCreated();
        $this->repExport($this->accountant, [$this->rep1->id])->assertSessionHasNoErrors();
        $this->export = FinanceExport::sole();
        $this->paymentId = $this->repPay($this->rep1, $this->north, 30)->json('payment.id');   // دفعة معلّقة للمراجعة
    }

    /** @return array<string, string> */
    private function financeGets(): array
    {
        return [
            'sheet' => route('finance.sheets.show', $this->sheetA),
            'unpaid' => route('finance.unpaid', ['from' => '2026-10-10']),
            'rep-payments' => route('finance.rep-payments'),
            'rep-receivables' => route('finance.rep-receivables'),
        ];
    }

    /** @return array<string, string> */
    private function reportExports(): array
    {
        return [
            'unpaid.export' => route('finance.unpaid.export', ['from' => '2026-10-10']),
            'rep-receivables.export' => route('finance.rep-receivables.export'),
            'rep-payments.list-export' => route('finance.rep-payments.list-export'),
        ];
    }

    /** طلبات «التعديل» و«التصدير للمحاسبة» [method, url, data] */
    private function manageRequests(): array
    {
        return [
            'cash' => ['post', route('finance.cash-counts.store', $this->sheetA), ['distributor_id' => $this->d1->id, 'currency' => 'SYP', 'amount' => 1]],
            'surplus' => ['post', route('finance.sheets.surplus', $this->sheetA), ['customer_id' => $this->north->id, 'cost_center' => '201201-كول سنتر']],
            'sheet-export' => ['post', route('finance.sheets.export', $this->sheetA), ['signature' => 'x', 'confirm' => 1]],
            'rate' => ['post', route('finance.payments.rate', $this->paymentId), ['exchange_rate' => 139]],
            'download' => ['get', route('finance.exports.download', [$this->export, 'import']), []],
            'review' => ['post', route('finance.rep-payments.review', $this->paymentId), ['review_status' => 'verified']],
            'rep-export' => ['post', route('finance.rep-payments.export'), ['reps' => [$this->rep1->id], 'signature' => 'x', 'confirm' => 1]],
            'settings' => ['put', route('settings.finance'), ['finance_statement_template' => 'x {user}']],
        ];
    }

    private function hit(User $user, string $method, string $url, array $data = [])
    {
        return $this->actingAs($user)->json(strtoupper($method), $url, $data);
    }

    public function test_admin_and_accountant_unchanged(): void
    {
        foreach ([$this->admin, $this->accountant] as $user) {
            foreach ($this->financeGets() + $this->reportExports() as $name => $url) {
                $this->assertSame(200, $this->actingAs($user)->get($url)->getStatusCode(), "{$user->role->value} {$name}");
            }
            foreach ($this->manageRequests() as $name => [$method, $url, $data]) {
                if ($name === 'settings' && $user->role === Role::Accountant) {
                    continue;   // الإعدادات للأدمن (والمشرف) كما كانت
                }
                $this->assertNotSame(403, $this->hit($user, $method, $url, $data)->getStatusCode(), "{$user->role->value} {$name}");
            }
        }
    }

    public function test_rep_supervisor_reads_and_exports_reports_but_cannot_change_or_export_to_accounting(): void
    {
        $u = $this->repSupervisor;
        foreach ($this->financeGets() + $this->reportExports() as $name => $url) {
            $this->assertSame(200, $this->actingAs($u)->get($url)->getStatusCode(), $name);
        }
        foreach ($this->manageRequests() as $name => [$method, $url, $data]) {
            $this->assertSame(403, $this->hit($u, $method, $url, $data)->getStatusCode(), $name);
        }

        // الكشوف: القراءة نعم، والتعديل والإسناد والإغلاق لا؛ وقيد التجهيز والمسودة غير موجودين
        $this->actingAs($u)->get(route('supervisor.index'))->assertOk()->assertDontSee(route('supervisor.complete', $this->sheetA->id), false);
        $show = $this->actingAs($u)->get(route('supervisor.sheet.show', $this->sheetA->id))->assertOk();
        $show->assertDontSee(route('supervisor.sheet.assign', $this->sheetA), false)->assertDontSee(route('supervisor.sheet.draft', $this->sheetA), false);
        $this->hit($u, 'post', route('supervisor.complete', $this->sheetA->id))->assertForbidden();
        $this->hit($u, 'put', route('supervisor.sheet.assign', $this->sheetA), ['all_distributor_id' => $this->d1->id])->assertForbidden();
        $this->actingAs($u)->get(route('sheets.export', $this->sheetA))->assertOk();
        $invoice = Invoice::where('distribution_sheet_id', $this->sheetA->id)->where('placement', Invoice::IN_SHEET)->first();
        $this->actingAs($u)->getJson(route('invoices.lines', $invoice))->assertOk()->assertJsonStructure(['lines', 'has_discount', 'net_total']);

        $this->sheetC->update(['status' => 'preparing']);
        $this->actingAs($u)->get(route('supervisor.sheet.show', $this->sheetC->id))->assertNotFound();
        $this->sheetC->update(['status' => 'draft']);
        $this->actingAs($u)->get(route('supervisor.sheet.show', $this->sheetC->id))->assertNotFound();
        $this->assertStringNotContainsString('#3913', $this->actingAs($u)->get(route('supervisor.index'))->getContent());

        // لا مستخدمين ولا إعدادات ولا رفع
        $this->actingAs($u)->get(route('supervisor.users.index'))->assertForbidden();
        $this->actingAs($u)->get(route('settings.index'))->assertForbidden();
        $this->actingAs($u)->get(route('sheet-manager.index'))->assertForbidden();
    }

    public function test_supervisor_has_no_finance_but_keeps_field_monitoring(): void
    {
        $u = $this->supervisor;
        foreach ($this->financeGets() + $this->reportExports() as $name => $url) {
            $this->assertSame(403, $this->actingAs($u)->get($url)->getStatusCode(), $name);
        }
        foreach ($this->manageRequests() as $name => [$method, $url, $data]) {
            if ($name === 'settings') {
                continue;
            }
            $this->assertSame(403, $this->hit($u, $method, $url, $data)->getStatusCode(), $name);
        }
        $this->actingAs($u)->get(route('payments.image', $this->paymentId))->assertForbidden();

        // صفحة الكشف تعمل بلا كتل مالية: لا رابط «المالية»، ويبقى الإسناد والإغلاق
        $html = $this->actingAs($u)->get(route('supervisor.sheet.show', $this->sheetA->id))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('finance.sheets.show', $this->sheetA), $html);
        $this->assertStringContainsString(route('supervisor.sheet.assign', $this->sheetA), $html);
    }

    public function test_other_roles_are_unchanged_and_blocked_from_finance(): void
    {
        foreach ([$this->manager, $this->d1, $this->rep1] as $user) {
            foreach ($this->financeGets() + $this->reportExports() as $name => $url) {
                $this->assertSame(403, $this->actingAs($user)->getJson($url)->getStatusCode(), "{$user->role->value} {$name}");
            }
            foreach ($this->manageRequests() as $name => [$method, $url, $data]) {
                $this->assertSame(403, $this->hit($user, $method, $url, $data)->getStatusCode(), "{$user->role->value} {$name}");
            }
        }
        // مسؤول الكشوف ما زال يرى أسطر الفواتير وتصدير الكشف
        $invoice = Invoice::where('distribution_sheet_id', $this->sheetA->id)->first();
        $this->actingAs($this->manager)->getJson(route('invoices.lines', $invoice))->assertOk();
        $this->actingAs($this->manager)->get(route('sheets.export', $this->sheetA))->assertOk();
    }

    public function test_rep_supervisor_user_form_and_seeder(): void
    {
        $this->actingAs($this->admin)->post(route('supervisor.users.store'), ['name' => 'م.م', 'email' => 'rs@example.com', 'password' => 'secret123', 'role' => 'rep_supervisor'])->assertSessionHasNoErrors();
        $this->assertSame(Role::RepSupervisor, User::where('email', 'rs@example.com')->sole()->role);
        $this->actingAs($this->admin)->get(route('supervisor.users.index'))->assertSee('مشرف المندوبين')->assertSee('عرض فقط: الكشوف والقسم المالي');

        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->assertSame(Role::RepSupervisor, User::where('email', 'rep-supervisor@test.com')->sole()->role);
    }
}
