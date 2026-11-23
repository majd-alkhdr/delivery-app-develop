<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 6 (سيناريو المندوب): ربط المندوب بمركز الكلفة، حقول دفعة المندوب وصورة إيصالها،
 * تمييز لقطات المندوب عن الموزع، وتعميم الدفعات التصديرية لنوعين (كشف/مندوب).
 */
return new class extends Migration
{
    /** القالب الافتراضي حرفياً: لا مسافة قبل «يومية» ومسافة واحدة في النهاية */
    public const REP_STATEMENT_TEMPLATE = 'بيد السيد {user} إيصال إيصال مالية كشف رقم {order}يومية ';

    public function up(): void
    {
        // مفاتيح المطابقة الحرفية: utf8mb4_bin على MySQL (لا أثر على SQLite)
        $bin = fn ($column) => in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) ? $column->collation('utf8mb4_bin') : $column;

        // A) مركز كلفة المندوب
        Schema::table('users', function (Blueprint $table) use ($bin) {
            $bin($table->string('cost_center_code', 50)->nullable());
            $table->string('cost_center_label', 255)->nullable();
            // فهرس عادي (لا unique مع NULL)؛ التفرد بين المندوبين يفحصه التطبيق
            $table->index('cost_center_code');
        });

        // C) حقول دفعة المندوب (distribution_sheet_id في payments nullable منذ المرحلة 3)
        Schema::table('payments', function (Blueprint $table) use ($bin) {
            $bin($table->string('book_receipt_no', 40)->nullable());
            $table->boolean('duplicate_book_no')->default(false);
            $table->boolean('exceeds_known_debt')->default(false);
            $bin($table->string('cost_center_code', 50)->nullable());
            $table->string('cost_center_label', 255)->nullable();
            $table->enum('review_status', ['none', 'verified', 'needs_review'])->default('none');
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();

            $table->index(['entered_by', 'book_receipt_no']);
            $table->index(['source', 'status']);
        });

        // D) صورة إيصال المندوب: صورة واحدة لكل دفعة
        Schema::create('payment_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->unique()->constrained('payments');
            $table->string('path', 255);
            $table->string('sha256', 64);
            $table->unsignedInteger('size_bytes');
            $table->string('mime', 50);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamp('created_at')->nullable();
        });

        // B) تمييز لقطات المندوب عن الموزع، وربط لقطة المندوب بدفعتها
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->string('source', 12)->default('distributor');
            $table->foreignId('payment_id')->nullable()->constrained('payments');
            $table->index(['invoice_id', 'source']);
        });

        // G) تعميم finance_exports: النوع ومفتاح النطاق غير الفارغ (sheet:{id} أو rep)
        Schema::table('finance_exports', function (Blueprint $table) {
            $table->string('kind', 10)->default('sheet');
            $table->string('scope_key', 40)->default('');
            $table->json('meta')->nullable();
            // فهرس عادي على الكشف قبل إسقاط الـunique القديم (MySQL يحتاج فهرساً للمفتاح الأجنبي)
            $table->index('distribution_sheet_id', 'finance_exports_sheet_index');
        });
        // (حلقة PHP بدل دمج نصوص SQL: «||» في MySQL عملية OR)
        DB::table('finance_exports')->whereNotNull('distribution_sheet_id')->orderBy('id')->get(['id', 'distribution_sheet_id'])
            ->each(fn ($row) => DB::table('finance_exports')->where('id', $row->id)->update(['scope_key' => 'sheet:'.$row->distribution_sheet_id]));
        Schema::table('finance_exports', function (Blueprint $table) {
            $table->dropUnique(['distribution_sheet_id', 'sequence']);
            $table->unique(['scope_key', 'sequence']);
        });
        // distribution_sheet_id يصبح nullable (دفعات المندوبين بلا كشف). على MySQL يُسقط المفتاح الأجنبي ثم يُعاد.
        $mysql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
        if ($mysql) {
            Schema::table('finance_exports', fn (Blueprint $table) => $table->dropForeign(['distribution_sheet_id']));
        }
        Schema::table('finance_exports', function (Blueprint $table) {
            $table->unsignedBigInteger('distribution_sheet_id')->nullable()->change();
        });
        if ($mysql) {
            Schema::table('finance_exports', fn (Blueprint $table) => $table->foreign('distribution_sheet_id')->references('id')->on('distribution_sheets'));
        }

        Schema::table('finance_export_rows', function (Blueprint $table) {
            $table->foreignId('rep_user_id')->nullable()->constrained('users');
        });

        $now = now();
        foreach ([
            'finance_rep_statement_template' => self::REP_STATEMENT_TEMPLATE,
            // صف قفل نطاق تصدير المندوبين (lockForUpdate يمنع تسابق نقرتين على التسلسل م-{n})
            'finance_rep_export_lock' => 0,
        ] as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert(['key' => $key, 'value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['finance_rep_statement_template', 'finance_rep_export_lock'])->delete();

        Schema::table('finance_export_rows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rep_user_id');
        });
        Schema::table('finance_exports', function (Blueprint $table) {
            $table->dropUnique(['scope_key', 'sequence']);
            $table->unique(['distribution_sheet_id', 'sequence']);
        });
        Schema::table('finance_exports', function (Blueprint $table) {
            $table->dropIndex('finance_exports_sheet_index');
            $table->dropColumn(['kind', 'scope_key', 'meta']);
        });
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropIndex(['invoice_id', 'source']);
            $table->dropConstrainedForeignId('payment_id');
            $table->dropColumn('source');
        });
        Schema::dropIfExists('payment_images');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['entered_by', 'book_receipt_no']);
            $table->dropIndex(['source', 'status']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['book_receipt_no', 'duplicate_book_no', 'exceeds_known_debt', 'cost_center_code', 'cost_center_label', 'review_status', 'review_note', 'reviewed_at']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['cost_center_code']);
            $table->dropColumn(['cost_center_code', 'cost_center_label']);
        });
    }
};
