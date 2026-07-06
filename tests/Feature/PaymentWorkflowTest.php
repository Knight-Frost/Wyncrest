<?php

namespace Tests\Feature;

use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PaymentWorkflowTest
 *
 * Tests payment processing (basic tests - full Stripe tests require test keys)
 */
class PaymentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $landlord;

    protected User $tenant;

    protected Contract $contract;

    protected LedgerEntry $rentEntry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->landlord = User::factory()->landlord()->create();
        $this->tenant = User::factory()->tenant()->create();

        // Create active contract
        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $this->landlord->id,
        ]);

        $this->contract = Contract::factory()->active()->create([
            'listing_id' => $listing->id,
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'rent_amount' => 250000,
            'payment_day' => 1,
        ]);

        // Create rent entry
        $this->rentEntry = LedgerEntry::factory()->rent()->pending()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'amount_cents' => 250000,
        ]);
    }

    public function test_tenant_can_view_balance()
    {
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/tenant/payments/balance');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'balance_cents',
                'balance_dollars',
                'owes_money',
            ]);
    }

    public function test_tenant_balance_calculation()
    {
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/tenant/payments/balance');

        $response->assertStatus(200)
            ->assertJson([
                'balance_cents' => 250000,
                'balance_dollars' => 2500,
                'owes_money' => true,
            ]);
    }

    public function test_balance_advertises_gateway_availability()
    {
        // No STRIPE_SECRET_KEY is set in the test environment, so the API must
        // tell the SPA that online card payments are unavailable rather than
        // letting it render a checkout that can only fail.
        config(['services.stripe.secret' => null]);

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/tenant/payments/balance');

        $response->assertStatus(200)
            ->assertJson(['online_payments_enabled' => false]);
    }

    public function test_initiate_fails_honestly_when_gateway_unconfigured()
    {
        config(['services.stripe.secret' => null]);

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->postJson("/api/tenant/payments/initiate/{$this->rentEntry->id}");

        $response->assertStatus(503)
            ->assertJson(['code' => 'gateway_unavailable']);
    }

    public function test_tenant_balance_after_payment()
    {
        // Create payment entry
        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::PAYMENT,
            'amount_cents' => -250000,
            'status' => LedgerStatus::PAID,
            'related_rent_entry_id' => $this->rentEntry->id,
        ]);

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/tenant/payments/balance');

        $response->assertStatus(200)
            ->assertJson([
                'balance_cents' => 0,
                'balance_dollars' => 0,
                'owes_money' => false,
            ]);
    }
}
