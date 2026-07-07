<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * OverrideCloseMaintenanceRequestRequest
 *
 * Validates a platform admin force-closing a stalled maintenance case.
 * Authorization is the route-level admin.can:manage_maintenance gate.
 */
class OverrideCloseMaintenanceRequestRequest extends FormRequest
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
