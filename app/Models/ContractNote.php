<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ContractNote
 *
 * Internal, admin-only note attached to a contract's case file.
 * Never exposed to the landlord or tenant.
 */
class ContractNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'admin_id',
        'body',
    ];

    /**
     * The contract this note belongs to.
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * The admin who authored the note.
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
