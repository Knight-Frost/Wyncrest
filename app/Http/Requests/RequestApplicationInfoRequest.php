<?php

namespace App\Http\Requests;

use App\Enums\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * RequestApplicationInfoRequest
 *
 * Validates a landlord's request for more information / a document replacement
 * on an application. Authorization (landlord owns + application active) is
 * enforced in the controller via the 'requestInfo' policy.
 */
class RequestApplicationInfoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:1000'],
            'type' => ['nullable', 'in:document_replacement,more_info,general'],
            'document_type' => ['nullable', Rule::enum(DocumentType::class)],
            'reason' => ['nullable', 'string', 'max:1000'],
            'due_at' => ['nullable', 'date'],
        ];
    }
}
