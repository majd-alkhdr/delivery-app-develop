<?php

namespace App\Http\Controllers;

use App\Models\InvoiceBulkOperation;
use App\Services\BulkInvoiceActionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * المرحلة 7F: الاستبعاد/الإلغاء الجماعي وإعادة الإدراج — معاينة (ملخص ومستثنى بأسبابه) ثم تنفيذ بمعاملة واحدة.
 * مسؤول الكشوف والأدمن (كالإجراء الفردي).
 */
class BulkInvoiceController extends Controller
{
    public function preview(Request $request, BulkInvoiceActionService $service)
    {
        [$scope, $filters, $ids] = $this->selection($request);
        $preview = $service->preview($scope, $filters, $ids);

        return view('invoices.bulk-preview', [
            'preview' => $preview, 'scope' => $scope, 'filters' => $filters, 'ids' => $ids,
            'reasons' => BulkInvoiceActionService::REASONS, 'back' => $this->backUrl($request, $scope, $filters),
        ]);
    }

    public function store(Request $request, BulkInvoiceActionService $service)
    {
        [$scope, $filters, $ids] = $this->selection($request);
        $request->validate([
            'reason_choice' => 'nullable|string|max:255',
            'reason_other' => 'nullable|string|max:1000',
        ]);
        $reason = $request->input('reason_choice') === '__other' || ! $request->filled('reason_choice')
            ? (string) $request->input('reason_other', '')
            : (string) $request->input('reason_choice');

        $operation = $service->execute($scope, $filters, $ids, $reason, $request->user());

        return redirect($this->backUrl($request, $scope, $filters))->with('success', $operation->label().': '.$operation->count.' فاتورة'
            .($operation->skipped ? ' (واستُثني '.collect($operation->skipped)->sum('count').' بأسبابها)' : '').'. رقم العملية '.substr($operation->uuid, 0, 8).'.')
            ->with('bulk_operation_id', $operation->id);
    }

    public function undoPreview(InvoiceBulkOperation $operation, BulkInvoiceActionService $service)
    {
        return view('invoices.bulk-undo', ['operation' => $operation, 'preview' => $service->undoPreview($operation)]);
    }

    public function undo(Request $request, InvoiceBulkOperation $operation, BulkInvoiceActionService $service)
    {
        $request->validate(['confirm' => 'accepted'], ['confirm.accepted' => 'أكّد إعادة الإدراج.']);
        $undo = $service->undo($operation, $request->user());

        return redirect()->route('bulk-invoices.undo.preview', $operation)->with('success', $undo->label().': '.$undo->count.' فاتورة.');
    }

    /**
     * @return array{0: string, 1: array, 2: ?array}
     */
    private function selection(Request $request): array
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(array_keys(BulkInvoiceActionService::SCOPES))],
            'batch' => 'nullable|string|exists:import_batches,id',
            'q' => 'nullable|string|max:100',
            'customer' => 'nullable|string|max:255',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'all' => 'nullable|boolean',
            'ids' => 'nullable|array',
            'ids.*' => 'integer',
        ], ['ids.*.integer' => 'تحديد غير صالح.']);
        if (str_starts_with($data['scope'], 'batch_') && empty($data['batch'])) {
            abort(422, 'الدفعة مطلوبة لهذا التحديد.');
        }
        $all = $request->boolean('all');
        if (! $all && empty($data['ids'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['ids' => 'حدد فاتورة واحدة على الأقل، أو «كل نتائج التصفية».']);
        }
        $filters = array_filter(['batch' => $data['batch'] ?? null, 'q' => $data['q'] ?? null, 'customer' => $data['customer'] ?? null,
            'from' => $data['from'] ?? null, 'to' => $data['to'] ?? null], fn ($v) => $v !== null && $v !== '');

        return [$data['scope'], $filters, $all ? null : array_map('intval', $data['ids'])];
    }

    private function backUrl(Request $request, string $scope, array $filters): string
    {
        return match ($scope) {
            'batch_unassigned' => route('sheet-manager.batches.show', ['batch' => $filters['batch'], 'tab' => 'sheets']).'#unassigned',
            'batch_deferred' => route('sheet-manager.batches.show', ['batch' => $filters['batch'], 'tab' => 'deferred']),
            'batch_excluded' => route('sheet-manager.batches.show', ['batch' => $filters['batch'], 'tab' => 'invoices']),
            'deferred' => route('invoices.deferred', array_diff_key($filters, ['batch' => 1])),
            default => route('invoices.unassigned'),
        };
    }
}
