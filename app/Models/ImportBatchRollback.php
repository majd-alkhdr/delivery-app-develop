<?php

namespace App\Models;

use App\Support\CompressedJson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * المرحلة 7F: سجل التراجع عن دفعة رفع، مع نسخة تدقيق مضغوطة لما حُذف وما أُعيد.
 */
class ImportBatchRollback extends Model
{
    protected $fillable = ['batch_id', 'user_id', 'reason', 'counts', 'audit'];

    protected function casts(): array
    {
        return ['counts' => 'array'];
    }

    public function auditData(): ?array
    {
        return CompressedJson::decode($this->audit);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
