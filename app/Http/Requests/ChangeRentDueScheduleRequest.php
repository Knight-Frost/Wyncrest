<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Admin: from a future billing month, rent for this lease is due on day N.
 * The reset route uses the same request with due_day omitted.
 */
class ChangeRentDueScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Admin guard + admin.can:manage_ledger route middleware handle authorization.
        return true;
    }

    public function rules(): array
    {
        return [
            'due_day' => [$this->routeIs('admin.contracts.rent-schedule.reset') ? 'prohibited' : 'required', 'integer', 'between:1,31'],
            'effective_month' => ['required', 'date_format:Y-m'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'expected_version' => ['required', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A reason is required to change when rent is due. It is kept permanently in the audit log.',
            'reason.min' => 'Please give a more descriptive reason.',
        ];
    }
}
