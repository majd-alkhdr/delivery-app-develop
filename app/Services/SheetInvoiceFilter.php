<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\ImportBatch;
use App\Models\ImportBatchItem;
use App\Models\Invoice;
use App\Models\SheetAssignment;
use App\Support\ArabicNormalizer;
use Illuminate\Database\Eloquent\Builder;

/**
 * المرحلة 8C: فلترة فواتير كشف «قيد التجهيز» وفرزها على الخادم (شاشة كشف مسؤول الكشوف)
 * لاكتشاف الفاتورة التي نزلت في الكشف بالخطأ. كل الفلاتر من بيانات موجودة فعلاً، بلا حقول جديدة.
 */
class SheetInvoiceFilter
{
    /** قيمة «بلا منطقة/بلا كتلة» في القوائم */
    public const NONE = '__none';

    /** الأصل/السبب: يُعرض منها ما له فواتير في الكشف فقط */
    public const ORIGINS = [
        'latest_batch' => 'أُضيفت بآخر رفع',
        'moved' => 'نُقلت إلى هذا الكشف',
        'manual' => 'أضافها المستخدم يدوياً',
        'date_changed' => 'تاريخ تسليمها يختلف عن تاريخ التوزيع',
        'no_cost_center' => 'بلا مركز كلفة',
    ];

    public const SORTS = [
        'customer' => 'الصيدلية',
        'area' => 'المنطقة',
        'amount_desc' => 'الأعلى قيمة أولاً',
        'amount_asc' => 'الأقل قيمة أولاً',
        'order' => 'ترتيب الفاتورة في الملف',
    ];

    /** مفاتيح الفلاتر في الرابط (search بدل q لأن q لبحث «إضافة فاتورة») */
    public const KEYS = ['search', 'area', 'block', 'origin', 'min', 'max', 'review', 'sort'];

    private ?string $latestBatchId = null;

    private bool $latestResolved = false;

    /** فواتير الكشف كلها (أساس الفلترة والأعداد) */
    public function base(DistributionSheet $sheet): Builder
    {
        return Invoice::where('distribution_sheet_id', $sheet->id)->where('placement', Invoice::IN_SHEET);
    }

    /**
     * @param  array<string, mixed>  $filters  القيم المتحقَّق منها من الطلب
     */
    public function apply(Builder $query, DistributionSheet $sheet, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $normalized = ArabicNormalizer::normalize($search);
            $query->where(fn ($w) => $w->where('invoice_number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")
                ->orWhereIn('customer_id', Customer::where('name_normalized', 'like', "%{$normalized}%")->select('id')));
        }

        if (($filters['area'] ?? '') !== '') {
            $filters['area'] === self::NONE ? $query->whereNull('area_key') : $query->where('area_key', $filters['area']);
        }

        if (($filters['block'] ?? '') !== '') {
            $filters['block'] === self::NONE
                ? $query->where(fn ($w) => $w->whereNull('customer_id')->orWhereIn('customer_id', Customer::whereNull('block')->select('id')))
                : $query->whereIn('customer_id', Customer::where('block', $filters['block'])->select('id'));
        }

        if (($filters['origin'] ?? '') !== '') {
            $this->applyOrigin($query, $sheet, $filters['origin']);
        }

        if (filled($filters['min'] ?? null)) {
            $query->where('invoice_amount', '>=', (float) $filters['min']);
        }
        if (filled($filters['max'] ?? null)) {
            $query->where('invoice_amount', '<=', (float) $filters['max']);
        }

        if (! empty($filters['review'])) {
            $query->whereIn('customer_id', Customer::where('needs_review', true)->select('id'));
        }

        return $this->sort($query, $filters['sort'] ?? 'customer');
    }

    public function sort(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            'area' => $query->orderBy('area')->orderBy('customer_name')->orderBy('id'),
            'amount_desc' => $query->orderByDesc('invoice_amount')->orderBy('id'),
            'amount_asc' => $query->orderBy('invoice_amount')->orderBy('id'),
            'order' => $query->orderBy('id'),
            default => $query->orderBy('customer_name')->orderBy('id'),
        };
    }

    /**
     * قوائم الفلاتر من قيم هذا الكشف وحده مع عدد الفواتير لكل قيمة.
     *
     * @return array{areas: array<string, array{label: string, count: int}>, blocks: array<string, array{label: string, count: int}>, origins: array<string, int>, review: int, latest_batch: ?ImportBatch}
     */
    public function options(DistributionSheet $sheet): array
    {
        $areas = $this->base($sheet)->selectRaw('area_key, MAX(area) as label, COUNT(*) as c')->groupBy('area_key')->get()
            ->mapWithKeys(fn ($row) => [($row->area_key ?? self::NONE) => [
                'label' => in_array($row->area_key, [null, SheetAssignment::NO_AREA], true) ? 'بلا منطقة' : ($row->label ?: $row->area_key),
                'count' => (int) $row->c,
            ]])
            ->sortBy('label', SORT_NATURAL)->all();

        $blocks = $this->base($sheet)->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->selectRaw('customers.block as block, COUNT(*) as c')->groupBy('customers.block')->get()
            ->mapWithKeys(fn ($row) => [($row->block ?? self::NONE) => ['label' => $row->block ?? 'بلا كتلة', 'count' => (int) $row->c]])
            ->sortBy('label', SORT_NATURAL)->all();

        $origins = [];
        foreach (array_keys(self::ORIGINS) as $origin) {
            if ($origin === 'date_changed' && ! $sheet->distribution_date) {
                continue;
            }
            if ($origin === 'latest_batch' && ! $this->latestBatchId($sheet)) {
                continue;
            }
            $count = $this->applyOrigin($this->base($sheet), $sheet, $origin)->count();
            if ($count > 0) {
                $origins[$origin] = $count;
            }
        }

        return [
            'areas' => $areas,
            'blocks' => $blocks,
            'origins' => $origins,
            'review' => $this->base($sheet)->whereIn('customer_id', Customer::where('needs_review', true)->select('id'))->count(),
            'latest_batch' => ($id = $this->latestBatchId($sheet)) ? ImportBatch::find($id) : null,
        ];
    }

    /** هل في الطلب فلتر فعلي (غير الفرز)؟ */
    public static function active(array $filters): bool
    {
        foreach (array_diff(self::KEYS, ['sort']) as $key) {
            if (filled($filters[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function applyOrigin(Builder $query, DistributionSheet $sheet, string $origin): Builder
    {
        return match ($origin) {
            // فاتورة أنشأها آخر رفع لمس فواتير هذا الكشف (عنصر «جديدة» في تلك الدفعة)
            'latest_batch' => $query->whereIn('invoices.id', ImportBatchItem::where('batch_id', $this->latestBatchId($sheet) ?? '')
                ->where('kind', ImportBatchItem::CREATED)->whereNotNull('invoice_id')->select('invoice_id')),
            // نقلها مستخدم من كشف آخر (invoice.moved) أو طُبّق نقل أمرها من ملف (عنصر «نُقلت» مطبَّق)
            'moved' => $query->where(fn ($w) => $w
                ->whereIn('invoices.id', ActivityLog::where('action', 'invoice.moved')->where('subject_type', (new Invoice)->getMorphClass())
                    ->where('new_values->sheet_id', $sheet->id)->select('subject_id'))
                ->orWhereIn('invoices.id', ImportBatchItem::where('kind', ImportBatchItem::ORDER_MOVED)->where('status', ImportBatchItem::APPLIED)
                    ->where('target_sheet_id', $sheet->id)->whereNotNull('invoice_id')->select('invoice_id'))),
            // وضعها المستخدم من «بلا كشف» (invoice.placed) وما زال وضعها يدوياً
            'manual' => $query->where('invoices.manual_placement', true)
                ->whereIn('invoices.id', ActivityLog::where('action', 'invoice.placed')->where('subject_type', (new Invoice)->getMorphClass())
                    ->where('new_values->sheet_id', $sheet->id)->select('subject_id')),
            'date_changed' => $query->whereNotNull('invoices.delivery_date')
                ->whereDate('invoices.delivery_date', '!=', $sheet->distribution_date?->toDateString() ?? '1900-01-01'),
            'no_cost_center' => $query->where(fn ($w) => $w->whereNull('invoices.cost_center_code')->orWhere('invoices.cost_center_code', '')),
            default => $query,
        };
    }

    /** أحدث دفعة رفع لمست فواتير هذا الكشف (last_seen_batch_id) */
    private function latestBatchId(DistributionSheet $sheet): ?string
    {
        if (! $this->latestResolved) {
            $this->latestResolved = true;
            $this->latestBatchId = ImportBatch::whereIn('id', $this->base($sheet)->whereNotNull('last_seen_batch_id')->select('last_seen_batch_id'))
                ->orderByDesc('created_at')
                // دفعتان في الثانية نفسها (المعرّف UUID لا يرتّب): الأحدث عناصرَ هي الأخيرة
                ->orderByDesc(ImportBatchItem::selectRaw('MAX(id)')->whereColumn('import_batch_items.batch_id', 'import_batches.id'))
                ->value('id');
        }

        return $this->latestBatchId;
    }
}
