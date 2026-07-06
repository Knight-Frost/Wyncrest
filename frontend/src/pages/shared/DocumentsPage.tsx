/**
 * Documents — Homecrest secure document vault.
 *
 * Fully real: list, upload (multipart), authorized download (Bearer-streamed
 * Blob → object URL → anchor click), and delete (with confirm). All data comes
 * from tenantApi — no mock service, no SEED_DOCUMENTS, no MOCK_MODE.
 *
 * Stats and per-tab counts are derived from the live list via useMemo so they
 * can never drift from the rows below them.
 *
 * Stat cards upgraded to StatusCard (DataCardGrid). Document verified/pending
 * status uses SemanticBadge. All Lucide imports replaced with Homecrest icons.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { tenantApi } from '@/lib/endpoints';
import { fieldErrors } from '@/lib/api';
import { formatDate, humanize } from '@/lib/format';
import { useApi } from '@/hooks/useApi';
import {
  ErrorState, ForbiddenState,
} from '@/components/ui/states';
import {
  StatusCard,
  SemanticBadge,
  DataCardGrid,
} from '@/components/cards';
import {
  IconDoc,
  IconCheckCircle,
  IconClock,
  IconShield,
  IconUpload,
  IconSearch,
  IconDownload,
  IconTrash,
  IconLock,
  IconEye,
} from '@/components/ui/icons';
import type { TenantDocument, DocumentType, ApiError } from '@/lib/types';
import './documents.css';

/* ── Image icon (not in icons.tsx) ───────────────────────────────────────── */
function IconImage({ size = 20 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor"
      strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
      <circle cx="8.5" cy="8.5" r="1.5" />
      <polyline points="21 15 16 10 5 21" />
    </svg>
  );
}

/* FolderOpen icon */
function IconFolderOpen({ size = 26 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor"
      strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z" />
      <polyline points="8 13 12 17 16 13" />
      <line x1="12" y1="17" x2="12" y2="9" />
    </svg>
  );
}

/* ── domain helpers ─────────────────────────────────────────────────────── */

/** All document_type values the backend accepts. */
const DOCUMENT_TYPES: DocumentType[] = [
  'identity_document',
  'proof_of_address',
  'proof_of_income',
  'lease_document',
  'application_attachment',
  'maintenance_attachment',
  'other',
];

/** Human-readable labels for document_type values. */
const TYPE_LABELS: Record<DocumentType, string> = {
  identity_document:       'Identity Document',
  proof_of_address:        'Proof of Address',
  proof_of_income:         'Proof of Income',
  lease_document:          'Lease Document',
  application_attachment:  'Application Attachment',
  maintenance_attachment:  'Maintenance Attachment',
  other:                   'Other',
};

/** Maps document_type to a rough visual category for the filter tabs. */
type FilterKey = 'all' | 'identity' | 'financial' | 'lease' | 'maintenance' | 'other';

const TYPE_TO_FILTER: Record<DocumentType, FilterKey> = {
  identity_document:       'identity',
  proof_of_address:        'identity',
  proof_of_income:         'financial',
  lease_document:          'lease',
  application_attachment:  'other',
  maintenance_attachment:  'maintenance',
  other:                   'other',
};

const FILTER_LABELS: Record<FilterKey, string> = {
  all:         'All Documents',
  identity:    'Identity',
  financial:   'Financial',
  lease:       'Lease & Rental',
  maintenance: 'Maintenance',
  other:       'Other',
};

const FILTER_KEYS: FilterKey[] = ['all', 'identity', 'financial', 'lease', 'maintenance', 'other'];

/* ── size formatting ────────────────────────────────────────────────────── */

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/* ── mime → file kind ───────────────────────────────────────────────────── */

type FileKind = 'pdf' | 'image' | 'doc';

function mimeToKind(mime: string): FileKind {
  if (mime === 'application/pdf') return 'pdf';
  if (mime.startsWith('image/')) return 'image';
  return 'doc';
}

function mimeToLabel(mime: string): string {
  const map: Record<string, string> = {
    'application/pdf': 'PDF',
    'image/jpeg': 'JPG',
    'image/jpg': 'JPG',
    'image/png': 'PNG',
    'image/webp': 'WEBP',
  };
  return map[mime] ?? mime.split('/')[1]?.toUpperCase() ?? 'FILE';
}

type FileIconComp = React.ComponentType<{ size?: number; className?: string }>;

const FILE_ICON: Record<FileKind, FileIconComp> = {
  pdf:   IconDoc,
  image: IconImage,
  doc:   IconDoc,
};

/* ── stats ──────────────────────────────────────────────────────────────── */

interface DocumentStats {
  total: number;
  verified: number;
  pending: number;
}

function computeStats(docs: TenantDocument[]): DocumentStats {
  return {
    total:    docs.length,
    verified: docs.filter((d) => d.is_verified).length,
    pending:  docs.filter((d) => !d.is_verified).length,
  };
}

/* ── search ─────────────────────────────────────────────────────────────── */

function matchesQuery(doc: TenantDocument, query: string): boolean {
  if (!query.trim()) return true;
  const q = query.toLowerCase();
  return (
    doc.original_filename.toLowerCase().includes(q) ||
    TYPE_LABELS[doc.document_type].toLowerCase().includes(q)
  );
}

function filterDocuments(
  docs: TenantDocument[],
  tab: FilterKey,
  query: string,
): TenantDocument[] {
  const byTab =
    tab === 'all'
      ? docs
      : docs.filter((d) => TYPE_TO_FILTER[d.document_type] === tab);
  return byTab.filter((d) => matchesQuery(d, query));
}

/* ── upload form state ──────────────────────────────────────────────────── */

interface UploadForm {
  file: File | null;
  documentType: DocumentType;
  uploading: boolean;
  errors: Record<string, string>;
  success: boolean;
}

const DEFAULT_UPLOAD: UploadForm = {
  file: null,
  documentType: 'identity_document',
  uploading: false,
  errors: {},
  success: false,
};

/* ── inline-preview state ───────────────────────────────────────────────── */

/**
 * Holds the currently-previewed document. `url` is a short-lived `blob:` object
 * URL created from the Bearer-streamed file; it is null while the bytes are
 * still loading and is always revoked when the viewer closes (see closeViewer).
 */
interface ViewerState {
  doc: TenantDocument;
  kind: FileKind;
  url: string | null;
  loading: boolean;
  error: boolean;
}

/* ================================================================== page == */

export function DocumentsPage() {
  /* live data */
  const {
    data: documents,
    loading,
    error,
    reload,
  } = useApi<TenantDocument[]>(() => tenantApi.documents(), []);

  const docs = useMemo(() => documents ?? [], [documents]);

  /* filter state */
  const [tab, setTab] = useState<FilterKey>('all');
  const [query, setQuery] = useState('');

  /* upload panel */
  const [showUpload, setShowUpload] = useState(false);
  const [upload, setUpload] = useState<UploadForm>(DEFAULT_UPLOAD);
  const fileInputRef = useRef<HTMLInputElement>(null);

  /* per-row in-flight tracking */
  const [downloading, setDownloading] = useState<Set<number>>(new Set());
  const [deleting, setDeleting] = useState<Set<number>>(new Set());
  const [confirmDelete, setConfirmDelete] = useState<number | null>(null);

  /* inline preview (view without download) — see handleView below */
  const [viewer, setViewer] = useState<ViewerState | null>(null);

  /* toast */
  const [toast, setToast] = useState<string | null>(null);
  const showToast = useCallback((msg: string) => {
    setToast(msg);
    setTimeout(() => setToast(null), 3500);
  }, []);

  /* derived */
  const stats = useMemo(() => computeStats(docs), [docs]);

  const tabCounts = useMemo(() => {
    const counts: Record<FilterKey, number> = {
      all: docs.length, identity: 0, financial: 0, lease: 0, maintenance: 0, other: 0,
    };
    for (const d of docs) counts[TYPE_TO_FILTER[d.document_type]] += 1;
    return counts;
  }, [docs]);

  const visible = useMemo(() => filterDocuments(docs, tab, query), [docs, tab, query]);

  /* ── upload ──────────────────────────────────────────────────────────── */

  const handleFileChange = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
    const f = e.target.files?.[0] ?? null;
    setUpload((prev) => ({ ...prev, file: f, errors: {}, success: false }));
  }, []);

  const handleTypeChange = useCallback((e: React.ChangeEvent<HTMLSelectElement>) => {
    setUpload((prev) => ({
      ...prev,
      documentType: e.target.value as DocumentType,
      errors: {},
    }));
  }, []);

  const handleUploadSubmit = useCallback(
    async (e: React.FormEvent) => {
      e.preventDefault();
      if (!upload.file || upload.uploading) return;

      setUpload((prev) => ({ ...prev, uploading: true, errors: {}, success: false }));
      try {
        await tenantApi.uploadDocument(upload.file, upload.documentType);
        setUpload({ ...DEFAULT_UPLOAD, success: true });
        if (fileInputRef.current) fileInputRef.current.value = '';
        showToast('Document uploaded successfully.');
        void reload();
      } catch (err) {
        const apiErr = err as ApiError;
        const ferrs = fieldErrors(apiErr);
        setUpload((prev) => ({
          ...prev,
          uploading: false,
          errors: ferrs,
        }));
        if (!Object.keys(ferrs).length) {
          showToast(apiErr.message ?? 'Upload failed. Please try again.');
        }
      }
    },
    [upload, showToast, reload],
  );

  /* ── download ────────────────────────────────────────────────────────── */

  const handleDownload = useCallback(
    async (doc: TenantDocument) => {
      if (downloading.has(doc.id)) return;
      setDownloading((s) => new Set(s).add(doc.id));
      try {
        const blob = await tenantApi.downloadDocument(doc.id);
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = doc.original_filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
      } catch {
        showToast('Download failed. Please try again.');
      } finally {
        setDownloading((s) => {
          const next = new Set(s);
          next.delete(doc.id);
          return next;
        });
      }
    },
    [downloading, showToast],
  );

  /* ── inline preview (view without download) ──────────────────────────── */

  const handleView = useCallback(async (doc: TenantDocument) => {
    const kind = mimeToKind(doc.mime_type);
    // Open immediately in a loading state so the click feels responsive, then
    // stream the same authorized Blob the download uses and swap in a blob URL.
    setViewer({ doc, kind, url: null, loading: true, error: false });
    try {
      const blob = await tenantApi.downloadDocument(doc.id);
      setViewer({ doc, kind, url: URL.createObjectURL(blob), loading: false, error: false });
    } catch {
      setViewer({ doc, kind, url: null, loading: false, error: true });
    }
  }, []);

  const closeViewer = useCallback(() => {
    // Revoke the object URL so the decrypted bytes don't linger in memory.
    setViewer((v) => {
      if (v?.url) URL.revokeObjectURL(v.url);
      return null;
    });
  }, []);

  // Escape-to-close + scroll lock while the viewer is open.
  useEffect(() => {
    if (!viewer) return;
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') closeViewer(); };
    document.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = '';
    };
  }, [viewer, closeViewer]);

  /* ── delete ──────────────────────────────────────────────────────────── */

  const handleDeleteConfirm = useCallback(
    async (id: number) => {
      if (deleting.has(id)) return;
      setConfirmDelete(null);
      setDeleting((s) => new Set(s).add(id));
      try {
        await tenantApi.deleteDocument(id);
        showToast('Document deleted.');
        void reload();
      } catch {
        showToast('Delete failed. Please try again.');
      } finally {
        setDeleting((s) => {
          const next = new Set(s);
          next.delete(id);
          return next;
        });
      }
    },
    [deleting, showToast, reload],
  );

  /* ── error boundary ──────────────────────────────────────────────────── */

  if (error) {
    if ((error as ApiError).status === 403) {
      return (
        <div className="dx-page">
          <ForbiddenState
            title="Documents not available"
            message="You don't have permission to access documents."
          />
        </div>
      );
    }
    return (
      <div className="dx-page">
        <ErrorState
          title="Couldn't load documents"
          message={(error as ApiError).message}
          onRetry={() => void reload()}
        />
      </div>
    );
  }

  /* ================================================================ render == */

  return (
    <div className="dx-page">
      {/* ── header ── */}
      <header className="dx-head">
        <div className="dx-head-title">
          <p className="dx-eyebrow">Your Account</p>
          <h1 className="dx-title">Documents</h1>
          <p className="dx-sub">Upload, store and manage your important documents securely.</p>
        </div>
        <button
          className="dx-btn dx-btn-primary"
          onClick={() => { setShowUpload((v) => !v); setUpload(DEFAULT_UPLOAD); }}
          aria-expanded={showUpload}
        >
          <IconUpload size={17} />
          {showUpload ? 'Cancel upload' : 'Upload document'}
        </button>
      </header>

      {/* ── upload form ─────────────────────────────────────────────────── */}
      {showUpload && (
        <section className="dx-panel" style={{ padding: '24px' }}>
          <h2 className="dx-stat-label" style={{ marginBottom: 16 }}>Upload a new document</h2>
          <form onSubmit={(e) => void handleUploadSubmit(e)} noValidate>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
              {/* document type */}
              <div>
                <label htmlFor="doc-type" className="dx-th" style={{ display: 'block', marginBottom: 6 }}>
                  Document type
                </label>
                <select
                  id="doc-type"
                  value={upload.documentType}
                  onChange={handleTypeChange}
                  disabled={upload.uploading}
                  style={{
                    width: '100%', maxWidth: 360,
                    fontFamily: 'var(--font-sans)', fontSize: 14,
                    color: 'var(--color-ink-900)',
                    background: 'var(--color-canvas)',
                    border: `1px solid ${upload.errors.document_type ? 'var(--color-danger-500)' : 'var(--color-ink-200)'}`,
                    borderRadius: 'var(--radius-xl)', padding: '10px 14px',
                  }}
                >
                  {DOCUMENT_TYPES.map((t) => (
                    <option key={t} value={t}>{TYPE_LABELS[t]}</option>
                  ))}
                </select>
                {upload.errors.document_type && (
                  <p style={{ marginTop: 4, fontSize: 12, color: 'var(--color-danger-600)' }}>
                    {upload.errors.document_type}
                  </p>
                )}
              </div>

              {/* file input */}
              <div>
                <label htmlFor="doc-file" className="dx-th" style={{ display: 'block', marginBottom: 6 }}>
                  File (PDF, JPG, PNG, WEBP)
                </label>
                <input
                  id="doc-file"
                  ref={fileInputRef}
                  type="file"
                  accept=".pdf,.jpg,.jpeg,.png,.webp"
                  disabled={upload.uploading}
                  onChange={handleFileChange}
                  style={{
                    fontFamily: 'var(--font-sans)', fontSize: 13.5,
                    color: 'var(--color-ink-800)',
                    border: `1px solid ${upload.errors.file ? 'var(--color-danger-500)' : 'var(--color-ink-200)'}`,
                    borderRadius: 'var(--radius-xl)', padding: '9px 14px',
                    background: 'var(--color-canvas)', width: '100%', maxWidth: 400,
                  }}
                />
                {upload.errors.file && (
                  <p style={{ marginTop: 4, fontSize: 12, color: 'var(--color-danger-600)' }}>
                    {upload.errors.file}
                  </p>
                )}
              </div>

              {/* generic error */}
              {upload.errors.general && (
                <p style={{ fontSize: 13, color: 'var(--color-danger-600)' }}>{upload.errors.general}</p>
              )}

              {/* actions */}
              <div style={{ display: 'flex', gap: 12, alignItems: 'center' }}>
                <button
                  type="submit"
                  className="dx-btn dx-btn-primary"
                  disabled={!upload.file || upload.uploading}
                  style={{ opacity: (!upload.file || upload.uploading) ? 0.55 : 1 }}
                >
                  {upload.uploading ? 'Uploading…' : 'Upload'}
                </button>
                <button
                  type="button"
                  className="dx-btn dx-btn-ghost"
                  onClick={() => { setShowUpload(false); setUpload(DEFAULT_UPLOAD); }}
                >
                  Cancel
                </button>
              </div>
            </div>
          </form>
        </section>
      )}

      {/* ── stat cards (StatusCard grid, data-driven roles) ──────────────── */}
      <section aria-label="Document summary">
        <DataCardGrid cols={4}>
          <StatusCard
            label="Total documents"
            value={loading ? '—' : stats.total}
            sub="Across all categories"
            icon={<IconDoc size={18} />}
            role="neutral"
            loading={loading}
          />
          <StatusCard
            label="Verified"
            value={loading ? '—' : stats.verified}
            sub="Approved by admin"
            icon={<IconCheckCircle size={18} />}
            role={!loading && stats.verified > 0 ? 'success' : 'neutral'}
            loading={loading}
          />
          <StatusCard
            label="Pending review"
            value={loading ? '—' : stats.pending}
            sub="Awaiting verification"
            icon={<IconClock size={18} />}
            role={!loading && stats.pending > 0 ? 'warning' : 'neutral'}
            loading={loading}
          />
          <StatusCard
            label="Secure storage"
            value={loading ? '—' : docs.length > 0 ? 'Active' : '—'}
            sub="End-to-end protected"
            icon={<IconShield size={18} />}
            role={!loading && docs.length > 0 ? 'info' : 'neutral'}
            loading={loading}
          />
        </DataCardGrid>
      </section>

      {/* ── main panel ──────────────────────────────────────────────────── */}
      <section className="dx-panel">
        <div className="dx-toolbar">
          <div className="dx-tabs" role="tablist" aria-label="Document categories">
            {FILTER_KEYS.map((key) => (
              <button
                key={key}
                role="tab"
                aria-selected={tab === key}
                className={`dx-tab${tab === key ? ' active' : ''}`}
                onClick={() => setTab(key)}
              >
                {FILTER_LABELS[key]}
                {tabCounts[key] > 0 && (
                  <span className="dx-tab-count">{tabCounts[key]}</span>
                )}
              </button>
            ))}
          </div>
          <div className="dx-tools">
            <div className="dx-search">
              <IconSearch size={16} />
              <input
                type="text"
                placeholder="Search documents…"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                aria-label="Search documents"
              />
            </div>
          </div>
        </div>

        {/* table */}
        <div className="dx-table" role="table" aria-label="Documents">
          <div className="dx-thead" role="row">
            <span className="dx-th">Document</span>
            <span className="dx-th">Type</span>
            <span className="dx-th">Status</span>
            <span className="dx-th">Uploaded</span>
            <span className="dx-th right">Actions</span>
          </div>

          {loading ? (
            Array.from({ length: 5 }).map((_, i) => (
              <div className="dx-skel-row" key={i} aria-hidden="true">
                <div className="dx-doc">
                  <span className="dx-skel box" />
                  <span className="dx-skel" style={{ width: '60%' }} />
                </div>
                <span className="dx-skel" style={{ width: 80 }} />
                <span className="dx-skel" style={{ width: 90 }} />
                <span className="dx-skel" style={{ width: 90 }} />
                <span className="dx-skel" style={{ width: 60, justifySelf: 'end' }} />
              </div>
            ))
          ) : visible.length === 0 ? (
            <div className="dx-empty">
              <span className="dx-empty-ico"><IconFolderOpen size={26} /></span>
              <p className="dx-empty-title">
                {docs.length === 0
                  ? 'No documents yet'
                  : 'No documents found'}
              </p>
              <p className="dx-empty-text">
                {docs.length === 0
                  ? 'Upload your ID, proof of income, or other rental documents to keep them in one place.'
                  : query.trim()
                    ? 'No documents match your search. Try a different term or clear the filter.'
                    : 'Nothing in this category yet. Upload a document to get started.'}
              </p>
              {docs.length === 0 && (
                <button
                  className="dx-btn dx-btn-primary"
                  style={{ marginTop: 8 }}
                  onClick={() => setShowUpload(true)}
                >
                  <IconUpload size={16} /> Upload document
                </button>
              )}
            </div>
          ) : (
            visible.map((doc) => {
              const kind = mimeToKind(doc.mime_type);
              const FileGlyph = FILE_ICON[kind];
              const filterKey = TYPE_TO_FILTER[doc.document_type];
              const isDownloading = downloading.has(doc.id);
              const isDeleting = deleting.has(doc.id);

              return (
                <div className="dx-row" role="row" key={doc.id}>
                  {/* document cell */}
                  <div className="dx-doc">
                    <span className={`dx-file ${kind}`}>
                      <FileGlyph size={20} />
                      <span className="dx-file-tag">
                        <span>{mimeToLabel(doc.mime_type)}</span>
                      </span>
                    </span>
                    <div className="dx-doc-body">
                      <div className="dx-doc-name">{doc.original_filename}</div>
                      <div className="dx-doc-meta">
                        {mimeToLabel(doc.mime_type)} · {formatBytes(doc.size_bytes)}
                      </div>
                    </div>
                  </div>

                  {/* type pill — uses SemanticBadge for consistent role mapping */}
                  <div>
                    <span className={`dx-cat ${filterKey}`}>
                      {humanize(doc.document_type)}
                    </span>
                  </div>

                  {/* status — SemanticBadge: verified=success, pending=warning */}
                  <div>
                    <SemanticBadge role={doc.is_verified ? 'success' : 'warning'}>
                      {doc.is_verified ? 'Verified' : 'Pending review'}
                    </SemanticBadge>
                  </div>

                  {/* uploaded */}
                  <div>
                    <div className="dx-up-date">{formatDate(doc.created_at)}</div>
                    {doc.verified_at && (
                      <div className="dx-up-by">verified {formatDate(doc.verified_at)}</div>
                    )}
                  </div>

                  {/* actions */}
                  <div className="dx-actions">
                    {/* view (inline preview, no download) */}
                    <button
                      className="dx-iconbtn"
                      aria-label={`View ${doc.original_filename}`}
                      onClick={() => void handleView(doc)}
                      disabled={isDeleting}
                      title="View"
                    >
                      <IconEye size={17} />
                    </button>

                    {/* download */}
                    <button
                      className="dx-iconbtn"
                      aria-label={`Download ${doc.original_filename}`}
                      onClick={() => void handleDownload(doc)}
                      disabled={isDownloading || isDeleting}
                      title="Download"
                    >
                      {isDownloading
                        ? <span style={{ fontSize: 11, fontFamily: 'var(--font-mono)' }}>…</span>
                        : <IconDownload size={17} />}
                    </button>

                    {/* delete */}
                    {confirmDelete === doc.id ? (
                      <>
                        <button
                          className="dx-iconbtn"
                          aria-label="Confirm delete"
                          title="Confirm delete"
                          onClick={() => void handleDeleteConfirm(doc.id)}
                          disabled={isDeleting}
                          style={{ color: 'var(--color-danger-600)' }}
                        >
                          {isDeleting
                            ? <span style={{ fontSize: 11, fontFamily: 'var(--font-mono)' }}>…</span>
                            : <IconTrash size={17} />}
                        </button>
                        <button
                          className="dx-iconbtn"
                          aria-label="Cancel delete"
                          title="Cancel"
                          onClick={() => setConfirmDelete(null)}
                          style={{ fontSize: 14, fontWeight: 700, color: 'var(--color-ink-500)' }}
                        >
                          ✕
                        </button>
                      </>
                    ) : (
                      <button
                        className="dx-iconbtn"
                        aria-label={`Delete ${doc.original_filename}`}
                        title="Delete"
                        onClick={() => setConfirmDelete(doc.id)}
                        disabled={isDownloading || isDeleting}
                      >
                        <IconTrash size={17} />
                      </button>
                    )}
                  </div>
                </div>
              );
            })
          )}
        </div>
      </section>

      {/* ── reassurance footer ── */}
      <div className="dx-secure">
        <span className="dx-secure-ico"><IconLock size={20} /></span>
        <div className="dx-secure-body">
          <div className="dx-secure-title">Your documents are secure</div>
          <div className="dx-secure-text">
            Documents are stored with bank-level encryption and served only to you via
            your authorized session. Files are never exposed via public URLs.
          </div>
        </div>
      </div>

      {/* ── inline document viewer ── */}
      {viewer && (
        <div className="dx-viewer" role="dialog" aria-modal="true"
          aria-label={`Preview: ${viewer.doc.original_filename}`}>
          <div className="dx-viewer-scrim" onClick={closeViewer} aria-hidden="true" />
          <div className="dx-viewer-panel">
            {/* header */}
            <div className="dx-viewer-head">
              <div className="dx-viewer-head-body">
                <span className={`dx-file ${viewer.kind}`}>
                  {(() => { const G = FILE_ICON[viewer.kind]; return <G size={18} />; })()}
                </span>
                <div className="dx-viewer-titles">
                  <div className="dx-viewer-name">{viewer.doc.original_filename}</div>
                  <div className="dx-viewer-meta">
                    {mimeToLabel(viewer.doc.mime_type)} · {formatBytes(viewer.doc.size_bytes)}
                  </div>
                </div>
              </div>
              <div className="dx-viewer-head-actions">
                <button
                  className="dx-btn dx-btn-ghost"
                  onClick={() => void handleDownload(viewer.doc)}
                  title="Download"
                >
                  <IconDownload size={16} /> Download
                </button>
                <button className="dx-iconbtn" aria-label="Close preview"
                  title="Close" onClick={closeViewer}>✕</button>
              </div>
            </div>

            {/* body */}
            <div className="dx-viewer-stage">
              {viewer.loading ? (
                <div className="dx-viewer-msg">
                  <span style={{ fontFamily: 'var(--font-mono)', fontSize: 13 }}>Loading preview…</span>
                </div>
              ) : viewer.error ? (
                <div className="dx-viewer-msg">
                  <span className="dx-viewer-glyph"><IconEye size={30} /></span>
                  <div className="dx-viewer-msg-title">Couldn’t load this document</div>
                  <p className="dx-viewer-msg-text">Something went wrong fetching the file. You can try downloading it instead.</p>
                  <button className="dx-btn dx-btn-primary" onClick={() => void handleDownload(viewer.doc)}>
                    <IconDownload size={16} /> Download instead
                  </button>
                </div>
              ) : viewer.kind === 'image' && viewer.url ? (
                <img className="dx-viewer-img" src={viewer.url} alt={viewer.doc.original_filename} />
              ) : viewer.kind === 'pdf' && viewer.url ? (
                <iframe className="dx-viewer-frame" src={viewer.url}
                  title={viewer.doc.original_filename} />
              ) : (
                /* ── DECISION POINT: fallback for files that can't render inline ──
                   (e.g. .docx, .xlsx — browsers can't preview these).
                   Default below offers a clear "download instead" path. */
                <div className="dx-viewer-msg">
                  <span className="dx-viewer-glyph"><IconDoc size={30} /></span>
                  <div className="dx-viewer-msg-title">Preview isn’t available for this file type</div>
                  <p className="dx-viewer-msg-text">
                    {mimeToLabel(viewer.doc.mime_type)} files can’t be shown in the browser. Download it to view on your device.
                  </p>
                  <button className="dx-btn dx-btn-primary" onClick={() => void handleDownload(viewer.doc)}>
                    <IconDownload size={16} /> Download to view
                  </button>
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      {/* toast */}
      {toast && (
        <div role="alert" className="dx-toast">{toast}</div>
      )}
    </div>
  );
}
