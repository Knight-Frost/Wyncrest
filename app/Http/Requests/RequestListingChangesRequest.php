<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * RequestListingChangesRequest
 *
 * Validates an admin sending a listing back to the landlord for changes.
 * Requires a clear, actionable message the landlord will see.
 */
class RequestListingChangesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Admin capability middleware handles authorization.
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'reason' => 'change request message',
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Tell the landlord what needs to change',
            'reason.min' => 'The message must be at least 20 characters so the landlord knows what to fix',
        ];
    }
}
