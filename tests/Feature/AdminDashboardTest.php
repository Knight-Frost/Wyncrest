<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AdminDashboardTest
 *
 * Covers GET /api/admin/dashboard — the platform command-center overview.
 * Asserts the extended JSON shape, that aggregates reflect seeded data, and
 * that authorization is enforced (non-admin → 403, unauthenticated → 401).
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::factory()->create(['is_super_admin' => true]);
    }

    public function test_returns_full_dashboard_shape_for_admin(): void
    {
        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'statistics' => [
                    'landlords',
                    'tenants',
                    'properties',
                    'units',
                    'pending_listings',
                    'active_listings',
                    'total_listings',
                    'active_contracts',
                    'pending_verifications',
                    'active_users',
                ],
                'contracts' => [
                    'draft',
                    'pending_tenant',
                    'active',
                    'terminated',
                    'expired',
                ],
                'ledger' => [
                    'outstanding_cents',
                    'overdue_cents',
                    'overdue_entries',
                    'collected_this_month_cents',
                ],
                'notifications' => [
                    'failed_deliveries',
                ],
                'listings_by_status' => [
                    'draft',
                    'pending_review',
                    'active',
                    'rejected',
                    'inactive',
                    'archived',
                ],
                'recent_listings',
            ]);
    }

    public function test_statistics_reflect_seeded_data(): void
    {
        // Listing/unit/property/user counts must reflect the platform totals,
        // including the rows the listing factory itself spins up (each listing
        // brings its own landlord + unit + property). We assert against the
        // actual model totals so the test is exact regardless of those.
        Listing::factory()->draft()->count(2)->create();
        Listing::factory()->pendingReview()->count(3)->create();
        Listing::factory()->active()->count(4)->create();

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('statistics.landlords', User::landlords()->count())
            ->assertJsonPath('statistics.tenants', User::tenants()->count())
            ->assertJsonPath('statistics.properties', Property::count())
            ->assertJsonPath('statistics.units', Unit::count())
            ->assertJsonPath('statistics.pending_listings', 3)
            ->assertJsonPath('statistics.active_listings', 4)
            ->assertJsonPath('statistics.total_listings', 9)
            ->assertJsonPath('listings_by_status.draft', 2)
            ->assertJsonPath('listings_by_status.pending_review', 3)
            ->assertJsonPath('listings_by_status.active', 4);

        // And those platform totals are non-trivial / wired through.
        $this->assertGreaterThanOrEqual(9, $response->json('statistics.landlords'));
        $this->assertGreaterThanOrEqual(9, $response->json('statistics.units'));
    }

    public function test_operational_attention_signals_are_accurate(): void
    {
        $tenant = User::factory()->create(['is_active' => true]);

        // Verification queue: PENDING + UNDER_REVIEW are admin-actionable;
        // NEEDS_MORE_INFORMATION waits on the user and must NOT be counted.
        \App\Models\VerificationRequest::create([
            'user_id' => $tenant->id,
            'status' => 'pending',
            'submitted_at' => now(),
        ]);
        \App\Models\VerificationRequest::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'under_review',
            'submitted_at' => now(),
        ]);
        \App\Models\VerificationRequest::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'needs_more_information',
            'submitted_at' => now(),
        ]);

        // Unresolved delivery failures across channels (2 distinct rows).
        \App\Models\Notification::factory()->create([
            'user_id' => $tenant->id,
            'delivery_failed_at' => now(),
        ]);
        \App\Models\Notification::factory()->create([
            'user_id' => $tenant->id,
            'sms_failed_at' => now(),
        ]);
        \App\Models\Notification::factory()->create([
            'user_id' => $tenant->id,
            'delivered_at' => now(),
        ]);

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('statistics.pending_verifications', 2)
            ->assertJsonPath('notifications.failed_deliveries', 2);

        // active_users reflects only in-good-standing accounts.
        $this->assertSame(
            User::where('is_active', true)->count(),
            $response->json('statistics.active_users'),
        );
    }

    public function test_contract_distribution_counts_by_status(): void
    {
        Contract::factory()->draft()->count(2)->create();
        Contract::factory()->pendingTenant()->count(1)->create();
        Contract::factory()->active()->count(4)->create();
        Contract::factory()->terminated()->count(1)->create();

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('contracts.draft', 2)
            ->assertJsonPath('contracts.pending_tenant', 1)
            ->assertJsonPath('contracts.active', 4)
            ->assertJsonPath('contracts.terminated', 1)
            ->assertJsonPath('contracts.expired', 0)
            ->assertJsonPath('statistics.active_contracts', 4);
    }

    public function test_ledger_health_aggregates_platform_wide(): void
    {
        $contract = Contract::factory()->active()->create();

        // Outstanding = pending + overdue.
        LedgerEntry::factory()->pending()->create([
            'contract_id' => $contract->id,
            'amount_cents' => 100_000,
        ]);
        LedgerEntry::factory()->overdue()->create([
            'contract_id' => $contract->id,
            'amount_cents' => 50_000,
        ]);

        // Paid this calendar month (counts toward collected).
        LedgerEntry::factory()->paid()->create([
            'contract_id' => $contract->id,
            'amount_cents' => 70_000,
            'due_date' => now()->startOfMonth()->addDays(2),
        ]);

        // Paid but outside this month (must NOT count toward collected).
        LedgerEntry::factory()->paid()->create([
            'contract_id' => $contract->id,
            'amount_cents' => 999_000,
            'due_date' => now()->subMonths(2),
        ]);

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('ledger.outstanding_cents', 150_000)
            ->assertJsonPath('ledger.overdue_cents', 50_000)
            ->assertJsonPath('ledger.collected_this_month_cents', 70_000);
    }

    public function test_recent_listings_capped_and_eager_loaded(): void
    {
        Listing::factory()->count(12)->create();

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(200);

        $recent = $response->json('recent_listings');
        $this->assertLessThanOrEqual(8, count($recent));
        $this->assertArrayHasKey('landlord', $recent[0]);
        $this->assertArrayHasKey('unit', $recent[0]);
    }

    public function test_forbidden_for_landlord(): void
    {
        $landlord = User::factory()->landlord()->create();
        Sanctum::actingAs($landlord, [], 'sanctum');

        $this->getJson('/api/admin/dashboard')->assertStatus(401);
    }

    public function test_forbidden_for_tenant(): void
    {
        $tenant = User::factory()->tenant()->create();
        Sanctum::actingAs($tenant, [], 'sanctum');

        $this->getJson('/api/admin/dashboard')->assertStatus(401);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/admin/dashboard')->assertStatus(401);
    }
}
