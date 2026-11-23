<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * المرحلة 7H: «اطلعت» على إشعار تعديل بعينه (من، متى) — صف لكل (إشعار، مستخدم).
 */
class SheetNoticeAck extends Model
{
    public $timestamps = false;

    protected $fillable = ['notice_id', 'distribution_sheet_id', 'user_id', 'acked_at'];

    protected function casts(): array
    {
        return ['acked_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
