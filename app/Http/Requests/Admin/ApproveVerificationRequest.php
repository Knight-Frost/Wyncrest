<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approving a verification request. The route's admin.can:review_verifications
 * middleware is the security gate; further reviewability/document/account
 * guards live in VerificationService::approve().
 */
class ApproveVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
