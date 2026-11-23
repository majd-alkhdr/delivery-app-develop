<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('alias', 255);
            $normalized = $table->string('alias_normalized', 255)->unique();
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $normalized->collation('utf8mb4_bin');
            }
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_aliases');
    }
};
