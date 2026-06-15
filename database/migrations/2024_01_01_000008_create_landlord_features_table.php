<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Landlord features - tracks which features are enabled per landlord.
     * This is queryable, auditable, and enforceable.
     * Backend services check this table before allowing actions.
     */
    public function up(): void
    {
        Schema::create('landlord_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();

            // Feature state
            $table->boolean('enabled')->default(true);

            // Audit trail
            $table->foreignId('enabled_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('enabled_at');
            $table->foreignId('disabled_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('disabled_at')->nullable();

            // Notes for admin tracking
            $table->text('notes')->nullable();

            $table->timestamps();

            // Indexes
            $table->unique(['landlord_id', 'feature_id']);
            $table->index(['landlord_id', 'enabled']);
            $table->index('feature_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('landlord_features');
    }
};
