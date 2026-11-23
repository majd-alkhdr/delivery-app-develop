<?php

namespace App\Services\Import;

use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\ImportBatchItem;
use App\Models\Invoice;
use App\Models\InvoiceChangeProposal;
use App\Models\InvoiceLine;
use App\Support\InvoiceFingerprint;

/**
 * مطابقة فاتورة واردة من الملف مع المخزَّن عبر invoice_key (لا تُنشأ فاتورة ثانية أبداً).
 *
 * المرحلة 7A: المطابقة تبني «عناصر الدفعة» (ImportBatchItem) بشرح عربي، ولا تعدّل أي فاتورة موجودة:
 * كل تغيير في محتوى فاتورة موجودة يُحفظ كاقتراح وينتظر قراراً صريحاً (ImportReviewService).
 * الاستثناء الوحيد: فاتورة لم يتغيّر محتواها وتغيّر موقعها بحسب تاريخ التوزيع (مؤجلة حان تسليمها)
 * إلى كشف قيد التجهيز أو «بلا كشف» أو «مؤجلة» — وإلى كشف منشور فقط بقرار.
 */
class InvoiceReconciler
{
    /**
     * المرحلة 7D: ذاكرة رفع ملف كامل (prime … flush) بدل استعلامات لكل فاتورة. خارجها يعمل كل شيء كما كان.
     *
     * @var array{invoices: array<string, ?Invoice>, fuzzy: bool, lines: array<int, \Illuminate\Support\Collection>, batch_items: array<int, true>,
     *            sheets: array<int, ?DistributionSheet>, seen: array<int, int>, new_lines: array<int, array>, batch: string}|null
     */
    private ?array $primed = null;

    /**
     * تحميل فواتير الملف الموجودة (بمفاتيحها) مع كشوفها وأسطرها، وعناصر الدفعة الحالية لها، على دفعات.
     *
     * @param  array<int, string>  $keys
     */
    public function prime(array $keys, string $batchId): void
    {
        $keys = array_values(array_unique(array_filter($keys, fn ($k) => $k !== null)));
        $this->primed = ['invoices' => array_fill_keys($keys, null), 'fuzzy' => false, 'lines' => [], 'batch_items' => [], 'sheets' => [],
            'seen' => [], 'new_lines' => [], 'batch' => $batchId];

        foreach (array_chunk($keys, \App\Support\BulkWrite::CHUNK) as $chunk) {
            foreach (Invoice::with('distributionSheet')->whereIn('invoice_key', $chunk)->orderBy('id')->get() as $invoice) {
                if (! array_key_exists($invoice->invoice_key, $this->primed['invoices'])) {
                    // تطابق بقواعد مقارنة القاعدة لا حرفياً: المفاتيح غير الموجودة حرفياً تُبحث في القاعدة كما كانت
                    $this->primed['fuzzy'] = true;

                    continue;
                }
                $this->primed['invoices'][$invoice->invoice_key] ??= $invoice;
                if ($invoice->distribution_sheet_id && $invoice->relationLoaded('distributionSheet')) {
                    $this->primed['sheets'][$invoice->distribution_sheet_id] = $invoice->distributionSheet;
                }
            }
        }

        $ids = collect($this->primed['invoices'])->filter()->pluck('id')->all();
        $this->primed['lines'] = array_fill_keys($ids, collect());
        foreach (array_chunk($ids, \App\Support\BulkWrite::CHUNK) as $chunk) {
            $lines = InvoiceLine::whereIn('invoice_id', $chunk)->orderBy('line_no')->orderBy('id')->get(['id', 'invoice_id', 'line_no', 'item_name', 'quantity', 'line_total']);
            foreach ($lines->groupBy('invoice_id') as $invoiceId => $group) {
                $this->primed['lines'][$invoiceId] = $group->values();
            }
            foreach (ImportBatchItem::where('batch_id', $batchId)->whereIn('invoice_id', $chunk)->distinct()->pluck('invoice_id') as $invoiceId) {
                $this->primed['batch_items'][$invoiceId] = true;
            }
        }
    }

    /**
     * كتابة ما أُجِّل (أسطر الفواتير الجديدة، last_seen_batch_id) جماعياً، وإنهاء الذاكرة.
     */
    public function flush(): void
    {
        if ($this->primed === null) {
            return;
        }
        [$lines, $seen, $batchId] = [$this->primed['new_lines'], $this->primed['seen'], $this->primed['batch']];
        $this->primed = null;

        foreach (array_chunk($lines, \App\Support\BulkWrite::CHUNK) as $chunk) {
            InvoiceLine::insert($chunk);
        }
        foreach (array_chunk(array_values($seen), \App\Support\BulkWrite::CHUNK) as $chunk) {
            Invoice::whereIn('id', $chunk)->update(['last_seen_batch_id' => $batchId]);
        }
    }

    /** إنهاء الذاكرة بلا كتابة (عند الفشل؛ المعاملة تتراجع أصلاً). */
    public function forget(): void
    {
        $this->primed = null;
    }

    private function findByKey(string $key): ?Invoice
    {
        if ($this->primed !== null && array_key_exists($key, $this->primed['invoices'])
            && ($this->primed['invoices'][$key] !== null || ! $this->primed['fuzzy'])) {
            return $this->primed['invoices'][$key];
        }

        return Invoice::where('invoice_key', $key)->first();
    }

    private function findSheet(int $id): ?DistributionSheet
    {
        if ($this->primed === null) {
            return DistributionSheet::find($id);
        }

        return array_key_exists($id, $this->primed['sheets'])
            ? $this->primed['sheets'][$id]
            : $this->primed['sheets'][$id] = DistributionSheet::find($id);
    }

    /**
     * @param  array  $data  فاتورة كما أخرجها WorkbookImporter
     * @param  callable(string): ?DistributionSheet  $sheetFor  الكشف الهدف لرقم أمر تسليم (يُنشئ كشفاً قيد التجهيز عند الحاجة)
     */
    public function reconcile(array $data, Customer $customer, string $date, string $batchId, callable $sheetFor, ImportTally $tally): Invoice
    {
        $payload = $this->payloadFor($data, $customer);
        $amount = (float) $payload['invoice_amount'];
        $number = $payload['invoice_number'];
        $new = $this->payloadSnapshot($payload);

        if ($payload['invoice_key'] !== null) {
            $tally->seenKeys[$payload['invoice_key']] = true;
        } else {
            $tally->note('no_invoice_key', 'warning', 'فواتير بلا رقم سلسلة/رقم صالح لا يمكن مطابقتها بما سبق (أُنشئت كفواتير جديدة، وقد تتكرر عند إعادة الرفع)', $number);
        }

        $existing = $payload['invoice_key'] !== null ? $this->findByKey($payload['invoice_key']) : null;

        // --- فاتورة جديدة ---
        if (! $existing) {
            return $this->create($payload, $customer, $date, $batchId, $sheetFor, $tally, $new);
        }

        $existing->last_seen_batch_id = $batchId;
        // المرحلة 7D: يُكتب جماعياً في flush، إلا إن كان الحفظ سيصحّح placement/is_deferred (حدث saving)
        if ($this->primed !== null && $existing->placement !== null && $existing->is_deferred === ($existing->placement === Invoice::DEFERRED)) {
            $this->primed['seen'][$existing->id] = $existing->id;
        } else {
            $existing->save();
        }

        $base = [
            'invoice_id' => $existing->id,
            'invoice_key' => $existing->invoice_key,
            'invoice_number' => $existing->invoice_number,
            'customer_id' => $existing->customer_id,
            'customer_name' => $payload['customer_name'],
        ] + $this->location($existing);
        $old = $this->invoiceSnapshot($existing);

        // ظهرت في ملف سابق من الدفعة نفسها: القرار في عنصرها الأول
        if (isset($tally->itemInvoiceIds[$existing->id])
            || ($this->primed !== null && $this->primed['batch'] === $batchId
                ? isset($this->primed['batch_items'][$existing->id])
                : ImportBatchItem::where('batch_id', $batchId)->where('invoice_id', $existing->id)->exists())) {
            $tally->count('other', $amount);
            $tally->item($base + ['kind' => ImportBatchItem::UNCHANGED, 'status' => ImportBatchItem::INFO, 'level' => ImportBatchItem::DUPLICATE, 'old' => $old, 'new' => $new,
                'reason' => 'ظهرت أيضاً في ملف آخر من الدفعة نفسها؛ عولجت في ظهورها الأول.']);

            return $existing;
        }
        $tally->itemInvoiceIds[$existing->id] = true;

        $changed = $existing->content_hash !== $payload['content_hash'];
        $sheet = $existing->distributionSheet;
        $diff = $changed ? $this->diffText($old, $new) : '';
        // المرحلة 7E2: «تغيّر وصفي فقط» (مركز الكلفة/تاريخ الفاتورة، والمبلغ والأصناف كما هي): يُعلَّم في new ويُشرح صراحة
        if ($changed && ($fields = $this->descriptiveFields($existing, $payload)) !== null) {
            $new['descriptive'] = $fields;
            $diff = $this->descriptiveText($existing, $payload, $fields, $old, $new);
        }

        // --- في كشف (غير مسودة) ---
        if ($existing->placement === Invoice::IN_SHEET && $sheet && ! $sheet->isDraft()) {
            $where = 'كشف '.$sheet->shortLabel();

            if ($sheet->status === 'completed') {
                $tally->count('other', $amount);
                if (! $changed) {
                    $tally->item($base + ['kind' => ImportBatchItem::CLOSED_UNCHANGED, 'status' => ImportBatchItem::INFO, 'old' => $old, 'new' => $new,
                        'reason' => "في {$where} المكتمل، والملف مطابق لها."]);

                    return $existing;
                }
                $proposalId = $this->propose($existing, $payload, $batchId, InvoiceChangeProposal::TYPE_POST_CLOSE, InvoiceChangeProposal::AWAITING_ADJUSTMENT);
                $tally->item($base + ['kind' => ImportBatchItem::CLOSED_CHANGED, 'status' => ImportBatchItem::INFO, 'old' => $old, 'new' => $new, 'proposal_id' => $proposalId,
                    'reason' => "في {$where} المكتمل، والملف يحمل قيمة مختلفة: {$diff}. المخزَّن ".$this->money($old['amount']).' والجديد '.$this->money($new['amount'])
                        .'. لم تُعدَّل هنا؛ حُفظ التغيير للتسوية بعد الإغلاق في «التعديلات اللاحقة».']);

                return $existing;
            }

            // مفتوح: قيد التجهيز أو منشور
            if (! $changed) {
                $tally->unchanged($sheet, $amount);
                $tally->item($base + ['kind' => ImportBatchItem::UNCHANGED, 'status' => ImportBatchItem::INFO, 'old' => $old, 'new' => $new,
                    'reason' => "موجودة بلا تغيير في {$where} (".$sheet->statusLabel().').']);

                return $existing;
            }

            $kind = $this->changeKind($existing, $payload);
            [$consequence, $targetId] = $existing->manual_placement
                ? ["الفاتورة موضوعة يدوياً في {$where}: عند التطبيق تُحدَّث بياناتها وتبقى في مكانها.", null]
                : $this->predict($payload, $sheet->distribution_date?->toDateString() ?? $date, $sheet);
            $tally->count('other', $amount);
            $tally->item($base + ['kind' => $kind, 'status' => ImportBatchItem::PENDING, 'old' => $old, 'new' => $new, 'target_sheet_id' => $targetId,
                'proposal_id' => $this->propose($existing, $payload, $batchId),
                'reason' => "{$diff}. {$consequence} لن تُعدَّل قبل قرارك."]);

            return $existing;
        }

        // --- مستبعدة ---
        if ($existing->placement === Invoice::EXCLUDED) {
            $tally->count('other', $amount);
            $why = $existing->exclusion_reason ? " (السبب: {$existing->exclusion_reason})" : '';
            if (! $changed) {
                $tally->item($base + ['kind' => ImportBatchItem::UNCHANGED, 'status' => ImportBatchItem::INFO, 'old' => $old, 'new' => $new,
                    'reason' => "مستبعدة سابقاً{$why} وبقيت مستبعدة، والملف مطابق لها."]);

                return $existing;
            }
            $tally->item($base + ['kind' => ImportBatchItem::EXCLUDED_CHANGED, 'status' => ImportBatchItem::PENDING, 'old' => $old, 'new' => $new,
                'proposal_id' => $this->propose($existing, $payload, $batchId),
                'reason' => "{$diff}. الفاتورة مستبعدة{$why}: عند التطبيق تُحدَّث بياناتها وتبقى مستبعدة. لن تُعدَّل قبل قرارك."]);

            return $existing;
        }

        // --- «بلا كشف» أو مؤجلة وضعها المستخدم يدوياً ---
        if ($existing->manual_placement && in_array($existing->placement, [Invoice::UNASSIGNED, Invoice::DEFERRED], true)) {
            $label = $existing->placementLabel();
            if (! $changed) {
                $tally->count($existing->placement === Invoice::DEFERRED ? 'deferred' : 'unassigned', $amount);
                $tally->item($base + ['kind' => ImportBatchItem::UNCHANGED, 'status' => ImportBatchItem::INFO, 'old' => $old, 'new' => $new,
                    'reason' => "وضعها المستخدم يدوياً في «{$label}» وبقيت كما هي؛ الملف مطابق لها."]);

                return $existing;
            }
            $tally->count('other', $amount);
            $tally->item($base + ['kind' => ImportBatchItem::HELD_CHANGED, 'status' => ImportBatchItem::PENDING, 'old' => $old, 'new' => $new,
                'proposal_id' => $this->propose($existing, $payload, $batchId),
                'reason' => "{$diff}. الفاتورة موضوعة يدوياً في «{$label}»: عند التطبيق تُحدَّث بياناتها وتبقى في مكانها. لن تُعدَّل قبل قرارك."]);

            return $existing;
        }

        // --- ملغاة وظهرت في الملف ---
        if ($existing->placement === Invoice::CANCELLED) {
            $tally->count('other', $amount);
            $why = $existing->cancelled_reason ? " (السبب: {$existing->cancelled_reason})" : '';
            [$consequence, $targetId] = $this->predict($payload, $date, null);
            $tally->item($base + [
                'kind' => $changed ? $this->changeKind($existing, $payload) : ImportBatchItem::UNCHANGED,
                'status' => ImportBatchItem::PENDING, 'old' => $old, 'new' => $new, 'target_sheet_id' => $targetId,
                'proposal_id' => $changed ? $this->propose($existing, $payload, $batchId) : null,
                'reason' => "كانت ملغاة{$why} وظهرت في الملف".($changed ? ": {$diff}" : ' كما هي').". عند التطبيق تُعاد: {$consequence} وإن تُجوهل العنصر تبقى ملغاة.",
            ]);

            return $existing;
        }

        // --- مؤجلة أو «بلا كشف» (غير يدوية) أو في كشف مسودة ---
        if ($changed) {
            $tally->count('other', $amount);
            [$consequence, $targetId] = $this->predict($payload, $date, null);
            $tally->item($base + ['kind' => $this->changeKind($existing, $payload), 'status' => ImportBatchItem::PENDING, 'old' => $old, 'new' => $new,
                'target_sheet_id' => $targetId, 'proposal_id' => $this->propose($existing, $payload, $batchId),
                'reason' => "{$diff}. موقعها الحالي: {$base['sheet_label']}. {$consequence} لن تُعدَّل قبل قرارك."]);

            return $existing;
        }

        return $this->relocateUnchanged($existing, $payload, $date, $sheetFor, $tally, $base, $old, $new, $amount);
    }

    /**
     * فاتورة لم يتغيّر محتواها خارج الكشوف: يُعاد تصنيف موقعها بتاريخ التوزيع (مؤجلة حان تسليمها...).
     * إلى كشف منشور: بقرار فقط (عنصر معلّق).
     */
    private function relocateUnchanged(Invoice $existing, array $payload, string $date, callable $sheetFor, ImportTally $tally, array $base, array $old, array $new, float $amount): Invoice
    {
        [$placement, $sheet] = $this->classify($payload, $date, $sheetFor, $tally);
        $from = $base['sheet_label'];

        if ($placement === Invoice::IN_SHEET && ! $sheet->isPreparing()) {
            $tally->count('other', $amount);
            $tally->item($base + ['kind' => ImportBatchItem::UNCHANGED, 'status' => ImportBatchItem::PENDING, 'old' => $old, 'new' => $new, 'target_sheet_id' => $sheet->id,
                'reason' => "لم يتغيّر محتواها (موقعها الآن: {$from})، وأمرها {$sheet->orderLabel()} لكشف منشور (".$sheet->statusLabel().'): ستُضاف إليه بعد قرارك، وسيُنبَّه الموزع.']);

            return $existing;
        }

        $sameSpot = $placement === $existing->placement
            && (int) ($placement === Invoice::IN_SHEET ? $sheet?->id : 0) === (int) ($existing->placement === Invoice::IN_SHEET ? $existing->distribution_sheet_id : 0);
        if ($sameSpot) {
            $this->countPlaced($existing, null, $amount, $tally);
            $tally->item($base + ['kind' => ImportBatchItem::UNCHANGED, 'status' => ImportBatchItem::INFO, 'old' => $old, 'new' => $new,
                'reason' => "موجودة بلا تغيير ({$from})."]);

            return $existing;
        }

        $oldSheetId = $existing->distribution_sheet_id;
        // المرحلة 7F: الموقع السابق بصيغة منظَّمة (للتراجع عن الدفعة)؛ لا يغيّر شيئاً في الرفع
        $old['from'] = ['placement' => $existing->placement, 'sheet_id' => $existing->distribution_sheet_id,
            'manual_placement' => (bool) $existing->manual_placement, 'area_key' => $existing->area_key, 'area' => $existing->area];
        $this->place($existing, $placement, $sheet);
        $existing->manual_placement = false;
        $existing->save();
        $tally->touch($oldSheetId);
        $this->countPlaced($existing, $sheet, $amount, $tally);

        $to = $this->location($existing)['sheet_label'];
        $tally->item(array_merge($base, $this->location($existing), ['kind' => ImportBatchItem::UNCHANGED, 'status' => ImportBatchItem::INFO, 'old' => $old, 'new' => $new,
            'reason' => "لم يتغيّر محتواها، وتغيّر موقعها بحسب تاريخ التوزيع: من «{$from}» إلى «{$to}»."]));

        return $existing;
    }

    private function create(array $payload, Customer $customer, string $date, string $batchId, callable $sheetFor, ImportTally $tally, array $new): Invoice
    {
        [$placement, $sheet] = $this->classify($payload, $date, $sheetFor, $tally, true);

        // هدفها كشف منشور: تُنشأ «بلا كشف» وتنتظر القرار
        $pendingTarget = null;
        if ($placement === Invoice::IN_SHEET && ! $sheet->isPreparing()) {
            [$pendingTarget, $placement, $sheet] = [$sheet, Invoice::UNASSIGNED, null];
        }

        $invoice = new Invoice(['last_seen_batch_id' => $batchId]);
        $this->fill($invoice, $payload, $customer);
        $this->place($invoice, $placement, $sheet);
        $invoice->save();
        if ($this->primed !== null) {
            // المرحلة 7D: فاتورة جديدة بلا أسطر سابقة؛ أسطرها تُدرج جماعياً في flush وتبقى معروفة لظهور ثانٍ لها في الملف
            $rows = $this->lineRows($invoice, $payload['lines']);
            array_push($this->primed['new_lines'], ...$rows);
            $this->primed['lines'][$invoice->id] = collect($rows)->sortBy('line_no')->map(fn ($row) => (object) $row)->values();
            if ($invoice->invoice_key !== null) {
                $this->primed['invoices'][$invoice->invoice_key] = $invoice;
            }
        } else {
            $this->replaceLines($invoice, $payload['lines']);
        }
        $tally->itemInvoiceIds[$invoice->id] = true;

        $base = [
            'kind' => ImportBatchItem::CREATED,
            'invoice_id' => $invoice->id,
            'invoice_key' => $invoice->invoice_key,
            'invoice_number' => $invoice->invoice_number,
            'customer_id' => $invoice->customer_id,
            'customer_name' => $invoice->customer_name,
            'new' => $new,
        ] + $this->location($invoice);

        if ($pendingTarget) {
            $tally->count('other', (float) $payload['invoice_amount']);
            $tally->item($base + ['status' => ImportBatchItem::PENDING, 'target_sheet_id' => $pendingTarget->id,
                'reason' => "فاتورة جديدة أمرها {$pendingTarget->orderLabel()} لكشف منشور (".$pendingTarget->statusLabel().'): ستُضاف إليه بعد قرارك، وسيُنبَّه الموزع. حتى ذلك الحين هي في «بلا كشف».']);

            return $invoice;
        }

        $this->countPlaced($invoice, $sheet, (float) $payload['invoice_amount'], $tally);
        $reason = match (true) {
            // المرحلة 7H: ملحق لأمر كشفه مكتمل
            $placement === Invoice::IN_SHEET && $sheet->isSupplement() && isset($tally->supplements[$sheet->id])
                => "فاتورة جديدة: أمر {$sheet->delivery_order_no} كشفه مكتمل، فأُنشئ ملحق {$sheet->supplement_no} لهذه الفاتورة (قيد التجهيز).",
            $placement === Invoice::IN_SHEET && $sheet->isSupplement()
                => "فاتورة جديدة: أمر {$sheet->delivery_order_no} كشفه مكتمل، فأُضيفت إلى ملحق {$sheet->supplement_no} المفتوح (قيد التجهيز).",
            $placement === Invoice::IN_SHEET => "فاتورة جديدة أُضيفت إلى كشف أمر {$sheet->delivery_order_no} (قيد التجهيز).",
            $placement === Invoice::DEFERRED => "فاتورة جديدة مؤجلة: تاريخ تسليمها {$payload['delivery_date']} بعد تاريخ التوزيع {$date}.",
            $payload['delivery_order_no'] === null => 'فاتورة جديدة بلا أمر تسليم: وُضعت في «بلا كشف».',
            default => "فاتورة جديدة أمرها {$payload['delivery_order_no']} لكشف مكتمل بتاريخ التوزيع نفسه: وُضعت في «بلا كشف».",
        };
        $tally->item($base + ['status' => ImportBatchItem::INFO, 'reason' => $reason]);

        return $invoice;
    }

    /**
     * التصنيف حسب تاريخ التوزيع D: مؤجلة إن كان التسليم بعد D، وإلا كشف أمرها أو "بلا كشف".
     *
     * @return array{0: string, 1: ?DistributionSheet}
     */
    public function classify(array $payload, string $date, callable $sheetFor, ImportTally $tally, bool $newInvoice = false): array
    {
        $delivery = $payload['delivery_date'];
        $order = $payload['delivery_order_no'];
        $number = $payload['invoice_number'];

        if ($delivery !== null && $delivery > $date) {
            if ($order !== null) {
                $tally->listed('deferred_with_order', "{$number} (أمر {$order}، تسليم {$delivery})");
            }

            return [Invoice::DEFERRED, null];
        }

        if ($delivery !== null && $delivery < $date) {
            $tally->listed('old_delivery', "{$number} ({$delivery})");
        }

        if ($order === null) {
            $tally->listed('no_order', $number);

            return [Invoice::UNASSIGNED, null];
        }

        $sheet = $sheetFor($order, $newInvoice);
        if (! $sheet) {
            $tally->listed('completed_order', "{$number} (أمر {$order})");

            return [Invoice::UNASSIGNED, null];
        }

        return [Invoice::IN_SHEET, $sheet];
    }

    /**
     * موقع فاتورة بعد تطبيق حمولة جديدة (بلا إنشاء كشوف): مؤجلة / كشف مفتوح لأمرها بالتاريخ نفسه / «بلا كشف».
     *
     * @return array{placement: string, sheet: ?DistributionSheet, reason: string}
     */
    public function placementFor(array $payload, string $date, ?DistributionSheet $current): array
    {
        $order = $payload['delivery_order_no'];
        $currentOrder = $current?->delivery_order_no;

        if ($payload['delivery_date'] !== null && $payload['delivery_date'] > $date) {
            return ['placement' => Invoice::DEFERRED, 'sheet' => null,
                'reason' => "تاريخ التسليم الجديد {$payload['delivery_date']} بعد تاريخ التوزيع {$date}: تصبح مؤجلة"];
        }
        if ($order === null) {
            return ['placement' => Invoice::UNASSIGNED, 'sheet' => null,
                'reason' => $currentOrder !== null ? "أمر التسليم تغيّر من {$currentOrder} إلى «بلا أمر» (0 أو غير صالح): تذهب إلى «بلا كشف»" : 'بلا أمر تسليم: تذهب إلى «بلا كشف»'];
        }
        if ($current && (string) $current->delivery_order_no === (string) $order && in_array($current->status, DistributionSheet::EDITABLE_STATUSES, true)) {
            return ['placement' => Invoice::IN_SHEET, 'sheet' => $current, 'reason' => 'تبقى في كشف '.$current->shortLabel()];
        }
        $target = $this->findOpenSheet($order, $date);
        if ($target) {
            return ['placement' => Invoice::IN_SHEET, 'sheet' => $target,
                'reason' => ($currentOrder !== null ? "أمر التسليم تغيّر من {$currentOrder} إلى {$order}: " : '').'تنتقل إلى كشف '.$target->shortLabel().' ('.$target->statusLabel().')'];
        }

        return ['placement' => Invoice::UNASSIGNED, 'sheet' => null,
            'reason' => ($currentOrder !== null ? "أمر التسليم تغيّر من {$currentOrder} إلى {$order}، و" : '')."لا يوجد كشف مفتوح للأمر {$order} بتاريخ التوزيع {$date}: تذهب إلى «بلا كشف»"];
    }

    /**
     * كشف v2 مفتوح (قيد التجهيز أو منشور) لأمر وتاريخ — بحث فقط، لا إنشاء.
     */
    public function findOpenSheet(string $order, string $date): ?DistributionSheet
    {
        return DistributionSheet::where('format', 'v2')
            ->whereDate('distribution_date', $date)
            ->where('delivery_order_no', $order)
            ->whereIn('status', DistributionSheet::EDITABLE_STATUSES)
            ->orderBy('id')
            ->first();
    }

    /**
     * جملة «ماذا سيحدث عند التطبيق» ومعرّف الكشف الهدف إن وُجد.
     *
     * @return array{0: string, 1: ?int}
     */
    private function predict(array $payload, string $date, ?DistributionSheet $current): array
    {
        $plan = $this->placementFor($payload, $date, $current);
        $published = $plan['sheet'] && in_array($plan['sheet']->status, ['pending', 'in_progress'], true);
        $currentPublished = $current && in_array($current->status, ['pending', 'in_progress'], true);
        $notify = ($published || $currentPublished) ? ' وسيُنبَّه الموزع.' : '.';

        return ['عند التطبيق: '.$plan['reason'].$notify, $plan['sheet']?->id];
    }

    private function changeKind(Invoice $invoice, array $payload): string
    {
        $old = $invoice->delivery_order_no;
        $new = $payload['delivery_order_no'];
        if ((string) $old === (string) $new) {
            return ImportBatchItem::CHANGED;
        }

        return $new === null ? ImportBatchItem::ORDER_REMOVED : ImportBatchItem::ORDER_MOVED;
    }

    /**
     * البيانات الكاملة للفاتورة من الملف (تُحفظ كما هي في اقتراحات التغيير).
     */
    public function payloadFor(array $data, Customer $customer): array
    {
        $order = InvoiceFingerprint::order($data['delivery_order_no'] ?? null);

        return [
            'invoice_number' => $data['invoice_number'],
            'invoice_key' => InvoiceFingerprint::key($data['series'], $data['number']),
            'customer_name' => $data['customer_name'],
            'customer_id' => $customer->id,
            'series' => $data['series'],
            'invoice_date' => $data['invoice_date'],
            'delivery_date' => $data['delivery_date'],
            'delivery_order_no' => $order,
            'cost_center_code' => $data['cost_center_code'],
            'cost_center_name' => $data['cost_center_name'],
            'entered_by_name' => $data['entered_by_name'],
            'invoice_note' => $data['invoice_note'],
            // المرحلة 6B: الصافي (Σ صافي الأسطر) حين يحوي الملف عمود الحسم، وإلا Σ الأسطر كما كان
            'invoice_amount' => round((float) $data['total'], 2),
            'is_zero_value' => (bool) $data['is_zero_value'],
            'net_uncertain' => (bool) ($data['net_uncertain'] ?? false),
            'content_hash' => InvoiceFingerprint::hash(
                $data['customer_name'], $data['invoice_date'], $data['delivery_date'], $order,
                $data['cost_center_code'], $data['cost_center_name'], $data['lines']
            ),
            'lines' => array_map(fn ($line) => [
                'line_no' => $line['line_no'],
                'item_name' => $line['item_name'],
                'item_group' => $line['item_group'],
                'quantity' => $line['quantity'],
                'gift_quantity' => $line['gift_quantity'],
                'unit_price' => $line['unit_price'],
                'line_total' => $line['line_total'],
                'discount_percent' => $line['discount_percent'] ?? null,
                'discount_amount' => $line['discount_amount'] ?? null,
                'line_net' => $line['line_net'] ?? $line['line_total'],
            ], $data['lines']),
        ];
    }

    /**
     * استبدال بيانات الفاتورة وأسطرها ببيانات من الملف (يُستخدم لتطبيق اقتراح تغيير).
     */
    public function applyPayload(Invoice $invoice, array $payload): void
    {
        $customer = Customer::find($payload['customer_id']);
        $this->fill($invoice, $payload, $customer);
        $invoice->save();
        $this->replaceLines($invoice, $payload['lines']);
    }

    // =====================================================================
    // لقطات العرض (القديم ← الجديد) والشرح
    // =====================================================================

    /**
     * @return array{amount: float, order: ?string, delivery_date: ?string, cost_center: ?string, lines_count: int, hash: ?string, lines: array}
     */
    public function invoiceSnapshot(Invoice $invoice): array
    {
        $lines = $this->primed['lines'][$invoice->id] ?? null;
        if ($lines === null || $this->primed === null) {
            $lines = $invoice->lines()->get(['item_name', 'quantity', 'line_total']);
        }

        return [
            'amount' => round((float) $invoice->invoice_amount, 2),
            'order' => $invoice->delivery_order_no,
            'delivery_date' => $invoice->delivery_date?->toDateString(),
            'cost_center' => $this->costCenter($invoice->cost_center_code, $invoice->cost_center_name),
            'lines_count' => $lines->count(),
            'hash' => $invoice->content_hash,
            'lines' => $lines->map(fn ($l) => [(string) $l->item_name, (float) $l->quantity, round((float) $l->line_total, 2)])->values()->all(),
        ];
    }

    public function payloadSnapshot(array $payload): array
    {
        return [
            'amount' => round((float) $payload['invoice_amount'], 2),
            'order' => $payload['delivery_order_no'],
            'delivery_date' => $payload['delivery_date'],
            'cost_center' => $this->costCenter($payload['cost_center_code'], $payload['cost_center_name']),
            'lines_count' => count($payload['lines']),
            'hash' => $payload['content_hash'],
            'lines' => array_map(fn ($l) => [(string) $l['item_name'], (float) $l['quantity'], round((float) $l['line_total'], 2)], $payload['lines']),
        ];
    }

    /**
     * المرحلة 7E2: الحقول الوصفية التي تغيّرت إن كان التغيّر وصفياً فقط، وإلا null.
     * «وصفي فقط» = بصمة الملف بعد إعادة مركز الكلفة وتاريخ الفاتورة إلى قيمتي الفاتورة المخزّنة تساوي بصمتها:
     * الزبون والتسليم والأمر والأسطر كلها (وبالتالي المبلغ) كما هي حرفياً بعد التطبيع.
     *
     * @return array<int, string>|null من: cost_center، invoice_date، invoice_note، entered_by
     */
    public function descriptiveFields(Invoice $existing, array $payload): ?array
    {
        $restored = InvoiceFingerprint::hash(
            $payload['customer_name'], $existing->invoice_date?->toDateString(), $payload['delivery_date'], $payload['delivery_order_no'],
            $existing->cost_center_code, $existing->cost_center_name, $payload['lines']
        );
        if ($existing->content_hash === null || $restored !== $existing->content_hash) {
            return null;
        }

        $fields = [];
        if ([(string) $existing->cost_center_code, (string) $existing->cost_center_name] !== [(string) $payload['cost_center_code'], (string) $payload['cost_center_name']]) {
            $fields[] = 'cost_center';
        }
        if ($existing->invoice_date?->toDateString() !== $payload['invoice_date']) {
            $fields[] = 'invoice_date';
        }
        if ((string) $existing->invoice_note !== (string) $payload['invoice_note']) {
            $fields[] = 'invoice_note';
        }
        if ((string) $existing->entered_by_name !== (string) $payload['entered_by_name']) {
            $fields[] = 'entered_by';
        }

        return $fields ?: null;
    }

    /**
     * «تغيّر مركز الكلفة من «بلا» إلى «62021-حسين قواص». لن يتغير المبلغ ولا الأصناف، وسيُنسب دَين الفاتورة لهذا المندوب»
     */
    private function descriptiveText(Invoice $existing, array $payload, array $fields, array $old, array $new): string
    {
        $parts = [];
        if (in_array('cost_center', $fields, true)) {
            $parts[] = 'تغيّر مركز الكلفة من «'.($old['cost_center'] ?? 'بلا').'» إلى «'.($new['cost_center'] ?? 'بلا').'»';
        }
        if (in_array('invoice_date', $fields, true)) {
            $parts[] = 'تغيّر تاريخ الفاتورة من '.($existing->invoice_date?->toDateString() ?? '—').' إلى '.($payload['invoice_date'] ?? '—');
        }
        if (in_array('invoice_note', $fields, true)) {
            $parts[] = 'تغيّر بيان الفاتورة';
        }
        if (in_array('entered_by', $fields, true)) {
            $parts[] = 'تغيّر اسم المُدخِل';
        }
        $who = ! in_array('cost_center', $fields, true) ? ''
            : ($payload['cost_center_code'] !== null ? '، وسيُنسب دَين الفاتورة لهذا المندوب' : '، ولن تُنسب الفاتورة لأي مندوب');

        return implode('، و', $parts).'. لن يتغير المبلغ ولا الأصناف'.$who;
    }
    /**
     * «المبلغ 1,000.00 ← 900.00؛ أمر التسليم 1580 ← بلا أمر …»
     */
    public function diffText(array $old, array $new): string
    {
        $parts = [];
        if (abs($old['amount'] - $new['amount']) >= 0.005) {
            $parts[] = 'المبلغ '.$this->money($old['amount']).' ← '.$this->money($new['amount']);
        }
        if ((string) $old['order'] !== (string) $new['order']) {
            $parts[] = 'أمر التسليم '.($old['order'] ?? 'بلا أمر').' ← '.($new['order'] ?? 'بلا أمر (0 أو غير صالح)');
        }
        if ((string) $old['delivery_date'] !== (string) $new['delivery_date']) {
            $parts[] = 'تاريخ التسليم '.($old['delivery_date'] ?? '—').' ← '.($new['delivery_date'] ?? '—');
        }
        if ((string) $old['cost_center'] !== (string) $new['cost_center']) {
            $parts[] = 'مركز الكلفة «'.($old['cost_center'] ?? '—').'» ← «'.($new['cost_center'] ?? '—').'»';
        }
        if ($old['lines_count'] !== $new['lines_count']) {
            $parts[] = 'عدد الأصناف '.$old['lines_count'].' ← '.$new['lines_count'];
        } elseif ($old['lines'] != $new['lines']) {
            $parts[] = 'تغيّرت أسطر الأصناف';
        }

        return $parts ? 'تغيّر في الملف: '.implode('؛ ', $parts) : 'تغيّرت بيانات أخرى في الفاتورة (الزبون أو تاريخ الفاتورة أو تفاصيل الأسطر)';
    }

    /**
     * @return array{sheet_id: ?int, sheet_label: string}
     */
    public function location(Invoice $invoice): array
    {
        if ($invoice->placement === Invoice::IN_SHEET && $invoice->distribution_sheet_id) {
            $sheet = $invoice->relationLoaded('distributionSheet') && $invoice->distributionSheet?->id === $invoice->distribution_sheet_id
                ? $invoice->distributionSheet
                : $this->findSheet((int) $invoice->distribution_sheet_id);
            $label = $sheet ? $sheet->shortLabel() : 'أمر #'.$invoice->distribution_sheet_id;

            return ['sheet_id' => $invoice->distribution_sheet_id, 'sheet_label' => $sheet?->isDraft() ? "كشف مسودة ({$label})" : $label];
        }

        return ['sheet_id' => null, 'sheet_label' => $invoice->placementLabel()];
    }

    private function costCenter(?string $code, ?string $name): ?string
    {
        $label = trim(($code ?? '').'-'.($name ?? ''), '-');

        return $label === '' ? null : $label;
    }

    private function money(float $value): string
    {
        return number_format($value, 2);
    }

    // =====================================================================

    /** تعبئة الفاتورة من حمولة الملف بلا حفظ (يستعملها applyPayload والتطبيق الجماعي 7E2) */
    public function fill(Invoice $invoice, array $payload, ?Customer $customer): void
    {
        $invoice->fill([
            'invoice_number' => $payload['invoice_number'],
            'invoice_key' => $payload['invoice_key'],
            'customer_name' => $payload['customer_name'],
            'customer_id' => $payload['customer_id'],
            'statement' => $payload['invoice_note'],
            'invoice_amount' => $payload['invoice_amount'],
            'invoice_pattern' => $payload['series'],
            'series' => $payload['series'],
            'invoice_date' => $payload['invoice_date'],
            'delivery_date' => $payload['delivery_date'],
            'delivery_order_no' => $payload['delivery_order_no'],
            'cost_center_code' => $payload['cost_center_code'],
            'cost_center_name' => $payload['cost_center_name'],
            'entered_by_name' => $payload['entered_by_name'],
            'invoice_note' => $payload['invoice_note'],
            'is_zero_value' => $payload['is_zero_value'],
            'net_uncertain' => (bool) ($payload['net_uncertain'] ?? false),
            'content_hash' => $payload['content_hash'],
        ]);

        if (! $invoice->exists) {
            $invoice->customer_total_invoices = 0;
            $invoice->final_balance = 0;
        }

        // منطقة الفاتورة تتبع الزبون ما لم تُعدَّل لهذه الفاتورة وحدها
        if (! $invoice->area_overridden && $customer) {
            $invoice->area = $customer->area;
            $invoice->area_key = $customer->areaKey();
        }
    }

    private function replaceLines(Invoice $invoice, array $lines): void
    {
        $invoice->lines()->delete();
        InvoiceLine::insert($this->lineRows($invoice, $lines));
    }

    public function lineRows(Invoice $invoice, array $lines): array
    {
        $now = now();

        return array_map(fn ($line) => $line + [
            'discount_percent' => null,
            'discount_amount' => null,
            'line_net' => $line['line_total'] ?? null,
            'invoice_id' => $invoice->id,
            'created_at' => $now,
            'updated_at' => $now,
        ], $lines);
    }

    private function place(Invoice $invoice, string $placement, ?DistributionSheet $sheet): void
    {
        $invoice->placement = $placement;
        $invoice->distribution_sheet_id = $placement === Invoice::IN_SHEET ? $sheet?->id : null;
    }

    private function countPlaced(Invoice $invoice, ?DistributionSheet $sheet, float $amount, ImportTally $tally): void
    {
        $bucket = match ($invoice->placement) {
            Invoice::IN_SHEET => 'sheets',
            Invoice::DEFERRED => 'deferred',
            Invoice::UNASSIGNED => 'unassigned',
            default => 'other',
        };
        $tally->count($bucket, $amount);

        if ($sheet) {
            $tally->touch($sheet->id);
            if (! $sheet->isPreparing()) {
                $tally->addedToPublished[] = ['number' => $invoice->invoice_number, 'sheet_id' => $sheet->id];
            }
        }
    }

    /**
     * حفظ حمولة الملف كاقتراح (لا يُكتب فوق اقتراح سابق: السابق يُعلَّم «حلّ محله أحدث» عند حفظ عناصر الدفعة).
     */
    private function propose(Invoice $invoice, array $payload, string $batchId, string $type = InvoiceChangeProposal::TYPE_CHANGE, string $status = InvoiceChangeProposal::PENDING): int
    {
        return InvoiceChangeProposal::create([
            'invoice_id' => $invoice->id,
            'batch_id' => $batchId,
            'payload' => $payload,
            'old_hash' => $invoice->content_hash,
            'new_hash' => $payload['content_hash'],
            'status' => $status,
            'type' => $type,
        ])->id;
    }
}
