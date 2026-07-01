import { useState } from 'react';
import { useApi } from '@/hooks/useApi';
import { adminApi } from '@/lib/endpoints';
import { normalizeError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { useToast } from '@/components/ui/toast';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { DetailDrawer } from '@/components/ui/Drawer';
import { Field, Textarea } from '@/components/ui/Field';
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/states';
import { Spinner } from '@/components/ui/Spinner';
import { StarRating } from '@/components/ui/StarRating';
import { RecordList, RecordCard } from '@/components/ui/RecordCard';
import {
  IconStar,
  IconAlertTriangle,
  IconChevronLeft,
  IconChevronRight,
} from '@/components/ui/icons';
import {
  SemanticBadge,
  CommandCard,
} from '@/components/cards';
import type {
  AdminReview,
  ApiError,
  ReviewStatus,
} from '@/lib/types';

/* ---- Helpers ---------------------------------------------------------------- */

type FilterKey = 'queue' | 'approved' | 'rejected' | 'hidden' | 'all';

const FILTER_TABS: { key: FilterKey; label: string }[] = [
  { key: 'queue',    label: 'Queue' },
  { key: 'approved', label: 'Approved' },
  { key: 'rejected', label: 'Rejected' },
  { key: 'hidden',   label: 'Hidden' },
  { key: 'all',      label: 'All' },
];

function filterToApiStatus(key: FilterKey): ReviewStatus | undefined {
  switch (key) {
    case 'queue':    return undefined; // pending + flagged — we filter client-side
    case 'approved': return 'approved';
    case 'rejected': return 'rejected';
    case 'hidden':   return 'hidden';
    case 'all':      return undefined;
  }
}

function reviewStatusBadgeRole(
  status: ReviewStatus,
): 'warning' | 'success' | 'danger' | 'neutral' {
  switch (status) {
    case 'pending':  return 'warning';
    case 'approved': return 'success';
    case 'rejected': return 'danger';
    case 'hidden':   return 'neutral';
    case 'flagged':  return 'warning';
    default:         return 'neutral';
  }
}

function reviewStatusLabel(status: ReviewStatus): string {
  switch (status) {
    case 'pending':  return 'Pending';
    case 'approved': return 'Approved';
    case 'rejected': return 'Rejected';
    case 'hidden':   return 'Hidden';
    case 'flagged':  return 'Flagged';
    default:         return status;
  }
}

function isQueueStatus(status: ReviewStatus): boolean {
  return status === 'pending' || status === 'flagged';
}

/* ---- Moderation action ----------------------------------------------------- */

type ModerationAction = 'approve' | 'reject' | 'hide' | 'flag';

interface ModerationDrawerProps {
  review: AdminReview;
  onClose: () => void;
  onDone: () => void;
}

function actionLabel(action: ModerationAction): string {
  switch (action) {
    case 'approve': return 'Approve';
    case 'reject':  return 'Reject';
    case 'hide':    return 'Hide';
    case 'flag':    return 'Flag';
  }
}

function actionButtonVariant(action: ModerationAction): 'primary' | 'secondary' | 'danger' {
  switch (action) {
    case 'approve': return 'primary';
    case 'reject':  return 'danger';
    case 'hide':    return 'secondary';
    case 'flag':    return 'secondary';
  }
}

function ModerationDrawer({ review, onClose, onDone }: ModerationDrawerProps) {
  const { toast } = useToast();
  const [selectedAction, setSelectedAction] = useState<ModerationAction | null>(null);
  const [reason, setReason] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const reviewerName =
    review.reviewer
      ? review.reviewer.full_name ?? `User #${review.reviewer_user_id}`
      : `User #${review.reviewer_user_id}`;

  const propertyTitle =
    review.property?.name ?? `Property #${review.property_id}`;

  async function handleModerate() {
    if (!selectedAction) return;
    setSubmitting(true);
    try {
      await adminApi.moderateReview(review.id, selectedAction, reason.trim() || undefined);
      toast(`Review ${actionLabel(selectedAction).toLowerCase()}d successfully.`, 'success');
      onDone();
    } catch (err) {
      const e = normalizeError(err) as ApiError;
      toast(e.message || 'Moderation action failed.', 'error');
    } finally {
      setSubmitting(false);
    }
  }

  const ACTIONS: ModerationAction[] = ['approve', 'reject', 'hide', 'flag'];

  return (
    <DetailDrawer
      open
      onClose={submitting ? () => {} : onClose}
      eyebrow="REVIEW"
      title={propertyTitle}
      description={`By ${reviewerName}`}
      dismissibleOnOutside={!submitting}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button
            variant={selectedAction ? actionButtonVariant(selectedAction) : 'primary'}
            onClick={handleModerate}
            disabled={!selectedAction || submitting}
            loading={submitting}
          >
            {selectedAction ? `${actionLabel(selectedAction)} review` : 'Select an action'}
          </Button>
        </>
      }
    >
      <div className="space-y-5">
        {/* Review preview */}
        <div className="rounded-xl border border-ink-200 bg-ink-50/40 px-4 py-4 space-y-2">
          <div className="flex items-center gap-3 flex-wrap">
            <StarRating value={review.rating} readOnly size={18} />
            <SemanticBadge role={reviewStatusBadgeRole(review.status as ReviewStatus)} dot={false}>
              {reviewStatusLabel(review.status as ReviewStatus)}
            </SemanticBadge>
          </div>
          {review.title && (
            <p className="font-display font-semibold text-ink-900">{review.title}</p>
          )}
          <p className="text-sm text-ink-700">{review.body}</p>
          {review.moderation_reason && (
            <p className="text-xs text-ink-500">
              <span className="font-medium">Previous decision: </span>
              {review.moderation_reason}
            </p>
          )}
          <p className="text-xs text-ink-400">
            Submitted {formatDate(review.created_at)}
          </p>
        </div>

        {/* Action picker */}
        <div>
          <p className="text-sm font-medium text-ink-700 mb-2">Choose an action</p>
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
            {ACTIONS.map((action) => (
              <button
                key={action}
                type="button"
                onClick={() => setSelectedAction(action)}
                className={[
                  'rounded-lg border px-3 py-2.5 text-sm font-medium transition-colors text-center',
                  selectedAction === action
                    ? action === 'reject'
                      ? 'border-danger-300 bg-danger-50 text-danger-700'
                      : action === 'approve'
                      ? 'border-success-300 bg-success-50 text-success-700'
                      : 'border-brand-300 bg-brand-50 text-brand-700'
                    : 'border-ink-200 bg-surface text-ink-700 hover:bg-ink-50',
                ].join(' ')}
              >
                {actionLabel(action)}
              </button>
            ))}
          </div>
        </div>

        {/* Reason field */}
        <Field label={selectedAction === 'approve' || selectedAction === 'flag' ? 'Reason (optional)' : 'Reason'}>
          {(id) => (
            <Textarea
              id={id}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder={
                selectedAction === 'approve'
                  ? 'Optionally note why this review is approved…'
                  : selectedAction === 'reject'
                  ? 'Explain why this review is rejected…'
                  : selectedAction === 'hide'
                  ? 'Explain why this review is hidden…'
                  : 'Describe why this review is flagged…'
              }
            />
          )}
        </Field>
      </div>
    </DetailDrawer>
  );
}

/* ---- Main page -------------------------------------------------------------- */

export function ReviewModeration() {
  const [filter, setFilter] = useState<FilterKey>('queue');
  const [page, setPage] = useState(1);
  const [moderating, setModerating] = useState<AdminReview | null>(null);

  const apiStatus = filter === 'queue' ? undefined : filterToApiStatus(filter);
  const apiParams = { status: apiStatus, page };

  const { data, loading, error, reload } = useApi(
    () => adminApi.adminReviews(apiParams),
    [filter, page],
  );

  const items = (data?.data ?? []).filter((r) => {
    if (filter === 'queue') return isQueueStatus(r.status as ReviewStatus);
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
        title="Review Moderation"
        description="Moderate tenant reviews on rental properties."
      />

      {/* Queue callout */}
      {!loading && !error && filter === 'queue' && queueCount !== undefined && queueCount > 0 && (
        <CommandCard
          role="warning"
          label="Reviews awaiting moderation"
          value={String(queueCount)}
          sub={
            queueCount === 1
              ? 'One review needs your decision'
              : `${queueCount} reviews need your decision`
          }
          icon={<IconAlertTriangle size={20} />}
        />
      )}

      {/* Filter tabs */}
      <div className="mb-5 flex gap-0 border-b border-ink-200" role="tablist" aria-label="Filter reviews">
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
        <LoadingState label="Loading reviews…" />
      ) : error ? (
        <ErrorState message={error.message} onRetry={reload} />
      ) : items.length === 0 ? (
        <EmptyState
          icon={<IconStar />}
          title="Nothing here"
          description={
            filter === 'queue'
              ? 'No reviews awaiting moderation.'
              : 'No reviews match this filter.'
          }
        />
      ) : (
        <>
          <RecordList>
            {items.map((review: AdminReview) => {
              const reviewerName =
                review.reviewer?.full_name ?? `User #${review.reviewer_user_id}`;
              const propertyTitle =
                review.property?.name ?? `Property #${review.property_id}`;

              return (
                <RecordCard
                  key={review.id}
                  title={propertyTitle}
                  subtitle={reviewerName}
                  indicator={<StarRating value={review.rating} readOnly size={15} />}
                  related={
                    review.body ? (
                      <p className="text-xs text-ink-500 line-clamp-2">{review.body}</p>
                    ) : undefined
                  }
                  status={
                    <SemanticBadge
                      role={reviewStatusBadgeRole(review.status as ReviewStatus)}
                      dot={false}
                    >
                      {reviewStatusLabel(review.status as ReviewStatus)}
                    </SemanticBadge>
                  }
                  timestamp={
                    review.moderator
                      ? `By ${review.moderator.name}`
                      : formatDate(review.created_at)
                  }
                  primaryAction={
                    <Button
                      variant="secondary"
                      size="sm"
                      onClick={() => setModerating(review)}
                    >
                      Moderate
                    </Button>
                  }
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
                `${total} ${total === 1 ? 'review' : 'reviews'} total`
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

      {/* Moderation drawer */}
      {moderating && (
        <ModerationDrawer
          review={moderating}
          onClose={() => setModerating(null)}
          onDone={() => {
            setModerating(null);
            reload();
          }}
        />
      )}
    </div>
  );
}
