<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreListingRequest
 *
 * Validates listing creation data.
 * Creates listing in DRAFT status.
 */
class StoreListingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $unit = $this->route('unit');

        return $this->user()?->can('view', $unit) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'min:50', 'max:5000'],
            'pets_allowed' => ['required', 'boolean'],
            'pet_policy' => ['required_if:pets_allowed,true', 'nullable', 'string', 'max:1000'],
            'lease_duration_months' => ['nullable', 'integer', 'min:1', 'max:36'],
            'move_in_date' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'pets_allowed' => 'pet policy',
            'pet_policy' => 'pet policy details',
            'lease_duration_months' => 'lease duration',
            'move_in_date' => 'move-in date',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'description.min' => 'Description must be at least 50 characters',
            'pet_policy.required_if' => 'Please provide pet policy details when pets are allowed',
            'move_in_date.after_or_equal' => 'Move-in date cannot be in the past',
        ];
    }
}
