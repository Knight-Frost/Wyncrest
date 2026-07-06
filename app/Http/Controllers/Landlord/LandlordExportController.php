<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Services\AuditService;
use App\Services\LandlordAnalyticsService;
use App\Services\Ledger\LedgerComputationEngine;
use App\Services\TenantReadinessService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * LandlordExportController
 *
 * CSV downloads for the authenticated landlord. Every query is strictly scoped
 * to the landlord's own id — another landlord's data is never reachable here.
 * All exports are read-only projections; nothing is mutated.
 */
class LandlordExportController extends Controller
{
    public function __construct(
        protected LedgerComputationEngine $engine,
        protected TenantReadinessService $readiness,
        protected AuditService $auditService
    ) {}

    /**
     * Ledger export (CSV). Amount is the signed balance-impact figure (so a
     * spreadsheet import nets to the same balance as the app) with an
     * explicit Direction column so it's never ambiguous in isolation.
     *
     * Optionally scoped to one contract, one property, and/or a date range so
     * the console's "export what I'm looking at" is exact. The export is
     * always constrained to the landlord's own id first, then written to the
     * append-only audit log (actor, filters, row count, stated reason) since
     * it moves financial data out of the app.
     */
    public function ledger(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', LedgerEntry::class);

        $landlordId = $request->user()->id;

        $filters = $request->validate([
            'contract_id' => ['sometimes', 'uuid'],
            'property_id' => ['sometimes', 'integer'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'reason' => ['sometimes', 'string', 'max:255'],
        ]);

        // landlord_id is forced last so no filter combination can widen scope
        // beyond the authenticated landlord's own entries.
        $scoped = array_merge(
            array_intersect_key($filters, array_flip(['contract_id', 'property_id', 'date_from', 'date_to'])),
            ['landlord_id' => $landlordId]
        );

        $entries = $this->engine->applyFilters(
            LedgerEntry::with(['tenant', 'contract.listing.unit.property']),
            $scoped
        )->orderBy('due_date', 'desc')->get();

        $this->auditService->log(
            actor: $request->user(),
            action: 'ledger_exported',
            subject: null,
            description: "Landlord ledger export generated: {$entries->count()} entries.",
            severity: 'info',
            metadata: [
                'filters' => $scoped,
                'row_count' => $entries->count(),
                'reason' => $filters['reason'] ?? null,
            ]
        );

        $balances = $this->engine->computeRunningBalances($entries);

        $rows = $entries->map(function (LedgerEntry $entry) use ($balances) {
            $unit = $entry->contract?->listing?->unit;
            $property = $unit?->property;

            return [
                $entry->due_date?->format('Y-m-d'),
                $this->tenantName($entry->tenant),
                $unit?->unit_number,
                $property?->name,
                $this->engine->displayLabel($entry),
                $this->engine->direction($entry),
                $this->engine->reference($entry),
                number_format($this->engine->displayAmountCents($entry) / 100, 2, '.', ''),
                number_format($this->engine->balanceImpactCents($entry) / 100, 2, '.', ''),
                $entry->status->value,
                number_format(($balances[$entry->id] ?? 0) / 100, 2, '.', ''),
            ];
        })->all();

        return $this->stream(
            'ledger.csv',
            ['Date', 'Tenant', 'Unit', 'Property', 'Type', 'Direction', 'Reference', 'Amount', 'Balance Impact', 'Status', 'Balance after'],
            $rows
        );
    }

    /**
     * Listings export (CSV).
     */
    public function listings(Request $request): StreamedResponse
    {
        $listings = Listing::where('landlord_id', $request->user()->id)
            ->with(['unit.property'])
            ->withCount('applications')
            ->orderByDesc('created_at')
            ->get();

        $rows = $listings->map(function (Listing $listing) {
            $unit = $listing->unit;
            $property = $unit?->property;

            return [
                $listing->title,
                $property?->name,
                $unit?->unit_number,
                $listing->status->value,
                $unit ? number_format((float) $unit->rent_amount, 2, '.', '') : '',
                (int) $listing->view_count,
                (int) $listing->applications_count,
                $listing->featured ? 'yes' : 'no',
                $listing->updated_at?->format('Y-m-d'),
            ];
        })->all();

        return $this->stream(
            'listings.csv',
            ['Title', 'Property', 'Unit', 'Status', 'Rent', 'Views', 'Applications', 'Featured', 'Updated'],
            $rows
        );
    }

    /**
     * Applications export (CSV).
     */
    public function applications(Request $request): StreamedResponse
    {
        $applications = Application::where('landlord_id', $request->user()->id)
            ->with(['tenant', 'listing.unit'])
            ->latest()
            ->get();

        $rows = $applications->map(function (Application $application) {
            $tenant = $application->tenant;
            $listing = $application->listing;
            $readiness = $this->readiness->compute($tenant);

            return [
                $this->tenantName($tenant),
                $tenant?->email,
                $listing?->title,
                $listing?->unit?->unit_number,
                $application->status->value,
                $readiness['percentage'].'%',
                $application->submitted_at?->format('Y-m-d'),
            ];
        })->all();

        return $this->stream(
            'applications.csv',
            ['Applicant', 'Email', 'Listing', 'Unit', 'Status', 'Readiness %', 'Submitted'],
            $rows
        );
    }

    /**
     * Analytics export (CSV) — a full, multi-section record of everything the
     * Analytics page shows (summary, financial trend, occupancy, listings
     * funnel, payment behaviour, maintenance, and the property performance
     * table), respecting the same range/property filters as the page.
     * Audited and SHA-256 checksummed like the maintenance export, since it
     * moves financial data out of the app.
     */
    public function analytics(Request $request, LandlordAnalyticsService $service): \Illuminate\Http\Response
    {
        $filters = $request->validate([
            'range' => ['sometimes', 'string', 'in:this,last,90,ytd'],
            'property_id' => ['sometimes', 'integer'],
        ]);

        $landlordId = $request->user()->id;
        $range = $filters['range'] ?? 'this';
        $propertyId = $filters['property_id'] ?? null;
        $payload = $service->build($landlordId, $range, $propertyId);

        $propertyName = $propertyId
            ? (\App\Models\Property::where('landlord_id', $landlordId)->where('id', $propertyId)->value('name') ?? 'Unknown property')
            : 'All properties';

        $csv = $this->buildAnalyticsCsv($payload, $propertyName);
        $checksum = hash('sha256', $csv);

        $this->auditService->log(
            actor: $request->user(),
            action: 'analytics_exported',
            subject: null,
            description: "Landlord analytics export generated: {$payload['range']['label']}, {$propertyName}, ".count($payload['properties']).' properties.',
            severity: 'info',
            metadata: [
                'range' => $payload['range'],
                'property_id' => $propertyId,
                'checksum' => $checksum,
            ]
        );

        $filename = 'analytics-'.now()->format('Y-m-d').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'X-Export-Checksum' => $checksum,
            'X-Export-Row-Count' => (string) count($payload['properties']),
            'Access-Control-Expose-Headers' => 'X-Export-Checksum, X-Export-Row-Count',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function buildAnalyticsCsv(array $payload, string $propertyName): string
    {
        $handle = fopen('php://temp', 'r+');
        $write = fn (array $row = []) => fputcsv($handle, $row);
        $cedis = fn (int $cents) => number_format($cents / 100, 2, '.', '');

        $write(['Wyncrest Portfolio Analytics Report']);
        $write(['Generated at', now()->toDateTimeString()]);
        $write(['Date range', $payload['range']['label'], $payload['range']['from'], $payload['range']['to']]);
        $write(['Property filter', $propertyName]);
        $write();

        $write(['SUMMARY']);
        $write(['Metric', 'Value']);
        $write(['Rent collected', $cedis($payload['summary']['collected_cents'])]);
        $write(['Expected rent', $cedis($payload['summary']['expected_cents'])]);
        $write(['Outstanding rent', $cedis($payload['summary']['outstanding_cents'])]);
        $collectionRate = $payload['summary']['expected_cents'] > 0
            ? round($payload['summary']['collected_cents'] / $payload['summary']['expected_cents'] * 100, 1)
            : 0;
        $write(['Collection rate', "{$collectionRate}%"]);
        $write(['Occupied units', "{$payload['summary']['occupied_units']} of {$payload['summary']['total_units']}"]);
        $unitStatus = $payload['occupancy']['unit_status'];
        $vacant = $unitStatus['vacant_listed'] + $unitStatus['vacant_draft'] + $unitStatus['vacant_unlisted'];
        $write(['Vacant units', "{$vacant} (listed: {$unitStatus['vacant_listed']}, draft: {$unitStatus['vacant_draft']}, unlisted: {$unitStatus['vacant_unlisted']})"]);
        $write(['Items needing attention', (string) count($payload['needs_attention'])]);
        $write();

        $write(['FINANCIAL TREND (last 6 months)']);
        $write(['Month', 'Expected', 'Collected']);
        foreach ($payload['financial_trend'] as $t) {
            $write([$t['month'], $cedis($t['expected_cents']), $cedis($t['collected_cents'])]);
        }
        $write();

        $write(['LISTINGS & APPLICATIONS FUNNEL']);
        $write(['Step', 'Count']);
        foreach ($payload['listings']['funnel'] as $f) {
            $write([$f['step'], $f['value']]);
        }
        $write();

        $write(['BALANCE AGING']);
        $write(['Bucket', 'Amount', 'Example']);
        foreach ($payload['payments']['aging'] as $a) {
            $write([$a['bucket'], $cedis($a['amount_cents']), $a['example'] ?? '']);
        }
        $write();

        $write(['OVERDUE TENANTS']);
        $write(['Tenant', 'Property', 'Unit', 'Overdue amount', 'Days overdue']);
        foreach ($payload['payments']['overdue_tenants'] as $o) {
            $write([$o['tenant_name'], $o['property_name'], $o['unit_number'], $cedis($o['overdue_cents']), $o['days_overdue']]);
        }
        $write();

        $write(['MAINTENANCE — BY STATUS']);
        $write(['Status', 'Count']);
        foreach ($payload['maintenance']['by_status'] as $m) {
            $write([$m['status'], $m['count']]);
        }
        $write();

        $write(['MAINTENANCE — BY CATEGORY']);
        $write(['Category', 'Count']);
        foreach ($payload['maintenance']['by_category'] as $m) {
            $write([$m['category'] ?? 'General', $m['count']]);
        }
        $write();

        $write(['PROPERTY PERFORMANCE']);
        $write(['Property', 'Area', 'Units', 'Occupied', 'Occupancy', 'Collected', 'Outstanding', 'Applications', 'Open Maintenance', 'Status']);
        foreach ($payload['properties'] as $p) {
            $write([
                $p['name'],
                $p['area'],
                $p['units'],
                $p['occupied'],
                $p['occupancy_pct'].'%',
                $cedis($p['collected_cents']),
                $cedis($p['outstanding_cents']),
                $p['applications_count'],
                $p['open_maintenance'],
                $p['status'],
            ]);
        }
        $write();

        $write(['Values in this report are generated from Wyncrest system records at the time of export and reflect the selected date range and property filter above.']);

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Build a CSV streamed download with properly escaped fields.
     *
     * @param  list<string>  $header
     * @param  list<array<int, mixed>>  $rows
     */
    private function stream(string $filename, array $header, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $header);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Best-effort display name for a tenant (falls back to email).
     */
    private function tenantName(?\App\Models\User $tenant): ?string
    {
        if ($tenant === null) {
            return null;
        }

        $name = trim("{$tenant->first_name} {$tenant->last_name}");

        return $name !== '' ? $name : $tenant->email;
    }
}
