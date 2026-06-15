<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SubmitListingRequest
 *
 * Validates listing submission for review.
 * Ensures listing meets minimum requirements before admin review.
 */
class SubmitListingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('submit', $this->route('listing')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $listing = $this->route('listing');

        // Validate listing has required data
        return [
            // No input required, but we validate the listing state
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $listing = $this->route('listing');

            // Ensure listing has minimum required fields
            if (empty($listing->title)) {
                $validator->errors()->add('listing', 'Listing must have a title before submission');
            }

            if (empty($listing->description) || strlen($listing->description) < 50) {
                $validator->errors()->add('listing', 'Listing must have a description of at least 50 characters');
            }

            // Ensure unit has required data
            if (! $listing->unit) {
                $validator->errors()->add('listing', 'Listing must be associated with a unit');
            }

            // Ensure property has address
            if (! $listing->unit->property->city || ! $listing->unit->property->state) {
                $validator->errors()->add('listing', 'Property address must be complete');
            }
        });
    }
}
