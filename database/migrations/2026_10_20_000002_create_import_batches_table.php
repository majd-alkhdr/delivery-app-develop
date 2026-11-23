<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            // المعرّف هو نفسه distribution_sheets.batch_id
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->date('distribution_date')->nullable()->index();
            $table->json('file_names')->nullable();
            $table->json('stats')->nullable();
            $table->json('warnings')->nullable();
            $table->unsignedBigInteger('conflicts_acknowledged_by')->nullable();
            $table->timestamp('conflicts_acknowledged_at')->nullable();
            $table->timestamps();
        });

        // ترحيل: دفعات المرحلة 2 الموجودة (batch_id على الكشوف) تحصل على سجل دفعة حتى تُفتح معاينتها
        $batches = DB::table('distribution_sheets')
            ->whereNotNull('batch_id')
            ->select('batch_id', DB::raw('MIN(supervisor_id) as user_id'), DB::raw('MIN(distribution_date) as distribution_date'), DB::raw('MIN(created_at) as created_at'))
            ->groupBy('batch_id')
            ->get();

        foreach ($batches as $batch) {
            $names = DB::table('distribution_sheets')->where('batch_id', $batch->batch_id)->pluck('original_name')->filter()->unique()->values()->all();
            DB::table('import_batches')->insert([
                'id' => $batch->batch_id,
                'user_id' => $batch->user_id,
                'distribution_date' => $batch->distribution_date,
                'file_names' => json_encode($names, JSON_UNESCAPED_UNICODE),
                'stats' => json_encode(['legacy' => true]),
                'warnings' => json_encode([]),
                'created_at' => $batch->created_at,
                'updated_at' => $batch->created_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
