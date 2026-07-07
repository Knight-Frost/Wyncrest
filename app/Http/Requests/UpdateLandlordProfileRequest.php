<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdateLandlordProfileRequest
 *
 * SECURITY: Whitelists ONLY landlord-editable display/contact fields. Privileged
 * fields (user_type, identity_verified, is_active, email, password) are
 * deliberately absent, so even a crafted payload cannot escalate or self-verify
 * — the controller only persists $request->validated().
 */
class UpdateLandlordProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->isLandlord() ?? false);
    }

    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'max:80'],
            'last_name' => ['sometimes', 'string', 'max:80'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }
}
