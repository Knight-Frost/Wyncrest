<?php

namespace App\Console\Commands\Phase6;

use App\Events\Cache\CacheInvalidationCompleted;
use App\Events\Cache\CacheInvalidationFailed;
use App\Events\Cache\CacheInvalidationRouted;
use App\Events\Cache\CacheJobCompleted;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;

class MonitorMetrics extends Command
{
    protected $signature = 'phase6:monitor 
                            {duration=60 : Duration in seconds}
                            {--interval=5 : Display interval}';

    protected $description = '[PHASE 6 TEMP] Real-time metrics monitoring';

    private array $metrics = [
        'sync_invalidations' => 0,
        'async_invalidations' => 0,
        'jobs_completed' => 0,
        'jobs_failed' => 0,
        'total_keys_invalidated' => 0,
        'avg_sync_duration' => [],
        'avg_async_duration' => [],
    ];

    public function handle()
    {
        $duration = (int) $this->argument('duration');
        $interval = (int) $this->option('interval');

        $this->setupListeners();

        $this->info('=== MONITORING METRICS ===');
        $this->info("Duration: {$duration}s | Update interval: {$interval}s");
        $this->newLine();

        $start = time();
        $iterations = 0;

        while (time() - $start < $duration) {
            sleep($interval);
            $this->displayMetrics(++$iterations);
        }

        $this->newLine();
        $this->info('Monitoring complete');
        $this->displayFinalSummary();

        return 0;
    }

    private function setupListeners()
    {
        Event::listen(CacheInvalidationRouted::class, function ($event) {
            if ($event->mode === 'sync') {
                $this->metrics['sync_invalidations']++;
            } else {
                $this->metrics['async_invalidations']++;
            }
        });

        Event::listen(CacheInvalidationCompleted::class, function ($event) {
            $this->metrics['total_keys_invalidated'] += $event->invalidatedCount;

            if ($event->mode === 'sync' && $event->durationMs) {
                $this->metrics['avg_sync_duration'][] = $event->durationMs;
            } elseif ($event->mode === 'async' && $event->durationMs) {
                $this->metrics['avg_async_duration'][] = $event->durationMs;
            }
        });

        Event::listen(CacheJobCompleted::class, function () {
            $this->metrics['jobs_completed']++;
        });

        Event::listen(CacheInvalidationFailed::class, function () {
            $this->metrics['jobs_failed']++;
        });
    }

    private function displayMetrics(int $iteration)
    {
        $this->info("--- Update #{$iteration} ---");

        $avgSyncDuration = count($this->metrics['avg_sync_duration']) > 0
            ? round(array_sum($this->metrics['avg_sync_duration']) / count($this->metrics['avg_sync_duration']), 2)
            : 0;

        $avgAsyncDuration = count($this->metrics['avg_async_duration']) > 0
            ? round(array_sum($this->metrics['avg_async_duration']) / count($this->metrics['avg_async_duration']), 2)
            : 0;

        $this->table(
            ['Metric', 'Count'],
            [
                ['Sync Invalidations', $this->metrics['sync_invalidations']],
                ['Async Invalidations', $this->metrics['async_invalidations']],
                ['Jobs Completed', $this->metrics['jobs_completed']],
                ['Jobs Failed', $this->metrics['jobs_failed']],
                ['Keys Invalidated', $this->metrics['total_keys_invalidated']],
                ['Avg Sync Duration', $avgSyncDuration.'ms'],
                ['Avg Async Duration', $avgAsyncDuration.'ms'],
            ]
        );
    }

    private function displayFinalSummary()
    {
        $total = $this->metrics['sync_invalidations'] + $this->metrics['async_invalidations'];
        $asyncRatio = $total > 0
            ? round(($this->metrics['async_invalidations'] / $total) * 100, 2)
            : 0;

        $this->info('=== FINAL SUMMARY ===');
        $this->line("Total Invalidations: {$total}");
        $this->line("Async Ratio: {$asyncRatio}%");
        $this->line('Success Rate: '.
            round((1 - ($this->metrics['jobs_failed'] / max(1, $this->metrics['jobs_completed']))) * 100, 2).'%');
    }
}
