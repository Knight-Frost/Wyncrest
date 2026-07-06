<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\User;
use App\Notifications\AdminAccessChangedNotification;
use App\Notifications\AdminInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AdminAccessControlTest
 *
 * Covers the "Manage Users & Permissions" feature: access gating, real summary
 * counts, the read-only matrix, granular capability ENFORCEMENT, admin invites,
 * capability management, and the Super Admin safety invariants.
 */
class AdminAccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $super;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->super = Admin::factory()->create(['is_super_admin' => true]);
    }

    protected function actingSuper(): void
    {
        $this->actingAs($this->super, 'admin');
    }

    protected function scopedAdmin(array $capabilities): Admin
    {
        return Admin::factory()->create([
            'is_super_admin' => false,
            'capabilities' => $capabilities,
        ]);
    }

    // ---- Access gating ---------------------------------------------------

    public function test_super_admin_can_access_summary(): void
    {
        $this->actingSuper();
        $this->getJson('/api/admin/access/summary')->assertOk();
    }

    public function test_regular_admin_with_manage_access_can_read(): void
    {
        $this->actingAs($this->scopedAdmin(['manage_access']), 'admin');

        $this->getJson('/api/admin/access/summary')->assertOk();
        $this->getJson('/api/admin/access/members')->assertOk();
        $this->getJson('/api/admin/access/roles')->assertOk();
    }

    public function test_regular_admin_without_manage_access_is_forbidden(): void
    {
        $this->actingAs($this->scopedAdmin([]), 'admin');

        $this->getJson('/api/admin/access/summary')
            ->assertStatus(403)
            ->assertJsonPath('required_capability', 'manage_access');
    }

    public function test_non_admin_cannot_access(): void
    {
        Sanctum::actingAs(User::factory()->tenant()->create(), [], 'sanctum');
        $this->getJson('/api/admin/access/summary')->assertStatus(401);
    }

    public function test_guest_is_unauthorized(): void
    {
        $this->getJson('/api/admin/access/summary')->assertStatus(401);
    }

    // ---- Summary ---------------------------------------------------------

    public function test_summary_counts_are_real_and_not_paginated(): void
    {
        // 25 tenants (> page size of 20) proves counts are not page-derived.
        User::factory()->tenant()->count(25)->create();
        User::factory()->landlord()->count(4)->create();
        $this->scopedAdmin([]);                          // regular admin
        Admin::factory()->create(['is_super_admin' => false, 'invited_at' => now(), 'invite_accepted_at' => null]);

        $this->actingSuper();
        $res = $this->getJson('/api/admin/access/summary')->assertOk();

        $res->assertJsonPath('tenants', 25)
            ->assertJsonPath('landlords', 4)
            ->assertJsonPath('super_admins', 1)
            ->assertJsonPath('scoped_admins', 2)
            ->assertJsonPath('pending_invites', 1);

        $this->assertSame(25 + 4 + 3, $res->json('members_total'));
    }

    // ---- Matrix ----------------------------------------------------------

    public function test_roles_matrix_returns_four_roles_with_locks(): void
    {
        $this->actingSuper();
        $res = $this->getJson('/api/admin/access/roles')->assertOk();

        $ids = collect($res->json('roles'))->pluck('id')->all();
        $this->assertSame(['tenant', 'landlord', 'admin', 'super_admin'], $ids);

        // Tenant/Landlord locked; Admin editable; Super Admin locked.
        $byId = collect($res->json('roles'))->keyBy('id');
        $this->assertTrue($byId['tenant']['locked']);
        $this->assertTrue($byId['landlord']['locked']);
        $this->assertFalse($byId['admin']['locked']);
        $this->assertTrue($byId['super_admin']['locked']);

        // Every admin-capability row: tenant/landlord denied+locked, super granted+locked.
        $adminCapRow = collect($res->json('groups'))
            ->firstWhere('readonly', false)['capabilities'][0];
        $this->assertSame('denied', $adminCapRow['cells']['tenant']['state']);
        $this->assertTrue($adminCapRow['cells']['tenant']['locked']);
        $this->assertSame('granted', $adminCapRow['cells']['super_admin']['state']);
        $this->assertTrue($adminCapRow['cells']['super_admin']['locked']);
        $this->assertSame('assignable', $adminCapRow['cells']['admin']['state']);
    }

    // ---- Capability ENFORCEMENT (the real teeth) -------------------------

    public function test_scoped_admin_without_capability_is_blocked_on_guarded_route(): void
    {
        // /admin/access/summary has no view/manage split — it's a hard capability gate.
        $this->actingAs($this->scopedAdmin(['manage_users']), 'admin'); // no manage_access
        $this->getJson('/api/admin/access/summary')->assertStatus(403);
    }

    public function test_scoped_admin_with_capability_is_allowed_on_guarded_route(): void
    {
        $this->actingAs($this->scopedAdmin(['manage_access']), 'admin');
        $this->getJson('/api/admin/access/summary')->assertOk();
    }

    public function test_super_admin_bypasses_capability_checks(): void
    {
        $this->actingSuper();
        $this->getJson('/api/admin/users')->assertOk();
        $this->getJson('/api/admin/audit-logs')->assertOk();
    }

    // ---- Invites ---------------------------------------------------------

    public function test_super_admin_can_invite_admin(): void
    {
        $this->actingSuper();

        $res = $this->postJson('/api/admin/access/admins', [
            'email' => 'newadmin@example.com',
            'capabilities' => ['review_verifications', 'view_audit'],
        ])->assertStatus(201);

        $this->assertDatabaseHas('admins', ['email' => 'newadmin@example.com', 'is_super_admin' => false]);
        $invited = Admin::where('email', 'newadmin@example.com')->first();
        $this->assertTrue($invited->isPendingInvite());
        $this->assertSame('invited', $res->json('admin.status'));

        Notification::assertSentTo($invited, AdminInvitationNotification::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin_invited', 'subject_id' => $invited->id]);
    }

    public function test_invite_blocks_duplicate_admin_email(): void
    {
        $this->actingSuper();
        $this->postJson('/api/admin/access/admins', ['email' => $this->super->email])
            ->assertStatus(422);
    }

    public function test_regular_admin_cannot_invite_even_with_manage_access(): void
    {
        $this->actingAs($this->scopedAdmin(['manage_access']), 'admin');
        $this->postJson('/api/admin/access/admins', ['email' => 'x@example.com'])
            ->assertStatus(403);
    }

    public function test_super_admin_can_invite_another_super_admin(): void
    {
        $this->actingSuper();
        $this->postJson('/api/admin/access/admins', [
            'email' => 'super2@example.com',
            'is_super_admin' => true,
        ])->assertStatus(201);

        $this->assertDatabaseHas('admins', ['email' => 'super2@example.com', 'is_super_admin' => true]);
    }

    public function test_invited_admin_can_accept_and_set_password(): void
    {
        $invited = Admin::factory()->create([
            'is_super_admin' => false,
            'invited_at' => now(),
            'invite_accepted_at' => null,
        ]);
        $token = Password::broker('admins')->createToken($invited);

        $this->postJson('/api/admin/accept-invite', [
            'email' => $invited->email,
            'token' => $token,
            'password' => 'Str0ngPass1',
            'password_confirmation' => 'Str0ngPass1',
        ])->assertOk();

        $this->assertNotNull($invited->fresh()->invite_accepted_at);
        $this->assertFalse($invited->fresh()->isPendingInvite());
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin_invite_accepted', 'subject_id' => $invited->id]);
    }

    public function test_accept_invite_rejects_bad_token(): void
    {
        $invited = Admin::factory()->create(['invited_at' => now(), 'invite_accepted_at' => null]);

        $this->postJson('/api/admin/accept-invite', [
            'email' => $invited->email,
            'token' => 'not-a-real-token',
            'password' => 'Str0ngPass1',
            'password_confirmation' => 'Str0ngPass1',
        ])->assertStatus(422);
    }

    public function test_super_can_revoke_pending_invite(): void
    {
        $this->actingSuper();
        $invited = Admin::factory()->create(['invited_at' => now(), 'invite_accepted_at' => null]);

        $this->postJson("/api/admin/access/admins/{$invited->id}/revoke-invite", [
            'reason' => 'Sent to the wrong address.',
        ])->assertOk();

        $this->assertDatabaseMissing('admins', ['id' => $invited->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin_invite_revoked']);
    }

    // ---- Capabilities ----------------------------------------------------

    public function test_super_can_update_regular_admin_capabilities(): void
    {
        $this->actingSuper();
        $target = $this->scopedAdmin(['manage_users']);

        $res = $this->patchJson("/api/admin/access/admins/{$target->id}/capabilities", [
            'capabilities' => ['review_verifications', 'view_audit'],
            'reason' => 'Reassigned to the verification queue.',
        ])->assertOk();

        $this->assertEqualsCanonicalizing(
            ['review_verifications', 'view_audit'],
            $target->fresh()->capabilities,
        );
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin_capabilities_updated', 'subject_id' => $target->id]);
        Notification::assertSentTo($target, AdminAccessChangedNotification::class);
        $this->assertContains('review_verifications', $res->json('admin.capabilities'));
    }

    public function test_capability_update_requires_a_reason(): void
    {
        $this->actingSuper();
        $target = $this->scopedAdmin([]);

        $this->patchJson("/api/admin/access/admins/{$target->id}/capabilities", [
            'capabilities' => ['view_audit'],
        ])->assertStatus(422);
    }

    public function test_cannot_scope_capabilities_of_a_super_admin(): void
    {
        $this->actingSuper();
        $otherSuper = Admin::factory()->create(['is_super_admin' => true]);

        $this->patchJson("/api/admin/access/admins/{$otherSuper->id}/capabilities", [
            'capabilities' => ['view_audit'],
            'reason' => 'Trying to scope a super admin.',
        ])->assertStatus(422);
    }

    public function test_regular_admin_cannot_resend_invite(): void
    {
        // The no-FormRequest mutations (resend/activate) are gated by an inline
        // super check — prove a manage_access regular admin still gets 403.
        $this->actingAs($this->scopedAdmin(['manage_access']), 'admin');
        $pending = Admin::factory()->create(['invited_at' => now(), 'invite_accepted_at' => null]);

        $this->postJson("/api/admin/access/admins/{$pending->id}/resend-invite")
            ->assertStatus(403);
    }

    public function test_regular_admin_cannot_activate_admin(): void
    {
        $this->actingAs($this->scopedAdmin(['manage_access']), 'admin');
        $deactivated = Admin::factory()->create(['is_super_admin' => false, 'is_active' => false]);

        $this->postJson("/api/admin/access/admins/{$deactivated->id}/activate")
            ->assertStatus(403);
    }

    public function test_regular_admin_cannot_update_capabilities(): void
    {
        $this->actingAs($this->scopedAdmin(['manage_access']), 'admin');
        $target = $this->scopedAdmin([]);

        $this->patchJson("/api/admin/access/admins/{$target->id}/capabilities", [
            'capabilities' => ['view_audit'],
            'reason' => 'Not allowed for a regular admin.',
        ])->assertStatus(403);
    }

    public function test_granting_manage_access_lets_a_regular_admin_open_the_page(): void
    {
        $target = $this->scopedAdmin([]); // no capabilities → no page access

        // Before: the regular admin is locked out of the read endpoints.
        $this->actingAs($target, 'admin');
        $this->getJson('/api/admin/access/summary')->assertStatus(403);

        // A super admin grants manage_access.
        $this->actingSuper();
        $this->patchJson("/api/admin/access/admins/{$target->id}/capabilities", [
            'capabilities' => ['manage_access'],
            'reason' => 'Granting read access to the access page.',
        ])->assertOk();

        // After: the same regular admin can now open the page (read endpoints).
        $this->actingAs($target->fresh(), 'admin');
        $this->getJson('/api/admin/access/summary')->assertOk();
        $this->getJson('/api/admin/access/admins')->assertOk();
    }

    public function test_revoking_manage_access_removes_page_access(): void
    {
        $target = $this->scopedAdmin(['manage_access']);

        $this->actingAs($target, 'admin');
        $this->getJson('/api/admin/access/summary')->assertOk();

        // Super admin revokes manage_access (empty capability set).
        $this->actingSuper();
        $this->patchJson("/api/admin/access/admins/{$target->id}/capabilities", [
            'capabilities' => [],
            'reason' => 'Revoking access-page permission.',
        ])->assertOk();

        $this->actingAs($target->fresh(), 'admin');
        $this->getJson('/api/admin/access/summary')->assertStatus(403);
    }

    public function test_regular_admin_with_manage_access_cannot_promote_super(): void
    {
        $this->actingAs($this->scopedAdmin(['manage_access']), 'admin');
        $target = $this->scopedAdmin([]);

        $this->postJson("/api/admin/access/admins/{$target->id}/promote-super", [
            'reason' => 'A regular admin should never be able to do this.',
        ])->assertStatus(403);

        $this->assertFalse($target->fresh()->is_super_admin);
    }

    public function test_invalid_capability_is_rejected(): void
    {
        $this->actingSuper();
        $target = $this->scopedAdmin([]);

        $this->patchJson("/api/admin/access/admins/{$target->id}/capabilities", [
            'capabilities' => ['make_me_god'],
            'reason' => 'Attempting an unknown capability.',
        ])->assertStatus(422);
    }

    // ---- Super Admin promotion / safety ----------------------------------

    public function test_super_can_promote_a_regular_admin(): void
    {
        $this->actingSuper();
        $target = $this->scopedAdmin(['manage_users']);

        $this->postJson("/api/admin/access/admins/{$target->id}/promote-super", [
            'reason' => 'Trusted operator, promoting to super.',
        ])->assertOk();

        $this->assertTrue($target->fresh()->is_super_admin);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin_promoted_super', 'subject_id' => $target->id]);
    }

    public function test_super_can_demote_another_super_when_multiple_exist(): void
    {
        $this->actingSuper();
        $other = Admin::factory()->create(['is_super_admin' => true]);

        $this->postJson("/api/admin/access/admins/{$other->id}/demote-super", [
            'reason' => 'Reducing to a scoped admin role.',
            'capabilities' => ['view_audit'],
        ])->assertOk();

        $this->assertFalse($other->fresh()->is_super_admin);
        $this->assertSame(['view_audit'], $other->fresh()->capabilities);
    }

    public function test_cannot_demote_the_last_active_super_admin(): void
    {
        // Only one super (the actor) — demoting self must be blocked.
        $this->actingSuper();

        $this->postJson("/api/admin/access/admins/{$this->super->id}/demote-super", [
            'reason' => 'Trying to demote the only super admin.',
        ])->assertStatus(422);

        $this->assertTrue($this->super->fresh()->is_super_admin);
    }

    public function test_super_can_self_demote_when_another_super_exists(): void
    {
        Admin::factory()->create(['is_super_admin' => true]); // a second safety net
        $this->actingSuper();

        $this->postJson("/api/admin/access/admins/{$this->super->id}/demote-super", [
            'reason' => 'Stepping down; another super remains.',
        ])->assertOk();

        $this->assertFalse($this->super->fresh()->is_super_admin);
    }

    public function test_cannot_deactivate_own_account(): void
    {
        $this->actingSuper();

        $this->postJson("/api/admin/access/admins/{$this->super->id}/deactivate", [
            'reason' => 'Trying to deactivate myself.',
        ])->assertStatus(422);

        $this->assertTrue($this->super->fresh()->is_active);
    }

    public function test_cannot_deactivate_the_last_active_super_admin(): void
    {
        // A second super acts; deactivating the only OTHER active super is fine
        // when >1 remain, but not when it would leave zero. Here we leave the
        // acting super only, so deactivating the sole remaining super is blocked.
        $actor = Admin::factory()->create(['is_super_admin' => true]);
        // Deactivate the original super first (allowed — two supers exist).
        $this->actingAs($actor, 'admin');
        $this->postJson("/api/admin/access/admins/{$this->super->id}/deactivate", [
            'reason' => 'Deactivating one of two supers.',
        ])->assertOk();

        // Now $actor is the only active super. It cannot deactivate itself.
        $this->postJson("/api/admin/access/admins/{$actor->id}/deactivate", [
            'reason' => 'Trying to remove the last active super.',
        ])->assertStatus(422);
    }

    public function test_regular_admin_cannot_promote_themselves(): void
    {
        $self = $this->scopedAdmin(['manage_access']);
        $this->actingAs($self, 'admin');

        $this->postJson("/api/admin/access/admins/{$self->id}/promote-super", [
            'reason' => 'Trying to self-promote.',
        ])->assertStatus(403);

        $this->assertFalse($self->fresh()->is_super_admin);
    }

    public function test_deactivate_then_reactivate_admin_is_audited_and_notified(): void
    {
        $this->actingSuper();
        $target = $this->scopedAdmin(['manage_users']);

        $this->postJson("/api/admin/access/admins/{$target->id}/deactivate", [
            'reason' => 'Temporary offboarding.',
        ])->assertOk();
        $this->assertFalse($target->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin_deactivated', 'subject_id' => $target->id]);

        $this->postJson("/api/admin/access/admins/{$target->id}/activate")->assertOk();
        $this->assertTrue($target->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin_reactivated', 'subject_id' => $target->id]);

        Notification::assertSentTo($target, AdminAccessChangedNotification::class);
    }

    // ---- Lifecycle (users) is audited ------------------------------------

    public function test_user_suspend_via_manage_users_creates_audit_log(): void
    {
        $this->actingSuper();
        $user = User::factory()->tenant()->create();

        $this->postJson("/api/admin/users/{$user->id}/suspend", [
            'reason' => 'Suspended for a policy review (test).',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'account_suspended', 'subject_id' => $user->id]);
    }

    // ---- /user exposes capabilities (frontend nav gating depends on this) ----

    public function test_authenticated_user_endpoint_exposes_super_admin_capabilities(): void
    {
        $this->actingSuper();

        $res = $this->getJson('/api/admin/me')->assertOk();

        $this->assertEqualsCanonicalizing(
            \App\Enums\AdminCapability::values(),
            $res->json('user.capabilities'),
        );
    }

    public function test_authenticated_user_endpoint_exposes_only_granted_scoped_admin_capabilities(): void
    {
        $granted = $this->scopedAdmin(['manage_users', 'view_audit']);
        $this->actingAs($granted, 'admin');

        $res = $this->getJson('/api/admin/me')->assertOk();

        $this->assertEqualsCanonicalizing(
            ['manage_users', 'view_audit'],
            $res->json('user.capabilities'),
        );
        $this->assertNotContains('manage_access', $res->json('user.capabilities'));
    }

    // ---- Reviewer-scoped admin matrix (the seeded "Efua Reviewer" profile) ----

    /**
     * The exact capability set the dev seeder grants the scoped reviewer.
     *
     * @var list<string>
     */
    private const REVIEWER_CAPS = ['review_verifications', 'moderate_listings', 'moderate_reviews', 'view_audit'];

    public function test_reviewer_scoped_admin_can_reach_only_granted_areas(): void
    {
        $this->actingAs($this->scopedAdmin(self::REVIEWER_CAPS), 'admin');

        // Granted capabilities → allowed.
        $this->getJson('/api/admin/verifications')->assertOk();      // review_verifications
        $this->getJson('/api/admin/listings/review')->assertOk();    // moderate_listings
        $this->getJson('/api/admin/reviews')->assertOk();            // moderate_reviews
        $this->getJson('/api/admin/audit-logs')->assertOk();         // view_audit
    }

    public function test_reviewer_scoped_admin_can_view_but_not_manage_financial_and_user_areas(): void
    {
        $this->actingAs($this->scopedAdmin(self::REVIEWER_CAPS), 'admin');

        // Read access to Users/Contracts/Ledger is a baseline admin privilege —
        // no capability required just to view.
        $this->getJson('/api/admin/ledger')->assertOk();
        $this->getJson('/api/admin/contracts')->assertOk();
        $this->getJson('/api/admin/users')->assertOk();

        // But mutating those areas still requires the matching capability,
        // which the reviewer profile was never granted.
        $tenant = User::factory()->tenant()->create();
        $this->postJson("/api/admin/users/{$tenant->id}/suspend", ['reason' => 'test'])
            ->assertStatus(403)->assertJsonPath('required_capability', 'manage_users');

        // Admin team management remains fully gated (view included) — it is
        // not a "view vs manage" split, just a hard capability requirement.
        $this->getJson('/api/admin/access/summary')
            ->assertStatus(403)->assertJsonPath('required_capability', 'manage_access');
    }

    public function test_super_admin_can_reach_every_capability_gated_area(): void
    {
        $this->actingSuper();

        // Super admin passes every gate without any capability rows assigned.
        foreach ([
            '/api/admin/verifications',
            '/api/admin/listings/review',
            '/api/admin/reviews',
            '/api/admin/audit-logs',
            '/api/admin/ledger',
            '/api/admin/contracts',
            '/api/admin/users',
            '/api/admin/access/summary',
        ] as $route) {
            $this->getJson($route)->assertOk();
        }
    }
}
