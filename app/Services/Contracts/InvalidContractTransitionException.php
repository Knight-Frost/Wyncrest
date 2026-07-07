<?php

namespace App\Services\Contracts;

/**
 * Thrown when a contract lifecycle transition is attempted from a status that
 * does not allow it. The controllers already gate transitions through policies
 * (ownership + state), so this is the service-layer safety net that also
 * protects non-HTTP callers (scheduled commands, future integrations) from
 * driving a contract through an illegal transition.
 */
class InvalidContractTransitionException extends \DomainException {}
