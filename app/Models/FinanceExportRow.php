<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سطر من ملفات التصدير كما صُدّر (allocation/surplus للملف الرباعي، receipt لكشف المقبوضات والدولار).
 */
class FinanceExportRow extends Model
{
    protected $fillable = [
        'finance_export_id', 'row_type', 'sort', 'customer_id', 'customer_name', 'statement', 'cost_center',
        'amount', 'payment_id', 'rep_user_id', 'currency', 'amount_original', 'exchange_rate', 'receipt_number',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_original' => 'decimal:2',
            'exchange_rate' => 'decimal:4',
        ];
    }

    public function export(): BelongsTo
    {
        return $this->belongsTo(FinanceExport::class, 'finance_export_id');
    }
}
