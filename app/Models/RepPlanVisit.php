<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * المرحلة 8B: زيارة مندوب لصيدلية في يوم من خطته (مستقلة عن زيارات الموزع sheet_visits).
 */
class RepPlanVisit extends Model
{
    public const VISITED = 'visited';
    public const NOT_VISITED = 'not_visited';

    public const MATCHED = 'matched';
    public const MISMATCH = 'mismatch';
    public const NO_PHARMACY_LOCATION = 'no_pharmacy_location';
    public const NO_GPS = 'no_gps';
    public const LOW_ACCURACY = 'low_accuracy';

    /** حالات الموقع بترتيب العرض، ونصها الذي يراه المشرف والمندوب */
    public const LOCATION_LABELS = [
        self::MATCHED => 'مطابقة للموقع',
        self::MISMATCH => 'بعيدة عن الموقع',
        self::NO_GPS => 'بلا تحديد موقع',
        self::LOW_ACCURACY => 'دقة موقع ضعيفة',
        self::NO_PHARMACY_LOCATION => 'الصيدلية بلا موقع محفوظ',
    ];

    /** أسباب «لم تتم» */
    public const REASONS = [
        'closed' => 'مغلقة',
        'owner_absent' => 'المالك غائب',
        'no_time' => 'لا يوجد وقت',
        'access' => 'مشكلة في الوصول',
        'refused' => 'رفض الاستقبال',
        'bad_time' => 'وقت غير مناسب',
        'other' => 'أخرى',
    ];

    protected $fillable = [
        'client_uuid', 'rep_user_id', 'customer_id', 'plan_date', 'supervisor_user_id', 'scope_type', 'scope_value', 'status', 'reason_code', 'reason_text',
        'visited_at', 'lat', 'lng', 'accuracy_m', 'pharmacy_lat', 'pharmacy_lng', 'distance_m', 'location_status', 'location_note', 'corrected_at',
    ];

    protected function casts(): array
    {
        return [
            'plan_date' => 'date',
            'visited_at' => 'datetime',
            'corrected_at' => 'datetime',
            'lat' => 'float',
            'lng' => 'float',
            'pharmacy_lat' => 'float',
            'pharmacy_lng' => 'float',
            'accuracy_m' => 'integer',
            'distance_m' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function rep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rep_user_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_user_id');
    }

    /**
     * النص العربي لحالة الزيارة (للمندوب والمشرف والتصدير).
     */
    public function statusText(): string
    {
        if ($this->status === self::NOT_VISITED) {
            $reason = self::REASONS[$this->reason_code] ?? $this->reason_code;

            return 'لم تتم: '.($this->reason_code === 'other' && $this->reason_text ? $this->reason_text : $reason);
        }

        return 'تمت الزيارة — '.$this->locationText();
    }

    public function locationText(): string
    {
        return match ($this->location_status) {
            self::MATCHED => 'مطابقة للموقع ('.$this->distance_m.' م)',
            self::MISMATCH => 'بعيدة عن الصيدلية '.$this->distance_m.' م (أكّد المندوب أنه فيها)',
            self::LOW_ACCURACY => 'دقة الموقع ضعيفة ('.$this->accuracy_m.' م)'.($this->distance_m !== null ? '، المسافة '.$this->distance_m.' م' : ''),
            self::NO_PHARMACY_LOCATION => 'لا يوجد موقع محفوظ لهذه الصيدلية، تعذّرت المطابقة',
            self::NO_GPS => 'لم يُتحقق من الموقع (تعذّر تحديد موقع المندوب)',
            default => '—',
        };
    }

    public function logValues(): array
    {
        return [
            'status' => $this->status,
            'reason_code' => $this->reason_code,
            'reason_text' => $this->reason_text,
            'location_status' => $this->location_status,
            'distance_m' => $this->distance_m,
        ];
    }
}
