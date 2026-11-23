<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * مطابقة موقع الزيارة/الدفعة مع موقع الصيدلية المرجعي، وتثبيت المرجع.
 *
 * حالات المرجع: none (لا مرجع) / unconfirmed (غير مؤكد) / confirmed (مؤكد).
 * - بلا GPS ⇒ no_gps.
 * - بلا مرجع ⇒ يُحفظ الموقع مرجعاً غير مؤكد (location_source = auto) ⇒ no_reference.
 * - بمرجع (مؤكد أو غير مؤكد) ⇒ مسافة Haversine؛ أبعد من location_radius_m ⇒ far (تحذير دون منع).
 * - الموزع يُسأل صراحة عن تثبيت المرجع حين لا يكون مؤكداً (can_pin)، والتثبيت طلب إضافي لا يحجب الحفظ:
 *   من يسبق يفوز، ولا يُستبدل مرجع مؤكد إلا من المشرف.
 */
class LocationService
{
    private const EARTH_RADIUS_M = 6371000;

    /**
     * @return array{flag: string, distance_m: ?int}
     */
    public function evaluate(Customer $customer, ?float $lat, ?float $lng): array
    {
        if ($lat === null || $lng === null) {
            return ['flag' => 'no_gps', 'distance_m' => null];
        }

        if ($customer->latitude === null || $customer->longitude === null) {
            $customer->forceFill([
                'latitude' => $lat,
                'longitude' => $lng,
                'location_confirmed' => false,
                'location_source' => 'auto',
            ])->save();

            return ['flag' => 'no_reference', 'distance_m' => null];
        }

        $distance = (int) round($this->distance((float) $customer->latitude, (float) $customer->longitude, $lat, $lng));

        return ['flag' => $distance > $this->radius() ? 'far' : 'ok', 'distance_m' => $distance];
    }

    /**
     * المسافة بالأمتار بين نقطتين (Haversine).
     */
    public function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function radius(): int
    {
        return (int) Setting::get('location_radius_m', 150);
    }

    public function maxPinAccuracy(): int
    {
        return (int) Setting::get('location_pin_max_accuracy_m', 50);
    }

    /**
     * طلب الموزع المرتبط بزيارة «مزار» أو دفعة: تثبيت موقعه كمرجع، أو «ليس الآن».
     * يُستدعى بعد حفظ السجل (ولا يحجبه)، ويعيد حقول الاستجابة التي تبني عليها الواجهة قرارها.
     *
     * @param  array{client_uuid: string, latitude: ?float, longitude: ?float, accuracy_m: ?float, pin_reference: bool, pin_declined: bool, visited: bool, distance_m: ?int, state_before: string}  $request
     * @return array{reference_state: string, distance_m: ?int, can_pin: bool, pin_result: ?string, pin_message: ?string}
     */
    public function handlePinRequest(Customer $customer, User $user, array $request, array $meta = []): array
    {
        $hasGps = $request['latitude'] !== null && $request['longitude'] !== null;
        $eligible = $hasGps && $request['visited'];
        $state = $request['state_before'];
        $result = null;
        $message = null;

        if ($eligible && $request['pin_reference']) {
            [$result, $message] = $this->pinFromDistributor($customer, $user, (float) $request['latitude'], (float) $request['longitude'], $request['accuracy_m'], $request['client_uuid'], $meta);
            if ($result === 'pinned') {
                $state = 'confirmed';
            }
        } elseif ($eligible && $request['pin_declined'] && $state !== 'confirmed') {
            $this->logSkip($customer, $request['client_uuid'], 'declined', $meta);
            $result = 'declined';
        }

        $current = $customer->fresh()->referenceState();
        if ($result !== 'pinned') {
            // المرجع ثبّته غيره في الأثناء: لا سؤال بعد الآن
            $state = $current === 'confirmed' ? 'confirmed' : $state;
        }

        return [
            'reference_state' => $state,
            'distance_m' => $request['distance_m'],
            'can_pin' => $eligible && $state !== 'confirmed',
            'pin_result' => $result,
            'pin_message' => $message,
        ];
    }

    /**
     * تثبيت الموزع: من يسبق يفوز (قفل على صف الزبون)، والدقة يجب ألا تكون أسوأ من الإعداد.
     *
     * @return array{0: string, 1: string} [النتيجة، رسالة للواجهة]
     */
    private function pinFromDistributor(Customer $customer, User $user, float $lat, float $lng, ?float $accuracy, string $clientUuid, array $meta): array
    {
        $max = $this->maxPinAccuracy();
        if ($accuracy === null || $accuracy > $max) {
            $this->logSkip($customer, $clientUuid, 'inaccurate', $meta + ['accuracy_m' => $accuracy, 'max_accuracy_m' => $max]);

            return ['inaccurate', 'الموقع غير دقيق بعد، انتظر لحظات وأعد المحاولة.'];
        }

        return DB::transaction(function () use ($customer, $user, $lat, $lng, $accuracy, $clientUuid, $meta) {
            $locked = Customer::whereKey($customer->id)->lockForUpdate()->first();

            if ($locked->referenceState() === 'confirmed') {
                // الطلب نفسه أُعيد إرساله (idempotent) ⇒ لا تسجيل ثانٍ
                if ((int) $locked->location_pinned_by === (int) $user->id && $locked->location_source === 'distributor') {
                    return ['already_pinned', 'الموقع مثبّت مسبقاً.'];
                }
                $this->logSkip($locked, $clientUuid, 'already_confirmed', $meta);

                return ['ignored_confirmed', 'ثبّت غيرك موقع هذه الصيدلية قبلك، فلم يُغيَّر.'];
            }

            $old = $this->snapshot($locked);
            $locked->forceFill([
                'latitude' => $lat,
                'longitude' => $lng,
                'location_confirmed' => true,
                'location_pinned_by' => $user->id,
                'location_pinned_at' => now(),
                'location_accuracy_m' => (int) round($accuracy),
                'location_source' => 'distributor',
            ])->save();

            ActivityLog::record('customer.location_pinned', $locked, $old, $this->snapshot($locked), $meta + [
                'customer' => $locked->name,
                'client_uuid' => $clientUuid,
            ]);

            return ['pinned', 'ثُبّت موقعك مرجعاً مؤكداً للصيدلية.'];
        });
    }

    /**
     * تثبيت موقع زيارة/دفعة كمرجع مؤكد (من شاشة المشرف). يستبدل أي مرجع سابق.
     */
    public function pin(Customer $customer, float $lat, float $lng, User $by, array $meta = []): void
    {
        $old = $this->snapshot($customer);
        $customer->forceFill([
            'latitude' => $lat,
            'longitude' => $lng,
            'location_confirmed' => true,
            'location_pinned_by' => $by->id,
            'location_pinned_at' => now(),
            'location_accuracy_m' => null,
            'location_source' => 'supervisor',
        ])->save();

        ActivityLog::record('customer.location_pinned', $customer, $old, $this->snapshot($customer), $meta + ['customer' => $customer->name]);
    }

    /**
     * «ليس الآن» أو تجاهل التثبيت: مرة واحدة لكل زيارة/دفعة (بنفس client_uuid).
     */
    private function logSkip(Customer $customer, string $clientUuid, string $reason, array $meta): void
    {
        $exists = ActivityLog::where('action', 'customer.location_pin_skipped')
            ->where('subject_type', $customer->getMorphClass())
            ->where('subject_id', $customer->id)
            ->where('meta', 'like', '%'.$clientUuid.'%')
            ->exists();
        if ($exists) {
            return;
        }

        ActivityLog::record('customer.location_pin_skipped', $customer, [], ['reason' => $reason], $meta + [
            'customer' => $customer->name,
            'client_uuid' => $clientUuid,
        ]);
    }

    private function snapshot(Customer $customer): array
    {
        return [
            'latitude' => $customer->latitude !== null ? (float) $customer->latitude : null,
            'longitude' => $customer->longitude !== null ? (float) $customer->longitude : null,
            'location_confirmed' => (bool) $customer->location_confirmed,
            'source' => $customer->location_source,
            'accuracy_m' => $customer->location_accuracy_m,
        ];
    }
}
