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
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * PaymentSettlementTest
 *
 * Proves that a successful Stripe payment actually settles the obligation:
 * the rent entry transitions to PAID, redelivered webhooks are idempotent,
 * mismatched amounts are refused, and the entry can no longer be paid twice
 * (webhook + manual). This is the success path the webhook signature tests
 * never covered.
 */
class PaymentSettlementTest extends TestCase
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

        $this->rentEntry = LedgerEntry::factory()->rent()->pending()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'amount_cents' => 250000,
            'currency' => 'GHS',
        ]);
    }

    /**
     * Build a PaymentService whose Stripe client returns the given intent.
     */
    protected function paymentServiceReturning(object $intent): PaymentService
    {
        $intents = Mockery::mock();
        $intents->shouldReceive('retrieve')->andReturn($intent);

        $stripe = Mockery::mock(StripeClient::class);
        $stripe->paymentIntents = $intents;

        $service = app(PaymentService::class);
        $reflection = new \ReflectionProperty(PaymentService::class, 'stripe');
        $reflection->setValue($service, $stripe);

        return $service;
    }

    protected function succeededIntent(string $id = 'pi_test_123', ?int $amount = null, string $currency = 'ghs', string $status = 'succeeded'): object
    {
        return (object) [
            'id' => $id,
            'status' => $status,
            'amount' => $amount ?? $this->rentEntry->amount_cents,
            'amount_received' => $amount ?? $this->rentEntry->amount_cents,
            'currency' => $currency,
            'metadata' => (object) ['ledger_entry_id' => $this->rentEntry->id],
        ];
    }

    public function test_successful_payment_settles_the_obligation()
    {
        $service = $this->paymentServiceReturning($this->succeededIntent());

        $payment = $service->recordSuccessfulPayment('pi_test_123');

        $this->assertEquals(LedgerType::PAYMENT, $payment->type);
        $this->assertEquals(-250000, $payment->amount_cents);

        // The core fix: the rent entry itself must now be PAID.
        $this->assertEquals(LedgerStatus::PAID, $this->rentEntry->fresh()->status);
        $this->assertEquals(0, $service->getTenantBalance($this->tenant));
    }

    public function test_redelivered_webhook_is_idempotent()
    {
        $service = $this->paymentServiceReturning($this->succeededIntent());

        $first = $service->recordSuccessfulPayment('pi_test_123');
        $second = $service->recordSuccessfulPayment('pi_test_123');

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, LedgerEntry::where('type', LedgerType::PAYMENT)->count());
        $this->assertEquals(0, $service->getTenantBalance($this->tenant));
    }

    public function test_amount_mismatch_is_refused_and_audited()
    {
        $service = $this->paymentServiceReturning($this->succeededIntent(amount: 100));

        try {
            $service->recordSuccessfulPayment('pi_test_123');
            $this->fail('Expected mismatch exception');
        } catch (\Exception $e) {
            $this->assertStringContainsString('does not match', $e->getMessage());
        }

        $this->assertEquals(LedgerStatus::PENDING, $this->rentEntry->fresh()->status);
        $this->assertEquals(0, LedgerEntry::where('type', LedgerType::PAYMENT)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment_amount_mismatch']);
    }

    public function test_non_succeeded_intent_is_refused()
    {
        $service = $this->paymentServiceReturning($this->succeededIntent(status: 'processing'));

        $this->expectExceptionMessage('is not succeeded');
        $service->recordSuccessfulPayment('pi_test_123');
    }

    public function test_settled_entry_cannot_be_paid_again_manually()
    {
        $service = $this->paymentServiceReturning($this->succeededIntent());
        $service->recordSuccessfulPayment('pi_test_123');

        $this->actingAs($this->landlord);
        $response = $this->postJson("/api/landlord/ledger/{$this->rentEntry->id}/record-payment", [
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(403); // policy: entry is no longer due
        $this->assertEquals(1, LedgerEntry::where('type', LedgerType::PAYMENT)->count());
    }

    public function test_stale_status_transition_fails_instead_of_double_applying()
    {
        $copyA = LedgerEntry::find($this->rentEntry->id);
        $copyB = LedgerEntry::find($this->rentEntry->id);

        $this->assertTrue($copyA->transitionStatus(LedgerStatus::PAID));

        // copyB still believes the entry is PENDING; the compare-and-swap
        // must refuse rather than stamp OVERDUE over a PAID row.
        $this->assertFalse($copyB->transitionStatus(LedgerStatus::OVERDUE));
        $this->assertEquals(LedgerStatus::PAID, $this->rentEntry->fresh()->status);
    }

    public function test_paid_entries_are_never_marked_overdue()
    {
        $service = $this->paymentServiceReturning($this->succeededIntent());
        $service->recordSuccessfulPayment('pi_test_123');

        $this->travel(2)->months();
        $count = app(\App\Services\LedgerAutomationService::class)->markOverdueEntries();

        $this->assertEquals(0, $count);
        $this->assertEquals(LedgerStatus::PAID, $this->rentEntry->fresh()->status);
    }
}
