<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreMaintenanceAdminNoteRequest
 *
 * Validates an internal, admin-only note on a maintenance case. Authorization
 * is the route-level admin.can:manage_maintenance gate.
 */
class StoreMaintenanceAdminNoteRequest extends FormRequest
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
