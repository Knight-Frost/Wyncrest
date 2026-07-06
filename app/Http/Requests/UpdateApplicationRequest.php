<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdateApplicationRequest
 *
 * Validates a partial save of a draft application's structured form. The shape
 * is UI-driven (multi-step: personal / contact / employment / rental /
 * household), so fields are whitelisted per section and every field is
 * optional — partial autosaves are the norm. Authorization (owns + is-draft) is
 * enforced in the controller via the 'update' policy after route-model binding.
 */
class UpdateApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $str = ['nullable', 'string', 'max:255'];
        $long = ['nullable', 'string', 'max:2000'];

        return [
            'form_data' => ['required', 'array'],

            'form_data.personal' => ['sometimes', 'array'],
            'form_data.personal.first' => $str,
            'form_data.personal.last' => $str,
            'form_data.personal.preferred' => $str,
            'form_data.personal.email' => ['nullable', 'email', 'max:255'],
            'form_data.personal.phone' => $str,
            'form_data.personal.dob' => $str,

            'form_data.contact' => ['sometimes', 'array'],
            'form_data.contact.pref' => $str,
            'form_data.contact.mailing' => $long,

            'form_data.employment' => ['sometimes', 'array'],
            'form_data.employment.status' => $str,
            'form_data.employment.employer' => $str,
            'form_data.employment.title' => $str,
            'form_data.employment.income' => $str,
            'form_data.employment.start' => $str,
            'form_data.employment.other' => $long,

            'form_data.rental' => ['sometimes', 'array'],
            'form_data.rental.curType' => $str,
            'form_data.rental.curLandlord' => $str,
            'form_data.rental.curContact' => $str,
            'form_data.rental.curRent' => $str,
            'form_data.rental.moveIn' => $str,
            'form_data.rental.reason' => $long,

            'form_data.household' => ['sometimes', 'array'],
            'form_data.household.adults' => $str,
            'form_data.household.children' => $str,
            'form_data.household.pets' => $str,
            'form_data.household.petDetail' => $long,
            'form_data.household.vehicles' => $str,
        ];
    }
}
