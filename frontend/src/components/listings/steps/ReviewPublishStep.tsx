/**
 * Step 6 — Review & publish.
 *
 * A read-back of everything entered, sourced from the live form/draft (not
 * fabricated). Surfaces validation warnings and the verification gate: Wyncrest
 * listings require admin review, and only identity-verified landlords may submit
 * — so the final action is "Submit for review" (handled by the flow footer),
 * never a fake "Publish". Unverified landlords see a banner + can still save the
 * draft.
 */
import { Link } from 'react-router';
import { formatCedisDecimal } from '@/lib/format';
import type { MediaAsset, Property } from '@/lib/types';
import type { ListingDraftForm } from '../types';

const BED_LABEL = (v: string) => (v === '0' ? 'Studio' : v === '6' ? '6+' : v || '—');

interface ReviewPublishStepProps {
  form: ListingDraftForm;
  property: Property | null;
  media: MediaAsset[];
  isVerified: boolean;
  /** Validation issues that block submission, shown as warnings. */
  warnings: string[];
}

export function ReviewPublishStep({ form, property, media, isVerified, warnings }: ReviewPublishStepProps) {
  return (
    <>
      {warnings.length > 0 && (
        <div className="cl-banner warn">
          <span>
            <strong>Before submitting:</strong>
            <ul style={{ margin: '6px 0 0', paddingLeft: 18 }}>
              {warnings.map((w) => <li key={w}>{w}</li>)}
            </ul>
          </span>
        </div>
      )}

      {!isVerified && (
        <div className="cl-banner info">
          <span>
            Your identity isn't verified yet, so this listing can't be submitted for review. You can
            save it as a draft now and submit once verified.{' '}
            <Link to="/app/verification" style={{ color: 'var(--color-brand-700)', fontWeight: 600 }}>
              Verify your identity
            </Link>.
          </span>
        </div>
      )}

      <div className="cl-review-grid">
        <div className="cl-review-card">
          <h4>Unit & property</h4>
          <dl style={{ margin: 0 }}>
            <div className="cl-review-row"><dt>Title</dt><dd>{form.title || '—'}</dd></div>
            <div className="cl-review-row"><dt>Property</dt><dd>{property?.name ?? '—'}</dd></div>
            <div className="cl-review-row"><dt>Bedrooms</dt><dd>{BED_LABEL(form.bedrooms)}</dd></div>
            <div className="cl-review-row"><dt>Bathrooms</dt><dd>{form.bathrooms || '—'}</dd></div>
            {form.squareFeet && <div className="cl-review-row"><dt>Size</dt><dd>{form.squareFeet} sq ft</dd></div>}
          </dl>
        </div>

        <div className="cl-review-card">
          <h4>Pricing</h4>
          <dl style={{ margin: 0 }}>
            <div className="cl-review-row"><dt>Monthly rent</dt><dd>{formatCedisDecimal(form.rentAmount)}</dd></div>
            <div className="cl-review-row"><dt>Deposit</dt><dd>{formatCedisDecimal(form.securityDeposit)}</dd></div>
            <div className="cl-review-row"><dt>Lease</dt><dd>{form.leaseDurationMonths ? `${form.leaseDurationMonths} months` : '—'}</dd></div>
            <div className="cl-review-row"><dt>Available</dt><dd>{form.availableFrom || '—'}</dd></div>
            <div className="cl-review-row"><dt>Pets</dt><dd>{form.petsAllowed ? 'Allowed' : 'Not allowed'}</dd></div>
          </dl>
        </div>

        <div className="cl-review-card">
          <h4>Amenities ({form.amenities.length})</h4>
          {form.amenities.length ? (
            <div className="cl-chips">
              {form.amenities.map((a) => (
                <span key={a} className="cl-chip on" style={{ cursor: 'default' }}>{a}</span>
              ))}
            </div>
          ) : (
            <p className="cl-help" style={{ margin: 0 }}>No amenities selected.</p>
          )}
        </div>

        <div className="cl-review-card">
          <h4>Photos ({media.length})</h4>
          {media.length ? (
            <div className="cl-review-thumbs">
              {media.slice(0, 6).map((m) => (
                <img key={m.id} className="cl-review-thumb" src={m.url ?? ''} alt={m.alt_text ?? 'Listing photo'} />
              ))}
            </div>
          ) : (
            <p className="cl-help" style={{ margin: 0 }}>No photos uploaded yet. Listings publish faster with photos.</p>
          )}
        </div>
      </div>

      <div className="cl-review-card">
        <h4>Description</h4>
        <p style={{ margin: 0, fontSize: 14, color: 'var(--color-ink-700)', lineHeight: 1.6 }}>
          {form.description || '—'}
        </p>
      </div>
    </>
  );
}
