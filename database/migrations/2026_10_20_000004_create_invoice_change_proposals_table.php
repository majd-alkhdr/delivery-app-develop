<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_change_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->uuid('batch_id')->nullable()->index();
            $table->json('payload');
            $table->string('old_hash', 64)->nullable();
            $table->string('new_hash', 64)->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_change_proposals');
    }
};
