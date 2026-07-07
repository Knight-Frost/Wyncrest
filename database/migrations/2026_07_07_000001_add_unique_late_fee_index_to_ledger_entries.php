<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Enforce, at the database level, that a given rent entry can back at most
     * one LATE_FEE ledger entry (defense in depth on top of the transaction +
     * row lock in LedgerService::generateLateFee()). The index is PARTIAL
     * (type = 'late_fee') because other entry types may legitimately share a
     * related_rent_entry_id value in the future.
     *
     * why: MySQL has no partial/filtered index support, so on MySQL this
     * invariant is enforced only at the application layer. SQLite and
     * Postgres both support partial unique indexes, so we add the real
     * DB-level guarantee there.
     */
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX ledger_entries_unique_late_fee '.
                'ON ledger_entries (related_rent_entry_id) '.
                "WHERE type = 'late_fee'"
            );
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement('DROP INDEX IF EXISTS ledger_entries_unique_late_fee');
        }
    }
};
