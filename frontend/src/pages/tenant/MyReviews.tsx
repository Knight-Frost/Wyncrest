/**
 * MyReviews — tenant's own reviews list + write/edit review.
 *
 * Truth contract:
 * - GET /tenant/reviews → list of the tenant's reviews with property + contract
 * - PATCH /tenant/reviews/{id} → edit while status === 'pending'
 *
 * The eligibility-gated review form for a specific listing lives in
 * ListingDetail (check-and-show pattern). This page shows the history and
 * allows editing pending reviews.
 *
 * No fake data, no dead buttons.
 */
import { useState } from 'react';
import { Link } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { tenantApi } from '@/lib/endpoints';
import { formatDate } from '@/lib/format';
import { useToast } from '@/components/ui/toast';
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/states';
import { SemanticBadge } from '@/components/cards';
import { StarRating } from '@/components/ui/StarRating';
import {
  IconStar,
  IconMapPin,
  IconEdit,
  IconChevronRight,
} from '@/components/ui/icons';
import type { Review, ReviewStatus } from '@/lib/types';

/* ── helpers ─────────────────────────────────────────────────────────────── */

function reviewRole(status: ReviewStatus): 'success' | 'warning' | 'danger' | 'info' | 'neutral' {
  switch (status) {
    case 'approved': return 'success';
    case 'pending':  return 'warning';
    case 'rejected': return 'danger';
    case 'hidden':   return 'neutral';
    case 'flagged':  return 'danger';
    default:         return 'neutral';
  }
}

function reviewLabel(status: ReviewStatus): string {
  switch (status) {
    case 'approved': return 'Approved';
    case 'pending':  return 'Pending review';
    case 'rejected': return 'Not approved';
    case 'hidden':   return 'Hidden';
    case 'flagged':  return 'Flagged';
    default:         return status;
  }
}

/* ── Edit form ───────────────────────────────────────────────────────────── */

function EditReviewForm({
  review,
  onDone,
}: {
  review: Review;
  onDone: (updated: Review) => void;
}) {
  const { toast } = useToast();
  const [rating, setRating] = useState(review.rating);
  const [title, setTitle] = useState(review.title ?? '');
  const [body, setBody] = useState(review.body);
  const [saving, setSaving] = useState(false);

  async function handleSave(e: React.FormEvent) {
    e.preventDefault();
    if (saving) return;
    if (!body.trim()) {
      toast('Please write a review body.', 'error');
      return;
    }
    setSaving(true);
    try {
      const updated = await tenantApi.updateReview(review.id, {
        rating,
        title: title || undefined,
        body,
      });
      toast('Review updated.', 'success');
      onDone(updated);
    } catch {
      toast('Save failed. Please try again.', 'error');
    } finally {
      setSaving(false);
    }
  }

  return (
    <form className="mr-edit-form" onSubmit={handleSave}>
      <h4 className="mr-edit-title">Edit review</h4>
      <div className="mr-edit-rating-row">
        <span className="mr-field-label">Rating</span>
        <StarRating value={rating} onChange={setRating} size={24} />
      </div>
      <div>
        <label className="mr-field-label" htmlFor="mr-title">Title (optional)</label>
        <input
          id="mr-title"
          className="mr-input"
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          maxLength={120}
          placeholder="Summarise your experience"
          disabled={saving}
        />
      </div>
      <div>
        <label className="mr-field-label" htmlFor="mr-body">Review</label>
        <textarea
          id="mr-body"
          className="mr-textarea"
          value={body}
          onChange={(e) => setBody(e.target.value)}
          maxLength={2000}
          rows={4}
          placeholder="Describe your rental experience…"
          required
          disabled={saving}
        />
        <span className="mr-char-count">{body.length}/2000</span>
      </div>
      <div className="mr-edit-actions">
        <button type="submit" className="mr-btn-save" disabled={saving}>
          {saving ? 'Saving…' : 'Save changes'}
        </button>
      </div>
    </form>
  );
}

/* ── Review card ─────────────────────────────────────────────────────────── */

function ReviewCard({
  review,
  onUpdated,
}: {
  review: Review;
  onUpdated: (r: Review) => void;
}) {
  const [editing, setEditing] = useState(false);

  const canEdit = review.status === 'pending';
  const propertyName = review.property?.name ?? 'Property';
  const propertyCity = review.property
    ? `${review.property.city}, ${review.property.state}`
    : null;

  function handleDone(updated: Review) {
    onUpdated(updated);
    setEditing(false);
  }

  return (
    <div className="mr-card">
      <div className="mr-card-head">
        <div className="mr-card-meta">
          <div className="mr-property-line">
            <span className="mr-property-name">{propertyName}</span>
            {propertyCity && (
              <span className="mr-property-city">
                <IconMapPin size={12} /> {propertyCity}
              </span>
            )}
          </div>
          <div className="mr-card-row2">
            <StarRating value={review.rating} readOnly size={16} />
            <SemanticBadge role={reviewRole(review.status)} size="sm">
              {reviewLabel(review.status)}
            </SemanticBadge>
          </div>
        </div>
        <div className="mr-card-actions">
          {canEdit && !editing && (
            <button className="mr-edit-btn" onClick={() => setEditing(true)} type="button" aria-label="Edit review">
              <IconEdit size={15} /> Edit
            </button>
          )}
        </div>
      </div>

      {review.title && <h4 className="mr-review-title">{review.title}</h4>}
      <p className="mr-review-body">{review.body}</p>

      {review.landlord_response && (
        <div className="mr-response">
          <span className="mr-response-label">Landlord response</span>
          <p className="mr-response-text">{review.landlord_response}</p>
        </div>
      )}

      {review.moderation_reason && review.status === 'rejected' && (
        <div className="mr-moderation-note">
          <span className="mr-moderation-label">Moderation note</span>
          <p className="mr-moderation-text">{review.moderation_reason}</p>
        </div>
      )}

      <div className="mr-card-foot">
        <span className="mr-date">{formatDate(review.created_at)}</span>
        {review.contract?.listing_id != null && (
          <Link to={`/app/listing/${review.contract.listing_id}`} className="mr-view-link">
            View listing <IconChevronRight size={13} />
          </Link>
        )}
      </div>

      {editing && <EditReviewForm review={review} onDone={handleDone} />}
    </div>
  );
}

/* ── Page ─────────────────────────────────────────────────────────────────── */

export function MyReviews() {
  const { data: reviews, loading, error, reload } = useApi(
    () => tenantApi.reviews(),
    [],
  );

  const [localReviews, setLocalReviews] = useState<Review[] | null>(null);

  // Merge updated review into the local list so we reflect edits without a full reload
  function handleUpdated(updated: Review) {
    const list = localReviews ?? reviews ?? [];
    setLocalReviews(list.map((r) => (r.id === updated.id ? updated : r)));
  }

  const displayReviews = localReviews ?? reviews;

  return (
    <div className="mr-page">
      <style>{MR_CSS}</style>

      <div className="mr-page-header">
        <p className="mr-eyebrow">Reputation</p>
        <h1 className="mr-page-title">My Reviews</h1>
        <p className="mr-page-desc">
          Reviews you've written for properties you've rented. Pending reviews can be edited.
        </p>
      </div>

      {loading && <LoadingState label="Loading reviews…" />}
      {error && <ErrorState message={error.message} onRetry={reload} />}

      {!loading && !error && displayReviews && (
        displayReviews.length === 0
          ? (
            <EmptyState
              icon={<IconStar size={22} />}
              title="No reviews yet"
              description="Once you have an active lease, you can review the property from the listing page."
              action={
                <Link to="/app/contracts" className="mr-cta-link">
                  View my contracts <IconChevronRight size={14} />
                </Link>
              }
            />
          )
          : (
            <div className="mr-list">
              {displayReviews.map((r) => (
                <ReviewCard key={r.id} review={r} onUpdated={handleUpdated} />
              ))}
            </div>
          )
      )}
    </div>
  );
}

/* ── Scoped styles ───────────────────────────────────────────────────────── */

const MR_CSS = `
.mr-page {
  max-width: 760px;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: 24px;
}
.mr-page-header {
  display: flex;
  flex-direction: column;
  gap: 0;
  background: var(--color-surface, #fff);
  border: 1px solid var(--color-ink-200, #E5E7EB);
  border-radius: var(--radius-2xl, 20px);
  box-shadow: var(--shadow-sm, 0 1px 2px rgba(0,0,0,.05));
  padding: clamp(1.4rem, 3vw, 2.1rem);
}
.mr-eyebrow {
  font-family: var(--font-mono, ui-monospace);
  font-size: 0.6rem;
  letter-spacing: .2em;
  text-transform: uppercase;
  color: var(--color-brand-700, #4338CA);
  display: inline-flex;
  align-items: center;
  gap: 0.6em;
}
.mr-eyebrow::before { content: ''; width: 24px; height: 1px; background: var(--color-brand-700, #4338CA); }
.mr-page-title {
  font-family: 'Fraunces', Georgia, serif;
  font-size: clamp(2.1rem, 4.4vw, 3rem);
  font-weight: 400;
  color: var(--color-ink-950, #0C0A09);
  line-height: 1.02;
  margin: 0.6rem 0 0.4rem;
}
.mr-page-desc { font-size: 0.9375rem; color: var(--color-ink-500, #6B7280); margin: 0; }

.mr-list { display: flex; flex-direction: column; gap: 16px; }

/* Card */
.mr-card {
  border-radius: 16px;
  border: 1px solid var(--color-ink-200, #E5E7EB);
  background: var(--color-surface, #FFFFFF);
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.mr-card-head { display: flex; gap: 12px; align-items: flex-start; }
.mr-card-meta { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
.mr-property-line { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.mr-property-name {
  font-family: 'Fraunces', Georgia, serif;
  font-size: 1.05rem;
  font-weight: 600;
  color: var(--color-ink-900, #111827);
}
.mr-property-city {
  display: flex; align-items: center; gap: 3px;
  font-size: 0.8125rem; color: var(--color-ink-400, #9CA3AF);
}
.mr-card-row2 { display: flex; align-items: center; gap: 10px; }
.mr-card-actions { flex: 0 0 auto; }

.mr-edit-btn {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 6px 12px;
  border-radius: 8px;
  border: 1.5px solid var(--color-ink-200, #E5E7EB);
  background: var(--color-surface, #FFFFFF);
  font-size: 0.8125rem; font-weight: 600;
  color: var(--color-ink-700, #374151);
  cursor: pointer;
  transition: background 0.12s, border-color 0.12s;
}
.mr-edit-btn:hover { background: var(--color-ink-50, #F9FAFB); border-color: var(--color-ink-300, #D1D5DB); }

.mr-review-title {
  font-family: 'Fraunces', Georgia, serif;
  font-size: 1rem;
  font-weight: 600;
  color: var(--color-ink-900, #111827);
  margin: 0;
}
.mr-review-body { font-size: 0.9rem; color: var(--color-ink-700, #374151); line-height: 1.65; white-space: pre-wrap; }

.mr-response {
  background: var(--color-brand-50, #F0F9FF);
  border-left: 3px solid var(--color-brand-400, #38BDF8);
  border-radius: 4px 8px 8px 4px;
  padding: 10px 14px;
  display: flex; flex-direction: column; gap: 4px;
}
.mr-response-label { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; color: var(--color-brand-600, #0284C7); }
.mr-response-text { font-size: 0.875rem; color: var(--color-ink-700, #374151); line-height: 1.55; }

.mr-moderation-note {
  background: var(--color-danger-50, #FFF5F5);
  border-left: 3px solid var(--color-danger-400, #F87171);
  border-radius: 4px 8px 8px 4px;
  padding: 10px 14px;
  display: flex; flex-direction: column; gap: 4px;
}
.mr-moderation-label { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; color: var(--color-danger-600, #DC2626); }
.mr-moderation-text { font-size: 0.875rem; color: var(--color-ink-700, #374151); }

.mr-card-foot {
  display: flex; align-items: center; gap: 12px;
  padding-top: 8px; border-top: 1px solid var(--color-ink-100, #F3F4F6);
}
.mr-date { font-size: 0.8125rem; color: var(--color-ink-400, #9CA3AF); }
.mr-view-link {
  margin-left: auto; display: inline-flex; align-items: center; gap: 3px;
  font-size: 0.8125rem; font-weight: 600;
  color: var(--color-brand-600, #0284C7); text-decoration: none;
}
.mr-view-link:hover { text-decoration: underline; }

/* Edit form */
.mr-edit-form {
  background: var(--color-ink-50, #F9FAFB);
  border-radius: 12px;
  border: 1.5px solid var(--color-ink-200, #E5E7EB);
  padding: 18px;
  display: flex; flex-direction: column; gap: 14px;
  margin-top: 4px;
}
.mr-edit-title {
  font-family: 'Fraunces', Georgia, serif;
  font-size: 1rem; font-weight: 600;
  color: var(--color-ink-900, #111827);
}
.mr-edit-rating-row { display: flex; align-items: center; gap: 12px; }
.mr-field-label { display: block; font-size: 0.875rem; font-weight: 500; color: var(--color-ink-700, #374151); margin-bottom: 4px; }
.mr-input {
  width: 100%; border-radius: 8px;
  border: 1.5px solid var(--color-ink-200, #E5E7EB);
  background: #fff; padding: 8px 12px;
  font-size: 0.9375rem; color: var(--color-ink-800, #1F2937);
  font-family: inherit; box-sizing: border-box;
  transition: border-color 0.15s;
}
.mr-input:focus { outline: none; border-color: var(--color-brand-500, #0EA5E9); }
.mr-textarea {
  width: 100%; border-radius: 8px;
  border: 1.5px solid var(--color-ink-200, #E5E7EB);
  background: #fff; padding: 8px 12px;
  font-size: 0.9375rem; color: var(--color-ink-800, #1F2937);
  font-family: inherit; resize: vertical; box-sizing: border-box;
  transition: border-color 0.15s;
}
.mr-textarea:focus { outline: none; border-color: var(--color-brand-500, #0EA5E9); }
.mr-char-count { font-size: 0.75rem; color: var(--color-ink-400, #9CA3AF); display: block; text-align: right; margin-top: 2px; }
.mr-edit-actions { display: flex; justify-content: flex-end; }
.mr-btn-save {
  padding: 9px 20px;
  border-radius: 8px;
  background: var(--color-brand-600, #0284C7); color: #fff;
  font-size: 0.9rem; font-weight: 600; border: none; cursor: pointer;
  transition: background 0.15s;
}
.mr-btn-save:hover:not(:disabled) { background: var(--color-brand-700, #0369A1); }
.mr-btn-save:disabled { opacity: 0.5; cursor: not-allowed; }
.mr-btn-save:focus-visible { outline: 2px solid var(--color-brand-500, #0EA5E9); outline-offset: 2px; }

.mr-cta-link {
  display: inline-flex; align-items: center; gap: 4px;
  font-weight: 600; color: var(--color-brand-600, #0284C7); text-decoration: none;
}
.mr-cta-link:hover { text-decoration: underline; }
`;
