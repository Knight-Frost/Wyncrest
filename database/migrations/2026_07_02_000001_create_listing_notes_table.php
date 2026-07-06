<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            // Author is always an admin — notes are internal moderation context,
            // never surfaced to landlords or tenants.
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            // Newest-first lookups per listing.
            $table->index(['listing_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_notes');
    }
};
