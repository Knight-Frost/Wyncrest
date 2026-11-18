<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forensic audit WYN-009 (launch closeout): polymorphic *_id columns that can
 * point at UUID-keyed models (Contract, LedgerEntry, VerificationRequest) were
 * created as BIGINT by morphs(). SQLite stores the UUID text anyway (type
 * affinity), which is why no test noticed; Postgres and MySQL refuse it, so
 * every audited contract or ledger write failed there.
 *
 * Widen them to varchar(36) on non-SQLite engines (existing bigint values
 * convert to their text form). SQLite is untouched.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'audit_logs' => 'subject_id',
        'documents' => 'related_id',
        'email_logs' => 'related_id',
    ];

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        foreach (self::COLUMNS as $table => $column) {
            Schema::table($table, function (Blueprint $t) use ($table, $column) {
                $t->string($column, 36)->nullable($table !== 'email_logs')->change();
            });
        }
    }

    public function down(): void
    {
        // Irreversible once UUID values exist in these columns; intentionally a no-op.
    }
};
