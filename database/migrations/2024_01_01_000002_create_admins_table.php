<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Admins table - Phase 1 supports Super Admin only.
     * RBAC expansion deferred to Phase 4.
     * Completely separate from users table to enforce role separation.
     */
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('name');

            // Phase 1: All admins are Super Admins
            // Phase 4: Add role/permissions structure
            $table->boolean('is_super_admin')->default(true);

            // Admin account status
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();

            $table->rememberToken();
            $table->timestamps();

            // No soft deletes - admin actions are permanent audit trail

            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};
