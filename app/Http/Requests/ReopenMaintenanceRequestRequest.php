<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ReopenMaintenanceRequestRequest
 *
 * Validates a landlord reopening a resolved/closed/cancelled request.
 * Authorization is performed in the controller via the 'updateStatus' policy
 * gate (the same landlord-owns-record check governs reopening).
 */
class ReopenMaintenanceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
