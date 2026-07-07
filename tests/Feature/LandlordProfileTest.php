<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Proves the landlord profile is real: editable fields persist, privileged
 * fields cannot be set by the client, and tenants cannot reach this endpoint.
 */
class LandlordProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_landlord_can_view_profile(): void
    {
        Sanctum::actingAs(User::factory()->landlord()->create(), [], 'sanctum');

        $this->getJson('/api/landlord/profile')
            ->assertOk()
            ->assertJsonStructure([
                'user' => ['id', 'first_name', 'last_name', 'full_name', 'email', 'phone', 'user_type', 'identity_verified', 'avatar_url'],
            ]);
    }

    public function test_tenant_cannot_access_landlord_profile(): void
    {
        Sanctum::actingAs(User::factory()->tenant()->create(), [], 'sanctum');

        $this->getJson('/api/landlord/profile')->assertStatus(403);
    }

    public function test_landlord_can_update_allowed_fields(): void
    {
        $landlord = User::factory()->landlord()->create(['phone' => null]);
        Sanctum::actingAs($landlord, [], 'sanctum');

        $this->patchJson('/api/landlord/profile', [
            'first_name' => 'Kwabena',
            'last_name' => 'Owusu',
            'phone' => '0241234567',
        ])->assertOk()->assertJsonPath('user.phone', '0241234567');

        $this->assertDatabaseHas('users', [
            'id' => $landlord->id,
            'first_name' => 'Kwabena',
            'last_name' => 'Owusu',
            'phone' => '0241234567',
        ]);
    }

    public function test_landlord_cannot_update_protected_fields(): void
    {
        $landlord = User::factory()->landlord()->create(['identity_verified' => false]);
        Sanctum::actingAs($landlord, [], 'sanctum');

        $this->patchJson('/api/landlord/profile', [
            'phone' => '0209998888',
            'user_type' => 'admin',
            'identity_verified' => true,
            'is_active' => false,
            'email' => 'hacker@evil.test',
        ])->assertOk();

        $landlord->refresh();
        $this->assertSame('landlord', $landlord->user_type->value);
        $this->assertFalse($landlord->identity_verified);
        $this->assertTrue($landlord->is_active);
        $this->assertNotSame('hacker@evil.test', $landlord->email);
        $this->assertSame('0209998888', $landlord->phone);
    }

    public function test_landlord_profile_update_creates_audit_log(): void
    {
        $landlord = User::factory()->landlord()->create();
        Sanctum::actingAs($landlord, [], 'sanctum');

        $this->patchJson('/api/landlord/profile', ['phone' => '0247778888'])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'landlord_profile_updated',
            'subject_type' => User::class,
            'subject_id' => $landlord->id,
        ]);
    }

    public function test_profile_validation_errors_return_json(): void
    {
        Sanctum::actingAs(User::factory()->landlord()->create(), [], 'sanctum');

        $this->patchJson('/api/landlord/profile', [
            'first_name' => str_repeat('x', 100),
        ])->assertStatus(422)->assertJsonValidationErrors(['first_name']);
    }
}
