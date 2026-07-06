import { useMemo } from 'react';
import { useNavigate } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { landlordApi } from '@/lib/endpoints';
import { formatCedisDecimal } from '@/lib/format';
import { IconBack, IconCompare } from './applicants-ui';
import { affordability, AFFORD_LABEL, completenessPercent, householdSummary, isFullyVerified } from './applicantHelpers';
import './applicants.css';

const STATUS_LABEL: Record<string, string> = {
  submitted: 'New',
  in_review: 'Under review',
  needs_action: 'Needs info',
  approved: 'Approved',
  rejected: 'Not selected',
  withdrawn: 'Withdrawn',
};

function bestIndex(values: (number | null)[]): boolean[] {
  const real = values.filter((v): v is number => v !== null);
  if (real.length === 0) return values.map(() => false);
  const max = Math.max(...real);
  return values.map((v) => v !== null && v === max);
}

export function ApplicantsCompare() {
  const { data, loading, error, reload } = useApi(() => landlordApi.applications(), []);
  const navigate = useNavigate();

  const sl = useMemo(() => (data ?? []).filter((a) => a.is_shortlisted), [data]);

  const incomes = sl.map((a) => {
    const n = parseFloat(a.form_data?.employment?.income ?? '');
    return Number.isFinite(n) ? n : null;
  });
  const ratios = sl.map((a) => affordability(a)?.ratio ?? null);
  const completeness = sl.map((a) => completenessPercent(a));
  const bInc = bestIndex(incomes);
  const bRat = bestIndex(ratios);
  const bComp = bestIndex(completeness);

  if (loading) {
    return (
      <div className="wla">
        <section className="glass" style={{ padding: '3rem', textAlign: 'center', color: 'var(--wla-ink-3)' }}>Loading shortlist…</section>
      </div>
    );
  }
  if (error) {
    return (
      <div className="wla">
        <section className="glass empty">
          <span className="et">Couldn't load applicants</span>
          <p>{error.message}</p>
          <button className="btn btn-dark" onClick={reload}>Retry</button>
        </section>
      </div>
    );
  }

  return (
    <div className="wla animate-rise">
      <div className="crumb">
        <button className="back" onClick={() => navigate('/app/applicants')}><IconBack /> Back to Applicants</button>
        {sl.length >= 2 && <><span className="sep">/</span><span>Compare shortlist</span></>}
      </div>

      {sl.length < 2 ? (
        <section className="glass">
          <div className="empty">
            <div className="ic"><IconCompare /></div>
            <span className="et">Shortlist at least two applicants</span>
            <p>Add applicants to your shortlist to compare them side by side across income, affordability, verification, and more.</p>
          </div>
        </section>
      ) : (
        <section className="glass" style={{ padding: '1.4rem 1.5rem' }}>
          <div className="sec-h" style={{ marginBottom: '.5rem' }}>
            Compare shortlisted applicants<span className="hint">{sl.length} applicants · highlighted cells lead on that factor</span>
          </div>
          <div className="comp-table">
            <table className="comp">
              <thead>
                <tr>
                  <th className="rowlabel" />
                  {sl.map((a) => (
                    <th key={a.id}>
                      {a.tenant?.full_name ?? `Applicant #${a.id}`}
                      <div style={{ fontFamily: 'var(--wla-sans)', fontWeight: 400, fontSize: '.76rem', color: 'var(--wla-ink-3)' }}>
                        {a.listing?.unit?.property?.name ?? a.listing?.title} {a.listing?.unit ? `· ${a.listing.unit.unit_number}` : ''}
                      </div>
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                <Row label="Status" cells={sl.map((a) => <span className={`statuspill ${a.status}`}><span className="sd" />{STATUS_LABEL[a.status] ?? a.status}</span>)} />
                <Row label="Monthly income" cells={sl.map((a) => (a.form_data?.employment?.income ? formatCedisDecimal(a.form_data.employment.income) : '—'))} best={bInc} />
                <Row
                  label="Affordability"
                  cells={sl.map((a) => {
                    const af = affordability(a);
                    return af ? `${af.ratio}× (${AFFORD_LABEL[af.level]})` : '—';
                  })}
                  best={bRat}
                />
                <Row label="Rent" cells={sl.map((a) => (a.listing?.unit?.rent_amount ? formatCedisDecimal(a.listing.unit.rent_amount) : '—'))} />
                <Row
                  label="Verification"
                  cells={sl.map((a) => <span className={`badge ${isFullyVerified(a) ? 'green' : 'amber'}`}>{isFullyVerified(a) ? 'Verified' : 'Partial'}</span>)}
                />
                <Row label="Completeness" cells={sl.map((a) => `${completenessPercent(a)}%`)} best={bComp} />
                <Row label="Employment" cells={sl.map((a) => [a.form_data?.employment?.title, a.form_data?.employment?.employer].filter(Boolean).join(', ') || '—')} />
                <Row label="Current residence" cells={sl.map((a) => a.form_data?.rental?.curType || '—')} />
                <Row label="Household" cells={sl.map((a) => householdSummary(a))} />
                <Row label="Move-in" cells={sl.map((a) => a.form_data?.rental?.moveIn || '—')} />
                <Row label="" cells={sl.map((a) => <button className="btn btn-sm btn-petrol" onClick={() => navigate(`/app/applicants/${a.id}`)}>Open</button>)} />
              </tbody>
            </table>
          </div>
          <div className="fairnote" style={{ marginTop: '1rem' }}>
            <IconCompare />
            <div>Highlights show who leads on each objective factor. They are a guide, not an automatic decision. Weigh the whole picture and apply the same standard to everyone.</div>
          </div>
        </section>
      )}
    </div>
  );
}

function Row({ label, cells, best }: { label: string; cells: React.ReactNode[]; best?: boolean[] }) {
  return (
    <tr>
      <td className="rowlabel">{label}</td>
      {cells.map((c, i) => (
        <td key={i} className={best?.[i] ? 'best' : undefined}>{c}</td>
      ))}
    </tr>
  );
}
