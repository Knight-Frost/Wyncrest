<?php

namespace Tests\Feature;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function makeContract(array $attributes): Contract
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
        ]);

        return Contract::factory()->create(array_merge([
            'listing_id' => $listing->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
        ], $attributes));
    }

    public function test_active_contract_past_end_date_is_marked_expired()
    {
        $contract = $this->makeContract([
            'status' => ContractStatus::ACTIVE,
            'start_date' => now()->subYear(),
            'end_date' => now()->subDay(),
        ]);

        $this->artisan('contracts:mark-expired')->assertSuccessful();

        $this->assertEquals(ContractStatus::EXPIRED, $contract->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'contract_expired']);
    }

    public function test_current_and_open_ended_contracts_are_untouched()
    {
        $current = $this->makeContract([
            'status' => ContractStatus::ACTIVE,
            'start_date' => now()->subMonths(2),
            'end_date' => now()->addMonths(10),
        ]);
        $openEnded = $this->makeContract([
            'status' => ContractStatus::ACTIVE,
            'start_date' => now()->subYears(3),
            'end_date' => null,
        ]);

        $this->artisan('contracts:mark-expired')->assertSuccessful();

        $this->assertEquals(ContractStatus::ACTIVE, $current->fresh()->status);
        $this->assertEquals(ContractStatus::ACTIVE, $openEnded->fresh()->status);
    }

    public function test_terminated_contracts_are_not_re_marked()
    {
        $terminated = $this->makeContract([
            'status' => ContractStatus::TERMINATED,
            'start_date' => now()->subYear(),
            'end_date' => now()->subDay(),
        ]);

        $this->artisan('contracts:mark-expired')->assertSuccessful();

        $this->assertEquals(ContractStatus::TERMINATED, $terminated->fresh()->status);
    }
}
