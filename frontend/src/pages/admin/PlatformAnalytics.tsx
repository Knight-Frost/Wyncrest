import { useState } from 'react';
import { useNavigate } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { useAuth } from '@/context/auth';
import { adminApi } from '@/lib/endpoints';
import { formatCents, timeAgo } from '@/lib/format';
import { ErrorState, ForbiddenState, LoadingState, EmptyState } from '@/components/ui/states';
import {
  IconRefresh,
  IconChevronRight,
  IconAlertTriangle,
  IconFileText,
  IconDownload,
} from '@/components/ui/icons';
import { BarChart, DualBarChart, LineChart, DonutChart, HBarList } from './pa-charts';
import { help } from '@/lib/helpText';
import { InfoHint } from '@/components/ui/InfoHint';
import type {
  PlatformAnalyticsOverview,
  PlatformAnalyticsResponse,
  PlatformAnalyticsRiskItem,
  PlatformAnalyticsRentCase,
  PlatformAnalyticsByLandlord,
  PlatformAnalyticsLedgerIssue,
} from '@/lib/types';
import './platform-analytics.css';

/* ============================================================================
   PLATFORM ANALYTICS — the Super Admin cross-domain analytics page, ported
   from wyncrest-admin-analytics.html. Every figure comes straight from
   GET /admin/analytics/overview (SuperAdminAnalyticsService) — a read-only
   aggregator over the platform's existing single-source-of-truth services.
   Nothing here is invented client-side. Sections and card copy the mockup
   asked for that the schema genuinely can't support (disputed/partial
   payments, API/webhook telemetry, a fabricated export catalogue, an
   18-field decorative filter panel) are intentionally dropped rather than
   faked — see the plan this page was built from.
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
const pct = (n: number | undefined | null) => `${(n ?? 0).toLocaleString('en-US', { maximumFractionDigits: 1 })}%`;

function monthLabel(key: string): string {
  const [y, m] = key.split('-');
  if (!y || !m) return key;
  return new Date(Number(y), Number(m) - 1, 1).toLocaleDateString('en-US', { month: 'short', year: '2-digit' });
}

function titleCase(s: string): string {
  return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/** Merge two month-keyed maps into one sorted array of {label, a, b} rows. */
function mergeMonthly(a: Record<string, number>, b: Record<string, number>): { label: string; a: number; b: number }[] {
  const keys = Array.from(new Set([...Object.keys(a), ...Object.keys(b)])).sort();
  return keys.map((k) => ({ label: monthLabel(k), a: a[k] ?? 0, b: b[k] ?? 0 }));
}

function monthlySeries(rows: Record<string, number>): { labels: string[]; values: number[] } {
  const keys = Object.keys(rows).sort();
  return { labels: keys.map(monthLabel), values: keys.map((k) => rows[k]) };
}

/* ── shared building blocks ──────────────────────────────────────────────── */

function SecHead({ num: n, title, sub, linkLabel, onLink }: { num: string; title: string; sub?: string; linkLabel?: string; onLink?: () => void }) {
  return (
    <div className="pa-sec-head">
      <div className="pa-sec-num">{n}</div>
      <div>
        <h2>{title}</h2>
        {sub && <p>{sub}</p>}
      </div>
      <div className="spacer" />
      {linkLabel && onLink && (
        <button type="button" className="pa-sec-link" onClick={onLink}>
          {linkLabel} <IconChevronRight size={13} />
        </button>
      )}
    </div>
  );
}

function StatCard({
  edge,
  label,
  value,
  exp,
  onClick,
  help: helpText,
}: {
  edge: 'pet' | 'ox' | 'gr' | 'am' | 'sl';
  label: string;
  value: React.ReactNode;
  exp?: React.ReactNode;
  onClick?: () => void;
  help?: string;
}) {
  const Tag = onClick ? 'button' : 'div';
  return (
    <Tag type={onClick ? 'button' : undefined} className={`pa-stat e-${edge}`} onClick={onClick}>
      <span className="pa-edge" />
      <div className="pa-lab">
        {label}
        {helpText && <InfoHint text={helpText} label={`About ${label}`} />}
      </div>
      <div className="pa-val">{value}</div>
      {exp && <div className="pa-exp">{exp}</div>}
    </Tag>
  );
}

function ChartCard({ title, sub, legend, children }: { title: string; sub?: string; legend?: React.ReactNode; children: React.ReactNode }) {
  return (
    <div className="pa-chart-card">
      <h4>
        {title}
        {sub && <span className="pa-chart-sub">{sub}</span>}
      </h4>
      {legend && <div className="pa-legend">{legend}</div>}
      <div>{children}</div>
    </div>
  );
}

function sevClass(sev: string): string {
  return `sev-${sev.toLowerCase()}`;
}

function pillLabel(sev: string): string {
  if (sev === 'fail') return 'Critical';
  if (sev === 'warning') return 'Warning';
  return titleCase(sev);
}

function AlertCard({ item, onClick }: { item: PlatformAnalyticsRiskItem; onClick: () => void }) {
  return (
    <button type="button" className={`pa-alert glass ${sevClass(item.severity)}`} onClick={onClick}>
      <div className="pa-alert-t">
        {item.title}
        <span className={`pa-pill ${sevClass(item.severity)}`}>{pillLabel(item.severity)}</span>
      </div>
      <div className="pa-alert-n">{item.area}</div>
      <div className="pa-alert-d">{item.subject}</div>
    </button>
  );
}

function RentCaseRow({ c, onClick }: { c: PlatformAnalyticsRentCase; onClick: () => void }) {
  return (
    <tr className="click" onClick={onClick}>
      <td>
        <div className="pa-who">
          <b>{c.tenant ?? 'Unknown tenant'}</b>
          <small>{c.property ?? '—'}</small>
        </div>
      </td>
      <td>{c.landlord ?? '—'}</td>
      <td className="num">{formatCents(c.display_amount_cents)}</td>
      <td className="num">{c.days_late}d</td>
    </tr>
  );
}

function LandlordRow({ l }: { l: PlatformAnalyticsByLandlord }) {
  return (
    <div className="pa-hbar-row">
      <div className="pa-hbar-top">
        <span>{l.name}</span>
        <b>{formatCents(l.overdue_cents)} overdue</b>
      </div>
      <div className="pa-track">
        <i className="danger" style={{ width: l.outstanding_cents > 0 ? `${(l.overdue_cents / l.outstanding_cents) * 100}%` : '0%' }} />
      </div>
    </div>
  );
}

/* ── page ────────────────────────────────────────────────────────────────── */

export function PlatformAnalytics() {
  const navigate = useNavigate();
  const { user } = useAuth();
  const adminName = user && 'name' in user ? user.name : 'Admin';
  const [range, setRange] = useState<RangeKey>('30d');
  const req = useApi<PlatformAnalyticsResponse>(() => adminApi.platformAnalytics({ range }), [range]);

  if (req.error?.status === 403) {
    return (
      <div className="wpa">
        <ForbiddenState title="Analytics access required" message="Ask a super admin to grant you the 'View analytics' capability." />
      </div>
    );
  }
  if (req.error) {
    return (
      <div className="wpa">
        <ErrorState message={req.error.message} onRetry={req.reload} />
      </div>
    );
  }

  const a: PlatformAnalyticsOverview | undefined = req.data?.analytics;

  const traceIssue = (issue: PlatformAnalyticsLedgerIssue) => {
    if (issue.entry_ids.length > 0) navigate(`/app/ledger/${issue.entry_ids[0]}`);
    else if (issue.contract_ids.length > 0) navigate(`/app/contracts/${issue.contract_ids[0]}`);
    else navigate('/app/ledger');
  };

  return (
    <div className="wpa">
      {req.loading && !a && <LoadingState label="Bringing the platform analytics together…" />}

      {a && (
        <>
          {/* ---- Hero ---- */}
          <div className="pa-hero glass">
            <div>
              <div className="pa-eyebrow">Platform Command Center</div>
              <h1 className="pa-title">
                Platform <span className="it">Analytics</span>
              </h1>
              <p className="pa-sub">Monitor financial health, user activity, operational workload, compliance risk, and system-wide performance across Wyncrest.</p>
              <div className="pa-meta-row">
                <span>
                  Generated <b>{timeAgo(a.generated_at)}</b>
                </span>
                {req.data && (
                  <span>
                    Range <b>{req.data.range.start_date} → {req.data.range.end_date}</b>
                  </span>
                )}
                <span>
                  Viewing as <b>{adminName}</b>
                </span>
              </div>
            </div>
            <div className="pa-controls">
              <div className="pa-seg">
                {RANGES.map((r) => (
                  <button key={r.key} type="button" className={range === r.key ? 'on' : ''} onClick={() => setRange(r.key)}>
                    {r.label}
                  </button>
                ))}
              </div>
              <InfoHint text={help.dateRange} label="About the date range" />
              <button className="btn" type="button" onClick={req.reload}>
                <IconRefresh size={14} /> Refresh
              </button>
            </div>
          </div>

          {/* ---- 02 Platform health summary ---- */}
          <section className="pa-section">
            <SecHead num="02" title="Platform health summary" sub="Where Wyncrest stands right now. Every card explains what the number means." />
            <div className="pa-grid pa-g5">
              <StatCard
                edge="pet"
                label="Active landlords"
                value={num(a.overview.landlords)}
                onClick={() => navigate('/app/users')}
                exp={<><b>{a.overview.new_landlords_this_month}</b> joined this month. <b>{a.overview.landlords_with_overdue_balance}</b> have overdue tenant balances.</>}
              />
              <StatCard
                edge="pet"
                label="Active tenants"
                value={num(a.overview.tenants)}
                onClick={() => navigate('/app/users')}
                exp={<><b>{a.overview.new_tenants_this_month}</b> joined this month. <b>{a.overview.tenants_with_outstanding_balance}</b> carry an unpaid balance.</>}
              />
              <StatCard
                edge="sl"
                label="Active properties"
                value={num(a.overview.properties)}
                exp={<><b>{a.listings.occupancy.occupied_units}</b> units occupied. <b>{a.overview.properties_with_open_maintenance}</b> have open maintenance.</>}
              />
              <StatCard
                edge="sl"
                label="Active units"
                value={num(a.overview.units)}
                exp={<><b>{a.listings.occupancy.occupied_units}</b> under contract. <b>{a.listings.occupancy.vacant_units}</b> vacant or in turnover.</>}
              />
              <StatCard
                edge="sl"
                label="Active listings"
                value={num(a.overview.active_listings)}
                onClick={() => navigate('/app/listing-review')}
                exp={<><b>{a.overview.pending_listings}</b> pending review. <b>{a.overview.listings_needing_changes}</b> sent back for changes.</>}
              />
              <StatCard
                edge="gr"
                label="Active contracts"
                value={num(a.overview.active_contracts)}
                onClick={() => navigate('/app/contracts')}
                exp={<><b>{a.overview.contracts_starting_this_month}</b> start this month. <b>{a.overview.contracts_ending_within_30_days}</b> end within 30 days.</>}
              />
              <StatCard
                edge="am"
                label="Open applications"
                value={num(a.overview.open_applications)}
                onClick={() => navigate('/app/applicants')}
                exp={<><b>{a.applications.stale_count}</b> untouched for over {a.applications.stale_threshold_days} days.</>}
              />
              <StatCard
                edge="am"
                label="Pending verifications"
                value={num(a.overview.pending_verifications)}
                onClick={() => navigate('/app/verifications')}
                exp={<><b>{a.overview.verifications_pending_by_role.tenant}</b> tenants, <b>{a.overview.verifications_pending_by_role.landlord}</b> landlords. <b>{a.overview.verifications_overdue}</b> over 72 hours.</>}
              />
              <StatCard
                edge="ox"
                label="Open maintenance"
                value={num(a.overview.open_maintenance)}
                onClick={() => navigate('/app/maintenance')}
                exp={<><b>{a.overview.maintenance_emergency}</b> emergency. <b>{a.overview.maintenance_overdue}</b> past response target.</>}
              />
              <StatCard
                edge="ox"
                label="Total platform balance"
                value={formatCents(a.overview.outstanding_cents)}
                onClick={() => navigate('/app/ledger')}
                exp={<>Owed across <b>{a.overview.tenants_with_outstanding_balance}</b> tenants. <b>{formatCents(a.overview.overdue_cents)}</b> past due.</>}
                help={help.outstandingBalance}
              />
            </div>
          </section>

          {/* ---- 03 Attention needed ---- */}
          <section className="pa-section">
            <SecHead num="03" title="Attention needed" sub="Platform-wide problems ranked by urgency. Fix these first." />
            {a.risk.length === 0 ? (
              <EmptyState title="Nothing needs attention" description="No open risk signals across finance, verification, maintenance, applications, or communication." />
            ) : (
              <div className="pa-alerts">
                {a.risk.slice(0, 8).map((item, i) => (
                  <AlertCard key={`${item.area}-${item.title}-${i}`} item={item} onClick={() => navigate(item.route)} />
                ))}
              </div>
            )}
          </section>

          {/* ---- 04 Financial analytics ---- */}
          <section className="pa-section">
            <SecHead num="04" title="Financial analytics" sub="The financial health of the entire platform." />
            <div className="pa-grid pa-g3" style={{ marginBottom: '0.9rem' }}>
              <StatCard edge="pet" label="Rent billed" value={formatCents(a.financial.rent_charged_cents)} exp={<>{a.financial.entry_count} ledger entries this period.</>} help={help.charged} />
              <StatCard edge="gr" label="Rent collected" value={formatCents(a.financial.collected_cents)} exp={<>{pct(a.financial.collection_rate_percentage)} collection rate.</>} help={help.collected} />
              <StatCard edge="ox" label="Outstanding balance" value={formatCents(a.financial.outstanding_cents)} onClick={() => navigate('/app/ledger')} exp={<>{formatCents(a.financial.due_soon_cents)} not yet due.</>} help={help.outstandingBalance} />
              <StatCard edge="ox" label="Overdue balance" value={formatCents(a.financial.overdue_cents)} onClick={() => navigate('/app/ledger')} exp="Past the due date." help={help.overdue} />
              <StatCard edge="am" label="Late fees generated" value={formatCents(a.financial.fees_charged_cents)} exp="Charged on late rent this period." help={help.lateFee} />
              <StatCard edge="sl" label="Net collection rate" value={pct(a.financial.collection_rate_percentage)} exp={<>{formatCents(a.financial.collected_cents)} of {formatCents(a.financial.rent_charged_cents)} billed.</>} help={help.collectionRateNet} />
            </div>
            <div className="pa-two-col">
              <ChartCard title="Rent billed vs. collected" sub="6 months" legend={<><span className="pa-legend-row"><i style={{ background: 'var(--wpa-petrol-2)' }} />Billed</span><span className="pa-legend-row"><i style={{ background: 'var(--wpa-green)' }} />Collected</span></>}>
                <DualBarChart rows={mergeMonthly(a.financial.billed_by_month, a.financial.collected_by_month)} formatValue={(v) => formatCents(v)} />
              </ChartCard>
              <ChartCard title="Outstanding balance over time" sub="6 months">
                <LineChart values={monthlySeries(a.financial.outstanding_trend_by_month).values} formatValue={(v) => formatCents(v)} />
              </ChartCard>
            </div>
            <div className="pa-two-col" style={{ marginTop: '0.7rem' }}>
              <ChartCard title="Outstanding balance by age" sub={`${formatCents(a.financial.overdue_cents)} past due`}>
                <HBarList
                  rows={a.financial.outstanding_by_age.map((b) => ({ label: `${b.label} (${b.tenant_count} tenants)`, value: b.amount_cents, tone: 'danger' as const }))}
                  formatValue={(v) => formatCents(v)}
                  emptyLabel="Nothing past due."
                />
              </ChartCard>
              <ChartCard title="Outstanding by landlord" sub="Highest exposure first">
                <div className="pa-hbars">
                  {a.financial.outstanding_by_landlord.length === 0 ? (
                    <div className="pa-empty">No outstanding balances.</div>
                  ) : (
                    a.financial.outstanding_by_landlord.map((l) => <LandlordRow key={l.landlord_id} l={l} />)
                  )}
                </div>
              </ChartCard>
            </div>
          </section>

          {/* ---- 05 Ledger integrity ---- */}
          <section className="pa-section">
            <SecHead num="05" title="Ledger integrity" sub="Every money movement must trace back to a real record. These entries need review." />
            <div className={`pa-tbl-wrap`} style={{ padding: a.ledger_integrity.issues.length === 0 ? '1rem' : undefined }}>
              {a.ledger_integrity.issues.length === 0 ? (
                <EmptyState title="No integrity issues detected" description="Sign rules, duplicate charges, orphan entries, and outstanding totals all reconcile." />
              ) : (
                <table className="pa-dt">
                  <thead>
                    <tr>
                      <th>Issue</th>
                      <th>Severity</th>
                      <th>Message</th>
                      <th className="num">Records</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {a.ledger_integrity.issues.map((issue, i) => (
                      <tr key={i} className="click" onClick={() => traceIssue(issue)}>
                        <td><b>{titleCase(issue.code)}</b></td>
                        <td><span className={`pa-pill ${sevClass(issue.severity)}`}>{pillLabel(issue.severity)}</span></td>
                        <td>{issue.message}</td>
                        <td className="num">{issue.entry_ids.length + issue.contract_ids.length}</td>
                        <td><button type="button" className="pa-mini-act">Trace</button></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
            <div className="pa-note">
              <IconAlertTriangle size={14} />
              Each exception traces to its real ledger entry or contract case file — click Trace to open it.
            </div>
          </section>

          {/* ---- 06 Rent collection health ---- */}
          <section className="pa-section">
            <SecHead num="06" title="Rent collection health" sub="How tenants are actually paying. Patterns, not just individual late payments." linkLabel="Full ledger" onLink={() => navigate('/app/ledger')} />
            <div className="pa-grid pa-g4" style={{ marginBottom: '0.9rem' }}>
              <StatCard edge="gr" label="On-time rate" value={pct(a.rent_collection.on_time_rate_percentage)} exp={<>{a.rent_collection.on_time_count} charges paid on time.</>} />
              <StatCard edge="am" label="Late rate" value={pct(a.rent_collection.late_rate_percentage)} exp={<>Avg {a.rent_collection.average_days_late} days late.</>} />
              <StatCard edge="ox" label="Currently unpaid" value={num(a.rent_collection.missed_count)} exp="Past due, still unpaid." />
              <StatCard edge="sl" label="Repeat late tenants" value={num(a.rent_collection.repeat_late_tenant_count)} exp="Late more than once this period." />
            </div>
            <div className="pa-two-col">
              <div>
                <h4 style={{ fontFamily: 'var(--wpa-disp)', fontSize: '0.85rem', marginBottom: '0.5rem' }}>Top overdue cases</h4>
                {a.rent_collection.top_overdue_cases.length === 0 ? (
                  <EmptyState title="No overdue rent" description="Every tenant is current." />
                ) : (
                  <div className="pa-tbl-wrap">
                    <table className="pa-dt">
                      <thead>
                        <tr>
                          <th>Tenant</th>
                          <th>Landlord</th>
                          <th className="num">Owed</th>
                          <th className="num">Days</th>
                        </tr>
                      </thead>
                      <tbody>
                        {a.rent_collection.top_overdue_cases.map((c) => (
                          <RentCaseRow key={c.id} c={c} onClick={() => navigate('/app/ledger')} />
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
              <div>
                <h4 style={{ fontFamily: 'var(--wpa-disp)', fontSize: '0.85rem', marginBottom: '0.5rem' }}>Highest outstanding landlords</h4>
                <div className="pa-hbars">
                  {a.rent_collection.top_landlords_by_overdue.length === 0 ? (
                    <div className="pa-empty">No landlords with outstanding balances.</div>
                  ) : (
                    a.rent_collection.top_landlords_by_overdue.map((l) => <LandlordRow key={l.landlord_id} l={l} />)
                  )}
                </div>
              </div>
            </div>
          </section>

          {/* ---- 07 User analytics ---- */}
          <section className="pa-section">
            <SecHead num="07" title="User analytics" sub="Platform governance, not vanity metrics. Growth and activity across roles." />
            <div className="pa-grid pa-g3" style={{ marginBottom: '0.9rem' }}>
              <div className="pa-kv-panel">
                <h4><span className="pa-edge-dot" style={{ background: 'var(--wpa-petrol-2)' }} />Tenants</h4>
                <dl className="pa-kv">
                  <dt>Total</dt><dd>{num(a.users.tenants.total)}</dd>
                  <dt>Active</dt><dd>{num(a.users.tenants.active)}</dd>
                  <dt>New this period</dt><dd>{num(a.users.tenants.new_this_period)}</dd>
                </dl>
              </div>
              <div className="pa-kv-panel">
                <h4><span className="pa-edge-dot" style={{ background: 'var(--wpa-amber)' }} />Landlords</h4>
                <dl className="pa-kv">
                  <dt>Total</dt><dd>{num(a.users.landlords.total)}</dd>
                  <dt>Active</dt><dd>{num(a.users.landlords.active)}</dd>
                  <dt>New this period</dt><dd>{num(a.users.landlords.new_this_period)}</dd>
                </dl>
              </div>
              <div className="pa-kv-panel">
                <h4><span className="pa-edge-dot" style={{ background: 'var(--wpa-oxblood)' }} />Admins</h4>
                <dl className="pa-kv">
                  <dt>Total</dt><dd>{num(a.users.admins.total)}</dd>
                  <dt>Active</dt><dd>{num(a.users.admins.active)}</dd>
                  <dt>Super admins</dt><dd>{num(a.users.admins.super_admins)}</dd>
                </dl>
              </div>
            </div>
            <ChartCard title="New signups by month" sub="Tenants vs. landlords" legend={<><span className="pa-legend-row"><i style={{ background: 'var(--wpa-petrol-2)' }} />Tenants</span><span className="pa-legend-row"><i style={{ background: 'var(--wpa-amber)' }} />Landlords</span></>}>
              <DualBarChart rows={Object.entries(a.users.signups_by_month).sort(([x], [y]) => x.localeCompare(y)).map(([m, v]) => ({ label: monthLabel(m), a: v.tenants, b: v.landlords }))} colorB="var(--wpa-amber)" />
            </ChartCard>
          </section>

          {/* ---- 08 Property & listing analytics ---- */}
          <section className="pa-section">
            <SecHead num="08" title="Property & listing analytics" sub="How the housing inventory is performing." />
            <div className="pa-grid pa-g4" style={{ marginBottom: '0.9rem' }}>
              <StatCard edge="pet" label="Occupancy" value={pct(a.listings.occupancy.occupancy_rate_percentage)} exp={<>{a.listings.occupancy.occupied_units} of {a.listings.occupancy.total_units} units occupied.</>} />
              <StatCard edge="sl" label="Vacant units" value={num(a.listings.occupancy.vacant_units)} exp={<>Avg {a.listings.occupancy.average_vacancy_duration_days} days vacant.</>} />
              <StatCard edge="gr" label="Listing → contract" value={pct(a.listings.listing_to_contract_conversion_rate)} exp="Conversion rate." help={help.listingFunnel} />
              <StatCard edge="am" label="Avg approval time" value={`${a.listings.average_approval_time_hours}h`} exp="Submitted to published." />
            </div>
            <ChartCard title="Listings by status">
              <DonutChart rows={Object.entries(a.listings.by_status).map(([k, v]) => ({ label: titleCase(k), value: v }))} totalLabel="listings" />
            </ChartCard>
          </section>

          {/* ---- 09 Application analytics ---- */}
          <section className="pa-section">
            <SecHead num="09" title="Application analytics" sub="Tenant application movement across every landlord." linkLabel="View applications" onLink={() => navigate('/app/applicants')} />
            <div className="pa-grid pa-g4" style={{ marginBottom: '0.9rem' }}>
              <StatCard edge="pet" label="In review" value={num(a.applications.in_review)} onClick={() => navigate('/app/applicants')} exp={<>{a.applications.needs_action} need tenant action.</>} />
              <StatCard edge="gr" label="Approved / rejected" value={`${a.applications.approved} / ${a.applications.rejected}`} exp={<>{pct(a.applications.approval_rate_percentage)} approval rate.</>} />
              <StatCard edge="am" label="Stale applications" value={num(a.applications.stale_count)} onClick={() => navigate('/app/applicants')} exp={<>Untouched over {a.applications.stale_threshold_days} days.</>} />
              <StatCard edge="sl" label="Avg review time" value={`${a.applications.average_review_time_hours}h`} exp="Submission to decision." />
            </div>
            <ChartCard title="Submissions by month" sub="Applications entering the funnel">
              <BarChart rows={Object.entries(a.applications.submissions_by_month).sort(([x], [y]) => x.localeCompare(y)).map(([m, v]) => ({ label: monthLabel(m), value: v }))} />
            </ChartCard>
          </section>

          {/* ---- 10 Verification analytics ---- */}
          <section className="pa-section">
            <SecHead num="10" title="Verification analytics" sub="Identity and document review." linkLabel="Review queue" onLink={() => navigate('/app/verifications')} />
            <div className="pa-grid pa-g4">
              <StatCard edge="am" label="Pending" value={num(a.verifications.pending)} onClick={() => navigate('/app/verifications')} exp={<>{a.verifications.pending_by_role.tenant} tenants · {a.verifications.pending_by_role.landlord} landlords.</>} />
              <StatCard edge="ox" label="Overdue (72h+)" value={num(a.verifications.overdue_count)} onClick={() => navigate('/app/verifications')} />
              <StatCard edge="gr" label="Verified / rejected" value={`${a.verifications.verified} / ${a.verifications.rejected}`} />
              <StatCard edge="sl" label="Avg review time" value={`${a.verifications.average_review_time_hours}h`} />
            </div>
          </section>

          {/* ---- 11 Maintenance analytics ---- */}
          <section className="pa-section">
            <SecHead num="11" title="Maintenance analytics" sub="Platform repair risk. Catch negligent patterns before they become legal exposure." linkLabel="Open requests" onLink={() => navigate('/app/maintenance')} />
            <div className="pa-grid pa-g4" style={{ marginBottom: '0.9rem' }}>
              <StatCard edge="ox" label="Open requests" value={num(a.maintenance.open)} onClick={() => navigate('/app/maintenance')} exp={<>{a.maintenance.urgent} urgent · {a.maintenance.overdue} overdue.</>} />
              <StatCard edge="gr" label="Resolved this period" value={num(a.maintenance.resolved_count)} exp={<>Avg {a.maintenance.average_resolution_days} days to resolve.</>} />
              <StatCard edge="pet" label="Avg response time" value={`${a.maintenance.average_response_hours}h`} exp="Submitted to acknowledged." />
              <StatCard edge="sl" label="Repeat-issue properties" value={num(a.maintenance.repeat_issue_properties)} />
            </div>
            <div className="pa-two-col">
              <ChartCard title="Open work, by priority">
                <DonutChart rows={Object.entries(a.maintenance.by_priority).map(([k, v]) => ({ label: titleCase(k), value: v }))} totalLabel="open" />
              </ChartCard>
              <ChartCard title="Open work, by category">
                <HBarList rows={Object.entries(a.maintenance.by_category).map(([k, v]) => ({ label: titleCase(k), value: v }))} />
              </ChartCard>
            </div>
            <div style={{ marginTop: '0.7rem' }}>
              <ChartCard title="Average resolution time, by month" sub="Days from submitted to resolved">
                <BarChart rows={Object.entries(a.maintenance.resolution_trend_by_month).sort(([x], [y]) => x.localeCompare(y)).map(([m, v]) => ({ label: monthLabel(m), value: v }))} formatValue={(v) => `${v}d`} color="var(--wpa-green)" />
              </ChartCard>
            </div>
          </section>

          {/* ---- 12 Notification & communication analytics ---- */}
          <section className="pa-section">
            <SecHead num="12" title="Notification & communication analytics" sub="Proof that users were informed." linkLabel="Delivery log" onLink={() => navigate('/app/notifications')} />
            <div className="pa-grid pa-g4">
              <StatCard edge="gr" label="Delivered" value={num(a.notifications.delivery.email_delivered + a.notifications.delivery.sms_delivered)} exp={<>{a.notifications.delivery.email_delivered} email · {a.notifications.delivery.sms_delivered} SMS.</>} />
              <StatCard edge="ox" label="Failed" value={num(a.notifications.delivery.email_failed + a.notifications.delivery.sms_failed)} onClick={() => navigate('/app/notifications')} exp={<>{a.notifications.delivery.email_failed} email · {a.notifications.delivery.sms_failed} SMS.</>} />
              <StatCard edge="pet" label="Success rate" value={pct(a.notifications.delivery.email_success_rate)} exp={<>SMS {pct(a.notifications.delivery.sms_success_rate)}.</>} />
              <StatCard edge="sl" label="Total sent" value={num(a.notifications.volume.total_notifications)} exp={<>{a.notifications.volume.per_user_avg} per user, avg.</>} />
            </div>
          </section>

          {/* ---- 13 Admin activity & permissions ---- */}
          <section className="pa-section">
            <SecHead num="13" title="Admin activity & permissions" sub="Who did what. Connects directly to the audit log." linkLabel="Audit log" onLink={() => navigate('/app/audit')} />
            <div className="pa-grid pa-g4" style={{ marginBottom: '0.9rem' }}>
              <StatCard edge="pet" label="Logins (24h)" value={num(a.admin_activity.logins_24h)} />
              <StatCard edge="am" label="Sensitive actions" value={num(a.admin_activity.sensitive_actions_period)} onClick={() => navigate('/app/audit')} />
              <StatCard edge="sl" label="Permission changes" value={num(a.admin_activity.permission_changes_period)} onClick={() => navigate('/app/manage-access')} />
              <StatCard edge="ox" label="Failed access attempts" value={num(a.admin_activity.failed_access_attempts_period)} onClick={() => navigate('/app/audit')} />
            </div>
            <div className="pa-two-col">
              <div className="pa-kv-panel">
                <h4>Recent sensitive actions</h4>
                {a.admin_activity.recent.length === 0 ? (
                  <div className="pa-empty">No sensitive actions in this period.</div>
                ) : (
                  <div className="pa-trace">
                    {a.admin_activity.recent.map((r, i) => (
                      <div className="pa-ev" key={i}>
                        <div className="pa-tm">{timeAgo(r.created_at)} · {r.admin_name}</div>
                        <div className="pa-tx">
                          {r.title} <span className="pa-pill sev-medium">{r.area}</span>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
              <div className="pa-tbl-wrap">
                <table className="pa-dt">
                  <thead>
                    <tr>
                      <th>Admin</th>
                      <th className="num">Actions</th>
                      <th className="num">Sensitive</th>
                      <th>Last active</th>
                    </tr>
                  </thead>
                  <tbody>
                    {a.admin_activity.by_admin.map((row) => (
                      <tr key={row.admin_id}>
                        <td>
                          <div className="pa-who">
                            <b>{row.name}</b>
                            {row.is_super_admin && <small>Super admin</small>}
                          </div>
                        </td>
                        <td className="num">{row.actions}</td>
                        <td className="num">{row.sensitive_actions}</td>
                        <td>{timeAgo(row.last_active_at)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </section>

          {/* ---- 14 Risk & compliance ---- */}
          <section className="pa-section">
            <SecHead num="14" title="Risk & compliance" sub="Every risky situation across finance, verification, maintenance, applications, and communication." />
            {a.risk.length === 0 ? (
              <EmptyState title="Nothing outstanding" description="No open risk signals right now." />
            ) : (
              <div className="pa-tbl-wrap">
                <table className="pa-dt">
                  <thead>
                    <tr>
                      <th>Risk</th>
                      <th>Severity</th>
                      <th>Affected</th>
                      <th>Area</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {a.risk.map((item, i) => (
                      <tr key={i} className="click" onClick={() => navigate(item.route)}>
                        <td><b>{item.title}</b></td>
                        <td><span className={`pa-pill ${sevClass(item.severity)}`}>{pillLabel(item.severity)}</span></td>
                        <td>{item.subject}</td>
                        <td><span className="pa-pill sev-low">{item.area}</span></td>
                        <td><button type="button" className="pa-mini-act">View</button></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>

          {/* ---- 15 System health ---- */}
          <section className="pa-section">
            <SecHead num="15" title="System health" sub="Whether Wyncrest itself is running properly." />
            <div className="pa-grid pa-g3">
              <StatCard edge={a.system_health.failed_jobs > 0 ? 'am' : 'gr'} label="Failed background jobs" value={num(a.system_health.failed_jobs)} />
              <StatCard edge={a.system_health.failed_notifications > 0 ? 'am' : 'gr'} label="Failed notifications" value={num(a.system_health.failed_notifications)} onClick={() => navigate('/app/notifications')} />
              <StatCard edge={a.system_health.payment_failures_24h > 0 ? 'ox' : 'gr'} label="Payment failures (24h)" value={num(a.system_health.payment_failures_24h)} />
            </div>
          </section>

          {/* ---- 16 Reports & export history ---- */}
          <section className="pa-section">
            <SecHead num="16" title="Reports & export history" sub="Who exported what, with which filters, when. Every export is stamped and traceable." />
            {a.exports.recent_exports.length === 0 ? (
              <EmptyState
                icon={<IconFileText size={22} />}
                title="No exports yet"
                description="Ledger and admin-summary exports are generated from their own pages and appear here once run."
                action={<button className="btn" type="button" onClick={() => navigate('/app/ledger')}>Open ledger</button>}
              />
            ) : (
              <div className="pa-tbl-wrap">
                <table className="pa-dt">
                  <thead>
                    <tr>
                      <th>Report</th>
                      <th>Generated by</th>
                      <th>Data</th>
                      <th>When</th>
                    </tr>
                  </thead>
                  <tbody>
                    {a.exports.recent_exports.map((e) => (
                      <tr key={e.id} className="click" onClick={() => navigate(e.action === 'ledger_exported' ? '/app/ledger' : '/app/admin-analytics')}>
                        <td><b>{e.description ?? titleCase(e.action)}</b></td>
                        <td>{e.by}</td>
                        <td><span className="pa-pill sev-low">{e.sensitive ? 'Sensitive' : 'Standard'}</span></td>
                        <td>{timeAgo(e.created_at)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            <div className="pa-note">
              <IconDownload size={14} />
              Need the full audit trail instead? <button type="button" className="pa-sec-link" onClick={() => navigate('/app/audit')}>Open audit logs →</button>
            </div>
          </section>
        </>
      )}
    </div>
  );
}
