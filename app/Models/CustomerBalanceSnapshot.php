<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * لقطة رصيد زبون عند تطبيق ملف أرصدة (المرحلة 7B): القديم والجديد والفرق والتفسير التقديري.
 * customers.balance يبقى «الخام» كما في المحاسبة، واللقطات هي التاريخ.
 */
class CustomerBalanceSnapshot extends Model
{
    public const OK = 'ok';
    public const FLAGGED = 'flagged';

    public const KINDS = [
        'changed' => 'تغيّر',
        'created' => 'زبون جديد',
        'zeroed' => 'غاب عن الملف فصُفِّر',
        'unchanged' => 'بلا تغيير (دفعات مصدَّرة للفحص)',
    ];

    /** جملة طريقة التفسير (تظهر في الواجهة) */
    public const METHOD_NOTE = 'التفسير تقديري يعتمد على ما سجّله التطبيق بين الملفين.';

    protected $fillable = [
        'customer_id', 'balance_import_id', 'batch_id', 'kind', 'balance', 'prev_balance', 'delta', 'as_of', 'prev_as_of',
        'exported_payments_sum', 'invoice_effect_sum', 'unexplained', 'explanation', 'details', 'status', 'scope', 'exported_mismatch',
        'seen_by', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'prev_balance' => 'decimal:2',
            'delta' => 'decimal:2',
            'exported_payments_sum' => 'decimal:2',
            'invoice_effect_sum' => 'decimal:2',
            'unexplained' => 'decimal:2',
            'as_of' => 'date',
            'prev_as_of' => 'date',
            'details' => 'array',
            'exported_mismatch' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function balanceImport(): BelongsTo
    {
        return $this->belongsTo(BalanceImport::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isFlagged(): bool
    {
        return $this->status === self::FLAGGED;
    }

    /**
     * تغيّرات رصيد لم «يطّلع» عليها المستخدم (المندوب) — آخر لقطة لكل زبون، فوق حدّ التنبيه وخلال 30 يوماً.
     *
     * @param  array<int, int>  $customerIds
     * @return \Illuminate\Support\Collection<int, self> customer_id => اللقطة
     */
    public static function unseenFor(array $customerIds, User $user): \Illuminate\Support\Collection
    {
        if (! $customerIds) {
            return collect();
        }
        $min = (float) Setting::get('balance_notice_min_change', 1);
        $acked = \Illuminate\Support\Facades\DB::table('balance_change_acks')->where('user_id', $user->id)->pluck('snapshot_id')->flip();

        return static::whereIn('customer_id', $customerIds)
            ->where('kind', '!=', 'created')
            ->where('created_at', '>=', now()->subDays(30))
            ->orderBy('id')->get()
            ->filter(fn ($s) => abs((float) $s->delta) >= $min)
            ->keyBy('customer_id')
            ->reject(fn ($s) => isset($acked[$s->id]));
    }

    /**
     * «اطلعت»: لقطة الزبون هذه وما قبلها للمستخدم.
     */
    public function markSeenBy(User $user): void
    {
        foreach (static::where('customer_id', $this->customer_id)->where('id', '<=', $this->id)->pluck('id') as $id) {
            \Illuminate\Support\Facades\DB::table('balance_change_acks')->insertOrIgnore(['snapshot_id' => $id, 'user_id' => $user->id, 'seen_at' => now()]);
        }
    }

    /**
     * «تغيّر الرصيد من 5000 إلى 4800»
     */
    public function changeLabel(): string
    {
        return 'تغيّر الرصيد من '.number_format((float) ($this->prev_balance ?? 0), 0).' إلى '.number_format((float) $this->balance, 0);
    }
}
