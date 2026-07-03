<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AdminSessionAuthTest
 *
 * Locks the admin console's cookie/session authentication architecture:
 *   - login establishes a first-party session and NEVER returns a bearer token;
 *   - the backend session (GET /api/admin/me) is the source of truth;
 *   - logout invalidates the session;
 *   - scoped-capability enforcement (403) and unauthenticated (401) stay distinct;
 *   - the deprecated bearer-token path can no longer authenticate admin routes,
 *     and tenant/landlord bearer identities can never reach the admin surface.
 */
class AdminSessionAuthTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): Admin
    {
        return Admin::factory()->create([
            'is_super_admin' => true,
            'is_active' => true,
            'password' => Hash::make('password'),
        ]);
    }

    private function scopedAdmin(array $capabilities): Admin
    {
        return Admin::factory()->create([
            'is_super_admin' => false,
            'is_active' => true,
            'capabilities' => $capabilities,
            'password' => Hash::make('password'),
        ]);
    }

    // ---- Login -----------------------------------------------------------

    public function test_login_establishes_session_and_returns_no_token(): void
    {
        $admin = $this->superAdmin();

        $response = $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.email', $admin->email)
            ->assertJsonPath('user.is_super_admin', true);

        // The credential is the HttpOnly session cookie — never a token in the body.
        $this->assertArrayNotHasKey('token', $response->json());
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_login_rejects_wrong_password_without_enumeration(): void
    {
        $admin = $this->superAdmin();

        $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertGuest('admin');
    }

    public function test_login_rejects_deactivated_admin(): void
    {
        $admin = $this->superAdmin();
        $admin->update(['is_active' => false]);

        $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertStatus(422);

        $this->assertGuest('admin');
    }

    // ---- Identity (source of truth) --------------------------------------

    public function test_me_returns_authenticated_admin(): void
    {
        $admin = $this->scopedAdmin(['view_audit']);

        $this->actingAs($admin, 'admin')
            ->getJson('/api/admin/me')
            ->assertOk()
            ->assertJsonPath('user.email', $admin->email)
            ->assertJsonPath('user.is_super_admin', false)
            ->assertJsonPath('user.capabilities', ['view_audit']);
    }

    public function test_me_is_401_for_guest(): void
    {
        $this->getJson('/api/admin/me')->assertStatus(401);
    }

    // ---- Logout ----------------------------------------------------------

    public function test_logout_invalidates_the_session(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'admin')
            ->postJson('/api/admin/logout')
            ->assertOk();

        $this->assertGuest('admin');
    }

    // ---- Authorization: 401 vs 403 stay distinct -------------------------

    public function test_super_admin_reaches_capability_gated_routes(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'admin')
            ->getJson('/api/admin/access/summary')
            ->assertOk();
    }

    public function test_scoped_admin_without_capability_is_forbidden_403(): void
    {
        $admin = $this->scopedAdmin(['view_audit']); // lacks manage_access

        $this->actingAs($admin, 'admin')
            ->getJson('/api/admin/access/summary')
            ->assertStatus(403)
            ->assertJsonPath('required_capability', 'manage_access');
    }

    public function test_scoped_admin_with_capability_is_allowed(): void
    {
        $admin = $this->scopedAdmin(['view_audit']);

        $this->actingAs($admin, 'admin')
            ->getJson('/api/admin/audit-logs')
            ->assertOk();
    }

    public function test_guest_hitting_gated_route_is_401_not_403(): void
    {
        $this->getJson('/api/admin/access/summary')->assertStatus(401);
    }

    // ---- Isolation from the bearer (tenant/landlord) pipeline ------------

    public function test_deprecated_admin_bearer_token_cannot_authenticate_admin_routes(): void
    {
        $admin = $this->superAdmin();
        $token = $admin->createToken('legacy')->plainTextToken;

        // A pre-migration admin bearer token must be inert on the session routes.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/me')
            ->assertStatus(401);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/contracts')
            ->assertStatus(401);
    }

    public function test_tenant_bearer_identity_cannot_reach_admin_routes(): void
    {
        $tenant = User::factory()->tenant()->create();
        Sanctum::actingAs($tenant, [], 'sanctum');

        // No admin session → unauthenticated on the admin guard (401), never
        // resolved as an admin.
        $this->getJson('/api/admin/me')->assertStatus(401);
        $this->getJson('/api/admin/contracts')->assertStatus(401);
    }

    // ---- Password change (session parity with token revocation) ----------

    public function test_password_change_requires_correct_current_password(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'admin')
            ->postJson('/api/admin/password', [
                'current_password' => 'wrong',
                'password' => 'NewPassw0rd',
                'password_confirmation' => 'NewPassw0rd',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');
    }

    public function test_password_change_succeeds_with_correct_current_password(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'admin')
            ->postJson('/api/admin/password', [
                'current_password' => 'password',
                'password' => 'NewPassw0rd',
                'password_confirmation' => 'NewPassw0rd',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('NewPassw0rd', $admin->fresh()->password));
    }
}
