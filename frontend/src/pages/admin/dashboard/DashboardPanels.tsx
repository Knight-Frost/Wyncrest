import { useState } from 'react';
import { useNavigate } from 'react-router';
import { adminHasCapability, type CapabilitySubject } from '@/lib/permissions';
import { formatCedisDecimal, timeAgo } from '@/lib/format';
import { help } from '@/lib/helpText';
import { InfoHint } from '@/components/ui/InfoHint';
import type { AdminDashboard, DashboardRentCase } from '@/lib/types';

/* ============================================================================
   SECTIONS 3–7 — Platform Snapshot, Rent Risk Monitor, Review Queues,
   System Health, Recent Important Activity.
   ============================================================================ */

/* ---- Section 3: Platform Snapshot ---------------------------------------- */

function SnapshotCard({
  title,
  lines,
  onClick,
  index,
}: {
  title: string;
  lines: React.ReactNode[];
  onClick?: () => void;
  index: number;
}) {
  const Tag = onClick ? 'button' : 'div';
  return (
    <Tag
      type={onClick ? 'button' : undefined}
      className={`snap-card glass reveal${onClick ? ' click' : ''}`}
      style={{ '--i': index } as React.CSSProperties}
      onClick={onClick}
    >
      <div className="snap-title mono-l">{title}</div>
      {lines.map((l, i) => (
        <div key={i} className={i === 0 ? 'snap-headline' : 'snap-line'}>
          {l}
        </div>
      ))}
    </Tag>
  );
}

export function PlatformSnapshot({
  data,
  user,
}: {
  data: AdminDashboard;
  user: CapabilitySubject | null | undefined;
}) {
  const navigate = useNavigate();
  const s = data.platform_snapshot;
  const canListings = adminHasCapability(user, 'moderate_listings');
  const canAudit = adminHasCapability(user, 'view_audit');

  return (
    <section className="wadm-section">
      <div className="wadm-section-head">
        <h2>Platform snapshot</h2>
        <div className="ph-sub">The high-level picture, still traceable</div>
      </div>
      <div className="snap-grid">
        <SnapshotCard
          index={0}
          title="Users"
          onClick={() => navigate('/app/users')}
          lines={[
            <>{(s.users.tenants + s.users.landlords).toLocaleString()} users</>,
            <>
              {s.users.tenants} tenants · {s.users.landlords} landlords
            </>,
            <>{s.users.pending_verifications} awaiting verification</>,
            s.users.suspended > 0 ? <>{s.users.suspended} suspended</> : <>{s.users.new_this_week} new this week</>,
          ]}
        />
        <SnapshotCard
          index={1}
          title="Listings"
          onClick={canListings ? () => navigate('/app/listing-review') : undefined}
          lines={[
            <>{s.listings.total.toLocaleString()} listings</>,
            <>
              {s.listings.active} active · {s.listings.pending} pending · {s.listings.rejected} rejected
            </>,
            <>{s.listings.recently_submitted} submitted this week</>,
          ]}
        />
        <SnapshotCard
          index={2}
          title="Contracts"
          onClick={() => navigate('/app/contracts')}
          lines={[
            <>{s.contracts.active.toLocaleString()} active contracts</>,
            <>{s.contracts.with_overdue_rent} with overdue rent</>,
            <>{s.contracts.ending_soon} ending within 30 days</>,
          ]}
        />
        <SnapshotCard
          index={3}
          title="Rent / ledger"
          onClick={() => navigate('/app/ledger')}
          lines={[
            <>{formatCedisDecimal(s.rent_ledger.expected_this_month_cents / 100)} expected this month</>,
            <>{formatCedisDecimal(s.rent_ledger.collected_this_month_cents / 100)} collected</>,
            <>{formatCedisDecimal(s.rent_ledger.outstanding_cents / 100)} outstanding</>,
            <span className={s.rent_ledger.overdue_cents > 0 ? 'down' : undefined}>
              {formatCedisDecimal(s.rent_ledger.overdue_cents / 100)} overdue
            </span>,
          ]}
        />
        <SnapshotCard
          index={4}
          title="Maintenance"
          onClick={() => navigate('/app/maintenance')}
          lines={[
            <>{s.maintenance.open} open requests</>,
            <>
              {s.maintenance.urgent} urgent · {s.maintenance.overdue} overdue
            </>,
            <>{s.maintenance.waiting} waiting</>,
          ]}
        />
        <SnapshotCard
          index={5}
          title="Notifications"
          onClick={canAudit ? () => navigate('/app/notifications') : undefined}
          lines={[
            <>{s.notifications.failed_total} failed deliveries</>,
            <>{s.notifications.critical_failed} critical failed notices</>,
          ]}
        />
      </div>
    </section>
  );
}

/* ---- Section 4: Rent Risk Monitor ----------------------------------------- */

function RentCaseRow({ c }: { c: DashboardRentCase }) {
  const navigate = useNavigate();
  return (
    <article className="rr-row glass">
      <div className="rr-main">
        <span className="rr-tenant">{c.tenant ?? 'Unknown tenant'}</span>
        <span className="rr-sep">·</span>
        <span className="rr-landlord">{c.landlord ?? 'Unknown landlord'}</span>
        {c.property && (
          <>
            <span className="rr-sep">·</span>
            <span className="rr-property">{c.property}</span>
          </>
        )}
      </div>
      <div className="rr-meta">
        <span className="rr-amount">{formatCedisDecimal(c.amount_cents / 100)}</span>
        <span className="rr-due mono-l">Due {c.due_date ?? '—'}</span>
        <span className="rr-days sev-high">{c.days_late}d late</span>
      </div>
      <button type="button" className="btn btn-glass rr-action" onClick={() => navigate('/app/ledger')}>
        View ledger <span aria-hidden="true">&rarr;</span>
      </button>
    </article>
  );
}

export function RentRiskMonitor({ data }: { data: AdminDashboard }) {
  const m = data.rent_risk_monitor;

  return (
    <section className="wadm-section">
      <div className="wadm-section-head">
        <h2>Rent risk monitor</h2>
        <div className="ph-sub">Every overdue balance, traced to a tenant, landlord, and property</div>
      </div>
      <div className="rr-summary">
        <div>
          <span className="mono-l">
            Outstanding <InfoHint text={help.outstandingBalance} label="About outstanding" />
          </span>
          <b>{formatCedisDecimal(m.summary.outstanding_cents / 100)}</b>
        </div>
        <div>
          <span className="mono-l">
            Overdue <InfoHint text={help.overdue} label="About overdue" />
          </span>
          <b className="down">{formatCedisDecimal(m.summary.overdue_cents / 100)}</b>
        </div>
        <div>
          <span className="mono-l">Affected tenants</span>
          <b>{m.summary.affected_tenants}</b>
        </div>
        <div>
          <span className="mono-l">Oldest case</span>
          <b>{m.summary.oldest_days_late > 0 ? `${m.summary.oldest_days_late}d late` : '—'}</b>
        </div>
      </div>
      {m.cases.length === 0 ? (
        <div className="pc-empty glass">
          No overdue rent cases. Current outstanding balances will appear here when rent is due.
        </div>
      ) : (
        <div className="rr-list">
          {m.cases.map((c) => (
            <RentCaseRow key={c.ledger_entry_id} c={c} />
          ))}
        </div>
      )}
    </section>
  );
}

/* ---- Section 5: Review Queues --------------------------------------------- */

/** Shape of a ListingReviewService::queue() summary row that this panel reads. */
interface ListingQueueRow {
  id: string | number;
  title: string;
  landlord: { name: string | null } | null;
  location: string | null;
  submitted_at: string | null;
  warning_count: number;
}

export function ReviewQueuePanel({
  data,
  user,
}: {
  data: AdminDashboard;
  user: CapabilitySubject | null | undefined;
}) {
  const navigate = useNavigate();
  const canVerifications = adminHasCapability(user, 'review_verifications');
  const canListings = adminHasCapability(user, 'moderate_listings');
  const tabs: ('verification' | 'listings')[] = [
    ...(canVerifications ? (['verification'] as const) : []),
    ...(canListings ? (['listings'] as const) : []),
  ];
  const [tab, setTab] = useState<'verification' | 'listings' | null>(tabs[0] ?? null);

  if (tabs.length === 0) return null;
  const active = tab ?? tabs[0];

  const verificationRows = data.review_queues.verification;
  const listingRows = data.review_queues.listings as unknown as ListingQueueRow[];

  return (
    <section className="wadm-section">
      <div className="wadm-section-head">
        <h2>Review queues</h2>
        <div className="ph-sub">What&rsquo;s waiting for an admin decision</div>
      </div>
      {tabs.length > 1 && (
        <div className="pc-tabs" role="tablist">
          {tabs.map((t) => (
            <button
              key={t}
              type="button"
              role="tab"
              aria-selected={active === t}
              className={`pc-tab${active === t ? ' on' : ''}`}
              onClick={() => setTab(t)}
            >
              {t === 'verification' ? 'Verification queue' : 'Listing queue'}
            </button>
          ))}
        </div>
      )}

      {active === 'verification' &&
        (verificationRows.length === 0 ? (
          <div className="pc-empty glass">No verification requests are waiting for review.</div>
        ) : (
          <div className="pc-list">
            {verificationRows.map((r) => (
              <article className="pc-row glass" key={r.id}>
                <div className="pc-row-main">
                  <span className="pc-person">
                    {r.user_name ?? 'Unknown applicant'}
                    {r.role && <span className="pc-role"> · {r.role}</span>}
                  </span>
                </div>
                <div className="pc-row-detail">
                  <span className="pc-issue">{r.document_count} document{r.document_count === 1 ? '' : 's'} submitted</span>
                  <span className="pc-age mono-l">{r.submitted_at ? timeAgo(r.submitted_at) : ''}</span>
                </div>
                <button
                  type="button"
                  className="btn btn-glass pc-action"
                  onClick={() => navigate(`/app/verifications/${r.id}`)}
                >
                  Review <span aria-hidden="true">&rarr;</span>
                </button>
              </article>
            ))}
          </div>
        ))}

      {active === 'listings' &&
        (listingRows.length === 0 ? (
          <div className="pc-empty glass">No listings are waiting for review.</div>
        ) : (
          <div className="pc-list">
            {listingRows.map((r) => (
              <article className="pc-row glass" key={r.id as string}>
                <div className="pc-row-main">
                  <span className="pc-person">{r.title as string}</span>
                  <span className="pc-property">{r.landlord?.name}</span>
                  {r.location && <span className="pc-property">{r.location as string}</span>}
                </div>
                <div className="pc-row-detail">
                  <span className="pc-issue">
                    {r.warning_count > 0 ? `${r.warning_count} item${r.warning_count === 1 ? '' : 's'} need attention` : 'Awaiting review'}
                  </span>
                  <span className="pc-age mono-l">{r.submitted_at ? timeAgo(r.submitted_at as string) : ''}</span>
                </div>
                <button
                  type="button"
                  className="btn btn-glass pc-action"
                  onClick={() => navigate(`/app/listing-review/${r.id}`)}
                >
                  Review <span aria-hidden="true">&rarr;</span>
                </button>
              </article>
            ))}
          </div>
        ))}
    </section>
  );
}

/* ---- Section 6: System Health --------------------------------------------- */

function SchedulerLine({ label, signal }: { label: string; signal: AdminDashboard['system_health']['scheduler']['rent_generation'] }) {
  return (
    <div className="sh-row">
      <span>{label}</span>
      {signal.status === 'not_tracked' || !signal.last_activity_at ? (
        <span className="mono-l">Not tracked</span>
      ) : (
        <span className="mono-l">{timeAgo(signal.last_activity_at)} (approximate, from scheduler log)</span>
      )}
    </div>
  );
}

export function SystemHealthPanel({ data }: { data: AdminDashboard }) {
  const h = data.system_health;
  const healthy = h.failed_jobs === 0 && h.failed_notifications === 0 && h.payment_failures_24h === 0;

  return (
    <section className="wadm-section">
      <div className="wadm-section-head">
        <h2>System health</h2>
        <div className="ph-sub">Is Wyncrest itself working correctly</div>
      </div>
      <div className={`sh-card glass${healthy ? ' sh-ok' : ' sh-warn'}`}>
        <div className="sh-status">
          {healthy ? 'Everything looks healthy.' : 'A few things need a look.'}
        </div>
        <div className="sh-grid">
          <div className="sh-row">
            <span>Failed background jobs</span>
            <span className="mono-l">{h.failed_jobs}</span>
          </div>
          <div className="sh-row">
            <span>Failed notifications</span>
            <span className="mono-l">{h.failed_notifications}</span>
          </div>
          <div className="sh-row">
            <span>Payment failures (24h)</span>
            <span className="mono-l">{h.payment_failures_24h}</span>
          </div>
          <SchedulerLine label="Last rent generation" signal={h.scheduler.rent_generation} />
          <SchedulerLine label="Last overdue check" signal={h.scheduler.overdue_marking} />
        </div>
      </div>
    </section>
  );
}

/* ---- Section 7: Recent Important Activity --------------------------------- */

export function RecentActivityFeed({
  data,
  user,
}: {
  data: AdminDashboard;
  user: CapabilitySubject | null | undefined;
}) {
  const navigate = useNavigate();
  if (!adminHasCapability(user, 'view_audit')) return null;

  const items = data.recent_activity;

  return (
    <section className="wadm-section">
      <div className="wadm-section-head">
        <h2>Recent important activity</h2>
        <div className="ph-sub">Human-readable — full technical detail in the audit log</div>
      </div>
      {items.length === 0 ? (
        <div className="pc-empty glass">No notable activity recorded yet.</div>
      ) : (
        <div className="ra-list glass">
          {items.map((a) => (
            <button
              key={a.id}
              type="button"
              className={`ra-row${a.severity === 'critical' ? ' blood' : a.severity === 'warning' ? ' warn' : ''}`}
              onClick={() => navigate(a.detail_route)}
            >
              <span className="ra-title">{a.title}</span>
              <span className="ra-meta mono-l">
                {a.actor} · {a.created_at ? timeAgo(a.created_at) : ''}
              </span>
            </button>
          ))}
        </div>
      )}
    </section>
  );
}
