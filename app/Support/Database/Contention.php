<?php

namespace App\Support\Database;

use Illuminate\Database\QueryException;

/**
 * Classifies database errors that mean "another writer got there first, retry"
 * as opposed to real failures.
 */
class Contention
{
    public static function isRetryable(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $message = $e->getMessage();

        // SQLite SQLITE_BUSY (5) / SQLITE_LOCKED (6).
        if (in_array($driverCode, [5, 6], true) && str_contains($message, 'locked')) {
            return true;
        }
        if (str_contains($message, 'database is locked') || str_contains($message, 'database table is locked')) {
            return true;
        }

        // Postgres serialization_failure / deadlock_detected / lock_not_available.
        if (in_array($sqlState, ['40001', '40P01', '55P03'], true)) {
            return true;
        }

        // MySQL deadlock (1213) / lock wait timeout (1205).
        return in_array($driverCode, [1205, 1213], true);
    }
}
