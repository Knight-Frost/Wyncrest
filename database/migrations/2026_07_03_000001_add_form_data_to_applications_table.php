<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a structured form snapshot to applications so the multi-step guided
     * application (personal / employment / rental history / household) can be
     * saved as a draft and submitted. Stored as JSON: the shape is UI-driven and
     * intentionally flexible, and none of it is money-in-cents (income is a
     * self-reported string), so a document column is the right fit.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->json('form_data')->nullable()->after('cover_note');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('form_data');
        });
    }
};
