<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // مجموعة التقسيم (لمحرك المرحلة 5؛ لا منطق عليها الآن)
        Schema::table('invoice_series', function (Blueprint $table) {
            $table->enum('allocation_group', ['milk', 'argivit', 'other'])->default('other');
        });

        DB::table('invoice_series')->where('key', 'm')->update(['allocation_group' => 'milk']);
        DB::table('invoice_series')->where('key', 'ارجيفيت')->update(['allocation_group' => 'argivit']);
    }

    public function down(): void
    {
        Schema::table('invoice_series', function (Blueprint $table) {
            $table->dropColumn('allocation_group');
        });
    }
};
