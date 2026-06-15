<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * TerminateContractRequest
 *
 * Validates contract termination.
 */
class TerminateContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        $contract = $this->route('contract');

        return $this->user()?->can('terminate', $contract) ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'You must provide a reason for terminating the contract',
            'reason.min' => 'Termination reason must be at least 10 characters',
        ];
    }

    public function attributes(): array
    {
        return [
            'reason' => 'termination reason',
        ];
    }
}
