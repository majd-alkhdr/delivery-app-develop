<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\DistributionSheet;
use App\Models\SheetAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * إسناد مناطق الكشف للموزعين.
 * القواعد: كل منطقة لموزع واحد فقط في الكشف نفسه، و"كل المناطق" (area_key = null)
 * لا تُجمع مع مناطق أخرى. الزيارات والدفعات المسجلة لا تتأثر بإعادة الإسناد.
 */
class SheetAssignmentService
{
    /**
     * @param  array<int, array{area_key: ?string, distributor_id: mixed}>  $rows  صفوف (منطقة، موزع)
     * @param  int|null  $allDistributorId  موزع واحد لكل المناطق
     */
    public function sync(DistributionSheet $sheet, array $rows, ?int $allDistributorId, User $by): void
    {
        if ($sheet->isDraft()) {
            throw ValidationException::withMessages(['assignments' => 'الكشف مسودة ولا يقبل أي تعديل.']);
        }

        $rows = array_values(array_filter($rows, fn ($r) => ! empty($r['distributor_id'])));

        if ($allDistributorId && $rows) {
            throw ValidationException::withMessages(['assignments' => 'لا يمكن الجمع بين إسناد "كل المناطق" وإسناد مناطق محددة في الكشف نفسه.']);
        }

        $new = []; // area_key ('' = كل المناطق) => distributor_id
        if ($allDistributorId) {
            $new[''] = $allDistributorId;
        }

        $validKeys = array_merge($sheet->areaKeys(), [SheetAssignment::NO_AREA]);
        foreach ($rows as $row) {
            $key = (string) ($row['area_key'] ?? '');
            if ($key === '' || ! in_array($key, $validKeys, true)) {
                throw ValidationException::withMessages(['assignments' => "المنطقة \"{$key}\" غير موجودة في هذا الكشف."]);
            }
            if (array_key_exists($key, $new)) {
                throw ValidationException::withMessages(['assignments' => 'لا يمكن إسناد المنطقة نفسها لأكثر من موزع في الكشف نفسه.']);
            }
            $new[$key] = (int) $row['distributor_id'];
        }

        $distributorIds = array_unique(array_values($new));
        $validCount = User::whereIn('id', $distributorIds)->where('role', Role::Distributor)->count();
        if ($validCount !== count($distributorIds)) {
            throw ValidationException::withMessages(['assignments' => 'أحد الموزعين المختارين غير موجود أو ليس موزعاً.']);
        }

        $old = $sheet->assignments()->get()
            ->mapWithKeys(fn ($a) => [(string) $a->area_key => (int) $a->distributor_id])
            ->all();

        ksort($old);
        ksort($new);
        if ($old === $new) {
            return;
        }

        DB::transaction(function () use ($sheet, $new, $by) {
            $sheet->assignments()->delete();
            foreach ($new as $key => $distributorId) {
                SheetAssignment::create([
                    'sheet_id' => $sheet->id,
                    'distributor_id' => $distributorId,
                    'area_key' => $key === '' ? null : $key,
                    'assigned_by' => $by->id,
                ]);
            }

            // الموزع "الرئيسي" للعرض فقط: عند وجود موزع واحد
            $distinct = array_unique(array_values($new));
            $sheet->update(['distributor_id' => count($distinct) === 1 ? reset($distinct) : null]);
        });

        $label = fn (array $map) => collect($map)->mapWithKeys(fn ($id, $key) => [
            $key === '' ? 'كل المناطق' : ($key === SheetAssignment::NO_AREA ? 'بلا منطقة' : $key) => $id,
        ])->all();

        ActivityLog::record(
            $old ? 'sheet.reassigned' : 'sheet.assigned',
            $sheet,
            $old ? ['assignments' => $label($old)] : [],
            ['assignments' => $label($new)]
        );
    }
}
