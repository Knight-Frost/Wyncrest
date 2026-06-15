<?php

namespace App\Console\Commands\Phase6;

use App\Jobs\InvalidateAnalyticsCacheJob;
use Illuminate\Console\Command;

class SimulateQueueBacklog extends Command
{
    protected $signature = 'phase6:queue-backlog 
                            {jobs=1000 : Number of jobs to dispatch}
                            {--delay=0 : Delay between dispatches (ms)}';

    protected $description = '[PHASE 6 TEMP] Create queue backlog for pressure testing';

    public function handle()
    {
        $count = (int) $this->argument('jobs');
        $delay = (int) $this->option('delay');

        $this->info('=== QUEUE BACKLOG SIMULATION ===');
        $this->info("Dispatching {$count} jobs with {$delay}ms delay...");
        $this->newLine();

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        for ($i = 0; $i < $count; $i++) {
            InvalidateAnalyticsCacheJob::dispatch('contracts', [
                'user_id' => rand(1, 100),
            ]);

            $bar->advance();

            if ($delay > 0) {
                usleep($delay * 1000);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Jobs dispatched successfully');
        $this->newLine();
        $this->warn('Monitor queue:');
        $this->line('  php artisan queue:monitor');
        $this->line('  php artisan queue:work --queue=analytics-invalidation --once');

        return 0;
    }
}
