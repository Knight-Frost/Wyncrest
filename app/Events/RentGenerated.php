<?php

namespace App\Events;

use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * RentGenerated
 *
 * Fired when automated rent is generated for a contract.
 * Triggered by: LedgerAutomationService::generateRentForContract()
 */
class RentGenerated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public LedgerEntry $ledgerEntry,
        public User $tenant
    ) {}
}
