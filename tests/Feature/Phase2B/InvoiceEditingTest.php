<?php

namespace Tests\Feature\Phase2B;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\User;
use App\Support\ArabicNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Workbooks;
use Tests\Support\FieldActivity;
use Tests\TestCase;

/**
 * J) تعديل الفواتير عبر SheetInvoiceEditor: نقل، إخراج، وضع من "بلا كشف" مع تغيير المنطقة، استبعاد، وحارس النشاط.
 */
class InvoiceEditingTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity;

    private User $admin;
    private User $manager;
    private DistributionSheet $a;
    private DistributionSheet $b;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->manager = User::factory()->role(Role::SheetManager)->create();

        $this->importFiles($this->buildWorkbook([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]],
            ['number' => 'ع G: 2', 'customer' => 'صيدلية باء', 'order' => 4000, 'lines' => [['صنف', 200]]],
            ['number' => 'ع G: 3', 'customer' => 'صيدلية ألف', 'order' => 0, 'lines' => [['صنف', 30]]],
        ], [
            ['صيدلية ألف', 'كتلة 1', 'منطقة أ', 1000, 0],
            ['صيدلية باء', 'كتلة 1', 'منطقة ب', 2000, 0],
        ]), '2026-10-13', $this->manager);

        $this->a = DistributionSheet::where('delivery_order_no', '3918')->firstOrFail();
        $this->b = DistributionSheet::where('delivery_order_no', '4000')->firstOrFail();
    }

    private function inv(string $number): Invoice
    {
        return Invoice::where('invoice_number', $number)->firstOrFail();
    }

    public function test_move_remove_and_place_with_area_change_update_sheet_figures(): void
    {
        // نقل
        $this->actingAs($this->manager)->post(route('sheet-manager.invoices.move'), ['invoice_ids' => [$this->inv('ع G: 1')->id], 'sheet_id' => $this->b->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->b->id, $this->inv('ع G: 1')->distribution_sheet_id);
        $this->assertTrue($this->inv('ع G: 1')->manual_placement);
        $this->assertSame('0.00', $this->a->fresh()->total_all);
        $this->assertSame('300.00', $this->b->fresh()->total_all);
        $log = ActivityLog::where('action', 'invoice.moved')->firstOrFail();
        $this->assertSame($this->a->id, $log->old_values['sheet_id']);
        $this->assertSame($this->b->id, $log->new_values['sheet_id']);

        // إخراج: السبب إلزامي
        $this->actingAs($this->manager)->post(route('sheet-manager.invoices.remove'), ['invoice_ids' => [$this->inv('ع G: 2')->id], 'reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->actingAs($this->manager)->post(route('sheet-manager.invoices.remove'), ['invoice_ids' => [$this->inv('ع G: 2')->id], 'reason' => 'الزبون أغلق'])
            ->assertSessionHasNoErrors();
        $this->assertSame(Invoice::UNASSIGNED, $this->inv('ع G: 2')->placement);
        $this->assertNull($this->inv('ع G: 2')->distribution_sheet_id);
        $this->assertSame('100.00', $this->b->fresh()->total_all);
        $this->assertSame('الزبون أغلق', ActivityLog::where('action', 'invoice.removed')->first()->new_values['reason']);

        // وضع فاتورة "بلا كشف" في كشف مع تغيير منطقتها وحدها
        $areaB = ArabicNormalizer::normalize('منطقة ب');
        $this->actingAs($this->manager)->post(route('sheet-manager.invoices.place', $this->inv('ع G: 3')), ['sheet_id' => $this->a->id, 'area_key' => $areaB])
            ->assertSessionHasNoErrors();
        $placed = $this->inv('ع G: 3');
        $this->assertSame(Invoice::IN_SHEET, $placed->placement);
        $this->assertSame($this->a->id, $placed->distribution_sheet_id);
        $this->assertTrue($placed->area_overridden);
        $this->assertSame($areaB, $placed->area_key);
        $this->assertSame('منطقة ب', $placed->area);
        $this->assertSame('30.00', $this->a->fresh()->total_all);
        $this->assertDatabaseHas('activity_logs', ['action' => 'invoice.placed', 'subject_id' => $placed->id]);

        // منطقة العميل في كشف لاحق لا تمسّ الفاتورة المعدّلة منطقتها
        app(\App\Services\SheetFiguresService::class)->syncCustomerArea($placed->customer);
        $this->assertSame($areaB, $placed->fresh()->area_key);

        // أرقام الكشف للزبون (المستحق) أُعيد حسابها
        $this->assertEquals(1000, (float) $placed->fresh()->final_balance);
        $this->assertEquals(30, (float) $placed->fresh()->customer_total_invoices);
    }

    public function test_bulk_move_and_change_area(): void
    {
        $this->actingAs($this->manager)->post(route('sheet-manager.invoices.move'), [
            'invoice_ids' => [$this->inv('ع G: 2')->id],
            'sheet_id' => $this->a->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, $this->a->invoices()->count());

        $this->actingAs($this->manager)->post(route('sheet-manager.invoices.area', $this->inv('ع G: 1')), ['area_key' => '__none__'])
            ->assertSessionHasNoErrors();
        $this->assertSame('__none__', $this->inv('ع G: 1')->area_key);
        $this->assertTrue($this->inv('ع G: 1')->area_overridden);
        $this->assertDatabaseHas('activity_logs', ['action' => 'invoice.area_changed']);
    }

    public function test_exclude_requires_reason_and_restore_returns_to_unassigned(): void
    {
        $loose = $this->inv('ع G: 3');
        $this->actingAs($this->manager)->post(route('sheet-manager.invoices.exclude', $loose), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->manager)->post(route('sheet-manager.invoices.exclude', $loose), ['reason' => 'فاتورة مكررة في المحاسبة'])->assertSessionHasNoErrors();
        $this->assertSame(Invoice::EXCLUDED, $loose->fresh()->placement);
        $this->assertSame('فاتورة مكررة في المحاسبة', $loose->fresh()->exclusion_reason);

        $this->actingAs($this->manager)->get(route('invoices.unassigned', ['excluded' => 1]))->assertOk()->assertSee('ع G: 3');

        $this->actingAs($this->manager)->post(route('sheet-manager.invoices.restore', $loose))->assertSessionHasNoErrors();
        $this->assertSame(Invoice::UNASSIGNED, $loose->fresh()->placement);
        $this->assertDatabaseHas('activity_logs', ['action' => 'invoice.excluded']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'invoice.restored']);
    }

    public function test_moving_invoice_of_customer_with_activity_is_admin_only(): void
    {
        $this->markActivity($this->inv('ع G: 1'));

        $this->actingAs($this->manager)->post(route('sheet-manager.invoices.move'), ['invoice_ids' => [$this->inv('ع G: 1')->id], 'sheet_id' => $this->b->id])
            ->assertSessionHasErrors('invoice');
        $this->assertSame($this->a->id, $this->inv('ع G: 1')->distribution_sheet_id);

        $this->actingAs($this->manager)->post(route('sheet-manager.invoices.remove'), ['invoice_ids' => [$this->inv('ع G: 1')->id], 'reason' => 'سبب'])
            ->assertSessionHasErrors('invoice');
        $this->actingAs($this->manager)->post(route('sheet-manager.invoices.area', $this->inv('ع G: 1')), ['area_key' => '__none__'])
            ->assertSessionHasErrors('invoice');

        $this->actingAs($this->admin)->post(route('sheet-manager.invoices.move'), ['invoice_ids' => [$this->inv('ع G: 1')->id], 'sheet_id' => $this->b->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->b->id, $this->inv('ع G: 1')->distribution_sheet_id);
    }

    public function test_completed_or_draft_sheets_do_not_accept_invoice_changes(): void
    {
        $this->b->update(['status' => 'completed']);
        $this->actingAs($this->manager)->post(route('sheet-manager.invoices.move'), ['invoice_ids' => [$this->inv('ع G: 1')->id], 'sheet_id' => $this->b->id])
            ->assertSessionHasErrors('invoice');
        $this->actingAs($this->manager)->post(route('sheet-manager.invoices.place', $this->inv('ع G: 3')), ['sheet_id' => $this->b->id])
            ->assertSessionHasErrors('invoice');
        $this->assertSame(Invoice::UNASSIGNED, $this->inv('ع G: 3')->placement);
    }

    public function test_supervisor_and_others_cannot_edit_invoices(): void
    {
        foreach ([Role::Supervisor, Role::Accountant, Role::Distributor] as $role) {
            $user = User::factory()->role($role)->create();
            $this->actingAs($user)->post(route('sheet-manager.invoices.move'), ['invoice_ids' => [$this->inv('ع G: 1')->id], 'sheet_id' => $this->b->id])
                ->assertForbidden();
            $this->actingAs($user)->post(route('sheet-manager.invoices.exclude', $this->inv('ع G: 3')), ['reason' => 'محاولة'])
                ->assertForbidden();
        }
        $this->assertSame($this->a->id, $this->inv('ع G: 1')->distribution_sheet_id);
    }

    public function test_manager_sheet_page_lists_invoices_and_searches_to_add(): void
    {
        $this->actingAs($this->manager)->get(route('sheet-manager.sheets.show', ['sheet' => $this->a, 'q' => 'G: 3']))
            ->assertOk()
            ->assertSee('ع G: 1')
            ->assertSee('ع G: 3')
            ->assertSee('إضافة');
        $this->actingAs($this->manager)->get(route('invoices.unassigned'))->assertOk()->assertSee('ع G: 3')->assertSee('ضعها في كشف');
    }
}
