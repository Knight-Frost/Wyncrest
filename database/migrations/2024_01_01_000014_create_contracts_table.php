<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Relationships - using foreignId to match integer PKs in referenced tables
            $table->foreignId('listing_id')->unique()->constrained('listings')->onDelete('cascade');
            $table->foreignId('landlord_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('tenant_id')->constrained('users')->onDelete('cascade');

            // Financial terms
            $table->bigInteger('rent_amount'); // Stored in cents
            $table->char('currency', 3)->default('USD');
            $table->enum('billing_cycle', ['monthly'])->default('monthly');
            $table->unsignedTinyInteger('payment_day'); // 1-28

            // Contract period
            $table->date('start_date');
            $table->date('end_date')->nullable();

            // Status and termination
            $table->enum('status', ['draft', 'pending_tenant', 'active', 'terminated', 'expired'])
                ->default('draft');
            $table->enum('terminated_by', ['landlord', 'tenant', 'admin'])->nullable();
            $table->text('termination_reason')->nullable();

            // Admin tracking - using foreignId to match integer PK in admins table
            $table->foreignId('admin_id')->nullable()->constrained('admins')->onDelete('set null');

            $table->timestamps();

            // Indexes
            $table->index('landlord_id');
            $table->index('tenant_id');
            $table->index('status');
            $table->index('start_date');
            $table->index('end_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
