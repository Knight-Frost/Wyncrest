<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreApplicationDraftRequest
 *
 * Validates a tenant's request to START a draft application for a listing.
 * SECURITY: only listing_id is accepted; tenant/landlord/status are derived
 * server-side. The create ability is checked via the Application policy.
 */
class StoreApplicationDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\Application::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'listing_id' => ['required', 'exists:listings,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'listing_id' => 'listing',
        ];
    }
}
