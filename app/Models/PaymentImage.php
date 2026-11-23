<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صورة إيصال دفعة المندوب (واحدة لكل دفعة، في تخزين خاص).
 */
class PaymentImage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['payment_id', 'path', 'sha256', 'size_bytes', 'mime', 'width', 'height', 'uploaded_by', 'created_at'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'width' => 'integer', 'height' => 'integer', 'created_at' => 'datetime'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
