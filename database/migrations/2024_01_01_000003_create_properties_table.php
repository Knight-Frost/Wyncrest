<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Properties are owned by landlords.
     * Properties contain units.
     * All landlord operations start with properties.
     */
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            
            // Property identification
            $table->string('name'); // e.g., "Sunset Apartments"
            $table->enum('property_type', [
                'single_family',
                'multi_family',
                'apartment',
                'condo',
                'townhouse',
                'commercial',
                'other'
            ]);
            
            // Address - denormalized for simplicity in Phase 1
            $table->string('street_address');
            $table->string('street_address_2')->nullable();
            $table->string('city');
            $table->string('state', 2); // US state codes
            $table->string('zip_code', 10);
            $table->string('country', 2)->default('US');
            
            // Property details
            $table->integer('year_built')->nullable();
            $table->decimal('lot_size', 10, 2)->nullable(); // acres
            $table->text('description')->nullable();
            
            // Management
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['landlord_id', 'is_active']);
            $table->index(['city', 'state']);
            $table->index('zip_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
