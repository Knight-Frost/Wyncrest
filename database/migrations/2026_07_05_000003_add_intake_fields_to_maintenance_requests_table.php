<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extends maintenance_requests with the tenant "repair report" intake fields:
     * WHERE the issue is (area + a free-text spot), WHEN it started, structured
     * safety/damage flags, and the tenant's access + contact preferences. These
     * turn a bare note into something a landlord can triage and schedule without
     * playing detective.
     *
     * All nullable: landlord-authored requests (StoreLandlordMaintenanceRequest)
     * and historical rows do not carry intake fields, and only the tenant intake
     * form requires the core three (area / onset / access_permission).
     */
    public function up(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->string('area')->nullable()->after('description');
            $table->string('specific_location')->nullable()->after('area');
            $table->string('onset')->nullable()->after('specific_location');
            $table->json('safety_flags')->nullable()->after('onset');
            $table->string('access_permission')->nullable()->after('safety_flags');
            $table->string('preferred_visit_window')->nullable()->after('access_permission');
            $table->string('preferred_contact_method')->nullable()->after('preferred_visit_window');
            $table->text('access_instructions')->nullable()->after('preferred_contact_method');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->dropColumn([
                'area',
                'specific_location',
                'onset',
                'safety_flags',
                'access_permission',
                'preferred_visit_window',
                'preferred_contact_method',
                'access_instructions',
            ]);
        });
    }
};
