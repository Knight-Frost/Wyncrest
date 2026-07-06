<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * RejectListingRequest
 *
 * Validates listing rejection by admin.
 * Requires detailed rejection reason.
 */
class RejectListingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Admin guard handles authorization
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
            // Optional admin-only note captured alongside the decision.
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'reason' => 'rejection reason',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'You must provide a reason for rejecting this listing',
            'reason.min' => 'Rejection reason must be at least 20 characters to be helpful to the landlord',
        ];
    }
}
