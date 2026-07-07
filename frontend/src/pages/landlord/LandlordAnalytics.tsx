/*
 * Landlord Portfolio Analytics — faithful rebuild of
 * wyncrest-landlord-analytics.html, backed end-to-end by
 * App\Services\LandlordAnalyticsService (real, portfolio-wide data — never a
 * single "first property" like the older generic analytics endpoints).
 *
 * Honesty notes vs. the mockup:
 *  - The mockup itself labelled "Views"/"Saved"/funnel steps as illustrative
 *    front-end stand-ins. In this app they are NOT stand-ins: Listing.view_count,
 *    the saved_listings pivot, and Application rows (draft-or-later) are all
 *    real tracked columns, so the full funnel below is real.
 *  - Delta pills only appear where the backend actually computed a previous-
 *    period figure (Collected) rather than being shown on every card.
 *  - The mockup's per-property "Analytics" drill-down duplicates the existing,
 *    richer Property Detail page (`/app/properties/:id`), so property bars/
 *    rows link there instead of a second, overlapping analytics view.
 *  - Export is a real, audited, SHA-256-checksummed CSV (matches every other
 *    export in the app) — the mockup's client-side PDF "certificate" with a
 *    fake checksum doesn't exist here; there's no PDF library and a
 *    client-computed checksum of an unsigned id would be integrity theatre.
 *  - The property filter is enforced server-side (LandlordAnalyticsService
 *    scopes every section by landlord_id + property_id together), not a
 *    client-side re-filter of an already-fetched portfolio-wide payload.
 */
import { useState } from 'react';
import { useNavigate } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { landlordApi } from '@/lib/endpoints';
import type { PortfolioAnalytics } from '@/lib/endpoints';
import type { Property } from '@/lib/types';
import { formatCents, formatCentsCompact, humanize } from '@/lib/format';
import { help } from '@/lib/helpText';
import { useToast } from '@/components/ui/toast';
import { LoadingState, ErrorState, ForbiddenState, EmptyState } from '@/components/ui/states';
import {
  IconFilter, IconChevronDown, IconChevronRight, IconUp, IconDown, IconShield, IconCash,
  IconBuilding, IconWrench, IconList, IconClock, IconDoor, IconChart, IconExport,
  IconRefresh, IconEye, Tip, Legend, LineChart, GroupedBarChart, HBarChart, StackedBarChart, Funnel, SegBar,
} from './analytics-ui';
import './analytics.css';

type Range = 'this' | 'last' | '90' | 'ytd';

const RANGE_LABEL: Record<Range, string> = {
  this: 'This month',
  last: 'Last month',
  '90': 'Last 90 days',
  ytd: 'Year to date',
};

/** Aging buckets exactly as returned by LandlordAnalyticsService::balanceAging(). */
const AGING_RANGES: Record<string, [number, number]> = {
  '0-7 days': [0, 7],
  '8-30 days': [8, 30],
  '31-60 days': [31, 60],
  '60+ days': [61, Infinity],
};

const CATEGORY_META: Record<string, { singular: string; plural: string }> = {
  overdue_rent: { singular: 'overdue rent account', plural: 'overdue rent accounts' },
  maintenance: { singular: 'unassigned repair', plural: 'unassigned repairs' },
  vacancy: { singular: 'unlisted vacancy', plural: 'unlisted vacancies' },
  listing_draft: { singular: 'draft listing', plural: 'draft listings' },
  low_conversion: { singular: 'low-converting listing', plural: 'low-converting listings' },
  lease_ending: { singular: 'lease ending soon', plural: 'leases ending soon' },
};

function pct(n: number): string {
  return `${Math.round(n)}%`;
}

function delta(cur: number, prev: number): { dir: 'up' | 'dn' | 'flat'; text: string } {
  if (prev === 0) return { dir: 'flat', text: '—' };
  const d = Math.round(((cur - prev) / prev) * 100);
  return { dir: d > 0 ? 'up' : d < 0 ? 'dn' : 'flat', text: `${d > 0 ? '+' : ''}${d}%` };
}

function DeltaPill({ cur, prev, goodUp }: { cur: number; prev: number; goodUp: boolean }) {
  const d = delta(cur, prev);
  const cls = d.dir === 'flat' ? 'flat' : (d.dir === 'up') === goodUp ? 'up' : 'dn';
  return (
    <span className={`delta ${cls}`}>
      {d.dir === 'up' ? <IconUp /> : d.dir === 'dn' ? <IconDown /> : null}
      {d.text}
    </span>
  );
}

const TONE_CLASS: Record<string, string> = { red: 'attn-red', amber: 'attn-amber', blue: 'attn-blue' };
const TONE_ICON: Record<string, React.ReactNode> = {
  red: <IconCash />,
  amber: <IconList />,
  blue: <IconDoor />,
};

export function LandlordAnalytics() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const [range, setRange] = useState<Range>('this');
  const [propertyId, setPropertyId] = useState<number | 'all'>('all');
  const [agingFilter, setAgingFilter] = useState<string | null>(null);
  const [exporting, setExporting] = useState(false);
  const [cert, setCert] = useState<{ count: number; checksum: string; at: string } | null>(null);

  const scopedPropertyId = propertyId === 'all' ? undefined : propertyId;
  const { data, loading, error, reload } = useApi<PortfolioAnalytics>(
    () => landlordApi.analyticsPortfolio(range, scopedPropertyId),
    [range, propertyId],
  );
  const { data: properties } = useApi<Property[]>(() => landlordApi.properties(), []);

  async function handleExport() {
    setExporting(true);
    try {
      const result = await landlordApi.exportAnalytics(range, scopedPropertyId);
      const url = URL.createObjectURL(result.blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = result.filename;
      a.click();
      URL.revokeObjectURL(url);
      setCert({ count: result.rowCount, checksum: result.checksum, at: new Date().toISOString() });
      toast(`Analytics report exported · ${result.rowCount} properties`, 'success');
    } catch {
      toast('Could not export the report', 'error');
    } finally {
      setExporting(false);
    }
  }

  const selectedPropertyName = propertyId === 'all'
    ? 'All properties'
    : properties?.find((p) => p.id === propertyId)?.name ?? 'Selected property';

  const header = (
    <div className="glass pagehead">
      <header>
        <div className="eyebrow">Portfolio</div>
        <h1 className="page">Analytics</h1>
        <p className="lede">
          Understand rent collection, occupancy, listings, tenant payments, and maintenance using
          real property, contract, ledger, and request data. Every figure here is computed from your
          real properties, contracts, and ledger — nothing is a stand-in.
        </p>
      </header>
      <div className="acts">
        <button className="btn btn-g" onClick={reload}><IconRefresh /> Refresh</button>
        <button className="btn btn-p" onClick={handleExport} disabled={exporting}>
          <IconExport /> {exporting ? 'Exporting…' : 'Export report'}
        </button>
      </div>
    </div>
  );

  if (error?.status === 403) {
    return <div className="wana">{header}<ForbiddenState message="You don't have access to analytics." /></div>;
  }
  if (error) {
    return <div className="wana">{header}<ErrorState message={error.message} onRetry={reload} /></div>;
  }
  if (loading || !data) {
    return <div className="wana">{header}<LoadingState label="Crunching your portfolio numbers…" /></div>;
  }

  const { summary, financial_trend, revenue_by_property, occupancy, listings, payments, maintenance, needs_attention, properties: propertyRows } = data;
  const months = financial_trend.map((t) => t.month);
  const noData = summary.total_units === 0 && propertyRows.length === 0;

  const collectionRatePct = summary.expected_cents > 0
    ? Math.round((summary.collected_cents / summary.expected_cents) * 100)
    : null;

  const vacantTotal = occupancy.unit_status.vacant_listed + occupancy.unit_status.vacant_draft + occupancy.unit_status.vacant_unlisted;

  const attentionCounts = needs_attention.reduce<Record<string, number>>((acc, item) => {
    acc[item.category] = (acc[item.category] ?? 0) + 1;
    return acc;
  }, {});
  const attentionBreakdown = Object.entries(attentionCounts)
    .map(([cat, count]) => {
      const meta = CATEGORY_META[cat];
      if (!meta) return null;
      return `${count} ${count === 1 ? meta.singular : meta.plural}`;
    })
    .filter(Boolean)
    .join(' · ');

  const filteredOverdueTenants = agingFilter
    ? payments.overdue_tenants.filter((o) => {
      const bucketRange = AGING_RANGES[agingFilter];
      return bucketRange && o.days_overdue >= bucketRange[0] && o.days_overdue <= bucketRange[1];
    })
    : payments.overdue_tenants;

  return (
    <div className="wana">
      {header}

      <div className="filters glass-2">
        <span className="fl"><IconFilter /> Filtered by <Tip text={help.dateRange} /></span>
        <div className="sel">
          <select value={range} onChange={(e) => setRange(e.target.value as Range)}>
            {(Object.keys(RANGE_LABEL) as Range[]).map((k) => (
              <option key={k} value={k}>{RANGE_LABEL[k]}</option>
            ))}
          </select>
          <span className="cv"><IconChevronDown /></span>
        </div>
        <div className="sel">
          <select
            value={propertyId}
            onChange={(e) => setPropertyId(e.target.value === 'all' ? 'all' : Number(e.target.value))}
          >
            <option value="all">All properties</option>
            {(properties ?? []).map((p) => (
              <option key={p.id} value={p.id}>{p.name}</option>
            ))}
          </select>
          <span className="cv"><IconChevronDown /></span>
        </div>
        <span className="fl" style={{ marginLeft: 'auto' }}>
          <b>{RANGE_LABEL[range]}</b> · {selectedPropertyName} · vs previous period
        </span>
      </div>

      {noData ? (
        <EmptyState
          icon={<IconChart />}
          title="Analytics will populate as your portfolio grows"
          description="Add a property and unit, then once contracts and rent flow through the ledger, your revenue, occupancy, and maintenance figures appear here."
        />
      ) : (
        <>
          <section className="cards">
            <div className="card glass-2 good">
              <span className="edge" />
              <div className="k">Rent collected <Tip text="Total successful payments received during the selected date range." /></div>
              <div className="v">{formatCents(summary.collected_cents)}</div>
              <div className="n">
                {RANGE_LABEL[range].toLowerCase()} ·{' '}
                <DeltaPill cur={summary.collected_cents} prev={summary.collected_prev_cents} goodUp />
              </div>
              {collectionRatePct !== null && (
                <div className="n">{collectionRatePct}% of expected rent collected</div>
              )}
            </div>
            <div className="card glass-2 info">
              <span className="edge" />
              <div className="k">Expected rent <Tip text="Total rent due from active contracts during the selected date range." /></div>
              <div className="v">{formatCents(summary.expected_cents)}</div>
              <div className="n">from active contracts</div>
            </div>
            <div className="card glass-2 bad">
              <span className="edge" />
              <div className="k">Outstanding <Tip text="Total unpaid charges from active contracts, including overdue rent and unpaid fees." /></div>
              <div className="v">{formatCents(summary.outstanding_cents)}</div>
              <div className="n">still unpaid</div>
            </div>
            <div className="card glass-2">
              <span className="edge" />
              <div className="k">Occupied units <Tip text="Occupied units divided by total rentable units, right now." /></div>
              <div className="v">{pct(summary.occupancy_pct)}</div>
              <div className="n">{summary.occupied_units} of {summary.total_units} units occupied</div>
            </div>
            <div
              className="card glass-2"
              style={{ cursor: 'pointer' }}
              onClick={() => navigate('/app/properties')}
            >
              <span className="edge" />
              <div className="k">Vacant units <Tip text="Units without an active contract. Listed units have an active or pending listing; draft units have an unpublished one; unlisted units have none yet." /></div>
              <div className="v">{vacantTotal}</div>
              <div className="n">
                {occupancy.unit_status.vacant_listed} listed · {occupancy.unit_status.vacant_draft} draft · {occupancy.unit_status.vacant_unlisted} unlisted
              </div>
            </div>
            <div className="card glass-2 warn">
              <span className="edge" />
              <div className="k">Needs attention <Tip text="Open items worth handling first: overdue rent, vacant or draft listings, urgent repairs, and upcoming move-outs." /></div>
              <div className="v">{needs_attention.length}</div>
              {needs_attention.length === 0 ? (
                <div className="n">Nothing needs action right now</div>
              ) : (
                <>
                  <div className="n">{attentionBreakdown}</div>
                  <div className="n">
                    <a href="#attn" style={{ color: 'var(--wa-amber)', fontWeight: 600 }}>See what needs action ↓</a>
                  </div>
                </>
              )}
            </div>
          </section>

          {needs_attention.length > 0 ? (
            <section className="section" id="attn">
              <div className="section-head"><h2>Needs attention</h2><span className="sq">The few things worth handling first</span></div>
              <div className="glass" style={{ padding: 8 }}>
                <div className="attn">
                  {needs_attention.map((a, i) => (
                    <div className={`attn-item ${TONE_CLASS[a.tone]}`} key={i}>
                      <div className="ai">{TONE_ICON[a.tone]}</div>
                      <div className="atxt">
                        <div className="att">{a.title}</div>
                        <div className="atd">{a.description}</div>
                      </div>
                      <div className="abtn">
                        <button className="btn btn-g sm" onClick={() => navigate(a.action_route)}>
                          {a.action_label} <IconChevronRight />
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </section>
          ) : (
            <section className="section" id="attn">
              <div className="section-head"><h2>Needs attention</h2><span className="sq">The few things worth handling first</span></div>
              <EmptyState
                icon={<IconShield />}
                title="No urgent items right now"
                description="Rent, occupancy, applications, and maintenance do not need immediate action for the selected filters."
              />
            </section>
          )}

          <section className="section">
            <div className="section-head"><h2>Financial performance</h2><span className="sq">Is rent coming in as it should?</span></div>
            <div className="chart-grid cg-2">
              <div className="chart-card glass">
                <div className="ct"><h3><IconChart /> Rent collected trend</h3></div>
                <div className="cd">Last 6 months · GH₵</div>
                <div className="chart-wrap">
                  <LineChart labels={months} area fmt={formatCentsCompact} series={[{ name: 'Collected', color: 'var(--wa-c2)', vals: financial_trend.map((t) => t.collected_cents) }]} />
                </div>
              </div>
              <div className="chart-card glass">
                <div className="ct"><h3><IconChart /> Expected vs collected</h3></div>
                <div className="cd">Last 6 months · GH₵</div>
                <div className="chart-wrap">
                  <GroupedBarChart
                    labels={months}
                    fmt={formatCentsCompact}
                    a={{ name: 'Expected', color: 'var(--wa-c6)', vals: financial_trend.map((t) => t.expected_cents) }}
                    b={{ name: 'Collected', color: 'var(--wa-c1)', vals: financial_trend.map((t) => t.collected_cents) }}
                  />
                </div>
                <Legend items={[{ label: 'Expected', color: 'var(--wa-c6)' }, { label: 'Collected', color: 'var(--wa-c1)' }]} />
              </div>
            </div>
            <div className="chart-grid cg-1">
              <div className="chart-card glass">
                <div className="ct"><h3><IconBuilding /> Revenue by property</h3></div>
                <div className="cd">Selected range · click a bar to open that property</div>
                <div className="chart-wrap">
                  <HBarChart
                    w={1040}
                    labelW={220}
                    rowH={44}
                    valueW={160}
                    fmt={(v) => formatCents(v)}
                    rows={revenue_by_property.map((r) => ({ label: r.name, value: r.collected_cents, color: 'var(--wa-c1)' }))}
                    onRowClick={(label) => {
                      const p = revenue_by_property.find((r) => r.name === label);
                      if (p) navigate(`/app/properties/${p.property_id}`);
                    }}
                  />
                </div>
              </div>
            </div>
          </section>

          <section className="section">
            <div className="section-head"><h2>Occupancy &amp; units</h2><span className="sq">Are units full or sitting empty?</span></div>
            <div className="chart-grid cg-2">
              <div className="chart-card glass">
                <div className="ct"><h3><IconChart /> Occupancy trend</h3></div>
                <div className="cd">Last 6 months</div>
                <div className="chart-wrap">
                  <LineChart labels={months} area yMax={100} fmt={(v) => `${Math.round(v)}%`} series={[{ name: 'Occupancy', color: 'var(--wa-c1)', vals: occupancy.trend.map((t) => t.occupancy_pct) }]} />
                </div>
              </div>
              <div className="chart-card glass">
                <div className="ct"><h3><IconBuilding /> Unit status</h3></div>
                <div className="cd">{occupancy.unit_status.total} rentable units today</div>
                <SegBar segs={[
                  { label: 'Occupied', value: occupancy.unit_status.occupied, color: 'var(--wa-c1)' },
                  { label: 'Vacant · listed', value: occupancy.unit_status.vacant_listed, color: 'var(--wa-c3)' },
                  { label: 'Vacant · draft', value: occupancy.unit_status.vacant_draft, color: 'var(--wa-c6)' },
                ]}
                />
                <div className="unit-break">
                  <div className="ub"><span className="k"><span className="sw" style={{ background: 'var(--wa-c1)' }} />Occupied</span><span className="v">{occupancy.unit_status.occupied}</span></div>
                  <div className="ub"><span className="k"><span className="sw" style={{ background: 'var(--wa-c3)' }} />Vacant · listed</span><span className="v">{occupancy.unit_status.vacant_listed}</span></div>
                  <div className="ub"><span className="k"><span className="sw" style={{ background: 'var(--wa-c6)' }} />Vacant · draft</span><span className="v">{occupancy.unit_status.vacant_draft}</span></div>
                  <div className="ub"><span className="k">Vacant · unlisted</span><span className="v">{occupancy.unit_status.vacant_unlisted}</span></div>
                </div>
              </div>
            </div>
            {occupancy.vacancy_by_property.length > 0 && (
              <div className="chart-grid cg-1">
                <div className="chart-card glass">
                  <div className="ct"><h3><IconDoor /> Vacant units by property</h3></div>
                  <div className="cd">Click a bar to open that property</div>
                  <div className="chart-wrap">
                    <HBarChart
                      w={1040}
                      labelW={220}
                      rowH={44}
                      rows={occupancy.vacancy_by_property.map((v) => ({ label: v.name, value: v.vacant, color: 'var(--wa-c3)' }))}
                      onRowClick={(label) => {
                        const p = occupancy.vacancy_by_property.find((v) => v.name === label);
                        if (p) navigate(`/app/properties/${p.property_id}`);
                      }}
                    />
                  </div>
                </div>
              </div>
            )}
          </section>

          <section className="section">
            <div className="section-head"><h2>Listings &amp; applications</h2><span className="sq">Are your rental ads working?</span></div>
            <div className="chart-grid cg-1">
              <div className="chart-card glass">
                <div className="ct"><h3><IconFilter /> Listing funnel <Tip text="Only backend-tracked steps are shown. Views and Saved are real, tracked columns — not illustrative stand-ins." /></h3></div>
                <div className="cd">Where people drop off — every step is a real, tracked count</div>
                <Funnel steps={listings.funnel.map((f) => ({ label: f.step, value: f.value }))} />
              </div>
            </div>
            <div className="chart-grid cg-2">
              <div className="chart-card glass">
                <div className="ct"><h3><IconList /> Applications by listing</h3></div>
                <div className="cd">All time · click a bar to open that listing</div>
                <div className="chart-wrap">
                  <HBarChart
                    labelW={190}
                    rowH={42}
                    rows={listings.applications_by_listing.map((a) => ({ label: a.label, value: a.value, color: a.status === 'draft' ? 'var(--wa-c6)' : 'var(--wa-c1)' }))}
                    onRowClick={(label) => {
                      const item = listings.applications_by_listing.find((a) => a.label === label);
                      if (item) navigate(`/app/listings/${item.listing_id}`);
                    }}
                  />
                </div>
              </div>
              <div className="chart-card glass">
                <div className="ct"><h3><IconList /> Listing status</h3></div>
                <div className="cd">Across the portfolio · click a bar to see those listings</div>
                <div className="chart-wrap">
                  <HBarChart
                    labelW={140}
                    rowH={44}
                    rows={listings.status_breakdown.map((l) => ({ label: humanize(l.status), value: l.count }))}
                    onRowClick={(label) => {
                      const item = listings.status_breakdown.find((l) => humanize(l.status) === label);
                      if (item) navigate(`/app/listings?status=${item.status}`);
                    }}
                  />
                </div>
              </div>
            </div>
          </section>

          <section className="section">
            <div className="section-head"><h2>Tenant payment behaviour</h2><span className="sq">Are tenants paying properly?</span></div>
            <div className="chart-grid cg-2">
              <div className="chart-card glass">
                <div className="ct"><h3><IconChart /> On-time vs late <Tip text="A payment counts as on time if it was made on or before the rent's due date." /></h3></div>
                <div className="cd">Last 6 months · payments</div>
                <div className="chart-wrap">
                  <StackedBarChart
                    labels={months}
                    segs={[{ key: 'on_time', name: 'On time', color: 'var(--wa-c2)' }, { key: 'late', name: 'Late', color: 'var(--wa-c3)' }]}
                    rows={payments.behavior_trend}
                  />
                </div>
                <Legend items={[{ label: 'On time', color: 'var(--wa-c2)' }, { label: 'Late', color: 'var(--wa-c3)' }]} />
              </div>
              <div className="chart-card glass">
                <div className="ct"><h3><IconClock /> Balance aging <Tip text="How long unpaid balances have remained unpaid after their due date. Click a bucket to filter the tenants below." /></h3></div>
                <div className="cd">Outstanding by age · GH₵</div>
                <div className="chart-wrap">
                  <HBarChart
                    labelW={120}
                    rowH={44}
                    valueW={150}
                    fmt={(v) => formatCents(v)}
                    rows={payments.aging.map((a) => ({ label: a.bucket, value: a.amount_cents, color: a.bucket.startsWith('31') || a.bucket.startsWith('60') ? 'var(--wa-c4)' : a.bucket.startsWith('8') ? 'var(--wa-c3)' : 'var(--wa-c1)' }))}
                    onRowClick={(label) => setAgingFilter((prev) => (prev === label ? null : label))}
                  />
                </div>
              </div>
            </div>
            <div className="chart-grid cg-1">
              <div className="chart-card glass">
                <div className="ct">
                  <h3><IconCash /> Overdue tenants</h3>
                  {agingFilter && (
                    <button className="btn btn-g sm" onClick={() => setAgingFilter(null)}>
                      {agingFilter} <IconChevronRight />
                    </button>
                  )}
                </div>
                <div className="cd">{agingFilter ? `Filtered by ${agingFilter} overdue · links to the Rent Ledger` : 'Links to the Rent Ledger'}</div>
                {filteredOverdueTenants.length === 0 ? (
                  <p style={{ fontSize: 14, color: 'var(--wa-slate)' }}>
                    {agingFilter ? 'No overdue tenants in this age range.' : 'No overdue tenants right now.'}
                  </p>
                ) : (
                  <table className="mini-tbl">
                    <thead><tr><th>Tenant</th><th className="r">Overdue</th><th className="r">Days</th><th /></tr></thead>
                    <tbody>
                      {filteredOverdueTenants.map((o) => (
                        <tr key={o.contract_id} className="clk" onClick={() => navigate(`/app/tenants/${o.contract_id}?tab=rent`)}>
                          <td><b>{o.tenant_name}</b><div style={{ fontSize: 12.5, color: 'var(--wa-slate)' }}>{o.property_name} · {o.unit_number}</div></td>
                          <td className="r" style={{ color: 'var(--wa-oxblood)' }}>{formatCents(o.overdue_cents)}</td>
                          <td className="r">{o.days_overdue}</td>
                          <td className="r"><IconChevronRight /></td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                )}
              </div>
            </div>
          </section>

          <section className="section">
            <div className="section-head"><h2>Maintenance insights</h2><span className="sq">Are repairs under control?</span></div>
            <div className="chart-grid cg-2">
              <div className="chart-card glass">
                <div className="ct"><h3><IconWrench /> Requests by status</h3></div>
                <div className="cd">Current workload</div>
                <div className="chart-wrap">
                  <HBarChart labelW={140} rowH={40} rows={maintenance.by_status.map((s) => ({ label: humanize(s.status), value: s.count, color: 'var(--wa-c1)' }))} onRowClick={() => navigate('/app/maintenance')} />
                </div>
              </div>
              <div className="chart-card glass">
                <div className="ct"><h3><IconWrench /> By category</h3></div>
                <div className="cd">All requests</div>
                <div className="chart-wrap">
                  <HBarChart labelW={140} rowH={40} rows={maintenance.by_category.map((c) => ({ label: humanize(c.category) === '—' ? 'General' : humanize(c.category), value: c.count, color: 'var(--wa-c1)' }))} onRowClick={() => navigate('/app/maintenance')} />
                </div>
              </div>
            </div>
            <div className="chart-grid cg-1">
              <div className="chart-card glass">
                <div className="ct"><h3><IconClock /> Resolution time</h3></div>
                <div className="cd">Average days to resolve</div>
                <div className="chart-wrap">
                  {/* Explicit w matches this card's full-width (cg-1) column — the
                      component's 560px default is sized for the two-column (cg-2)
                      layout, and rendering it here without a matching width scaled
                      the whole chart (including its text) up ~1.8x. */}
                  <LineChart labels={months} area yMax={5} w={1040} fmt={(v) => v.toFixed(1)} series={[{ name: 'Days', color: 'var(--wa-c2)', vals: maintenance.resolution_trend.map((t) => t.avg_days ?? 0) }]} />
                </div>
              </div>
            </div>
          </section>

          <section className="section">
            <div className="section-head"><h2>Property performance</h2><span className="sq">Compare properties side by side</span></div>
            <div className="glass" style={{ padding: '6px 8px' }}>
              <div className="tblwrap">
                <table className="ptbl">
                  <thead>
                    <tr>
                      <th>Property</th><th>Units</th><th>Occupancy</th><th className="r">Collected</th>
                      <th className="r">Outstanding</th><th className="r">Applications</th><th className="r">Open maint.</th><th>Status</th><th />
                    </tr>
                  </thead>
                  <tbody>
                    {propertyRows.map((p) => {
                      const fillColor = p.occupancy_pct >= 80 ? 'var(--wa-green)' : p.occupancy_pct >= 50 ? 'var(--wa-amber)' : 'var(--wa-oxblood)';
                      const statusMeta = { healthy: ['b-green', 'Healthy'], attention: ['b-red', 'Needs attention'], vacancy: ['b-amber', 'Vacancy issue'] }[p.status] ?? ['b-gray', p.status];
                      return (
                        <tr key={p.id} onClick={() => navigate(`/app/properties/${p.id}`)}>
                          <td className="pn">{p.name}<div className="ps">{p.area}</div></td>
                          <td>{p.units}</td>
                          <td><span className="occ"><span className="track"><span className="fill" style={{ width: `${p.occupancy_pct}%`, background: fillColor }} /></span>{p.occupancy_pct}%</span></td>
                          <td className="r">{formatCents(p.collected_cents)}</td>
                          <td className="r" style={p.outstanding_cents > 0 ? { color: 'var(--wa-oxblood)', fontWeight: 600 } : undefined}>{formatCents(p.outstanding_cents)}</td>
                          <td className="r">{p.applications_count}</td>
                          <td className="r">{p.open_maintenance}</td>
                          <td><span className={`badge ${statusMeta[0]}`}>{statusMeta[1]}</span></td>
                          <td className="r"><span className="btn btn-g sm">Details <IconChevronRight /></span></td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>
          </section>

          {cert && (
            <div className="glass cert">
              <div className="ct"><IconShield /> Export certificate</div>
              <div className="crow"><span className="k">Properties</span><span className="v">{cert.count}</span></div>
              <div className="crow"><span className="k">Format</span><span className="v">CSV</span></div>
              <div className="crow"><span className="k">Filters</span><span className="v">{RANGE_LABEL[range]} · {selectedPropertyName}</span></div>
              <div className="crow"><span className="k">Generated at</span><span className="v">{new Date(cert.at).toLocaleString()}</span></div>
              <div className="crow"><span className="k">Integrity</span><span className="v"><span className="badge b-green"><IconEye />SHA-256 verified</span></span></div>
              <div className="crow"><span className="k">Checksum</span><span className="v cksum">{cert.checksum}</span></div>
            </div>
          )}

          <div className="foot glass-2">
            <IconShield />
            <div>
              Every figure above is computed live from your properties, contracts, and ledger — the same records
              behind your Ledger and Maintenance pages, so nothing shown here can disagree with them.
            </div>
          </div>
        </>
      )}
    </div>
  );
}
