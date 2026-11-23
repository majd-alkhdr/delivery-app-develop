<?php

namespace Tests\Feature\Phase1;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SheetAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ملكية الكشف (المرحلة 1)، محدَّثة للمرحلة 3: الدفعات في جدول payments، وكشوف v1 للقراءة فقط.
 */
class SheetOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $other;
    private DistributionSheet $sheet;
    private Customer $customer;
    private Invoice $first;

    protected function setUp(): void
    {
        parent::setUp();

        $supervisor = User::factory()->role(Role::Supervisor)->create();
        $this->owner = User::factory()->role(Role::Distributor)->create();
        $this->other = User::factory()->role(Role::Distributor)->create();
        $this->customer = Customer::create(['name' => 'صيدلية الاختبار', 'area' => 'منطقة', 'balance' => 2000]);

        $this->sheet = DistributionSheet::create([
            'format' => 'v2',
            'distributor_id' => $this->owner->id,
            'supervisor_id' => $supervisor->id,
            'file_name' => 'test.xlsx',
            'status' => 'pending',
            'delivery_order_no' => '100',
            'distribution_date' => now('Asia/Damascus')->toDateString(),
        ]);
        $this->assign($this->sheet, $this->owner);

        $base = [
            'distribution_sheet_id' => $this->sheet->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'صيدلية الاختبار',
            'area_key' => $this->customer->areaKey(),
            'invoice_amount' => 1000,
            'customer_total_invoices' => 2000,
            'final_balance' => 2000,
        ];
        $this->first = Invoice::create($base + ['invoice_number' => 'ع G: 1', 'invoice_key' => 'g:1']);
        Invoice::create($base + ['invoice_number' => 'ع G: 2', 'invoice_key' => 'g:2']);
    }

    private function pay(User $user, array $extra = [])
    {
        return $this->actingAs($user)->postJson(route('distributor.payments.store', $this->sheet), $extra + [
            'client_uuid' => (string) Str::uuid(),
            'customer_id' => $this->customer->id,
            'amount' => 700,
            'currency' => 'SYP',
        ]);
    }

    public function test_distributor_cannot_open_another_distributors_sheet(): void
    {
        $this->actingAs($this->other)->get(route('distributor.sheet', $this->sheet->id))->assertForbidden();
        $this->actingAs($this->owner)->get(route('distributor.sheet', $this->sheet->id))->assertOk();
    }

    public function test_distributor_cannot_record_payment_on_another_distributors_sheet(): void
    {
        $this->pay($this->other)->assertForbidden();
        $this->actingAs($this->other)->postJson(route('distributor.visits.store', $this->sheet), [
            'client_uuid' => (string) Str::uuid(), 'customer_id' => $this->customer->id, 'status' => 'visited',
        ])->assertForbidden();

        $this->assertSame(0, Payment::count());
        $this->assertSame('pending', $this->sheet->fresh()->status);
    }

    public function test_payment_is_stored_in_payments_without_touching_invoices(): void
    {
        $this->pay($this->owner)->assertCreated()->assertJsonPath('payment.amount', 700);

        $this->assertSame(1, Payment::count());
        $this->assertEquals(0, (float) $this->first->fresh()->collected_amount);   // لا كتابة على أول فاتورة
        $this->assertNull($this->first->fresh()->visited_at);
    }

    public function test_first_payment_moves_sheet_to_in_progress_and_is_logged(): void
    {
        $this->pay($this->owner)->assertCreated();

        $this->assertSame('in_progress', $this->sheet->fresh()->status);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'payment.created',
            'user_id' => $this->owner->id,
            'subject_id' => Payment::first()->id,
        ]);
    }

    public function test_sheet_details_render_for_management_roles(): void
    {
        foreach ([Role::Admin, Role::Supervisor] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get(route('supervisor.sheet.show', $this->sheet->id))
                ->assertOk()->assertSee('صيدلية الاختبار');
        }

        $this->actingAs(User::factory()->role(Role::Accountant)->create())
            ->get(route('accountant.sheet.show', $this->sheet->id))
            ->assertOk()->assertSee(route('accountant.index'));
    }

    public function test_paid_pharmacy_shows_stamp_reminder(): void
    {
        $this->pay($this->owner)->assertCreated();

        $this->actingAs($this->owner)->get(route('distributor.sheet', $this->sheet->id))
            ->assertOk()
            ->assertSee('تذكير: اختم الإيصال')
            ->assertSee('inputmode="numeric"', false)
            ->assertDontSee('Sadana');
    }

    public function test_completed_sheet_rejects_payments(): void
    {
        $this->sheet->update(['status' => 'completed']);

        $this->pay($this->owner)->assertForbidden();
        $this->assertSame(0, Payment::count());
    }

    public function test_v1_sheets_are_read_only(): void
    {
        $v1 = DistributionSheet::create(['distributor_id' => $this->owner->id, 'supervisor_id' => $this->sheet->supervisor_id, 'file_name' => 'old.xlsx', 'status' => 'pending']);
        $this->assign($v1, $this->owner);
        Invoice::create(['distribution_sheet_id' => $v1->id, 'invoice_number' => 'OLD-1', 'customer_name' => 'زبون قديم', 'invoice_amount' => 50]);

        $this->actingAs($this->owner)->get(route('distributor.sheet', $v1->id))->assertOk()->assertSee('للعرض فقط')->assertDontSee('data-open-payment', false);
        $this->actingAs($this->owner)->postJson(route('distributor.payments.store', $v1), [
            'client_uuid' => (string) Str::uuid(), 'customer_id' => $this->customer->id, 'amount' => 5, 'currency' => 'SYP',
        ])->assertForbidden();
    }

    public function test_distributor_index_lists_only_own_sheets_latest_first(): void
    {
        $newer = DistributionSheet::create([
            'distributor_id' => $this->owner->id,
            'supervisor_id' => $this->sheet->supervisor_id,
            'file_name' => 'newer.xlsx',
            'status' => 'pending',
        ]);
        $newer->forceFill(['created_at' => now()->addHour()])->save();
        $this->assign($newer, $this->owner);

        $notMine = DistributionSheet::create([
            'distributor_id' => $this->other->id,
            'supervisor_id' => $this->sheet->supervisor_id,
            'file_name' => 'not-mine.xlsx',
            'status' => 'pending',
        ]);
        $this->assign($notMine, $this->other);

        $this->actingAs($this->owner)->get(route('distributor.index'))
            ->assertOk()
            ->assertSeeInOrder(['newer.xlsx', 'أمر التسليم #100'])
            ->assertDontSee('not-mine.xlsx');
    }

    private function assign(DistributionSheet $sheet, User $distributor): void
    {
        SheetAssignment::create(['sheet_id' => $sheet->id, 'distributor_id' => $distributor->id, 'area_key' => null]);
    }
}
