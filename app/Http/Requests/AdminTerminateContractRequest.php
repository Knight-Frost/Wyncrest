<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * AdminTerminateContractRequest
 *
 * Validates admin forced contract termination.
 */
class AdminTerminateContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Admin guard handles authorization
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:20', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Admin must provide a detailed reason for forced termination',
            'reason.min' => 'Admin termination reason must be at least 20 characters for audit compliance',
        ];
    }

    public function attributes(): array
    {
        return [
            'reason' => 'termination reason',
        ];
    }
}
