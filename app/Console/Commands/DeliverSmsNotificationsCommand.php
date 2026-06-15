<?php

namespace App\Console\Commands;

use App\Services\SmsDeliveryService;
use Illuminate\Console\Command;

/**
 * DeliverSmsNotificationsCommand
 *
 * Delivers pending notifications via SMS.
 * Phase 3.7: Idempotent, safe to run multiple times.
 * Mirrors DeliverNotificationsCommand architecture exactly.
 */
class DeliverSmsNotificationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:sms-deliver 
                            {--limit=50 : Maximum number of notifications to deliver}
                            {--retry : Retry previously failed deliveries}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deliver pending notifications via SMS';

    /**
     * Execute the console command.
     */
    public function handle(SmsDeliveryService $deliveryService): int
    {
        $this->info('📱 Nexus - SMS Notification Delivery');
        $this->info('====================================');
        $this->newLine();

        $limit = (int) $this->option('limit');
        $retry = $this->option('retry');

        if ($retry) {
            $this->info('🔄 Retrying failed SMS deliveries...');
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
            $this->info('✨ No pending SMS notifications to deliver');

            return Command::SUCCESS;
        }

        $this->info("📋 Found {$pendingCount} pending SMS notification(s)");
        $this->info("🚀 Delivering up to {$limit} SMS notification(s)...");
        $this->newLine();

        // Deliver SMS notifications
        $result = $deliveryService->deliverPending($limit);

        $this->info('📊 Delivery Summary:');
        $this->line("   ✅ Delivered: {$result['delivered']}");
        $this->line("   ❌ Failed: {$result['failed']}");
        $this->line("   ⏭️  Skipped: {$result['skipped']}");

        $this->newLine();

        // Show remaining count
        $remainingCount = $deliveryService->getPendingCount();
        if ($remainingCount > 0) {
            $this->warn("⚠️  {$remainingCount} SMS notification(s) still pending");
        } else {
            $this->info('✨ All pending SMS notifications delivered!');
        }

        // Show failed count if any
        $failedCount = $deliveryService->getFailedCount();
        if ($failedCount > 0) {
            $this->newLine();
            $this->error("⚠️  {$failedCount} SMS notification(s) have failed delivery");
            $this->line('   Run with --retry to attempt redelivery');
        }

        return Command::SUCCESS;
    }
}
