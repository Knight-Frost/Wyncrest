/**
 * Landlord Maintenance detail — routed full page (/app/maintenance/:id),
 * faithfully ported from wyncrest-landlord-maintenance.html. Eight tabs:
 * Overview, Location, Tenant, Media, Assignment, Messages, Costs, Activity.
 */
import { useMemo, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { landlordApi } from '@/lib/endpoints';
import { useToast } from '@/components/ui/toast';
import { ErrorState, LoadingState } from '@/components/ui/states';
import { formatCents, formatDate, formatDateTime } from '@/lib/format';
import type { MaintenanceMessage, MaintenanceRequest } from '@/lib/types';
import {
  maintenanceCategoryLabel, maintenancePriorityLabel, maintenanceStatusLabel,
  CATEGORY_ICON, CATEGORY_TINT, CATEGORY_COLOR, PRIORITY_CLASS, STATUS_BADGE,
  isOpen, isFinal, assigneeTypeLabel, totalCost, locationLabel,
} from './maintenance-helpers';
import {
  IconBack, IconInfo, IconWarn, IconFlag, IconEye, IconHandshake, IconPlay, IconPause,
  IconCheck, IconArchive, IconRenew, IconX, IconHome, IconMsg, IconCash, IconActivity,
  IconCal, IconClock, IconCamera, IconPlus, IconReceipt, IconDoc,
} from './maintenance-ui';
import { AssignVendorModal, UpdateStatusModal, ResolveModal, CloseModal, ReopenModal, type RecentVendor } from './maintenance-modals';
import { AuthedImage } from '@/components/media/AuthedImage';
import { areaLabel, onsetLabel, accessLabel, visitLabel, safetyLabel } from '@/pages/tenant/maintenanceIntake';
import './maintenance.css';

type TabKey = 'overview' | 'location' | 'tenant' | 'media' | 'assignment' | 'messages' | 'costs' | 'activity';

const TABS: { key: TabKey; label: string }[] = [
  { key: 'overview', label: 'Overview' },
  { key: 'location', label: 'Location' },
  { key: 'tenant', label: 'Tenant' },
  { key: 'media', label: 'Media' },
  { key: 'assignment', label: 'Assignment' },
  { key: 'messages', label: 'Messages' },
  { key: 'costs', label: 'Costs' },
  { key: 'activity', label: 'Activity' },
];

type Action = 'assign' | 'status' | 'resolve' | 'close' | 'reopen';

export function LandlordMaintenanceDetail() {
  const { id } = useParams();
  const requestId = Number(id);
  const navigate = useNavigate();
  const [search] = useSearchParams();

  const { data: request, loading, error, reload } = useApi(() => landlordApi.maintenanceDetail(requestId), [requestId]);
  const vendorsApi = useApi(() => landlordApi.maintenance(), []);
  const recentVendors: RecentVendor[] = useMemo(() => {
    const seen = new Map<string, RecentVendor>();
    (vendorsApi.data ?? []).forEach((r) => {
      if (r.assignee_name && !seen.has(r.assignee_name)) {
        seen.set(r.assignee_name, { name: r.assignee_name, phone: r.assignee_phone, type: r.assignee_type, category: r.category });
      }
    });
    return [...seen.values()];
  }, [vendorsApi.data]);

  const [tab, setTab] = useState<TabKey>((search.get('tab') as TabKey) ?? 'overview');
  const [action, setAction] = useState<Action | null>(search.get('assign') ? 'assign' : null);

  if (loading) return <div className="wmnt"><LoadingState label="Loading request…" /></div>;
  if (error) return <div className="wmnt"><ErrorState message={error.message} onRetry={reload} /></div>;
  if (!request) {
    return (
      <div className="wmnt">
        <button className="back" onClick={() => navigate('/app/maintenance')}><IconBack /> Maintenance</button>
        <div className="empty glass"><div className="et">Request not found</div></div>
      </div>
    );
  }

  const Icon = CATEGORY_ICON[request.category];
  const sp = statusPanel(request);

  function done(updated: MaintenanceRequest) {
    setAction(null);
    reload();
    void updated;
  }

  return (
    <div className="wmnt">
      <button className="back" onClick={() => navigate('/app/maintenance')}><IconBack /> Maintenance</button>

      <div className="dhead glass">
        <div className="dcat" style={{ background: CATEGORY_TINT[request.category], color: CATEGORY_COLOR[request.category] }}><Icon /></div>
        <div className="dmain">
          <h2>{request.title}</h2>
          <div className="dtags">
            <span className={`prio-flag ${PRIORITY_CLASS[request.priority]}`}>{request.priority === 'urgent' ? <IconWarn /> : <IconFlag />}{maintenancePriorityLabel[request.priority]} priority</span>
            <span className={`badge ${STATUS_BADGE[request.status]}`}><span className="dot" />{maintenanceStatusLabel[request.status]}</span>
            <span className="badge b-gray">{maintenanceCategoryLabel[request.category]}</span>
            <span className="badge b-blue">MNT-{request.id}</span>
          </div>
          <div className="dloc">{locationLabel(request)}</div>
          <div className="drep">Reported by {request.reported_by === 'landlord' ? 'you' : (request.tenant?.full_name ?? 'tenant')} · {formatDateTime(request.submitted_at ?? request.created_at)}</div>
        </div>
        <div className="dside"><div className="row">{headerActions(request, setAction)}</div></div>
      </div>

      <div className={`spanel ${sp.cls}`}>
        <div className="spi">{sp.icon}</div>
        <div><div className="spt">{sp.title}</div><div className="spm">{sp.message}</div>{sp.next && <div className="spnext">{sp.next}</div>}</div>
      </div>

      <div className="qs">
        <Qsc k="Priority" ic={<IconFlag />} v={<span className={`prio-flag ${PRIORITY_CLASS[request.priority]}`} style={{ fontSize: 11 }}>{maintenancePriorityLabel[request.priority]}</span>} />
        <Qsc k="Status" ic={<IconRenew />} v={<span className={`badge ${STATUS_BADGE[request.status]}`} style={{ fontSize: 11 }}>{maintenanceStatusLabel[request.status]}</span>} />
        <Qsc k="Reported" ic={<IconCal />} v={formatDate(request.submitted_at ?? request.created_at)} data />
        <Qsc k="Category" ic={<Icon />} v={maintenanceCategoryLabel[request.category]} />
        <Qsc k="Assigned to" ic={<IconHandshake />} v={request.assignee_name ?? 'Unassigned'} />
        <Qsc k="Photos" ic={<IconCamera />} v={String(request.media?.length ?? 0)} data />
        {request.appointment_at && <Qsc k="Appointment" ic={<IconClock />} v={formatDateTime(request.appointment_at)} />}
        {request.total_cost_cents !== null && <Qsc k="Cost" ic={<IconCash />} v={formatCents(request.total_cost_cents)} data />}
      </div>

      <div className="tabs glass-2">
        {TABS.map((t) => (
          <button key={t.key} className={tab === t.key ? 'on' : ''} onClick={() => setTab(t.key)}>
            {t.label}
            {t.key === 'media' && (request.media?.length ?? 0) > 0 && <span className="n">{request.media!.length}</span>}
          </button>
        ))}
      </div>

      <div>
        {tab === 'overview' && <TabOverview request={request} />}
        {tab === 'location' && <TabLocation request={request} />}
        {tab === 'tenant' && <TabTenant request={request} onMessage={() => setTab('messages')} />}
        {tab === 'media' && <TabMedia request={request} onUploaded={reload} />}
        {tab === 'assignment' && <TabAssignment request={request} onAssign={() => setAction('assign')} onUpdateStatus={() => setAction('status')} onResolve={() => setAction('resolve')} />}
        {tab === 'messages' && <TabMessages request={request} />}
        {tab === 'costs' && <TabCosts request={request} onReload={reload} />}
        {tab === 'activity' && <TabActivity request={request} />}
      </div>

      {action === 'assign' && <AssignVendorModal request={request} recentVendors={recentVendors} onClose={() => setAction(null)} onDone={done} />}
      {action === 'status' && <UpdateStatusModal request={request} onClose={() => setAction(null)} onDone={done} />}
      {action === 'resolve' && <ResolveModal request={request} onClose={() => setAction(null)} onDone={done} />}
      {action === 'close' && <CloseModal request={request} onClose={() => setAction(null)} onDone={done} />}
      {action === 'reopen' && <ReopenModal request={request} onClose={() => setAction(null)} onDone={done} />}
    </div>
  );
}

/* ---- status panel ---------------------------------------------------------- */
function statusPanel(r: MaintenanceRequest): { cls: string; icon: React.ReactNode; title: string; message: string; next: string } {
  if ((r.priority === 'urgent') && isOpen(r)) {
    return {
      cls: 'sp-bad', icon: <IconWarn />, title: 'Emergency request',
      message: 'This issue may affect safety or property. Handle it immediately and document every action.',
      next: `Next step: ${r.assignee_name ? 'confirm the vendor is on the way and follow up until resolved.' : 'assign a vendor now and contact the tenant.'}`,
    };
  }
  switch (r.status) {
    case 'open': return { cls: 'sp-new', icon: <IconInfo />, title: 'This request is new', message: 'The tenant has reported a maintenance issue. Review the photos and acknowledge the request.', next: 'Next step: acknowledge, then assign someone to handle it.' };
    case 'acknowledged': return { cls: 'sp-new', icon: <IconEye />, title: 'Acknowledged', message: 'You have seen this request. It still needs someone assigned to carry out the work.', next: 'Next step: assign a vendor or staff member and schedule a visit.' };
    case 'assigned': return { cls: 'sp-warn', icon: <IconHandshake />, title: 'Assigned, work not started', message: 'A vendor has been assigned but work has not started yet.', next: `Next step: confirm the appointment${r.appointment_at ? ` on ${formatDateTime(r.appointment_at)}` : ''} or follow up.` };
    case 'in_progress': return { cls: 'sp-warn', icon: <IconPlay />, title: 'Work in progress', message: 'The repair is being carried out. Keep the activity trail and photos up to date.', next: 'Next step: add updates as work proceeds, then mark it resolved when complete.' };
    case 'waiting': return { cls: 'sp-warn', icon: <IconPause />, title: 'Waiting', message: r.waiting_reason || 'This request is paused, waiting on a response, part, or appointment.', next: 'Next step: follow up on what is blocking it, then continue the work.' };
    case 'resolved': return { cls: 'sp-good', icon: <IconCheck />, title: 'This request is resolved', message: `Repair was marked complete${r.resolved_at ? ` on ${formatDate(r.resolved_at)}` : ''}. Review the resolution note and close the request if no further action is needed.`, next: 'Next step: close the request to archive it, or reopen it if the issue returns.' };
    case 'closed': return { cls: 'sp-good', icon: <IconArchive />, title: 'This request is closed', message: 'The request has been reviewed and archived. Its full history is kept for your records.', next: 'If the same issue returns, reopen the request so the history stays connected.' };
    case 'cancelled': return { cls: 'sp-good', icon: <IconX />, title: 'This request was cancelled', message: 'This request was cancelled by the tenant. Its history is kept for your records.', next: 'You can reopen it if the issue comes back.' };
    default: return { cls: 'sp-new', icon: <IconInfo />, title: maintenanceStatusLabel[r.status], message: '', next: '' };
  }
}

function headerActions(r: MaintenanceRequest, setAction: (a: Action) => void): React.ReactNode {
  const b = (action: Action, label: string, icon: React.ReactNode, primary = false) => (
    <button key={action} className={`btn sm ${primary ? 'btn-p' : 'btn-g'}`} onClick={() => setAction(action)}>{icon} {label}</button>
  );
  if (r.status === 'open') return <>{b('status', 'Acknowledge', <IconEye />)}{b('assign', 'Assign', <IconHandshake />, true)}</>;
  if (r.status === 'acknowledged') return <>{b('assign', 'Assign', <IconHandshake />, true)}</>;
  if (r.status === 'assigned' || r.status === 'in_progress') return <>{b('status', 'Update status', <IconRenew />)}{b('resolve', 'Mark resolved', <IconCheck />, true)}</>;
  if (r.status === 'waiting') return <>{b('status', 'Update status', <IconRenew />, true)}</>;
  if (r.status === 'resolved') return <>{b('reopen', 'Reopen', <IconRenew />)}{b('close', 'Close request', <IconArchive />, true)}</>;
  if (r.status === 'closed' || r.status === 'cancelled') return <>{b('reopen', 'Reopen', <IconRenew />)}</>;
  return null;
}

function Qsc({ k, ic, v, data }: { k: string; ic: React.ReactNode; v: React.ReactNode; data?: boolean }) {
  return (
    <div className="qsc glass-2">
      <div className="qk">{ic}{k}</div>
      <div className={`qv${data ? ' data' : ''}`}>{v}</div>
    </div>
  );
}

/* ---- Overview --------------------------------------------------------------- */
function TabOverview({ request }: { request: MaintenanceRequest }) {
  return (
    <div className="grid2">
      <div className="panel glass">
        <div className="ph"><h3>Issue</h3></div>
        <div className="section-t">Description</div>
        <p className="prose">{request.description}</p>
        {request.safety_flags && request.safety_flags.length > 0 && (
          <div className={`notice ${request.has_severe_safety_flag ? 'warn' : 'info'}`} style={{ marginTop: 16 }}>
            {request.has_severe_safety_flag ? <IconWarn /> : <IconInfo />}
            <div className="nt"><b>Tenant flagged:</b> {request.safety_flags.map((f) => safetyLabel[f]).join(', ')}.</div>
          </div>
        )}
        {request.status === 'waiting' && request.waiting_reason && (
          <div className="notice warn" style={{ marginTop: 16 }}><IconPause /><div className="nt"><b>Waiting:</b> {request.waiting_reason}</div></div>
        )}
        {request.resolution_notes && (
          <div style={{ marginTop: 16 }}>
            <div className="section-t">Resolution</div>
            <p className="prose">{request.resolution_notes}</p>
            {request.resolved_at && <div style={{ fontSize: 12, color: 'var(--wm-mute)', marginTop: 6 }}>Marked {formatDateTime(request.resolved_at)}</div>}
          </div>
        )}
      </div>
      <div className="panel glass">
        <div className="ph"><h3>Summary</h3></div>
        <Row k="Issue" v={request.title} />
        <Row k="Category" v={maintenanceCategoryLabel[request.category]} />
        <Row k="Priority" v={<span className={`prio-flag ${PRIORITY_CLASS[request.priority]}`} style={{ fontSize: 11 }}>{maintenancePriorityLabel[request.priority]}</span>} />
        <Row k="Status" v={<span className={`badge ${STATUS_BADGE[request.status]}`}>{maintenanceStatusLabel[request.status]}</span>} />
        <Row k="Reported by" v={`${request.reported_by === 'landlord' ? 'You' : 'Tenant'} · ${request.tenant?.full_name ?? '—'}`} />
        <Row k="Reported" v={formatDateTime(request.submitted_at ?? request.created_at)} />
        {request.onset && <Row k="Issue started" v={onsetLabel[request.onset]} />}
        <Row k="Request ID" v={`MNT-${request.id}`} />
      </div>
    </div>
  );
}

/* ---- Location ----------------------------------------------------------------- */
function TabLocation({ request }: { request: MaintenanceRequest }) {
  return (
    <div className="grid2">
      <div className="panel glass">
        <div className="ph"><h3>Where the issue is</h3></div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 13, marginBottom: 14 }}>
          <div style={{ background: 'color-mix(in srgb, var(--wm-petrol-2) 12%, transparent)', color: 'var(--wm-petrol-2)', width: 48, height: 48, borderRadius: 12, display: 'grid', placeItems: 'center' }}><IconHome /></div>
          <div>
            <div style={{ fontFamily: 'var(--wm-serif)', fontWeight: 600, fontSize: 18, color: 'var(--wm-petrol)' }}>{request.property?.name ?? '—'}</div>
            <div style={{ fontSize: 13, color: 'var(--wm-slate)' }}>{request.unit?.unit_number ? `Unit ${request.unit.unit_number}` : '—'}</div>
          </div>
        </div>
        <Row k="Property" v={request.property?.name} />
        <Row k="Unit" v={request.unit?.unit_number ? `Unit ${request.unit.unit_number}` : null} />
        <Row k="Room / area" v={request.area ? areaLabel[request.area] : null} />
        <Row k="Specific spot" v={request.specific_location} />
        <Row k="Current tenant" v={request.tenant?.full_name} />
        <Row k="Contract" v={request.contract_id ? `${request.contract_id.slice(0, 8)}…` : null} />
      </div>
      <div className="panel glass">
        <div className="ph"><h3>Access &amp; scheduling</h3></div>
        <Row k="Entry when tenant is out" v={request.access_permission ? accessLabel[request.access_permission] : null} />
        <Row k="Preferred visit time" v={request.preferred_visit_window ? visitLabel[request.preferred_visit_window] : null} />
        {request.access_instructions && (
          <div style={{ marginTop: 12 }}>
            <div className="section-t">Access instructions from tenant</div>
            <p className="prose" style={{ fontSize: 13.5 }}>{request.access_instructions}</p>
          </div>
        )}
        {!request.access_permission && !request.access_instructions && (
          <p className="prose" style={{ fontSize: 13.5 }}>No access preferences were recorded. Confirm entry arrangements with the tenant before any visit.</p>
        )}
        {request.appointment_at && (
          <div className="notice info" style={{ marginTop: 14 }}><IconCal /><div className="nt"><b>Scheduled visit:</b> {formatDateTime(request.appointment_at)}{request.assignee_name ? ` with ${request.assignee_name}` : ''}.</div></div>
        )}
      </div>
    </div>
  );
}

/* ---- Tenant ------------------------------------------------------------------- */
function TabTenant({ request, onMessage }: { request: MaintenanceRequest; onMessage: () => void }) {
  return (
    <div className="grid2">
      <div className="panel glass">
        <div className="ph"><h3>Reported by</h3></div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 13, marginBottom: 14 }}>
          <div style={{ background: 'linear-gradient(150deg,var(--wm-petrol-2),var(--wm-petrol))', color: 'var(--color-on-action)', width: 48, height: 48, borderRadius: 12, display: 'grid', placeItems: 'center', fontWeight: 600 }}>
            {(request.tenant?.full_name ?? 'T').split(' ').filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase()}
          </div>
          <div>
            <div style={{ fontWeight: 600, fontSize: 16 }}>{request.tenant?.full_name ?? '—'}</div>
            <div style={{ fontSize: 12.5, color: 'var(--wm-slate)' }}>{locationLabel(request)}</div>
          </div>
        </div>
        <div className="kv-row"><span className="k">Phone</span><span className="v">{request.tenant?.phone ? <a href={`tel:${request.tenant.phone}`}>{request.tenant.phone}</a> : '—'}</span></div>
        <div className="kv-row"><span className="k">Email</span><span className="v">{request.tenant?.email ? <a href={`mailto:${request.tenant.email}`}>{request.tenant.email}</a> : '—'}</span></div>
        <Row k="Preferred contact" v="Message via Wyncrest" />
        <div style={{ display: 'flex', gap: 9, marginTop: 16 }}>
          <button className="btn btn-p" onClick={onMessage}><IconMsg /> Message tenant</button>
        </div>
      </div>
      <div className="panel glass">
        <div className="ph"><h3>Privacy</h3></div>
        <div className="notice info"><IconInfo /><div className="nt">This tab intentionally shows no rent or financial details. Maintenance and money are kept separate.</div></div>
      </div>
    </div>
  );
}

/* ---- Media -------------------------------------------------------------------- */
function TabMedia({ request, onUploaded }: { request: MaintenanceRequest; onUploaded: () => void }) {
  const { toast } = useToast();
  const [uploading, setUploading] = useState(false);
  const media = request.media ?? [];

  async function onFile(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    setUploading(true);
    try {
      await landlordApi.uploadMaintenanceMedia(request.id, file);
      toast('Photo uploaded', 'success');
      onUploaded();
    } catch {
      toast('Could not upload the photo. Please try again.', 'error');
    } finally {
      setUploading(false);
    }
  }

  const uploadBtn = (
    <label className="btn btn-g sm" style={{ cursor: 'pointer' }}>
      <IconPlus /> {uploading ? 'Uploading…' : 'Upload photo'}
      <input type="file" accept="image/*" hidden onChange={onFile} disabled={uploading} />
    </label>
  );

  if (media.length === 0) {
    return (
      <div className="empty glass">
        <div className="ei"><IconCamera /></div>
        <div className="et">No photos yet</div>
        <div className="em">Photos uploaded by the tenant or you will appear here. Upload before-and-after shots to keep a clear record.</div>
        <div className="eacts">{uploadBtn}</div>
      </div>
    );
  }

  return (
    <div className="panel glass">
      <div className="ph"><h3>Media &amp; proof</h3>{uploadBtn}</div>
      <div className="mediagrid">
        {media.map((m) => (
          <div className="mtile" key={m.id}>
            <div className="img"><AuthedImage fetcher={() => landlordApi.mediaBlob(m.id)} alt={m.caption || m.original_filename} className="imgfill" /></div>
            <div className="cap">
              <div className="cw">{m.caption || m.original_filename}</div>
              <div className="ct">{formatDate(m.created_at)}</div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

/* ---- Assignment --------------------------------------------------------------- */
function TabAssignment({ request, onAssign, onUpdateStatus, onResolve }: {
  request: MaintenanceRequest;
  onAssign: () => void;
  onUpdateStatus: () => void;
  onResolve: () => void;
}) {
  const workStatus = request.status === 'in_progress' ? 'Work in progress'
    : request.status === 'assigned' ? 'Scheduled, not started'
    : isFinal(request) ? 'Completed'
    : request.status === 'waiting' ? 'On hold' : 'Not started';

  return (
    <div className="grid2">
      <div className="panel glass">
        <div className="ph"><h3>Assignment</h3></div>
        {request.assignee_name ? (
          <>
            <div className="assign">
              <div className="va"><IconHandshake /></div>
              <div>
                <div className="vn">{request.assignee_name}</div>
                <div className="vm">{assigneeTypeLabel(request.assignee_type)}{request.assignee_phone && <> · <a href={`tel:${request.assignee_phone}`} style={{ color: 'var(--wm-petrol-2)' }}>{request.assignee_phone}</a></>}</div>
              </div>
              <div className="vr"><span className={`badge ${request.status === 'in_progress' ? 'b-amber' : isFinal(request) ? 'b-green' : 'b-purple'}`}>{workStatus}</span></div>
            </div>
            <Row k="Appointment" v={request.appointment_at ? formatDateTime(request.appointment_at) : 'Not scheduled'} />
            <Row k="Work status" v={workStatus} />
            <Row k="Expected completion" v={request.expected_completion_date ? formatDate(request.expected_completion_date) : null} />
            {request.waiting_reason && <Row k="Waiting on" v={request.waiting_reason} />}
          </>
        ) : (
          <div className="notice info"><IconInfo /><div className="nt"><b>No one assigned yet.</b> Assign a vendor or staff member so the tenant knows help is on the way.</div></div>
        )}
      </div>
      <div className="panel glass">
        <div className="ph"><h3>Manage</h3></div>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 9 }}>
          <button className={`btn ${request.assignee_name ? 'btn-g' : 'btn-p'}`} onClick={onAssign} style={{ justifyContent: 'flex-start' }}><IconHandshake /> {request.assignee_name ? 'Change vendor' : 'Assign vendor'}</button>
          {request.assignee_name && <button className="btn btn-g" onClick={onUpdateStatus} style={{ justifyContent: 'flex-start' }}><IconCal /> Update status or schedule</button>}
          {isOpen(request) && <button className="btn btn-g" onClick={onResolve} style={{ justifyContent: 'flex-start' }}><IconCheck /> Mark resolved</button>}
        </div>
      </div>
    </div>
  );
}

/* ---- Messages ------------------------------------------------------------------ */
function TabMessages({ request }: { request: MaintenanceRequest }) {
  const { toast } = useToast();
  const { data, loading, reload } = useApi(() => landlordApi.maintenanceMessages(request.id), [request.id]);
  const [body, setBody] = useState('');
  const [sending, setSending] = useState(false);
  const messages: MaintenanceMessage[] = data?.messages ?? [];

  async function send() {
    if (!body.trim()) return;
    setSending(true);
    try {
      await landlordApi.sendMaintenanceMessage(request.id, body.trim());
      setBody('');
      reload();
    } catch {
      toast('Could not send the message. Please try again.', 'error');
    } finally {
      setSending(false);
    }
  }

  return (
    <div className="panel glass" style={{ maxWidth: 760 }}>
      <div className="ph"><h3>Conversation</h3><span className="badge b-blue"><IconMsg />Linked to MNT-{request.id}</span></div>
      <div className="notice info" style={{ marginBottom: 14 }}><IconInfo /><div className="nt">These messages are attached to this request only, so the repair history stays in one place.</div></div>
      {loading ? <LoadingState label="Loading messages…" /> : (
        <div className="thread">
          {messages.length === 0 ? (
            <p className="prose" style={{ fontSize: 13, textAlign: 'center', padding: 20, color: 'var(--wm-slate)' }}>No messages yet. Start the conversation below.</p>
          ) : messages.map((m) => (
            <div className={`msg ${m.sender.is_me ? 'you' : 'them'}`} key={m.id}>
              <div>{m.body}</div>
              <div className="mm">{m.sender.name ?? (m.sender.is_me ? 'You' : 'Tenant')} · {formatDateTime(m.created_at)}</div>
            </div>
          ))}
        </div>
      )}
      <div className="composer">
        <input placeholder="Write a message about this request..." value={body} onChange={(e) => setBody(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') send(); }} disabled={sending} />
        <button className="btn btn-p" onClick={send} disabled={sending}><IconMsg /> Send</button>
      </div>
    </div>
  );
}

/* ---- Costs ---------------------------------------------------------------------- */
function TabCosts({ request, onReload }: { request: MaintenanceRequest; onReload: () => void }) {
  const { toast } = useToast();
  const [markingPaid, setMarkingPaid] = useState(false);

  if (request.total_cost_cents === null) {
    return (
      <div className="empty glass">
        <div className="ei"><IconCash /></div>
        <div className="et">No costs recorded yet</div>
        <div className="em">When this repair has a labour or parts cost, it is recorded when marking the request resolved.</div>
      </div>
    );
  }

  async function togglePaid() {
    setMarkingPaid(true);
    try {
      await landlordApi.updateMaintenanceCosts(request.id, { cost_paid: !request.cost_paid });
      onReload();
    } catch {
      toast('Could not update the cost record.', 'error');
    } finally {
      setMarkingPaid(false);
    }
  }

  return (
    <div className="grid2">
      <div className="panel glass">
        <div className="ph"><h3>Repair costs</h3><span className={`badge ${request.cost_paid ? 'b-green' : 'b-amber'}`}>{request.cost_paid ? 'Vendor paid' : 'Unpaid'}</span></div>
        <div className="costrow"><span className="ck">Labour</span><span className="cv">{formatCents(request.labor_cost_cents ?? 0)}</span></div>
        <div className="costrow"><span className="ck">Parts</span><span className="cv">{formatCents(request.parts_cost_cents ?? 0)}</span></div>
        {request.cost_notes && <div className="costrow"><span className="ck">Note</span><span className="cv" style={{ fontWeight: 400, color: 'var(--wm-slate)', maxWidth: '60%', textAlign: 'right' }}>{request.cost_notes}</span></div>}
        <div className="costtotal"><span className="k">Total repair cost</span><span className="v">{formatCents(totalCost(request))}</span></div>
        {request.invoice_reference && (
          <div className="receipt"><div className="ri"><IconDoc /></div><div style={{ flex: 1 }}><div style={{ fontWeight: 600, fontSize: 13.5 }}>Invoice</div><div style={{ fontSize: 12, color: 'var(--wm-slate)' }}>{request.invoice_reference}</div></div><IconReceipt /></div>
        )}
      </div>
      <div className="panel glass">
        <div className="ph"><h3>Record</h3></div>
        <Row k="Invoice" v={request.invoice_reference} />
        <Row k="Labour" v={formatCents(request.labor_cost_cents ?? 0)} />
        <Row k="Parts" v={formatCents(request.parts_cost_cents ?? 0)} />
        <Row k="Total" v={<b>{formatCents(totalCost(request))}</b>} />
        <Row k="Vendor bill" v={request.cost_paid ? 'Paid' : 'Outstanding'} />
        <button className="btn btn-g" onClick={togglePaid} disabled={markingPaid} style={{ width: '100%', justifyContent: 'center', marginTop: 14 }}>
          {markingPaid ? 'Updating…' : request.cost_paid ? 'Mark as unpaid' : 'Mark as paid'}
        </button>
      </div>
    </div>
  );
}

/* ---- Activity ------------------------------------------------------------------- */
function TabActivity({ request }: { request: MaintenanceRequest }) {
  const events = request.events ?? [];
  return (
    <div className="panel glass" style={{ maxWidth: 760 }}>
      <div className="ph"><h3>Activity trail</h3><p>Every event, in order</p></div>
      <div className="tl">
        {events.length === 0 ? (
          <p className="prose" style={{ fontSize: 13 }}>No activity recorded yet.</p>
        ) : events.map((e) => (
          <div className="ev" key={e.id}>
            <div className="edot"><IconActivity /></div>
            <div className="et"><span className="who">{e.actor?.full_name ?? (e.actor_type ? 'User' : 'System')}</span> · {e.description}</div>
            <div className="ed">{formatDateTime(e.created_at)}</div>
          </div>
        ))}
      </div>
      <div className="notice info" style={{ marginTop: 16 }}><IconInfo /><div className="nt">This trail is append-only. Resolutions, reopenings, and cancellations are added as new events so the full history is never erased.</div></div>
    </div>
  );
}

function Row({ k, v }: { k: string; v: React.ReactNode }) {
  return (
    <div className="kv-row">
      <span className="k">{k}</span>
      <span className="v">{v === null || v === undefined || v === '' ? '—' : v}</span>
    </div>
  );
}
