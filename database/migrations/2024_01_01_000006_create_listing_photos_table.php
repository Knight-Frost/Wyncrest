<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Photos belong to listings.
     * Stored paths are abstracted for S3 migration later.
     */
    public function up(): void
    {
        Schema::create('listing_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();

            // Storage
            $table->string('path'); // storage path
            $table->string('disk')->default('local'); // local or s3

            // Metadata
            $table->string('filename');
            $table->string('mime_type');
            $table->integer('file_size'); // bytes
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();

            // Organization
            $table->integer('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);

            // Alt text for accessibility
            $table->string('alt_text')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['listing_id', 'sort_order']);
            $table->index(['listing_id', 'is_primary']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listing_photos');
    }
};
