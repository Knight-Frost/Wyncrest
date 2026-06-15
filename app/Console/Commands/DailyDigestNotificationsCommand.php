<?php

namespace App\Console\Commands;

use App\Services\NotificationDigestService;
use Illuminate\Console\Command;

/**
 * DailyDigestNotificationsCommand
 *
 * Sends daily notification digests to users.
 * Phase 3.9: Batches notifications from the last 24 hours.
 */
class DailyDigestNotificationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:digest-daily';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send daily notification digests via email and SMS';

    /**
     * Execute the console command.
     */
    public function handle(NotificationDigestService $digestService): int
    {
        $this->info('📬 Nexus - Daily Notification Digest');
        $this->info('===================================');
        $this->newLine();

        $this->info('🔄 Processing daily digests...');

        $result = $digestService->sendDailyDigests();

        $this->newLine();
        $this->info('📊 Digest Summary:');
        $this->line("   👥 Users processed: {$result['users']}");
        $this->line("   📧 Email digests sent: {$result['email_digests']}");
        $this->line("   📱 SMS digests sent: {$result['sms_digests']}");
        $this->line("   📋 Total notifications: {$result['notifications']}");

        if ($result['email_digests'] === 0 && $result['sms_digests'] === 0) {
            $this->newLine();
            $this->info('✨ No digests to send today');
        } else {
            $this->newLine();
            $this->info('✅ Daily digests completed successfully!');
        }

        return Command::SUCCESS;
    }
}
