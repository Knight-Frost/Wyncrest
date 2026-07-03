<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AdminUserManagementTest
 *
 * Covers the admin user-management endpoints:
 *   GET    /api/admin/users
 *   GET    /api/admin/users/{user}
 *   POST   /api/admin/users/{user}/suspend
 *   POST   /api/admin/users/{user}/activate
 */
class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::factory()->create(['is_super_admin' => true]);
    }

    public function test_index_returns_paginated_users_excluding_admins(): void
    {
        User::factory()->tenant()->count(3)->create();
        User::factory()->landlord()->count(2)->create();

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/users');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'current_page',
                'last_page',
                'per_page',
                'total',
            ])
            ->assertJsonPath('total', 5)
            ->assertJsonPath('per_page', 20);

        // Admins are a separate table and must not appear.
        $emails = collect($response->json('data'))->pluck('email');
        $this->assertNotContains($this->admin->email, $emails);

        // withCount fields are present.
        $this->assertArrayHasKey('properties_count', $response->json('data.0'));
        $this->assertArrayHasKey('listings_count', $response->json('data.0'));
        $this->assertArrayHasKey('applications_count', $response->json('data.0'));
    }

    public function test_index_type_filter(): void
    {
        User::factory()->tenant()->count(3)->create();
        User::factory()->landlord()->count(2)->create();

        $this->actingAs($this->admin, 'admin');

        $tenants = $this->getJson('/api/admin/users?type=tenant');
        $tenants->assertStatus(200)->assertJsonPath('total', 3);

        $landlords = $this->getJson('/api/admin/users?type=landlord');
        $landlords->assertStatus(200)->assertJsonPath('total', 2);
    }

    public function test_index_status_filter(): void
    {
        User::factory()->tenant()->count(2)->create();
        User::factory()->tenant()->create([
            'is_active' => false,
            'suspended_at' => now(),
        ]);

        $this->actingAs($this->admin, 'admin');

        $active = $this->getJson('/api/admin/users?status=active');
        $active->assertStatus(200)->assertJsonPath('total', 2);

        $suspended = $this->getJson('/api/admin/users?status=suspended');
        $suspended->assertStatus(200)->assertJsonPath('total', 1);
    }

    public function test_index_search_filter(): void
    {
        User::factory()->tenant()->create([
            'first_name' => 'Kwame',
            'last_name' => 'Mensah',
            'email' => 'kwame@example.com',
        ]);
        User::factory()->tenant()->create([
            'first_name' => 'Ama',
            'last_name' => 'Owusu',
            'email' => 'ama@example.com',
        ]);

        $this->actingAs($this->admin, 'admin');

        // by first name (case-insensitive)
        $byName = $this->getJson('/api/admin/users?search=kwame');
        $byName->assertStatus(200)->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.email', 'kwame@example.com');

        // by email fragment
        $byEmail = $this->getJson('/api/admin/users?search=ama@');
        $byEmail->assertStatus(200)->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.first_name', 'Ama');

        // by last name
        $byLast = $this->getJson('/api/admin/users?search=mensah');
        $byLast->assertStatus(200)->assertJsonPath('total', 1);
    }

    public function test_show_returns_user_with_stats(): void
    {
        $landlord = User::factory()->landlord()->create();
        Property::factory()->count(2)->create(['landlord_id' => $landlord->id]);

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson("/api/admin/users/{$landlord->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'user' => ['id', 'email', 'full_name', 'initials'],
                'stats' => ['properties', 'listings', 'active_contracts', 'applications'],
                'recent_contracts',
                'recent_applications',
            ])
            ->assertJsonPath('stats.properties', 2)
            ->assertJsonPath('user.id', $landlord->id);
    }

    public function test_admin_can_suspend_a_user(): void
    {
        $user = User::factory()->tenant()->create();

        $this->actingAs($this->admin, 'admin');

        $response = $this->postJson("/api/admin/users/{$user->id}/suspend", [
            'reason' => 'Fraudulent activity detected.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'User suspended');

        $user->refresh();
        $this->assertNotNull($user->suspended_at);
        $this->assertFalse($user->is_active);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'account_suspended',
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'actor_type' => Admin::class,
            'actor_id' => $this->admin->id,
        ]);
    }

    public function test_suspend_requires_a_reason(): void
    {
        $user = User::factory()->tenant()->create();

        $this->actingAs($this->admin, 'admin');

        $this->postJson("/api/admin/users/{$user->id}/suspend", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_double_suspend_returns_422(): void
    {
        $user = User::factory()->tenant()->create([
            'is_active' => false,
            'suspended_at' => now(),
        ]);

        $this->actingAs($this->admin, 'admin');

        $this->postJson("/api/admin/users/{$user->id}/suspend", [
            'reason' => 'Already handled previously.',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'User is already suspended');
    }

    public function test_admin_can_activate_a_suspended_user(): void
    {
        $user = User::factory()->tenant()->create([
            'is_active' => false,
            'suspended_at' => now(),
        ]);

        $this->actingAs($this->admin, 'admin');

        $response = $this->postJson("/api/admin/users/{$user->id}/activate");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'User reactivated');

        $user->refresh();
        $this->assertNull($user->suspended_at);
        $this->assertTrue($user->is_active);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'account_reactivated',
            'subject_type' => User::class,
            'subject_id' => $user->id,
        ]);
    }

    public function test_activate_already_active_user_returns_422(): void
    {
        $user = User::factory()->tenant()->create();

        $this->actingAs($this->admin, 'admin');

        $this->postJson("/api/admin/users/{$user->id}/activate")
            ->assertStatus(422)
            ->assertJsonPath('message', 'User is already active');
    }

    public function test_landlord_cannot_access_admin_user_management(): void
    {
        $landlord = User::factory()->landlord()->create();
        Sanctum::actingAs($landlord, [], 'sanctum');

        $this->getJson('/api/admin/users')->assertStatus(401);
    }

    public function test_tenant_cannot_access_admin_user_management(): void
    {
        $tenant = User::factory()->tenant()->create();
        Sanctum::actingAs($tenant, [], 'sanctum');

        $this->getJson('/api/admin/users')->assertStatus(401);
    }

    public function test_admin_user_management_requires_authentication(): void
    {
        $this->getJson('/api/admin/users')->assertStatus(401);
    }

    /**
     * Established admin accounts cannot be deleted — a hard, model-level guard.
     * The one deliberate exception (an unaccepted pending invite) is covered by
     * the access-control test suite.
     */
    public function test_admin_account_cannot_be_deleted(): void
    {
        $target = Admin::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Established admin accounts cannot be deleted');

        $target->delete();

        $this->assertDatabaseHas('admins', ['id' => $target->id]);
    }

    public function test_admin_account_cannot_be_force_deleted(): void
    {
        $target = Admin::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Admin accounts cannot be force-deleted');

        $target->forceDelete();
    }
}
