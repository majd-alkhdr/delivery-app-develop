<?php

namespace App\Services\Reps;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\RepPlanVisit;
use App\Models\User;
use App\Services\LocationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * المرحلة 8B: تسجيل زيارة المندوب وتصحيحها. الخادم وحده يصنّف الموقع، بدالة المسافة نفسها لزيارة الموزع
 * (LocationService::distance) ودون أي تعديل على موقع الصيدلية (لا مرجع تلقائي ولا تثبيت من هنا).
 *
 * «تمت الزيارة»: no_gps (بتأكيد صريح) ← no_pharmacy_location ← low_accuracy (بتأكيد أو أعد المحاولة) ← mismatch (بتأكيد) ← matched.
 * ما يحتاج تأكيداً ولم يُؤكَّد يُرجع needs_confirmation ولا يحفظ شيئاً.
 */
class RepPlanVisitService
{
    public function __construct(private RepPlanService $plans, private LocationService $location)
    {
    }

    /**
     * @param  array{client_uuid: string, customer_id: int, plan_date: string, status: string, reason_code?: ?string, reason_text?: ?string,
     *     lat?: ?float, lng?: ?float, accuracy?: ?float, confirm?: ?string, correction?: bool}  $input
     * @return array{result: string, visit?: RepPlanVisit, message: string, confirm?: string, distance_m?: ?int, accuracy_m?: ?int, http: int}
     */
    public function record(User $rep, array $input): array
    {
        $existing = RepPlanVisit::where('client_uuid', $input['client_uuid'])->first();
        if ($existing) {
            if ((int) $existing->rep_user_id !== $rep->id) {
                abort(409, 'معرّف الإرسال مستعمل.');
            }

            return ['result' => 'duplicate', 'visit' => $existing, 'message' => 'سُجّلت هذه الزيارة مسبقاً.', 'http' => 200];
        }

        $date = $input['plan_date'];
        $this->assertRepDate($date);
        $item = $this->plans->itemOfCustomer($rep, $date, (int) $input['customer_id']);
        if (! $item) {
            throw ValidationException::withMessages(['customer_id' => 'هذه الصيدلية ليست في خطتك لهذا اليوم.']);
        }
        $customer = Customer::findOrFail((int) $input['customer_id']);

        $current = RepPlanVisit::where('rep_user_id', $rep->id)->where('customer_id', $customer->id)->whereDate('plan_date', $date)->first();
        if ($current && empty($input['correction'])) {
            return ['result' => 'exists', 'visit' => $current, 'message' => 'سُجّلت حالة هذه الصيدلية اليوم مسبقاً ('.$current->statusText().'). استعمل «تغيير القرار» لتصحيحها.', 'http' => 409];
        }
        if ($current && $date !== RepPlanService::today()) {
            throw ValidationException::withMessages(['plan_date' => 'انتهى اليوم: لا يصحّح الزيارة إلا المشرف أو الأدمن بسبب مكتوب.']);
        }

        $fields = $this->fields($customer, $input);
        if (isset($fields['confirm'])) {
            return $fields + ['result' => 'needs_confirmation', 'http' => 409];
        }

        $visit = DB::transaction(function () use ($rep, $customer, $date, $item, $input, $fields, $current) {
            if ($current) {
                $old = $current->logValues();
                $current->fill($fields + ['client_uuid' => $input['client_uuid'], 'corrected_at' => now()])->save();
                ActivityLog::record('rep_plan.visit_corrected', $current, $old, $current->logValues(), ['customer' => $customer->name, 'plan_date' => $date, 'by' => 'rep']);

                return $current;
            }
            $visit = RepPlanVisit::create($fields + [
                'client_uuid' => $input['client_uuid'],
                'rep_user_id' => $rep->id,
                'customer_id' => $customer->id,
                'plan_date' => $date,
                'supervisor_user_id' => $item['supervisor_id'],
                'scope_type' => $item['type'],
                'scope_value' => $item['value'],
            ]);
            ActivityLog::record('rep_plan.visit_recorded', $visit, [], $visit->logValues(), ['customer' => $customer->name, 'plan_date' => $date]);

            return $visit;
        });

        return ['result' => $current ? 'corrected' : 'saved', 'visit' => $visit, 'message' => $this->savedMessage($visit), 'http' => $current ? 200 : 201];
    }

    /**
     * حقول الزيارة، أو طلب تأكيد (confirm + message) دون حفظ.
     */
    private function fields(Customer $customer, array $input): array
    {
        $blank = ['reason_code' => null, 'reason_text' => null, 'visited_at' => null, 'lat' => null, 'lng' => null, 'accuracy_m' => null,
            'pharmacy_lat' => null, 'pharmacy_lng' => null, 'distance_m' => null, 'location_status' => null, 'location_note' => null];

        if ($input['status'] === RepPlanVisit::NOT_VISITED) {
            $code = $input['reason_code'] ?? null;
            if (! isset(RepPlanVisit::REASONS[$code])) {
                throw ValidationException::withMessages(['reason_code' => 'اختر سبب عدم الزيارة.']);
            }
            $text = trim((string) ($input['reason_text'] ?? ''));
            if ($code === 'other' && $text === '') {
                throw ValidationException::withMessages(['reason_text' => 'اكتب السبب عند اختيار «أخرى».']);
            }

            return ['status' => RepPlanVisit::NOT_VISITED, 'reason_code' => $code, 'reason_text' => $text !== '' ? $text : null] + $blank;
        }

        $confirm = $input['confirm'] ?? null;
        $lat = isset($input['lat']) ? (float) $input['lat'] : null;
        $lng = isset($input['lng']) ? (float) $input['lng'] : null;
        $accuracy = isset($input['accuracy']) ? (int) round((float) $input['accuracy']) : null;
        $base = ['status' => RepPlanVisit::VISITED, 'visited_at' => now()] + $blank;
        $hasPharmacy = $customer->latitude !== null && $customer->longitude !== null;
        $pharmacy = $hasPharmacy ? ['pharmacy_lat' => (float) $customer->latitude, 'pharmacy_lng' => (float) $customer->longitude] : [];

        if ($lat === null || $lng === null) {
            if ($confirm !== RepPlanVisit::NO_GPS) {
                return ['confirm' => RepPlanVisit::NO_GPS, 'message' => 'تعذّر تحديد موقعك. سيُسجَّل أنه لم يُتحقق من الموقع.'];
            }

            return ['location_status' => RepPlanVisit::NO_GPS, 'location_note' => 'تعذّر تحديد موقع المندوب، وأكّد تسجيل الزيارة بلا تحقق.'] + $pharmacy + $base;
        }

        $gps = ['lat' => $lat, 'lng' => $lng, 'accuracy_m' => $accuracy];
        if (! $hasPharmacy) {
            return $gps + ['location_status' => RepPlanVisit::NO_PHARMACY_LOCATION, 'location_note' => 'لا يوجد موقع محفوظ لهذه الصيدلية، تعذّرت المطابقة.'] + $base;
        }

        $distance = (int) round($this->location->distance((float) $customer->latitude, (float) $customer->longitude, $lat, $lng));
        $gps += $pharmacy + ['distance_m' => $distance];
        $minAccuracy = RepPlanService::setting('plan_min_gps_accuracy_m');
        if ($accuracy === null || $accuracy > $minAccuracy) {
            if ($confirm !== RepPlanVisit::LOW_ACCURACY) {
                return ['confirm' => RepPlanVisit::LOW_ACCURACY, 'accuracy_m' => $accuracy, 'distance_m' => $distance,
                    'message' => 'دقة موقعك ضعيفة ('.($accuracy ?? '؟').' م، المطلوب '.$minAccuracy.' م أو أفضل). أعد المحاولة للحصول على قراءة أدق، أو سجّل الزيارة بوسم «دقة ضعيفة».'];
            }

            return $gps + ['location_status' => RepPlanVisit::LOW_ACCURACY, 'location_note' => 'دقة الموقع '.($accuracy ?? '؟').' م أسوأ من '.$minAccuracy.' م.'] + $base;
        }

        $max = RepPlanService::setting('plan_location_max_distance_m');
        if ($distance > $max) {
            if ($confirm !== RepPlanVisit::MISMATCH) {
                return ['confirm' => RepPlanVisit::MISMATCH, 'distance_m' => $distance,
                    'message' => 'موقعك يبعد '.$distance.' م عن الصيدلية. هل أنت في الصيدلية؟'];
            }

            return $gps + ['location_status' => RepPlanVisit::MISMATCH, 'location_note' => 'أبعد من '.$max.' م، وأكّد المندوب أنه في الصيدلية.'] + $base;
        }

        return $gps + ['location_status' => RepPlanVisit::MATCHED] + $base;
    }

    private function savedMessage(RepPlanVisit $visit): string
    {
        return $visit->status === RepPlanVisit::VISITED ? 'سُجّلت الزيارة: '.$visit->locationText().'.' : 'سُجّل: '.$visit->statusText().'.';
    }

    /**
     * التاريخ المسموح للمندوب: اليوم، أو حتى rep_plan_backfill_days يوماً مضت. لا مستقبل.
     */
    public function assertRepDate(string $date): void
    {
        $today = RepPlanService::today();
        if ($date > $today) {
            throw ValidationException::withMessages(['plan_date' => 'لا تُسجَّل زيارة بتاريخ مستقبلي.']);
        }
        $oldest = Carbon::parse($today)->subDays(RepPlanService::setting('rep_plan_backfill_days'))->toDateString();
        if ($date < $oldest) {
            throw ValidationException::withMessages(['plan_date' => 'انتهى وقت التسجيل لهذا اليوم.']);
        }
    }

    public function repCanRecord(string $date): bool
    {
        try {
            $this->assertRepDate($date);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    /**
     * تصحيح المشرف/الأدمن بعد انتهاء اليوم (أو في أي وقت) بسبب مكتوب. التحويل إلى «تمت» بلا GPS ⇒ no_gps.
     */
    public function correctBySupervisor(RepPlanVisit $visit, User $by, string $status, ?string $reasonCode, ?string $reasonText, string $note): RepPlanVisit
    {
        if (! $by->isAdmin() && (int) $visit->supervisor_user_id !== $by->id) {
            abort(403, 'هذه الزيارة لنطاق مشرف آخر.');
        }
        $old = $visit->logValues();
        if ($status === RepPlanVisit::NOT_VISITED) {
            if (! isset(RepPlanVisit::REASONS[$reasonCode])) {
                throw ValidationException::withMessages(['reason_code' => 'اختر سبب عدم الزيارة.']);
            }
            $visit->fill(['status' => $status, 'reason_code' => $reasonCode, 'reason_text' => $reasonText, 'visited_at' => null,
                'location_status' => null, 'location_note' => null]);
        } else {
            $visit->fill(['status' => RepPlanVisit::VISITED, 'reason_code' => null, 'reason_text' => null, 'visited_at' => $visit->visited_at ?? now(),
                'location_status' => $visit->location_status ?? RepPlanVisit::NO_GPS,
                'location_note' => $visit->location_status ? $visit->location_note : 'صحّحها '.$by->name.' إلى «تمت» بلا تحقق من الموقع.']);
        }
        $visit->corrected_at = now();
        $visit->save();
        ActivityLog::record('rep_plan.visit_corrected', $visit, $old, $visit->logValues(), ['by' => $by->isAdmin() ? 'admin' : 'supervisor', 'note' => $note]);

        return $visit;
    }
}
