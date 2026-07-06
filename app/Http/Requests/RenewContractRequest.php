<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * RenewContractRequest
 *
 * Validates a landlord's in-place renewal of their own ACTIVE contract.
 * Authorization (contract is active, requester is the landlord) is enforced
 * via ContractPolicy::renew().
 */
class RenewContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('renew', $this->route('contract')) ?? false;
    }

    public function rules(): array
    {
        $contract = $this->route('contract');

        // Open-ended contracts (end_date null) are legal, so there is no prior
        // end date to be "after". In that case the renewal just needs to move
        // the lease forward from today; otherwise it must extend past the
        // contract's current end date.
        $after = $contract?->end_date ? $contract->end_date : today();

        return [
            'new_end_date' => ['required', 'date', 'after:'.$after],
            'new_rent_amount' => ['nullable', 'integer', 'min:1', 'max:9999999999'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
