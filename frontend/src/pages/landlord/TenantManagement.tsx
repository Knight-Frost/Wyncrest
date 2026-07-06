/**
 * TenantManagement — Tenant Roster
 *
 * Faithful port of wyncrest-landlord-tenants.html's roster view (`#/`),
 * rebuilt on 100% real data: `landlordApi.contracts()`, `landlordApi.ledger()`
 * and `landlordApi.maintenance()`. No partial-payment status, no scheduled
 * future move-out date, no simulated deposit-hold tracking — those aren't
 * real backend concepts (see project plan). The real "awaiting signature"
 * (pending_tenant contracts) state from the previous build is kept as an
 * additional chip alongside the mockup's own filter set.
 */
import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { landlordApi } from '@/lib/endpoints';
import { ErrorState, LoadingState } from '@/components/ui/states';
import { formatCents, formatDate, daysUntil } from '@/lib/format';
import {
  IconSearch,
  IconBell,
  IconCash,
  IconBack,
  IconUsers,
  IconWrench,
  IconShield,
} from './tenant-management-ui';
import {
  buildRosterRow,
  computeRosterKpis,
  groupLedgerByContract,
  relativeDays,
  avatarStyle,
  initials,
  RENT_LABEL,
  RENT_BADGE,
  RENEWAL_LABEL,
  RENEWAL_BADGE,
  type TenantRosterRow,
} from './tenantHelpers';
import type { Contract } from '@/lib/types';
import './tenant-management.css';

type FilterKey = 'all' | 'awaiting' | 'attention' | 'due_soon' | 'renewal';

function rentLine(row: TenantRosterRow): string {
  const { rent } = row;
  if (rent.status === 'paid') return 'All settled';
  const due = rent.nextPayment?.dueDate ?? null;
  const dayLabel = due ? relativeDays(daysUntil(due)) : '—';
  return `${formatCents(rent.outstandingCents)} · ${dayLabel}`;
}

export function TenantManagement() {
  const navigate = useNavigate();
  const contractsQ = useApi(() => landlordApi.contracts(), []);
  const ledgerQ = useApi(() => landlordApi.ledger(), []);
  const maintenanceQ = useApi(() => landlordApi.maintenance(), []);

  const [filter, setFilter] = useState<FilterKey>('all');
  const [query, setQuery] = useState('');

  const contracts = useMemo(() => contractsQ.data ?? [], [contractsQ.data]);
  const ledger = useMemo(() => ledgerQ.data?.entries ?? [], [ledgerQ.data]);
  const maintenance = useMemo(() => maintenanceQ.data ?? [], [maintenanceQ.data]);

  const ledgerByContract = useMemo(() => groupLedgerByContract(ledger), [ledger]);

  const activeContracts = useMemo(() => contracts.filter((c) => c.status === 'active'), [contracts]);
  const awaitingSignature = useMemo(
    () => contracts.filter((c) => c.status === 'pending_tenant'),
    [contracts],
  );

  const rows = useMemo<TenantRosterRow[]>(
    () => activeContracts.map((c) => buildRosterRow(c, ledgerByContract, maintenance)),
    [activeContracts, ledgerByContract, maintenance],
  );

  const kpis = useMemo(() => computeRosterKpis(rows), [rows]);
  const propertyCount = useMemo(() => new Set(rows.map((r) => r.location.property)).size, [rows]);

  const counts = {
    all: rows.length,
    awaiting: awaitingSignature.length,
    attention: rows.filter((r) => r.rent.status === 'overdue').length,
    due_soon: rows.filter((r) => r.rent.status === 'due_soon').length,
    renewal: rows.filter((r) => r.renewalStatus === 'up_for_renewal' || r.renewalStatus === 'holdover').length,
  };

  const filteredRows = useMemo(() => {
    let list = rows;
    if (filter === 'attention') list = list.filter((r) => r.rent.status === 'overdue');
    else if (filter === 'due_soon') list = list.filter((r) => r.rent.status === 'due_soon');
    else if (filter === 'renewal') {
      list = list.filter((r) => r.renewalStatus === 'up_for_renewal' || r.renewalStatus === 'holdover');
    }

    const q = query.trim().toLowerCase();
    if (q) {
      list = list.filter((r) => {
        const tenantName = r.contract.tenant?.full_name ?? '';
        const hay = [tenantName, r.location.property, r.location.unit, r.location.city]
          .filter(Boolean)
          .join(' ')
          .toLowerCase();
        return hay.includes(q);
      });
    }
    return list;
  }, [rows, filter, query]);

  const isLoading = contractsQ.loading || ledgerQ.loading || maintenanceQ.loading;
  const primaryError = contractsQ.error ?? ledgerQ.error ?? maintenanceQ.error;

  function reload() {
    contractsQ.reload();
    ledgerQ.reload();
    maintenanceQ.reload();
  }

  if (isLoading) {
    return (
      <div className="wtenant">
        <LoadingState label="Loading tenants…" />
      </div>
    );
  }
  if (primaryError) {
    return (
      <div className="wtenant">
        <ErrorState message={primaryError.message} onRetry={reload} />
      </div>
    );
  }

  const chip = (key: FilterKey, label: string, count: number) => (
    <button key={key} className={`chip ${filter === key ? 'on' : ''}`} onClick={() => setFilter(key)}>
      {label}
      <span className="ct">{count}</span>
    </button>
  );

  return (
    <div className="wtenant animate-rise">
      <header className="glass pagehead">
        <div className="eyebrow">Active tenancies</div>
        <h1 className="page">Tenants</h1>
        <p className="lede">
          The people currently living in your properties. Track rent standing, lease health,
          maintenance and renewals, and keep every conversation in one place.
        </p>
      </header>

      <section className="strip">
        <div className="stat glass-2">
          <div className="k">Active tenants</div>
          <div className="v">{kpis.activeCount}</div>
          <div className="n">
            across {propertyCount} {propertyCount === 1 ? 'property' : 'properties'}
          </div>
        </div>
        <div className={`stat glass-2 ${kpis.avgOnTimeRate >= 90 ? 'good' : kpis.avgOnTimeRate >= 75 ? '' : 'warn'}`}>
          <div className="k">On-time rate</div>
          <div className="v">{kpis.avgOnTimeRate}%</div>
          <div className="n">rolling, all tenants</div>
        </div>
        <div className={`stat glass-2 ${kpis.outstandingCents > 0 ? 'bad' : 'good'}`}>
          <div className="k">Outstanding</div>
          <div className="v" style={{ fontSize: 23 }}>
            {formatCents(kpis.outstandingCents)}
          </div>
          <div className="n">
            {kpis.outstandingCents > 0 ? `across ${kpis.outstandingTenantCount} tenants` : 'nothing owed'}
          </div>
        </div>
        <div className={`stat glass-2 ${kpis.endingSoonCount > 0 ? 'warn' : ''}`}>
          <div className="k">Leases ending</div>
          <div className="v">{kpis.endingSoonCount}</div>
          <div className="n">need a renewal call</div>
        </div>
        <div className={`stat glass-2 ${kpis.openMaintenanceTotal > 0 ? 'warn' : 'good'}`}>
          <div className="k">Open maintenance</div>
          <div className="v">{kpis.openMaintenanceTotal}</div>
          <div className="n">{kpis.openMaintenanceTotal === 1 ? '1 request' : `${kpis.openMaintenanceTotal} requests`}</div>
        </div>
      </section>

      <div className="controls">
        <div className="search glass-2">
          <IconSearch />
          <input
            placeholder="Search by name, property or unit"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
        </div>
        <div className="chips">
          {chip('all', 'Everyone', counts.all)}
          {chip('awaiting', 'Awaiting signature', counts.awaiting)}
          {chip('attention', 'Needs attention', counts.attention)}
          {chip('due_soon', 'Due soon', counts.due_soon)}
          {chip('renewal', 'Renewals', counts.renewal)}
        </div>
      </div>

      {filter === 'awaiting' ? (
        awaitingSignature.length === 0 ? (
          <EmptyRoster />
        ) : (
          <div className="roster">
            {awaitingSignature.map((c) => (
              <AwaitingRow key={c.id} contract={c} onOpen={() => navigate(`/app/contracts/${c.id}`)} />
            ))}
          </div>
        )
      ) : filteredRows.length === 0 ? (
        <EmptyRoster />
      ) : (
        <div className="roster">
          {filteredRows.map((row) => (
            <TenantRow key={row.contract.id} row={row} navigate={navigate} />
          ))}
        </div>
      )}

      <div className="foot glass-2">
        <IconShield />
        <div>
          Rent standing, lease health and on-time rates are computed from your real contracts and
          ledger — never fabricated. Partial payments aren't tracked as a separate state, and a
          future-dated move-out or deposit escrow isn't modeled yet.
        </div>
      </div>
    </div>
  );
}

function EmptyRoster() {
  return (
    <div className="empty glass">
      <div className="ei">
        <IconUsers />
      </div>
      <div className="et">No tenants match that view</div>
      <div className="em">Try a different filter or clear your search to see everyone currently in your properties.</div>
    </div>
  );
}

/**
 * Spread onto a clickable card <div> so keyboard users can reach and activate
 * it: button semantics, Tab focus, Enter/Space fire the action. The keydown
 * only reacts on the card itself so nested buttons keep their own behaviour.
 */
function pressable(action: () => void) {
  return {
    role: 'button' as const,
    tabIndex: 0,
    onClick: action,
    onKeyDown: (e: React.KeyboardEvent<HTMLDivElement>) => {
      if (e.target !== e.currentTarget) return;
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        action();
      }
    },
  };
}

function AwaitingRow({ contract, onOpen }: { contract: Contract; onOpen: () => void }) {
  const tenantName = contract.tenant?.full_name ?? 'Tenant unavailable';
  const unit = contract.listing?.unit;
  const property = unit?.property;
  return (
    <div className="trow glass" {...pressable(onOpen)}>
      <div className="tid">
        <div className="tav" style={avatarStyle(tenantName)}>
          {initials(tenantName)}
        </div>
        <div style={{ minWidth: 0 }}>
          <div className="nm">{tenantName}</div>
          <div className="meta">
            {property?.name ?? 'Property unavailable'}
            {unit?.unit_number ? ` · Unit ${unit.unit_number}` : ''}
          </div>
        </div>
      </div>
      <div className="tcell hide-m">
        <div className="lbl">Rent</div>
        <div className="val sm">{formatCents(contract.rent_amount)}/mo</div>
        <div className="sub">
          <span className="badge b-amber">Awaiting signature</span>
        </div>
      </div>
      <div className="tcell hide-m">
        <div className="lbl">Term</div>
        <div className="val sm">
          {formatDate(contract.start_date)} to {formatDate(contract.end_date)}
        </div>
      </div>
      <div className="rowact">
        <span className="iconbtn" title="View contract" style={{ transform: 'rotate(180deg)' }}>
          <IconBack />
        </span>
      </div>
    </div>
  );
}

function TenantRow({
  row,
  navigate,
}: {
  row: TenantRosterRow;
  navigate: ReturnType<typeof useNavigate>;
}) {
  const { contract, rent, renewalStatus, openMaintenance, location } = row;
  const tenantName = contract.tenant?.full_name ?? 'Tenant unavailable';

  return (
    <div className="trow glass" {...pressable(() => navigate(`/app/tenants/${contract.id}`))}>
      <div className="tid">
        <div className="tav" style={avatarStyle(tenantName)}>
          {initials(tenantName)}
        </div>
        <div style={{ minWidth: 0 }}>
          <div className="nm">{tenantName}</div>
          <div className="meta">
            {location.property}
            {location.unit ? ` · Unit ${location.unit}` : ''}
          </div>
        </div>
      </div>
      <div className="tcell hide-m">
        <div className="lbl">Rent</div>
        <div className="val sm">{rentLine(row)}</div>
        <div className="sub">
          <span className={`badge ${RENT_BADGE[rent.status]}`}>
            <span className="dot" />
            {RENT_LABEL[rent.status]}
          </span>
        </div>
      </div>
      <div className="tcell hide-m">
        <div className="lbl">Lease</div>
        <div className="val sm">Ends {formatDate(contract.end_date)}</div>
        <div className="sub">
          <span className={`badge ${RENEWAL_BADGE[renewalStatus]}`}>{RENEWAL_LABEL[renewalStatus]}</span>
          {openMaintenance > 0 && (
            <span className="badge b-amber" style={{ marginLeft: 4 }}>
              <IconWrench />
              {openMaintenance} open
            </span>
          )}
        </div>
      </div>
      <div className="rowact">
        <button
          className="iconbtn"
          title="Send reminder"
          onClick={(e) => {
            e.stopPropagation();
            navigate(`/app/tenants/${contract.id}?tab=messages&reminder=1`);
          }}
        >
          <IconBell />
        </button>
        <button
          className="iconbtn"
          title="Record payment"
          onClick={(e) => {
            e.stopPropagation();
            navigate(`/app/tenants/${contract.id}?tab=rent`);
          }}
        >
          <IconCash />
        </button>
        <span className="iconbtn" title="Open tenant" style={{ transform: 'rotate(180deg)' }}>
          <IconBack />
        </span>
      </div>
    </div>
  );
}
