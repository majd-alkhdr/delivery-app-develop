<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * تسجيل الدفعات وإلغاؤها (void) وبيانات الإيصال.
 * - idempotency: نفس client_uuid ⇒ يُعاد السجل الموجود (HTTP 200) ولا يُنشأ ثانٍ.
 * - العملة: SYP بلا سعر؛ USD (للموزع والمندوب فقط) بسعر إلزامي (المرحلة 5)، وخارج النطاق يحتاج تأكيداً.
 *   القديم المعلّق في الطابور بلا سعر (legacy_no_rate) يُقبل بـ rate_is_estimate ويضبطه المحاسب.
 * - القفل: (الزبون + يوم التوزيع) عبر PharmacyLockService داخل الـtransaction.
 */
class PaymentService
{
    public function __construct(
        private PharmacyLockService $locks,
        private LocationService $location,
        private VisitService $visits,
        private CustomerBalanceService $balances,
        private PaymentImageService $images,
        private \App\Services\Finance\RepReceivableService $receivables,
    ) {
    }

    /**
     * دفعة المندوب (المرحلة 6): بلا كشف ولا قفل صيدلية ولا زيارة، والصورة إلزامية في الطلب نفسه.
     * التحقق من الصورة (وكل المدخلات) قبل إنشاء أي سجل، ثم الدفعة وسجل الصورة وملفها في transaction واحد.
     *
     * @return array{payment: Payment, created: bool, location: array}
     */
    public function recordRep(User $rep, Customer $customer, array $input, ?UploadedFile $image): array
    {
        if ($existing = $this->existing($input['client_uuid'], $rep)) {
            // الدفعة الموجودة لها صورة دائماً: لا يُمس شيء
            return ['payment' => $existing, 'created' => false, 'location' => $this->pinFor($existing, $customer, $rep, $input, $customer->referenceState())];
        }
        if ($rep->role !== Role::Rep) {
            abort(403, 'التحصيل بصورة الإيصال للمندوب فقط.');
        }
        if (! $rep->hasCostCenter()) {
            abort(403, 'لم يُضبط مركز الكلفة لحسابك، راجع الإدارة.');
        }

        $book = trim((string) ($input['book_receipt_no'] ?? ''));
        if ($book === '') {
            throw ValidationException::withMessages(['book_receipt_no' => 'رقم الإيصال الورقي مطلوب.']);
        }
        $amounts = $this->amounts($rep, $input);

        $duplicate = Payment::active()->where('source', 'rep')->where('entered_by', $rep->id)->where('book_receipt_no', $book)->exists();
        if ($duplicate && empty($input['confirm_duplicate_book_no'])) {
            throw new HttpResponseException(response()->json([
                'message' => 'رقم الإيصال هذا مستخدم سابقاً ('.$book.'). تأكد منه ثم أعد الإرسال للتأكيد.',
                'requires_confirmation' => true,
                'confirm_field' => 'confirm_duplicate_book_no',
                'errors' => ['book_receipt_no' => ['رقم الإيصال هذا مستخدم سابقاً.']],
            ], 422));
        }

        // الصورة: تحقق كامل وإعادة ترميز قبل أي كتابة
        $prepared = $this->images->prepare($image);
        $stateBefore = $customer->referenceState();
        $knownDebt = $this->receivables->knownDebtCents($rep, $customer->id);

        $writtenPath = null;
        $this->images->forgetLastWritten();
        try {
            $payment = DB::transaction(function () use ($rep, $customer, $input, $amounts, $book, $duplicate, $prepared, $knownDebt, &$writtenPath) {
                if ($existing = Payment::where('client_uuid', $input['client_uuid'])->lockForUpdate()->first()) {
                    return $existing;
                }

                // الموقع يُحفظ بعلامته، ولا يُعرض للمندوب تحذير «بعيد» ولا يُمنع شيء
                $location = $this->location->evaluate($customer, $input['latitude'] ?? null, $input['longitude'] ?? null);
                $balance = $this->balanceFields($customer, null, $amounts, $input);

                $payment = Payment::create($balance['fields'] + [
                    'client_uuid' => $input['client_uuid'],
                    'receipt_number' => $this->uniqueReceipt($input['receipt_number'] ?? null),
                    'distribution_sheet_id' => null,
                    'customer_id' => $customer->id,
                    'entered_by' => $rep->id,
                    'entered_role' => $rep->role->value,
                    'source' => 'rep',
                    'amount' => $amounts['amount'],
                    'currency' => $amounts['currency'],
                    'exchange_rate' => $amounts['exchange_rate'],
                    'amount_syp' => $amounts['amount_syp'],
                    'rate_is_estimate' => $amounts['rate_is_estimate'],
                    'note' => $amounts['note'],
                    'status' => 'pending',
                    'book_receipt_no' => $book,
                    'duplicate_book_no' => $duplicate,
                    'exceeds_known_debt' => $amounts['amount_syp'] !== null && (int) round($amounts['amount_syp'] * 100) > $knownDebt,
                    'cost_center_code' => $rep->cost_center_code,
                    'cost_center_label' => $rep->cost_center_label,
                    'review_status' => 'none',
                    'latitude' => $input['latitude'] ?? null,
                    'longitude' => $input['longitude'] ?? null,
                    'distance_m' => $location['distance_m'],
                    'location_flag' => $location['flag'],
                    'collected_at' => now(),
                ]);

                $image = $this->images->attach($payment, $prepared, $rep);
                $writtenPath = $image->path;

                ActivityLog::record('payment.created', $payment, [], [
                    'amount' => (float) $payment->amount,
                    'currency' => $payment->currency,
                    'exchange_rate' => $payment->exchange_rate !== null ? (float) $payment->exchange_rate : null,
                    'amount_syp' => $payment->amount_syp !== null ? (float) $payment->amount_syp : null,
                    'receipt_number' => $payment->receipt_number,
                    'book_receipt_no' => $book,
                    'source' => 'rep',
                ], [
                    'customer_id' => $customer->id,
                    'customer' => $customer->name,
                    'cost_center' => $rep->cost_center_label,
                    'location_flag' => $location['flag'],
                    'duplicate_book_no' => $duplicate,
                    'exceeds_known_debt' => (bool) $payment->exceeds_known_debt,
                ]);
                ActivityLog::record('rep.image_uploaded', $payment, [], [
                    'sha256' => $image->sha256,
                    'size_bytes' => $image->size_bytes,
                ], ['payment_id' => $payment->id]);

                return $payment;
            });
        } catch (\Throwable $e) {
            // أي فشل بعد كتابة الملف: الـtransaction ألغى السجلات، ويُحذف الملف فلا يبقى شيء
            $this->images->deleteFile($writtenPath ?? $this->images->lastWrittenPath());
            if ($e instanceof QueryException && ($existing = $this->existing($input['client_uuid'], $rep))) {
                return ['payment' => $existing, 'created' => false, 'location' => $this->pinFor($existing, $customer, $rep, $input, $customer->fresh()->referenceState())];
            }
            throw $e;
        }

        return [
            'payment' => $payment,
            'created' => $payment->wasRecentlyCreated,
            'location' => $this->pinFor($payment, $customer, $rep, $input, $payment->wasRecentlyCreated ? $stateBefore : $customer->fresh()->referenceState()),
        ];
    }

    /**
     * قرار سؤال تثبيت الموقع للواجهة (لا إحداثيات خام، فقط المسافة).
     */
    public function locationResponse(array $location): array
    {
        return [
            'reference_state' => $location['reference_state'],
            'distance_m' => $location['distance_m'],
            'can_pin' => $location['can_pin'],
            'pin_result' => $location['pin_result'],
            'pin_message' => $location['pin_message'],
        ];
    }

    /**
     * @param  array{client_uuid: string, receipt_number?: ?string, amount: float, currency: string, exchange_rate?: ?float, rate_confirmed?: bool, note?: ?string, latitude?: ?float, longitude?: ?float}  $input
     * @return array{payment: Payment, created: bool, location: array}
     */
    public function record(User $user, DistributionSheet $sheet, Customer $customer, string $source, array $input): array
    {
        if ($existing = $this->existing($input['client_uuid'], $user)) {
            // إعادة الإرسال نفسها قد تحمل طلب تثبيت الموقع (من الطابور المحلي): يُعالَج مرة واحدة
            return ['payment' => $existing, 'created' => false, 'location' => $this->pinFor($existing, $customer, $user, $input, $customer->referenceState())];
        }

        $stateBefore = $customer->referenceState();

        $amounts = $this->amounts($user, $input);

        try {
            $payment = DB::transaction(function () use ($user, $sheet, $customer, $source, $input, $amounts) {
                $this->locks->acquire($customer, $sheet->distribution_date->toDateString(), $user, $sheet->id);

                if ($existing = Payment::where('client_uuid', $input['client_uuid'])->lockForUpdate()->first()) {
                    return $existing;
                }

                $location = $this->location->evaluate($customer, $input['latitude'] ?? null, $input['longitude'] ?? null);
                // المرحلة 7B: الرصيد قبل/بعد يُخزَّن مع الدفعة (الإيصال ثابت عند إعادة الطباعة)
                $balance = $this->balanceFields($customer, $sheet, $amounts, $input);

                $payment = Payment::create($balance['fields'] + [
                    'client_uuid' => $input['client_uuid'],
                    'receipt_number' => $this->uniqueReceipt($input['receipt_number'] ?? null),
                    'distribution_sheet_id' => $sheet->id,
                    'customer_id' => $customer->id,
                    'entered_by' => $user->id,
                    'entered_role' => $user->role->value,
                    'source' => $source,
                    'amount' => $amounts['amount'],
                    'currency' => $amounts['currency'],
                    'exchange_rate' => $amounts['exchange_rate'],
                    'amount_syp' => $amounts['amount_syp'],
                    'rate_is_estimate' => $amounts['rate_is_estimate'],
                    'note' => $amounts['note'],
                    'status' => 'pending',
                    'latitude' => $input['latitude'] ?? null,
                    'longitude' => $input['longitude'] ?? null,
                    'distance_m' => $location['distance_m'],
                    'location_flag' => $location['flag'],
                    'collected_at' => now(),
                    // المرحلة 6B-D4: دفعة دون اتصال لصيدلية أُخرجت من الكشف لاحقاً (تُحفظ ولا تُفقد)
                    'outside_sheet' => (bool) ($input['outside_sheet'] ?? false),
                    // المرحلة 7H: من الطابور دون اتصال قبل اطلاع الموزع على تعديل (يُقبل دائماً ويُوسَم)
                    'recorded_before_ack' => ! empty($input['ack_context']),
                    'ack_context' => $input['ack_context'] ?? null,
                ]);

                $this->logBalanceMismatch($payment, $balance);
                if (! empty($input['ack_context'])) {
                    ActivityLog::record('payment.recorded_before_ack', $payment, ['recorded_before_ack' => false], ['recorded_before_ack' => true], [
                        'sheet_id' => $sheet->id, 'customer_id' => $customer->id, 'customer' => $customer->name,
                        'amount' => (float) $payment->amount, 'currency' => $payment->currency, 'receipt_number' => $payment->receipt_number,
                    ] + $input['ack_context']);
                }

                // تسجيل الدفعة من الكشف يعني زيارة تلقائياً
                if ($source === 'sheet') {
                    $this->visits->markVisited($sheet, $customer, $user, $input['latitude'] ?? null, $input['longitude'] ?? null, $location);
                }

                if ($sheet->status === 'pending') {
                    $sheet->update(['status' => 'in_progress']);
                }

                ActivityLog::record('payment.created', $payment, [], [
                    'amount' => (float) $payment->amount,
                    'currency' => $payment->currency,
                    'exchange_rate' => $payment->exchange_rate !== null ? (float) $payment->exchange_rate : null,
                    'amount_syp' => $payment->amount_syp !== null ? (float) $payment->amount_syp : null,
                    'receipt_number' => $payment->receipt_number,
                    'source' => $source,
                ], [
                    'sheet_id' => $sheet->id,
                    'customer_id' => $customer->id,
                    'customer' => $customer->name,
                    'location_flag' => $location['flag'],
                ]);

                return $payment;
            });
        } catch (QueryException $e) {
            // سباق على client_uuid نفسه: أعد السجل الموجود
            if ($existing = $this->existing($input['client_uuid'], $user)) {
                return ['payment' => $existing, 'created' => false, 'location' => $this->pinFor($existing, $customer, $user, $input, $customer->fresh()->referenceState())];
            }
            throw $e;
        }

        return [
            'payment' => $payment,
            'created' => $payment->wasRecentlyCreated,
            'location' => $this->pinFor($payment, $customer, $user, $input, $payment->wasRecentlyCreated ? $stateBefore : $customer->fresh()->referenceState()),
        ];
    }

    /**
     * سؤال/طلب تثبيت الموقع المرجعي المرتبط بالدفعة (الدفعة تعني زيارة).
     */
    private function pinFor(Payment $payment, Customer $customer, User $user, array $input, string $stateBefore): array
    {
        return $this->location->handlePinRequest($customer->fresh(), $user, [
            'client_uuid' => $payment->client_uuid,
            'latitude' => $input['latitude'] ?? null,
            'longitude' => $input['longitude'] ?? null,
            'accuracy_m' => $input['accuracy_m'] ?? null,
            'pin_reference' => (bool) ($input['pin_reference'] ?? false),
            'pin_declined' => (bool) ($input['pin_declined'] ?? false),
            'visited' => ! $payment->isVoided(),
            'distance_m' => $payment->distance_m,
            'state_before' => $stateBefore,
        ], ['source' => 'distributor', 'payment_id' => $payment->id, 'sheet_id' => $payment->distribution_sheet_id, 'accuracy_m' => $input['accuracy_m'] ?? null]);
    }

    /**
     * إلغاء دفعة (لا حذف): الموزع لدفعاته قبل التصدير وفي اليوم نفسه، والمشرف والمحاسب والأدمن قبل التصدير.
     */
    public function void(Payment $payment, User $user, string $reason): Payment
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw ValidationException::withMessages(['reason' => 'سبب الإلغاء إلزامي (3 أحرف على الأقل).']);
        }
        if ($payment->isVoided()) {
            throw ValidationException::withMessages(['reason' => 'هذه الدفعة ملغاة مسبقاً.']);
        }
        if (! $this->canVoid($payment, $user)) {
            abort(403, 'لا يمكنك إلغاء هذه الدفعة (بعد التصدير، أو ليست دفعتك، أو من يوم سابق).');
        }

        $payment->update(['voided_at' => now(), 'void_reason' => trim($reason), 'voided_by' => $user->id]);

        ActivityLog::record('payment.voided', $payment, ['voided_at' => null], [
            'voided_at' => $payment->voided_at->toDateTimeString(),
            'reason' => $payment->void_reason,
        ], [
            'sheet_id' => $payment->distribution_sheet_id,
            'customer_id' => $payment->customer_id,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'receipt_number' => $payment->receipt_number,
        ]);

        return $payment;
    }

    public function canVoid(Payment $payment, User $user): bool
    {
        if ($payment->isVoided() || $payment->isExported()) {
            return false;
        }
        if ($user->hasRole(Role::Admin, Role::Supervisor, Role::Accountant)) {
            return true;
        }

        // الموزع والمندوب: دفعتهما فقط وفي يومها
        return $user->hasRole(Role::Distributor, Role::Rep)
            && (int) $payment->entered_by === (int) $user->id
            && $payment->collected_at?->copy()->setTimezone('Asia/Damascus')->toDateString() === now('Asia/Damascus')->toDateString();
    }

    /**
     * المرحلة 7B: الرصيد قبل الدفعة وبعدها من currentBalance (قبل إنشائها). إن أرسل الجهاز ما احتسبه دون اتصال
     * (وطبعه للصيدلية) يُحفظ ما طُبع كأصل، ويُسجَّل الفرق إن كان الخادم يحسب رقماً مختلفاً.
     *
     * @return array{fields: array, server_before: float, server_after: float, device: bool}
     */
    private function balanceFields(Customer $customer, ?DistributionSheet $sheet, array $amounts, array $input): array
    {
        $current = $this->balances->currentBalance($customer->fresh(), $sheet);
        $serverBefore = $current->net;
        $serverAfter = $amounts['amount_syp'] !== null ? round($serverBefore - (float) $amounts['amount_syp'], 2) : $serverBefore;
        $device = isset($input['device_balance_before']) && $input['device_balance_before'] !== null;

        return [
            'fields' => [
                'balance_before' => $device ? round((float) $input['device_balance_before'], 2) : $serverBefore,
                'balance_after' => $device ? round((float) ($input['device_balance_after'] ?? $input['device_balance_before']), 2) : $serverAfter,
                'balance_as_of' => $current->asOf,
                'balance_snapshot_id' => \App\Models\CustomerBalanceSnapshot::where('customer_id', $customer->id)->max('id'),
                'balance_source' => $device ? 'device' : 'server',
            ],
            'server_before' => $serverBefore,
            'server_after' => $serverAfter,
            'device' => $device,
        ];
    }

    private function logBalanceMismatch(Payment $payment, array $balance): void
    {
        if (! $balance['device'] || abs((float) $payment->balance_before - $balance['server_before']) < 0.01) {
            return;
        }
        ActivityLog::record('payment.balance_mismatch', $payment, [
            'balance_before' => (float) $payment->balance_before,
            'balance_after' => (float) $payment->balance_after,
        ], [
            'balance_before' => $balance['server_before'],
            'balance_after' => $balance['server_after'],
        ], [
            'receipt_number' => $payment->receipt_number,
            'customer_id' => $payment->customer_id,
            'note' => 'الإيصال المطبوع دون اتصال (القيم القديمة) يختلف عمّا يحسبه الخادم (الجديدة)؛ لم يُعدَّل الإيصال المطبوع.',
        ]);
    }

    /**
     * بيانات الإيصال (الطباعة في المرحلة 4). الرصيد المستحق فقط، وليس الخام.
     * المرحلة 7B: القيم المخزّنة مع الدفعة ثابتة؛ وإن اختلف الرصيد الحالي يُضاف «الرصيد الحالي الآن».
     * الدفعات القديمة بلا قيم مخزّنة تُحسب كما كانت مع «محسوب عند الطباعة».
     */
    public function receiptData(Payment $payment, bool $isDuplicate): array
    {
        $payment->loadMissing('customer', 'sheet', 'enteredBy');
        $sheet = $payment->sheet;
        $computedAtPrint = $payment->balance_before === null;
        $current = null;

        if (! $computedAtPrint) {
            $before = round((float) $payment->balance_before, 2);
            $after = $payment->isVoided() ? $before : round((float) $payment->balance_after, 2);
            $now = $this->balances->currentBalance($payment->customer, $sheet)->net;
            $current = abs($now - $after) >= 0.01 ? $now : null;
        } else {
            $date = $sheet?->distribution_date ?? $payment->collected_at;
            $due = $date ? $this->balances->dueBalance($payment->customer, $date) : (float) $payment->customer->balance;

            // دفعات سابقة غير ملغاة للزبون في اليوم نفسه (أي كشف)
            $previous = (float) Payment::active()
                ->where('customer_id', $payment->customer_id)
                ->where('id', '<', $payment->id)
                ->when($sheet, fn ($q) => $q->whereHas('sheet', fn ($s) => $s->whereDate('distribution_date', $sheet->distribution_date)))
                ->sum('amount_syp');

            $before = round($due - $previous, 2);
            $after = $payment->amount_syp !== null && ! $payment->isVoided() ? round($before - (float) $payment->amount_syp, 2) : $before;
        }

        return [
            'computed_at_print' => $computedAtPrint,
            'current_balance' => $current,
            'balance_as_of' => $payment->balance_as_of?->toDateString(),
            'payment_id' => $payment->id,
            'receipt_number' => $payment->receipt_number,
            'pharmacy' => $payment->customer->name,
            'area' => $payment->customer->area,
            'date' => $payment->collected_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d H:i'),
            'entered_by' => $payment->enteredBy?->name,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'exchange_rate' => $payment->exchange_rate !== null ? (float) $payment->exchange_rate : null,
            'amount_syp' => $payment->amount_syp !== null ? (float) $payment->amount_syp : null,
            'rate_is_estimate' => (bool) $payment->rate_is_estimate,
            'due_before' => $before,
            'due_after' => $after,
            'delivery_order_no' => $sheet?->delivery_order_no,
            'source' => $payment->source,
            'is_voided' => $payment->isVoided(),
            'is_duplicate' => $isDuplicate,
        ];
    }

    /**
     * JSON مختصر للواجهة.
     */
    public function toArray(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'client_uuid' => $payment->client_uuid,
            'receipt_number' => $payment->receipt_number,
            'customer_id' => $payment->customer_id,
            'sheet_id' => $payment->distribution_sheet_id,
            'source' => $payment->source,
            'book_receipt_no' => $payment->book_receipt_no,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'exchange_rate' => $payment->exchange_rate !== null ? (float) $payment->exchange_rate : null,
            'amount_syp' => $payment->amount_syp !== null ? (float) $payment->amount_syp : null,
            'rate_is_estimate' => (bool) $payment->rate_is_estimate,
            'note' => $payment->note,
            'location_flag' => $payment->location_flag,
            'distance_m' => $payment->distance_m,
            'voided' => $payment->isVoided(),
            'receipt_data_url' => route('payments.receipt-data', $payment),
        ];
    }

    // =====================================================================

    private function existing(string $uuid, User $user): ?Payment
    {
        $existing = Payment::where('client_uuid', $uuid)->first();
        if ($existing && (int) $existing->entered_by !== (int) $user->id) {
            abort(403, 'معرّف الدفعة مستخدم مسبقاً.');
        }

        return $existing;
    }

    /**
     * التحقق من المبلغ والعملة والسعر، وحساب amount_syp والملاحظة.
     *
     * @return array{amount: float, currency: string, exchange_rate: ?float, amount_syp: ?float, rate_is_estimate: bool, note: ?string}
     */
    private function amounts(User $user, array $input): array
    {
        $amount = round((float) $input['amount'], 2);
        $currency = $input['currency'] ?? 'SYP';
        $note = isset($input['note']) && trim((string) $input['note']) !== '' ? trim((string) $input['note']) : null;

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'المبلغ يجب أن يكون أكبر من صفر.']);
        }
        $max = (float) Setting::get('payment_amount_max', 1000000000);
        if ($amount > $max) {
            throw ValidationException::withMessages(['amount' => 'المبلغ أكبر من الحد المسموح ('.number_format($max).').']);
        }

        if ($currency === 'SYP') {
            return ['amount' => $amount, 'currency' => 'SYP', 'exchange_rate' => null, 'amount_syp' => $amount, 'rate_is_estimate' => false, 'note' => $note];
        }

        if (! $user->canEnterUsd()) {
            throw ValidationException::withMessages(['currency' => 'إدخال الدولار متاح للموزع والمندوب فقط.']);
        }

        $rate = isset($input['exchange_rate']) && $input['exchange_rate'] !== null ? (float) $input['exchange_rate'] : null;
        if ($rate === null) {
            // المرحلة 5: السعر إلزامي لكل دفعة دولار جديدة
            if (empty($input['legacy_no_rate'])) {
                throw ValidationException::withMessages(['exchange_rate' => 'سعر الصرف إلزامي لدفعة الدولار.']);
            }
            // دفعة قديمة معلّقة في الطابور بلا سعر: تُقبل بلا قيمة بالليرة، ويضبط المحاسب سعرها قبل التصدير
            return ['amount' => $amount, 'currency' => 'USD', 'exchange_rate' => null, 'amount_syp' => null, 'rate_is_estimate' => true, 'note' => $note];
        }
        if ($rate <= 0) {
            throw ValidationException::withMessages(['exchange_rate' => 'سعر الصرف يجب أن يكون أكبر من صفر.']);
        }

        $min = (float) Setting::get('usd_rate_min', 50);
        $maxRate = (float) Setting::get('usd_rate_max', 500);
        if (($rate < $min || $rate > $maxRate) && empty($input['rate_confirmed'])) {
            throw new HttpResponseException(response()->json([
                'message' => sprintf('سعر الصرف %s خارج النطاق المعتاد (%s – %s). تأكد منه ثم أعد الإرسال للتأكيد.', $this->num($rate), $this->num($min), $this->num($maxRate)),
                'requires_confirmation' => true,
                'confirm_field' => 'rate_confirmed',
                'errors' => ['exchange_rate' => ['سعر الصرف خارج النطاق المعتاد ويحتاج تأكيداً.']],
            ], 422));
        }

        $stamp = 'تم الحساب على '.$this->num($rate);
        if ($note === null || ! str_contains($note, $stamp)) {
            $note = trim(($note ? $note.' — ' : '').$stamp);
        }

        return ['amount' => $amount, 'currency' => 'USD', 'exchange_rate' => $rate, 'amount_syp' => round($amount * $rate, 2), 'rate_is_estimate' => false, 'note' => $note];
    }

    /**
     * رقم الإيصال: {device_prefix}-{yyyymmdd}-{seq} من العميل؛ عند التصادم تُضاف لاحقة.
     */
    private function uniqueReceipt(?string $requested): string
    {
        $requested = $requested !== null ? strtoupper(trim($requested)) : '';
        if (! preg_match('/^[A-Z0-9]{1,12}-\d{8}-\d{1,6}$/', $requested)) {
            $requested = 'SRV-'.now('Asia/Damascus')->format('Ymd').'-'.strtoupper(Str::random(5));
        }

        $candidate = $requested;
        $n = 1;
        while (Payment::where('receipt_number', $candidate)->exists()) {
            $n++;
            $candidate = $requested.'-'.$n;
        }

        return $candidate;
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
