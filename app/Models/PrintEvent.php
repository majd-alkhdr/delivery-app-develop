<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حدث طباعة (إيصال، فاتورة، فاتورة مجمّعة). المعاينة تُسجَّل لكنها لا تُحسب طباعة.
 */
class PrintEvent extends Model
{
    public const UPDATED_AT = null;

    public const KINDS = ['receipt', 'invoice', 'merged'];

    public const MODES = ['ble', 'browser', 'image', 'rawbt', 'preview'];

    protected $fillable = [
        'client_uuid', 'user_id', 'kind', 'payment_id', 'invoice_id', 'distribution_sheet_id', 'customer_id',
        'mode', 'status', 'error', 'device_label', 'printed_at',
    ];

    protected function casts(): array
    {
        return ['printed_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * طباعة فعلية ناجحة (ليست معاينة ولا فشلاً) — هي التي تجعل النسخة التالية «مكررة».
     */
    /**
     * طُبعت هذه الفاتورة سابقاً للصيدلية؟ (فاتورة فردية، أو فاتورة مجمّعة للزبون في كشفها) — المرحلة 6B-D5
     */
    public static function invoicePrinted(Invoice $invoice): bool
    {
        return static::counted()->where(function ($q) use ($invoice) {
            $q->where(fn ($i) => $i->where('kind', 'invoice')->where('invoice_id', $invoice->id));
            if ($invoice->distribution_sheet_id && $invoice->customer_id) {
                $q->orWhere(fn ($m) => $m->where('kind', 'merged')->where('distribution_sheet_id', $invoice->distribution_sheet_id)->where('customer_id', $invoice->customer_id));
            }
        })->exists();
    }

    public function scopeCounted(Builder $query): Builder
    {
        return $query->where('status', 'success')->where('mode', '!=', 'preview');
    }
}
