<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\PharmacyLock;
use App\Models\User;
use Illuminate\Database\QueryException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * قفل الصيدلية على مستوى (الزبون + يوم التوزيع) عبر كل الكشوف:
 * أول موزع يسجّل زيارة أو دفعة يقفلها، والباقون يُمنعون (على الخادم، داخل transaction مع lockForUpdate).
 */
class PharmacyLockService
{
    /**
     * يجب أن يُستدعى داخل DB::transaction.
     *
     * @throws HttpException 423 إن كانت مقفلة لموزع آخر
     */
    public function acquire(Customer $customer, string $date, User $user, ?int $sheetId): PharmacyLock
    {
        $lock = $this->find($customer->id, $date);

        if (! $lock) {
            try {
                return PharmacyLock::create([
                    'customer_id' => $customer->id,
                    'distribution_date' => $date,
                    'user_id' => $user->id,
                    'distribution_sheet_id' => $sheetId,
                    'locked_at' => now(),
                ]);
            } catch (QueryException $e) {
                // سباق: أنشأه طلب آخر للتو
                $lock = $this->find($customer->id, $date);
                if (! $lock) {
                    throw $e;
                }
            }
        }

        if ($lock->user_id === null) {
            $lock->update(['user_id' => $user->id, 'distribution_sheet_id' => $sheetId, 'locked_at' => now()]);

            return $lock;
        }

        if ((int) $lock->user_id !== (int) $user->id) {
            $holder = User::find($lock->user_id)?->name ?? 'موزع آخر';
            throw new HttpException(423, "تمت زيارة هذه الصيدلية اليوم من قِبل {$holder}، فلا يمكنك التسجيل عليها.");
        }

        return $lock;
    }

    /**
     * صاحب القفل الحالي (إن وُجد) لزبون في يوم.
     */
    public function holder(int $customerId, string $date): ?PharmacyLock
    {
        return PharmacyLock::with('holder')
            ->where('customer_id', $customerId)
            ->whereDate('distribution_date', $date)
            ->whereNotNull('user_id')
            ->first();
    }

    /**
     * فك القفل (للمشرف والأدمن).
     */
    public function release(PharmacyLock $lock, User $by): void
    {
        $old = ['user_id' => $lock->user_id, 'holder' => $lock->holder?->name];
        $lock->update(['user_id' => null, 'released_at' => now(), 'released_by' => $by->id]);

        ActivityLog::record('pharmacy.unlocked', $lock, $old, ['user_id' => null], [
            'customer_id' => $lock->customer_id,
            'customer' => $lock->customer?->name,
            'distribution_date' => $lock->distribution_date?->toDateString(),
            'sheet_id' => $lock->distribution_sheet_id,
        ]);
    }

    private function find(int $customerId, string $date): ?PharmacyLock
    {
        return PharmacyLock::where('customer_id', $customerId)
            ->whereDate('distribution_date', $date)
            ->lockForUpdate()
            ->first();
    }
}
