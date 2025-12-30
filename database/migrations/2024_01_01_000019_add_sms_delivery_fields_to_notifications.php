<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->timestamp('sms_delivered_at')->nullable()->after('delivery_error');
            $table->timestamp('sms_failed_at')->nullable()->after('sms_delivered_at');
            $table->text('sms_error')->nullable()->after('sms_failed_at');
            
            // Indexes for SMS delivery queries
            $table->index('sms_delivered_at');
            $table->index('sms_failed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['sms_delivered_at']);
            $table->dropIndex(['sms_failed_at']);
            $table->dropColumn(['sms_delivered_at', 'sms_failed_at', 'sms_error']);
        });
    }
};
