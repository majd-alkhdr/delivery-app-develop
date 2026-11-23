<?php

namespace Tests\Feature\Phase2B;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * J) ترحيل الـ backfill يعمل على بيانات المرحلة 2 الموجودة دون فشل، بما فيها وجود مفتاح مكرر.
 * (على SQLite في الذاكرة: تُشغَّل migrations حتى المرحلة 2 فقط، تُدخل بيانات بصيغتها، ثم تُشغَّل migrations المرحلة 2B.)
 */
class BackfillMigrationTest extends TestCase
{
    private const PHASE_2B_PREFIX = '2026_10_20_';

    public function test_backfill_runs_on_phase_2_data_including_duplicate_keys(): void
    {
        // حماية: هذا الاختبار يشغّل migrate، فلا يعمل إلا على قاعدة SQLite في الذاكرة
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));

        $before = collect(glob(database_path('migrations/*.php')))
            ->reject(fn ($path) => str_starts_with(basename($path), self::PHASE_2B_PREFIX))
            ->values()
            ->all();
        Artisan::call('migrate', ['--path' => $before, '--realpath' => true]);
        $this->assertFalse(Schema::hasColumn('invoices', 'invoice_key'));

        $now = now();
        $userId = DB::table('users')->insertGetId([
            'name' => 'مشرف', 'email' => 's@example.com', 'password' => 'x', 'role' => 'supervisor', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $batchId = '0f7c3d1e-1111-4222-8333-944455556666';
        $sheet = fn (array $extra) => DB::table('distribution_sheets')->insertGetId($extra + [
            'distributor_id' => null, 'supervisor_id' => $userId, 'file_name' => 'f.xlsx', 'status' => 'pending',
            'format' => 'v2', 'delivery_order_no' => '3918', 'distribution_date' => '2026-10-13', 'batch_id' => $batchId,
            'total_all' => 999, 'total_deferred' => 300, 'total_effective' => 699, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $s1 = $sheet([]);
        $s2 = $sheet(['distribution_date' => '2026-10-14', 'delivery_order_no' => '3920']);
        $v1 = $sheet(['format' => 'v1', 'delivery_order_no' => null, 'batch_id' => null, 'distributor_id' => $userId]);

        $invoice = fn (int $sheetId, string $number, float $amount, bool $deferred, array $extra = []) => DB::table('invoices')->insertGetId($extra + [
            'distribution_sheet_id' => $sheetId, 'invoice_number' => $number, 'customer_name' => 'صيدلية ألف', 'invoice_amount' => $amount,
            'invoice_pattern' => 'G', 'series' => 'G', 'is_deferred' => $deferred, 'invoice_date' => '2026-10-12',
            'delivery_date' => $deferred ? '2026-10-14' : '2026-10-13', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $deferredOld = $invoice($s1, 'ع G: 1', 300, true);           // مؤجلة يوم 13
        $normal = $invoice($s1, 'ع A: 5', 699, false);
        $external = $invoice($s1, 'خارجية-1', 0, false, ['invoice_pattern' => 'external', 'series' => null]);
        $duplicate = $invoice($s2, 'ع G: 1', 300, false);            // الفاتورة نفسها يوم 14: مفتاح مكرر
        $v1Invoice = $invoice($v1, 'OLD-1', 50, false, ['series' => null, 'invoice_pattern' => 'M']);
        DB::table('invoice_lines')->insert([
            'invoice_id' => $normal, 'line_no' => 1, 'item_name' => 'صنف', 'quantity' => 1, 'gift_quantity' => 0,
            'unit_price' => 699, 'line_total' => 699, 'created_at' => $now, 'updated_at' => $now,
        ]);

        // ترحيل المرحلة 2B: يجب ألا يفشل
        $this->assertSame(0, Artisan::call('migrate'));
        $this->assertTrue(Schema::hasColumn('invoices', 'invoice_key'));
        $this->assertTrue(Schema::hasTable('import_batches'));
        $this->assertTrue(Schema::hasTable('invoice_change_proposals'));

        $row = fn (int $id) => DB::table('invoices')->find($id);

        // الأقدم يحتفظ بالمفتاح، والمكرر يبقى بلا مفتاح
        $this->assertSame('g:1', $row($deferredOld)->invoice_key);
        $this->assertNull($row($duplicate)->invoice_key);

        // المؤجلة خرجت من الكشف، والباقي في كشفه
        $this->assertSame('deferred', $row($deferredOld)->placement);
        $this->assertNull($row($deferredOld)->distribution_sheet_id);
        $this->assertSame('in_sheet', $row($duplicate)->placement);
        $this->assertEquals($s2, $row($duplicate)->distribution_sheet_id);

        $this->assertSame('a:5', $row($normal)->invoice_key);
        $this->assertSame('3918', $row($normal)->delivery_order_no);
        $this->assertSame(64, strlen($row($normal)->content_hash));
        $this->assertNull($row($external)->invoice_key);

        // v1 بلا تغيير
        $this->assertNull($row($v1Invoice)->invoice_key);
        $this->assertSame('in_sheet', $row($v1Invoice)->placement);
        $this->assertNull($row($v1Invoice)->delivery_order_no);

        // معنى أرقام الكشف: مجموع فواتيره الحالية، والمؤجل صفر
        $s1Row = DB::table('distribution_sheets')->find($s1);
        $this->assertEquals(699, (float) $s1Row->total_all);
        $this->assertEquals(699, (float) $s1Row->total_effective);
        $this->assertEquals(0, (float) $s1Row->total_deferred);

        // دفعة المرحلة 2 أصبح لها سجل في import_batches
        $this->assertTrue(DB::table('import_batches')->where('id', $batchId)->exists());
    }
}
