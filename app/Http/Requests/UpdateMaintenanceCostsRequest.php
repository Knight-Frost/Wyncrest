<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdateMaintenanceCostsRequest
 *
 * Validates a landlord editing the cost record on a maintenance request
 * after resolution (e.g. adding an invoice reference or marking it paid).
 * Authorization is performed in the controller via the 'updateStatus' policy
 * gate (the same landlord-owns-record check governs cost edits).
 */
class UpdateMaintenanceCostsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'labor_cost_cents' => ['nullable', 'integer', 'min:0'],
            'parts_cost_cents' => ['nullable', 'integer', 'min:0'],
            'invoice_reference' => ['nullable', 'string', 'max:100'],
            'cost_notes' => ['nullable', 'string', 'max:1000'],
            'cost_paid' => ['nullable', 'boolean'],
        ];
    }
}
