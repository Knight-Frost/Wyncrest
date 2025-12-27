<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Enums\UnitAvailabilityStatus;
use Illuminate\Validation\Rule;

/**
 * UpdateUnitRequest
 * 
 * Validates unit update data.
 * All fields optional for partial updates.
 */
class UpdateUnitRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('unit')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'unit_number' => ['sometimes', 'string', 'max:50'],
            'internal_name' => ['nullable', 'string', 'max:255'],
            'bedrooms' => ['sometimes', 'numeric', 'min:0', 'max:20'],
            'bathrooms' => ['sometimes', 'numeric', 'min:0', 'max:20'],
            'square_feet' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'rent_amount' => ['sometimes', 'numeric', 'min:0', 'max:999999.99'],
            'security_deposit' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'availability_status' => ['sometimes', Rule::enum(UnitAvailabilityStatus::class)],
            'available_from' => ['nullable', 'date'],
            'amenities' => ['nullable', 'array'],
            'amenities.*' => ['string', 'max:100'],
        ];
    }
}
