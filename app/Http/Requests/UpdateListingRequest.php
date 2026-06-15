<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdateListingRequest
 *
 * Validates listing update data.
 * Can only update if listing status allows editing.
 */
class UpdateListingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('listing')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'min:50', 'max:5000'],
            'pets_allowed' => ['sometimes', 'boolean'],
            'pet_policy' => ['required_if:pets_allowed,true', 'nullable', 'string', 'max:1000'],
            'lease_duration_months' => ['nullable', 'integer', 'min:1', 'max:36'],
            'move_in_date' => ['nullable', 'date'],
        ];
    }
}
