<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Enforce, at the database level, that a given Stripe payment_intent can
     * back at most one PAYMENT ledger entry (payment idempotency). The index
     * is PARTIAL (type = 'payment') because obligation entries (rent/late_fee)
     * may legitimately carry the same intent id for reference/reconciliation.
     *
     * why: MySQL has no partial/filtered index support, so on MySQL this
     * invariant is enforced only at the application layer (LedgerService's
     * transaction + row lock around payment recording). SQLite and Postgres
     * both support partial unique indexes, so we add the real DB-level
     * guarantee there.
     */
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX ledger_entries_payment_intent_unique '.
                'ON ledger_entries (stripe_payment_intent_id) '.
                "WHERE type = 'payment' AND stripe_payment_intent_id IS NOT NULL"
            );
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement('DROP INDEX IF EXISTS ledger_entries_payment_intent_unique');
        }
    }
};
