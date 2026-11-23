<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * دفعة تصديرية للمالية (المرحلة 5): لقطة ثابتة لا يُعاد حسابها.
 */
class FinanceExport extends Model
{
    protected $fillable = [
        'kind', 'scope_key', 'meta', 'distribution_sheet_id', 'sequence', 'exported_by', 'exported_at', 'payments_count',
        'total_syp', 'total_usd', 'surplus_syp', 'statement_template', 'template_signature',
    ];

    protected function casts(): array
    {
        return [
            'exported_at' => 'datetime',
            'meta' => 'array',
            'total_syp' => 'decimal:2',
            'total_usd' => 'decimal:2',
            'surplus_syp' => 'decimal:2',
        ];
    }

    public const KIND_SHEET = 'sheet';

    public const KIND_REP = 'rep';

    /** مفتاح نطاق تصدير المندوبين (تسلسل م-{n} واحد لكل المندوبين) */
    public const REP_SCOPE = 'rep';

    public static function sheetScope(int $sheetId): string
    {
        return 'sheet:'.$sheetId;
    }

    public function isRep(): bool
    {
        return $this->kind === self::KIND_REP;
    }

    /** رقم «كشف رقم» للمندوبين: م-{n} */
    public function repOrder(): string
    {
        return 'م-'.$this->sequence;
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(DistributionSheet::class, 'distribution_sheet_id');
    }

    public function exporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exported_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(FinanceExportRow::class)->orderBy('sort');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
