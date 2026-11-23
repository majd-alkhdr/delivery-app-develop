<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ImportBatchItem;
use App\Models\Invoice;
use App\Models\InvoiceBulkOperation;
use App\Models\User;
use App\Support\ArabicNormalizer;
use App\Support\BulkWrite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * المرحلة 7F: الاستبعاد الجماعي (فواتير «بلا كشف») والإلغاء الجماعي (طلبيات مؤجلة)، وإعادة الإدراج الجماعية.
 * - التحديد: معرّفات صريحة، أو «كل نتائج التصفية» تُعاد على الخادم بالمرشّحات نفسها (scope + filters) لا بقائمة معرّفات.
 * - الأهلية: ما لا يُنفَّذ عليه يُعرض بفئة وسبب وأمثلة (لا استثناء صامت)، والتنفيذ معاملة واحدة على المؤهَّل كله.
 * - التنفيذ عبر SheetInvoiceEditor (القواعد نفسها للإجراء الفردي) وسجل لكل فاتورة + سجل ملخَّص.
 */
class BulkInvoiceActionService
{
    /** نطاقات القوائم وما يقابلها من إجراء */
    public const SCOPES = [
        'batch_unassigned' => InvoiceBulkOperation::EXCLUDE,
        'unassigned' => InvoiceBulkOperation::EXCLUDE,
        'batch_deferred' => InvoiceBulkOperation::CANCEL,
        'deferred' => InvoiceBulkOperation::CANCEL,
        'batch_excluded' => InvoiceBulkOperation::RESTORE,
    ];

    /** أسباب جاهزة («سبب آخر» نصي إلزامي) */
    public const REASONS = [
        'ملف رُفع بالخطأ',
        'فواتير مكررة',
        'ليست للتوزيع من التطبيق',
        'أُلغيت في المحاسبة',
    ];

    private const EXAMPLES = 5;

    public function __construct(private SheetInvoiceEditor $editor)
    {
    }

    /**
     * استعلام القائمة بمرشّحاتها (المصدر نفسه الذي تعرضه الشاشات).
     */
    public function query(string $scope, array $filters): Builder
    {
        $batchId = (string) ($filters['batch'] ?? '');
        $term = trim((string) ($filters['q'] ?? ''));
        $search = fn (Builder $q) => $q->when($term !== '', fn ($w) => $w->where(fn ($x) => $x->where('invoice_number', 'like', "%{$term}%")->orWhere('customer_name', 'like', "%{$term}%")));

        return match ($scope) {
            // كما BatchPageController::unassignedQuery: «بلا كشف» رأتها الدفعة، عدا الجديدة التي تنتظر قرار كشف منشور
            'batch_unassigned' => Invoice::where('placement', Invoice::UNASSIGNED)->where('last_seen_batch_id', $batchId)
                ->whereNotIn('id', ImportBatchItem::where('kind', ImportBatchItem::CREATED)->where('status', ImportBatchItem::PENDING)->whereNotNull('invoice_id')->select('invoice_id'))
                ->tap($search),
            // كما InvoiceListController::unassigned
            'unassigned' => Invoice::where('placement', Invoice::UNASSIGNED)
                ->where(fn ($q) => $q->whereNull('invoice_pattern')->orWhere('invoice_pattern', '!=', 'external'))->tap($search),
            'batch_deferred' => Invoice::where('placement', Invoice::DEFERRED)->whereIn('id', ImportBatchItem::where('batch_id', $batchId)->whereNotNull('invoice_id')->select('invoice_id'))
                ->tap($search),
            // كما InvoiceListController::deferred (الزبون ومدى تاريخ التسليم)
            'deferred' => Invoice::where('placement', Invoice::DEFERRED)
                ->when(! empty($filters['customer']), function ($q) use ($filters) {
                    $normalized = ArabicNormalizer::normalize((string) $filters['customer']);
                    $q->where(fn ($w) => $w->where('customer_name', 'like', '%'.trim((string) $filters['customer']).'%')
                        ->orWhereHas('customer', fn ($c) => $c->where('name_normalized', 'like', "%{$normalized}%")));
                })
                ->when(! empty($filters['from']), fn ($q) => $q->whereDate('delivery_date', '>=', $filters['from']))
                ->when(! empty($filters['to']), fn ($q) => $q->whereDate('delivery_date', '<=', $filters['to'])),
            // 2.3: فواتير هذا الملف المستبعدة سابقاً (للعلم، وإعادة إدراجها جماعياً اختيارياً)
            'batch_excluded' => Invoice::where('placement', Invoice::EXCLUDED)->whereIn('id', ImportBatchItem::where('batch_id', $batchId)->whereNotNull('invoice_id')->select('invoice_id'))
                ->tap($search),
            default => throw ValidationException::withMessages(['scope' => 'قائمة غير معروفة.']),
        };
    }

    /**
     * المعاينة: المؤهَّل، والمستثنى بفئاته وأسبابه وأمثلته، وملخص بالحالة والمنطقة.
     *
     * @param  array<int, int>|null  $ids  null = كل نتائج التصفية
     * @return array{action: string, eligible: Collection, skipped: array<int, array>, total: int, summary: array}
     */
    public function preview(string $scope, array $filters, ?array $ids): array
    {
        $action = self::SCOPES[$scope] ?? throw ValidationException::withMessages(['scope' => 'قائمة غير معروفة.']);
        $query = $this->query($scope, $filters);
        $selected = collect();
        $columns = ['id', 'invoice_number', 'customer_id', 'customer_name', 'placement', 'area', 'area_key', 'invoice_amount', 'distribution_sheet_id', 'delivery_date', 'exclusion_reason', 'cancelled_reason'];
        if ($ids !== null) {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            foreach (array_chunk($ids, BulkWrite::CHUNK) as $chunk) {
                $selected = $selected->concat(Invoice::whereIn('id', $chunk)->get($columns));
            }
        } else {
            $selected = $query->orderBy('id')->get($columns);
        }

        $from = [InvoiceBulkOperation::EXCLUDE => Invoice::UNASSIGNED, InvoiceBulkOperation::CANCEL => Invoice::DEFERRED, InvoiceBulkOperation::RESTORE => Invoice::EXCLUDED][$action];
        $skipped = [];
        $skip = function (string $text, Collection $rows) use (&$skipped) {
            if ($rows->isNotEmpty()) {
                $skipped[] = ['text' => $text, 'count' => $rows->count(), 'examples' => $rows->take(self::EXAMPLES)->pluck('invoice_number')->all(), 'ids' => $rows->pluck('id')->all()];
            }
        };
        $wrong = $selected->reject(fn ($i) => $i->placement === $from);
        $skip('ليست في الحالة المطلوبة ('.(Invoice::PLACEMENTS[$from] ?? $from).') — ربما تغيّرت منذ فتح القائمة:', $wrong);
        $candidates = $selected->filter(fn ($i) => $i->placement === $from)->values();

        // نشاط أو إغلاق مالي على الفاتورة: لا تُستبعد ولا تُلغى جماعياً
        if ($action !== InvoiceBulkOperation::RESTORE) {
            foreach ([
                'payment_allocations' => 'عليها دفعات مالية موزَّعة (مغلقة مالياً):',
                'invoice_adjustments' => 'عليها تعديلات لاحقة:',
                'print_events' => 'طُبعت للصيدلية:',
            ] as $table => $text) {
                $hit = [];
                foreach (array_chunk($candidates->pluck('id')->all(), BulkWrite::CHUNK) as $chunk) {
                    foreach (DB::table($table)->whereIn('invoice_id', $chunk)->distinct()->pluck('invoice_id') as $id) {
                        $hit[(int) $id] = true;
                    }
                }
                $rows = $candidates->filter(fn ($i) => isset($hit[(int) $i->id]));
                $skip($text, $rows);
                $candidates = $candidates->reject(fn ($i) => isset($hit[(int) $i->id]))->values();
            }
        }

        return [
            'action' => $action,
            'eligible' => $candidates,
            'skipped' => $skipped,
            'total' => $selected->count(),
            'summary' => [
                'amount' => round((float) $candidates->sum('invoice_amount'), 2),
                'by_area' => $candidates->groupBy(fn ($i) => $i->area ?: 'بلا منطقة')->map->count()->sortDesc()->take(15)->all(),
                'by_placement' => $selected->groupBy(fn ($i) => Invoice::PLACEMENTS[$i->placement] ?? $i->placement)->map->count()->all(),
            ],
        ];
    }

    /**
     * التنفيذ: كل المؤهَّل أو لا شيء، بسبب مشترك، مع عملية جماعية بمعرّف.
     */
    public function execute(string $scope, array $filters, ?array $ids, string $reason, User $user): InvoiceBulkOperation
    {
        $reason = trim($reason);
        $preview = $this->preview($scope, $filters, $ids);
        $action = $preview['action'];
        if ($action !== InvoiceBulkOperation::RESTORE && mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'السبب المشترك إلزامي (3 أحرف على الأقل).']);
        }
        if ($preview['eligible']->isEmpty()) {
            throw ValidationException::withMessages(['invoices' => 'لا توجد فواتير مؤهَّلة لهذا الإجراء في التحديد.']);
        }

        return DB::transaction(function () use ($preview, $action, $scope, $filters, $reason, $user) {
            $uuid = (string) Str::uuid();
            $invoices = $this->reload($preview['eligible']->pluck('id')->all());
            $count = match ($action) {
                InvoiceBulkOperation::EXCLUDE => $this->editor->excludeMany($invoices, $reason, $user, $uuid),
                InvoiceBulkOperation::CANCEL => $this->editor->cancelMany($invoices, $reason, $user, $uuid),
                InvoiceBulkOperation::RESTORE => $this->editor->restoreMany($invoices, $user, $uuid),
            };
            $operation = InvoiceBulkOperation::create([
                'uuid' => $uuid, 'action' => $action, 'reason' => $reason !== '' ? $reason : null, 'user_id' => $user->id,
                'batch_id' => $filters['batch'] ?? null, 'filters' => ['scope' => $scope] + array_filter($filters, fn ($v) => $v !== null && $v !== ''),
                'invoice_ids' => $invoices->pluck('id')->all(), 'skipped' => array_map(fn ($s) => array_diff_key($s, ['ids' => 1]), $preview['skipped']), 'count' => $count,
            ]);
            ActivityLog::record($this->summaryAction($action), null, [], ['count' => $count, 'reason' => $operation->reason], [
                'bulk_operation_id' => $uuid, 'scope' => $scope, 'batch_id' => $operation->batch_id,
                'skipped' => collect($preview['skipped'])->map(fn ($s) => ['text' => $s['text'], 'count' => $s['count']])->all(),
                'amount' => $preview['summary']['amount'],
            ]);

            return $operation;
        });
    }

    /**
     * معاينة إعادة الإدراج لعملية جماعية: الفواتير التي ما زالت على حالتها وسببها.
     */
    public function undoPreview(InvoiceBulkOperation $operation): array
    {
        if (! $operation->isUndoable()) {
            return ['eligible' => collect(), 'total' => count($operation->invoice_ids), 'skipped' => [[
                'text' => $operation->undone_at ? 'أُعيد إدراج هذه العملية سابقاً ('.$operation->undone_at->copy()->setTimezone('Asia/Damascus')->format('Y-m-d H:i').').' : 'هذه العملية ليست استبعاداً أو إلغاءً، فلا تُعاد.',
                'count' => count($operation->invoice_ids), 'examples' => [],
            ]]];
        }
        [$state, $column] = $operation->action === InvoiceBulkOperation::EXCLUDE ? [Invoice::EXCLUDED, 'exclusion_reason'] : [Invoice::CANCELLED, 'cancelled_reason'];
        $invoices = $this->reload($operation->invoice_ids);
        $eligible = $invoices->filter(fn ($i) => $i->placement === $state && trim((string) $i->{$column}) === (string) $operation->reason)->values();
        $changed = $invoices->reject(fn ($i) => $eligible->contains('id', $i->id));
        $missing = count($operation->invoice_ids) - $invoices->count();
        $skipped = [];
        if ($changed->isNotEmpty()) {
            $skipped[] = ['text' => 'تغيّرت حالتها أو سببها بعد العملية (تبقى كما هي):', 'count' => $changed->count(), 'examples' => $changed->take(self::EXAMPLES)->pluck('invoice_number')->all()];
        }
        if ($missing > 0) {
            $skipped[] = ['text' => 'لم تعد موجودة (حُذفت بتراجع عن دفعتها):', 'count' => $missing, 'examples' => []];
        }

        return ['eligible' => $eligible, 'skipped' => $skipped, 'total' => count($operation->invoice_ids)];
    }

    public function undo(InvoiceBulkOperation $operation, User $user): InvoiceBulkOperation
    {
        return DB::transaction(function () use ($operation, $user) {
            $operation = InvoiceBulkOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if (! $operation->isUndoable()) {
                throw ValidationException::withMessages(['operation' => 'هذه العملية لا تُعاد (أُعيدت سابقاً أو ليست استبعاداً/إلغاءً).']);
            }
            $preview = $this->undoPreview($operation);
            if ($preview['eligible']->isEmpty()) {
                throw ValidationException::withMessages(['operation' => 'لا توجد فواتير ما زالت على حالة العملية لإعادة إدراجها.']);
            }
            $uuid = (string) Str::uuid();
            [$action, $count] = $operation->action === InvoiceBulkOperation::EXCLUDE
                ? [InvoiceBulkOperation::RESTORE, $this->editor->restoreMany($preview['eligible'], $user, $uuid)]
                : [InvoiceBulkOperation::UNCANCEL, $this->editor->uncancelMany($preview['eligible'], $user, $uuid)];
            $undo = InvoiceBulkOperation::create([
                'uuid' => $uuid, 'action' => $action, 'reason' => 'إعادة إدراج العملية '.$operation->uuid, 'user_id' => $user->id, 'batch_id' => $operation->batch_id,
                'filters' => ['undo_of' => $operation->uuid], 'invoice_ids' => $preview['eligible']->pluck('id')->all(),
                'skipped' => $preview['skipped'], 'count' => $count, 'undoes_id' => $operation->id,
            ]);
            $operation->update(['undone_at' => now(), 'undone_by' => $user->id]);
            ActivityLog::record($this->summaryAction($action), null, ['count' => $operation->count], ['count' => $count], [
                'bulk_operation_id' => $uuid, 'undoes' => $operation->uuid, 'skipped' => collect($preview['skipped'])->map(fn ($s) => ['text' => $s['text'], 'count' => $s['count']])->all(),
            ]);

            return $undo;
        });
    }

    private function summaryAction(string $action): string
    {
        return match ($action) {
            InvoiceBulkOperation::EXCLUDE => 'invoices.bulk_excluded',
            InvoiceBulkOperation::CANCEL => 'invoices.bulk_cancelled',
            default => 'invoices.bulk_restored',
        };
    }

    private function reload(array $ids): Collection
    {
        $rows = collect();
        foreach (array_chunk(array_values($ids), BulkWrite::CHUNK) as $chunk) {
            $rows = $rows->concat(Invoice::whereIn('id', $chunk)->lockForUpdate()->get());
        }

        return $rows->sortBy('id')->values();
    }
}
