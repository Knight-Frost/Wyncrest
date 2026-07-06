/*
 * Landlord ledger transaction "case file" — /app/ledger/tx/:entryId.
 * Full trace for one immutable entry: money header, tenant→property→unit→
 * contract→period→entry chain, transaction details, linked entries, people &
 * context, and the real append-only audit trail. Open obligations expose the
 * offline Record-Payment action. Faithful to wyncrest-landlord-ledger.html's
 * #/tx/:ref view; nothing fabricated — every field is server-derived.
 */
import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { landlordApi } from '@/lib/endpoints';
import type { LedgerEntry, LedgerAuditEvent } from '@/lib/types';
import { formatCents } from '@/lib/format';
import { LoadingState, ErrorState } from '@/components/ui/states';
import { I, ENTRY, STATUS, fmtDShort, fmtDT, initials, avStyle, isOpenObligation } from './ledgerShared';
import { Badge, RecordPaymentModal } from './ledgerComponents';
import './ledger.css';

function periodLabel(entry: LedgerEntry): string {
  const iso = entry.billing_period_start ?? entry.due_date;
  if (!iso) return '—';
  return new Date(iso).toLocaleDateString('en-GB', { month: 'short', year: 'numeric' });
}

function severityClass(sev: string | null): string {
  if (sev === 'warning') return 'warn';
  if (sev === 'critical' || sev === 'error') return 'bad';
  return 'done';
}

export function LedgerTransaction() {
  const { entryId = '' } = useParams();
  const navigate = useNavigate();
  const { data: entry, loading, error, reload } = useApi(() => landlordApi.ledgerEntry(entryId), [entryId]);
  const [paying, setPaying] = useState(false);

  const back = (
    <Link className="back" to="/app/ledger">
      {I.back} Rent ledger
    </Link>
  );

  if (loading) {
    return (
      <div className="wled">
        {back}
        <LoadingState label="Loading entry…" />
      </div>
    );
  }
  if (error || !entry) {
    return (
      <div className="wled">
        {back}
        <ErrorState message={error?.message ?? 'Entry not found'} onRetry={reload} />
      </div>
    );
  }

  const m = ENTRY[entry.type];
  const st = STATUS[entry.status] ?? { badge: 'b-gray', label: entry.status };
  const isCredit = entry.direction === 'payment';
  const contract = entry.contract;
  const unit = contract?.listing?.unit;
  const property = unit?.property;
  const tenant = entry.tenant;
  const tenantName = tenant?.full_name ?? 'Unknown tenant';
  const canRecord = isOpenObligation(entry);

  const chain: [string, string][] = [
    ['Tenant', tenantName],
    ['Property', property?.name ?? '—'],
    ['Unit', unit?.unit_number ? `Unit ${unit.unit_number}` : '—'],
    ['Contract', entry.contract_id.slice(0, 8)],
    ['Period', periodLabel(entry)],
    ['Entry', entry.reference ?? '—'],
  ];

  return (
    <div className="wled">
      {back}

      <div className="cfhead glass">
        <div className="cfic" style={m.tint}>
          {m.icon}
        </div>
        <div className="cfmain">
          <div className={`cfamt ${isCredit ? 'cr' : ''}`}>
            {isCredit ? '− ' : ''}
            {formatCents(entry.display_amount_cents)}
          </div>
          <div className="cfsub">
            {m.label} · {periodLabel(entry)} · {property?.name} {unit?.unit_number ? `Unit ${unit.unit_number}` : ''}
          </div>
          <div className="cftags">
            <Badge badge={st.badge}>
              <span className="dot" />
              {st.label}
            </Badge>
            <Badge badge="b-blue">{entry.reference}</Badge>
            <Badge badge="b-green">{I.shield} Immutable entry</Badge>
          </div>
        </div>
        <div className="cfside">
          {canRecord && (
            <button className="btn btn-p sm" onClick={() => setPaying(true)}>
              {I.cash} Record payment
            </button>
          )}
          <button className="btn btn-g sm" onClick={() => navigate(`/app/ledger/statement/${entry.contract_id}`)}>
            {I.doc2} Statement
          </button>
        </div>
      </div>

      <div className="chain">
        {chain.map(([label, value], i) => (
          <span key={label} style={{ display: 'contents' }}>
            <span className="node">
              <span className="cl">{label}</span>
              {value}
            </span>
            {i < chain.length - 1 && <span className="arw">{I.arw}</span>}
          </span>
        ))}
      </div>

      <div className="grid2">
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          <div className="panel glass">
            <div className="ph">
              <h3>Transaction details</h3>
            </div>
            <Kv k="Type" v={m.label} />
            <Kv
              k="Amount"
              v={
                <span style={{ color: isCredit ? 'var(--green)' : 'var(--ink)' }}>
                  {isCredit ? '− ' : ''}
                  {formatCents(entry.display_amount_cents)}
                </span>
              }
            />
            <Kv k="Balance impact" v={formatCents(entry.balance_impact_cents)} />
            <Kv k="Billing period" v={periodLabel(entry)} />
            <Kv k="Status" v={<Badge badge={st.badge}>{st.label}</Badge>} />
            <Kv k="Balance after" v={entry.running_balance_cents != null ? <b>{formatCents(entry.running_balance_cents)}</b> : '—'} />
            <Kv k="Recorded" v={fmtDT(entry.occurred_at)} />
            <Kv k="Method" v={entry.payment_method ? humanizeMethod(entry.payment_method) : '—'} />
            {entry.payment_reference && <Kv k="Payment reference" v={<span style={{ fontFamily: 'var(--disp)', letterSpacing: '.03em' }}>{entry.payment_reference}</span>} />}
            <Kv k="Ledger reference" v={entry.reference ?? '—'} />
          </div>

          <div className="panel glass">
            <div className="ph">
              <h3>Linked entries</h3>
              <p>Entries that reference — or are referenced by — this one</p>
            </div>
            {entry.linked_entries.length ? (
              <div className="rel">
                {entry.linked_entries.map((x) => (
                  <LinkedRow key={x.id} entry={x} onOpen={() => navigate(`/app/ledger/tx/${x.id}`)} />
                ))}
              </div>
            ) : (
              <p style={{ fontSize: '13px', color: 'var(--slate)' }}>No linked entries.</p>
            )}
          </div>
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          <div className="panel glass">
            <div className="ph">
              <h3>People &amp; context</h3>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 6 }}>
              <div className="bav" style={{ ...avStyle(tenantName), width: 40, height: 40, borderRadius: 11, fontSize: 14 }}>
                {initials(tenantName)}
              </div>
              <div>
                <div style={{ fontWeight: 600 }}>{tenantName}</div>
                <div style={{ fontSize: 12, color: 'var(--slate)' }}>{tenant?.email}</div>
              </div>
            </div>
            <Kv k="Property" v={property?.name ?? '—'} />
            <Kv k="Unit" v={unit?.unit_number ? `Unit ${unit.unit_number}` : '—'} />
            {property?.city && <Kv k="Area" v={property.city} />}
            {contract && (
              <>
                <Kv k="Contract term" v={`${fmtDShort(contract.start_date)} – ${fmtDShort(contract.end_date)}`} />
                <Kv k="Monthly rent" v={formatCents(Number(contract.rent_amount))} />
              </>
            )}
            <div style={{ marginTop: 12 }}>
              <button className="btn btn-g" style={{ width: '100%', justifyContent: 'center' }} onClick={() => navigate(`/app/contracts/${entry.contract_id}`)}>
                {I.doc} View contract
              </button>
            </div>
          </div>

          <div className="panel glass">
            <div className="ph">
              <h3>Audit trail</h3>
            </div>
            {entry.audit_trail.length ? (
              <div className="tl">
                {entry.audit_trail.map((ev: LedgerAuditEvent) => (
                  <div className={`ev ${severityClass(ev.severity)}`} key={ev.id}>
                    <div className="et">{humanizeAction(ev.action)}</div>
                    <div className="em">{ev.description}</div>
                    <div className="ed">
                      {fmtDT(ev.created_at)} · {ev.actor}
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <p style={{ fontSize: '13px', color: 'var(--slate)' }}>No audit events recorded for this entry yet.</p>
            )}
          </div>
        </div>
      </div>

      {paying && (
        <RecordPaymentModal
          obligations={[entry]}
          tenantName={tenantName}
          onClose={() => setPaying(false)}
          onDone={() => {
            setPaying(false);
            reload();
          }}
        />
      )}
    </div>
  );
}

function Kv({ k, v }: { k: string; v: React.ReactNode }) {
  return (
    <div className="kv-row">
      <span className="k">{k}</span>
      <span className="v">{v === null || v === undefined || v === '' ? '—' : v}</span>
    </div>
  );
}

function LinkedRow({ entry, onOpen }: { entry: LedgerEntry; onOpen: () => void }) {
  const xm = ENTRY[entry.type];
  return (
    <div
      className="relrow"
      role="button"
      tabIndex={0}
      onClick={onOpen}
      onKeyDown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          onOpen();
        }
      }}
    >
      <div className="rt" style={xm.tint}>
        {xm.icon}
      </div>
      <div>
        <div className="rd1">{xm.label}</div>
        <div className="rd2">
          {entry.reference} · {fmtDShort(entry.due_date ?? entry.occurred_at)} · {(STATUS[entry.status] ?? { label: entry.status }).label}
        </div>
      </div>
      <div className="ra" style={{ color: entry.direction === 'payment' ? 'var(--green)' : entry.type === 'late_fee' ? 'var(--amber)' : 'var(--ink)' }}>
        {entry.direction === 'payment' ? '− ' : ''}
        {formatCents(entry.display_amount_cents)}
      </div>
      <div className="chev">{I.chev}</div>
    </div>
  );
}

function humanizeMethod(m: string): string {
  return (
    {
      mobile_money_mtn: 'Mobile money · MTN',
      mobile_money_vodafone: 'Mobile money · Vodafone',
      bank_transfer: 'Bank transfer',
      cash: 'Cash',
    } as Record<string, string>
  )[m] ?? m;
}

function humanizeAction(a: string): string {
  return a
    .split('_')
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join(' ');
}
