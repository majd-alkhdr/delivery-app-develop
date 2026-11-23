<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * المرحلة 8A: إسناد مندوب لمشرف مندوبين بنطاق (كل صيدلياته / منطقة / كتلة). الإنهاء بـ ended_at لا بالحذف.
 */
class RepSupervisorAssignment extends Model
{
    public const ALL = 'all';
    public const AREA = 'area';
    public const BLOCK = 'block';

    public const TYPES = [self::ALL => 'كل صيدلياته', self::AREA => 'منطقة', self::BLOCK => 'كتلة'];

    protected $fillable = [
        'rep_user_id', 'supervisor_user_id', 'scope_type', 'scope_value', 'scope_label', 'created_by', 'ended_at', 'ended_by',
    ];

    protected function casts(): array
    {
        return ['ended_at' => 'datetime'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * «منطقة نهر عيشة» / «كتلة الميدان» / «كل صيدلياته».
     */
    public function scopeText(): string
    {
        return match ($this->scope_type) {
            self::ALL => 'كل صيدلياته',
            self::BLOCK => 'كتلة '.$this->scope_label,
            default => 'منطقة '.$this->scope_label,
        };
    }

    /**
     * القيم المسجَّلة في سجل النشاط (قبل/بعد).
     */
    public function logValues(): array
    {
        return [
            'rep_user_id' => $this->rep_user_id,
            'supervisor_user_id' => $this->supervisor_user_id,
            'scope_type' => $this->scope_type,
            'scope_value' => $this->scope_value,
            'scope_label' => $this->scope_label,
            'ended_at' => $this->ended_at?->toDateTimeString(),
        ];
    }

    public function rep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rep_user_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function ender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }
}
