<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WaiveLedgerEntryRequest
 *
 * Validates waiving a rent or late fee entry (admin only). A reason is
 * required — it is recorded permanently on the immutable audit log.
 */
class WaiveLedgerEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Admin guard + admin.can:manage_ledger route middleware handle authorization.
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A reason is required to waive a ledger entry.',
            'reason.min' => 'Please provide a more descriptive reason.',
        ];
    }
}
