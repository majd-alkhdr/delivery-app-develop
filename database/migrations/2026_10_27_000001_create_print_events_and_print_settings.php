<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** إعدادات الطباعة (لا تكتب فوق قيمة موجودة؛ printing_enabled موجود من المرحلة 3) */
    public const SETTINGS = [
        'printing_enabled' => false,
        'print_paper_dots' => 576,
        'print_chunk_bytes' => 100,
        'print_chunk_delay_ms' => 20,
        'print_slice_rows' => 128,
        'print_threshold' => 160,
        'print_feed_lines' => 4,
        'print_cut' => false,
        'print_header_title' => 'مستودع نيو هبة للأدوية',
        'print_footer_text' => 'شكراً لتعاملكم معنا',
        'print_mark_duplicates' => true,
    ];

    public function up(): void
    {
        Schema::create('print_events', function (Blueprint $table) {
            $table->id();
            $table->string('client_uuid', 36)->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->enum('kind', ['receipt', 'invoice', 'merged']);
            $table->foreignId('payment_id')->nullable()->constrained('payments');
            $table->foreignId('invoice_id')->nullable()->constrained('invoices');
            $table->foreignId('distribution_sheet_id')->nullable()->constrained('distribution_sheets');
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->enum('mode', ['ble', 'browser', 'image', 'rawbt', 'preview']);
            $table->enum('status', ['success', 'failed']);
            $table->string('error', 500)->nullable();
            $table->string('device_label', 100)->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('payment_id');
            $table->index('invoice_id');
            $table->index(['distribution_sheet_id', 'customer_id']);
        });

        $now = now();
        foreach (self::SETTINGS as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert(['key' => $key, 'value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('print_events');
        // printing_enabled أُنشئ في المرحلة 3 فلا يُحذف هنا
        DB::table('settings')->whereIn('key', array_values(array_diff(array_keys(self::SETTINGS), ['printing_enabled'])))->delete();
    }
};
