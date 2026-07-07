/**
 * Admin Maintenance — platform-wide oversight command centre, rebuilt from
 * wyncrest-maintenance.html onto real data only. Faithfully ported White
 * Liquid Glass styling (admin-maintenance.css, scoped `.wamnt`) shared with
 * the landlord Maintenance page's design system.
 *
 * Deliberately dropped from the mockup (see project history): SLA countdown
 * rings/response-time targets (no such column exists — "overdue" is honestly
 * just "past the landlord's own expected_completion_date"), a dispute/ruling
 * system (zero precedent anywhere in this app), and a bespoke per-maintenance
 * permission matrix (the real /app/manage-access page already covers the new
 * manage_maintenance capability). Full case detail lives at
 * /app/maintenance/:id (AdminMaintenanceDetail).
 */
import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { adminApi } from '@/lib/endpoints';
import { useAuth } from '@/context/auth';
import { isSuperAdmin } from '@/lib/permissions';
import { ErrorState, LoadingState } from '@/components/ui/states';
import { formatDate, timeAgo } from '@/lib/format';
import type { MaintenanceCategory, MaintenancePriority } from '@/lib/types';
import {
  maintenanceCategoryLabel, maintenancePriorityLabel, maintenanceStatusLabel,
  CATEGORY_ICON, CATEGORY_TINT, CATEGORY_COLOR, PRIORITY_CLASS, STATUS_BADGE, isOpen,
} from './maintenance-helpers';
import { triagePriority } from './maintenance-priority';
import {
  IconSearch, IconChevRight, IconChevDown, IconWrench, IconWarn, IconFlag, IconShield,
  IconInfo, IconActivity, IconUser, IconLock,
} from '@/pages/landlord/maintenance-ui';
import './admin-maintenance.css';

type DataTab = 'needs' | 'open' | 'urgent' | 'overdue' | 'waiting' | 'escalated' | 'unassigned' | 'all';
type Tab = DataTab | 'reports' | 'oversight';
type CategoryFilter = 'all' | MaintenanceCategory;
type PriorityFilter = 'all' | MaintenancePriority;

export function AdminMaintenanceQueue() {
  const navigate = useNavigate();
  const { user } = useAuth();
  const superAdmin = isSuperAdmin(user);

  const [tab, setTab] = useState<Tab>('needs');
  const [q, setQ] = useState('');
  const [category, setCategory] = useState<CategoryFilter>('all');
  const [priority, setPriority] = useState<PriorityFilter>('all');

  const summaryReq = useApi(() => adminApi.maintenanceSummary(), []);
  const queueReq = useApi(() => adminApi.maintenanceQueue({ status: 'all', limit: 200 }), []);
  const analyticsReq = useApi(() => (tab === 'reports' ? adminApi.maintenanceAnalytics() : Promise.resolve(null)), [tab]);
  const oversightReq = useApi(
    () => (tab === 'oversight' && superAdmin ? adminApi.maintenanceOversight() : Promise.resolve(null)),
    [tab, superAdmin],
  );

  const cases = useMemo(() => queueReq.data?.data ?? [], [queueReq.data]);

  const dataForTab = useMemo(() => {
    switch (tab) {
      case 'needs':
        return cases.filter((c) => isOpen(c) && (c.escalated_at || c.has_severe_safety_flag || c.is_overdue || (c.priority === 'urgent' && isOpen(c))));
      case 'open': return cases.filter(isOpen);
      case 'urgent': return cases.filter((c) => isOpen(c) && (c.priority === 'urgent' || c.priority === 'high'));
      case 'overdue': return cases.filter((c) => c.is_overdue);
      case 'waiting': return cases.filter((c) => c.status === 'waiting');
      case 'escalated': return cases.filter((c) => !!c.escalated_at);
      case 'unassigned': return cases.filter((c) => isOpen(c) && !c.handling_admin);
      case 'all': return cases;
      default: return [];
    }
  }, [cases, tab]);

  const filtered = useMemo(() => {
    let list = dataForTab.slice();
    if (category !== 'all') list = list.filter((c) => c.category === category);
    if (priority !== 'all') list = list.filter((c) => c.priority === priority);
    if (q.trim()) {
      const needle = q.trim().toLowerCase();
      list = list.filter((c) => [c.id, c.title, c.tenant?.name, c.landlord?.name, c.property, c.category]
        .filter(Boolean).join(' ').toLowerCase().includes(needle));
    }
    return list;
  }, [dataForTab, category, priority, q]);

  const triaged = useMemo(
    () => (tab !== 'needs' ? filtered : [...filtered].sort((a, b) => triagePriority(a) - triagePriority(b))),
    [filtered, tab],
  );

  const tabsDef: { key: DataTab; label: string }[] = [
    { key: 'needs', label: 'Needs Attention' },
    { key: 'open', label: 'Open' },
    { key: 'urgent', label: 'Urgent' },
    { key: 'overdue', label: 'Overdue' },
    { key: 'waiting', label: 'Waiting' },
    { key: 'escalated', label: 'Escalated' },
    { key: 'unassigned', label: 'Unassigned' },
    { key: 'all', label: 'All' },
  ];

  if (queueReq.loading || summaryReq.loading) return <div className="wamnt"><LoadingState label="Loading maintenance oversight…" /></div>;
  if (queueReq.error) return <div className="wamnt"><ErrorState message={queueReq.error.message} onRetry={queueReq.reload} /></div>;

  const summary = summaryReq.data;

  return (
    <div className="wamnt">
      <div className="glass pagehead">
        <header>
          <div className="eyebrow">Platform</div>
          <h1 className="page">Maintenance Oversight</h1>
          <p className="lede">Every maintenance request across the platform. What is urgent, who is responsible, what is stuck, and what needs action now.</p>
        </header>
      </div>

      {summary && (
        <section className="cards">
          <Card cls="info" k="Open" v={summary.open} n="active, not yet resolved" />
          <Card cls={summary.urgent > 0 ? 'bad' : 'good'} k="Urgent" v={summary.urgent} n="emergency or high priority, open" />
          <Card cls={summary.overdue > 0 ? 'bad' : 'good'} k="Overdue" v={summary.overdue} n="past the landlord's own estimate" />
          <Card cls={summary.waiting > 0 ? 'warn' : 'good'} k="Waiting" v={summary.waiting} n="stalled on a response or visit" />
        </section>
      )}

      <div className="tabs glass-2">
        {tabsDef.map((t) => (
          <button key={t.key} className={tab === t.key ? 'on' : ''} onClick={() => setTab(t.key)}>{t.label}</button>
        ))}
        <button className={tab === 'reports' ? 'on' : ''} onClick={() => setTab('reports')}><IconActivity /> Reports</button>
        {superAdmin && (
          <button className={tab === 'oversight' ? 'on' : ''} onClick={() => setTab('oversight')}><IconShield /> Platform Oversight</button>
        )}
      </div>

      {tab === 'reports' ? (
        <ReportsPanel loading={analyticsReq.loading} data={analyticsReq.data} />
      ) : tab === 'oversight' ? (
        <OversightPanel loading={oversightReq.loading} error={oversightReq.error} data={oversightReq.data} onGo={(id) => navigate(`/app/maintenance/${id}`)} />
      ) : (
        <>
          <div className="filters glass-2">
            <div className="fsearch">
              <IconSearch />
              <input aria-label="Search maintenance cases" placeholder="Search by ID, title, tenant, landlord, or property..." value={q} onChange={(e) => setQ(e.target.value)} />
            </div>
            <Sel label="Filter by category" value={category} onChange={(v) => setCategory(v as CategoryFilter)} options={[['all', 'All categories'], ...(Object.keys(maintenanceCategoryLabel) as MaintenanceCategory[]).map((c): [string, string] => [c, maintenanceCategoryLabel[c]])]} />
            <Sel label="Filter by priority" value={priority} onChange={(v) => setPriority(v as PriorityFilter)} options={[['all', 'All priorities'], ...(Object.keys(maintenancePriorityLabel) as MaintenancePriority[]).map((p): [string, string] => [p, maintenancePriorityLabel[p]])]} />
          </div>

          {triaged.length === 0 ? (
            <div className="empty glass">
              <div className="ei"><IconWrench /></div>
              <div className="et">No requests in this view</div>
              <div className="em">Nothing matches the current tab and filters. Clear a filter or switch tabs to see more.</div>
            </div>
          ) : (
            <>
              <div className="filtered-note">
                <IconInfo />
                <div><b>{triaged.length}</b> {triaged.length === 1 ? 'request' : 'requests'}. Click any card for the full case file.</div>
              </div>
              <div className="mlist">
                {triaged.map((c) => {
                  const cat = c.category as MaintenanceCategory | null;
                  const Icon = cat ? CATEGORY_ICON[cat] : IconWrench;
                  const prio = c.priority as MaintenancePriority | null;
                  const spine = prio === 'urgent' ? 'var(--wam-oxblood)' : prio === 'high' ? 'var(--wam-amber)' : prio === 'medium' ? 'var(--wam-petrol-2)' : 'var(--wam-slate)';
                  return (
                    <div
                      key={c.id}
                      className="mcard glass"
                      role="button"
                      tabIndex={0}
                      onClick={() => navigate(`/app/maintenance/${c.id}`)}
                      onKeyDown={(e) => {
                        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); navigate(`/app/maintenance/${c.id}`); }
                      }}
                    >
                      <span className="spine" style={{ background: spine }} />
                      <div className="cat" style={{ background: cat ? CATEGORY_TINT[cat] : undefined, color: cat ? CATEGORY_COLOR[cat] : undefined }}><Icon /></div>
                      <div className="body">
                        <div className="ttl">{c.title}</div>
                        <div className="tags">
                          {prio && <span className={`prio-flag ${PRIORITY_CLASS[prio]}`}>{prio === 'urgent' ? <IconWarn /> : <IconFlag />}{maintenancePriorityLabel[prio]}</span>}
                          {c.status && <span className={`badge ${STATUS_BADGE[c.status as keyof typeof STATUS_BADGE]}`}><span className="dot" />{maintenanceStatusLabel[c.status as keyof typeof maintenanceStatusLabel]}</span>}
                          {c.is_overdue && <span className="badge b-red"><IconWarn />Overdue</span>}
                          {c.escalated_at && <span className="badge b-red"><IconWarn />Escalated</span>}
                          {c.has_severe_safety_flag && <span className="badge b-red"><IconWarn />Safety flag</span>}
                        </div>
                        <div className="loc">{c.property ?? '—'}</div>
                        <div className="meta">
                          <span>{c.tenant?.name ?? 'Tenant'}</span><span className="d" />
                          <span>{c.landlord?.name ?? 'Landlord'}</span><span className="d" />
                          <span>{c.submitted_at ? `reported ${formatDate(c.submitted_at)}` : ''}</span><span className="d" />
                          <span>{c.age_days} {c.age_days === 1 ? 'day' : 'days'} open</span>
                          {c.waiting_reason && <><span className="d" /><span>{c.waiting_reason}</span></>}
                        </div>
                      </div>
                      <div className="side">
                        <div className="assignee">
                          {c.handling_admin ? <>Owned by<br /><b>{c.handling_admin.name}</b></> : <span style={{ color: 'var(--wam-oxblood)' }}><IconUser /> Unassigned</span>}
                        </div>
                        <div className="qacts">
                          <button className="btn btn-g sm" onClick={(e) => { e.stopPropagation(); navigate(`/app/maintenance/${c.id}`); }}>Review <IconChevRight /></button>
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            </>
          )}
        </>
      )}

      <div className="foot glass-2">
        <IconLock />
        <div>Every figure here is derived from real columns. There is no SLA/response-time target on maintenance requests, so "overdue" honestly means "past the landlord's own estimated completion date," never a fabricated countdown.</div>
      </div>
    </div>
  );
}

function Card({ cls, k, v, n }: { cls: string; k: string; v: number; n: string }) {
  return (
    <div className={`card glass-2 ${cls}`}>
      <span className="edge" />
      <div className="k">{k}</div>
      <div className="v">{v}</div>
      <div className="n">{n}</div>
    </div>
  );
}

function Sel({ label, value, onChange, options }: { label: string; value: string; onChange: (v: string) => void; options: [string, string][] }) {
  return (
    <div className="sel">
      <select aria-label={label} value={value} onChange={(e) => onChange(e.target.value)}>
        {options.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
      </select>
      <span className="cv"><IconChevDown /></span>
    </div>
  );
}

function ReportsPanel({ loading, data }: { loading: boolean; data: Awaited<ReturnType<typeof adminApi.maintenanceAnalytics>> | null }) {
  if (loading) return <LoadingState label="Loading reports…" />;
  if (!data) return null;
  const maxCat = Math.max(1, ...Object.values(data.by_category));
  return (
    <>
      <section className="cards">
        <Card cls="good" k="Resolved" v={data.resolved_count} n="requests resolved" />
        <Card cls="info" k="Avg response" v={Math.round(data.average_response_hours)} n="hours to landlord acknowledgement" />
        <Card cls="info" k="Avg resolution" v={Math.round(data.average_resolution_days)} n="days, submission to resolution" />
        <Card cls={data.repeat_issue_properties > 0 ? 'warn' : 'good'} k="Repeat-issue properties" v={data.repeat_issue_properties} n="more than one open request" />
      </section>
      <div className="glass panel">
        <h3>Most common categories (open)</h3>
        <div className="qs" style={{ marginTop: 12 }}>
          {Object.entries(data.by_category).sort((a, b) => b[1] - a[1]).map(([cat, count]) => (
            <div key={cat} className="kv-row" style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
              <span className="k" style={{ minWidth: 120 }}>{maintenanceCategoryLabel[cat as MaintenanceCategory] ?? cat}</span>
              <div style={{ flex: 1, height: 8, borderRadius: 8, background: 'var(--wam-line-2)', overflow: 'hidden' }}>
                <div style={{ width: `${(count / maxCat) * 100}%`, height: '100%', background: 'var(--wam-petrol-2)' }} />
              </div>
              <span className="v">{count}</span>
            </div>
          ))}
        </div>
      </div>
    </>
  );
}

function OversightPanel({
  loading, error, data, onGo,
}: {
  loading: boolean;
  error: unknown;
  data: Awaited<ReturnType<typeof adminApi.maintenanceOversight>> | null;
  onGo: (id: string) => void;
}) {
  if (loading) return <LoadingState label="Loading platform oversight…" />;
  if (error) return <ErrorState message="Platform oversight is restricted to super admins." />;
  if (!data) return null;
  return (
    <>
      <section className="cards" style={{ gridTemplateColumns: 'repeat(3, 1fr)' }}>
        <Card cls="info" k="Open platform-wide" v={data.open_platform_wide} n="across every landlord and property" />
        <Card cls={data.unresolved_safety_flags.length > 0 ? 'bad' : 'good'} k="Unresolved safety flags" v={data.unresolved_safety_flags.length} n="open cases with a severe flag" />
        <Card cls={data.landlords_with_repeat_overdue.length > 0 ? 'warn' : 'good'} k="Landlords with repeat overdue" v={data.landlords_with_repeat_overdue.length} n="more than one overdue case" />
      </section>

      <div className="glass panel">
        <h3>Emergencies not yet resolved</h3>
        <div className="olist" style={{ marginTop: 12 }}>
          {data.unresolved_safety_flags.length === 0 ? <p className="lede">No open cases with a severe safety flag.</p> : data.unresolved_safety_flags.map((c) => (
            <div key={c.id} className="orow clickable" onClick={() => onGo(c.id)}>
              <div><b>{c.title}</b><small>{c.property ?? '—'} · {c.landlord?.name ?? '—'}</small></div>
              <span className="badge b-red"><IconWarn />Safety flag</span>
            </div>
          ))}
        </div>
      </div>

      <div className="grid2">
        <div className="glass panel">
          <h3>Landlords with repeated overdue</h3>
          <div className="olist" style={{ marginTop: 12 }}>
            {data.landlords_with_repeat_overdue.length === 0 ? <p className="lede">None.</p> : data.landlords_with_repeat_overdue.map((row) => (
              <div key={row.landlord_id} className="orow">
                <div><b>{row.landlord_name ?? `Landlord #${row.landlord_id}`}</b><small>Repeated overdue maintenance response</small></div>
                <span className="badge b-red">{row.overdue_count} overdue</span>
              </div>
            ))}
          </div>
        </div>
        <div className="glass panel">
          <h3>Admins with open cases</h3>
          <div className="olist" style={{ marginTop: 12 }}>
            {data.admin_caseload.length === 0 ? <p className="lede">No cases currently owned by an admin.</p> : data.admin_caseload.map((row) => (
              <div key={row.admin_id} className="orow">
                <div><b>{row.admin_name ?? `Admin #${row.admin_id}`}</b><small>Open cases currently owned</small></div>
                <span className="badge b-blue">{row.open_case_count} open</span>
              </div>
            ))}
          </div>
        </div>
      </div>

      <div className="glass panel">
        <h3>Properties with recurring issues</h3>
        <div className="olist" style={{ marginTop: 12 }}>
          {data.properties_with_recurring_issues.length === 0 ? <p className="lede">None flagged.</p> : data.properties_with_recurring_issues.map((row) => (
            <div key={row.property_id} className="orow">
              <div><b>{row.property_name ?? `Property #${row.property_id}`}</b><small>Underlying condition problem, not a one-off repair</small></div>
              <span className="badge b-amber">{row.open_case_count} open</span>
            </div>
          ))}
        </div>
      </div>
      <div className="foot glass-2"><IconInfo /><div>Last computed {timeAgo(new Date().toISOString())}.</div></div>
    </>
  );
}
