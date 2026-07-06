/*
 * Tenant / contract statement — /app/ledger/statement/:contractId.
 * A clean, downloadable financial record for one contract for one billing
 * month: opening → charges/fees/payments → ending, plus the entries that
 * produced it. Month is navigable. Faithful to the mockup's #/stmt view, with
 * the app's real data; the only real action is CSV export (audit-logged
 * server-side) — PDF/email aren't backend features so they're not shown.
 */
import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { landlordApi } from '@/lib/endpoints';
import type { LedgerEntry } from '@/lib/types';
import { formatCents } from '@/lib/format';
import { brand } from '@/config/brand';
import { useToast } from '@/components/ui/toast';
import { LoadingState, ErrorState } from '@/components/ui/states';
import { I, ENTRY, fmtDShort, fmtDT, amtToneClass } from './ledgerShared';
import './ledger.css';

function useNowPeriod() {
  const now = new Date();
  return useState({ year: now.getFullYear(), month: now.getMonth() + 1 });
}

export function LedgerStatement() {
  const { contractId = '' } = useParams();
  const navigate = useNavigate();
  const { toast } = useToast();
  const [period, setPeriod] = useNowPeriod();
  const [exporting, setExporting] = useState(false);
  const { data, loading, error, reload } = useApi(() => landlordApi.contractStatement(contractId, period), [contractId, period]);

  const back = (
    <Link className="back" to="/app/ledger">
      {I.back} Rent ledger
    </Link>
  );

  function shiftMonth(delta: number) {
    setPeriod((p) => {
      const d = new Date(p.year, p.month - 1 + delta, 1);
      return { year: d.getFullYear(), month: d.getMonth() + 1 };
    });
  }

  async function exportCsv() {
    if (!data) return;
    setExporting(true);
    try {
      await landlordApi.exportLedger({
        contract_id: contractId,
        date_from: data.period.start,
        date_to: data.period.end,
        reason: `Tenant statement · ${data.period.label}`,
      });
      toast('Statement exported', 'success');
    } catch {
      toast('Export failed', 'error');
    } finally {
      setExporting(false);
    }
  }

  if (loading) {
    return (
      <div className="wled">
        {back}
        <LoadingState label="Loading statement…" />
      </div>
    );
  }
  if (error || !data) {
    return (
      <div className="wled">
        {back}
        <ErrorState message={error?.message ?? 'Statement not found'} onRetry={reload} />
      </div>
    );
  }

  const { contract, tenant, property, unit_number, entries } = data;
  const owed = data.ending_cents > 0;

  return (
    <div className="wled">
      {back}

      <div className="glass stmt-doc">
        <div className="stmt-top">
          <div>
            <div className="lg">
              <span className="mark">{brand.brandInitial}</span>
              {brand.appName}
            </div>
            <div style={{ fontSize: 12, color: 'var(--slate)', marginTop: 6 }}>Landlord rent statement</div>
          </div>
          <div className="meta">
            Statement generated
            <br />
            <b style={{ color: 'var(--ink-2)' }}>{fmtDT(new Date().toISOString())}</b>
            <br />
            Currency: GH₵ ({contract.currency})
          </div>
        </div>

        <div className="stmt-title">Tenant statement</div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginTop: 4 }}>
          <button className="btn btn-g sm" onClick={() => shiftMonth(-1)}>
            ‹ Prev
          </button>
          <span style={{ fontSize: 13, color: 'var(--slate)', fontWeight: 600 }}>{data.period.label}</span>
          <button className="btn btn-g sm" onClick={() => shiftMonth(1)}>
            Next ›
          </button>
        </div>

        <div className="stmt-grid" style={{ marginTop: 16 }}>
          <Si k="Tenant" v={tenant?.full_name ?? '—'} />
          <Si k="Contract" v={contract.id.slice(0, 8)} />
          <Si k="Property" v={property?.name ?? '—'} />
          <Si k="Term" v={`${fmtDShort(contract.start_date)} – ${fmtDShort(contract.end_date)}`} />
          <Si k="Unit" v={unit_number ? `Unit ${unit_number}` : '—'} />
          <Si k="Monthly rent" v={formatCents(contract.rent_cents)} />
          <Si k="Email" v={tenant?.email ?? '—'} />
          <Si k="Payment day" v={`${contract.payment_day} of the month`} />
        </div>

        <div className="stmt-sum">
          <div className="cell">
            <div className="k">Opening balance</div>
            <div className="v">{formatCents(data.opening_cents)}</div>
          </div>
          <div className="cell">
            <div className="k">Charges</div>
            <div className="v">{formatCents(data.charges_cents)}</div>
          </div>
          <div className="cell">
            <div className="k">Late fees</div>
            <div className="v">{formatCents(data.fees_cents)}</div>
          </div>
          <div className="cell">
            <div className="k">Payments</div>
            <div className="v" style={{ color: 'var(--green)' }}>
              {data.payments_cents < 0 ? '− ' : ''}
              {formatCents(data.payments_cents)}
            </div>
          </div>
          <div className={`cell end ${owed ? 'owed' : ''}`}>
            <div className="k">Ending balance</div>
            <div className="v" style={owed ? { color: 'light-dark(#FF9AA5, #FFB4BE)' } : undefined}>
              {formatCents(data.ending_cents)}
              {owed ? ' due' : ''}
            </div>
          </div>
        </div>

        <div
          className="section-t"
          style={{ fontFamily: 'var(--disp)', fontWeight: 700, fontSize: 12, letterSpacing: '.1em', textTransform: 'uppercase', color: 'var(--slate)', margin: '4px 0 8px' }}
        >
          Entries this period
        </div>
        <div className="tblwrap">
          <table className="tbl" style={{ minWidth: 560 }}>
            <thead>
              <tr>
                <th>Date</th>
                <th>Type</th>
                <th className="r">Amount</th>
                <th className="r">Balance</th>
                <th className="r">Reference</th>
              </tr>
            </thead>
            <tbody>
              {entries.length ? (
                entries.map((e: LedgerEntry) => {
                  const m = ENTRY[e.type];
                  return (
                    <tr key={e.id} onClick={() => navigate(`/app/ledger/tx/${e.id}`)}>
                      <td className="tdate">{fmtDShort(e.due_date ?? e.occurred_at)}</td>
                      <td>
                        <span className="ttype">
                          <span className="ti" style={m.tint}>
                            {m.icon}
                          </span>
                          {m.label}
                        </span>
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
                      <td className="r">
                        <span className="ref">{e.reference}</span>
                      </td>
                    </tr>
                  );
                })
              ) : (
                <tr>
                  <td colSpan={5} style={{ textAlign: 'center', color: 'var(--slate)', padding: 20 }}>
                    No entries in this period.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>

        <div style={{ display: 'flex', gap: 9, justifyContent: 'flex-end', marginTop: 20, flexWrap: 'wrap' }}>
          <button className="btn btn-p" onClick={exportCsv} disabled={exporting}>
            {I.export} {exporting ? 'Exporting…' : 'Export CSV'}
          </button>
        </div>
      </div>
    </div>
  );
}

function Si({ k, v }: { k: string; v: string }) {
  return (
    <div className="si">
      <span className="k">{k}</span>
      <span className="v">{v}</span>
    </div>
  );
}
