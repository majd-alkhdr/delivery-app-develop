<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // من ثبّت الموقع المرجعي ومتى وبأي دقة ومن أي مصدر (distributor | supervisor | auto)
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'location_pinned_by')) {
                $table->foreignId('location_pinned_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('customers', 'location_pinned_at')) {
                $table->timestamp('location_pinned_at')->nullable();
            }
            if (! Schema::hasColumn('customers', 'location_accuracy_m')) {
                $table->unsignedInteger('location_accuracy_m')->nullable();
            }
            if (! Schema::hasColumn('customers', 'location_source')) {
                $table->string('location_source', 20)->nullable();
            }
        });

        // المراجع غير المؤكدة الموجودة حُفظت تلقائياً من أول زيارة (المرحلة 3)
        DB::table('customers')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->where('location_confirmed', false)
            ->whereNull('location_source')
            ->update(['location_source' => 'auto']);

        // المراجع المؤكدة الموجودة لم يكن يثبّتها قبل هذا الإصلاح إلا المشرف
        DB::table('customers')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->where('location_confirmed', true)
            ->whereNull('location_source')
            ->update(['location_source' => 'supervisor']);

        if (! DB::table('settings')->where('key', 'location_pin_max_accuracy_m')->exists()) {
            DB::table('settings')->insert([
                'key' => 'location_pin_max_accuracy_m',
                'value' => json_encode(50),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'location_pinned_by')) {
                $table->dropConstrainedForeignId('location_pinned_by');
            }
            $columns = array_values(array_filter(['location_pinned_at', 'location_accuracy_m', 'location_source'], fn ($c) => Schema::hasColumn('customers', $c)));
            if ($columns) {
                $table->dropColumn($columns);
            }
        });
        DB::table('settings')->where('key', 'location_pin_max_accuracy_m')->delete();
    }
};
