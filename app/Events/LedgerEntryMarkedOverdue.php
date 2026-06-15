<?php

namespace App\Events;

use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * LedgerEntryMarkedOverdue
 *
 * Fired when a ledger entry is marked as overdue.
 * Triggered by: LedgerAutomationService::markOverdueEntries()
 */
class LedgerEntryMarkedOverdue
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public LedgerEntry $ledgerEntry,
        public User $tenant
    ) {}
}
