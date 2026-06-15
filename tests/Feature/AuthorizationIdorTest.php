<?php

namespace Tests\Feature;

use App\Enums\ContractStatus;
use App\Enums\LedgerType;
use App\Enums\ListingStatus;
use App\Models\Admin;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Notification;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AuthorizationIdorTest
 *
 * Negative-authorization / IDOR / privilege-escalation suite.
 *
 * GOAL: prove that users CANNOT access resources they don't own and CANNOT
 * escalate privileges. Every assertion here expects a FAILURE status
 * (401/403/404/422). If any of these start passing with a 2xx, that is a
 * SECURITY REGRESSION.
 *
 * Conventions (confirmed against routes/policies/controllers):
 *   - Wrong-role middleware (EnsureTenant/EnsureLandlord/EnsureAdmin) -> 403.
 *   - Cross-owner access is blocked by policy AFTER route-model binding
 *     resolves the entity by id/uuid, so the expected code is 403.
 *     We still accept 404 (binding-miss) defensively where noted.
 *   - Auth tokens issued via Sanctum personal access tokens, sent as Bearer.
 */
class AuthorizationIdorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Issue a Sanctum bearer token and return auth headers.
     */
    private function authHeaders($user): array
    {
        $token = $user->createToken('test')->plainTextToken;

        return ['Authorization' => "Bearer {$token}"];
    }

    /**
     * Build a landlord with a property, unit, and a listing in the given status.
     */
    private function makeListingFor(User $landlord, ListingStatus $status = ListingStatus::DRAFT): Listing
    {
        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);

        return Listing::factory()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
            'status' => $status,
        ]);
    }

    /**
     * Build an active contract between a landlord and tenant (with listing).
     */
    private function makeContract(User $landlord, User $tenant, ContractStatus $status = ContractStatus::ACTIVE): Contract
    {
        $listing = $this->makeListingFor($landlord, ListingStatus::ACTIVE);

        return Contract::factory()->create([
            'listing_id' => $listing->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
            'status' => $status,
        ]);
    }

    // ========================================================================
    // CROSS-OWNER IDOR (landlord vs landlord)
    // ========================================================================

    public function test_landlord_b_cannot_view_landlord_a_property(): void
    {
        $landlordA = User::factory()->landlord()->create();
        $landlordB = User::factory()->landlord()->create();

        $property = Property::factory()->create(['landlord_id' => $landlordA->id]);

        $response = $this->withHeaders($this->authHeaders($landlordB))
            ->getJson("/api/landlord/properties/{$property->id}");

        $this->assertContains($response->status(), [403, 404],
            "Landlord B was able to VIEW landlord A's property (status {$response->status()})");
    }

    public function test_landlord_b_cannot_update_landlord_a_property(): void
    {
        $landlordA = User::factory()->landlord()->create();
        $landlordB = User::factory()->landlord()->create();

        $property = Property::factory()->create([
            'landlord_id' => $landlordA->id,
            'name' => 'Original Name',
        ]);

        $response = $this->withHeaders($this->authHeaders($landlordB))
            ->putJson("/api/landlord/properties/{$property->id}", [
                'name' => 'Hijacked Name',
            ]);

        $this->assertContains($response->status(), [403, 404],
            "Landlord B was able to UPDATE landlord A's property (status {$response->status()})");

        // Defense-in-depth: confirm nothing actually changed.
        $this->assertDatabaseHas('properties', [
            'id' => $property->id,
            'name' => 'Original Name',
        ]);
    }

    public function test_landlord_b_cannot_update_landlord_a_listing(): void
    {
        $landlordA = User::factory()->landlord()->create();
        $landlordB = User::factory()->landlord()->create();

        $listing = $this->makeListingFor($landlordA, ListingStatus::DRAFT);

        $response = $this->withHeaders($this->authHeaders($landlordB))
            ->putJson("/api/landlord/listings/{$listing->id}", [
                'title' => 'Hijacked Title',
            ]);

        $this->assertContains($response->status(), [403, 404],
            "Landlord B was able to UPDATE landlord A's listing (status {$response->status()})");
    }

    public function test_landlord_b_cannot_submit_landlord_a_listing(): void
    {
        $landlordA = User::factory()->landlord()->create();
        $landlordB = User::factory()->landlord()->create();

        $listing = $this->makeListingFor($landlordA, ListingStatus::DRAFT);

        $response = $this->withHeaders($this->authHeaders($landlordB))
            ->postJson("/api/landlord/listings/{$listing->id}/submit");

        $this->assertContains($response->status(), [403, 404],
            "Landlord B was able to SUBMIT landlord A's listing (status {$response->status()})");

        // Status must remain DRAFT — it was not pushed to review.
        $this->assertDatabaseHas('listings', [
            'id' => $listing->id,
            'status' => ListingStatus::DRAFT->value,
        ]);
    }

    public function test_landlord_b_cannot_view_landlord_a_contract(): void
    {
        $landlordA = User::factory()->landlord()->create();
        $landlordB = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();

        $contract = $this->makeContract($landlordA, $tenant);

        $response = $this->withHeaders($this->authHeaders($landlordB))
            ->getJson("/api/landlord/contracts/{$contract->id}");

        $this->assertContains($response->status(), [403, 404],
            "Landlord B was able to VIEW landlord A's contract (status {$response->status()})");
    }

    public function test_landlord_b_cannot_view_landlord_a_ledger_entry(): void
    {
        $landlordA = User::factory()->landlord()->create();
        $landlordB = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();

        $contract = $this->makeContract($landlordA, $tenant);
        $entry = LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'landlord_id' => $landlordA->id,
            'tenant_id' => $tenant->id,
        ]);

        $response = $this->withHeaders($this->authHeaders($landlordB))
            ->getJson("/api/landlord/ledger/{$entry->id}");

        $this->assertContains($response->status(), [403, 404],
            "Landlord B was able to VIEW landlord A's ledger entry (status {$response->status()})");
    }

    // ========================================================================
    // CROSS-TENANT IDOR (tenant vs tenant) — FINANCIAL
    // ========================================================================

    public function test_tenant_b_cannot_view_tenant_a_ledger_entry(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenantA = User::factory()->tenant()->create();
        $tenantB = User::factory()->tenant()->create();

        $contract = $this->makeContract($landlord, $tenantA);
        $entry = LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenantA->id,
        ]);

        $response = $this->withHeaders($this->authHeaders($tenantB))
            ->getJson("/api/tenant/ledger/{$entry->id}");

        $this->assertContains($response->status(), [403, 404],
            "Tenant B was able to VIEW tenant A's ledger entry (status {$response->status()})");
    }

    public function test_tenant_b_cannot_initiate_payment_for_tenant_a_ledger_entry(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenantA = User::factory()->tenant()->create();
        $tenantB = User::factory()->tenant()->create();

        $contract = $this->makeContract($landlord, $tenantA);

        // A genuinely payable (pending RENT) entry owned by tenant A.
        // This isolates the failure cause to OWNERSHIP, not entry state.
        $entry = LedgerEntry::factory()->rent()->pending()->create([
            'contract_id' => $contract->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenantA->id,
            'type' => LedgerType::RENT,
        ]);

        $response = $this->withHeaders($this->authHeaders($tenantB))
            ->postJson("/api/tenant/payments/initiate/{$entry->id}");

        // InitiatePaymentRequest::authorize -> LedgerEntryPolicy@pay -> 403.
        // PaymentService also re-checks ownership as defense-in-depth.
        $this->assertContains($response->status(), [403, 404, 422],
            "Tenant B was able to INITIATE PAYMENT for tenant A's ledger entry (status {$response->status()})");
    }

    public function test_tenant_b_cannot_view_tenant_a_contract(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenantA = User::factory()->tenant()->create();
        $tenantB = User::factory()->tenant()->create();

        $contract = $this->makeContract($landlord, $tenantA, ContractStatus::PENDING_TENANT);

        $response = $this->withHeaders($this->authHeaders($tenantB))
            ->getJson("/api/tenant/contracts/{$contract->id}");

        $this->assertContains($response->status(), [403, 404],
            "Tenant B was able to VIEW tenant A's contract (status {$response->status()})");
    }

    public function test_tenant_b_cannot_accept_tenant_a_contract(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenantA = User::factory()->tenant()->create();
        $tenantB = User::factory()->tenant()->create();

        $contract = $this->makeContract($landlord, $tenantA, ContractStatus::PENDING_TENANT);

        $response = $this->withHeaders($this->authHeaders($tenantB))
            ->postJson("/api/tenant/contracts/{$contract->id}/accept");

        $this->assertContains($response->status(), [403, 404],
            "Tenant B was able to ACCEPT tenant A's contract (status {$response->status()})");

        // Contract must NOT have been activated by the attacker.
        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'status' => ContractStatus::PENDING_TENANT->value,
        ]);
    }

    // ========================================================================
    // ROLE BOUNDARY / PRIVILEGE ESCALATION
    // ========================================================================

    public function test_tenant_cannot_create_property(): void
    {
        $tenant = User::factory()->tenant()->create();

        $response = $this->withHeaders($this->authHeaders($tenant))
            ->postJson('/api/landlord/properties', [
                'name' => 'Evil Property',
                'property_type' => 'single_family',
                'street_address' => '1 Hacker Way',
                'city' => 'Nowhere',
                'state' => 'CA',
                'zip_code' => '90210',
            ]);

        $response->assertStatus(403);
    }

    public function test_tenant_cannot_create_unit(): void
    {
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        $property = Property::factory()->create(['landlord_id' => $landlord->id]);

        $response = $this->withHeaders($this->authHeaders($tenant))
            ->postJson("/api/landlord/properties/{$property->id}/units", [
                'unit_number' => '1A',
                'bedrooms' => 2,
                'bathrooms' => 1,
            ]);

        $response->assertStatus(403);
    }

    public function test_tenant_cannot_create_listing(): void
    {
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);

        $response = $this->withHeaders($this->authHeaders($tenant))
            ->postJson("/api/landlord/units/{$unit->id}/listings", [
                'title' => 'Evil Listing',
                'description' => str_repeat('a', 60),
            ]);

        $response->assertStatus(403);
    }

    public function test_landlord_cannot_initiate_payment(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();

        $contract = $this->makeContract($landlord, $tenant);
        $entry = LedgerEntry::factory()->rent()->pending()->create([
            'contract_id' => $contract->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
        ]);

        // Tenant-only route — landlord blocked by EnsureTenant middleware.
        $response = $this->withHeaders($this->authHeaders($landlord))
            ->postJson("/api/tenant/payments/initiate/{$entry->id}");

        $response->assertStatus(403);
    }

    public function test_landlord_cannot_accept_contract(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();

        $contract = $this->makeContract($landlord, $tenant, ContractStatus::PENDING_TENANT);

        // Tenant-only route — landlord blocked by EnsureTenant middleware.
        $response = $this->withHeaders($this->authHeaders($landlord))
            ->postJson("/api/tenant/contracts/{$contract->id}/accept");

        $response->assertStatus(403);
    }

    public function test_tenant_cannot_access_admin_listing_moderation(): void
    {
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();
        $listing = $this->makeListingFor($landlord, ListingStatus::PENDING_REVIEW);

        $approve = $this->withHeaders($this->authHeaders($tenant))
            ->postJson("/api/admin/listings/{$listing->id}/approve");
        $approve->assertStatus(403);

        $reject = $this->withHeaders($this->authHeaders($tenant))
            ->postJson("/api/admin/listings/{$listing->id}/reject", [
                'reason' => 'Attacker rejection attempt that is long enough',
            ]);
        $reject->assertStatus(403);
    }

    public function test_landlord_cannot_access_admin_feature_management(): void
    {
        $landlordTarget = User::factory()->landlord()->create();
        $attacker = User::factory()->landlord()->create();

        $enable = $this->withHeaders($this->authHeaders($attacker))
            ->postJson("/api/admin/landlords/{$landlordTarget->id}/features/listings/enable");
        $enable->assertStatus(403);

        $disable = $this->withHeaders($this->authHeaders($attacker))
            ->postJson("/api/admin/landlords/{$landlordTarget->id}/features/listings/disable");
        $disable->assertStatus(403);
    }

    public function test_non_admin_cannot_view_audit_logs(): void
    {
        $tenant = User::factory()->tenant()->create();
        $landlord = User::factory()->landlord()->create();

        $this->withHeaders($this->authHeaders($tenant))
            ->getJson('/api/admin/audit-logs')
            ->assertStatus(403);

        $this->withHeaders($this->authHeaders($landlord))
            ->getJson('/api/admin/audit-logs')
            ->assertStatus(403);
    }

    public function test_non_admin_cannot_admin_terminate_contract(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $contract = $this->makeContract($landlord, $tenant);

        // Landlord and tenant are PARTIES to this contract, but the admin
        // terminate endpoint must still reject them (admin-only route).
        $this->withHeaders($this->authHeaders($landlord))
            ->postJson("/api/admin/contracts/{$contract->id}/terminate", [
                'reason' => 'Attacker attempting an admin-level forced termination',
            ])
            ->assertStatus(403);

        $this->withHeaders($this->authHeaders($tenant))
            ->postJson("/api/admin/contracts/{$contract->id}/terminate", [
                'reason' => 'Attacker attempting an admin-level forced termination',
            ])
            ->assertStatus(403);
    }

    public function test_user_cannot_read_another_users_notification(): void
    {
        $owner = User::factory()->tenant()->create();
        $attacker = User::factory()->tenant()->create();

        $notification = Notification::factory()->unread()->create([
            'user_id' => $owner->id,
        ]);

        // Only the mark-as-read route exposes a single notification by id.
        // NotificationPolicy@update must deny a non-owner.
        $response = $this->withHeaders($this->authHeaders($attacker))
            ->patchJson("/api/notifications/{$notification->id}/read");

        $this->assertContains($response->status(), [403, 404],
            "Attacker was able to mark another user's notification as read (status {$response->status()})");

        // The notification must remain unread.
        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'read_at' => null,
        ]);
    }

    // ========================================================================
    // CONTRACT LIFECYCLE AUTHORIZATION (third-party, neither landlord nor tenant)
    // ========================================================================

    public function test_landlord_b_cannot_send_landlord_a_contract(): void
    {
        $landlordA = User::factory()->landlord()->create();
        $landlordB = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();

        $contract = $this->makeContract($landlordA, $tenant, ContractStatus::DRAFT);

        // Only the landlord party may send their own draft contract.
        $response = $this->withHeaders($this->authHeaders($landlordB))
            ->postJson("/api/landlord/contracts/{$contract->id}/send");

        $this->assertContains($response->status(), [403, 404],
            "Landlord B was able to SEND landlord A's contract (status {$response->status()})");

        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'status' => ContractStatus::DRAFT->value,
        ]);
    }

    public function test_third_party_tenant_cannot_view_or_accept_contract(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $thirdParty = User::factory()->tenant()->create();

        $contract = $this->makeContract($landlord, $tenant, ContractStatus::PENDING_TENANT);

        // View
        $view = $this->withHeaders($this->authHeaders($thirdParty))
            ->getJson("/api/tenant/contracts/{$contract->id}");
        $this->assertContains($view->status(), [403, 404],
            "Third-party tenant was able to VIEW a contract they are not a party to (status {$view->status()})");

        // Accept
        $accept = $this->withHeaders($this->authHeaders($thirdParty))
            ->postJson("/api/tenant/contracts/{$contract->id}/accept");
        $this->assertContains($accept->status(), [403, 404],
            "Third-party tenant was able to ACCEPT a contract they are not a party to (status {$accept->status()})");
    }

    public function test_third_party_landlord_cannot_terminate_contract(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $thirdParty = User::factory()->landlord()->create();

        $contract = $this->makeContract($landlord, $tenant, ContractStatus::ACTIVE);

        // TerminateContractRequest::authorize delegates to ContractPolicy@terminate;
        // a non-party landlord must be rejected.
        $response = $this->withHeaders($this->authHeaders($thirdParty))
            ->postJson("/api/landlord/contracts/{$contract->id}/terminate", [
                'reason' => 'Third-party landlord attempting to terminate a contract',
            ]);

        $this->assertContains($response->status(), [403, 404],
            "Third-party landlord was able to TERMINATE a contract they are not a party to (status {$response->status()})");

        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'status' => ContractStatus::ACTIVE->value,
        ]);
    }
}
