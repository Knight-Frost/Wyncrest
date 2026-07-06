/**
 * ApplicationForm — the guided, multi-step rental application (draft only).
 *
 * Real data only: hydrates from GET /tenant/applications/{id}, autosaves each
 * section via PATCH /tenant/applications/{id}, uploads documents via
 * POST /tenant/applications/{id}/documents, and submits via
 * POST /tenant/applications/{id}/submit. Non-draft applications redirect to the
 * read-only detail workspace.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useParams, useNavigate, Link } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { tenantApi } from '@/lib/endpoints';
import { formatCedisDecimal } from '@/lib/format';
import { useToast } from '@/components/ui/toast';
import { LoadingState, ErrorState } from '@/components/ui/states';
import {
  IconChevronLeft,
  IconCheck,
  IconUpload,
  IconCircleCheck,
  IconShield,
} from '@/components/ui/icons';
import type {
  Application,
  ApplicationFormData,
  TenantDocument,
  DocumentType,
} from '@/lib/types';
import {
  SECTIONS,
  sectionDone,
  draftReadyToSubmit,
  homeTitle,
  unitLabel,
  homeAddress,
  rentAmount,
  APP_DOC_REQUIREMENTS,
  hasDocOfType,
} from './applicationHelpers';
import './applications.css';

type Section = keyof ApplicationFormData;

export function ApplicationForm() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { toast } = useToast();
  const appId = Number(id);

  const q = useApi(() => tenantApi.application(appId), [appId]);

  const [form, setForm] = useState<ApplicationFormData>({});
  const [docs, setDocs] = useState<TenantDocument[]>([]);
  const [step, setStep] = useState(0);
  const [confirm, setConfirm] = useState({ a: false, b: false, c: false });
  const [saveState, setSaveState] = useState<'idle' | 'saving' | 'saved'>('idle');
  const [submitting, setSubmitting] = useState(false);
  const [done, setDone] = useState(false);
  const saveTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const hydrated = useRef(false);

  // Hydrate once from the loaded application.
  useEffect(() => {
    if (q.data && !hydrated.current) {
      hydrated.current = true;
      setForm(q.data.form_data ?? {});
      setDocs(q.data.documents ?? []);
      if (q.data.status !== 'draft') {
        navigate(`/app/applications/${appId}`, { replace: true });
      }
    }
  }, [q.data, appId, navigate]);

  const persist = useCallback(
    (next: ApplicationFormData) => {
      setSaveState('saving');
      if (saveTimer.current) clearTimeout(saveTimer.current);
      saveTimer.current = setTimeout(async () => {
        try {
          await tenantApi.saveApplicationDraft(appId, next);
          setSaveState('saved');
        } catch {
          setSaveState('idle');
          toast('Could not save your changes.', 'error');
        }
      }, 700);
    },
    [appId, toast],
  );

  const setField = useCallback(
    (section: Section, field: string, value: string) => {
      setForm((prev) => {
        const next: ApplicationFormData = {
          ...prev,
          [section]: { ...(prev[section] as Record<string, string> | undefined), [field]: value },
        };
        persist(next);
        return next;
      });
    },
    [persist],
  );

  const app = q.data;

  const doneFlags = useMemo(
    () => SECTIONS.map((_, i) => sectionDone(form, docs, i)),
    [form, docs],
  );
  const canSubmit =
    draftReadyToSubmit(form, docs) && confirm.a && confirm.b && confirm.c;

  if (q.loading) return <div className="wapp"><LoadingState label="Loading application…" /></div>;
  if (q.error || !app) {
    return <div className="wapp"><ErrorState title="Couldn't load application" message={q.error?.message ?? 'Not found'} onRetry={q.reload} /></div>;
  }

  async function handleUpload(file: File, type: DocumentType) {
    const ok = file.type.startsWith('image/') || file.type === 'application/pdf';
    if (!ok) { toast('Please upload a PDF or image file.', 'error'); return; }
    if (file.size > 10 * 1024 * 1024) { toast('Maximum file size is 10 MB.', 'error'); return; }
    try {
      const res = await tenantApi.uploadApplicationDocument(appId, file, type);
      setDocs(res.application.documents ?? []);
      toast('Document uploaded.', 'success');
    } catch {
      toast('Upload failed. Please try again.', 'error');
    }
  }

  async function handleSubmit() {
    if (submitting || !canSubmit) return;
    setSubmitting(true);
    try {
      await tenantApi.submitApplication(appId);
      setDone(true);
      window.scrollTo({ top: 0 });
    } catch (err: unknown) {
      toast((err as { message?: string })?.message ?? 'Could not submit. Please try again.', 'error');
    } finally {
      setSubmitting(false);
    }
  }

  if (done) return <ConfirmScreen app={app} />;

  const last = SECTIONS.length - 1;

  return (
    <div className="wapp">
      <div className="wapp-crumb">
        <button className="wapp-back" onClick={() => navigate('/app/applications')} type="button">
          <IconChevronLeft size={15} aria-hidden="true" /> Back to Applications
        </button>
        <span className="sep">/</span>
        <Link to={`/app/applications/${appId}`} style={{ color: 'var(--color-ink-500)' }}>{homeTitle(app)}</Link>
        <span className="sep">/</span>
        <span>Application form</span>
      </div>

      <section className="wapp-glass" style={{ padding: 0 }}>
        <div className="wapp-fbar">
          <div className="fp">Application draft · {homeTitle(app)}</div>
          <div className="save">
            {saveState === 'saving' ? 'Saving…' : saveState === 'saved' ? <><IconCheck size={13} aria-hidden="true" /> Saved</> : 'Autosaves as you type'}
          </div>
        </div>

        <div className="wapp-fnav">
          {SECTIONS.map((sec, i) => (
            <button
              key={sec}
              type="button"
              className={i === step ? 'on' : doneFlags[i] ? 'ok' : ''}
              onClick={() => setStep(i)}
            >
              <span className="fd">{doneFlags[i] && i !== step ? '✓' : i + 1}</span>
              {sec}
            </button>
          ))}
        </div>

        <div className="wapp-fstep">
          <StepBody
            step={step}
            app={app}
            form={form}
            docs={docs}
            confirm={confirm}
            doneFlags={doneFlags}
            setField={setField}
            onUpload={handleUpload}
            onConfirm={(k, v) => setConfirm((c) => ({ ...c, [k]: v }))}
          />

          <div className="wapp-fstep-btns">
            <button type="button" className="wapp-btn wapp-btn-ghost" disabled={step === 0} onClick={() => setStep((s) => Math.max(0, s - 1))}>
              Back
            </button>
            {step < last ? (
              <button type="button" className="wapp-btn wapp-btn-primary" onClick={() => setStep((s) => Math.min(last, s + 1))}>
                Continue
              </button>
            ) : (
              <button type="button" className="wapp-btn wapp-btn-success" disabled={!canSubmit || submitting} onClick={handleSubmit}>
                {submitting ? 'Submitting…' : 'Submit application'}
              </button>
            )}
          </div>
        </div>
      </section>
    </div>
  );
}

/* ── Step body ───────────────────────────────────────────────────────────── */

function StepBody({
  step,
  app,
  form,
  docs,
  confirm,
  doneFlags,
  setField,
  onUpload,
  onConfirm,
}: {
  step: number;
  app: Application;
  form: ApplicationFormData;
  docs: TenantDocument[];
  confirm: { a: boolean; b: boolean; c: boolean };
  doneFlags: boolean[];
  setField: (section: Section, field: string, value: string) => void;
  onUpload: (file: File, type: DocumentType) => void;
  onConfirm: (k: 'a' | 'b' | 'c', v: boolean) => void;
}) {
  if (step === 0) {
    return (
      <>
        <h3>Property you are applying for</h3>
        <div className="wapp-fsub">Make sure this is the right home before you continue.</div>
        <div className="wapp-finfo">
          <div className="fih">{homeTitle(app)}{unitLabel(app) ? `, ${unitLabel(app)}` : ''}</div>
          <div className="wapp-fikv"><span>Address</span><b>{homeAddress(app) || '—'}</b></div>
          {rentAmount(app) && <div className="wapp-fikv"><span>Rent</span><b>{formatCedisDecimal(rentAmount(app))}/mo</b></div>}
          <div className="wapp-fikv"><span>Landlord</span><b>{app.listing?.unit?.property?.name ?? '—'}</b></div>
        </div>
        <Link to={`/app/listing/${app.listing_id}`} className="wapp-btn wapp-btn-glass wapp-btn-sm">View listing</Link>
      </>
    );
  }

  if (step === 1) {
    return (
      <>
        <h3>Personal information</h3>
        <div className="wapp-fsub">This should match your ID. Identity verification is handled separately.</div>
        <div className="wapp-frow">
          <Field label="Legal first name" req value={form.personal?.first} onChange={(v) => setField('personal', 'first', v)} />
          <Field label="Legal last name" req value={form.personal?.last} onChange={(v) => setField('personal', 'last', v)} />
        </div>
        <div className="wapp-frow">
          <Field label="Preferred name" value={form.personal?.preferred} onChange={(v) => setField('personal', 'preferred', v)} />
          <Field label="Date of birth" placeholder="DD/MM/YYYY" value={form.personal?.dob} onChange={(v) => setField('personal', 'dob', v)} />
        </div>
        <div className="wapp-frow">
          <Field label="Email" type="email" req value={form.personal?.email} onChange={(v) => setField('personal', 'email', v)} />
          <Field label="Phone" req value={form.personal?.phone} onChange={(v) => setField('personal', 'phone', v)} />
        </div>
        <div className="wapp-finfo">
          <div className="fih"><IconShield size={16} aria-hidden="true" /> Identity verification</div>
          <div style={{ fontSize: '0.86rem', color: 'var(--color-ink-600)', marginTop: '0.4rem' }}>
            Your platform identity is verified separately — no need to re-enter your ID here.{' '}
            <Link to="/app/verification" style={{ color: 'var(--color-brand-600)', fontWeight: 600 }}>View verification</Link>
          </div>
        </div>
        <Field
          label="Preferred contact method"
          type="select"
          value={form.contact?.pref}
          options={['', 'Email', 'SMS', 'Phone call', 'No preference']}
          onChange={(v) => setField('contact', 'pref', v)}
        />
      </>
    );
  }

  if (step === 2) {
    return (
      <>
        <h3>Employment &amp; income</h3>
        <div className="wapp-fsub">Share where your income comes from. This is used only for this application.</div>
        <Field
          label="Employment status" req type="select" value={form.employment?.status}
          options={['', 'Employed full-time', 'Employed part-time', 'Self-employed', 'Student', 'Retired', 'Unemployed', 'Other']}
          onChange={(v) => setField('employment', 'status', v)}
        />
        <div className="wapp-frow">
          <Field label="Employer name" value={form.employment?.employer} onChange={(v) => setField('employment', 'employer', v)} />
          <Field label="Job title" value={form.employment?.title} onChange={(v) => setField('employment', 'title', v)} />
        </div>
        <div className="wapp-frow">
          <Field label="Monthly income (GH₵)" req placeholder="e.g. 9200" value={form.employment?.income} onChange={(v) => setField('employment', 'income', v)} />
          <Field label="Employment start" placeholder="Month / Year" value={form.employment?.start} onChange={(v) => setField('employment', 'start', v)} />
        </div>
        <Field label="Other income sources" type="textarea" placeholder="e.g. rental income, allowances" value={form.employment?.other} onChange={(v) => setField('employment', 'other', v)} />
        <UploadCard
          label="Proof of income" required
          rule="Pay stub, offer letter, or bank statement · PDF/JPG/PNG · max 10 MB"
          uploaded={hasDocOfType(docs, 'proof_of_income')}
          onUpload={(f) => onUpload(f, 'proof_of_income')}
        />
      </>
    );
  }

  if (step === 3) {
    return (
      <>
        <h3>Rental history</h3>
        <div className="wapp-fsub">Tell us about where you live now.</div>
        <Field
          label="Current residence type" req type="select" value={form.rental?.curType}
          options={['', 'Apartment', 'House', 'Studio', 'Family home', 'Other']}
          onChange={(v) => setField('rental', 'curType', v)}
        />
        <div className="wapp-frow">
          <Field label="Current landlord / manager" value={form.rental?.curLandlord} onChange={(v) => setField('rental', 'curLandlord', v)} />
          <Field label="Landlord contact" value={form.rental?.curContact} onChange={(v) => setField('rental', 'curContact', v)} />
        </div>
        <div className="wapp-frow">
          <Field label="Current monthly rent (GH₵)" value={form.rental?.curRent} onChange={(v) => setField('rental', 'curRent', v)} />
          <Field label="Desired move-in date" req placeholder="e.g. 1 Sep 2026" value={form.rental?.moveIn} onChange={(v) => setField('rental', 'moveIn', v)} />
        </div>
        <Field label="Reason for moving" type="textarea" placeholder="e.g. lease ending, relocating for work" value={form.rental?.reason} onChange={(v) => setField('rental', 'reason', v)} />
      </>
    );
  }

  if (step === 4) {
    return (
      <>
        <h3>Household details</h3>
        <div className="wapp-fsub">Who will live in the home, and any pets or vehicles.</div>
        <div className="wapp-frow">
          <Field label="Number of adults" req type="select" value={form.household?.adults} options={['', '1', '2', '3', '4', '5+']} onChange={(v) => setField('household', 'adults', v)} />
          <Field label="Number of children" type="select" value={form.household?.children} options={['', '0', '1', '2', '3', '4+']} onChange={(v) => setField('household', 'children', v)} />
        </div>
        <Pillset label="Do you have pets?" options={['No', 'Yes']} value={form.household?.pets} onChange={(v) => setField('household', 'pets', v)} />
        {form.household?.pets === 'Yes' && (
          <Field label="Tell us about your pets" type="textarea" placeholder="Type, breed, number." value={form.household?.petDetail} onChange={(v) => setField('household', 'petDetail', v)} />
        )}
        <Pillset label="Do you have a vehicle needing parking?" options={['No', 'Yes']} value={form.household?.vehicles} onChange={(v) => setField('household', 'vehicles', v)} />
      </>
    );
  }

  if (step === 5) {
    return (
      <>
        <h3>Documents</h3>
        <div className="wapp-fsub">Only the documents this application needs. Files are used only for review.</div>
        <div className="wapp-finfo" style={{ background: 'var(--color-ink-50)' }}>
          <div style={{ fontSize: '0.84rem', color: 'var(--color-ink-600)' }}>
            Accepted: PDF, JPG, PNG · max 10 MB · make sure your name is visible · no password-protected files.
          </div>
        </div>
        {APP_DOC_REQUIREMENTS.map((r) => (
          <UploadCard
            key={r.key}
            label={r.label}
            required={r.required}
            rule={r.rule}
            uploaded={hasDocOfType(docs, r.key)}
            onUpload={(f) => onUpload(f, r.key)}
          />
        ))}
      </>
    );
  }

  // step 6 — review & submit
  const checks: [string, boolean][] = [
    ['Personal information complete', doneFlags[1]],
    ['Employment and income complete', doneFlags[2]],
    ['Rental history complete', doneFlags[3]],
    ['Household details complete', doneFlags[4]],
    ['Required documents uploaded', doneFlags[5]],
  ];
  const allOk = checks.every((c) => c[1]);

  return (
    <>
      <h3>Review &amp; submit</h3>
      <div className="wapp-fsub">Check everything looks right before sending to the landlord.</div>
      <div className="wapp-checklist">
        {checks.map(([label, ok]) => (
          <div key={label} className={`wapp-chk ${ok ? 'ok' : 'no'}`}>
            <span className="ci">{ok ? <IconCheck size={12} aria-hidden="true" /> : <span aria-hidden="true">✕</span>}</span>
            <span>{label}</span>
          </div>
        ))}
      </div>
      {allOk ? (
        <div className="wapp-confirm">
          <div className="cbh">Before you submit</div>
          <p>By submitting, you confirm the information provided is accurate. Your application goes to the landlord for review.</p>
          <label className="wapp-agree"><input type="checkbox" checked={confirm.a} onChange={(e) => onConfirm('a', e.target.checked)} />I confirm the information in this application is accurate.</label>
          <label className="wapp-agree"><input type="checkbox" checked={confirm.b} onChange={(e) => onConfirm('b', e.target.checked)} />I understand the landlord may request additional information.</label>
          <label className="wapp-agree"><input type="checkbox" checked={confirm.c} onChange={(e) => onConfirm('c', e.target.checked)} />I agree to Wyncrest's application terms.</label>
        </div>
      ) : (
        <div className="wapp-finfo" style={{ background: 'color-mix(in srgb, var(--color-warning-500) 8%, transparent)', borderColor: 'color-mix(in srgb, var(--color-warning-500) 30%, transparent)' }}>
          <div style={{ fontSize: '0.88rem', color: 'var(--color-warning-600)' }}>
            <b>A few items still need attention.</b> Complete the unchecked sections above before submitting.
          </div>
        </div>
      )}
    </>
  );
}

/* ── Field primitives ────────────────────────────────────────────────────── */

function Field({
  label,
  value,
  onChange,
  type = 'text',
  req,
  placeholder,
  options,
}: {
  label: string;
  value: string | undefined;
  onChange: (v: string) => void;
  type?: 'text' | 'email' | 'select' | 'textarea';
  req?: boolean;
  placeholder?: string;
  options?: string[];
}) {
  return (
    <div className={`wapp-field${req ? ' req' : ''}`}>
      <label>{label}{!req && <span className="opt"> (optional)</span>}</label>
      {type === 'select' ? (
        <select value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
          {(options ?? []).map((o) => <option key={o} value={o}>{o || 'Select…'}</option>)}
        </select>
      ) : type === 'textarea' ? (
        <textarea value={value ?? ''} placeholder={placeholder} onChange={(e) => onChange(e.target.value)} />
      ) : (
        <input type={type} value={value ?? ''} placeholder={placeholder} onChange={(e) => onChange(e.target.value)} />
      )}
    </div>
  );
}

function Pillset({
  label,
  options,
  value,
  onChange,
}: {
  label: string;
  options: string[];
  value: string | undefined;
  onChange: (v: string) => void;
}) {
  return (
    <div className="wapp-field" style={{ marginBottom: '1rem' }}>
      <label>{label}</label>
      <div className="wapp-pillset">
        {options.map((o) => (
          <button key={o} type="button" className={`wapp-ps${value === o ? ' on' : ''}`} onClick={() => onChange(o)}>{o}</button>
        ))}
      </div>
    </div>
  );
}

function UploadCard({
  label,
  required,
  rule,
  uploaded,
  onUpload,
}: {
  label: string;
  required: boolean;
  rule: string;
  uploaded: boolean;
  onUpload: (file: File) => void;
}) {
  const ref = useRef<HTMLInputElement>(null);
  return (
    <div className="wapp-upcard">
      <div className="uh">
        <div>
          <div className="un">{label}{required ? '' : ' (optional)'}</div>
          <div className="urule">Status: <b>{uploaded ? 'Uploaded' : 'Not uploaded'}</b> · {rule}</div>
        </div>
        <input ref={ref} type="file" accept=".pdf,image/*" style={{ display: 'none' }} onChange={(e) => { const f = e.target.files?.[0]; if (f) onUpload(f); if (ref.current) ref.current.value = ''; }} />
        <button type="button" className={`wapp-btn wapp-btn-sm ${uploaded ? 'wapp-btn-glass' : 'wapp-btn-primary'}`} onClick={() => ref.current?.click()}>
          <IconUpload size={14} aria-hidden="true" /> {uploaded ? 'Replace' : 'Upload'}
        </button>
      </div>
    </div>
  );
}

/* ── Confirmation screen ─────────────────────────────────────────────────── */

function ConfirmScreen({ app }: { app: Application }) {
  return (
    <div className="wapp">
      <section className="wapp-glass">
        <div className="wapp-confscreen">
          <div className="ic"><IconCircleCheck size={32} aria-hidden="true" /></div>
          <h2>Application submitted</h2>
          <p>Your application for {homeTitle(app)}{unitLabel(app) ? `, ${unitLabel(app)}` : ''} has been sent to the landlord for review.</p>
          <div className="wapp-nextsteps">
            <div className="wapp-ns"><span className="nsn">1</span><span>The landlord reviews your application.</span></div>
            <div className="wapp-ns"><span className="nsn">2</span><span>They may request additional information.</span></div>
            <div className="wapp-ns"><span className="nsn">3</span><span>You'll be notified when your status changes.</span></div>
            <div className="wapp-ns"><span className="nsn">4</span><span>If approved, you may receive a lease agreement.</span></div>
          </div>
          <div style={{ display: 'flex', gap: '0.6rem', justifyContent: 'center', flexWrap: 'wrap' }}>
            <Link to={`/app/applications/${app.id}`} className="wapp-btn wapp-btn-primary">View application progress</Link>
            <Link to="/app/applications" className="wapp-btn wapp-btn-glass">Back to applications</Link>
          </div>
        </div>
      </section>
    </div>
  );
}
