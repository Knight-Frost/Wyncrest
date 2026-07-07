import { useState } from 'react';
import { useNavigate } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { adminApi } from '@/lib/endpoints';
import { formatCents, timeAgo } from '@/lib/format';
import { ErrorState, LoadingState, EmptyState } from '@/components/ui/states';
import {
  IconRefresh,
  IconDownload,
  IconLock,
  IconBarChart,
  IconGrid,
  IconAlertTriangle,
  IconShield,
  IconCircleCheck,
  IconWrench,
  IconDollarSign,
  IconBell,
  IconActivity,
  IconInfo,
  IconArrowRight,
  IconCheck,
  IconFlag,
} from '@/components/ui/icons';
import { GroupedBarChart, DonutChart, ReasonBars, StackedSplitBar, FilterableTable, WhoCell } from './analytics-charts';
import { help } from '@/lib/helpText';
import { InfoHint } from '@/components/ui/InfoHint';
import type {
  AdminAnalyticsSummary,
  AdminAnalyticsResponse,
  AdminAnalyticsAttentionItem,
  AdminAnalyticsListingRow,
  AdminAnalyticsVerificationRow,
  AdminAnalyticsMaintenanceRow,
  AdminAnalyticsLedgerRow,
  AdminAnalyticsNotificationRow,
  AdminAnalyticsActivityRow,
} from '@/lib/types';
import './my-analytics.css';

/* ============================================================================
   MY ANALYTICS — faithful port of the approved wyncrest_admin.html mockup for
   the SIGNED-IN (scoped) admin. Every figure comes from GET
   /admin/analytics/admin-summary (AdminAnalyticsService), which omits a
   module section entirely when this admin lacks the capability that governs
   it. Super admins never reach this page (RequireScopedAdminOnly redirects
   them to Platform Analytics, their full-platform equivalent).

   The mockup's own hash-routed sidebar becomes an in-page tab rail here
   (Wyncrest's real global sidebar already occupies that role app-wide —
   duplicating it inside the page would be a second, competing sidebar, which
   no other page in this app does). Everything below it — cards, charts,
   tables — is a close visual port of the mockup's own `.card/.att/.tbl-wrap/
   .ch/.hb` system (my-analytics.css), not the OTHER admin dashboard's design
   language. Drill-downs link to the EXISTING dedicated pages (listing review,
   verification queue, maintenance, ledger) rather than duplicating detail
   views here.
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

function SecHead({ ix, title, hint, onLink, linkText }: { ix: string; title: string; hint?: string; onLink?: () => void; linkText?: string }) {
  return (
    <div className="sec-h">
      <div className="lt">
        {ix && <div className="ix">{ix}</div>}
        <div>
          <h2>{title}</h2>
          {hint && <div className="hint">{hint}</div>}
        </div>
      </div>
      {onLink && (
        <button type="button" className="link" onClick={onLink}>
          {linkText ?? 'View all'} <IconArrowRight size={13} />
        </button>
      )}
    </div>
  );
}

function StatCard({
  icon,
  label,
  value,
  sub,
  onClick,
  help: helpText,
}: {
  icon?: React.ReactNode;
  label: string;
  value: React.ReactNode;
  sub?: React.ReactNode;
  onClick?: () => void;
  help?: string;
}) {
  const Tag = onClick ? 'button' : 'div';
  return (
    <Tag type={onClick ? 'button' : undefined} className={`card stat${onClick ? ' click' : ''}`} onClick={onClick}>
      <div className="k">
        {icon} {label}
        {helpText && <InfoHint text={helpText} label={`About ${label}`} className="ml-0.5" />}
      </div>
      <div className="v">{value}</div>
      {sub && <div className="sub">{sub}</div>}
    </Tag>
  );
}

function severityIcon(sev: string) {
  if (sev === 'critical') return <IconFlag size={16} />;
  if (sev === 'high') return <IconAlertTriangle size={16} />;
  return <IconInfo size={16} />;
}

function AttentionCard({ item }: { item: AdminAnalyticsAttentionItem }) {
  const navigate = useNavigate();
  const sevClass = item.severity === 'critical' ? 'crit' : item.severity === 'high' ? 'high' : item.severity === 'medium' ? 'med' : 'low';
  const lead = item.subject.match(/^\d+/)?.[0];
  return (
    <button type="button" className={`card att ${sevClass}`} onClick={() => navigate(item.route)}>
      <div className="top">
        {lead && <div className="n">{lead}</div>}
        <div className="ic">{severityIcon(item.severity)}</div>
      </div>
      <div className="lab">{item.title}</div>
      <div className="desc">{item.subject}</div>
      <div className="queuebar">
        {item.age && <span>{item.age} waiting</span>}
        <span className="actpill">{item.action}</span>
      </div>
      <span className="go">
        Open <IconArrowRight size={12} />
      </span>
    </button>
  );
}

type TabKey = 'overview' | 'queue' | 'listings' | 'verifications' | 'maintenance' | 'ledger' | 'notifications' | 'performance' | 'activity' | 'reports';

export function AdminAnalytics() {
  const [range, setRange] = useState<RangeKey>('30d');
  const [exporting, setExporting] = useState(false);
  const [tab, setTab] = useState<TabKey>('overview');
  const req = useApi<AdminAnalyticsResponse>(() => adminApi.adminAnalytics({ range }), [range]);

  const handleExport = async () => {
    setExporting(true);
    try {
      await adminApi.exportAdminAnalytics({ range });
    } finally {
      setExporting(false);
    }
  };

  if (req.error) {
    return (
      <div className="wana">
        <Header range={range} setRange={setRange} onRefresh={req.reload} onExport={handleExport} exporting={exporting} meta={null} />
        <ErrorState message={req.error.message} onRetry={req.reload} />
      </div>
    );
  }

  const a: AdminAnalyticsSummary | undefined = req.data?.analytics;

  const TABS: { key: TabKey; label: string; icon: React.ReactNode; badge?: number }[] = a
    ? [
        { key: 'overview', label: 'Overview', icon: <IconGrid size={15} /> },
        { key: 'queue', label: 'My Queue', icon: <IconAlertTriangle size={15} />, badge: a.attention.length || undefined },
        ...(a.modules.listings ? [{ key: 'listings' as const, label: 'Listings', icon: <IconShield size={15} />, badge: a.modules.listings.counts.pending || undefined }] : []),
        ...(a.modules.verifications
          ? [{ key: 'verifications' as const, label: 'Verifications', icon: <IconCircleCheck size={15} />, badge: a.modules.verifications.summary.pending || undefined }]
          : []),
        { key: 'maintenance', label: 'Maintenance', icon: <IconWrench size={15} />, badge: a.modules.maintenance?.summary.overdue || undefined },
        ...(a.modules.ledger ? [{ key: 'ledger' as const, label: 'Ledger', icon: <IconDollarSign size={15} />, badge: a.modules.ledger.overdue_count || undefined }] : []),
        ...(a.modules.notifications
          ? [{ key: 'notifications' as const, label: 'Notifications', icon: <IconBell size={15} />, badge: a.modules.notifications.failed_total || undefined }]
          : []),
        { key: 'performance', label: 'My Performance', icon: <IconBarChart size={15} /> },
        { key: 'activity', label: 'My Activity', icon: <IconActivity size={15} /> },
        { key: 'reports', label: 'Reports', icon: <IconDownload size={15} /> },
      ]
    : [];

  return (
    <div className="wana">
      <Header range={range} setRange={setRange} onRefresh={req.reload} onExport={handleExport} exporting={exporting} meta={a ?? null} />

      {req.loading && !a && <LoadingState label="Bringing your scope together…" />}

      {a && (
        <>
          <div className="tabs" role="tablist">
            {TABS.map((t) => (
              <button
                key={t.key}
                type="button"
                role="tab"
                aria-selected={tab === t.key}
                className={tab === t.key ? 'on' : undefined}
                onClick={() => setTab(t.key)}
              >
                {t.icon} {t.label}
                {!!t.badge && <span className="n">{t.badge}</span>}
              </button>
            ))}
          </div>

          {tab === 'overview' && <OverviewView a={a} setTab={setTab} />}
          {tab === 'queue' && <QueueView a={a} />}
          {tab === 'listings' && a.modules.listings && <ListingsView listings={a.modules.listings} />}
          {tab === 'verifications' && a.modules.verifications && <VerificationsView v={a.modules.verifications} />}
          {tab === 'maintenance' && a.modules.maintenance && <MaintenanceView m={a.modules.maintenance} />}
          {tab === 'ledger' && a.modules.ledger && <LedgerView g={a.modules.ledger} />}
          {tab === 'notifications' && a.modules.notifications && <NotificationsView n={a.modules.notifications} />}
          {tab === 'performance' && <PerformanceView a={a} />}
          {tab === 'activity' && <ActivityView a={a} />}
          {tab === 'reports' && <ReportsView a={a} range={range} exporting={exporting} onExport={handleExport} />}
        </>
      )}
    </div>
  );
}

/* ============================================================================ Header ============================================================================ */
function Header({
  range,
  setRange,
  onRefresh,
  onExport,
  exporting,
  meta,
}: {
  range: RangeKey;
  setRange: (r: RangeKey) => void;
  onRefresh: () => void;
  onExport: () => void;
  exporting: boolean;
  meta: AdminAnalyticsSummary | null;
}) {
  return (
    <div className="hero">
      <div>
        <div className="crumb">Admin Console · Your Scope</div>
        <h1 className="title">
          My <span className="it">Analytics</span>
        </h1>
        {meta && <div className="meta-line">Generated {timeAgo(meta.generated_at)}</div>}
      </div>
      <div className="controls">
        <div className="seg">
          {RANGES.map((r) => (
            <button key={r.key} type="button" className={range === r.key ? 'on' : ''} onClick={() => setRange(r.key)}>
              {r.label}
            </button>
          ))}
        </div>
        <InfoHint text={help.dateRange} label="About the date range" />
        <button className="btn" type="button" onClick={onRefresh}>
          <IconRefresh size={15} /> Refresh
        </button>
        <button className="btn" type="button" onClick={onExport} disabled={exporting}>
          <IconDownload size={15} /> {exporting ? 'Exporting…' : 'Export'}
        </button>
      </div>
    </div>
  );
}

/* ============================================================================ Overview ============================================================================ */
function OverviewView({ a, setTab }: { a: AdminAnalyticsSummary; setTab: (t: TabKey) => void }) {
  return (
    <>
      <div className="sec">
        <SecHead ix="01" title="Your scope" hint="What this console can see and act on" />
        <div className="grid g4" style={{ marginBottom: '10px' }}>
          <div className="card stat">
            <div className="k">
              <IconGrid size={14} /> Permitted areas
            </div>
            <div className="v">{a.scope.permitted_modules.length}</div>
            <div className="sub">in your review scope</div>
          </div>
          <div className="card stat">
            <div className="k">
              <IconAlertTriangle size={14} /> Open reviews
            </div>
            <div className="v">{num(a.workload.pending_total)}</div>
            <div className="sub">in your queues</div>
          </div>
          <div className="card stat">
            <div className="k">
              <IconCheck size={14} /> My decisions
            </div>
            <div className="v">{num(a.me.decisions_period)}</div>
            <div className="sub">this period</div>
          </div>
          <div className="card stat">
            <div className="k">
              <IconLock size={14} /> Restricted areas
            </div>
            <div className="v">{a.scope.restricted_modules.length}</div>
            <div className="sub">need super-admin access</div>
          </div>
        </div>
        <div className="scope-note">
          <IconInfo size={15} />
          Showing analytics for your permitted areas: {a.scope.permitted_modules.join(', ')}.
        </div>
        {a.scope.restricted_modules.length > 0 && (
          <div className="scope-note locked" style={{ marginTop: '8px' }}>
            <IconLock size={15} />
            Restricted to super admins: {a.scope.restricted_modules.join(', ')}.
          </div>
        )}
        <div className="scope-note locked" style={{ marginTop: '8px' }}>
          <IconInfo size={15} />
          Application review isn&rsquo;t shown here — Wyncrest admins don&rsquo;t decide applications; landlords do.
        </div>
      </div>

      <div className="sec">
        <SecHead ix="02" title="Attention needed" hint="Act on these first, ordered by urgency" onLink={() => setTab('queue')} linkText="My risk queue" />
        {a.attention.length === 0 ? (
          <EmptyState title="Nothing needs attention" description="No open risk signals across the areas in your scope." />
        ) : (
          <div className="grid g4">
            {a.attention.slice(0, 8).map((item, i) => (
              <AttentionCard key={`${item.area}-${item.title}-${i}`} item={item} />
            ))}
          </div>
        )}
      </div>

      <div className="sec">
        <SecHead ix="03" title="Review workload" hint="How much is waiting and how fast it moves" />
        <div className="grid g4" style={{ marginBottom: '14px' }}>
          <div className="card stat">
            <div className="k">Pending reviews</div>
            <div className="v">{num(a.workload.pending_total)}</div>
            <div className="sub">across every queue you hold</div>
          </div>
          {a.modules.verifications && (
            <div className="card stat">
              <div className="k">Avg review time</div>
              <div className="v">{a.modules.verifications.timing.average_review_time_hours}h</div>
              <div className="sub">verifications, first touch to decision</div>
            </div>
          )}
          <div className="card stat">
            <div className="k">My decisions</div>
            <div className="v">{num(a.me.decisions_period)}</div>
            <div className="sub">this period</div>
          </div>
          <div className="card stat">
            <div className="k">Sensitive actions</div>
            <div className="v">{num(a.me.sensitive_actions_period)}</div>
            <div className="sub">this period</div>
          </div>
        </div>
        <div className="grid g3">
          <div className="ch" style={{ gridColumn: 'span 2' }}>
            <div className="ch-h">
              <div className="t">Your decisions over time</div>
              <div className="u">by week</div>
            </div>
            <div className="cap">Listings &amp; Verifications only — the record types with a comparable review clock.</div>
            {a.me.decision_trend.length === 0 ? (
              <EmptyState title="No decisions yet" description="Approve, reject, or send back an item to see your trend." />
            ) : (
              <>
                <GroupedBarChart
                  data={a.me.decision_trend.slice(-12).map((p) => ({ label: p.week.slice(5), approved: p.approved, rejected: p.rejected, sent_back: p.sent_back }))}
                  keys={['approved', 'rejected', 'sent_back']}
                  colors={['var(--green)', 'var(--oxblood)', 'var(--amber)']}
                />
                <div className="legend">
                  <span>
                    <i style={{ background: 'var(--green)' }} /> Approved
                  </span>
                  <span>
                    <i style={{ background: 'var(--oxblood)' }} /> Rejected
                  </span>
                  <span>
                    <i style={{ background: 'var(--amber)' }} /> Sent back
                  </span>
                </div>
              </>
            )}
          </div>
          <div className="ch">
            <div className="ch-h">
              <div className="t">Pending by module</div>
            </div>
            <div className="cap">Where the {num(a.workload.pending_total)} open reviews sit right now.</div>
            <StackedSplitBar items={Object.entries(a.workload.by_module).map(([label, value]) => ({ label, value }))} />
          </div>
        </div>
      </div>

      <div className="sec">
        <SecHead ix="04" title="Modules" hint="Jump into any queue" />
        <div className="grid g3">
          {a.modules.listings && (
            <ModuleSnap
              icon={<IconShield size={16} />}
              title="Listings"
              onClick={() => setTab('listings')}
              stats={[
                ['Pending', a.modules.listings.counts.pending],
                ['Approved', a.modules.listings.counts.approved],
                ['Rejected', a.modules.listings.counts.rejected],
              ]}
            />
          )}
          {a.modules.verifications && (
            <ModuleSnap
              icon={<IconCircleCheck size={16} />}
              title="Verifications"
              onClick={() => setTab('verifications')}
              stats={[
                ['Pending', a.modules.verifications.summary.pending],
                ['Overdue', a.modules.verifications.timing.overdue_count],
                ['Tenants', a.modules.verifications.summary.pending_by_role.tenant],
              ]}
            />
          )}
          {a.modules.maintenance && (
            <ModuleSnap
              icon={<IconWrench size={16} />}
              title="Maintenance"
              onClick={() => setTab('maintenance')}
              stats={[
                ['Open', a.modules.maintenance.summary.open],
                ['Urgent', a.modules.maintenance.summary.urgent],
                ['Overdue', a.modules.maintenance.summary.overdue],
              ]}
            />
          )}
          {a.modules.ledger && (
            <ModuleSnap
              icon={<IconDollarSign size={16} />}
              title="Ledger"
              onClick={() => setTab('ledger')}
              stats={[
                ['Overdue', a.modules.ledger.overdue_count],
                ['Tenants', a.modules.ledger.affected_tenants],
              ]}
            />
          )}
          {a.modules.notifications && (
            <ModuleSnap
              icon={<IconBell size={16} />}
              title="Notifications"
              onClick={() => setTab('notifications')}
              stats={[
                ['Failed email', a.modules.notifications.email_failed],
                ['Failed SMS', a.modules.notifications.sms_failed],
              ]}
            />
          )}
        </div>
      </div>
    </>
  );
}

function ModuleSnap({ icon, title, onClick, stats }: { icon: React.ReactNode; title: string; onClick: () => void; stats: [string, number][] }) {
  return (
    <button type="button" className="card" style={{ display: 'block', textAlign: 'left', width: '100%', cursor: 'pointer' }} onClick={onClick}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '9px' }}>
          <div
            style={{
              width: '30px',
              height: '30px',
              borderRadius: '8px',
              background: 'color-mix(in srgb, var(--petrol-2) 12%, transparent)',
              color: 'var(--petrol-2)',
              display: 'grid',
              placeItems: 'center',
            }}
          >
            {icon}
          </div>
          <b style={{ fontFamily: 'var(--disp)', fontSize: '17px' }}>{title}</b>
        </div>
        <IconArrowRight size={15} />
      </div>
      <div style={{ display: 'flex', gap: '18px', marginTop: '14px' }}>
        {stats.map(([label, value]) => (
          <div key={label}>
            <div style={{ fontFamily: 'var(--disp)', fontWeight: 700, fontSize: '22px', lineHeight: 1 }}>{num(value)}</div>
            <div style={{ fontSize: '11px', color: 'var(--muted)', marginTop: '3px' }}>{label}</div>
          </div>
        ))}
      </div>
    </button>
  );
}

/* ============================================================================ My Queue ============================================================================ */
type AttentionRow = AdminAnalyticsAttentionItem & { id: string };

function QueueView({ a }: { a: AdminAnalyticsSummary }) {
  const rows: AttentionRow[] = a.attention
    .map((item, i) => ({ ...item, id: `${item.area}-${item.title}-${i}` }))
    .sort((x, y) => (y.age_hours ?? 0) - (x.age_hours ?? 0));
  const crit = a.attention.filter((r) => r.severity === 'critical').length;
  const high = a.attention.filter((r) => r.severity === 'high').length;
  const oldest = rows.reduce((max, r) => Math.max(max, r.age_hours ?? 0), 0);

  return (
    <>
      <div className="sec">
        <div className="grid g4">
          <div className="card stat">
            <div className="k">Critical</div>
            <div className="v">{crit}</div>
            <div className="sub">act immediately</div>
          </div>
          <div className="card stat">
            <div className="k">High</div>
            <div className="v">{high}</div>
            <div className="sub">today</div>
          </div>
          <div className="card stat">
            <div className="k">Total items</div>
            <div className="v">{a.attention.length}</div>
            <div className="sub">in your queue</div>
          </div>
          <div className="card stat">
            <div className="k">Oldest</div>
            <div className="v">{oldest > 0 ? rows[0]?.age : '—'}</div>
            <div className="sub">longest-waiting item</div>
          </div>
        </div>
      </div>
      <div className="sec">
        <SecHead ix="" title="Prioritised list" hint="Sorted by severity and age, most urgent first" />
        <FilterableTable<AttentionRow>
          title="Risk queue"
          rows={rows}
          searchKeys={['title', 'area']}
          searchPlaceholder="risk or area"
          rowLink={(r) => r.route}
          actionLabel="Act"
          columns={[
            { key: 'risk', label: 'Risk', render: (r) => <b>{r.title}</b> },
            { key: 'area', label: 'Area', render: (r) => <span className="pill neutral">{r.area}</span> },
            {
              key: 'sev',
              label: 'Severity',
              render: (r) => <span className={`pill ${r.severity === 'critical' ? 'crit' : r.severity === 'high' ? 'high' : r.severity === 'medium' ? 'med' : 'low'}`}>{r.severity}</span>,
            },
            { key: 'age', label: 'Age', num: true, cellClassName: (r) => ((r.age_hours ?? 0) > 48 ? 'age over' : 'age'), render: (r) => r.age ?? '—' },
            { key: 'act', label: 'Action', render: (r) => <span className="pill info">{r.action}</span> },
          ]}
          filters={[
            { key: 'all', label: 'All' },
            { key: 'crit', label: 'Critical', test: (r) => r.severity === 'critical' },
            { key: 'high', label: 'High', test: (r) => r.severity === 'high' },
          ]}
        />
      </div>
    </>
  );
}

/* ============================================================================ Listings ============================================================================ */
function ListingsView({ listings }: { listings: NonNullable<AdminAnalyticsSummary['modules']['listings']> }) {
  const totalDecisions = listings.my_decisions.approved + listings.my_decisions.rejected + listings.my_decisions.sent_back;
  return (
    <>
      <div className="sec">
        <div className="grid g4">
          <StatCard label="Pending review" value={num(listings.counts.pending)} sub="awaiting approval" help={help.listingPending} />
          <StatCard label="Approved" value={num(listings.counts.approved)} sub="all time" />
          <StatCard label="Rejected" value={num(listings.counts.rejected)} sub="all time" />
          <StatCard
            label="My decisions"
            value={num(totalDecisions)}
            sub={
              <>
                {listings.my_decisions.approved} approved · {listings.my_decisions.rejected} rejected · {listings.my_decisions.sent_back} sent back
              </>
            }
          />
        </div>
      </div>
      {listings.top_reasons.length > 0 && (
        <div className="sec">
          <div className="ch">
            <div className="ch-h">
              <div className="t">Common rejection reasons</div>
            </div>
            <div className="cap">Why listings fail review, across the whole moderation team. Fixing the top reason clears the most backlog.</div>
            <ReasonBars items={listings.top_reasons} />
          </div>
        </div>
      )}
      <div className="sec">
        <SecHead ix="" title="Pending listings" hint="Top 5 oldest — open Listing Review for the full queue" />
        <FilterableTable<AdminAnalyticsListingRow>
          title="Listing review queue"
          rows={listings.queue_preview}
          searchKeys={['title', 'landlord']}
          searchPlaceholder="title or landlord"
          rowLink={(r) => r.route}
          actionLabel="Review"
          emptyDescription="No listings are waiting for review."
          columns={[
            { key: 'title', label: 'Listing', render: (r) => <WhoCell name={r.landlord ?? 'Unknown landlord'} meta={r.title ?? undefined} /> },
            { key: 'location', label: 'Location', render: (r) => r.location ?? '—' },
          ]}
        />
      </div>
    </>
  );
}

/* ============================================================================ Verifications ============================================================================ */
function VerificationsView({ v }: { v: NonNullable<AdminAnalyticsSummary['modules']['verifications']> }) {
  const totalDecisions = v.my_decisions.approved + v.my_decisions.rejected + v.my_decisions.sent_back;
  return (
    <>
      <div className="sec">
        <div className="grid g4">
          <StatCard
            label="Pending"
            value={num(v.summary.pending)}
            sub={
              <>
                {v.summary.pending_by_role.tenant} tenants · {v.summary.pending_by_role.landlord} landlords
              </>
            }
            help={help.verifPending}
          />
          <StatCard label="Overdue (72h+)" value={num(v.timing.overdue_count)} help={help.verifOverdue} />
          <StatCard label="Avg review time" value={`${v.timing.average_review_time_hours}h`} sub="per case" help={help.verifReviewTime} />
          <StatCard
            label="My decisions"
            value={num(totalDecisions)}
            sub={
              <>
                {v.my_decisions.approved} approved · {v.my_decisions.rejected} rejected
              </>
            }
          />
        </div>
      </div>
      <div className="sec">
        <div className="grid g2">
          <div className="ch">
            <div className="ch-h">
              <div className="t">Pending by role</div>
            </div>
            <div className="cap">Split of pending checks between tenants and landlords.</div>
            <DonutChart
              label="PENDING"
              segments={[
                { label: 'Tenant', value: v.summary.pending_by_role.tenant },
                { label: 'Landlord', value: v.summary.pending_by_role.landlord },
              ]}
            />
          </div>
          <div className="card pad-lg">
            <div className="mono-l">This period</div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: '11px', marginTop: '14px' }}>
              <Row label="Approved" value={v.my_decisions.approved} />
              <Row label="Rejected" value={v.my_decisions.rejected} />
              <Row label="Sent back" value={v.my_decisions.sent_back} help={help.verifNeedsInfo} />
              <Row label="Verified (all time)" value={v.summary.verified} />
              <Row label="Missing documents" value={v.summary.missing_documents} />
            </div>
          </div>
        </div>
      </div>
      <div className="sec">
        <SecHead ix="" title="Pending verifications" hint="Top 5 oldest — open Verifications for the full queue" />
        <FilterableTable<AdminAnalyticsVerificationRow>
          title="Verification queue"
          rows={v.queue_preview}
          searchKeys={['name']}
          searchPlaceholder="name"
          rowLink={(r) => r.route}
          actionLabel="Review"
          emptyDescription="No verification requests are waiting for review."
          columns={[
            { key: 'who', label: 'User', render: (r) => <WhoCell name={r.name ?? 'Unknown applicant'} /> },
            { key: 'role', label: 'Role', render: (r) => <span className={`pill ${r.role === 'tenant' ? 'info' : 'neutral'}`}>{r.role ?? '—'}</span> },
            { key: 'submitted', label: 'Submitted', render: (r) => (r.submitted_at ? timeAgo(r.submitted_at) : '—') },
          ]}
        />
      </div>
    </>
  );
}

function Row({ label, value, help: helpText }: { label: string; value: React.ReactNode; help?: string }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '12.5px' }}>
      <span style={{ color: 'var(--muted)', display: 'inline-flex', alignItems: 'center', gap: '4px' }}>
        {label}
        {helpText && <InfoHint text={helpText} label={`About ${label}`} />}
      </span>
      <b>{value}</b>
    </div>
  );
}

/* ============================================================================ Maintenance ============================================================================ */
function MaintenanceView({ m }: { m: NonNullable<AdminAnalyticsSummary['modules']['maintenance']> }) {
  const statusLabels: Record<string, string> = {
    open: 'New',
    acknowledged: 'Acknowledged',
    assigned: 'Assigned',
    in_progress: 'In progress',
    waiting: 'Awaiting parts',
  };
  return (
    <>
      <div className="sec">
        <div className="grid g4">
          <StatCard label="Open requests" value={num(m.summary.open)} sub="not yet resolved" />
          <StatCard label="Urgent" value={num(m.summary.urgent)} sub="high-priority issues" />
          <StatCard label="Overdue" value={num(m.summary.overdue)} sub="past response target" />
          <StatCard label="Waiting" value={num(m.summary.waiting)} sub="on parts or access" />
        </div>
      </div>
      {Object.keys(m.by_status).length > 0 && (
        <div className="sec">
          <div className="ch">
            <div className="ch-h">
              <div className="t">Open requests by status</div>
            </div>
            <div className="cap">Where active work sits across the platform.</div>
            <GroupedBarChart
              data={Object.entries(m.by_status).map(([status, n]) => ({ label: statusLabels[status] ?? status, n }))}
              keys={['n']}
              colors={['var(--petrol-2)']}
            />
          </div>
        </div>
      )}
      <div className="sec">
        <SecHead ix="" title="Priority cases" hint="Top 5 — open Maintenance for the full queue" />
        <FilterableTable<AdminAnalyticsMaintenanceRow>
          title="Maintenance oversight"
          rows={m.queue_preview}
          searchKeys={['title', 'property']}
          searchPlaceholder="property or issue"
          rowLink={(r) => r.route}
          actionLabel="Open"
          emptyDescription="No maintenance requests are waiting."
          columns={[
            { key: 'issue', label: 'Issue', render: (r) => <WhoCell name={r.title} meta={r.property ?? undefined} /> },
            { key: 'priority', label: 'Priority', render: (r) => <span className="pill med">{r.priority}</span> },
            { key: 'age', label: 'Waiting', num: true, cellClassName: (r) => (r.age_days >= 2 ? 'age over' : 'age'), render: (r) => `${r.age_days}d` },
          ]}
        />
      </div>
    </>
  );
}

/* ============================================================================ Ledger ============================================================================ */
function LedgerView({ g }: { g: NonNullable<AdminAnalyticsSummary['modules']['ledger']> }) {
  return (
    <>
      <div className="sec">
        <div className="grid g3">
          <div className="card pad-lg stat">
            <div className="k">
              <IconDollarSign size={14} /> Collected this period
            </div>
            <div className="v">{formatCents(g.collected_cents)}</div>
            <div className="sub">
              vs <b>{formatCents(g.charged_cents)}</b> charged
            </div>
          </div>
          <StatCard label="Outstanding" value={formatCents(g.outstanding_cents)} sub="all open balances (all time)" />
          <StatCard label="Overdue" value={formatCents(g.overdue_cents)} sub="unpaid, past due date (all time)" />
        </div>
      </div>
      <div className="sec">
        <SecHead ix="" title="Exceptions" hint="Records that need review before they become disputes" />
        <div className="grid g4">
          <StatCard label="Overdue charges" value={num(g.overdue_count)} sub="flagged for review (all time)" />
          <StatCard label="Tenants affected" value={num(g.affected_tenants)} sub="carrying an overdue balance (all time)" />
        </div>
      </div>
      <div className="sec">
        <SecHead ix="" title="Highest overdue balances" hint="Top 5 by amount — click through to its records" />
        <FilterableTable<AdminAnalyticsLedgerRow>
          title="Ledger exceptions"
          rows={g.queue_preview}
          searchKeys={['tenant']}
          searchPlaceholder="tenant name"
          rowLink={(r) => r.route}
          actionLabel="Investigate"
          emptyDescription="No overdue balances right now."
          columns={[
            { key: 'tenant', label: 'Tenant', render: (r) => <WhoCell name={r.tenant ?? 'Unknown tenant'} /> },
            { key: 'amount', label: 'Amount', num: true, render: (r) => formatCents(r.amount_cents) },
            { key: 'days', label: 'Days late', num: true, cellClassName: (r) => (r.days_late >= 30 ? 'age over' : 'age'), render: (r) => `${r.days_late}d` },
          ]}
        />
      </div>
    </>
  );
}

/* ============================================================================ Notifications ============================================================================ */
function NotificationsView({ n }: { n: NonNullable<AdminAnalyticsSummary['modules']['notifications']> }) {
  return (
    <>
      <div className="sec">
        <div className="grid g4">
          <StatCard label="Failed" value={num(n.failed_total)} sub="all channels" />
          <StatCard label="Email failed" value={num(n.email_failed)} />
          <StatCard label="SMS failed" value={num(n.sms_failed)} />
        </div>
        <div className="scope-note" style={{ marginTop: '12px' }}>
          <IconBell size={15} />
          {n.failed_total > 0 ? (
            <>
              <b style={{ margin: '0 4px' }}>{n.failed_total} failed deliveries.</b> These recipients may not have received important messages. Resend to keep the record clean.
            </>
          ) : (
            'No failed deliveries this period.'
          )}
        </div>
      </div>
      <div className="sec">
        <div className="ch">
          <div className="ch-h">
            <div className="t">Delivery by channel</div>
          </div>
          <div className="cap">Volume attempted and failures per channel, this period.</div>
          <GroupedBarChart
            data={n.channel.map((c) => ({ label: c.channel, sent: c.sent, failed: c.failed }))}
            keys={['sent', 'failed']}
            colors={['var(--petrol-2)', 'var(--oxblood)']}
          />
          <div className="legend">
            <span>
              <i style={{ background: 'var(--petrol-2)' }} /> Sent
            </span>
            <span>
              <i style={{ background: 'var(--oxblood)' }} /> Failed
            </span>
          </div>
        </div>
      </div>
      <div className="sec">
        <SecHead ix="" title="Recent failures" hint="Top 5 most recent" />
        <FilterableTable<AdminAnalyticsNotificationRow>
          title="Failed deliveries"
          rows={n.recent_failures}
          searchKeys={['recipient']}
          searchPlaceholder="recipient"
          rowLink={(r) => r.route}
          actionLabel="Open"
          emptyDescription="No failed deliveries."
          columns={[
            { key: 'recipient', label: 'Recipient', render: (r) => <WhoCell name={r.recipient ?? 'Unknown recipient'} /> },
            { key: 'type', label: 'Type', render: (r) => <span className="pill neutral">{r.type ?? '—'}</span> },
            { key: 'when', label: 'When', render: (r) => (r.occurred_at ? timeAgo(r.occurred_at) : '—') },
          ]}
        />
      </div>
    </>
  );
}

/* ============================================================================ My Performance ============================================================================ */
function PerformanceView({ a }: { a: AdminAnalyticsSummary }) {
  return (
    <>
      <div className="scope-note" style={{ marginBottom: '16px' }}>
        <IconInfo size={15} />
        This is your own performance. You cannot see other admins&rsquo; activity or the team permission matrix — those are restricted to super administrators.
      </div>
      <div className="sec">
        <div className="grid g4">
          <StatCard label="Reviews completed" value={num(a.me.decisions_period)} sub="this period" />
          <StatCard label="Sensitive actions" value={num(a.me.sensitive_actions_period)} />
          <StatCard label="Exports generated" value={num(a.me.exports_period)} />
          <StatCard
            label="Avg decision time"
            value={
              a.me.avg_decision_hours.listings !== null || a.me.avg_decision_hours.verifications !== null
                ? [
                    a.me.avg_decision_hours.listings !== null && `${a.me.avg_decision_hours.listings}h listings`,
                    a.me.avg_decision_hours.verifications !== null && `${a.me.avg_decision_hours.verifications}h verifications`,
                  ]
                    .filter(Boolean)
                    .join(' · ')
                : '—'
            }
          />
        </div>
      </div>
      <div className="sec">
        <div className="grid g3">
          <div className="ch" style={{ gridColumn: 'span 2' }}>
            <div className="ch-h">
              <div className="t">Your decisions over time</div>
              <div className="u">by week</div>
            </div>
            <div className="cap">Approved, rejected and sent-back items you handled per week.</div>
            {a.me.decision_trend.length === 0 ? (
              <EmptyState title="No decisions yet" description="Approve, reject, or send back an item to see your trend." />
            ) : (
              <>
                <GroupedBarChart
                  data={a.me.decision_trend.slice(-12).map((p) => ({ label: p.week.slice(5), approved: p.approved, rejected: p.rejected, sent_back: p.sent_back }))}
                  keys={['approved', 'rejected', 'sent_back']}
                  colors={['var(--green)', 'var(--oxblood)', 'var(--amber)']}
                />
                <div className="legend">
                  <span>
                    <i style={{ background: 'var(--green)' }} /> Approved
                  </span>
                  <span>
                    <i style={{ background: 'var(--oxblood)' }} /> Rejected
                  </span>
                  <span>
                    <i style={{ background: 'var(--amber)' }} /> Sent back
                  </span>
                </div>
              </>
            )}
          </div>
          <div className="ch">
            <div className="ch-h">
              <div className="t">Your outcome split</div>
            </div>
            <div className="cap">How your decisions resolved.</div>
            <DonutChart
              label="DECIDED"
              segments={[
                { label: 'Approved', value: a.me.outcome_totals.approved },
                { label: 'Rejected', value: a.me.outcome_totals.rejected },
                { label: 'Sent back', value: a.me.outcome_totals.sent_back },
              ]}
            />
          </div>
        </div>
      </div>
      {a.me.top_reasons.length > 0 && (
        <div className="sec">
          <div className="ch">
            <div className="ch-h">
              <div className="t">Your common send-back reasons</div>
            </div>
            <div className="cap">Why you returned items for changes.</div>
            <ReasonBars items={a.me.top_reasons} />
          </div>
        </div>
      )}
    </>
  );
}

/* ============================================================================ My Activity ============================================================================ */
function ActivityView({ a }: { a: AdminAnalyticsSummary }) {
  return (
    <>
      <div className="scope-note" style={{ marginBottom: '16px' }}>
        <IconInfo size={15} />
        You see only your own actions. The full platform audit trail, covering every admin, is restricted to super administrators.
      </div>
      <div className="sec">
        <div className="grid g4">
          <StatCard label="My actions" value={num(a.me.actions_period)} sub="this period" />
          <StatCard label="Important / needs review" value={num(a.me.sensitive_actions_period)} />
          <StatCard label="Exports" value={num(a.me.exports_period)} sub="reports you generated" />
        </div>
      </div>
      <div className="sec">
        <SecHead ix="" title="Activity log" hint="Your actions, newest first" />
        <FilterableTable<AdminAnalyticsActivityRow>
          title="My activity log"
          rows={a.me.recent_activity}
          searchKeys={['title', 'area']}
          searchPlaceholder="action or area"
          rowLink={(r) => (r.detail_route ? r.detail_route : null)}
          actionLabel="Open"
          emptyTitle="No activity yet"
          emptyDescription="Actions you take will appear here."
          columns={[
            { key: 'when', label: 'When', render: (r) => <span className="mono-l" style={{ fontSize: '11px' }}>{r.created_at ? timeAgo(r.created_at) : '—'}</span> },
            { key: 'act', label: 'Action', render: (r) => <b>{r.title}</b> },
            { key: 'area', label: 'Area', render: (r) => <span className="pill neutral">{r.area}</span> },
            {
              key: 'type',
              label: 'Type',
              render: (r) => <span className={`pill ${r.type === 'Important' ? 'crit' : r.type === 'Needs review' ? 'high' : r.type === 'Export' ? 'info' : 'neutral'}`}>{r.type}</span>,
            },
          ]}
          filters={[
            { key: 'all', label: 'All' },
            { key: 'review', label: 'Needs review', test: (r) => r.type === 'Needs review' || r.type === 'Important' },
            { key: 'exp', label: 'Exports', test: (r) => r.type === 'Export' },
          ]}
        />
        {!a.scope.permitted_modules.includes('Notifications') && (
          <div className="scope-note locked" style={{ marginTop: '0.8rem' }}>
            <IconLock size={15} />
            The full platform audit trail is restricted to super admins.
          </div>
        )}
      </div>
    </>
  );
}

/* ============================================================================ Reports ============================================================================ */
function ReportsView({ a, range, exporting, onExport }: { a: AdminAnalyticsSummary; range: RangeKey; exporting: boolean; onExport: () => void }) {
  return (
    <>
      <div className="scope-note" style={{ marginBottom: '16px' }}>
        <IconInfo size={15} />
        You can export analytics for the modules in your permission scope. Every export records who, when, the filters used, and is itself audit-logged.
      </div>
      <div className="sec">
        <SecHead ix="" title="Available export" hint="In your permission scope" />
        <div className="card" style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', flexWrap: 'wrap' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
            <div
              style={{
                width: '36px',
                height: '36px',
                borderRadius: '9px',
                background: 'color-mix(in srgb, var(--petrol-2) 10%, transparent)',
                color: 'var(--petrol-2)',
                display: 'grid',
                placeItems: 'center',
              }}
            >
              <IconDownload size={17} />
            </div>
            <div>
              <div style={{ fontWeight: 600 }}>My analytics summary ({RANGES.find((r) => r.key === range)?.label})</div>
              <div style={{ fontSize: '12px', color: 'var(--muted)' }}>Scope: {a.scope.permitted_modules.join(', ')}</div>
            </div>
          </div>
          <button className="btn" type="button" onClick={onExport} disabled={exporting}>
            <IconDownload size={15} /> {exporting ? 'Exporting…' : 'Generate CSV'}
          </button>
        </div>
      </div>
      <div className="sec">
        <div className="card pad-lg">
          <div className="mono-l" style={{ marginBottom: '12px' }}>
            Every export includes
          </div>
          <div className="grid g3">
            {['Generated by', 'Generated date', 'Permission scope', 'Attention/risk items with age', 'Decision outcome totals', 'Common rejection reasons'].map((x) => (
              <div key={x} style={{ display: 'flex', alignItems: 'center', gap: '8px', fontSize: '13px' }}>
                <IconCheck size={14} /> {x}
              </div>
            ))}
          </div>
        </div>
      </div>
    </>
  );
}
