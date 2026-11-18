<?php

namespace Tests\Feature\Ledger;

use App\Enums\LedgerType;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * First-of-month rent policy, end to end, through the normal entry points:
 * landlord API (create, send) → tenant API (accept) → the artisan commands the
 * scheduler runs (ledger:generate-rent, ledger:mark-overdue). Expected dates
 * and amounts are hard-coded synthetic fixtures, never recomputed.
 */
class RentPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    private User $tenant;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->landlord = User::factory()->landlord()->create();
        $this->tenant = User::factory()->tenant()->create();
        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $this->listing = Listing::factory()->active()->create(['unit_id' => $unit->id, 'landlord_id' => $this->landlord->id]);
    }

    private function signLease(string $start, ?string $end = null, int $rent = 250000): Contract
    {
        $create = $this->actingAs($this->landlord, 'sanctum')->postJson('/api/landlord/contracts', [
            'listing_id' => $this->listing->id,
            'tenant_id' => $this->tenant->id,
            'rent_amount' => $rent,
            'currency' => 'GHS',
            'start_date' => $start,
            'end_date' => $end,
        ])->assertCreated();
        $id = $create->json('contract.id');

        $this->actingAs($this->landlord, 'sanctum')->postJson("/api/landlord/contracts/{$id}/send")->assertOk();
        $this->actingAs($this->tenant, 'sanctum')->postJson("/api/tenant/contracts/{$id}/accept")->assertOk();

        return Contract::findOrFail($id);
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: int, 4: string}> */
    private function rentRows(Contract $c): array
    {
        return LedgerEntry::where('contract_id', $c->id)->where('type', LedgerType::RENT)->orderBy('billing_period_start')->get()
            ->map(fn (LedgerEntry $e) => [
                $e->billing_period_start->toDateString(),
                $e->billing_period_end->toDateString(),
                $e->due_date->toDateString(),
                $e->amount_cents,
                $e->status->value,
            ])->all();
    }

    private function at(string $moment): void
    {
        Carbon::setTestNow(Carbon::parse($moment, 'UTC'));
    }

    public function test_new_lease_is_calendar_billed_and_due_on_the_first(): void
    {
        $this->at('2026-11-20 09:00');
        $c = $this->signLease('2026-12-01', '2027-11-30');

        $this->assertSame('calendar', $c->billing_anchor->value);
        $this->assertSame(1, $c->payment_day);
        $this->assertSame([['2026-12-01', '2026-12-31', '2026-12-01', 250000, 'pending']], $this->rentRows($c));

        // On the due date itself the charge is still current.
        $this->at('2026-12-01 23:00');
        $this->artisan('ledger:mark-overdue')->assertSuccessful();
        $this->assertSame('pending', $this->rentRows($c)[0][4]);

        // The day after the due date (grace 0) it is overdue.
        $this->at('2026-12-02 00:30');
        $this->artisan('ledger:mark-overdue')->assertSuccessful();
        $this->assertSame('overdue', $this->rentRows($c)[0][4]);
    }

    public function test_landlord_cannot_choose_another_due_day(): void
    {
        $this->at('2026-11-20 09:00');
        $this->actingAs($this->landlord, 'sanctum')->postJson('/api/landlord/contracts', [
            'listing_id' => $this->listing->id,
            'tenant_id' => $this->tenant->id,
            'rent_amount' => 250000,
            'payment_day' => 15,
            'start_date' => '2026-12-01',
        ])->assertStatus(422)->assertJsonValidationErrors('payment_day');
    }

    public function test_mid_month_start_then_first_recurring_calendar_month_and_december_rollover(): void
    {
        $this->at('2026-11-02 09:00');
        $c = $this->signLease('2026-11-10', '2027-11-09');

        // Opening stub Nov 10–30 = 21/30 days of 250000 = 175000, due move-in day.
        $this->assertSame([['2026-11-10', '2026-11-30', '2026-11-10', 175000, 'pending']], $this->rentRows($c));

        // Scheduler later in November: same period identity, nothing new.
        $this->at('2026-11-25 01:00');
        $this->artisan('ledger:generate-rent')->assertSuccessful();
        $this->assertCount(1, $this->rentRows($c));

        // Dec 1 and Jan 1 runs (twice each: repeats are no-ops).
        foreach (['2026-12-01 01:00', '2026-12-01 01:05', '2027-01-01 01:00', '2027-01-01 01:05'] as $at) {
            $this->at($at);
            $this->artisan('ledger:generate-rent')->assertSuccessful();
        }

        $this->assertSame([
            ['2026-11-10', '2026-11-30', '2026-11-10', 175000, 'pending'],
            ['2026-12-01', '2026-12-31', '2026-12-01', 250000, 'pending'],
            ['2027-01-01', '2027-01-31', '2027-01-01', 250000, 'pending'],
        ], array_map(fn ($r) => [$r[0], $r[1], $r[2], $r[3], 'pending'], $this->rentRows($c)));
        $this->assertSame(675000, (int) LedgerEntry::where('contract_id', $c->id)->sum('amount_cents'));
    }

    public function test_final_partial_month_is_prorated_and_nothing_bills_after_the_lease(): void
    {
        $this->at('2026-11-20 09:00');
        $c = $this->signLease('2026-12-01', '2027-02-14');

        foreach (['2027-01-01 01:00', '2027-02-01 01:00', '2027-03-01 01:00'] as $at) {
            $this->at($at);
            $this->artisan('ledger:generate-rent')->assertSuccessful();
        }

        // Feb 1–14 of a 28-day February = exactly half.
        $this->assertSame([
            ['2026-12-01', '2026-12-31', '2026-12-01', 250000],
            ['2027-01-01', '2027-01-31', '2027-01-01', 250000],
            ['2027-02-01', '2027-02-14', '2027-02-01', 125000],
        ], array_map(fn ($r) => array_slice($r, 0, 4), $this->rentRows($c)));
    }

    public function test_billing_time_zone_decides_the_calendar_day(): void
    {
        $this->at('2026-12-15 09:00');
        $c = $this->signLease('2027-01-01');

        // 2027-01-31 23:30 UTC is still Jan 31 in Accra but already Feb 1 in
        // Auckland (UTC+13 in summer).
        $this->at('2027-01-31 23:30');
        $this->artisan('ledger:generate-rent')->assertSuccessful();
        $this->assertCount(1, $this->rentRows($c), 'Accra: still January, no February charge yet');

        config(['billing.timezone' => 'Pacific/Auckland']);
        $this->artisan('ledger:generate-rent')->assertSuccessful();
        $this->assertSame(['2027-02-01', '2027-02-28', '2027-02-01', 250000, 'pending'], $this->rentRows($c)[1]);

        // Overdue follows the same calendar: Feb 1 charge, at 2027-02-01
        // 12:00 UTC it is Feb 2 01:00 in Auckland → overdue; in Accra → not.
        $this->at('2027-02-01 12:00');
        config(['billing.timezone' => 'Africa/Accra']);
        $this->artisan('ledger:mark-overdue')->assertSuccessful();
        $this->assertSame('pending', $this->rentRows($c)[1][4]);
        config(['billing.timezone' => 'Pacific/Auckland']);
        $this->artisan('ledger:mark-overdue')->assertSuccessful();
        $this->assertSame('overdue', $this->rentRows($c)[1][4]);
    }

    public function test_grace_days_delay_overdue_marking_without_moving_the_due_date(): void
    {
        config(['billing.grace_days' => 3]);
        $this->at('2026-11-20 09:00');
        $c = $this->signLease('2026-12-01');

        $this->at('2026-12-04 12:00');
        $this->artisan('ledger:mark-overdue')->assertSuccessful();
        $this->assertSame(['2026-12-01', 'pending'], [$this->rentRows($c)[0][2], $this->rentRows($c)[0][4]]);

        $this->at('2026-12-05 00:10');
        $this->artisan('ledger:mark-overdue')->assertSuccessful();
        $this->assertSame(['2026-12-01', 'overdue'], [$this->rentRows($c)[0][2], $this->rentRows($c)[0][4]]);
    }

    public function test_late_fee_cannot_be_applied_on_the_due_date(): void
    {
        $this->at('2026-11-20 09:00');
        $c = $this->signLease('2026-12-01');
        $rent = LedgerEntry::where('contract_id', $c->id)->firstOrFail();

        $this->at('2026-12-01 18:00');
        $this->assertFalse($rent->fresh()->isOverdue(), 'due today is not overdue (was true from 00:00:01 before)');

        $this->at('2026-12-02 00:01');
        $this->assertTrue($rent->fresh()->isOverdue());
    }
}
