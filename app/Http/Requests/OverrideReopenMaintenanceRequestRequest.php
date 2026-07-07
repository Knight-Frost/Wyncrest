<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * OverrideReopenMaintenanceRequestRequest
 *
 * Validates a platform admin reopening a maintenance case. Authorization is
 * the route-level admin.can:manage_maintenance gate.
 */
class OverrideReopenMaintenanceRequestRequest extends FormRequest
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
