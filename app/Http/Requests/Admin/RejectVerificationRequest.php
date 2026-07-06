<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rejecting a verification request requires a reason — it becomes both the
 * decision_reason on the record and the message shown to the applicant.
 */
class RejectVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'reason' => 'rejection reason',
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'You must provide a reason for rejecting this verification request.',
        ];
    }
}
