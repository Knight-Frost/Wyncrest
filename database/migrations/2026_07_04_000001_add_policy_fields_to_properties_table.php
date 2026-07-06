<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds free-text policy fields to properties.
 *
 * The property already carries structured `rules` (pets_allowed/smoking_allowed
 * booleans) and `amenities` (including parking categories), but the landlord
 * Property page surfaces human-readable policy prose — e.g. "1 covered space
 * per unit", "Cats and small dogs allowed", "No smoking indoors". These three
 * nullable strings hold that prose so the Overview reads like the real world,
 * not a checkbox dump.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('parking')->nullable()->after('description');
            $table->string('pet_policy')->nullable()->after('parking');
            $table->string('smoking_policy')->nullable()->after('pet_policy');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['parking', 'pet_policy', 'smoking_policy']);
        });
    }
};
