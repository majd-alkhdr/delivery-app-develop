<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * المرحلة 8B: تعديل خطة مندوب بتاريخ محدد (إضافة نطاق أو إزالته لذلك اليوم فقط) دون المساس بالقالب.
 */
class RepPlanOverride extends Model
{
    public const ADD = 'add';
    public const REMOVE = 'remove';

    protected $fillable = [
        'rep_user_id', 'plan_date', 'action', 'scope_type', 'scope_value', 'scope_label', 'supervisor_user_id', 'reason', 'created_by',
    ];

    protected function casts(): array
    {
        return ['plan_date' => 'date'];
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_user_id');
    }

    public function scopeText(): string
    {
        return (RepPlanTemplate::TYPES[$this->scope_type] ?? $this->scope_type).' '.($this->scope_label ?? $this->scope_value);
    }

    public function logValues(): array
    {
        return [
            'plan_date' => $this->plan_date?->toDateString(),
            'action' => $this->action,
            'scope' => $this->scopeText(),
            'supervisor_user_id' => $this->supervisor_user_id,
            'reason' => $this->reason,
        ];
    }
}
