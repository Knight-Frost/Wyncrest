<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only "from billing month X, rent is due on day N" decision for a
 * lease. Rows are never edited or deleted (model guard + DB trigger); a later
 * row supersedes every earlier row whose effective_from is on/after its own.
 * Resolution lives in App\Services\Ledger\RentDueDayResolver.
 */
class RentDueScheduleChange extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'contract_id', 'due_day', 'effective_from', 'is_default', 'reason', 'admin_id',
    ];

    protected $casts = [
        'due_day' => 'integer',
        'effective_from' => 'date',
        'is_default' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    protected function performUpdate(\Illuminate\Database\Eloquent\Builder $query)
    {
        throw new \LogicException('Rent due schedule changes are append-only.');
    }

    public function delete()
    {
        throw new \LogicException('Rent due schedule changes are append-only.');
    }
}
