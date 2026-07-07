import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { landlordApi } from '@/lib/endpoints';
import type { Application, ApplicationStatus } from '@/lib/types';
import { formatDate, formatCedisDecimal } from '@/lib/format';
import { Modal } from '@/components/ui/Modal';
import { InfoHint } from '@/components/ui/InfoHint';
import { help } from '@/lib/helpText';
import { useToast } from '@/components/ui/toast';
import {
  IconStar,
  IconMessage,
  IconSearch,
  IconShield,
  IconCompare,
  IconPerson,
} from './applicants-ui';
import {
  APPLICANT_TABS,
  affordability,
  AFFORD_LABEL,
  canShortlist,
  completenessPercent,
  householdSummary,
  isDecidable,
  isFullyVerified,
  matchesApplicantTab,
  type ApplicantTab,
} from './applicantHelpers';
import './applicants.css';

type SortKey = 'newest' | 'oldest' | 'income' | 'afford' | 'complete' | 'name';

const SORTS: { key: SortKey; label: string }[] = [
  { key: 'newest', label: 'Newest first' },
  { key: 'oldest', label: 'Oldest first' },
  { key: 'income', label: 'Highest income' },
  { key: 'afford', label: 'Best affordability' },
  { key: 'complete', label: 'Most complete' },
  { key: 'name', label: 'Name (A–Z)' },
];

const EDGE: Record<string, string> = {
  submitted: 'var(--wla-amber)',
  in_review: 'var(--wla-petrol-2)',
  needs_action: 'var(--wla-amber)',
  approved: 'var(--wla-green)',
  rejected: 'var(--wla-oxblood)',
  withdrawn: 'color-mix(in srgb, var(--wla-ink) 25%, transparent)',
};

const STATUS_LABEL: Record<ApplicationStatus, string> = {
  draft: 'Draft',
  submitted: 'New',
  in_review: 'Under review',
  landlord_review: 'Under review',
  needs_action: 'Needs info',
  approved: 'Approved',
  rejected: 'Not selected',
  withdrawn: 'Withdrawn',
};

function initials(name: string): string {
  return name.split(' ').map((w) => w[0]).slice(0, 2).join('').toUpperCase();
}

export function Applicants() {
  const { data, loading, error, reload } = useApi(() => landlordApi.applications(), []);
  const { toast } = useToast();
  const navigate = useNavigate();

  const [apps, setApps] = useState<Application[] | null>(null);
  const [q, setQ] = useState('');
  const [tab, setTab] = useState<ApplicantTab>('all');
  const [listingFilter, setListingFilter] = useState('all');
  const [sort, setSort] = useState<SortKey>('newest');
  const [group, setGroup] = useState(false);
  const [shortlistingId, setShortlistingId] = useState<number | null>(null);
  const [fairOpen, setFairOpen] = useState(false);
  const [exporting, setExporting] = useState(false);

  const list = useMemo<Application[]>(() => apps ?? data ?? [], [apps, data]);

  function patch(updated: Application) {
    setApps((prev) => (prev ?? data ?? []).map((a) => (a.id === updated.id ? updated : a)));
  }

  async function toggleShortlist(app: Application, e?: React.MouseEvent) {
    e?.stopPropagation();
    setShortlistingId(app.id);
    try {
      const updated = await landlordApi.toggleApplicationShortlist(app.id);
      patch(updated);
      toast(updated.is_shortlisted ? 'Added to shortlist' : 'Removed from shortlist', 'success');
    } catch {
      toast('Could not update the shortlist.', 'error');
    } finally {
      setShortlistingId(null);
    }
  }

  async function handleExport() {
    setExporting(true);
    try {
      await landlordApi.exportApplications();
      toast('Applicants exported', 'success');
    } catch {
      toast('Export failed', 'error');
    } finally {
      setExporting(false);
    }
  }

  const listingOptions = useMemo(() => {
    const seen = new Map<string, string>();
    for (const a of list) {
      const key = String(a.listing_id);
      if (!seen.has(key)) seen.set(key, a.listing?.title ?? `Listing #${a.listing_id}`);
    }
    return Array.from(seen.entries());
  }, [list]);

  const counts = useMemo(() => {
    const c: Record<string, number> = {};
    for (const t of APPLICANT_TABS) c[t.key] = list.filter((a) => matchesApplicantTab(a, t.key)).length;
    return c;
  }, [list]);

  const kpi = useMemo(
    () => ({
      total: list.filter((a) => a.status !== 'withdrawn').length,
      newCount: list.filter((a) => a.status === 'submitted').length,
      review: list.filter((a) => a.status === 'in_review' || a.status === 'needs_action').length,
      shortlisted: list.filter((a) => a.is_shortlisted).length,
      approved: list.filter((a) => a.status === 'approved').length,
    }),
    [list],
  );

  const rows = useMemo(() => {
    const query = q.trim().toLowerCase();
    let out = list.filter((a) => {
      if (!matchesApplicantTab(a, tab)) return false;
      if (listingFilter !== 'all' && String(a.listing_id) !== listingFilter) return false;
      if (query) {
        const hay = [a.tenant?.full_name, a.tenant?.email, a.tenant?.phone, a.listing?.title, a.form_data?.employment?.employer]
          .filter(Boolean)
          .join(' ')
          .toLowerCase();
        if (!hay.includes(query)) return false;
      }
      return true;
    });

    out = [...out].sort((a, b) => {
      switch (sort) {
        case 'oldest':
          return new Date(a.submitted_at ?? a.created_at).getTime() - new Date(b.submitted_at ?? b.created_at).getTime();
        case 'income':
          return (parseFloat(b.form_data?.employment?.income ?? '0') || 0) - (parseFloat(a.form_data?.employment?.income ?? '0') || 0);
        case 'afford':
          return (affordability(b)?.ratio ?? -1) - (affordability(a)?.ratio ?? -1);
        case 'complete':
          return completenessPercent(b) - completenessPercent(a);
        case 'name':
          return (a.tenant?.full_name ?? '').localeCompare(b.tenant?.full_name ?? '');
        default:
          return new Date(b.submitted_at ?? b.created_at).getTime() - new Date(a.submitted_at ?? a.created_at).getTime();
      }
    });
    return out;
  }, [list, q, tab, listingFilter, sort]);

  function openApplicant(app: Application, action?: 'approve' | 'reject') {
    navigate(`/app/applicants/${app.id}${action ? `?action=${action}` : ''}`);
  }

  if (loading) {
    return (
      <div className="wla">
        <section className="glass" style={{ padding: '3rem', textAlign: 'center', color: 'var(--wla-ink-3)' }}>Loading applicants…</section>
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
      <section className="glass pagehead">
        <div>
          <span className="ph-eyebrow">Leasing</span>
          <h1 className="ph-title">
            Tenant <b>applications.</b>
          </h1>
          <p className="ph-sub">
            Review prospective tenants for your listings. Wyncrest verifies each applicant's identity and documents, so you can
            focus on the fit.
          </p>
        </div>
        <div className="ph-actions">
          <button className="btn" onClick={handleExport} disabled={exporting || list.length === 0}>
            {exporting ? 'Exporting…' : 'Export'}
          </button>
          {kpi.shortlisted > 1 && (
            <button className="btn" onClick={() => navigate('/app/applicants/compare')}>
              <IconCompare /> Compare shortlist ({kpi.shortlisted})
            </button>
          )}
        </div>
      </section>

      <div className="sumcards">
        <div className="scard glass">
          <div className="sl"><i style={{ background: 'var(--wla-petrol-2)' }} />Total applications</div>
          <div className="sv">{kpi.total}</div>
          <div className="ss">across your listings</div>
        </div>
        <div className="scard glass new">
          <div className="sl"><i style={{ background: 'var(--wla-amber)' }} />New</div>
          <div className="sv">{kpi.newCount}</div>
          <div className="ss">need first review</div>
        </div>
        <div className="scard glass">
          <div className="sl">
            <i style={{ background: 'var(--wla-petrol-2)' }} />
            Under review
            <InfoHint text={help.appSubmitted} label="About under review" />
          </div>
          <div className="sv">{kpi.review}</div>
          <div className="ss">in progress</div>
        </div>
        <div className="scard glass short">
          <div className="sl"><i style={{ background: 'var(--wla-indigo)' }} />Shortlisted</div>
          <div className="sv">{kpi.shortlisted}</div>
          <div className="ss">strong candidates</div>
        </div>
        <div className="scard glass appr">
          <div className="sl"><i style={{ background: 'var(--wla-green)' }} />Approved</div>
          <div className="sv">{kpi.approved}</div>
          <div className="ss">moving to lease</div>
        </div>
      </div>

      <button type="button" className="glass fairbar" onClick={() => setFairOpen(true)}>
        <div className="fi"><IconShield /></div>
        <div>
          Review every applicant on the same objective basis: affordability, verification, and rental history. Wyncrest
          records your decisions to support fair, consistent leasing. <span className="link">Fair housing guidance</span>
        </div>
      </button>

      <section className="glass">
        <div className="toolbar">
          <div className="tabs">
            {APPLICANT_TABS.map((t) => (
              <button key={t.key} className={`tab ${tab === t.key ? 'on' : ''}`} onClick={() => setTab(t.key)}>
                {t.label} <span className="cnt">{counts[t.key] ?? 0}</span>
              </button>
            ))}
          </div>
          <div className="filt">
            <div className="search">
              <IconSearch />
              <input aria-label="Search applicants" placeholder="Search applicants, listings, or employers…" value={q} onChange={(e) => setQ(e.target.value)} />
            </div>
            <select className="sel" value={listingFilter} onChange={(e) => setListingFilter(e.target.value)}>
              <option value="all">All listings</option>
              {listingOptions.map(([id, title]) => (
                <option key={id} value={id}>{title}</option>
              ))}
            </select>
            <select className="sel" value={sort} onChange={(e) => setSort(e.target.value as SortKey)}>
              {SORTS.map((s) => (
                <option key={s.key} value={s.key}>Sort: {s.label}</option>
              ))}
            </select>
            <button className={`btn btn-sm ${group ? 'btn-dark' : ''}`} onClick={() => setGroup((g) => !g)}>
              {group ? 'Grouped' : 'Group by listing'}
            </button>
          </div>
        </div>
      </section>

      {rows.length === 0 ? (
        <section className="glass">
          <div className="empty">
            <div className="ic"><IconPerson /></div>
            <span className="et">{list.length === 0 ? 'No applicants yet' : 'No applications here'}</span>
            <p>
              {list.length === 0
                ? "When tenants apply to your active listings, they'll appear here for review."
                : 'No applications match this view. Try adjusting the search term or filter.'}
            </p>
          </div>
        </section>
      ) : group ? (
        <GroupedList rows={rows} onOpen={openApplicant} onToggleShortlist={toggleShortlist} shortlistingId={shortlistingId} />
      ) : (
        <div className="alist">
          {rows.map((app) => (
            <ApplicantCard key={app.id} app={app} onOpen={openApplicant} onToggleShortlist={toggleShortlist} shortlisting={shortlistingId === app.id} />
          ))}
        </div>
      )}

      <Modal
        open={fairOpen}
        onClose={() => setFairOpen(false)}
        title="Fair housing guidance"
        footer={<button className="btn btn-dark" onClick={() => setFairOpen(false)}>Got it</button>}
      >
        <p style={{ fontSize: '0.86rem', color: 'var(--wla-ink-2)', lineHeight: 1.6 }}>
          Wyncrest is committed to fair, consistent leasing. When reviewing applicants:
          <br />
          <br />
          <b>Do</b> consider affordability, verified identity, rental history, references, and completeness.
          <br />
          <br />
          <b>Do not</b> consider race, ethnicity, religion, gender, sexual orientation, disability, nationality, or family
          status.
          <br />
          <br />
          Apply the same criteria to every applicant for a unit. Wyncrest records decisions and reasons so your process stays
          consistent and defensible.
        </p>
      </Modal>
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

function ApplicantCard({
  app,
  onOpen,
  onToggleShortlist,
  shortlisting,
}: {
  app: Application;
  onOpen: (app: Application, action?: 'approve' | 'reject') => void;
  onToggleShortlist: (app: Application, e?: React.MouseEvent) => void;
  shortlisting: boolean;
}) {
  const name = app.tenant?.full_name ?? `Applicant #${app.id}`;
  const listing = app.listing;
  const unit = listing?.unit;
  const property = unit?.property;
  const afford = affordability(app);
  const income = app.form_data?.employment?.income;
  const completeness = completenessPercent(app);
  const verified = isFullyVerified(app);

  return (
    <div className="acard glass" style={{ ['--edge' as string]: EDGE[app.status] ?? 'var(--wla-gborder)' }} {...pressable(() => onOpen(app))}>
      <div className="ac-top">
        <div className="avatar">{initials(name)}</div>
        <div className="ac-id">
          <div className="ac-name">
            {name}
            {verified ? (
              <span className="vbadge ok"><IconCheck /> Verified</span>
            ) : (
              <span className="vbadge part">Partly verified</span>
            )}
          </div>
          <div className="ac-for">
            Applied for {property?.name ?? listing?.title} {unit ? `· Unit ${unit.unit_number}` : ''} ·{' '}
            {unit?.rent_amount ? `${formatCedisDecimal(unit.rent_amount)}/mo` : '—'}
          </div>
        </div>
        <span className={`statuspill ${app.status}`}><span className="sd" />{STATUS_LABEL[app.status]}</span>
      </div>

      <div className="signals">
        <div className="sig"><div className="n">{income ? formatCedisDecimal(income) : '—'}</div><div className="l">Monthly income</div></div>
        <div className="sig">
          <div className="n">{afford ? <span className={`afford ${afford.level}`}>{afford.ratio}× · {AFFORD_LABEL[afford.level]}</span> : '—'}</div>
          <div className="l">Affordability</div>
        </div>
        <div className="sig"><div className="n">{completeness}%</div><div className="l">Completeness</div></div>
        <div className="sig"><div className="n">{householdSummary(app)}</div><div className="l">Household</div></div>
      </div>

      <div className="ac-foot">
        <span className="sub">Submitted {formatDate(app.submitted_at ?? app.created_at)} · #{app.id}</span>
        {isDecidable(app.status) && (
          <button className="btn btn-sm btn-petrol" onClick={(e) => { e.stopPropagation(); onOpen(app); }}>Review</button>
        )}
        {canShortlist(app.status) && (
          <button
            className={`iconbtn ${app.is_shortlisted ? 'on' : ''}`}
            title="Shortlist"
            disabled={shortlisting}
            onClick={(e) => onToggleShortlist(app, e)}
          >
            <IconStar />
          </button>
        )}
        <button className="iconbtn" title="Message" onClick={(e) => { e.stopPropagation(); onOpen(app); }}>
          <IconMessage />
        </button>
      </div>
    </div>
  );
}

/** Local checkmark for the verified badge (avoids importing the shared icon kit). */
function IconCheck(p: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" className={p.className}>
      <path d="M20 6L9 17l-5-5" />
    </svg>
  );
}

function GroupedList({
  rows,
  onOpen,
  onToggleShortlist,
  shortlistingId,
}: {
  rows: Application[];
  onOpen: (app: Application, action?: 'approve' | 'reject') => void;
  onToggleShortlist: (app: Application, e?: React.MouseEvent) => void;
  shortlistingId: number | null;
}) {
  const groups: { key: string; label: string; items: Application[] }[] = [];
  const index = new Map<string, number>();
  for (const app of rows) {
    const key = String(app.listing_id);
    if (!index.has(key)) {
      index.set(key, groups.length);
      const unit = app.listing?.unit;
      const label = [app.listing?.unit?.property?.name ?? app.listing?.title, unit ? `Unit ${unit.unit_number}` : null].filter(Boolean).join(' · ');
      groups.push({ key, label, items: [] });
    }
    groups[index.get(key)!].items.push(app);
  }

  return (
    <div className="alist">
      {groups.map((g) => (
        <div key={g.key}>
          <div className="grouphead">
            {g.label} <span className="gc">{g.items.length}</span>
          </div>
          <div className="alist">
            {g.items.map((app) => (
              <ApplicantCard key={app.id} app={app} onOpen={onOpen} onToggleShortlist={onToggleShortlist} shortlisting={shortlistingId === app.id} />
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}
