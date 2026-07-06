/*
 * Landlord Rent Ledger console — faithful rebuild of wyncrest-landlord-ledger.html.
 * Three tabs (Balances · Transactions · Statements) over the real, immutable
 * ledger, plus a scoped CSV export and an offline Record-Payment flow. Every
 * money figure is server-computed (LedgerComputationEngine / LandlordLedgerService).
 *
 * Honesty notes vs. the mockup:
 *  - The mockup's `pending` / `partial` / `failed` payment states and its
 *    arbitrary "Record adjustment" action do not exist in Wyncrest's ledger, so
 *    they are intentionally absent. The real write path is Record payment
 *    (a full-amount offline settlement) — see ledgerShared RecordPaymentModal.
 */
import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { landlordApi } from '@/lib/endpoints';
import type { LedgerEntry, LedgerBalanceRow } from '@/lib/types';
import { formatCents } from '@/lib/format';
import { useToast } from '@/components/ui/toast';
import { LoadingState, ErrorState } from '@/components/ui/states';
import {
  I,
  ENTRY,
  STATUS,
  cedis0,
  fmtDShort,
  initials,
  avStyle,
  amtToneClass,
  contractStatusMeta,
  isOpenObligation,
} from './ledgerShared';
import { Badge, RecordPaymentModal } from './ledgerComponents';
import './ledger.css';

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

type Tab = 'balances' | 'transactions' | 'statements';

interface Filters {
  q: string;
  property: string;
  type: string;
  status: string;
}

interface ExportState {
  scope: 'all' | 'property' | 'contract';
  target: string;
  range: 'this' | 'last' | 'all';
  reason: string;
}

/** Translate an export range into created_at date bounds. */
function rangeBounds(range: ExportState['range']): { date_from?: string; date_to?: string } {
  const now = new Date();
  if (range === 'this') {
    return {
      date_from: new Date(now.getFullYear(), now.getMonth(), 1).toISOString(),
      date_to: new Date(now.getFullYear(), now.getMonth() + 1, 0, 23, 59, 59).toISOString(),
    };
  }
  if (range === 'last') {
    return {
      date_from: new Date(now.getFullYear(), now.getMonth() - 1, 1).toISOString(),
      date_to: new Date(now.getFullYear(), now.getMonth(), 0, 23, 59, 59).toISOString(),
    };
  }
  return {};
}

export function LandlordLedger() {
  const { toast } = useToast();
  const navigate = useNavigate();
  const { data, loading, error, reload } = useApi(() => landlordApi.ledger(), []);

  const [tab, setTab] = useState<Tab>('balances');
  const [filters, setFilters] = useState<Filters>({ q: '', property: 'all', type: 'all', status: 'all' });
  const [expOpen, setExpOpen] = useState(false);
  const [exp, setExp] = useState<ExportState>({ scope: 'all', target: '', range: 'this', reason: '' });
  const [exporting, setExporting] = useState(false);
  const [payFor, setPayFor] = useState<{ obligations: LedgerEntry[]; tenantName?: string | null } | null>(null);

  const entries = useMemo(() => data?.entries ?? [], [data]);
  const balances = useMemo(() => data?.balances ?? [], [data]);
  const summary = data?.summary;

  /* Distinct properties (for filters + export target) */
  const properties = useMemo(() => {
    const seen = new Map<string, string>();
    for (const b of balances) if (b.property) seen.set(String(b.property.id), b.property.name);
    return [...seen.entries()].map(([id, name]) => ({ id, name }));
  }, [balances]);

  function setFilter(patch: Partial<Filters>) {
    setFilters((f) => ({ ...f, ...patch }));
  }

  /* Open obligations grouped by contract — powers the "Record payment" action */
  function openObligationsForContract(contractId: string): LedgerEntry[] {
    return entries.filter((e) => e.contract_id === contractId && isOpenObligation(e));
  }

  async function runExport() {
    setExporting(true);
    try {
      const params: Record<string, string> = { ...rangeBounds(exp.range) } as Record<string, string>;
      if (exp.scope === 'property' && exp.target) params.property_id = exp.target;
      if (exp.scope === 'contract' && exp.target) params.contract_id = exp.target;
      if (exp.reason.trim()) params.reason = exp.reason.trim();
      if ((exp.scope === 'property' || exp.scope === 'contract') && !exp.target) {
        toast(`Pick a ${exp.scope} to export`, 'error');
        setExporting(false);
        return;
      }
      await landlordApi.exportLedger(params);
      toast('Ledger exported', 'success');
    } catch {
      toast('Export failed', 'error');
    } finally {
      setExporting(false);
    }
  }

  if (loading) {
    return (
      <div className="wled">
        <LoadingState label="Loading ledger…" />
      </div>
    );
  }
  if (error || !summary) {
    return (
      <div className="wled">
        <ErrorState message={error?.message ?? 'Could not load the ledger'} onRetry={reload} />
      </div>
    );
  }

  const card = (cls: string, k: string, v: string, n: string) => (
    <div className={`card glass-2 ${cls}`}>
      <span className="edge" />
      <div className="k">{k}</div>
      <div className="v">{v}</div>
      <div className="n">{n}</div>
    </div>
  );

  const od = summary.tenants_overdue;

  return (
    <div className="wled">
      <div className="app" style={{ maxWidth: 'none', padding: 0 }}>
        {/* ---- page head ---- */}
        <div className="pagehead glass">
          <header>
            <div className="eyebrow">Operations</div>
            <h1 className="page">Rent ledger</h1>
            <p className="lede">
              Track every rent charge, payment, fee, and balance by tenant, property, unit, and contract. Each entry
              traces back to who was charged, what for, when, and how it was paid.
            </p>
          </header>
          <div className="acts">
            <button className="btn btn-g" onClick={() => setTab('statements')}>
              {I.download} Statements
            </button>
            <button className="btn btn-p" onClick={() => setExpOpen((o) => !o)}>
              {I.export} Export
            </button>
          </div>
        </div>

        {/* ---- summary cards ---- */}
        <section className="cards">
          {card(summary.outstanding_cents > 0 ? 'bad' : 'good', 'Total outstanding', cedis0(summary.outstanding_cents), 'across active contracts')}
          {card(summary.overdue_cents > 0 ? 'bad' : 'good', 'Overdue balance', cedis0(summary.overdue_cents), 'past the due date')}
          {card('good', `Collected · ${summary.month_label.split(' ')[0]}`, cedis0(summary.collected_month_cents), 'payments received')}
          {card('info', `Charged · ${summary.month_label.split(' ')[0]}`, cedis0(summary.charged_month_cents), 'rent and fees posted')}
          {card(od > 0 ? 'warn' : 'good', 'Tenants overdue', String(od), od === 1 ? 'needs follow-up' : 'need follow-up')}
        </section>

        {/* ---- tabs ---- */}
        <div className="tabs glass-2">
          <button className={tab === 'balances' ? 'on' : ''} onClick={() => setTab('balances')}>
            {I.scale}Balances
          </button>
          <button className={tab === 'transactions' ? 'on' : ''} onClick={() => setTab('transactions')}>
            {I.doc}Transactions <span className="n">{entries.length}</span>
          </button>
          <button className={tab === 'statements' ? 'on' : ''} onClick={() => setTab('statements')}>
            {I.doc2}Statements
          </button>
        </div>

        {/* ---- export panel ---- */}
        <div className={`exp glass ${expOpen ? 'open' : ''}`}>
          <div className="exp-inner">
            <div className="ph" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <div>
                <h3>Export ledger</h3>
                <p style={{ fontSize: '12.5px', color: 'var(--slate)', marginTop: 2 }}>
                  Generate a CSV records file with references, balances, and signed amounts. Every export is written to
                  the audit log with the reason you give.
                </p>
              </div>
              <button className="iconbtn" aria-label="Close" onClick={() => setExpOpen(false)}>
                {I.x}
              </button>
            </div>
            <div className="exp-grid">
              <div className="field">
                <label>What to export</label>
                <select value={exp.scope} onChange={(e) => setExp((s) => ({ ...s, scope: e.target.value as ExportState['scope'], target: '' }))}>
                  <option value="all">Full ledger — every entry</option>
                  <option value="property">One property</option>
                  <option value="contract">One contract</option>
                </select>
                {exp.scope === 'property' && (
                  <div className="field" style={{ marginTop: 12 }}>
                    <label>Property</label>
                    <select value={exp.target} onChange={(e) => setExp((s) => ({ ...s, target: e.target.value }))}>
                      <option value="">Select a property…</option>
                      {properties.map((p) => (
                        <option key={p.id} value={p.id}>
                          {p.name}
                        </option>
                      ))}
                    </select>
                  </div>
                )}
                {exp.scope === 'contract' && (
                  <div className="field" style={{ marginTop: 12 }}>
                    <label>Contract</label>
                    <select value={exp.target} onChange={(e) => setExp((s) => ({ ...s, target: e.target.value }))}>
                      <option value="">Select a contract…</option>
                      {balances.map((b) => (
                        <option key={b.contract_id} value={b.contract_id}>
                          {b.tenant?.full_name ?? '—'} · {b.property?.name} {b.unit_number}
                        </option>
                      ))}
                    </select>
                  </div>
                )}
              </div>
              <div>
                <div className="field">
                  <label>Date range</label>
                  <select value={exp.range} onChange={(e) => setExp((s) => ({ ...s, range: e.target.value as ExportState['range'] }))}>
                    <option value="this">This month ({summary.month_label})</option>
                    <option value="last">Last month</option>
                    <option value="all">All time</option>
                  </select>
                </div>
                <div className="field">
                  <label>Reason for export (recorded)</label>
                  <input value={exp.reason} onChange={(e) => setExp((s) => ({ ...s, reason: e.target.value }))} placeholder="e.g. Monthly accounting, dispute record, audit" />
                </div>
              </div>
            </div>
            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 16 }}>
              <button className="btn btn-p" onClick={runExport} disabled={exporting}>
                {I.export} {exporting ? 'Generating…' : 'Generate CSV'}
              </button>
            </div>
          </div>
        </div>

        {/* ---- filters ---- */}
        <div className="filters glass-2">
          <div className="fsearch">
            <span className="fi">{I.search}</span>
            <input
              value={filters.q}
              onChange={(e) => setFilter({ q: e.target.value })}
              placeholder={tab === 'transactions' ? 'Search tenant, property, unit, contract, or reference…' : 'Search tenant, property, unit, or contract…'}
              autoComplete="off"
            />
          </div>
          <Sel value={filters.property} onChange={(v) => setFilter({ property: v })} options={[['all', 'All properties'], ...properties.map((p) => [p.id, p.name] as [string, string])]} />
          {tab === 'transactions' && (
            <>
              <Sel
                value={filters.type}
                onChange={(v) => setFilter({ type: v })}
                options={[
                  ['all', 'All types'],
                  ['rent', 'Rent charge'],
                  ['payment', 'Payment'],
                  ['late_fee', 'Late fee'],
                ]}
              />
              <Sel
                value={filters.status}
                onChange={(v) => setFilter({ status: v })}
                options={[
                  ['all', 'All statuses'],
                  ['paid', 'Paid'],
                  ['pending', 'Pending'],
                  ['overdue', 'Overdue'],
                  ['waived', 'Waived'],
                ]}
              />
            </>
          )}
          {tab === 'balances' && (
            <Sel
              value={filters.status}
              onChange={(v) => setFilter({ status: v })}
              options={[
                ['all', 'All statuses'],
                ['paid', 'Current'],
                ['open', 'Due soon'],
                ['overdue', 'Overdue'],
              ]}
            />
          )}
        </div>

        {/* ---- content ---- */}
        {tab === 'balances' && (
          <BalancesView
            balances={balances}
            filters={filters}
            onStatement={(cid) => navigate(`/app/ledger/statement/${cid}`)}
            onRecord={(row) => {
              const obligations = openObligationsForContract(row.contract_id);
              if (!obligations.length) {
                toast('No open charge to record a payment against', 'info');
                return;
              }
              setPayFor({ obligations, tenantName: row.tenant?.full_name });
            }}
            openCount={openObligationsForContract}
          />
        )}
        {tab === 'transactions' && <TransactionsView entries={entries} filters={filters} onOpen={(id) => navigate(`/app/ledger/tx/${id}`)} />}
        {tab === 'statements' && (
          <StatementsView
            balances={balances}
            filters={filters}
            onContract={(cid) => navigate(`/app/ledger/statement/${cid}`)}
            onProperty={(pid) => navigate(`/app/ledger/property/${pid}`)}
          />
        )}

        {/* ---- footer note ---- */}
        <div className="foot glass-2">
          {I.shield}
          <div>
            The ledger is append-only and immutable: corrections are added as new compensating entries, never edits to
            the past. Running balances, references, and totals are derived server-side by the ledger computation engine —
            the same figures the tenant and admin see.
          </div>
        </div>
      </div>

      {payFor && (
        <RecordPaymentModal
          obligations={payFor.obligations}
          tenantName={payFor.tenantName}
          onClose={() => setPayFor(null)}
          onDone={() => {
            setPayFor(null);
            reload();
          }}
        />
      )}
    </div>
  );
}

/* ---------- select control ------------------------------------------------ */
function Sel({ value, onChange, options }: { value: string; onChange: (v: string) => void; options: [string, string][] }) {
  return (
    <div className="sel">
      <select value={value} onChange={(e) => onChange(e.target.value)}>
        {options.map(([v, l]) => (
          <option key={v} value={v}>
            {l}
          </option>
        ))}
      </select>
      <span className="cv">{I.down}</span>
    </div>
  );
}

function Empty({ title, message }: { title: string; message: string }) {
  return (
    <div className="empty glass">
      <div className="ei">{I.search}</div>
      <div className="et">{title}</div>
      <div className="em">{message}</div>
    </div>
  );
}

/* ---------- BALANCES ------------------------------------------------------ */
function BalancesView({
  balances,
  filters,
  onStatement,
  onRecord,
  openCount,
}: {
  balances: LedgerBalanceRow[];
  filters: Filters;
  onStatement: (cid: string) => void;
  onRecord: (row: LedgerBalanceRow) => void;
  openCount: (cid: string) => LedgerEntry[];
}) {
  const rows = useMemo(() => {
    const q = filters.q.trim().toLowerCase();
    return balances.filter((b) => {
      if (filters.property !== 'all' && String(b.property?.id) !== filters.property) return false;
      if (filters.status !== 'all' && b.status !== filters.status) return false;
      if (q) {
        const hay = [b.tenant?.full_name, b.property?.name, b.unit_number, b.contract_id, b.tenant?.email].filter(Boolean).join(' ').toLowerCase();
        if (!hay.includes(q)) return false;
      }
      return true;
    });
  }, [balances, filters]);

  if (!rows.length) return <Empty title="No matching balances" message="Try a different property, status, or clear your search." />;

  return (
    <div className="blist">
      {rows.map((b) => {
        const owed = b.balance_cents > 0;
        const st = contractStatusMeta(b.status);
        const name = b.tenant?.full_name ?? 'Unknown tenant';
        const canRecord = openCount(b.contract_id).length > 0;
        return (
          <div className="brow glass" key={b.contract_id} {...pressable(() => onStatement(b.contract_id))}>
            <div className="bt">
              <div className="bav" style={avStyle(name)}>
                {initials(name)}
              </div>
              <div style={{ minWidth: 0 }}>
                <div className="nm">{name}</div>
                <div className="meta">
                  {b.property?.name} · Unit {b.unit_number}
                </div>
              </div>
            </div>
            <div className="bcell hm">
              <div className="lbl">Contract</div>
              <div className="val" style={{ fontSize: '12.5px' }}>
                {b.rent_cents ? `${formatCents(b.rent_cents)}/mo` : '—'}
              </div>
              <div className="sub">
                {fmtDShort(b.start_date)} – {fmtDShort(b.end_date)}
              </div>
            </div>
            <div className="bcell">
              <div className="lbl">Balance</div>
              <div className={`val big ${owed ? 'owed' : ''}`}>{owed ? formatCents(b.balance_cents) : 'GH₵ 0'}</div>
              <div className="sub" style={b.status === 'overdue' ? { color: 'var(--oxblood)' } : undefined}>
                {b.status === 'overdue' ? 'overdue' : owed ? 'outstanding' : 'settled'}
              </div>
            </div>
            <div className="bcell hm">
              <div className="lbl">{owed ? 'Due' : 'Next due'}</div>
              <div className="val" style={{ fontSize: '13px' }}>
                {fmtDShort(b.next_due)}
              </div>
              <div className="sub">last paid {fmtDShort(b.last_payment_at)}</div>
            </div>
            <div className="bact" onClick={(e) => e.stopPropagation()}>
              <Badge badge={st.badge}>
                <span className="dot" />
                {st.label}
              </Badge>
              <div style={{ display: 'flex', gap: 6 }}>
                {canRecord && (
                  <button className="btn btn-g sm" onClick={() => onRecord(b)}>
                    {I.cash} Record
                  </button>
                )}
                <button className="btn btn-g sm" onClick={() => onStatement(b.contract_id)}>
                  {I.doc2} Statement
                </button>
              </div>
            </div>
          </div>
        );
      })}
    </div>
  );
}

/* ---------- TRANSACTIONS -------------------------------------------------- */
function TransactionsView({ entries, filters, onOpen }: { entries: LedgerEntry[]; filters: Filters; onOpen: (id: string) => void }) {
  const rows = useMemo(() => {
    const q = filters.q.trim().toLowerCase();
    return entries.filter((e) => {
      if (filters.property !== 'all' && String(e.contract?.listing?.unit?.property?.id) !== filters.property) return false;
      if (filters.type !== 'all' && e.type !== filters.type) return false;
      if (filters.status !== 'all' && e.status !== filters.status) return false;
      if (q) {
        const unit = e.contract?.listing?.unit;
        const hay = [e.reference, e.display_label, e.tenant?.full_name, unit?.property?.name, unit ? `unit ${unit.unit_number}` : null]
          .filter(Boolean)
          .join(' ')
          .toLowerCase();
        if (!hay.includes(q)) return false;
      }
      return true;
    });
  }, [entries, filters]);

  if (!rows.length) return <Empty title="No transactions in this view" message="Clear a filter or widen your search to see more entries." />;

  return (
    <>
      <div className="filtered-note">
        {I.info}
        <div>
          <b>{rows.length}</b> {rows.length === 1 ? 'entry' : 'entries'}. Click any row for full details, running balance,
          and audit trail.
        </div>
      </div>
      <div className="glass" style={{ padding: '6px 8px' }}>
        <div className="tblwrap">
          <table className="tbl">
            <thead>
              <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Tenant</th>
                <th>Property / Unit</th>
                <th className="r">Amount</th>
                <th className="r">Balance after</th>
                <th>Status</th>
                <th className="r">Reference</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((e) => {
                const m = ENTRY[e.type];
                const st = STATUS[e.status] ?? { badge: 'b-gray', label: e.status };
                const unit = e.contract?.listing?.unit;
                return (
                  <tr key={e.id} onClick={() => onOpen(e.id)}>
                    <td className="tdate">
                      {fmtDShort(e.due_date ?? e.occurred_at)}
                      <div className="tsmall">{new Date(e.occurred_at).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}</div>
                    </td>
                    <td>
                      <span className="ttype">
                        <span className="ti" style={m.tint}>
                          {m.icon}
                        </span>
                        {m.label}
                      </span>
                    </td>
                    <td>
                      <div className="tten">{e.tenant?.full_name ?? '—'}</div>
                    </td>
                    <td>
                      {unit?.property?.name ?? '—'}
                      <div className="tsmall">Unit {unit?.unit_number}</div>
                    </td>
                    <td className="r">
                      <span className={`amt ${amtToneClass(e)}`}>
                        {e.direction === 'payment' ? '− ' : ''}
                        {formatCents(e.display_amount_cents)}
                      </span>
                    </td>
                    <td className="r">
                      <span className="bal">{e.running_balance_cents != null ? formatCents(e.running_balance_cents) : '—'}</span>
                    </td>
                    <td>
                      <Badge badge={st.badge}>{st.label}</Badge>
                    </td>
                    <td className="r">
                      <span className="ref">{e.reference}</span> <span className="chev">{I.chev}</span>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>
    </>
  );
}

/* ---------- STATEMENTS ---------------------------------------------------- */
function StatementsView({
  balances,
  filters,
  onContract,
  onProperty,
}: {
  balances: LedgerBalanceRow[];
  filters: Filters;
  onContract: (cid: string) => void;
  onProperty: (pid: number) => void;
}) {
  const q = filters.q.trim().toLowerCase();
  const list = balances.filter((b) => {
    if (filters.property !== 'all' && String(b.property?.id) !== filters.property) return false;
    if (q) {
      const hay = [b.tenant?.full_name, b.property?.name, b.unit_number, b.contract_id].filter(Boolean).join(' ').toLowerCase();
      if (!hay.includes(q)) return false;
    }
    return true;
  });

  const propsMap = new Map<number, { name: string; count: number; outstanding: number }>();
  for (const b of list) {
    if (!b.property) continue;
    const cur = propsMap.get(b.property.id) ?? { name: b.property.name, count: 0, outstanding: 0 };
    cur.count += 1;
    cur.outstanding += Math.max(0, b.balance_cents);
    propsMap.set(b.property.id, cur);
  }

  if (!list.length) return <Empty title="No statements match" message="Clear your search or pick a different property." />;

  return (
    <>
      <div className="panel glass" style={{ marginBottom: 16 }}>
        <div className="ph">
          <h3>Property statements</h3>
          <p>Money by property, broken down by unit</p>
        </div>
        <div className="stmt-cards">
          {[...propsMap.entries()].map(([id, p]) => (
            <div className="scard glass" key={id} {...pressable(() => onProperty(id))}>
              <div className="st">
                <div className="sav" style={avStyle(p.name)}>
                  {I.building}
                </div>
                <div>
                  <div className="snm">{p.name}</div>
                  <div className="sm">
                    {p.count} unit{p.count > 1 ? 's' : ''} · property statement
                  </div>
                </div>
              </div>
              <div className="srow">
                <span className="k">Active contracts</span>
                <span className="v">{p.count}</span>
              </div>
              <div className="srow">
                <span className="k">Outstanding</span>
                <span className="v" style={p.outstanding > 0 ? { color: 'var(--oxblood)' } : undefined}>
                  {formatCents(p.outstanding)}
                </span>
              </div>
              <div className="srow">
                <span className="k">Statement</span>
                <span className="v" style={{ color: 'var(--petrol-2)' }}>
                  View →
                </span>
              </div>
            </div>
          ))}
        </div>
      </div>

      <div className="panel glass">
        <div className="ph">
          <h3>Tenant statements</h3>
          <p>One clean financial record per contract, ready to download</p>
        </div>
        <div className="stmt-cards">
          {list.map((b) => {
            const st = contractStatusMeta(b.status);
            const name = b.tenant?.full_name ?? 'Unknown tenant';
            return (
              <div className="scard glass" key={b.contract_id} {...pressable(() => onContract(b.contract_id))}>
                <div className="st">
                  <div className="sav" style={avStyle(name)}>
                    {initials(name)}
                  </div>
                  <div>
                    <div className="snm">{name}</div>
                    <div className="sm">
                      {b.property?.name} · Unit {b.unit_number}
                    </div>
                  </div>
                </div>
                <div className="srow">
                  <span className="k">Balance</span>
                  <span className="v" style={b.balance_cents > 0 ? { color: 'var(--oxblood)' } : undefined}>
                    {formatCents(b.balance_cents)}
                  </span>
                </div>
                <div className="srow">
                  <span className="k">Status</span>
                  <span className="v">
                    <Badge badge={st.badge}>{st.label}</Badge>
                  </span>
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </>
  );
}
