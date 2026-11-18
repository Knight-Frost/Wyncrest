<?php

namespace Tests\Feature\Ledger;

use App\Enums\ContractStatus;
use App\Enums\LedgerType;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\LedgerDueDateRevision;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Notification;
use App\Models\Property;
use App\Models\RentDueScheduleChange;
use App\Models\Unit;
use App\Models\User;
use App\Services\LedgerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two admin due-date controls, through the admin API, with receiving-end
 * checks on the tenant and landlord APIs. All values are synthetic and
 * hard-coded.
 */
class RentDueDateControlsTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    private User $tenant;

    private Admin $ledgerAdmin;

    private Admin $viewOnlyAdmin;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-10 10:00', 'UTC'));

        $this->landlord = User::factory()->landlord()->create();
        $this->tenant = User::factory()->tenant()->create();
        $this->ledgerAdmin = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => ['manage_ledger']]);
        $this->viewOnlyAdmin = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => []]);

        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->active()->create(['unit_id' => $unit->id, 'landlord_id' => $this->landlord->id]);

        $this->contract = Contract::factory()->create([
            'listing_id' => $listing->id,
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'status' => ContractStatus::PENDING_TENANT,
            'rent_amount' => 250000,
            'currency' => 'GHS',
            'payment_day' => 1,
            'billing_anchor' => 'calendar',
            'start_date' => '2026-12-01',
            'end_date' => '2027-11-30',
        ]);
        // Activation posts the December charge (due Dec 1), as in production.
        $this->contract->update(['status' => ContractStatus::ACTIVE]);
        $this->artisan('ledger:mark-overdue');
    }

    private function decemberRent(): LedgerEntry
    {
        return LedgerEntry::where('contract_id', $this->contract->id)->where('type', LedgerType::RENT)
            ->whereDate('billing_period_start', '2026-12-01')->firstOrFail();
    }

    private function adjust(LedgerEntry $e, string $date, ?Admin $as = null, array $overrides = [])
    {
        return $this->actingAs($as ?? $this->ledgerAdmin, 'admin')->postJson("/api/admin/ledger/{$e->id}/due-date", array_merge([
            'due_date' => $date,
            'reason' => 'Tenant salary delayed; agreed one-time extension',
            'expected_due_date' => $e->due_date->toDateString(),
            'expected_status' => $e->status->value,
        ], $overrides));
    }

    /* ─────────────── One-time adjustment ─────────────── */

    public function test_extension_makes_an_overdue_charge_current_and_keeps_its_late_fee(): void
    {
        $rent = $this->decemberRent();
        $this->assertSame(['2026-12-01', 'overdue'], [$rent->due_date->toDateString(), $rent->status->value]);
        $fee = app(LedgerService::class)->generateLateFee($rent, 10000, $this->ledgerAdmin);
        $balanceBefore = (int) LedgerEntry::where('contract_id', $this->contract->id)->sum('amount_cents');
        $referenceBefore = $this->actingAs($this->tenant, 'sanctum')->getJson("/api/tenant/ledger/{$rent->id}")->json('reference');

        $preview = $this->actingAs($this->ledgerAdmin, 'admin')
            ->getJson("/api/admin/ledger/{$rent->id}/due-date/preview?due_date=2026-12-20")
            ->assertOk()->json();
        $this->assertTrue($preview['eligible']);
        $this->assertSame(['2026-12-01', '2026-12-20', 'overdue', 'pending'], [$preview['current_due_date'], $preview['proposed_due_date'], $preview['current_status'], $preview['resulting_status']]);
        $this->assertSame($fee->id, $preview['late_fee']['id']);
        $this->assertSame(1, LedgerEntry::where('contract_id', $this->contract->id)->where('type', 'rent')->count(), 'preview never writes');

        $this->adjust($rent, '2026-12-20')->assertOk()->assertJsonPath('changed', true)
            ->assertJsonPath('entry.due_date_adjusted', true);

        $rent->refresh();
        $this->assertSame(['2026-12-20', '2026-12-01', 'pending', 250000, '2026-12-01', '2026-12-31'], [
            $rent->due_date->toDateString(), $rent->original_due_date->toDateString(), $rent->status->value,
            $rent->amount_cents, $rent->billing_period_start->toDateString(), $rent->billing_period_end->toDateString(),
        ]);
        // Late fee untouched; no new rows; balance unchanged.
        $this->assertSame(['pending', 10000], [$fee->fresh()->status->value, $fee->fresh()->amount_cents]);
        $this->assertSame(2, LedgerEntry::where('contract_id', $this->contract->id)->count());
        $this->assertSame($balanceBefore, (int) LedgerEntry::where('contract_id', $this->contract->id)->sum('amount_cents'));

        // History + audit.
        $rev = LedgerDueDateRevision::sole();
        $this->assertSame(['2026-12-01', '2026-12-20', 'overdue', 'pending', $this->ledgerAdmin->id],
            [$rev->previous_due_date->toDateString(), $rev->new_due_date->toDateString(), $rev->previous_status, $rev->new_status, $rev->admin_id]);
        $log = AuditLog::where('action', 'rent_due_date_adjusted')->sole();
        $this->assertSame(Admin::class, $log->actor_type);
        $this->assertSame('2026-12-01', $log->metadata['previous_due_date']);
        $this->assertSame('2026-12-20', $log->metadata['new_due_date']);

        // Receiving end: tenant and landlord see the same effective date, the
        // same reference, and a notice.
        $tenantView = $this->actingAs($this->tenant, 'sanctum')->getJson("/api/tenant/ledger/{$rent->id}")->assertOk();
        $landlordView = $this->actingAs($this->landlord, 'sanctum')->getJson("/api/landlord/ledger/{$rent->id}")->assertOk();
        foreach ([$tenantView, $landlordView] as $view) {
            $this->assertStringStartsWith('2026-12-20', $view->json('due_date'));
            $this->assertSame('2026-12-01', $view->json('original_due_date'));
            $this->assertSame($referenceBefore, $view->json('reference'), 'reference is built from the posted date');
        }
        $this->assertSame('pending', $tenantView->json('status'));
        foreach ([$this->tenant, $this->landlord] as $user) {
            $n = Notification::where('user_id', $user->id)->where('type', 'rent_due_date_changed')->sole();
            $this->assertStringContainsString('20 Dec 2026', $n->message);
        }

        // Exports carry the effective date.
        $csv = $this->actingAs($this->ledgerAdmin, 'admin')->get('/api/admin/ledger/export?contract_id='.$this->contract->id)->streamedContent();
        $this->assertStringContainsString('2026-12-20', $csv);
        $landlordCsv = $this->actingAs($this->landlord, 'sanctum')->get('/api/landlord/ledger/export?contract_id='.$this->contract->id)->streamedContent();
        $this->assertStringContainsString('2026-12-20', $landlordCsv);

        // The overdue sweep respects the new date (grace 0).
        Carbon::setTestNow(Carbon::parse('2026-12-20 23:00', 'UTC'));
        $this->artisan('ledger:mark-overdue');
        $this->assertSame('pending', $rent->fresh()->status->value);
        Carbon::setTestNow(Carbon::parse('2026-12-21 00:30', 'UTC'));
        $this->artisan('ledger:mark-overdue');
        $this->assertSame('overdue', $rent->fresh()->status->value);
    }

    public function test_an_eligible_earlier_date_is_allowed_but_never_in_the_past(): void
    {
        // January charge (pending, due Jan 1) after the Jan 1 run, viewed on Dec 10.
        Carbon::setTestNow(Carbon::parse('2027-01-01 01:00', 'UTC'));
        $this->artisan('ledger:generate-rent');
        $jan = LedgerEntry::whereDate('billing_period_start', '2027-01-01')->sole();
        Carbon::setTestNow(Carbon::parse('2026-12-10 10:00', 'UTC'));

        $this->adjust($jan, '2026-12-09')->assertStatus(422)->assertJsonPath('code', 'refused');
        $this->adjust($jan, '2026-11-30')->assertStatus(422);
        $this->adjust($jan, '2026-12-28')->assertOk();
        $this->assertSame(['2026-12-28', 'pending'], [$jan->fresh()->due_date->toDateString(), $jan->fresh()->status->value]);

        // Moving it earlier did not make it overdue, and no fee is possible yet.
        $this->assertFalse($jan->fresh()->isOverdue());
        $this->expectException(\InvalidArgumentException::class);
        app(LedgerService::class)->generateLateFee($jan->fresh(), 10000, $this->ledgerAdmin);
    }

    public function test_window_end_is_enforced(): void
    {
        // Dec period ends Dec 31; default window is 60 days → Mar 1 2027.
        $this->adjust($this->decemberRent(), '2027-03-01')->assertOk();
        $rent = $this->decemberRent();
        $this->adjust($rent, '2027-03-02')->assertStatus(422);
    }

    public function test_paid_and_waived_charges_and_late_fees_are_protected(): void
    {
        $rent = $this->decemberRent();
        $fee = app(LedgerService::class)->generateLateFee($rent, 10000, $this->ledgerAdmin);
        $this->adjust($fee, '2026-12-20')->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Late fees'));

        $payment = app(LedgerService::class)->recordManualPayment($rent, \App\Enums\PaymentMethod::MOBILE_MONEY_MTN, 'MM-123', $this->landlord);
        $receipt = $this->actingAs($this->tenant, 'sanctum')->getJson("/api/tenant/ledger/{$payment->id}")->json();

        $paid = $rent->fresh();
        $this->adjust($paid, '2026-12-20')->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'paid'));
        $this->assertSame('2026-12-01', $paid->fresh()->due_date->toDateString());
        $this->assertSame($receipt, $this->actingAs($this->tenant, 'sanctum')->getJson("/api/tenant/ledger/{$payment->id}")->json(), 'receipt unchanged');
        $this->assertSame(0, LedgerDueDateRevision::count());
    }

    public function test_stale_preview_conflicts_and_repeats_have_one_effect(): void
    {
        $rent = $this->decemberRent();

        // A payment lands after the admin previewed: their submit is stale.
        $stale = clone $rent;
        app(LedgerService::class)->recordManualPayment($rent, \App\Enums\PaymentMethod::CASH, null, $this->landlord);
        $this->adjust($stale, '2026-12-20')->assertStatus(409)->assertJsonPath('code', 'stale')
            ->assertJsonPath('current.status', 'paid');

        // A second admin changes the date first.
        Carbon::setTestNow(Carbon::parse('2027-01-01 01:00', 'UTC'));
        $this->artisan('ledger:generate-rent');
        $jan = LedgerEntry::whereDate('billing_period_start', '2027-01-01')->sole();
        $this->adjust($jan, '2027-01-15')->assertOk()->assertJsonPath('changed', true);
        $this->adjust($jan, '2027-01-20')->assertStatus(409); // still holds the Jan 1 preview
        // The same request again (double-click / retry) is a no-op success.
        $this->adjust($jan, '2027-01-15')->assertOk()->assertJsonPath('changed', false);

        $this->assertSame(1, LedgerDueDateRevision::where('ledger_entry_id', $jan->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'rent_due_date_adjusted')->count());
        $this->assertSame(1, Notification::where('user_id', $this->tenant->id)->where('type', 'rent_due_date_changed')->count());
    }

    public function test_overdue_sweep_does_not_overwrite_a_concurrent_extension(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-01 01:00', 'UTC'));
        $this->artisan('ledger:generate-rent');
        $jan = LedgerEntry::whereDate('billing_period_start', '2027-01-01')->sole();

        // The sweep selected the entry on Jan 2 (past due) ...
        Carbon::setTestNow(Carbon::parse('2027-01-02 02:00', 'UTC'));
        $selectedBySweep = LedgerEntry::find($jan->id);
        // ... then an admin extended it before the sweep's UPDATE ran.
        $this->adjust($jan->fresh(), '2027-01-10')->assertOk();

        $this->assertFalse($selectedBySweep->markOverdueIfPastDue('2027-01-02'));
        $this->assertSame(['2027-01-10', 'pending'], [$jan->fresh()->due_date->toDateString(), $jan->fresh()->status->value]);
    }

    public function test_permissions_for_the_one_charge_control(): void
    {
        $rent = $this->decemberRent();

        $this->actingAs($this->viewOnlyAdmin, 'admin')->getJson("/api/admin/ledger/{$rent->id}/due-date/preview?due_date=2026-12-20")->assertForbidden();
        $this->adjust($rent, '2026-12-20', $this->viewOnlyAdmin)->assertForbidden();
        // Every admin can still read the ledger entry and the lease schedule.
        $this->actingAs($this->viewOnlyAdmin, 'admin')->getJson("/api/admin/ledger/{$rent->id}")->assertOk();
        $this->actingAs($this->viewOnlyAdmin, 'admin')->getJson("/api/admin/contracts/{$this->contract->id}/rent-schedule")->assertOk();

        // Tenant/landlord bearer tokens never reach admin routes.
        foreach ([$this->tenant, $this->landlord] as $user) {
            $this->app['auth']->forgetGuards();
            $status = $this->actingAs($user, 'sanctum')->postJson("/api/admin/ledger/{$rent->id}/due-date", [])->status();
            $this->assertContains($status, [401, 403]);
        }
        $this->app['auth']->forgetGuards();

        // A reason is mandatory.
        $this->adjust($rent, '2026-12-20', null, ['reason' => ''])->assertStatus(422)->assertJsonValidationErrors('reason');

        $super = Admin::factory()->superAdmin()->create();
        $this->adjust($rent, '2026-12-20', $super)->assertOk();
        $this->assertSame(0, LedgerDueDateRevision::where('admin_id', $this->viewOnlyAdmin->id)->count());
    }

    /* ─────────────── Future schedule ─────────────── */

    private function changeSchedule(int $day, string $month, ?Admin $as = null, ?int $version = null)
    {
        $version ??= (int) RentDueScheduleChange::where('contract_id', $this->contract->id)->max('id');

        return $this->actingAs($as ?? $this->ledgerAdmin, 'admin')->postJson("/api/admin/contracts/{$this->contract->id}/rent-schedule", [
            'due_day' => $day,
            'effective_month' => $month,
            'reason' => 'Tenant is paid on the last day of the month',
            'expected_version' => $version,
        ]);
    }

    public function test_future_schedule_day_31_clamps_and_a_return_to_default_applies_later(): void
    {
        // Dec is issued, so the earliest month is January 2027.
        $this->changeSchedule(31, '2026-12')->assertStatus(422);

        $preview = $this->actingAs($this->ledgerAdmin, 'admin')
            ->getJson("/api/admin/contracts/{$this->contract->id}/rent-schedule/preview?due_day=31&effective_month=2027-01")
            ->assertOk()->json();
        $this->assertTrue($preview['eligible']);
        $this->assertSame('2027-01-01', $preview['first_affected_period_start']);
        $this->assertSame(
            [['2027-01-31', '2027-01-01'], ['2027-02-28', '2027-02-01'], ['2027-03-31', '2027-03-01'], ['2027-04-30', '2027-04-01']],
            array_map(fn ($p) => [$p['due_date'], $p['current_due_date']], array_slice($preview['periods'], 0, 4))
        );
        $this->assertSame(0, RentDueScheduleChange::count(), 'preview never writes');

        $this->changeSchedule(31, '2027-01')->assertOk()->assertJsonPath('changed', true);
        $this->changeSchedule(31, '2027-01', null, 0)->assertOk()->assertJsonPath('changed', false); // repeat
        $this->changeSchedule(15, '2027-02', null, 0)->assertStatus(409); // stale version

        // Return to the default (day 1) from April.
        $this->actingAs($this->ledgerAdmin, 'admin')->postJson("/api/admin/contracts/{$this->contract->id}/rent-schedule/reset", [
            'effective_month' => '2027-04',
            'reason' => 'Back to standard first-of-month billing',
            'expected_version' => (int) RentDueScheduleChange::max('id'),
        ])->assertOk()->assertJsonPath('changed', true);

        // Run the real scheduler for Jan–May 2027.
        foreach (['2027-01-01', '2027-02-01', '2027-03-01', '2027-04-01', '2027-05-01'] as $day) {
            Carbon::setTestNow(Carbon::parse($day.' 01:00', 'UTC'));
            $this->artisan('ledger:generate-rent')->assertSuccessful();
        }

        $rows = LedgerEntry::where('contract_id', $this->contract->id)->where('type', 'rent')->orderBy('billing_period_start')->get()
            ->map(fn ($e) => [$e->billing_period_start->toDateString(), $e->due_date->toDateString(), $e->amount_cents])->all();
        $this->assertSame([
            ['2026-12-01', '2026-12-01', 250000], // issued before the change: unchanged
            ['2027-01-01', '2027-01-31', 250000],
            ['2027-02-01', '2027-02-28', 250000],
            ['2027-03-01', '2027-03-31', 250000],
            ['2027-04-01', '2027-04-01', 250000],
            ['2027-05-01', '2027-05-01', 250000],
        ], $rows);

        // Notices to both parties for both changes; audit for both.
        $this->assertSame(2, Notification::where('user_id', $this->tenant->id)->where('type', 'rent_due_date_changed')->count());
        $this->assertSame(2, Notification::where('user_id', $this->landlord->id)->where('type', 'rent_due_date_changed')->count());
        $this->assertSame(1, AuditLog::where('action', 'rent_due_schedule_changed')->count());
        $this->assertSame(1, AuditLog::where('action', 'rent_due_schedule_reset')->count());
    }

    public function test_tenant_and_landlord_see_the_effective_due_day(): void
    {
        $this->changeSchedule(25, '2027-01')->assertOk();

        $this->assertSame(25, $this->actingAs($this->tenant, 'sanctum')->getJson("/api/tenant/contracts/{$this->contract->id}")->json('contract.rent_due_day')
            ?? $this->actingAs($this->tenant, 'sanctum')->getJson("/api/tenant/contracts/{$this->contract->id}")->json('rent_due_day'));
        $statement = $this->actingAs($this->landlord, 'sanctum')->getJson("/api/landlord/ledger/statement/contract/{$this->contract->id}?year=2026&month=12")->assertOk();
        $this->assertSame(25, $statement->json('contract.rent_due_day'));
        $this->assertSame(1, $statement->json('contract.payment_day'), 'the signed term is still shown as signed');
    }

    public function test_schedule_permissions_and_ended_leases(): void
    {
        $this->changeSchedule(15, '2027-01', $this->viewOnlyAdmin)->assertForbidden();
        $this->actingAs($this->viewOnlyAdmin, 'admin')->getJson("/api/admin/contracts/{$this->contract->id}/rent-schedule/preview?due_day=15&effective_month=2027-01")->assertForbidden();

        $this->contract->update(['status' => ContractStatus::TERMINATED, 'terminated_by' => 'admin', 'termination_reason' => 'test']);
        $this->changeSchedule(15, '2027-01')->assertStatus(422);
        $this->assertSame(0, RentDueScheduleChange::count());
    }
}
