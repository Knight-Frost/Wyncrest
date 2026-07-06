<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the "request changes" moderation outcome to listings.
 *
 * Distinct from a rejection: when an admin sends a listing back for changes it
 * returns to DRAFT (no rejection stigma, does not count against the landlord),
 * and we persist the admin's message so the landlord can see exactly what to fix
 * when they reopen the draft. Cleared automatically on resubmission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->text('changes_requested_reason')->nullable()->after('rejection_reason');
            $table->timestamp('changes_requested_at')->nullable()->after('changes_requested_reason');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['changes_requested_reason', 'changes_requested_at']);
        });
    }
};
