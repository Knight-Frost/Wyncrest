<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit\AuditClassifier;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AuditLogService
 *
 * Encapsulates all query, filter, summary, and export logic for audit logs.
 * The controller stays thin; all business logic lives here.
 */
class AuditLogService
{
    /**
     * Return a paginated set of audit logs matching the given filters.
     *
     * Supported filters:
     *   severity    string  in:info,warning,critical
     *   area        string  translated to whereIn action via AuditClassifier
     *   actor_role  string  in:admin,landlord,tenant,user,system
     *   from_date   date
     *   to_date     date
     *   search      string  case-insensitive LIKE across action/description/ip/actor
     *   sort        string  'newest' (default) | 'oldest'
     *   per_page    int     1–100, default 20
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = $this->buildQuery($filters);

        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);

        return $query->paginate($perPage);
    }

    /**
     * Resolve a valid IANA timezone from (untrusted) client input, falling back
     * to the app timezone and finally UTC. This is the single place client
     * timezone intent enters the audit query layer.
     */
    public function resolveTimezone(?string $tz): string
    {
        if ($tz !== null && $tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
            return $tz;
        }

        return config('app.timezone') ?: 'UTC';
    }

    /**
     * Convert calendar-day date filters — which the client expresses in its own
     * timezone — into INCLUSIVE UTC timestamp bounds against the UTC-stored
     * created_at column.
     *
     * This is the shared source of truth for audit date filtering so the list
     * and the summary can never drift on timezone handling: a local "to_date"
     * of today (whose end-of-day is the next UTC day for negative-offset zones)
     * correctly includes evening events stored under the next UTC date.
     *
     * @return array{0: ?Carbon, 1: ?Carbon} [startUtc, endUtc]
     */
    public function dateBoundsUtc(array $filters, string $tz): array
    {
        $start = ! empty($filters['from_date'])
            ? Carbon::parse($filters['from_date'], $tz)->startOfDay()->utc()
            : null;

        $end = ! empty($filters['to_date'])
            ? Carbon::parse($filters['to_date'], $tz)->endOfDay()->utc()
            : null;

        return [$start, $end];
    }

    /**
     * Compute real summary metrics from the DB.
     * All counts are today vs yesterday for trend analysis.
     *
     * @return array<string, mixed>
     */
    public function summary(?string $tz = null): array
    {
        // Use the SAME timezone resolution as the list query so "today" here and
        // a to_date=today range in the list are computed on one clock and can
        // never disagree about whether an event belongs to today.
        $tz = $this->resolveTimezone($tz);

        // Convert the tz-local day boundaries to UTC instants for comparison
        // against the UTC-stored created_at column.
        $todayStart = Carbon::today($tz)->utc();
        $yesterdayStart = Carbon::yesterday($tz)->utc();

        // -----------------------------------------------------------------------
        // Raw counts
        // -----------------------------------------------------------------------
        $criticalToday = AuditLog::where('severity', 'critical')
            ->where('created_at', '>=', $todayStart)->count();
        $criticalYesterday = AuditLog::where('severity', 'critical')
            ->whereBetween('created_at', [$yesterdayStart, $todayStart])->count();

        $failedSigninsToday = AuditLog::where('action', 'login_rate_limited')
            ->where('created_at', '>=', $todayStart)->count();
        $failedSigninsYesterday = AuditLog::where('action', 'login_rate_limited')
            ->whereBetween('created_at', [$yesterdayStart, $todayStart])->count();

        $policyActions = ['feature_enabled', 'feature_disabled', 'identity_verified', 'account_suspended', 'account_reactivated'];
        $policyToday = AuditLog::whereIn('action', $policyActions)
            ->where('created_at', '>=', $todayStart)->count();
        $policyYesterday = AuditLog::whereIn('action', $policyActions)
            ->whereBetween('created_at', [$yesterdayStart, $todayStart])->count();

        $activityToday = AuditLog::where('created_at', '>=', $todayStart)->count();
        $activityYesterday = AuditLog::whereBetween('created_at', [$yesterdayStart, $todayStart])->count();

        $needsReviewToday = AuditLog::whereIn('severity', ['critical', 'warning'])
            ->where('created_at', '>=', $todayStart)->count();

        // -----------------------------------------------------------------------
        // Metrics array
        // -----------------------------------------------------------------------
        $metrics = [
            'critical_today' => [
                'value' => $criticalToday,
                'label' => 'Critical events today',
                'trend' => $this->computeTrend($criticalToday, $criticalYesterday),
            ],
            'failed_signins' => [
                'value' => $failedSigninsToday,
                // Honest label — these are rate-limited sign-ins, not necessarily
                // all failed (an attacker hits the limit; the label is accurate).
                'label' => 'Rate-limited sign-ins today',
                'trend' => $this->computeTrend($failedSigninsToday, $failedSigninsYesterday),
            ],
            'policy_changes' => [
                'value' => $policyToday,
                'label' => 'Policy changes today',
                'trend' => $this->computeTrend($policyToday, $policyYesterday),
            ],
            'user_activity' => [
                'value' => $activityToday,
                'label' => 'Total events today',
                'trend' => $this->computeTrend($activityToday, $activityYesterday),
            ],
            'needs_review' => [
                'value' => $needsReviewToday,
                'label' => $needsReviewToday > 0 ? 'Requires your attention' : 'No items',
                // No trend for needs_review — the count itself is the signal.
            ],
        ];

        // -----------------------------------------------------------------------
        // Insights — derived ONLY from real aggregates computed above
        // -----------------------------------------------------------------------
        $insights = [];

        if ($criticalToday === 0) {
            $insights[] = [
                'tone' => 'success',
                'title' => 'No critical events today',
                'detail' => 'The platform has had no critical-severity audit events today.',
                'action' => null,
            ];
        } elseif ($criticalToday > $criticalYesterday && $criticalYesterday > 0) {
            $delta = $criticalToday - $criticalYesterday;
            $insights[] = [
                'tone' => 'danger',
                'title' => "Critical events up by {$delta} vs yesterday",
                'detail' => "Today: {$criticalToday} · Yesterday: {$criticalYesterday}.",
                'action' => ['label' => 'Review now', 'to' => null],
            ];
        }

        if ($failedSigninsToday > $failedSigninsYesterday) {
            $delta = $failedSigninsToday - $failedSigninsYesterday;
            $insights[] = [
                'tone' => $failedSigninsToday >= 5 ? 'danger' : 'warning',
                'title' => 'Rate-limited sign-ins increased today',
                'detail' => "Today: {$failedSigninsToday} · Yesterday: {$failedSigninsYesterday} (↑{$delta}).",
                'action' => ['label' => 'Review users', 'to' => '/app/users'],
            ];
        }

        if ($needsReviewToday > 0) {
            $insights[] = [
                'tone' => 'warning',
                'title' => "{$needsReviewToday} ".($needsReviewToday === 1 ? 'event needs' : 'events need').' review',
                'detail' => 'Events with critical or warning severity require your attention.',
                'action' => null,
            ];
        }

        // "Most activity from {area}" — only when there are events today
        if ($activityToday > 0) {
            $areaCounts = AuditLog::where('created_at', '>=', $todayStart)
                ->get(['action'])
                ->groupBy(fn ($log) => AuditClassifier::area($log->action))
                ->map(fn ($group) => $group->count())
                ->sortDesc();

            if ($areaCounts->isNotEmpty()) {
                $topArea = $areaCounts->keys()->first();
                $topCount = $areaCounts->first();
                $insights[] = [
                    'tone' => 'info',
                    'title' => "Most activity from {$topArea}",
                    'detail' => "{$topCount} ".($topCount === 1 ? 'event' : 'events')." from the {$topArea} area today.",
                    'action' => null,
                ];
            }
        }

        return [
            'metrics' => $metrics,
            'insights' => $insights,
            'stats' => $this->headlineStats($tz, $todayStart, $activityToday),
        ];
    }

    /**
     * Headline stat-strip figures for the Audit page header (all real):
     *   events_today       count today + distinct areas today
     *   total_on_record    lifetime count + "since <first month>"
     *   actors_active_24h  distinct actors in the last 24h + which kinds
     *
     * Chain integrity is intentionally NOT here — it comes from verifyChain()
     * so the (O(n)) recompute runs once per page load, not on every summary.
     *
     * @return array<string, mixed>
     */
    private function headlineStats(string $tz, Carbon $todayStart, int $activityToday): array
    {
        $categoriesToday = AuditLog::where('created_at', '>=', $todayStart)
            ->pluck('action')
            ->map(fn ($action) => AuditClassifier::area($action))
            ->unique()
            ->count();

        $total = AuditLog::count();
        $firstAt = AuditLog::min('created_at');
        $since = $firstAt ? Carbon::parse($firstAt)->timezone($tz)->format('M Y') : null;

        // Distinct actors over the trailing 24h; null actor collapses to one
        // "system" identity. Descriptor lists only the kinds that actually appear.
        $since24h = Carbon::now($tz)->subDay()->utc();
        $recentActors = AuditLog::where('created_at', '>=', $since24h)
            ->get(['actor_type', 'actor_id']);

        $actorKeys = $recentActors
            ->map(fn ($log) => $log->actor_type ? $log->actor_type.'#'.$log->actor_id : 'system')
            ->unique();

        $kinds = [];
        if ($recentActors->contains(fn ($l) => $l->actor_type === Admin::class)) {
            $kinds[] = 'admins';
        }
        if ($recentActors->contains(fn ($l) => $l->actor_type === User::class)) {
            $kinds[] = 'users';
        }
        if ($recentActors->contains(fn ($l) => $l->actor_type === null)) {
            $kinds[] = 'system';
        }

        return [
            'events_today' => [
                'value' => $activityToday,
                'sub' => 'across '.$categoriesToday.' '.($categoriesToday === 1 ? 'category' : 'categories'),
            ],
            'total_on_record' => [
                'value' => $total,
                'sub' => $since ? 'since '.$since : 'no events yet',
            ],
            'actors_active_24h' => [
                'value' => $actorKeys->count(),
                'sub' => $kinds ? implode(', ', $kinds) : 'no activity',
            ],
        ];
    }

    /**
     * Recompute the SHA-256 hash chain over the whole table (oldest → newest)
     * and report whether it is intact. This is REAL verification: it rebuilds
     * each row's canonical payload with the same serialization used at write
     * time and compares both the stored hash and the previous-hash link.
     *
     * A tampered historical row (content edited, or a hash/link overwritten)
     * produces a mismatch that is reported at `broken_at` (the first bad id).
     *
     * @return array{intact: bool, verified: int, total: int, head: ?string, broken_at: ?int, checked_at: string}
     */
    public function verifyChain(): array
    {
        $previous = AuditLog::GENESIS_HASH;
        $verified = 0;
        $total = 0;
        $head = null;
        $brokenAt = null;

        AuditLog::query()->orderBy('id')->chunk(500, function ($rows) use (&$previous, &$verified, &$total, &$head, &$brokenAt) {
            foreach ($rows as $log) {
                $total++;
                $expected = AuditLog::chainHashFor($previous, $log->canonicalFields());

                $linkOk = $log->previous_hash === $previous;
                $hashOk = $log->hash === $expected;

                if ($linkOk && $hashOk) {
                    $verified++;
                } elseif ($brokenAt === null) {
                    $brokenAt = $log->id;
                }

                // Continue from the STORED hash so a single tampered row is
                // localized rather than cascading a break through the tail.
                $previous = $log->hash ?? $expected;
                $head = $log->hash;
            }
        });

        return [
            'intact' => $brokenAt === null && $total === $verified,
            'verified' => $verified,
            'total' => $total,
            'head' => $head,
            'broken_at' => $brokenAt,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Stream a CSV export of filtered audit logs (max 5 000 rows).
     * Reuses buildQuery() — no duplication with paginate().
     */
    public function export(array $filters): StreamedResponse
    {
        $query = $this->buildQuery($filters)->limit(5000);

        $filename = 'audit-logs-'.now()->format('Y-m-d').'.csv';

        return response()->stream(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // Header row
            fputcsv($handle, [
                'Time',
                'Area',
                'Actor',
                'Actor email',
                'Action',
                'Summary',
                'Severity',
                'Status',
                'IP',
            ]);

            $query->with(['actor', 'subject'])->chunk(500, function ($logs) use ($handle) {
                foreach ($logs as $log) {
                    $actor = $log->actor;
                    $actorName = $this->resolveActorName($actor);
                    $actorEmail = $actor ? $actor->email : null;
                    $status = AuditClassifier::status($log->severity);

                    fputcsv($handle, [
                        $log->created_at?->toIso8601String(),
                        AuditClassifier::area($log->action),
                        $actorName,
                        $actorEmail,
                        $log->action,
                        $log->description ?? AuditClassifier::actionLabel($log->action),
                        $log->severity,
                        $status['label'],
                        $log->ip_address,
                    ]);
                }
            });

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-store',
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build the base Eloquent query from a filters array.
     * Shared between paginate() and export() to keep logic DRY.
     */
    private function buildQuery(array $filters)
    {
        $query = AuditLog::query()->with(['actor', 'subject']);

        // Severity
        if (! empty($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }

        // Area → translate to a whereIn on action column
        if (! empty($filters['area'])) {
            $areaMap = AuditClassifier::areaToActions();
            $actions = $areaMap[$filters['area']] ?? [];
            if (empty($actions)) {
                // Area is valid but has no mapped actions — return nothing
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('action', $actions);
            }
        }

        // Actor role
        if (! empty($filters['actor_role'])) {
            $role = $filters['actor_role'];
            if ($role === 'admin') {
                $query->where('actor_type', Admin::class);
            } elseif ($role === 'system') {
                $query->whereNull('actor_type');
            } elseif (in_array($role, ['tenant', 'landlord'])) {
                $query->where('actor_type', User::class)
                    ->whereHas('actor', fn ($q) => $q->where('user_type', $role));
            } elseif ($role === 'user') {
                $query->where('actor_type', User::class);
            }
        }

        // Date bounds — normalized to inclusive UTC instants in the client's
        // timezone via the shared helper (see dateBoundsUtc). Never naive.
        $tz = $this->resolveTimezone($filters['tz'] ?? null);
        [$from, $to] = $this->dateBoundsUtc($filters, $tz);
        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }

        // Search — wrapped in a closure so AND filters are not broken
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term) {
                $q->where('action', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%")
                    ->orWhere('ip_address', 'like', "%{$term}%")
                    ->orWhereHas('actor', function ($actorQ) use ($term) {
                        // Admin actors have name + email; User actors have full_name + email
                        $actorQ->where(function ($inner) use ($term) {
                            $inner->where('email', 'like', "%{$term}%")
                                ->orWhere('name', 'like', "%{$term}%")         // Admin
                                ->orWhere('first_name', 'like', "%{$term}%")   // User
                                ->orWhere('last_name', 'like', "%{$term}%");   // User
                        });
                    });
            });
        }

        // Sort
        $direction = ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc';
        $query->orderBy('created_at', $direction);

        return $query;
    }

    /**
     * Compute a trend comparison object.
     *
     * @return array{direction: string, pct: int|null, label: string}
     */
    private function computeTrend(int $today, int $yesterday): array
    {
        if ($yesterday === 0) {
            return [
                'direction' => $today > 0 ? 'up' : 'flat',
                'pct' => null,
                'label' => 'No prior-day baseline',
            ];
        }

        $pct = (int) round((($today - $yesterday) / $yesterday) * 100);

        if ($pct > 0) {
            return ['direction' => 'up',   'pct' => $pct, 'label' => "+{$pct}% vs yesterday"];
        } elseif ($pct < 0) {
            return ['direction' => 'down', 'pct' => abs($pct), 'label' => abs($pct).'% lower than yesterday'];
        }

        return ['direction' => 'flat', 'pct' => 0, 'label' => 'Same as yesterday'];
    }

    /**
     * Resolve a display name from a polymorphic actor model.
     */
    private function resolveActorName(?object $actor): string
    {
        if ($actor === null) {
            return 'System';
        }

        if ($actor instanceof Admin) {
            return $actor->name ?? 'Admin';
        }

        if ($actor instanceof User) {
            $full = trim(($actor->first_name ?? '').' '.($actor->last_name ?? ''));

            return $full !== '' ? $full : ($actor->email ?? 'User');
        }

        return 'Unknown';
    }
}
