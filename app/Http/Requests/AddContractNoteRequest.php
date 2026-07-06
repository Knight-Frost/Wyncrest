<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * AddContractNoteRequest
 *
 * Validates a landlord adding a private note to their own contract's case
 * file. Authorization is enforced in the controller via the 'view' policy.
 */
class AddContractNoteRequest extends FormRequest
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
