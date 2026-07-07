<?php

namespace App\Services;

use App\Enums\MaintenanceReporter;
use App\Enums\MaintenanceStatus;
use App\Enums\NotificationType;
use App\Models\Admin;
use App\Models\Contract;
use App\Models\MaintenanceEvent;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * MaintenanceService
 *
 * Single source of truth for the maintenance-request lifecycle. Every state
 * transition flows through here so that the tenant-visible timeline
 * (maintenance_events), the privileged audit log, and notifications stay in
 * lock-step. Controllers stay thin and never mutate status directly.
 */
class MaintenanceService
{
    public function __construct(
        protected AuditService $auditService,
        protected NotificationService $notificationService,
    ) {}

    // -------------------------------------------------------------------------
    // Timeline
    // -------------------------------------------------------------------------

    public function recordEvent(
        MaintenanceRequest $request,
        string $event,
        string $description,
        ?Model $actor = null,
        array $meta = [],
    ): MaintenanceEvent {
        return $request->events()->create([
            'actor_type' => $actor ? $actor->getMorphClass() : null,
            'actor_id' => $actor?->getKey(),
            'event' => $event,
            'description' => $description,
            'meta' => $meta ?: null,
            'created_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Creation
    // -------------------------------------------------------------------------

    /**
     * File a new request as the tenant on an active contract.
     *
     * Intake fields (area/onset/safety_flags/access + preferences) turn a bare
     * note into a triage-ready repair report; they are validated by
     * StoreMaintenanceRequest before reaching here.
     *
     * @param  array{title:string,description:string,category:string,priority:string,area?:?string,specific_location?:?string,onset?:?string,safety_flags?:?array,access_permission?:?string,preferred_visit_window?:?string,preferred_contact_method?:?string,access_instructions?:?string}  $data
     */
    public function createForTenant(User $tenant, Contract $contract, array $data): MaintenanceRequest
    {
        return DB::transaction(function () use ($tenant, $contract, $data) {
            $unit = $contract->listing->unit;
            $property = $unit->property;

            $request = MaintenanceRequest::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'property_id' => $property->id,
                'unit_id' => $unit->id,
                'landlord_id' => $contract->landlord_id,
                'reported_by' => MaintenanceReporter::TENANT,
                'title' => $data['title'],
                'description' => $data['description'],
                'category' => $data['category'],
                'priority' => $data['priority'],
                'area' => $data['area'] ?? null,
                'specific_location' => $data['specific_location'] ?? null,
                'onset' => $data['onset'] ?? null,
                'safety_flags' => $data['safety_flags'] ?? null,
                'access_permission' => $data['access_permission'] ?? null,
                'preferred_visit_window' => $data['preferred_visit_window'] ?? null,
                'preferred_contact_method' => $data['preferred_contact_method'] ?? null,
                'access_instructions' => $data['access_instructions'] ?? null,
                'status' => MaintenanceStatus::OPEN->value,
                'submitted_at' => now(),
            ]);

            $this->recordEvent($request, 'submitted', 'Submitted the request', $tenant, [
                'safety_flags' => $data['safety_flags'] ?? [],
            ]);

            $this->auditService->log(
                actor: $tenant,
                action: 'maintenance_request_created',
                subject: $request,
                description: "Maintenance request created: {$request->title}",
                severity: 'info',
            );

            $this->notifyLandlordSubmitted($request);

            return $request->fresh();
        });
    }

    /**
     * Log a request as the landlord (e.g. from a routine inspection). `mode`
     * controls how far the request advances immediately: `new` leaves it
     * open, `assign` also assigns a vendor/appointment, `resolved` logs work
     * already completed.
     *
     * @param  array{title:string,description:string,category:string,priority:string,mode:string,assignee_name?:?string,assignee_phone?:?string,assignee_type?:?string,appointment_at?:?string,expected_completion_date?:?string,resolution_notes?:?string}  $data
     */
    public function createForLandlord(User $landlord, Contract $contract, array $data): MaintenanceRequest
    {
        return DB::transaction(function () use ($landlord, $contract, $data) {
            $unit = $contract->listing->unit;
            $property = $unit->property;
            $now = now();
            $mode = $data['mode'] ?? 'new';

            $attrs = [
                'tenant_id' => $contract->tenant_id,
                'contract_id' => $contract->id,
                'property_id' => $property->id,
                'unit_id' => $unit->id,
                'landlord_id' => $landlord->id,
                'reported_by' => MaintenanceReporter::LANDLORD,
                'title' => $data['title'],
                'description' => ($data['description'] ?? null) ?: 'Logged by landlord.',
                'category' => $data['category'],
                'priority' => $data['priority'],
                'status' => MaintenanceStatus::OPEN->value,
                'submitted_at' => $now,
            ];

            if ($mode === 'assign') {
                $attrs = array_merge($attrs, [
                    'status' => MaintenanceStatus::ASSIGNED->value,
                    'acknowledged_at' => $now,
                    'assigned_at' => $now,
                    'assignee_name' => $data['assignee_name'] ?? null,
                    'assignee_phone' => $data['assignee_phone'] ?? null,
                    'assignee_type' => $data['assignee_type'] ?? null,
                    'appointment_at' => $data['appointment_at'] ?? null,
                    'expected_completion_date' => $data['expected_completion_date'] ?? null,
                ]);
            } elseif ($mode === 'resolved') {
                $attrs = array_merge($attrs, [
                    'status' => MaintenanceStatus::RESOLVED->value,
                    'acknowledged_at' => $now,
                    'resolved_at' => $now,
                    'resolution_notes' => ($data['resolution_notes'] ?? null) ?: 'Logged as already completed by landlord.',
                ]);
            }

            $request = MaintenanceRequest::create($attrs);

            $description = match ($mode) {
                'assign' => 'Created the request and assigned '.($request->assignee_name ?? 'a vendor'),
                'resolved' => 'Created the request (logged as already resolved)',
                default => 'Created the request',
            };

            $this->recordEvent($request, 'submitted', $description, $landlord);

            $this->auditService->log(
                actor: $landlord,
                action: 'maintenance_request_created',
                subject: $request,
                description: "Landlord logged maintenance request: {$request->title}",
                metadata: ['mode' => $mode],
                severity: 'info',
            );

            $this->notifyTenantLogged($request);

            return $request->fresh();
        });
    }

    // -------------------------------------------------------------------------
    // Landlord-driven transitions
    // -------------------------------------------------------------------------

    public function acknowledge(MaintenanceRequest $request, User $landlord): MaintenanceRequest
    {
        $request->status = MaintenanceStatus::ACKNOWLEDGED;
        $request->acknowledged_at = $request->acknowledged_at ?? now();
        $request->save();

        $this->recordEvent($request, 'acknowledged', 'Acknowledged the request', $landlord);
        $this->logAndNotify($request, $landlord, 'acknowledged');

        return $request->fresh();
    }

    /**
     * @param  array{assignee_name:string,assignee_phone?:?string,assignee_type?:?string,appointment_at?:?string,expected_completion_date?:?string}  $data
     */
    public function assign(MaintenanceRequest $request, User $landlord, array $data): MaintenanceRequest
    {
        $now = now();

        $request->status = MaintenanceStatus::ASSIGNED;
        $request->acknowledged_at = $request->acknowledged_at ?? $now;
        $request->assigned_at = $now;
        $request->assignee_name = $data['assignee_name'];
        $request->assignee_phone = $data['assignee_phone'] ?? null;
        $request->assignee_type = $data['assignee_type'] ?? null;
        $request->appointment_at = $data['appointment_at'] ?? null;
        $request->expected_completion_date = $data['expected_completion_date'] ?? null;
        $request->save();

        $appt = $request->appointment_at ? ', appointment '.$request->appointment_at->format('j M Y g:i A') : '';
        $this->recordEvent($request, 'assigned', "Assigned {$request->assignee_name}{$appt}", $landlord);
        $this->logAndNotify($request, $landlord, 'assigned');

        return $request->fresh();
    }

    public function markInProgress(MaintenanceRequest $request, User $landlord): MaintenanceRequest
    {
        $request->status = MaintenanceStatus::IN_PROGRESS;
        $request->acknowledged_at = $request->acknowledged_at ?? now();
        $request->save();

        $this->recordEvent($request, 'in_progress', 'Marked in progress', $landlord);
        $this->logAndNotify($request, $landlord, 'in_progress');

        return $request->fresh();
    }

    public function markWaiting(MaintenanceRequest $request, User $landlord, string $reason): MaintenanceRequest
    {
        $request->status = MaintenanceStatus::WAITING;
        $request->waiting_reason = $reason;
        $request->save();

        $this->recordEvent($request, 'waiting', "Marked waiting: {$reason}", $landlord);
        $this->logAndNotify($request, $landlord, 'waiting');

        return $request->fresh();
    }

    public function resolve(
        MaintenanceRequest $request,
        User $landlord,
        string $notes,
        ?int $laborCostCents = null,
        ?int $partsCostCents = null,
    ): MaintenanceRequest {
        $request->status = MaintenanceStatus::RESOLVED;
        $request->resolved_at = now();
        $request->resolution_notes = $notes;

        if ($laborCostCents !== null || $partsCostCents !== null) {
            $request->labor_cost_cents = $laborCostCents ?? 0;
            $request->parts_cost_cents = $partsCostCents ?? 0;
            $request->cost_paid = false;
        }

        $request->save();

        $this->recordEvent($request, 'resolved', 'Marked resolved', $landlord);
        $this->logAndNotify($request, $landlord, 'resolved');

        return $request->fresh();
    }

    /**
     * @param  array{labor_cost_cents?:?int,parts_cost_cents?:?int,invoice_reference?:?string,cost_notes?:?string,cost_paid?:?bool}  $data
     */
    public function updateCosts(MaintenanceRequest $request, User $landlord, array $data): MaintenanceRequest
    {
        $request->fill(array_intersect_key($data, array_flip([
            'labor_cost_cents', 'parts_cost_cents', 'invoice_reference', 'cost_notes', 'cost_paid',
        ])));
        $request->save();

        $this->recordEvent($request, 'cost_updated', 'Updated the cost record', $landlord);

        $this->auditService->log(
            actor: $landlord,
            action: 'maintenance_costs_updated',
            subject: $request,
            description: "Cost record updated: {$request->title}",
            severity: 'info',
        );

        return $request->fresh();
    }

    public function close(MaintenanceRequest $request, User $landlord): MaintenanceRequest
    {
        $request->status = MaintenanceStatus::CLOSED;
        $request->closed_at = now();
        $request->save();

        $this->recordEvent($request, 'closed', 'Closed the request', $landlord);
        $this->logAndNotify($request, $landlord, 'closed');

        return $request->fresh();
    }

    public function reopen(MaintenanceRequest $request, User $landlord, string $reason): MaintenanceRequest
    {
        // History is additive: resolved_at/closed_at are never cleared.
        $request->status = $request->assignee_name ? MaintenanceStatus::ASSIGNED : MaintenanceStatus::ACKNOWLEDGED;
        $request->save();

        $this->recordEvent($request, 'reopened', "Reopened: {$reason}", $landlord);
        $this->logAndNotify($request, $landlord, 'reopened');

        return $request->fresh();
    }

    /**
     * Platform-admin override: force-close a case the landlord has stalled
     * on. A genuine MaintenanceStatus transition (unlike escalate/assign-
     * owner, which are admin-only metadata) — so it goes through the same
     * tenant-visible timeline + audit trail as a landlord-driven close, with
     * the admin recorded as the actor.
     */
    public function adminOverrideClose(MaintenanceRequest $request, Admin $admin, string $reason): MaintenanceRequest
    {
        $request->status = MaintenanceStatus::CLOSED;
        $request->closed_at = now();
        $request->save();

        $this->recordEvent($request, 'closed', "Closed by platform admin: {$reason}", $admin, ['override' => true, 'reason' => $reason]);
        $this->logAndNotify($request, $admin, 'closed');

        return $request->fresh();
    }

    /**
     * Platform-admin override: reopen a case (e.g. the tenant disputes that
     * it was actually resolved). Mirrors reopen()'s status logic exactly.
     */
    public function adminOverrideReopen(MaintenanceRequest $request, Admin $admin, string $reason): MaintenanceRequest
    {
        $request->status = $request->assignee_name ? MaintenanceStatus::ASSIGNED : MaintenanceStatus::ACKNOWLEDGED;
        $request->save();

        $this->recordEvent($request, 'reopened', "Reopened by platform admin: {$reason}", $admin, ['override' => true, 'reason' => $reason]);
        $this->logAndNotify($request, $admin, 'reopened');

        return $request->fresh();
    }

    /**
     * Cancel an open request as the tenant. Only OPEN requests are cancellable
     * (enforced by the policy/controller before this is called).
     */
    public function cancel(MaintenanceRequest $request, User $tenant): MaintenanceRequest
    {
        $request->status = MaintenanceStatus::CANCELLED;
        $request->closed_at = now();
        $request->save();

        $this->recordEvent($request, 'cancelled', 'Cancelled the request', $tenant);

        $this->auditService->log(
            actor: $tenant,
            action: 'maintenance_request_cancelled',
            subject: $request,
            description: "Maintenance request cancelled: {$request->title}",
            severity: 'info',
        );

        return $request->fresh();
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Shared audit + tenant-notification tail for every status transition.
     * $actor is typically the landlord, but admin override actions pass an
     * Admin instead — both satisfy AuditService::log()'s Model contract, and
     * notifyTenantStatusUpdated() never reads the actor itself.
     */
    protected function logAndNotify(MaintenanceRequest $request, Model $actor, string $transition): void
    {
        $this->auditService->log(
            actor: $actor,
            action: 'maintenance_status_updated',
            subject: $request,
            description: "Maintenance request status updated to '{$request->status->value}': {$request->title}",
            metadata: ['new_status' => $request->status->value, 'transition' => $transition],
            severity: 'info',
        );

        $this->notifyTenantStatusUpdated($request, $transition);
    }

    protected function notifyLandlordSubmitted(MaintenanceRequest $request): void
    {
        $landlord = $request->landlord;
        if (! $landlord) {
            return;
        }

        $eventId = "maintenance-submitted:{$request->id}";
        if ($this->notificationService->exists($landlord, $eventId)) {
            return;
        }

        $tenantName = $request->tenant?->full_name ?: $request->tenant?->email;

        $this->notificationService->create(
            user: $landlord,
            type: NotificationType::MAINTENANCE_REQUEST_SUBMITTED,
            title: 'New Maintenance Request',
            message: "{$tenantName} reported: \"{$request->title}\".",
            data: [
                'event_id' => $eventId,
                'maintenance_request_id' => $request->id,
                'property_id' => $request->property_id,
                'unit_id' => $request->unit_id,
            ],
        );
    }

    protected function notifyTenantLogged(MaintenanceRequest $request): void
    {
        $tenant = $request->tenant;
        if (! $tenant) {
            return;
        }

        $eventId = "maintenance-logged:{$request->id}";
        if ($this->notificationService->exists($tenant, $eventId)) {
            return;
        }

        $this->notificationService->create(
            user: $tenant,
            type: NotificationType::MAINTENANCE_LOGGED_BY_LANDLORD,
            title: 'Maintenance Logged For Your Unit',
            message: "Your landlord logged a maintenance request: \"{$request->title}\".",
            data: [
                'event_id' => $eventId,
                'maintenance_request_id' => $request->id,
            ],
        );
    }

    protected function notifyTenantStatusUpdated(MaintenanceRequest $request, string $transition): void
    {
        $tenant = $request->tenant;
        if (! $tenant) {
            return;
        }

        $eventId = "maintenance-status:{$request->id}:{$transition}:".now()->timestamp;

        $this->notificationService->create(
            user: $tenant,
            type: NotificationType::MAINTENANCE_STATUS_UPDATED,
            title: 'Maintenance Request Updated',
            message: "\"{$request->title}\" is now {$request->status->value}.",
            data: [
                'event_id' => $eventId,
                'maintenance_request_id' => $request->id,
                'status' => $request->status->value,
                'transition' => $transition,
            ],
        );
    }
}
