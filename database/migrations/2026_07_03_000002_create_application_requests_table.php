<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * application_requests — a landlord (or platform admin) asking the tenant for
     * something on a specific application: a document replacement or more
     * information. Creating an open request moves the application into
     * NEEDS_ACTION; resolving it (tenant uploads / responds) clears it.
     */
    public function up(): void
    {
        Schema::create('application_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('application_id')
                ->constrained('applications')
                ->cascadeOnDelete();

            // Who raised the request (landlord User or Admin). Nullable + polymorphic
            // so platform/system requests are representable.
            $table->nullableMorphs('requested_by');

            // 'landlord' | 'admin' | 'platform' — surfaced to the tenant as the "who".
            $table->string('requester_role')->default('landlord');

            // 'document_replacement' | 'more_info' | 'general'
            $table->string('type')->default('more_info');

            // Which document requirement this concerns (DocumentType value), if any.
            $table->string('document_type')->nullable();

            $table->text('message');
            $table->text('reason')->nullable();

            $table->timestamp('due_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            // Common query: open requests for an application.
            $table->index(['application_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_requests');
    }
};
