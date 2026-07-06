<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ContractLandlordNote
 *
 * Landlord-authored, landlord-visible note on a tenancy's case file. Distinct
 * from ContractNote, which is internal/admin-only and never exposed to the
 * landlord.
 */
class ContractLandlordNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'landlord_id',
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
     * The landlord who authored the note.
     */
    public function landlord(): BelongsTo
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }
}
