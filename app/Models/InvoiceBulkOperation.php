<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * المرحلة 7F: عملية جماعية على الفواتير (استبعاد «بلا كشف» أو إلغاء مؤجلة، وإعادة إدراجها) بمعرّف واحد.
 * سجل كل فاتورة في ActivityLog يحمل bulk_operation_id = uuid.
 */
class InvoiceBulkOperation extends Model
{
    public const EXCLUDE = 'exclude';
    public const CANCEL = 'cancel';
    public const RESTORE = 'restore';
    public const UNCANCEL = 'uncancel';

    public const LABELS = [
        self::EXCLUDE => 'استبعاد جماعي',
        self::CANCEL => 'إلغاء جماعي لطلبيات مؤجلة',
        self::RESTORE => 'إعادة إدراج جماعية (إلى «بلا كشف»)',
        self::UNCANCEL => 'إعادة إدراج جماعية (إلى «مؤجلة»)',
    ];

    protected $fillable = ['uuid', 'action', 'reason', 'user_id', 'batch_id', 'filters', 'invoice_ids', 'skipped', 'count', 'undoes_id', 'undone_at', 'undone_by'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'invoice_ids' => 'array',
            'skipped' => 'array',
            'undone_at' => 'datetime',
        ];
    }

    public function label(): string
    {
        return self::LABELS[$this->action] ?? $this->action;
    }

    public function isUndoable(): bool
    {
        return in_array($this->action, [self::EXCLUDE, self::CANCEL], true) && $this->undone_at === null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
