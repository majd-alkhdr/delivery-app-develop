<?php

namespace App\Services;

use App\Models\DistributionSheet;
use App\Models\Payment;
use App\Models\SheetVisit;

/**
 * حارس النشاط (المرحلة 3): نشاط زبون في كشف = زيارة غير ملغاة في sheet_visits أو دفعة غير ملغاة في payments.
 * لم يعد يعتمد على أعمدة الزيارة والتحصيل القديمة على invoices.
 * يمنع نقل فواتير زبون عليه نشاط، وإغلاق كشف عليه نشاط كمسودة (لغير الأدمن).
 */
class SheetActivityGuard
{
    public function customerHasActivity(?DistributionSheet $sheet, ?int $customerId, ?string $customerName = null): bool
    {
        if (! $sheet || ! $customerId) {
            return false;
        }

        return SheetVisit::active()->where('distribution_sheet_id', $sheet->id)->where('customer_id', $customerId)->exists()
            || Payment::active()->where('distribution_sheet_id', $sheet->id)->where('customer_id', $customerId)->exists();
    }

    /**
     * المرحلة 7F: الشرط نفسه (زيارة أو دفعة غير ملغاة للزبون في الكشف) لمجموعة أزواج دفعة واحدة، بدل استعلام لكل فاتورة.
     *
     * @param  array<int, array{0: int, 1: int}>  $pairs  [sheet_id, customer_id]
     * @return array<string, true> مفاتيح "sheet_id:customer_id" التي عليها نشاط
     */
    public function pairsWithActivity(array $pairs): array
    {
        $sheetIds = array_values(array_unique(array_map(fn ($p) => (int) $p[0], $pairs)));
        $wanted = array_fill_keys(array_map(fn ($p) => (int) $p[0].':'.(int) $p[1], $pairs), true);
        $found = [];
        foreach (array_chunk($sheetIds, \App\Support\BulkWrite::CHUNK) as $chunk) {
            $rows = SheetVisit::active()->whereIn('distribution_sheet_id', $chunk)->get(['distribution_sheet_id', 'customer_id'])
                ->concat(Payment::active()->whereIn('distribution_sheet_id', $chunk)->get(['distribution_sheet_id', 'customer_id']));
            foreach ($rows as $row) {
                $key = (int) $row->distribution_sheet_id.':'.(int) $row->customer_id;
                if (isset($wanted[$key])) {
                    $found[$key] = true;
                }
            }
        }

        return $found;
    }

    public function sheetHasActivity(DistributionSheet $sheet): bool
    {
        return SheetVisit::active()->where('distribution_sheet_id', $sheet->id)->exists()
            || Payment::active()->where('distribution_sheet_id', $sheet->id)->exists();
    }
}
