<?php

namespace App\Console\Commands\Phase6;

use App\Support\Cache\AnalyticsCacheInvalidator;
use Illuminate\Console\Command;

class StressCacheInvalidation extends Command
{
    protected $signature = 'phase6:stress-invalidation 
                            {count=1000 : Number of invalidations}
                            {--domain=contracts : Cache domain}';

    protected $description = '[PHASE 6 TEMP] Stress test cache invalidation system';

    public function handle()
    {
        $count = (int) $this->argument('count');
        $domain = $this->option('domain');

        $this->info('=== CACHE INVALIDATION STRESS TEST ===');
        $this->info("Triggering {$count} invalidations for domain: {$domain}");
        $this->newLine();

        $start = microtime(true);

        for ($i = 0; $i < $count; $i++) {
            AnalyticsCacheInvalidator::invalidate($domain, [
                'user_id' => rand(1, 100),
                'property_id' => rand(1, 50),
            ]);

            if ($i % 100 === 0) {
                $this->line("Progress: {$i}/{$count}");
            }
        }

        $duration = microtime(true) - $start;

        $this->newLine();
        $this->info('=== RESULTS ===');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Invalidations', $count],
                ['Duration', round($duration, 2).'s'],
                ['Rate', round($count / $duration, 2).' ops/sec'],
                ['Avg Time', round(($duration / $count) * 1000, 2).'ms'],
            ]
        );

        $this->newLine();
        $this->warn('Check storage/logs/laravel.log for sync/async routing decisions');
        $this->warn('Check queue depth: php artisan queue:monitor');

        return 0;
    }
}
