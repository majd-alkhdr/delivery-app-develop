<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * المرحلة 8B: إشعار للمندوب بتعديل خطته (يبقى ظاهراً حتى «اطلعت»).
 */
class RepPlanNotice extends Model
{
    protected $fillable = ['rep_user_id', 'plan_date', 'text', 'created_by', 'seen_at'];

    protected function casts(): array
    {
        return ['plan_date' => 'date', 'seen_at' => 'datetime'];
    }

    public static function notify(int $repId, string $text, ?string $date = null, ?int $by = null): self
    {
        return static::create(['rep_user_id' => $repId, 'plan_date' => $date, 'text' => $text, 'created_by' => $by]);
    }
}
