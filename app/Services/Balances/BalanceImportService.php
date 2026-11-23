<?php

namespace App\Services\Balances;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\BalanceImport;
use App\Models\Customer;
use App\Models\CustomerAlias;
use App\Models\CustomerBalanceSnapshot;
use App\Models\DistributionSheet;
use App\Models\ImportBatchItem;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\Import\ImportTally;
use App\Services\Import\WorkbookImporter;
use App\Services\SheetChangeNotifier;
use App\Services\SheetFiguresService;
use App\Support\ArabicNormalizer;
use App\Support\BulkWrite;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * تطبيق ملف الأرصدة (المرحلة 7B):
 * - حارس التاريخ: ملف أقدم من المطبَّق، أو بلا «لغاية» ⇒ لا تطبيق حتى قرار. نفس التاريخ ⇒ يُطبَّق مع ملاحظة.
 * - حارس التصفير: من سيُصفَّرون أكثر من النسبة المضبوطة ⇒ تأكيد «هل الملف كامل؟».
 * - لقطة customer_balance_snapshots لكل زبون تغيّر رصيده أو أُنشئ أو صُفِّر (أو عليه دفعات مصدَّرة للفحص).
 * - finalize: التفسير التقديري، انعكاس الدفعات المصدَّرة، تنبيه «تغيّر الرصيد»، وتحديث final_balance.
 * customers.balance يبقى «الخام» كما في المحاسبة.
 */
class BalanceImportService
{
    public function __construct(
        private BalanceDeltaExplainer $explainer,
        private SheetFiguresService $figures,
        private SheetChangeNotifier $notifier,
        private WorkbookImporter $parser,
    ) {
    }

    /**
     * مرحلة الرفع: إنشاء سجل الملف، فحص الحراس، والتطبيق إن سُمح (finalize يُستدعى بعد معالجة الفواتير).
     */
    public function stage(array $balances, string $fileName, ?string $storedPath, string $batchId, User $user, ?string $manualAsOf, ImportTally $tally, array &$warnings): BalanceImport
    {
        $asOf = $balances['as_of'] ?? null;
        $manual = false;
        if ($asOf === null && $manualAsOf) {
            [$asOf, $manual] = [$manualAsOf, true];
        }

        $import = BalanceImport::create([
            'user_id' => $user->id,
            'file_name' => $fileName,
            'as_of' => $asOf,
            'as_of_manual' => $manual,
            'rows_count' => count($balances['rows']),
            'total_debit' => $balances['total_debit'],
            'batch_id' => $batchId,
            'stored_path' => $storedPath,
            'status' => BalanceImport::HELD,
        ]);
        if ($manual) {
            $tally->note('balances_manual_date', 'warning', "ملف الأرصدة بلا تاريخ «لغاية»: أُدخل تاريخ الأرصدة يدوياً ({$asOf}) عند الرفع.");
        }

        $merged = $this->mergeRows($balances['rows'], $warnings);
        $hold = $this->hold($import, $merged, false, false);
        if ($hold) {
            $import->update(['hold_reason' => $hold[0], 'hold_message' => $hold[1]]);
            $tally->note('balances_held', 'warning', $hold[1]);

            return $import;
        }

        $sameDate = $this->sameDateNote($import, $tally);
        $this->apply($import, $merged, $balances, $user);
        // المرحلة 7E2: إعادة رفع ملف بالتاريخ نفسه وبالقيم نفسها: لا لقطات ولا تنبيهات، ويُقال ذلك صراحة
        if ($sameDate && (int) ($import->stats['snapshots'] ?? 0) === 0) {
            $tally->note('balances_same_date_unchanged', 'info', 'طُبّق ملف بالتاريخ نفسه سابقاً؛ الأرصدة بلا تغيير.');
        }

        return $import;
    }

    /**
     * سبب عدم التطبيق التلقائي، أو null: [رمز، نص].
     *
     * @return array{0: string, 1: string}|null
     */
    public function hold(BalanceImport $import, array $merged, bool $allowOlder, bool $confirmZeroing): ?array
    {
        if (! $import->as_of) {
            return [BalanceImport::HOLD_NO_DATE, 'ملف الأرصدة بلا تاريخ «لغاية»: لم تُطبَّق الأرصدة. أدخل تاريخ الأرصدة يدوياً ثم طبّقها.'];
        }

        $latest = BalanceImport::where('status', BalanceImport::APPLIED)->where('id', '!=', $import->id)->whereNotNull('as_of')
            ->orderByDesc('as_of')->first();
        if (! $allowOlder && $latest && $import->as_of->lt($latest->as_of)) {
            return [BalanceImport::HOLD_OLDER, 'تاريخ ملف الأرصدة ('.$import->as_of->format('d-m').') أقدم من الأرصدة المطبَّقة ('.$latest->as_of->format('d-m').'). لم تُطبَّق الأرصدة حتى تقرر.'];
        }

        if (! $confirmZeroing) {
            [$toZero, $nonZero] = $this->zeroingCounts($merged);
            $percent = (float) Setting::get('balances_zeroing_confirm_percent', 30);
            if ($nonZero > 0 && $toZero > 0 && ($toZero * 100 / $nonZero) > $percent) {
                $ratio = round($toZero * 100 / $nonZero);

                return [BalanceImport::HOLD_ZEROING, "سيُصفَّر رصيد {$toZero} صيدلية ({$ratio}% من الزبائن ذوي الرصيد غير الصفري) لأنها غير موجودة في ملف الأرصدة. هل الملف كامل؟ لم تُطبَّق الأرصدة حتى تقرر."];
            }
        }

        return null;
    }

    /**
     * [من سيُصفَّرون، الزبائن ذوو الرصيد غير الصفري]
     *
     * @return array{0: int, 1: int}
     */
    public function zeroingCounts(array $merged): array
    {
        // المرحلة 7D: العدّ في الذاكرة بدل NOT IN بآلاف الأسماء (النتيجة نفسها؛ الاسم الفارغ لا يُعدّ كما في SQL)
        $names = Customer::where('balance', '!=', 0)->pluck('name_normalized');
        $toZero = $names->filter(fn ($name) => $name !== null && ! isset($merged[(string) $name]))->count();

        return [$toZero, $names->count()];
    }

    /**
     * تطبيق الأرصدة وكتابة اللقطات (بلا تفسير؛ التفسير في finalize).
     */
    public function apply(BalanceImport $import, array $merged, array $balances, User $user): void
    {
        // المرحلة 7F: صورة الأرصدة قبل الكتابة (للتراجع الدقيق عن الدفعة)؛ لا تغيّر نتيجة التطبيق
        BalancePreImage::capture($import);
        $asOf = $import->as_of->toDateString();
        $before = Customer::get(['id', 'name_normalized', 'balance', 'balance_as_of'])->keyBy('name_normalized');
        $beforeById = $before->keyBy('id');
        $now = now();

        $matched = 0;
        $created = 0;
        $upserts = [];
        foreach ($merged as $key => $row) {
            isset($before[$key]) ? $matched++ : $created++;
            $upserts[] = [
                'name' => $row['name'],
                'name_normalized' => $key,
                'block' => $row['block'],
                'area' => $row['area'],
                'area_normalized' => $row['area'] !== null ? ArabicNormalizer::normalize($row['area']) : null,
                'balance' => $row['balance'],
                'balance_as_of' => $asOf,
                'last_balance_import_id' => $import->id,
                'needs_review' => false,
            ];
        }
        foreach (array_chunk($upserts, BulkWrite::CHUNK) as $chunk) {
            Customer::upsert($chunk, ['name_normalized'], ['block', 'area', 'area_normalized', 'balance', 'balance_as_of', 'last_balance_import_id', 'needs_review']);
        }

        // الزبائن غير الموجودين في الملف: رصيدهم صفر (المحاسبة لا تُصدر صفوف الرصيد الصفري) — مع لقطة لكل من تغيّر
        $zeroed = Customer::where(fn ($q) => $q->whereNull('last_balance_import_id')->orWhere('last_balance_import_id', '!=', $import->id))
            ->update(['balance' => 0, 'balance_as_of' => $asOf]);

        // دفعات مصدَّرة تنتظر الانعكاس: يُكتب لزبائنها لقطة حتى لو لم يتغيّر رصيدهم (فحص H)
        $awaiting = Payment::active()->where('status', '!=', 'pending')->whereNotNull('exported_at')->whereNull('reflected_at')
            ->where('exported_at', '<=', $now)->distinct()->pluck('customer_id')->flip();

        $snapshots = 0;
        $unchanged = 0;
        $rows = [];
        $after = Customer::get(['id', 'name_normalized', 'balance', 'balance_as_of'])->keyBy('id');
        foreach ($after as $customer) {
            $old = $beforeById->get($customer->id);
            $prev = $old ? round((float) $old->balance, 2) : null;
            $new = round((float) $customer->balance, 2);
            $kind = match (true) {
                $old === null => 'created',
                abs($new - $prev) < 0.005 => 'unchanged',
                ! isset($merged[$customer->name_normalized]) => 'zeroed',
                default => 'changed',
            };
            if ($kind === 'unchanged' && ! isset($awaiting[$customer->id])) {
                $unchanged++;

                continue;
            }
            $rows[] = new CustomerBalanceSnapshot([
                'customer_id' => $customer->id,
                'balance_import_id' => $import->id,
                'batch_id' => $import->batch_id,
                'kind' => $kind,
                'balance' => $new,
                'prev_balance' => $prev,
                'delta' => round($new - ($prev ?? 0), 2),
                'as_of' => $asOf,
                'prev_as_of' => $old?->balance_as_of,
            ]);
            $snapshots++;
        }
        // المرحلة 7D: إدخال جماعي بالقيم والترتيب نفسيهما (بدل استعلام لكل لقطة)
        BulkWrite::insertModels($rows);

        $stats = [
            'balance_import_id' => $import->id,
            'as_of' => $asOf,
            'rows' => count($balances['rows']),
            'matched' => $matched,
            'created' => $created,
            'zeroed' => $zeroed,
            'total_debit' => $balances['total_debit'],
            'snapshots' => $snapshots,
            'unchanged' => $unchanged,
        ];
        $import->update(['status' => BalanceImport::APPLIED, 'applied_at' => $now, 'applied_by' => $user->id, 'hold_reason' => null, 'stats' => $stats]);

        ActivityLog::record('customers.balances_imported', $import, [], [
            'file_name' => $import->file_name,
            'as_of' => $asOf,
        ], [
            'rows' => $stats['rows'],
            'matched' => $matched,
            'created' => $created,
            'zeroed' => $zeroed,
            'total_debit' => $balances['total_debit'],
            'snapshots' => $snapshots,
            'unchanged' => $unchanged,
        ]);
    }

    /**
     * بعد تطبيق الأرصدة ومعالجة فواتير الملف: التفسير، الانعكاس، التنبيهات، final_balance، وملاحظات الدفعة.
     */
    public function finalize(BalanceImport $import, ?ImportTally $tally = null): array
    {
        if (! $import->isApplied()) {
            return [];
        }
        $previousAppliedAt = BalanceImport::where('status', BalanceImport::APPLIED)->where('id', '!=', $import->id)
            ->where('applied_at', '<=', $import->applied_at)->max('applied_at');
        $minNotice = (float) Setting::get('balance_notice_min_change', 1);

        $snapshots = CustomerBalanceSnapshot::with('customer')->where('balance_import_id', $import->id)->get();
        $result = ['flagged' => 0, 'mismatch' => 0, 'notified' => 0, 'below_threshold' => 0, 'no_open_sheet' => 0];
        $customerIds = [];

        // المرحلة 7D: التفسير بمعطيات محمَّلة مسبقاً على دفعات، والكشوف المفتوحة لكل الزبائن باستعلام واحد
        $this->explainer->explainMany($snapshots, $previousAppliedAt ? Carbon::parse($previousAppliedAt) : null, $import->applied_at);
        $notify = $snapshots->reject(fn ($s) => abs((float) $s->delta) < $minNotice || $s->kind === 'created');
        $openSheets = $this->openSheetsByCustomer($notify->pluck('customer_id')->all());
        $notices = [];

        foreach ($snapshots as $snapshot) {
            $result['flagged'] += $snapshot->isFlagged() ? 1 : 0;
            $result['mismatch'] += $snapshot->exported_mismatch ? 1 : 0;
            $customerIds[] = $snapshot->customer_id;

            if (abs((float) $snapshot->delta) < $minNotice || $snapshot->kind === 'created') {
                $result['below_threshold'] += $snapshot->kind === 'created' ? 0 : 1;

                continue;
            }
            $sheets = $openSheets[$snapshot->customer_id] ?? [];
            foreach ($sheets as [$sheet, $invoice]) {
                $notices[] = [$sheet, $snapshot, $invoice];
            }
            $sheets ? $result['notified'] += count($sheets) : $result['no_open_sheet']++;
        }
        $this->notifier->notifyBalanceMany($notices);

        if ($result['mismatch'] > 0) {
            ActivityLog::record('customers.exported_unreflected', $import, [], [], [
                'customers' => $snapshots->where('exported_mismatch', true)->map(fn ($s) => [
                    'customer' => $s->customer?->name, 'exported' => (float) $s->exported_payments_sum, 'unexplained' => (float) $s->unexplained,
                ])->values()->all(),
            ]);
        }

        // final_balance يبقى محدّثاً للتوافق (لا يُعرض)
        if ($customerIds) {
            $this->figures->refreshMany(Invoice::whereIn('customer_id', $customerIds)->whereNotNull('distribution_sheet_id')
                ->whereHas('distributionSheet', fn ($q) => $q->where('format', 'v2')->where('status', '!=', 'draft'))
                ->distinct()->pluck('distribution_sheet_id'));
        }

        $import->update(['stats' => ($import->stats ?? []) + ['finalize' => $result]]);

        if ($tally) {
            if ($result['below_threshold'] > 0) {
                $tally->note('balances_below_threshold', 'info', "{$result['below_threshold']} تغيّراً في الأرصدة أقل من الحدّ (".number_format($minNotice, 0).' ل.س) لم يُنبَّه عنه الموزع أو المندوب، ويظهر في شاشة «تغيّرات الأرصدة».');
            }
            if ($result['no_open_sheet'] > 0) {
                $tally->note('balances_no_open_sheet', 'info', "{$result['no_open_sheet']} تغيّراً لزبائن ليس لهم كشف منشور أو مكتمل غير مغلق مالياً: لا إشعار للموزع، ويظهر في شاشة «تغيّرات الأرصدة».");
            }
        }

        return $result;
    }

    /**
     * الكشوف التي يُرسل إليها «تغيّر رصيد الصيدلية»: كل كشف منشور (أو مكتمل لم يُغلق مالياً) فيه فواتير للزبون.
     * المرحلة 7D: الكشوف المفتوحة باستعلام واحد، ثم فواتيرها لهؤلاء الزبائن على دفعات (معظم الزبائن بلا كشف).
     *
     * @param  array<int, int>  $customerIds
     * @return array<int, array<int, array{0: DistributionSheet, 1: Invoice}>> customer_id => [[الكشف، أول فاتورة للزبون فيه]] بترتيب الكشوف
     */
    private function openSheetsByCustomer(array $customerIds): array
    {
        if (! $customerIds) {
            return [];
        }
        $sheets = DistributionSheet::where('format', 'v2')
            ->where(fn ($q) => $q->whereIn('status', ['pending', 'in_progress'])
                ->orWhere(fn ($c) => $c->where('status', 'completed')
                    ->whereHas('payments', fn ($p) => $p->whereNull('voided_at')->where('status', 'pending'))))
            ->orderBy('id')->get()->keyBy('id');
        if ($sheets->isEmpty()) {
            return [];
        }

        $first = [];
        foreach (array_chunk(array_values(array_unique($customerIds)), BulkWrite::CHUNK) as $chunk) {
            $invoices = Invoice::whereIn('distribution_sheet_id', $sheets->keys()->all())->whereIn('customer_id', $chunk)
                ->where('placement', Invoice::IN_SHEET)->orderBy('id')->get(['id', 'distribution_sheet_id', 'customer_id', 'customer_name', 'area_key']);
            foreach ($invoices as $invoice) {
                $first[$invoice->customer_id][$invoice->distribution_sheet_id] ??= $invoice;
            }
        }

        $result = [];
        foreach ($first as $customerId => $bySheet) {
            ksort($bySheet);
            foreach ($bySheet as $sheetId => $invoice) {
                $result[$customerId][] = [$sheets[$sheetId], $invoice];
            }
        }

        return $result;
    }

    // =====================================================================
    // قرارات ملف الأرصدة المحجوز
    // =====================================================================

    /**
     * تطبيق ملف محجوز: «تطبيقها رغم ذلك» (الأدمن، سبب إلزامي)، أو «نعم، الملف كامل»، أو بعد إدخال التاريخ يدوياً.
     *
     * @return array{ok: bool, message: string}
     */
    public function applyHeld(BalanceImport $import, User $user, ?string $reason, bool $confirmZeroing, ?string $manualAsOf): array
    {
        if (! $user->hasRole(Role::Admin, Role::SheetManager)) {
            abort(403, 'قرار الأرصدة لمسؤول الكشوف والمدير فقط.');
        }
        if (! $import->isHeld()) {
            throw ValidationException::withMessages(['balances' => 'تم البت في ملف الأرصدة هذا ('.$import->statusLabel().').']);
        }
        $reason = $reason !== null ? trim($reason) : null;
        $allowOlder = false;
        if ($import->hold_reason === BalanceImport::HOLD_OLDER || ($manualAsOf && $this->isOlder($manualAsOf, $import))) {
            if ($reason !== null && $reason !== '' && ! $user->isAdmin()) {
                abort(403, 'تطبيق أرصدة أقدم من المطبَّقة للمدير فقط.');
            }
            $allowOlder = $user->isAdmin() && $reason !== null && mb_strlen($reason) >= 3;
        }

        $path = $import->stored_path ? Storage::path($import->stored_path) : null;
        if (! $path || ! is_file($path)) {
            throw ValidationException::withMessages(['balances' => 'ملف الأرصدة الأصلي غير محفوظ، فلا يمكن تطبيقه. ارفعه من جديد.']);
        }
        $balances = $this->parser->parse($path)['balances'] ?? null;
        if (! $balances) {
            throw ValidationException::withMessages(['balances' => 'لم تُعثر في الملف المحفوظ على صفحة الأرصدة.']);
        }

        return DB::transaction(function () use ($import, $user, $reason, $confirmZeroing, $manualAsOf, $allowOlder, $balances) {
            if (! $import->as_of && $manualAsOf) {
                $import->update(['as_of' => $manualAsOf, 'as_of_manual' => true]);
            }
            $warnings = [];
            $merged = $this->mergeRows($balances['rows'], $warnings);
            if ($hold = $this->hold($import->fresh(), $merged, $allowOlder, $confirmZeroing)) {
                $import->update(['hold_reason' => $hold[0], 'hold_message' => $hold[1]]);

                return ['ok' => false, 'message' => $hold[1].($hold[0] === BalanceImport::HOLD_OLDER ? ' (التطبيق رغم ذلك للمدير مع سبب إلزامي).' : '')];
            }

            $import = $import->fresh();
            $oldReason = $import->hold_reason;
            $this->apply($import, $merged, $balances, $user);
            $import->update(['decided_by' => $user->id, 'decision_note' => $reason]);
            $result = $this->finalize($import->fresh());

            ActivityLog::record('customers.balances_applied_after_hold', $import, ['status' => BalanceImport::HELD, 'hold_reason' => $oldReason], [
                'status' => BalanceImport::APPLIED, 'as_of' => $import->as_of?->toDateString(), 'as_of_manual' => (bool) $import->as_of_manual,
            ], ['reason' => $reason, 'confirm_zeroing' => $confirmZeroing, 'batch_id' => $import->batch_id] + $result);

            return ['ok' => true, 'message' => 'طُبّقت أرصدة الملف ('.$import->file_name.') بتاريخ '.$import->as_of?->toDateString().($import->as_of_manual ? ' (تاريخ أُدخل يدوياً)' : '').'.'];
        });
    }

    public function ignore(BalanceImport $import, User $user, ?string $note): void
    {
        if (! $user->hasRole(Role::Admin, Role::SheetManager)) {
            abort(403, 'قرار الأرصدة لمسؤول الكشوف والمدير فقط.');
        }
        if (! $import->isHeld()) {
            throw ValidationException::withMessages(['balances' => 'تم البت في ملف الأرصدة هذا ('.$import->statusLabel().').']);
        }
        $old = $import->hold_reason;
        $import->update(['status' => BalanceImport::IGNORED, 'decided_by' => $user->id, 'decision_note' => $note !== null && trim($note) !== '' ? trim($note) : null]);
        ActivityLog::record('customers.balances_ignored', $import, ['status' => BalanceImport::HELD, 'hold_reason' => $old], ['status' => BalanceImport::IGNORED], [
            'note' => $import->decision_note, 'batch_id' => $import->batch_id, 'file_name' => $import->file_name,
        ]);
    }

    // =====================================================================

    private function isOlder(string $date, BalanceImport $import): bool
    {
        $latest = BalanceImport::where('status', BalanceImport::APPLIED)->where('id', '!=', $import->id)->whereNotNull('as_of')->max('as_of');

        return $latest !== null && $date < Carbon::parse($latest)->toDateString();
    }

    private function sameDateNote(BalanceImport $import, ImportTally $tally): bool
    {
        $same = BalanceImport::where('status', BalanceImport::APPLIED)->where('id', '!=', $import->id)
            ->whereDate('as_of', $import->as_of)->latest('applied_at')->first();
        if ($same) {
            $tally->note('balances_same_date', 'info', 'ملف أرصدة آخر بالتاريخ نفسه ('.$import->as_of->toDateString().') طُبِّق سابقاً؛ الأحدث رفعاً هو المعتمد. السابق: «'
                .$same->file_name.'» طُبّق '.$same->applied_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d H:i')
                .'، والحالي: «'.$import->file_name.'» '.now('Asia/Damascus')->format('Y-m-d H:i').'.');
        }

        return $same !== null;
    }

    /**
     * دمج الصفوف التي تتطابق أسماؤها بعد التطبيع (والأسماء البديلة).
     *
     * @return array<string, array> name_normalized => الصف
     */
    public function mergeRows(array $rows, array &$warnings): array
    {
        $existing = Customer::pluck('name_normalized', 'id')->flip()->all();
        $aliases = CustomerAlias::pluck('customer_id', 'alias_normalized')->all();
        $idToNormalized = array_flip($existing);

        $merged = [];
        foreach ($rows as $row) {
            $key = $row['name_normalized'];
            if (isset($aliases[$key]) && isset($idToNormalized[$aliases[$key]])) {
                $key = $idToNormalized[$aliases[$key]];
            }
            if (isset($merged[$key])) {
                $merged[$key]['balance'] = round($merged[$key]['balance'] + $row['balance'], 2);
                $warnings[] = "الاسم \"{$row['name']}\" مكرر في صفحة الأرصدة (بعد التطبيع)، وجُمع رصيداه.";

                continue;
            }
            $merged[$key] = $row + ['key' => $key];
        }

        return $merged;
    }
}
