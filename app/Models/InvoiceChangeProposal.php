<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تغيير وارد من ملف جديد على فاتورة في كشف منشور: لا يُطبَّق إلا بقرار من مسؤول الكشوف.
 */
class InvoiceChangeProposal extends Model
{
    public const PENDING = 'pending';
    public const APPLIED = 'applied';
    public const REJECTED = 'rejected';
    // المرحلة 7A
    public const SUPERSEDED = 'superseded';
    public const AWAITING_ADJUSTMENT = 'awaiting_adjustment';

    public const TYPE_CHANGE = 'change';
    // تغيير على فاتورة في كشف مكتمل: يُحفظ للتسوية بعد الإغلاق، ويُطبَّق أو يُتجاهل من «التعديلات اللاحقة» (المرحلة 7C)
    public const TYPE_POST_CLOSE = 'post_close';

    protected $fillable = [
        'invoice_id',
        'batch_id',
        'payload',
        'old_hash',
        'new_hash',
        'status',
        'type',
        'decided_by',
        'decided_at',
        'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
