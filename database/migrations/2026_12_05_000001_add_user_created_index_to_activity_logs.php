<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 8C: سجل النشاط يُحصر بمنفّذ الإجراء ويُرتَّب بالأحدث ⇒ فهرس مركّب (user_id, created_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'activity_logs_user_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('activity_logs_user_created_index');
        });
    }
};
