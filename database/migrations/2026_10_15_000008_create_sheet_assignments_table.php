<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheet_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sheet_id')->constrained('distribution_sheets')->cascadeOnDelete();
            $table->foreignId('distributor_id')->constrained('users');
            // null = كل المناطق، '__none__' = الزبائن بلا منطقة.
            // MySQL يسمح بعدة NULL في الفهرس الفريد، لذلك يُفرض التحقق تطبيقياً أيضاً (SheetAssignmentService).
            $areaKey = $table->string('area_key', 255)->nullable();
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $areaKey->collation('utf8mb4_bin');
            }
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamps();

            $table->unique(['sheet_id', 'area_key']);
            $table->index('distributor_id');
        });

        // ترحيل البيانات الحالية: كل كشف موجود يُسند لموزعه الحالي على كل المناطق
        $now = now();
        DB::table('distribution_sheets')
            ->whereNotNull('distributor_id')
            ->orderBy('id')
            ->select('id', 'distributor_id', 'supervisor_id')
            ->chunk(500, function ($sheets) use ($now) {
                $rows = [];
                foreach ($sheets as $sheet) {
                    $rows[] = [
                        'sheet_id' => $sheet->id,
                        'distributor_id' => $sheet->distributor_id,
                        'area_key' => null,
                        'assigned_by' => $sheet->supervisor_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('sheet_assignments')->insert($rows);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheet_assignments');
    }
};
