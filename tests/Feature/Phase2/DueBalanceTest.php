<?php

namespace Tests\Feature\Phase2;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\User;
use App\Services\CustomerBalanceService;
use App\Support\InvoiceFingerprint;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الرصيد المستحق (G.1 في المرحلة 2B): الرصيد الخام − كل فواتير الزبون التي تسليمها بعد التاريخ،
 * في أي مكان كانت (مؤجلة أو في كشف بأي حالة) عدا الملغاة، وبلا تكرار.
 */
class DueBalanceTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;
    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supervisor = User::factory()->role(Role::Supervisor)->create();
        $this->customer = Customer::create(['name' => 'صيدلية الاختبار', 'area' => 'منطقة', 'balance' => 1000]);
    }

    private function sheet(string $status = 'pending', string $date = '2026-10-13'): DistributionSheet
    {
        return DistributionSheet::create([
            'format' => 'v2', 'file_name' => 'x.xlsx', 'status' => $status,
            'supervisor_id' => $this->supervisor->id, 'distribution_date' => $date,
        ]);
    }

    private function invoice(?DistributionSheet $sheet, string $number, float $amount, string $delivery, ?string $placement = null, bool $withKey = true): Invoice
    {
        $placement ??= $sheet ? Invoice::IN_SHEET : Invoice::DEFERRED;

        return Invoice::create([
            'distribution_sheet_id' => $sheet?->id, 'invoice_number' => $number, 'customer_name' => $this->customer->name,
            'customer_id' => $this->customer->id, 'invoice_amount' => $amount, 'delivery_date' => $delivery,
            'placement' => $placement,
            'invoice_key' => $withKey ? InvoiceFingerprint::keyFromNumber($number) : null,
        ]);
    }

    private function due(string $asOf = '2026-10-13'): float
    {
        return app(CustomerBalanceService::class)->dueBalance($this->customer->fresh(), Carbon::parse($asOf));
    }

    public function test_deferred_invoice_is_subtracted_from_raw_balance(): void
    {
        $this->invoice(null, 'ع G: 1', 200, '2026-10-14');                 // مؤجلة بلا كشف
        $this->invoice($this->sheet(), 'ع G: 2', 300, '2026-10-13');       // تُسلَّم يوم التوزيع: ليست مؤجلة

        $this->assertSame(800.0, $this->due());
    }

    public function test_deferred_in_any_state_is_subtracted_cancelled_is_not_and_never_twice(): void
    {
        $this->invoice(null, 'ع A: 9', 150, '2026-10-20');                              // مؤجلة
        $this->invoice($this->sheet('draft'), 'ع B: 5', 100, '2026-10-30');            // في مسودة: تُطرح أيضاً
        $this->invoice(null, 'ع C: 7', 999, '2026-10-30', Invoice::CANCELLED);          // ملغاة: لا تُطرح
        $this->invoice(null, 'ع D: 3', 50, '2026-10-15', Invoice::UNASSIGNED);          // بلا كشف: تُطرح

        // فاتورة من المرحلة 2 مكررة بلا مفتاح (بعد الترحيل): لا تُطرح مرتين
        $this->invoice($this->sheet('in_progress', '2026-10-10'), 'ع A: 9', 150, '2026-10-20', Invoice::IN_SHEET, false);

        $this->assertSame(700.0, $this->due());                 // 1000 − 150 − 100 − 50
        $this->assertSame(900.0, $this->due('2026-10-20'));     // بعد 20: فقط ع B: 5
    }

    public function test_negative_due_is_not_clamped(): void
    {
        $this->customer->update(['balance' => 100]);
        $this->invoice(null, 'ع G: 1', 250, '2026-10-15');

        $this->assertSame(-150.0, $this->due());
    }

    public function test_is_deferred_always_follows_placement(): void
    {
        $invoice = $this->invoice(null, 'ع G: 1', 250, '2026-10-15');
        $this->assertTrue($invoice->is_deferred);

        $invoice->update(['placement' => Invoice::UNASSIGNED]);
        $this->assertFalse($invoice->fresh()->is_deferred);
    }
}
