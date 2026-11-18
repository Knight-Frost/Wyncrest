<?php

namespace App\Support\Ops;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Support\Facades\Log;

/**
 * Durable evidence that scheduled jobs ran (forensic audit WYN-010).
 *
 * Every scheduled task outcome (finished, failed, skipped because a previous
 * run still held the overlap lock) is appended as one JSON line to
 * storage/logs/scheduler-runs.log. `php artisan ops:scheduler-status` reads it
 * back and fails when a required billing job has not succeeded recently, so a
 * cron/monitor can alert on a scheduler that silently stopped.
 */
class SchedulerRunLog
{
    public static function path(): string
    {
        return storage_path('logs/scheduler-runs.log');
    }

    public static function finished(ScheduledTaskFinished $e): void
    {
        $exit = $e->task->exitCode;
        self::write($e->task, $exit === 0 || $exit === null ? 'success' : 'failed', ['exit_code' => $exit, 'runtime_s' => round($e->runtime, 3)]);
        if ($exit !== 0 && $exit !== null) {
            Log::error('Scheduled task exited non-zero', ['task' => self::name($e->task), 'exit_code' => $exit]);
        }
    }

    public static function failed(ScheduledTaskFailed $e): void
    {
        self::write($e->task, 'failed', ['error' => mb_substr($e->exception->getMessage(), 0, 300)]);
        Log::error('Scheduled task threw', ['task' => self::name($e->task), 'error' => $e->exception->getMessage()]);
    }

    public static function skipped(ScheduledTaskSkipped $e): void
    {
        self::write($e->task, 'skipped');
    }

    public static function name(ScheduledEvent $task): string
    {
        if (preg_match("/artisan'?\\s+'?([\\w:-]+)/", (string) $task->command, $m)) {
            return $m[1];
        }

        return $task->description ?: (string) $task->command;
    }

    /** @param array<string, mixed> $extra */
    private static function write(ScheduledEvent $task, string $outcome, array $extra = []): void
    {
        $line = json_encode(array_merge([
            'at' => now()->toIso8601String(),
            'task' => self::name($task),
            'outcome' => $outcome,
            'host' => gethostname() ?: null,
        ], $extra), JSON_UNESCAPED_SLASHES);

        @file_put_contents(self::path(), $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * Latest record per task, newest last.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function latest(): array
    {
        $latest = [];
        $latestSuccess = [];
        if (! is_file(self::path())) {
            return [];
        }
        foreach (file(self::path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (! is_array($row) || ! isset($row['task'])) {
                continue;
            }
            $latest[$row['task']] = $row;
            if (($row['outcome'] ?? null) === 'success') {
                $latestSuccess[$row['task']] = $row['at'];
            }
        }
        foreach ($latest as $task => $row) {
            $latest[$task]['last_success_at'] = $latestSuccess[$task] ?? null;
        }

        return $latest;
    }
}
