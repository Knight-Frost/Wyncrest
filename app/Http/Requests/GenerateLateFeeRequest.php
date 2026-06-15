<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GenerateLateFeeRequest
 *
 * Validates late fee generation (admin only).
 */
class GenerateLateFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Admin guard handles authorization
        return true;
    }

    public function rules(): array
    {
        return [
            'amount_cents' => ['required', 'integer', 'min:1', 'max:100000000'], // Max $1M late fee
        ];
    }

    public function messages(): array
    {
        return [
            'amount_cents.required' => 'Late fee amount is required',
            'amount_cents.integer' => 'Late fee amount must be an integer (in cents)',
            'amount_cents.min' => 'Late fee amount must be at least 1 cent',
            'amount_cents.max' => 'Late fee amount cannot exceed $1,000,000',
        ];
    }

    public function attributes(): array
    {
        return [
            'amount_cents' => 'late fee amount',
        ];
    }
}
