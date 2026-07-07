<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * EscalateMaintenanceRequestRequest
 *
 * Validates an admin escalating a maintenance case for closer attention.
 * Authorization is the route-level admin.can:manage_maintenance gate.
 */
class EscalateMaintenanceRequestRequest extends FormRequest
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
