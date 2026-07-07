<?php

namespace Tests\Feature\Ledger;

use App\Enums\ContractStatus;
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
 * LateFeeDuplicationTest
 *
 * Regression coverage for H1: LedgerService::generateLateFee() used to do a
 * plain check-then-create with no transaction/lock, so two concurrent calls
 * (e.g. a double-click) could each pass the "does a late fee already exist?"
 * check before either had created one, producing two late fee entries for
 * the same rent entry. The fix wraps the check + create in a DB transaction
 * with a row lock on the rent entry and the late-fee lookup, so the second
 * caller always observes the first caller's committed row and throws instead
 * of creating a duplicate.
 */
class LateFeeDuplicationTest extends TestCase
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

        $this->landlord = User::factory()->landlord()->create();
        $this->tenant = User::factory()->tenant()->create();
        $this->admin = Admin::factory()->create();

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
            'rent_amount' => 250000,
            'payment_day' => 1,
        ]);

        $this->ledgerService = app(LedgerService::class);
    }

    public function test_generating_a_late_fee_twice_throws_and_never_creates_a_second_entry(): void
    {
        $rentEntry = LedgerEntry::factory()->overdue()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
        ]);

        $firstLateFee = $this->ledgerService->generateLateFee($rentEntry, 10000, $this->admin);
        $this->assertNotNull($firstLateFee);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Late fee already exists for this rent entry');

        try {
            $this->ledgerService->generateLateFee($rentEntry, 10000, $this->admin);
        } finally {
            $this->assertSame(
                1,
                LedgerEntry::where('related_rent_entry_id', $rentEntry->id)
                    ->where('type', LedgerType::LATE_FEE)
                    ->count()
            );
        }
    }
}
