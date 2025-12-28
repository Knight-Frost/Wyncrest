<?php

namespace App\Enums;

enum LedgerType: string
{
    case RENT = 'rent';
    case LATE_FEE = 'late_fee';
    case PAYMENT = 'payment';
    case REFUND = 'refund';

    /**
     * Check if this is a rent entry
     */
    public function isRent(): bool
    {
        return $this === self::RENT;
    }

    /**
     * Check if this is a late fee entry
     */
    public function isLateFee(): bool
    {
        return $this === self::LATE_FEE;
    }

    /**
     * Check if this is a payment entry
     */
    public function isPayment(): bool
    {
        return $this === self::PAYMENT;
    }

    /**
     * Check if this is a refund entry
     */
    public function isRefund(): bool
    {
        return $this === self::REFUND;
    }

    /**
     * Check if this is an obligation (what tenant owes)
     */
    public function isObligation(): bool
    {
        return in_array($this, [self::RENT, self::LATE_FEE]);
    }

    /**
     * Check if this is a transaction (money moved)
     */
    public function isTransaction(): bool
    {
        return in_array($this, [self::PAYMENT, self::REFUND]);
    }
}
