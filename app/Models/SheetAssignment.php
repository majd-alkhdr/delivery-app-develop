<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SheetAssignment extends Model
{
    /** مفتاح مجموعة الزبائن بلا منطقة */
    public const NO_AREA = '__none__';

    protected $fillable = ['sheet_id', 'distributor_id', 'area_key', 'assigned_by'];

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(DistributionSheet::class, 'sheet_id');
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'distributor_id');
    }
}
