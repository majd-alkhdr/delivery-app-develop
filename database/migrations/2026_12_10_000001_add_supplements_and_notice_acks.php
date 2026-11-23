<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 7H:
 * - كشف «ملحق» لأمر كشفه مكتمل: parent_sheet_id وsupplement_no على distribution_sheets
 *   (لا قيد تفرّد على (أمر التسليم + تاريخ التوزيع) أصلاً، فلا يُعدَّل قيد).
 * - وسم «أُضيفت بعد الإرسال» على الفاتورة: added_after_send_at وadded_after_send_batch_id.
 * - الاطلاع الإلزامي لكل إشعار (من، متى): sheet_notice_acks. جدول sheet_user_seen يبقى كما هو لشريط التعديلات.
 * - تسجيل دون اتصال قبل الاطلاع: recorded_before_ack وack_context على payments وsheet_visits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribution_sheets', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_sheet_id')->nullable()->index();
            $table->unsignedSmallInteger('supplement_no')->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('added_after_send_at')->nullable();
            $table->uuid('added_after_send_batch_id')->nullable();
        });

        Schema::create('sheet_notice_acks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('notice_id');
            $table->unsignedBigInteger('distribution_sheet_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('acked_at')->nullable();

            $table->unique(['notice_id', 'user_id']);
            $table->index(['distribution_sheet_id', 'user_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->boolean('recorded_before_ack')->default(false);
            $table->json('ack_context')->nullable();
        });
        Schema::table('sheet_visits', function (Blueprint $table) {
            $table->boolean('recorded_before_ack')->default(false);
            $table->json('ack_context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sheet_visits', fn (Blueprint $table) => $table->dropColumn(['recorded_before_ack', 'ack_context']));
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn(['recorded_before_ack', 'ack_context']));
        Schema::dropIfExists('sheet_notice_acks');
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['added_after_send_at', 'added_after_send_batch_id']));
        Schema::table('distribution_sheets', function (Blueprint $table) {
            $table->dropIndex(['parent_sheet_id']);
            $table->dropColumn(['parent_sheet_id', 'supplement_no']);
        });
    }
};
