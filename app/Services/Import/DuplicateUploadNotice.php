<?php

namespace App\Services\Import;

use App\Models\ImportBatch;

/**
 * المرحلة 7G: «هذا الملف نفسه رُفع سابقاً» (معلومات بلا منع ولا تأكيد؛ إعادة الرفع بتاريخ آخر قد تكون مقصودة).
 */
class DuplicateUploadNotice
{
    /**
     * @param  ?ImportBatch  $current  الدفعة الحالية (صفحة الدفعة)، أو null قبل التطبيق (شاشة التأكيد)
     * @return array{batch: ImportBatch, text: string, reclassify: ?string, rolled_back: bool}|null
     */
    public static function for(?string $hash, string $distributionDate, ?ImportBatch $current = null): ?array
    {
        $previous = ImportBatch::previousWithHash($hash, $current);
        if (! $previous) {
            return null;
        }

        $previousDate = $previous->distribution_date?->toDateString();
        $text = sprintf('هذا الملف نفسه رُفع سابقاً في الدفعة %s بتاريخ %s (بالمستخدم %s)%s. تاريخ التوزيع المختار الآن %s مقابل %s في الدفعة السابقة.',
            substr($previous->id, 0, 8),
            $previous->created_at->format('Y-m-d g:i A'),
            $previous->user?->name ?? '—',
            $previous->isRolledBack() ? '، وقد تُراجِع عنها' : '',
            $distributionDate,
            $previousDate ?? '—');

        $reclassify = $previousDate !== null && $previousDate !== $distributionDate
            ? 'اختلاف تاريخ التوزيع يعيد تصنيف الفواتير نفسها بين «بلا كشف» و«المؤجلة» (الفاتورة مؤجلة إن كان تسليمها بعد تاريخ التوزيع)، كما حدث في رفع «تجربة تطبيق الموزعين الجديد» مرتين بتاريخين مختلفين.'
            : null;

        return ['batch' => $previous, 'text' => $text, 'reclassify' => $reclassify, 'rolled_back' => $previous->isRolledBack()];
    }
}
