<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\SheetAssignment;
use App\Models\SheetChangeNotice;
use App\Models\User;
use App\Services\Finance\PaymentAllocationEngine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * تنبيهات تعديل الكشف بعد نشره (المرحلة 6B-D).
 * - تُنشأ داخل transaction العملية نفسها (SheetInvoiceEditor وتطبيق اقتراح التغيير)، وفقط إن كان الكشف pending/in_progress.
 * - كل إشعار: revision++ على الكشف، ونص مختصر بلا مبالغ (رقم الفاتورة والصيدلية والمنطقة).
 * - الموزع يرى إشعارات مناطقه فقط (area_key ضمن إسناده)؛ والمؤجلة لا تصل كشفاً أصلاً فلا إشعار عنها.
 */
class SheetChangeNotifier
{
    public const NOTIFY_STATUSES = ['pending', 'in_progress'];

    public const VERBS = [
        'added' => 'أُضيفت فاتورة',
        'removed' => 'أُخرجت فاتورة',
        'moved_in' => 'نُقلت إلى هذا الكشف فاتورة',
        'moved_out' => 'نُقلت من هذا الكشف فاتورة',
        'updated' => 'حُدّثت بيانات فاتورة',
        'area_changed' => 'تغيّرت منطقة فاتورة',
    ];

    public function notify(DistributionSheet $sheet, string $type, ?Invoice $invoice, ?User $by, ?string $areaKey = null, ?string $areaLabel = null): ?SheetChangeNotice
    {
        $status = DistributionSheet::whereKey($sheet->id)->value('status');
        if (! in_array($status, self::NOTIFY_STATUSES, true)) {
            return null;
        }

        DistributionSheet::whereKey($sheet->id)->increment('revision');

        $areaKey ??= $invoice?->area_key;
        $areaLabel ??= $areaKey === SheetAssignment::NO_AREA ? 'بلا منطقة' : ($invoice?->area ?: 'بلا منطقة');
        $parts = array_filter([
            self::VERBS[$type] ?? 'تعديل فاتورة',
            $invoice ? PaymentAllocationEngine::invoiceLabel((string) $invoice->invoice_number) : null,
            $invoice?->customer_name,
            $areaLabel,
        ]);

        return SheetChangeNotice::create([
            'distribution_sheet_id' => $sheet->id,
            'invoice_id' => $invoice?->id,
            'customer_id' => $invoice?->customer_id,
            'type' => $type,
            'area_key' => $areaKey,
            'summary' => mb_substr(implode(' — ', $parts), 0, 500),
            'created_by' => $by?->id,
            'created_at' => now(),
        ]);
    }

    /**
     * المرحلة 7B: «تغيّر رصيد الصيدلية من X إلى Y» في كشف منشور (أو مكتمل لم يُغلق مالياً): revision++ لتتحدث نسخة الموزع.
     * يظهر على بطاقة الصيدلية حتى «اطلعت» عليها (صف sheet_user_seen خاص بالصيدلية)، لا في شريط التعديلات العام.
     * المرحلة 7D: كل إشعارات ملف الأرصدة دفعة واحدة: revision يزيد بعدد إشعارات كل كشف، والإدخال على دفعات بالترتيب نفسه.
     *
     * @param  array<int, array{0: DistributionSheet, 1: \App\Models\CustomerBalanceSnapshot, 2: ?Invoice}>  $items  [الكشف، اللقطة، أول فاتورة للزبون في الكشف]
     */
    public function notifyBalanceMany(array $items): void
    {
        $notices = [];
        $perSheet = [];
        foreach ($items as [$sheet, $snapshot, $invoice]) {
            $perSheet[$sheet->id] = ($perSheet[$sheet->id] ?? 0) + 1;
            $notices[] = new SheetChangeNotice($this->balanceNotice($sheet, $snapshot, $invoice));
        }
        foreach ($perSheet as $sheetId => $count) {
            DistributionSheet::whereKey($sheetId)->increment('revision', $count);
        }
        \App\Support\BulkWrite::insertModels($notices);
    }

    private function balanceNotice(DistributionSheet $sheet, \App\Models\CustomerBalanceSnapshot $snapshot, ?Invoice $invoice): array
    {
        $name = $snapshot->customer?->name ?? $invoice?->customer_name;
        $reason = $this->shortReason($snapshot);

        return [
            'distribution_sheet_id' => $sheet->id,
            'invoice_id' => null,
            'customer_id' => $snapshot->customer_id,
            'type' => 'balance_changed',
            'area_key' => $invoice?->area_key,
            'summary' => mb_substr('تغيّر رصيد '.$name.' من '.number_format((float) $snapshot->prev_balance, 0).' إلى '.number_format((float) $snapshot->balance, 0)
                .($reason ? ' ('.$reason.')' : ''), 0, 500),
            'meta' => ['old' => (float) $snapshot->prev_balance, 'new' => (float) $snapshot->balance, 'snapshot_id' => $snapshot->id,
                'as_of' => $snapshot->as_of?->toDateString(), 'reason' => $reason],
            'created_by' => null,
            'created_at' => now(),
        ];
    }

    /**
     * سبب مختصر للوسم (أول جزء مفيد من التفسير).
     */
    public function shortReason(\App\Models\CustomerBalanceSnapshot $snapshot): ?string
    {
        $types = collect($snapshot->details ?? [])->pluck('type');
        $labels = collect($snapshot->details ?? [])->whereIn('type', ['applied_change', 'proposed_change', 'new_invoice'])
            ->map(fn ($d) => ($d['type'] === 'new_invoice' ? 'فاتورة جديدة ' : 'تعديل فاتورة ').$d['label'])->take(2)->implode('، ');

        return match (true) {
            $snapshot->kind === 'zeroed' => 'غاب عن ملف الأرصدة',
            $labels !== '' => $labels,
            $types->contains('exported_payment') && ! $snapshot->exported_mismatch => 'دفعات مصدَّرة انعكست',
            $snapshot->isFlagged() => 'غير مفسَّر',
            default => null,
        };
    }

    /**
     * تغيّرات رصيد الصيدليات غير المقروءة للمستخدم في هذا الكشف — customer_id => آخر إشعار.
     *
     * @return Collection<int, SheetChangeNotice>
     */
    public function unseenBalanceChanges(DistributionSheet $sheet, User $user): Collection
    {
        $seen = DB::table('sheet_user_seen')->where('distribution_sheet_id', $sheet->id)->where('user_id', $user->id)
            ->whereNotNull('customer_id')->pluck('last_notice_id', 'customer_id');

        return $this->visibleQuery($sheet, $user, true)->where('type', 'balance_changed')->orderBy('id')->get()
            ->filter(fn ($n) => $n->id > (int) ($seen[$n->customer_id] ?? 0))
            ->keyBy('customer_id');
    }

    /**
     * «اطلعت» على تغيّر رصيد صيدلية بعينها في الكشف.
     */
    public function markBalanceSeen(DistributionSheet $sheet, User $user, int $customerId): void
    {
        $max = (int) SheetChangeNotice::where('distribution_sheet_id', $sheet->id)->where('customer_id', $customerId)->where('type', 'balance_changed')->max('id');
        $existing = DB::table('sheet_user_seen')->where('distribution_sheet_id', $sheet->id)->where('user_id', $user->id)->where('customer_id', $customerId)->first();
        if ($existing) {
            DB::table('sheet_user_seen')->where('id', $existing->id)->update(['last_notice_id' => max($max, (int) $existing->last_notice_id), 'updated_at' => now()]);

            return;
        }
        DB::table('sheet_user_seen')->insert(['distribution_sheet_id' => $sheet->id, 'user_id' => $user->id, 'customer_id' => $customerId,
            'last_notice_id' => $max, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * إشعارات الكشف التي يراها المستخدم (الموزع: مناطقه فقط).
     * تغيّرات الرصيد (7B) لها مسار «اطلعت» خاص بكل صيدلية، فلا تدخل شريط التعديلات العام.
     */
    public function visibleQuery(DistributionSheet $sheet, User $user, bool $withBalance = false)
    {
        $query = SheetChangeNotice::where('distribution_sheet_id', $sheet->id)
            ->when(! $withBalance, fn ($q) => $q->where('type', '!=', 'balance_changed'));
        if ($user->role === Role::Distributor) {
            $keys = $sheet->areaKeysFor($user);   // null = كل المناطق
            if ($keys !== null) {
                $query->whereIn('area_key', $keys ?: ['__no_access__']);
            }
        }

        return $query;
    }

    public function lastSeenId(DistributionSheet $sheet, User $user): int
    {
        return (int) DB::table('sheet_user_seen')->where('distribution_sheet_id', $sheet->id)->where('user_id', $user->id)->whereNull('customer_id')->value('last_notice_id');
    }

    /**
     * غير المقروءة للمستخدم.
     */
    public function unseen(DistributionSheet $sheet, User $user): Collection
    {
        return $this->visibleQuery($sheet, $user)->where('id', '>', $this->lastSeenId($sheet, $user))->orderBy('id')->get();
    }

    /**
     * أعداد غير المقروءة لعدة كشوف (للشارة في القوائم) — sheet_id => عدد.
     *
     * @param  iterable<DistributionSheet>  $sheets
     * @return array<int, int>
     */
    public function unseenCounts(iterable $sheets, User $user): array
    {
        $counts = [];
        foreach ($sheets as $sheet) {
            $counts[$sheet->id] = (int) ($sheet->revision ?? 0) > 0 ? $this->unseen($sheet, $user)->count() : 0;
        }

        return $counts;
    }

    /**
     * آخر التعديلات (للمشرفين ومسؤول الكشوف).
     */
    public function recent(DistributionSheet $sheet, int $limit = 20): Collection
    {
        return SheetChangeNotice::with('creator:id,name')->where('distribution_sheet_id', $sheet->id)->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * «اطلعت»: يحدّث آخر إشعار مقروء للمستخدم في هذا الكشف (ضمن ما يراه).
     */
    public function markSeen(DistributionSheet $sheet, User $user): void
    {
        $max = (int) $this->visibleQuery($sheet, $user)->max('id');
        $existing = DB::table('sheet_user_seen')->where('distribution_sheet_id', $sheet->id)->where('user_id', $user->id)->whereNull('customer_id')->first();
        if ($existing) {
            if ($max > (int) $existing->last_notice_id) {
                DB::table('sheet_user_seen')->where('id', $existing->id)->update(['last_notice_id' => $max, 'updated_at' => now()]);
            }

            return;
        }
        DB::table('sheet_user_seen')->insert(['distribution_sheet_id' => $sheet->id, 'user_id' => $user->id, 'last_notice_id' => $max, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * هل أُخرجت فواتير هذا الزبون من الكشف (أو نُقلت أو تغيّرت منطقتها) بعد نشره؟ (حماية الطابور دون اتصال، D4)
     */
    public function customerLeftSheet(DistributionSheet $sheet, int $customerId): bool
    {
        return SheetChangeNotice::where('distribution_sheet_id', $sheet->id)
            ->where('customer_id', $customerId)
            ->whereIn('type', ['removed', 'moved_out', 'area_changed'])
            ->exists();
    }
}
