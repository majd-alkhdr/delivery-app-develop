<?php

namespace App\Services;

use App\Models\DistributionSheet;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * كشوف مفتوحة منذ أكثر من يوم (المرحلة 5-H3): pending/in_progress وتاريخ توزيعها قبل اليوم.
 * تنبيه للمشرف والمحاسب والأدمن، لأن الدفعة الخارجية قد تُربط بكشف قديم نُسي مفتوحاً.
 */
class StaleSheetsService
{
    /**
     * @return Collection<int, array{sheet: DistributionSheet, days_late: int, payments: int}>
     */
    public function list(): Collection
    {
        $today = now('Asia/Damascus')->startOfDay();

        return DistributionSheet::query()
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereNotNull('distribution_date')
            ->whereDate('distribution_date', '<', $today->toDateString())
            ->withCount(['payments as active_payments_count' => fn ($q) => $q->whereNull('voided_at')])
            ->with('assignments.distributor:id,name')
            ->orderBy('distribution_date')
            ->get()
            ->map(fn (DistributionSheet $sheet) => [
                'sheet' => $sheet,
                'days_late' => (int) Carbon::parse($sheet->distribution_date->toDateString(), 'Asia/Damascus')->diffInDays($today),
                'payments' => (int) $sheet->active_payments_count,
            ]);
    }
}
