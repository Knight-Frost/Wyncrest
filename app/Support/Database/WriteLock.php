<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Take the write lock for one row at the START of a transaction.
 *
 * Postgres/MySQL: SELECT ... FOR UPDATE (a real row lock).
 *
 * SQLite: a no-op UPDATE of the row. why (launch closeout rehearsal): with a
 * DEFERRED transaction, the first SELECT takes a read lock; when the later
 * INSERT/UPDATE needs the write lock while another connection holds it, SQLite
 * returns SQLITE_BUSY *immediately* (it will not wait, to avoid deadlock), so
 * busy_timeout never helps and the request or scheduled job fails with
 * "database is locked". Making a write the first statement means the busy
 * handler applies and the transaction queues instead. Laravel's
 * `transaction_mode => IMMEDIATE` would do the same but is only honoured on
 * PHP 8.4+, and the launch host runs PHP 8.3.
 *
 * The no-op UPDATE changes no column, fires no model events and passes the
 * ledger immutability triggers.
 */
class WriteLock
{
    public static function row(string $table, string|int $id): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::update("update \"{$table}\" set \"id\" = \"id\" where \"id\" = ?", [$id]);

            return;
        }

        DB::table($table)->where('id', $id)->lockForUpdate()->first();
    }
}
