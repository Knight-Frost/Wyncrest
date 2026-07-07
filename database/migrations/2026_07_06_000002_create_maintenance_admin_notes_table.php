<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_admin_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_request_id')->constrained('maintenance_requests')->cascadeOnDelete();
            // Author is always an admin — notes are internal oversight
            // context, never surfaced to the tenant or landlord.
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            // Newest-first lookups per case.
            $table->index(['maintenance_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_admin_notes');
    }
};
