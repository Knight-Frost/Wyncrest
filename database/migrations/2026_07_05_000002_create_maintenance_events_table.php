<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * maintenance_events — an append-only, tenant-visible timeline of what has
     * happened to a maintenance request (submitted, acknowledged, assigned,
     * in progress, waiting, resolved, closed, reopened). Distinct from
     * audit_logs (admin-only, privileged), mirroring application_events.
     *
     * Append-only: there is no updated_at (see MaintenanceEvent::UPDATED_AT).
     */
    public function up(): void
    {
        Schema::create('maintenance_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('maintenance_request_id')
                ->constrained('maintenance_requests')
                ->cascadeOnDelete();

            // Who caused the event (tenant/landlord User, or null=system).
            $table->nullableMorphs('actor');

            // Machine key, e.g. 'submitted', 'assigned', 'resolved'.
            $table->string('event');

            // Human-readable line for the timeline.
            $table->string('description');

            $table->json('meta')->nullable();

            // Append-only: created_at only.
            $table->timestamp('created_at')->nullable();

            $table->index(['maintenance_request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_events');
    }
};
