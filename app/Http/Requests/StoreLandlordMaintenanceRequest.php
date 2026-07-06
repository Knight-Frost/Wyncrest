<?php

namespace App\Http\Requests;

use App\Enums\MaintenanceAssigneeType;
use App\Enums\MaintenanceCategory;
use App\Enums\MaintenancePriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreLandlordMaintenanceRequest
 *
 * Validates a landlord logging a maintenance request themselves (e.g. from a
 * routine inspection), rather than the tenant filing one. `mode` controls how
 * far it advances immediately — matched against MaintenanceService::createForLandlord().
 * Authorization: only a landlord may call this endpoint, and only against
 * their own contract (enforced via 'createAsLandlord' policy in the controller).
 */
class StoreLandlordMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contract_id' => ['required', 'uuid', 'exists:contracts,id'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', Rule::enum(MaintenanceCategory::class)],
            'priority' => ['required', Rule::enum(MaintenancePriority::class)],
            'mode' => ['required', 'in:new,assign,resolved'],

            'assignee_name' => [Rule::requiredIf(fn () => $this->input('mode') === 'assign'), 'nullable', 'string', 'max:160'],
            'assignee_phone' => ['nullable', 'string', 'max:40'],
            'assignee_type' => ['nullable', Rule::enum(MaintenanceAssigneeType::class)],
            'appointment_at' => ['nullable', 'date'],
            'expected_completion_date' => ['nullable', 'date'],

            'resolution_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
