import { Link, useNavigate, useParams } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { adminApi } from '@/lib/endpoints';
import { formatCedisDecimal, humanize } from '@/lib/format';
import { Button } from '@/components/ui/Button';
import { ErrorState, LoadingState } from '@/components/ui/states';
import { SemanticBadge } from '@/components/cards';
import {
  IconArrowLeft,
  IconEye,
  IconBed,
  IconBath,
  IconMapPin,
  IconImage,
  IconHome,
  IconCheckCircle,
} from '@/components/ui/icons';
import type { ListingPreview as PreviewData } from '@/lib/types';

/**
 * Tenant Preview — renders exactly what a tenant sees once the listing is
 * published. Fed by the admin preview endpoint (the public API would 404 a
 * not-yet-public listing), and deliberately excludes all admin-only data.
 */
export function ListingPreview() {
  const { listingId } = useParams<{ listingId: string }>();
  const numericId = Number(listingId);
  const validId = Number.isFinite(numericId) && numericId > 0;
  const navigate = useNavigate();

  const { data, loading, error, reload } = useApi<PreviewData>(
    () =>
      validId
        ? adminApi.listingReviewPreview(numericId)
        : Promise.reject({ status: 404, message: 'Invalid listing id.' }),
    [numericId],
  );

  return (
    <div className="animate-rise mx-auto max-w-4xl space-y-6">
      {/* Preview banner — always clearly labelled. */}
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-brand-600/25 bg-brand-50 px-4 py-3">
        <div className="flex items-center gap-2.5 text-sm text-brand-700">
          <IconEye className="h-4 w-4" />
          <span className="font-semibold">Tenant Preview</span>
          <span className="text-brand-700/80">— this is what tenants will see once approved.</span>
        </div>
        <Button
          variant="secondary"
          size="sm"
          leftIcon={<IconArrowLeft className="h-4 w-4" />}
          onClick={() => navigate(`/app/listing-review/${numericId}`)}
        >
          Back to review
        </Button>
      </div>

      {loading && <LoadingState label="Loading preview…" />}
      {error && <ErrorState message={error.message} onRetry={reload} />}

      {data && (
        <article className="space-y-6">
          {/* Gallery */}
          {data.photos.length > 0 ? (
            <div className="grid grid-cols-2 gap-2 overflow-hidden rounded-2xl sm:grid-cols-4">
              {data.photos.slice(0, 5).map((p, i) => (
                <div
                  key={p.id}
                  className={`relative overflow-hidden bg-ink-100 ${
                    i === 0 ? 'col-span-2 row-span-2 aspect-square sm:aspect-auto' : 'aspect-square'
                  }`}
                >
                  {p.url ? (
                    <img
                      src={p.url}
                      alt={p.alt_text ?? 'Listing photo'}
                      loading="lazy"
                      className="h-full w-full object-cover"
                    />
                  ) : (
                    <div className="flex h-full w-full items-center justify-center text-ink-400">
                      <IconImage className="h-5 w-5" />
                    </div>
                  )}
                </div>
              ))}
            </div>
          ) : (
            <div className="flex flex-col items-center gap-2 rounded-2xl border border-dashed border-ink-300 py-16 text-center">
              <IconImage className="h-6 w-6 text-ink-400" />
              <p className="text-sm text-ink-500">No photos to preview</p>
            </div>
          )}

          {/* Title + location + rent */}
          <header className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div className="min-w-0">
              <h1 className="font-display text-2xl font-semibold tracking-tight text-ink-950">{data.title}</h1>
              {data.property && (
                <p className="mt-1.5 flex items-center gap-1.5 text-sm text-ink-500">
                  <IconMapPin className="h-4 w-4" />
                  {[data.property.name, data.property.city, data.property.state].filter(Boolean).join(' · ')}
                </p>
              )}
            </div>
            {data.unit && (
              <div className="shrink-0 text-right">
                <p className="font-display text-2xl font-semibold" style={{ color: 'var(--color-money)' }}>
                  {formatCedisDecimal(data.unit.rent_amount)}
                </p>
                <p className="text-xs text-ink-400">per month</p>
              </div>
            )}
          </header>

          {/* Facts row */}
          {data.unit && (
            <div className="flex flex-wrap gap-x-6 gap-y-2 rounded-2xl border border-ink-200 bg-surface px-5 py-4 text-sm text-ink-700 shadow-sm">
              <span className="inline-flex items-center gap-1.5">
                <IconBed className="h-4 w-4 text-ink-400" />
                {data.unit.bedrooms} bedrooms
              </span>
              <span className="inline-flex items-center gap-1.5">
                <IconBath className="h-4 w-4 text-ink-400" />
                {data.unit.bathrooms} bathrooms
              </span>
              {data.unit.square_feet && (
                <span className="inline-flex items-center gap-1.5">
                  <IconHome className="h-4 w-4 text-ink-400" />
                  {data.unit.square_feet} sq ft
                </span>
              )}
              {data.property?.property_type && (
                <span className="inline-flex items-center gap-1.5">{humanize(data.property.property_type)}</span>
              )}
            </div>
          )}

          {/* Description */}
          {data.description && (
            <section className="rounded-2xl border border-ink-200 bg-surface p-5 shadow-sm sm:p-6">
              <h2 className="mb-2 font-display text-lg font-semibold text-ink-900">About this home</h2>
              <p className="whitespace-pre-line text-sm leading-relaxed text-ink-700">{data.description}</p>
            </section>
          )}

          {/* Amenities */}
          {data.unit?.amenities?.length ? (
            <section className="rounded-2xl border border-ink-200 bg-surface p-5 shadow-sm sm:p-6">
              <h2 className="mb-3 font-display text-lg font-semibold text-ink-900">Amenities</h2>
              <div className="flex flex-wrap gap-2">
                {data.unit.amenities.map((a) => (
                  <span key={a} className="rounded-full bg-ink-100 px-3 py-1 text-sm text-ink-700">
                    {humanize(a)}
                  </span>
                ))}
              </div>
            </section>
          ) : null}

          {/* Landlord trust + application CTA (state only — this is a preview) */}
          <section className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-ink-200 bg-surface p-5 shadow-sm sm:p-6">
            <div className="flex items-center gap-2 text-sm text-ink-700">
              {data.landlord?.identity_verified ? (
                <SemanticBadge role="success">
                  <span className="inline-flex items-center gap-1">
                    <IconCheckCircle className="h-3.5 w-3.5" />
                    Verified landlord
                  </span>
                </SemanticBadge>
              ) : (
                <span className="text-ink-500">Listed by {data.landlord?.name}</span>
              )}
            </div>
            <Button disabled title="Preview only — applications open once approved.">
              Apply now
            </Button>
          </section>

          <p className="text-center text-xs text-ink-400">
            You are viewing an admin preview.{' '}
            <Link to={`/app/listing-review/${numericId}`} className="text-brand-700 hover:underline">
              Return to review
            </Link>
          </p>
        </article>
      )}
    </div>
  );
}

export default ListingPreview;
