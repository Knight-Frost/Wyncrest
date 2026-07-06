<?php

namespace App\Enums;

/**
 * PaymentMethod
 *
 * The offline/manual payment methods a landlord can record against a rent or
 * late-fee ledger entry (e.g. a tenant paid in cash or via mobile money
 * outside Stripe). Never used for the Stripe-originated PAYMENT flow, which
 * remains untouched in PaymentService.
 */
enum PaymentMethod: string
{
    case MOBILE_MONEY_MTN = 'mobile_money_mtn';
    case MOBILE_MONEY_VODAFONE = 'mobile_money_vodafone';
    case BANK_TRANSFER = 'bank_transfer';
    case CASH = 'cash';

    public function label(): string
    {
        return match ($this) {
            self::MOBILE_MONEY_MTN => 'Mobile money · MTN',
            self::MOBILE_MONEY_VODAFONE => 'Mobile money · Vodafone',
            self::BANK_TRANSFER => 'Bank transfer',
            self::CASH => 'Cash',
        };
    }
}
