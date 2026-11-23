<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * دفعة رفع (ملف أو عدة ملفات). تحفظ تقرير الرفع حتى تبقى شاشة المعاينة قابلة للفتح لاحقاً.
 * المعرّف uuid وهو نفسه distribution_sheets.batch_id.
 */
class ImportBatch extends Model
{
    // المرحلة 7F: حالة الدفعة
    public const APPLIED = 'applied';
    public const ROLLED_BACK = 'rolled_back';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'distribution_date',
        'file_names',
        'stats',
        'warnings',
        'conflicts_acknowledged_by',
        'conflicts_acknowledged_at',
        'status',
        'rolled_back_at',
        'rolled_back_by',
        'rollback_reason',
        'file_hash',
    ];

    protected function casts(): array
    {
        return [
            'distribution_date' => 'date',
            'file_names' => 'array',
            'stats' => 'array',
            'warnings' => 'array',
            'conflicts_acknowledged_at' => 'datetime',
            'rolled_back_at' => 'datetime',
        ];
    }

    public function isRolledBack(): bool
    {
        return $this->status === self::ROLLED_BACK;
    }

    public function rolledBackBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rolled_back_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conflicts_acknowledged_by');
    }

    public function sheets(): HasMany
    {
        return $this->hasMany(DistributionSheet::class, 'batch_id');
    }

    public function conflictsAcknowledged(): bool
    {
        return $this->conflicts_acknowledged_at !== null;
    }

    public function stat(string $key, mixed $default = null): mixed
    {
        return data_get($this->stats, $key, $default);
    }

    /**
     * المرحلة 7G: أحدث دفعة سابقة رُفع فيها الملف نفسه (بالبصمة)، متراجَعاً عنها أو لا. الدفعات القديمة بلا بصمة لا تُطابَق.
     */
    public static function previousWithHash(?string $hash, ?self $before = null): ?self
    {
        if ($hash === null || $hash === '') {
            return null;
        }

        return static::with('user:id,name')->where('file_hash', $hash)
            ->when($before, fn ($q) => $q->whereKeyNot($before->getKey())->where('created_at', '<=', $before->created_at))
            ->latest('created_at')->first();
    }
}
