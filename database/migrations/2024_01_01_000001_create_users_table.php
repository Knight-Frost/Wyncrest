<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Users table supports both tenants and landlords.
     * Role is strictly enforced via user_type enum.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->enum('user_type', ['tenant', 'landlord'])->index();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone')->nullable();
            $table->date('date_of_birth')->nullable();
            
            // Identity verification (required for landlords before certain features)
            $table->boolean('identity_verified')->default(false);
            $table->timestamp('identity_verified_at')->nullable();
            $table->string('identity_verified_by')->nullable(); // admin email or system
            
            // Account status
            $table->boolean('is_active')->default(true);
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
            
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes(); // Soft delete for audit trail
            
            // Indexes
            $table->index(['user_type', 'is_active']);
            $table->index('email_verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
