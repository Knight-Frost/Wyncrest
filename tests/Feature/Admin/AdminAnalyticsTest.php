<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Listing;
use App\Models\MaintenanceRequest;
use App\Models\Notification;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AdminAnalyticsTest
 *
 * Covers the scoped "Admin Analytics" page (GET /admin/analytics/admin-summary):
 * access gating, per-capability section scoping, super-admin bypass, real
 * seeded-data traceability, and the CSV export.
 */
class AdminAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function scopedAdmin(array $capabilities): Admin
    {
        return Admin::factory()->create([
            'is_super_admin' => false,
            'capabilities' => $capabilities,
        ]);
    }

    // ---- Access gating ------------------------------------------------------

    public function test_any_admin_can_open_the_page_regardless_of_capabilities(): void
    {
        $this->actingAs($this->scopedAdmin([]), 'admin');

        $this->getJson('/api/admin/analytics/admin-summary')->assertOk();
    }

    public function test_guest_is_unauthorized(): void
    {
        $this->getJson('/api/admin/analytics/admin-summary')->assertStatus(401);
    }

    public function test_non_admin_cannot_access(): void
    {
        Sanctum::actingAs(User::factory()->tenant()->create(), [], 'sanctum');

        $this->getJson('/api/admin/analytics/admin-summary')->assertStatus(401);
    }

    // ---- Capability scoping --------------------------------------------------

    public function test_scoped_admin_only_sees_modules_they_hold_capabilities_for(): void
    {
        $admin = $this->scopedAdmin(['moderate_listings', 'review_verifications']);
        $this->actingAs($admin, 'admin');

        $response = $this->getJson('/api/admin/analytics/admin-summary')->assertOk();
        $analytics = $response->json('analytics');

        $this->assertContains('Listings', $analytics['scope']['permitted_modules']);
        $this->assertContains('Verifications', $analytics['scope']['permitted_modules']);
        // Maintenance is a baseline privilege — always present.
        $this->assertContains('Maintenance', $analytics['scope']['permitted_modules']);
        $this->assertNotContains('Ledger', $analytics['scope']['permitted_modules']);
        $this->assertNotContains('Notifications', $analytics['scope']['permitted_modules']);

        $this->assertArrayHasKey('listings', $analytics['modules']);
        $this->assertArrayHasKey('verifications', $analytics['modules']);
        $this->assertArrayHasKey('maintenance', $analytics['modules']);
        $this->assertArrayNotHasKey('ledger', $analytics['modules']);
        $this->assertArrayNotHasKey('notifications', $analytics['modules']);

        $this->assertContains('Manage ledger', $analytics['scope']['restricted_modules']);
        $this->assertContains('View audit log', $analytics['scope']['restricted_modules']);
    }

    public function test_admin_with_no_capabilities_only_sees_maintenance(): void
    {
        $admin = $this->scopedAdmin([]);
        $this->actingAs($admin, 'admin');

        $analytics = $this->getJson('/api/admin/analytics/admin-summary')->assertOk()->json('analytics');

        $this->assertEquals(['Maintenance'], $analytics['scope']['permitted_modules']);
        $this->assertArrayHasKey('maintenance', $analytics['modules']);
        $this->assertCount(4, $analytics['scope']['restricted_modules']);
    }

    public function test_super_admin_sees_every_module_with_no_restrictions(): void
    {
        $admin = Admin::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin, 'admin');

        $analytics = $this->getJson('/api/admin/analytics/admin-summary')->assertOk()->json('analytics');

        foreach (['Maintenance', 'Listings', 'Verifications', 'Ledger', 'Notifications'] as $module) {
            $this->assertContains($module, $analytics['scope']['permitted_modules']);
        }
        $this->assertSame([], $analytics['scope']['restricted_modules']);
        $this->assertTrue($analytics['admin']['is_super_admin']);
    }

    // ---- Traceability to real seeded records ----------------------------------

    public function test_listing_counts_are_real_and_traceable(): void
    {
        Listing::factory()->pendingReview()->count(3)->create();
        Listing::factory()->active()->count(2)->create();

        $admin = $this->scopedAdmin(['moderate_listings']);
        $this->actingAs($admin, 'admin');

        $analytics = $this->getJson('/api/admin/analytics/admin-summary')->assertOk()->json('analytics');

        $this->assertSame(3, $analytics['modules']['listings']['counts']['pending']);
        $this->assertCount(3, $analytics['modules']['listings']['queue_preview']);
        $this->assertNotEmpty(
            collect($analytics['attention'])->firstWhere('area', 'Listings'),
            'Pending listings should surface an attention item.'
        );
    }

    public function test_verification_counts_are_real_and_traceable(): void
    {
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        VerificationRequest::create(['user_id' => $tenant->id, 'status' => 'pending', 'submitted_at' => now()]);
        VerificationRequest::create(['user_id' => $landlord->id, 'status' => 'pending', 'submitted_at' => now()->subHours(80)]);

        $admin = $this->scopedAdmin(['review_verifications']);
        $this->actingAs($admin, 'admin');

        $analytics = $this->getJson('/api/admin/analytics/admin-summary')->assertOk()->json('analytics');

        $this->assertSame(2, $analytics['modules']['verifications']['summary']['pending']);
        $this->assertSame(1, $analytics['modules']['verifications']['timing']['overdue_count']);
        $this->assertNotEmpty(collect($analytics['attention'])->firstWhere('title', 'Verification backlog'));
    }

    public function test_maintenance_urgent_case_is_always_visible_and_flagged_critical(): void
    {
        MaintenanceRequest::factory()->create(['priority' => 'urgent', 'status' => 'open']);

        // No capabilities at all — maintenance still shows, per its ungated route.
        $admin = $this->scopedAdmin([]);
        $this->actingAs($admin, 'admin');

        $analytics = $this->getJson('/api/admin/analytics/admin-summary')->assertOk()->json('analytics');

        $this->assertGreaterThanOrEqual(1, $analytics['modules']['maintenance']['summary']['urgent']);
        $critical = collect($analytics['attention'])->firstWhere('title', 'Urgent maintenance open');
        $this->assertNotNull($critical);
        $this->assertSame('critical', $critical['severity']);
    }

    public function test_notifications_module_requires_view_audit_capability(): void
    {
        Notification::factory()->failed()->create();

        $withoutAudit = $this->scopedAdmin([]);
        $this->actingAs($withoutAudit, 'admin');
        $analytics = $this->getJson('/api/admin/analytics/admin-summary')->assertOk()->json('analytics');
        $this->assertArrayNotHasKey('notifications', $analytics['modules']);

        $withAudit = $this->scopedAdmin(['view_audit']);
        $this->actingAs($withAudit, 'admin');
        $analytics = $this->getJson('/api/admin/analytics/admin-summary')->assertOk()->json('analytics');
        $this->assertGreaterThanOrEqual(1, $analytics['modules']['notifications']['failed_total']);
    }

    // ---- My activity / my decisions --------------------------------------------

    public function test_my_activity_is_scoped_to_the_signed_in_admin_only(): void
    {
        $me = $this->scopedAdmin(['moderate_listings']);
        $otherAdmin = Admin::factory()->create(['is_super_admin' => true]);

        AuditLog::factory()->forActor($me)->create(['action' => 'listing_published', 'severity' => 'info']);
        AuditLog::factory()->forActor($otherAdmin)->create(['action' => 'listing_rejected', 'severity' => 'info']);

        $this->actingAs($me, 'admin');
        $analytics = $this->getJson('/api/admin/analytics/admin-summary')->assertOk()->json('analytics');

        $this->assertSame(1, $analytics['me']['actions_period']);
        $this->assertSame(1, $analytics['me']['decisions_period']);
        $this->assertSame(1, $analytics['modules']['listings']['my_decisions']['approved']);
        $this->assertSame(0, $analytics['modules']['listings']['my_decisions']['rejected']);
    }

    public function test_my_activity_detail_route_is_null_without_view_audit(): void
    {
        $admin = $this->scopedAdmin(['moderate_listings']);
        AuditLog::factory()->forActor($admin)->create(['action' => 'listing_published', 'severity' => 'info']);

        $this->actingAs($admin, 'admin');
        $analytics = $this->getJson('/api/admin/analytics/admin-summary')->assertOk()->json('analytics');

        $this->assertNull($analytics['me']['recent_activity'][0]['detail_route']);
    }

    // ---- Date range scoping -----------------------------------------------------

    public function test_date_range_correctly_scopes_my_decisions(): void
    {
        $admin = $this->scopedAdmin(['moderate_listings']);

        AuditLog::factory()->forActor($admin)->create([
            'action' => 'listing_published',
            'created_at' => now()->subDays(3),
        ]);
        AuditLog::factory()->forActor($admin)->create([
            'action' => 'listing_published',
            'created_at' => now()->subDays(60),
        ]);

        $this->actingAs($admin, 'admin');

        $sevenDay = $this->getJson('/api/admin/analytics/admin-summary?range=7d')->assertOk()->json('analytics');
        $this->assertSame(1, $sevenDay['modules']['listings']['my_decisions']['approved']);

        $ninetyDay = $this->getJson('/api/admin/analytics/admin-summary?range=90d')->assertOk()->json('analytics');
        $this->assertSame(2, $ninetyDay['modules']['listings']['my_decisions']['approved']);
    }

    // ---- Attention item age/action enrichment ----------------------------------

    public function test_attention_items_carry_age_and_suggested_action(): void
    {
        MaintenanceRequest::factory()->create(['priority' => 'urgent', 'status' => 'open', 'submitted_at' => now()->subHours(30)]);

        $admin = $this->scopedAdmin([]);
        $this->actingAs($admin, 'admin');

        $analytics = $this->getJson('/api/admin/analytics/admin-summary')->assertOk()->json('analytics');
        $critical = collect($analytics['attention'])->firstWhere('title', 'Urgent maintenance open');

        $this->assertNotNull($critical['age_hours']);
        $this->assertIsString($critical['age']);
        $this->assertSame('Escalate', $critical['action']);
    }

    // ---- My performance (decision trend / outcomes / reasons / timing) --------

    public function test_my_performance_aggregates_real_listing_and_verification_decisions(): void
    {
        $admin = $this->scopedAdmin(['moderate_listings', 'review_verifications']);

        $rejectedListing = Listing::factory()->create();
        AuditLog::factory()->forActor($admin)->create([
            'action' => 'listing_rejected',
            'subject_type' => Listing::class,
            'subject_id' => $rejectedListing->id,
            'metadata' => ['reason' => 'Photos below standard'],
            'severity' => 'warning',
        ]);
        AuditLog::factory()->forActor($admin)->create([
            'action' => 'listing_published',
            'subject_type' => Listing::class,
            'subject_id' => Listing::factory()->create()->id,
            'severity' => 'info',
        ]);

        // Mirrors VerificationService::reject(), which always writes both the
        // model row (decision_reason, reviewed_by_admin_id) AND a matching
        // AuditLog entry together — outcome_totals/decision_trend are read
        // from AuditLog, while reasons/timing are read from the model.
        $tenant = User::factory()->tenant()->create();
        $verification = VerificationRequest::create([
            'user_id' => $tenant->id,
            'status' => 'rejected',
            'submitted_at' => now()->subHours(20),
            'reviewed_at' => now(),
            'reviewed_by_admin_id' => $admin->id,
            'decision_reason' => 'Photos below standard',
        ]);
        AuditLog::factory()->forActor($admin)->create([
            'action' => 'verification_rejected',
            'subject_type' => User::class,
            'subject_id' => $tenant->id,
            'metadata' => ['reason' => 'Photos below standard'],
            'severity' => 'warning',
        ]);

        $this->actingAs($admin, 'admin');
        $me = $this->getJson('/api/admin/analytics/admin-summary')->assertOk()->json('analytics.me');

        $this->assertSame(1, $me['outcome_totals']['approved']);
        $this->assertSame(2, $me['outcome_totals']['rejected']); // 1 listing + 1 verification
        $this->assertSame(0, $me['outcome_totals']['sent_back']);
        $this->assertNotEmpty($me['decision_trend']);

        $topReason = collect($me['top_reasons'])->firstWhere('reason', 'Photos below standard');
        $this->assertNotNull($topReason);
        $this->assertSame(2, $topReason['count']); // merged from both listing + verification sources

        // Submitted 20h before decision — exact, since the fixture controls both timestamps.
        $this->assertEqualsWithDelta(20.0, $me['avg_decision_hours']['verifications'], 0.1);
        // Listing "submitted_at" is the record's created_at; the AuditLog factory's own
        // created_at is randomized, so just assert a real (non-null) figure was computed.
        $this->assertIsNumeric($me['avg_decision_hours']['listings']);
        $this->assertGreaterThanOrEqual(0, $me['avg_decision_hours']['listings']);
    }

    // ---- New module breakdowns (mockup-parity pass) ----------------------------

    public function test_listings_module_exposes_platform_wide_reasons(): void
    {
        $admin = $this->scopedAdmin(['moderate_listings']);
        $otherAdmin = Admin::factory()->create(['is_super_admin' => true]);
        $listing = Listing::factory()->create();

        // A DIFFERENT admin's rejection reason must still count — this is
        // platform-wide, not "my decisions".
        AuditLog::factory()->forActor($otherAdmin)->create([
            'action' => 'listing_rejected',
            'subject_type' => Listing::class,
            'subject_id' => $listing->id,
            'metadata' => ['reason' => 'Missing ownership proof'],
        ]);

        $this->actingAs($admin, 'admin');
        $reasons = $this->getJson('/api/admin/analytics/admin-summary')->assertOk()->json('analytics.modules.listings.top_reasons');

        $this->assertNotEmpty(collect($reasons)->firstWhere('reason', 'Missing ownership proof'));
    }

    public function test_maintenance_module_exposes_by_status_breakdown(): void
    {
        MaintenanceRequest::factory()->create(['priority' => 'medium', 'status' => 'in_progress']);
        MaintenanceRequest::factory()->create(['priority' => 'medium', 'status' => 'waiting']);

        $admin = $this->scopedAdmin([]);
        $this->actingAs($admin, 'admin');

        $byStatus = $this->getJson('/api/admin/analytics/admin-summary')->assertOk()->json('analytics.modules.maintenance.by_status');

        $this->assertSame(1, $byStatus['in_progress']);
        $this->assertSame(1, $byStatus['waiting']);
    }

    public function test_notifications_module_exposes_channel_breakdown(): void
    {
        Notification::factory()->create(['delivered_at' => now()]);
        Notification::factory()->failed()->create();

        $admin = $this->scopedAdmin(['view_audit']);
        $this->actingAs($admin, 'admin');

        $channel = collect($this->getJson('/api/admin/analytics/admin-summary')->assertOk()->json('analytics.modules.notifications.channel'));

        $inApp = $channel->firstWhere('channel', 'In-app');
        $email = $channel->firstWhere('channel', 'Email');
        $this->assertSame(2, $inApp['sent']);
        $this->assertGreaterThanOrEqual(1, $email['sent']);
    }

    /**
     * Regression: $failedBase (the failed_total/email_failed/sms_failed stat
     * cards) must respect the selected date range exactly like the per-channel
     * chart, or the same tab shows two different "email failed" numbers.
     */
    public function test_notifications_module_range_scopes_failed_counts_to_match_the_channel_chart(): void
    {
        Notification::factory()->failed()->create(['created_at' => now()->subDays(20)]);
        Notification::factory()->failed()->create(['created_at' => now()->subDays(1)]);

        $admin = $this->scopedAdmin(['view_audit']);
        $this->actingAs($admin, 'admin');

        $analytics = $this->getJson('/api/admin/analytics/admin-summary?range=7d')->assertOk()->json('analytics');

        $notifications = $analytics['modules']['notifications'];
        $this->assertSame(1, $notifications['failed_total']);
        $this->assertSame(1, $notifications['email_failed']);

        $emailChannel = collect($notifications['channel'])->firstWhere('channel', 'Email');
        $this->assertSame($notifications['email_failed'], $emailChannel['failed']);
    }

    public function test_ledger_module_exposes_period_collected_and_charged(): void
    {
        $admin = $this->scopedAdmin(['manage_ledger']);
        $this->actingAs($admin, 'admin');

        $ledger = $this->getJson('/api/admin/analytics/admin-summary')->assertOk()->json('analytics.modules.ledger');

        $this->assertArrayHasKey('collected_cents', $ledger);
        $this->assertArrayHasKey('charged_cents', $ledger);
    }

    // ---- Empty state --------------------------------------------------------

    public function test_empty_platform_returns_no_attention_items(): void
    {
        $admin = Admin::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin, 'admin');

        $analytics = $this->getJson('/api/admin/analytics/admin-summary')->assertOk()->json('analytics');

        $this->assertSame([], $analytics['attention']);
    }

    // ---- Export ---------------------------------------------------------------

    public function test_export_streams_csv_and_is_audit_logged(): void
    {
        Listing::factory()->pendingReview()->create();
        $admin = $this->scopedAdmin(['moderate_listings']);
        $this->actingAs($admin, 'admin');

        $response = $this->get('/api/admin/analytics/admin-summary/export');
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=utf-8');
        $this->assertStringContainsString('My decisions this period', $response->streamedContent());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin_analytics_exported',
            'actor_type' => Admin::class,
            'actor_id' => $admin->id,
        ]);
    }
}
