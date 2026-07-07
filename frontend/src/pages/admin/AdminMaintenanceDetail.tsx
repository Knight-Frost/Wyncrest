/**
 * Admin Maintenance case detail — routed full page (/app/maintenance/:id),
 * sharing the same White Liquid Glass system as AdminMaintenanceQueue
 * (admin-maintenance.css, scoped `.wamnt`). Five tabs: Overview, Evidence,
 * Timeline, Admin Notes, Export.
 *
 * Deliberately NOT built: a Messages tab (Conversation is a strict two-party
 * model and Admin is a separate model from User — joining the existing
 * tenant<->landlord thread as a third party would need new modeling that was
 * explicitly scoped out) and a Dispute tab (no dispute concept exists
 * anywhere in this app). A dedicated Audit Log tab was also left out — the
 * platform-wide /app/audit page already covers this subject via its filters.
 */
import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { adminApi } from '@/lib/endpoints';
import { useAuth } from '@/context/auth';
import { adminHasCapability } from '@/lib/permissions';
import { useToast } from '@/components/ui/toast';
import { ErrorState, LoadingState } from '@/components/ui/states';
import { formatCents, formatDate, formatDateTime } from '@/lib/format';
import type { AdminMaintenanceDetail as CaseDetail, MaintenanceCategory, MaintenancePriority } from '@/lib/types';
import {
  maintenanceCategoryLabel, maintenancePriorityLabel, maintenanceStatusLabel,
  CATEGORY_ICON, CATEGORY_TINT, CATEGORY_COLOR, PRIORITY_CLASS, STATUS_BADGE, isOpen,
} from './maintenance-helpers';
import {
  IconBack, IconInfo, IconWarn, IconFlag, IconUser, IconActivity, IconCamera,
  IconArchive, IconRenew, IconExport, IconCheck, IconPlus, IconEye,
} from '@/pages/landlord/maintenance-ui';
import { AuthedImage } from '@/components/media/AuthedImage';
import './admin-maintenance.css';

type TabKey = 'overview' | 'evidence' | 'timeline' | 'notes' | 'export';

const TABS: { key: TabKey; label: string }[] = [
  { key: 'overview', label: 'Overview' },
  { key: 'evidence', label: 'Evidence' },
  { key: 'timeline', label: 'Timeline' },
  { key: 'notes', label: 'Admin Notes' },
  { key: 'export', label: 'Export' },
];

export function AdminMaintenanceDetail() {
  const { id } = useParams();
  const caseId = String(id);
  const navigate = useNavigate();
  const { user } = useAuth();
  const { toast } = useToast();
  const canManage = adminHasCapability(user, 'manage_maintenance');

  const { data: response, loading, error, reload } = useApi(() => adminApi.maintenanceDetail(caseId), [caseId]);
  const c = response?.data;

  const [tab, setTab] = useState<TabKey>('overview');
  const [busy, setBusy] = useState(false);
  const [reasonPrompt, setReasonPrompt] = useState<'escalate' | 'override-close' | 'override-reopen' | null>(null);
  const [reason, setReason] = useState('');

  if (loading) return <div className="wamnt"><LoadingState label="Loading case…" /></div>;
  if (error) return <div className="wamnt"><ErrorState message={error.message} onRetry={reload} /></div>;
  if (!c) {
    return (
      <div className="wamnt">
        <button className="back" onClick={() => navigate('/app/maintenance')}><IconBack /> Maintenance</button>
        <div className="empty glass"><div className="et">Case not found</div></div>
      </div>
    );
  }

  const cat = c.category as MaintenanceCategory | null;
  const Icon = cat ? CATEGORY_ICON[cat] : IconWarn;
  const prio = c.priority as MaintenancePriority | null;

  async function assignToMe() {
    if (!user || user.role !== 'admin') return;
    setBusy(true);
    try {
      await adminApi.assignMaintenanceCaseOwner(caseId, user.id);
      toast('Case assigned to you', 'success');
      reload();
    } catch {
      toast('Could not assign the case.', 'error');
    } finally {
      setBusy(false);
    }
  }

  async function clearEscalation() {
    setBusy(true);
    try {
      await adminApi.clearMaintenanceEscalation(caseId);
      toast('Escalation cleared', 'success');
      reload();
    } catch {
      toast('Could not clear the escalation.', 'error');
    } finally {
      setBusy(false);
    }
  }

  async function confirmReasonAction() {
    if (!reason.trim() || !reasonPrompt) return;
    setBusy(true);
    try {
      if (reasonPrompt === 'escalate') await adminApi.escalateMaintenance(caseId, reason.trim());
      if (reasonPrompt === 'override-close') await adminApi.overrideCloseMaintenance(caseId, reason.trim());
      if (reasonPrompt === 'override-reopen') await adminApi.overrideReopenMaintenance(caseId, reason.trim());
      toast('Done', 'success');
      setReasonPrompt(null);
      setReason('');
      reload();
    } catch {
      toast('That action could not be completed.', 'error');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="wamnt">
      <button className="back" onClick={() => navigate('/app/maintenance')}><IconBack /> Maintenance</button>

      <div className="dhead glass">
        <div className="dcat" style={{ background: cat ? CATEGORY_TINT[cat] : undefined, color: cat ? CATEGORY_COLOR[cat] : undefined }}><Icon /></div>
        <div className="dmain">
          <h2>{c.title}</h2>
          <div className="dtags">
            {prio && <span className={`prio-flag ${PRIORITY_CLASS[prio]}`}>{prio === 'urgent' ? <IconWarn /> : <IconFlag />}{maintenancePriorityLabel[prio]} priority</span>}
            {c.status && <span className={`badge ${STATUS_BADGE[c.status as keyof typeof STATUS_BADGE]}`}><span className="dot" />{maintenanceStatusLabel[c.status as keyof typeof maintenanceStatusLabel]}</span>}
            {cat && <span className="badge b-gray">{maintenanceCategoryLabel[cat]}</span>}
            {c.is_overdue && <span className="badge b-red"><IconWarn />Overdue</span>}
          </div>
          <div className="dloc">{c.property ?? '—'} · {c.tenant?.name ?? '—'} · {c.landlord?.name ?? '—'}</div>
          <div className="drep">{c.submitted_at ? `Reported ${formatDateTime(c.submitted_at)}` : ''} · {c.age_days} {c.age_days === 1 ? 'day' : 'days'} open</div>
        </div>
        {canManage && (
          <div className="dside">
            <div className="row">
              <button className="btn btn-g sm" disabled={busy} onClick={assignToMe}><IconUser /> {c.handling_admin ? 'Reassign to me' : 'Assign to me'}</button>
              {c.escalated_at
                ? <button className="btn btn-g sm" disabled={busy} onClick={clearEscalation}><IconCheck /> Clear escalation</button>
                : <button className="btn btn-g sm" disabled={busy} onClick={() => setReasonPrompt('escalate')}><IconWarn /> Escalate</button>}
              {isOpen(c)
                ? <button className="btn btn-d sm" disabled={busy} onClick={() => setReasonPrompt('override-close')}><IconArchive /> Override close</button>
                : <button className="btn btn-g sm" disabled={busy} onClick={() => setReasonPrompt('override-reopen')}><IconRenew /> Override reopen</button>}
            </div>
          </div>
        )}
      </div>

      {c.escalated_at && (
        <div className="escband">
          <IconWarn />
          <div>
            <div className="et">Escalated {formatDateTime(c.escalated_at)}</div>
            <div className="em">{c.escalation_reason}</div>
          </div>
        </div>
      )}

      <div className="ownercard">
        {c.handling_admin ? (
          <>
            <div className="oa">{c.handling_admin.name.split(' ').filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase()}</div>
            <div><div className="on">{c.handling_admin.name}</div><div className="om">Owns this case</div></div>
          </>
        ) : (
          <div className="om"><IconInfo /> No admin currently owns this case.</div>
        )}
      </div>

      {reasonPrompt && (
        <div className="panel glass">
          <div className="ph"><h3>{reasonPrompt === 'escalate' ? 'Escalate this case' : reasonPrompt === 'override-close' ? 'Override-close this case' : 'Override-reopen this case'}</h3></div>
          <div className="field">
            <label>Reason (recorded in the audit log{reasonPrompt !== 'escalate' ? ' and the tenant/landlord-visible timeline' : ''})</label>
            <textarea value={reason} onChange={(e) => setReason(e.target.value)} rows={3} />
          </div>
          <div style={{ display: 'flex', gap: 9, marginTop: 12, justifyContent: 'flex-end' }}>
            <button className="btn btn-g" onClick={() => { setReasonPrompt(null); setReason(''); }}>Cancel</button>
            <button className="btn btn-p" disabled={busy || !reason.trim()} onClick={confirmReasonAction}><IconCheck /> Confirm</button>
          </div>
        </div>
      )}

      <div className="tabs glass-2">
        {TABS.map((t) => (
          <button key={t.key} className={tab === t.key ? 'on' : ''} onClick={() => setTab(t.key)}>
            {t.label}
            {t.key === 'evidence' && c.media.length > 0 && <span className="n">{c.media.length}</span>}
            {t.key === 'notes' && c.admin_notes.length > 0 && <span className="n">{c.admin_notes.length}</span>}
          </button>
        ))}
      </div>

      <div>
        {tab === 'overview' && <TabOverview c={c} />}
        {tab === 'evidence' && <TabEvidence c={c} />}
        {tab === 'timeline' && <TabTimeline c={c} />}
        {tab === 'notes' && <TabNotes c={c} canManage={canManage} caseId={caseId} onAdded={reload} />}
        {tab === 'export' && <TabExport caseId={caseId} canExport={canManage} />}
      </div>
    </div>
  );
}

function TabOverview({ c }: { c: CaseDetail }) {
  return (
    <div className="grid2">
      <div className="panel glass">
        <div className="ph"><h3>Issue</h3></div>
        <div className="section-t">Description</div>
        <p className="prose">{c.description}</p>
        {c.has_severe_safety_flag && (
          <div className="notice warn" style={{ marginTop: 16 }}><IconWarn /><div className="nt"><b>Severe safety flag raised</b> on this request.</div></div>
        )}
        {c.waiting_reason && (
          <div className="notice warn" style={{ marginTop: 16 }}><IconInfo /><div className="nt"><b>Waiting:</b> {c.waiting_reason}</div></div>
        )}
        {c.resolution_notes && (
          <div style={{ marginTop: 16 }}>
            <div className="section-t">Resolution</div>
            <p className="prose">{c.resolution_notes}</p>
            {c.resolved_at && <div style={{ fontSize: 12, color: 'var(--wam-mute)', marginTop: 6 }}>Marked {formatDateTime(c.resolved_at)}</div>}
          </div>
        )}
      </div>
      <div className="panel glass">
        <div className="ph"><h3>Case summary</h3></div>
        <Row k="Property" v={c.property} />
        <Row k="Tenant" v={c.tenant?.name} />
        <Row k="Landlord" v={c.landlord?.name} />
        <Row k="Assignee (landlord's)" v={c.assignee_name} />
        <Row k="Appointment" v={c.appointment_at ? formatDateTime(c.appointment_at) : null} />
        <Row k="Expected completion" v={c.expected_completion_date ? formatDate(c.expected_completion_date) : null} />
        <Row k="Total cost" v={c.total_cost_cents !== null ? formatCents(c.total_cost_cents) : null} />
        <Row k="Case owner" v={c.handling_admin?.name} />
        <Row k="Case ID" v={c.id} />
      </div>
    </div>
  );
}

function TabEvidence({ c }: { c: CaseDetail }) {
  if (c.media.length === 0) {
    return (
      <div className="empty glass">
        <div className="ei"><IconCamera /></div>
        <div className="et">No photos yet</div>
        <div className="em">Evidence uploaded by the tenant or landlord will appear here.</div>
      </div>
    );
  }
  return (
    <div className="panel glass">
      <div className="ph"><h3>Photo evidence</h3></div>
      <div className="mediagrid">
        {c.media.map((m) => (
          <div className="mtile" key={m.id}>
            <div className="img"><AuthedImage fetcher={() => adminApi.mediaBlob(m.id)} alt={m.caption || 'Evidence photo'} className="imgfill" /></div>
            <div className="cap">
              <div className="cw">{m.caption || 'Evidence photo'}</div>
              <div className="ct">{formatDate(m.created_at)}</div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

function TabTimeline({ c }: { c: CaseDetail }) {
  return (
    <div className="panel glass" style={{ maxWidth: 760 }}>
      <div className="ph"><h3>Timeline</h3><p>Read-only, tenant and landlord visible.</p></div>
      <div className="tl">
        {c.events.length === 0 ? (
          <p className="prose" style={{ fontSize: 13 }}>No activity recorded yet.</p>
        ) : c.events.map((e) => (
          <div className="ev" key={e.id}>
            <div className="edot"><IconActivity /></div>
            <div className="et"><span className="who">{e.actor_name ?? (e.actor_type ? 'User' : 'System')}</span> · {e.description}</div>
            <div className="ed">{formatDateTime(e.created_at)}</div>
          </div>
        ))}
      </div>
      <div className="notice info" style={{ marginTop: 16 }}><IconInfo /><div className="nt">This trail is append-only. Admin overrides appear here with the admin as actor; escalation and case-ownership changes are internal-only and do not appear on this tenant/landlord-visible timeline.</div></div>
    </div>
  );
}

function TabNotes({ c, canManage, caseId, onAdded }: { c: CaseDetail; canManage: boolean; caseId: string; onAdded: () => void }) {
  const { toast } = useToast();
  const [body, setBody] = useState('');
  const [sending, setSending] = useState(false);

  async function addNote() {
    if (!body.trim()) return;
    setSending(true);
    try {
      await adminApi.addMaintenanceNote(caseId, body.trim());
      setBody('');
      toast('Note added', 'success');
      onAdded();
    } catch {
      toast('Could not add the note.', 'error');
    } finally {
      setSending(false);
    }
  }

  return (
    <div className="panel glass" style={{ maxWidth: 760 }}>
      <div className="ph"><h3>Admin notes</h3></div>
      <div className="notice info" style={{ marginBottom: 14 }}><IconEye /><div className="nt">Internal only — never shown to the tenant or landlord.</div></div>
      {c.admin_notes.length === 0 ? (
        <p className="prose" style={{ fontSize: 13 }}>No internal notes yet.</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {c.admin_notes.map((n) => (
            <div key={n.id} className="notice warn">
              <IconInfo />
              <div className="nt"><b>{n.admin_name ?? 'Admin'}</b> · {formatDateTime(n.created_at)}<br />{n.body}</div>
            </div>
          ))}
        </div>
      )}
      {canManage && (
        <div className="composer" style={{ marginTop: 14 }}>
          <input placeholder="Add an internal note about this case..." value={body} onChange={(e) => setBody(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') addNote(); }} disabled={sending} />
          <button className="btn btn-p" onClick={addNote} disabled={sending}><IconPlus /> Add note</button>
        </div>
      )}
    </div>
  );
}

function TabExport({ caseId, canExport }: { caseId: string; canExport: boolean }) {
  const { toast } = useToast();
  const [cert, setCert] = useState<{ count: number; checksum: string } | null>(null);

  async function generate() {
    try {
      const result = await adminApi.exportMaintenance({ scope: 'single', maintenance_request_id: caseId });
      const url = URL.createObjectURL(result.blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = result.filename;
      a.click();
      URL.revokeObjectURL(url);
      setCert({ count: result.rowCount, checksum: result.checksum });
      toast('Export generated', 'success');
    } catch {
      toast('Could not generate the export.', 'error');
    }
  }

  if (!canExport) {
    return <div className="notice info"><IconInfo /><div className="nt">Exporting requires the manage_maintenance capability.</div></div>;
  }

  return (
    <div className="panel glass" style={{ maxWidth: 560 }}>
      <div className="ph"><h3>Export this case</h3></div>
      <p className="prose" style={{ marginBottom: 14 }}>Generate a CSV row for this case with a real SHA-256 checksum of the exported bytes.</p>
      <button className="btn btn-p" onClick={generate}><IconExport /> Generate CSV</button>
      {cert && (
        <div className="cert">
          <div className="ct"><IconCheck /> Export certificate</div>
          <div className="crow"><span className="k">Rows</span><span className="v">{cert.count}</span></div>
          <div className="crow"><span className="k">Checksum</span><span className="v cksum">{cert.checksum}</span></div>
        </div>
      )}
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
