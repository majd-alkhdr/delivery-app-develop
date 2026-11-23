<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DistributionSheet extends Model
{
    public const STATUSES = ['preparing', 'pending', 'in_progress', 'completed', 'draft'];

    /** حالات الكشف التي يراها الموزع المسند */
    public const DISTRIBUTOR_STATUSES = ['pending', 'in_progress', 'completed'];

    /**
     * شرح كل حالة كما يظهر في الواجهة (تلميح الشارة وسطر الشرح في صفحة الكشف).
     * لا يوجد زر «بدء»: pending تتحول تلقائياً إلى in_progress عند أول زيارة أو دفعة.
     */
    public const STATUS_HINTS = [
        'preparing' => 'قيد التجهيز: عند مسؤول الكشوف ولم يُرسل بعد.',
        'pending' => 'بانتظار البدء: أُرسل، ولم يسجّل الموزع أي زيارة أو دفعة بعد. يبدأ تلقائياً عند أول تسجيل.',
        'in_progress' => 'قيد التحصيل: بدأ الموزعون التسجيل عليه.',
        'completed' => 'مكتمل: أغلقه المشرف.',
        'draft' => 'مسودة: أُغلق بلا توزيع.',
    ];

    /** حالات يمكن فيها إضافة/نقل/إخراج الفواتير */
    public const EDITABLE_STATUSES = ['preparing', 'pending', 'in_progress'];

    protected $fillable = [
        'distributor_id',
        'supervisor_id',
        'file_name',
        'status',
        'format',
        'delivery_order_no',
        'report_from',
        'report_to',
        'distribution_date',
        'total_all',
        'total_deferred',
        'total_effective',
        'original_name',
        'stored_path',
        'batch_id',
        'status_reason',
        'status_changed_by',
        'status_changed_at',
        'sent_at',
        'sent_by',
        // المرحلة 7H: كشف «ملحق» لأمر كشفه مكتمل
        'parent_sheet_id',
        'supplement_no',
    ];

    protected function casts(): array
    {
        return [
            'report_from' => 'date',
            'report_to' => 'date',
            'distribution_date' => 'date',
            'total_all' => 'decimal:2',
            'total_deferred' => 'decimal:2',
            'total_effective' => 'decimal:2',
            'status_changed_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function distributor()
    {
        return $this->belongsTo(User::class, 'distributor_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function statusChanger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'batch_id');
    }

    /** المرحلة 7H: الكشف الأصل لكشف «ملحق» */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_sheet_id');
    }

    public function isSupplement(): bool
    {
        return $this->supplement_no !== null;
    }

    /**
     * المرحلة 7H: الاسم المعروض الواحد لرقم الكشف في كل مكان: «1580» أو «1580 — ملحق 1» (#id إن لم يكن له أمر).
     * title() وdisplayTitle() وshortLabel() وأسماء ملفات التصدير كلها مبنية عليه.
     */
    public function orderLabel(): string
    {
        $order = trim((string) $this->delivery_order_no) !== '' ? (string) $this->delivery_order_no : '#'.$this->id;

        return $order.($this->supplement_no !== null ? ' — ملحق '.$this->supplement_no : '');
    }

    /** «أمر 1580 — ملحق 1» */
    public function shortLabel(): string
    {
        return 'أمر '.$this->orderLabel();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(SheetAssignment::class, 'sheet_id');
    }

    public function isV2(): bool
    {
        return $this->format === 'v2';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPreparing(): bool
    {
        return $this->status === 'preparing';
    }

    /**
     * كشف لا يقبل تسجيل زيارات أو دفعات (قيد التجهيز أو مكتمل أو مسودة).
     */
    public function isLocked(): bool
    {
        return ! in_array($this->status, ['pending', 'in_progress'], true);
    }

    /**
     * عنوان مختصر للكشف في القوائم.
     */
    /**
     * اسم الحالة بالعربية (كما في شارة الحالة).
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            'preparing' => 'قيد التجهيز',
            'pending' => 'بانتظار البدء',
            'in_progress' => 'قيد التحصيل',
            'completed' => 'مكتمل',
            'draft' => 'مسودة',
            default => (string) $this->status,
        };
    }

    public function title(): string
    {
        if ($this->isV2() && $this->delivery_order_no) {
            return 'أمر التسليم #'.$this->orderLabel();
        }

        return $this->original_name ?? $this->file_name ?? 'كشف #'.$this->id;
    }

    /**
     * عنوان الكشف في كل القوائم: لكشوف v2 «أمر التسليم #X — تاريخ التوزيع»، ولكشوف v1 اسم الملف
     * (الواجهة تضيف شارة «صيغة قديمة» بجانبه).
     */
    public function displayTitle(): string
    {
        if ($this->isV2()) {
            return 'أمر التسليم #'.($this->delivery_order_no !== null ? $this->orderLabel() : '—')
                .($this->distribution_date ? ' — '.$this->distribution_date->format('Y-m-d') : '');
        }

        return $this->original_name ?? $this->file_name ?? 'كشف #'.$this->id;
    }

    /**
     * أسماء الموزعين: لكشوف v2 من sheet_assignments (أكثر من ثلاثة: «فلان، فلان +N»)،
     * و«غير مسند» إن لم يوجد إسناد. لكشوف v1 من distributor_id كما كان.
     * لتفادي استعلام لكل صف، حمّل مسبقاً: with(['assignments.distributor', 'distributor']).
     */
    public function distributorsLabel(): string
    {
        if (! $this->isV2()) {
            return $this->distributor?->name ?? 'غير مسند';
        }

        $names = $this->assignments
            ->map(fn ($a) => $a->distributor?->name)
            ->filter()
            ->unique()
            ->values();

        if ($names->isEmpty()) {
            return 'غير مسند';
        }
        if ($names->count() > 3) {
            return $names->take(2)->implode('، ').' +'.($names->count() - 2);
        }

        return $names->implode('، ');
    }

    /**
     * إجمالي الكشف للقوائم: لكشوف v2 الرقم المحفوظ (مجموع فواتير الكشف)، ولكشوف v1 مجموع فواتيره
     * (يُفضَّل تحميله مسبقاً بـ withSum('invoices', 'invoice_amount')).
     */
    public function listTotal(): float
    {
        if ($this->isV2()) {
            return (float) $this->total_all;
        }

        return (float) ($this->invoices_sum_invoice_amount ?? $this->invoices()->sum('invoice_amount'));
    }

    /**
     * كشف أُرسل للمشرف ولم يُسند أي منطقة فيه بعد.
     */
    public function awaitsAssignment(): bool
    {
        if (! in_array($this->status, ['pending', 'in_progress'], true)) {
            return false;
        }
        $assignments = $this->relationLoaded('assignments') ? $this->assignments : $this->assignments()->get();

        return $assignments->isEmpty();
    }

    /**
     * الكشوفات التي يصل إليها الموزع: له إسناد فيها، وحالتها pending أو in_progress أو completed.
     */
    public function scopeVisibleToDistributor(Builder $query, User $user): Builder
    {
        return $query->whereIn('status', self::DISTRIBUTOR_STATUSES)
            ->whereHas('assignments', fn (Builder $q) => $q->where('distributor_id', $user->id));
    }

    /**
     * الكشوف التي يراها المشرف والمحاسب: كل الحالات عدا قيد التجهيز.
     */
    public function scopeVisibleToStaff(Builder $query): Builder
    {
        return $query->where('status', '!=', 'preparing');
    }

    /**
     * مناطق الموزع في هذا الكشف: null = كل المناطق، ومصفوفة فارغة = لا وصول.
     *
     * @return array<int, string>|null
     */
    public function areaKeysFor(User $user): ?array
    {
        $rows = $this->relationLoaded('assignments')
            ? $this->assignments->where('distributor_id', $user->id)
            : $this->assignments()->where('distributor_id', $user->id)->get();

        if ($rows->contains(fn ($a) => $a->area_key === null)) {
            return null;
        }

        return $rows->pluck('area_key')->values()->all();
    }

    /**
     * فواتير الكشف التي يراها مستخدم معيّن.
     * الموزع: فواتير هذا الكشف الموضوعة فيه (in_sheet) غير المؤجلة، ومن مناطقه فقط.
     * لا يخرج أي استعلام للموزع من خارج هذه الدالة. الإدارة والمحاسبة: كل فواتير الكشف.
     */
    public function invoicesVisibleTo(User $user)
    {
        $query = $this->invoices();

        if ($user->role !== Role::Distributor) {
            return $query;
        }

        $query->where('invoices.distribution_sheet_id', $this->id)
            ->where('placement', Invoice::IN_SHEET)
            ->where('is_deferred', false);

        $areaKeys = $this->areaKeysFor($user);
        if ($areaKeys !== null) {
            $query->whereIn('area_key', $areaKeys ?: ['__no_access__']);
        }

        return $query;
    }

    /**
     * مفاتيح المناطق الموجودة في زبائن الكشف الذين لهم فواتير غير مؤجلة.
     *
     * @return array<int, string>
     */
    public function areaKeys(): array
    {
        if (! $this->isV2()) {
            return [];
        }

        return $this->invoices()
            ->where('placement', Invoice::IN_SHEET)
            ->where('is_deferred', false)
            ->whereNotNull('area_key')
            ->distinct()
            ->orderBy('area_key')
            ->pluck('area_key')
            ->all();
    }

    /**
     * المناطق التي لم تُسند لأي موزع بعد.
     *
     * @return array<int, string>
     */
    public function unassignedAreaKeys(?array $areaKeys = null): array
    {
        $assignments = $this->relationLoaded('assignments') ? $this->assignments : $this->assignments()->get();

        if ($assignments->contains(fn ($a) => $a->area_key === null)) {
            return [];
        }

        $areaKeys ??= $this->areaKeys();
        if (! $this->isV2() && $assignments->isEmpty()) {
            return ['*'];
        }

        return array_values(array_diff($areaKeys, $assignments->pluck('area_key')->all()));
    }

    /**
     * هل سُجّلت في الكشف أي زيارة أو دفعة؟
     */
    public function hasActivity(): bool
    {
        // المرحلة 3: النشاط = زيارة أو دفعة غير ملغاة (sheet_visits / payments)
        return app(\App\Services\SheetActivityGuard::class)->sheetHasActivity($this);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'distribution_sheet_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(SheetVisit::class, 'distribution_sheet_id');
    }
}
