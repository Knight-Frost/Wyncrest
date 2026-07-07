<?php

namespace Tests\Feature;

use App\Enums\ContractStatus;
use App\Models\Admin;
use App\Models\Contract;
use App\Models\User;
use App\Services\Contracts\ContractLifecycleService;
use App\Services\Contracts\InvalidContractTransitionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ContractLifecycleService engine centralises contract status transitions.
 * The controllers' policies already gate the happy paths (covered by
 * ContractWorkflowTest / LifecycleNotificationsTest); these tests pin the NEW
 * behavior the engine adds — a defense-in-depth guard that fails loudly when a
 * transition is attempted from an illegal status (the safety net for the
 * scheduled expiry command and any future non-HTTP caller).
 */
class ContractLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ContractLifecycleService
    {
        return app(ContractLifecycleService::class);
    }

    private function contract(ContractStatus $status): Contract
    {
        return Contract::factory()->create(['status' => $status]);
    }

    public function test_send_rejects_non_draft_contract(): void
    {
        $this->expectException(InvalidContractTransitionException::class);

        $this->service()->send($this->contract(ContractStatus::ACTIVE), User::factory()->landlord()->create());
    }

    public function test_accept_rejects_contract_not_awaiting_tenant(): void
    {
        $this->expectException(InvalidContractTransitionException::class);

        $this->service()->accept($this->contract(ContractStatus::DRAFT), User::factory()->tenant()->create());
    }

    public function test_terminate_by_tenant_rejects_terminal_contract(): void
    {
        $this->expectException(InvalidContractTransitionException::class);

        $this->service()->terminateByTenant($this->contract(ContractStatus::TERMINATED), User::factory()->tenant()->create(), 'no');
    }

    public function test_force_terminate_rejects_expired_contract(): void
    {
        $this->expectException(InvalidContractTransitionException::class);

        $this->service()->forceTerminateByAdmin($this->contract(ContractStatus::EXPIRED), Admin::factory()->create(), 'no');
    }

    public function test_expire_rejects_non_active_contract(): void
    {
        $this->expectException(InvalidContractTransitionException::class);

        $this->service()->expire($this->contract(ContractStatus::PENDING_TENANT));
    }

    public function test_expire_marks_active_contract_expired(): void
    {
        $contract = Contract::factory()->create([
            'status' => ContractStatus::ACTIVE,
            'end_date' => now()->subDay(),
        ]);

        $this->service()->expire($contract);

        $this->assertSame(ContractStatus::EXPIRED, $contract->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'contract_expired',
            'subject_id' => $contract->id,
        ]);
    }
}
