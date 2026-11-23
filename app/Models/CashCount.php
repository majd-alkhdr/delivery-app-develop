<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مطابقة الصندوق: المبلغ المستلم فعلياً من موزع بعملة معيّنة في كشف (لا يمنع التصدير).
 */
class CashCount extends Model
{
    protected $fillable = ['distribution_sheet_id', 'distributor_id', 'currency', 'amount', 'note', 'entered_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'distributor_id');
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
