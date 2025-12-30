<?php

namespace App\Services\Sms;

/**
 * FakeSmsClient
 * 
 * Fake SMS client for testing.
 * Phase 3.7: Does not send real SMS, tracks calls for assertions.
 */
class FakeSmsClient implements SmsClientInterface
{
    protected array $sent = [];
    protected bool $shouldFail = false;
    protected string $failureMessage = 'SMS delivery failed';

    /**
     * Fake send - stores message instead of sending
     * 
     * @param string $to
     * @param string $message
     * @return bool
     * @throws \Exception if configured to fail
     */
    public function send(string $to, string $message): bool
    {
        if ($this->shouldFail) {
            throw new \Exception($this->failureMessage);
        }

        $this->sent[] = [
            'to' => $to,
            'message' => $message,
            'sent_at' => now(),
        ];

        return true;
    }

    /**
     * Always configured for testing
     * 
     * @return bool
     */
    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * Configure client to fail on next send
     * 
     * @param string $message
     * @return void
     */
    public function shouldFail(string $message = 'SMS delivery failed'): void
    {
        $this->shouldFail = true;
        $this->failureMessage = $message;
    }

    /**
     * Get sent messages
     * 
     * @return array
     */
    public function getSent(): array
    {
        return $this->sent;
    }

    /**
     * Get count of sent messages
     * 
     * @return int
     */
    public function getSentCount(): int
    {
        return count($this->sent);
    }

    /**
     * Assert SMS was sent to specific number
     * 
     * @param string $to
     * @return bool
     */
    public function assertSentTo(string $to): bool
    {
        foreach ($this->sent as $sms) {
            if ($sms['to'] === $to) {
                return true;
            }
        }
        return false;
    }

    /**
     * Assert specific message was sent
     * 
     * @param string $message
     * @return bool
     */
    public function assertMessageSent(string $message): bool
    {
        foreach ($this->sent as $sms) {
            if (str_contains($sms['message'], $message)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reset sent messages
     * 
     * @return void
     */
    public function reset(): void
    {
        $this->sent = [];
        $this->shouldFail = false;
    }
}
