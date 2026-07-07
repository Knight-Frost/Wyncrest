<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * AssignMaintenanceCaseOwnerRequest
 *
 * Validates setting which platform admin owns/is triaging a maintenance case.
 * Authorization is the route-level admin.can:manage_maintenance gate.
 */
class AssignMaintenanceCaseOwnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'handling_admin_id' => ['required', 'integer', 'exists:admins,id'],
        ];
    }
}
