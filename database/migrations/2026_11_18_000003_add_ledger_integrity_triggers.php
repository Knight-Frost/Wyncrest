<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database-level protection for financial history (forensic audit WYN-013,
 * WYN-006 hardening, launch closeout).
 *
 * Before this migration immutability was enforced only by the LedgerEntry
 * model, and every ledger FK was ON DELETE CASCADE: a raw query, a future
 * force-delete of a user, or a hard delete of a contract or listing would
 * silently erase charges, payments and receipts. Rebuilding ledger_entries on
 * SQLite to change the FK actions is a risky table copy of the most important
 * table, so instead triggers refuse the dangerous statements outright:
 *
 *  - DELETE on ledger_entries, ledger_due_date_revisions,
 *    rent_due_schedule_changes                       → refused
 *  - UPDATE of any posted term on ledger_entries      → refused
 *    (allowed to change: status, due_date, stripe_payment_intent_id — the
 *    three columns the sanctioned compare-and-swap paths write)
 *  - UPDATE on the two append-only history tables     → refused
 *  - DELETE of a contract or user that still has ledger rows → refused
 *    (this is what makes the legacy CASCADE FKs inert)
 *
 * Supported: SQLite (launch engine) and PostgreSQL. MySQL is not a supported
 * launch engine (see WYNCREST_LAUNCH_CLOSEOUT.md); on MySQL this is a no-op
 * and the model-level guards remain the only protection.
 *
 * Rehearsed on a copy of a seeded database: see the closeout report.
 */
return new class extends Migration
{
    /** Posted terms that may never change after insert. */
    private const IMMUTABLE_LEDGER_COLUMNS = [
        'contract_id', 'tenant_id', 'landlord_id', 'type', 'amount_cents', 'currency',
        'billing_period_start', 'billing_period_end', 'original_due_date',
        'related_rent_entry_id', 'payment_method', 'payment_reference', 'created_at',
    ];

    public function up(): void
    {
        match (DB::getDriverName()) {
            'sqlite' => $this->upSqlite(),
            'pgsql' => $this->upPgsql(),
            default => null,
        };
    }

    public function down(): void
    {
        match (DB::getDriverName()) {
            'sqlite' => $this->downSqlite(),
            'pgsql' => $this->downPgsql(),
            default => null,
        };
    }

    private function upSqlite(): void
    {
        $changed = implode(' OR ', array_map(
            fn (string $c) => "NEW.{$c} IS NOT OLD.{$c}",
            self::IMMUTABLE_LEDGER_COLUMNS
        ));

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ledger_entries_no_delete BEFORE DELETE ON ledger_entries
            BEGIN SELECT RAISE(ABORT, 'ledger_entries rows are permanent financial history and cannot be deleted'); END;
        SQL);
        DB::unprepared(<<<SQL
            CREATE TRIGGER ledger_entries_posted_terms_immutable BEFORE UPDATE ON ledger_entries
            WHEN {$changed}
            BEGIN SELECT RAISE(ABORT, 'ledger_entries posted terms are immutable'); END;
        SQL);

        foreach (['ledger_due_date_revisions', 'rent_due_schedule_changes'] as $table) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_append_only_update BEFORE UPDATE ON {$table}
                BEGIN SELECT RAISE(ABORT, '{$table} is append-only'); END;
            SQL);
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_append_only_delete BEFORE DELETE ON {$table}
                BEGIN SELECT RAISE(ABORT, '{$table} is append-only'); END;
            SQL);
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER contracts_keep_ledger_history BEFORE DELETE ON contracts
            WHEN EXISTS (SELECT 1 FROM ledger_entries WHERE contract_id = OLD.id)
            BEGIN SELECT RAISE(ABORT, 'contract has ledger history and cannot be deleted'); END;
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER users_keep_ledger_history BEFORE DELETE ON users
            WHEN EXISTS (SELECT 1 FROM ledger_entries WHERE tenant_id = OLD.id OR landlord_id = OLD.id)
            BEGIN SELECT RAISE(ABORT, 'user has ledger history and cannot be deleted'); END;
        SQL);
    }

    private function downSqlite(): void
    {
        foreach ([
            'ledger_entries_no_delete',
            'ledger_entries_posted_terms_immutable',
            'ledger_due_date_revisions_append_only_update',
            'ledger_due_date_revisions_append_only_delete',
            'rent_due_schedule_changes_append_only_update',
            'rent_due_schedule_changes_append_only_delete',
            'contracts_keep_ledger_history',
            'users_keep_ledger_history',
        ] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function upPgsql(): void
    {
        $changed = implode(' OR ', array_map(
            fn (string $c) => "NEW.{$c} IS DISTINCT FROM OLD.{$c}",
            self::IMMUTABLE_LEDGER_COLUMNS
        ));

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION wyncrest_refuse() RETURNS trigger AS \$\$
            BEGIN RAISE EXCEPTION '%', TG_ARGV[0]; END; \$\$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION wyncrest_ledger_terms_guard() RETURNS trigger AS \$\$
            BEGIN
                IF {$changed} THEN
                    RAISE EXCEPTION 'ledger_entries posted terms are immutable';
                END IF;
                RETURN NEW;
            END; \$\$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION wyncrest_keep_contract_history() RETURNS trigger AS \$\$
            BEGIN
                IF EXISTS (SELECT 1 FROM ledger_entries WHERE contract_id = OLD.id) THEN
                    RAISE EXCEPTION 'contract has ledger history and cannot be deleted';
                END IF;
                RETURN OLD;
            END; \$\$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION wyncrest_keep_user_history() RETURNS trigger AS \$\$
            BEGIN
                IF EXISTS (SELECT 1 FROM ledger_entries WHERE tenant_id = OLD.id OR landlord_id = OLD.id) THEN
                    RAISE EXCEPTION 'user has ledger history and cannot be deleted';
                END IF;
                RETURN OLD;
            END; \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER ledger_entries_no_delete BEFORE DELETE ON ledger_entries
                FOR EACH ROW EXECUTE FUNCTION wyncrest_refuse('ledger_entries rows are permanent financial history and cannot be deleted');
            CREATE TRIGGER ledger_entries_posted_terms_immutable BEFORE UPDATE ON ledger_entries
                FOR EACH ROW EXECUTE FUNCTION wyncrest_ledger_terms_guard();
            CREATE TRIGGER ledger_due_date_revisions_append_only BEFORE UPDATE OR DELETE ON ledger_due_date_revisions
                FOR EACH ROW EXECUTE FUNCTION wyncrest_refuse('ledger_due_date_revisions is append-only');
            CREATE TRIGGER rent_due_schedule_changes_append_only BEFORE UPDATE OR DELETE ON rent_due_schedule_changes
                FOR EACH ROW EXECUTE FUNCTION wyncrest_refuse('rent_due_schedule_changes is append-only');
            CREATE TRIGGER contracts_keep_ledger_history BEFORE DELETE ON contracts
                FOR EACH ROW EXECUTE FUNCTION wyncrest_keep_contract_history();
            CREATE TRIGGER users_keep_ledger_history BEFORE DELETE ON users
                FOR EACH ROW EXECUTE FUNCTION wyncrest_keep_user_history();
        SQL);
    }

    private function downPgsql(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS ledger_entries_no_delete ON ledger_entries;
            DROP TRIGGER IF EXISTS ledger_entries_posted_terms_immutable ON ledger_entries;
            DROP TRIGGER IF EXISTS ledger_due_date_revisions_append_only ON ledger_due_date_revisions;
            DROP TRIGGER IF EXISTS rent_due_schedule_changes_append_only ON rent_due_schedule_changes;
            DROP TRIGGER IF EXISTS contracts_keep_ledger_history ON contracts;
            DROP TRIGGER IF EXISTS users_keep_ledger_history ON users;
            DROP FUNCTION IF EXISTS wyncrest_refuse();
            DROP FUNCTION IF EXISTS wyncrest_ledger_terms_guard();
            DROP FUNCTION IF EXISTS wyncrest_keep_contract_history();
            DROP FUNCTION IF EXISTS wyncrest_keep_user_history();
        SQL);
    }
};
