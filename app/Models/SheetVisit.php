<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * زيارة صيدلية في كشف (سجل واحد لكل كشف وزبون). بديل أعمدة الزيارة القديمة على invoices.
 */
class SheetVisit extends Model
{
    /** أسباب عدم الزيارة (و«أخرى» تتطلب نصاً) */
    public const REASONS = ['الصيدلية مغلقة', 'الصيدلي غير موجود', 'رفض الاستلام', 'لا يوجد مبلغ للتحصيل', 'ضيق الوقت', 'أخرى'];

    protected $fillable = [
        'client_uuid',
        'distribution_sheet_id',
        'customer_id',
        'user_id',
        'status',
        'reason',
        'visited_at',
        'latitude',
        'longitude',
        'distance_m',
        'location_flag',
        'voided_at',
        'outside_sheet',
        // المرحلة 7H: سُجّل دون اتصال قبل اطلاع الموزع على تعديل
        'recorded_before_ack',
        'ack_context',
    ];

    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
            'voided_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'distance_m' => 'integer',
            'outside_sheet' => 'boolean',
            'recorded_before_ack' => 'boolean',
            'ack_context' => 'array',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }
}
