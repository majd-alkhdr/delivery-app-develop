<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory;

    /** مكان الفاتورة (placement) */
    public const IN_SHEET = 'in_sheet';
    public const DEFERRED = 'deferred';
    public const UNASSIGNED = 'unassigned';
    public const EXCLUDED = 'excluded';
    public const CANCELLED = 'cancelled';

    public const PLACEMENTS = [
        self::IN_SHEET => 'في كشف',
        self::DEFERRED => 'مؤجلة',
        self::UNASSIGNED => 'بلا كشف',
        self::EXCLUDED => 'مستبعدة',
        self::CANCELLED => 'ملغاة',
    ];

    protected $fillable = [
        'distribution_sheet_id',
        'invoice_number',          // v1: العمود B — v2: النص الأصلي مثل "ع G: 12830"
        'customer_name',           // v1: العمود C — v2: الاسم كما ورد في الملف
        'statement',               // v1: العمود E
        'invoice_amount',          // v1: العمود F — v2: مجموع أسطر الفاتورة
        'customer_total_invoices', // v2: مجموع فواتير الزبون غير المؤجلة في الكشف
        'invoice_pattern',         // v1: العمود H — v2: السلسلة
        'area',
        'final_balance',           // v2: الرصيد المستحق (بعد طرح المؤجل) بتاريخ التوزيع
        'notes',
        'collected_amount',
        'is_paid_full',
        'visited_at',
        'latitude',
        'longitude',
        // أعمدة الصيغة الجديدة v2
        'customer_id',
        'area_key',
        'series',
        'invoice_date',
        'delivery_date',
        'cost_center_code',
        'cost_center_name',
        'entered_by_name',
        'invoice_note',
        'is_zero_value',
        'is_deferred',
        // المرحلة 2B: تشكيل الكشوف
        'invoice_key',
        'placement',
        'delivery_order_no',
        'content_hash',
        'manual_placement',
        'area_overridden',
        // المرحلة 7H: «أُضيفت بعد الإرسال»
        'added_after_send_at',
        'added_after_send_batch_id',
        'exclusion_reason',
        'cancelled_reason',
        'last_seen_batch_id',
        // المرحلة 6B
        'net_uncertain',
    ];

    protected $casts = [
        'visited_at' => 'datetime',
        'invoice_date' => 'date',
        'delivery_date' => 'date',
        'is_zero_value' => 'boolean',
        'is_deferred' => 'boolean',
        'is_paid_full' => 'boolean',
        'manual_placement' => 'boolean',
        'area_overridden' => 'boolean',
        'added_after_send_at' => 'datetime',
        'net_uncertain' => 'boolean',
    ];

    protected static function booted(): void
    {
        // is_deferred متسق دائماً مع placement
        static::saving(function (Invoice $invoice) {
            if ($invoice->placement === null) {
                $invoice->placement = $invoice->is_deferred ? self::DEFERRED : self::IN_SHEET;
            }
            $invoice->is_deferred = $invoice->placement === self::DEFERRED;
        });
    }

    public function distributionSheet()
    {
        return $this->belongsTo(DistributionSheet::class, 'distribution_sheet_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('line_no');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(InvoiceChangeProposal::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(InvoiceRevision::class)->orderBy('revision_no');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(InvoiceAdjustment::class)->orderBy('id');
    }

    /**
     * المرحلة 7C: مجموع فروق التعديلات اليدوية المطبَّقة (تحميل مسبق للقائمة لتفادي استعلام لكل فاتورة).
     */
    public function scopeWithAdjustmentDelta(Builder $query): Builder
    {
        return $query->withSum(['adjustments as adjustment_delta' => fn ($q) => $q->where('kind', InvoiceAdjustment::KIND_MANUAL)->where('status', InvoiceAdjustment::APPLIED)], 'delta');
    }

    /**
     * المرحلة 7C: القيمة الفعالة للفاتورة في الحسابات المالية = invoice_amount + Σ delta (تعديلات يدوية مطبَّقة).
     * تعديل «من الملف» يغيّر invoice_amount نفسه فلا يُضاف ثانية.
     */
    public function effectiveAmount(): float
    {
        // محمَّل بـ withAdjustmentDelta()، وإلا استعلام واحد (لا يُخزَّن كعمود حتى لا يدخل في save)
        $delta = array_key_exists('adjustment_delta', $this->attributes)
            ? $this->attributes['adjustment_delta']
            : ($this->exists ? InvoiceAdjustment::where('invoice_id', $this->id)->where('kind', InvoiceAdjustment::KIND_MANUAL)->where('status', InvoiceAdjustment::APPLIED)->sum('delta') : 0);

        return round((float) $this->invoice_amount + (float) $delta, 2);
    }

    /** هل لها تعديل لاحق مطبَّق؟ */
    public function hasAppliedAdjustment(): bool
    {
        return InvoiceAdjustment::where('invoice_id', $this->id)->where('status', InvoiceAdjustment::APPLIED)->exists();
    }

    public function isExternal(): bool
    {
        return $this->invoice_pattern === 'external';
    }

    public function placementLabel(): string
    {
        return self::PLACEMENTS[$this->placement] ?? (string) $this->placement;
    }

    /**
     * أعمدة الزيارة والتحصيل القديمة على الفاتورة (كشوف v1 فقط، للقراءة).
     * النشاط منذ المرحلة 3 في sheet_visits وpayments (SheetActivityGuard).
     */
    public function hasActivity(): bool
    {
        return $this->visited_at !== null || (float) $this->collected_amount > 0;
    }

    public function scopePlacement(Builder $query, string ...$placements): Builder
    {
        return $query->whereIn('placement', $placements);
    }
}
