<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * قفل الصيدلية ليوم توزيع (عبر كل الكشوف): أول موزع يسجّل زيارة أو دفعة يقفلها.
 * user_id = صاحب القفل، وnull = مفكوك.
 */
class PharmacyLock extends Model
{
    protected $fillable = [
        'customer_id',
        'distribution_date',
        'user_id',
        'distribution_sheet_id',
        'locked_at',
        'released_at',
        'released_by',
    ];

    protected function casts(): array
    {
        return [
            'distribution_date' => 'date',
            'locked_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isHeld(): bool
    {
        return $this->user_id !== null;
    }
}
