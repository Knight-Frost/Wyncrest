<?php

namespace App\Services\Admin;

use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceRequest;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * MaintenanceOverviewService
 *
 * Read model for the admin maintenance queue — the platform's first
 * admin-facing view into maintenance requests (previously landlord/tenant
 * only). Every figure here is derived from real columns; there is no SLA/
 * due-by column on maintenance_requests, so "overdue" is defined
 * pragmatically as an open request whose landlord-set expected_completion_date
 * has passed. There is no "waiting on landlord vs tenant" flag captured at
 * write time (only a free-text waiting_reason) — this service never invents
 * that split.
 */
class MaintenanceOverviewService
{
    /**
     * Truthful counts for the dashboard's Maintenance Escalations card and
     * the admin maintenance queue header.
     */
    public function summary(): array
    {
        $open = $this->openBase()->count();
        $urgent = $this->openBase()
            ->whereIn('priority', [MaintenancePriority::URGENT->value, MaintenancePriority::HIGH->value])
            ->count();
        $overdue = $this->overdueBase()->count();
        $waiting = $this->openBase()->where('status', MaintenanceStatus::WAITING->value)->count();

        $oldest = $this->openBase()
            ->with(['tenant', 'landlord', 'property', 'unit'])
            ->orderBy('submitted_at', 'asc')
            ->first();

        return [
            'open' => $open,
            'urgent' => $urgent,
            'overdue' => $overdue,
            'waiting' => $waiting,
            'oldest' => $oldest ? $this->rowSummary($oldest) : null,
        ];
    }

    /**
     * Filtered case list — reused by the admin maintenance list page and by
     * the dashboard's Priority Cases feed.
     *
     * @param  array{status?:string,limit?:int}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function cases(array $filters = []): array
    {
        $status = $filters['status'] ?? 'open';

        $query = MaintenanceRequest::query()->with(['tenant', 'landlord', 'property', 'unit']);

        match ($status) {
            'urgent' => $query->open()->whereIn('priority', [MaintenancePriority::URGENT->value, MaintenancePriority::HIGH->value]),
            'overdue' => $this->applyOverdue($query->open()),
            'waiting' => $query->open()->where('status', MaintenanceStatus::WAITING->value),
            'all' => null,
            default => $query->open(),
        };

        $query->orderBy('submitted_at', 'asc');

        if (! empty($filters['limit'])) {
            $query->limit($filters['limit']);
        }

        return $query->get()->map(fn (MaintenanceRequest $r) => $this->rowSummary($r))->all();
    }

    /**
     * Platform-wide maintenance analytics for the Super Admin Analytics page:
     * resolution volume/speed and open-work breakdowns. `date_from`/`date_to`
     * (optional) scope the resolved-work figures to `resolved_at` so "resolved
     * this period" and "average resolution time this period" move together;
     * the open-work snapshot (by priority/category) is always as-of-now, same
     * as summary() above, since there is no meaningful "open as of a past
     * date" without event-sourcing the status column.
     *
     * @param  array{date_from?:string,date_to?:string}  $filters
     */
    public function analytics(array $filters = []): array
    {
        $resolvedQuery = MaintenanceRequest::query()->whereNotNull('resolved_at');
        if (! empty($filters['date_from'])) {
            $resolvedQuery->where('resolved_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $resolvedQuery->where('resolved_at', '<=', $filters['date_to']);
        }

        $resolved = $resolvedQuery->get();
        $resolvedCount = $resolved->count();

        $resolutionDays = $resolved
            ->filter(fn (MaintenanceRequest $r) => $r->submitted_at !== null)
            ->map(fn (MaintenanceRequest $r) => $r->submitted_at->floatDiffInDays($r->resolved_at));
        $averageResolutionDays = $resolutionDays->isNotEmpty() ? round($resolutionDays->avg(), 2) : 0.0;

        $responded = MaintenanceRequest::query()->whereNotNull('acknowledged_at')->whereNotNull('submitted_at')->get();
        $responseHours = $responded->map(fn (MaintenanceRequest $r) => $r->submitted_at->floatDiffInHours($r->acknowledged_at));
        $averageResponseHours = $responseHours->isNotEmpty() ? round($responseHours->avg(), 2) : 0.0;

        $byPriority = $this->groupCountsByEnumColumn($this->openBase(), 'priority');
        $byCategory = $this->groupCountsByEnumColumn($this->openBase(), 'category');

        $repeatProperties = $this->openBase()
            ->whereNotNull('property_id')
            ->selectRaw('property_id, COUNT(*) as aggregate')
            ->groupBy('property_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $resolutionTrend = $resolved
            ->groupBy(fn (MaintenanceRequest $r) => $r->resolved_at->format('Y-m'))
            ->map(function ($group) {
                $days = $group->filter(fn (MaintenanceRequest $r) => $r->submitted_at !== null)
                    ->map(fn (MaintenanceRequest $r) => $r->submitted_at->floatDiffInDays($r->resolved_at));

                return $days->isNotEmpty() ? round($days->avg(), 2) : 0.0;
            })
            ->sortKeys();

        return [
            'resolved_count' => $resolvedCount,
            'average_response_hours' => $averageResponseHours,
            'average_resolution_days' => $averageResolutionDays,
            'repeat_issue_properties' => $repeatProperties,
            'by_priority' => $byPriority,
            'by_category' => $byCategory,
            'resolution_trend_by_month' => $resolutionTrend->toArray(),
        ];
    }

    /**
     * Group-and-count an enum-cast column, converting keys to their raw
     * string value (an enum-cast column can't be a plain pluck() key).
     *
     * @return array<string, int>
     */
    protected function groupCountsByEnumColumn(Builder $query, string $column): array
    {
        $rows = $query->selectRaw("{$column}, COUNT(*) as aggregate")->groupBy($column)->get();

        $output = [];
        foreach ($rows as $row) {
            $value = $row->{$column};
            $key = $value instanceof \BackedEnum ? $value->value : (string) $value;
            $output[$key] = (int) $row->aggregate;
        }

        return $output;
    }

    protected function openBase(): Builder
    {
        return MaintenanceRequest::query()->open();
    }

    protected function overdueBase(): Builder
    {
        return $this->applyOverdue($this->openBase());
    }

    protected function applyOverdue(Builder $query): Builder
    {
        return $query
            ->whereNotNull('expected_completion_date')
            ->whereDate('expected_completion_date', '<', Carbon::today()->toDateString());
    }

    /**
     * @return array<string, mixed>
     */
    protected function rowSummary(MaintenanceRequest $r): array
    {
        $today = Carbon::today();
        $isOverdue = $r->expected_completion_date !== null
            && $r->status->isOpen()
            && $r->expected_completion_date->lt($today);

        $property = collect([$r->property?->name, $r->unit?->display_name])->filter()->implode(' · ');

        return [
            'id' => $r->id,
            'title' => $r->title,
            'category' => $r->category?->value,
            'priority' => $r->priority?->value,
            'status' => $r->status?->value,
            'tenant' => $r->tenant ? ['id' => $r->tenant->id, 'name' => $r->tenant->full_name] : null,
            'landlord' => $r->landlord ? ['id' => $r->landlord->id, 'name' => $r->landlord->full_name] : null,
            'property' => $property !== '' ? $property : null,
            'waiting_reason' => $r->waiting_reason,
            'submitted_at' => $r->submitted_at?->toIso8601String(),
            'age_days' => $r->submitted_at ? (int) abs($r->submitted_at->diffInDays($today)) : 0,
            'expected_completion_date' => $r->expected_completion_date?->toDateString(),
            'is_overdue' => $isOverdue,
            'has_severe_safety_flag' => $r->has_severe_safety_flag,
        ];
    }
}
