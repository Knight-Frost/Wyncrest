<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AdminNotificationAccessTest
 *
 * The `notifications` / `notification_preferences` tables are keyed by user_id
 * (tenants/landlords). Admins authenticate via a separate model and have no
 * per-user notification stream. These tests prove the endpoints:
 *  - no longer 500 for admins (the original bug: User-typed service args),
 *  - return truthful EMPTY results for admins,
 *  - never leak a real user's data via an id collision (IDOR),
 *  - still work for a normal user (happy-path regression).
 */
class AdminNotificationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_notifications_index_returns_empty_not_error(): void
    {
        // Tenant id and admin id both start at 1 (separate tables) — the
        // collision that a naive where('user_id', $admin->id) would leak.
        $tenant = User::factory()->tenant()->create();
        Notification::factory()->count(3)->create(['user_id' => $tenant->id, 'read_at' => null]);

        $admin = Admin::factory()->create();
        $this->assertSame($tenant->id, $admin->id, 'precondition: ids collide');

        $this->actingAs($admin, 'admin');

        $res = $this->getJson('/api/admin/notifications');
        $res->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('data', []);
    }

    public function test_admin_unread_count_is_zero(): void
    {
        $tenant = User::factory()->tenant()->create();
        Notification::factory()->count(2)->create(['user_id' => $tenant->id, 'read_at' => null]);

        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->getJson('/api/admin/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);
    }

    public function test_admin_unread_list_is_empty(): void
    {
        $tenant = User::factory()->tenant()->create();
        Notification::factory()->create(['user_id' => $tenant->id, 'read_at' => null]);

        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->getJson('/api/admin/notifications/unread')
            ->assertOk()
            ->assertJson([]);
    }

    public function test_admin_mark_all_read_is_noop(): void
    {
        $tenant = User::factory()->tenant()->create();
        Notification::factory()->count(2)->create(['user_id' => $tenant->id, 'read_at' => null]);

        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->postJson('/api/admin/notifications/mark-all-read')
            ->assertOk()
            ->assertJsonPath('count', 0);

        // The tenant's notifications must remain unread.
        $this->assertSame(2, Notification::whereNull('read_at')->count());
    }

    public function test_admin_preferences_index_returns_defaults(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->getJson('/api/admin/notification-preferences')
            ->assertOk()
            ->assertJsonPath('rent_generated.email', true)
            ->assertJsonPath('rent_generated.sms', false);
    }

    public function test_admin_cannot_write_preferences(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->putJson('/api/admin/notification-preferences', [
            'rent_generated' => ['email' => false, 'sms' => true],
        ])->assertForbidden();

        $this->assertDatabaseCount('notification_preferences', 0);
    }

    public function test_user_still_receives_their_notifications(): void
    {
        $tenant = User::factory()->tenant()->create();
        Notification::factory()->count(3)->create(['user_id' => $tenant->id, 'read_at' => null]);

        Sanctum::actingAs($tenant, [], 'sanctum');

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('total', 3);

        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 3);
    }
}
