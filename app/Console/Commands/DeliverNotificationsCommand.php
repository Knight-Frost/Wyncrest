<?php

namespace App\Console\Commands;

use App\Services\NotificationDeliveryService;
use Illuminate\Console\Command;

/**
 * DeliverNotificationsCommand
 *
 * Delivers pending notifications via email.
 * Phase 3.6: Idempotent, safe to run multiple times.
 */
class DeliverNotificationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:deliver 
                            {--limit=50 : Maximum number of notifications to deliver}
                            {--retry : Retry previously failed deliveries}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deliver pending notifications via email';

    /**
     * Execute the console command.
     */
    public function handle(NotificationDeliveryService $deliveryService): int
    {
        $this->info('📬 Nexus - Notification Delivery');
        $this->info('================================');
        $this->newLine();

        $limit = (int) $this->option('limit');
        $retry = $this->option('retry');

        if ($retry) {
            $this->info('🔄 Retrying failed deliveries...');
            $result = $deliveryService->retryFailed($limit);

            $this->newLine();
            $this->info('📊 Retry Summary:');
            $this->line("   ✅ Delivered: {$result['delivered']}");
            $this->line("   ❌ Failed: {$result['failed']}");

            return Command::SUCCESS;
        }

        // Get pending count before delivery
        $pendingCount = $deliveryService->getPendingCount();

        if ($pendingCount === 0) {
            $this->info('✨ No pending notifications to deliver');

            return Command::SUCCESS;
        }

        $this->info("📋 Found {$pendingCount} pending notification(s)");
        $this->info("🚀 Delivering up to {$limit} notification(s)...");
        $this->newLine();

        // Deliver notifications
        $result = $deliveryService->deliverPending($limit);

        $this->info('📊 Delivery Summary:');
        $this->line("   ✅ Delivered: {$result['delivered']}");
        $this->line("   ❌ Failed: {$result['failed']}");
        $this->line("   ⏭️  Skipped: {$result['skipped']}");

        $this->newLine();

        // Show remaining count
        $remainingCount = $deliveryService->getPendingCount();
        if ($remainingCount > 0) {
            $this->warn("⚠️  {$remainingCount} notification(s) still pending");
        } else {
            $this->info('✨ All pending notifications delivered!');
        }

        // Show failed count if any
        $failedCount = $deliveryService->getFailedCount();
        if ($failedCount > 0) {
            $this->newLine();
            $this->error("⚠️  {$failedCount} notification(s) have failed delivery");
            $this->line('   Run with --retry to attempt redelivery');
        }

        return Command::SUCCESS;
    }
}
