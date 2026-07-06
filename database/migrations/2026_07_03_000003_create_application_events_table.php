<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * application_events — an append-only, tenant-visible timeline of what has
     * happened to an application (started, submitted, opened, documents
     * uploaded, info requested/resolved, decided, withdrawn). Distinct from
     * audit_logs (admin-only, privileged) so the tenant can see honest history
     * without exposing the internal audit trail.
     *
     * Append-only: there is no updated_at (see ApplicationEvent::UPDATED_AT).
     */
    public function up(): void
    {
        Schema::create('application_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('application_id')
                ->constrained('applications')
                ->cascadeOnDelete();

            // Who caused the event (tenant/landlord User, Admin, or null=system).
            $table->nullableMorphs('actor');

            // Machine key, e.g. 'submitted', 'info_requested', 'documents_uploaded'.
            $table->string('event');

            // Human-readable line for the timeline.
            $table->string('description');

            $table->json('meta')->nullable();

            // Append-only: created_at only.
            $table->timestamp('created_at')->nullable();

            $table->index(['application_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_events');
    }
};
