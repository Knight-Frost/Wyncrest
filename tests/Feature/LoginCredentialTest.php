<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Login distinguishes an unknown email from a wrong password so the UI can point
 * at the field that is actually wrong. (Enumeration risk is accepted and
 * mitigated by the login throttle + audit logging.)
 */
class LoginCredentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_email_returns_an_email_error(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'nobody@example.com',
            'password' => 'Password123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
        $response->assertJsonMissingValidationErrors('password');
    }

    public function test_wrong_password_for_existing_user_returns_a_password_error(): void
    {
        User::factory()->create([
            'email' => 'tenant@example.com',
            'password' => Hash::make('CorrectHorse1'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'tenant@example.com',
            'password' => 'WrongPassword9',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
        $response->assertJsonMissingValidationErrors('email');
    }

    public function test_admin_cannot_authenticate_via_the_user_login_endpoint(): void
    {
        // Admins authenticate through the isolated cookie-session surface at
        // POST /api/admin/login. The shared /login endpoint (tenant/landlord
        // bearer tokens) must NOT authenticate an admin, even with the correct
        // password, and must never leak that the email belongs to an admin.
        Admin::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('AdminPass123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'admin@example.com',
            'password' => 'AdminPass123', // correct password, still rejected here
        ]);

        $response->assertStatus(422);
        // Falls through to the user lookup → generic "no account" (no enumeration).
        $this->assertArrayNotHasKey('token', $response->json());
        $this->assertGuest('admin');
    }

    public function test_correct_credentials_succeed(): void
    {
        User::factory()->create([
            'email' => 'good@example.com',
            'password' => Hash::make('CorrectHorse1'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'good@example.com',
            'password' => 'CorrectHorse1',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['user', 'token']);
    }
}
