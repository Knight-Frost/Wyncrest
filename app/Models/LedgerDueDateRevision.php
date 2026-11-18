<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only record of one admin due-date adjustment on a ledger entry. The
 * entry's original_due_date keeps the posted term; its due_date holds the
 * effective date; these rows are the history in between.
 */
class LedgerDueDateRevision extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'ledger_entry_id', 'contract_id', 'previous_due_date', 'new_due_date',
        'previous_status', 'new_status', 'reason', 'admin_id',
    ];

    protected $casts = [
        'previous_due_date' => 'date',
        'new_due_date' => 'date',
        'created_at' => 'datetime',
    ];

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    protected function performUpdate(\Illuminate\Database\Eloquent\Builder $query)
    {
        throw new \LogicException('Ledger due-date revisions are append-only.');
    }

    public function delete()
    {
        throw new \LogicException('Ledger due-date revisions are append-only.');
    }
}
