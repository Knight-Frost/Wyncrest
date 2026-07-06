<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ContractRenewal
 *
 * History row recorded every time a landlord renews an ACTIVE contract
 * in-place (new end date / rent amount). Unlike ContractNote, this IS
 * landlord-visible — it's the truthful "what changed and when" record the
 * Tenant Management lease-history timeline is built from.
 */
class ContractRenewal extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'landlord_id',
        'previous_end_date',
        'previous_rent_amount',
        'new_end_date',
        'new_rent_amount',
        'note',
    ];

    protected $casts = [
        'previous_end_date' => 'date',
        'previous_rent_amount' => 'integer',
        'new_end_date' => 'date',
        'new_rent_amount' => 'integer',
    ];

    /**
     * The contract this renewal belongs to.
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * The landlord who performed the renewal.
     */
    public function landlord(): BelongsTo
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }
}
