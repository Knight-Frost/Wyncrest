<?php

namespace App\Http\Requests;

use App\Enums\UnitAvailabilityStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreUnitRequest
 *
 * Validates unit creation data.
 */
class StoreUnitRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $property = $this->route('property');

        return $this->user()?->can('view', $property) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'unit_number' => ['nullable', 'string', 'max:50'],
            'internal_name' => ['nullable', 'string', 'max:255'],
            'bedrooms' => ['required', 'numeric', 'min:0', 'max:20'],
            'bathrooms' => ['required', 'numeric', 'min:0', 'max:20'],
            'square_feet' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'rent_amount' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'security_deposit' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'availability_status' => ['required', Rule::enum(UnitAvailabilityStatus::class)],
            'available_from' => ['nullable', 'date', 'after_or_equal:today'],
            'amenities' => ['nullable', 'array'],
            'amenities.*' => ['string', 'max:100'],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'unit_number' => 'unit number',
            'internal_name' => 'internal name',
            'square_feet' => 'square footage',
            'rent_amount' => 'monthly rent',
            'security_deposit' => 'security deposit',
            'availability_status' => 'availability status',
            'available_from' => 'available date',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'available_from.after_or_equal' => 'Available date cannot be in the past',
            'amenities.*.max' => 'Each amenity must be 100 characters or less',
        ];
    }
}
