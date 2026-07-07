/**
 * ApplicationDetail — the tenant's per-application workspace.
 *
 * Real data only: GET /tenant/applications/{id} (listing + documents + requests
 * + timeline). Actions call real endpoints: withdraw, delete-draft, and
 * per-application document upload/replacement. Downloadable copy is generated
 * client-side from the real record.
 */
import { useCallback, useRef, useState } from 'react';
import { useParams, useNavigate, Link } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { tenantApi } from '@/lib/endpoints';
import { formatDate, formatDateTime, formatCedisDecimal } from '@/lib/format';
import { useToast } from '@/components/ui/toast';
import { LoadingState, ErrorState } from '@/components/ui/states';
import {
  IconChevronLeft,
  IconCheck,
  IconUpload,
  IconDownload,
  IconMessage,
  IconAlertTriangle,
  IconCircleCheck,
  IconClock,
  IconDoc,
} from '@/components/ui/icons';
import { InfoHint } from '@/components/ui/InfoHint';
import { help, type HelpKey } from '@/lib/helpText';
import type {
  Application,
  ApplicationRequestItem,
  TenantDocument,
  DocumentType,
} from '@/lib/types';
import {
  STATUS_LABEL,
  STATUS_ROLE,
  canWithdraw,
  homeTitle,
  unitLabel,
  homeAddress,
  rentAmount,
  progressText,
  reviewSteps,
  APP_DOC_REQUIREMENTS,
} from './applicationHelpers';
import './applications.css';

/* Maps an application's status to its verified help-copy entry (ApplicationStatus enum). */
const STATUS_HELP_KEY: Record<Application['status'], HelpKey> = {
  draft: 'appDraft',
  submitted: 'appSubmitted',
  in_review: 'appSubmitted',
  landlord_review: 'appSubmitted',
  needs_action: 'appNeedsAction',
  approved: 'appApproved',
  rejected: 'appRejected',
  withdrawn: 'appWithdrawn',
};

/* ── Action box ──────────────────────────────────────────────────────────── */

function actionBox(app: Application): {
  cls: 'good' | 'warn' | 'info';
  title: string;
  sub: string;
} {
  switch (app.status) {
    case 'needs_action': {
      const open = (app.requests ?? []).find((r) => !r.is_resolved);
      return {
        cls: 'warn',
        title: 'The landlord needs something from you',
        sub: open?.message ?? 'Please provide the requested information below.',
      };
    }
    case 'approved':
      return {
        cls: 'good',
        title: 'Application approved',
        sub: `Your application for ${homeTitle(app)} was approved. The landlord may send you a lease agreement through Wyncrest.`,
      };
    case 'rejected':
      return {
        cls: 'info',
        title: 'Not selected',
        sub: app.decision_reason
          ? `The landlord did not move forward. Reason: ${app.decision_reason}`
          : 'The landlord did not move forward with this application.',
      };
    case 'withdrawn':
      return { cls: 'info', title: 'Application withdrawn', sub: 'You withdrew this application.' };
    case 'draft':
      return { cls: 'info', title: 'Draft in progress', sub: 'Finish your application and submit it to the landlord.' };
    default:
      return {
        cls: 'good',
        title: 'No action needed right now',
        sub: 'Your application is under review. You will be notified if the landlord needs anything else.',
      };
  }
}

/* ── Document uploader (per requirement) ─────────────────────────────────── */

function DocSlot({
  app,
  requirement,
  existing,
  onUploaded,
}: {
  app: Application;
  requirement: { key: DocumentType; label: string; required: boolean; rule: string };
  existing: TenantDocument | undefined;
  onUploaded: () => void;
}) {
  const { toast } = useToast();
  const inputRef = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);

  async function handle(file: File) {
    if (busy) return;
    const ok = file.type.startsWith('image/') || file.type === 'application/pdf';
    if (!ok) { toast('Please upload a PDF or image file.', 'error'); return; }
    if (file.size > 10 * 1024 * 1024) { toast('Maximum file size is 10 MB.', 'error'); return; }
    setBusy(true);
    try {
      await tenantApi.uploadApplicationDocument(app.id, file, requirement.key);
      toast(`${requirement.label} uploaded.`, 'success');
      onUploaded();
    } catch {
      toast('Upload failed. Please try again.', 'error');
    } finally {
      setBusy(false);
      if (inputRef.current) inputRef.current.value = '';
    }
  }

  const badge = existing
    ? (existing.is_verified ? { c: 'verified', t: 'Verified' } : { c: 'uploaded', t: 'Uploaded' })
    : (requirement.required ? { c: 'missing', t: 'Missing' } : { c: 'optional', t: 'Optional' });

  return (
    <div className="wapp-docr">
      <div className="dic"><IconDoc size={18} aria-hidden="true" /></div>
      <div className="dm">
        <div className="dt">{requirement.label}{requirement.required ? '' : ' (optional)'}</div>
        <div className="ds">
          {existing ? `${existing.original_filename} · ${formatDate(existing.created_at)}` : requirement.rule}
        </div>
      </div>
      <span className={`wapp-docbadge ${badge.c}`}>{badge.t}</span>
      <span className="dact">
        <input
          ref={inputRef}
          type="file"
          accept=".pdf,image/*"
          style={{ display: 'none' }}
          onChange={(e) => { const f = e.target.files?.[0]; if (f) void handle(f); }}
          disabled={busy}
        />
        <button
          type="button"
          className={`wapp-btn wapp-btn-sm ${existing ? 'wapp-btn-glass' : 'wapp-btn-primary'}`}
          onClick={() => inputRef.current?.click()}
          disabled={busy}
        >
          <IconUpload size={14} aria-hidden="true" />
          {busy ? 'Uploading…' : existing ? 'Replace' : 'Upload'}
        </button>
      </span>
    </div>
  );
}

/* ── Requests ────────────────────────────────────────────────────────────── */

function RequestCard({ request }: { request: ApplicationRequestItem }) {
  const who = request.requester_role === 'landlord' ? 'Landlord' : 'Wyncrest';
  return (
    <div className={`wapp-reqcard${request.is_resolved ? ' resolved' : ''}`}>
      <div className="rqh">
        <span className="who">{who}</span>
        {request.type === 'document_replacement' ? 'Document replacement' : 'More information'}
        {request.is_resolved && <IconCircleCheck size={15} aria-hidden="true" style={{ color: 'var(--color-success-600)' }} />}
      </div>
      <div className="rqd">
        {formatDate(request.created_at)}
        {request.due_at && !request.is_resolved ? ` · due ${formatDate(request.due_at)}` : ''}
        {request.is_resolved ? ' · resolved' : ''}
      </div>
      <div className="rqm">{request.message}</div>
      {request.reason && <div className="rqr">Reason: <b>{request.reason}</b></div>}
    </div>
  );
}

/* ── Download copy (from real data) ──────────────────────────────────────── */

function downloadCopy(app: Application) {
  const f = app.form_data ?? {};
  const lines = [
    'WYNCREST RENTAL APPLICATION',
    `Application #${app.id}`,
    '',
    `${homeTitle(app)}${unitLabel(app) ? `, ${unitLabel(app)}` : ''}`,
    homeAddress(app),
    '',
    `Status: ${STATUS_LABEL[app.status]}`,
    rentAmount(app) ? `Rent: ${formatCedisDecimal(rentAmount(app))}/month` : '',
    app.submitted_at ? `Submitted: ${formatDate(app.submitted_at)}` : '',
    '',
    `Applicant: ${[f.personal?.first, f.personal?.last].filter(Boolean).join(' ') || '—'}`,
    `Email: ${f.personal?.email ?? '—'}`,
    `Phone: ${f.personal?.phone ?? '—'}`,
    `Employment: ${f.employment?.status ?? '—'}`,
    `Employer: ${f.employment?.employer ?? '—'}`,
    f.employment?.income ? `Monthly income: GH₵ ${f.employment.income}` : '',
    '',
    'Documents:',
    ...(app.documents ?? []).map((d) => `- ${d.document_type}: ${d.original_filename}`),
  ].filter((l) => l !== '');

  const blob = new Blob([lines.join('\n')], { type: 'text/plain' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `wyncrest-application-${app.id}.txt`;
  a.click();
  URL.revokeObjectURL(a.href);
}

/* ── Page ────────────────────────────────────────────────────────────────── */

export function ApplicationDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { toast } = useToast();
  const appId = Number(id);

  const q = useApi(() => tenantApi.application(appId), [appId]);
  const reload = useCallback(() => q.reload(), [q]);
  const [acting, setActing] = useState(false);

  if (q.loading) return <div className="wapp"><LoadingState label="Loading application…" /></div>;
  if (q.error || !q.data) {
    return (
      <div className="wapp">
        <ErrorState title="Couldn't load application" message={q.error?.message ?? 'Not found'} onRetry={reload} />
      </div>
    );
  }

  const app = q.data;
  const ab = actionBox(app);
  const steps = reviewSteps(app);
  const f = app.form_data ?? {};
  const docsByType = (t: DocumentType) => (app.documents ?? []).find((d) => d.document_type === t);
  const openRequests = (app.requests ?? []).filter((r) => !r.is_resolved);

  async function handleWithdraw() {
    if (acting) return;
    if (!window.confirm('Withdraw this application? You can still download a copy for your records.')) return;
    setActing(true);
    try {
      await tenantApi.withdrawApplication(app.id);
      toast('Application withdrawn.', 'success');
      reload();
    } catch {
      toast('Could not withdraw. Please try again.', 'error');
    } finally {
      setActing(false);
    }
  }

  async function handleDelete() {
    if (acting) return;
    if (!window.confirm('Delete this draft? This cannot be undone.')) return;
    setActing(true);
    try {
      await tenantApi.deleteApplicationDraft(app.id);
      toast('Draft deleted.', 'success');
      navigate('/app/applications');
    } catch {
      toast('Could not delete draft.', 'error');
      setActing(false);
    }
  }

  return (
    <div className="wapp">
      <div className="wapp-crumb">
        <button className="wapp-back" onClick={() => navigate('/app/applications')} type="button">
          <IconChevronLeft size={15} aria-hidden="true" /> Back to Applications
        </button>
        <span className="sep">/</span>
        <span>{homeTitle(app)}</span>
      </div>

      {/* Header */}
      <section className="wapp-glass wapp-dhead">
        <span className="wapp-eyebrow">Application #{app.id}</span>
        <h1 className="wapp-dh-title">{homeTitle(app)}{unitLabel(app) ? `, ${unitLabel(app)}` : ''}</h1>
        <div className="wapp-dh-addr">{homeAddress(app) || '—'}</div>
        <div className="wapp-dh-meta">
          <span className={`wapp-pill ${STATUS_ROLE[app.status]}`}><span className="sd" />{STATUS_LABEL[app.status]}</span>
          <InfoHint text={help[STATUS_HELP_KEY[app.status]]} label={`About ${STATUS_LABEL[app.status]}`} />
          {rentAmount(app) && <span>{formatCedisDecimal(rentAmount(app))}/mo</span>}
          <span>{app.submitted_at ? `Submitted ${formatDate(app.submitted_at)}` : `Started ${formatDate(app.created_at)}`}</span>
        </div>
        <div className="wapp-dh-actions">
          {app.status === 'draft' ? (
            <Link to={`/app/applications/${app.id}/apply`} className="wapp-btn wapp-btn-primary">Continue application</Link>
          ) : (
            <button type="button" className="wapp-btn wapp-btn-glass" onClick={() => downloadCopy(app)}>
              <IconDownload size={15} aria-hidden="true" /> Download copy
            </button>
          )}
          <Link to="/app/messages" className="wapp-btn wapp-btn-glass">
            <IconMessage size={15} aria-hidden="true" /> Message landlord
          </Link>
          {app.status === 'draft' && (
            <button type="button" className="wapp-btn wapp-btn-danger" onClick={handleDelete} disabled={acting}>
              Delete draft
            </button>
          )}
          {canWithdraw(app.status) && (
            <button type="button" className="wapp-btn wapp-btn-danger" onClick={handleWithdraw} disabled={acting}>
              Withdraw application
            </button>
          )}
        </div>
      </section>

      {/* Action box */}
      <section className={`wapp-glass wapp-actionbox ${ab.cls}`}>
        <div className="ic">
          {ab.cls === 'good' ? <IconCircleCheck size={24} /> : ab.cls === 'warn' ? <IconAlertTriangle size={24} /> : <IconClock size={24} />}
        </div>
        <div style={{ flex: 1 }}>
          <div className="t">{ab.title}</div>
          <div className="s">{ab.sub}</div>
        </div>
      </section>

      {/* Progress */}
      <section className="wapp-glass wapp-sec">
        <div className="wapp-sec-h">Application progress<span className="hint">{progressText(app)}</span></div>
        <div className="wapp-stepper">
          {steps.map((s, i) => (
            <div key={i} className={`wapp-stp ${s.state}`}>
              <div className="dot">{s.state === 'done' && <IconCheck size={14} aria-hidden="true" />}</div>
              <div className="slt">{s.label}</div>
            </div>
          ))}
        </div>
      </section>

      {/* Summary */}
      <section className="wapp-glass wapp-sec">
        <div className="wapp-sec-h">Application summary</div>
        <div className="wapp-two">
          <div className="wapp-subcard">
            <div className="sch"><span className="sct">Applicant</span></div>
            <KV k="Name" v={[f.personal?.first, f.personal?.last].filter(Boolean).join(' ')} />
            <KV k="Email" v={f.personal?.email} />
            <KV k="Phone" v={f.personal?.phone} />
            <KV k="Preferred contact" v={f.contact?.pref} />
          </div>
          <div className="wapp-subcard">
            <div className="sch"><span className="sct">Employment &amp; income</span></div>
            <KV k="Status" v={f.employment?.status} />
            <KV k="Employer" v={f.employment?.employer} />
            <KV k="Monthly income" v={f.employment?.income ? `GH₵ ${f.employment.income}` : undefined} />
          </div>
          <div className="wapp-subcard">
            <div className="sch"><span className="sct">Household</span></div>
            <KV k="Adults" v={f.household?.adults} />
            <KV k="Children" v={f.household?.children} />
            <KV k="Pets" v={f.household?.pets} />
            <KV k="Vehicles" v={f.household?.vehicles} />
          </div>
          <div className="wapp-subcard">
            <div className="sch"><span className="sct">Property</span></div>
            <KV k="Home" v={`${homeTitle(app)}${unitLabel(app) ? `, ${unitLabel(app)}` : ''}`} />
            <KV k="Rent" v={rentAmount(app) ? `${formatCedisDecimal(rentAmount(app))}/mo` : undefined} />
            <KV k="Requested move-in" v={f.rental?.moveIn} />
          </div>
        </div>
      </section>

      {/* Documents */}
      <section className="wapp-glass wapp-sec">
        <div className="wapp-sec-h">
          Documents
          <span className="hint">Upload, replace and track the files this home needs</span>
        </div>
        {app.status === 'withdrawn' || app.status === 'rejected' ? (
          <div className="wapp-lockmsg">This application is closed. Documents can no longer be changed.</div>
        ) : (
          <div className="wapp-doctable">
            {APP_DOC_REQUIREMENTS.map((r) => (
              <DocSlot key={r.key} app={app} requirement={r} existing={docsByType(r.key)} onUploaded={reload} />
            ))}
          </div>
        )}
      </section>

      {/* Requests */}
      {(app.requests ?? []).length > 0 && (
        <section className="wapp-glass wapp-sec">
          <div className="wapp-sec-h">
            Requests
            {openRequests.length > 0 && <span className="hint">{openRequests.length} open</span>}
          </div>
          {(app.requests ?? []).map((r) => <RequestCard key={r.id} request={r} />)}
        </section>
      )}

      {/* Timeline */}
      <section className="wapp-glass wapp-sec">
        <div className="wapp-sec-h">
          Timeline
          <InfoHint text={help.tenantVisibleActivity} label="About timeline" />
          <span className="hint">What has happened so far</span>
        </div>
        {(app.events ?? []).length === 0 ? (
          <div className="wapp-lockmsg">No activity yet.</div>
        ) : (
          <div className="wapp-tl">
            {(app.events ?? []).map((ev) => {
              const cls = ev.event === 'rejected' || ev.event === 'withdrawn' ? 'danger'
                : ev.event === 'approved' || ev.event === 'request_resolved' ? 'success' : '';
              return (
                <div key={ev.id} className={`wapp-tl-item ${cls}`}>
                  <div className="te">{ev.description}</div>
                  <div className="tm">{formatDateTime(ev.created_at)}</div>
                </div>
              );
            })}
          </div>
        )}
      </section>
    </div>
  );
}

function KV({ k, v }: { k: string; v: string | null | undefined }) {
  return (
    <div className="wapp-kv">
      <span className="kk">{k}</span>
      <span className="vv">{v || '—'}</span>
    </div>
  );
}
