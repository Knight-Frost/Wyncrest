<?php

namespace Tests\Feature;

use App\Enums\ContractStatus;
use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\Analytics\FinancialAnalyticsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FinancialAnalyticsTest
 *
 * Phase 4.0b: Tests for ledger-only financial analytics
 */
class FinancialAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $tenant;

    protected User $landlord;

    protected Property $property;

    protected Unit $unit;

    protected Listing $listing;

    protected Contract $contract;

    protected FinancialAnalyticsService $analyticsService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create users
        $this->tenant = User::factory()->tenant()->create();
        $this->landlord = User::factory()->landlord()->create();
        $this->admin = $this->landlord;

        // Create property chain: Property → Unit → Listing → Contract
        $this->property = Property::factory()->create([
            'landlord_id' => $this->landlord->id,
        ]);

        $this->unit = Unit::factory()->create([
            'property_id' => $this->property->id,
        ]);

        $this->listing = Listing::factory()->create([
            'unit_id' => $this->unit->id,
            'landlord_id' => $this->landlord->id,
        ]);

        $this->contract = Contract::factory()->create([
            'listing_id' => $this->listing->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'status' => ContractStatus::ACTIVE,
        ]);

        $this->analyticsService = app(FinancialAnalyticsService::class);
    }

    public function test_revenue_totals_are_correct()
    {
        // Create rent entries
        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 100000,
            'status' => LedgerStatus::PAID,
        ]);

        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 150000,
            'status' => LedgerStatus::PENDING,
        ]);

        $metrics = $this->analyticsService->getRevenueMetrics();

        $this->assertEquals(2500.00, $metrics['total_rent_generated']);
        $this->assertEquals(1000.00, $metrics['total_payments_received']);
    }

    public function test_waived_entries_are_calculated_correctly()
    {
        // Create paid entry
        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 100000,
            'status' => LedgerStatus::PAID,
        ]);

        // Create waived entry
        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 20000,
            'status' => LedgerStatus::WAIVED,
        ]);

        $metrics = $this->analyticsService->getRevenueMetrics();

        $this->assertEquals(1000.00, $metrics['total_payments_received']);
        $this->assertEquals(200.00, $metrics['total_waived']);
        $this->assertEquals(800.00, $metrics['net_revenue']);
    }

    public function test_outstanding_balance_is_accurate()
    {
        // Create pending entry
        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 150000,
            'status' => LedgerStatus::PENDING,
        ]);

        // Create paid entry
        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 150000,
            'status' => LedgerStatus::PAID,
        ]);

        $metrics = $this->analyticsService->getOutstandingMetrics();

        $this->assertEquals(1500.00, $metrics['total_outstanding_balance']);
    }

    public function test_overdue_logic_is_correct()
    {
        // Create overdue entry
        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 120000,
            'status' => LedgerStatus::OVERDUE,
        ]);

        // Create regular rent
        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 80000,
            'status' => LedgerStatus::PENDING,
        ]);

        $metrics = $this->analyticsService->getOutstandingMetrics();

        $this->assertEquals(1200.00, $metrics['total_overdue_amount']);
        $this->assertGreaterThan(0, $metrics['overdue_rate_percentage']);
    }

    public function test_average_days_overdue_calculation()
    {
        // Create overdue entry due 5 days ago
        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 100000,
            'status' => LedgerStatus::OVERDUE,
            'due_date' => Carbon::now()->subDays(5),
        ]);

        // Create overdue entry due 10 days ago
        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 100000,
            'status' => LedgerStatus::OVERDUE,
            'due_date' => Carbon::now()->subDays(10),
        ]);

        $metrics = $this->analyticsService->getOutstandingMetrics();

        $this->assertEqualsWithDelta(7.5, $metrics['average_days_overdue'], 2, 'Average days should be approximately 7.5');
    }

    public function test_ledger_integrity_flags_work()
    {
        // Create normal entry
        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 100000,
            'status' => LedgerStatus::PENDING,
        ]);

        $metrics = $this->analyticsService->getLedgerIntegrityMetrics();

        $this->assertEquals(1000.00, $metrics['ledger_balance_sum']);
        $this->assertEquals(0, $metrics['negative_balances_count']);
        $this->assertFalse($metrics['balance_mismatch_detected']);
    }

    public function test_negative_balances_are_detected()
    {
        // This shouldn't happen in real system, but test detection
        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => -20000,
            'status' => LedgerStatus::PENDING,
        ]);

        $metrics = $this->analyticsService->getLedgerIntegrityMetrics();

        $this->assertEquals(1, $metrics['negative_balances_count']);
    }

    public function test_date_filters_work()
    {
        // Create old entry
        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 50000,
            'status' => LedgerStatus::PAID,
            'created_at' => Carbon::now()->subMonths(2),
        ]);

        // Create recent entry
        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 100000,
            'status' => LedgerStatus::PAID,
            'created_at' => Carbon::now(),
        ]);

        $metrics = $this->analyticsService->getRevenueMetrics([
            'start_date' => Carbon::now()->subDays(7),
        ]);

        $this->assertEquals(1000.00, $metrics['total_rent_generated']);
    }

    public function test_property_scoping_is_enforced()
    {
        // Create another property
        $property2 = Property::factory()->create([
            'landlord_id' => $this->landlord->id,
        ]);

        $unit2 = Unit::factory()->create([
            'property_id' => $property2->id,
        ]);

        $listing2 = Listing::factory()->create([
            'unit_id' => $unit2->id,
            'landlord_id' => $this->landlord->id,
        ]);

        $contract2 = Contract::factory()->create([
            'listing_id' => $listing2->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'status' => ContractStatus::ACTIVE,
        ]);

        // Create entries for both properties
        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 100000,
            'status' => LedgerStatus::PAID,
        ]);

        LedgerEntry::factory()->create([
            'contract_id' => $contract2->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 200000,
            'status' => LedgerStatus::PAID,
        ]);

        // Filter to property 1 only
        $metrics = $this->analyticsService->getRevenueMetrics([
            'property_id' => $this->property->id,
        ]);

        $this->assertEquals(1000.00, $metrics['total_rent_generated']);
    }

    public function test_tenant_isolation_is_enforced()
    {
        // Create another tenant
        $tenant2 = User::factory()->tenant()->create();

        $unit2 = Unit::factory()->create([
            'property_id' => $this->property->id,
        ]);

        $listing2 = Listing::factory()->create([
            'unit_id' => $unit2->id,
            'landlord_id' => $this->landlord->id,
        ]);

        $contract2 = Contract::factory()->create([
            'listing_id' => $listing2->id,
            'tenant_id' => $tenant2->id,
            'landlord_id' => $this->landlord->id,
            'status' => ContractStatus::ACTIVE,
        ]);

        // Create entries for both tenants
        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 100000,
            'status' => LedgerStatus::PAID,
        ]);

        LedgerEntry::factory()->create([
            'contract_id' => $contract2->id,
            'tenant_id' => $tenant2->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 200000,
            'status' => LedgerStatus::PAID,
        ]);

        // Filter to tenant 1 only
        $metrics = $this->analyticsService->getRevenueMetrics([
            'user_id' => $this->tenant->id,
        ]);

        $this->assertEquals(1000.00, $metrics['total_rent_generated']);
    }

    public function test_admin_can_access_financial_analytics_api()
    {
        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 100000,
            'status' => LedgerStatus::PAID,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/analytics/financial');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'analytics' => [
                'revenue',
                'outstanding',
                'ledger_integrity',
            ],
        ]);
    }

    public function test_tenant_sees_only_personal_financial_data()
    {
        // Tenant's entry
        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 100000,
            'status' => LedgerStatus::PAID,
        ]);

        // Other tenant's entry
        $tenant2 = User::factory()->tenant()->create();

        $unit2 = Unit::factory()->create([
            'property_id' => $this->property->id,
        ]);

        $listing2 = Listing::factory()->create([
            'unit_id' => $unit2->id,
            'landlord_id' => $this->landlord->id,
        ]);

        $contract2 = Contract::factory()->create([
            'listing_id' => $listing2->id,
            'tenant_id' => $tenant2->id,
            'landlord_id' => $this->landlord->id,
            'status' => ContractStatus::ACTIVE,
        ]);

        LedgerEntry::factory()->create([
            'contract_id' => $contract2->id,
            'tenant_id' => $tenant2->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 500000,
            'status' => LedgerStatus::PAID,
        ]);

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/analytics/financial');

        $response->assertStatus(200);
        $this->assertEquals(1000.00, $response->json('analytics.revenue.total_rent_generated'));
        $this->assertEquals('personal', $response->json('scoped_to'));
    }

    public function test_unauthenticated_user_cannot_access_financial_analytics()
    {
        $response = $this->getJson('/api/analytics/financial');
        $response->assertStatus(401);
    }

    public function test_financial_analytics_validates_date_filters()
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/analytics/financial?start_date=invalid');

        $response->assertStatus(422);
    }

    public function test_end_date_must_be_after_start_date()
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/analytics/financial?start_date=2025-12-31&end_date=2025-01-01');

        $response->assertStatus(422);
    }

    public function test_financial_analytics_does_not_mutate_data()
    {
        $ledgerEntry = LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 100000,
            'status' => LedgerStatus::PENDING,
        ]);

        $originalAmountCents = $ledgerEntry->amount_cents;
        $originalStatus = $ledgerEntry->status;
        $originalCreatedAt = $ledgerEntry->created_at;

        // Call analytics multiple times
        $this->analyticsService->getAnalytics();
        $this->analyticsService->getAnalytics();

        $ledgerEntry->refresh();

        $this->assertEquals($originalAmountCents, $ledgerEntry->amount_cents);
        $this->assertEquals($originalStatus, $ledgerEntry->status);
        $this->assertEquals($originalCreatedAt, $ledgerEntry->created_at);
    }

    public function test_financial_analytics_returns_deterministic_results()
    {
        LedgerEntry::factory()->count(5)->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 100000,
            'status' => LedgerStatus::PAID,
        ]);

        $result1 = $this->analyticsService->getAnalytics();
        $result2 = $this->analyticsService->getAnalytics();

        $this->assertEquals($result1, $result2);
    }

    public function test_revenue_by_month_grouping_works()
    {
        // Create entries in different months
        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 100000,
            'status' => LedgerStatus::PAID,
            'created_at' => Carbon::now()->startOfMonth(),
        ]);

        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 200000,
            'status' => LedgerStatus::PAID,
            'created_at' => Carbon::now()->subMonth()->startOfMonth(),
        ]);

        $metrics = $this->analyticsService->getRevenueMetrics();

        $this->assertIsArray($metrics['revenue_by_month']);
        $this->assertCount(2, $metrics['revenue_by_month']);
    }

    public function test_revenue_by_property_grouping_works()
    {
        // Create entry
        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 100000,
            'status' => LedgerStatus::PAID,
        ]);

        $metrics = $this->analyticsService->getRevenueMetrics();

        $this->assertIsArray($metrics['revenue_by_property']);
        $this->assertArrayHasKey($this->property->name, $metrics['revenue_by_property']);
    }

    public function test_empty_data_returns_zero_metrics()
    {
        // No data created
        $metrics = $this->analyticsService->getAnalytics();

        $this->assertEquals(0.00, $metrics['revenue']['total_rent_generated']);
        $this->assertEquals(0.00, $metrics['outstanding']['total_outstanding_balance']);
        $this->assertEquals(0, $metrics['ledger_integrity']['negative_balances_count']);
    }

    public function test_outstanding_balance_includes_overdue_not_just_pending()
    {
        // Pending obligation (future due date, still unpaid).
        LedgerEntry::factory()->pending()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 100000,
        ]);

        // Overdue obligation (also still unpaid).
        LedgerEntry::factory()->overdue()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 50000,
        ]);

        $metrics = $this->analyticsService->getOutstandingMetrics();

        // Outstanding must be pending + overdue (1500), not pending-only (1000).
        $this->assertEquals(1500.00, $metrics['total_outstanding_balance']);
    }

    public function test_legitimate_negative_payment_is_not_flagged_as_corruption()
    {
        // A settled obligation: PAID rent obligation + its linked negative
        // PAYMENT entry, exactly as PaymentEntryFactory records real payments.
        $rent = LedgerEntry::factory()->paid()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 100000,
        ]);

        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::PAYMENT,
            'amount_cents' => -100000, // canonical: payments stored negative
            'status' => LedgerStatus::PAID,
            'related_rent_entry_id' => $rent->id,
        ]);

        $metrics = $this->analyticsService->getLedgerIntegrityMetrics();

        // The negative payment is legitimate, not corruption.
        $this->assertEquals(0, $metrics['negative_balances_count']);
        // Charges (100000) - collected (100000) - outstanding (0) == 0.
        $this->assertFalse($metrics['balance_mismatch_detected']);
    }

    public function test_wrong_signed_entries_are_flagged_as_corruption()
    {
        // A rent obligation stored negative (impossible under the convention).
        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => -20000,
            'status' => LedgerStatus::PENDING,
        ]);

        // A payment stored positive (impossible under the convention).
        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::PAYMENT,
            'amount_cents' => 20000,
            'status' => LedgerStatus::PAID,
        ]);

        $metrics = $this->analyticsService->getLedgerIntegrityMetrics();

        $this->assertEquals(2, $metrics['negative_balances_count']);
    }
}
