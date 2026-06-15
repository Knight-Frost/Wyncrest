<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ValidateQueueHealth Command - Phase 7.5 Task 3
 *
 * Validates queue and cache health:
 * - Checks for stuck jobs
 * - Monitors failed jobs
 * - Validates cache operations
 * - Reports queue metrics
 */
class ValidateQueueHealth extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'queue:health-check 
                            {--detailed : Show detailed information}';

    /**
     * The console command description.
     */
    protected $description = 'Validate queue and cache health (Phase 7.5)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔍 Phase 7.5 Queue & Cache Health Check');
        $this->newLine();

        $healthy = true;

        // Check queue health
        $queueHealth = $this->checkQueueHealth();
        if (! $queueHealth) {
            $healthy = false;
        }

        $this->newLine();

        // Check cache health
        $cacheHealth = $this->checkCacheHealth();
        if (! $cacheHealth) {
            $healthy = false;
        }

        $this->newLine();

        // Overall status
        if ($healthy) {
            $this->info('✅ Overall Status: HEALTHY');

            return self::SUCCESS;
        } else {
            $this->error('❌ Overall Status: ISSUES DETECTED');

            return self::FAILURE;
        }
    }

    /**
     * Check queue health
     */
    private function checkQueueHealth(): bool
    {
        $this->info('📊 Queue Health:');

        try {
            // Get pending jobs
            $pendingJobs = DB::table('jobs')->count();

            // Get failed jobs
            $failedJobs = DB::table('failed_jobs')->count();

            // Get stuck jobs (jobs older than 1 hour)
            $stuckJobs = DB::table('jobs')
                ->where('created_at', '<', now()->subHour())
                ->count();

            // Display metrics
            $this->line("  Pending jobs: {$pendingJobs}");
            $this->line("  Failed jobs: {$failedJobs}");
            $this->line("  Stuck jobs (>1h): {$stuckJobs}");

            // Detailed view
            if ($this->option('detailed') && $stuckJobs > 0) {
                $this->warn('  Stuck jobs detected:');
                $stuck = DB::table('jobs')
                    ->where('created_at', '<', now()->subHour())
                    ->select('id', 'queue', 'payload', 'created_at')
                    ->get();

                foreach ($stuck as $job) {
                    $this->line("    - Job {$job->id} (queue: {$job->queue}, age: ".
                        now()->diffForHumans($job->created_at).')');
                }
            }

            // Health check
            $healthy = true;

            if ($stuckJobs > 0) {
                $this->warn('  ⚠️  Warning: Stuck jobs detected');
                $healthy = false;
            }

            if ($failedJobs > 10) {
                $this->error('  ❌ Error: High number of failed jobs');
                $healthy = false;
            }

            if ($pendingJobs > 100) {
                $this->warn('  ⚠️  Warning: High queue backlog');
                $healthy = false;
            }

            if ($healthy) {
                $this->info('  ✅ Queue: HEALTHY');
            }

            return $healthy;

        } catch (\Exception $e) {
            $this->error('  ❌ Error checking queue: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Check cache health
     */
    private function checkCacheHealth(): bool
    {
        $this->info('💾 Cache Health:');

        try {
            // Test write
            $testKey = 'health_check_'.time();
            Cache::put($testKey, 'test_value', 60);

            // Test read
            $value = Cache::get($testKey);

            if ($value !== 'test_value') {
                $this->error('  ❌ Cache read/write failed');

                return false;
            }

            // Test delete (invalidation)
            Cache::forget($testKey);
            $value = Cache::get($testKey);

            if ($value !== null) {
                $this->error('  ❌ Cache invalidation failed');

                return false;
            }

            $this->info('  ✅ Cache: OPERATIONAL');
            $this->line('  - Write: ✅');
            $this->line('  - Read: ✅');
            $this->line('  - Invalidation: ✅');

            // Check cache driver
            $driver = config('cache.default');
            $this->line("  - Driver: {$driver}");

            return true;

        } catch (\Exception $e) {
            $this->error('  ❌ Error checking cache: '.$e->getMessage());

            return false;
        }
    }
}
