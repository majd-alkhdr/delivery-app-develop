<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\SheetAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * نطاق الموزع: أي زبائن يصل إليهم، وأي كشف تُربط به دفعته الخارجية.
 * ما يخص فواتير كشف يمر دائماً عبر DistributionSheet::invoicesVisibleTo().
 */
class DistributorScope
{
    /**
     * هل للزبون فواتير يراها الموزع في هذا الكشف (مناطقه، غير مؤجلة، in_sheet)؟
     */
    public function customerVisibleInSheet(DistributionSheet $sheet, User $user, int $customerId): bool
    {
        return $sheet->invoicesVisibleTo($user)->where('customer_id', $customerId)->exists();
    }

    /**
     * الكشوف المفتوحة (pending أو in_progress) للموزع.
     */
    public function openSheets(User $user): Builder
    {
        return DistributionSheet::query()
            ->where('format', 'v2')
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereHas('assignments', fn (Builder $q) => $q->where('distributor_id', $user->id));
    }

    /**
     * كشف الدفعة الخارجية: كشف مفتوح للموزع يغطي إسناده منطقة الزبون (أو كل المناطق)، والأحدث رفعاً.
     */
    public function openSheetFor(User $user, Customer $customer): ?DistributionSheet
    {
        $areaKey = $customer->areaKey();

        return $this->openSheets($user)
            ->whereHas('assignments', fn (Builder $q) => $q->where('distributor_id', $user->id)
                ->where(fn (Builder $w) => $w->whereNull('area_key')->orWhere('area_key', $areaKey)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * مناطق تبويب العملاء: اتحاد مناطق إسناداته في الكشوف المفتوحة التي تاريخ توزيعها اليوم.
     *
     * @return array<int, string>|null null = كل المناطق
     */
    public function todayAreaKeys(User $user): ?array
    {
        $today = now('Asia/Damascus')->toDateString();

        $keys = SheetAssignment::query()
            ->where('distributor_id', $user->id)
            ->whereHas('sheet', fn (Builder $q) => $q->whereIn('status', ['pending', 'in_progress'])->whereDate('distribution_date', $today))
            ->pluck('area_key');

        if ($keys->contains(fn ($k) => $k === null)) {
            return null;
        }

        return $keys->unique()->values()->all();
    }

    /**
     * زبائن تبويب العملاء (غير المخفيين) ضمن مناطق اليوم.
     */
    public function customersQuery(User $user): Builder
    {
        $keys = $this->todayAreaKeys($user);
        $query = Customer::query()->where('is_hidden', false);

        if ($keys === null) {
            return $query;
        }
        if ($keys === []) {
            return $query->whereRaw('1 = 0');
        }

        $named = array_values(array_diff($keys, [SheetAssignment::NO_AREA]));

        return $query->where(function (Builder $q) use ($keys, $named) {
            $q->whereIn('area_normalized', $named ?: ['__no_access__']);
            if (in_array(SheetAssignment::NO_AREA, $keys, true)) {
                $q->orWhereNull('area_normalized');
            }
        });
    }
}
