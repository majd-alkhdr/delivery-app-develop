<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «تعديل لاحق» على فاتورة في كشف مكتمل (المرحلة 7C).
 * - file: تغيير وصل في ملف (اقتراح post_close): تُحدَّث ترويسة الفاتورة وأسطرها، وdelta للعلم.
 * - manual: من المحاسب بلا أسطر: لا يمس الفاتورة، والقيمة الفعالة = invoice_amount + Σ delta (manual، applied).
 * ذمة المندوب قبل/بعد والفائض تُحفظ عند التطبيق، ولا يُعدَّل أي دفع أو توزيع أو تصدير سابق.
 */
class InvoiceAdjustment extends Model
{
    public const KIND_FILE = 'file';
    public const KIND_MANUAL = 'manual';

    public const APPLIED = 'applied';
    public const REVERTED = 'reverted';

    public const REASONS = [
        'return' => 'مرتجع',
        'price' => 'تعديل سعر',
        'cancel' => 'إلغاء فاتورة',
        'voucher' => 'تعديل سند',
        'other' => 'أخرى',
        'file' => 'تعديل في المحاسبة وصل في ملف',
    ];

    /** أسباب النموذج اليدوي */
    public const MANUAL_REASONS = ['return', 'price', 'cancel', 'voucher', 'other'];

    public const NO_SNAPSHOT = 'لا توجد لقطة رصيد مطابقة';

    protected $fillable = [
        'invoice_id', 'sheet_id', 'customer_id', 'cost_center_code', 'rep_user_id', 'kind', 'old_amount', 'new_amount', 'delta',
        'reason_code', 'reason_text', 'source_batch_id', 'proposal_id', 'import_batch_item_id', 'balance_snapshot_id', 'snapshot_note',
        'invoice_revision_id', 'rep_debt_before', 'rep_debt_after', 'surplus', 'status', 'applied_by', 'applied_at',
        'reverted_by', 'reverted_at', 'revert_reason', 'rep_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'applied_at' => 'datetime',
            'reverted_at' => 'datetime',
            'rep_seen_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(DistributionSheet::class, 'sheet_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function rep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rep_user_id');
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(InvoiceChangeProposal::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(CustomerBalanceSnapshot::class, 'balance_snapshot_id');
    }

    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function reverter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reverted_by');
    }

    public function scopeApplied(Builder $query): Builder
    {
        return $query->where('status', self::APPLIED);
    }

    public function isApplied(): bool
    {
        return $this->status === self::APPLIED;
    }

    public function reasonLabel(): string
    {
        $label = self::REASONS[$this->reason_code] ?? (string) $this->reason_code;

        return $this->reason_text ? $label.': '.$this->reason_text : $label;
    }

    public function kindLabel(): string
    {
        return $this->kind === self::KIND_FILE ? 'من الملف' : 'يدوي';
    }

    /** «تعديل لاحق: من 1,000 إلى 800» */
    public function tagLabel(): string
    {
        $money = fn ($v) => \App\Services\Balances\BalanceDeltaExplainer::money((float) $v);

        return 'تعديل لاحق: من '.$money($this->old_amount).' إلى '.$money($this->new_amount)
            .((float) $this->new_amount === 0.0 ? ' (ألغيت في المحاسبة)' : '');
    }

    /**
     * تعديلات مطبَّقة/متراجع عنها لم «يطّلع» عليها المندوب (إنذار «تغيّرت ذمتك بسبب تعديل لاحق»).
     */
    public static function unseenForRep(User $rep): \Illuminate\Support\Collection
    {
        return self::with('invoice:id,invoice_number', 'customer:id,name')
            ->where('rep_user_id', $rep->id)->whereNull('rep_seen_at')->orderByDesc('id')->get();
    }
}
