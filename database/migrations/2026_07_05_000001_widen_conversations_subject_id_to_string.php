<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * conversations.subject_id was created via morphs() as unsignedBigInteger,
     * but `subject` is also polymorphed onto Contract, whose primary key is a
     * UUID string. On SQLite this "worked" only by type affinity (SQLite has
     * none); on MySQL/Postgres a UUID could never be stored in a bigint column.
     * Widen it to string(36) so the morph pair works on every supported driver.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // Preserves the ['subject_type', 'subject_id'] index morphs() created.
            $table->string('subject_id', 36)->change();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('subject_id')->change();
        });
    }
};
