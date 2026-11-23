<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * عنصر دفعة رفع (المرحلة 7A): نتيجة فاتورة واحدة أو ملاحظة على الملف، بشرح عربي جاهز للعرض.
 * المطابقة تبني العناصر ولا تعدّل أي فاتورة موجودة؛ التعديل بقرار عبر ImportReviewService.
 */
class ImportBatchItem extends Model
{
    // الأنواع
    public const UNCHANGED = 'unchanged';
    public const CREATED = 'created';
    public const CHANGED = 'changed';
    public const ORDER_REMOVED = 'order_removed';
    public const ORDER_MOVED = 'order_moved';
    public const CLOSED_UNCHANGED = 'closed_unchanged';
    public const CLOSED_CHANGED = 'closed_changed';
    public const EXCLUDED_CHANGED = 'excluded_changed';
    public const HELD_CHANGED = 'held_changed';
    public const MISSING = 'missing';
    public const NOTE = 'note';

    /** level لعنصر فاتورة ظهرت مرة ثانية في ملف آخر من الدفعة نفسها (تُعرض مرة واحدة في «بلا تغيير») */
    public const DUPLICATE = 'duplicate';

    // الحالات
    public const INFO = 'info';
    public const PENDING = 'pending';
    public const APPLIED = 'applied';
    public const REJECTED = 'rejected';
    public const BLOCKED = 'blocked';
    public const SUPERSEDED = 'superseded';

    /** الأنواع التي يحمل تطبيقها حمولة جديدة من الملف (اقتراح تغيير) */
    public const PAYLOAD_KINDS = [self::CHANGED, self::ORDER_REMOVED, self::ORDER_MOVED, self::EXCLUDED_CHANGED, self::HELD_CHANGED];

    /** الوسم وجملة التعريف لكل نوع (أعلى شاشة المراجعة) */
    public const KINDS = [
        self::UNCHANGED => ['بلا تغيير', 'الفاتورة موجودة والملف مطابق لها تماماً.', 'secondary'],
        self::CREATED => ['جديدة', 'فاتورة لم تكن في النظام، أُنشئت من هذا الملف.', 'success'],
        self::CHANGED => ['معدّلة', 'الفاتورة موجودة وتغيّر في الملف مبلغها أو أصنافها أو تاريخها أو مركز كلفتها.', 'warning'],
        self::ORDER_REMOVED => ['خرجت من الكشف', 'أمر تسليمها في الملف صار 0 أو غير صالح، فتخرج من كشفها إلى «بلا كشف» إن طُبّق.', 'danger'],
        self::ORDER_MOVED => ['نُقلت', 'أمر تسليمها في الملف تغيّر إلى أمر آخر، فتنتقل إلى كشف ذلك الأمر إن طُبّق.', 'info'],
        self::CLOSED_UNCHANGED => ['كشف مكتمل', 'الفاتورة في كشف مكتمل والملف مطابق لها.', 'dark'],
        self::CLOSED_CHANGED => ['كشف مكتمل (مختلفة)', 'الفاتورة في كشف مكتمل والملف يحمل قيمة مختلفة: لا تُعدَّل هنا، وحُفظ التغيير للتسوية بعد الإغلاق.', 'danger'],
        self::EXCLUDED_CHANGED => ['مستبعدة (معدّلة)', 'فاتورة مستبعدة تغيّر محتواها: التطبيق يحدّث بياناتها وتبقى مستبعدة.', 'warning'],
        self::HELD_CHANGED => ['موضوعة يدوياً (معدّلة)', 'فاتورة وضعها المستخدم يدوياً في «بلا كشف» أو «مؤجلة» تغيّر محتواها: التطبيق يحدّث بياناتها وتبقى في مكانها.', 'warning'],
        self::MISSING => ['غائبة', 'فاتورة في كشف مفتوح تاريخها ضمن مدى الملف ولم تظهر فيه.', 'danger'],
        self::NOTE => ['ملاحظة', 'ما لم يُقرأ من الملف أو عُدِّل بقاعدة تلقائية.', 'light'],
    ];

    public const STATUSES = [
        self::INFO => ['للعلم', 'secondary'],
        self::PENDING => ['بانتظار قرارك', 'warning'],
        self::APPLIED => ['طُبّق', 'success'],
        self::REJECTED => ['تُجوهل بقرار', 'secondary'],
        self::BLOCKED => ['محجوز', 'danger'],
        self::SUPERSEDED => ['حلّ محله عنصر أحدث', 'light'],
    ];

    protected $fillable = [
        'batch_id', 'kind', 'status', 'level', 'invoice_id', 'invoice_key', 'invoice_number', 'customer_id', 'customer_name',
        'sheet_id', 'sheet_label', 'target_sheet_id', 'reason', 'old', 'new', 'proposal_id', 'blocked_reason', 'decision_note',
        'superseded_by_id', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'old' => 'array',
            'new' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'batch_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(DistributionSheet::class, 'sheet_id');
    }

    public function targetSheet(): BelongsTo
    {
        return $this->belongsTo(DistributionSheet::class, 'target_sheet_id');
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(InvoiceChangeProposal::class);
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopeInvoices(Builder $query): Builder
    {
        return $query->where('kind', '!=', self::NOTE);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::PENDING, self::BLOCKED]);
    }

    /** المرحلة 7E2: أسماء الحقول الوصفية للحقل الواحد (للعرض) */
    public const DESCRIPTIVE_LABELS = [
        'cost_center' => 'مركز الكلفة',
        'invoice_date' => 'تاريخ الفاتورة',
        'invoice_note' => 'البيان',
        'entered_by' => 'المدخل',
    ];

    /**
     * المرحلة 7E2: عناصر «تغيّر وصفي فقط» (المبلغ والأصناف والموقع كما هي) المعلّقة.
     */
    public function scopeDescriptivePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING)->whereIn('kind', self::PAYLOAD_KINDS)->whereNotNull('new->descriptive');
    }

    /** @return array<int, string>|null */
    public function descriptiveFields(): ?array
    {
        return in_array($this->kind, self::PAYLOAD_KINDS, true) ? ($this->new['descriptive'] ?? null) : null;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::PENDING, self::BLOCKED], true);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind][0] ?? $this->kind;
    }

    public function kindVariant(): string
    {
        return self::KINDS[$this->kind][2] ?? 'secondary';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function statusVariant(): string
    {
        return self::STATUSES[$this->status][1] ?? 'secondary';
    }
}
