/**
 * LandlordReviews — read approved reviews on this landlord's properties + respond.
 *
 * Truth contract:
 * - GET /landlord/reviews → { reviews: Review[], summary: ReviewSummary[] }
 *   Only APPROVED reviews; reviewer and property eager-loaded; newest first.
 * - POST /landlord/reviews/{review}/respond → { response: string } → updated Review
 *   Landlord can only respond — never edit or delete tenant reviews.
 * - No fake data, no dead buttons.
 */
import { useState } from 'react';
import { useApi } from '@/hooks/useApi';
import { landlordApi } from '@/lib/endpoints';
import type { ApiError, Review, ReviewSummary, ReviewWithReviewer } from '@/lib/types';
import { formatDate, timeAgo } from '@/lib/format';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Avatar } from '@/components/ui/Avatar';
import { Field, Textarea } from '@/components/ui/Field';
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/states';
import { StarRating } from '@/components/ui/StarRating';
import { SemanticBadge } from '@/components/cards';
import {
  IconBuilding,
  IconMessage,
  IconStar,
} from '@/components/ui/icons';
import { useToast } from '@/components/ui/toast';

/* ── Rating summary card ─────────────────────────────────────────────────── */

interface RatingSummaryCardProps {
  summary: ReviewSummary;
  propertyName?: string;
}

function RatingSummaryCard({ summary, propertyName }: RatingSummaryCardProps) {
  return (
    <div className="lr-summary-card">
      <div className="lr-summary-header">
        <IconBuilding size={16} className="lr-summary-icon" />
        <span className="lr-summary-name">{propertyName ?? `Property #${summary.property_id}`}</span>
      </div>
      <div className="lr-summary-rating">
        <StarRating value={summary.average_rating} readOnly size={18} />
        <span className="lr-summary-avg">{summary.average_rating.toFixed(1)}</span>
        <span className="lr-summary-count">({summary.review_count} review{summary.review_count !== 1 ? 's' : ''})</span>
      </div>
    </div>
  );
}

/* ── Respond form ────────────────────────────────────────────────────────── */

interface RespondFormProps {
  review: Review;
  onResponded: (updated: Review) => void;
}

function RespondForm({ review, onResponded }: RespondFormProps) {
  const { toast } = useToast();
  const [open, setOpen] = useState(false);
  const [text, setText] = useState(review.landlord_response ?? '');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const hasResponse = Boolean(review.landlord_response);

  if (!open) {
    return (
      <button
        className="lr-respond-btn"
        onClick={() => setOpen(true)}
        aria-label={hasResponse ? 'Edit your response' : 'Write a response'}
      >
        <IconMessage size={14} />
        {hasResponse ? 'Edit response' : 'Respond'}
      </button>
    );
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const trimmed = text.trim();
    if (!trimmed) {
      setError('Response cannot be empty.');
      return;
    }
    setSaving(true);
    setError('');
    try {
      const updated = await landlordApi.respondToReview(review.id, trimmed);
      toast('Response saved', 'success');
      setOpen(false);
      onResponded(updated);
    } catch (err) {
      const msg = (err as ApiError).message ?? 'Could not save response. Please try again.';
      toast(msg, 'error');
      setError(msg);
    } finally {
      setSaving(false);
    }
  }

  return (
    <form className="lr-respond-form" onSubmit={handleSubmit}>
      <Field
        label="Your response"
        error={error}
        hint="Responses are visible to all prospective tenants. Be professional and constructive."
      >
        {(fid, invalid) => (
          <Textarea
            id={fid}
            invalid={invalid}
            rows={4}
            value={text}
            onChange={(e) => setText(e.target.value)}
            placeholder="Thank you for your feedback…"
            disabled={saving}
            maxLength={2000}
          />
        )}
      </Field>
      <div className="lr-respond-form-actions">
        <Button variant="secondary" size="sm" onClick={() => setOpen(false)} disabled={saving}>
          Cancel
        </Button>
        <Button size="sm" type="submit" loading={saving}>
          {saving ? 'Saving…' : 'Save response'}
        </Button>
      </div>
    </form>
  );
}

/* ── Review card ─────────────────────────────────────────────────────────── */

interface ReviewCardProps {
  review: ReviewWithReviewer;
  onResponded: (updated: Review) => void;
}

function ReviewCard({ review, onResponded }: ReviewCardProps) {
  const reviewerName = review.reviewer
    ? review.reviewer.full_name ?? `${review.reviewer.first_name} ${review.reviewer.last_name}`
    : 'Tenant';

  return (
    <div className="lr-review-card">
      {/* Header */}
      <div className="lr-review-header">
        <Avatar name={reviewerName} src={review.reviewer?.avatar_url} className="lr-reviewer-avatar" />
        <div className="lr-reviewer-info">
          <span className="lr-reviewer-name">{reviewerName}</span>
          {review.property && (
            <span className="lr-reviewer-property">
              <IconBuilding size={12} /> {review.property.name}
            </span>
          )}
        </div>
        <div className="lr-review-meta">
          <StarRating value={review.rating} readOnly size={15} />
          <span className="lr-review-date" title={review.created_at}>{timeAgo(review.created_at)}</span>
        </div>
      </div>

      {/* Body */}
      {review.title && <p className="lr-review-title">{review.title}</p>}
      <p className="lr-review-body">{review.body}</p>

      {/* Existing response */}
      {review.landlord_response && (
        <div className="lr-existing-response">
          <div className="lr-response-label">
            <SemanticBadge role="info" size="sm">Your response</SemanticBadge>
            {review.responded_at && (
              <span className="lr-response-date">{formatDate(review.responded_at)}</span>
            )}
          </div>
          <p className="lr-response-body">{review.landlord_response}</p>
        </div>
      )}

      {/* Respond form */}
      <div className="lr-respond-section">
        <RespondForm review={review} onResponded={onResponded} />
      </div>
    </div>
  );
}

/* ── Page ─────────────────────────────────────────────────────────────────── */

export function LandlordReviews() {
  const { data, loading, error, reload } = useApi(
    () => landlordApi.reviews(),
    [],
  );

  // Local state to apply optimistic response updates without a full refetch
  const [localReviews, setLocalReviews] = useState<ReviewWithReviewer[] | null>(null);

  const reviews: ReviewWithReviewer[] = (localReviews ?? data?.reviews ?? []) as ReviewWithReviewer[];
  const summary: ReviewSummary[] = data?.summary ?? [];

  // Build property name lookup from review data
  const propertyNames = new Map<number, string>();
  for (const r of reviews) {
    if (r.property?.id && r.property?.name) {
      propertyNames.set(r.property.id, r.property.name);
    }
  }

  function handleResponded(updated: Review) {
    setLocalReviews(
      (localReviews ?? data?.reviews ?? []).map((r) =>
        r.id === updated.id ? { ...r, ...updated } : r,
      ) as ReviewWithReviewer[],
    );
  }

  // Overall platform avg
  const overallAvg = summary.length
    ? summary.reduce((sum, s) => sum + s.average_rating * s.review_count, 0) /
      summary.reduce((sum, s) => sum + s.review_count, 0)
    : null;

  const totalCount = summary.reduce((sum, s) => sum + s.review_count, 0);

  return (
    <div className="animate-rise space-y-10">
      <style>{LR_CSS}</style>

      <div className="lr-hero">
        <p className="lr-eyebrow">Reputation</p>
        <h1 className="lr-title">Reviews</h1>
        <p className="lr-sub">Approved tenant reviews on your properties. Respond to build trust with prospective renters.</p>
      </div>

      {loading && <LoadingState label="Loading reviews…" />}
      {error && <ErrorState message={error.message} onRetry={reload} />}

      {!loading && !error && (
        <>
          {/* Summary cards */}
          {summary.length > 0 && (
            <div>
              <p className="lr-section-eyebrow">Rating Summary</p>
              <div className="lr-summary-grid">
                {/* Overall */}
                {overallAvg !== null && (
                  <div className="lr-overall-card">
                    <span className="lr-overall-label">Overall</span>
                    <div className="lr-overall-rating">
                      <StarRating value={overallAvg} readOnly size={22} />
                      <span className="lr-overall-avg">{overallAvg.toFixed(1)}</span>
                    </div>
                    <span className="lr-overall-count">{totalCount} approved review{totalCount !== 1 ? 's' : ''}</span>
                  </div>
                )}
                {summary.map((s) => (
                  <RatingSummaryCard
                    key={s.property_id}
                    summary={s}
                    propertyName={propertyNames.get(s.property_id)}
                  />
                ))}
              </div>
            </div>
          )}

          {/* Reviews list */}
          {reviews.length === 0 ? (
            <EmptyState
              icon={<IconStar />}
              title="No reviews yet"
              description="Once tenants leave approved reviews on your properties, they'll appear here. You can respond to each review."
            />
          ) : (
            <Card>
              <CardHeader
                title="Approved Reviews"
                description={`${reviews.length} review${reviews.length !== 1 ? 's' : ''} from tenants on your properties.`}
              />
              <CardBody className="p-0">
                <div className="lr-reviews-list">
                  {reviews.map((review) => (
                    <ReviewCard
                      key={review.id}
                      review={review}
                      onResponded={handleResponded}
                    />
                  ))}
                </div>
              </CardBody>
            </Card>
          )}
        </>
      )}
    </div>
  );
}

/* ── Styles ──────────────────────────────────────────────────────────────── */

const LR_CSS = `
.lr-hero {
  display: flex;
  flex-direction: column;
  gap: 0;
  background: var(--color-surface, #fff);
  border: 1px solid var(--color-ink-200, #E5E7EB);
  border-radius: var(--radius-2xl, 20px);
  box-shadow: var(--shadow-sm, 0 1px 2px rgba(0,0,0,.05));
  padding: clamp(1.4rem, 3vw, 2.1rem);
}
.lr-eyebrow {
  font-family: var(--font-mono, 'IBM Plex Mono', monospace);
  font-size: 0.6rem;
  letter-spacing: .2em;
  text-transform: uppercase;
  color: var(--color-brand-700, #4338CA);
  display: inline-flex;
  align-items: center;
  gap: 0.6em;
}
.lr-eyebrow::before { content: ''; width: 24px; height: 1px; background: var(--color-brand-700, #4338CA); }
.lr-title {
  font-family: 'Fraunces', Georgia, serif;
  font-size: clamp(2.1rem, 4.4vw, 3rem);
  font-weight: 400;
  color: var(--color-ink-950, #0C0A09);
  line-height: 1.02;
  margin: 0.6rem 0 0.4rem;
}
.lr-sub { font-size: 0.9375rem; color: var(--color-ink-500, #6B7280); margin: 0; max-width: 64ch; }

.lr-section-eyebrow {
  font-size: 0.6875rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: var(--color-ink-400, #9CA3AF);
  margin-bottom: 12px;
  font-family: 'IBM Plex Mono', monospace;
}

/* Summary grid */
.lr-summary-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 12px;
  align-items: stretch;
}

.lr-overall-card {
  border-radius: 14px;
  border: 1.5px solid var(--color-brand-200, #A7D8D4);
  background: var(--color-brand-50, #F0FFFE);
  padding: 18px 20px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.lr-overall-label {
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--color-brand-700, #0E7490);
  font-family: 'IBM Plex Mono', monospace;
}
.lr-overall-rating { display: flex; align-items: center; gap: 8px; }
.lr-overall-avg {
  font-family: 'Fraunces', Georgia, serif;
  font-size: 1.75rem;
  font-weight: 700;
  color: var(--color-ink-900, #111827);
  line-height: 1;
}
.lr-overall-count { font-size: 0.8125rem; color: var(--color-ink-500, #6B7280); white-space: nowrap; }

.lr-summary-card {
  border-radius: 14px;
  border: 1px solid var(--color-ink-200, #E5E7EB);
  background: var(--color-surface, #FFFFFF);
  padding: 16px 18px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.lr-summary-header { display: flex; align-items: center; gap: 8px; }
.lr-summary-icon { color: var(--color-ink-400, #9CA3AF); flex-shrink: 0; }
.lr-summary-name {
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--color-ink-800, #1F2937);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.lr-summary-rating { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.lr-summary-avg { font-size: 0.9375rem; font-weight: 700; color: var(--color-ink-900, #111827); }
.lr-summary-count { font-size: 0.8125rem; color: var(--color-ink-400, #9CA3AF); white-space: nowrap; }

/* Reviews list */
.lr-reviews-list { display: flex; flex-direction: column; }

.lr-review-card {
  padding: 20px 24px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  border-bottom: 1px solid var(--color-ink-100, #F3F4F6);
}
.lr-review-card:last-child { border-bottom: none; }

.lr-review-header {
  display: flex;
  align-items: flex-start;
  gap: 12px;
}
.lr-reviewer-avatar {
  flex: 0 0 auto;
  width: 38px; height: 38px;
  border-radius: 50%;
  background: var(--color-ink-200, #E5E7EB);
  display: flex; align-items: center; justify-content: center;
  font-size: 0.9375rem;
  font-weight: 700;
  color: var(--color-ink-600, #4B5563);
}
.lr-reviewer-info { flex: 1; display: flex; flex-direction: column; gap: 2px; }
.lr-reviewer-name { font-size: 0.9375rem; font-weight: 600; color: var(--color-ink-900, #111827); }
.lr-reviewer-property {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 0.8125rem;
  color: var(--color-ink-500, #6B7280);
}
.lr-review-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 3px; }
.lr-review-date { font-size: 0.75rem; color: var(--color-ink-400, #9CA3AF); white-space: nowrap; }

.lr-review-title {
  font-family: 'Fraunces', Georgia, serif;
  font-size: 1rem;
  font-weight: 600;
  color: var(--color-ink-900, #111827);
}
.lr-review-body { font-size: 0.9rem; color: var(--color-ink-700, #374151); line-height: 1.6; }

/* Existing response */
.lr-existing-response {
  border-left: 3px solid var(--color-brand-400, #22D3EE);
  background: var(--color-brand-50, #F0F9FF);
  border-radius: 4px 10px 10px 4px;
  padding: 12px 16px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.lr-response-label { display: flex; align-items: center; gap: 8px; }
.lr-response-date { font-size: 0.75rem; color: var(--color-ink-400, #9CA3AF); }
.lr-response-body { font-size: 0.875rem; color: var(--color-ink-700, #374151); line-height: 1.55; }

/* Respond section */
.lr-respond-section { display: flex; flex-direction: column; gap: 8px; }

.lr-respond-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 14px;
  border-radius: 8px;
  border: 1.5px solid var(--color-ink-200, #E5E7EB);
  background: transparent;
  color: var(--color-ink-600, #4B5563);
  font-size: 0.8125rem;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.12s, color 0.12s;
  align-self: flex-start;
}
.lr-respond-btn:hover {
  background: var(--color-ink-50, #F9FAFB);
  color: var(--color-ink-900, #111827);
}
.lr-respond-btn:focus-visible { outline: 2px solid var(--color-brand-500, #0EA5E9); outline-offset: 2px; }

.lr-respond-form {
  display: flex;
  flex-direction: column;
  gap: 12px;
  background: var(--color-ink-50, #F9FAFB);
  border: 1px solid var(--color-ink-200, #E5E7EB);
  border-radius: 12px;
  padding: 16px;
}
.lr-respond-form-actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
}
`;
