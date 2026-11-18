<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * CRITICAL: Ledger entries are IMMUTABLE.
     * No updates or deletes allowed.
     * Corrections require compensating entries.
     */
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Relationships - contract uses UUID, users use integer IDs
            $table->foreignUuid('contract_id')->constrained('contracts')->onDelete('cascade');
            $table->foreignId('tenant_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('landlord_id')->constrained('users')->onDelete('cascade');

            // Entry details - includes payment type for recording payments
            $table->enum('type', ['rent', 'late_fee', 'payment', 'refund']);
            // Signed accounting value, in cents: rent/late_fee/refund are positive
            // (increase balance), payment is negative (reduces balance). See
            // App\Services\Ledger\LedgerComputationEngine for the canonical
            // sign convention and all derived display/balance math.
            $table->bigInteger('amount_cents');
            $table->char('currency', 3)->default('USD');

            // Billing period (for rent entries)
            $table->date('billing_period_start');
            $table->date('billing_period_end');
            $table->date('due_date');

            // Payment status
            $table->enum('status', ['pending', 'paid', 'overdue', 'waived'])->default('pending');

            // Late fee reference (nullable - only for late_fee entries)
            $relatedRentEntry = $table->foreignUuid('related_rent_entry_id')->nullable();

            // why (forensic audit WYN-009): on Postgres/MySQL Laravel emits this
            // self-referencing FK before the primary key exists, so a fresh
            // install failed. SQLite keeps the original inline definition
            // (byte-identical schema); other engines add it after creation.
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $relatedRentEntry->constrained('ledger_entries')->onDelete('set null');
            }

            // Audit trail
            $table->timestamp('created_at')->useCurrent();

            // Indexes for queries
            $table->index('contract_id');
            $table->index('tenant_id');
            $table->index('landlord_id');
            $table->index('type');
            $table->index('status');
            $table->index('due_date');
            $table->index('billing_period_start');
            $table->index('created_at');
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('ledger_entries', function (Blueprint $table) {
                $table->foreign('related_rent_entry_id')->references('id')->on('ledger_entries')->onDelete('set null');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
