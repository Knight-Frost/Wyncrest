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
use App\Services\LedgerAutomationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LedgerAutomationTest
 *
 * Tests automated rent generation and overdue detection.
 * All tests use time travel for deterministic results.
 */
class LedgerAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected User $landlord;

    protected User $tenant;

    protected Contract $contract;

    protected LedgerAutomationService $automationService;

    protected function setUp(): void
    {
        parent::setUp();

        // Register observer

        $this->landlord = User::factory()->landlord()->create();
        $this->tenant = User::factory()->tenant()->create();

        // Create property, unit, listing
        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $this->landlord->id,
        ]);

        $this->automationService = app(LedgerAutomationService::class);
    }

    public function test_rent_is_generated_for_active_contract()
    {
        // Set known date: Feb 20, 2025
        Carbon::setTestNow(Carbon::parse('2025-02-20'));

        // Create active contract starting Jan 15
        $this->contract = Contract::factory()->create([
            'listing_id' => Listing::first()->id,
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'status' => ContractStatus::ACTIVE,
            'start_date' => Carbon::parse('2025-01-15'),
            'end_date' => null,
            'rent_amount' => 250000,
            'payment_day' => 1,
        ]);

        // Generate rent
        $entry = $this->automationService->generateRentForContract($this->contract);

        // Verify entry created
        $this->assertNotNull($entry);
        $this->assertEquals(LedgerType::RENT, $entry->type);
        $this->assertEquals(LedgerStatus::PENDING, $entry->status);
        $this->assertEquals(250000, $entry->amount_cents);

        // Verify period: Feb 15 - Mar 14 (current period for Feb 20)
        $this->assertEquals('2025-02-15', $entry->billing_period_start->format('Y-m-d'));
        $this->assertEquals('2025-03-14', $entry->billing_period_end->format('Y-m-d'));

        // Verify due date: March 1 (payment_day of month containing period_end)
        $this->assertEquals('2025-03-01', $entry->due_date->format('Y-m-d'));

        // Verify audit log
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'rent_entry_automated',
            'severity' => 'info',
        ]);

        Carbon::setTestNow(); // Reset
    }

    public function test_duplicate_rent_is_not_created()
    {
        Carbon::setTestNow(Carbon::parse('2025-02-20'));

        $this->contract = Contract::factory()->create([
            'listing_id' => Listing::first()->id,
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'status' => ContractStatus::ACTIVE,
            'start_date' => Carbon::parse('2025-01-15'),
            'rent_amount' => 250000,
            'payment_day' => 1,
        ]);

        // Generate rent first time
        $entry1 = $this->automationService->generateRentForContract($this->contract);
        $this->assertNotNull($entry1);

        // Try to generate again
        $entry2 = $this->automationService->generateRentForContract($this->contract);
        $this->assertNull($entry2); // Should return null (skipped)

        // Verify only 1 entry exists
        $count = LedgerEntry::where('contract_id', $this->contract->id)
            ->where('type', LedgerType::RENT)
            ->count();
        $this->assertEquals(1, $count);

        Carbon::setTestNow();
    }

    public function test_rent_is_not_generated_for_inactive_contract()
    {
        Carbon::setTestNow(Carbon::parse('2025-02-20'));

        // Create DRAFT contract (not active)
        $this->contract = Contract::factory()->create([
            'listing_id' => Listing::first()->id,
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'status' => ContractStatus::DRAFT,
            'start_date' => Carbon::parse('2025-01-15'),
            'rent_amount' => 250000,
            'payment_day' => 1,
        ]);

        // Try to generate rent
        $entry = $this->automationService->generateRentForContract($this->contract);

        // Should be null (not eligible)
        $this->assertNull($entry);

        // Verify no entries created
        $count = LedgerEntry::where('contract_id', $this->contract->id)->count();
        $this->assertEquals(0, $count);

        Carbon::setTestNow();
    }

    public function test_overdue_entries_are_marked()
    {
        // Set current date: Feb 10, 2025
        Carbon::setTestNow(Carbon::parse('2025-02-10'));

        $this->contract = Contract::factory()->create([
            'listing_id' => Listing::first()->id,
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'status' => ContractStatus::ACTIVE,
            'rent_amount' => 250000,
        ]);

        // Create rent entry with past due date
        $entry = LedgerEntry::factory()->rent()->pending()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'due_date' => Carbon::parse('2025-02-01'), // 9 days overdue
        ]);

        // Mark overdue
        $count = $this->automationService->markOverdueEntries();

        // Verify 1 entry marked
        $this->assertEquals(1, $count);

        // Verify status changed
        $entry->refresh();
        $this->assertEquals(LedgerStatus::OVERDUE, $entry->status);

        // Verify audit log
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ledger_entry_marked_overdue',
            'severity' => 'warning',
        ]);

        Carbon::setTestNow();
    }

    public function test_paid_entries_are_not_marked_overdue()
    {
        Carbon::setTestNow(Carbon::parse('2025-02-10'));

        $this->contract = Contract::factory()->create([
            'listing_id' => Listing::first()->id,
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'status' => ContractStatus::ACTIVE,
        ]);

        // Create PAID entry with past due date
        $entry = LedgerEntry::factory()->rent()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'status' => LedgerStatus::PAID,
            'due_date' => Carbon::parse('2025-02-01'),
        ]);

        // Mark overdue
        $count = $this->automationService->markOverdueEntries();

        // Verify 0 entries marked (PAID entries skipped)
        $this->assertEquals(0, $count);

        // Verify status unchanged
        $entry->refresh();
        $this->assertEquals(LedgerStatus::PAID, $entry->status);

        Carbon::setTestNow();
    }

    public function test_future_entries_are_not_marked_overdue()
    {
        Carbon::setTestNow(Carbon::parse('2025-02-10'));

        $this->contract = Contract::factory()->create([
            'listing_id' => Listing::first()->id,
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'status' => ContractStatus::ACTIVE,
        ]);

        // Create entry with future due date
        $entry = LedgerEntry::factory()->rent()->pending()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'due_date' => Carbon::parse('2025-02-15'), // 5 days in future
        ]);

        // Mark overdue
        $count = $this->automationService->markOverdueEntries();

        // Verify 0 entries marked
        $this->assertEquals(0, $count);

        // Verify status unchanged
        $entry->refresh();
        $this->assertEquals(LedgerStatus::PENDING, $entry->status);

        Carbon::setTestNow();
    }

    public function test_automation_creates_audit_logs()
    {
        Carbon::setTestNow(Carbon::parse('2025-02-20'));

        $this->contract = Contract::factory()->create([
            'listing_id' => Listing::first()->id,
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'status' => ContractStatus::ACTIVE,
            'start_date' => Carbon::parse('2025-01-15'),
            'rent_amount' => 250000,
            'payment_day' => 1,
        ]);

        // Generate rent
        $this->automationService->generateRentForContract($this->contract);

        // Verify audit log for rent generation
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'rent_entry_automated',
            'severity' => 'info',
        ]);

        // Create overdue entry
        $entry = LedgerEntry::factory()->rent()->pending()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'due_date' => Carbon::parse('2025-02-01'),
        ]);

        // Mark overdue
        $this->automationService->markOverdueEntries();

        // Verify audit log for overdue marking
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ledger_entry_marked_overdue',
            'severity' => 'warning',
        ]);

        Carbon::setTestNow();
    }

    public function test_rent_respects_contract_end_date()
    {
        // Today: April 5, 2025 (after contract ended)
        Carbon::setTestNow(Carbon::parse('2025-04-05'));

        $this->contract = Contract::factory()->create([
            'listing_id' => Listing::first()->id,
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'status' => ContractStatus::ACTIVE,
            'start_date' => Carbon::parse('2025-01-15'),
            'end_date' => Carbon::parse('2025-03-31'), // Contract ended
            'rent_amount' => 250000,
            'payment_day' => 1,
        ]);

        // Try to generate rent
        $entry = $this->automationService->generateRentForContract($this->contract);

        // Should be null (contract ended)
        $this->assertNull($entry);

        // Verify no entries created
        $count = LedgerEntry::where('contract_id', $this->contract->id)->count();
        $this->assertEquals(0, $count);

        Carbon::setTestNow();
    }

    public function test_command_generates_rent_for_all_contracts()
    {
        Carbon::setTestNow(Carbon::parse('2025-02-20'));

        // Create 3 active contracts (each needs own listing to avoid unique constraint)
        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);

        for ($i = 0; $i < 3; $i++) {
            $unit = Unit::factory()->create(['property_id' => $property->id]);
            $listing = Listing::factory()->active()->create([
                'unit_id' => $unit->id,
                'landlord_id' => $this->landlord->id,
            ]);

            Contract::factory()->create([
                'listing_id' => $listing->id,
                'landlord_id' => $this->landlord->id,
                'tenant_id' => $this->tenant->id,
                'status' => ContractStatus::ACTIVE,
                'start_date' => Carbon::parse('2025-01-15'),
                'rent_amount' => 250000,
                'payment_day' => 1,
            ]);
        }

        // Generate for all
        $result = $this->automationService->generateRentForAllContracts();

        // Verify 3 created
        $this->assertEquals(3, $result['created']);
        $this->assertEquals(0, $result['skipped']);

        // Verify 3 entries exist
        $this->assertEquals(3, LedgerEntry::where('type', LedgerType::RENT)->count());

        Carbon::setTestNow();
    }

    public function test_billing_period_handles_invalid_payment_days()
    {
        // Test payment_day 30 in February
        Carbon::setTestNow(Carbon::parse('2025-02-20'));

        $this->contract = Contract::factory()->create([
            'listing_id' => Listing::first()->id,
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'status' => ContractStatus::ACTIVE,
            'start_date' => Carbon::parse('2025-01-15'),
            'rent_amount' => 250000,
            'payment_day' => 30, // Invalid for Feb
        ]);

        $entry = $this->automationService->generateRentForContract($this->contract);

        // Should handle gracefully (use last day of month)
        $this->assertNotNull($entry);

        // Period: Feb 15 - Mar 14
        // Due date should be Mar 30 (payment_day in month containing period_end)
        $this->assertEquals('2025-03-30', $entry->due_date->format('Y-m-d'));

        Carbon::setTestNow();
    }
}
