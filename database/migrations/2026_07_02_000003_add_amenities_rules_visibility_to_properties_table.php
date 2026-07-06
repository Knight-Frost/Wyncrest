<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds structured building-level amenities/rules and an address privacy
     * setting to properties, and widens property_type from a fixed DB enum
     * (CHECK constraint) to a plain string so the PropertyType PHP enum can
     * grow without a destructive schema migration every time.
     */
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('property_type', 30)->change();
            $table->json('amenities')->nullable()->after('description');
            $table->json('rules')->nullable()->after('amenities');
            $table->string('address_visibility', 20)->default('area_only')->after('country');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['amenities', 'rules', 'address_visibility']);
            $table->enum('property_type', [
                'single_family',
                'multi_family',
                'apartment',
                'condo',
                'townhouse',
                'commercial',
                'other',
            ])->change();
        });
    }
};
