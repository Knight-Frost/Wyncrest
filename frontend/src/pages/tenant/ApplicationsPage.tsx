/**
 * ApplicationsPage — the tenant's rental-application command centre.
 *
 * Rebuilt from the wyncrest-applications mockup, wired to 100% real data:
 *   GET /tenant/applications  → list (status, listing, counts, latest event)
 * Every card links to the real detail workspace; drafts open the guided form.
 * No fabricated fields, no dead buttons.
 */
import { useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { tenantApi } from '@/lib/endpoints';
import { formatDate, formatCedisDecimal, formatDateTime } from '@/lib/format';
import { LoadingState, ErrorState, ForbiddenState } from '@/components/ui/states';
import {
  IconAlertTriangle,
  IconArrowRight,
  IconCheck,
  IconClock,
  IconCircleCheck,
} from '@/components/ui/icons';
import { InfoHint } from '@/components/ui/InfoHint';
import { help } from '@/lib/helpText';
import type { Application, ApplicationStatus } from '@/lib/types';
import {
  STATUS_LABEL,
  STATUS_ROLE,
  isPastStatus,
  homeTitle,
  unitLabel,
  homeAddress,
  rentAmount,
  progressText,
  progressPercent,
} from './applicationHelpers';
import './applications.css';

/* ── Status pill ─────────────────────────────────────────────────────────── */

function StatusPill({ status }: { status: ApplicationStatus }) {
  return (
    <span className={`wapp-pill ${STATUS_ROLE[status]}`}>
      <span className="sd" />
      {STATUS_LABEL[status]}
    </span>
  );
}

/* ── Tabs ────────────────────────────────────────────────────────────────── */

const TABS: { v: string; l: string }[] = [
  { v: 'all', l: 'All' },
  { v: 'drafts', l: 'Drafts' },
  { v: 'submitted', l: 'Submitted' },
  { v: 'needs', l: 'Needs action' },
  { v: 'approved', l: 'Approved' },
  { v: 'past', l: 'Not selected' },
];

const SUBMITTED_SET: ApplicationStatus[] = ['submitted', 'in_review', 'landlord_review'];

function inTab(app: Application, tab: string): boolean {
  switch (tab) {
    case 'drafts':    return app.status === 'draft';
    case 'submitted': return SUBMITTED_SET.includes(app.status);
    case 'needs':     return app.status === 'needs_action';
    case 'approved':  return app.status === 'approved';
    case 'past':      return isPastStatus(app.status);
    default:          return true;
  }
}

/* ── Application card ─────────────────────────────────────────────────────── */

function ApplicationCard({ app }: { app: Application }) {
  const navigate = useNavigate();
  const isDraft = app.status === 'draft';
  const unit = unitLabel(app);
  const rent = rentAmount(app);
  const pct = progressPercent(app);

  const primaryLabel =
    app.status === 'draft' ? 'Continue application'
    : app.status === 'needs_action' ? 'Fix application'
    : app.status === 'approved' ? 'View approval'
    : isPastStatus(app.status) ? 'View decision'
    : 'View details';

  const primaryClass =
    app.status === 'needs_action' ? 'wapp-btn-warning'
    : app.status === 'approved' ? 'wapp-btn-success'
    : app.status === 'draft' ? 'wapp-btn-primary'
    : 'wapp-btn-glass';

  const go = () => navigate(isDraft ? `/app/applications/${app.id}/apply` : `/app/applications/${app.id}`);

  const edgeVar =
    STATUS_ROLE[app.status] === 'warning' ? 'var(--color-warning-500)'
    : STATUS_ROLE[app.status] === 'success' ? 'var(--color-success-600)'
    : STATUS_ROLE[app.status] === 'danger' ? 'var(--color-danger-500)'
    : STATUS_ROLE[app.status] === 'info' ? 'var(--color-info-500)'
    : 'var(--color-ink-300)';

  return (
    <button
      type="button"
      className="wapp-acard"
      style={{ ['--wapp-edge' as string]: edgeVar }}
      onClick={() => navigate(`/app/applications/${app.id}`)}
    >
      <div className="wapp-ac-top">
        <div className="wapp-ac-home">
          <div className="h">{homeTitle(app)}{unit ? `, ${unit}` : ''}</div>
          <div className="a">{homeAddress(app) || '—'}</div>
        </div>
        <StatusPill status={app.status} />
      </div>

      <div className="wapp-ac-grid">
        <div className="wapp-ac-cell">
          <div className="l">Rent</div>
          <div className="v money">{rent ? `${formatCedisDecimal(rent)}/mo` : '—'}</div>
        </div>
        <div className="wapp-ac-cell">
          <div className="l">Progress</div>
          <div className="v" style={{ fontSize: '0.82rem' }}>{progressText(app)}</div>
          <div className="wapp-progbar"><i style={{ width: `${pct}%` }} /></div>
        </div>
        <div className="wapp-ac-cell">
          <div className="l">{isDraft ? 'Started' : 'Submitted'}</div>
          <div className="v">{formatDate(app.submitted_at ?? app.created_at)}</div>
        </div>
        <div className="wapp-ac-cell">
          <div className="l">Last update</div>
          <div className="v">{formatDate(app.latest_event?.created_at ?? app.created_at)}</div>
        </div>
      </div>

      <div className="wapp-ac-foot">
        {(app.status === 'needs_action' || app.status === 'draft') && (
          <span className="wapp-ac-next">
            <IconAlertTriangle size={13} aria-hidden="true" />
            {app.status === 'needs_action' ? 'Action needed' : 'Continue where you left off'}
          </span>
        )}
        <span className="spacer" />
        <span
          className={`wapp-btn wapp-btn-sm ${primaryClass}`}
          onClick={(e) => { e.stopPropagation(); go(); }}
          role="button"
          tabIndex={0}
          onKeyDown={(e) => { if (e.key === 'Enter') { e.stopPropagation(); go(); } }}
        >
          {primaryLabel}
        </span>
      </div>
    </button>
  );
}

/* ── Recent updates ──────────────────────────────────────────────────────── */

function RecentUpdates({ apps }: { apps: Application[] }) {
  const updates = useMemo(() => {
    return apps
      .filter((a) => a.latest_event)
      .map((a) => ({ app: a, ev: a.latest_event! }))
      .sort((x, y) => (y.ev.created_at ?? '').localeCompare(x.ev.created_at ?? ''))
      .slice(0, 5);
  }, [apps]);

  if (updates.length === 0) return null;

  return (
    <section className="wapp-glass wapp-updates">
      <h2>Recent updates</h2>
      {updates.map(({ app, ev }) => {
        const cls = ev.event === 'info_requested' ? 'warn'
          : ev.event === 'approved' || ev.event === 'request_resolved' ? 'ok'
          : ev.event === 'rejected' || ev.event === 'withdrawn' ? 'warn'
          : 'info';
        const Icon = cls === 'warn' ? IconAlertTriangle : cls === 'ok' ? IconCheck : IconClock;
        return (
          <Link key={ev.id} to={`/app/applications/${app.id}`} className={`wapp-upd ${cls}`} style={{ textDecoration: 'none' }}>
            <span className="ud"><Icon size={15} aria-hidden="true" /></span>
            <span className="um">
              <span>{ev.description} · <b>{homeTitle(app)}</b></span>
              <span className="ut" style={{ display: 'block' }}>{formatDateTime(ev.created_at)}</span>
            </span>
          </Link>
        );
      })}
    </section>
  );
}

/* ── Page ────────────────────────────────────────────────────────────────── */

export function ApplicationsPage() {
  const [tab, setTab] = useState('all');
  const appsQ = useApi(() => tenantApi.applications(), []);
  const apps = useMemo(() => appsQ.data ?? [], [appsQ.data]);

  const counts = useMemo(() => ({
    drafts: apps.filter((a) => a.status === 'draft').length,
    submitted: apps.filter((a) => SUBMITTED_SET.includes(a.status)).length,
    needs: apps.filter((a) => a.status === 'needs_action').length,
    approved: apps.filter((a) => a.status === 'approved').length,
  }), [apps]);

  const needs = useMemo(() => apps.filter((a) => a.status === 'needs_action'), [apps]);
  const rows = useMemo(() => apps.filter((a) => inTab(a, tab)), [apps, tab]);

  if (appsQ.loading) {
    return (
      <div className="wapp">
        <Intro />
        <LoadingState label="Loading applications…" />
      </div>
    );
  }

  if (appsQ.error) {
    if (appsQ.error.status === 403) {
      return (
        <div className="wapp">
          <Intro />
          <ForbiddenState title="Applications unavailable" message="Your account doesn't have access to applications." />
        </div>
      );
    }
    return (
      <div className="wapp">
        <Intro />
        <ErrorState title="Couldn't load applications" message={appsQ.error.message} onRetry={appsQ.reload} />
      </div>
    );
  }

  return (
    <div className="wapp">
      <Intro />

      {/* Stat cards */}
      <section className="wapp-cards">
        <StatCard label="Drafts" help={help.appDraft} value={counts.drafts} color="var(--color-ink-400)" onClick={() => setTab('drafts')} />
        <StatCard label="Submitted" help={help.appSubmitted} value={counts.submitted} color="var(--color-info-500)" onClick={() => setTab('submitted')} />
        <StatCard label="Needs action" help={help.appNeedsAction} value={counts.needs} color="var(--color-warning-500)" tone="warn" onClick={() => setTab('needs')} />
        <StatCard label="Approved" help={help.appApproved} value={counts.approved} color="var(--color-success-600)" tone="ok" onClick={() => setTab('approved')} />
      </section>

      {/* Needs-action banner */}
      {needs.length > 0 && (
        <section className="wapp-glass wapp-alert">
          <div className="wapp-alert-h">
            <IconAlertTriangle size={18} aria-hidden="true" />
            Needs action
          </div>
          {needs.map((a) => (
            <div key={a.id} className="wapp-alert-row">
              <div className="wapp-alert-m">
                <div className="wapp-alert-mt">{homeTitle(a)}{unitLabel(a) ? `, ${unitLabel(a)}` : ''}</div>
                <div className="wapp-alert-ms">
                  {a.open_requests_count && a.open_requests_count > 0
                    ? `${a.open_requests_count} open request${a.open_requests_count === 1 ? '' : 's'} from the landlord.`
                    : 'The landlord needs something from you.'}
                </div>
              </div>
              <Link to={`/app/applications/${a.id}`} className="wapp-btn wapp-btn-warning wapp-btn-sm">
                Open application
              </Link>
            </div>
          ))}
        </section>
      )}

      {/* List panel */}
      <section className="wapp-glass">
        <div className="wapp-panel-head">
          <h2>Your applications</h2>
          <div className="wapp-tabs">
            {TABS.map((t) => (
              <button
                key={t.v}
                type="button"
                className={`wapp-tab${tab === t.v ? ' on' : ''}`}
                onClick={() => setTab(t.v)}
                aria-pressed={tab === t.v}
              >
                {t.l}
              </button>
            ))}
          </div>
        </div>

        <div className="wapp-list">
          {rows.length > 0 ? (
            rows.map((a) => <ApplicationCard key={a.id} app={a} />)
          ) : (
            <div className="wapp-empty">
              <div className="ic"><IconCircleCheck size={26} aria-hidden="true" /></div>
              <span className="t">No applications here</span>
              <p>
                {apps.length === 0
                  ? 'When you apply for a rental, your progress, documents, and landlord updates will appear here.'
                  : 'Nothing matches this filter. Try a different tab.'}
              </p>
              <Link to="/app/browse" className="wapp-btn wapp-btn-primary">
                Browse listings <IconArrowRight size={15} aria-hidden="true" />
              </Link>
            </div>
          )}
        </div>
      </section>

      <RecentUpdates apps={apps} />
    </div>
  );
}

/* ── Small pieces ────────────────────────────────────────────────────────── */

function Intro() {
  return (
    <section className="wapp-glass wapp-intro">
      <span className="wapp-eyebrow">Applications</span>
      <h1>Rental <b>applications.</b></h1>
      <p>Apply for homes, upload the documents each home needs, and track landlord review — all in one place.</p>
    </section>
  );
}

function StatCard({
  label,
  help: helpText,
  value,
  color,
  tone,
  onClick,
}: {
  label: string;
  help?: string;
  value: number;
  color: string;
  tone?: 'warn' | 'ok';
  onClick: () => void;
}) {
  return (
    <button type="button" className={`wapp-glass wapp-card${tone ? ` ${tone}` : ''}`} onClick={onClick}>
      <div className="wapp-card-l">
        <i style={{ background: color }} />
        {label}
        {helpText && (
          <span onClick={(e) => e.stopPropagation()}>
            <InfoHint text={helpText} label={`About ${label}`} />
          </span>
        )}
      </div>
      <div className="wapp-card-v">{value}</div>
    </button>
  );
}
