<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\LedgerEntry;

/**
 * InitiatePaymentRequest
 *
 * Validates payment initiation request.
 * SECURITY: Uses strict type comparison and delegates to policy.
 */
class InitiatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Get the ledger entry from route
        $ledgerEntry = $this->route('ledgerEntry');

        if (!$ledgerEntry instanceof LedgerEntry) {
            return false;
        }

        // Use the LedgerEntry policy for authorization
        return $this->user()?->can('pay', $ledgerEntry) ?? false;
    }

    public function rules(): array
    {
        return [
            // No additional input required - amount comes from ledger
        ];
    }

    public function messages(): array
    {
        return [];
    }
}
