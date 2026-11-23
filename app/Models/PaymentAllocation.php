<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * لقطة توزيع دفعة تصديرية على فاتورة (إجمالي الفاتورة لحظة التصدير، الموزَّع، غير المسدد).
 */
class PaymentAllocation extends Model
{
    public const SOURCE_DISTRIBUTOR = 'distributor';

    public const SOURCE_REP = 'rep';

    protected $fillable = [
        'source', 'payment_id', 'finance_export_id', 'distribution_sheet_id', 'customer_id', 'invoice_id', 'invoice_key', 'invoice_number',
        'series', 'cost_center', 'allocation_group', 'invoice_total', 'remaining_before', 'allocated', 'unpaid', 'status',
    ];

    protected function casts(): array
    {
        return [
            'invoice_total' => 'decimal:2',
            'remaining_before' => 'decimal:2',
            'allocated' => 'decimal:2',
            'unpaid' => 'decimal:2',
        ];
    }

    public function export(): BelongsTo
    {
        return $this->belongsTo(FinanceExport::class, 'finance_export_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
