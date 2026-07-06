/**
 * AdminContractsPage — the admin Lease Contracts command centre.
 *
 * Rendered at /app/contracts when the signed-in user is an admin (see the
 * role branch in pages/shared/ContractsPage.tsx). Every number here is
 * backend-computed (ContractCaseFileService::queue/counts) — no client-side
 * summing, no fabricated "disputed" segment (Wyncrest has no dispute model),
 * no fake filters.
 */
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { adminApi } from '@/lib/endpoints';
import { formatCents, formatDate } from '@/lib/format';
import { NexusCard, StatusCard, SemanticBadge, DataCardGrid } from '@/components/cards';
import type { SemanticRole } from '@/components/cards';
import { Button } from '@/components/ui/Button';
import { ErrorState } from '@/components/ui/states';
import {
  IconDoc,
  IconCheckCircle,
  IconClock,
  IconAlertTriangle,
  IconSearch,
  IconArrowRight,
  IconUsers,
  IconDownload,
} from '@/components/ui/icons';
import type { ContractQueue, ContractSegment, ContractSummary } from '@/lib/types';
import './contract-case-file.css';

/** Exports exactly the contracts currently loaded in the list — real data,
 * never a fabricated sample — as a CSV download. */
function exportContractsCsv(rows: ContractSummary[]): void {
  const header = ['reference', 'property', 'city', 'tenant', 'landlord', 'rent_cents', 'start_date', 'end_date', 'status'];
  const lines = [header, ...rows.map((c) => [
    c.reference,
    c.property_name ?? '',
    c.city ?? '',
    c.tenant_name,
    c.landlord_name,
    String(c.rent_amount),
    c.start_date ?? '',
    c.end_date ?? '',
    c.status_label,
  ])];
  const csv = lines.map((row) => row.map((v) => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\n');
  const blob = new Blob([csv], { type: 'text/csv' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'wyncrest-contracts.csv';
  a.click();
  URL.revokeObjectURL(a.href);
}

type StatusFilter = 'all' | 'active' | 'awaiting' | 'expiring' | 'overdue' | 'ended' | 'draft';
type SortOption = 'ending_soonest' | 'newest' | 'rent' | 'property';

const CHIPS: { key: StatusFilter; label: string }[] = [
  { key: 'all', label: 'All' },
  { key: 'active', label: 'Active' },
  { key: 'awaiting', label: 'Awaiting' },
  { key: 'expiring', label: 'Expiring' },
  { key: 'overdue', label: 'Overdue' },
  { key: 'draft', label: 'Drafts' },
  { key: 'ended', label: 'Ended' },
];

const SEGMENT_ROLE: Record<ContractSegment, SemanticRole> = {
  draft: 'neutral',
  awaiting: 'warning',
  active: 'success',
  expiring: 'warning',
  overdue: 'danger',
  ended: 'neutral',
};

function useDebounced<T>(value: T, delayMs: number): T {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setDebounced(value), delayMs);
    return () => clearTimeout(t);
  }, [value, delayMs]);
  return debounced;
}

function ContractRow({ contract, onOpen }: { contract: ContractSummary; onOpen: () => void }) {
  const role = SEGMENT_ROLE[contract.segment];
  return (
    <button type="button" className="ccf-row" onClick={onOpen}>
      <div className="ccf-row-mono">{(contract.property_name ?? 'WYN').slice(0, 2).toUpperCase()}</div>
      <div className="ccf-row-id">
        <div className="ccf-row-prop">{contract.property_name ?? 'Unassigned property'}</div>
        <div className="ccf-row-parties">
          <b>{contract.tenant_name}</b>
          <span className="ccf-row-arrow">→</span>
          <b>{contract.landlord_name}</b>
          {contract.city && <span>· {contract.city}</span>}
        </div>
      </div>
      <div className="ccf-row-rent">
        {formatCents(contract.rent_amount)}
        <small>/mo</small>
      </div>
      <div className="ccf-row-term">
        <div className="ccf-row-term-top">
          <span>{contract.end_date ? `Ends ${formatDate(contract.end_date)}` : 'Open-ended'}</span>
          <b>{contract.term_progress_percent}%</b>
        </div>
        <div className="ccf-termbar">
          <i
            className={role === 'warning' || role === 'danger' ? 'ccf-termbar-warn' : ''}
            style={{ width: `${contract.term_progress_percent}%` }}
          />
        </div>
      </div>
      {contract.warning_count > 0 && (
        <span className="ccf-row-warn" title={`${contract.warning_count} warning(s)`}>
          <IconAlertTriangle size={14} />
        </span>
      )}
      <SemanticBadge role={role}>{contract.status_label}</SemanticBadge>
      <span className="ccf-row-chev">
        <IconArrowRight size={16} />
      </span>
    </button>
  );
}

export function AdminContractsPage() {
  const navigate = useNavigate();
  const [status, setStatus] = useState<StatusFilter>('all');
  const [query, setQuery] = useState('');
  const [sort, setSort] = useState<SortOption>('ending_soonest');
  const debouncedQuery = useDebounced(query, 250);

  const { data, loading, error, reload } = useApi<ContractQueue>(
    () => adminApi.contractQueue({ status, search: debouncedQuery || undefined, sort }),
    [status, debouncedQuery, sort],
  );

  const counts = data?.counts;
  const rows = data?.data ?? [];

  return (
    <div className="ccf-page animate-rise">
      <header className="ccf-ph">
        <div>
          <span className="ccf-ph-eyebrow">Agreements</span>
          <h1 className="ccf-ph-title">
            Lease <em>contracts.</em>
          </h1>
          <p className="ccf-ph-sub">
            Every lease on Wyncrest, from first signature to final month. Click any contract to
            open its full case file.
          </p>
        </div>
        <button
          type="button"
          className="ccf-ph-export"
          onClick={() => exportContractsCsv(rows)}
          disabled={rows.length === 0}
        >
          <IconDownload size={15} /> Export
        </button>
      </header>

      <DataCardGrid cols={4}>
        <StatusCard
          label="Active leases"
          value={loading ? '—' : (counts?.active ?? 0)}
          sub="currently live"
          icon={<IconCheckCircle size={18} />}
          role={!loading && (counts?.active ?? 0) > 0 ? 'success' : 'neutral'}
          loading={loading}
          onClick={() => setStatus('active')}
        />
        <StatusCard
          label="Awaiting signatures"
          value={loading ? '—' : (counts?.awaiting_signatures ?? 0)}
          sub="sent, not fully signed"
          icon={<IconClock size={18} />}
          role={!loading && (counts?.awaiting_signatures ?? 0) > 0 ? 'warning' : 'neutral'}
          loading={loading}
          onClick={() => setStatus('awaiting')}
        />
        <StatusCard
          label="Expiring soon"
          value={loading ? '—' : (counts?.expiring_soon ?? 0)}
          sub="within 60 days"
          icon={<IconAlertTriangle size={18} />}
          role={!loading && (counts?.expiring_soon ?? 0) > 0 ? 'warning' : 'neutral'}
          loading={loading}
          onClick={() => setStatus('expiring')}
        />
        <StatusCard
          label="Overdue"
          value={loading ? '—' : (counts?.overdue ?? 0)}
          sub="real overdue balance"
          icon={<IconDoc size={18} />}
          role={!loading && (counts?.overdue ?? 0) > 0 ? 'danger' : 'neutral'}
          loading={loading}
          onClick={() => setStatus('overdue')}
        />
      </DataCardGrid>

      <NexusCard role="neutral" className="ccf-panel p-0 overflow-hidden">
        <div className="ccf-panel-head">
          <div>
            <h2 className="font-display text-lg font-semibold text-ink-950">All contracts</h2>
            <p className="ccf-panel-sub">
              {loading ? 'Loading…' : `${counts?.total ?? 0} contract${counts?.total === 1 ? '' : 's'} on the platform`}
            </p>
          </div>
        </div>

        <div className="ccf-toolbar">
          <label className="ccf-search">
            <IconSearch size={16} />
            <input
              type="text"
              placeholder="Search property, tenant, landlord or city…"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              aria-label="Search contracts"
            />
          </label>
          <div className="ccf-chips" role="tablist" aria-label="Contract filters">
            {CHIPS.map((chip) => (
              <button
                key={chip.key}
                type="button"
                role="tab"
                aria-selected={status === chip.key}
                className={`ccf-chip${status === chip.key ? ' on' : ''}`}
                onClick={() => setStatus(chip.key)}
              >
                {chip.label}
              </button>
            ))}
          </div>
          <select
            className="ccf-select"
            value={sort}
            onChange={(e) => setSort(e.target.value as SortOption)}
            aria-label="Sort contracts"
          >
            <option value="ending_soonest">Ending soonest</option>
            <option value="newest">Newest first</option>
            <option value="rent">Highest rent</option>
            <option value="property">Property A–Z</option>
          </select>
        </div>

        {error ? (
          <div className="p-6">
            <ErrorState message={error.message} onRetry={reload} />
          </div>
        ) : loading ? (
          <div className="ccf-skel-list" aria-hidden="true">
            {Array.from({ length: 4 }).map((_, i) => (
              <div className="ccf-skel" key={i} />
            ))}
          </div>
        ) : rows.length === 0 ? (
          <div className="ccf-empty">
            <span className="ccf-empty-ico">
              <IconUsers size={28} />
            </span>
            <p className="ccf-empty-title">
              {(counts?.total ?? 0) === 0 ? 'No contracts yet.' : 'No contracts match.'}
            </p>
            <p className="ccf-empty-text">
              {(counts?.total ?? 0) === 0
                ? 'Contracts will appear here once landlords create rental agreements.'
                : 'Try another search or filter.'}
            </p>
            {status !== 'all' && (
              <Button variant="secondary" size="sm" onClick={() => setStatus('all')}>
                Clear filter
              </Button>
            )}
          </div>
        ) : (
          <div className="ccf-list">
            {rows.map((c) => (
              <ContractRow key={c.id} contract={c} onOpen={() => navigate(`/app/contracts/${c.id}`)} />
            ))}
          </div>
        )}
      </NexusCard>
    </div>
  );
}
