<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add Stripe payment intent ID to ledger entries for idempotency
     * AND update the type enum to include PAYMENT and REFUND
     */
    public function up(): void
    {
        // Add stripe_payment_intent_id column
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->string('stripe_payment_intent_id')->nullable()->after('related_rent_entry_id');
            $table->index('stripe_payment_intent_id');
        });

        // Update the type enum to include payment and refund
        // This is SQLite-specific - recreate the table with new enum values
        DB::statement("
            CREATE TABLE ledger_entries_new (
                id TEXT PRIMARY KEY,
                contract_id TEXT NOT NULL,
                tenant_id INTEGER NOT NULL,
                landlord_id INTEGER NOT NULL,
                type TEXT NOT NULL CHECK(type IN ('rent', 'late_fee', 'payment', 'refund')),
                amount_cents BIGINT NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT 'USD',
                billing_period_start DATE,
                billing_period_end DATE,
                due_date DATE NOT NULL,
                status TEXT NOT NULL CHECK(status IN ('pending', 'paid', 'overdue', 'waived')),
                related_rent_entry_id TEXT,
                stripe_payment_intent_id TEXT,
                created_at TIMESTAMP NOT NULL,
                FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
                FOREIGN KEY (tenant_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (landlord_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (related_rent_entry_id) REFERENCES ledger_entries(id) ON DELETE SET NULL
            )
        ");

        // Copy existing data
        DB::statement('
            INSERT INTO ledger_entries_new 
            SELECT id, contract_id, tenant_id, landlord_id, type, amount_cents, currency,
                   billing_period_start, billing_period_end, due_date, status, 
                   related_rent_entry_id, NULL as stripe_payment_intent_id, created_at
            FROM ledger_entries
        ');

        // Drop old table and rename new one
        DB::statement('DROP TABLE ledger_entries');
        DB::statement('ALTER TABLE ledger_entries_new RENAME TO ledger_entries');

        // Recreate indexes
        DB::statement('CREATE INDEX ledger_entries_contract_id_index ON ledger_entries(contract_id)');
        DB::statement('CREATE INDEX ledger_entries_tenant_id_index ON ledger_entries(tenant_id)');
        DB::statement('CREATE INDEX ledger_entries_landlord_id_index ON ledger_entries(landlord_id)');
        DB::statement('CREATE INDEX ledger_entries_type_index ON ledger_entries(type)');
        DB::statement('CREATE INDEX ledger_entries_status_index ON ledger_entries(status)');
        DB::statement('CREATE INDEX ledger_entries_due_date_index ON ledger_entries(due_date)');
        DB::statement('CREATE INDEX ledger_entries_related_rent_entry_id_index ON ledger_entries(related_rent_entry_id)');
        DB::statement('CREATE INDEX ledger_entries_stripe_payment_intent_id_index ON ledger_entries(stripe_payment_intent_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate table without payment/refund types and stripe column
        DB::statement("
            CREATE TABLE ledger_entries_old (
                id TEXT PRIMARY KEY,
                contract_id TEXT NOT NULL,
                tenant_id INTEGER NOT NULL,
                landlord_id INTEGER NOT NULL,
                type TEXT NOT NULL CHECK(type IN ('rent', 'late_fee')),
                amount_cents BIGINT NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT 'USD',
                billing_period_start DATE,
                billing_period_end DATE,
                due_date DATE NOT NULL,
                status TEXT NOT NULL CHECK(status IN ('pending', 'paid', 'overdue', 'waived')),
                related_rent_entry_id TEXT,
                created_at TIMESTAMP NOT NULL,
                FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
                FOREIGN KEY (tenant_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (landlord_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (related_rent_entry_id) REFERENCES ledger_entries(id) ON DELETE SET NULL
            )
        ");

        // Copy data back (excluding payment/refund entries)
        DB::statement("
            INSERT INTO ledger_entries_old 
            SELECT id, contract_id, tenant_id, landlord_id, type, amount_cents, currency,
                   billing_period_start, billing_period_end, due_date, status, 
                   related_rent_entry_id, created_at
            FROM ledger_entries
            WHERE type IN ('rent', 'late_fee')
        ");

        // Drop current table and rename old one
        DB::statement('DROP TABLE ledger_entries');
        DB::statement('ALTER TABLE ledger_entries_old RENAME TO ledger_entries');

        // Recreate indexes
        DB::statement('CREATE INDEX ledger_entries_contract_id_index ON ledger_entries(contract_id)');
        DB::statement('CREATE INDEX ledger_entries_tenant_id_index ON ledger_entries(tenant_id)');
        DB::statement('CREATE INDEX ledger_entries_landlord_id_index ON ledger_entries(landlord_id)');
        DB::statement('CREATE INDEX ledger_entries_type_index ON ledger_entries(type)');
        DB::statement('CREATE INDEX ledger_entries_status_index ON ledger_entries(status)');
        DB::statement('CREATE INDEX ledger_entries_due_date_index ON ledger_entries(due_date)');
        DB::statement('CREATE INDEX ledger_entries_related_rent_entry_id_index ON ledger_entries(related_rent_entry_id)');
    }
};
