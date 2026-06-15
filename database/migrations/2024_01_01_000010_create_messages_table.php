<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Messages belong to conversations.
     * Phase 1: Schema only (no sending endpoints or UI).
     * Supports read receipts and attachments for Phase 2.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();

            // Sender - polymorphic to support users and admins
            $table->morphs('sender');

            // Content
            $table->text('body');

            // Metadata
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();

            // System messages (automated notifications)
            $table->boolean('is_system_message')->default(false);

            // Attachments (Phase 2)
            $table->boolean('has_attachments')->default(false);

            $table->timestamps();
            $table->softDeletes();

            // Indexes (morphs() already creates index for sender_type + sender_id)
            $table->index(['conversation_id', 'created_at']);
            $table->index('is_read');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
