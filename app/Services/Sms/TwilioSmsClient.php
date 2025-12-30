<?php

namespace App\Services\Sms;

use Twilio\Rest\Client as TwilioClient;
use Twilio\Exceptions\TwilioException;

/**
 * TwilioSmsClient
 * 
 * Twilio implementation of SMS client.
 * Phase 3.7: Concrete adapter for Twilio SMS service.
 */
class TwilioSmsClient implements SmsClientInterface
{
    protected ?TwilioClient $client = null;
    protected ?string $fromNumber = null;

    public function __construct()
    {
        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');
        $this->fromNumber = config('services.twilio.from');

        if ($sid && $token && $this->fromNumber) {
            $this->client = new TwilioClient($sid, $token);
        }
    }

    /**
     * Send SMS via Twilio
     * 
     * @param string $to Phone number in E.164 format
     * @param string $message SMS content (max 160 chars recommended)
     * @return bool
     * @throws \Exception
     */
    public function send(string $to, string $message): bool
    {
        if (!$this->isConfigured()) {
            throw new \Exception('Twilio is not configured. Check TWILIO_SID, TWILIO_TOKEN, and TWILIO_FROM in .env');
        }

        try {
            $this->client->messages->create(
                $to,
                [
                    'from' => $this->fromNumber,
                    'body' => $message,
                ]
            );

            return true;
        } catch (TwilioException $e) {
            throw new \Exception("Twilio SMS failed: {$e->getMessage()}");
        }
    }

    /**
     * Check if Twilio is configured
     * 
     * @return bool
     */
    public function isConfigured(): bool
    {
        return $this->client !== null && $this->fromNumber !== null;
    }
}
