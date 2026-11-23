<?php

namespace Tests\Feature\Phase7F;

use App\Enums\Role;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * سيناريو المرحلة 7F:
 * - ملف أساس (توزيع 10-10، أرصدة 10-10): أ (1000) وب (500)؛ G:1 لأ بلا أمر («بلا كشف»)، G:5 لأ تسليم 13-10 (مؤجلة)، G:6 لب أمر 3910 (كشف قيد التجهيز).
 * - ملف خاطئ (توزيع 13-10، أرصدة 13-10): أ 1500 بمنطقة أخرى، ب 450، ج جديد 700؛
 *   G:1 كما هي، G:5 كما هي (تنتقل من «مؤجلة» إلى كشف أمر 3918 الجديد)، G:2 جديدة لأ أمر 3918، G:3 لزبون جديد د بلا أمر،
 *   G:4 لأ مؤجلة (تسليم 20-10)، G:7 لج بلا أمر.
 */
trait RollbackFixture
{
    protected User $admin;
    protected User $manager;

    protected function setUpRollback(): void
    {
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->manager = User::factory()->role(Role::SheetManager)->create();
    }

    protected function baseFile(): string
    {
        return $this->buildWorkbook([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية أ', 'delivery' => '10-10-2026', 'order' => 0, 'lines' => [['صنف 1', 100]]],
            ['number' => 'ع G: 5', 'customer' => 'صيدلية أ', 'delivery' => '13-10-2026', 'order' => 3918, 'lines' => [['صنف 5', 500]]],
            ['number' => 'ع G: 6', 'customer' => 'صيدلية ب', 'delivery' => '10-10-2026', 'order' => 3910, 'lines' => [['صنف 6', 600]]],
        ], [
            ['صيدلية أ', 'كتلة1', 'منطقة1', 1000, 0],
            ['صيدلية ب', 'كتلة1', 'منطقة2', 500, 0],
        ], '10-10-2026');
    }

    protected function wrongFile(string $asOf = '13-10-2026'): string
    {
        return $this->buildWorkbook([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية أ', 'delivery' => '10-10-2026', 'order' => 0, 'lines' => [['صنف 1', 100]]],
            ['number' => 'ع G: 5', 'customer' => 'صيدلية أ', 'delivery' => '13-10-2026', 'order' => 3918, 'lines' => [['صنف 5', 500]]],
            ['number' => 'ع G: 2', 'customer' => 'صيدلية أ', 'delivery' => '13-10-2026', 'order' => 3918, 'lines' => [['صنف 2', 200], ['صنف 2ب', 20]]],
            ['number' => 'ع G: 3', 'customer' => 'صيدلية د', 'delivery' => '13-10-2026', 'order' => 0, 'lines' => [['صنف 3', 300]]],
            ['number' => 'ع G: 4', 'customer' => 'صيدلية أ', 'delivery' => '20-10-2026', 'order' => 0, 'lines' => [['صنف 4', 400]]],
            ['number' => 'ع G: 7', 'customer' => 'صيدلية ج', 'delivery' => '13-10-2026', 'order' => 0, 'lines' => [['صنف 7', 700]]],
        ], [
            ['صيدلية أ', 'كتلة2', 'منطقة9', 1500, 0],
            ['صيدلية ب', 'كتلة1', 'منطقة2', 450, 0],
            ['صيدلية ج', 'كتلة3', 'منطقة3', 700, 0],
        ], $asOf);
    }

    protected function upload(string $path, string $date): ImportBatch
    {
        return $this->importFiles($path, $date, $this->manager)['batch'];
    }

    /**
     * لقطة مقارنة: أعداد الجداول وصفوفها المفتاحية (بلا سجل الدفعات والنشاط، فهما تاريخ يبقى بقصد).
     */
    protected function dbState(): array
    {
        $rows = fn (string $table, array $columns, string $order) => DB::table($table)->orderBy($order)->get($columns)
            ->map(fn ($r) => array_map(fn ($v) => is_float($v) || is_numeric($v) && str_contains((string) $v, '.') ? round((float) $v, 2) : $v, (array) $r))->all();

        return [
            'counts' => collect(['invoices', 'invoice_lines', 'customers', 'distribution_sheets', 'customer_balance_snapshots', 'sheet_change_notices',
                'invoice_change_proposals', 'invoice_revisions', 'sheet_assignments', 'customer_aliases'])
                ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all(),
            'applied_balance_imports' => DB::table('balance_imports')->where('status', 'applied')->orderBy('id')->pluck('id')->all(),
            'invoices' => $rows('invoices', ['id', 'invoice_key', 'customer_id', 'placement', 'is_deferred', 'distribution_sheet_id', 'invoice_amount', 'content_hash',
                'manual_placement', 'last_seen_batch_id', 'delivery_order_no', 'exclusion_reason', 'cancelled_reason'], 'id'),
            'lines' => $rows('invoice_lines', ['invoice_id', 'line_no', 'item_name', 'line_total'], 'id'),
            'customers' => $rows('customers', ['id', 'name_normalized', 'balance', 'balance_as_of', 'last_balance_import_id', 'block', 'area', 'area_normalized', 'needs_review'], 'id'),
            'sheets' => $rows('distribution_sheets', ['id', 'status', 'delivery_order_no', 'total_all', 'total_deferred', 'total_effective'], 'id'),
        ];
    }
}
