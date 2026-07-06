<?php

namespace App\Http\Requests;

use App\Enums\MaintenanceAccess;
use App\Enums\MaintenanceArea;
use App\Enums\MaintenanceCategory;
use App\Enums\MaintenanceContactMethod;
use App\Enums\MaintenanceOnset;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceSafetyFlag;
use App\Enums\MaintenanceVisitWindow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreMaintenanceRequest
 *
 * Validates tenant submission of a new maintenance request.
 * Authorization: only tenants may call this endpoint (enforced via policy).
 * Active-lease enforcement happens in the controller after this request passes.
 */
class StoreMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\MaintenanceRequest::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'contract_id' => ['required', 'exists:contracts,id'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:2000'],
            'category' => ['required', Rule::enum(MaintenanceCategory::class)],
            'priority' => ['required', Rule::enum(MaintenancePriority::class)],

            // Intake ("repair report") fields. The core three are required so a
            // landlord always knows WHERE it is, WHEN it started, and whether
            // they can enter; the rest refine scheduling.
            'area' => ['required', Rule::enum(MaintenanceArea::class)],
            'specific_location' => ['nullable', 'string', 'max:255'],
            'onset' => ['required', Rule::enum(MaintenanceOnset::class)],
            'safety_flags' => ['nullable', 'array'],
            'safety_flags.*' => [Rule::enum(MaintenanceSafetyFlag::class)],
            'access_permission' => ['required', Rule::enum(MaintenanceAccess::class)],
            'preferred_visit_window' => ['nullable', Rule::enum(MaintenanceVisitWindow::class)],
            'preferred_contact_method' => ['nullable', Rule::enum(MaintenanceContactMethod::class)],
            'access_instructions' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'contract_id' => 'contract',
            'title' => 'title',
            'description' => 'description',
            'category' => 'category',
            'priority' => 'priority',
            'area' => 'room or area',
            'onset' => 'when it started',
            'access_permission' => 'access permission',
            'preferred_visit_window' => 'preferred visit time',
            'preferred_contact_method' => 'preferred contact method',
        ];
    }
}
