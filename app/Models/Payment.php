<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * دفعة محصّلة (من كشف أو خارجية). لا حذف نهائي: الإلغاء = void مع سبب.
 */
class Payment extends Model
{
    public const SOURCES = ['sheet', 'external', 'rep'];

    public const CURRENCIES = ['SYP', 'USD'];

    protected $fillable = [
        'client_uuid',
        'receipt_number',
        'distribution_sheet_id',
        'customer_id',
        'entered_by',
        'entered_role',
        'source',
        'amount',
        'currency',
        'exchange_rate',
        'amount_syp',
        'rate_is_estimate',
        'note',
        'status',
        'receipt_image_path',
        'latitude',
        'longitude',
        'distance_m',
        'location_flag',
        'collected_at',
        'voided_at',
        'void_reason',
        'voided_by',
        'exported_at',
        'finance_export_id',
        // المرحلة 6: دفعة المندوب
        'book_receipt_no',
        'duplicate_book_no',
        'exceeds_known_debt',
        'cost_center_code',
        'cost_center_label',
        'review_status',
        'review_note',
        'reviewed_by',
        'reviewed_at',
        // المرحلة 6B: دفعة دون اتصال لصيدلية أُخرجت من الكشف لاحقاً
        'outside_sheet',
        // المرحلة 7H: سُجّل دون اتصال قبل اطلاع الموزع على تعديل
        'recorded_before_ack',
        'ack_context',
        // المرحلة 7B: الانعكاس في الأرصدة والرصيد المخزَّن للإيصال
        'reflected_at',
        'reflected_snapshot_id',
        'balance_before',
        'balance_after',
        'balance_as_of',
        'balance_snapshot_id',
        'balance_source',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'exchange_rate' => 'decimal:4',
            'amount_syp' => 'decimal:2',
            'rate_is_estimate' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'distance_m' => 'integer',
            'collected_at' => 'datetime',
            'voided_at' => 'datetime',
            'exported_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'duplicate_book_no' => 'boolean',
            'exceeds_known_debt' => 'boolean',
            'outside_sheet' => 'boolean',
            'recorded_before_ack' => 'boolean',
            'ack_context' => 'array',
            'reflected_at' => 'datetime',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'balance_as_of' => 'date',
        ];
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(DistributionSheet::class, 'distribution_sheet_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function image(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PaymentImage::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isRep(): bool
    {
        return $this->source === 'rep';
    }

    public function financeExport(): BelongsTo
    {
        return $this->belongsTo(FinanceExport::class);
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function isExported(): bool
    {
        return $this->status !== 'pending' || $this->exported_at !== null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }
}
