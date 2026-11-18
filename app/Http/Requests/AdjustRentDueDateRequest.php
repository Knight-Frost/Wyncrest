<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Admin: move one unpaid rent charge's due date. The expected_* fields are
 * the compare-and-swap token from the preview (stale → 409).
 */
class AdjustRentDueDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Admin guard + admin.can:manage_ledger route middleware handle authorization.
        return true;
    }

    public function rules(): array
    {
        return [
            'due_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'expected_due_date' => ['required', 'date_format:Y-m-d'],
            'expected_status' => ['required', 'string', 'in:pending,overdue,paid,waived'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A reason is required to change a rent due date. It is kept permanently in the audit log.',
            'reason.min' => 'Please give a more descriptive reason.',
        ];
    }
}
