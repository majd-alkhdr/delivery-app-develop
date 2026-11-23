<?php

use App\Support\InvoiceFingerprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // الفواتير المؤجلة و"بلا كشف" ليست في أي كشف
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('distribution_sheet_id')->nullable()->change();
        });

        Schema::table('invoices', function (Blueprint $table) {
            // السلسلة + الرقم بعد التطبيع؛ null لفواتير v1 والدفعات الخارجية
            $key = $table->string('invoice_key', 191)->nullable()->unique();
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $key->collation('utf8mb4_bin');
            }
            // in_sheet / deferred / unassigned / excluded / cancelled
            $table->string('placement', 20)->default('in_sheet')->index();
            $table->string('delivery_order_no', 50)->nullable()->index();
            $table->string('content_hash', 64)->nullable();
            $table->boolean('manual_placement')->default(false);
            $table->boolean('area_overridden')->default(false);
            $table->text('exclusion_reason')->nullable();
            $table->text('cancelled_reason')->nullable();
            $table->uuid('last_seen_batch_id')->nullable()->index();
        });

        $this->backfill();
    }

    /**
     * ترحيل بيانات المرحلة 2: لا يفشل حتى مع وجود مفاتيح مكررة.
     */
    private function backfill(): void
    {
        $v2Sheets = DB::table('distribution_sheets')->where('format', 'v2')->pluck('delivery_order_no', 'id');
        if ($v2Sheets->isEmpty()) {
            return;
        }

        $seen = [];
        $duplicates = [];

        DB::table('invoices')
            ->whereIn('distribution_sheet_id', $v2Sheets->keys())
            ->orderBy('id')
            ->chunkById(500, function ($invoices) use ($v2Sheets, &$seen, &$duplicates) {
                $lines = DB::table('invoice_lines')
                    ->whereIn('invoice_id', $invoices->pluck('id'))
                    ->orderBy('line_no')
                    ->get()
                    ->groupBy('invoice_id');

                foreach ($invoices as $invoice) {
                    $order = InvoiceFingerprint::order($v2Sheets[$invoice->distribution_sheet_id] ?? null);
                    $external = $invoice->invoice_pattern === 'external';

                    $key = $external ? null : InvoiceFingerprint::keyFromNumber((string) $invoice->invoice_number);
                    if ($key !== null && isset($seen[$key])) {
                        // نحتفظ بالأقدم، والباقي يبقى بلا مفتاح
                        $duplicates[] = ['invoice_id' => $invoice->id, 'key' => $key, 'kept_invoice_id' => $seen[$key]];
                        $key = null;
                    } elseif ($key !== null) {
                        $seen[$key] = $invoice->id;
                    }

                    $deferred = (bool) $invoice->is_deferred;
                    $update = [
                        'invoice_key' => $key,
                        'delivery_order_no' => $order,
                        'placement' => $deferred ? 'deferred' : 'in_sheet',
                    ];
                    if ($deferred) {
                        $update['distribution_sheet_id'] = null;
                    }
                    if (! $external) {
                        $update['content_hash'] = InvoiceFingerprint::hash(
                            (string) $invoice->customer_name,
                            $invoice->invoice_date,
                            $invoice->delivery_date,
                            $order,
                            $invoice->cost_center_code,
                            $invoice->cost_center_name,
                            $lines->get($invoice->id, collect())->map(fn ($l) => (array) $l)->all()
                        );
                    }

                    DB::table('invoices')->where('id', $invoice->id)->update($update);
                }
            });

        if ($duplicates) {
            Log::warning('ترحيل المرحلة 2B: مفاتيح فواتير مكررة، احتُفظ بالأقدم وتُرك المفتاح فارغاً للباقي.', [
                'count' => count($duplicates),
                'duplicates' => $duplicates,
            ]);
        }

        // معنى أرقام الكشف تغيّر: إجمالي الكشف = فواتيره الحالية، والمؤجل خارج أي كشف
        foreach ($v2Sheets->keys() as $sheetId) {
            $total = round((float) DB::table('invoices')->where('distribution_sheet_id', $sheetId)->sum('invoice_amount'), 2);
            DB::table('distribution_sheets')->where('id', $sheetId)->update([
                'total_all' => $total,
                'total_deferred' => 0,
                'total_effective' => $total,
            ]);
        }
    }

    public function down(): void
    {
        // ملاحظة: distribution_sheet_id يبقى nullable لأن الفواتير المؤجلة لم تعد مرتبطة بكشف
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['invoice_key']);
            $table->dropIndex(['placement']);
            $table->dropIndex(['delivery_order_no']);
            $table->dropIndex(['last_seen_batch_id']);
            $table->dropColumn([
                'invoice_key', 'placement', 'delivery_order_no', 'content_hash', 'manual_placement',
                'area_overridden', 'exclusion_reason', 'cancelled_reason', 'last_seen_batch_id',
            ]);
        });
    }
};
