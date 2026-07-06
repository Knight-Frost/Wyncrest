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

    public function test_index_returns_segment_counts(): void
    {
        User::factory()->landlord()->identityVerified()->count(2)->create();
        User::factory()->landlord()->create(['identity_verified' => false]);
        User::factory()->tenant()->count(3)->create(['identity_verified' => false]);

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/users');

        $response->assertStatus(200)
            ->assertJsonPath('counts.all', 6)
            ->assertJsonPath('counts.landlords', 3)
            ->assertJsonPath('counts.tenants', 3)
            // "needs review" = no verified identity (1 landlord + 3 tenants)
            ->assertJsonPath('counts.unverified', 4);
    }

    public function test_index_segment_counts_are_global_not_filtered(): void
    {
        User::factory()->landlord()->identityVerified()->count(2)->create();
        User::factory()->tenant()->count(3)->create(['identity_verified' => false]);

        $this->actingAs($this->admin, 'admin');

        // Even when the list is narrowed to landlords, the tiles show the
        // platform-wide totals so they never lie about the whole population.
        $response = $this->getJson('/api/admin/users?type=landlord');

        $response->assertStatus(200)
            ->assertJsonPath('total', 2)          // list is filtered
            ->assertJsonPath('counts.all', 5)     // counts are global
            ->assertJsonPath('counts.tenants', 3);
    }

    public function test_index_unverified_status_filter(): void
    {
        User::factory()->tenant()->identityVerified()->count(2)->create();
        User::factory()->tenant()->create(['identity_verified' => false]);

        $this->actingAs($this->admin, 'admin');

        $this->getJson('/api/admin/users?status=unverified')
            ->assertStatus(200)
            ->assertJsonPath('total', 1);
    }

    public function test_index_sort_by_name(): void
    {
        User::factory()->tenant()->create(['first_name' => 'Zara', 'last_name' => 'Zed']);
        User::factory()->tenant()->create(['first_name' => 'Abena', 'last_name' => 'Ackah']);

        $this->actingAs($this->admin, 'admin');

        $this->getJson('/api/admin/users?sort=name')
            ->assertStatus(200)
            ->assertJsonPath('data.0.first_name', 'Abena');
    }

    public function test_index_archived_filter_includes_soft_deleted(): void
    {
        $user = User::factory()->tenant()->create(['account_status' => 'archived']);
        $user->delete(); // soft delete — only reachable via withTrashed()

        User::factory()->tenant()->count(2)->create();

        $this->actingAs($this->admin, 'admin');

        // Default list excludes the soft-deleted archived user…
        $this->getJson('/api/admin/users')
            ->assertStatus(200)
            ->assertJsonPath('total', 2);

        // …but the archived filter surfaces it.
        $this->getJson('/api/admin/users?status=archived')
            ->assertStatus(200)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $user->id);
    }

    public function test_show_includes_verification_and_landlord_rating(): void
    {
        $landlord = User::factory()->landlord()->identityVerified()->create();
        $property = Property::factory()->create(['landlord_id' => $landlord->id]);

        \App\Models\Review::factory()->approved()->create([
            'landlord_id' => $landlord->id,
            'property_id' => $property->id,
            'rating' => 4,
        ]);
        \App\Models\Review::factory()->approved()->create([
            'landlord_id' => $landlord->id,
            'property_id' => $property->id,
            'rating' => 5,
        ]);
        // A pending review must NOT count toward the average.
        \App\Models\Review::factory()->create([
            'landlord_id' => $landlord->id,
            'property_id' => $property->id,
            'rating' => 1,
        ]);

        $this->actingAs($this->admin, 'admin');

        $this->getJson("/api/admin/users/{$landlord->id}")
            ->assertStatus(200)
            ->assertJsonStructure([
                'verification' => ['identity_verified', 'email_verified', 'latest_request'],
                'stats' => ['rating', 'review_count'],
            ])
            ->assertJsonPath('verification.identity_verified', true)
            ->assertJsonPath('stats.rating', 4.5) // (4+5)/2, pending excluded
            ->assertJsonPath('stats.review_count', 2);
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

        // A landlord bearer identity is unauthenticated on the admin session guard.
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

    public function test_scoped_admin_without_manage_users_can_still_view_roster(): void
    {
        User::factory()->tenant()->count(2)->create();
        $scoped = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => []]);
        $this->actingAs($scoped, 'admin');

        $this->getJson('/api/admin/users')->assertOk();
    }

    public function test_scoped_admin_without_manage_users_cannot_suspend(): void
    {
        $user = User::factory()->tenant()->create();
        $scoped = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => []]);
        $this->actingAs($scoped, 'admin');

        $this->postJson("/api/admin/users/{$user->id}/suspend", ['reason' => 'test'])
            ->assertStatus(403)->assertJsonPath('required_capability', 'manage_users');

        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_scoped_admin_with_manage_users_can_suspend(): void
    {
        $user = User::factory()->tenant()->create();
        $scoped = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => ['manage_users']]);
        $this->actingAs($scoped, 'admin');

        $this->postJson("/api/admin/users/{$user->id}/suspend", ['reason' => 'Suspended for a policy review.'])
            ->assertOk();

        $this->assertFalse($user->fresh()->is_active);
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
