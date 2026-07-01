import { useState } from 'react';
import { useApi } from '@/hooks/useApi';
import { adminApi } from '@/lib/endpoints';
import { normalizeError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { useToast } from '@/components/ui/toast';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { Avatar } from '@/components/ui/Avatar';
import { Drawer, DrawerHeader, DrawerBody, DrawerFooter } from '@/components/ui/Drawer';
import { Field, Textarea } from '@/components/ui/Field';
import { RecordList, RecordCard } from '@/components/ui/RecordCard';
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/states';
import { Spinner } from '@/components/ui/Spinner';
import {
  IconCircleCheck,
  IconAlertTriangle,
  IconCheck,
  IconX,
  IconInfo,
  IconFileText,
  IconDownload,
  IconChevronLeft,
  IconChevronRight,
} from '@/components/ui/icons';
import {
  SemanticBadge,
  NexusCard,
  CommandCard,
} from '@/components/cards';
import type {
  AdminVerificationRequest,
  AdminVerificationDetail,
  ApiError,
  VerificationRequestStatus,
} from '@/lib/types';

/* ---- Helpers ---------------------------------------------------------------- */

type FilterKey = 'queue' | 'approved' | 'rejected' | 'needs_info' | 'all';

const FILTER_TABS: { key: FilterKey; label: string }[] = [
  { key: 'queue',     label: 'Queue' },
  { key: 'approved',  label: 'Approved' },
  { key: 'rejected',  label: 'Rejected' },
  { key: 'needs_info', label: 'Needs Info' },
  { key: 'all',       label: 'All' },
];

function filterToApiStatus(key: FilterKey): VerificationRequestStatus | undefined {
  switch (key) {
    case 'queue':     return undefined; // fetched as pending + under_review via no filter, we filter client-side
    case 'approved':  return 'approved';
    case 'rejected':  return 'rejected';
    case 'needs_info': return 'needs_more_information';
    case 'all':       return undefined;
  }
}

function verificationStatusBadgeRole(
  status: VerificationRequestStatus,
): 'warning' | 'info' | 'success' | 'danger' | 'neutral' {
  switch (status) {
    case 'pending':                 return 'warning';
    case 'under_review':            return 'info';
    case 'approved':                return 'success';
    case 'rejected':                return 'danger';
    case 'needs_more_information':  return 'warning';
    default:                        return 'neutral';
  }
}

function verificationStatusLabel(status: VerificationRequestStatus): string {
  switch (status) {
    case 'pending':                 return 'Pending';
    case 'under_review':            return 'Under review';
    case 'approved':                return 'Approved';
    case 'rejected':                return 'Rejected';
    case 'needs_more_information':  return 'Needs info';
    default:                        return status;
  }
}

function documentTypeLabel(type: string): string {
  switch (type) {
    case 'identity_document':       return 'Identity Document';
    case 'proof_of_income':         return 'Proof of Income';
    case 'lease_document':          return 'Lease Document';
    case 'application_attachment':  return 'Application Attachment';
    case 'maintenance_attachment':  return 'Maintenance Attachment';
    case 'other':                   return 'Other';
    default:                        return type;
  }
}

function formatBytes(bytes: number): string {
  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(1)} KB`;
  }
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

function isQueueStatus(status: VerificationRequestStatus): boolean {
  return status === 'pending' || status === 'under_review';
}

/* ---- Avatar (photo when available, else initials) ------------------------- */

function AvatarCircle({ name, src }: { name: string; src?: string | null }) {
  return (
    <Avatar
      name={name}
      src={src}
      className="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-brand-100 text-sm font-semibold text-brand-700 select-none"
    />
  );
}

/* ---- Inline action kinds --------------------------------------------------- */

type ActionKind = 'approve' | 'reject' | 'request_info';

/* ---- Detail drawer with inline action handling ----------------------------- */

function VerificationDetailDrawer({
  id,
  onClose,
  onDone,
}: {
  id: string;
  onClose: () => void;
  onDone: () => void;
}) {
  const { data, loading, error, reload } = useApi<AdminVerificationDetail>(
    () => adminApi.verification(id),
    [id],
  );
  const { toast } = useToast();
  const [downloadingId, setDownloadingId] = useState<number | null>(null);

  // Inline action state (replaces the separate ActionModal)
  const [activeKind, setActiveKind] = useState<ActionKind | null>(null);
  const [actionText, setActionText] = useState('');
  const [actionError, setActionError] = useState<string | undefined>();
  const [actionSubmitting, setActionSubmitting] = useState(false);

  const vr = data;
  const user = vr?.user;
  const userName = user?.full_name ?? `User #${vr?.user_id ?? id}`;

  async function downloadDoc(docId: number, filename: string) {
    setDownloadingId(docId);
    try {
      await adminApi.downloadDocument(docId, filename);
    } catch (err) {
      const e = normalizeError(err) as ApiError;
      toast(e.message || 'Could not download this document.', 'error');
    } finally {
      setDownloadingId(null);
    }
  }

  function selectAction(kind: ActionKind) {
    setActiveKind(kind);
    setActionText('');
    setActionError(undefined);
  }

  function cancelAction() {
    setActiveKind(null);
    setActionText('');
    setActionError(undefined);
  }

  async function submitAction() {
    if (!activeKind) return;
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
      onDone();
    } catch (err) {
      const e = normalizeError(err) as ApiError;
      toast(e.message || 'Action failed. Please try again.', 'error');
    } finally {
      setActionSubmitting(false);
    }
  }

  const actionable = vr ? isQueueStatus(vr.status) : false;
  const isApproveSelected = activeKind === 'approve';
  const isRejectSelected = activeKind === 'reject';
  const required = activeKind !== null && !isApproveSelected;

  const actionFieldLabel = isApproveSelected
    ? 'Reason (optional)'
    : isRejectSelected
    ? 'Reason for rejection'
    : 'Note to applicant';

  const actionFieldPlaceholder = isApproveSelected
    ? 'Optionally note why this was approved…'
    : isRejectSelected
    ? 'Explain what was wrong with the submission…'
    : 'Describe what additional documents or information are needed…';

  return (
    <>
      <DrawerHeader
        title={loading ? 'Verification Request' : userName}
        description={
          vr
            ? `Submitted ${vr.submitted_at ? formatDate(vr.submitted_at) : formatDate(vr.created_at)}`
            : undefined
        }
        onClose={onClose}
      />
      <DrawerBody>
        {loading ? (
          <LoadingState label="Loading request…" />
        ) : error ? (
          <ErrorState message={error.message} onRetry={reload} />
        ) : vr ? (
          <div className="space-y-6">
            {/* Applicant info */}
            <section>
              <h3 className="eyebrow text-ink-400 mb-3">Applicant</h3>
              <NexusCard role="neutral" className="p-4 space-y-2">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-display text-base font-semibold text-ink-900">
                    {userName}
                  </span>
                  <SemanticBadge role={user?.user_type === 'landlord' ? 'info' : 'neutral'} dot={false}>
                    {user?.user_type === 'landlord' ? 'Landlord' : 'Tenant'}
                  </SemanticBadge>
                </div>
                {user?.email && (
                  <p className="text-sm text-ink-600">{user.email}</p>
                )}
                {user?.phone && (
                  <p className="text-sm text-ink-500">{user.phone}</p>
                )}
              </NexusCard>
            </section>

            {/* Status */}
            <section>
              <h3 className="eyebrow text-ink-400 mb-3">Status</h3>
              <div className="flex items-center gap-3">
                <SemanticBadge
                  role={verificationStatusBadgeRole(vr.status)}
                  dot={false}
                >
                  {verificationStatusLabel(vr.status)}
                </SemanticBadge>
                {vr.reviewed_at && (
                  <span className="text-xs text-ink-500">
                    Reviewed {formatDate(vr.reviewed_at)}
                    {vr.reviewer ? ` by ${vr.reviewer.name}` : ''}
                  </span>
                )}
              </div>
              {vr.decision_reason && (
                <p className="mt-2 text-sm text-ink-600 rounded-lg border border-ink-200 bg-ink-50/50 px-3 py-2">
                  <span className="font-medium text-ink-700">Decision note: </span>
                  {vr.decision_reason}
                </p>
              )}
            </section>

            {/* Applicant note */}
            {vr.note && (
              <section>
                <h3 className="eyebrow text-ink-400 mb-3">Note from applicant</h3>
                <p className="text-sm text-ink-700 rounded-lg border border-ink-200 bg-ink-50/50 px-3 py-2">
                  {vr.note}
                </p>
              </section>
            )}

            {/* Documents — streamed via the admin-gated, audited download route */}
            <section>
              <h3 className="eyebrow text-ink-400 mb-3">Documents</h3>
              {!vr.documents || vr.documents.length === 0 ? (
                <p className="text-sm text-ink-500 rounded-lg border border-dashed border-ink-200 px-4 py-4 text-center">
                  No documents attached to this request.
                </p>
              ) : (
                <ul className="divide-y divide-ink-200 rounded-xl border border-ink-200">
                  {vr.documents.map((doc) => (
                    <li key={doc.id} className="flex items-center gap-3 px-4 py-3">
                      <IconFileText size={18} className="shrink-0 text-ink-400" />
                      <div className="min-w-0 flex-1">
                        <p className="text-sm font-medium text-ink-900 truncate">
                          {doc.original_filename}
                        </p>
                        <p className="text-xs text-ink-500 mt-0.5">
                          {documentTypeLabel(doc.document_type)}
                          {' · '}
                          {doc.mime_type}
                          {' · '}
                          {formatBytes(doc.size_bytes)}
                        </p>
                      </div>
                      <Button
                        variant="secondary"
                        size="sm"
                        leftIcon={
                          downloadingId === doc.id ? (
                            <Spinner size={14} />
                          ) : (
                            <IconDownload size={14} />
                          )
                        }
                        disabled={downloadingId !== null}
                        onClick={() => downloadDoc(doc.id, doc.original_filename)}
                      >
                        Download
                      </Button>
                    </li>
                  ))}
                </ul>
              )}
            </section>

            {/* Inline action section — visible when an action is selected */}
            {actionable && activeKind !== null && (
              <section className="border-t border-ink-200 pt-6">
                <h3 className="eyebrow text-ink-400 mb-3">
                  {isApproveSelected
                    ? 'Approve verification'
                    : isRejectSelected
                    ? 'Reject verification'
                    : 'Request more information'}
                </h3>
                <Field
                  label={actionFieldLabel}
                  required={required}
                  error={actionError}
                >
                  {(fid, invalid) => (
                    <Textarea
                      id={fid}
                      invalid={invalid}
                      value={actionText}
                      onChange={(e) => {
                        setActionText(e.target.value);
                        if (actionError) setActionError(undefined);
                      }}
                      placeholder={actionFieldPlaceholder}
                      autoFocus
                    />
                  )}
                </Field>
              </section>
            )}
          </div>
        ) : null}
      </DrawerBody>

      {/* Footer: action picker when request is actionable */}
      {vr && actionable && (
        <DrawerFooter>
          {activeKind === null ? (
            /* Action picker mode — choose which decision to make */
            <>
              <div className="flex items-center gap-2 flex-wrap">
                <Button
                  variant="secondary"
                  leftIcon={<IconInfo size={14} />}
                  onClick={() => selectAction('request_info')}
                >
                  Request Info
                </Button>
              </div>
              <div className="flex items-center gap-2">
                <Button
                  variant="danger"
                  leftIcon={<IconX size={14} />}
                  onClick={() => selectAction('reject')}
                >
                  Reject
                </Button>
                <Button
                  leftIcon={<IconCheck size={14} />}
                  onClick={() => selectAction('approve')}
                >
                  Approve
                </Button>
              </div>
            </>
          ) : (
            /* Submit mode — confirm the selected action */
            <>
              <Button
                variant="secondary"
                onClick={cancelAction}
                disabled={actionSubmitting}
              >
                Back
              </Button>
              <Button
                variant={isRejectSelected ? 'danger' : 'primary'}
                onClick={submitAction}
                loading={actionSubmitting}
              >
                {isApproveSelected
                  ? 'Approve'
                  : isRejectSelected
                  ? 'Reject'
                  : 'Send request'}
              </Button>
            </>
          )}
        </DrawerFooter>
      )}
    </>
  );
}

/* ---- Main page -------------------------------------------------------------- */

export function VerificationModeration() {
  const [filter, setFilter] = useState<FilterKey>('queue');
  const [page, setPage] = useState(1);
  const [selectedId, setSelectedId] = useState<string | null>(null);

  // For 'queue' filter we pull the full pending list; for others we send the
  // matching status param. The API returns paginated results either way.
  const apiStatus = filter === 'queue' ? undefined : filterToApiStatus(filter);
  const apiParams = { status: apiStatus, page };

  const { data, loading, error, reload } = useApi(
    () => adminApi.verifications(apiParams),
    [filter, page],
  );

  const items = (data?.data ?? []).filter((v) => {
    if (filter === 'queue') return isQueueStatus(v.status);
    return true;
  });

  const currentPage = data?.current_page ?? 1;
  const lastPage = data?.last_page ?? 1;
  const total = data?.total ?? 0;

  const queueCount = filter === 'queue' ? items.length : undefined;

  function changeFilter(key: FilterKey) {
    setFilter(key);
    setPage(1);
  }

  return (
    <div className="animate-rise space-y-8">
      <PageHeader
        eyebrow="Platform"
        title="Identity Verification"
        description="Review and action verification requests from tenants and landlords."
      />

      {/* Queue size callout */}
      {!loading && !error && filter === 'queue' && queueCount !== undefined && queueCount > 0 && (
        <CommandCard
          role="warning"
          label="Requests awaiting review"
          value={String(queueCount)}
          sub={
            queueCount === 1
              ? 'One request needs your decision'
              : `${queueCount} requests need your decision`
          }
          icon={<IconAlertTriangle size={20} />}
        />
      )}

      {/* Filter tabs */}
      <div className="mb-5 flex gap-0 border-b border-ink-200" role="tablist" aria-label="Filter verification requests">
        {FILTER_TABS.map((tab) => (
          <button
            key={tab.key}
            type="button"
            role="tab"
            onClick={() => changeFilter(tab.key)}
            aria-selected={filter === tab.key}
            className={[
              'inline-flex items-center gap-2 mr-6 py-2.5 px-1 text-sm font-medium border-b-2 -mb-px transition-colors',
              filter === tab.key
                ? 'border-brand-600 text-brand-700'
                : 'border-transparent text-ink-500 hover:text-ink-800',
            ].join(' ')}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {/* Content */}
      {loading ? (
        <LoadingState label="Loading verification requests…" />
      ) : error ? (
        <ErrorState message={error.message} onRetry={reload} />
      ) : items.length === 0 ? (
        <EmptyState
          icon={<IconCircleCheck />}
          title="Nothing here"
          description={
            filter === 'queue'
              ? 'No verification requests awaiting review.'
              : 'No requests match this filter.'
          }
        />
      ) : (
        <>
          <RecordList>
            {items.map((vr: AdminVerificationRequest) => {
              const userName = vr.user?.full_name ?? `User #${vr.user_id}`;
              const isLandlord = vr.user?.user_type === 'landlord';
              return (
                <RecordCard
                  key={vr.id}
                  leading={<AvatarCircle name={userName} src={vr.user?.avatar_url} />}
                  title={userName}
                  subtitle={
                    vr.user?.email ? <span>{vr.user.email}</span> : undefined
                  }
                  related={
                    <SemanticBadge
                      role={isLandlord ? 'info' : 'neutral'}
                      dot={false}
                    >
                      {isLandlord ? 'Landlord' : 'Tenant'}
                    </SemanticBadge>
                  }
                  status={
                    <SemanticBadge
                      role={verificationStatusBadgeRole(vr.status)}
                      dot={false}
                    >
                      {verificationStatusLabel(vr.status)}
                    </SemanticBadge>
                  }
                  timestamp={
                    vr.submitted_at
                      ? formatDate(vr.submitted_at)
                      : formatDate(vr.created_at)
                  }
                  primaryAction={
                    <Button
                      variant="secondary"
                      size="sm"
                      onClick={() => setSelectedId(vr.id)}
                    >
                      Review
                    </Button>
                  }
                  onClick={() => setSelectedId(vr.id)}
                />
              );
            })}
          </RecordList>

          {/* Pagination */}
          <div className="flex items-center justify-between gap-4">
            <p className="text-xs text-ink-500">
              {loading ? (
                <span className="inline-flex items-center gap-2">
                  <Spinner size={14} /> Loading…
                </span>
              ) : (
                `${total} ${total === 1 ? 'request' : 'requests'} total`
              )}
            </p>
            {lastPage > 1 && (
              <div className="flex items-center gap-4">
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={currentPage <= 1 || loading}
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  leftIcon={<IconChevronLeft className="h-4 w-4" />}
                >
                  Previous
                </Button>
                <span className="text-sm text-ink-500">
                  Page {currentPage} of {lastPage}
                </span>
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={currentPage >= lastPage || loading}
                  onClick={() => setPage((p) => p + 1)}
                  leftIcon={<IconChevronRight className="h-4 w-4" />}
                >
                  Next
                </Button>
              </div>
            )}
          </div>
        </>
      )}

      {/* Detail Drawer — action controls are now inline within the drawer */}
      <Drawer
        open={selectedId !== null}
        onOpenChange={(open) => { if (!open) setSelectedId(null); }}
        widthClass="sm:max-w-[700px]"
      >
        {selectedId && (
          <VerificationDetailDrawer
            id={selectedId}
            onClose={() => setSelectedId(null)}
            onDone={() => {
              setSelectedId(null);
              reload();
            }}
          />
        )}
      </Drawer>
    </div>
  );
}
