<?php

namespace Tests\Support;

use App\Enums\Role;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\SheetVisit;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * أدوات الاختبار للنشاط الميداني (المرحلة 3): زيارات ودفعات في sheet_visits وpayments.
 */
trait FieldActivity
{
    /**
     * نشاط مباشر على زبون فاتورة في كشفها (زيارة فعّالة).
     */
    protected function markActivity(Invoice $invoice, ?User $user = null): SheetVisit
    {
        $user ??= User::factory()->role(Role::Distributor)->create();

        return SheetVisit::create([
            'client_uuid' => (string) Str::uuid(),
            'distribution_sheet_id' => $invoice->distribution_sheet_id,
            'customer_id' => $invoice->customer_id,
            'user_id' => $user->id,
            'status' => 'visited',
            'visited_at' => now(),
            'location_flag' => 'no_gps',
        ]);
    }

    protected function payApi(User $user, DistributionSheet $sheet, int $customerId, float $amount, array $extra = []): TestResponse
    {
        return $this->actingAs($user)->postJson(route('distributor.payments.store', $sheet), $extra + [
            'client_uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'amount' => $amount,
            'currency' => 'SYP',
        ]);
    }

    protected function visitApi(User $user, DistributionSheet $sheet, int $customerId, array $extra = []): TestResponse
    {
        return $this->actingAs($user)->postJson(route('distributor.visits.store', $sheet), $extra + [
            'client_uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'status' => 'visited',
        ]);
    }
}
