<?php

namespace App\Console\Commands;

use App\Support\Ops\SchedulerRunLog;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * ops:scheduler-status — is the scheduler actually running the jobs billing
 * depends on? Exit 0 = healthy, 1 = a required job is missing or stale.
 * Intended for a monitor (or a human after deploy). See docs/OPERATIONS.md.
 */
class SchedulerStatusCommand extends Command
{
    protected $signature = 'ops:scheduler-status {--json : Machine-readable output}';

    protected $description = 'Report the last run of each scheduled task and fail if a required job is stale';

    /** task => maximum age (hours) of its last success before it is "stale". */
    private const REQUIRED = [
        'ledger:generate-rent' => 26,
        'contracts:mark-expired' => 26,
        'ledger:mark-overdue' => 26,
        'notifications:deliver' => 1,
    ];

    public function handle(): int
    {
        $latest = SchedulerRunLog::latest();
        $rows = [];
        $healthy = true;

        foreach (self::REQUIRED as $task => $maxHours) {
            $last = $latest[$task] ?? null;
            $success = isset($last['last_success_at']) ? Carbon::parse($last['last_success_at']) : null;
            $ok = $success !== null && $success->gt(now()->subHours($maxHours));
            $healthy = $healthy && $ok;
            $rows[] = [
                'task' => $task,
                'last_outcome' => $last['outcome'] ?? 'never',
                'last_run_at' => $last['at'] ?? null,
                'last_success_at' => $success?->toIso8601String(),
                'max_age_hours' => $maxHours,
                'status' => $ok ? 'ok' : 'STALE',
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode(['healthy' => $healthy, 'tasks' => $rows, 'others' => array_values(array_diff_key($latest, self::REQUIRED))], JSON_PRETTY_PRINT));
        } else {
            $this->table(['Task', 'Last outcome', 'Last run', 'Last success', 'Max age (h)', 'Status'], array_map('array_values', $rows));
            $healthy ? $this->info('Scheduler healthy.') : $this->error('Scheduler NOT healthy: a required job has not succeeded within its window. See docs/OPERATIONS.md.');
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
