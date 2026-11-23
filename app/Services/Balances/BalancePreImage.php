<?php

namespace App\Services\Balances;

use App\Models\BalanceImport;
use App\Support\CompressedJson;
use Illuminate\Support\Facades\DB;

/**
 * المرحلة 7F: صورة أرصدة كل الزبائن قبل تطبيق ملف أرصدة (صف واحد مضغوط لكل ملف).
 * يكتبها BalanceImportService::apply قبل الكتابة، ويستعملها التراجع عن الدفعة ليعيد كل زبون كما كان بدقة.
 * لا تغيّر شيئاً في نتيجة الرفع.
 */
class BalancePreImage
{
    public const FIELDS = ['balance', 'balance_as_of', 'last_balance_import_id', 'block', 'area', 'area_normalized', 'needs_review'];

    public static function capture(BalanceImport $import): void
    {
        $rows = [];
        foreach (DB::table('customers')->orderBy('id')->get(array_merge(['id'], self::FIELDS)) as $c) {
            $rows[(int) $c->id] = [
                round((float) $c->balance, 2),
                $c->balance_as_of !== null ? substr((string) $c->balance_as_of, 0, 10) : null,
                $c->last_balance_import_id !== null ? (int) $c->last_balance_import_id : null,
                $c->block, $c->area, $c->area_normalized, (bool) $c->needs_review,
            ];
        }
        DB::table('balance_import_states')->updateOrInsert(
            ['balance_import_id' => $import->id],
            ['customers_count' => count($rows), 'payload' => CompressedJson::encode($rows), 'created_at' => now(), 'updated_at' => now()],
        );
    }

    /**
     * @return array<int, array{balance: float, balance_as_of: ?string, last_balance_import_id: ?int, block: ?string, area: ?string, area_normalized: ?string, needs_review: bool}>|null
     */
    public static function load(int $balanceImportId): ?array
    {
        $payload = DB::table('balance_import_states')->where('balance_import_id', $balanceImportId)->value('payload');
        $rows = CompressedJson::decode($payload);
        if (! is_array($rows)) {
            return null;
        }

        return array_map(fn ($r) => array_combine(self::FIELDS, $r), $rows);
    }
}
