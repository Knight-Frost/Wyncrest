<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Requesting more information requires a note — it becomes both the
 * decision_reason on the record and the applicant-facing message.
 */
class RequestMoreInfoVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'note' => 'information request',
        ];
    }

    public function messages(): array
    {
        return [
            'note.required' => 'Describe what additional information or documents are needed.',
        ];
    }
}
