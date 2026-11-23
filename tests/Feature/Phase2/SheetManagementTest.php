<?php

namespace Tests\Feature\Phase2;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerAlias;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\SheetAssignment;
use App\Models\User;
use App\Support\ArabicNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Workbooks;
use Tests\Support\FieldActivity;
use Tests\TestCase;

class SheetManagementTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity;

    private User $admin;
    private User $supervisor;
    private User $d1;
    private User $d2;
    private User $manager;
    private DistributionSheet $sheet;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->supervisor = User::factory()->role(Role::Supervisor)->create();
        $this->d1 = User::factory()->role(Role::Distributor)->create();
        $this->d2 = User::factory()->role(Role::Distributor)->create();
        $this->manager = User::factory()->role(Role::SheetManager)->create();

        $path = $this->buildWorkbook([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]],
            ['number' => 'ع G: 2', 'customer' => 'صيدلية باء', 'lines' => [['صنف', 200]]],
            ['number' => 'ع G: 3', 'customer' => 'صيدلية جديدة', 'lines' => [['صنف', 300]]],
        ], [
            ['صيدلية ألف', 'كتلة 1', 'منطقة أ', 1000, 0],
            ['صيدلية باء', 'كتلة 1', 'منطقة ب', 2000, 0],
        ]);
        $this->sheet = $this->publishWorkbook($path);
    }

    private function key(string $area): string
    {
        return ArabicNormalizer::normalize($area);
    }

    public function test_area_cannot_be_assigned_to_two_distributors(): void
    {
        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $this->sheet), ['rows' => [
            ['area_key' => $this->key('منطقة أ'), 'distributor_id' => $this->d1->id],
            ['area_key' => $this->key('منطقة أ'), 'distributor_id' => $this->d2->id],
        ]])->assertSessionHasErrors('assignments');

        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $this->sheet), [
            'all_distributor_id' => $this->d1->id,
            'rows' => [['area_key' => $this->key('منطقة ب'), 'distributor_id' => $this->d2->id]],
        ])->assertSessionHasErrors('assignments');

        $this->assertSame(0, SheetAssignment::count());

        // وقاعدة البيانات تمنع التكرار أيضاً
        SheetAssignment::create(['sheet_id' => $this->sheet->id, 'distributor_id' => $this->d1->id, 'area_key' => $this->key('منطقة أ')]);
        $this->expectException(QueryException::class);
        SheetAssignment::create(['sheet_id' => $this->sheet->id, 'distributor_id' => $this->d2->id, 'area_key' => $this->key('منطقة أ')]);
    }

    public function test_unassigned_areas_show_warning_until_assigned(): void
    {
        $this->actingAs($this->supervisor)->get(route('supervisor.index'))->assertSee('منطقة بلا موزع');

        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $this->sheet), ['rows' => [
            ['area_key' => $this->key('منطقة أ'), 'distributor_id' => $this->d1->id],
        ]])->assertSessionHas('warning');

        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $this->sheet), ['rows' => [
            ['area_key' => $this->key('منطقة أ'), 'distributor_id' => $this->d1->id],
            ['area_key' => $this->key('منطقة ب'), 'distributor_id' => $this->d2->id],
            ['area_key' => SheetAssignment::NO_AREA, 'distributor_id' => $this->d2->id],
        ]])->assertSessionHas('success');

        $this->actingAs($this->supervisor)->get(route('supervisor.index'))->assertDontSee('منطقة بلا موزع');
        $this->assertDatabaseHas('activity_logs', ['action' => 'sheet.assigned']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'sheet.reassigned']);
    }

    public function test_reassign_keeps_recorded_visits(): void
    {
        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $this->sheet), ['all_distributor_id' => $this->d1->id]);
        $invoice = Invoice::where('invoice_number', 'ع G: 1')->first();
        $this->payApi($this->d1, $this->sheet, $invoice->customer_id, 90)->assertCreated();

        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $this->sheet), ['all_distributor_id' => $this->d2->id]);

        $this->assertEquals(90, (float) \App\Models\Payment::where('customer_id', $invoice->customer_id)->value('amount'));
        $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->assertForbidden();
        $this->actingAs($this->d2)->get(route('distributor.sheet', $this->sheet->id))->assertOk();
        $log = ActivityLog::where('action', 'sheet.reassigned')->latest('id')->first();
        $this->assertSame(['كل المناطق' => $this->d1->id], $log->old_values['assignments']);
        $this->assertSame(['كل المناطق' => $this->d2->id], $log->new_values['assignments']);
    }

    public function test_sheet_delete_route_is_removed(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('supervisor.destroy'));
        $status = $this->actingAs($this->admin)->delete('/supervisor/sheets/'.$this->sheet->id)->getStatusCode();
        $this->assertContains($status, [404, 405]);
        $this->assertNotNull($this->sheet->fresh());
    }

    public function test_draft_requires_reason_and_only_admin_can_reopen(): void
    {
        $this->actingAs($this->supervisor)->post(route('supervisor.sheet.draft', $this->sheet), ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->assertSame('pending', $this->sheet->fresh()->status);

        $this->actingAs($this->supervisor)->post(route('supervisor.sheet.draft', $this->sheet), ['reason' => 'ملف مكرر'])
            ->assertSessionHasNoErrors();
        $this->assertSame('draft', $this->sheet->fresh()->status);
        $this->assertSame('ملف مكرر', $this->sheet->fresh()->status_reason);

        // المرحلة 2B: فواتير المسودة تُفرَّغ إلى "بلا كشف"
        $this->assertSame(0, Invoice::where('distribution_sheet_id', $this->sheet->id)->count());
        $this->assertSame(3, Invoice::where('placement', Invoice::UNASSIGNED)->count());

        // المسودة لا تقبل تعديلاً
        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $this->sheet), ['all_distributor_id' => $this->d1->id])
            ->assertSessionHasErrors('assignments');
        $this->actingAs($this->supervisor)->post(route('supervisor.complete', $this->sheet->id))->assertSessionHas('error');

        // تظهر للمحاسب بشارة "مسودة" وسببها
        $accountant = User::factory()->role(Role::Accountant)->create();
        $this->actingAs($accountant)->get(route('accountant.index'))->assertSee('مسودة')->assertSee('ملف مكرر');

        $this->actingAs($this->supervisor)->post(route('supervisor.sheet.reopen', $this->sheet))->assertForbidden();

        // مسودة فارغة: إعادة تفعيلها ممنوعة
        $this->actingAs($this->admin)->post(route('supervisor.sheet.reopen', $this->sheet))->assertSessionHas('error');
        $this->assertSame('draft', $this->sheet->fresh()->status);

        // مسودة بفواتير (مثل مسودات المرحلة 2): يعيد المدير تفعيلها
        Invoice::where('placement', Invoice::UNASSIGNED)->update(['placement' => Invoice::IN_SHEET, 'distribution_sheet_id' => $this->sheet->id]);
        $this->actingAs($this->admin)->post(route('supervisor.sheet.reopen', $this->sheet))->assertSessionHas('success');
        $this->assertSame('pending', $this->sheet->fresh()->status);

        $this->assertDatabaseHas('activity_logs', ['action' => 'sheet.drafted']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'sheet.reopened']);
    }

    public function test_deferred_is_subtracted_until_cancelled(): void
    {
        $customer = Customer::where('name', 'صيدلية ألف')->first();
        $this->importFiles($this->buildWorkbook([
            ['number' => 'ع G: 50', 'customer' => 'صيدلية ألف', 'delivery' => '20-10-2026', 'lines' => [['صنف', 400]]],
        ]), '2026-10-13');

        $service = app(\App\Services\CustomerBalanceService::class);
        $this->assertSame(600.0, $service->dueBalance($customer->fresh(), \Carbon\Carbon::parse('2026-10-13')));

        $deferred = Invoice::where('invoice_number', 'ع G: 50')->first();
        app(\App\Services\SheetInvoiceEditor::class)->cancel($deferred, 'أُلغيت في المحاسبة', $this->manager);
        $this->assertSame(1000.0, $service->dueBalance($customer->fresh(), \Carbon\Carbon::parse('2026-10-13')));
    }

    public function test_review_link_moves_invoices_and_creates_alias(): void
    {
        $temp = Customer::where('needs_review', true)->firstOrFail();
        $target = Customer::where('name', 'صيدلية ألف')->first();

        // المرحلة 2B: المطابقة لمسؤول الكشوف، والمشرف فقدها
        $this->actingAs($this->supervisor)->get(route('customers.review'))->assertForbidden();
        $this->actingAs($this->manager)->get(route('customers.review'))->assertOk()->assertSee('صيدلية جديدة');
        $this->actingAs($this->manager)->post(route('customers.link', $temp), ['target_id' => $target->id])
            ->assertSessionHas('success');

        $this->assertNull($temp->fresh());
        $this->assertSame($target->id, Invoice::where('invoice_number', 'ع G: 3')->first()->customer_id);
        $this->assertSame($this->key('منطقة أ'), Invoice::where('invoice_number', 'ع G: 3')->first()->area_key);
        $this->assertTrue(CustomerAlias::where('alias_normalized', ArabicNormalizer::normalize('صيدلية جديدة'))->exists());
        $this->assertEquals(1000, (float) Invoice::where('invoice_number', 'ع G: 3')->first()->final_balance);

        // الرفع التالي يطابق الاسم البديل تلقائياً
        $report = $this->importFiles($this->buildWorkbook([
            ['number' => 'ع G: 60', 'customer' => 'صيدلية جديدة', 'lines' => [['صنف', 10]]],
        ]), '2026-10-13')['reports'][0];
        $this->assertSame(1, $report['counts']['matched']);

        // المرحلة 8C: سجل النشاط بالمنفّذ: مسؤول الكشوف يرى ربطه هو (المشرف لا يرى إجراءات مسؤول الكشوف)
        $this->actingAs($this->manager)->get(route('activity.index'))
            ->assertViewHas('logs', fn ($logs) => collect($logs->items())->pluck('action')->contains('customer.aliased'));
    }

    public function test_review_approve_sets_area_and_updates_open_sheet(): void
    {
        $temp = Customer::where('needs_review', true)->firstOrFail();

        $this->actingAs($this->supervisor)->post(route('customers.approve', $temp), ['area' => 'منطقة جديدة'])->assertForbidden();
        $this->actingAs($this->manager)->post(route('customers.approve', $temp), ['area' => 'منطقة جديدة'])
            ->assertSessionHas('success');

        $this->assertFalse($temp->fresh()->needs_review);
        $this->assertSame($this->key('منطقة جديدة'), Invoice::where('invoice_number', 'ع G: 3')->first()->area_key);
        $this->assertDatabaseHas('activity_logs', ['action' => 'customer.created_from_review']);
    }

    public function test_customers_screen_and_hide_toggle(): void
    {
        $customer = Customer::where('name', 'صيدلية باء')->first();

        // المشرف: القائمة للقراءة فقط
        $this->actingAs($this->supervisor)->get(route('customers.index', ['q' => 'باء']))
            ->assertOk()->assertSee('صيدلية باء')->assertSee('2,000.00')
            ->assertDontSee(route('customers.visibility', $customer));
        $this->actingAs($this->supervisor)->post(route('customers.visibility', $customer))->assertForbidden();

        $this->actingAs($this->manager)->post(route('customers.visibility', $customer))->assertSessionHas('success');
        $this->assertTrue($customer->fresh()->is_hidden);
        $this->actingAs($this->supervisor)->get(route('customers.index'))->assertDontSee('صيدلية باء');
        $this->assertDatabaseHas('activity_logs', ['action' => 'customer.hidden']);
    }

    public function test_series_screen_is_admin_only(): void
    {
        $this->actingAs($this->supervisor)->get(route('admin.series.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.series.index'))->assertOk()->assertSee('Hammers Team');

        $series = \App\Models\InvoiceSeries::where('key', 'g')->first();
        $this->actingAs($this->admin)->put(route('admin.series.update', $series), ['label' => 'عام'])->assertSessionHas('success');
        $this->assertSame('عام', $series->fresh()->label);
    }

    public function test_v1_sheets_remain_viewable_and_v2_download_is_hidden(): void
    {
        $v1 = DistributionSheet::create(['distributor_id' => $this->d1->id, 'supervisor_id' => $this->supervisor->id, 'file_name' => 'old.xlsx', 'status' => 'pending']);
        Invoice::create(['distribution_sheet_id' => $v1->id, 'invoice_number' => 'OLD-1', 'customer_name' => 'زبون قديم', 'invoice_amount' => 50, 'invoice_pattern' => 'M']);
        SheetAssignment::create(['sheet_id' => $v1->id, 'distributor_id' => $this->d1->id]);

        $this->assertSame('v1', $v1->fresh()->format);
        $this->actingAs($this->supervisor)->get(route('supervisor.sheet.show', $v1->id))->assertOk()->assertSee('OLD-1')
            ->assertSee(route('supervisor.download', $v1->id));
        $this->actingAs($this->d1)->get(route('distributor.sheet', $v1->id))->assertOk()->assertSee('OLD-1');

        $this->actingAs($this->supervisor)->get(route('supervisor.sheet.show', $this->sheet->id))
            ->assertOk()
            ->assertDontSee(route('supervisor.download', $this->sheet->id))
            ->assertSee('1 صنف');
        $this->actingAs($this->supervisor)->get(route('supervisor.download', $this->sheet->id))->assertSessionHas('error');
    }
}
