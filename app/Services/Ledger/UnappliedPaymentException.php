<?php

namespace App\Services\Ledger;

/**
 * Stripe reports money as captured, but Wyncrest cannot apply it to the
 * obligation it was meant for: the obligation is already settled (paid by
 * another channel or waived) or the captured amount/currency does not match.
 *
 * This is a permanent condition: retrying the webhook can never apply it. The
 * thrower has already written a durable, critical `payment_unapplied` audit
 * record for a human to resolve (refund or credit is an owner decision; no
 * ledger entry is invented). The webhook acknowledges it so Stripe stops
 * retrying. Forensic audit WYN-003.
 */
class UnappliedPaymentException extends \RuntimeException {}
