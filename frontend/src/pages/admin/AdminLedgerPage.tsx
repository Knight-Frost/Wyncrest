/**
 * AdminLedgerPage — the platform-wide Ledger command centre for admins.
 *
 * Rendered at /app/ledger for admins (see the role branch in
 * pages/shared/LedgerPage.tsx's LedgerRouter usage in App.tsx). Every figure
 * comes from LedgerComputationEngine via AdminLedgerController — this page
 * never sums amount_cents itself. The reconciliation banner and "needs
 * attention" list are LedgerReconciliationService's real integrity checks,
 * not a fabricated dispute/risk feed — Wyncrest's ledger schema has no
 * dispute, payout, or chargeback concept, so none is shown here.
 */
import { useState } from 'react';
import { useNavigate } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { adminApi } from '@/lib/endpoints';
import { formatCents } from '@/lib/format';
import { NexusCard, StatusCard, SemanticBadge, DataCardGrid, getLedgerVariant } from '@/components/cards';
import { Button } from '@/components/ui/Button';
import { ErrorState } from '@/components/ui/states';
import {
  IconWallet,
  IconAlertTriangle,
  IconCheckCircle,
  IconClock,
  IconSearch,
  IconArrowRight,
  IconDownload,
  IconShield,
  IconRefresh,
} from '@/components/ui/icons';
import { help } from '@/lib/helpText';
import { InfoHint } from '@/components/ui/InfoHint';
import type { LedgerEntry, LedgerQueryParams, LedgerType, LedgerStatus } from '@/lib/types';
import './ledger-case-file.css';

type TabKey = 'all' | 'charges' | 'payments' | 'refunds' | 'overdue';

const TABS: { key: TabKey; label: string }[] = [
  { key: 'all', label: 'All entries' },
  { key: 'charges', label: 'Charges' },
  { key: 'payments', label: 'Payments' },
  { key: 'refunds', label: 'Refunds' },
  { key: 'overdue', label: 'Overdue' },
];

const TYPE_LABEL: Record<LedgerType, string> = {
  rent: 'Rent charge',
  late_fee: 'Late fee',
  payment: 'Payment',
  refund: 'Refund',
};

const TYPE_DOT: Record<LedgerType, string> = {
  rent: 'var(--color-brand-600)',
  late_fee: 'var(--color-warning-500)',
  payment: 'var(--color-success-500)',
  refund: 'var(--color-info-500)',
};

function tabToParams(tab: TabKey): Partial<LedgerQueryParams> {
  switch (tab) {
    case 'charges':
      return { charges_only: true };
    case 'payments':
      return { type: 'payment' as LedgerType };
    case 'refunds':
      return { type: 'refund' as LedgerType };
    case 'overdue':
      return { overdue_only: true };
    default:
      return {};
  }
}

function EntryRow({ entry, onOpen }: { entry: LedgerEntry; onOpen: () => void }) {
  const property = entry.contract?.listing?.unit?.property;
  const unit = entry.contract?.listing?.unit;
  const partyName = entry.tenant?.full_name;
  const impactClass =
    entry.direction === 'payment' ? 'adl-impact-payment' : entry.direction === 'refund' ? 'adl-impact-refund' : 'adl-impact-charge';
  const impactSign = entry.balance_impact_cents > 0 ? '+' : entry.balance_impact_cents < 0 ? '−' : '';

  return (
    <button type="button" className="adl-row" onClick={onOpen}>
      <span className="adl-row-mono" style={{ background: TYPE_DOT[entry.type] }}>
        {entry.type.slice(0, 2).toUpperCase()}
      </span>
      <div>
        <div className="adl-row-prop">{TYPE_LABEL[entry.type]}</div>
        <div className="adl-row-parties">
          <b>{partyName ?? 'Unknown tenant'}</b>
          {property && (
            <>
              <span className="adl-row-arrow">·</span>
              <span>
                {property.name}
                {unit ? ` / ${unit.internal_name ?? unit.unit_number}` : ''}
              </span>
            </>
          )}
        </div>
      </div>
      <div className="adl-row-rent">
        {formatCents(entry.display_amount_cents)}
        <br />
        <small className={impactClass}>
          {impactSign}
          {formatCents(Math.abs(entry.balance_impact_cents))}
        </small>
      </div>
      <SemanticBadge role={getLedgerVariant(entry.status)} status={entry.status} />
      <span className="adl-row-chev">
        <IconArrowRight size={16} />
      </span>
    </button>
  );
}

export function AdminLedgerPage() {
  const navigate = useNavigate();
  const [tab, setTab] = useState<TabKey>('all');
  const [query, setQuery] = useState('');
  const [status, setStatus] = useState<LedgerStatus | ''>('');
  const [page, setPage] = useState(1);

  const params: LedgerQueryParams = {
    page,
    search: query || undefined,
    status: status || undefined,
    ...tabToParams(tab),
  };

  const { data, loading, error, reload } = useApi(
    () => adminApi.ledger(params),
    [tab, query, status, page],
  );

  const { data: reconciliation, reload: reloadReconciliation } = useApi(
    () => adminApi.ledgerReconciliation(),
    [],
  );

  const summary = data?.summary;
  const rows = data?.data ?? [];

  return (
    <div className="adl-page animate-rise">
      <header className="adl-ph">
        <div>
          <span className="adl-ph-eyebrow">Finance · Audit</span>
          <h1 className="adl-ph-title">
            Ledger<em>.</em>
          </h1>
          <p className="adl-ph-sub">
            Platform-wide financial record for every rent charge, late fee, payment, and refund.
            Every entry is immutable and traceable to a tenant, contract, and property.
          </p>
        </div>
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: '6px' }}>
          <button
            type="button"
            className="adl-ph-export"
            onClick={() => adminApi.exportLedger(params)}
            disabled={rows.length === 0}
          >
            <IconDownload size={15} /> Export
          </button>
          <InfoHint text={help.exportMatchesPage} label="About the export" />
        </span>
      </header>

      <DataCardGrid cols={4}>
        <StatusCard
          label={<>Collected <InfoHint text={help.collected} label="About collected" /></>}
          value={loading ? '—' : formatCents(summary?.collected_cents ?? 0)}
          sub="successful payments"
          icon={<IconWallet size={18} />}
          role="success"
          loading={loading}
        />
        <StatusCard
          label={<>Outstanding <InfoHint text={help.outstandingBalance} label="About outstanding" /></>}
          value={loading ? '—' : formatCents(summary?.outstanding_cents ?? 0)}
          sub="unpaid across all contracts"
          icon={<IconClock size={18} />}
          role={!loading && (summary?.outstanding_cents ?? 0) > 0 ? 'warning' : 'neutral'}
          loading={loading}
          onClick={() => setTab('charges')}
        />
        <StatusCard
          label={<>Overdue <InfoHint text={help.overdue} label="About overdue" /></>}
          value={loading ? '—' : formatCents(summary?.overdue_cents ?? 0)}
          sub="past due date"
          icon={<IconAlertTriangle size={18} />}
          role={!loading && (summary?.overdue_cents ?? 0) > 0 ? 'danger' : 'neutral'}
          loading={loading}
          onClick={() => setTab('overdue')}
        />
        <StatusCard
          label="Entries"
          value={loading ? '—' : (summary?.entry_count ?? 0)}
          sub="in the current filter"
          icon={<IconCheckCircle size={18} />}
          role="neutral"
          loading={loading}
        />
      </DataCardGrid>

      {reconciliation && (
        <NexusCard role="neutral" className="p-4">
          <div className={`adl-banner ${reconciliation.status}`}>
            <span className="adl-integrity-ic">
              {reconciliation.status === 'pass' ? <IconShield size={20} /> : <IconAlertTriangle size={20} />}
            </span>
            <div style={{ flex: 1 }}>
              <div className="adl-integrity-title">
                {reconciliation.status === 'pass'
                  ? 'Ledger integrity: passed'
                  : `Ledger integrity: ${reconciliation.issues.length} issue(s) found`}
              </div>
              <div className="adl-integrity-sub">
                Independent checks over sign rules, duplicate charges, orphaned entries, and
                aggregate consistency.
              </div>
            </div>
            <Button variant="ghost" size="sm" onClick={() => reloadReconciliation()}>
              <IconRefresh size={14} /> Re-check
            </Button>
          </div>
          {reconciliation.issues.length > 0 && (
            <div style={{ marginTop: '0.9rem' }}>
              {reconciliation.issues.map((issue) => (
                <div key={issue.code} className={`adl-issue-row ${issue.severity}`}>
                  <span className="adl-issue-ic">
                    <IconAlertTriangle size={14} />
                  </span>
                  <div className="adl-issue-body">
                    <div className="adl-issue-title">{issue.message}</div>
                    <div className="adl-issue-sub">
                      {issue.entry_ids.length > 0
                        ? `${issue.entry_ids.length} entry(ies) affected`
                        : issue.contract_ids.length > 0
                        ? `${issue.contract_ids.length} contract(s) affected`
                        : null}
                    </div>
                  </div>
                  {issue.entry_ids[0] && (
                    <Button variant="secondary" size="sm" onClick={() => navigate(`/app/ledger/${issue.entry_ids[0]}`)}>
                      View
                    </Button>
                  )}
                </div>
              ))}
            </div>
          )}
        </NexusCard>
      )}

      <NexusCard role="neutral" className="adl-panel p-0 overflow-hidden">
        <div className="adl-panel-head">
          <div>
            <h2 className="font-display text-lg font-semibold text-ink-950">Entries</h2>
            <p className="adl-panel-sub">
              {loading ? 'Loading…' : `${data?.total ?? 0} entr${data?.total === 1 ? 'y' : 'ies'} on the platform`}
            </p>
          </div>
        </div>

        <div className="adl-toolbar">
          <label className="adl-search">
            <IconSearch size={16} />
            <input
              type="text"
              placeholder="Search tenant, landlord, or property…"
              value={query}
              onChange={(e) => {
                setPage(1);
                setQuery(e.target.value);
              }}
              aria-label="Search ledger"
            />
          </label>
          <div className="adl-chips" role="tablist" aria-label="Ledger filters">
            {TABS.map((t) => (
              <button
                key={t.key}
                type="button"
                role="tab"
                aria-selected={tab === t.key}
                className={`adl-chip${tab === t.key ? ' on' : ''}`}
                onClick={() => {
                  setPage(1);
                  setTab(t.key);
                }}
              >
                {t.label}
              </button>
            ))}
          </div>
          <select
            className="adl-select"
            value={status}
            onChange={(e) => {
              setPage(1);
              setStatus(e.target.value as LedgerStatus | '');
            }}
            aria-label="Filter by status"
          >
            <option value="">All statuses</option>
            <option value="pending">Pending</option>
            <option value="overdue">Overdue</option>
            <option value="paid">Paid</option>
            <option value="waived">Waived</option>
          </select>
        </div>

        {error ? (
          <div className="p-6">
            <ErrorState message={error.message} onRetry={reload} />
          </div>
        ) : loading ? (
          <div className="adl-skel-list" aria-hidden="true">
            {Array.from({ length: 5 }).map((_, i) => (
              <div className="adl-skel" key={i} />
            ))}
          </div>
        ) : rows.length === 0 ? (
          <div className="adl-empty">
            <span className="adl-empty-ico">
              <IconWallet size={28} />
            </span>
            <p className="adl-empty-title">No entries match.</p>
            <p className="adl-empty-text">Try another search or filter.</p>
          </div>
        ) : (
          <>
            <div className="adl-list">
              {rows.map((entry) => (
                <EntryRow key={entry.id} entry={entry} onOpen={() => navigate(`/app/ledger/${entry.id}`)} />
              ))}
            </div>
            {(data?.last_page ?? 1) > 1 && (
              <div className="adl-panel-head" style={{ justifyContent: 'center', gap: '1rem', paddingBottom: '1.1rem' }}>
                <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                  Previous
                </Button>
                <span className="adl-panel-sub" style={{ margin: 0 }}>
                  Page {data?.current_page} of {data?.last_page}
                </span>
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={page >= (data?.last_page ?? 1)}
                  onClick={() => setPage((p) => p + 1)}
                >
                  Next
                </Button>
              </div>
            )}
          </>
        )}
      </NexusCard>
    </div>
  );
}
