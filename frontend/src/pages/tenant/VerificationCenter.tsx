/**
 * VerificationCenter — tenant identity verification status + document upload + submit.
 *
 * Truth contract:
 * - GET /tenant/verification → current status + latest_request
 * - POST /tenant/documents   → upload identity_document / proof_of_address (multipart)
 * - DELETE /tenant/documents/{id} → remove an uploaded document (owner-only)
 * - POST /tenant/verification/submit → submit for review (requires ≥1 identity_document)
 * - GET /tenant/documents    → list uploaded docs (to show what's already there)
 *
 * There is no selfie/liveness capture, no document-subtype (Ghana Card vs. passport)
 * tracking, no re-verification/expiry, and reviewer notes are internal-only — the
 * single tenant-visible signal is `decision_reason` on the latest request. The only
 * feature actually gated on verification is submitting a rental application
 * (see ApplicationController::store); leases, payments, and messaging are not
 * gated, so this page never claims otherwise.
 *
 * No fake data, no dead buttons. Every action calls a real endpoint.
 */
import { useCallback, useRef, useState } from 'react';
import { Link } from 'react-router';
import { useAuth } from '@/context/auth';
import { useApi } from '@/hooks/useApi';
import { tenantApi } from '@/lib/endpoints';
import { formatDate } from '@/lib/format';
import { useToast } from '@/components/ui/toast';
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/states';
import { SemanticBadge } from '@/components/cards';
import {
  IconCheckCircle,
  IconClock,
  IconShield,
  IconUpload,
  IconDoc,
  IconXCircle,
  IconFolder,
  IconAlertTriangle,
  IconTrash,
  IconLock,
  IconEye,
  IconArrowUpRight,
} from '@/components/ui/icons';
import type {
  VerificationStatus,
  VerificationStatusResponse,
  VerificationRequest,
  TenantDocument,
  DocumentType,
  ApiError,
} from '@/lib/types';
import { InfoHint } from '@/components/ui/InfoHint';
import { help } from '@/lib/helpText';
import './verification.css';

/* ── helpers ─────────────────────────────────────────────────────────────── */

type Role = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

function canResubmit(s: VerificationStatus | null): boolean {
  return s === 'unverified' || s === 'rejected' || s === 'needs_more_information';
}

function isPending(s: VerificationStatus | null): boolean {
  return s === 'pending' || s === 'under_review';
}

function scrollToId(id: string) {
  document.getElementById(id)?.scrollIntoView({ block: 'start' });
}

function statusBadgeLabel(s: VerificationStatus | null): string {
  switch (s) {
    case 'verified': return 'Verified';
    case 'pending':
    case 'under_review': return 'Under review';
    case 'rejected': return 'Rejected';
    case 'needs_more_information': return 'More info needed';
    default: return 'Not submitted';
  }
}

function statusBadgeRole(s: VerificationStatus | null): Role {
  switch (s) {
    case 'verified': return 'success';
    case 'pending':
    case 'under_review': return 'info';
    case 'rejected': return 'danger';
    case 'needs_more_information': return 'warning';
    default: return 'neutral';
  }
}

/* ── Hero content, computed from real state only ────────────────────────── */

interface HeroContent {
  role: Role;
  icon: React.ReactNode;
  eyebrow: string;
  headline: string;
  sub: string;
  tick?: boolean;
}

function heroContent(vs: VerificationStatus, hasIdDoc: boolean, firstName: string): HeroContent {
  if (vs === 'verified') {
    return {
      role: 'success',
      icon: <IconCheckCircle size={26} />,
      tick: true,
      eyebrow: 'Verified',
      headline: 'You are verified',
      sub: 'Your identity has been confirmed. You can apply to any listing on Wyncrest.',
    };
  }
  if (isPending(vs)) {
    return {
      role: 'info',
      icon: <IconClock size={26} />,
      eyebrow: 'Under review',
      headline: `Thanks, ${firstName || 'there'} — we're reviewing your identity`,
      sub: "An admin will review your documents and decide. We'll notify you as soon as there's an update.",
    };
  }
  if (vs === 'needs_more_information') {
    return {
      role: 'warning',
      icon: <IconAlertTriangle size={26} />,
      eyebrow: 'Needs action',
      headline: 'One thing needs another look',
      sub: 'A reviewer needs more from you before they can decide. Read the note below, update your documents, and resubmit.',
    };
  }
  if (vs === 'rejected') {
    return {
      role: 'danger',
      icon: <IconXCircle size={26} />,
      eyebrow: 'Not verified',
      headline: 'We could not verify your identity',
      sub: 'Read the reason below, then upload corrected documents and try again.',
    };
  }
  if (hasIdDoc) {
    return {
      role: 'info',
      icon: <IconShield size={26} />,
      eyebrow: 'In progress',
      headline: "You've added your documents",
      sub: "Review what you've uploaded and submit when you're ready. It only takes a moment.",
    };
  }
  return {
    role: 'neutral',
    icon: <IconShield size={26} />,
    eyebrow: 'Get verified',
    headline: 'Verify your identity',
    sub: 'A one-time check that keeps Wyncrest trustworthy for everyone. Have a government ID ready — it takes about two minutes.',
  };
}

/* ── Document uploader ───────────────────────────────────────────────────── */

function DocumentUploader({
  documentType,
  label,
  onUploaded,
}: {
  documentType: DocumentType;
  label: string;
  onUploaded: () => void;
}) {
  const { toast } = useToast();
  const inputRef = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = useState(false);
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
      await tenantApi.uploadDocument(file, documentType);
      toast(`Uploaded: ${file.name}`, 'success');
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
      className={`vfy-uploader${dragOver ? ' drag-over' : ''}${uploading ? ' uploading' : ''}`}
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
      <span className="vfy-uploader-icon">
        {uploading ? <span className="vfy-spinner" aria-label="Uploading…" /> : <IconUpload size={20} />}
      </span>
      <span className="vfy-uploader-label">
        {uploading ? 'Uploading…' : `Upload ${label.toLowerCase()}`}
      </span>
      <span className="vfy-uploader-hint">PDF or image · max 10 MB · drag or click</span>
    </div>
  );
}

/* ── Uploaded documents list ─────────────────────────────────────────────── */

function UploadedDocsList({
  docs,
  documentType,
  canDelete,
  onDeleted,
}: {
  docs: TenantDocument[];
  documentType: DocumentType;
  canDelete: boolean;
  onDeleted: () => void;
}) {
  const { toast } = useToast();
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const matching = docs.filter((d) => d.document_type === documentType);
  if (matching.length === 0) return null;

  async function handleDelete(id: number) {
    setDeletingId(id);
    try {
      await tenantApi.deleteDocument(id);
      toast('Document removed.', 'success');
      onDeleted();
    } catch {
      toast('Could not remove document. Please try again.', 'error');
    } finally {
      setDeletingId(null);
    }
  }

  return (
    <div className="vfy-docs-list">
      {matching.map((doc) => (
        <div key={doc.id} className="vfy-doc-row">
          <span className="vfy-doc-icon"><IconDoc size={16} /></span>
          <div className="vfy-doc-info">
            <span className="vfy-doc-name">{doc.original_filename}</span>
            <span className="vfy-doc-meta">{formatDate(doc.created_at)}</span>
          </div>
          <SemanticBadge role={doc.is_verified ? 'success' : 'neutral'} size="sm">
            {doc.is_verified ? 'Verified' : 'Uploaded'}
          </SemanticBadge>
          {canDelete && (
            <button
              type="button"
              className="vfy-doc-del"
              aria-label={`Remove ${doc.original_filename}`}
              disabled={deletingId === doc.id}
              onClick={() => void handleDelete(doc.id)}
            >
              <IconTrash size={14} />
            </button>
          )}
        </div>
      ))}
    </div>
  );
}

/* ── Hero ─────────────────────────────────────────────────────────────────── */

function StatusHero({
  status,
  hasIdDoc,
  firstName,
}: {
  status: VerificationStatusResponse;
  hasIdDoc: boolean;
  firstName: string;
}) {
  const vs = (status.verification_status ?? 'unverified') as VerificationStatus;
  const latest: VerificationRequest | null = status.latest_request;
  const c = heroContent(vs, hasIdDoc, firstName);

  const showReason = latest?.decision_reason && (vs === 'rejected' || vs === 'needs_more_information');

  let cta: { label: string; onClick: () => void } | null = null;
  if (vs === 'unverified' && !hasIdDoc) cta = { label: 'Add your documents', onClick: () => scrollToId('vfy-documents') };
  else if (vs === 'unverified' && hasIdDoc) cta = { label: 'Continue to submit', onClick: () => scrollToId('vfy-submit') };
  else if (vs === 'needs_more_information') cta = { label: 'Update your documents', onClick: () => scrollToId('vfy-documents') };
  else if (vs === 'rejected') cta = { label: 'Try again', onClick: () => scrollToId('vfy-documents') };
  else if (isPending(vs)) cta = { label: 'See what happens next', onClick: () => scrollToId('vfy-timeline') };

  return (
    <div className={`vfy-hero vfy-glass role-${c.role}`}>
      <div className="vfy-hero-ic">
        {c.icon}
        {c.tick && <span className="vfy-hero-tick"><IconCheckCircle size={13} /></span>}
      </div>
      <div className="vfy-hero-body">
        <div className="vfy-hero-top">
          <span className="vfy-hero-eyebrow">{c.eyebrow}</span>
        </div>
        <h2 className="vfy-hero-h">{c.headline}</h2>
        <p className="vfy-hero-s">{c.sub}</p>

        {showReason && (
          <div className="vfy-reason" style={{ color: c.role === 'danger' ? 'var(--color-danger-500)' : 'var(--color-warning-500)' }}>
            <span className="vfy-reason-label">Reviewer note</span>
            <span className="vfy-reason-text">{latest?.decision_reason}</span>
          </div>
        )}

        {latest?.submitted_at && (
          <p className="vfy-hero-meta">
            Submitted {formatDate(latest.submitted_at)}
            {latest.reviewed_at && ` · Reviewed ${formatDate(latest.reviewed_at)}`}
          </p>
        )}

        {cta && (
          <div className="vfy-hero-cta">
            <button type="button" className="vfy-btn vfy-btn-primary" onClick={cta.onClick}>
              {cta.label}
            </button>
          </div>
        )}
        {vs === 'verified' && (
          <div className="vfy-hero-cta">
            <Link to="/app/browse" className="vfy-btn vfy-btn-primary">
              Browse listings <IconArrowUpRight size={15} />
            </Link>
          </div>
        )}
      </div>
    </div>
  );
}

/* ── Why we ask ──────────────────────────────────────────────────────────── */

function WhySection() {
  return (
    <div className="vfy-section vfy-glass">
      <h3 className="vfy-section-title">Why we ask</h3>
      <div className="vfy-why-grid">
        <div className="vfy-why-card">
          <div className="vfy-why-ic"><IconShield size={18} /></div>
          <div className="vfy-why-t">Builds trust</div>
          <div className="vfy-why-s">Verified renters help landlords feel confident reviewing your application.</div>
        </div>
        <div className="vfy-why-card">
          <div className="vfy-why-ic"><IconLock size={18} /></div>
          <div className="vfy-why-t">Unlocks applying</div>
          <div className="vfy-why-s">You need a verified identity before you can apply to any listing on Wyncrest.</div>
        </div>
        <div className="vfy-why-card">
          <div className="vfy-why-ic"><IconEye size={18} /></div>
          <div className="vfy-why-t">Protects everyone</div>
          <div className="vfy-why-s">Identity checks help stop impersonation and keep the platform safe for renters and owners.</div>
        </div>
      </div>
    </div>
  );
}

/* ── What happens next (pending / under review) ─────────────────────────── */

function TimelineSection({ latest }: { latest: VerificationRequest | null }) {
  return (
    <div id="vfy-timeline" className="vfy-section vfy-glass">
      <h3 className="vfy-section-title">What happens next</h3>
      <div className="vfy-tl">
        <div className="vfy-tl-item is-done">
          <div className="vfy-tl-e">
            Documents submitted <InfoHint text={help.verifPending} label="About documents submitted" />
          </div>
          <div className="vfy-tl-m">{latest?.submitted_at ? formatDate(latest.submitted_at) : '—'}</div>
        </div>
        <div className="vfy-tl-item is-current">
          <div className="vfy-tl-e">
            Admin review <InfoHint text={help.verifUnderReview} label="About admin review" />
          </div>
          <div className="vfy-tl-m">In progress</div>
        </div>
        <div className="vfy-tl-item is-pending">
          <div className="vfy-tl-e">
            Decision <InfoHint text={help.verifApproved} label="About decision" />
          </div>
          <div className="vfy-tl-m">You'll be notified</div>
        </div>
      </div>
      <p className="vfy-tl-note">
        You don't need to do anything while we review. We'll send you an in-app notification as soon as there's an update.
      </p>
    </div>
  );
}

/* ── Verified unlocks ────────────────────────────────────────────────────── */

function VerifiedSection({ latest }: { latest: VerificationRequest | null }) {
  return (
    <div className="vfy-section vfy-glass">
      <h3 className="vfy-section-title">What your verification unlocks</h3>
      <div className="vfy-badgebar">
        <div className="vfy-badgebar-ic"><IconCheckCircle size={18} /></div>
        <div>
          <div className="vfy-badgebar-t">Identity verified</div>
          <div className="vfy-badgebar-s">{latest?.reviewed_at ? `Verified ${formatDate(latest.reviewed_at)}` : 'Verified'}</div>
        </div>
      </div>
      <div className="vfy-unlock-row"><span className="vfy-unlock-check"><IconCheckCircle size={15} /></span>Apply to any listing on Wyncrest</div>
      <div className="vfy-unlock-row"><span className="vfy-unlock-check"><IconCheckCircle size={15} /></span>A confirmed identity on file for landlords and admins</div>
    </div>
  );
}

/* ── Documents section ───────────────────────────────────────────────────── */

function DocumentsSection({
  docs,
  docsLoading,
  docsError,
  reloadDocs,
  canManage,
}: {
  docs: TenantDocument[] | null;
  docsLoading: boolean;
  docsError: ApiError | null;
  reloadDocs: () => void;
  canManage: boolean;
}) {
  const identityDocs = (docs ?? []).filter((d) => d.document_type === 'identity_document');
  const addressDocs = (docs ?? []).filter((d) => d.document_type === 'proof_of_address');

  return (
    <div id="vfy-documents" className="vfy-section vfy-glass">
      <div>
        <h3 className="vfy-section-title">
          Identity document <span className="vfy-section-tag">required</span>{' '}
          <InfoHint text={help.documentUpload} label="About uploading documents" />
        </h3>
        <p className="vfy-section-desc">
          A government-issued ID — Ghana Card, passport, or driver's licence. If your ID has two sides,
          upload both as separate files.
        </p>
      </div>

      {canManage && (
        <DocumentUploader documentType="identity_document" label="identity document" onUploaded={reloadDocs} />
      )}

      {docsLoading && <LoadingState label="Loading documents…" />}
      {docsError && <ErrorState message={docsError.message} onRetry={reloadDocs} />}
      {docs && !docsLoading && (
        identityDocs.length === 0
          ? (
            <EmptyState
              icon={<IconFolder size={20} />}
              title="No identity documents yet"
              description="Upload a government ID to get started."
            />
          )
          : <UploadedDocsList docs={docs} documentType="identity_document" canDelete={canManage} onDeleted={reloadDocs} />
      )}

      <div style={{ height: 4 }} />

      <div>
        <h3 className="vfy-section-title">
          Proof of address <span className="vfy-section-tag">recommended</span>
        </h3>
        <p className="vfy-section-desc">
          A recent utility bill or bank statement showing your name and address. Not required to submit,
          but it helps reviewers confirm your identity faster.
        </p>
      </div>

      {canManage && (
        <DocumentUploader documentType="proof_of_address" label="proof of address" onUploaded={reloadDocs} />
      )}

      {docs && !docsLoading && addressDocs.length > 0 && (
        <UploadedDocsList docs={docs} documentType="proof_of_address" canDelete={canManage} onDeleted={reloadDocs} />
      )}
    </div>
  );
}

/* ── Submit section ──────────────────────────────────────────────────────── */

function SubmitSection({
  hasIdDoc,
  onSubmitted,
}: {
  hasIdDoc: boolean;
  onSubmitted: () => void;
}) {
  const { toast } = useToast();
  const [note, setNote] = useState('');
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (submitting || !hasIdDoc) return;
    setSubmitting(true);
    try {
      await tenantApi.submitVerification(note || undefined);
      toast("Verification request submitted. We'll notify you once the review is complete.", 'success');
      onSubmitted();
    } catch (err: unknown) {
      const msg = (err as { message?: string })?.message ?? 'Could not submit. Please try again.';
      toast(msg, 'error');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form id="vfy-submit" className="vfy-section vfy-glass" onSubmit={handleSubmit}>
      <h3 className="vfy-section-title">Submit for review</h3>
      {!hasIdDoc && (
        <div className="vfy-alert">You must upload at least one identity document above before submitting.</div>
      )}
      <label className="vfy-field-label" htmlFor="vfy-note">Optional note to reviewer</label>
      <textarea
        id="vfy-note"
        className="vfy-textarea"
        value={note}
        onChange={(e) => setNote(e.target.value)}
        placeholder="Anything the reviewer should know…"
        maxLength={1000}
        rows={3}
        disabled={submitting}
      />
      <div>
        <button type="submit" className="vfy-btn vfy-btn-primary" disabled={!hasIdDoc || submitting} aria-disabled={!hasIdDoc || submitting}>
          {submitting ? 'Submitting…' : 'Submit for verification'}
        </button>
      </div>
    </form>
  );
}

/* ── Privacy ─────────────────────────────────────────────────────────────── */

function PrivacySection() {
  return (
    <div className="vfy-section vfy-glass">
      <h3 className="vfy-section-title">How we handle your documents</h3>
      <div className="vfy-privacy-grid">
        <div className="vfy-privacy-card">
          <div className="vfy-privacy-h"><IconDoc size={16} />What we collect</div>
          <p className="vfy-privacy-p">Your government ID and, optionally, a proof of address. Nothing more than what you upload here.</p>
        </div>
        <div className="vfy-privacy-card">
          <div className="vfy-privacy-h"><IconEye size={16} />Who can see it</div>
          <p className="vfy-privacy-p">Only you and Wyncrest's verification admins, while reviewing your request. Landlords and other tenants never see your documents.</p>
        </div>
        <div className="vfy-privacy-card">
          <div className="vfy-privacy-h"><IconLock size={16} />How it's stored</div>
          <p className="vfy-privacy-p">Files are kept on private storage — never a public link — and every access is recorded in our audit log.</p>
        </div>
        <div className="vfy-privacy-card">
          <div className="vfy-privacy-h"><IconTrash size={16} />Your control</div>
          <p className="vfy-privacy-p">You can remove a document any time before your request is submitted for review.</p>
        </div>
      </div>
    </div>
  );
}

/* ── Page ─────────────────────────────────────────────────────────────────── */

export function VerificationCenter() {
  const { user } = useAuth();
  const firstName = user && 'first_name' in user ? user.first_name : '';

  const { data: status, loading: statusLoading, error: statusError, reload: reloadStatus } = useApi(
    () => tenantApi.verificationStatus(),
    [],
  );

  const [docsNonce, setDocsNonce] = useState(0);
  const reloadDocs = useCallback(() => setDocsNonce((n) => n + 1), []);

  const { data: docs, loading: docsLoading, error: docsError } = useApi(
    () => tenantApi.documents(),
    [docsNonce],
  );

  function handleSubmitted() {
    reloadStatus();
    reloadDocs();
  }

  const vs = (status?.verification_status ?? 'unverified') as VerificationStatus;
  const hasIdDoc = (docs ?? []).some((d) => d.document_type === 'identity_document');
  const canManage = canResubmit(vs);

  return (
    <div className="vfy">
      <div className="vfy-intro vfy-glass">
        <div>
          <span className="vfy-eyebrow">Account · Security</span>
          <h1 className="vfy-title">Identity <em>verification.</em></h1>
          <p className="vfy-sub">
            A quick, secure check that confirms you are who you say you are.{' '}
            <InfoHint text={help.verifWhyTenant} label="Why verification is required" />
          </p>
        </div>
        {status && !statusLoading && (
          <SemanticBadge role={statusBadgeRole(vs)}>
            {statusBadgeLabel(vs)}
          </SemanticBadge>
        )}
      </div>

      {statusLoading && <LoadingState label="Loading verification status…" />}
      {statusError && <ErrorState message={statusError.message} onRetry={reloadStatus} />}

      {status && !statusLoading && (
        <>
          <StatusHero status={status} hasIdDoc={hasIdDoc} firstName={firstName} />

          {(vs === 'unverified' || vs === 'rejected' || vs === 'needs_more_information') && <WhySection />}

          {isPending(vs) && <TimelineSection latest={status.latest_request} />}

          {vs === 'verified' && <VerifiedSection latest={status.latest_request} />}

          <DocumentsSection
            docs={docs}
            docsLoading={docsLoading}
            docsError={docsError}
            reloadDocs={reloadDocs}
            canManage={canManage}
          />

          {canManage && <SubmitSection hasIdDoc={hasIdDoc} onSubmitted={handleSubmitted} />}

          <PrivacySection />
        </>
      )}
    </div>
  );
}
