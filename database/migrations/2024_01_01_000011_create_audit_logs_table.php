<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Audit logs are immutable records of all critical actions.
     * Required for compliance and governance.
     * Admin actions MUST always be logged.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Actor - who performed the action (nullable for system-generated actions)
            $table->nullableMorphs('actor'); // user, admin, or system (null for system)

            // Subject - what was acted upon
            $table->nullableMorphs('subject'); // listing, user, property, etc.

            // Action details
            $table->string('action'); // e.g., 'created', 'updated', 'deleted', 'approved', 'rejected'
            $table->text('description')->nullable(); // human-readable description

            // Context
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            // Changes (for update actions)
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            // Metadata
            $table->json('metadata')->nullable(); // additional context

            // Severity for filtering
            $table->enum('severity', [
                'info',
                'warning',
                'critical',
            ])->default('info');

            $table->timestamp('created_at'); // Immutable - no updated_at

            // Indexes (nullableMorphs() already creates indexes for actor and subject)
            $table->index(['action', 'created_at']);
            $table->index('severity');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Audit logs should never be dropped in production
        // This is here for development only
        Schema::dropIfExists('audit_logs');
    }
};
