<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DistributionSheet;
use App\Models\Setting;
use App\Models\SheetChangeNotice;
use App\Models\SheetNoticeAck;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * المرحلة 7H: اطلاع الموزع الإلزامي على تعديلات الكشف بعد إرساله.
 *
 * - الإشعار «الإلزامي»: نوعه ضمن إعداد ack_required_notice_types (الافتراضي: كل الأنواع السبعة).
 * - الاطلاع لكل إشعار ولكل موزع (sheet_notice_acks). الموزع يُلزَم بإشعارات مناطقه فقط (visibleQuery نفسها).
 * - الإشعار الذي له customer_id يمنع التسجيل (زيارة/دفعة) على تلك الصيدلية فقط حتى «اطلعت»؛ والذي بلا customer_id
 *   لا يمنع شيئاً ويبقى في شريط أعلى الكشف.
 * - عند مسؤول الكشوف والمشرف: «مُطَّلَع عليه» = اطّلع عليه موزع واحد على الأقل (مع أسماء من لم يطّلع من الموزعين المعنيين).
 */
class NoticeAckService
{
    public const SETTING = 'ack_required_notice_types';

    public function __construct(private SheetChangeNotifier $notifier)
    {
    }

    /**
     * @return array<int, string>
     */
    public static function requiredTypes(): array
    {
        $types = Setting::get(self::SETTING, SheetChangeNotice::TYPES);

        return array_values(array_intersect(SheetChangeNotice::TYPES, is_array($types) ? $types : []));
    }

    public static function typeLabel(string $type): string
    {
        return $type === 'balance_changed' ? 'تغيّر الرصيد' : (SheetChangeNotifier::VERBS[$type] ?? 'تعديل');
    }

    /**
     * الإشعارات الإلزامية التي لم يطّلع عليها هذا المستخدم في الكشف (ضمن مناطقه).
     */
    public function pendingQuery(DistributionSheet $sheet, User $user): Builder
    {
        return $this->notifier->visibleQuery($sheet, $user, true)
            ->whereIn('type', self::requiredTypes() ?: ['__none__'])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('sheet_notice_acks')
                ->whereColumn('sheet_notice_acks.notice_id', 'sheet_change_notices.id')
                ->where('sheet_notice_acks.user_id', $user->id));
    }

    /**
     * @return Collection<int, SheetChangeNotice>
     */
    public function pendingForCustomer(DistributionSheet $sheet, User $user, int $customerId): Collection
    {
        return $this->pendingQuery($sheet, $user)->where('customer_id', $customerId)->orderBy('id')->get();
    }

    /**
     * كل الإشعارات الإلزامية غير المُطَّلَع عليها لصيدليات الكشف (استعلام واحد) — customer_id => إشعارات.
     *
     * @return Collection<int, Collection<int, SheetChangeNotice>>
     */
    public function pendingByCustomer(DistributionSheet $sheet, User $user): Collection
    {
        if (! in_array($sheet->status, SheetChangeNotifier::NOTIFY_STATUSES, true)) {
            return collect();
        }

        return $this->pendingQuery($sheet, $user)->whereNotNull('customer_id')->orderBy('id')->get()->groupBy('customer_id');
    }

    /**
     * «اطلعت» على كل إشعارات صيدلية (الإلزامية غير المُطَّلَع عليها). يُسجَّل في ActivityLog.
     *
     * @return int عدد الإشعارات
     */
    public function ackCustomer(DistributionSheet $sheet, User $user, int $customerId): int
    {
        $notices = $this->pendingForCustomer($sheet, $user, $customerId);
        $this->store($sheet, $user, $notices, ['customer_id' => $customerId]);
        // تغيّر الرصيد (7B) له علامة «اطلعت» خاصة به على البطاقة: تُحدَّث معاً
        if ($notices->contains('type', 'balance_changed')) {
            $this->notifier->markBalanceSeen($sheet, $user, $customerId);
        }

        return $notices->count();
    }

    /**
     * «اطلعت» من شريط التعديلات: الإشعارات الإلزامية بلا صيدلية (ما يمنع التسجيل يبقى لاطلاعه على البطاقة).
     */
    public function ackGeneral(DistributionSheet $sheet, User $user): int
    {
        $notices = $this->pendingQuery($sheet, $user)->whereNull('customer_id')->orderBy('id')->get();
        $this->store($sheet, $user, $notices, ['customer_id' => null]);

        return $notices->count();
    }

    /**
     * «اطلعت» على تغيّر رصيد صيدلية (مسار 7B): يُعدّ اطلاعاً على إشعارات الرصيد نفسها.
     */
    public function ackBalance(DistributionSheet $sheet, User $user, int $customerId): int
    {
        $notices = $this->pendingQuery($sheet, $user)->where('customer_id', $customerId)->where('type', 'balance_changed')->get();
        $this->store($sheet, $user, $notices, ['customer_id' => $customerId]);

        return $notices->count();
    }

    private function store(DistributionSheet $sheet, User $user, Collection $notices, array $meta): void
    {
        if ($notices->isEmpty()) {
            return;
        }
        $now = now();
        DB::transaction(function () use ($sheet, $user, $notices, $meta, $now) {
            foreach ($notices->pluck('id')->chunk(500) as $chunk) {
                SheetNoticeAck::insertOrIgnore($chunk->map(fn ($id) => [
                    'notice_id' => $id, 'distribution_sheet_id' => $sheet->id, 'user_id' => $user->id, 'acked_at' => $now,
                ])->values()->all());
            }
            ActivityLog::record('sheet.notice_acked', $sheet, ['acked' => false], ['acked' => true, 'acked_at' => $now->toDateTimeString()], $meta + [
                'notice_ids' => $notices->pluck('id')->values()->all(),
                'types' => $notices->pluck('type')->unique()->values()->all(),
            ]);
        });
    }

    /**
     * الرسالة العربية لمنع التسجيل: «تغيّرت بيانات هذه الصيدلية (أُضيفت فاتورة، …). اطّلع على التعديل أولاً.»
     */
    public static function message(Collection $notices): string
    {
        $what = $notices->pluck('type')->unique()->map(fn ($t) => self::typeLabel($t))->implode('، ');

        return 'تغيّرت بيانات هذه الصيدلية ('.$what.'). اطّلع على التعديل أولاً.';
    }

    /**
     * تفاصيل الإشعار للموزع بلا مبالغ (كما في 6B): نص الإشعار، وتغيّر الرصيد بلا أرقام.
     */
    public static function detail(SheetChangeNotice $notice): string
    {
        if ($notice->type === 'balance_changed') {
            $reason = $notice->meta['reason'] ?? null;

            return 'تغيّر رصيد الصيدلية'.($reason ? ' ('.$reason.')' : '');
        }

        return (string) $notice->summary;
    }

    /**
     * سياق تسجيل قادم من الطابور دون اتصال على صيدلية لها إشعارات إلزامية غير مُطَّلَع عليها (يُحفظ مع السجل).
     *
     * @return array<string, mixed>
     */
    public static function context(Collection $notices, ?string $recordedAt, ?int $knownNoticeId): array
    {
        $recorded = null;
        try {
            $recorded = $recordedAt ? Carbon::parse($recordedAt) : null;
        } catch (\Throwable) {
            $recorded = null;
        }
        // وقت الجهاز لا يُعتمد إن كان في المستقبل
        if ($recorded && $recorded->isFuture()) {
            $recorded = null;
        }

        return [
            'notice_ids' => $notices->pluck('id')->values()->all(),
            'notices' => $notices->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'summary' => self::detail($n),
                'created_at' => $n->created_at?->toDateTimeString(),
                // الإشعار سبق وقت التسجيل على الجهاز (إن عُرف)، أو حدث بعده وقبل المزامنة
                'before_recording' => $recorded && $n->created_at ? $n->created_at->lessThanOrEqualTo($recorded) : null,
                // لم يكن على الجهاز وقت التسجيل (أحدث من آخر إشعار عرفه الجهاز)
                'unknown_to_device' => $knownNoticeId !== null ? $n->id > $knownNoticeId : null,
            ])->values()->all(),
            'recorded_at' => $recorded?->toDateTimeString(),
            'known_notice_id' => $knownNoticeId,
            'synced_at' => now()->toDateTimeString(),
        ];
    }

    // =====================================================================
    // رؤية مسؤول الكشوف والمشرف
    // =====================================================================

    /**
     * عدد الإشعارات الإلزامية التي لم يطّلع عليها أي موزع — لكل كشف مُرسل (استعلام واحد).
     *
     * @param  iterable<DistributionSheet>  $sheets
     * @return array<int, int>
     */
    public function unackedCounts(iterable $sheets): array
    {
        $ids = collect($sheets)->filter(fn ($s) => in_array($s->status, SheetChangeNotifier::NOTIFY_STATUSES, true))->pluck('id')->all();
        if (! $ids) {
            return [];
        }

        return SheetChangeNotice::whereIn('distribution_sheet_id', $ids)
            ->whereIn('type', self::requiredTypes() ?: ['__none__'])
            ->whereDoesntHave('acks')
            ->selectRaw('distribution_sheet_id, COUNT(*) as c')
            ->groupBy('distribution_sheet_id')
            ->pluck('c', 'distribution_sheet_id')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    public function unackedCount(DistributionSheet $sheet): int
    {
        return $this->unackedCounts([$sheet])[$sheet->id] ?? 0;
    }

    /**
     * لوحة «اطلاع الموزع»: الإشعارات الإلزامية (غير المُطَّلَع عليه أولاً)، مع من اطّلع ومتى ومن لم يطّلع من الموزعين المعنيين.
     *
     * @return array{rows: Collection, total: int, unacked: int, limit: int}
     */
    public function panel(DistributionSheet $sheet, int $limit = 200): array
    {
        $types = self::requiredTypes() ?: ['__none__'];
        $base = SheetChangeNotice::where('distribution_sheet_id', $sheet->id)->whereIn('type', $types);
        $total = (clone $base)->count();
        $unacked = (clone $base)->whereDoesntHave('acks')->count();

        $notices = (clone $base)->withExists('acks as acked')->with('acks.user:id,name')
            ->orderBy('acked')->orderByDesc('id')->limit($limit)->get();
        $assignments = $sheet->assignments()->with('distributor:id,name')->get();

        $rows = $notices->map(function (SheetChangeNotice $n) use ($assignments) {
            $relevant = $assignments->filter(fn ($a) => $a->area_key === null || $a->area_key === $n->area_key)
                ->pluck('distributor')->filter()->unique('id');
            $ackedIds = $n->acks->pluck('user_id')->all();

            return [
                'notice' => $n,
                'acked' => (bool) $n->acked,
                'acks' => $n->acks->sortBy('acked_at')->values(),
                'waiting' => $relevant->reject(fn ($u) => in_array($u->id, $ackedIds, true))->pluck('name')->values()->all(),
                'blocking' => $n->customer_id !== null,
            ];
        });

        return ['rows' => $rows, 'total' => $total, 'unacked' => $unacked, 'limit' => $limit];
    }
}
