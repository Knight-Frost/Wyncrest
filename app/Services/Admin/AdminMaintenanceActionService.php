<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\MaintenanceRequest;
use App\Services\AuditService;

/**
 * AdminMaintenanceActionService
 *
 * Mutating admin-only actions on a maintenance request that have no
 * tenant/landlord equivalent: case ownership and escalation. These are
 * internal triage metadata, not MaintenanceStatus transitions, so — unlike
 * MaintenanceService::adminOverrideClose/adminOverrideReopen — they never
 * write to the tenant/landlord-visible maintenance_events timeline, only to
 * the privileged audit log.
 */
class AdminMaintenanceActionService
{
    public function __construct(protected AuditService $auditService) {}

    public function assignCaseOwner(MaintenanceRequest $request, Admin $actingAdmin, Admin $handlingAdmin): MaintenanceRequest
    {
        $request->update(['handling_admin_id' => $handlingAdmin->id]);

        $this->auditService->log(
            actor: $actingAdmin,
            action: 'maintenance_case_owner_assigned',
            subject: $request,
            description: "Case owner set to {$handlingAdmin->name}: {$request->title}",
            metadata: ['handling_admin_id' => $handlingAdmin->id],
            severity: 'info',
        );

        return $request->fresh();
    }

    public function escalate(MaintenanceRequest $request, Admin $admin, string $reason): MaintenanceRequest
    {
        $request->update([
            'escalated_at' => now(),
            'escalation_reason' => $reason,
        ]);

        $this->auditService->log(
            actor: $admin,
            action: 'maintenance_escalated',
            subject: $request,
            description: "Escalated: {$request->title}",
            metadata: ['reason' => $reason],
            severity: 'warning',
        );

        return $request->fresh();
    }

    public function clearEscalation(MaintenanceRequest $request, Admin $admin): MaintenanceRequest
    {
        $request->update([
            'escalated_at' => null,
            'escalation_reason' => null,
        ]);

        $this->auditService->log(
            actor: $admin,
            action: 'maintenance_escalation_cleared',
            subject: $request,
            description: "Escalation cleared: {$request->title}",
            severity: 'info',
        );

        return $request->fresh();
    }
}
