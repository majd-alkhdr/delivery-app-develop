<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BalanceImport extends Model
{
    // المرحلة 7B: حالة تطبيق ملف الأرصدة
    public const APPLIED = 'applied';
    public const HELD = 'held';
    public const IGNORED = 'ignored';
    // المرحلة 7F: ملف أرصدة دفعة تُرُوجع عنها (لا يُحتسب في حارسي التاريخ ولا في «آخر رصيد معتمد»)
    public const ROLLED_BACK = 'rolled_back';

    public const HOLD_OLDER = 'older';
    public const HOLD_NO_DATE = 'no_date';
    public const HOLD_ZEROING = 'zeroing';

    protected $fillable = [
        'user_id', 'file_name', 'as_of', 'rows_count', 'total_debit',
        'batch_id', 'stored_path', 'status', 'hold_reason', 'hold_message', 'as_of_manual',
        'applied_at', 'applied_by', 'decided_by', 'decision_note', 'stats',
    ];

    protected function casts(): array
    {
        return [
            'as_of' => 'date',
            'total_debit' => 'decimal:2',
            'as_of_manual' => 'boolean',
            'applied_at' => 'datetime',
            'stats' => 'array',
        ];
    }

    public function isApplied(): bool
    {
        return $this->status === self::APPLIED;
    }

    public function isHeld(): bool
    {
        return $this->status === self::HELD;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::APPLIED => 'مطبَّقة',
            self::HELD => 'أرصدة غير مطبَّقة (بانتظار قرار)',
            self::IGNORED => 'أرصدة غير مطبَّقة (تُجوهلت بقرار)',
            self::ROLLED_BACK => 'تم التراجع عنها مع دفعتها',
            default => (string) $this->status,
        };
    }

    public function snapshots(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CustomerBalanceSnapshot::class);
    }

    /**
     * أحدث ملف أرصدة مطبَّق (تاريخ «لغاية» الأحدث، ثم الأحدث تطبيقاً).
     */
    public static function latestApplied(): ?self
    {
        return static::where('status', self::APPLIED)->whereNotNull('as_of')->orderByDesc('as_of')->orderByDesc('applied_at')->orderByDesc('id')->first();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
