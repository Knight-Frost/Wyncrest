<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\LedgerEntry;

/**
 * InitiatePaymentRequest
 * 
 * Validates payment initiation request.
 * Authorization handled by policy.
 */
class InitiatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Get the ledger entry from route
        $ledgerEntry = $this->route('ledgerEntry');
        
        if (!$ledgerEntry) {
            return false;
        }

        // Check if entry can be paid
        if (!$ledgerEntry->canBePaid()) {
            return false;
        }

        // Check if tenant owns this entry
        return $this->user()->id == $ledgerEntry->tenant_id;
    }

    public function rules(): array
    {
        return [
            // No additional input required - amount comes from ledger
        ];
    }

    public function messages(): array
    {
        return [
            //
        ];
    }
}
