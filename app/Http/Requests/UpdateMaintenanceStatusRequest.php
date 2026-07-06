<?php

namespace App\Http\Requests;

use App\Enums\MaintenanceAssigneeType;
use App\Enums\MaintenanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateMaintenanceStatusRequest
 *
 * Validates a landlord's status update on a maintenance request. Assignment
 * (assignee/appointment fields) and resolution (notes/costs) are folded into
 * this single endpoint since they are just status transitions with extra
 * payload — reopening and cost-only edits are separate endpoints, since
 * those aren't "set status to X" actions.
 *
 * Authorization is performed in the controller via the policy
 * (updateStatus gate), so authorize() returns true here.
 */
class UpdateMaintenanceStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Controller performs authorization via $this->authorize('updateStatus', $maintenanceRequest)
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(MaintenanceStatus::class)],
            'resolution_notes' => [
                Rule::requiredIf(fn () => $this->input('status') === MaintenanceStatus::RESOLVED->value),
                'nullable', 'string', 'max:2000',
            ],
            'waiting_reason' => [
                Rule::requiredIf(fn () => $this->input('status') === MaintenanceStatus::WAITING->value),
                'nullable', 'string', 'max:1000',
            ],
            'assignee_name' => [
                Rule::requiredIf(fn () => $this->input('status') === MaintenanceStatus::ASSIGNED->value),
                'nullable', 'string', 'max:160',
            ],
            'assignee_phone' => ['nullable', 'string', 'max:40'],
            'assignee_type' => ['nullable', Rule::enum(MaintenanceAssigneeType::class)],
            'appointment_at' => ['nullable', 'date'],
            'expected_completion_date' => ['nullable', 'date'],
            'labor_cost_cents' => ['nullable', 'integer', 'min:0'],
            'parts_cost_cents' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function attributes(): array
    {
        return [
            'status' => 'status',
            'resolution_notes' => 'resolution notes',
            'waiting_reason' => 'waiting reason',
            'assignee_name' => 'vendor name',
        ];
    }
}
