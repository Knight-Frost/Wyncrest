<?php

namespace App\Http\Requests;

use App\Enums\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreApplicationDocumentRequest
 *
 * Validates a document uploaded against a specific application (proof of income,
 * supporting attachment, etc.). Mirrors StoreDocumentRequest's strict file
 * whitelist. Authorization (owns application + non-final) is enforced in the
 * controller via the 'uploadDocument' policy.
 */
class StoreApplicationDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png,webp',
                'max:10240', // 10 MB
            ],
            'document_type' => [
                'nullable',
                Rule::enum(DocumentType::class),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'The file must be a PDF, JPG, PNG, or WebP image.',
            'file.max' => 'The file may not be larger than 10 MB.',
        ];
    }
}
