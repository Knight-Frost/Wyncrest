<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * RecordManualPaymentRequest
 *
 * Validates a landlord recording an offline (cash/mobile money/bank
 * transfer) payment against one of their own open rent/late-fee ledger
 * entries. Authorization is enforced via LedgerEntryPolicy::recordPayment().
 */
class RecordManualPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('recordPayment', $this->route('ledgerEntry')) ?? false;
    }

    public function rules(): array
    {
        return [
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
