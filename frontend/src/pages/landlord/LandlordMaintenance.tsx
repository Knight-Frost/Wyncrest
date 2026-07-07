/**
 * Landlord Maintenance — routed list, faithfully ported from
 * wyncrest-landlord-maintenance.html. Card list + summary + filters + export
 * panel; full detail lives at /app/maintenance/:id (LandlordMaintenanceDetail).
 */
import { useId, useMemo, useState } from 'react';
import { useNavigate } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { landlordApi } from '@/lib/endpoints';
import { useToast } from '@/components/ui/toast';
import { ErrorState, LoadingState } from '@/components/ui/states';
import { InfoHint } from '@/components/ui/InfoHint';
import { help } from '@/lib/helpText';
import { formatDate, timeAgo } from '@/lib/format';
import type { MaintenanceCategory, MaintenancePriority, MaintenanceRequest, MaintenanceStatus } from '@/lib/types';
import {
  maintenanceCategoryLabel, maintenancePriorityLabel, maintenanceStatusLabel,
  CATEGORY_ICON, CATEGORY_TINT, CATEGORY_COLOR, PRIORITY_RANK, PRIORITY_CLASS, STATUS_BADGE,
  isOpen, isUrgent,
} from './maintenance-helpers';
import {
  IconSearch, IconChevRight, IconChevDown, IconPlus, IconExport, IconEye, IconMsg,
  IconHandshake, IconRenew, IconArchive, IconWrench, IconWarn, IconFlag, IconShield, IconCamera, IconInfo,
} from './maintenance-ui';
import { AssignVendorModal, CreateRequestModal, UpdateStatusModal, ResolveModal, CloseModal, type RecentVendor } from './maintenance-modals';
import './maintenance.css';

type StatusFilter = 'open' | 'all' | MaintenanceStatus;
type PriorityFilter = 'all' | MaintenancePriority;
type CategoryFilter = 'all' | MaintenanceCategory;
type AssignedFilter = 'all' | 'unassigned' | 'assigned';
type SortKey = 'urgent' | 'newest' | 'oldest' | 'longest';

type Action = 'assign' | 'status' | 'resolve' | 'close';

export function LandlordMaintenance() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const { data, loading, error, reload } = useApi(() => landlordApi.maintenance(), []);
  const requests = useMemo(() => data ?? [], [data]);

  const [q, setQ] = useState('');
  const [status, setStatus] = useState<StatusFilter>('open');
  const [priority, setPriority] = useState<PriorityFilter>('all');
  const [property, setProperty] = useState('all');
  const [category, setCategory] = useState<CategoryFilter>('all');
  const [assigned, setAssigned] = useState<AssignedFilter>('all');
  const [sort, setSort] = useState<SortKey>('urgent');

  const [expOpen, setExpOpen] = useState(false);
  const [creating, setCreating] = useState(false);
  const [actionTarget, setActionTarget] = useState<{ req: MaintenanceRequest; action: Action } | null>(null);

  const properties = useMemo(
    () => ['all', ...new Set(requests.map((r) => r.property?.name).filter(Boolean) as string[])],
    [requests],
  );

  const recentVendors: RecentVendor[] = useMemo(() => {
    const seen = new Map<string, RecentVendor>();
    requests.forEach((r) => {
      if (r.assignee_name && !seen.has(r.assignee_name)) {
        seen.set(r.assignee_name, { name: r.assignee_name, phone: r.assignee_phone, type: r.assignee_type, category: r.category });
      }
    });
    return [...seen.values()];
  }, [requests]);

  const kpi = useMemo(() => {
    const open = requests.filter(isOpen).length;
    const urgent = requests.filter(isUrgent).length;
    const inProgress = requests.filter((r) => r.status === 'in_progress').length;
    const waiting = requests.filter((r) => r.status === 'waiting').length;
    const now = new Date();
    const resolvedThisMonth = requests.filter((r) => {
      if (!r.resolved_at) return false;
      const d = new Date(r.resolved_at);
      return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth();
    }).length;
    return { open, urgent, inProgress, waiting, resolvedThisMonth };
  }, [requests]);

  const filtered = useMemo(() => {
    let list = requests.slice();
    if (status === 'open') list = list.filter(isOpen);
    else if (status !== 'all') list = list.filter((r) => r.status === status);
    if (priority !== 'all') list = list.filter((r) => r.priority === priority);
    if (property !== 'all') list = list.filter((r) => r.property?.name === property);
    if (category !== 'all') list = list.filter((r) => r.category === category);
    if (assigned === 'unassigned') list = list.filter((r) => !r.assignee_name);
    else if (assigned === 'assigned') list = list.filter((r) => !!r.assignee_name);
    if (q.trim()) {
      const needle = q.trim().toLowerCase();
      list = list.filter((r) => [r.title, r.tenant?.full_name, r.property?.name, r.unit?.unit_number, maintenanceCategoryLabel[r.category], r.assignee_name, String(r.id)]
        .filter(Boolean).join(' ').toLowerCase().includes(needle));
    }
    list.sort((a, b) => {
      switch (sort) {
        case 'newest': return +new Date(b.submitted_at ?? b.created_at) - +new Date(a.submitted_at ?? a.created_at);
        case 'oldest': return +new Date(a.submitted_at ?? a.created_at) - +new Date(b.submitted_at ?? b.created_at);
        case 'longest': {
          const ao = isOpen(a) ? 0 : 1, bo = isOpen(b) ? 0 : 1;
          return ao - bo || +new Date(a.submitted_at ?? a.created_at) - +new Date(b.submitted_at ?? b.created_at);
        }
        case 'urgent':
        default:
          return PRIORITY_RANK[a.priority] - PRIORITY_RANK[b.priority] || +new Date(b.submitted_at ?? b.created_at) - +new Date(a.submitted_at ?? a.created_at);
      }
    });
    return list;
  }, [requests, status, priority, property, category, assigned, q, sort]);

  async function quickAcknowledge(req: MaintenanceRequest) {
    try {
      await landlordApi.updateMaintenanceStatus(req.id, { status: 'acknowledged' });
      toast('Request acknowledged', 'success');
      reload();
    } catch {
      toast('Could not acknowledge. Please try again.', 'error');
    }
  }

  function cardQuickActions(r: MaintenanceRequest) {
    const btn = (action: Action | 'acknowledge' | 'message', label: string, icon: React.ReactNode) => (
      <button key={action} className="iconbtn" title={label} onClick={(e) => {
        e.stopPropagation();
        if (action === 'acknowledge') return quickAcknowledge(r);
        if (action === 'message') return navigate(`/app/maintenance/${r.id}?tab=messages`);
        setActionTarget({ req: r, action: action as Action });
      }}>{icon}</button>
    );
    if (r.status === 'open') return [btn('acknowledge', 'Acknowledge', <IconEye />), btn('assign', 'Assign', <IconHandshake />)];
    if (r.status === 'acknowledged') return [btn('assign', 'Assign', <IconHandshake />), btn('message', 'Message tenant', <IconMsg />)];
    if (r.status === 'assigned' || r.status === 'in_progress') return [btn('status', 'Update status', <IconRenew />), btn('message', 'Message tenant', <IconMsg />)];
    if (r.status === 'waiting') return [btn('message', 'Message', <IconMsg />), btn('status', 'Update status', <IconRenew />)];
    if (r.status === 'resolved') return [btn('close', 'Close request', <IconArchive />)];
    return [];
  }

  function onActionDone(updated: MaintenanceRequest) {
    setActionTarget(null);
    reload();
    void updated;
  }

  const hasAny = requests.length > 0;

  if (loading) return <div className="wmnt"><LoadingState label="Loading maintenance requests…" /></div>;
  if (error) return <div className="wmnt"><ErrorState message={error.message} onRetry={reload} /></div>;

  return (
    <div className="wmnt">
      <div className="glass pagehead">
        <header>
          <div className="eyebrow">Operations</div>
          <h1 className="page">Maintenance</h1>
          <p className="lede">Track repair requests by tenant, property, unit, priority, status, and resolution history. Every request is traceable from report to resolution.</p>
        </header>
        <div className="acts">
          <button className="btn btn-g" onClick={() => setExpOpen((v) => !v)}><IconExport /> Export</button>
          <button className="btn btn-p" onClick={() => setCreating(true)}><IconPlus /> Create request</button>
        </div>
      </div>

      <section className="cards">
        <Card cls={kpi.open > 0 ? 'info' : 'good'} k="Open requests" v={kpi.open} n="not yet resolved" />
        <Card cls={kpi.urgent > 0 ? 'bad' : 'good'} k="Urgent" v={kpi.urgent} n="need immediate attention" help={help.maintenancePriority} />
        <Card cls="warn" k="In progress" v={kpi.inProgress} n="being handled now" />
        <Card cls={kpi.waiting > 0 ? 'warn' : 'good'} k="Waiting" v={kpi.waiting} n="need response or a visit" />
        <Card cls="good" k="Resolved this month" v={kpi.resolvedThisMonth} n="completed this month" />
      </section>

      {expOpen && (
        <ExportPanel requests={requests} properties={properties.filter((p) => p !== 'all')} onClose={() => setExpOpen(false)} />
      )}

      <div className="filters glass-2">
        <div className="fsearch">
          <IconSearch />
          <input aria-label="Search maintenance requests" placeholder="Search by tenant, property, unit, issue, or request ID..." value={q} onChange={(e) => setQ(e.target.value)} />
        </div>
        <Sel label="Filter by status" value={status} onChange={(v) => setStatus(v as StatusFilter)} options={[
          ['open', 'Open requests'], ['all', 'All statuses'],
          ...(Object.keys(maintenanceStatusLabel) as MaintenanceStatus[]).map((s): [string, string] => [s, maintenanceStatusLabel[s]]),
        ]} />
        <Sel label="Filter by priority" value={priority} onChange={(v) => setPriority(v as PriorityFilter)} options={[['all', 'All priorities'], ...(Object.keys(maintenancePriorityLabel) as MaintenancePriority[]).map((p): [string, string] => [p, maintenancePriorityLabel[p]])]} />
        <Sel label="Filter by property" value={property} onChange={setProperty} options={properties.map((p): [string, string] => [p, p === 'all' ? 'All properties' : p])} />
        <Sel label="Filter by category" value={category} onChange={(v) => setCategory(v as CategoryFilter)} options={[['all', 'All categories'], ...(Object.keys(maintenanceCategoryLabel) as MaintenanceCategory[]).map((c): [string, string] => [c, maintenanceCategoryLabel[c]])]} />
        <Sel label="Filter by assignment" value={assigned} onChange={(v) => setAssigned(v as AssignedFilter)} options={[['all', 'Anyone'], ['unassigned', 'Unassigned'], ['assigned', 'Assigned']]} />
        <Sel label="Sort requests" value={sort} onChange={(v) => setSort(v as SortKey)} options={[['urgent', 'Urgent first'], ['newest', 'Newest'], ['oldest', 'Oldest'], ['longest', 'Unresolved longest']]} />
      </div>

      {!hasAny ? (
        <Empty title="No maintenance requests yet" body="Requests will appear here when tenants report repairs. You can also create a request to log work manually." onCreate={() => setCreating(true)} />
      ) : filtered.length === 0 ? (
        <Empty title="No requests match your filters" body="Try changing the status, property, priority, category, or date range." onClear={() => { setQ(''); setStatus('open'); setPriority('all'); setProperty('all'); setCategory('all'); setAssigned('all'); setSort('urgent'); }} />
      ) : (
        <>
          <div className="filtered-note">
            <IconInfo />
            <div><b>{filtered.length}</b> {filtered.length === 1 ? 'request' : 'requests'}{status === 'open' ? ' · open' : status !== 'all' ? ` · ${maintenanceStatusLabel[status]}` : ''}{property !== 'all' ? ` · ${property}` : ''}. Click any card for the full report, media, and activity trail.</div>
          </div>
          <div className="mlist">
            {filtered.map((r) => {
              const Icon = CATEGORY_ICON[r.category];
              const spine = r.priority === 'urgent' ? 'var(--wm-oxblood)' : r.priority === 'high' ? 'var(--wm-amber)' : r.priority === 'medium' ? 'var(--wm-petrol-2)' : 'var(--wm-slate)';
              const photoCount = r.media?.length ?? 0;
              return (
                <div
                  key={r.id}
                  className="mcard glass"
                  role="button"
                  tabIndex={0}
                  onClick={() => navigate(`/app/maintenance/${r.id}`)}
                  onKeyDown={(e) => {
                    if (e.target !== e.currentTarget) return;
                    if (e.key === 'Enter' || e.key === ' ') {
                      e.preventDefault();
                      navigate(`/app/maintenance/${r.id}`);
                    }
                  }}
                >
                  <span className="spine" style={{ background: spine }} />
                  <div className="cat" style={{ background: CATEGORY_TINT[r.category], color: CATEGORY_COLOR[r.category] }}><Icon /></div>
                  <div className="body">
                    <div className="ttl">{r.title}</div>
                    <div className="tags">
                      <span className={`prio-flag ${PRIORITY_CLASS[r.priority]}`}>{r.priority === 'urgent' ? <IconWarn /> : <IconFlag />}{maintenancePriorityLabel[r.priority]}</span>
                      <span className={`badge ${STATUS_BADGE[r.status]}`}><span className="dot" />{maintenanceStatusLabel[r.status]}</span>
                      <span className="badge b-gray">{maintenanceCategoryLabel[r.category]}</span>
                    </div>
                    <div className="loc">{r.property?.name ?? '—'} · {r.unit?.unit_number ? `Unit ${r.unit.unit_number}` : '—'}</div>
                    <div className="meta">
                      <span>{r.tenant?.full_name ?? 'Tenant'}</span><span className="d" />
                      <span>reported {formatDate(r.submitted_at ?? r.created_at)}</span><span className="d" />
                      <span>updated {timeAgo(r.submitted_at ?? r.created_at)}</span>
                      {photoCount > 0 && <><span className="d" /><span className="ph"><IconCamera />{photoCount}</span></>}
                    </div>
                  </div>
                  <div className="side">
                    <div className="assignee">
                      {r.assignee_name ? <>Assigned to<br /><b>{r.assignee_name}</b></> : isOpen(r) ? <span style={{ color: 'var(--wm-oxblood)' }}>Unassigned</span> : null}
                      {r.appointment_at && <div style={{ fontSize: 11, color: 'var(--wm-mute)', marginTop: 2 }}>visit {formatDate(r.appointment_at)}</div>}
                    </div>
                    <div className="qacts">
                      {cardQuickActions(r)}
                      <button className="btn btn-g sm" onClick={(e) => { e.stopPropagation(); navigate(`/app/maintenance/${r.id}`); }}>Details <IconChevRight /></button>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        </>
      )}

      <div className="foot glass-2">
        <IconShield />
        <div>Statuses, assignments, messages, photos, and costs are all backed by real records. The activity trail is append-only: resolutions and reopenings are recorded as new events, never erased.</div>
      </div>

      {creating && (
        <CreateRequestModalHost onClose={() => setCreating(false)} onCreated={(created, mode) => {
          setCreating(false);
          reload();
          if (mode === 'assign') navigate(`/app/maintenance/${created.id}?assign=1`);
          else navigate(`/app/maintenance/${created.id}`);
        }} />
      )}

      {actionTarget?.action === 'assign' && (
        <AssignVendorModal request={actionTarget.req} recentVendors={recentVendors} onClose={() => setActionTarget(null)} onDone={onActionDone} />
      )}
      {actionTarget?.action === 'status' && (
        <UpdateStatusModal request={actionTarget.req} onClose={() => setActionTarget(null)} onDone={onActionDone} />
      )}
      {actionTarget?.action === 'resolve' && (
        <ResolveModal request={actionTarget.req} onClose={() => setActionTarget(null)} onDone={onActionDone} />
      )}
      {actionTarget?.action === 'close' && (
        <CloseModal request={actionTarget.req} onClose={() => setActionTarget(null)} onDone={onActionDone} />
      )}
    </div>
  );
}

function CreateRequestModalHost({ onClose, onCreated }: {
  onClose: () => void;
  onCreated: (created: MaintenanceRequest, mode: 'new' | 'assign' | 'resolved') => void;
}) {
  const { data } = useApi(() => landlordApi.contracts(), []);
  const active = useMemo(() => (data ?? []).filter((c) => c.status === 'active'), [data]);
  return <CreateRequestModal contracts={active} onClose={onClose} onCreated={onCreated} />;
}

function Card({ cls, k, v, n, help: helpText }: { cls: string; k: string; v: number; n: string; help?: string }) {
  return (
    <div className={`card glass-2 ${cls}`}>
      <span className="edge" />
      <div className="k inline-flex items-center gap-1">
        {k}
        {helpText && <InfoHint text={helpText} label={`About ${k}`} />}
      </div>
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

function Empty({ title, body, onCreate, onClear }: { title: string; body: string; onCreate?: () => void; onClear?: () => void }) {
  return (
    <div className="empty glass">
      <div className="ei"><IconWrench /></div>
      <div className="et">{title}</div>
      <div className="em">{body}</div>
      <div className="eacts">
        {onCreate && <button className="btn btn-p" onClick={onCreate}><IconPlus /> Create request</button>}
        {onClear && <button className="btn btn-g" onClick={onClear}>Clear filters</button>}
      </div>
    </div>
  );
}

function ExportPanel({ requests, properties, onClose }: { requests: MaintenanceRequest[]; properties: string[]; onClose: () => void }) {
  const { toast } = useToast();
  // Associates the visible "Reason for export" label with its input.
  const reasonId = useId();
  const [scope, setScope] = useState<'filtered' | 'property' | 'tenant' | 'single' | 'full'>('full');
  const [target, setTarget] = useState('');
  const [reason, setReason] = useState('');
  const [cert, setCert] = useState<{ id: string; count: number; checksum: string; at: string } | null>(null);
  const tenants = useMemo(() => [...new Set(requests.map((r) => r.tenant?.full_name).filter(Boolean) as string[])], [requests]);

  async function generate() {
    try {
      const params: Parameters<typeof landlordApi.exportMaintenance>[0] = { scope, reason: reason || undefined };
      if (scope === 'property') {
        const p = requests.find((r) => r.property?.name === target)?.property_id;
        if (!p) { toast('Choose a property', 'error'); return; }
        params.property_id = p;
      }
      if (scope === 'tenant') {
        const t = requests.find((r) => r.tenant?.full_name === target)?.tenant_id;
        if (!t) { toast('Choose a tenant', 'error'); return; }
        params.tenant_id = t;
      }
      if (scope === 'single') {
        const id = Number(target);
        if (!id) { toast('Choose a request', 'error'); return; }
        params.maintenance_request_id = id;
      }
      const result = await landlordApi.exportMaintenance(params);
      const url = URL.createObjectURL(result.blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = result.filename;
      a.click();
      URL.revokeObjectURL(url);
      setCert({ id: result.checksum.slice(0, 8).toUpperCase(), count: result.rowCount, checksum: result.checksum, at: new Date().toISOString() });
      toast(`Export generated · ${result.rowCount} records (CSV)`, 'success');
    } catch {
      toast('Could not generate the export.', 'error');
    }
  }

  return (
    <div className="exp glass open">
      <div className="exp-inner">
        <div className="ph">
          <div><h3>Export maintenance records</h3><p style={{ fontSize: 12.5, color: 'var(--wm-slate)', marginTop: 2 }}>Generate a CSV with a real SHA-256 checksum of the exported bytes.</p></div>
          <button className="iconbtn" aria-label="Close" onClick={onClose}><IconChevDown /></button>
        </div>
        <div className="exp-grid">
          <div className="opts" role="radiogroup" aria-label="Export scope">
            {([
              ['filtered', 'Current filtered list', 'Whatever the list is showing now (all statuses/properties)'],
              ['single', 'Single request report', 'One request, by ID'],
              ['property', 'Property maintenance history', 'Every request for one property'],
              ['tenant', 'Tenant maintenance history', 'Every request reported by one tenant'],
              ['full', 'Full maintenance log', 'Every request across the portfolio'],
            ] as [typeof scope, string, string][]).map(([v, t, d]) => (
              <div
                key={v}
                className={`opt${scope === v ? ' on' : ''}`}
                role="radio"
                aria-checked={scope === v}
                tabIndex={0}
                onClick={() => { setScope(v); setTarget(''); }}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    setScope(v);
                    setTarget('');
                  }
                }}
              >
                <div className="rd" /><div><div className="ot">{t}</div><div className="od">{d}</div></div>
              </div>
            ))}
          </div>
          <div>
            {(scope === 'property' || scope === 'tenant' || scope === 'single') && (
              <div className="field">
                <label>{scope === 'property' ? 'Property' : scope === 'tenant' ? 'Tenant' : 'Request'}</label>
                <select value={target} onChange={(e) => setTarget(e.target.value)}>
                  <option value="">Select...</option>
                  {scope === 'property' && properties.map((p) => <option key={p} value={p}>{p}</option>)}
                  {scope === 'tenant' && tenants.map((t) => <option key={t} value={t}>{t}</option>)}
                  {scope === 'single' && requests.map((r) => <option key={r.id} value={r.id}>{r.id} · {r.title}</option>)}
                </select>
              </div>
            )}
            <div className="field">
              <label htmlFor={reasonId}>Reason for export (recorded)</label>
              <input id={reasonId} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Insurance claim, accounting, dispute record" />
            </div>
            <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 14 }}>
              <button className="btn btn-p" onClick={generate}><IconExport /> Generate export</button>
            </div>
          </div>
        </div>
        {cert && (
          <div className="cert">
            <div className="ct">
              <IconShield /> Export certificate
              <InfoHint text={help.maintenanceExport} label="About the export certificate" />
            </div>
            <div className="crow"><span className="k">Records</span><span className="v">{cert.count}</span></div>
            <div className="crow"><span className="k">Format</span><span className="v">CSV</span></div>
            <div className="crow"><span className="k">Generated at</span><span className="v">{formatDate(cert.at)}</span></div>
            <div className="crow"><span className="k">Integrity</span><span className="v"><span className="badge b-green"><IconEye />SHA-256 verified</span></span></div>
            <div className="crow">
              <span className="k inline-flex items-center gap-1">
                Checksum
                <InfoHint text={help.exportChecksum} label="About the checksum" />
              </span>
              <span className="v cksum">{cert.checksum}</span>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
