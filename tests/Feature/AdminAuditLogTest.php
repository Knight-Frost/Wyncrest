<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AdminAuditLogTest
 *
 * Covers the four Audit & Activity Center endpoints:
 *   GET /api/admin/audit-logs
 *   GET /api/admin/audit-logs/summary
 *   GET /api/admin/audit-logs/export
 *   GET /api/admin/audit-logs/{auditLog}
 */
class AdminAuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::factory()->create(['is_super_admin' => true]);
    }

    // =========================================================================
    // Authorization — tenants, landlords, and guests must NOT access these
    // =========================================================================

    public function test_unauthenticated_gets_401_on_index(): void
    {
        $this->getJson('/api/admin/audit-logs')->assertStatus(401);
    }

    public function test_tenant_gets_401_on_index(): void
    {
        $tenant = User::factory()->tenant()->create();
        Sanctum::actingAs($tenant, [], 'sanctum');

        $this->getJson('/api/admin/audit-logs')->assertStatus(401);
    }

    public function test_landlord_gets_401_on_index(): void
    {
        $landlord = User::factory()->landlord()->create();
        Sanctum::actingAs($landlord, [], 'sanctum');

        $this->getJson('/api/admin/audit-logs')->assertStatus(401);
    }

    public function test_tenant_gets_401_on_summary(): void
    {
        $tenant = User::factory()->tenant()->create();
        Sanctum::actingAs($tenant, [], 'sanctum');

        $this->getJson('/api/admin/audit-logs/summary')->assertStatus(401);
    }

    public function test_tenant_gets_401_on_export(): void
    {
        $tenant = User::factory()->tenant()->create();
        Sanctum::actingAs($tenant, [], 'sanctum');

        $this->getJson('/api/admin/audit-logs/export')->assertStatus(401);
    }

    public function test_tenant_gets_401_on_show(): void
    {
        $tenant = User::factory()->tenant()->create();
        $log = AuditLog::factory()->create();
        Sanctum::actingAs($tenant, [], 'sanctum');

        $this->getJson("/api/admin/audit-logs/{$log->id}")->assertStatus(401);
    }

    public function test_unauthenticated_gets_401_on_show(): void
    {
        $log = AuditLog::factory()->create();

        $this->getJson("/api/admin/audit-logs/{$log->id}")->assertStatus(401);
    }

    // =========================================================================
    // Index — pagination shape and derived fields
    // =========================================================================

    public function test_index_returns_flat_paginated_shape(): void
    {
        AuditLog::factory()->count(5)->create();
        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/audit-logs');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'current_page',
                'last_page',
                'per_page',
                'total',
            ]);

        $this->assertIsArray($response->json('data'));
        $this->assertSame(5, $response->json('total'));
    }

    public function test_index_includes_derived_fields(): void
    {
        // account_suspended + critical severity => area=Users, status.key=needs_review
        AuditLog::factory()->create([
            'action' => 'account_suspended',
            'severity' => 'critical',
        ]);

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/audit-logs');
        $response->assertStatus(200);

        $item = $response->json('data.0');

        $this->assertSame('Users', $item['area']);
        $this->assertSame('Account suspended', $item['action_label']);
        $this->assertSame('needs_review', $item['status']['key']);
        $this->assertSame('Needs review', $item['status']['label']);
        $this->assertSame('critical', $item['severity']);
        $this->assertArrayHasKey('actor', $item);
        $this->assertArrayHasKey('summary', $item);
        $this->assertArrayHasKey('ip_address', $item);
        $this->assertArrayHasKey('created_at', $item);
    }

    public function test_index_actor_shape_for_admin_actor(): void
    {
        AuditLog::factory()->forActor($this->admin)->create([
            'action' => 'admin_login',
            'severity' => 'info',
        ]);

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/audit-logs');
        $response->assertStatus(200);

        $actor = $response->json('data.0.actor');

        $this->assertSame('admin', $actor['role']);
        $this->assertSame($this->admin->name, $actor['name']);
        $this->assertSame($this->admin->email, $actor['email']);
    }

    public function test_index_actor_shape_for_null_actor(): void
    {
        AuditLog::factory()->create([
            'actor_type' => null,
            'actor_id' => null,
        ]);

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/audit-logs');
        $actor = $response->json('data.0.actor');

        $this->assertSame('system', $actor['role']);
        $this->assertSame('System', $actor['name']);
        $this->assertNull($actor['email']);
        $this->assertNull($actor['id']);
    }

    // =========================================================================
    // Filter tests
    // =========================================================================

    public function test_filter_by_severity(): void
    {
        AuditLog::factory()->count(3)->create(['severity' => 'critical']);
        AuditLog::factory()->count(5)->create(['severity' => 'info']);
        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/audit-logs?severity=critical');
        $response->assertStatus(200);

        $this->assertSame(3, $response->json('total'));
        foreach ($response->json('data') as $item) {
            $this->assertSame('critical', $item['severity']);
        }
    }

    public function test_filter_by_area_returns_only_matching_actions(): void
    {
        // Ledger actions
        AuditLog::factory()->count(4)->create(['action' => 'payment_recorded']);
        // Non-ledger actions
        AuditLog::factory()->count(3)->create(['action' => 'user_created']);
        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/audit-logs?area=Ledger');
        $response->assertStatus(200);

        // Only ledger rows returned
        foreach ($response->json('data') as $item) {
            $this->assertSame('Ledger', $item['area']);
        }

        $this->assertSame(4, $response->json('total'));
    }

    public function test_filter_by_unknown_area_returns_empty(): void
    {
        AuditLog::factory()->count(3)->create();
        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/audit-logs?area=NonExistentArea');
        $response->assertStatus(200);

        $this->assertSame(0, $response->json('total'));
    }

    public function test_filter_by_actor_role_admin(): void
    {
        $otherAdmin = Admin::factory()->create();
        AuditLog::factory()->forActor($otherAdmin)->count(3)->create();

        $tenant = User::factory()->tenant()->create();
        AuditLog::factory()->forActor($tenant)->count(2)->create();

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/audit-logs?actor_role=admin');
        $response->assertStatus(200);

        $this->assertSame(3, $response->json('total'));
        foreach ($response->json('data') as $item) {
            $this->assertSame('admin', $item['actor']['role']);
        }
    }

    public function test_filter_from_date_and_to_date(): void
    {
        AuditLog::factory()->create(['created_at' => now()->subDays(5)]);
        AuditLog::factory()->create(['created_at' => now()->subDays(10)]);
        AuditLog::factory()->create(['created_at' => now()->subDays(1)]);
        $this->actingAs($this->admin, 'admin');

        $from = now()->subDays(6)->toDateString();
        $to = now()->subDays(2)->toDateString();

        $response = $this->getJson("/api/admin/audit-logs?from_date={$from}&to_date={$to}");
        $response->assertStatus(200);

        // Only the -5-days row is in [from, to]
        $this->assertSame(1, $response->json('total'));
    }

    public function test_filter_search_by_action(): void
    {
        AuditLog::factory()->create(['action' => 'account_suspended']);
        AuditLog::factory()->create(['action' => 'listing_published']);
        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/audit-logs?search=account_suspended');
        $response->assertStatus(200);

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('account_suspended', $response->json('data.0.action'));
    }

    // =========================================================================
    // Timezone-aware date filtering (Finding A regression coverage)
    //
    // Audit timestamps are stored in UTC. A local evening in a negative-offset
    // zone is stored as the NEXT UTC calendar day. The date filter must resolve
    // the client's calendar day in the client's timezone so such events are not
    // silently hidden — and the list must agree with the summary.
    // =========================================================================

    public function test_evening_local_event_stored_next_utc_day_is_included_in_local_range(): void
    {
        // 2026-06-30 23:30 in New York == 2026-07-01 03:30 UTC (stored next day).
        $eveningUtc = \Carbon\Carbon::parse('2026-07-01 03:30:00', 'UTC');
        AuditLog::factory()->create(['created_at' => $eveningUtc]);

        $this->actingAs($this->admin, 'admin');

        // The admin, in New York, filters for "June 30" (their local day).
        $tz = 'America/New_York';
        $response = $this->getJson("/api/admin/audit-logs?from_date=2026-06-30&to_date=2026-06-30&tz={$tz}");
        $response->assertStatus(200);
        $this->assertSame(1, $response->json('total'), 'Evening-local event must appear in its local day range.');

        // Sanity: interpreting the same range as UTC (no tz) would wrongly drop it.
        $utcResponse = $this->getJson('/api/admin/audit-logs?from_date=2026-06-30&to_date=2026-06-30');
        $this->assertSame(0, $utcResponse->json('total'));
    }

    public function test_summary_and_list_agree_on_todays_events_in_client_tz(): void
    {
        // Freeze "now" to a local evening (New York) that is the next UTC day.
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-07-01 03:30:00', 'UTC'));
        $tz = 'America/New_York';

        // One event "now" (today in NY) and one two days ago (not today).
        AuditLog::factory()->create(['created_at' => now()]);
        AuditLog::factory()->create(['created_at' => now()->subDays(2)]);

        $this->actingAs($this->admin, 'admin');

        // Summary counts "today" in the client timezone.
        $summary = $this->getJson("/api/admin/audit-logs/summary?tz={$tz}");
        $summary->assertStatus(200);
        $todayCount = $summary->json('metrics.user_activity.value');
        $this->assertSame(1, $todayCount);

        // The list, filtered to that same local "today", must return the same count.
        $list = $this->getJson("/api/admin/audit-logs?from_date=2026-06-30&to_date=2026-06-30&tz={$tz}");
        $list->assertStatus(200);
        $this->assertSame($todayCount, $list->json('total'), 'Summary and list must agree on today.');
    }

    public function test_last_7_days_range_includes_boundary_evening_event(): void
    {
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-07-01 03:30:00', 'UTC'));
        $tz = 'America/New_York';

        // Evening-of-today event (stored next UTC day) + one 3 days ago.
        AuditLog::factory()->create(['created_at' => now()]);
        AuditLog::factory()->create(['created_at' => now()->subDays(3)]);
        // One clearly outside the 7-day window.
        AuditLog::factory()->create(['created_at' => now()->subDays(30)]);

        $this->actingAs($this->admin, 'admin');

        // "Last 7 days" as the SPA computes it in local time: 2026-06-24..2026-06-30.
        $response = $this->getJson("/api/admin/audit-logs?from_date=2026-06-24&to_date=2026-06-30&tz={$tz}");
        $response->assertStatus(200);
        $this->assertSame(2, $response->json('total'));
    }

    public function test_no_date_filters_returns_all_events(): void
    {
        AuditLog::factory()->count(4)->create();
        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/audit-logs');
        $response->assertStatus(200);
        $this->assertSame(4, $response->json('total'));
    }

    public function test_invalid_timezone_falls_back_gracefully(): void
    {
        AuditLog::factory()->create(['created_at' => now()]);
        $this->actingAs($this->admin, 'admin');

        // A bogus tz must not error — it falls back to the app timezone.
        $response = $this->getJson('/api/admin/audit-logs?tz=Not/AZone');
        $response->assertStatus(200);
        $this->assertSame(1, $response->json('total'));
    }

    public function test_filter_search_by_ip_address(): void
    {
        AuditLog::factory()->create(['ip_address' => '192.168.99.1']);
        AuditLog::factory()->create(['ip_address' => '10.0.0.1']);
        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/audit-logs?search=192.168.99');
        $response->assertStatus(200);

        $this->assertSame(1, $response->json('total'));
    }

    public function test_filter_search_by_actor_email(): void
    {
        $tenant = User::factory()->tenant()->create(['email' => 'uniqueuser@example.com']);
        AuditLog::factory()->forActor($tenant)->create();
        AuditLog::factory()->count(3)->create(); // no actor or different actor
        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/audit-logs?search=uniqueuser@example.com');
        $response->assertStatus(200);

        $this->assertSame(1, $response->json('total'));
    }

    public function test_sort_newest_vs_oldest(): void
    {
        AuditLog::factory()->create(['created_at' => now()->subDays(3), 'action' => 'user_login']);
        AuditLog::factory()->create(['created_at' => now()->subDays(1), 'action' => 'admin_login']);
        $this->actingAs($this->admin, 'admin');

        // Newest first (default)
        $newestFirst = $this->getJson('/api/admin/audit-logs?sort=newest');
        $this->assertSame('admin_login', $newestFirst->json('data.0.action'));

        // Oldest first
        $oldestFirst = $this->getJson('/api/admin/audit-logs?sort=oldest');
        $this->assertSame('user_login', $oldestFirst->json('data.0.action'));
    }

    public function test_per_page_and_pagination(): void
    {
        AuditLog::factory()->count(25)->create();
        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/audit-logs?per_page=10&page=2');
        $response->assertStatus(200);

        $this->assertSame(10, $response->json('per_page'));
        $this->assertSame(2, $response->json('current_page'));
        $this->assertSame(25, $response->json('total'));
        $this->assertSame(3, $response->json('last_page'));
        $this->assertCount(10, $response->json('data'));
    }

    // =========================================================================
    // Summary
    // =========================================================================

    public function test_summary_returns_expected_structure(): void
    {
        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/audit-logs/summary');
        $response->assertStatus(200);

        $response->assertJsonStructure([
            'metrics' => [
                'critical_today' => ['value', 'label', 'trend'],
                'failed_signins' => ['value', 'label', 'trend'],
                'policy_changes' => ['value', 'label', 'trend'],
                'user_activity' => ['value', 'label', 'trend'],
                'needs_review' => ['value', 'label'],
            ],
            'insights',
        ]);
    }

    public function test_summary_critical_today_counts_todays_critical_rows(): void
    {
        // 2 critical events today
        AuditLog::factory()->count(2)->create([
            'severity' => 'critical',
            'created_at' => now(),
        ]);
        // 1 critical event yesterday — should not count in today
        AuditLog::factory()->create([
            'severity' => 'critical',
            'created_at' => now()->subDay(),
        ]);

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/audit-logs/summary');
        $response->assertStatus(200);

        $this->assertSame(2, $response->json('metrics.critical_today.value'));
    }

    public function test_summary_trend_handles_yesterday_zero_without_error(): void
    {
        // Only today's data — yesterday count will be 0
        AuditLog::factory()->count(3)->create([
            'severity' => 'critical',
            'created_at' => now(),
        ]);

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/audit-logs/summary');
        $response->assertStatus(200);

        $trend = $response->json('metrics.critical_today.trend');

        $this->assertNull($trend['pct']);
        $this->assertSame('No prior-day baseline', $trend['label']);
        $this->assertContains($trend['direction'], ['up', 'flat']);
    }

    public function test_summary_insights_is_array(): void
    {
        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/audit-logs/summary');
        $response->assertStatus(200);

        $this->assertIsArray($response->json('insights'));
    }

    public function test_summary_needs_review_has_no_trend_key(): void
    {
        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/audit-logs/summary');
        $response->assertStatus(200);

        // needs_review intentionally has no trend field
        $needsReview = $response->json('metrics.needs_review');
        $this->assertArrayNotHasKey('trend', $needsReview);
    }

    // =========================================================================
    // Show (detail)
    // =========================================================================

    public function test_show_returns_detail_fields(): void
    {
        $log = AuditLog::factory()->create([
            'action' => 'payment_failed',
            'severity' => 'critical',
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'old_values' => ['status' => 'pending'],
            'new_values' => ['status' => 'failed'],
            'metadata' => ['stripe_error' => 'card_declined'],
        ]);

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson("/api/admin/audit-logs/{$log->id}");
        $response->assertStatus(200);

        // Base fields
        $response->assertJsonStructure([
            'id', 'created_at', 'area', 'action', 'action_label',
            'severity', 'status', 'actor', 'summary', 'subject_label', 'ip_address',
            // Detail extras
            'user_agent', 'device', 'actor_type', 'subject',
            'metadata', 'old_values', 'new_values',
            'why_it_matters', 'recommended_steps',
        ]);

        // Device parsed from known UA
        $this->assertSame('macOS · Chrome', $response->json('device'));

        // why_it_matters is a non-empty string
        $this->assertNotEmpty($response->json('why_it_matters'));

        // recommended_steps is an array
        $this->assertIsArray($response->json('recommended_steps'));

        // Raw stored values are present
        $this->assertSame(['status' => 'pending'], $response->json('old_values'));
        $this->assertSame(['status' => 'failed'], $response->json('new_values'));
        $this->assertSame(['stripe_error' => 'card_declined'], $response->json('metadata'));
    }

    public function test_show_device_is_null_for_null_user_agent(): void
    {
        $log = AuditLog::factory()->create(['user_agent' => null]);

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson("/api/admin/audit-logs/{$log->id}");
        $response->assertStatus(200);

        $this->assertNull($response->json('device'));
    }

    public function test_show_recommended_steps_for_listing_action(): void
    {
        $log = AuditLog::factory()->create(['action' => 'listing_submitted', 'severity' => 'info']);

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson("/api/admin/audit-logs/{$log->id}");
        $response->assertStatus(200);

        $steps = $response->json('recommended_steps');
        $this->assertNotEmpty($steps);
        $routes = array_column($steps, 'to');
        $this->assertContains('/app/listing-review', $routes);
    }

    public function test_show_returns_404_for_missing_audit_log(): void
    {
        $this->actingAs($this->admin, 'admin');

        $this->getJson('/api/admin/audit-logs/999999')->assertStatus(404);
    }

    // =========================================================================
    // Export
    // =========================================================================

    public function test_export_returns_csv_with_correct_headers(): void
    {
        AuditLog::factory()->count(5)->create();
        $this->actingAs($this->admin, 'admin');

        $response = $this->get('/api/admin/audit-logs/export');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('.csv', $response->headers->get('Content-Disposition'));
    }

    public function test_export_csv_body_contains_header_row(): void
    {
        AuditLog::factory()->count(2)->create();
        $this->actingAs($this->admin, 'admin');

        $response = $this->get('/api/admin/audit-logs/export');
        $response->assertStatus(200);

        $content = $response->streamedContent();
        $this->assertStringContainsString('Time', $content);
        $this->assertStringContainsString('Area', $content);
        $this->assertStringContainsString('Action', $content);
        $this->assertStringContainsString('Severity', $content);
    }

    // =========================================================================
    // Hash chain (tamper evidence)
    // =========================================================================

    public function test_new_audit_logs_are_hash_chained(): void
    {
        AuditLog::factory()->count(3)->create();

        $logs = AuditLog::orderBy('id')->get();

        // First row links to the genesis anchor; every hash is 64 hex chars.
        $this->assertSame(AuditLog::GENESIS_HASH, $logs[0]->previous_hash);
        foreach ($logs as $log) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $log->hash);
        }

        // Each row commits to the previous row's hash.
        for ($i = 1; $i < $logs->count(); $i++) {
            $this->assertSame($logs[$i - 1]->hash, $logs[$i]->previous_hash);
        }
    }

    public function test_verify_reports_healthy_chain(): void
    {
        AuditLog::factory()->count(5)->create();
        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/audit-logs/verify');
        $response->assertStatus(200);

        $response->assertJson([
            'status' => 'healthy',
            'is_valid' => true,
            'checked_count' => 5,
            'total_count' => 5,
            'failed_count' => 0,
            'broken_at' => null,
            'algorithm' => 'SHA-256',
        ]);

        // Latest event is the newest row, and its hash prefix matches.
        $newest = AuditLog::orderByDesc('id')->first();
        $this->assertSame($newest->id, $response->json('latest_event_id'));
        $this->assertSame(substr($newest->hash, 0, 8), $response->json('latest_hash_prefix'));
        $this->assertNotEmpty($response->json('message'));
        $this->assertNotEmpty($response->json('verified_at'));
    }

    public function test_verify_detects_tampering(): void
    {
        AuditLog::factory()->count(4)->create();

        // Tamper with a historical row's content directly in the DB, bypassing
        // the model — its stored hash no longer matches the recomputed one.
        $target = AuditLog::orderBy('id')->skip(1)->first();
        DB::table('audit_logs')->where('id', $target->id)->update(['action' => 'tampered_action']);

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/audit-logs/verify');
        $response->assertStatus(200);

        $response->assertJson([
            'status' => 'broken',
            'is_valid' => false,
            'broken_at' => $target->id,
        ]);
        $this->assertGreaterThan(0, $response->json('failed_count'));
        $this->assertLessThan($response->json('total_count'), $response->json('checked_count'));
        $this->assertStringContainsString((string) $target->id, $response->json('message'));
    }

    public function test_verify_reports_empty_chain(): void
    {
        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/audit-logs/verify');
        $response->assertStatus(200);

        $response->assertJson([
            'status' => 'empty',
            'is_valid' => true,
            'checked_count' => 0,
            'total_count' => 0,
            'failed_count' => 0,
            'latest_event_id' => null,
            'latest_hash_prefix' => null,
        ]);
    }

    public function test_verify_requires_admin(): void
    {
        $this->getJson('/api/admin/audit-logs/verify')->assertStatus(401);

        $tenant = User::factory()->tenant()->create();
        Sanctum::actingAs($tenant, [], 'sanctum');
        $this->getJson('/api/admin/audit-logs/verify')->assertStatus(401);
    }

    public function test_show_includes_hash_chain_fields(): void
    {
        $log = AuditLog::factory()->create();
        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson("/api/admin/audit-logs/{$log->id}");
        $response->assertStatus(200)->assertJsonStructure(['hash', 'previous_hash']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $response->json('hash'));
    }

    public function test_index_rows_include_hash(): void
    {
        AuditLog::factory()->count(2)->create();
        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/audit-logs');
        $response->assertStatus(200)->assertJsonStructure(['data' => [['hash']]]);
    }
}
