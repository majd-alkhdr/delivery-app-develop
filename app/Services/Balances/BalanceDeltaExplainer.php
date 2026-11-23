<?php

namespace App\Services\Balances;

use App\Enums\Role;
use App\Models\BalanceImport;
use App\Models\CustomerBalanceSnapshot;
use App\Models\ImportBatchItem;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\InvoiceChangeProposal;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Support\BulkWrite;
use App\Support\CostCenter;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * التفسير التقديري لتغيّر رصيد زبون بين ملفي أرصدة (المرحلة 7B).
 * - exported_payments_sum: دفعات الزبون المصدَّرة التي لم تنعكس بعد (تنتظر هذا الملف).
 * - invoice_effect_sum: فواتير جديدة ضمن مدى التاريخين + فروق فواتير طُبّقت من مراجعة 7A + تغيّرات مُقترحة من الملف لم تُطبَّق (وسم مختلف)
 *   + (المرحلة 7C) التعديلات اللاحقة المطبَّقة (من الملف أو يدوية بـdelta): المرتبطة بهذه اللقطة، أو المطبَّقة بين الملفين بلا ربط.
 *   تعديل لاحق يُحسب مرة واحدة: عنصر/اقتراح تحوّل إلى تعديل لا يُحسب «مطبَّقاً» ولا «مقترحاً» معه.
 * - unexplained = delta + exported − invoice_effect. أكبر من الحدّ (Settings: balance_unexplained_tolerance، افتراضي 1) ⇒ flagged
 *   إن كان للزبون ارتباط بحركة التطبيق في نافذة اللقطة (المرحلة 7E، SnapshotScope)، وإلا «خارجي» بلا تحقق. الدفعة المصدَّرة غير المنعكسة ⇒ flagged دائماً.
 * - الدفعات المصدَّرة تُعلَّم «منعكسة» فقط إن لم يبقَ فرق غير مفسَّر؛ وإلا تبقى تُطرح من الرصيد المعروض ويظهر التحذير.
 * التفسير تقديري يعتمد على ما سجّله التطبيق بين الملفين.
 */
class BalanceDeltaExplainer
{
    /** @var array<string, string>|null كود مركز الكلفة المطبَّع => اسم المندوب */
    private ?array $reps = null;

    public function explain(CustomerBalanceSnapshot $snapshot, ?CarbonInterface $since, ?CarbonInterface $until): CustomerBalanceSnapshot
    {
        $until ??= now();
        $payments = $this->fill($snapshot, $since, $until, (float) Setting::get('balance_unexplained_tolerance', 1), null);
        $snapshot->save();
        $this->reflect($snapshot, $payments, $until);

        return $snapshot;
    }

    /**
     * المرحلة 7D: تفسير لقطات ملف أرصدة كاملة بالنتائج نفسها لـexplain() لكل لقطة، لكن بمعطيات محمَّلة
     * على دفعات (الدفعات المصدَّرة، التعديلات اللاحقة، الفواتير الجديدة، عناصر الدفعات) وحفظ جماعي.
     * اللقطات لزبائن مختلفين (لقطة واحدة لكل زبون في الملف)، فلا يؤثر تفسير إحداها في غيرها.
     *
     * @param  Collection<int, CustomerBalanceSnapshot>  $snapshots
     */
    public function explainMany(Collection $snapshots, ?CarbonInterface $since, ?CarbonInterface $until): void
    {
        $until ??= now();
        $tolerance = (float) Setting::get('balance_unexplained_tolerance', 1);

        foreach ($snapshots->chunk(BulkWrite::CHUNK) as $chunk) {
            $preloaded = $this->preload($chunk, $since, $until);
            // ما يكتبه save() لكل لقطة: الحقول المتغيّرة فقط مع updated_at (اللقطة غير المتغيّرة لا تُكتب)
            $rows = [];
            $reflect = [];
            $now = null;
            foreach ($chunk as $snapshot) {
                $payments = $this->fill($snapshot, $since, $until, $tolerance, $preloaded);
                if ($dirty = $snapshot->getDirty()) {
                    $now ??= $snapshot->freshTimestamp();
                    $snapshot->setUpdatedAt($now);
                    $rows[$snapshot->id] = $dirty + [$snapshot->getUpdatedAtColumn() => $snapshot->getAttributes()[$snapshot->getUpdatedAtColumn()]];
                    $snapshot->syncOriginal();
                }
                $reflect[] = [$snapshot, $payments];
            }
            if ($rows) {
                BulkWrite::updateById($chunk->first()->getTable(), $rows);
            }
            foreach ($reflect as [$snapshot, $payments]) {
                $this->reflect($snapshot, $payments, $until);
            }
        }
    }

    /**
     * حساب التفسير وتعبئة اللقطة (بلا حفظ). يُرجع الدفعات المصدَّرة المحسوبة (للانعكاس).
     */
    private function fill(CustomerBalanceSnapshot $snapshot, ?CarbonInterface $since, CarbonInterface $until, float $tolerance, ?array $preloaded): Collection
    {
        $delta = round((float) $snapshot->delta, 2);
        $details = [];

        // دفعات مصدَّرة تنتظر الانعكاس (وعند إعادة التفسير: التي انعكست في هذه اللقطة نفسها)
        $payments = $preloaded !== null
            ? $preloaded['payments']->get($snapshot->customer_id, collect())
                ->filter(fn ($p) => $p->reflected_at === null || (int) $p->reflected_snapshot_id === (int) $snapshot->id)->values()
            : Payment::active()->where('customer_id', $snapshot->customer_id)->where('status', '!=', 'pending')
                ->whereNotNull('exported_at')->where('exported_at', '<=', $until)
                ->where(fn ($q) => $q->whereNull('reflected_at')->when($snapshot->exists, fn ($w) => $w->orWhere('reflected_snapshot_id', $snapshot->id)))
                ->get();
        $exported = round((float) $payments->sum(fn ($p) => (float) $p->amount_syp), 2);
        foreach ($payments as $p) {
            $details[] = ['type' => 'exported_payment', 'payment_id' => $p->id, 'receipt_number' => $p->receipt_number, 'amount' => (float) $p->amount_syp,
                'exported_at' => $p->exported_at?->toDateTimeString(), 'finance_export_id' => $p->finance_export_id];
        }

        // أثر الفواتير (يحتاج لقطة سابقة؛ أول رصيد للزبون لا يُفسَّر)
        $effect = 0.0;
        $proposedEffect = 0.0;
        if ($snapshot->prev_balance !== null && $snapshot->kind !== 'created') {
            [$effect, $proposedEffect, $invoiceDetails] = $this->invoiceEffect($snapshot, $since, $until, $preloaded);
            $details = array_merge($details, $invoiceDetails);
        }
        $totalEffect = round($effect + $proposedEffect, 2);
        $unexplained = round($delta + $exported - $totalEffect, 2);

        // المرحلة 7E: «تحتاج تحققاً» فقط لدفعة مصدَّرة لم تنعكس، أو لفرق غير مفسَّر لزبون مرتبط بحركة التطبيق في نافذة اللقطة.
        // الفرق غير المرتبط بأي حركة في التطبيق «خارجي» (مبيعات أو تحصيل خارجه) فلا يحتاج تحققاً. الزبون الجديد لا يُعلَّم أبداً.
        $linked = $preloaded !== null ? ($preloaded['linked'][$snapshot->id] ?? false) : $this->linked($snapshot);
        $first = $snapshot->kind === 'created' || $snapshot->prev_balance === null;
        $over = ! $first && abs($unexplained) > $tolerance;
        $mismatch = $over && $exported > 0;
        $flagged = $mismatch || ($over && $linked);
        $external = $over && ! $flagged;

        $snapshot->fill([
            'exported_payments_sum' => $exported,
            'invoice_effect_sum' => $totalEffect,
            'unexplained' => $first ? 0 : $unexplained,
            'details' => $details,
            'status' => $flagged ? CustomerBalanceSnapshot::FLAGGED : CustomerBalanceSnapshot::OK,
            // الزبون الجديد (أول رصيد) بلا نطاق: لا تغيّر يُصنَّف
            'scope' => $snapshot->kind === 'created' ? null : ($linked ? SnapshotScope::APP : SnapshotScope::EXTERNAL),
            'exported_mismatch' => $mismatch,
            'explanation' => $this->text($snapshot, $delta, $exported, $effect, $proposedEffect, $unexplained, $first, $flagged, $mismatch, $details, $external),
        ]);

        return $payments;
    }

    /**
     * الانعكاس: الدفعة المصدَّرة تُعدّ منعكسة عند أول ملف أرصدة بعد تصديرها إن لم يبقَ فرق غير مفسَّر.
     */
    private function reflect(CustomerBalanceSnapshot $snapshot, Collection $payments, CarbonInterface $until): void
    {
        if ($snapshot->exported_mismatch || $payments->isEmpty()) {
            return;
        }
        Payment::whereIn('id', $payments->pluck('id'))->whereNull('reflected_at')->update(['reflected_at' => $until, 'reflected_snapshot_id' => $snapshot->id]);
        // تحذير سابق «لم تنعكس» لهذا الزبون يزول بانعكاس دفعاته في هذا الملف (يبقى في التاريخ مع السبب)
        CustomerBalanceSnapshot::where('customer_id', $snapshot->customer_id)->where('id', '<', $snapshot->id)
            ->where('exported_mismatch', true)->whereNull('reviewed_at')
            ->update(['reviewed_at' => $until, 'review_note' => 'انعكست الدفعات المصدَّرة في ملف أرصدة لاحق (لقطة #'.$snapshot->id.').']);
    }

    /**
     * المرحلة 7D: كل ما يحتاجه تفسير دفعة لقطات (محفوظة) بعدد ثابت من الاستعلامات، مجمَّعاً حسب الزبون.
     * الشروط نفسها التي تستعملها explain() للقطة واحدة؛ ما يخص كل لقطة (معرّفها، مدى تاريخيها) يُصفّى في الذاكرة.
     *
     * @param  Collection<int, CustomerBalanceSnapshot>  $snapshots
     */
    private function preload(Collection $snapshots, ?CarbonInterface $since, CarbonInterface $until): array
    {
        $customerIds = $snapshots->pluck('customer_id')->unique()->values()->all();

        $data = ['adjustments' => collect(), 'invoices' => collect(), 'items' => collect(), 'adjusted_proposals' => []];
        $data['linked'] = SnapshotScope::linked($snapshots->mapWithKeys(fn ($s) => [$s->id => self::window($s)])->all());
        $data['payments'] = Payment::active()->whereIn('customer_id', $customerIds)->where('status', '!=', 'pending')
            ->whereNotNull('exported_at')->where('exported_at', '<=', $until)
            ->where(fn ($q) => $q->whereNull('reflected_at')->orWhereIn('reflected_snapshot_id', $snapshots->pluck('id')->all()))
            ->get()->sortBy('id')->groupBy('customer_id');

        $explained = $snapshots->filter(fn ($s) => $s->prev_balance !== null && $s->kind !== 'created');
        if ($explained->isEmpty()) {
            return $data;
        }
        $ids = $explained->pluck('customer_id')->unique()->values()->all();

        $data['adjustments'] = InvoiceAdjustment::with('invoice')->applied()->whereIn('customer_id', $ids)
            ->where(fn ($q) => $q->whereIn('balance_snapshot_id', $explained->pluck('id')->all())
                ->orWhere(fn ($w) => $w->whereNull('balance_snapshot_id')
                    ->when($since, fn ($x) => $x->where('applied_at', '>=', $since))
                    ->where('applied_at', '<=', $until)))
            ->orderBy('id')->get()->groupBy('customer_id');

        $windows = $explained->filter(fn ($s) => $s->prev_as_of && $s->as_of);
        if ($windows->isNotEmpty()) {
            $data['invoices'] = Invoice::whereIn('customer_id', $windows->pluck('customer_id')->unique()->values()->all())->where('placement', '!=', Invoice::CANCELLED)
                ->whereDate('invoice_date', '>', $windows->min(fn ($s) => $s->prev_as_of->toDateString()))
                ->whereDate('invoice_date', '<=', $windows->max(fn ($s) => $s->as_of->toDateString()))
                ->get()->sortBy('id')->groupBy('customer_id');
        }

        $items = ImportBatchItem::with('invoice', 'proposal')
            ->whereIn('kind', ImportBatchItem::PAYLOAD_KINDS + [99 => ImportBatchItem::CLOSED_CHANGED])
            ->whereHas('invoice', fn ($q) => $q->whereIn('customer_id', $ids))
            ->when($since, fn ($q) => $q->where(fn ($w) => $w->where('decided_at', '>=', $since)->orWhere('created_at', '>=', $since)))
            ->orderBy('id')->get();
        $data['items'] = $items->groupBy(fn ($item) => $item->invoice->customer_id);
        $data['adjusted_proposals'] = InvoiceAdjustment::applied()->whereIn('proposal_id', $items->pluck('proposal_id')->filter()->unique()->values()->all() ?: [0])
            ->pluck('proposal_id')->mapWithKeys(fn ($id) => [$id => true])->all();

        return $data;
    }

    /**
     * المرحلة 7C: إعادة كتابة تفسير لقطة بنافذة ملفها نفسه (بعد تطبيق تعديل لاحق مرتبط بها أو التراجع عنه).
     */
    public function reexplain(CustomerBalanceSnapshot $snapshot): CustomerBalanceSnapshot
    {
        $import = $snapshot->balanceImport;
        if (! $import || ! $import->applied_at) {
            return $snapshot;
        }
        $previous = BalanceImport::where('status', BalanceImport::APPLIED)->where('id', '!=', $import->id)
            ->where('applied_at', '<=', $import->applied_at)->max('applied_at');

        return $this->explain($snapshot, $previous ? Carbon::parse($previous) : null, $import->applied_at);
    }

    /**
     * @return array{0: float, 1: float, 2: array<int, array>} [أثر مطبَّق/جديد، أثر مقترح، التفاصيل]
     */
    private function invoiceEffect(CustomerBalanceSnapshot $snapshot, ?CarbonInterface $since, ?CarbonInterface $until = null, ?array $preloaded = null): array
    {
        $details = [];
        $effect = 0.0;
        $proposed = 0.0;
        $counted = [];

        // المرحلة 7C: التعديلات اللاحقة المطبَّقة — المرتبطة بهذه اللقطة، أو المطبَّقة ضمن نافذة الملفين ولم تُربط بلقطة أخرى
        $adjustedInvoices = [];
        $adjustedProposals = [];
        $adjustments = $preloaded !== null
            ? $preloaded['adjustments']->get($snapshot->customer_id, collect())
                ->filter(fn ($a) => $a->balance_snapshot_id === null || (int) $a->balance_snapshot_id === (int) $snapshot->id)
            : InvoiceAdjustment::with('invoice')->applied()->where('customer_id', $snapshot->customer_id)
                ->where(fn ($q) => $q->when($snapshot->exists, fn ($w) => $w->where('balance_snapshot_id', $snapshot->id))
                    ->orWhere(fn ($w) => $w->whereNull('balance_snapshot_id')
                        ->when($since, fn ($x) => $x->where('applied_at', '>=', $since))
                        ->when($until, fn ($x) => $x->where('applied_at', '<=', $until))))
                ->orderBy('id')->get();
        foreach ($adjustments as $adjustment) {
            $effect += (float) $adjustment->delta;
            $adjustedInvoices[$adjustment->invoice_id] = true;
            if ($adjustment->proposal_id) {
                $adjustedProposals[$adjustment->proposal_id] = true;
            }
            $details[] = $this->invoiceRow('post_close_adjustment', $adjustment->invoice, (float) $adjustment->delta)
                + ['adjustment_id' => $adjustment->id, 'adjustment_kind' => $adjustment->kind];
        }

        // فواتير جديدة ضمن مدى التاريخين (prev_as_of, as_of]
        if ($snapshot->prev_as_of && $snapshot->as_of) {
            $new = $preloaded !== null
                ? $preloaded['invoices']->get($snapshot->customer_id, collect())->filter(fn ($i) => $i->invoice_date !== null
                    && $i->invoice_date->toDateString() > $snapshot->prev_as_of->toDateString() && $i->invoice_date->toDateString() <= $snapshot->as_of->toDateString())
                : Invoice::where('customer_id', $snapshot->customer_id)->where('placement', '!=', Invoice::CANCELLED)
                    ->whereDate('invoice_date', '>', $snapshot->prev_as_of)->whereDate('invoice_date', '<=', $snapshot->as_of)->get();
            foreach ($new as $invoice) {
                $effect += (float) $invoice->invoice_amount;
                $counted[$invoice->id] = true;
                $details[] = $this->invoiceRow('new_invoice', $invoice, (float) $invoice->invoice_amount);
            }
        }

        if ($preloaded !== null) {
            $items = $preloaded['items']->get($snapshot->customer_id, collect());
            // المحمَّل لكل اقتراحات الدفعة؛ الاقتراح يخص فاتورة واحدة فلا يطابق إلا عناصر زبونها
            $adjustedProposals += $preloaded['adjusted_proposals'];
        } else {
            $items = ImportBatchItem::with('invoice', 'proposal')
                ->whereIn('kind', ImportBatchItem::PAYLOAD_KINDS + [99 => ImportBatchItem::CLOSED_CHANGED])
                ->whereHas('invoice', fn ($q) => $q->where('customer_id', $snapshot->customer_id))
                ->when($since, fn ($q) => $q->where(fn ($w) => $w->where('decided_at', '>=', $since)->orWhere('created_at', '>=', $since)))
                ->orderBy('id')->get();
            // اقتراحات تحوّلت إلى تعديل لاحق مطبَّق (في أي لقطة): لا تُحسب «مطبَّقة» ولا «مقترحة» مرة أخرى
            $adjustedProposals += InvoiceAdjustment::applied()->whereIn('proposal_id', $items->pluck('proposal_id')->filter()->unique()->all() ?: [0])
                ->pluck('proposal_id')->mapWithKeys(fn ($id) => [$id => true])->all();
        }

        $seenProposed = [];
        foreach ($items as $item) {
            $diff = round((float) ($item->new['amount'] ?? 0) - (float) ($item->old['amount'] ?? 0), 2);
            if (abs($diff) < 0.005 || isset($counted[$item->invoice_id])) {
                continue;
            }
            // تحوّل إلى تعديل لاحق (أو للفاتورة تعديل لاحق مطبَّق): يُحسب مرة واحدة مع التعديل
            if (($item->proposal_id && isset($adjustedProposals[$item->proposal_id])) || isset($adjustedInvoices[$item->invoice_id])) {
                continue;
            }
            // فرق طُبّق من مراجعة 7A
            if ($item->status === ImportBatchItem::APPLIED && (! $since || $item->decided_at >= $since)) {
                $effect += $diff;
                $details[] = $this->invoiceRow('applied_change', $item->invoice, $diff) + ['item_id' => $item->id];

                continue;
            }
            // تغيّر مُقترح من الملف لم يُطبَّق بعد (كشف مكتمل بانتظار التسوية، أو عنصر معلّق) — آخر عنصر لكل فاتورة
            $open = $item->status === ImportBatchItem::PENDING || $item->status === ImportBatchItem::BLOCKED
                || ($item->kind === ImportBatchItem::CLOSED_CHANGED && $item->proposal?->status === InvoiceChangeProposal::AWAITING_ADJUSTMENT);
            if ($open) {
                $seenProposed[$item->invoice_id] = $this->invoiceRow('proposed_change', $item->invoice, $diff) + ['item_id' => $item->id, 'closed' => $item->kind === ImportBatchItem::CLOSED_CHANGED];
            }
        }
        foreach ($seenProposed as $row) {
            $proposed += $row['amount'];
            $details[] = $row;
        }

        return [round($effect, 2), round($proposed, 2), $details];
    }

    private function invoiceRow(string $type, ?Invoice $invoice, float $amount): array
    {
        $code = CostCenter::normalizeCode($invoice?->cost_center_code);

        return [
            'type' => $type,
            'invoice_id' => $invoice?->id,
            'invoice_number' => $invoice?->invoice_number,
            'label' => \App\Services\Finance\PaymentAllocationEngine::invoiceLabel((string) $invoice?->invoice_number),
            'amount' => round($amount, 2),
            'cost_center' => trim(($invoice?->cost_center_code ?? '').'-'.($invoice?->cost_center_name ?? ''), '-') ?: null,
            'rep' => $code !== null ? ($this->repNames()[$code] ?? null) : null,
            'sheet_id' => $invoice?->distribution_sheet_id,
        ];
    }

    private function linked(CustomerBalanceSnapshot $snapshot): bool
    {
        return SnapshotScope::linked([0 => self::window($snapshot)])[0];
    }

    /** @return array{customer_id: int, from: ?string, to: ?string} نافذة اللقطة [prev_as_of, as_of] */
    private static function window(CustomerBalanceSnapshot $snapshot): array
    {
        return ['customer_id' => (int) $snapshot->customer_id, 'from' => $snapshot->prev_as_of?->toDateString(), 'to' => $snapshot->as_of?->toDateString()];
    }

    private function text(CustomerBalanceSnapshot $s, float $delta, float $exported, float $effect, float $proposed, float $unexplained, bool $first, bool $flagged, bool $mismatch, array $details, bool $external = false): string
    {
        if ($s->kind === 'created') {
            return 'زبون جديد في ملف الأرصدة برصيد '.self::money((float) $s->balance).'.';
        }
        if ($first) {
            return 'أول رصيد مسجّل لهذا الزبون: '.self::money((float) $s->balance).'.';
        }

        $head = match (true) {
            $delta < 0 => 'انخفض الرصيد '.self::money(abs($delta)),
            $delta > 0 => 'ارتفع الرصيد '.self::money($delta),
            default => 'لم يتغيّر الرصيد',
        };
        $parts = [$head.': دفعات مصدَّرة '.self::money($exported).'، تعديلات فواتير '.self::signed($effect + $proposed).'، غير مفسَّر '.self::signed($unexplained)];
        if ($s->kind === 'zeroed') {
            array_unshift($parts, 'غاب عن ملف الأرصدة فصُفِّر.');
        }

        $lines = [];
        foreach ($details as $d) {
            $who = $d['rep'] ?? null ? ' (مندوب '.$d['rep'].')' : ($d['cost_center'] ?? null ? ' ('.$d['cost_center'].')' : '');
            $lines[] = match ($d['type']) {
                'new_invoice' => 'فاتورة جديدة '.$d['label'].$who.' '.self::signed($d['amount']),
                'applied_change' => 'تعديل فاتورة '.$d['label'].$who.' '.self::signed($d['amount']),
                'post_close_adjustment' => 'تعديل لاحق '.self::signed($d['amount']).' على الفاتورة '.$d['label'].$who,
                'proposed_change' => 'تغيّر مُقترح من الملف لفاتورة '.$d['label'].$who.' '.self::signed($d['amount'])
                    .(! empty($d['closed']) ? ' (كشف مكتمل، بانتظار التسوية)' : ' (لم يُطبَّق بعد)'),
                default => null,
            };
        }
        $lines = array_values(array_filter($lines));
        if ($lines) {
            $parts[] = implode('؛ ', $lines);
        }
        if ($mismatch) {
            $parts[] = 'دفعات مصدَّرة بمبلغ '.self::money($exported).' ولم ينخفض الرصيد بما يقابلها (الفرق '.self::signed($unexplained).'): تحقق أن القيود رُحّلت في المحاسبة.';
        } elseif ($flagged) {
            $parts[] = 'غير مفسَّر: تحقق في المحاسبة (مرتجع؟ تعديل سند؟ إلغاء فاتورة؟)';
        } elseif ($external) {
            $parts[] = SnapshotScope::EXTERNAL_NOTE;
        }

        return implode(' — ', $parts);
    }

    /** @return array<string, string> */
    private function repNames(): array
    {
        return $this->reps ??= User::where('role', Role::Rep)->whereNotNull('cost_center_code')->get(['name', 'cost_center_code'])
            ->mapWithKeys(fn ($u) => [CostCenter::normalizeCode($u->cost_center_code) => $u->name])->filter()->all();
    }

    public static function money(float $value): string
    {
        return number_format($value, abs($value - round($value)) < 0.005 ? 0 : 2);
    }

    /** «−200» / «+300» / «0» */
    public static function signed(float $value): string
    {
        if (abs($value) < 0.005) {
            return '0';
        }

        return ($value < 0 ? '−' : '+').self::money(abs($value));
    }
}
