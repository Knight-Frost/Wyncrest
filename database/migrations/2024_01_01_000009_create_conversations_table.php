<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Conversations - Phase 1 schema only (no UI or flows).
     * Supports tenant-landlord messaging.
     * Polymorphic to allow future expansion (admin support, etc.).
     */
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            
            // Participants - polymorphic for flexibility
            $table->morphs('participant_one'); // user or admin
            $table->morphs('participant_two'); // user or admin
            
            // Context - what is this conversation about?
            $table->morphs('subject'); // listing, application, lease, maintenance, etc.
            
            // Conversation metadata
            $table->string('title')->nullable();
            $table->enum('status', [
                'active',
                'archived',
                'closed'
            ])->default('active');
            
            // Last activity tracking
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedBigInteger('last_message_by')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes (morphs() already creates indexes for participant_one, participant_two, and subject)
            $table->index(['status', 'last_message_at']);
            $table->index('last_message_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};