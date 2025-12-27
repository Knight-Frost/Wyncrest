<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Email logs track all emails sent by the system.
     * Required for audit trail and debugging.
     * All emails are triggered by events, never manually in controllers.
     */
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            
            // Recipient
            $table->morphs('recipient'); // user or admin
            $table->string('recipient_email');
            
            // Email details
            $table->string('subject');
            $table->string('mailable_class'); // Laravel mailable class name
            $table->enum('email_type', [
                'account',
                'verification',
                'notification',
                'transaction',
                'security',
                'system'
            ]);
            
            // Related entity (what triggered this email)
            $table->morphs('related'); // listing, application, lease, etc.
            
            // Delivery status
            $table->enum('status', [
                'queued',
                'sent',
                'failed',
                'bounced'
            ])->default('queued');
            
            $table->timestamp('sent_at')->nullable();
            $table->text('error_message')->nullable();
            
            // Tracking (for future)
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            
            $table->timestamps();
            
            // Indexes (morphs() already creates indexes for recipient and related)
            $table->index(['email_type', 'status']);
            $table->index('sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};