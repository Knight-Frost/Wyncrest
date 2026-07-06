import { useState } from 'react';
import { useNavigate } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { adminApi } from '@/lib/endpoints';
import { formatCents, timeAgo } from '@/lib/format';
import { ErrorState, LoadingState, EmptyState } from '@/components/ui/states';
import { IconRefresh, IconDownload, IconLock, IconBarChart } from '@/components/ui/icons';
import type {
  AdminAnalyticsSummary,
  AdminAnalyticsResponse,
  PlatformAnalyticsRiskItem,
} from '@/lib/types';
import './admin-dashboard.css';
import './dashboard/dashboard-sections.css';
import './platform-analytics.css';

/* ============================================================================
   ADMIN ANALYTICS — the scoped, permission-aware work/risk dashboard for the
   SIGNED-IN admin. Every figure comes from GET /admin/analytics/admin-summary
   (AdminAnalyticsService), which OMITS a module section entirely when this
   admin lacks the capability that governs it. This is deliberately distinct
   from the Super Admin "Platform Analytics" page (PlatformAnalytics.tsx):
   that page is a full platform command center gated by view_analytics; this
   page answers "what needs MY attention, in the areas I'm allowed to touch."
   Reuses the same .wadm/snap-card/aq-card/pc-row/sh-card primitives so the
   two analytics surfaces (and dark mode / accent color) never disagree.
   ============================================================================ */

type RangeKey = '7d' | '30d' | '90d' | 'this_month' | 'last_month' | 'ytd';

const RANGES: { key: RangeKey; label: string }[] = [
  { key: '7d', label: '7 days' },
  { key: '30d', label: '30 days' },
  { key: '90d', label: '90 days' },
  { key: 'this_month', label: 'This month' },
  { key: 'last_month', label: 'Last month' },
  { key: 'ytd', label: 'Year to date' },
];

const num = (n: number | undefined | null) => (n ?? 0).toLocaleString('en-US');

function SectionHead({ eyebrow, title, sub }: { eyebrow: string; title: string; sub?: string }) {
  return (
    <div className="wadm-section-head">
      <div className="mono-l">{eyebrow}</div>
      <h2>{title}</h2>
      {sub && <div className="ph-sub">{sub}</div>}
    </div>
  );
}

function StatCard({
  index,
  title,
  lines,
  onClick,
}: {
  index: number;
  title: string;
  lines: React.ReactNode[];
  onClick?: () => void;
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

function sevClass(sev: string): string {
  if (sev === 'critical' || sev === 'high') return 'sev-high';
  if (sev === 'medium') return 'sev-medium';
  return 'sev-low';
}

function RiskCard({ item, index }: { item: PlatformAnalyticsRiskItem; index: number }) {
  const navigate = useNavigate();
  return (
    <button
      type="button"
      className={`aq-card glass reveal click ${sevClass(item.severity)}`}
      style={{ '--i': index } as React.CSSProperties}
      onClick={() => navigate(item.route)}
    >
      <div className="aq-top">
        <span className="aq-title">{item.area}</span>
        <span className="aq-sev">{item.severity}</span>
      </div>
      <div className="aq-headline">{item.title}</div>
      <div className="aq-detail">{item.subject}</div>
    </button>
  );
}

function QueueRow<T extends { id: string | number; route: string }>({ item, label }: { item: T; label: (i: T) => string }) {
  const navigate = useNavigate();
  return (
    <button type="button" className="pc-row glass click" onClick={() => navigate(item.route)}>
      <div className="pc-row-main">
        <span className="pc-person">{label(item)}</span>
      </div>
    </button>
  );
}

function ModuleSection({
  index,
  title,
  sub,
  stats,
  children,
}: {
  index: string;
  title: string;
  sub: string;
  stats: React.ReactNode;
  children?: React.ReactNode;
}) {
  return (
    <section className="wadm-section">
      <SectionHead eyebrow={index} title={title} sub={sub} />
      <div className="snap-grid">{stats}</div>
      {children}
    </section>
  );
}

export function AdminAnalytics() {
  const navigate = useNavigate();
  const [range, setRange] = useState<RangeKey>('30d');
  const [exporting, setExporting] = useState(false);
  const req = useApi<AdminAnalyticsResponse>(() => adminApi.adminAnalytics({ range }), [range]);

  if (req.error) {
    return (
      <div className="animate-rise">
        <div className="pa-head">
          <div>
            <div className="mono-l">Admin Console · Your Scope</div>
            <h1 className="hc-greet" style={{ fontSize: 'clamp(1.8rem, 3vw, 2.6rem)', margin: '0.4rem 0' }}>
              Admin <span className="it">Analytics</span>
            </h1>
          </div>
        </div>
        <ErrorState message={req.error.message} onRetry={req.reload} />
      </div>
    );
  }

  const a: AdminAnalyticsSummary | undefined = req.data?.analytics;

  const handleExport = async () => {
    setExporting(true);
    try {
      await adminApi.exportAdminAnalytics({ range });
    } finally {
      setExporting(false);
    }
  };

  return (
    <div className="wadm">
      <div className="wadm-bg" aria-hidden="true">
        <div className="blob b1" />
        <div className="blob b2" />
        <div className="blob b3" />
      </div>

      <div className="pa-head">
        <div>
          <div className="mono-l">Admin Console · Your Scope</div>
          <h1 className="hc-greet" style={{ fontSize: 'clamp(1.8rem, 3vw, 2.6rem)', margin: '0.4rem 0' }}>
            Admin <span className="it">Analytics</span>
          </h1>
          <div className="pa-meta mono-l">
            {a && <span>Generated {timeAgo(a.generated_at)}</span>}
            {req.data && (
              <span>
                {req.data.range.start_date} → {req.data.range.end_date}
              </span>
            )}
          </div>
        </div>
        <div className="pa-meta">
          <div className="pa-seg">
            {RANGES.map((r) => (
              <button key={r.key} type="button" className={range === r.key ? 'on' : ''} onClick={() => setRange(r.key)}>
                {r.label}
              </button>
            ))}
          </div>
          <button className="btn btn-glass" type="button" onClick={req.reload}>
            <IconRefresh size={15} /> Refresh
          </button>
          <button className="btn btn-glass" type="button" onClick={handleExport} disabled={exporting}>
            <IconDownload size={15} /> {exporting ? 'Exporting…' : 'Export'}
          </button>
        </div>
      </div>

      {req.loading && !a && <LoadingState label="Bringing your scope together…" />}

      {a && (
        <>
          {/* ---- Scope ---- */}
          <section className="wadm-section">
            <SectionHead
              eyebrow="01"
              title="Your scope"
              sub={`Signed in as ${a.admin.is_super_admin ? 'a super admin (full platform scope)' : a.admin.name}.`}
            />
            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
              <div className="pa-note">
                <IconBarChart size={15} />
                Showing analytics for your permitted areas: {a.scope.permitted_modules.join(', ')}.
              </div>
              {a.scope.restricted_modules.length > 0 && (
                <div className="pa-note">
                  <IconLock size={15} />
                  Restricted to super admins: {a.scope.restricted_modules.join(', ')}.
                </div>
              )}
            </div>
          </section>

          {/* ---- Attention needed ---- */}
          <section className="wadm-section">
            <SectionHead eyebrow="02" title="Attention needed" sub="Act on these first, ranked by urgency." />
            {a.attention.length === 0 ? (
              <EmptyState title="Nothing needs attention" description="No open risk signals across the areas in your scope." />
            ) : (
              <div className="aq-grid">
                {a.attention.map((item, i) => (
                  <RiskCard key={`${item.area}-${item.title}-${i}`} item={item} index={i} />
                ))}
              </div>
            )}
          </section>

          {/* ---- Review workload ---- */}
          <section className="wadm-section">
            <SectionHead eyebrow="03" title="Review workload" sub="How much is waiting across your permitted modules." />
            <div className="snap-grid">
              <StatCard index={0} title="Pending reviews" lines={[<>{num(a.workload.pending_total)}</>, <>across your modules</>]} />
              {Object.entries(a.workload.by_module).map(([mod, count], i) => (
                <StatCard key={mod} index={i + 1} title={mod} lines={[<>{num(count)}</>, <>pending</>]} />
              ))}
            </div>
          </section>

          {/* ---- Listings ---- */}
          {a.modules.listings && (
            <ModuleSection
              index="04"
              title="Listing review"
              sub="Approvals, rejections and the review queue."
              stats={
                <>
                  <StatCard
                    index={0}
                    title="Pending review"
                    onClick={() => navigate('/app/listing-review')}
                    lines={[<>{num(a.modules.listings.counts.pending)}</>, <>awaiting approval</>]}
                  />
                  <StatCard index={1} title="Approved" lines={[<>{num(a.modules.listings.counts.approved)}</>, <>this period</>]} />
                  <StatCard index={2} title="Rejected" lines={[<>{num(a.modules.listings.counts.rejected)}</>, <>this period</>]} />
                  <StatCard
                    index={3}
                    title="My decisions"
                    lines={[
                      <>{num(a.modules.listings.my_decisions.approved + a.modules.listings.my_decisions.rejected + a.modules.listings.my_decisions.sent_back)}</>,
                      <>
                        {a.modules.listings.my_decisions.approved} approved · {a.modules.listings.my_decisions.rejected} rejected ·{' '}
                        {a.modules.listings.my_decisions.sent_back} sent back
                      </>,
                    ]}
                  />
                </>
              }
            >
              {a.modules.listings.queue_preview.length > 0 && (
                <div className="pc-list">
                  {a.modules.listings.queue_preview.map((row) => (
                    <QueueRow key={row.id} item={row} label={(i) => `${i.title ?? 'Listing'} · ${i.landlord ?? 'Unknown landlord'}`} />
                  ))}
                </div>
              )}
            </ModuleSection>
          )}

          {/* ---- Verifications ---- */}
          {a.modules.verifications && (
            <ModuleSection
              index="05"
              title="Verification review"
              sub="Identity and document checks waiting on a decision."
              stats={
                <>
                  <StatCard
                    index={0}
                    title="Pending"
                    onClick={() => navigate('/app/verifications')}
                    lines={[
                      <>{num(a.modules.verifications.summary.pending)}</>,
                      <>
                        {a.modules.verifications.summary.pending_by_role.tenant} tenants ·{' '}
                        {a.modules.verifications.summary.pending_by_role.landlord} landlords
                      </>,
                    ]}
                  />
                  <StatCard index={1} title="Overdue (72h+)" lines={[<>{num(a.modules.verifications.timing.overdue_count)}</>]} />
                  <StatCard
                    index={2}
                    title="Avg review time"
                    lines={[<>{a.modules.verifications.timing.average_review_time_hours}h</>]}
                  />
                  <StatCard
                    index={3}
                    title="My decisions"
                    lines={[
                      <>
                        {a.modules.verifications.my_decisions.approved + a.modules.verifications.my_decisions.rejected + a.modules.verifications.my_decisions.sent_back}
                      </>,
                      <>
                        {a.modules.verifications.my_decisions.approved} approved · {a.modules.verifications.my_decisions.rejected} rejected
                      </>,
                    ]}
                  />
                </>
              }
            >
              {a.modules.verifications.queue_preview.length > 0 && (
                <div className="pc-list">
                  {a.modules.verifications.queue_preview.map((row) => (
                    <QueueRow key={row.id} item={row} label={(i) => `${i.name ?? 'Applicant'} · ${i.role ?? ''}`} />
                  ))}
                </div>
              )}
            </ModuleSection>
          )}

          {/* ---- Maintenance ---- */}
          {a.modules.maintenance && (
            <ModuleSection
              index="06"
              title="Maintenance oversight"
              sub="Open and urgent repair requests across every property."
              stats={
                <>
                  <StatCard
                    index={0}
                    title="Open requests"
                    onClick={() => navigate('/app/maintenance')}
                    lines={[<>{num(a.modules.maintenance.summary.open)}</>]}
                  />
                  <StatCard index={1} title="Urgent" lines={[<>{num(a.modules.maintenance.summary.urgent)}</>]} />
                  <StatCard index={2} title="Overdue" lines={[<>{num(a.modules.maintenance.summary.overdue)}</>]} />
                  <StatCard index={3} title="Waiting" lines={[<>{num(a.modules.maintenance.summary.waiting)}</>]} />
                </>
              }
            >
              {a.modules.maintenance.queue_preview.length > 0 && (
                <div className="pc-list">
                  {a.modules.maintenance.queue_preview.map((row) => (
                    <QueueRow key={row.id} item={row} label={(i) => `${i.title ?? 'Request'} · ${i.property ?? ''}`} />
                  ))}
                </div>
              )}
            </ModuleSection>
          )}

          {/* ---- Ledger ---- */}
          {a.modules.ledger && (
            <ModuleSection
              index="07"
              title="Ledger & finance review"
              sub="Overdue rent risk. Every figure links to its records."
              stats={
                <>
                  <StatCard
                    index={0}
                    title="Outstanding"
                    onClick={() => navigate('/app/ledger')}
                    lines={[<>{formatCents(a.modules.ledger.outstanding_cents)}</>]}
                  />
                  <StatCard index={1} title="Overdue" lines={[<>{formatCents(a.modules.ledger.overdue_cents)}</>]} />
                  <StatCard index={2} title="Overdue charges" lines={[<>{num(a.modules.ledger.overdue_count)}</>]} />
                  <StatCard index={3} title="Tenants affected" lines={[<>{num(a.modules.ledger.affected_tenants)}</>]} />
                </>
              }
            >
              {a.modules.ledger.queue_preview.length > 0 && (
                <div className="pc-list">
                  {a.modules.ledger.queue_preview.map((row) => (
                    <QueueRow
                      key={row.id}
                      item={row}
                      label={(i) => `${i.tenant ?? 'Unknown tenant'} · ${formatCents(i.amount_cents)} · ${i.days_late}d late`}
                    />
                  ))}
                </div>
              )}
            </ModuleSection>
          )}

          {/* ---- Notifications ---- */}
          {a.modules.notifications && (
            <ModuleSection
              index="08"
              title="Notification analytics"
              sub="Failed deliveries that create risk for follow-up."
              stats={
                <>
                  <StatCard
                    index={0}
                    title="Failed"
                    onClick={() => navigate('/app/notifications')}
                    lines={[<>{num(a.modules.notifications.failed_total)}</>]}
                  />
                  <StatCard index={1} title="Email failed" lines={[<>{num(a.modules.notifications.email_failed)}</>]} />
                  <StatCard index={2} title="SMS failed" lines={[<>{num(a.modules.notifications.sms_failed)}</>]} />
                </>
              }
            >
              {a.modules.notifications.recent_failures.length > 0 && (
                <div className="pc-list">
                  {a.modules.notifications.recent_failures.map((row) => (
                    <QueueRow key={row.id} item={row} label={(i) => `${i.recipient ?? 'Unknown recipient'} · ${i.type ?? ''}`} />
                  ))}
                </div>
              )}
            </ModuleSection>
          )}

          {/* ---- My performance ---- */}
          <section className="wadm-section">
            <SectionHead eyebrow="09" title="My performance" sub="Your review output. Only your own activity is shown." />
            <div className="snap-grid">
              <StatCard index={0} title="Actions this period" lines={[<>{num(a.me.actions_period)}</>]} />
              <StatCard index={1} title="Decisions made" lines={[<>{num(a.me.decisions_period)}</>]} />
              <StatCard index={2} title="Sensitive actions" lines={[<>{num(a.me.sensitive_actions_period)}</>]} />
              <StatCard index={3} title="Exports generated" lines={[<>{num(a.me.exports_period)}</>]} />
            </div>
          </section>

          {/* ---- My activity ---- */}
          <section className="wadm-section">
            <SectionHead eyebrow="10" title="My activity" sub="A log of your own actions across your permitted modules." />
            {a.me.recent_activity.length === 0 ? (
              <EmptyState title="No activity yet" description="Actions you take will appear here." />
            ) : (
              <div className="ra-list glass">
                {a.me.recent_activity.map((row) => {
                  const Tag = row.detail_route ? 'button' : 'div';
                  return (
                    <Tag
                      key={row.id}
                      type={row.detail_route ? 'button' : undefined}
                      className="ra-row"
                      onClick={row.detail_route ? () => navigate(row.detail_route!) : undefined}
                    >
                      <div>
                        <div className="ra-title">{row.title}</div>
                        <div className="pc-property">{row.area}</div>
                      </div>
                      <div className="ra-meta">{timeAgo(row.created_at)}</div>
                    </Tag>
                  );
                })}
              </div>
            )}
            {!a.scope.permitted_modules.includes('Notifications') && (
              <div className="pa-note" style={{ marginTop: '0.8rem' }}>
                <IconLock size={15} />
                The full platform audit trail is restricted to super admins.
              </div>
            )}
          </section>
        </>
      )}
    </div>
  );
}
