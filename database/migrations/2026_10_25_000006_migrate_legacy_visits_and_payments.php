<?php

use App\Support\ArabicNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ترحيل بيانات التجريب (best-effort، لا يفشل):
 * - كل زبون له فاتورة عليها visited_at في كشف v2 ⇒ سجل sheet_visits واحد.
 * - كل زبون عليه مبلغ تحصيل (collected_amount > 0) ⇒ سجل payments واحد SYP (source = sheet، رقم الإيصال MIG-{id}).
 * - كل فاتورة بنمط external في كشف v2 ⇒ payments بـ source = external، ثم تُستبعد الفاتورة من العرض والمجاميع (لا حذف).
 * الأعداد تُسجَّل في سجل Laravel وفي الإعداد phase3_migration_counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        $counts = ['visits' => 0, 'sheet_payments' => 0, 'external_payments' => 0, 'skipped' => 0];

        $sheets = DB::table('distribution_sheets')->where('format', 'v2')->get(['id', 'distributor_id', 'supervisor_id', 'status']);

        foreach ($sheets as $sheet) {
            try {
                $this->migrateSheet($sheet, $counts);
            } catch (\Throwable $e) {
                $counts['skipped']++;
                Log::warning('ترحيل المرحلة 3: تعذّر ترحيل كشف', ['sheet_id' => $sheet->id, 'error' => $e->getMessage()]);
            }
        }

        DB::table('settings')->updateOrInsert(['key' => 'phase3_migration_counts'], ['value' => json_encode($counts), 'updated_at' => now(), 'created_at' => now()]);
        Log::info('ترحيل المرحلة 3: الزيارات والدفعات القديمة', $counts);
    }

    private function migrateSheet(object $sheet, array &$counts): void
    {
        $userId = $sheet->distributor_id
            ?? DB::table('sheet_assignments')->where('sheet_id', $sheet->id)->orderBy('id')->value('distributor_id')
            ?? $sheet->supervisor_id;
        $role = DB::table('users')->where('id', $userId)->value('role') ?? 'distributor';
        $now = now();

        $invoices = DB::table('invoices')->where('distribution_sheet_id', $sheet->id)->get();

        // --- الزيارات والتحصيل على فواتير عادية ---
        foreach ($invoices->where('invoice_pattern', '!=', 'external')->whereNotNull('customer_id')->groupBy('customer_id') as $customerId => $rows) {
            $visitedAt = $rows->pluck('visited_at')->filter()->sort()->first();
            if ($visitedAt && ! DB::table('sheet_visits')->where('distribution_sheet_id', $sheet->id)->where('customer_id', $customerId)->exists()) {
                $withGps = $rows->first(fn ($r) => $r->latitude !== null && $r->longitude !== null);
                DB::table('sheet_visits')->insert([
                    'client_uuid' => (string) Str::uuid(),
                    'distribution_sheet_id' => $sheet->id,
                    'customer_id' => $customerId,
                    'user_id' => $userId,
                    'status' => 'visited',
                    'reason' => null,
                    'visited_at' => $visitedAt,
                    'latitude' => $withGps?->latitude,
                    'longitude' => $withGps?->longitude,
                    'location_flag' => $withGps ? 'no_reference' : 'no_gps',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $counts['visits']++;
            }

            $collected = round((float) $rows->sum('collected_amount'), 2);
            if ($collected > 0) {
                $this->insertPayment($sheet->id, (int) $customerId, $userId, $role, 'sheet', $collected,
                    $rows->pluck('notes')->filter()->implode(' | ') ?: null, $visitedAt ?? $rows->max('updated_at'));
                $counts['sheet_payments']++;
            }
        }

        // --- الدفعات الخارجية القديمة (كانت سجلات فواتير) ---
        foreach ($invoices->where('invoice_pattern', 'external') as $external) {
            $amount = round((float) $external->collected_amount, 2);
            $customerId = $external->customer_id ?? $this->resolveCustomer((string) $external->customer_name);
            if ($amount > 0 && $customerId) {
                $this->insertPayment($sheet->id, $customerId, $userId, $role, 'external', $amount,
                    $external->notes ?: $external->statement, $external->visited_at ?? $external->created_at);
                $counts['external_payments']++;
            }

            DB::table('invoices')->where('id', $external->id)->update([
                'placement' => 'excluded',
                'is_deferred' => false,
                'exclusion_reason' => 'دفعة خارجية قديمة رُحّلت إلى جدول الدفعات (المرحلة 3).',
                'updated_at' => $now,
            ]);
        }
    }

    private function insertPayment(int $sheetId, int $customerId, int $userId, string $role, string $source, float $amount, ?string $note, $collectedAt): void
    {
        $now = now();
        $id = DB::table('payments')->insertGetId([
            'client_uuid' => (string) Str::uuid(),
            'receipt_number' => 'MIG-TMP-'.Str::uuid(),
            'distribution_sheet_id' => $sheetId,
            'customer_id' => $customerId,
            'entered_by' => $userId,
            'entered_role' => $role,
            'source' => $source,
            'amount' => $amount,
            'currency' => 'SYP',
            'amount_syp' => $amount,
            'rate_is_estimate' => false,
            'note' => $note,
            'status' => 'pending',
            'location_flag' => 'no_gps',
            'collected_at' => $collectedAt ?? $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('payments')->where('id', $id)->update(['receipt_number' => 'MIG-'.$id]);
    }

    /**
     * زبون الدفعة الخارجية القديمة (كانت بالاسم فقط): بالاسم المطبَّع أو الاسم البديل، وإلا يُنشأ للمراجعة.
     */
    private function resolveCustomer(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $normalized = ArabicNormalizer::normalize($name);

        $id = DB::table('customers')->where('name_normalized', $normalized)->value('id')
            ?? DB::table('customer_aliases')->where('alias_normalized', $normalized)->value('customer_id');
        if ($id) {
            return (int) $id;
        }

        return (int) DB::table('customers')->insertGetId([
            'name' => $name,
            'name_normalized' => $normalized,
            'needs_review' => true,
            'balance' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // لا تراجع عن ترحيل البيانات (لا حذف لأي دفعة أو زيارة)
    }
};
