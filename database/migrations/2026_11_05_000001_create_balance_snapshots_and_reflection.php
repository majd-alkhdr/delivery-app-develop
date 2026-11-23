<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 7B: أرصدة الصيدليات.
 * - customer_balance_snapshots: تاريخ تغيّر رصيد كل زبون مع التفسير التقديري.
 * - balance_imports: حالة التطبيق (مطبَّق / بانتظار قرار / متجاهَل) وملف الأرصدة المحفوظ والدفعة.
 * - payments: انعكاس الدفعة المصدَّرة في الأرصدة، والرصيد قبل/بعد المخزَّن للإيصال.
 * - sheet_change_notices: نوع «balance_changed» (نص بدل ENUM) وبيانات القيمتين.
 * - sheet_user_seen.customer_id: «اطلعت» على تغيّر رصيد صيدلية بعينها في الكشف.
 * - balance_change_acks: «اطلعت» للمندوب على تغيّر رصيد صيدلية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_balance_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('balance_import_id')->nullable()->index();
            $table->uuid('batch_id')->nullable();
            $table->string('kind', 20)->default('changed');            // changed | created | zeroed | unchanged
            $table->decimal('balance', 15, 2);
            $table->decimal('prev_balance', 15, 2)->nullable();
            $table->decimal('delta', 15, 2)->default(0);
            $table->date('as_of')->nullable();
            $table->date('prev_as_of')->nullable();
            $table->decimal('exported_payments_sum', 15, 2)->default(0);
            $table->decimal('invoice_effect_sum', 15, 2)->default(0);
            $table->decimal('unexplained', 15, 2)->default(0);
            $table->text('explanation')->nullable();
            $table->json('details')->nullable();                         // الفواتير والدفعات التي دخلت التفسير
            $table->string('status', 10)->default('ok');                 // ok | flagged
            $table->boolean('exported_mismatch')->default(false);        // دفعات مصدَّرة لم تنعكس (H)
            $table->unsignedBigInteger('seen_by')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'as_of']);
            $table->index('status');
        });

        Schema::table('balance_imports', function (Blueprint $table) {
            $table->uuid('batch_id')->nullable()->index();
            $table->string('stored_path', 255)->nullable();
            $table->string('status', 20)->default('applied');            // applied | held | ignored
            $table->string('hold_reason', 20)->nullable();               // older | no_date | zeroing
            $table->text('hold_message')->nullable();
            $table->boolean('as_of_manual')->default(false);
            $table->timestamp('applied_at')->nullable();
            $table->unsignedBigInteger('applied_by')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->text('decision_note')->nullable();
            $table->json('stats')->nullable();
        });
        // الملفات المطبَّقة سابقاً: وقت التطبيق = وقت الإنشاء
        DB::table('balance_imports')->update(['status' => 'applied', 'applied_at' => DB::raw('created_at')]);

        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('reflected_at')->nullable();
            $table->unsignedBigInteger('reflected_snapshot_id')->nullable();
            $table->decimal('balance_before', 16, 2)->nullable();
            $table->decimal('balance_after', 16, 2)->nullable();
            $table->date('balance_as_of')->nullable();
            $table->unsignedBigInteger('balance_snapshot_id')->nullable();
            $table->string('balance_source', 10)->nullable();            // server | device
        });
        // دفعات مصدَّرة سابقاً وصل بعدها ملف أرصدة: منعكسة
        $latestImport = DB::table('balance_imports')->max('created_at');
        if ($latestImport) {
            DB::table('payments')->where('status', '!=', 'pending')->whereNotNull('exported_at')
                ->where('exported_at', '<', $latestImport)->whereNull('reflected_at')
                ->update(['reflected_at' => $latestImport]);
        }

        Schema::table('sheet_change_notices', function (Blueprint $table) {
            $table->string('type', 30)->change();
            $table->json('meta')->nullable();
        });

        Schema::table('sheet_user_seen', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->index(['distribution_sheet_id', 'user_id', 'customer_id'], 'sheet_user_seen_customer_index');
        });
        // صف لكل (كشف، مستخدم) للإشعارات العامة + صف لكل صيدلية لتغيّر رصيدها: لم يعد (كشف، مستخدم) فريداً
        Schema::table('sheet_user_seen', function (Blueprint $table) {
            $table->dropUnique(['distribution_sheet_id', 'user_id']);
        });

        Schema::create('balance_change_acks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('snapshot_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('seen_at')->nullable();
            $table->unique(['snapshot_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_change_acks');
        DB::table('sheet_user_seen')->whereNotNull('customer_id')->delete();
        Schema::table('sheet_user_seen', function (Blueprint $table) {
            $table->unique(['distribution_sheet_id', 'user_id']);
        });
        Schema::table('sheet_user_seen', function (Blueprint $table) {
            $table->dropIndex('sheet_user_seen_customer_index');
            $table->dropColumn('customer_id');
        });
        Schema::table('sheet_change_notices', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['reflected_at', 'reflected_snapshot_id', 'balance_before', 'balance_after', 'balance_as_of', 'balance_snapshot_id', 'balance_source']);
        });
        Schema::table('balance_imports', function (Blueprint $table) {
            $table->dropColumn(['batch_id', 'stored_path', 'status', 'hold_reason', 'hold_message', 'as_of_manual', 'applied_at', 'applied_by', 'decided_by', 'decision_note', 'stats']);
        });
        Schema::dropIfExists('customer_balance_snapshots');
    }
};
