<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Listings are public-facing representations of units.
     * One unit can have one active listing.
     * Listings are moderatable by admins.
     */
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            
            // Listing content
            $table->string('title');
            $table->text('description');
            
            // Listing status
            $table->enum('status', [
                'draft',
                'pending_review',
                'active',
                'inactive',
                'rejected',
                'archived'
            ])->default('draft');
            
            // Moderation
            $table->foreignId('reviewed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            
            // Publishing
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            
            // Search optimization
            $table->boolean('featured')->default(false);
            $table->integer('view_count')->default(0);
            
            // Pet policy
            $table->boolean('pets_allowed')->default(false);
            $table->text('pet_policy')->nullable();
            
            // Lease terms
            $table->integer('lease_duration_months')->nullable();
            $table->date('move_in_date')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['status', 'published_at']);
            $table->index(['landlord_id', 'status']);
            $table->index('unit_id');
            $table->index('featured');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
