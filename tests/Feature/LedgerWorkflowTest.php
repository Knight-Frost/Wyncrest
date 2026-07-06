<?php

namespace Tests\Feature;

use App\Enums\ContractStatus;
use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Models\Admin;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LedgerWorkflowTest
 *
 * Tests ledger functionality:
 * - Auto-generation when contract becomes active
 * - Tenant/landlord viewing
 * - Authorization
 * - Late fee generation
 * - Immutability
 */
class LedgerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $landlord;

    protected User $tenant;

    protected Admin $admin;

    protected Contract $contract;

    protected LedgerService $ledgerService;

    protected function setUp(): void
    {
        parent::setUp();

        // Manually register observer for tests

        $this->landlord = User::factory()->landlord()->create();
        $this->tenant = User::factory()->tenant()->create();
        $this->admin = Admin::factory()->create();

        // Create active listing and contract
        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $this->landlord->id,
        ]);

        $this->contract = Contract::factory()->create([
            'listing_id' => $listing->id,
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'status' => ContractStatus::PENDING_TENANT,
            'rent_amount' => 250000, // $2500
            'payment_day' => 1,
        ]);

        $this->ledgerService = app(LedgerService::class);
    }

    public function test_rent_entry_created_when_contract_becomes_active()
    {
        // Contract starts as PENDING_TENANT
        $this->assertEquals(ContractStatus::PENDING_TENANT, $this->contract->status);

        // No ledger entries yet
        $this->assertDatabaseMissing('ledger_entries', [
            'contract_id' => $this->contract->id,
        ]);

        // Activate contract (triggers observer)
        $this->contract->update(['status' => ContractStatus::ACTIVE]);

        // Verify rent entry was created
        $this->assertDatabaseHas('ledger_entries', [
            'contract_id' => $this->contract->id,
            'type' => LedgerType::RENT->value,
            'status' => LedgerStatus::PENDING->value,
            'amount_cents' => 250000,
        ]);

        // Verify audit log
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'rent_entry_created',
            'severity' => 'info',
        ]);
    }

    public function test_tenant_can_view_their_ledger_entries()
    {
        // Create ledger entry
        $entry = LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
        ]);

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/tenant/ledger');

        $response->assertStatus(200)
            ->assertJsonFragment([
                'id' => $entry->id,
            ]);
    }

    public function test_tenant_can_filter_ledger_entries_by_contract()
    {
        $entry = LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
        ]);

        // A second contract/entry for the same tenant that must NOT appear
        // once the response is scoped to $this->contract.
        $otherListing = Listing::factory()->active()->create([
            'unit_id' => Unit::factory()->create(['property_id' => $this->contract->listing->unit->property_id])->id,
            'landlord_id' => $this->landlord->id,
        ]);
        $otherContract = Contract::factory()->create([
            'listing_id' => $otherListing->id,
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
        ]);
        $otherEntry = LedgerEntry::factory()->create([
            'contract_id' => $otherContract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
        ]);

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson("/api/tenant/ledger?contract_id={$this->contract->id}");

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $entry->id])
            ->assertJsonMissing(['id' => $otherEntry->id]);
    }

    public function test_landlord_can_view_their_ledger_entries()
    {
        // Create ledger entry
        $entry = LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
        ]);

        $response = $this->actingAs($this->landlord, 'sanctum')
            ->getJson('/api/landlord/ledger');

        $response->assertStatus(200)
            ->assertJsonFragment([
                'id' => $entry->id,
            ]);
    }

    public function test_unauthorized_user_cannot_view_ledger_entry()
    {
        $otherUser = User::factory()->tenant()->create();

        $entry = LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
        ]);

        $response = $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/tenant/ledger/{$entry->id}");

        $response->assertStatus(403);
    }

    public function test_late_fee_can_be_generated_for_overdue_rent()
    {
        // Create overdue rent entry
        $rentEntry = LedgerEntry::factory()->overdue()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
        ]);

        // Generate late fee (admin action)
        $response = $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/ledger/{$rentEntry->id}/late-fee", [
                'amount_cents' => 10000, // $100 late fee
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Late fee generated successfully',
            ]);

        // Verify late fee entry created
        $this->assertDatabaseHas('ledger_entries', [
            'type' => LedgerType::LATE_FEE->value,
            'amount_cents' => 10000,
            'related_rent_entry_id' => $rentEntry->id,
        ]);

        // Verify audit log (warning severity)
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'late_fee_applied',
            'severity' => 'warning',
        ]);
    }

    public function test_late_fee_cannot_be_generated_for_non_overdue_rent()
    {
        // Create pending (not overdue) rent entry
        $rentEntry = LedgerEntry::factory()->pending()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'due_date' => now()->addDays(5), // Future due date
        ]);

        $response = $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/ledger/{$rentEntry->id}/late-fee", [
                'amount_cents' => 10000,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Late fees can only be applied to overdue entries',
            ]);
    }

    public function test_scoped_admin_without_manage_ledger_can_view_but_not_generate_late_fee()
    {
        $rentEntry = LedgerEntry::factory()->overdue()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
        ]);
        $scoped = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => []]);

        // Viewing the ledger needs no capability.
        $this->actingAs($scoped, 'admin')->getJson('/api/admin/ledger')->assertOk();

        // Generating a late fee still requires manage_ledger.
        $this->actingAs($scoped, 'admin')
            ->postJson("/api/admin/ledger/{$rentEntry->id}/late-fee", ['amount_cents' => 10000])
            ->assertStatus(403)->assertJsonPath('required_capability', 'manage_ledger');

        $this->assertDatabaseMissing('ledger_entries', ['type' => LedgerType::LATE_FEE->value]);
    }

    public function test_duplicate_late_fee_cannot_be_created()
    {
        // Create overdue rent entry
        $rentEntry = LedgerEntry::factory()->overdue()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
        ]);

        // Create first late fee
        $lateFee = $this->ledgerService->generateLateFee($rentEntry, 10000);
        $this->assertNotNull($lateFee);

        // Attempt second late fee
        $response = $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/ledger/{$rentEntry->id}/late-fee", [
                'amount_cents' => 10000,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Late fee already exists for this rent entry',
            ]);
    }

    public function test_ledger_entries_cannot_be_updated()
    {
        $entry = LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Ledger entries are immutable');

        $entry->update(['amount_cents' => 999999]);
    }

    public function test_ledger_entries_cannot_be_deleted()
    {
        $entry = LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Ledger entries cannot be deleted');

        $entry->delete();
    }

    public function test_admin_can_filter_ledger_entries()
    {
        // Create various entries
        $rentEntry = LedgerEntry::factory()->rent()->pending()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
        ]);

        $lateFeeEntry = LedgerEntry::factory()->lateFee()->overdue()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
        ]);

        // Filter by type
        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/ledger?type=late_fee');

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $lateFeeEntry->id])
            ->assertJsonMissing(['id' => $rentEntry->id]);

        // Filter by status
        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/ledger?status=pending');

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $rentEntry->id]);
    }

    public function test_next_rent_entry_can_be_generated()
    {
        // Activate contract (creates first entry)
        $this->contract->update(['status' => ContractStatus::ACTIVE]);

        $firstEntry = LedgerEntry::where('contract_id', $this->contract->id)->first();
        $this->assertNotNull($firstEntry);

        // Generate next entry
        $secondEntry = $this->ledgerService->generateNextRentEntry($this->contract);

        // Verify second entry starts after first one ends
        $this->assertTrue(
            $secondEntry->billing_period_start->eq($firstEntry->billing_period_end->addDay())
        );

        // Verify both entries exist
        $this->assertEquals(2, LedgerEntry::where('contract_id', $this->contract->id)->count());
    }
}
