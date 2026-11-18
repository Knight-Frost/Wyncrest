<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Enforce, at the database level, that a contract carries at most one LIVE
     * rent charge per billing period (forensic audit WYN-001).
     *
     * Before this index the only duplicate-rent protection was an
     * application-level check-then-insert (LedgerAutomationService::
     * rentExistsForPeriod) and generateFirstRentEntry() had no check at all, so a
     * repeated contract activation or two overlapping scheduler runs could bill
     * the same month twice. The index is PARTIAL:
     *   - type = 'rent'        payments/late fees legitimately share a period;
     *   - status <> 'waived'   a waived duplicate (the documented correction
     *                          mechanism) must not block the live charge, and it
     *                          is what lets an existing deployment that already
     *                          holds a duplicate recover without deleting history.
     *
     * billing_period_start is indexed raw: every writer persists it through the
     * model's `date` cast (always 'Y-m-d 00:00:00' on SQLite, a DATE on Postgres).
     *
     * why: MySQL has no partial index support, so on MySQL this invariant stays
     * application-level only (same precedent as the payment-intent and late-fee
     * indexes). Tracked as an open item in WYNCREST_FORENSIC_AUDIT.md.
     *
     * UPGRADE PREFLIGHT: if live duplicates already exist the migration refuses
     * to run instead of deleting or altering ledger rows. Resolve each reported
     * duplicate by waiving the extra charge (admin ledger "waive", audited), then
     * re-run `php artisan migrate`.
     */
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            return;
        }

        $duplicateGroups = DB::table('ledger_entries')
            ->select('contract_id', 'billing_period_start')
            ->where('type', 'rent')
            ->where('status', '<>', 'waived')
            ->groupBy('contract_id', 'billing_period_start')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicateGroups->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Refusing to add ledger_entries_unique_live_rent_period: %d contract billing period(s) already hold '.
                'more than one live rent charge (contracts: %s). No ledger rows were changed. Waive the extra '.
                'charge(s) through the audited admin ledger action, then re-run the migration.',
                $duplicateGroups->count(),
                $duplicateGroups->pluck('contract_id')->unique()->implode(', ')
            ));
        }

        DB::statement(
            'CREATE UNIQUE INDEX ledger_entries_unique_live_rent_period '.
            'ON ledger_entries (contract_id, billing_period_start) '.
            "WHERE type = 'rent' AND status <> 'waived'"
        );
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement('DROP INDEX IF EXISTS ledger_entries_unique_live_rent_period');
        }
    }
};
