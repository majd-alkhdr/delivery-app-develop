<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * المرحلة 8B: بند في الخطة الأسبوعية المتكررة لمندوب (منطقة أو كتلة ليوم من الأسبوع).
 * الأسبوع يبدأ السبت: weekday السبت=0، الأحد=1، … الجمعة=6.
 * يسري من starts_on إلى ends_on (null = مفتوح)؛ الحذف إنهاء (ends_on) كي تبقى تغطية الأيام الماضية كما كانت.
 */
class RepPlanTemplate extends Model
{
    public const AREA = 'area';
    public const BLOCK = 'block';

    public const TYPES = [self::AREA => 'منطقة', self::BLOCK => 'كتلة'];

    /** أسماء الأيام بترتيب weekday */
    public const WEEKDAYS = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'];

    protected $fillable = [
        'rep_user_id', 'supervisor_user_id', 'weekday', 'scope_type', 'scope_value', 'scope_label', 'active', 'starts_on', 'ends_on', 'created_by',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'starts_on' => 'date', 'ends_on' => 'date', 'weekday' => 'integer'];
    }

    /**
     * رقم يوم الأسبوع لتاريخ (السبت=0).
     */
    public static function weekdayOf(\DateTimeInterface|string $date): int
    {
        $dow = (int) \Carbon\Carbon::parse($date)->dayOfWeek;   // الأحد=0 … السبت=6

        return ($dow + 1) % 7;
    }

    /** البنود السارية في تاريخ معيّن (بلا شرط active: المعطَّل يُنهى بـ ends_on) */
    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->whereDate('starts_on', '<=', $date)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date));
    }

    /** البنود الحالية (من اليوم فصاعداً) */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('active', true)->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', today('Asia/Damascus')->toDateString()));
    }

    public function rep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rep_user_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_user_id');
    }

    public function scopeText(): string
    {
        return (self::TYPES[$this->scope_type] ?? $this->scope_type).' '.($this->scope_label ?? $this->scope_value);
    }

    public function logValues(): array
    {
        return [
            'weekday' => self::WEEKDAYS[$this->weekday] ?? $this->weekday,
            'scope' => $this->scopeText(),
            'supervisor_user_id' => $this->supervisor_user_id,
            'active' => (bool) $this->active,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
        ];
    }
}
