<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A landlord's shortlist is an internal organisational flag, independent of
     * the application's lifecycle status — a submitted or needs_action
     * application can be shortlisted while still awaiting a decision. Storing it
     * as a nullable timestamp (rather than a boolean) records *when* it happened
     * for free, matching the pattern used by decided_at/withdrawn_at/etc.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->timestamp('shortlisted_at')->nullable()->after('landlord_notes');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('shortlisted_at');
        });
    }
};
