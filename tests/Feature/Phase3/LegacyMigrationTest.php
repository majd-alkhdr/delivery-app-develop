<?php

namespace Tests\Feature\Phase3;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * G) ترحيل بيانات التجريب: الزيارات والتحصيل القديمة والدفعات الخارجية إلى sheet_visits وpayments.
 * يشغّل migrations حتى ما قبل الترحيل على SQLite في الذاكرة، يُدخل بيانات قديمة، ثم يشغّل الترحيل.
 */
class LegacyMigrationTest extends TestCase
{
    private const MIGRATION = '2026_10_25_000006_migrate_legacy_visits_and_payments.php';

    public function test_legacy_visits_collections_and_external_invoices_are_migrated(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));

        $before = collect(glob(database_path('migrations/*.php')))
            ->reject(fn ($p) => basename($p) === self::MIGRATION)
            ->values()->all();
        Artisan::call('migrate', ['--path' => $before, '--realpath' => true]);

        $now = now();
        $user = DB::table('users')->insertGetId(['name' => 'موزع', 'email' => 'd@example.com', 'password' => 'x', 'role' => 'distributor', 'created_at' => $now, 'updated_at' => $now]);
        $sheet = DB::table('distribution_sheets')->insertGetId([
            'format' => 'v2', 'distributor_id' => $user, 'supervisor_id' => $user, 'file_name' => 'x.xlsx', 'status' => 'in_progress',
            'delivery_order_no' => '3918', 'distribution_date' => '2026-10-13', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $customer = fn ($name) => DB::table('customers')->insertGetId(['name' => $name, 'name_normalized' => $name, 'balance' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $a = $customer('صيدلية أ');
        $b = $customer('صيدلية ب');
        $invoice = fn (array $v) => DB::table('invoices')->insert($v + [
            'distribution_sheet_id' => $sheet, 'invoice_amount' => 100, 'placement' => 'in_sheet', 'created_at' => $now, 'updated_at' => $now,
        ]);

        // زبون أ: فاتورتان، زيارة وتحصيل على الأولى
        $invoice(['invoice_number' => 'ع G: 1', 'customer_name' => 'صيدلية أ', 'customer_id' => $a, 'visited_at' => '2026-10-13 10:00:00', 'collected_amount' => 150, 'notes' => 'دفعة']);
        $invoice(['invoice_number' => 'ع G: 2', 'customer_name' => 'صيدلية أ', 'customer_id' => $a, 'collected_amount' => 50]);
        // زبون ب: زيارة بلا تحصيل
        $invoice(['invoice_number' => 'ع G: 3', 'customer_name' => 'صيدلية ب', 'customer_id' => $b, 'visited_at' => '2026-10-13 11:00:00']);
        // دفعة خارجية قديمة كانت سجل فاتورة (بالاسم فقط، لزبون غير موجود)
        $invoice(['invoice_number' => 'خارجية-1', 'customer_name' => 'صيدلية خارجية', 'invoice_pattern' => 'external', 'invoice_amount' => 0, 'collected_amount' => 300, 'visited_at' => '2026-10-13 12:00:00']);

        $this->assertSame(0, Artisan::call('migrate'));

        $this->assertSame(2, DB::table('sheet_visits')->count());
        $sheetPayment = DB::table('payments')->where('source', 'sheet')->first();
        $this->assertEquals(200, $sheetPayment->amount);          // مجموع ما حُصّل على فواتير الزبون
        $this->assertSame('SYP', $sheetPayment->currency);
        $this->assertSame('MIG-'.$sheetPayment->id, $sheetPayment->receipt_number);
        $this->assertSame(36, strlen($sheetPayment->client_uuid));

        $external = DB::table('payments')->where('source', 'external')->first();
        $this->assertEquals(300, $external->amount);
        $this->assertSame('صيدلية خارجية', DB::table('customers')->where('id', $external->customer_id)->value('name'));
        $this->assertSame('excluded', DB::table('invoices')->where('invoice_pattern', 'external')->value('placement'));
        $this->assertSame(1, DB::table('invoices')->where('invoice_pattern', 'external')->count());   // لا حذف

        $counts = json_decode(DB::table('settings')->where('key', 'phase3_migration_counts')->value('value'), true);
        $this->assertSame(['visits' => 2, 'sheet_payments' => 1, 'external_payments' => 1, 'skipped' => 0], $counts);
    }
}
