<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('verification_request_id')->constrained('verification_requests')->cascadeOnDelete();
            // Author is always an admin — notes are internal case-review context,
            // never surfaced to the tenant or landlord.
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            // Newest-first lookups per verification request.
            $table->index(['verification_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_notes');
    }
};
