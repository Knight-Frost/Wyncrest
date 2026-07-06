<?php

namespace App\Http\Requests;

use App\Enums\PropertyAmenity;
use App\Enums\PropertyType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdatePropertyRequest
 *
 * Validates property update data.
 * All fields optional for partial updates.
 */
class UpdatePropertyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('property')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'property_type' => ['sometimes', Rule::enum(PropertyType::class)],
            'street_address' => ['sometimes', 'string', 'max:255'],
            'street_address_2' => ['nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:100'],
            'state' => ['sometimes', 'string', 'size:2', 'uppercase'],
            'zip_code' => ['sometimes', 'string', 'max:10'],
            'country' => ['nullable', 'string', 'size:2', 'uppercase'],
            'year_built' => ['nullable', 'integer', 'min:1800', 'max:'.(date('Y') + 1)],
            'lot_size' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'description' => ['nullable', 'string', 'max:2000'],
            'parking' => ['nullable', 'string', 'max:255'],
            'pet_policy' => ['nullable', 'string', 'max:255'],
            'smoking_policy' => ['nullable', 'string', 'max:255'],
            'address_visibility' => ['sometimes', Rule::in(['area_only', 'full_after_approval', 'public'])],
            'amenities' => ['sometimes', 'nullable', 'array'],
            'amenities.*' => [Rule::enum(PropertyAmenity::class)],
            'rules' => ['sometimes', 'nullable', 'array'],
            'rules.pets_allowed' => ['nullable', 'boolean'],
            'rules.smoking_allowed' => ['nullable', 'boolean'],
            'rules.guests_allowed' => ['nullable', 'boolean'],
            'rules.max_occupants' => ['nullable', 'integer', 'min:1', 'max:50'],
            'rules.min_lease_months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'rules.quiet_hours' => ['nullable', 'string', 'max:100'],
            'rules.utility_responsibility' => ['nullable', Rule::in(['landlord', 'tenant', 'shared'])],
            'rules.maintenance_responsibility' => ['nullable', Rule::in(['landlord', 'tenant', 'shared'])],
        ];
    }
}
