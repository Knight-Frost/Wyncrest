/**
 * VerificationDetailPage — dedicated full-page case review for a single
 * identity verification request. Reached at /app/verifications/:id (linked
 * from every queue row). This is a routed page, not a drawer/modal/overlay —
 * per the project's dedicated-page pattern (see AuditLogDetail.tsx / the
 * /app/audit/:id precedent), styled as an editorial "white liquid glass"
 * command centre matching Listing Review's `.wlr` pattern (scoped `.wver`).
 *
 * Renders ONLY what GET /admin/verifications/{id} actually returns —
 * applicant profile, documents, computed checklist/warnings, real audit-log
 * history, previous attempts, and internal notes. Absent data renders a
 * truthful empty/missing state, never a fabricated value. Blockers/warnings
 * for the verdict banner are derived from the real checklist (`result`),
 * which mirrors exactly what VerificationService::approve() enforces
 * server-side (identity document on file + active account) — nothing here
 * invents a risk signal the backend doesn't actually compute.
 */
import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { adminApi } from '@/lib/endpoints';
import { normalizeError } from '@/lib/api';
import { formatDate, formatDateTime, timeAgo } from '@/lib/format';
import { useToast } from '@/components/ui/toast';
import { ErrorState, LoadingState } from '@/components/ui/states';
import { DocumentViewer } from './verification/DocumentViewer';
import { verificationStatusLabel, checklistResultLabel } from './verification/verificationVisuals';
import {
  WVIconBack,
  WVIconCheck,
  WVIconX,
  WVIconWarn,
  WVIconInfo,
  WVIconManual,
} from './wverIcons';
import type { ApiError, AdminVerificationDetail, VerificationChecklistItem } from '@/lib/types';
import './verification-review.css';

const SECTIONS = [
  ['summary', 'Summary'],
  ['applicant', 'Applicant'],
  ['documents', 'Documents'],
  ['checklist', 'Checklist'],
  ['warnings', 'Warnings'],
  ['history', 'History'],
  ['attempts', 'Previous attempts'],
  ['notes', 'Notes'],
  ['decision', 'Decision'],
] as const;

const TL_TONE: Record<string, string> = {
  critical: 'blood',
  warning: 'amber',
  info: '',
};

function avatarColor(isLandlord: boolean): string {
  return isLandlord ? 'var(--petrol)' : 'var(--slate)';
}

function initials(name: string): string {
  const parts = name.trim().split(/\s+/);
  return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase();
}

const CK_GLYPH: Record<VerificationChecklistItem['result'], React.ReactNode> = {
  passed: <WVIconCheck />,
  warning: <WVIconWarn />,
  failed: <WVIconX />,
  manual: <WVIconManual />,
  not_applicable: <WVIconInfo />,
};

function ChecklistCell({ item }: { item: VerificationChecklistItem }) {
  return (
    <div className={`ck ${item.result}`}>
      <span className="ci">{CK_GLYPH[item.result]}</span>
      <span className="ct">
        {item.label}
        {item.required && <span className="req" title="Required for this role">*</span>}
        <small>{item.detail}</small>
      </span>
      <span className="cr">{checklistResultLabel(item.result)}</span>
    </div>
  );
}

type ActionKind = 'approve' | 'reject' | 'request_info';

export function VerificationDetailPage() {
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const { toast } = useToast();
  const rootRef = useRef<HTMLDivElement>(null);

  const { data, loading, error, reload } = useApi<AdminVerificationDetail>(
    () => (id ? adminApi.verification(id) : Promise.reject({ status: 404, message: 'Invalid verification id.' })),
    [id],
  );

  const [activeKind, setActiveKind] = useState<ActionKind | null>(null);
  const [actionText, setActionText] = useState('');
  const [actionError, setActionError] = useState<string | undefined>();
  const [actionSubmitting, setActionSubmitting] = useState(false);

  const [noteBody, setNoteBody] = useState('');
  const [noteSubmitting, setNoteSubmitting] = useState(false);

  // Scrollspy for the sticky section nav.
  const [activeSec, setActiveSec] = useState<string>('summary');
  useEffect(() => {
    if (!data) return;
    const els = SECTIONS.map(([sid]) => document.getElementById(`vsec-${sid}`)).filter(Boolean) as HTMLElement[];
    if (!('IntersectionObserver' in window) || els.length === 0) return;
    const obs = new IntersectionObserver(
      (entries) => {
        entries.forEach((en) => {
          if (en.isIntersecting) setActiveSec(en.target.id.replace('vsec-', ''));
        });
      },
      { rootMargin: '-45% 0px -50% 0px' },
    );
    els.forEach((el) => obs.observe(el));
    return () => obs.disconnect();
  }, [data]);

  function goToSection(sid: string) {
    document.getElementById(`vsec-${sid}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  const vr = data;
  const userName = vr?.user?.full_name?.trim() || 'Name not provided';
  const isLandlord = vr?.user?.user_type === 'landlord';

  function selectAction(kind: ActionKind) {
    setActiveKind(kind);
    setActionText('');
    setActionError(undefined);
    goToSection('decision');
  }

  function quickApprove() {
    if (!blockers.length) selectAction('approve');
    else {
      toast('Resolve the blockers listed in the summary before approving.', 'error');
      goToSection('summary');
    }
  }

  async function submitAction() {
    if (!activeKind || !id) return;
    const trimmed = actionText.trim();
    const isApprove = activeKind === 'approve';
    const required = !isApprove;
    if (required && trimmed.length < 5) {
      setActionError('Please provide at least 5 characters.');
      return;
    }
    setActionSubmitting(true);
    setActionError(undefined);
    try {
      if (isApprove) {
        await adminApi.approveVerification(id, trimmed || undefined);
        toast(`${userName}'s verification approved.`, 'success');
      } else if (activeKind === 'reject') {
        await adminApi.rejectVerification(id, trimmed);
        toast(`${userName}'s verification rejected.`, 'success');
      } else {
        await adminApi.requestInfoVerification(id, trimmed);
        toast(`Information request sent to ${userName}.`, 'success');
      }
      setActiveKind(null);
      setActionText('');
      reload();
    } catch (err) {
      const e = normalizeError(err) as ApiError;
      toast(e.message || 'Action failed. Please try again.', 'error');
    } finally {
      setActionSubmitting(false);
    }
  }

  async function submitNote() {
    if (!id) return;
    const trimmed = noteBody.trim();
    if (trimmed.length < 2) {
      toast('Write a short note before saving.', 'error');
      return;
    }
    setNoteSubmitting(true);
    try {
      await adminApi.addVerificationNote(id, trimmed);
      setNoteBody('');
      toast('Note added.', 'success');
      reload();
    } catch (err) {
      const e = normalizeError(err) as ApiError;
      toast(e.message || 'Could not save this note.', 'error');
    } finally {
      setNoteSubmitting(false);
    }
  }

  if (!loading && error && (error.status === 404 || !id)) {
    return (
      <div className="wver rise">
        <div className="crumb">
          <Link to="/app/verifications" className="back">
            <WVIconBack />
            Back to Verifications
          </Link>
        </div>
        <section className="glass sec">
          <h1 className="ch-title" style={{ fontSize: '1.8rem' }}>
            Verification request not found
          </h1>
          <p className="ph-sub">This verification request does not exist, or the link is out of date.</p>
          <div style={{ marginTop: '1rem' }}>
            <button type="button" className="btn btn-glass" onClick={() => navigate('/app/verifications')}>
              Back to Verifications
            </button>
          </div>
        </section>
      </div>
    );
  }

  if (!vr) {
    return (
      <div className="wver rise">
        {loading && <LoadingState label="Loading case file…" />}
        {error && error.status !== 404 && <ErrorState message={error.message} onRetry={reload} />}
      </div>
    );
  }

  // Only these two checks are actually enforced server-side by
  // VerificationService::approve() (missing identity document / inactive
  // account) — gate the Approve button on exactly those, not on every
  // "failed" checklist row. Other failed items (e.g. a landlord missing
  // proof of address) are real concerns worth surfacing, but the API would
  // still accept the approval, so they must not disable the button — doing
  // so would tell an admin they can't do something they actually can.
  const HARD_BLOCK_KEYS = ['identity_document_submitted', 'account_active'];
  const failedItems = vr.checklist.filter((i) => i.result === 'failed');
  const blockers = failedItems.filter((i) => HARD_BLOCK_KEYS.includes(i.key));
  const advisoryFailed = failedItems.filter((i) => !HARD_BLOCK_KEYS.includes(i.key));
  const checklistWarnings = vr.checklist.filter((i) => i.result === 'warning');
  const flagCount = advisoryFailed.length + checklistWarnings.length + vr.warnings.length;
  const canApprove = vr.reviewable && blockers.length === 0;

  let verdictCls: string;
  let verdictTitle: string;
  let verdictSub: string;
  if (!vr.reviewable) {
    verdictCls = vr.status === 'approved' ? 'okv' : vr.status === 'rejected' ? 'block' : 'warnv';
    verdictTitle =
      vr.status === 'approved' ? 'Verified' : vr.status === 'rejected' ? 'Rejected' : 'Needs info from applicant';
    verdictSub = `This request was decided ${vr.reviewed_at ? `on ${formatDate(vr.reviewed_at)}` : 'already'}${
      vr.reviewer ? ` by ${vr.reviewer.name}` : ''
    }.`;
  } else if (blockers.length) {
    verdictCls = 'block';
    verdictTitle = 'Do not verify yet';
    verdictSub = `${blockers.length} blocker${blockers.length > 1 ? 's' : ''} must be resolved before approval.`;
  } else if (flagCount) {
    verdictCls = 'warnv';
    verdictTitle = 'Ready for a decision';
    verdictSub = 'No hard blockers, but review the flags below before deciding.';
  } else {
    verdictCls = 'okv';
    verdictTitle = 'Clear to approve';
    verdictSub = 'All required checks passed.';
  }

  return (
    <div className="wver rise" ref={rootRef}>
      <div className="crumb">
        <Link to="/app/verifications" className="back">
          <WVIconBack />
          Back to Verifications
        </Link>
        <span className="sep">·</span>
        <span>Identity Verification</span>
        <span className="sep">/</span>
        <span>{userName}</span>
      </div>

      <section className="chead glass">
        <div className="ch-eyebrow">
          <span>{vr.id.slice(0, 8)}</span>
          <span className="mono" style={{ color: 'var(--ink-3)' }}>
            Submitted {vr.submitted_at ? formatDate(vr.submitted_at) : formatDate(vr.created_at)}
          </span>
        </div>
        <div className="ch-idrow">
          <span className="ch-av" style={{ background: avatarColor(isLandlord) }}>
            {initials(userName) || '—'}
          </span>
          <h1 className="ch-title">{userName}</h1>
        </div>
        <div className="ch-facts">
          <span className="cf">
            <span className={`rolechip ${isLandlord ? 'landlord' : 'tenant'}`}>{isLandlord ? 'Landlord' : 'Tenant'}</span>
          </span>
          <span className="cf">
            <span className={`statuspill ${vr.status}`}>
              <span className="sd" />
              {verificationStatusLabel(vr.status)}
            </span>
          </span>
          <span className="cf">
            <b>{vr.documents.length}</b> document{vr.documents.length === 1 ? '' : 's'}
          </span>
          <span className="cf">
            <b>{blockers.length + flagCount}</b> flag{blockers.length + flagCount === 1 ? '' : 's'}
          </span>
          {vr.reviewer && (
            <span className="cf">
              Reviewed by <b>{vr.reviewer.name}</b>
            </span>
          )}
        </div>
        <div className="ch-actions">
          <button
            type="button"
            className="btn btn-ok"
            disabled={!vr.reviewable || !canApprove}
            title={!vr.reviewable ? 'This case has already been decided.' : canApprove ? undefined : 'Resolve the blockers first.'}
            onClick={quickApprove}
          >
            <WVIconCheck />
            Approve
          </button>
          <button type="button" className="btn btn-warn" disabled={!vr.reviewable} onClick={() => selectAction('request_info')}>
            Request more info
          </button>
          <button type="button" className="btn btn-danger" disabled={!vr.reviewable} onClick={() => selectAction('reject')}>
            Reject
          </button>
          <button type="button" className="btn btn-glass" onClick={() => goToSection('documents')}>
            View documents
          </button>
        </div>
      </section>

      <nav className="secnav" aria-label="Sections">
        {SECTIONS.map(([sid, label]) => (
          <button key={sid} type="button" className={activeSec === sid ? 'active' : ''} onClick={() => goToSection(sid)}>
            {label}
          </button>
        ))}
      </nav>

      {/* 01 Summary */}
      <section className="sec glass" id="vsec-summary">
        <div className="sec-h">
          <h2>
            <span className="n">01</span> Review summary
          </h2>
          <span className="hint">Should Wyncrest trust this account?</span>
        </div>
        <div className={`verdict ${verdictCls}`}>
          <div className="vi">
            {verdictCls === 'block' ? <WVIconX /> : verdictCls === 'warnv' ? <WVIconWarn /> : <WVIconCheck />}
          </div>
          <div>
            <div className="vt">{verdictTitle}</div>
            <div className="vs">{verdictSub}</div>
            {vr.reviewable && (
              <div className="rec">
                {isLandlord && 'Landlords are held to a stricter bar because they publish listings and collect rent.'}
              </div>
            )}
          </div>
        </div>
        {(blockers.length > 0 || advisoryFailed.length > 0 || vr.warnings.length > 0) && (
          <div>
            {blockers.map((b) => (
              <div key={b.key} className="blockline blk">
                <span className="bd">
                  <WVIconX />
                </span>
                {b.detail}
              </div>
            ))}
            {advisoryFailed.map((f) => (
              <div key={f.key} className="blockline wrn">
                <span className="bd">
                  <WVIconWarn />
                </span>
                {f.detail}
              </div>
            ))}
            {vr.warnings.map((w, i) => (
              <div key={i} className="blockline wrn">
                <span className="bd">
                  <WVIconWarn />
                </span>
                {w}
              </div>
            ))}
          </div>
        )}
        {vr.decision_reason && (
          <div className="kv" style={{ marginTop: '.8rem' }}>
            <span className="kk">Decision note</span>
            <span className="vv">{vr.decision_reason}</span>
          </div>
        )}
        {vr.note && (
          <div className="kv">
            <span className="kk">Note from applicant</span>
            <span className="vv">{vr.note}</span>
          </div>
        )}
        <div className="sgrid">
          <div className={`scell ${blockers.length ? 'b' : ''}`}>
            <div className="sn">{blockers.length}</div>
            <div className="sl">Blockers</div>
          </div>
          <div className={`scell ${flagCount ? 'w' : ''}`}>
            <div className="sn">{flagCount}</div>
            <div className="sl">Warnings</div>
          </div>
          <div className="scell">
            <div className="sn">{vr.previous_attempts.length}</div>
            <div className="sl">Previous attempts</div>
          </div>
          <div className="scell">
            <div className="sn" style={{ fontSize: '1rem', paddingTop: '.4rem' }}>
              {vr.reviewable ? 'Yes' : 'No'}
            </div>
            <div className="sl">Action needed</div>
          </div>
        </div>
      </section>

      {/* 02 Applicant */}
      <section className="sec glass" id="vsec-applicant">
        <div className="sec-h">
          <h2>
            <span className="n">02</span> Applicant profile
          </h2>
        </div>
        <div className="two">
          <div className="subcard">
            <div className="kv">
              <span className="kk">Full name</span>
              <span className="vv">{userName}</span>
            </div>
            <div className="kv">
              <span className="kk">Email</span>
              <span className="vv mono">{vr.user?.email ?? '—'}</span>
            </div>
            <div className="kv">
              <span className="kk">Phone</span>
              <span className="vv mono">{vr.user?.phone ?? 'Not provided'}</span>
            </div>
            <div className="kv">
              <span className="kk">Role</span>
              <span className="vv">{isLandlord ? 'Landlord' : 'Tenant'}</span>
            </div>
          </div>
          <div className="subcard">
            <div className="kv">
              <span className="kk">Account status</span>
              <span className="vv">{vr.user?.account_status ?? 'active'}</span>
            </div>
            <div className="kv">
              <span className="kk">Verification</span>
              <span className="vv">
                <span className={`vbadge ${vr.status === 'approved' ? 'ok' : vr.status === 'rejected' ? 'no' : 'pending'}`}>
                  {verificationStatusLabel(vr.status)}
                </span>
              </span>
            </div>
            <div className="kv">
              <span className="kk">Account created</span>
              <span className="vv">{vr.user?.created_at ? formatDate(vr.user.created_at) : '—'}</span>
            </div>
            <div className="kv">
              <span className="kk">Current verification status</span>
              <span className="vv">{vr.user?.verification_status ?? '—'}</span>
            </div>
          </div>
        </div>
      </section>

      {/* 03 Documents */}
      <section className="sec glass" id="vsec-documents">
        <div className="sec-h">
          <h2>
            <span className="n">03</span> Submitted documents
          </h2>
          <span className="hint">{vr.documents.length} uploaded</span>
        </div>
        {vr.documents.length === 0 && (
          <div className="warnrow blood">
            <WVIconWarn />
            This request cannot be approved until a required identity document is submitted.
          </div>
        )}
        <DocumentViewer documents={vr.documents} />
      </section>

      {/* 04 Checklist */}
      <section className="sec glass" id="vsec-checklist">
        <div className="sec-h">
          <h2>
            <span className="n">04</span> Identity match checklist
          </h2>
          <span className="hint">Honest states — no fake automation</span>
        </div>
        <div className="checklist">
          {vr.checklist.map((item) => (
            <ChecklistCell key={item.key} item={item} />
          ))}
        </div>
      </section>

      {/* 05 Warnings */}
      <section className="sec glass" id="vsec-warnings">
        <div className="sec-h">
          <h2>
            <span className="n">05</span> Warnings &amp; risk signals
          </h2>
        </div>
        {vr.warnings.length === 0 ? (
          <div className="okrow">
            <WVIconCheck />
            No warnings detected from the available checks.
          </div>
        ) : (
          vr.warnings.map((w, i) => (
            <div key={i} className="warnrow amber">
              <WVIconWarn />
              {w}
            </div>
          ))
        )}
      </section>

      {/* 06 History */}
      <section className="sec glass" id="vsec-history">
        <div className="sec-h">
          <h2>
            <span className="n">06</span> Verification timeline
          </h2>
        </div>
        {vr.history.length === 0 ? (
          <p style={{ fontSize: '.85rem', color: 'var(--ink-3)' }}>No recorded events for this case yet.</p>
        ) : (
          <div className="tl">
            {vr.history.map((h) => (
              <div key={h.id} className={`tl-item ${TL_TONE[h.severity] ?? ''}`}>
                <div className="te">{h.description ?? h.action}</div>
                <div className="tm">
                  {formatDateTime(h.created_at)}
                  {h.actor ? ` · ${h.actor.name}` : ''}
                </div>
              </div>
            ))}
          </div>
        )}
      </section>

      {/* 07 Previous attempts */}
      <section className="sec glass" id="vsec-attempts">
        <div className="sec-h">
          <h2>
            <span className="n">07</span> Previous attempts
          </h2>
        </div>
        {vr.previous_attempts.length === 0 ? (
          <p style={{ fontSize: '.85rem', color: 'var(--ink-3)' }}>This applicant has no earlier verification requests.</p>
        ) : (
          vr.previous_attempts.map((a) => (
            <div key={a.id} className="attempt">
              <div className="ah">
                <span className={`statuspill ${a.status}`}>
                  <span className="sd" />
                  {verificationStatusLabel(a.status)}
                </span>
                <span className="mono" style={{ color: 'var(--ink-3)', fontSize: '.72rem' }}>
                  {a.submitted_at ? formatDate(a.submitted_at) : formatDate(a.created_at)}
                </span>
              </div>
              {a.decision_reason && <div className="ar">{a.decision_reason}</div>}
            </div>
          ))
        )}
      </section>

      {/* 08 Notes */}
      <section className="sec glass" id="vsec-notes">
        <div className="sec-h">
          <h2>
            <span className="n">08</span> Internal notes
          </h2>
          <span className="hint">Never shown to the applicant</span>
        </div>
        {vr.notes.length === 0 ? (
          <p style={{ fontSize: '.85rem', color: 'var(--ink-3)' }}>No internal notes yet.</p>
        ) : (
          vr.notes.map((n) => (
            <div key={n.id} className="note">
              {n.body}
              <div className="nm">
                {n.admin?.name ?? 'Admin'} · {timeAgo(n.created_at)}
                <span className="lk">Internal</span>
              </div>
            </div>
          ))
        )}
        <div className="noteadd">
          <input
            type="text"
            value={noteBody}
            onChange={(e) => setNoteBody(e.target.value)}
            placeholder="Add an internal note…"
            onKeyDown={(e) => {
              if (e.key === 'Enter') submitNote();
            }}
          />
          <button type="button" className="btn btn-blood btn-sm" onClick={submitNote} disabled={noteSubmitting || !noteBody.trim()}>
            Add
          </button>
        </div>
      </section>

      {/* 09 Decision */}
      <section className="sec glass decision" id="vsec-decision">
        <div className="sec-h">
          <h2>
            <span className="n">09</span> Decision
          </h2>
        </div>

        {!vr.reviewable ? (
          <div className="decided-note">
            <WVIconInfo />
            <span>
              This case has already been decided
              {vr.reviewed_at ? ` (${formatDate(vr.reviewed_at)})` : ''}. No further action is available here.
            </span>
          </div>
        ) : (
          <div className="dpanel">
            <div className={`dopt approve ${canApprove ? '' : 'blocked'}`}>
              <h3>
                <WVIconCheck />
                Approve verification
              </h3>
              {canApprove ? (
                <>
                  <p>
                    Approving marks {userName} as verified and unlocks verification-gated features
                    {isLandlord ? ' — submitting properties and listings for review.' : ' — submitting rental applications.'}
                  </p>
                  {activeKind === 'approve' ? (
                    <>
                      <label className="fieldlabel" htmlFor="approve-reason">
                        Reason (optional)
                      </label>
                      <textarea
                        id="approve-reason"
                        value={actionText}
                        onChange={(e) => setActionText(e.target.value)}
                        placeholder="Optionally note why this was approved…"
                        autoFocus
                      />
                      <div style={{ display: 'flex', gap: '.5rem', marginTop: '.6rem' }}>
                        <button type="button" className="btn btn-glass btn-sm" onClick={() => setActiveKind(null)} disabled={actionSubmitting}>
                          Back
                        </button>
                        <button type="button" className="btn btn-ok btn-sm" onClick={submitAction} disabled={actionSubmitting}>
                          {actionSubmitting ? 'Approving…' : 'Confirm approve'}
                        </button>
                      </div>
                    </>
                  ) : (
                    <button type="button" className="btn btn-ok" onClick={() => selectAction('approve')}>
                      Approve &amp; mark verified
                    </button>
                  )}
                </>
              ) : (
                <>
                  <div className="cannot">
                    <b>Cannot approve yet:</b>
                    <ul>
                      {blockers.map((b) => (
                        <li key={b.key}>{b.detail}</li>
                      ))}
                    </ul>
                  </div>
                  <button type="button" className="btn btn-ok" disabled>
                    Approve
                  </button>
                  <span style={{ fontSize: '.8rem', color: 'var(--ink-3)', marginLeft: '.5rem' }}>
                    Resolve the blockers, or request more info.
                  </span>
                </>
              )}
              <div className="willdo">
                On approval: identity marked verified · reviewer &amp; timestamp stored · audit log entry created · applicant
                notified{isLandlord ? ' · publishing access unlocked' : ''}.
              </div>
            </div>

            <div className="dopt">
              <h3 style={{ color: 'var(--amber)' }}>
                <WVIconWarn />
                Request more info
              </h3>
              <p>Use when the applicant can fix the issue. Status becomes &ldquo;Needs info&rdquo; and they can resubmit.</p>
              {activeKind === 'request_info' ? (
                <>
                  <label className="fieldlabel" htmlFor="info-msg">
                    What is needed from the applicant
                  </label>
                  <textarea
                    id="info-msg"
                    value={actionText}
                    onChange={(e) => {
                      setActionText(e.target.value);
                      if (actionError) setActionError(undefined);
                    }}
                    placeholder="e.g. Upload a clearer photo of your Ghana Card"
                    autoFocus
                  />
                  {actionError && <div style={{ color: 'var(--oxblood)', fontSize: '.78rem', marginTop: '.3rem' }}>{actionError}</div>}
                  <div style={{ display: 'flex', gap: '.5rem', marginTop: '.6rem' }}>
                    <button type="button" className="btn btn-glass btn-sm" onClick={() => setActiveKind(null)} disabled={actionSubmitting}>
                      Back
                    </button>
                    <button type="button" className="btn btn-warn btn-sm" onClick={submitAction} disabled={actionSubmitting}>
                      {actionSubmitting ? 'Sending…' : 'Send request'}
                    </button>
                  </div>
                </>
              ) : (
                <button type="button" className="btn btn-warn" onClick={() => selectAction('request_info')}>
                  Request more info
                </button>
              )}
            </div>

            <div className="dopt">
              <h3 style={{ color: 'var(--oxblood)' }}>
                <WVIconX />
                Reject
              </h3>
              <p>Use when the request should not be approved. A reason is required.</p>
              {activeKind === 'reject' ? (
                <>
                  <label className="fieldlabel" htmlFor="reject-msg">
                    Reason for rejection
                  </label>
                  <textarea
                    id="reject-msg"
                    value={actionText}
                    onChange={(e) => {
                      setActionText(e.target.value);
                      if (actionError) setActionError(undefined);
                    }}
                    placeholder="Explain what was wrong with the submission…"
                    autoFocus
                  />
                  {actionError && <div style={{ color: 'var(--oxblood)', fontSize: '.78rem', marginTop: '.3rem' }}>{actionError}</div>}
                  <div style={{ display: 'flex', gap: '.5rem', marginTop: '.6rem' }}>
                    <button type="button" className="btn btn-glass btn-sm" onClick={() => setActiveKind(null)} disabled={actionSubmitting}>
                      Back
                    </button>
                    <button type="button" className="btn btn-danger btn-sm" onClick={submitAction} disabled={actionSubmitting}>
                      {actionSubmitting ? 'Rejecting…' : 'Reject request'}
                    </button>
                  </div>
                </>
              ) : (
                <button type="button" className="btn btn-danger" onClick={() => selectAction('reject')}>
                  Reject request
                </button>
              )}
            </div>
          </div>
        )}
      </section>
    </div>
  );
}

export default VerificationDetailPage;
