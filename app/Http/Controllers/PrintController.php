<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PrintEvent;
use App\Services\DistributorScope;
use App\Support\PrintSettings;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * الطباعة (المرحلة 4): إعدادات الطباعة للواجهة، صفحة اختبار الطابعة، وأحداث الطباعة.
 * لا يخرج من هنا أي رصيد خام ولا فاتورة مؤجلة.
 */
class PrintController extends Controller
{
    /**
     * إعدادات الطباعة (للأدوار المسموح لها بالطباعة)، مع ETag.
     */
    public function config(Request $request)
    {
        $payload = [
            'settings' => PrintSettings::values(),
            'ranges' => PrintSettings::ranges(),
        ];
        $etag = '"'.sha1(json_encode($payload)).'"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'private, no-cache'];

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, $headers);
        }

        return response()->json($payload + ['etag' => $etag], 200, $headers);
    }

    /**
     * صفحة اختبار الطابعة (بلا أي بيانات حقيقية).
     */
    public function test()
    {
        return view('print.test', [
            'config' => PrintSettings::values(),
            'ranges' => PrintSettings::ranges(),
        ]);
    }

    /**
     * تسجيل حدث طباعة (idempotent بـ client_uuid). المعاينة تُسجَّل ولا تُحسب طباعة.
     */
    public function storeEvent(Request $request, DistributorScope $scope)
    {
        $data = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'kind' => ['required', Rule::in(PrintEvent::KINDS)],
            // الإيصال: معرّف الدفعة، أو client_uuid الخاص بها (دفعة طُبعت قبل مزامنتها)
            'payment_id' => ['nullable', 'integer'],
            'payment_client_uuid' => ['nullable', 'uuid', Rule::requiredIf(fn () => $request->input('kind') === 'receipt' && ! $request->filled('payment_id'))],
            'invoice_id' => ['nullable', 'integer', 'required_if:kind,invoice'],
            'distribution_sheet_id' => ['nullable', 'integer', 'required_if:kind,merged'],
            'customer_id' => ['nullable', 'integer', 'required_if:kind,merged'],
            'mode' => ['required', Rule::in(PrintEvent::MODES)],
            'status' => ['required', Rule::in(['success', 'failed'])],
            'error' => ['nullable', 'string', 'max:500'],
            'device_label' => ['nullable', 'string', 'max:100'],
            'printed_at' => ['nullable', 'date'],
        ]);
        $user = $request->user();
        $uuid = strtolower($data['client_uuid']);

        if ($existing = PrintEvent::where('client_uuid', $uuid)->first()) {
            if ((int) $existing->user_id !== (int) $user->id) {
                abort(403, 'معرّف حدث الطباعة مستخدم مسبقاً.');
            }

            return response()->json(['success' => true, 'duplicate' => true] + $this->counts($existing), 200);
        }

        $refs = $this->authorizeReference($user, $data, $scope);

        try {
            $event = PrintEvent::create($refs + [
                'client_uuid' => $uuid,
                'user_id' => $user->id,
                'kind' => $data['kind'],
                'mode' => $data['mode'],
                'status' => $data['status'],
                'error' => $data['error'] ?? null,
                'device_label' => $data['device_label'] ?? null,
                'printed_at' => isset($data['printed_at']) ? \Carbon\Carbon::parse($data['printed_at']) : now(),
            ]);
        } catch (QueryException $e) {
            $existing = PrintEvent::where('client_uuid', $uuid)->first();
            if (! $existing) {
                throw $e;
            }

            return response()->json(['success' => true, 'duplicate' => true] + $this->counts($existing), 200);
        }

        // المعاينة لا تُعد طباعة، فلا تُسجَّل في سجل النشاط
        if ($event->mode !== 'preview') {
            ActivityLog::record($event->status === 'success' ? 'print.success' : 'print.failed', $event, [], [
                'kind' => $event->kind,
                'mode' => $event->mode,
                'status' => $event->status,
                'error' => $event->error,
            ], array_filter([
                'payment_id' => $event->payment_id,
                'invoice_id' => $event->invoice_id,
                'sheet_id' => $event->distribution_sheet_id,
                'customer_id' => $event->customer_id,
                'device_label' => $event->device_label,
            ], fn ($v) => $v !== null));
        }

        return response()->json(['success' => true, 'duplicate' => false] + $this->counts($event), 201);
    }

    /**
     * الموزع (والمندوب) يطبع محتواه فقط: دفعاته، وفواتير كشوفه المرئية له، وزبائن مناطقه.
     *
     * @return array<string, int|null> المراجع المعتمدة للحفظ
     */
    private function authorizeReference($user, array $data, DistributorScope $scope): array
    {
        if (! $user->hasRole(Role::Distributor, Role::Rep)) {
            abort(403, 'طباعة بيانات الكشوف متاحة للموزع والمندوب فقط.');
        }

        if ($data['kind'] === 'receipt') {
            $payment = ! empty($data['payment_id'])
                ? Payment::find($data['payment_id'])
                : Payment::where('client_uuid', strtolower($data['payment_client_uuid']))->first();
            if (! $payment && empty($data['payment_id'])) {
                abort(404, 'الدفعة لم تُزامَن بعد.');   // يعيد العميل المحاولة لاحقاً
            }
            if (! $payment || (int) $payment->entered_by !== (int) $user->id) {
                abort(403, 'هذه الدفعة ليست لك.');
            }

            return ['payment_id' => $payment->id, 'distribution_sheet_id' => $payment->distribution_sheet_id, 'customer_id' => $payment->customer_id, 'invoice_id' => null];
        }

        if ($data['kind'] === 'invoice') {
            $invoice = Invoice::find($data['invoice_id']);
            $sheet = $invoice?->distributionSheet;
            if (! $sheet || Gate::forUser($user)->denies('view', $sheet)
                || ! $sheet->invoicesVisibleTo($user)->whereKey($invoice->id)->exists()) {
                abort(403, 'هذه الفاتورة ليست ضمن ما تراه.');
            }

            return ['invoice_id' => $invoice->id, 'distribution_sheet_id' => $sheet->id, 'customer_id' => $invoice->customer_id, 'payment_id' => null];
        }

        $sheet = DistributionSheet::find($data['distribution_sheet_id']);
        $customer = Customer::find($data['customer_id']);
        if (! $sheet || ! $customer || Gate::forUser($user)->denies('view', $sheet)
            || ! $scope->customerVisibleInSheet($sheet, $user, $customer->id)) {
            abort(403, 'هذا الزبون ليس ضمن مناطقك في هذا الكشف.');
        }

        return ['distribution_sheet_id' => $sheet->id, 'customer_id' => $customer->id, 'payment_id' => null, 'invoice_id' => null];
    }

    /**
     * عدد الطباعات الفعلية للمرجع نفسه (لوسم «نسخة مكررة»).
     */
    private function counts(PrintEvent $event): array
    {
        $query = PrintEvent::counted()->where('kind', $event->kind);
        match ($event->kind) {
            'receipt' => $query->where('payment_id', $event->payment_id),
            'invoice' => $query->where('invoice_id', $event->invoice_id),
            default => $query->where('distribution_sheet_id', $event->distribution_sheet_id)->where('customer_id', $event->customer_id),
        };

        return ['print_count' => $query->count()];
    }
}
