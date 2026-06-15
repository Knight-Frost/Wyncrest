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
            $table->timestamp('delivered_at')->nullable()->after('read_at');
            $table->timestamp('delivery_failed_at')->nullable()->after('delivered_at');
            $table->text('delivery_error')->nullable()->after('delivery_failed_at');

            // Indexes for delivery queries
            $table->index('delivered_at');
            $table->index('delivery_failed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['delivered_at']);
            $table->dropIndex(['delivery_failed_at']);
            $table->dropColumn(['delivered_at', 'delivery_failed_at', 'delivery_error']);
        });
    }
};
