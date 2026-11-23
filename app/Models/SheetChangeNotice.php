<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * إشعار تعديل على كشف منشور (المرحلة 6B-D): نص عربي مختصر لحظة الحدث بلا مبالغ.
 */
class SheetChangeNotice extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = ['added', 'removed', 'moved_in', 'moved_out', 'updated', 'area_changed', 'balance_changed'];

    protected $fillable = ['distribution_sheet_id', 'invoice_id', 'customer_id', 'type', 'area_key', 'summary', 'created_by', 'created_at', 'meta'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'meta' => 'array'];
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(DistributionSheet::class, 'distribution_sheet_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** المرحلة 7H: اطلاع الموزعين على هذا الإشعار */
    public function acks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SheetNoticeAck::class, 'notice_id');
    }
}
