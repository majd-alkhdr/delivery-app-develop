<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\Payment;
use App\Models\PharmacyLock;
use App\Models\SheetVisit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * زيارات الصيدليات في الكشف (sheet_visits): سجل واحد لكل (كشف، زبون) يُحدَّث عند إعادة التسجيل.
 */
class VisitService
{
    public function __construct(
        private PharmacyLockService $locks,
        private LocationService $location,
    ) {
    }

    /**
     * @param  array{accuracy_m?: ?float, pin_reference?: bool, pin_declined?: bool}  $pin  طلب تثبيت الموقع المرجعي (اختياري)
     * @return array{visit: SheetVisit, created: bool, location: array}
     */
    public function record(User $user, DistributionSheet $sheet, Customer $customer, string $status, ?string $reason, string $clientUuid, ?float $lat, ?float $lng, array $pin = [], bool $outsideSheet = false, ?array $ackContext = null): array
    {
        if ($existing = SheetVisit::where('client_uuid', $clientUuid)->first()) {
            if ((int) $existing->user_id !== (int) $user->id) {
                abort(403, 'معرّف الزيارة مستخدم مسبقاً.');
            }

            return ['visit' => $existing, 'created' => false, 'location' => $this->pinFor($existing, $customer, $user, $lat, $lng, $pin, $customer->referenceState())];
        }

        $stateBefore = $customer->referenceState();

        if ($status === 'not_visited' && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'اختر سبب عدم الزيارة.']);
        }

        try {
            $visit = DB::transaction(function () use ($user, $sheet, $customer, $status, $reason, $clientUuid, $lat, $lng, $outsideSheet, $ackContext) {
                $this->locks->acquire($customer, $sheet->distribution_date->toDateString(), $user, $sheet->id);

                if ($status === 'not_visited' && Payment::active()->where('distribution_sheet_id', $sheet->id)->where('customer_id', $customer->id)->exists()) {
                    throw ValidationException::withMessages(['status' => 'سُجّلت دفعة لهذه الصيدلية، فلا يمكن تسجيلها «غير مزارة». ألغِ الدفعة أولاً.']);
                }

                $location = $this->location->evaluate($customer, $lat, $lng);
                $visit = SheetVisit::where('distribution_sheet_id', $sheet->id)->where('customer_id', $customer->id)->lockForUpdate()->first();
                $old = $visit ? ['status' => $visit->status, 'reason' => $visit->reason, 'user_id' => $visit->user_id] : [];

                $attributes = [
                    'client_uuid' => $clientUuid,
                    'user_id' => $user->id,
                    'status' => $status,
                    'reason' => $status === 'not_visited' ? trim((string) $reason) : null,
                    'visited_at' => now(),
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'distance_m' => $location['distance_m'],
                    'location_flag' => $location['flag'],
                    'voided_at' => null,
                    // المرحلة 6B-D4: زيارة دون اتصال لصيدلية أُخرجت من الكشف لاحقاً
                    'outside_sheet' => $outsideSheet,
                    // المرحلة 7H: من الطابور دون اتصال قبل اطلاع الموزع على تعديل
                    'recorded_before_ack' => $ackContext !== null,
                    'ack_context' => $ackContext,
                ];

                if ($visit) {
                    $visit->update($attributes);
                } else {
                    $visit = SheetVisit::create($attributes + ['distribution_sheet_id' => $sheet->id, 'customer_id' => $customer->id]);
                }

                if ($sheet->status === 'pending') {
                    $sheet->update(['status' => 'in_progress']);
                }

                ActivityLog::record('visit.recorded', $visit, $old, [
                    'status' => $visit->status,
                    'reason' => $visit->reason,
                ], [
                    'sheet_id' => $sheet->id,
                    'customer_id' => $customer->id,
                    'customer' => $customer->name,
                    'location_flag' => $location['flag'],
                ]);
                if ($ackContext !== null) {
                    ActivityLog::record('visit.recorded_before_ack', $visit, ['recorded_before_ack' => false], ['recorded_before_ack' => true], [
                        'sheet_id' => $sheet->id, 'customer_id' => $customer->id, 'customer' => $customer->name, 'status' => $visit->status,
                    ] + $ackContext);
                }

                return $visit;
            });
        } catch (QueryException $e) {
            if ($existing = SheetVisit::where('client_uuid', $clientUuid)->first()) {
                return ['visit' => $existing, 'created' => false, 'location' => $this->pinFor($existing, $customer, $user, $lat, $lng, $pin, $customer->fresh()->referenceState())];
            }
            throw $e;
        }

        return ['visit' => $visit, 'created' => true, 'location' => $this->pinFor($visit, $customer, $user, $lat, $lng, $pin, $stateBefore)];
    }

    /**
     * سؤال/طلب تثبيت الموقع المرجعي: فقط لزيارة «مزار» بإحداثيات GPS.
     */
    private function pinFor(SheetVisit $visit, Customer $customer, User $user, ?float $lat, ?float $lng, array $pin, string $stateBefore): array
    {
        return $this->location->handlePinRequest($customer->fresh(), $user, [
            'client_uuid' => $visit->client_uuid,
            'latitude' => $lat,
            'longitude' => $lng,
            'accuracy_m' => $pin['accuracy_m'] ?? null,
            'pin_reference' => (bool) ($pin['pin_reference'] ?? false),
            'pin_declined' => (bool) ($pin['pin_declined'] ?? false),
            'visited' => $visit->status === 'visited' && $visit->voided_at === null,
            'distance_m' => $visit->distance_m,
            'state_before' => $stateBefore,
        ], ['source' => 'distributor', 'visit_id' => $visit->id, 'sheet_id' => $visit->distribution_sheet_id, 'accuracy_m' => $pin['accuracy_m'] ?? null]);
    }

    /**
     * فصل زبون عن كشف بعد خروج آخر فواتيره منه (إخراج/نقل/مسودة):
     * تُلغى (void) زيارته الفعّالة في هذا الكشف حتى لا تعود حالتها إن أُعيد إليه لاحقاً،
     * ويُفك قفل يومه إن لم يبقَ لصاحب القفل أي زيارة أو دفعة فعّالة للزبون في ذلك اليوم. لا حذف.
     * الدفعات لا تُمس (سجلات مالية؛ إلغاؤها قرار يدوي بسبب).
     */
    public function detachCustomer(DistributionSheet $sheet, int $customerId, User $by, string $reason): void
    {
        if ($sheet->invoices()->where('placement', 'in_sheet')->where('customer_id', $customerId)->exists()) {
            return; // ما زالت له فواتير في الكشف
        }

        $visits = SheetVisit::active()->where('distribution_sheet_id', $sheet->id)->where('customer_id', $customerId)->get();
        foreach ($visits as $visit) {
            $visit->update(['voided_at' => now()]);
            ActivityLog::record('visit.voided', $visit, ['voided_at' => null], ['voided_at' => $visit->voided_at->toDateTimeString(), 'reason' => $reason], [
                'sheet_id' => $sheet->id,
                'customer_id' => $customerId,
            ]);
        }

        if (! $sheet->distribution_date) {
            return;
        }
        $date = $sheet->distribution_date->toDateString();
        $lock = PharmacyLock::where('customer_id', $customerId)->whereDate('distribution_date', $date)->whereNotNull('user_id')->first();
        if (! $lock) {
            return;
        }

        $stillActive = SheetVisit::active()->where('customer_id', $customerId)->where('user_id', $lock->user_id)
            ->whereHas('sheet', fn ($q) => $q->whereDate('distribution_date', $date))->exists()
            || Payment::active()->where('customer_id', $customerId)->where('entered_by', $lock->user_id)
                ->whereHas('sheet', fn ($q) => $q->whereDate('distribution_date', $date))->exists();

        if (! $stillActive) {
            $old = ['user_id' => $lock->user_id];
            $lock->update(['user_id' => null, 'released_at' => now(), 'released_by' => $by->id]);
            ActivityLog::record('pharmacy.unlocked', $lock, $old, ['user_id' => null], [
                'customer_id' => $customerId,
                'distribution_date' => $date,
                'sheet_id' => $sheet->id,
                'auto' => true,
                'reason' => $reason,
            ]);
        }
    }

    /**
     * زيارة تلقائية عند تسجيل دفعة (يُستدعى داخل transaction الدفعة، بعد أخذ القفل).
     */
    public function markVisited(DistributionSheet $sheet, Customer $customer, User $user, ?float $lat, ?float $lng, array $location): SheetVisit
    {
        $visit = SheetVisit::where('distribution_sheet_id', $sheet->id)->where('customer_id', $customer->id)->lockForUpdate()->first();

        if ($visit && $visit->status === 'visited' && $visit->voided_at === null) {
            return $visit;
        }

        $attributes = [
            'user_id' => $user->id,
            'status' => 'visited',
            'reason' => null,
            'visited_at' => now(),
            'latitude' => $lat,
            'longitude' => $lng,
            'distance_m' => $location['distance_m'],
            'location_flag' => $location['flag'],
            'voided_at' => null,
        ];

        if ($visit) {
            $visit->update($attributes);
        } else {
            $visit = SheetVisit::create($attributes + [
                'client_uuid' => (string) Str::uuid(),
                'distribution_sheet_id' => $sheet->id,
                'customer_id' => $customer->id,
            ]);
        }

        ActivityLog::record('visit.recorded', $visit, [], ['status' => 'visited', 'via' => 'payment'], [
            'sheet_id' => $sheet->id,
            'customer_id' => $customer->id,
            'customer' => $customer->name,
        ]);

        return $visit;
    }
}
