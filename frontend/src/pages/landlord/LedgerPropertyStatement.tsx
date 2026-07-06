/*
 * Property statement — /app/ledger/property/:propertyId.
 * Money by property for one billing month, broken down by unit/contract.
 * Faithful to the mockup's #/pstmt view with real per-unit figures computed
 * server-side. CSV export (property-scoped, audit-logged) is the real action.
 */
import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { landlordApi } from '@/lib/endpoints';
import { formatCents } from '@/lib/format';
import { brand } from '@/config/brand';
import { useToast } from '@/components/ui/toast';
import { LoadingState, ErrorState } from '@/components/ui/states';
import { I, contractStatusMeta } from './ledgerShared';
import { Badge } from './ledgerComponents';
import './ledger.css';

export function LedgerPropertyStatement() {
  const { propertyId = '' } = useParams();
  const id = Number(propertyId);
  const navigate = useNavigate();
  const { toast } = useToast();
  const now = new Date();
  const [period, setPeriod] = useState({ year: now.getFullYear(), month: now.getMonth() + 1 });
  const [exporting, setExporting] = useState(false);
  const { data, loading, error, reload } = useApi(() => landlordApi.propertyStatement(id, period), [id, period]);

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
    setExporting(true);
    try {
      await landlordApi.exportLedger({ property_id: id, reason: `Property statement · ${data?.property.name ?? ''} · ${data?.period.label ?? ''}` });
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

  const { property } = data;

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
            <div style={{ fontSize: 12, color: 'var(--slate)', marginTop: 6 }}>Landlord property statement</div>
          </div>
          <div className="meta">
            Statement generated
            <br />
            <b style={{ color: 'var(--ink-2)' }}>{new Date().toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })}</b>
            <br />
            Currency: GH₵ (GHS)
          </div>
        </div>

        <div className="stmt-title">{property.name} statement</div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginTop: 4 }}>
          <span style={{ fontSize: 13, color: 'var(--slate)' }}>
            {[property.city, property.state].filter(Boolean).join(', ') || 'Property'} ·
          </span>
          <button className="btn btn-g sm" onClick={() => shiftMonth(-1)}>
            ‹ Prev
          </button>
          <span style={{ fontSize: 13, color: 'var(--slate)', fontWeight: 600 }}>{data.period.label}</span>
          <button className="btn btn-g sm" onClick={() => shiftMonth(1)}>
            Next ›
          </button>
        </div>

        <div className="stmt-sum" style={{ gridTemplateColumns: 'repeat(3,1fr)', marginTop: 16 }}>
          <Cell k="Units" v={String(data.unit_count)} />
          <Cell k={`Charged · ${data.period.label.split(' ')[0]}`} v={formatCents(data.charged_month_cents)} />
          <Cell k={`Collected · ${data.period.label.split(' ')[0]}`} v={formatCents(data.collected_month_cents)} green />
          <Cell k="Outstanding" v={formatCents(data.outstanding_cents)} owed />
          <Cell k="Overdue" v={formatCents(data.overdue_cents)} owed />
        </div>

        <div
          className="section-t"
          style={{ fontFamily: 'var(--disp)', fontWeight: 700, fontSize: 12, letterSpacing: '.1em', textTransform: 'uppercase', color: 'var(--slate)', margin: '4px 0 8px' }}
        >
          Breakdown by unit
        </div>
        <table className="units-tbl">
          <thead>
            <tr>
              <th>Unit</th>
              <th>Tenant</th>
              <th className="r">Rent</th>
              <th className="r">Paid</th>
              <th className="r">Balance</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {data.units.map((u) => {
              const st = contractStatusMeta(u.status);
              return (
                <tr key={u.contract_id} style={{ cursor: 'pointer' }} onClick={() => navigate(`/app/ledger/statement/${u.contract_id}`)}>
                  <td>
                    <b>{u.unit_number ? `Unit ${u.unit_number}` : '—'}</b>
                  </td>
                  <td>{u.tenant?.full_name ?? '—'}</td>
                  <td className="r">{formatCents(u.rent_cents)}</td>
                  <td className="r" style={{ color: 'var(--green)' }}>
                    {formatCents(u.paid_month_cents)}
                  </td>
                  <td className="r" style={u.balance_cents > 0 ? { color: 'var(--oxblood)' } : undefined}>
                    {formatCents(u.balance_cents)}
                  </td>
                  <td>
                    <Badge badge={st.badge}>{st.label}</Badge>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>

        <div style={{ display: 'flex', gap: 9, justifyContent: 'flex-end', marginTop: 20 }}>
          <button className="btn btn-p" onClick={exportCsv} disabled={exporting}>
            {I.export} {exporting ? 'Exporting…' : 'Export CSV'}
          </button>
        </div>
      </div>
    </div>
  );
}

function Cell({ k, v, green, owed }: { k: string; v: string; green?: boolean; owed?: boolean }) {
  return (
    <div className={`cell ${owed ? 'owed' : ''}`}>
      <div className="k">{k}</div>
      <div className="v" style={green ? { color: 'var(--green)' } : undefined}>
        {v}
      </div>
    </div>
  );
}
