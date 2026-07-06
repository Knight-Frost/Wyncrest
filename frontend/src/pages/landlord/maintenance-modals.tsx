/**
 * Modals for the landlord Maintenance list + detail pages: assign a vendor,
 * update status (in-progress/waiting), mark resolved (with costs), close
 * (confirmation checklist), reopen, and the 3-step create-request wizard.
 * Scoped under `.wmnt`, own scrim/modal shell (ported 1:1 from the mockup),
 * not the shared Modal component — matches the established pattern used by
 * ledgerComponents.tsx / TenantDetail.tsx's local modal components.
 */
import { useEffect, useId, useRef, useState } from 'react';
import { landlordApi } from '@/lib/endpoints';
import { useToast } from '@/components/ui/toast';
import { maintenanceCategoryLabel, maintenancePriorityLabel } from '@/lib/statusMaps';
import type {
  Contract, MaintenanceAssigneeType, MaintenanceCategory, MaintenancePriority, MaintenanceRequest,
} from '@/lib/types';
import { IconCheck, IconHandshake, IconInfo, IconPause, IconWarn } from './maintenance-ui';

function Shell({ eyebrow, title, description, children, foot, onClose }: {
  eyebrow: string;
  title: string;
  description?: string;
  children: React.ReactNode;
  foot: React.ReactNode;
  onClose: () => void;
}) {
  // Dialog a11y — Escape closes, focus lands on the dialog when it opens, and
  // the visible title names it (mirrors the shared Modal component).
  const titleId = useId();
  const dialogRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    dialogRef.current?.focus();
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [onClose]);

  return (
    <div className="scrim on" onClick={(e) => e.target === e.currentTarget && onClose()}>
      <div ref={dialogRef} tabIndex={-1} className="modal" role="dialog" aria-modal="true" aria-labelledby={titleId}>
        <div className="mhead">
          <div className="eyebrow">{eyebrow}</div>
          <h3 id={titleId}>{title}</h3>
          {description && <p>{description}</p>}
        </div>
        <div className="mbody">{children}</div>
        <div className="mfoot">{foot}</div>
      </div>
    </div>
  );
}

export interface RecentVendor {
  name: string;
  phone: string | null;
  type: MaintenanceAssigneeType | null;
  category: MaintenanceCategory;
}

/* ---- Assign vendor -------------------------------------------------------- */
export function AssignVendorModal({ request, recentVendors, onClose, onDone }: {
  request: MaintenanceRequest;
  recentVendors: RecentVendor[];
  onClose: () => void;
  onDone: (updated: MaintenanceRequest) => void;
}) {
  const { toast } = useToast();
  const [name, setName] = useState(request.assignee_name ?? '');
  const [phone, setPhone] = useState(request.assignee_phone ?? '');
  const [type, setType] = useState<MaintenanceAssigneeType>(request.assignee_type ?? 'vendor');
  const [date, setDate] = useState(request.appointment_at ? request.appointment_at.slice(0, 10) : '');
  const [time, setTime] = useState(request.appointment_at ? request.appointment_at.slice(11, 16) : '10:00');
  const [expected, setExpected] = useState(request.expected_completion_date ?? '');
  const [saving, setSaving] = useState(false);

  const suggested = recentVendors.filter((v) => v.category === request.category);
  const others = recentVendors.filter((v) => v.category !== request.category);

  function pick(v: RecentVendor) {
    setName(v.name);
    setPhone(v.phone ?? '');
    setType(v.type ?? 'vendor');
  }

  async function submit() {
    if (!name.trim()) { toast('Enter a vendor or staff name', 'error'); return; }
    setSaving(true);
    try {
      const updated = await landlordApi.updateMaintenanceStatus(request.id, {
        status: 'assigned',
        assignee_name: name.trim(),
        assignee_phone: phone.trim() || undefined,
        assignee_type: type,
        appointment_at: date ? `${date}T${time}:00` : undefined,
        expected_completion_date: expected || undefined,
      });
      toast(`Assigned to ${name.trim()}`, 'success');
      onDone(updated);
    } catch {
      toast('Could not assign. Please try again.', 'error');
    } finally {
      setSaving(false);
    }
  }

  return (
    <Shell eyebrow="Assignment" title={request.assignee_name ? 'Change vendor' : 'Assign vendor'}
      description={`${request.title} · ${request.property?.name ?? ''} ${request.unit?.unit_number ?? ''}`} onClose={onClose}
      foot={<>
        <button className="btn btn-g" onClick={onClose} disabled={saving}>Cancel</button>
        <button className="btn btn-p" onClick={submit} disabled={saving}><IconHandshake /> {saving ? 'Assigning…' : 'Assign'}</button>
      </>}>
      {(suggested.length > 0 || others.length > 0) && (
        <div className="field">
          <label>Vendors you have used before</label>
          <div className="pick">
            {[...suggested, ...others].slice(0, 6).map((v, i) => (
              <button key={i} className={v.name === name ? 'on' : ''} onClick={() => pick(v)} type="button">
                {v.name}
                <div className="pd">{maintenanceCategoryLabel[v.category]}{v.phone ? ` · ${v.phone}` : ''}</div>
              </button>
            ))}
          </div>
        </div>
      )}
      <div className="field">
        <label>Vendor / staff name</label>
        <input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. AquaFix Plumbers" />
      </div>
      <div className="field">
        <label>Phone</label>
        <input value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="e.g. +233 30 291 4471" />
      </div>
      <div className="field">
        <label>Type</label>
        <select value={type} onChange={(e) => setType(e.target.value as MaintenanceAssigneeType)}>
          <option value="vendor">External vendor</option>
          <option value="staff">Staff</option>
        </select>
      </div>
      <div className="field">
        <label>Appointment date</label>
        <input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
      </div>
      <div className="field">
        <label>Appointment time</label>
        <input type="time" value={time} onChange={(e) => setTime(e.target.value)} />
      </div>
      <div className="field">
        <label>Expected completion date</label>
        <input type="date" value={expected} onChange={(e) => setExpected(e.target.value)} />
      </div>
      <div className="mnote"><IconInfo /><div>The tenant will be notified of the vendor and appointment. This records an activity entry and moves the request to Assigned.</div></div>
    </Shell>
  );
}

/* ---- Update status (in progress / waiting) -------------------------------- */
export function UpdateStatusModal({ request, onClose, onDone }: {
  request: MaintenanceRequest;
  onClose: () => void;
  onDone: (updated: MaintenanceRequest) => void;
}) {
  const { toast } = useToast();
  const [picked, setPicked] = useState<'in_progress' | 'waiting'>(request.status === 'waiting' ? 'waiting' : 'in_progress');
  const [reason, setReason] = useState(request.waiting_reason ?? '');
  const [saving, setSaving] = useState(false);

  async function submit() {
    if (picked === 'waiting' && !reason.trim()) { toast('Say what you are waiting on', 'error'); return; }
    setSaving(true);
    try {
      const updated = await landlordApi.updateMaintenanceStatus(request.id, {
        status: picked,
        waiting_reason: picked === 'waiting' ? reason.trim() : undefined,
      });
      toast(`Status updated to ${picked === 'waiting' ? 'Waiting' : 'In progress'}`, 'success');
      onDone(updated);
    } catch {
      toast('Could not update status. Please try again.', 'error');
    } finally {
      setSaving(false);
    }
  }

  return (
    <Shell eyebrow="Update status" title="Update status" description={`${request.title} · currently ${request.status}`} onClose={onClose}
      foot={<>
        <button className="btn btn-g" onClick={onClose} disabled={saving}>Cancel</button>
        <button className="btn btn-p" onClick={submit} disabled={saving}><IconCheck /> {saving ? 'Updating…' : 'Update status'}</button>
      </>}>
      <div className="pick" style={{ flexDirection: 'column' }}>
        <button className={picked === 'in_progress' ? 'on' : ''} onClick={() => setPicked('in_progress')} type="button" style={{ minWidth: '100%' }}>
          In progress<div className="pd">Work is happening</div>
        </button>
        <button className={picked === 'waiting' ? 'on' : ''} onClick={() => setPicked('waiting')} type="button" style={{ minWidth: '100%' }}>
          Waiting<div className="pd">Blocked on someone or a part</div>
        </button>
      </div>
      {picked === 'waiting' && (
        <div className="field" style={{ marginTop: 14 }}>
          <label>What are you waiting on?</label>
          <input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Replacement part on order" />
        </div>
      )}
    </Shell>
  );
}

/* ---- Mark resolved --------------------------------------------------------- */
export function ResolveModal({ request, onClose, onDone }: {
  request: MaintenanceRequest;
  onClose: () => void;
  onDone: (updated: MaintenanceRequest) => void;
}) {
  const { toast } = useToast();
  const [notes, setNotes] = useState('');
  const [labor, setLabor] = useState('');
  const [parts, setParts] = useState('');
  const [saving, setSaving] = useState(false);
  // Associate the visible labels with their controls (they're siblings, not wrappers).
  const noteId = useId();
  const laborId = useId();
  const partsId = useId();

  async function submit() {
    if (!notes.trim()) { toast('Add a resolution note', 'error'); return; }
    setSaving(true);
    try {
      const laborCents = labor ? Math.round(parseFloat(labor) * 100) : undefined;
      const partsCents = parts ? Math.round(parseFloat(parts) * 100) : undefined;
      const updated = await landlordApi.updateMaintenanceStatus(request.id, {
        status: 'resolved',
        resolution_notes: notes.trim(),
        labor_cost_cents: laborCents,
        parts_cost_cents: partsCents,
      });
      toast('Request marked resolved', 'success');
      onDone(updated);
    } catch {
      toast('Could not mark resolved. Please try again.', 'error');
    } finally {
      setSaving(false);
    }
  }

  return (
    <Shell eyebrow="Resolve" title="Mark resolved" description={`${request.title} · ${request.property?.name ?? ''} ${request.unit?.unit_number ?? ''}`} onClose={onClose}
      foot={<>
        <button className="btn btn-g" onClick={onClose} disabled={saving}>Cancel</button>
        <button className="btn btn-p" onClick={submit} disabled={saving}><IconCheck /> {saving ? 'Saving…' : 'Mark resolved'}</button>
      </>}>
      <div className="field">
        <label htmlFor={noteId}>Resolution note (recorded)</label>
        <textarea id={noteId} value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="What was done to fix it?" />
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <div className="field">
          <label htmlFor={laborId}>Labour cost (GH₵, optional)</label>
          <input id={laborId} type="number" min="0" step="0.01" value={labor} onChange={(e) => setLabor(e.target.value)} placeholder="0.00" />
        </div>
        <div className="field">
          <label htmlFor={partsId}>Parts cost (GH₵, optional)</label>
          <input id={partsId} type="number" min="0" step="0.01" value={parts} onChange={(e) => setParts(e.target.value)} placeholder="0.00" />
        </div>
      </div>
      <div className="mnote"><IconInfo /><div>Resolving records the work as complete and keeps the full history. Closing the request is a separate final step.</div></div>
    </Shell>
  );
}

/* ---- Close (checklist confirmation) ---------------------------------------- */
export function CloseModal({ request, onClose, onDone }: {
  request: MaintenanceRequest;
  onClose: () => void;
  onDone: (updated: MaintenanceRequest) => void;
}) {
  const { toast } = useToast();
  const [checks, setChecks] = useState([false, false, false]);
  const [saving, setSaving] = useState(false);
  const labels = ['Repair is complete', 'Tenant has been informed', 'Photos or notes added, if needed'];

  async function submit() {
    if (!checks.every(Boolean)) { toast('Confirm all three before closing', 'error'); return; }
    setSaving(true);
    try {
      const updated = await landlordApi.updateMaintenanceStatus(request.id, { status: 'closed' });
      toast('Request closed', 'success');
      onDone(updated);
    } catch {
      toast('Could not close the request. Please try again.', 'error');
    } finally {
      setSaving(false);
    }
  }

  return (
    <Shell eyebrow="Close request" title="Close maintenance request?" description={`${request.title} · ${request.property?.name ?? ''} ${request.unit?.unit_number ?? ''}`} onClose={onClose}
      foot={<>
        <button className="btn btn-g" onClick={onClose} disabled={saving}>Cancel</button>
        <button className="btn btn-p" onClick={submit} disabled={saving}><IconCheck /> {saving ? 'Closing…' : 'Close request'}</button>
      </>}>
      <div style={{ fontSize: 13, color: 'var(--wm-ink-2)', marginBottom: 12 }}>Before closing, confirm:</div>
      <div className="checklist">
        {labels.map((label, i) => (
          <label className="ci" key={i} onClick={() => setChecks((c) => c.map((v, j) => (i === j ? !v : v)))}>
            <span className={`cb${checks[i] ? ' on' : ''}`}><IconCheck /></span> {label}
          </label>
        ))}
      </div>
      <div className="mnote" style={{ marginTop: 14 }}><IconInfo /><div>Closing archives the request. The full history is kept.</div></div>
    </Shell>
  );
}

/* ---- Reopen ----------------------------------------------------------------- */
export function ReopenModal({ request, onClose, onDone }: {
  request: MaintenanceRequest;
  onClose: () => void;
  onDone: (updated: MaintenanceRequest) => void;
}) {
  const { toast } = useToast();
  const [reason, setReason] = useState('');
  const [saving, setSaving] = useState(false);

  async function submit() {
    if (!reason.trim()) { toast('Add a reason for reopening', 'error'); return; }
    setSaving(true);
    try {
      const updated = await landlordApi.reopenMaintenance(request.id, reason.trim());
      toast('Request reopened', 'success');
      onDone(updated);
    } catch {
      toast('Could not reopen the request. Please try again.', 'error');
    } finally {
      setSaving(false);
    }
  }

  return (
    <Shell eyebrow="Reopen" title="Reopen request" description={`${request.title} · ${request.property?.name ?? ''} ${request.unit?.unit_number ?? ''}`} onClose={onClose}
      foot={<>
        <button className="btn btn-g" onClick={onClose} disabled={saving}>Cancel</button>
        <button className="btn btn-p" onClick={submit} disabled={saving}>{saving ? 'Reopening…' : 'Reopen request'}</button>
      </>}>
      <div className="field">
        <label>Reason for reopening</label>
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. The issue returned after the repair." />
      </div>
      <div className="mnote"><IconWarn /><div>Reopening keeps all previous history and adds a new activity entry. The earlier resolution is never erased.</div></div>
    </Shell>
  );
}

/* ---- Create request (3 steps) ---------------------------------------------- */
const CATEGORY_OPTIONS = Object.keys(maintenanceCategoryLabel) as MaintenanceCategory[];
const PRIORITY_OPTIONS = Object.keys(maintenancePriorityLabel) as MaintenancePriority[];

export function CreateRequestModal({ contracts, onClose, onCreated }: {
  contracts: Contract[];
  onClose: () => void;
  onCreated: (created: MaintenanceRequest, mode: 'new' | 'assign' | 'resolved') => void;
}) {
  const { toast } = useToast();
  const [step, setStep] = useState(1);
  const [contractId, setContractId] = useState('');
  const [title, setTitle] = useState('');
  const [category, setCategory] = useState<MaintenanceCategory>('plumbing');
  const [priority, setPriority] = useState<MaintenancePriority>('medium');
  const [description, setDescription] = useState('');
  const [mode, setMode] = useState<'new' | 'assign' | 'resolved'>('new');
  const [saving, setSaving] = useState(false);

  const contract = contracts.find((c) => c.id === contractId);
  const contractLabel = (c: Contract) =>
    `${c.listing?.title ?? 'Listing'} · ${c.tenant?.full_name ?? c.tenant?.email ?? 'Tenant'}`;

  async function save() {
    if (!contract) return;
    setSaving(true);
    try {
      const created = await landlordApi.createLandlordMaintenance({
        contract_id: contract.id,
        title: title.trim(),
        description: description.trim() || undefined,
        category,
        priority,
        mode,
      });
      toast(`Request ${created.id} created`, 'success');
      onCreated(created, mode);
    } catch {
      toast('Could not create the request. Please try again.', 'error');
      setSaving(false);
    }
  }

  const steps = (
    <div className="steps">
      {[1, 2, 3].map((s) => <div key={s} className={`stp${step >= s ? ' on' : ''}`} />)}
    </div>
  );

  return (
    <Shell eyebrow="New request" title="Create maintenance request" onClose={onClose}
      foot={step === 1 ? (
        <>
          <button className="btn btn-g" onClick={onClose}>Cancel</button>
          <button className="btn btn-p" onClick={() => { if (!contractId) { toast('Select a unit', 'error'); return; } setStep(2); }}>Continue</button>
        </>
      ) : step === 2 ? (
        <>
          <button className="btn btn-g" onClick={() => setStep(1)}>Back</button>
          <button className="btn btn-p" onClick={() => { if (!title.trim()) { toast('Give the issue a title', 'error'); return; } setStep(3); }}>Continue</button>
        </>
      ) : (
        <>
          <button className="btn btn-g" onClick={() => setStep(2)} disabled={saving}>Back</button>
          <button className="btn btn-p" onClick={save} disabled={saving}><IconCheck /> {saving ? 'Creating…' : 'Create request'}</button>
        </>
      )}>
      {step === 1 && (<>
        {steps}
        <div className="section-t">Step 1 · Where is the issue?</div>
        <div className="field">
          <label>Property and unit</label>
          <select value={contractId} onChange={(e) => setContractId(e.target.value)}>
            <option value="">Select a unit...</option>
            {contracts.map((c) => <option key={c.id} value={c.id}>{contractLabel(c)}</option>)}
          </select>
        </div>
        <div className="mnote"><IconInfo /><div>Pick the unit the repair relates to. The tenant on that contract is linked automatically so the request stays traceable.</div></div>
      </>)}
      {step === 2 && (<>
        {steps}
        <div className="section-t">Step 2 · Describe the issue</div>
        {contract && <div style={{ fontSize: 12.5, color: 'var(--wm-slate)', marginBottom: 12 }}>{contractLabel(contract)}</div>}
        <div className="field">
          <label>Issue title</label>
          <input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="e.g. Kitchen sink leaking" />
        </div>
        <div className="field">
          <label>Category</label>
          <select value={category} onChange={(e) => setCategory(e.target.value as MaintenanceCategory)}>
            {CATEGORY_OPTIONS.map((c) => <option key={c} value={c}>{maintenanceCategoryLabel[c]}</option>)}
          </select>
        </div>
        <div className="field">
          <label>Priority</label>
          <div className="pick">
            {PRIORITY_OPTIONS.map((p) => (
              <button key={p} className={priority === p ? 'on' : ''} onClick={() => setPriority(p)} type="button" style={{ minWidth: 'calc(25% - 6px)', textAlign: 'center' }}>
                {maintenancePriorityLabel[p]}
              </button>
            ))}
          </div>
        </div>
        <div className="field">
          <label>Description</label>
          <textarea value={description} onChange={(e) => setDescription(e.target.value)} placeholder="What is the problem?" />
        </div>
      </>)}
      {step === 3 && contract && (<>
        {steps}
        <div className="section-t">Step 3 · Save or assign</div>
        <div className="panel" style={{ padding: '14px 16px', border: '1px solid var(--wm-line-2)', borderRadius: 12, marginBottom: 14 }}>
          <div style={{ fontWeight: 600, fontSize: 15 }}>{title || 'Untitled issue'}</div>
          <div style={{ fontSize: 12.5, color: 'var(--wm-slate)', marginTop: 3 }}>{maintenanceCategoryLabel[category]} · {maintenancePriorityLabel[priority]} priority</div>
          <div style={{ fontSize: 12.5, color: 'var(--wm-slate)', marginTop: 2 }}>{contractLabel(contract)}</div>
        </div>
        <div className="field">
          <label>How should this start?</label>
          <div className="pick" style={{ flexDirection: 'column' }}>
            <button className={mode === 'new' ? 'on' : ''} onClick={() => setMode('new')} type="button" style={{ minWidth: '100%' }}>
              Save as a new request<div className="pd">Log it now and assign someone later</div>
            </button>
            <button className={mode === 'assign' ? 'on' : ''} onClick={() => setMode('assign')} type="button" style={{ minWidth: '100%' }}>
              Save and assign a vendor<div className="pd">Go straight to assignment after saving</div>
            </button>
            <button className={mode === 'resolved' ? 'on' : ''} onClick={() => setMode('resolved')} type="button" style={{ minWidth: '100%' }}>
              Log as already resolved<div className="pd">For work that is already done</div>
            </button>
          </div>
        </div>
      </>)}
    </Shell>
  );
}

/* ---- Quick pause icon used by the waiting badge on cards ------------------- */
export { IconPause };
