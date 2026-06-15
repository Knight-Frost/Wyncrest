<?php

namespace App\Services\Sms;

/**
 * SmsClientInterface
 *
 * Abstraction layer for SMS providers.
 * Phase 3.7: Allows swapping between Twilio, Nexmo, AWS SNS, etc.
 */
interface SmsClientInterface
{
    /**
     * Send SMS message
     *
     * @param  string  $to  Phone number in E.164 format (e.g., +12025551234)
     * @param  string  $message  SMS message content
     * @return bool True if sent successfully, false otherwise
     *
     * @throws \Exception on delivery failure
     */
    public function send(string $to, string $message): bool;

    /**
     * Check if client is configured and ready
     */
    public function isConfigured(): bool;
}
