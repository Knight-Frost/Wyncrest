<?php

namespace App\Listeners;

use App\Events\RentGenerated;
use App\Events\LedgerEntryMarkedOverdue;
use App\Events\PaymentSucceeded;
use App\Events\PaymentFailed;
use App\Enums\NotificationType;
use App\Services\NotificationService;

/**
 * CreateNotificationListener
 * 
 * Universal listener that handles all notification-triggering events.
 * Uses match expression to route to appropriate handler.
 */
class CreateNotificationListener
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    /**
     * Handle the event
     */
    public function handle(object $event): void
    {
        match (true) {
            $event instanceof RentGenerated => $this->handleRentGenerated($event),
            $event instanceof LedgerEntryMarkedOverdue => $this->handleOverdue($event),
            $event instanceof PaymentSucceeded => $this->handlePaymentSuccess($event),
            $event instanceof PaymentFailed => $this->handlePaymentFailure($event),
            default => null,
        };
    }

    /**
     * Handle rent generated event
     */
    protected function handleRentGenerated(RentGenerated $event): void
    {
        $entry = $event->ledgerEntry;
        
        // Generate deterministic event ID for idempotency
        $eventId = "rent-generated:{$entry->id}";
        
        // Check if notification already exists
        if ($this->notificationService->exists($event->tenant, $eventId)) {
            return; // Skip duplicate
        }
        
        $period = "{$entry->billing_period_start->format('M j')} - {$entry->billing_period_end->format('M j, Y')}";
        $amount = '$' . number_format($entry->amount_cents / 100, 2);
        $dueDate = $entry->due_date->format('F j, Y');
        
        $this->notificationService->create(
            user: $event->tenant,
            type: NotificationType::RENT_GENERATED,
            title: 'Rent Generated',
            message: "Your rent for {$period} ({$amount}) is due on {$dueDate}.",
            data: [
                'event_id' => $eventId,
                'ledger_entry_id' => $entry->id,
                'amount_cents' => $entry->amount_cents,
                'due_date' => $entry->due_date->toDateString(),
                'billing_period' => $period,
            ]
        );
    }

    /**
     * Handle overdue event
     */
    protected function handleOverdue(LedgerEntryMarkedOverdue $event): void
    {
        $entry = $event->ledgerEntry;
        
        // Generate deterministic event ID
        $eventId = "rent-overdue:{$entry->id}";
        
        if ($this->notificationService->exists($event->tenant, $eventId)) {
            return;
        }
        
        $amount = '$' . number_format($entry->amount_cents / 100, 2);
        
        $this->notificationService->create(
            user: $event->tenant,
            type: NotificationType::RENT_OVERDUE,
            title: 'Rent Payment Overdue',
            message: "Your rent payment of {$amount} is now overdue. Please pay as soon as possible.",
            data: [
                'event_id' => $eventId,
                'ledger_entry_id' => $entry->id,
                'amount_cents' => $entry->amount_cents,
                'due_date' => $entry->due_date->toDateString(),
                'days_overdue' => now()->diffInDays($entry->due_date),
            ]
        );
    }

    /**
     * Handle payment success event
     */
    protected function handlePaymentSuccess(PaymentSucceeded $event): void
    {
        $payment = $event->paymentEntry;
        
        // Generate deterministic event ID
        $eventId = "payment-succeeded:{$payment->id}";
        
        if ($this->notificationService->exists($event->tenant, $eventId)) {
            return;
        }
        
        // Payment amount is negative, so use absolute value
        $amount = '$' . number_format(abs($payment->amount_cents) / 100, 2);
        
        $this->notificationService->create(
            user: $event->tenant,
            type: NotificationType::PAYMENT_SUCCEEDED,
            title: 'Payment Received',
            message: "Payment of {$amount} received! Thank you.",
            data: [
                'event_id' => $eventId,
                'payment_entry_id' => $payment->id,
                'rent_entry_id' => $event->rentEntry->id,
                'amount_cents' => abs($payment->amount_cents),
                'stripe_payment_intent_id' => $payment->stripe_payment_intent_id,
            ]
        );
    }

    /**
     * Handle payment failure event
     */
    protected function handlePaymentFailure(PaymentFailed $event): void
    {
        $entry = $event->rentEntry;
        
        // Generate deterministic event ID
        $eventId = "payment-failed:{$event->paymentIntentId}";
        
        if ($this->notificationService->exists($event->tenant, $eventId)) {
            return;
        }
        
        $amount = '$' . number_format($entry->amount_cents / 100, 2);
        
        $this->notificationService->create(
            user: $event->tenant,
            type: NotificationType::PAYMENT_FAILED,
            title: 'Payment Failed',
            message: "Payment of {$amount} failed: {$event->errorMessage}. Please try again or update your payment method.",
            data: [
                'event_id' => $eventId,
                'rent_entry_id' => $entry->id,
                'amount_cents' => $entry->amount_cents,
                'stripe_payment_intent_id' => $event->paymentIntentId,
                'error_message' => $event->errorMessage,
            ]
        );
    }
}
