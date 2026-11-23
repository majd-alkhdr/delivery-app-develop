<?php

use App\Services\Balances\SnapshotScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 7E: نطاق تغيّر الرصيد (app = مرتبط بحركة التطبيق في نافذة اللقطة، external = خارجي).
 * ملء ابتدائي للقطات الحالية بالقاعدة نفسها، ومن عُلِّمت «تحتاج تحققاً» وهي خارجية بلا دفعة مصدَّرة غير منعكسة تصبح «مفسَّرة»
 * (ويُستبدل في تفسيرها سطر «تحقق في المحاسبة» بسطر التغيّر الخارجي). لا يُحذف شيء.
 */
return new class extends Migration
{
    private const FLAGGED_LINE = 'غير مفسَّر: تحقق في المحاسبة (مرتجع؟ تعديل سند؟ إلغاء فاتورة؟)';

    public function up(): void
    {
        if (! Schema::hasTable('customer_balance_snapshots')) {
            return;
        }
        if (! Schema::hasColumn('customer_balance_snapshots', 'scope')) {
            Schema::table('customer_balance_snapshots', function (Blueprint $table) {
                $table->string('scope', 10)->nullable()->after('status');
            });
        }

        DB::table('customer_balance_snapshots')->orderBy('id')
            ->select(['id', 'customer_id', 'kind', 'status', 'exported_mismatch', 'prev_as_of', 'as_of', 'explanation'])
            ->chunk(500, function ($rows) {
                $linked = SnapshotScope::linked($rows->mapWithKeys(fn ($r) => [$r->id => [
                    'customer_id' => (int) $r->customer_id,
                    'from' => $r->prev_as_of ? substr((string) $r->prev_as_of, 0, 10) : null,
                    'to' => $r->as_of ? substr((string) $r->as_of, 0, 10) : null,
                ]])->all());

                foreach ($rows as $row) {
                    $update = ['scope' => $row->kind === 'created' ? null : ($linked[$row->id] ? SnapshotScope::APP : SnapshotScope::EXTERNAL)];
                    if ($row->status === 'flagged' && ! $row->exported_mismatch && ! $linked[$row->id] && $row->kind !== 'created') {
                        $update['status'] = 'ok';
                        $update['explanation'] = str_replace(self::FLAGGED_LINE, SnapshotScope::EXTERNAL_NOTE, (string) $row->explanation);
                    }
                    DB::table('customer_balance_snapshots')->where('id', $row->id)->update($update);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_balance_snapshots', 'scope')) {
            Schema::table('customer_balance_snapshots', function (Blueprint $table) {
                $table->dropColumn('scope');
            });
        }
    }
};
