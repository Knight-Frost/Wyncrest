<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Features table defines all gateable features in the system.
     * This is the master list of features that can be enabled/disabled per landlord.
     */
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // e.g., 'applications', 'payments', 'maintenance'
            $table->string('name'); // Human-readable name
            $table->text('description')->nullable();

            // Feature requirements
            $table->json('requires_features')->nullable(); // e.g., ["identity_verification"]
            $table->boolean('requires_identity_verification')->default(false);

            // Feature configuration
            $table->boolean('enabled_by_default')->default(false);
            $table->boolean('is_available')->default(true); // System-wide availability

            $table->timestamps();

            $table->index('is_available');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
