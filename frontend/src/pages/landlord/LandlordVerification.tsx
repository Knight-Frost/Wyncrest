/**
 * LandlordVerification — landlord identity verification status + document upload + submit.
 *
 * Truth contract:
 * - GET /landlord/verification → current status + latest_request
 * - POST /landlord/documents   → upload identity_document (multipart, shared endpoint)
 *   NOTE: Landlords use the same /landlord/documents path as tenants use /tenant/documents.
 *   Check api.php: landlord group has POST /landlord/avatar but documents endpoint may be
 *   under a different prefix. Surfaced below.
 * - POST /landlord/verification/submit → submit for review
 * - No fake data, no dead buttons.
 */
import { useCallback, useRef, useState } from 'react';
import { Link } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { landlordApi } from '@/lib/endpoints';
import { formatDate } from '@/lib/format';
import { useToast } from '@/components/ui/toast';
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/states';
import { SemanticBadge } from '@/components/cards';
import {
  IconCheckCircle,
  IconClock,
  IconDoc,
  IconFolder,
  IconShield,
  IconUpload,
  IconXCircle,
} from '@/components/ui/icons';
import type {
  DocumentType,
  TenantDocument,
  VerificationStatus,
  VerificationStatusResponse,
} from '@/lib/types';

/* ── helpers ─────────────────────────────────────────────────────────────── */

const STATUS_COPY: Record<
  VerificationStatus,
  { label: string; role: 'success' | 'warning' | 'danger' | 'info' | 'neutral'; headline: string; sub: string }
> = {
  unverified: {
    label: 'Not submitted',
    role: 'neutral',
    headline: 'Verify your identity to build trust with tenants',
    sub: 'Verified landlords get a trust badge on their listings. Upload a government-issued identity document and submit your request. Admin review usually takes one to two business days.',
  },
  pending: {
    label: 'Pending',
    role: 'info',
    headline: 'Your request is queued',
    sub: "We've received your submission. An admin will begin review shortly.",
  },
  under_review: {
    label: 'Under review',
    role: 'warning',
    headline: 'Your identity is being reviewed',
    sub: "An admin is reviewing your documents. You'll be notified when a decision is made.",
  },
  verified: {
    label: 'Verified',
    role: 'success',
    headline: 'Your identity is verified',
    sub: 'You have a verified landlord badge. Tenants can trust your listings are from a confirmed owner.',
  },
  rejected: {
    label: 'Rejected',
    role: 'danger',
    headline: 'Verification was not approved',
    sub: 'Your request was rejected. Please review the reason below, upload updated documents, and resubmit.',
  },
  needs_more_information: {
    label: 'More info needed',
    role: 'warning',
    headline: 'Additional information required',
    sub: 'The review team needs more from you. Review the note below, upload updated documents, and resubmit.',
  },
};

function statusRole(s: VerificationStatus | null): 'success' | 'warning' | 'danger' | 'info' | 'neutral' {
  if (!s) return 'neutral';
  return STATUS_COPY[s]?.role ?? 'neutral';
}

function canResubmit(s: VerificationStatus | null): boolean {
  return s === 'unverified' || s === 'rejected' || s === 'needs_more_information';
}

function isPending(s: VerificationStatus | null): boolean {
  return s === 'pending' || s === 'under_review';
}

/* ── Document uploader ───────────────────────────────────────────────────── */

interface DocumentUploaderProps {
  documentType: DocumentType;
  label: string;
  onUploaded: () => void;
  uploading: boolean;
  setUploading: (v: boolean) => void;
}

function DocumentUploader({ documentType, label, onUploaded, uploading, setUploading }: DocumentUploaderProps) {
  const { toast } = useToast();
  const inputRef = useRef<HTMLInputElement>(null);
  const [dragOver, setDragOver] = useState(false);

  async function handleFile(file: File) {
    if (uploading) return;
    const ok = file.type.startsWith('image/') || file.type === 'application/pdf';
    if (!ok) {
      toast('Please upload a PDF or image file.', 'error');
      return;
    }
    if (file.size > 10 * 1024 * 1024) {
      toast('Maximum file size is 10 MB.', 'error');
      return;
    }
    setUploading(true);
    try {
      await landlordApi.uploadDocument(file, documentType);
      toast(`Document uploaded: ${file.name}`, 'success');
      onUploaded();
    } catch (err: unknown) {
      const msg = (err as { message?: string })?.message ?? 'Upload failed. Please try again.';
      toast(msg, 'error');
    } finally {
      setUploading(false);
      if (inputRef.current) inputRef.current.value = '';
    }
  }

  return (
    <div
      className={`lv-uploader${dragOver ? ' drag-over' : ''}${uploading ? ' uploading' : ''}`}
      onDragOver={(e) => { e.preventDefault(); setDragOver(true); }}
      onDragLeave={() => setDragOver(false)}
      onDrop={(e) => {
        e.preventDefault();
        setDragOver(false);
        const file = e.dataTransfer.files?.[0];
        if (file) void handleFile(file);
      }}
      onClick={() => !uploading && inputRef.current?.click()}
      role="button"
      tabIndex={0}
      aria-label={`Upload ${label.toLowerCase()}`}
      onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') inputRef.current?.click(); }}
    >
      <input
        ref={inputRef}
        type="file"
        accept=".pdf,image/*"
        style={{ display: 'none' }}
        onChange={(e) => { const f = e.target.files?.[0]; if (f) void handleFile(f); }}
        disabled={uploading}
      />
      <span className="lv-uploader-icon">
        {uploading
          ? <span className="lv-spinner" aria-label="Uploading…" />
          : <IconUpload size={22} />
        }
      </span>
      <span className="lv-uploader-label">
        {uploading ? 'Uploading…' : `Upload ${label.toLowerCase()}`}
      </span>
      <span className="lv-uploader-hint">PDF or image · max 10 MB · drag or click</span>
    </div>
  );
}

/* ── Uploaded documents list ─────────────────────────────────────────────── */

function UploadedDocsList({
  docs,
  documentType,
  label,
}: {
  docs: TenantDocument[];
  documentType: DocumentType;
  label: string;
}) {
  const matching = docs.filter((d) => d.document_type === documentType);
  if (matching.length === 0) return null;

  return (
    <div className="lv-docs-list">
      <p className="lv-docs-label">{label}</p>
      {matching.map((doc) => (
        <div key={doc.id} className="lv-doc-row">
          <span className="lv-doc-icon"><IconDoc size={16} /></span>
          <div className="lv-doc-info">
            <span className="lv-doc-name">{doc.original_filename}</span>
            <span className="lv-doc-meta">{formatDate(doc.created_at)}</span>
          </div>
          <SemanticBadge role={doc.is_verified ? 'success' : 'neutral'} size="sm">
            {doc.is_verified ? 'Verified' : 'Uploaded'}
          </SemanticBadge>
        </div>
      ))}
    </div>
  );
}

/* ── Status hero ─────────────────────────────────────────────────────────── */

function StatusHero({ status }: { status: VerificationStatusResponse }) {
  const vs = (status.verification_status ?? 'unverified') as VerificationStatus;
  const copy = STATUS_COPY[vs];
  const role = statusRole(vs);

  const IconMap: Record<string, React.ReactNode> = {
    verified:               <IconCheckCircle size={28} />,
    pending:                <IconClock size={28} />,
    under_review:           <IconClock size={28} />,
    rejected:               <IconXCircle size={28} />,
    needs_more_information: <IconShield size={28} />,
    unverified:             <IconShield size={28} />,
  };

  return (
    <div className={`lv-hero lv-hero--${role}`}>
      <div className="lv-hero-icon">{IconMap[vs]}</div>
      <div className="lv-hero-body">
        <div className="lv-hero-top">
          <h2 className="lv-hero-title">{copy.headline}</h2>
          <SemanticBadge role={role}>{copy.label}</SemanticBadge>
        </div>
        <p className="lv-hero-sub">{copy.sub}</p>
        {status.latest_request?.decision_reason && (vs === 'rejected' || vs === 'needs_more_information') && (
          <div className="lv-reason">
            <span className="lv-reason-label">Reviewer note:</span>
            <span className="lv-reason-text">{status.latest_request.decision_reason}</span>
          </div>
        )}
        {status.latest_request?.submitted_at && (
          <p className="lv-hero-meta">
            Submitted {formatDate(status.latest_request.submitted_at)}
            {status.latest_request.reviewed_at && ` · Reviewed ${formatDate(status.latest_request.reviewed_at)}`}
          </p>
        )}
      </div>
    </div>
  );
}

/* ── Submit section ──────────────────────────────────────────────────────── */

function SubmitSection({
  status,
  hasIdDoc,
  onSubmitted,
}: {
  status: VerificationStatusResponse;
  hasIdDoc: boolean;
  onSubmitted: () => void;
}) {
  const { toast } = useToast();
  const [note, setNote] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const vs = (status.verification_status ?? 'unverified') as VerificationStatus;

  if (!canResubmit(vs)) return null;

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (submitting || !hasIdDoc) return;
    setSubmitting(true);
    try {
      await landlordApi.submitVerification(note || undefined);
      toast('Verification request submitted. We\'ll notify you once the review is complete.', 'success');
      onSubmitted();
    } catch (err: unknown) {
      const msg = (err as { message?: string })?.message ?? 'Could not submit. Please try again.';
      toast(msg, 'error');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form className="lv-submit-section" onSubmit={handleSubmit}>
      <h3 className="lv-section-title">Submit for review</h3>
      {!hasIdDoc && (
        <div className="lv-requirement-alert">
          You must upload at least one identity document above before submitting.
        </div>
      )}
      <label className="lv-field-label" htmlFor="lv-note">
        Optional note to reviewer
      </label>
      <textarea
        id="lv-note"
        className="lv-textarea"
        value={note}
        onChange={(e) => setNote(e.target.value)}
        placeholder="Anything the reviewer should know…"
        maxLength={1000}
        rows={3}
        disabled={submitting}
      />
      <button
        type="submit"
        className="lv-submit-btn"
        disabled={!hasIdDoc || submitting}
        aria-disabled={!hasIdDoc || submitting}
      >
        {submitting ? 'Submitting…' : 'Submit for verification'}
      </button>
    </form>
  );
}

/* ── Page ─────────────────────────────────────────────────────────────────── */

export function LandlordVerification() {
  const { data: status, loading: statusLoading, error: statusError, reload: reloadStatus } = useApi(
    () => landlordApi.verificationStatus(),
    [],
  );

  const [docsNonce, setDocsNonce] = useState(0);
  const reloadDocs = useCallback(() => setDocsNonce((n) => n + 1), []);

  const { data: docs, loading: docsLoading, error: docsError } = useApi(
    () => landlordApi.documents(),
    [docsNonce],
  );

  const [uploading, setUploading] = useState(false);

  function handleUploaded() {
    reloadDocs();
  }

  function handleSubmitted() {
    reloadStatus();
    reloadDocs();
  }

  const hasIdDoc = (docs ?? []).some((d) => d.document_type === 'identity_document');
  const vs = status?.verification_status ?? 'unverified';
  const showUploader = canResubmit(vs as VerificationStatus);
  const showPendingNote = isPending(vs as VerificationStatus);

  return (
    <div className="lv-page">
      <style>{LV_CSS}</style>

      {/* Page header */}
      <div className="lv-page-header">
        <p className="lv-eyebrow">Account · Security</p>
        <h1 className="lv-page-title">Identity Verification</h1>
        <p className="lv-page-desc">
          Verified landlords display a trust badge on their listings, building confidence with prospective tenants.
        </p>
      </div>

      {statusLoading && <LoadingState label="Loading verification status…" />}
      {statusError && <ErrorState message={statusError.message} onRetry={reloadStatus} />}

      {status && !statusLoading && (
        <>
          <StatusHero status={status} />

          {/* Documents section */}
          <div className="lv-section">
            <h3 className="lv-section-title">Identity documents</h3>
            <p className="lv-section-desc">
              Upload a government-issued ID (passport, national ID, or driver's licence).
              Supported formats: PDF, JPG, PNG.
            </p>

            {showUploader && (
              <DocumentUploader
                documentType="identity_document"
                label="identity document"
                onUploaded={handleUploaded}
                uploading={uploading}
                setUploading={setUploading}
              />
            )}

            {docsLoading && <LoadingState label="Loading documents…" />}
            {docsError && <ErrorState message={docsError.message} onRetry={reloadDocs} />}
            {docs && !docsLoading && (
              docs.filter((d) => d.document_type === 'identity_document').length === 0
                ? (
                  <EmptyState
                    icon={<IconFolder size={22} />}
                    title="No identity documents yet"
                    description="Upload a government ID to get started."
                  />
                )
                : <UploadedDocsList docs={docs} documentType="identity_document" label="Uploaded identity documents" />
            )}
          </div>

          {/* Proof of address — recommended for landlords managing physical properties */}
          <div className="lv-section">
            <h3 className="lv-section-title">Proof of address (recommended)</h3>
            <p className="lv-section-desc">
              A recent utility bill or bank statement showing your name and address. Recommended
              before approval since you manage physical properties, though not required to submit.
            </p>

            {showUploader && (
              <DocumentUploader
                documentType="proof_of_address"
                label="proof of address"
                onUploaded={handleUploaded}
                uploading={uploading}
                setUploading={setUploading}
              />
            )}

            {docs && !docsLoading && (
              <UploadedDocsList docs={docs} documentType="proof_of_address" label="Uploaded proof of address" />
            )}
          </div>

          {showPendingNote && (
            <div className="lv-pending-notice">
              Your request is in the queue. No action needed right now.
              We'll notify you when the review is complete.
            </div>
          )}

          <SubmitSection status={status} hasIdDoc={hasIdDoc} onSubmitted={handleSubmitted} />

          {vs === 'verified' && (
            <div className="lv-verified-cta">
              <IconCheckCircle size={20} />
              <span>Your listings display a verified landlord badge.</span>
              <Link to="/app/listings" className="lv-link">Go to Listings</Link>
            </div>
          )}
        </>
      )}
    </div>
  );
}

/* ── Styles ──────────────────────────────────────────────────────────────── */

const LV_CSS = `
.lv-page {
  max-width: 680px;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.lv-page-header {
  display: flex;
  flex-direction: column;
  gap: 0;
  background: var(--color-surface, #fff);
  border: 1px solid var(--color-ink-200, #E5E7EB);
  border-radius: var(--radius-2xl, 20px);
  box-shadow: var(--shadow-sm, 0 1px 2px rgba(0,0,0,.05));
  padding: clamp(1.4rem, 3vw, 2.1rem);
}
.lv-eyebrow {
  font-family: var(--font-mono, ui-monospace);
  font-size: 0.6rem;
  letter-spacing: .2em;
  text-transform: uppercase;
  color: var(--color-brand-700, #4338CA);
  display: inline-flex;
  align-items: center;
  gap: 0.6em;
}
.lv-eyebrow::before { content: ''; width: 24px; height: 1px; background: var(--color-brand-700, #4338CA); }
.lv-page-title {
  font-family: 'Fraunces', Georgia, serif;
  font-size: clamp(2.1rem, 4.4vw, 3rem);
  font-weight: 400;
  color: var(--color-ink-950, #0C0A09);
  line-height: 1.02;
  margin: 0.6rem 0 0.4rem;
}
.lv-page-desc { font-size: 0.9375rem; color: var(--color-ink-500, #6B7280); margin: 0; }

/* Hero */
.lv-hero {
  border-radius: 16px;
  border: 1.5px solid var(--color-ink-200, #E5E7EB);
  background: var(--color-surface, #FFFFFF);
  padding: 24px;
  display: flex;
  gap: 18px;
  align-items: flex-start;
}
.lv-hero--success { border-color: var(--color-success-200, #BBF7D0); background: var(--color-success-50, #F0FFF4); }
.lv-hero--danger  { border-color: var(--color-danger-200, #FCA5A5); background: var(--color-danger-50, #FFF5F5); }
.lv-hero--warning { border-color: var(--color-warning-200, #FDE68A); background: var(--color-warning-50, #FFFBEB); }
.lv-hero--info    { border-color: var(--color-brand-200, #A7D8D4); background: var(--color-brand-50, #F0F9FF); }

.lv-hero-icon {
  flex: 0 0 auto;
  width: 52px; height: 52px;
  border-radius: 50%;
  background: var(--color-ink-100, #F3F4F6);
  display: flex; align-items: center; justify-content: center;
  color: var(--color-ink-600, #4B5563);
}
.lv-hero--success .lv-hero-icon { background: var(--color-success-100, #D1FAE5); color: var(--color-success-600, #059669); }
.lv-hero--danger  .lv-hero-icon { background: var(--color-danger-100, #FEE2E2); color: var(--color-danger-600, #DC2626); }
.lv-hero--warning .lv-hero-icon { background: var(--color-warning-100, #FEF3C7); color: var(--color-warning-600, #D97706); }
.lv-hero--info    .lv-hero-icon { background: var(--color-brand-100, #CFFAFE); color: var(--color-brand-600, #0E7490); }

.lv-hero-body { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 8px; }
.lv-hero-top  { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.lv-hero-title {
  font-family: 'Fraunces', Georgia, serif;
  font-size: 1.1875rem;
  font-weight: 600;
  color: var(--color-ink-900, #111827);
  flex: 1;
}
.lv-hero-sub  { font-size: 0.9rem; color: var(--color-ink-600, #4B5563); line-height: 1.55; }
.lv-hero-meta { font-size: 0.8125rem; color: var(--color-ink-400, #9CA3AF); }
.lv-reason {
  background: var(--color-ink-50, #F9FAFB);
  border-left: 3px solid var(--color-danger-400, #F87171);
  border-radius: 4px 8px 8px 4px;
  padding: 10px 14px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.lv-reason-label { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; color: var(--color-ink-500, #6B7280); }
.lv-reason-text  { font-size: 0.9rem; color: var(--color-ink-800, #1F2937); }

/* Section */
.lv-section {
  border-radius: 16px;
  border: 1px solid var(--color-ink-200, #E5E7EB);
  background: var(--color-surface, #FFFFFF);
  padding: 24px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.lv-section-title {
  font-family: 'Fraunces', Georgia, serif;
  font-size: 1.05rem;
  font-weight: 600;
  color: var(--color-ink-900, #111827);
}
.lv-section-desc { font-size: 0.875rem; color: var(--color-ink-500, #6B7280); line-height: 1.5; margin-top: -6px; }

/* Documents list */
.lv-docs-label { font-size: 0.8125rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; color: var(--color-ink-400, #9CA3AF); }
.lv-docs-list { display: flex; flex-direction: column; gap: 8px; }
.lv-doc-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  border-radius: 10px;
  background: var(--color-ink-50, #F9FAFB);
  border: 1px solid var(--color-ink-200, #E5E7EB);
}
.lv-doc-icon { color: var(--color-ink-400, #9CA3AF); flex: 0 0 auto; }
.lv-doc-info { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; }
.lv-doc-name { font-size: 0.875rem; font-weight: 500; color: var(--color-ink-800, #1F2937); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.lv-doc-meta { font-size: 0.75rem; color: var(--color-ink-400, #9CA3AF); }

/* Requirement alert */
.lv-requirement-alert {
  background: var(--color-warning-50, #FFFBEB);
  border: 1px solid var(--color-warning-200, #FDE68A);
  border-radius: 10px;
  padding: 12px 16px;
  font-size: 0.875rem;
  color: var(--color-warning-800, #92400E);
}

/* Uploader */
.lv-uploader {
  border: 2px dashed var(--color-ink-300, #D1D5DB);
  border-radius: 12px;
  background: var(--color-ink-50, #F9FAFB);
  padding: 28px 24px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  transition: border-color 0.15s, background 0.15s;
  user-select: none;
  outline: none;
}
.lv-uploader:focus-visible { outline: 2px solid var(--color-brand-500, #0EA5E9); outline-offset: 2px; }
.lv-uploader:hover, .lv-uploader.drag-over {
  border-color: var(--color-brand-500, #0EA5E9);
  background: var(--color-brand-50, #F0F9FF);
}
.lv-uploader.uploading { opacity: 0.6; cursor: not-allowed; }
.lv-uploader-icon { color: var(--color-brand-600, #0284C7); }
.lv-uploader-label { font-size: 0.9375rem; font-weight: 600; color: var(--color-ink-800, #1F2937); }
.lv-uploader-hint { font-size: 0.8125rem; color: var(--color-ink-400, #9CA3AF); }

.lv-spinner {
  display: inline-block;
  width: 22px; height: 22px;
  border: 2.5px solid var(--color-brand-200, #BAE6FD);
  border-top-color: var(--color-brand-600, #0284C7);
  border-radius: 50%;
  animation: lv-spin 0.75s linear infinite;
}
@keyframes lv-spin { to { transform: rotate(360deg); } }

/* Pending notice */
.lv-pending-notice {
  background: var(--color-brand-50, #F0F9FF);
  border: 1px solid var(--color-brand-200, #BAE6FD);
  border-radius: 12px;
  padding: 16px 20px;
  font-size: 0.9rem;
  color: var(--color-brand-800, #075985);
  line-height: 1.55;
}

/* Submit section */
.lv-submit-section {
  border-radius: 16px;
  border: 1px solid var(--color-ink-200, #E5E7EB);
  background: var(--color-surface, #FFFFFF);
  padding: 24px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.lv-field-label { font-size: 0.875rem; font-weight: 500; color: var(--color-ink-700, #374151); }
.lv-textarea {
  width: 100%;
  border-radius: 10px;
  border: 1.5px solid var(--color-ink-200, #E5E7EB);
  background: var(--color-ink-50, #F9FAFB);
  padding: 10px 14px;
  font-size: 0.9375rem;
  color: var(--color-ink-800, #1F2937);
  resize: vertical;
  font-family: inherit;
  transition: border-color 0.15s;
  box-sizing: border-box;
}
.lv-textarea:focus { outline: none; border-color: var(--color-brand-500, #0EA5E9); }
.lv-submit-btn {
  align-self: flex-start;
  padding: 11px 24px;
  border-radius: 10px;
  background: var(--color-brand-600, #0284C7);
  color: #fff;
  font-size: 0.9375rem;
  font-weight: 600;
  border: none;
  cursor: pointer;
  transition: background 0.15s, opacity 0.15s;
}
.lv-submit-btn:hover:not(:disabled) { background: var(--color-brand-700, #0369A1); }
.lv-submit-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.lv-submit-btn:focus-visible { outline: 2px solid var(--color-brand-500, #0EA5E9); outline-offset: 2px; }

/* Verified CTA */
.lv-verified-cta {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 16px 20px;
  border-radius: 12px;
  background: var(--color-success-50, #F0FFF4);
  border: 1px solid var(--color-success-200, #BBF7D0);
  font-size: 0.9rem;
  color: var(--color-success-800, #065F46);
}
.lv-link {
  margin-left: auto;
  font-weight: 600;
  color: var(--color-brand-600, #0284C7);
  text-decoration: none;
}
.lv-link:hover { text-decoration: underline; }

@media (prefers-reduced-motion: reduce) {
  .lv-spinner { animation: none; }
}
`;
