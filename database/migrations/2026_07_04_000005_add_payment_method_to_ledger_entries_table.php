<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds landlord manual-payment metadata to ledger_entries. Left null for
     * every existing/Stripe-originated PAYMENT row (PaymentService is
     * untouched); only populated by LedgerService::recordManualPayment().
     */
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->string('payment_method')->nullable()->after('stripe_payment_intent_id');
            $table->string('payment_reference')->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'payment_reference']);
        });
    }
};
