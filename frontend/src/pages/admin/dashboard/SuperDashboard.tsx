import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router';
import { formatCents, formatCentsCompact, timeAgo } from '@/lib/format';
import { isSuperAdmin as userIsSuperAdmin, adminHasCapability, type CapabilitySubject } from '@/lib/permissions';
import { help } from '@/lib/helpText';
import { InfoHint } from '@/components/ui/InfoHint';
import type {
  AdminDashboard,
  AdminMaintenanceCase,
  PlatformAnalyticsOverview,
  DashboardRentCase,
} from '@/lib/types';
import { BarChart, DualBarChart, LineChart, DonutChart, HBarList } from '../pa-charts';
import { whoInitials, avatarColor } from '../analytics-charts';
import '../platform-analytics.css';
import './super-dashboard.css';

/* ============================================================================
   SUPER ADMIN DASHBOARD — the platform command center, rebuilt from
   wyncrest_super_dashboard.html. Every section is fed by real backend data:
   the operations dashboard (GET /admin/dashboard) supplies the live worklists
   and system signals, and the platform analytics overview
   (GET /admin/analytics/overview) supplies the cross-domain aggregates and
   time-series. Nothing is fabricated — data points the schema genuinely can't
   support (manual payments / refunds / "duplicates prevented" / a scheduled-
   jobs table with next-run times / non-existent "support" queues, and the
   mockup's non-functional Emergency Actions) are omitted rather than faked.

   `analytics` is null for a scoped admin who lacks the `view_analytics`
   capability: analytics-heavy sections then hide, while operational sections
   (alerts, verification queue, work queues, recent activity, system health,
   and a dashboard-sourced platform-health strip) still render.
   ============================================================================ */

type RangeKey = '7d' | '30d' | '90d' | 'this_month' | 'ytd';

const RANGES: { key: RangeKey; label: string }[] = [
  { key: '7d', label: '7 days' },
  { key: '30d', label: '30 days' },
  { key: '90d', label: '90 days' },
  { key: 'this_month', label: 'This month' },
  { key: 'ytd', label: 'Year to date' },
];

/* ── formatting helpers ──────────────────────────────────────────────────── */

const num = (n: number | null | undefined) => (n ?? 0).toLocaleString('en-US');
const pct = (n: number | null | undefined) => `${(n ?? 0).toLocaleString('en-US', { maximumFractionDigits: 1 })}%`;

function monthLabel(key: string): string {
  const [y, m] = key.split('-');
  if (!y || !m) return key;
  return new Date(Number(y), Number(m) - 1, 1).toLocaleDateString('en-US', { month: 'short', year: '2-digit' });
}
function titleCase(s: string): string {
  return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}
function mergeMonthly(a: Record<string, number>, b: Record<string, number>): { label: string; a: number; b: number }[] {
  const keys = Array.from(new Set([...Object.keys(a), ...Object.keys(b)])).sort();
  return keys.map((k) => ({ label: monthLabel(k), a: a[k] ?? 0, b: b[k] ?? 0 }));
}
function monthlyValues(rows: Record<string, number>): number[] {
  return Object.keys(rows)
    .sort()
    .map((k) => rows[k]);
}

/* ── inline icons (hairline, matching the mockup) ────────────────────────── */

const IC: Record<string, React.ReactNode> = {
  alert: <path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" />,
  flame: <path d="M12 22c4 0 7-2.7 7-6.5 0-4-3-6-4-9-.4 2-1.5 3-3 4 .3-2-.5-4-2-5 0 3-3 4.5-3 8C4 18 8 22 12 22z" />,
  bell: <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0" />,
  users: <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />,
  home: <path d="M3 10.5 12 3l9 7.5M5 9v11h14V9" />,
  building: <path d="M4 21V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v16M14 21V9h4a2 2 0 0 1 2 2v10M3 21h18M8 7h2M8 11h2M8 15h2" />,
  list: <path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" />,
  contract: <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M9 13l2 2 4-4" />,
  coins: (
    <>
      <ellipse cx="8" cy="6" rx="5" ry="3" />
      <path d="M3 6v6c0 1.7 2.2 3 5 3s5-1.3 5-3V6M13 12c0 1.7 2.2 3 5 3s5-1.3 5-3-2.2-3-5-3" />
      <path d="M23 12v6c0 1.7-2.2 3-5 3s-5-1.3-5-3v-2" />
    </>
  ),
  check: <path d="M20 6 9 17l-5-5" />,
  wrench: <path d="M14.7 6.3a4 4 0 0 0-5.4 5.2L3 18v3h3l6.5-6.5a4 4 0 0 0 5.2-5.4l-2.6 2.6-2.3-.6-.6-2.3z" />,
  shield: <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />,
  key: (
    <>
      <circle cx="8" cy="15" r="4" />
      <path d="M10.8 12.2 20 3M18 5l2 2M15 8l2 2" />
    </>
  ),
  mail: (
    <>
      <rect x="2" y="4" width="20" height="16" rx="2" />
      <path d="m22 6-10 7L2 6" />
    </>
  ),
  clip: (
    <>
      <rect x="8" y="2" width="8" height="4" rx="1" />
      <path d="M9 4H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-3" />
    </>
  ),
  audit: <path d="M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" />,
  server: (
    <>
      <rect x="3" y="4" width="18" height="7" rx="2" />
      <rect x="3" y="13" width="18" height="7" rx="2" />
      <path d="M7 7.5h.01M7 16.5h.01" />
    </>
  ),
  gauge: <path d="M12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM12 14l4-4M4 20a9 9 0 1 1 16 0" />,
  refresh: <path d="M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5M21 12a9 9 0 0 1-15 6.7L3 16M3 21v-5h5" />,
  dl: <path d="M12 3v12M7 10l5 5 5-5M4 21h16" />,
  arrow: <path d="M5 12h14M13 6l6 6-6 6" />,
  search: (
    <>
      <circle cx="11" cy="11" r="7" />
      <path d="m21 21-4.3-4.3" />
    </>
  ),
  clock: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 7v5l3 2" />
    </>
  ),
  ban: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="m5.6 5.6 12.8 12.8" />
    </>
  ),
};

function Icon({ name }: { name: string }) {
  return (
    <svg className="wsvg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      {IC[name] ?? null}
    </svg>
  );
}

/* ── shared building blocks ──────────────────────────────────────────────── */

function SecHead({ ix, title, hint, linkLabel, onLink }: { ix: string; title: string; hint?: string; linkLabel?: string; onLink?: () => void }) {
  return (
    <div className="sec-h">
      <div className="ix">{ix}</div>
      <div>
        <h2>{title}</h2>
        {hint && <div className="hint">{hint}</div>}
      </div>
      <div className="spacer" />
      {linkLabel && onLink && (
        <button type="button" className="link" onClick={onLink}>
          {linkLabel} <Icon name="arrow" />
        </button>
      )}
    </div>
  );
}

function StatCard({ icon, k, value, sub, onClick, help: helpText }: { icon: string; k: string; value: React.ReactNode; sub?: React.ReactNode; onClick?: () => void; help?: string }) {
  const Tag = onClick ? 'button' : 'div';
  return (
    <Tag type={onClick ? 'button' : undefined} className="card stat" onClick={onClick}>
      <div className="k">
        <Icon name={icon} />
        {k}
        {helpText && <InfoHint text={helpText} label={`About ${k}`} className="ml-0.5" />}
      </div>
      <div className="v">{value}</div>
      {sub != null && <div className="sub">{sub}</div>}
    </Tag>
  );
}

function ChartCard({ title, unit, cap, legend, children }: { title: string; unit?: string; cap?: string; legend?: React.ReactNode; children: React.ReactNode }) {
  return (
    <div className="ch">
      <div className="ch-h">
        <div className="t">{title}</div>
        {unit && <div className="u">{unit}</div>}
      </div>
      {cap && <div className="cap">{cap}</div>}
      {children}
      {legend && <div className="legend">{legend}</div>}
    </div>
  );
}

function Pill({ tone, children }: { tone: string; children: React.ReactNode }) {
  return (
    <span className={`pill ${tone}`}>
      <span className="pd" />
      {children}
    </span>
  );
}

function WhoCell({ name, meta }: { name: string; meta?: string | null }) {
  return (
    <div className="who">
      <div className="a" style={{ background: avatarColor(name) }}>
        {whoInitials(name)}
      </div>
      <div>
        <div className="nm">{name}</div>
        {meta && <div className="m">{meta}</div>}
      </div>
    </div>
  );
}

function sevTone(sev: string): string {
  const s = sev.toLowerCase();
  if (s === 'critical' || s === 'fail' || s === 'high') return s === 'high' ? 'high' : 'crit';
  if (s === 'warning' || s === 'medium') return 'med';
  return 'low';
}

/* ── a small filterable table (chips + search + row link) ────────────────── */

interface Col<T> {
  label: string;
  num?: boolean;
  render: (r: T) => React.ReactNode;
  cls?: (r: T) => string;
}
interface Filt<T> {
  key: string;
  label: string;
  test?: (r: T) => boolean;
}
function DataTable<T>({
  title,
  columns,
  rows,
  filters,
  searchKeys,
  searchPlaceholder,
  rowLink,
  actLabel,
  getKey,
  emptyHeadline = 'Nothing here',
  emptyBody = 'No records match this filter.',
}: {
  title: string;
  columns: Col<T>[];
  rows: T[];
  filters?: Filt<T>[];
  searchKeys?: (r: T) => string;
  searchPlaceholder?: string;
  rowLink?: (r: T) => string | null;
  actLabel?: string;
  getKey: (r: T, i: number) => string;
  emptyHeadline?: string;
  emptyBody?: string;
}) {
  const navigate = useNavigate();
  const [filter, setFilter] = useState('all');
  const [q, setQ] = useState('');
  const activeFilter = filters?.find((f) => f.key === filter);
  const shown = rows.filter((r) => {
    const passF = filter === 'all' || !activeFilter?.test || activeFilter.test(r);
    const passQ = !q || !searchKeys || searchKeys(r).toLowerCase().includes(q.toLowerCase());
    return passF && passQ;
  });
  const hasAct = Boolean(rowLink || actLabel);
  return (
    <div className="tbl-wrap">
      <div className="tbl-top">
        <div className="tt">{title}</div>
        <div className="tbl-tools">
          {filters?.map((f) => (
            <button key={f.key} type="button" className={`chip${filter === f.key ? ' on' : ''}`} onClick={() => setFilter(f.key)}>
              {f.label}
            </button>
          ))}
          {searchKeys && (
            <div className="search">
              <Icon name="search" />
              <input value={q} placeholder={`Search ${searchPlaceholder ?? ''}`} onChange={(e) => setQ(e.target.value)} />
            </div>
          )}
        </div>
      </div>
      <div className="tbl-scroll">
        <table>
          <thead>
            <tr>
              {columns.map((c) => (
                <th key={c.label} className={c.num ? 'num' : undefined}>
                  {c.label}
                </th>
              ))}
              {hasAct && <th />}
            </tr>
          </thead>
          <tbody>
            {shown.length === 0 ? (
              <tr>
                <td colSpan={columns.length + (hasAct ? 1 : 0)}>
                  <div className="empty">
                    <div className="em-h">{emptyHeadline}</div>
                    {emptyBody}
                  </div>
                </td>
              </tr>
            ) : (
              shown.map((r, i) => {
                const link = rowLink?.(r) ?? null;
                return (
                  <tr key={getKey(r, i)} className={link ? 'clk' : undefined} onClick={link ? () => navigate(link) : undefined}>
                    {columns.map((c) => (
                      <td key={c.label} className={[c.num ? 'num' : '', c.cls?.(r) ?? ''].filter(Boolean).join(' ') || undefined}>
                        {c.render(r)}
                      </td>
                    ))}
                    {hasAct && (
                      <td className="num">
                        <span className="rowact">
                          {actLabel ?? 'View'} <Icon name="arrow" />
                        </span>
                      </td>
                    )}
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

/* ── listing review queue row shape (from ListingReviewService::summary) ──── */
interface ListingQueueRow {
  id: string | number;
  title: string;
  landlord?: { name: string | null } | null;
  property_name?: string | null;
  location?: string | null;
  submitted_at?: string | null;
  status_label?: string | null;
  warning_count?: number;
}

/* ============================================================================
   PAGE
   ============================================================================ */

export function SuperDashboard({
  dashboard: d,
  analytics: a,
  maintenanceRows,
  user,
  range,
  onRange,
  onRefresh,
  refreshing,
  updatedLabel,
}: {
  dashboard: AdminDashboard;
  analytics: PlatformAnalyticsOverview | null;
  maintenanceRows: AdminMaintenanceCase[];
  user: CapabilitySubject | null | undefined;
  range: RangeKey;
  onRange: (r: RangeKey) => void;
  onRefresh: () => void;
  refreshing: boolean;
  updatedLabel: string;
}) {
  const navigate = useNavigate();
  const isSuper = userIsSuperAdmin(user);
  const canAudit = adminHasCapability(user, 'view_audit');
  const canVerif = adminHasCapability(user, 'review_verifications');
  const canListings = adminHasCapability(user, 'moderate_listings');

  const q = d.attention_queue;
  const snap = d.platform_snapshot;

  /* 1. CRITICAL ALERTS — real risk items when analytics present, else derived
        from the operations attention queue (so scoped admins still get them). */
  const alerts = useMemo(() => {
    if (a) {
      return a.risk.slice(0, 6).map((r) => ({
        tone: sevTone(r.severity),
        title: r.title,
        sub: `${r.subject} · ${r.area}`,
        route: r.route,
      }));
    }
    const items: { tone: string; title: string; sub: string; route: string }[] = [];
    if (q.rent_risk.overdue_count > 0)
      items.push({
        tone: 'crit',
        title: `${q.rent_risk.overdue_count} overdue rent ${q.rent_risk.overdue_count === 1 ? 'case' : 'cases'}`,
        sub: `${formatCents(q.rent_risk.overdue_total_cents)} unpaid across ${q.rent_risk.affected_tenants} tenants`,
        route: q.rent_risk.action_route,
      });
    if (q.maintenance.overdue > 0)
      items.push({
        tone: 'crit',
        title: `${q.maintenance.overdue} maintenance ${q.maintenance.overdue === 1 ? 'request' : 'requests'} overdue`,
        sub: `${q.maintenance.urgent} urgent · past response target`,
        route: q.maintenance.action_route,
      });
    if (q.verification.pending > 0)
      items.push({
        tone: 'high',
        title: `${q.verification.pending} verification ${q.verification.pending === 1 ? 'case' : 'cases'} waiting`,
        sub: `${q.verification.pending_by_role.landlord} landlords · ${q.verification.pending_by_role.tenant} tenants`,
        route: q.verification.action_route,
      });
    if (q.notifications.failed_total > 0)
      items.push({
        tone: 'high',
        title: `${q.notifications.failed_total} failed ${q.notifications.failed_total === 1 ? 'notification' : 'notifications'}`,
        sub: `${q.notifications.critical_failed} critical notices not delivered`,
        route: q.notifications.action_route,
      });
    if (q.listings.pending > 0)
      items.push({
        tone: 'med',
        title: `${q.listings.pending} ${q.listings.pending === 1 ? 'listing' : 'listings'} awaiting review`,
        sub: 'Marketplace publishing blocked until reviewed',
        route: q.listings.action_route,
      });
    return items;
  }, [a, q])
    // Drop alerts that link to a page the current admin lacks the capability
    // to open (a view_analytics-only admin, for example, would otherwise get
    // a clickable link to a 403).
    .filter((al) => !((al.route.startsWith('/app/verifications') && !canVerif) || (al.route.startsWith('/app/listing-review') && !canListings)));

  const goApp = (route: string) => navigate(route.startsWith('/') ? route : `/${route}`);

  /* ── render ─────────────────────────────────────────────────────────────── */
  return (
    <div className="wsad">
      {/* Toolbar — the real, supported controls only. */}
      <div className="wsad-toolbar">
        <span className="updated">
          <Icon name="refresh" /> Updated <b>{updatedLabel}</b>
        </span>
        {a && (
          <div className="seg-wrap">
            <div className="seg">
              {RANGES.map((r) => (
                <button key={r.key} type="button" className={range === r.key ? 'on' : ''} onClick={() => onRange(r.key)}>
                  {r.label}
                </button>
              ))}
            </div>
            <InfoHint text={help.dateRange} label="About the date range" />
          </div>
        )}
        <button className="btn" type="button" onClick={onRefresh} disabled={refreshing}>
          <Icon name="refresh" /> {refreshing ? 'Refreshing…' : 'Refresh'}
        </button>
      </div>

      {/* 1 — CRITICAL ALERTS */}
      <section className="sec">
        <SecHead ix="01" title="Critical alerts" hint="Urgent platform-wide issues — act on these first" />
        {alerts.length === 0 ? (
          <div className="tbl-wrap">
            <div className="empty">
              <div className="em-h">All clear</div>
              No urgent platform issues right now.
            </div>
          </div>
        ) : (
          <div className="alerts">
            {alerts.map((al, i) => (
              <button key={i} type="button" className={`alert ${al.tone}`} onClick={() => goApp(al.route)}>
                <div className="dotwrap">
                  <Icon name={al.tone === 'crit' ? 'flame' : al.tone === 'high' ? 'alert' : 'bell'} />
                </div>
                <div className="body">
                  <div className="msg">{al.title}</div>
                  <div className="subtxt">{al.sub}</div>
                </div>
                <span className="go">
                  <Icon name="arrow" />
                </span>
              </button>
            ))}
          </div>
        )}
      </section>

      {/* 2 — PLATFORM HEALTH */}
      <section className="sec">
        <SecHead ix="02" title="Platform health" hint="Is the platform operating normally today?" />
        <div className="grid g4">
          <StatCard
            icon="users"
            k="Active users"
            value={num(a ? a.users.total_users : snap.users.tenants + snap.users.landlords)}
            sub={<>{num(snap.users.tenants)} tenants · {num(snap.users.landlords)} landlords</>}
            onClick={() => navigate('/app/users')}
          />
          <StatCard
            icon="home"
            k="Active landlords"
            value={num(a ? a.overview.landlords : snap.users.landlords)}
            sub={a ? <>{a.overview.verifications_pending_by_role.landlord} pending verification</> : <>{snap.users.pending_verifications} awaiting verification</>}
            onClick={() => navigate('/app/users')}
          />
          <StatCard
            icon="users"
            k="Active tenants"
            value={num(a ? a.overview.tenants : snap.users.tenants)}
            sub={<>{num(a ? a.overview.active_contracts : snap.contracts.active)} in active contracts</>}
            onClick={() => navigate('/app/users')}
          />
          <StatCard
            icon="building"
            k="Active properties"
            value={num(a ? a.overview.properties : d.properties)}
            sub={a ? <>{num(a.listings.occupancy.occupied_units)} units occupied</> : <>{num(d.units)} units</>}
          />
          <StatCard
            icon="list"
            k="Active listings"
            value={num(a ? a.overview.active_listings : snap.listings.active)}
            sub={<>{num(a ? a.overview.pending_listings : snap.listings.pending)} pending review</>}
            onClick={canListings ? () => navigate('/app/listing-review') : undefined}
          />
          <StatCard
            icon="contract"
            k="Active contracts"
            value={num(a ? a.overview.active_contracts : snap.contracts.active)}
            sub={<>{num(a ? a.overview.contracts_ending_within_30_days : snap.contracts.ending_soon)} ending within 30 days</>}
            onClick={() => navigate('/app/contracts')}
          />
          <StatCard
            icon="coins"
            k="Rent collected"
            value={formatCents(snap.rent_ledger.collected_this_month_cents)}
            sub={<>of {formatCents(snap.rent_ledger.expected_this_month_cents)} expected this month</>}
            onClick={() => navigate('/app/ledger')}
          />
          <StatCard
            icon="wrench"
            k="Open maintenance"
            value={num(a ? a.overview.open_maintenance : snap.maintenance.open)}
            sub={<>{num(a ? a.overview.maintenance_emergency : snap.maintenance.urgent)} emergency</>}
            onClick={() => navigate('/app/maintenance')}
          />
        </div>
      </section>

      {/* Analytics-backed sections (hidden for scoped admins without view_analytics) */}
      {a && (
        <>
          {/* 3 — FINANCIAL */}
          <FinancialSection a={a} navigate={navigate} />

          {/* 4 — LEDGER INTEGRITY */}
          <LedgerSection a={a} navigate={navigate} />
        </>
      )}

      {/* 5 — VERIFICATION */}
      {(canVerif || a) && <VerificationSection d={d} a={a} navigate={navigate} canVerif={canVerif} />}

      {/* 6 — ADMIN ACCESS (super admin only) */}
      {isSuper && a && <AdminAccessSection a={a} navigate={navigate} />}

      {/* 7 — USER GROWTH */}
      {a && <UserGrowthSection a={a} snap={snap} />}

      {/* 8 — MARKETPLACE */}
      {a && <MarketplaceSection d={d} a={a} navigate={navigate} canListings={canListings} />}

      {/* 9 — APPLICATIONS */}
      {a && <ApplicationsSection a={a} />}

      {/* 10 — CONTRACTS */}
      {a && <ContractsSection d={d} a={a} navigate={navigate} />}

      {/* 11 — MAINTENANCE */}
      {a && <MaintenanceSection a={a} rows={maintenanceRows} navigate={navigate} />}

      {/* 12 — NOTIFICATIONS */}
      {a && <NotificationsSection a={a} navigate={navigate} />}

      {/* 13 — AUDIT & SECURITY */}
      {a && canAudit && <AuditSection a={a} navigate={navigate} />}

      {/* 14 — SYSTEM HEALTH */}
      <SystemSection d={d} navigate={navigate} />

      {/* 15 — WORK QUEUES */}
      <WorkQueuesSection d={d} navigate={navigate} canVerif={canVerif} canListings={canListings} />

      {/* 16 — RECENT ACTIVITY */}
      {canAudit && <RecentActivitySection d={d} navigate={navigate} />}
    </div>
  );
}

/* ============================================================================
   SECTION COMPONENTS
   ============================================================================ */

type Nav = ReturnType<typeof useNavigate>;

/* 3 — FINANCIAL */
function FinancialSection({ a, navigate }: { a: PlatformAnalyticsOverview; navigate: Nav }) {
  const f = a.financial;
  const rc = a.rent_collection;
  const statusRows = [
    { label: 'On time', value: rc.on_time_count },
    { label: 'Late', value: rc.late_count },
    { label: 'Unpaid', value: rc.missed_count },
    { label: 'Waived', value: rc.waived_count },
  ].filter((r) => r.value > 0);
  return (
    <section className="sec">
      <SecHead ix="03" title="Financial overview" hint="Is money moving correctly?" linkLabel="Full ledger" onLink={() => navigate('/app/ledger')} />
      <div className="grid g4">
        <StatCard icon="coins" k="Rent billed" value={formatCents(f.rent_charged_cents)} sub={<>{num(f.entry_count)} entries this period</>} help={help.charged} />
        <StatCard icon="check" k="Rent collected" value={formatCents(f.collected_cents)} sub={<>{pct(f.collection_rate_percentage)} collection rate</>} help={help.collected} />
        <StatCard icon="flame" k="Outstanding" value={formatCents(f.outstanding_cents)} sub={<>{formatCents(f.overdue_cents)} overdue</>} onClick={() => navigate('/app/ledger')} help={help.outstandingBalance} />
        <StatCard icon="clock" k="Late fees" value={formatCents(f.fees_charged_cents)} sub="charged on late rent" help={help.lateFee} />
      </div>
      <div className="grid g2" style={{ marginTop: '0.85rem' }}>
        <ChartCard
          title="Rent billed vs collected"
          unit="by month"
          cap="Collection performance over time."
          legend={
            <>
              <span><i style={{ background: 'var(--w-mut)' }} />Billed</span>
              <span><i style={{ background: 'var(--w-green)' }} />Collected</span>
            </>
          }
        >
          <DualBarChart rows={mergeMonthly(f.billed_by_month, f.collected_by_month)} colorA="var(--w-mut)" colorB="var(--w-green)" formatValue={(v) => formatCentsCompact(v)} />
        </ChartCard>
        <ChartCard title="Payment status" cap="How current rent charges are actually paying.">
          <DonutChart rows={statusRows} totalLabel="charges" />
        </ChartCard>
      </div>
      <div className="grid g2" style={{ marginTop: '0.85rem' }}>
        <ChartCard title="Revenue trend" unit="monthly" cap="Accrued rent + fees by month.">
          <LineChart values={monthlyValues(f.revenue_by_month)} color="var(--w-green)" formatValue={(v) => formatCentsCompact(v)} />
        </ChartCard>
        <ChartCard title="Outstanding balance by age" cap="Where collections risk sits — older debt is higher risk.">
          <HBarList
            rows={f.outstanding_by_age.map((b) => ({ label: `${b.label} (${b.tenant_count} tenants)`, value: b.amount_cents, tone: 'danger' as const }))}
            formatValue={(v) => formatCents(v)}
            emptyLabel="Nothing past due."
          />
        </ChartCard>
      </div>
    </section>
  );
}

/* 4 — LEDGER INTEGRITY */
function LedgerSection({ a, navigate }: { a: PlatformAnalyticsOverview; navigate: Nav }) {
  const li = a.ledger_integrity;
  const statusTone = li.status === 'pass' ? 'ok' : li.status === 'warning' ? 'warn' : 'bad';
  return (
    <section className="sec">
      <SecHead ix="04" title="Ledger integrity" hint="Can every payment be traced?" />
      <div className="grid g3">
        <StatCard icon="gauge" k="Total ledger entries" value={num(a.financial.entry_count)} sub="financial records this period" />
        <StatCard icon="alert" k="Integrity issues" value={num(li.issue_count)} sub="need review" onClick={() => navigate('/app/ledger')} help={help.ledgerIntegrity} />
        <div className="card stat">
          <div className="k">
            <Icon name="shield" />
            Reconciliation status
          </div>
          <div className="v">
            <span className={`hstatus ${statusTone}`}>
              <span className="pd" />
              {li.status === 'pass' ? 'Balanced' : li.status === 'warning' ? 'Warnings' : 'Failing'}
            </span>
          </div>
          <div className="sub">audit-ready export available</div>
        </div>
      </div>
      {li.issues.length > 0 && (
        <div style={{ marginTop: '0.85rem' }}>
          <DataTable
            title="Ledger issues needing attention"
            rows={li.issues}
            getKey={(r, i) => `${r.code}-${i}`}
            columns={[
              { label: 'Issue', render: (r) => <b>{titleCase(r.code)}</b> },
              { label: 'Severity', render: (r) => <Pill tone={sevTone(r.severity)}>{r.severity === 'fail' ? 'Critical' : 'Warning'}</Pill> },
              { label: 'Detail', render: (r) => r.message },
              { label: 'Records', num: true, render: (r) => <span className="mono">{r.entry_ids.length + r.contract_ids.length}</span> },
            ]}
            rowLink={(r) => (r.entry_ids[0] ? `/app/ledger/${r.entry_ids[0]}` : r.contract_ids[0] ? `/app/contracts/${r.contract_ids[0]}` : '/app/ledger')}
            actLabel="Trace"
          />
        </div>
      )}
    </section>
  );
}

/* 5 — VERIFICATION */
function VerificationSection({ d, a, navigate, canVerif }: { d: AdminDashboard; a: PlatformAnalyticsOverview | null; navigate: Nav; canVerif: boolean }) {
  const rows = d.review_queues.verification;
  return (
    <section className="sec">
      <SecHead
        ix="05"
        title="Verification & compliance"
        hint="Who is verified, pending, or risky"
        linkLabel={canVerif ? 'Review queue' : undefined}
        onLink={canVerif ? () => navigate('/app/verifications') : undefined}
      />
      <div className="grid g4">
        <StatCard
          icon="users"
          k="Pending tenants"
          value={num(a ? a.verifications.pending_by_role.tenant : d.attention_queue.verification.pending_by_role.tenant)}
          sub="identity checks waiting"
          onClick={canVerif ? () => navigate('/app/verifications') : undefined}
        />
        <StatCard
          icon="home"
          k="Pending landlords"
          value={num(a ? a.verifications.pending_by_role.landlord : d.attention_queue.verification.pending_by_role.landlord)}
          sub="ownership checks waiting"
          onClick={canVerif ? () => navigate('/app/verifications') : undefined}
        />
        {a && <StatCard icon="check" k="Verified / rejected" value={`${num(a.verifications.verified)} / ${num(a.verifications.rejected)}`} sub="decided to date" />}
        <StatCard
          icon="clock"
          k="Overdue (72h+)"
          value={num(a ? a.verifications.overdue_count : 0)}
          sub="past review target"
          onClick={canVerif ? () => navigate('/app/verifications') : undefined}
          help={help.verifOverdue}
        />
      </div>
      <div style={{ marginTop: '0.85rem' }}>
        <DataTable
          title="Verification queue"
          rows={rows}
          getKey={(r) => r.id}
          searchKeys={(r) => `${r.user_name ?? ''} ${r.role ?? ''}`}
          searchPlaceholder="name or role"
          filters={[
            { key: 'all', label: 'All' },
            { key: 'tenant', label: 'Tenants', test: (r) => r.role === 'tenant' },
            { key: 'landlord', label: 'Landlords', test: (r) => r.role === 'landlord' },
          ]}
          columns={[
            { label: 'User', render: (r) => <WhoCell name={r.user_name ?? 'Unknown'} /> },
            { label: 'Role', render: (r) => <Pill tone={r.role === 'landlord' ? 'neutral' : 'info'}>{titleCase(r.role ?? '—')}</Pill> },
            { label: 'Documents', num: true, render: (r) => `${r.document_count}` },
            { label: 'Submitted', render: (r) => (r.submitted_at ? timeAgo(r.submitted_at) : '—') },
          ]}
          rowLink={canVerif ? (r) => `/app/verifications/${r.id}` : undefined}
          actLabel={canVerif ? 'Review' : undefined}
          emptyHeadline="Queue clear"
          emptyBody="No verification requests are waiting."
        />
      </div>
    </section>
  );
}

/* 6 — ADMIN ACCESS (super only) */
function AdminAccessSection({ a, navigate }: { a: PlatformAnalyticsOverview; navigate: Nav }) {
  const adm = a.users.admins;
  const act = a.admin_activity;
  return (
    <section className="sec">
      <SecHead ix="06" title="Admin access & permissions" hint="Super admin only — do admins have the right access?" linkLabel="Manage access" onLink={() => navigate('/app/manage-access')} />
      <div className="grid g4">
        <StatCard icon="users" k="Total admins" value={num(adm.total)} sub={<>{adm.super_admins} super · {Math.max(0, adm.total - adm.super_admins)} scoped</>} onClick={() => navigate('/app/manage-access')} />
        <StatCard icon="key" k="Permission changes" value={num(act.permission_changes_period)} sub="this period" onClick={() => navigate('/app/audit')} help={help.capability} />
        <StatCard icon="ban" k="Restricted attempts" value={num(act.failed_access_attempts_period)} sub="blocked access this period" onClick={() => navigate('/app/audit')} help={help.restrictedAttempts} />
        <StatCard icon="shield" k="Sensitive actions" value={num(act.sensitive_actions_period)} sub="perm & ledger changes" onClick={() => navigate('/app/audit')} help={help.auditLog} />
      </div>
      <div style={{ marginTop: '0.85rem' }}>
        <DataTable
          title="Admin accounts"
          rows={act.by_admin}
          getKey={(r) => String(r.admin_id)}
          searchKeys={(r) => r.name}
          searchPlaceholder="admin"
          filters={[
            { key: 'all', label: 'All' },
            { key: 'super', label: 'Super', test: (r) => r.is_super_admin },
            { key: 'scoped', label: 'Scoped', test: (r) => !r.is_super_admin },
          ]}
          columns={[
            { label: 'Admin', render: (r) => <WhoCell name={r.name} meta={r.is_super_admin ? 'Super admin' : 'Scoped admin'} /> },
            { label: 'Permissions', render: (r) => (r.is_super_admin ? 'All modules' : r.capabilities.length ? r.capabilities.map((c) => titleCase(c)).slice(0, 3).join(', ') + (r.capabilities.length > 3 ? '…' : '') : '—') },
            { label: 'Actions', num: true, render: (r) => `${r.actions}` },
            { label: 'Sensitive', num: true, render: (r) => `${r.sensitive_actions}` },
            { label: 'Last active', render: (r) => (r.last_active_at ? timeAgo(r.last_active_at) : '—') },
          ]}
        />
      </div>
    </section>
  );
}

/* 7 — USER GROWTH */
function UserGrowthSection({ a, snap }: { a: PlatformAnalyticsOverview; snap: AdminDashboard['platform_snapshot'] }) {
  const u = a.users;
  const breakdown = [
    { role: 'Tenants', active: u.tenants.active, total: u.tenants.total },
    { role: 'Landlords', active: u.landlords.active, total: u.landlords.total },
    { role: 'Admins', active: u.admins.active, total: u.admins.total },
  ];
  return (
    <section className="sec">
      <SecHead ix="07" title="User growth & account activity" hint="Is the platform growing and are accounts healthy?" />
      <div className="grid g4">
        <StatCard icon="users" k="New this month" value={num(a.overview.new_tenants_this_month + a.overview.new_landlords_this_month)} sub={<>{a.overview.new_tenants_this_month} tenants · {a.overview.new_landlords_this_month} landlords</>} />
        <StatCard icon="check" k="Active accounts" value={num(snap.users.active)} sub="usable, non-suspended" />
        <StatCard icon="ban" k="Suspended" value={num(snap.users.suspended)} sub="blocked from access" />
        <StatCard icon="clock" k="New this week" value={num(snap.users.new_this_week)} sub="joined in last 7 days" />
      </div>
      <div className="grid g3" style={{ marginTop: '0.85rem' }}>
        <div className="ch" style={{ gridColumn: 'span 2' }}>
          <div className="ch-h">
            <div className="t">New signups by month</div>
            <div className="u">tenants vs landlords</div>
          </div>
          <div className="cap">Who is joining the platform over time.</div>
          <DualBarChart
            rows={Object.entries(u.signups_by_month)
              .sort(([x], [y]) => x.localeCompare(y))
              .map(([m, v]) => ({ label: monthLabel(m), a: v.tenants, b: v.landlords }))}
            colorA="var(--w-petrol-2)"
            colorB="var(--w-amber)"
          />
          <div className="legend">
            <span><i style={{ background: 'var(--w-petrol-2)' }} />Tenants</span>
            <span><i style={{ background: 'var(--w-amber)' }} />Landlords</span>
          </div>
        </div>
        <div className="card pad-lg">
          <div className="k" style={{ fontSize: '0.72rem', letterSpacing: '0.05em', textTransform: 'uppercase', color: 'var(--w-mut)', fontWeight: 600, marginBottom: '0.75rem', display: 'flex', gap: '0.4rem' }}>
            By role
          </div>
          <div className="tbl-scroll">
            <table style={{ fontSize: '0.76rem' }}>
              <thead>
                <tr>
                  <th>Role</th>
                  <th className="num">Active</th>
                  <th className="num">Total</th>
                </tr>
              </thead>
              <tbody>
                {breakdown.map((b) => (
                  <tr key={b.role}>
                    <td style={{ fontWeight: 600 }}>{b.role}</td>
                    <td className="num">{num(b.active)}</td>
                    <td className="num">{num(b.total)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </section>
  );
}

/* 8 — MARKETPLACE */
/* Plain labeled stat rows — used where metrics have different units and a
   shared-scale bar list (HBarList) would make their lengths meaningless. */
function MetricRows({ rows }: { rows: { label: string; value: string }[] }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '0.65rem', marginTop: '0.25rem' }}>
      {rows.map((r) => (
        <div key={r.label} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
          <span style={{ fontSize: '0.76rem', color: 'var(--w-mut)' }}>{r.label}</span>
          <span style={{ fontSize: '0.9rem', fontWeight: 700, color: 'var(--w-ink)', fontVariantNumeric: 'tabular-nums' }}>{r.value}</span>
        </div>
      ))}
    </div>
  );
}

function MarketplaceSection({ d, a, navigate, canListings }: { d: AdminDashboard; a: PlatformAnalyticsOverview; navigate: Nav; canListings: boolean }) {
  const occ = a.listings.occupancy;
  const listingRows = d.review_queues.listings as unknown as ListingQueueRow[];
  return (
    <section className="sec">
      <SecHead ix="08" title="Marketplace overview" hint="Health of the rental marketplace" />
      <div className="grid g4">
        <StatCard icon="building" k="Total properties" value={num(a.overview.properties)} sub={<>{num(occ.total_units)} units</>} />
        <StatCard icon="list" k="Active listings" value={num(a.overview.active_listings)} sub={<>{num(a.overview.pending_listings)} pending review</>} onClick={canListings ? () => navigate('/app/listing-review') : undefined} />
        <StatCard icon="check" k="Occupancy" value={pct(occ.occupancy_rate_percentage)} sub={<>{num(occ.occupied_units)} of {num(occ.total_units)} units</>} />
        <StatCard icon="home" k="Vacant units" value={num(occ.vacant_units)} sub={<>avg {occ.average_vacancy_duration_days}d vacant</>} />
      </div>
      <div className="grid g2" style={{ marginTop: '0.85rem' }}>
        <ChartCard title="Listings by status" cap="The publishing pipeline.">
          <DonutChart rows={Object.entries(a.listings.by_status).map(([k, v]) => ({ label: titleCase(k), value: v }))} totalLabel="listings" />
        </ChartCard>
        <ChartCard title="Conversion" cap="How inventory turns into signed leases.">
          <MetricRows
            rows={[
              { label: 'Listing → contract', value: pct(a.listings.listing_to_contract_conversion_rate) },
              { label: 'Avg approval time', value: `${Math.round(a.listings.average_approval_time_hours)}h` },
            ]}
          />
        </ChartCard>
      </div>
      {canListings && (
        <div style={{ marginTop: '0.85rem' }}>
          <DataTable
            title="Listing review queue"
            rows={listingRows}
            getKey={(r) => String(r.id)}
            searchKeys={(r) => `${r.title} ${r.landlord?.name ?? ''} ${r.location ?? ''}`}
            searchPlaceholder="listing or landlord"
            columns={[
              { label: 'Listing', render: (r) => <b>{r.title}</b> },
              { label: 'Landlord', render: (r) => <WhoCell name={r.landlord?.name ?? 'Unknown'} meta={r.location ?? r.property_name ?? undefined} /> },
              { label: 'Submitted', render: (r) => (r.submitted_at ? timeAgo(r.submitted_at) : '—') },
              { label: 'Status', render: (r) => <Pill tone="med">{r.status_label ?? 'Pending'}</Pill> },
              { label: 'Attention', num: true, render: (r) => (r.warning_count ? <Pill tone="high">{r.warning_count}</Pill> : '—') },
            ]}
            rowLink={(r) => `/app/listing-review/${r.id}`}
            actLabel="Review"
            emptyHeadline="Queue clear"
            emptyBody="No listings are waiting for review."
          />
        </div>
      )}
    </section>
  );
}

/* 9 — APPLICATIONS */
function ApplicationsSection({ a }: { a: PlatformAnalyticsOverview }) {
  const ap = a.applications;
  const funnel = [
    { label: 'Submitted', n: ap.submitted_total },
    { label: 'In review', n: ap.in_review },
    { label: 'Approved', n: ap.approved },
  ];
  const max = Math.max(1, ...funnel.map((s) => s.n));
  const cols = ['var(--w-petrol)', 'var(--w-petrol-2)', 'var(--w-green)'];
  return (
    <section className="sec">
      <SecHead ix="09" title="Applications overview" hint="Tenant application flow" />
      <div className="grid g4">
        <StatCard icon="clip" k="Submitted" value={num(ap.submitted_total)} sub="this period" />
        <StatCard icon="clock" k="In review" value={num(ap.in_review)} sub={<>{ap.needs_action} need tenant action</>} />
        <StatCard icon="check" k="Approved" value={num(ap.approved)} sub={<>{pct(ap.approval_rate_percentage)} approval rate</>} />
        <StatCard icon="clock" k="Avg review time" value={`${ap.average_review_time_hours}h`} sub="submission to decision" />
      </div>
      <div className="grid g2" style={{ marginTop: '0.85rem' }}>
        <ChartCard title="Application funnel" cap="Movement from submitted to approved.">
          <div className="funnel">
            {funnel.map((s, i) => (
              <div className="frow" key={s.label}>
                <div className="flab">{s.label}</div>
                <div className="ftrack">
                  <div className="ffill" style={{ width: `${Math.max(10, (s.n / max) * 100)}%`, background: cols[i] }}>
                    {num(s.n)}
                  </div>
                </div>
              </div>
            ))}
          </div>
        </ChartCard>
        <ChartCard title="Submissions by month" cap="Applications entering the funnel.">
          <BarChart
            rows={Object.entries(ap.submissions_by_month)
              .sort(([x], [y]) => x.localeCompare(y))
              .map(([m, v]) => ({ label: monthLabel(m), value: v }))}
            color="var(--w-petrol-2)"
          />
        </ChartCard>
      </div>
    </section>
  );
}

/* 10 — CONTRACTS */
function ContractsSection({ d, a, navigate }: { d: AdminDashboard; a: PlatformAnalyticsOverview; navigate: Nav }) {
  const c = a.contracts;
  const cases: DashboardRentCase[] = d.rent_risk_monitor.cases.slice(0, 8);
  return (
    <section className="sec">
      <SecHead ix="10" title="Contracts & leases" hint="What agreements are active" linkLabel="All contracts" onLink={() => navigate('/app/contracts')} />
      <div className="grid g4">
        <StatCard icon="contract" k="Active contracts" value={num(a.overview.active_contracts)} sub="in force" onClick={() => navigate('/app/contracts')} />
        <StatCard icon="clock" k="Expiring soon" value={num(a.overview.contracts_ending_within_30_days)} sub="ending within 30 days" help={help.leasesExpiring} />
        <StatCard icon="flame" k="With balance" value={num(a.overview.tenants_with_outstanding_balance)} sub="tenants owing money" onClick={() => navigate('/app/ledger')} />
        <StatCard icon="ban" k="Terminated" value={num(c.terminated_contracts)} sub={<>{num(c.expired_contracts)} expired</>} />
      </div>
      <div className="grid g2" style={{ marginTop: '0.85rem' }}>
        <ChartCard title="Contracts by status" cap="The lease lifecycle across the platform.">
          <DonutChart rows={Object.entries(c.contracts_by_status).map(([k, v]) => ({ label: titleCase(k), value: v }))} totalLabel="contracts" />
        </ChartCard>
        <ChartCard title="Lease health" cap="Averages across every active lease.">
          <MetricRows
            rows={[
              { label: 'Avg duration', value: `${Math.round(c.average_contract_duration_days)}d` },
              { label: 'Renewal rate', value: pct(c.renewal_rate) },
              { label: 'Early termination rate', value: pct(c.early_termination_rate) },
            ]}
          />
        </ChartCard>
      </div>
      {cases.length > 0 && (
        <div style={{ marginTop: '0.85rem' }}>
          <DataTable
            title="Contracts with an outstanding balance"
            rows={cases}
            getKey={(r) => r.ledger_entry_id}
            searchKeys={(r) => `${r.tenant ?? ''} ${r.landlord ?? ''} ${r.property ?? ''}`}
            searchPlaceholder="tenant or property"
            columns={[
              { label: 'Tenant', render: (r) => <WhoCell name={r.tenant ?? 'Unknown'} meta={r.landlord ?? undefined} /> },
              { label: 'Property', render: (r) => r.property ?? '—' },
              { label: 'Balance', num: true, cls: () => 'age over', render: (r) => <span className="mono">{formatCents(r.amount_cents)}</span> },
              { label: 'Days late', num: true, render: (r) => `${r.days_late}d` },
            ]}
            rowLink={() => '/app/ledger'}
            actLabel="View"
          />
        </div>
      )}
    </section>
  );
}

/* 11 — MAINTENANCE */
function MaintenanceSection({ a, rows, navigate }: { a: PlatformAnalyticsOverview; rows: AdminMaintenanceCase[]; navigate: Nav }) {
  const m = a.maintenance;
  return (
    <section className="sec">
      <SecHead ix="11" title="Maintenance overview" hint="Platform safety and repair risk" linkLabel="Open requests" onLink={() => navigate('/app/maintenance')} />
      <div className="grid g4">
        <StatCard icon="wrench" k="Open requests" value={num(m.open)} sub="not yet closed" onClick={() => navigate('/app/maintenance')} />
        <StatCard icon="flame" k="Emergency" value={num(m.urgent)} sub="urgent / safety" />
        <StatCard icon="clock" k="Overdue" value={num(m.overdue)} sub="past response target" />
        <StatCard icon="check" k="Avg resolution" value={`${m.average_resolution_days}d`} sub="open to fixed" />
      </div>
      <div className="grid g2" style={{ marginTop: '0.85rem' }}>
        <ChartCard title="Open work by priority">
          <DonutChart rows={Object.entries(m.by_priority).map(([k, v]) => ({ label: titleCase(k), value: v }))} totalLabel="open" />
        </ChartCard>
        <ChartCard title="Open work by category">
          <HBarList rows={Object.entries(m.by_category).map(([k, v]) => ({ label: titleCase(k), value: v }))} />
        </ChartCard>
      </div>
      <div style={{ marginTop: '0.85rem' }}>
        <DataTable
          title="Maintenance requests"
          rows={rows}
          getKey={(r) => r.id}
          searchKeys={(r) => `${r.title} ${r.tenant?.name ?? ''} ${r.property ?? ''}`}
          searchPlaceholder="request or property"
          filters={[
            { key: 'all', label: 'All' },
            { key: 'emg', label: 'Emergency', test: (r) => (r.priority ?? '').toLowerCase() === 'urgent' },
            { key: 'over', label: 'Overdue', test: (r) => r.is_overdue },
          ]}
          columns={[
            { label: 'Request', render: (r) => <b>{r.title}</b> },
            { label: 'Tenant', render: (r) => <WhoCell name={r.tenant?.name ?? 'Unknown'} meta={r.property ?? undefined} /> },
            { label: 'Priority', render: (r) => <Pill tone={(r.priority ?? '').toLowerCase() === 'urgent' ? 'crit' : (r.priority ?? '').toLowerCase() === 'high' ? 'high' : 'med'}>{titleCase(r.priority ?? '—')}</Pill> },
            { label: 'Age', num: true, cls: (r) => (r.is_overdue ? 'age over' : 'age'), render: (r) => `${r.age_days}d` },
            { label: 'Status', render: (r) => <Pill tone="info">{titleCase(r.status ?? '—')}</Pill> },
          ]}
          rowLink={(r) => `/app/maintenance/${r.id}`}
          actLabel="View"
          emptyHeadline="Nothing open"
          emptyBody="No maintenance requests are currently open."
        />
      </div>
    </section>
  );
}

/* 12 — NOTIFICATIONS */
function NotificationsSection({ a, navigate }: { a: PlatformAnalyticsOverview; navigate: Nav }) {
  const del = a.notifications.delivery;
  const vol = a.notifications.volume;
  const failed = del.email_failed + del.sms_failed;
  const byDayKeys = Object.keys(vol.by_day).sort();
  const latestDay = byDayKeys.length ? vol.by_day[byDayKeys[byDayKeys.length - 1]] : 0;
  return (
    <section className="sec">
      <SecHead ix="12" title="Communication health" hint="Are emails, SMS and notices working?" linkLabel="Delivery log" onLink={() => navigate('/app/notifications')} />
      <div className="grid g4">
        <StatCard icon="bell" k="Sent (latest day)" value={num(latestDay)} sub={<>{num(vol.total_notifications)} this period</>} />
        <StatCard icon="mail" k="Email delivery" value={pct(del.email_success_rate)} sub={<>{del.email_failed} failed</>} />
        <StatCard icon="bell" k="SMS delivery" value={pct(del.sms_success_rate)} sub={<>{del.sms_failed} failed</>} />
        <StatCard icon="alert" k="Failed deliveries" value={num(failed)} sub="need follow-up" onClick={() => navigate('/app/notifications')} />
      </div>
      <div className="grid g2" style={{ marginTop: '0.85rem' }}>
        <ChartCard title="Notifications sent by day" cap="Daily communication volume this period.">
          <BarChart rows={byDayKeys.slice(-14).map((k) => ({ label: k.slice(5), value: vol.by_day[k] }))} color="var(--w-petrol-2)" />
        </ChartCard>
        <ChartCard title="Delivery outcome" cap="Successful vs failed across channels.">
          <DonutChart
            rows={[
              { label: 'Email delivered', value: del.email_delivered },
              { label: 'SMS delivered', value: del.sms_delivered },
              { label: 'Failed', value: failed },
            ].filter((r) => r.value > 0)}
            totalLabel="sent"
          />
        </ChartCard>
      </div>
    </section>
  );
}

/* 13 — AUDIT & SECURITY */
function AuditSection({ a, navigate }: { a: PlatformAnalyticsOverview; navigate: Nav }) {
  const act = a.admin_activity;
  return (
    <section className="sec">
      <SecHead ix="13" title="Audit & security activity" hint="Who did what, when, and to whom" linkLabel="Audit log" onLink={() => navigate('/app/audit')} />
      <div className="grid g4">
        <StatCard icon="audit" k="Logins (24h)" value={num(act.logins_24h)} sub="admin sessions" />
        <StatCard icon="shield" k="Sensitive actions" value={num(act.sensitive_actions_period)} sub="this period" onClick={() => navigate('/app/audit')} help={help.auditLog} />
        <StatCard icon="key" k="Permission changes" value={num(act.permission_changes_period)} sub="this period" onClick={() => navigate('/app/manage-access')} help={help.capability} />
        <StatCard icon="ban" k="Restricted attempts" value={num(act.failed_access_attempts_period)} sub="blocked access" onClick={() => navigate('/app/audit')} help={help.restrictedAttempts} />
      </div>
      <div style={{ marginTop: '0.85rem' }}>
        <DataTable
          title="Recent sensitive actions"
          rows={act.recent}
          getKey={(r, i) => `${r.action}-${i}`}
          columns={[
            { label: 'When', render: (r) => <b className="mono">{r.created_at ? timeAgo(r.created_at) : '—'}</b> },
            { label: 'Actor', render: (r) => <WhoCell name={r.admin_name} /> },
            { label: 'Action', render: (r) => r.title },
            { label: 'Area', render: (r) => <Pill tone="info">{titleCase(r.area)}</Pill> },
          ]}
          emptyHeadline="Nothing logged"
          emptyBody="No sensitive admin actions in this period."
        />
      </div>
    </section>
  );
}

/* 14 — SYSTEM HEALTH */
function SystemSection({ d, navigate }: { d: AdminDashboard; navigate: Nav }) {
  const h = d.system_health;
  const healthy = h.failed_jobs === 0 && h.failed_notifications === 0 && h.payment_failures_24h === 0;
  const sched = h.scheduler;
  return (
    <section className="sec">
      <SecHead ix="14" title="System jobs & backend health" hint="Is Wyncrest itself running properly?" />
      <div className="grid g4">
        <div className="card stat">
          <div className="k">
            <Icon name="server" />
            Overall
          </div>
          <div className="v">
            <span className={`hstatus ${healthy ? 'ok' : 'warn'}`}>
              <span className="pd" />
              {healthy ? 'Healthy' : 'Needs a look'}
            </span>
          </div>
          <div className="sub">across jobs, notices &amp; payments</div>
        </div>
        <StatCard icon="alert" k="Failed jobs" value={num(h.failed_jobs)} sub="in the queue" />
        <StatCard icon="bell" k="Failed notifications" value={num(h.failed_notifications)} sub="delivery failures" onClick={() => navigate('/app/notifications')} />
        <StatCard icon="coins" k="Payment failures" value={num(h.payment_failures_24h)} sub="last 24 hours" />
      </div>
      <div className="tbl-wrap" style={{ marginTop: '0.85rem' }}>
        <div className="tbl-scroll">
          <table>
            <thead>
              <tr>
                <th>Scheduled job</th>
                <th>Last activity</th>
                <th>Source</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><b>Rent generation</b></td>
                <td>{sched.rent_generation.last_activity_at ? timeAgo(sched.rent_generation.last_activity_at) : 'Not tracked'}</td>
                <td><span style={{ color: 'var(--w-mut)' }}>{sched.rent_generation.status === 'approximate' ? 'Scheduler log (approximate)' : 'No run log yet'}</span></td>
              </tr>
              <tr>
                <td><b>Overdue marking</b></td>
                <td>{sched.overdue_marking.last_activity_at ? timeAgo(sched.overdue_marking.last_activity_at) : 'Not tracked'}</td>
                <td><span style={{ color: 'var(--w-mut)' }}>{sched.overdue_marking.status === 'approximate' ? 'Scheduler log (approximate)' : 'No run log yet'}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div className="note">
        <Icon name="clock" />
        Scheduler timings are read from the run log and shown as approximate — the platform does not persist a guaranteed last-run timestamp.
      </div>
    </section>
  );
}

/* 15 — WORK QUEUES */
function WorkQueuesSection({
  d,
  navigate,
  canVerif,
  canListings,
}: {
  d: AdminDashboard;
  navigate: Nav;
  canVerif: boolean;
  canListings: boolean;
}) {
  const queues: { icon: string; n: number; label: string; route: string }[] = [
    ...(canVerif ? [{ icon: 'shield', n: d.attention_queue.verification.pending, label: 'Verification reviews', route: '/app/verifications' }] : []),
    ...(canListings ? [{ icon: 'list', n: d.attention_queue.listings.pending, label: 'Listing reviews', route: '/app/listing-review' }] : []),
    { icon: 'coins', n: d.attention_queue.rent_risk.overdue_count, label: 'Rent / payment issues', route: '/app/ledger' },
    { icon: 'wrench', n: d.attention_queue.maintenance.overdue, label: 'Maintenance risks', route: '/app/maintenance' },
    { icon: 'bell', n: d.attention_queue.notifications.failed_total, label: 'Delivery failures', route: '/app/notifications' },
  ].filter((qc) => qc.n > 0);

  return (
    <section className="sec">
      <SecHead ix="15" title="Work queues" hint="What needs action right now" />
      {queues.length === 0 ? (
        <div className="tbl-wrap">
          <div className="empty">
            <div className="em-h">All queues clear</div>
            Nothing is waiting for an admin decision.
          </div>
        </div>
      ) : (
        <div className="qgrid">
          {queues.map((qc) => (
            <button key={qc.label} type="button" className="qcard" onClick={() => navigate(qc.route)}>
              <div className="qic">
                <Icon name={qc.icon} />
              </div>
              <div>
                <div className="qn">{num(qc.n)}</div>
                <div className="ql">{qc.label}</div>
              </div>
              <span className="go">
                <Icon name="arrow" />
              </span>
            </button>
          ))}
        </div>
      )}
    </section>
  );
}

/* 16 — RECENT ACTIVITY */
function RecentActivitySection({ d, navigate }: { d: AdminDashboard; navigate: Nav }) {
  const items = d.recent_activity;
  const toneFor = (sev: string) => {
    const s = sev.toLowerCase();
    return s === 'critical' ? 'crit' : s === 'warning' ? 'warn' : 'info';
  };
  const iconFor = (sev: string) => {
    const s = sev.toLowerCase();
    return s === 'critical' ? 'flame' : s === 'warning' ? 'alert' : 'audit';
  };
  return (
    <section className="sec">
      <SecHead ix="16" title="Recent platform activity" hint="Meaningful events only — full detail in the audit log" />
      {items.length === 0 ? (
        <div className="tbl-wrap">
          <div className="empty">
            <div className="em-h">Nothing yet</div>
            No notable activity recorded.
          </div>
        </div>
      ) : (
        <div className="feed">
          {items.map((e) => (
            <button key={e.id} type="button" className="fr" onClick={() => navigate(e.detail_route)}>
              <div className={`fi ${toneFor(e.severity)}`}>
                <Icon name={iconFor(e.severity)} />
              </div>
              <div className="fd">
                {e.title}
                <div className="fmeta">
                  {e.actor} · {e.created_at ? timeAgo(e.created_at) : ''}
                </div>
              </div>
              <span className="fa">
                Open <Icon name="arrow" />
              </span>
            </button>
          ))}
        </div>
      )}
    </section>
  );
}
