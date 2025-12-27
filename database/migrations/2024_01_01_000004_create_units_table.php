<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Units belong to properties.
     * Units are the actual rentable space.
     * Units can have listings.
     */
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            
            // Unit identification
            $table->string('unit_number')->nullable(); // e.g., "101", "A", null for single-family
            $table->string('internal_name')->nullable(); // landlord's internal reference
            
            // Unit specifications
            $table->decimal('bedrooms', 3, 1); // supports 1.5, 2.5, etc.
            $table->decimal('bathrooms', 3, 1);
            $table->integer('square_feet')->nullable();
            
            // Pricing
            $table->decimal('rent_amount', 10, 2);
            $table->decimal('security_deposit', 10, 2)->nullable();
            
            // Availability
            $table->enum('availability_status', [
                'available',
                'occupied',
                'pending',
                'maintenance',
                'unlisted'
            ])->default('unlisted');
            $table->date('available_from')->nullable();
            
            // Features (JSON for flexibility)
            $table->json('amenities')->nullable(); // ["parking", "balcony", "washer_dryer"]
            
            // Status
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['property_id', 'is_active']);
            $table->index('availability_status');
            $table->index(['rent_amount', 'availability_status']);
            
            // Unique constraint for unit numbers within property
            $table->unique(['property_id', 'unit_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
