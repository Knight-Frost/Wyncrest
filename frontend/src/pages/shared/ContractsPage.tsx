/**
 * Contracts (Lease & Rent) — Wyncrest lease agreements.
 *
 * Editorial layout: SectionHeader + four DERIVED stat cards (StatusCard) +
 * a filterable panel that shows either the contract list or a rich empty
 * state. Data is LIVE and role-aware (tenant / landlord / admin) via the API.
 *
 * Role capabilities:
 *  - Landlord: can create contracts (the header/empty-state CTA routes to the
 *    dedicated full page at /app/contracts/new — never a drawer).
 *  - Admin: supervisory — view/filter every contract, NO create affordance.
 *  - Tenant: view-only — their leases appear here once a landlord activates them.
 *
 * The four headline figures are derived from the loaded contracts, never typed.
 * Contract status badges use SemanticBadge + getContractVariant.
 */
import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router';
import { useAuth } from '@/context/auth';
import { useApi } from '@/hooks/useApi';
import { AdminContractsPage } from '@/pages/admin/AdminContractsPage';
import { LeaseAndRentPage } from '@/pages/tenant/LeaseAndRentPage';
import { landlordApi } from '@/lib/endpoints';
import { formatCents, formatDate, humanize } from '@/lib/format';
import {
  StatusCard,
  SemanticBadge,
  DataCardGrid,
  getContractVariant,
} from '@/components/cards';
import {
  IconDoc,
  IconClock,
  IconCheckCircle,
  IconCalendar,
  IconUsers,
  IconPlus,
  IconBell,
  IconSearch,
  IconShield,
  IconArrowRight,
} from '@/components/ui/icons';
import type { Contract, ContractStatus } from '@/lib/types';
import './contracts.css';

/* ── tabs: All + the four lifecycle states the design surfaces ────────────── */
type FilterTab = 'all' | ContractStatus;
const TABS: { key: FilterTab; label: string }[] = [
  { key: 'all', label: 'All' },
  { key: 'pending_tenant', label: 'Pending review' },
  { key: 'active', label: 'Active' },
  { key: 'expired', label: 'Expired' },
  { key: 'terminated', label: 'Cancelled' },
];

/** Friendly status label (overrides the raw enum where the design renames it). */
function statusLabel(status: ContractStatus): string {
  if (status === 'pending_tenant') return 'Pending review';
  if (status === 'terminated') return 'Cancelled';
  return humanize(status);
}

/* ── a small warm-paper town, drawn inline (no asset dependency) ──────────── */
function TownArt() {
  const wall = 'var(--color-ink-200)';
  const wall2 = 'var(--color-ink-300)';
  const roof = 'var(--color-ink-300)';
  const tree = 'color-mix(in srgb, var(--color-success-600) 35%, var(--color-ink-200))';
  return (
    <svg className="ct-empty-art" viewBox="0 0 280 96" fill="none" aria-hidden="true">
      {/* ground line */}
      <line x1="8" y1="88" x2="272" y2="88" stroke="var(--color-ink-200)" strokeWidth="1.5" />
      {/* trees */}
      <circle cx="40" cy="70" r="11" fill={tree} />
      <rect x="38.5" y="78" width="3" height="10" fill={wall2} />
      <circle cx="244" cy="68" r="12" fill={tree} />
      <rect x="242.5" y="77" width="3" height="11" fill={wall2} />
      {/* buildings */}
      <rect x="64" y="44" width="34" height="44" rx="2" fill={wall} />
      <rect x="70" y="52" width="6" height="6" fill="var(--color-surface)" />
      <rect x="86" y="52" width="6" height="6" fill="var(--color-surface)" />
      <rect x="70" y="64" width="6" height="6" fill="var(--color-surface)" />
      <rect x="86" y="64" width="6" height="6" fill="var(--color-surface)" />
      {/* house with gable */}
      <path d="M104 88V58l20-14 20 14v30z" fill={wall2} />
      <path d="M101 60l23-16 23 16" stroke={roof} strokeWidth="3" strokeLinejoin="round" fill="none" />
      <rect x="118" y="70" width="12" height="18" fill="var(--color-surface)" />
      {/* tall block */}
      <rect x="152" y="34" width="30" height="54" rx="2" fill={wall} />
      <rect x="158" y="42" width="5" height="6" fill="var(--color-surface)" />
      <rect x="171" y="42" width="5" height="6" fill="var(--color-surface)" />
      <rect x="158" y="54" width="5" height="6" fill="var(--color-surface)" />
      <rect x="171" y="54" width="5" height="6" fill="var(--color-surface)" />
      <rect x="158" y="66" width="5" height="6" fill="var(--color-surface)" />
      <rect x="171" y="66" width="5" height="6" fill="var(--color-surface)" />
      {/* small house */}
      <path d="M188 88V62l16-11 16 11v26z" fill={wall2} />
      <path d="M186 64l18-13 18 13" stroke={roof} strokeWidth="3" strokeLinejoin="round" fill="none" />
      <rect x="198" y="72" width="12" height="16" fill="var(--color-surface)" />
    </svg>
  );
}

/* ================================================================== page ==== */

export function ContractsPage() {
  const { user } = useAuth();
  const role = user?.role;
  const navigate = useNavigate();

  /**
   * Only landlords may create contracts. Admins are supervisory (view/filter),
   * tenants are view-only. This gates the UI half; the API 403s any non-landlord
   * create attempt regardless.
   */
  const canCreateContract = role === 'landlord';

  const [tab, setTab] = useState<FilterTab>('all');
  const [query, setQuery] = useState('');
  const [notice, setNotice] = useState<string | null>(null);

  // Tenants and admins render their own dedicated pages below (each with its
  // own fetch) — this hook is a no-op for them so every render still calls
  // the same hooks in the same order.
  const { data, loading, error, reload } = useApi<Contract[]>(async () => {
    if (role === 'landlord') return landlordApi.contracts();
    return [];
  }, [role]);

  const contracts = useMemo(() => data ?? [], [data]);

  /* DERIVED headline figures — never hardcoded. */
  const stats = useMemo(() => ({
    total: contracts.length,
    pending: contracts.filter((c) => c.status === 'pending_tenant').length,
    active: contracts.filter((c) => c.status === 'active').length,
    expired: contracts.filter((c) => c.status === 'expired').length,
  }), [contracts]);

  const tabCounts = useMemo(() => {
    const counts: Record<FilterTab, number> = { all: contracts.length, draft: 0, pending_tenant: 0, active: 0, terminated: 0, expired: 0 };
    for (const c of contracts) counts[c.status] += 1;
    return counts;
  }, [contracts]);

  const visible = useMemo(() => {
    const q = query.trim().toLowerCase();
    return contracts.filter((c) => {
      if (tab !== 'all' && c.status !== tab) return false;
      if (q === '') return true;
      const parts = [
        c.listing?.title,
        c.listing?.unit?.property?.name,
        c.landlord && `${c.landlord.first_name} ${c.landlord.last_name}`,
        c.tenant && `${c.tenant.first_name} ${c.tenant.last_name}`,
      ];
      return parts.some((p) => p && p.toLowerCase().includes(q));
    });
  }, [contracts, tab, query]);

  const description =
    role === 'landlord' ? 'Lease contracts you have drafted or sent to tenants.'
    : role === 'admin' ? 'All lease contracts across the platform.'
    : 'Your lease agreements and their current status.';
  const eyebrow = role === 'admin' ? 'Administration' : role === 'landlord' ? 'Operations' : 'My Rental';

  /* Role-aware empty state copy — tenants/admins get NO create CTA. */
  const emptyState =
    role === 'admin'
      ? { title: 'No contracts yet.', text: 'Contracts will appear here when landlords create rental agreements.' }
      : role === 'landlord'
        ? { title: 'No contracts yet.', text: 'Create a contract when a tenant is ready to rent one of your units.' }
        : { title: 'No active contract yet.', text: 'Your lease will appear here once a landlord creates and activates it.' };

  // Tenants get the dedicated "Lease & Rent" experience (lease + payment
  // posture merged into one view) — its own fetch of contracts/ledger.
  // Admins get the dedicated case-file command centre (rich rows, truthful
  // segment counts, search/filter/sort) — everyone else keeps this page.
  // Checked after every hook above runs unconditionally on every render.
  if (role === 'tenant') return <LeaseAndRentPage />;
  if (role === 'admin') return <AdminContractsPage />;

  return (
    <div className="ct-page">
      {/* ── page header ── */}
      <header className="ct-head">
        <div className="ct-head-title">
          <p className="ct-eyebrow">{eyebrow}</p>
          <h1 className="ct-title">Contracts</h1>
          <p className="ct-sub">{description}</p>
        </div>
        {canCreateContract && (
          <button className="ct-btn ct-btn-primary" onClick={() => navigate('/app/contracts/new')}>
            <IconPlus size={17} /> New contract
          </button>
        )}
      </header>

      {/* ── derived stat cards (StatusCard grid) ── */}
      <section aria-label="Contract summary">
        <DataCardGrid cols={4}>
          <StatusCard
            label="Total contracts"
            value={loading ? '—' : stats.total}
            sub="All time"
            icon={<IconDoc size={18} />}
            role="neutral"
            loading={loading}
          />
          <StatusCard
            label="Pending review"
            value={loading ? '—' : stats.pending}
            sub="Awaiting acceptance"
            icon={<IconClock size={18} />}
            role={!loading && stats.pending > 0 ? 'warning' : 'neutral'}
            loading={loading}
          />
          <StatusCard
            label="Active"
            value={loading ? '—' : stats.active}
            sub="Currently in effect"
            icon={<IconCheckCircle size={18} />}
            role={!loading && stats.active > 0 ? 'success' : 'neutral'}
            loading={loading}
          />
          <StatusCard
            label="Expired"
            value={loading ? '—' : stats.expired}
            sub="Past end date"
            icon={<IconCalendar size={18} />}
            role="neutral"
            loading={loading}
          />
        </DataCardGrid>
      </section>

      {/* ── main panel ── */}
      <section className="ct-panel">
        <div className="ct-toolbar">
          <div className="ct-tabs" role="tablist" aria-label="Contract filters">
            {TABS.map((t) => (
              <button key={t.key} role="tab" aria-selected={tab === t.key} className={`ct-tab${tab === t.key ? ' active' : ''}`} onClick={() => setTab(t.key)}>
                {t.label}
                {tabCounts[t.key] > 0 && <span className="ct-tab-count">{tabCounts[t.key]}</span>}
              </button>
            ))}
          </div>
          <div className="ct-tools">
            <div className="ct-search">
              <IconSearch size={16} />
              <input type="text" placeholder="Search contracts…" value={query} onChange={(e) => setQuery(e.target.value)} aria-label="Search contracts" />
            </div>
          </div>
        </div>

        {loading ? (
          <div className="ct-skel-list" aria-hidden="true">{Array.from({ length: 4 }).map((_, i) => <div className="ct-skel" key={i} />)}</div>
        ) : error ? (
          <div className="ct-mini-empty">
            <span className="ct-mini-ico"><IconDoc size={24} /></span>
            <p className="ct-mini-title">We couldn't load your contracts</p>
            <p className="ct-mini-text">{error.message}</p>
            <button className="ct-btn ct-btn-ghost" onClick={reload} style={{ marginTop: 14 }}>Try again</button>
          </div>
        ) : contracts.length === 0 ? (
          <div className="ct-empty">
            <span className="ct-empty-ico"><IconDoc size={34} /></span>
            <TownArt />
            <p className="ct-empty-title">{emptyState.title}</p>
            <p className="ct-empty-text">{emptyState.text}</p>
            {canCreateContract && (
              <button className="ct-btn ct-btn-primary" onClick={() => navigate('/app/contracts/new')}>
                <IconPlus size={16} /> Create new contract
              </button>
            )}
          </div>
        ) : visible.length === 0 ? (
          <div className="ct-mini-empty">
            <span className="ct-mini-ico"><IconDoc size={24} /></span>
            <p className="ct-mini-title">No matching contracts</p>
            <p className="ct-mini-text">{query.trim() ? 'No contracts match your search.' : 'No contracts in this category.'}</p>
          </div>
        ) : (
          <div className="ct-list">
            {visible.map((c) => {
              const place = c.listing?.unit?.property?.name;
              // Only landlords reach this list render (tenants/admins render their own
              // dedicated pages above), so the counterparty is always the tenant.
              const counterparty = c.tenant ? `${c.tenant.first_name} ${c.tenant.last_name}` : `Tenant #${c.tenant_id}`;
              return (
                <button type="button" className="ct-card" key={c.id} onClick={() => navigate(`/app/contracts/${c.id}`)}>
                  <div className="ct-card-top">
                    <div style={{ minWidth: 0 }}>
                      <div className="ct-card-title">{c.listing?.title ?? `Contract ${c.id.slice(0, 8)}…`}</div>
                      {place && <div className="ct-card-place">{place}</div>}
                    </div>
                    {/* SemanticBadge replaces the raw .ct-status span */}
                    <SemanticBadge role={getContractVariant(c.status)}>
                      {statusLabel(c.status)}
                    </SemanticBadge>
                  </div>
                  <div className="ct-card-grid">
                    <div className="ct-meta"><IconUsers size={14} /><span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{counterparty}</span></div>
                    <div className="ct-meta rent">{formatCents(c.rent_amount)}/mo</div>
                    <div className="ct-meta" style={{ gridColumn: '1 / -1' }}><IconCalendar size={14} /><span>{formatDate(c.start_date)} to {formatDate(c.end_date)}</span></div>
                  </div>
                  <div className="ct-card-foot">View contract <IconArrowRight size={13} style={{ display: 'inline', verticalAlign: 'middle' }} /></div>
                </button>
              );
            })}
          </div>
        )}
      </section>

      {/* ── reassurance footer ── */}
      <div className="ct-secure">
        <span className="ct-secure-ico"><IconShield size={20} /></span>
        <div className="ct-secure-body">
          <div className="ct-secure-title">Your security is our priority</div>
          <div className="ct-secure-text">All contracts are securely stored and only you and the landlord can access them.</div>
        </div>
        <button className="ct-btn ct-btn-ghost" onClick={() => setNotice('Contracts are encrypted at rest and scoped to the parties on the agreement.')}>Learn more</button>
      </div>

      {notice && (
        <div role="alert" className="ct-toast">
          <IconBell size={14} style={{ display: 'inline', verticalAlign: 'middle', marginRight: 6 }} />
          {notice}
        </div>
      )}
    </div>
  );
}
