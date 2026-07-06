<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SendMaintenanceMessageRequest
 *
 * Validates a message sent on a maintenance request's thread. Authorization
 * (tenant or landlord named on the request) is enforced in the controller
 * via the 'view' policy.
 */
class SendMaintenanceMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
        ];
    }
}
