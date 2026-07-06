<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SendContractMessageRequest
 *
 * Validates a landlord's message to their tenant about a contract.
 * Authorization (landlord/tenant owns the contract) is enforced in the
 * controller via the 'view' policy.
 */
class SendContractMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
        ];
    }
}
