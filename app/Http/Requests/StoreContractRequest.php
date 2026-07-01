<?php

namespace App\Http\Requests;

use App\Enums\BillingCycle;
use App\Enums\UserType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreContractRequest
 *
 * Validates contract creation by landlord.
 */
class StoreContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\Contract::class) ?? false;
    }

    /**
     * Landlords identify the tenant by email in the UI (they know the email,
     * not the internal id). Resolve it to a real tenant id here so the strict
     * tenant_id rule below stays the single security gate. Direct API callers
     * may still pass tenant_id and skip this entirely (backward compatible).
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('tenant_id') && $this->filled('tenant_email')) {
            $tenant = \App\Models\User::query()
                ->where('email', $this->input('tenant_email'))
                ->where('user_type', UserType::TENANT->value)
                ->first();

            if ($tenant) {
                $this->merge(['tenant_id' => $tenant->id]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'listing_id' => ['required', 'exists:listings,id'],
            // Optional tenant email — resolved to tenant_id in prepareForValidation().
            'tenant_email' => ['nullable', 'email'],
            // Security: Ensure tenant_id is a valid tenant user, not a landlord
            'tenant_id' => [
                'required',
                Rule::exists('users', 'id')->where(function ($query) {
                    $query->where('user_type', UserType::TENANT->value);
                }),
            ],
            'rent_amount' => ['required', 'integer', 'min:1', 'max:9999999999'], // Min $0.01, Max ~$99M in cents
            'currency' => ['sometimes', 'string', 'size:3', 'uppercase'],
            'billing_cycle' => ['sometimes', Rule::enum(BillingCycle::class)],
            'payment_day' => ['required', 'integer', 'min:1', 'max:28'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
        ];
    }

    /**
     * Give a precise error when an email was supplied but matched no tenant,
     * rather than the generic "tenant is required" the resolved-id rule emits.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->filled('tenant_email') && ! $this->filled('tenant_id')) {
                $validator->errors()->add(
                    'tenant_email',
                    'No tenant account was found with this email address.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'payment_day.min' => 'Payment day must be between 1 and 28',
            'payment_day.max' => 'Payment day must be between 1 and 28',
            'start_date.after_or_equal' => 'Start date cannot be in the past',
            'end_date.after' => 'End date must be after start date',
        ];
    }

    public function attributes(): array
    {
        return [
            'listing_id' => 'listing',
            'tenant_id' => 'tenant',
            'rent_amount' => 'rent amount',
            'payment_day' => 'payment day',
            'start_date' => 'start date',
            'end_date' => 'end date',
        ];
    }
}
