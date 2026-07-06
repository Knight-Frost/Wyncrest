/**
 * Lease & Rent (tenant) — list view.
 *
 * The tenant-only replacement for the generic shared contracts list, rendered
 * from `ContractsPage` when `role === 'tenant'` (same dispatch pattern as the
 * existing admin branch there). Folds lease status AND rent posture into one
 * "Lease & Rent" surface, matching the nav label
 * (`frontend/src/routes/nav.tsx`).
 *
 * Every headline figure is derived from real `tenantApi.contracts()` /
 * `tenantApi.ledger()` data — nothing here is a fixed mock value.
 */
import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { tenantApi } from '@/lib/endpoints';
import { formatCents, formatDate, daysUntil, humanize } from '@/lib/format';
import {
  NexusCard,
  StatusCard,
  SemanticBadge,
  DataCardGrid,
  getContractVariant,
  type SemanticRole,
} from '@/components/cards';
import { ErrorState } from '@/components/ui/states';
import {
  IconScale,
  IconWallet,
  IconCalendar,
  IconAlertTriangle,
  IconCheckCircle,
  IconSearch,
  IconUsers,
  IconDoc,
} from '@/components/ui/icons';
import type { Contract, ContractStatus } from '@/lib/types';
import '../shared/contracts.css';
import './lease.css';

const ENDING_SOON_DAYS = 60;

function isEndingSoon(contract: Contract): boolean {
  if (contract.status !== 'active') return false;
  const days = daysUntil(contract.end_date);
  return days !== null && days > 0 && days <= ENDING_SOON_DAYS;
}

/** Friendly status label — matches the wording already used on the shared ContractsPage. */
function statusLabel(status: ContractStatus): string {
  if (status === 'pending_tenant') return 'Pending review';
  if (status === 'terminated') return 'Cancelled';
  return humanize(status);
}

function cardFootHint(contract: Contract): string {
  if (contract.status === 'pending_tenant') return 'Review & sign →';
  if (contract.status === 'active') return 'View lease & payments →';
  return 'View details →';
}

type FilterTab = 'all' | 'active' | 'pending' | 'past';
const TABS: { key: FilterTab; label: string }[] = [
  { key: 'all', label: 'All' },
  { key: 'active', label: 'Active' },
  { key: 'pending', label: 'Pending review' },
  { key: 'past', label: 'Past' },
];

function inTab(contract: Contract, tab: FilterTab): boolean {
  if (tab === 'all') return true;
  if (tab === 'active') return contract.status === 'active';
  if (tab === 'pending') return contract.status === 'pending_tenant' || contract.status === 'draft';
  return contract.status === 'terminated' || contract.status === 'expired';
}

export function LeaseAndRentPage() {
  const contractsQ = useApi(() => tenantApi.contracts(), []);
  const ledgerQ = useApi(() => tenantApi.ledger(), []);

  const [tab, setTab] = useState<FilterTab>('all');
  const [query, setQuery] = useState('');

  const contracts = useMemo(() => contractsQ.data ?? [], [contractsQ.data]);
  const ledgerEntries = useMemo(() => ledgerQ.data?.entries ?? [], [ledgerQ.data]);

  const activeContract = contracts.find((c) => c.status === 'active') ?? null;
  const pendingContract = contracts.find((c) => c.status === 'pending_tenant') ?? null;
  const activeCount = contracts.filter((c) => c.status === 'active').length;

  /* "Next important date" — a real scheduled date, never invented. */
  const nextDate = useMemo(() => {
    if (pendingContract) {
      return { value: formatDate(pendingContract.start_date), sub: 'Lease starts once you sign' };
    }
    if (activeContract) {
      if (isEndingSoon(activeContract)) {
        return { value: formatDate(activeContract.end_date), sub: 'Your lease ends' };
      }
      const nextDue = ledgerEntries
        .filter(
          (e) =>
            e.contract_id === activeContract.id &&
            (e.status === 'pending' || e.status === 'overdue') &&
            e.due_date,
        )
        .sort((a, b) => new Date(a.due_date!).getTime() - new Date(b.due_date!).getTime())[0];
      if (nextDue) return { value: formatDate(nextDue.due_date), sub: 'Next rent payment' };
    }
    return { value: '—', sub: 'Nothing scheduled' };
  }, [pendingContract, activeContract, ledgerEntries]);

  /* "Action needed" — derived, never a fixed banner. */
  const action = useMemo((): { value: string; sub: string; role: SemanticRole } => {
    if (pendingContract) {
      return { value: 'Signature required', sub: 'Review and sign your lease', role: 'warning' };
    }
    if (activeContract && isEndingSoon(activeContract)) {
      return { value: 'Lease ending soon', sub: 'Renew or plan your move', role: 'warning' };
    }
    return { value: 'None right now', sub: 'You are all caught up', role: 'success' };
  }, [pendingContract, activeContract]);

  const visible = useMemo(() => {
    const q = query.trim().toLowerCase();
    return contracts.filter((c) => {
      if (!inTab(c, tab)) return false;
      if (q === '') return true;
      const parts = [
        c.listing?.unit?.property?.name,
        c.listing?.unit?.property?.city,
        c.landlord?.full_name,
      ];
      return parts.some((p) => p && p.toLowerCase().includes(q));
    });
  }, [contracts, tab, query]);

  const tabCounts = useMemo(() => {
    const counts: Record<FilterTab, number> = { all: contracts.length, active: 0, pending: 0, past: 0 };
    for (const key of ['active', 'pending', 'past'] as const) {
      counts[key] = contracts.filter((c) => inTab(c, key)).length;
    }
    return counts;
  }, [contracts]);

  const loading = contractsQ.loading || ledgerQ.loading;
  const error = contractsQ.error ?? ledgerQ.error;
  const navigate = useNavigate();

  return (
    <div className="lr-page animate-rise">
      <NexusCard as="header" specular className="lr-intro">
        <span className="eyebrow">Your leases</span>
        <h1 className="lr-intro-title">
          My rental <em>contracts.</em>
        </h1>
        <p className="lr-intro-sub">
          View your lease agreements, track their status, and see what needs your attention. Open
          any lease for the full details, payment history, and documents.
        </p>
      </NexusCard>

      {loading ? (
        <DataCardGrid cols={4}>
          {[0, 1, 2, 3].map((i) => (
            <StatusCard key={i} label="—" value="—" loading />
          ))}
        </DataCardGrid>
      ) : (
        <DataCardGrid cols={4}>
          <StatusCard
            label="Active lease"
            value={activeCount}
            sub={activeCount > 0 ? 'Current agreement' : 'No active lease right now'}
            icon={<IconScale size={18} />}
            role={activeCount > 0 ? 'success' : 'neutral'}
          />
          <StatusCard
            label="Monthly rent"
            value={activeContract ? formatCents(activeContract.rent_amount) : '—'}
            sub={activeContract ? `Due day ${activeContract.payment_day} of each cycle` : 'No active lease'}
            icon={<IconWallet size={18} />}
            role="neutral"
          />
          <StatusCard
            label="Next important date"
            value={nextDate.value}
            sub={nextDate.sub}
            icon={<IconCalendar size={18} />}
            role="neutral"
          />
          <StatusCard
            label="Action needed"
            value={action.value}
            sub={action.sub}
            icon={action.role === 'success' ? <IconCheckCircle size={18} /> : <IconAlertTriangle size={18} />}
            role={action.role}
          />
        </DataCardGrid>
      )}

      <section className="ct-panel">
        <div className="ct-toolbar">
          <div className="ct-tabs" role="tablist" aria-label="Lease filters">
            {TABS.map((t) => (
              <button
                key={t.key}
                role="tab"
                aria-selected={tab === t.key}
                className={`ct-tab${tab === t.key ? ' active' : ''}`}
                onClick={() => setTab(t.key)}
              >
                {t.label}
                {tabCounts[t.key] > 0 && <span className="ct-tab-count">{tabCounts[t.key]}</span>}
              </button>
            ))}
          </div>
          <div className="ct-tools">
            <div className="ct-search">
              <IconSearch size={16} />
              <input
                type="text"
                placeholder="Search by property or landlord…"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                aria-label="Search leases"
              />
            </div>
          </div>
        </div>

        {loading ? (
          <div className="ct-skel-list" aria-hidden="true">
            {Array.from({ length: 3 }).map((_, i) => (
              <div className="ct-skel" key={i} />
            ))}
          </div>
        ) : error ? (
          <ErrorState message={error.message} onRetry={() => { contractsQ.reload(); ledgerQ.reload(); }} />
        ) : contracts.length === 0 ? (
          <div className="ct-empty">
            <span className="ct-empty-ico">
              <IconScale size={34} />
            </span>
            <p className="ct-empty-title">No rental contracts yet</p>
            <p className="ct-empty-text">
              When a landlord sends you a lease agreement, it will appear here to review, sign, and
              track.
            </p>
          </div>
        ) : visible.length === 0 ? (
          <div className="ct-mini-empty">
            <span className="ct-mini-ico">
              <IconDoc size={24} />
            </span>
            <p className="ct-mini-title">No matching leases</p>
            <p className="ct-mini-text">
              {query.trim() ? 'No leases match your search.' : 'No leases in this category.'}
            </p>
          </div>
        ) : (
          <div className="ct-list">
            {visible.map((c) => {
              const property = c.listing?.unit?.property;
              const unit = c.listing?.unit;
              const home = property
                ? `${property.name}${unit?.unit_number ? `, Unit ${unit.unit_number}` : ''}`
                : (c.listing?.title ?? `Contract ${c.id.slice(0, 8)}…`);
              const address = property ? `${property.city}, ${property.state}` : null;
              const landlordName = c.landlord?.full_name ?? `Landlord #${c.landlord_id}`;

              return (
                <button
                  type="button"
                  className="ct-card"
                  key={c.id}
                  onClick={() => navigate(`/app/contracts/${c.id}`)}
                >
                  <div className="ct-card-top">
                    <div style={{ minWidth: 0 }}>
                      <div className="ct-card-title">{home}</div>
                      {address && <div className="ct-card-place">{address}</div>}
                    </div>
                    <SemanticBadge role={getContractVariant(c.status)}>
                      {statusLabel(c.status)}
                    </SemanticBadge>
                  </div>
                  <div className="ct-card-grid">
                    <div className="ct-meta">
                      <IconUsers size={14} />
                      <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                        {landlordName}
                      </span>
                    </div>
                    <div className="ct-meta rent">{formatCents(c.rent_amount)}/mo</div>
                    <div className="ct-meta" style={{ gridColumn: '1 / -1' }}>
                      <IconCalendar size={14} />
                      <span>
                        {formatDate(c.start_date)} to {formatDate(c.end_date)} · due day {c.payment_day}
                      </span>
                    </div>
                  </div>
                  <div className="ct-card-foot">{cardFootHint(c)}</div>
                </button>
              );
            })}
          </div>
        )}
      </section>
    </div>
  );
}
