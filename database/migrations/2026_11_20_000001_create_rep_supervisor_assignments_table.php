<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 8A: إسناد المندوبين لمشرفي المندوبين (rep_supervisor) بنطاق: كل صيدلياته، أو منطقة، أو كتلة.
 * الحذف = إنهاء (ended_at)، والإعداد rep_supervisor_scoping مُطفأ افتراضياً (السلوك السابق قائم حتى يفعّله الأدمن).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rep_supervisor_assignments')) {
            Schema::create('rep_supervisor_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('rep_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('supervisor_user_id')->constrained('users')->cascadeOnDelete();
                // all | area | block
                $table->string('scope_type', 10);
                // القيمة المطبَّعة (ArabicNormalizer)؛ null لـ all
                $table->string('scope_value', 255)->nullable();
                // النص الأصلي للعرض
                $table->string('scope_label', 255)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('ended_at')->nullable();
                $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['supervisor_user_id', 'ended_at']);
                $table->index(['rep_user_id', 'ended_at']);
            });
        }

        if (Schema::hasTable('settings') && ! DB::table('settings')->where('key', 'rep_supervisor_scoping')->exists()) {
            DB::table('settings')->insert([
                'key' => 'rep_supervisor_scoping',
                'value' => json_encode(false),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rep_supervisor_assignments');
        if (Schema::hasTable('settings')) {
            DB::table('settings')->where('key', 'rep_supervisor_scoping')->delete();
        }
    }
};
