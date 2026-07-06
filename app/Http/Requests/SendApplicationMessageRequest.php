<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SendApplicationMessageRequest
 *
 * Validates a landlord's message to an applicant. Authorization (landlord owns
 * the application) is enforced in the controller via the 'view' policy.
 */
class SendApplicationMessageRequest extends FormRequest
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
