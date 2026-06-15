<?php

namespace App\Console\Commands\Phase6;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class InjectFailure extends Command
{
    protected $signature = 'phase6:inject-failure {type}';

    protected $description = '[PHASE 6 TEMP] Inject failures for chaos testing';

    public function handle()
    {
        $type = $this->argument('type');

        $this->warn('=== FAILURE INJECTION ===');
        $this->warn("Type: {$type}");
        $this->newLine();

        switch ($type) {
            case 'redis-flush':
                $this->flushRedis();
                break;

            case 'queue-clear':
                $this->clearQueue();
                break;

            case 'test-redis':
                $this->testRedisConnection();
                break;

            case 'test-queue':
                $this->testQueueConnection();
                break;

            case 'list':
                $this->listFailureTypes();
                break;

            default:
                $this->error("Unknown failure type: {$type}");
                $this->line('Run: php artisan phase6:inject-failure list');

                return 1;
        }

        return 0;
    }

    private function flushRedis()
    {
        try {
            Cache::flush();
            $this->info('✓ Redis cache flushed');
            $this->warn('All cached analytics cleared');
        } catch (\Exception $e) {
            $this->error('✗ Failed to flush Redis: '.$e->getMessage());
        }
    }

    private function clearQueue()
    {
        try {
            Artisan::call('queue:clear', ['--queue' => 'analytics-invalidation']);
            $this->info('✓ Queue cleared');
            $this->warn('All pending invalidation jobs removed');
        } catch (\Exception $e) {
            $this->error('✗ Failed to clear queue: '.$e->getMessage());
        }
    }

    private function testRedisConnection()
    {
        try {
            Redis::ping();
            $this->info('✓ Redis: CONNECTED');

            // Get some stats
            $info = Redis::info();
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Connected Clients', $info['connected_clients'] ?? 'N/A'],
                    ['Used Memory', $info['used_memory_human'] ?? 'N/A'],
                    ['Total Keys', Redis::dbsize()],
                ]
            );
        } catch (\Exception $e) {
            $this->error('✗ Redis: DISCONNECTED');
            $this->error('Error: '.$e->getMessage());
        }
    }

    private function testQueueConnection()
    {
        try {
            $size = \Illuminate\Support\Facades\Queue::size('analytics-invalidation');
            $this->info('✓ Queue: CONNECTED');
            $this->line("Pending jobs: {$size}");
        } catch (\Exception $e) {
            $this->error('✗ Queue: DISCONNECTED');
            $this->error('Error: '.$e->getMessage());
        }
    }

    private function listFailureTypes()
    {
        $this->table(
            ['Type', 'Description'],
            [
                ['redis-flush', 'Clear all Redis cache (simulates cache loss)'],
                ['queue-clear', 'Clear queue backlog (simulates queue failure)'],
                ['test-redis', 'Test Redis connection and show stats'],
                ['test-queue', 'Test queue connection and show depth'],
            ]
        );
    }
}
