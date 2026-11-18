<?php

namespace App\Services\Ledger;

/**
 * The charge or schedule changed after the admin previewed it (a payment,
 * waiver, overdue sweep, rent generation or another admin got there first).
 * Rendered as HTTP 409 with the current state so the UI can re-preview.
 */
class DueDateConflict extends \RuntimeException
{
    /** @param array<string, mixed> $current */
    public function __construct(string $message, public readonly array $current = [])
    {
        parent::__construct($message);
    }
}
