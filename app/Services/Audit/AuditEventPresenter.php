<?php

namespace App\Services\Audit;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Services\Ledger\LedgerComputationEngine;
use App\Support\Audit\AuditClassifier;
use Illuminate\Database\Eloquent\Model;

/**
 * AuditEventPresenter
 *
 * Turns a raw AuditLog row into an admin-readable "case file": a plain
 * English summary, resolved related records (tenant/landlord/property/
 * contract/ledger entry — never a bare UUID), financial context for ledger
 * events, and a truthful note when data needed for a richer summary simply
 * was not captured at write time.
 *
 * Truthfulness contract: every field here is either read directly off a
 * model or computed by LedgerComputationEngine (the single source of
 * financial truth — never re-derive money math here). Nothing is invented.
 * When a relationship can't be resolved (deleted record, action type this
 * presenter doesn't model deeply), the field is omitted or a fallback string
 * says so explicitly — see `presentGeneric()`.
 */
class AuditEventPresenter
{
    /** Actions whose subject is always a LedgerEntry in real code paths. */
    private const LEDGER_ACTIONS = [
        'payment_intent_created', 'payment_intent_failed', 'payment_recorded', 'payment_failed',
        'rent_entry_created', 'rent_entry_automated', 'late_fee_applied',
        'entry_marked_overdue', 'ledger_entry_marked_overdue', 'entry_paid', 'entry_waived',
    ];

    private const LISTING_ACTIONS = [
        'listing_created', 'listing_updated', 'listing_submitted',
        'listing_published', 'listing_rejected', 'listing_changes_requested', 'listing_deleted',
    ];

    private const CONTRACT_ACTIONS = [
        'contract_created', 'contract_sent', 'contract_accepted',
        'contract_terminated', 'contract_force_terminated',
    ];

    private const VERIFICATION_ACTIONS = [
        'verification_submitted', 'verification_approved', 'verification_rejected', 'verification_needs_info',
    ];

    private const USER_ACCOUNT_ACTIONS = [
        'account_suspended', 'account_reactivated', 'account_blocked', 'account_archived',
    ];

    private const ADMIN_ACCESS_ACTIONS = [
        'admin_invited', 'admin_invite_resent', 'admin_invite_revoked', 'admin_invite_accepted',
        'admin_capabilities_updated', 'admin_promoted_super', 'admin_demoted_super',
        'admin_deactivated', 'admin_reactivated',
    ];

    public function __construct(private readonly LedgerComputationEngine $ledger) {}

    public function present(AuditLog $log): array
    {
        $subject = $log->relationLoaded('subject') ? $log->subject : $log->subject()->first();

        $body = match (true) {
            $log->action === 'identity_verified' => $this->presentIdentityVerified($log, $subject),
            in_array($log->action, self::LEDGER_ACTIONS, true) && $subject instanceof LedgerEntry => $this->presentLedgerEvent($log, $subject),
            in_array($log->action, self::LISTING_ACTIONS, true) && $subject instanceof Listing => $this->presentListingEvent($log, $subject),
            in_array($log->action, self::CONTRACT_ACTIONS, true) && $subject instanceof Contract => $this->presentContractEvent($log, $subject),
            in_array($log->action, self::VERIFICATION_ACTIONS, true) => $this->presentVerificationEvent($log, $subject),
            in_array($log->action, self::USER_ACCOUNT_ACTIONS, true) && $subject instanceof User => $this->presentUserAccountEvent($log, $subject),
            in_array($log->action, self::ADMIN_ACCESS_ACTIONS, true) && $subject instanceof Admin => $this->presentAdminAccessEvent($log, $subject),
            default => $this->presentGeneric($log, $subject),
        };

        $recommendContext = $body['_recommend_context'] ?? [];
        unset($body['_recommend_context']);

        return array_merge([
            'event_title' => AuditClassifier::title($log->action),
            'classification' => [
                'category' => AuditClassifier::area($log->action),
                'severity' => $log->severity,
                'label' => AuditClassifier::classification($log->action, $log->severity),
                'sensitivity' => AuditClassifier::sensitivity($log->action),
            ],
            'source' => $this->buildSource($log),
            'integrity_statement' => 'This record is append-only and SHA-256 hash-chained to the entry '
                .'written immediately before it. Editing or deleting any historical row would break every '
                .'hash after it, which chain verification detects.',
        ], $body, [
            'recommended_steps' => AuditClassifier::recommendedSteps(
                $log->action,
                $log->subject_type,
                $log->subject_id,
                $recommendContext
            ),
        ]);
    }

    // -------------------------------------------------------------------------
    // Ledger events (rent generated, late fee, payment recorded/failed, etc.)
    // -------------------------------------------------------------------------

    private function presentLedgerEvent(AuditLog $log, LedgerEntry $entry): array
    {
        $entry->loadMissing(['contract.listing.unit.property', 'tenant', 'landlord']);

        $contract = $entry->contract;
        $tenant = $entry->tenant;
        $landlord = $entry->landlord;
        $unit = $contract?->listing?->unit;
        $property = $unit?->property;

        $runningBalance = null;
        if ($contract) {
            $balances = $this->ledger->computeRunningBalances(
                LedgerEntry::where('contract_id', $contract->id)->get()
            );
            $runningBalance = $balances[$entry->id] ?? null;
        }

        $decorated = $this->ledger->decorateEntry($entry, $runningBalance);

        $periodLabel = ($entry->billing_period_start && $entry->billing_period_end)
            ? $entry->billing_period_start->format('j M Y').' to '.$entry->billing_period_end->format('j M Y')
            : null;

        $monthLabel = $entry->billing_period_start?->format('F Y');

        $plainSummary = match ($log->action) {
            'rent_entry_created', 'rent_entry_automated' => $monthLabel
                ? "Wyncrest automatically generated the rent charge for {$monthLabel}."
                : 'Wyncrest automatically generated a scheduled rent charge.',
            'late_fee_applied' => $monthLabel
                ? "A late fee was applied because the {$monthLabel} rent charge was overdue."
                : 'A late fee was applied to an overdue rent charge.',
            'entry_marked_overdue', 'ledger_entry_marked_overdue' => 'This charge passed its due date without payment and was marked overdue.',
            'entry_paid' => 'This ledger entry was marked as paid.',
            'entry_waived' => 'This charge was waived by an admin and no longer counts toward the tenant\'s balance.',
            'payment_recorded' => 'A rent payment was received and recorded on the ledger.',
            'payment_failed' => 'A payment attempt failed. The tenant may still owe this charge.',
            'payment_intent_created' => 'A payment was started for this ledger entry.',
            'payment_intent_failed' => 'Wyncrest could not start a payment for this ledger entry.',
            default => AuditClassifier::whyItMatters($log->action, $log->severity),
        };

        $facts = [];
        $facts[] = ['label' => 'Amount', 'kind' => 'money', 'value_cents' => $decorated['display_amount_cents']];
        if ($periodLabel) {
            $facts[] = ['label' => 'Period', 'kind' => 'text', 'value' => $periodLabel];
        }
        if ($entry->due_date) {
            $facts[] = ['label' => 'Due date', 'kind' => 'text', 'value' => $entry->due_date->format('j M Y')];
        }
        if ($tenant) {
            $facts[] = ['label' => 'Tenant', 'kind' => 'text', 'value' => $tenant->full_name];
        }
        if ($property || $unit) {
            $label = trim(collect([$property?->name, $unit?->display_name])->filter()->implode(' · '));
            if ($label !== '') {
                $facts[] = ['label' => 'Property', 'kind' => 'text', 'value' => $label];
            }
        }
        if ($landlord) {
            $facts[] = ['label' => 'Landlord', 'kind' => 'text', 'value' => $landlord->full_name];
        }
        $facts[] = ['label' => 'Status', 'kind' => 'text', 'value' => ucfirst($decorated['status'])];

        $related = [];
        $related[] = [
            'type' => 'Ledger entry',
            'label' => $decorated['display_label'],
            'sublabel' => $decorated['reference'],
            'href' => null,
        ];
        if ($contract) {
            $related[] = [
                'type' => 'Contract',
                'label' => trim(collect([$tenant?->full_name, $property?->name])->filter()->implode(' · ')) ?: 'Contract',
                'sublabel' => ucfirst($contract->status->value),
                'href' => "/app/contracts/{$contract->id}",
            ];
        }
        if ($tenant) {
            $related[] = ['type' => 'Tenant', 'label' => $tenant->full_name, 'sublabel' => $tenant->email, 'href' => null];
        }
        if ($landlord) {
            $related[] = ['type' => 'Landlord', 'label' => $landlord->full_name, 'sublabel' => $landlord->email, 'href' => null];
        }

        $createdRecordSummary = in_array($log->action, ['rent_entry_created', 'rent_entry_automated', 'late_fee_applied', 'payment_recorded'], true)
            ? [
                'type' => $decorated['display_label'],
                'fields' => array_values(array_filter([
                    ['label' => 'Amount', 'kind' => 'money', 'value_cents' => $decorated['display_amount_cents']],
                    $periodLabel ? ['label' => 'Period', 'kind' => 'text', 'value' => $periodLabel] : null,
                    ['label' => 'Status', 'kind' => 'text', 'value' => ucfirst($decorated['status'])],
                    ['label' => 'Created', 'kind' => 'text', 'value' => $entry->created_at?->format('j M Y, g:i A')],
                ])),
            ]
            : null;

        return [
            'plain_summary' => $plainSummary,
            'key_facts' => $facts,
            'related_records' => $related,
            'created_record_summary' => $createdRecordSummary,
            'financial_context' => [
                'display_amount_cents' => $decorated['display_amount_cents'],
                'balance_impact_cents' => $decorated['balance_impact_cents'],
                'direction' => $decorated['direction'],
                'display_label' => $decorated['display_label'],
                'reference' => $decorated['reference'],
                'running_balance_cents' => $decorated['running_balance_cents'],
            ],
            'data_gap_note' => null,
            '_recommend_context' => $contract ? ['contract_id' => $contract->id] : [],
        ];
    }

    // -------------------------------------------------------------------------
    // Listing moderation events
    // -------------------------------------------------------------------------

    private function presentListingEvent(AuditLog $log, Listing $listing): array
    {
        $listing->loadMissing(['unit.property', 'landlord']);
        $unit = $listing->unit;
        $property = $unit?->property;
        $landlord = $listing->landlord;
        $reason = $log->metadata['reason'] ?? null;

        $plainSummary = match ($log->action) {
            'listing_published' => 'This listing was approved and is now publicly visible to tenants.',
            'listing_rejected' => 'This listing was rejected during moderation.',
            'listing_changes_requested' => 'This listing was sent back to the landlord for changes before it can be published.',
            'listing_submitted' => 'The landlord submitted this listing for admin review.',
            'listing_created' => 'A new listing was drafted.',
            'listing_updated' => 'An existing listing was edited.',
            'listing_deleted' => 'This listing was deleted from the platform.',
            default => AuditClassifier::whyItMatters($log->action, $log->severity),
        };

        $facts = array_values(array_filter([
            ['label' => 'Listing', 'kind' => 'text', 'value' => $listing->title],
            $landlord ? ['label' => 'Landlord', 'kind' => 'text', 'value' => $landlord->full_name] : null,
            ($property || $unit) ? ['label' => 'Property', 'kind' => 'text', 'value' => trim(collect([$property?->name, $unit?->display_name])->filter()->implode(' · '))] : null,
            $reason ? ['label' => 'Reason', 'kind' => 'text', 'value' => $reason] : null,
        ]));

        $related = array_values(array_filter([
            ['type' => 'Listing', 'label' => $listing->title, 'sublabel' => ucfirst($listing->status->value ?? ''), 'href' => "/app/listing-review/{$listing->id}"],
            $landlord ? ['type' => 'Landlord', 'label' => $landlord->full_name, 'sublabel' => $landlord->email, 'href' => null] : null,
        ]));

        return [
            'plain_summary' => $plainSummary,
            'key_facts' => $facts,
            'related_records' => $related,
            'created_record_summary' => null,
            'financial_context' => null,
            'data_gap_note' => null,
            '_recommend_context' => [],
        ];
    }

    // -------------------------------------------------------------------------
    // Contract lifecycle events
    // -------------------------------------------------------------------------

    private function presentContractEvent(AuditLog $log, Contract $contract): array
    {
        $contract->loadMissing(['tenant', 'landlord', 'listing.unit.property']);
        $tenant = $contract->tenant;
        $landlord = $contract->landlord;
        $property = $contract->listing?->unit?->property;
        $unit = $contract->listing?->unit;

        $plainSummary = match ($log->action) {
            'contract_created' => 'A new rental contract was drafted.',
            'contract_sent' => 'The contract was sent to the tenant for signature.',
            'contract_accepted' => 'The tenant accepted the contract, activating the lease. Rent generation begins from this contract.',
            'contract_terminated' => 'This contract was terminated, ending the lease relationship.',
            'contract_force_terminated' => 'An admin force-terminated this contract.',
            default => AuditClassifier::whyItMatters($log->action, $log->severity),
        };

        $facts = array_values(array_filter([
            $tenant ? ['label' => 'Tenant', 'kind' => 'text', 'value' => $tenant->full_name] : null,
            $landlord ? ['label' => 'Landlord', 'kind' => 'text', 'value' => $landlord->full_name] : null,
            ($property || $unit) ? ['label' => 'Property', 'kind' => 'text', 'value' => trim(collect([$property?->name, $unit?->display_name])->filter()->implode(' · '))] : null,
            ['label' => 'Rent', 'kind' => 'money', 'value_cents' => $contract->rent_amount],
            ['label' => 'Status', 'kind' => 'text', 'value' => ucfirst($contract->status->value)],
        ]));

        $related = array_values(array_filter([
            ['type' => 'Contract', 'label' => trim(collect([$tenant?->full_name, $property?->name])->filter()->implode(' · ')) ?: 'Contract', 'sublabel' => ucfirst($contract->status->value), 'href' => "/app/contracts/{$contract->id}"],
            $tenant ? ['type' => 'Tenant', 'label' => $tenant->full_name, 'sublabel' => $tenant->email, 'href' => null] : null,
            $landlord ? ['type' => 'Landlord', 'label' => $landlord->full_name, 'sublabel' => $landlord->email, 'href' => null] : null,
        ]));

        return [
            'plain_summary' => $plainSummary,
            'key_facts' => $facts,
            'related_records' => $related,
            'created_record_summary' => null,
            'financial_context' => null,
            'data_gap_note' => null,
            '_recommend_context' => [],
        ];
    }

    // -------------------------------------------------------------------------
    // Identity verification decision events
    // -------------------------------------------------------------------------

    private function presentVerificationEvent(AuditLog $log, ?Model $subject): array
    {
        // submit's subject is the VerificationRequest; approve/reject/needs_info's
        // subject is the User — resolve the applicant either way.
        $request = $subject instanceof VerificationRequest ? $subject : null;
        $user = $subject instanceof User ? $subject : $request?->user;
        $user?->loadMissing([]);

        $reason = $log->metadata['reason'] ?? $log->metadata['note'] ?? null;

        $plainSummary = match ($log->action) {
            'verification_submitted' => 'The applicant submitted identity documents for verification.',
            'verification_approved' => 'An admin approved this applicant\'s identity verification.',
            'verification_rejected' => 'An admin rejected this applicant\'s identity verification request.',
            'verification_needs_info' => 'An admin requested more information before deciding this verification request.',
            default => AuditClassifier::whyItMatters($log->action, $log->severity),
        };

        $facts = array_values(array_filter([
            $user ? ['label' => 'Applicant', 'kind' => 'text', 'value' => $user->full_name] : null,
            $user ? ['label' => 'Current status', 'kind' => 'text', 'value' => str_replace('_', ' ', ucfirst($user->verification_status?->value ?? 'unknown'))] : null,
            $reason ? ['label' => 'Reason', 'kind' => 'text', 'value' => $reason] : null,
        ]));

        $related = array_values(array_filter([
            $request ? ['type' => 'Verification request', 'label' => 'Case #'.$request->id, 'sublabel' => ucfirst($request->status), 'href' => "/app/verifications/{$request->id}"] : null,
            $user ? ['type' => 'Applicant', 'label' => $user->full_name, 'sublabel' => $user->email, 'href' => null] : null,
        ]));

        return [
            'plain_summary' => $plainSummary,
            'key_facts' => $facts,
            'related_records' => $related,
            'created_record_summary' => null,
            'financial_context' => null,
            'data_gap_note' => $request === null
                ? 'This event is not linked to a specific verification request in the audit record — only the applicant is known.'
                : null,
            '_recommend_context' => [],
        ];
    }

    private function presentIdentityVerified(AuditLog $log, ?Model $subject): array
    {
        $user = $subject instanceof User ? $subject : null;

        return [
            'plain_summary' => $user
                ? "{$user->full_name}'s identity was verified, unlocking verification-gated features."
                : 'A user\'s identity was verified.',
            'key_facts' => $user ? [
                ['label' => 'User', 'kind' => 'text', 'value' => $user->full_name],
                ['label' => 'Role', 'kind' => 'text', 'value' => ucfirst($user->user_type?->value ?? '')],
            ] : [],
            'related_records' => $user ? [
                ['type' => 'User', 'label' => $user->full_name, 'sublabel' => $user->email, 'href' => null],
            ] : [],
            'created_record_summary' => null,
            'financial_context' => null,
            'data_gap_note' => 'The admin who reviewed this verification is not captured on this event — see the '
                .'matching "Identity Verification Approved" event for that record.',
            '_recommend_context' => [],
        ];
    }

    // -------------------------------------------------------------------------
    // User account governance (suspend/reactivate/block/archive)
    // -------------------------------------------------------------------------

    private function presentUserAccountEvent(AuditLog $log, User $user): array
    {
        $reason = $log->metadata['reason'] ?? null;

        $plainSummary = match ($log->action) {
            'account_suspended' => "{$user->full_name}'s account was suspended.",
            'account_reactivated' => "{$user->full_name}'s account was reactivated.",
            'account_blocked' => "{$user->full_name}'s account was blocked from the platform.",
            'account_archived' => "{$user->full_name}'s account was archived.",
            default => AuditClassifier::whyItMatters($log->action, $log->severity),
        };

        $facts = array_values(array_filter([
            ['label' => 'User', 'kind' => 'text', 'value' => $user->full_name],
            ['label' => 'Role', 'kind' => 'text', 'value' => ucfirst($user->user_type?->value ?? '')],
            $reason ? ['label' => 'Reason', 'kind' => 'text', 'value' => $reason] : null,
        ]));

        return [
            'plain_summary' => $plainSummary,
            'key_facts' => $facts,
            'related_records' => [
                ['type' => 'User', 'label' => $user->full_name, 'sublabel' => $user->email, 'href' => null],
            ],
            'created_record_summary' => null,
            'financial_context' => null,
            'data_gap_note' => null,
            '_recommend_context' => [],
        ];
    }

    // -------------------------------------------------------------------------
    // Admin team / permissions events
    // -------------------------------------------------------------------------

    private function presentAdminAccessEvent(AuditLog $log, Admin $target): array
    {
        $reason = $log->metadata['reason'] ?? null;

        $plainSummary = match ($log->action) {
            'admin_invited' => "{$target->email} was invited to join the admin team.",
            'admin_invite_resent' => "The admin invitation for {$target->email} was resent.",
            'admin_invite_revoked' => "The pending admin invitation for {$target->email} was revoked.",
            'admin_invite_accepted' => "{$target->email} accepted their admin invitation and activated their account.",
            'admin_capabilities_updated' => "{$target->email}'s admin permissions were changed. See field changes below for the before/after.",
            'admin_promoted_super' => "{$target->email} was promoted to Super Admin — full platform authority.",
            'admin_demoted_super' => "{$target->email} was demoted from Super Admin to a regular admin.",
            'admin_deactivated' => "{$target->email}'s console access was deactivated.",
            'admin_reactivated' => "{$target->email}'s console access was reactivated.",
            default => AuditClassifier::whyItMatters($log->action, $log->severity),
        };

        $facts = array_values(array_filter([
            ['label' => 'Admin', 'kind' => 'text', 'value' => $target->name ?? $target->email],
            ['label' => 'Email', 'kind' => 'text', 'value' => $target->email],
            $reason ? ['label' => 'Reason', 'kind' => 'text', 'value' => $reason] : null,
        ]));

        return [
            'plain_summary' => $plainSummary,
            'key_facts' => $facts,
            'related_records' => [
                ['type' => 'Admin', 'label' => $target->name ?? $target->email, 'sublabel' => $target->email, 'href' => '/app/manage-access'],
            ],
            'created_record_summary' => null,
            'financial_context' => null,
            'data_gap_note' => null,
            '_recommend_context' => [],
        ];
    }

    // -------------------------------------------------------------------------
    // Generic fallback — every action this presenter doesn't model deeply.
    // Honest about the gap rather than pretending full context exists.
    // -------------------------------------------------------------------------

    private function presentGeneric(AuditLog $log, ?Model $subject): array
    {
        $related = [];
        if ($log->subject_type !== null) {
            if ($subject !== null) {
                $basename = class_basename(get_class($subject));
                $name = $subject->title ?? $subject->name
                    ?? (isset($subject->first_name) ? trim(($subject->first_name ?? '').' '.($subject->last_name ?? '')) : null)
                    ?? $subject->unit_number ?? null;
                $related[] = [
                    'type' => $basename,
                    'label' => ($name && trim($name) !== '') ? $name : "{$basename} #{$log->subject_id}",
                    'sublabel' => null,
                    'href' => null,
                ];
            } else {
                $related[] = [
                    'type' => class_basename($log->subject_type),
                    'label' => 'Record no longer exists',
                    'sublabel' => "#{$log->subject_id}",
                    'href' => null,
                ];
            }
        }

        return [
            'plain_summary' => $log->description ?? AuditClassifier::whyItMatters($log->action, $log->severity),
            'key_facts' => [],
            'related_records' => $related,
            'created_record_summary' => null,
            'financial_context' => null,
            'data_gap_note' => 'Wyncrest does not yet build a detailed case file for this event type — the '
                .'fields above are everything truthfully known about it.',
            '_recommend_context' => [],
        ];
    }

    // -------------------------------------------------------------------------
    // Actor / source
    // -------------------------------------------------------------------------

    private function buildSource(AuditLog $log): array
    {
        if ($log->actor_type === null) {
            $automated = in_array($log->action, ['rent_entry_created', 'rent_entry_automated', 'late_fee_applied', 'entry_marked_overdue', 'ledger_entry_marked_overdue'], true);
            $webhook = in_array($log->action, ['payment_recorded', 'payment_failed'], true);

            return [
                'label' => 'System',
                'description' => match (true) {
                    $automated => 'Generated by Wyncrest\'s scheduled rent automation, not a person.',
                    $webhook => 'Recorded automatically from a Stripe payment webhook, not a person.',
                    default => 'Generated by the system. No human actor is recorded for this event.',
                },
            ];
        }

        if ($log->actor_type === Admin::class) {
            return ['label' => 'Admin', 'description' => 'Performed by an admin from the console.'];
        }

        return ['label' => 'User', 'description' => 'Performed by a tenant or landlord from their account.'];
    }
}
