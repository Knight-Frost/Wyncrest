<?php

namespace App\Support\Csv;

/**
 * CsvWriter
 *
 * Shared CSV-export hardening. Spreadsheet applications (Excel, Google
 * Sheets, LibreOffice) treat a cell beginning with `=`, `+`, `-`, `@`, a tab,
 * or a carriage return as a formula to evaluate on open. Any CSV export that
 * includes user-controlled strings (tenant names, listing titles, maintenance
 * titles, assignee names, etc.) is a formula-injection vector unless those
 * cells are neutralised first.
 */
class CsvWriter
{
    /**
     * Neutralise a single CSV cell. Non-string values pass through untouched;
     * string values whose first character could be interpreted as a formula
     * trigger by a spreadsheet application are prefixed with a single quote,
     * which forces the cell to be read back as literal text.
     */
    public static function sanitizeCell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        if (str_starts_with($value, '=')
            || str_starts_with($value, '+')
            || str_starts_with($value, '-')
            || str_starts_with($value, '@')
            || str_starts_with($value, "\t")
            || str_starts_with($value, "\r")
        ) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * Sanitize every cell in a single CSV row.
     *
     * @param  array<int|string, mixed>  $row
     * @return array<int|string, mixed>
     */
    public static function sanitizeRow(array $row): array
    {
        return array_map([self::class, 'sanitizeCell'], $row);
    }
}
