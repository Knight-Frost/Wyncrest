<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Enums\PropertyType;
use Illuminate\Validation\Rule;

/**
 * StorePropertyRequest
 * 
 * Validates property creation data.
 */
class StorePropertyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isLandlord() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'property_type' => ['required', Rule::enum(PropertyType::class)],
            'street_address' => ['required', 'string', 'max:255'],
            'street_address_2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'size:2', 'uppercase'],
            'zip_code' => ['required', 'string', 'max:10'],
            'country' => ['nullable', 'string', 'size:2', 'uppercase'],
            'year_built' => ['nullable', 'integer', 'min:1800', 'max:' . (date('Y') + 1)],
            'lot_size' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'property_type' => 'property type',
            'street_address_2' => 'apartment/suite number',
            'year_built' => 'year built',
            'lot_size' => 'lot size',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'state.size' => 'State must be a 2-letter code (e.g., CA, NY)',
            'state.uppercase' => 'State must be uppercase',
            'year_built.min' => 'Year built must be 1800 or later',
            'year_built.max' => 'Year built cannot be in the future',
        ];
    }
}
