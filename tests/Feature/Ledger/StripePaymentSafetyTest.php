<?php

namespace Tests\Feature\Ledger;

use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Enums\PaymentMethod;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\Ledger\UnappliedPaymentException;
use App\Services\LedgerService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * StripePaymentSafetyTest (forensic audit WYN-003 / WYN-005)
 *
 * WYN-003: Stripe can capture money for an obligation Wyncrest already
 * considers settled (e.g. the landlord recorded a cash payment first, or a
 * second checkout panel was confirmed). recordSuccessfulPayment() used to throw
 * before writing any record, the webhook answered 500, Stripe retried for days,
 * and the captured charge was visible only in log lines. It must now leave one
 * critical, deduplicated audit record and stop the retry loop, without booking
 * any ledger credit (refund/credit handling is an owner decision).
 *
 * WYN-005: every checkout open minted a NEW PaymentIntent for the same
 * obligation, so two open panels could each capture a charge. Intent creation
 * must carry a deterministic Stripe idempotency key per obligation.
 *
 * Stripe is never contacted: the client is a Mockery double and the webhook is
 * signed locally with a test-only secret. Amounts are synthetic.
 */
class StripePaymentSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_WEBHOOK_SECRET = 'whsec_forensic_test_only';

    protected User $landlord;

    protected User $tenant;

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
        $contract = Contract::factory()->active()->create([
            'listing_id' => $listing->id,
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'rent_amount' => 250000,
            'payment_day' => 1,
        ]);

        $this->rentEntry = LedgerEntry::factory()->rent()->pending()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'amount_cents' => 250000,
            'currency' => 'GHS',
        ]);
    }

    protected function stripeDouble(object $intents): PaymentService
    {
        $stripe = Mockery::mock(StripeClient::class);
        $stripe->paymentIntents = $intents;

        $service = app(PaymentService::class);
        (new \ReflectionProperty(PaymentService::class, 'stripe'))->setValue($service, $stripe);

        return $service;
    }

    protected function succeededIntent(string $id = 'pi_test_unapplied'): object
    {
        return (object) [
            'id' => $id,
            'status' => 'succeeded',
            'amount' => 250000,
            'amount_received' => 250000,
            'currency' => 'ghs',
            'metadata' => (object) ['ledger_entry_id' => $this->rentEntry->id],
        ];
    }

    protected function serviceRetrieving(object $intent): PaymentService
    {
        $intents = Mockery::mock();
        $intents->shouldReceive('retrieve')->andReturn($intent);

        return $this->stripeDouble($intents);
    }

    protected function settleManually(): void
    {
        app(LedgerService::class)->recordManualPayment(
            $this->rentEntry, PaymentMethod::CASH, 'SYNTH-RCPT-001', $this->landlord
        );
    }

    protected function postSignedWebhook(string $type, string $intentId)
    {
        config(['services.stripe.webhook_secret' => self::TEST_WEBHOOK_SECRET]);

        $payload = json_encode([
            'id' => 'evt_synthetic_1',
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => ['id' => $intentId, 'object' => 'payment_intent', 'amount' => 250000]],
        ]);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", self::TEST_WEBHOOK_SECRET);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    protected function unappliedAuditRows(string $intentId)
    {
        return AuditLog::where('action', 'payment_unapplied')->get()
            ->filter(fn (AuditLog $row) => ($row->metadata['stripe_payment_intent_id'] ?? null) === $intentId);
    }

    public function test_capture_against_an_already_settled_obligation_leaves_one_critical_record_and_no_credit(): void
    {
        $this->settleManually();
        $service = $this->serviceRetrieving($this->succeededIntent());
        $paymentsBefore = LedgerEntry::where('type', LedgerType::PAYMENT)->count();

        foreach ([1, 2] as $delivery) {
            try {
                $service->recordSuccessfulPayment('pi_test_unapplied');
                $this->fail("Delivery {$delivery} must not be booked against a settled obligation.");
            } catch (UnappliedPaymentException) {
                // expected on every delivery
            }
        }

        $rows = $this->unappliedAuditRows('pi_test_unapplied');
        $this->assertCount(1, $rows, 'Exactly one durable record, however often Stripe redelivers.');
        $this->assertSame('critical', $rows->first()->severity);
        $this->assertSame($paymentsBefore, LedgerEntry::where('type', LedgerType::PAYMENT)->count(), 'No ledger credit is invented.');
        $this->assertSame(LedgerStatus::PAID, $this->rentEntry->fresh()->status);
        $this->assertSame(0, $service->getTenantBalance($this->tenant));
    }

    public function test_signed_webhook_for_an_unapplied_capture_is_acknowledged_so_stripe_stops_retrying(): void
    {
        $this->settleManually();
        $this->app->instance(PaymentService::class, $this->serviceRetrieving($this->succeededIntent()));

        $this->postSignedWebhook('payment_intent.succeeded', 'pi_test_unapplied')
            ->assertOk()
            ->assertJson(['status' => 'unapplied']);

        $this->assertCount(1, $this->unappliedAuditRows('pi_test_unapplied'));
    }

    public function test_signed_webhook_success_path_settles_the_obligation_end_to_end(): void
    {
        $this->app->instance(PaymentService::class, $this->serviceRetrieving($this->succeededIntent('pi_test_ok')));

        $this->postSignedWebhook('payment_intent.succeeded', 'pi_test_ok')->assertOk()->assertJson(['status' => 'success']);
        // Redelivery is idempotent through the HTTP entry point too.
        $this->postSignedWebhook('payment_intent.succeeded', 'pi_test_ok')->assertOk();

        $this->assertSame(LedgerStatus::PAID, $this->rentEntry->fresh()->status);
        $this->assertSame(1, LedgerEntry::where('stripe_payment_intent_id', 'pi_test_ok')->where('type', LedgerType::PAYMENT)->count());
        $this->assertSame(0, (int) LedgerEntry::where('contract_id', $this->rentEntry->contract_id)->sum('amount_cents'));
    }

    public function test_payment_intent_creation_uses_one_idempotency_key_per_obligation(): void
    {
        $keys = [];
        $intents = Mockery::mock();
        $intents->shouldReceive('create')->twice()
            ->withArgs(function (array $params, $opts = null) use (&$keys) {
                $keys[] = is_array($opts) ? ($opts['idempotency_key'] ?? null) : null;

                return $params['amount'] === 250000;
            })
            ->andReturn((object) ['id' => 'pi_test_same', 'client_secret' => 'cs_synthetic']);
        $service = $this->stripeDouble($intents);

        $service->createPaymentIntent($this->rentEntry, $this->tenant);
        $service->createPaymentIntent($this->rentEntry, $this->tenant);

        $this->assertNotNull($keys[0], 'Intent creation must send a Stripe idempotency key.');
        $this->assertSame($keys[0], $keys[1], 'Two checkout opens for the same obligation must share one key.');
        $this->assertStringContainsString($this->rentEntry->id, $keys[0]);
    }
}
