import { useEffect, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { landlordApi } from '@/lib/endpoints';
import { fieldErrors } from '@/lib/api';
import type { ApiError, Application, ApplicationMessageThread, DocumentType, TenantDocument } from '@/lib/types';
import { formatDate, formatCedisDecimal, formatDateTime, humanize } from '@/lib/format';
import { useToast } from '@/components/ui/toast';
import { Modal } from '@/components/ui/Modal';
import {
  IconStar,
  IconShield,
  IconBack,
  IconDollar,
  IconChecklist,
  IconClock,
  IconWarn,
  IconLock,
  IconFile,
} from './applicants-ui';
import {
  affordability,
  AFFORD_LABEL,
  completenessPercent,
  hasRequiredDocuments,
  isDecidable,
  canRequestInfo,
  canShortlist,
  isFullyVerified,
} from './applicantHelpers';
import './applicants.css';

type Tab = 'overview' | 'employment' | 'rental' | 'household' | 'documents' | 'verification' | 'messages' | 'timeline';

const TABS: { key: Tab; label: string }[] = [
  { key: 'overview', label: 'Overview' },
  { key: 'employment', label: 'Employment & income' },
  { key: 'rental', label: 'Rental history' },
  { key: 'household', label: 'Household' },
  { key: 'documents', label: 'Documents' },
  { key: 'verification', label: 'Verification' },
  { key: 'messages', label: 'Messages' },
  { key: 'timeline', label: 'Decision & timeline' },
];

const STATUS_LABEL: Record<string, string> = {
  submitted: 'New',
  in_review: 'Under review',
  landlord_review: 'Under review',
  needs_action: 'Needs info',
  approved: 'Approved',
  rejected: 'Not selected',
  withdrawn: 'Withdrawn',
};

const REQUEST_TYPE_OPTIONS: { value: 'more_info' | 'document_replacement' | 'general'; label: string }[] = [
  { value: 'more_info', label: 'More information' },
  { value: 'document_replacement', label: 'Document replacement' },
  { value: 'general', label: 'General' },
];

const REQUEST_DOC_TYPE_OPTIONS: { value: DocumentType; label: string }[] = [
  { value: 'identity_document', label: 'Identity document' },
  { value: 'proof_of_address', label: 'Proof of address' },
  { value: 'proof_of_income', label: 'Proof of income' },
  { value: 'application_attachment', label: 'Supporting document' },
  { value: 'other', label: 'Other' },
];

function Kv({ label, value }: { label: string; value?: string | null }) {
  return (
    <div className="kv">
      <span className="kk">{label}</span>
      <span className="vv">{value || '—'}</span>
    </div>
  );
}

function initials(name: string): string {
  return name.split(' ').map((w) => w[0]).slice(0, 2).join('').toUpperCase();
}

export function ApplicantDetail() {
  const { applicationId } = useParams();
  const id = Number(applicationId);
  const [searchParams, setSearchParams] = useSearchParams();
  const navigate = useNavigate();
  const { toast } = useToast();

  const { data, loading, error, reload } = useApi(() => landlordApi.application(id), [id]);
  const [override, setOverride] = useState<Application | null>(null);
  const application = override ?? data;

  const [tab, setTab] = useState<Tab>('overview');
  const [approveOpen, setApproveOpen] = useState(false);
  const [declineOpen, setDeclineOpen] = useState(false);
  const [infoOpen, setInfoOpen] = useState(false);
  const [approveNote, setApproveNote] = useState('');
  const [declineReason, setDeclineReason] = useState('');
  const [deciding, setDeciding] = useState(false);
  const [decideError, setDecideError] = useState('');
  const [shortlisting, setShortlisting] = useState(false);

  const [requestType, setRequestType] = useState<'more_info' | 'document_replacement' | 'general'>('more_info');
  const [requestDocType, setRequestDocType] = useState<DocumentType | ''>('');
  const [requestMessage, setRequestMessage] = useState('');
  const [requestDueAt, setRequestDueAt] = useState('');
  const [requestError, setRequestError] = useState('');
  const [requestSending, setRequestSending] = useState(false);

  const [thread, setThread] = useState<ApplicationMessageThread | null>(null);
  const [threadLoading, setThreadLoading] = useState(false);
  const [messageBody, setMessageBody] = useState('');
  const [sendingMessage, setSendingMessage] = useState(false);

  useEffect(() => {
    const action = searchParams.get('action');
    if (!application || !action) return;
    if (action === 'approve') setApproveOpen(true);
    if (action === 'reject') setDeclineOpen(true);
    const next = new URLSearchParams(searchParams);
    next.delete('action');
    setSearchParams(next, { replace: true });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [application]);

  useEffect(() => {
    if (tab !== 'messages' || !application || thread) return;
    setThreadLoading(true);
    landlordApi
      .applicationMessages(application.id)
      .then(setThread)
      .catch(() => toast('Could not load messages.', 'error'))
      .finally(() => setThreadLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab, application]);

  if (loading) {
    return (
      <div className="wla">
        <section className="glass" style={{ padding: '3rem', textAlign: 'center', color: 'var(--wla-ink-3)' }}>Loading application…</section>
      </div>
    );
  }
  if (error || !application) {
    return (
      <div className="wla">
        <section className="glass empty">
          <span className="et">Couldn't load this application</span>
          <p>{error?.message}</p>
          <button className="btn btn-dark" onClick={reload}>Retry</button>
        </section>
      </div>
    );
  }

  const tenant = application.tenant;
  const name = tenant?.full_name ?? `Applicant #${application.id}`;
  const listing = application.listing;
  const unit = listing?.unit;
  const property = unit?.property;
  const form = application.form_data ?? {};
  const afford = affordability(application);
  const completeness = completenessPercent(application);
  const decidable = isDecidable(application.status);
  const verified = isFullyVerified(application);

  async function toggleShortlist() {
    setShortlisting(true);
    try {
      const updated = await landlordApi.toggleApplicationShortlist(application!.id);
      setOverride(updated);
      toast(updated.is_shortlisted ? 'Added to shortlist' : 'Removed from shortlist', 'success');
    } catch {
      toast('Could not update the shortlist.', 'error');
    } finally {
      setShortlisting(false);
    }
  }

  async function submitApprove() {
    setDeciding(true);
    setDecideError('');
    try {
      const updated = await landlordApi.decideApplication(application!.id, 'approved', approveNote.trim() || undefined);
      setOverride(updated);
      setApproveOpen(false);
      setApproveNote('');
      toast('Application approved.', 'success');
    } catch (err) {
      setDecideError(fieldErrors(err as ApiError).decision_reason || (err as ApiError).message || 'Could not approve this application.');
    } finally {
      setDeciding(false);
    }
  }

  async function submitDecline() {
    setDeciding(true);
    setDecideError('');
    try {
      const updated = await landlordApi.decideApplication(application!.id, 'rejected', declineReason.trim() || undefined);
      setOverride(updated);
      setDeclineOpen(false);
      setDeclineReason('');
      toast('Application declined.', 'success');
    } catch (err) {
      setDecideError(fieldErrors(err as ApiError).decision_reason || (err as ApiError).message || 'Could not decline this application.');
    } finally {
      setDeciding(false);
    }
  }

  async function submitRequestInfo() {
    const message = requestMessage.trim();
    if (!message) {
      setRequestError('A message for the applicant is required.');
      return;
    }
    setRequestSending(true);
    setRequestError('');
    try {
      const updated = await landlordApi.requestApplicationInfo(application!.id, {
        message,
        type: requestType,
        document_type: requestType === 'document_replacement' && requestDocType ? requestDocType : undefined,
        due_at: requestDueAt || undefined,
      });
      setOverride(updated);
      setInfoOpen(false);
      setRequestMessage('');
      setRequestDocType('');
      setRequestDueAt('');
      setRequestType('more_info');
      toast('Requested more information from the applicant.', 'success');
    } catch (err) {
      setRequestError(fieldErrors(err as ApiError).message || (err as ApiError).message || 'Could not send the request.');
    } finally {
      setRequestSending(false);
    }
  }

  async function downloadDocument(doc: TenantDocument) {
    try {
      const blob = await landlordApi.downloadDocument(doc.id);
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = doc.original_filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    } catch {
      toast('Could not download this document.', 'error');
    }
  }

  async function sendMessage() {
    const body = messageBody.trim();
    if (!body) return;
    setSendingMessage(true);
    try {
      const updated = await landlordApi.sendApplicationMessage(application!.id, body);
      setThread(updated);
      setMessageBody('');
    } catch {
      toast('Could not send the message.', 'error');
    } finally {
      setSendingMessage(false);
    }
  }

  return (
    <div className="wla animate-rise">
      <div className="crumb">
        <button className="back" onClick={() => navigate('/app/applicants')}><IconBack /> Back to Applicants</button>
        <span className="sep">/</span>
        <span>{name}</span>
      </div>

      <section className="glass dhead">
        <div className="dh-top">
          <div className="avatar-lg">{initials(name)}</div>
          <div style={{ flex: 1, minWidth: 0 }}>
            <div className="dh-name">
              {name}
              {verified ? (
                <span className="vbadge ok"><CheckGlyph /> Verified by Wyncrest</span>
              ) : (
                <span className="vbadge part">Partly verified</span>
              )}
            </div>
            <div className="dh-for">
              Applied for {property?.name ?? listing?.title} {unit ? `· Unit ${unit.unit_number}` : ''} ·{' '}
              {unit?.rent_amount ? `${formatCedisDecimal(unit.rent_amount)}/mo` : '—'} · {listing?.title}
            </div>
            <div className="dh-meta">
              <span className={`statuspill ${application.status}`}><span className="sd" />{STATUS_LABEL[application.status] ?? application.status}</span>
              <span>Submitted {formatDate(application.submitted_at ?? application.created_at)}</span>
              <span className="mono">#{application.id}</span>
              {form.rental?.moveIn && <span>Move-in {form.rental.moveIn}</span>}
            </div>
          </div>
        </div>

        <div className="dh-actions">
          {decidable ? (
            <>
              <button className="btn btn-green" onClick={() => setApproveOpen(true)}>Approve</button>
              {canShortlist(application.status) && (
                <button className={`btn ${application.is_shortlisted ? 'btn-dark' : ''}`} onClick={toggleShortlist} disabled={shortlisting}>
                  <IconStar /> {application.is_shortlisted ? 'Shortlisted' : 'Shortlist'}
                </button>
              )}
              {canRequestInfo(application.status) && (
                <button className="btn btn-amber" onClick={() => setInfoOpen(true)}>Request info</button>
              )}
              <button className="btn btn-blood" onClick={() => setDeclineOpen(true)}>Decline</button>
            </>
          ) : (
            canShortlist(application.status) && (
              <button className={`btn ${application.is_shortlisted ? 'btn-dark' : ''}`} onClick={toggleShortlist} disabled={shortlisting}>
                <IconStar /> {application.is_shortlisted ? 'Shortlisted' : 'Shortlist'}
              </button>
            )
          )}
          <button className="btn" onClick={() => setTab('messages')}>Message</button>
        </div>
      </section>

      <section className="glass decision">
        <div className="dec-h">Review signals</div>
        <div className="dec-sub">Objective factors to help you decide. These are guidance, not a score, and should be weighed alongside your own judgement.</div>
        <div className="sig-grid">
          <div className={`sigcard ${afford ? (afford.level === 'good' ? 'good' : 'mod') : 'mod'}`}>
            <div className="sh"><span className="st">Affordability</span><span className="sicon"><IconDollar /></span></div>
            <div className="sv">{afford ? `${afford.ratio}×` : '—'}</div>
            <div className="ss">
              {afford ? `Income is ${afford.ratio}× the monthly rent (${AFFORD_LABEL[afford.level]}).` : 'Income not yet provided.'}
            </div>
          </div>
          <div className="sigcard good">
            <div className="sh"><span className="st">Verification</span><span className="sicon"><IconShield /></span></div>
            <div className="sv" style={{ fontSize: '1.1rem', paddingTop: '.35rem' }}>{verified ? 'Identity + documents' : tenant?.identity_verified ? 'Identity only' : 'Pending'}</div>
            <div className="ss">Verified by Wyncrest. Documents {hasRequiredDocuments(application) ? 'complete' : 'incomplete'}.</div>
          </div>
          <div className={`sigcard ${completeness === 100 ? 'good' : 'mod'}`}>
            <div className="sh"><span className="st">Completeness</span><span className="sicon"><IconChecklist /></span></div>
            <div className="sv">{completeness}%</div>
            <div className="ss">{completeness === 100 ? 'All required sections and documents provided.' : 'Some items still outstanding.'}</div>
          </div>
        </div>
        <div className="fairnote">
          <IconShield />
          <div>
            Base your decision on affordability, verification, rental history, and completeness. Do not consider race,
            religion, gender, nationality, disability, or family status. Wyncrest logs each decision to support fair,
            consistent leasing.
          </div>
        </div>
      </section>

      <section className="glass" style={{ padding: '.4rem' }}>
        <div className="dtabs">
          {TABS.map((t) => (
            <button key={t.key} className={`dtab ${tab === t.key ? 'on' : ''}`} onClick={() => setTab(t.key)}>
              {t.label}
              {t.key === 'documents' && <span className="cnt">{application.documents?.length ?? 0}</span>}
            </button>
          ))}
        </div>
      </section>

      {tab === 'overview' && (
        <section className="sec glass">
          <div className="sec-h">Applicant snapshot</div>
          <div className="two">
            <div>
              <Kv label="Name" value={name} />
              <Kv label="Phone" value={tenant?.phone} />
              <Kv label="Email" value={tenant?.email} />
              <Kv label="Preferred move-in" value={form.rental?.moveIn} />
            </div>
            <div>
              <Kv label="Employment" value={form.employment?.status} />
              <Kv label="Monthly income" value={form.employment?.income ? formatCedisDecimal(form.employment.income) : null} />
              <Kv label="Affordability" value={afford ? `${afford.ratio}× rent (${AFFORD_LABEL[afford.level]})` : null} />
              <Kv label="Documents" value={hasRequiredDocuments(application) ? 'Complete' : 'Incomplete'} />
            </div>
          </div>
        </section>
      )}

      {tab === 'employment' && (
        <section className="sec glass">
          <div className="sec-h">
            Employment &amp; income{form.employment?.income && <span className="hint">Self-reported by applicant</span>}
          </div>
          <div className="two">
            <div>
              <Kv label="Employment status" value={form.employment?.status} />
              <Kv label="Employer" value={form.employment?.employer} />
              <Kv label="Job title" value={form.employment?.title} />
              <Kv label="Start date" value={form.employment?.start} />
            </div>
            <div>
              <Kv label="Monthly income" value={form.employment?.income ? formatCedisDecimal(form.employment.income) : null} />
              <Kv label="Other income" value={form.employment?.other} />
            </div>
          </div>
          {afford && (
            <div className="affbox">
              <div className="ab-top">
                <div>
                  <div className="dl">Affordability</div>
                  <div style={{ fontSize: '.84rem', color: 'var(--wla-ink-3)', marginTop: '.2rem' }}>Income ÷ rent · a common guideline is 3× or more</div>
                </div>
                <div className="ratio" style={{ color: afford.level === 'good' ? 'var(--wla-green)' : afford.level === 'mod' ? 'var(--wla-amber)' : 'var(--wla-oxblood)' }}>
                  {afford.ratio}×
                </div>
              </div>
              <div className="track">
                <i style={{ width: `${Math.min(100, (afford.ratio / 4) * 100)}%`, background: afford.level === 'good' ? 'var(--wla-green)' : afford.level === 'mod' ? 'var(--wla-amber)' : 'var(--wla-oxblood)' }} />
              </div>
              <div className="ticks"><span>0×</span><span>2×</span><span>3×</span><span>4×+</span></div>
            </div>
          )}
        </section>
      )}

      {tab === 'rental' && (
        <section className="sec glass">
          <div className="sec-h">Rental history</div>
          <div className="two">
            <div>
              <Kv label="Current residence type" value={form.rental?.curType} />
              <Kv label="Current landlord" value={form.rental?.curLandlord} />
              <Kv label="Landlord contact" value={form.rental?.curContact} />
            </div>
            <div>
              <Kv label="Current rent" value={form.rental?.curRent ? formatCedisDecimal(form.rental.curRent) : null} />
              <Kv label="Preferred move-in" value={form.rental?.moveIn} />
              <Kv label="Reason for moving" value={form.rental?.reason} />
            </div>
          </div>
        </section>
      )}

      {tab === 'household' && (
        <section className="sec glass">
          <div className="sec-h">Household</div>
          <div className="two">
            <div>
              <Kv label="Adults" value={form.household?.adults} />
              <Kv label="Children" value={form.household?.children} />
            </div>
            <div>
              <Kv label="Pets" value={form.household?.pets} />
              <Kv label="Vehicles" value={form.household?.vehicles} />
            </div>
          </div>
          <div className="fairnote" style={{ marginTop: '1rem' }}>
            <IconShield />
            <div>Household composition is shown for planning (space, parking, pets). It must not be used to discriminate on the basis of family status.</div>
          </div>
        </section>
      )}

      {tab === 'documents' && (
        <section className="sec glass">
          <div className="sec-h">Documents<span className="hint">Verified by Wyncrest</span></div>
          {(application.documents ?? []).length === 0 ? (
            <div className="emptytab">
              <div className="ei"><IconFile /></div>
              <span className="et2">No documents yet</span>
              <p>The applicant hasn't uploaded any documents for this application.</p>
            </div>
          ) : (
            <div className="tablewrap">
              <table className="tbl">
                <thead><tr><th>Document</th><th>Status</th><th className="r">Action</th></tr></thead>
                <tbody>
                  {application.documents!.map((doc) => (
                    <tr key={doc.id}>
                      <td style={{ fontWeight: 600 }}>{humanize(doc.document_type)}</td>
                      <td><span className={`badge ${doc.is_verified ? 'green' : 'gray'}`}>{doc.is_verified ? 'Verified' : 'Uploaded'}</span></td>
                      <td className="r"><button className="btn btn-sm" onClick={() => downloadDocument(doc)}>Download</button></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          <div className="fairnote" style={{ marginTop: '1rem' }}>
            <IconLock />
            <div>Documents are streamed through an authorised, audited download — they are never publicly linked.</div>
          </div>
        </section>
      )}

      {tab === 'verification' && (
        <section className="sec glass">
          <div className="sec-h">Verification<span className="hint">Handled by Wyncrest</span></div>
          <div className="vlist">
            <VRow ok={Boolean(tenant?.identity_verified)} title="Identity" sub="Government ID checked and matched by Wyncrest." />
            <VRow ok={(application.documents ?? []).some((d) => d.document_type === 'proof_of_income')} title="Income" sub="Proof of income reviewed against stated income." />
            <VRow ok={hasRequiredDocuments(application)} title="Documents" sub="All required documents submitted and reviewed." />
          </div>
        </section>
      )}

      {tab === 'messages' && (
        <section className="sec glass">
          <div className="sec-h">Messages<span className="hint">Through Wyncrest</span></div>
          {threadLoading ? (
            <p style={{ textAlign: 'center', color: 'var(--wla-ink-3)', padding: '1.5rem' }}>Loading messages…</p>
          ) : (
            <div className="msg">
              {(thread?.messages ?? []).length === 0 ? (
                <p style={{ textAlign: 'center', color: 'var(--wla-ink-3)', padding: '1.5rem' }}>No messages yet. Start a conversation with {name.split(' ')[0]}.</p>
              ) : (
                thread!.messages.map((m) => (
                  <div key={m.id} className={`bubble ${m.sender.is_me ? 'me' : 'them'}`}>
                    {m.body}
                    <div className="bmeta">{m.sender.is_me ? 'You' : name} · {formatDateTime(m.created_at)}</div>
                  </div>
                ))
              )}
            </div>
          )}
          <div className="msg-input">
            <input
              value={messageBody}
              onChange={(e) => setMessageBody(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') sendMessage(); }}
              placeholder="Write a message…"
            />
            <button className="btn btn-petrol" onClick={sendMessage} disabled={sendingMessage}>{sendingMessage ? 'Sending…' : 'Send'}</button>
          </div>
        </section>
      )}

      {tab === 'timeline' && (
        <section className="sec glass">
          <div className="sec-h">Decision &amp; timeline</div>
          {application.decision_reason && (application.status === 'approved' || application.status === 'rejected') && (
            <div className="fairnote" style={{ background: 'color-mix(in srgb, var(--wla-oxblood) 5%, transparent)', borderColor: 'color-mix(in srgb, var(--wla-oxblood) 20%, transparent)', marginBottom: '1rem' }}>
              <IconWarn />
              <div>Decision reason: {application.decision_reason}</div>
            </div>
          )}
          <div className="tl">
            {(application.events ?? []).length === 0 ? (
              <p style={{ color: 'var(--wla-ink-3)', fontSize: '.86rem' }}>No timeline events yet.</p>
            ) : (
              application.events!.map((ev) => (
                <div key={ev.id} className="tl-item">
                  <div className="te">{ev.description}</div>
                  <div className="tm">{formatDateTime(ev.created_at)}</div>
                </div>
              ))
            )}
          </div>
        </section>
      )}

      <Modal
        open={approveOpen}
        onClose={() => !deciding && setApproveOpen(false)}
        title={`Approve ${name.split(' ')[0]}?`}
        description={`This approves ${name} for ${property?.name ?? listing?.title}. Other applicants for this unit are not affected until you decline them.`}
        footer={
          <>
            <button className="btn btn-sm" onClick={() => setApproveOpen(false)} disabled={deciding}>Cancel</button>
            <button className="btn btn-green btn-sm" onClick={submitApprove} disabled={deciding}>{deciding ? 'Approving…' : 'Approve applicant'}</button>
          </>
        }
      >
        <label className="dl" style={{ display: 'block', margin: '.5rem 0 .35rem' }}>Message to applicant (optional)</label>
        <textarea
          rows={2}
          value={approveNote}
          onChange={(e) => setApproveNote(e.target.value)}
          placeholder="e.g. Congratulations! I'll send the lease shortly."
          style={{ width: '100%', border: '1px solid var(--wla-gborder)', borderRadius: 9, padding: '.55rem .7rem', fontSize: '.85rem' }}
        />
        {decideError && <p style={{ color: 'var(--wla-oxblood)', fontSize: '.85rem', marginTop: '.5rem' }}>{decideError}</p>}
      </Modal>

      <Modal
        open={declineOpen}
        onClose={() => !deciding && setDeclineOpen(false)}
        title={`Decline ${name.split(' ')[0]}?`}
        description="Let them know respectfully. This is recorded for fair-leasing records."
        tone="danger"
        footer={
          <>
            <button className="btn btn-sm" onClick={() => setDeclineOpen(false)} disabled={deciding}>Cancel</button>
            <button className="btn btn-blood btn-sm" onClick={submitDecline} disabled={deciding}>{deciding ? 'Declining…' : 'Decline application'}</button>
          </>
        }
      >
        <label className="dl" style={{ display: 'block', margin: '.5rem 0 .35rem' }}>Reason for declining (optional)</label>
        <textarea
          rows={3}
          value={declineReason}
          onChange={(e) => setDeclineReason(e.target.value)}
          placeholder="e.g. The unit has been let to another applicant."
          style={{ width: '100%', border: '1px solid var(--wla-gborder)', borderRadius: 9, padding: '.55rem .7rem', fontSize: '.85rem' }}
        />
        {decideError && <p style={{ color: 'var(--wla-oxblood)', fontSize: '.85rem', marginTop: '.5rem' }}>{decideError}</p>}
      </Modal>

      <Modal
        open={infoOpen}
        onClose={() => !requestSending && setInfoOpen(false)}
        title="Request more information"
        description={`Ask ${name} for anything you need before deciding. They'll be notified through Wyncrest.`}
        footer={
          <>
            <button className="btn btn-sm" onClick={() => setInfoOpen(false)} disabled={requestSending}>Cancel</button>
            <button className="btn btn-amber btn-sm" onClick={submitRequestInfo} disabled={requestSending}>{requestSending ? 'Sending…' : 'Send request'}</button>
          </>
        }
      >
        <div style={{ display: 'flex', flexDirection: 'column', gap: '.7rem' }}>
          <div>
            <label className="dl" style={{ display: 'block', marginBottom: '.35rem' }}>Type</label>
            <select className="sel" style={{ width: '100%' }} value={requestType} onChange={(e) => setRequestType(e.target.value as typeof requestType)}>
              {REQUEST_TYPE_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
          </div>
          {requestType === 'document_replacement' && (
            <div>
              <label className="dl" style={{ display: 'block', marginBottom: '.35rem' }}>Document type</label>
              <select className="sel" style={{ width: '100%' }} value={requestDocType} onChange={(e) => setRequestDocType(e.target.value as DocumentType | '')}>
                <option value="">Select a document type…</option>
                {REQUEST_DOC_TYPE_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
              </select>
            </div>
          )}
          <div>
            <label className="dl" style={{ display: 'block', marginBottom: '.35rem' }}>Message</label>
            <textarea
              rows={3}
              value={requestMessage}
              onChange={(e) => { setRequestMessage(e.target.value); if (requestError) setRequestError(''); }}
              placeholder="Explain what you need from the applicant…"
              style={{ width: '100%', border: '1px solid var(--wla-gborder)', borderRadius: 9, padding: '.55rem .7rem', fontSize: '.85rem' }}
            />
            {requestError && <p style={{ color: 'var(--wla-oxblood)', fontSize: '.8rem', marginTop: '.3rem' }}>{requestError}</p>}
          </div>
          <div>
            <label className="dl" style={{ display: 'block', marginBottom: '.35rem' }}>Due date (optional)</label>
            <input
              type="date"
              value={requestDueAt}
              onChange={(e) => setRequestDueAt(e.target.value)}
              style={{ width: '100%', border: '1px solid var(--wla-gborder)', borderRadius: 9, padding: '.55rem .7rem', fontSize: '.85rem' }}
            />
          </div>
        </div>
      </Modal>
    </div>
  );
}

function VRow({ ok, title, sub }: { ok: boolean; title: string; sub: string }) {
  return (
    <div className={`vrow ${ok ? 'ok' : 'wait'}`}>
      <div className="vi">{ok ? <CheckGlyph /> : <IconClock />}</div>
      <div className="vm">
        <div className="vt">{title}</div>
        <div className="vs">{sub}</div>
      </div>
      <span className={`badge ${ok ? 'green' : 'amber'}`}>{ok ? 'Verified' : 'In review'}</span>
    </div>
  );
}

function CheckGlyph() {
  return <svg viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5" /></svg>;
}
