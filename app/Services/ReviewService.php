<?php

namespace App\Services;

use App\Enums\ContractStatus;
use App\Enums\NotificationType;
use App\Enums\ReviewStatus;
use App\Models\Admin;
use App\Models\Contract;
use App\Models\Property;
use App\Models\Review;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * ReviewService
 *
 * Governs the creation, moderation, and landlord-response lifecycle for reviews.
 *
 * ELIGIBILITY: A tenant may only review a property if they hold a contract
 * on that property with status active|terminated|expired AND that contract
 * does not already have a review attached.
 */
class ReviewService
{
    /** Statuses that unlock review eligibility */
    private const ELIGIBLE_STATUSES = [
        ContractStatus::ACTIVE,
        ContractStatus::TERMINATED,
        ContractStatus::EXPIRED,
    ];

    public function __construct(
        protected NotificationService $notificationService,
        protected AuditService $auditService
    ) {}

    /**
     * Find the tenant's eligible contract for a given property, or null if none.
     *
     * "Eligible" means: status is active|terminated|expired AND no review exists yet.
     */
    public function eligibleContractFor(User $tenant, Property $property): ?Contract
    {
        return Contract::where('tenant_id', $tenant->id)
            ->whereHas('listing.unit', function ($q) use ($property) {
                $q->where('property_id', $property->id);
            })
            ->whereIn('status', array_map(fn ($s) => $s->value, self::ELIGIBLE_STATUSES))
            ->whereDoesntHave('review')
            ->latest()
            ->first();
    }

    /**
     * Create a review from a tenant on a property.
     *
     * @throws ValidationException if the tenant is not eligible
     */
    public function create(
        User $tenant,
        Contract $contract,
        int $rating,
        ?string $title,
        string $body
    ): Review {
        // Verify eligibility: contract must belong to this tenant and be eligible
        if ((int) $contract->tenant_id !== (int) $tenant->id) {
            throw ValidationException::withMessages([
                'contract_id' => ['You are not the tenant on this contract.'],
            ]);
        }

        if (! in_array($contract->status, self::ELIGIBLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'contract_id' => ['Your contract is not in an eligible status for a review.'],
            ]);
        }

        // Prevent duplicate review on the same contract
        if (Review::where('contract_id', $contract->id)->exists()) {
            throw ValidationException::withMessages([
                'contract_id' => ['You have already reviewed this contract.'],
            ]);
        }

        // Resolve property via contract → listing → unit → property
        $listing = $contract->listing;
        $unit = $listing?->unit;
        $property = $unit?->property;

        if (! $property) {
            throw ValidationException::withMessages([
                'contract_id' => ['Cannot resolve the property for this contract.'],
            ]);
        }

        $review = Review::create([
            'reviewer_user_id' => $tenant->id,
            'property_id' => $property->id,
            'unit_id' => $unit?->id,
            'landlord_id' => $contract->landlord_id,
            'contract_id' => $contract->id,
            'rating' => $rating,
            'title' => $title,
            'body' => $body,
            'status' => ReviewStatus::PENDING,
        ]);

        $this->auditService->log(
            actor: $tenant,
            action: 'review_submitted',
            subject: $review,
            description: "Tenant submitted review for property {$property->id}",
            severity: 'info'
        );

        // Notify the landlord
        $eventId = "review-submitted:{$review->id}";
        if (! $this->notificationService->exists($contract->landlord, $eventId)) {
            $reviewerName = $tenant->full_name ?: $tenant->email;
            $this->notificationService->create(
                user: $contract->landlord,
                type: NotificationType::REVIEW_SUBMITTED,
                title: 'New Review Received',
                message: "{$reviewerName} has submitted a review for \"{$property->name}\".",
                data: [
                    'event_id' => $eventId,
                    'review_id' => $review->id,
                    'property_id' => $property->id,
                    'property_name' => $property->name,
                    'reviewer_id' => $tenant->id,
                    'reviewer_name' => $reviewerName,
                    'rating' => $rating,
                ]
            );
        }

        return $review;
    }

    /**
     * Admin moderation: approve, reject, hide, or flag a review.
     *
     * @param  string  $action  One of: approve|reject|hide|flag
     *
     * @throws \InvalidArgumentException on invalid action
     */
    public function moderate(Review $review, Admin $admin, string $action, ?string $reason): Review
    {
        [$newStatus, $auditAction] = match ($action) {
            'approve' => [ReviewStatus::APPROVED, 'review_approved'],
            'reject' => [ReviewStatus::REJECTED, 'review_rejected'],
            'hide' => [ReviewStatus::HIDDEN, 'review_hidden'],
            'flag' => [ReviewStatus::FLAGGED, 'review_flagged'],
            default => throw new \InvalidArgumentException("Invalid moderation action: {$action}"),
        };

        $review->status = $newStatus;
        $review->moderation_reason = $reason;
        $review->moderated_by_admin_id = $admin->id;
        $review->save();

        $this->auditService->log(
            actor: $admin,
            action: $auditAction,
            subject: $review,
            description: "Admin {$action}d review {$review->id}",
            metadata: ['reason' => $reason],
            severity: 'warning'
        );

        // On approval, notify the reviewer
        if ($newStatus === ReviewStatus::APPROVED) {
            $reviewer = $review->reviewer;
            if ($reviewer) {
                $eventId = "review-approved:{$review->id}";
                if (! $this->notificationService->exists($reviewer, $eventId)) {
                    $this->notificationService->create(
                        user: $reviewer,
                        type: NotificationType::REVIEW_APPROVED,
                        title: 'Your Review Was Approved',
                        message: "Your review for \"{$review->property?->name}\" has been approved and is now publicly visible.",
                        data: [
                            'event_id' => $eventId,
                            'review_id' => $review->id,
                            'property_id' => $review->property_id,
                            'property_name' => $review->property?->name,
                        ]
                    );
                }
            }
        }

        return $review->fresh();
    }

    /**
     * Landlord response to an approved review.
     *
     * Only the property's landlord may respond, only on an approved review,
     * and a response may be set or updated (no separate "one-time" restriction).
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException on ownership mismatch
     * @throws ValidationException if review is not approved
     */
    public function respond(Review $review, User $landlord, string $text): Review
    {
        // Ownership check
        if ((int) $review->landlord_id !== (int) $landlord->id) {
            throw new \Illuminate\Auth\Access\AuthorizationException(
                'You can only respond to reviews on your own properties.'
            );
        }

        // Status check
        if ($review->status !== ReviewStatus::APPROVED) {
            throw ValidationException::withMessages([
                'review' => ['You can only respond to approved reviews.'],
            ]);
        }

        $review->landlord_response = $text;
        $review->responded_at = now();
        $review->save();

        $this->auditService->log(
            actor: $landlord,
            action: 'review_responded',
            subject: $review,
            description: "Landlord responded to review {$review->id}",
            severity: 'info'
        );

        // Notify the reviewer
        $reviewer = $review->reviewer;
        if ($reviewer) {
            $eventId = "review-response:{$review->id}:{$review->responded_at->timestamp}";
            if (! $this->notificationService->exists($reviewer, $eventId)) {
                $this->notificationService->create(
                    user: $reviewer,
                    type: NotificationType::REVIEW_RESPONSE,
                    title: 'Landlord Responded to Your Review',
                    message: "The landlord has responded to your review for \"{$review->property?->name}\".",
                    data: [
                        'event_id' => $eventId,
                        'review_id' => $review->id,
                        'property_id' => $review->property_id,
                        'property_name' => $review->property?->name,
                    ]
                );
            }
        }

        return $review->fresh();
    }
}
