<?php

namespace App\Services\Import;

use RuntimeException;

/**
 * المرحلة 7G: الملف ينقصه عمود حرج (رقم أمر التسليم، تاريخ التسليم…) ولم يُؤكَّد بعد.
 * لم يُطبَّق شيء؛ الملفات محفوظة، و$pending فيه ما تحتاجه شاشة التأكيد لإكمال الرفع دون إعادة اختيار الملف.
 */
class ColumnsConfirmationRequired extends RuntimeException
{
    /**
     * @param  array{files: array, distribution_date: string, check_missing: bool, balances_as_of: ?string, file_hash: ?string,
     *     columns: array, present: array, text: string, estimate: array}  $pending
     */
    public function __construct(public readonly array $pending)
    {
        parent::__construct($pending['text']);
    }
}
