<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SecurityHardeningTest
 *
 * Regression tests for the OWASP-aligned hardening controls audited during
 * project completion: response security headers, mass-assignment protection on
 * registration, and Stripe webhook signature enforcement.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_responses_include_security_headers(): void
    {
        $response = $this->getJson('/api/listings');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        $this->assertNotEmpty($response->headers->get('Permissions-Policy'));
    }

    public function test_registration_cannot_escalate_privileges_via_mass_assignment(): void
    {
        // Attacker attempts to smuggle privileged fields into registration.
        $response = $this->postJson('/api/register', [
            'email' => 'attacker@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'first_name' => 'Mal',
            'last_name' => 'Lory',
            'user_type' => 'tenant',
            'identity_verified' => true,
            'is_active' => true,
            'suspended_at' => null,
            'identity_verified_by' => 1,
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'attacker@example.com')->firstOrFail();

        // Privileged fields must reflect secure defaults, not attacker input.
        $this->assertFalse($user->identity_verified, 'identity_verified must not be mass-assignable at registration');
        $this->assertNull($user->identity_verified_by, 'identity_verified_by must not be mass-assignable');
    }

    public function test_registration_rejects_invalid_user_type(): void
    {
        $response = $this->postJson('/api/register', [
            'email' => 'role@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'first_name' => 'Role',
            'last_name' => 'Hacker',
            'user_type' => 'admin', // not an allowed self-registration role
        ]);

        $response->assertStatus(422);
    }

    public function test_stripe_webhook_rejects_missing_signature_when_configured(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_testsecret']);

        $response = $this->postJson('/api/webhooks/stripe', ['type' => 'payment_intent.succeeded']);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'Missing signature']);
    }

    public function test_stripe_webhook_rejects_invalid_signature(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_testsecret']);

        $response = $this->withHeaders(['Stripe-Signature' => 't=1,v1=deadbeef'])
            ->postJson('/api/webhooks/stripe', ['type' => 'payment_intent.succeeded']);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'Invalid signature']);
    }

    public function test_stripe_webhook_rejects_when_secret_not_configured(): void
    {
        config(['services.stripe.webhook_secret' => null]);

        $response = $this->withHeaders(['Stripe-Signature' => 't=1,v1=whatever'])
            ->postJson('/api/webhooks/stripe', ['type' => 'payment_intent.succeeded']);

        $response->assertStatus(503);
    }
}
