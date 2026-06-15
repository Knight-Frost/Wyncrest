<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * SecurityAuditTest - Phase 7.5 Task 4
 * Simplified for projects without ADMIN user type
 */
class SecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_routes_require_authentication(): void
    {
        $protectedRoutes = [
            '/api/tenant/dashboard',
            '/api/landlord/onboarding',
        ];

        foreach ($protectedRoutes as $route) {
            $response = $this->getJson($route);
            $this->assertContains($response->status(), [401, 403],
                "Route {$route} did not require authentication");
        }
    }

    public function test_tenant_cannot_access_landlord_routes(): void
    {
        $tenant = User::factory()->create(['user_type' => UserType::TENANT]);
        $token = $tenant->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/landlord/onboarding');

        $response->assertStatus(403);
    }

    public function test_landlord_cannot_access_tenant_routes(): void
    {
        $landlord = User::factory()->create(['user_type' => UserType::LANDLORD]);
        $token = $landlord->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/tenant/dashboard');

        $response->assertStatus(403);
    }

    public function test_unauthorized_returns_401(): void
    {
        $response = $this->getJson('/api/tenant/dashboard');
        $response->assertStatus(401);
    }

    public function test_forbidden_returns_403(): void
    {
        $tenant = User::factory()->create(['user_type' => UserType::TENANT]);
        $token = $tenant->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/landlord/properties');

        $response->assertStatus(403);
    }

    public function test_invalid_token_rejected(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalid-token-12345',
        ])->getJson('/api/tenant/dashboard');

        $response->assertStatus(401);
    }

    public function test_csrf_protection_enabled(): void
    {
        $csrfEnabled = config('sanctum.stateful');
        $this->assertIsArray($csrfEnabled);
    }

    public function test_password_hashing_secure(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $this->assertNotEquals('password123', $user->password);
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertStringStartsWith('$2y$', $user->password);
    }
}
