import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import {
  ArrowUpDown, Banknote, Bath, BedDouble, Building2, Calendar, Camera, Car,
  Check, ChevronLeft, ChevronRight, Clock, Droplet, Dumbbell, FileText, Heart,
  Lock, MapPin, PawPrint, ShieldCheck, Snowflake, Sofa, Star, Trash2, Trees,
  Users, WashingMachine, Waves, Wifi, Zap,
} from 'lucide-react';
import { useAuth } from '@/context/auth';
import { useApi } from '@/hooks/useApi';
import { publicApi, tenantApi } from '@/lib/endpoints';
import { formatDate, formatDollars, humanize, listingStatusTone } from '@/lib/format';
import { normalizeError } from '@/lib/api';
import { Card, CardBody } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Avatar } from '@/components/ui/Avatar';
import { EmptyState, Skeleton } from '@/components/ui/states';
import { StarRating } from '@/components/ui/StarRating';
import { useToast } from '@/components/ui/toast';
import { SectionHeader } from '@/components/cards';
import { InfoHint } from '@/components/ui/InfoHint';
import { help } from '@/lib/helpText';
import type { Application, Contract, Listing, PropertyAmenity, ReviewEligibility, User } from '@/lib/types';
import './listing-detail.css';

/* ── static lookups ──────────────────────────────────────────────────────── */

const PROPERTY_TYPE_LABEL: Record<string, string> = {
  single_family: 'House', multi_family: 'Multi-family home', apartment: 'Apartment',
  condo: 'Condo', townhouse: 'Townhouse', commercial: 'Commercial', duplex: 'Duplex',
  studio_block: 'Studio block', compound_house: 'Compound house', mixed_use: 'Mixed-use', other: 'Property',
};
const propertyTypeLabel = (t?: string) =>
  t ? PROPERTY_TYPE_LABEL[t] ?? humanize(t) : null;

const AMENITY_ICON: Partial<Record<PropertyAmenity | string, typeof Wifi>> = {
  gated: Lock,
  security_guard: ShieldCheck,
  cctv: Camera,
  water: Droplet,
  electricity: Zap,
  backup_generator: Zap,
  internet_ready: Wifi,
  waste_collection: Trash2,
  air_conditioning: Snowflake,
  furnished: Sofa,
  laundry: WashingMachine,
  elevator: ArrowUpDown,
  street_parking: Car,
  private_parking: Car,
  covered_parking: Car,
  garden: Trees,
  pool: Waves,
  gym: Dumbbell,
  shared_courtyard: Users,
};

/**
 * Every status blocks a fresh "Apply" except `withdrawn` — matches the backend's
 * `hasOpenApplication()` scope (ApplicationController). A withdrawn application
 * was the tenant's own choice to retract, so starting over is allowed; every
 * other status (including a past decision) routes the tenant to that record
 * instead of letting them stack a second one.
 */
function findBlockingApplication(applications: Application[], listingId: number): Application | undefined {
  return applications.find((a) => a.listing_id === listingId && a.status !== 'withdrawn');
}

/* ── gallery ──────────────────────────────────────────────────────────────── */

interface GalleryImage {
  url: string;
  alt: string;
}

/** Real gallery only — the listing's own media_assets first, then legacy photos. Never a stock photo standing in for the unit. */
function resolveGalleryImages(listing: Listing): GalleryImage[] {
  const assets = (listing.media_assets ?? []).filter((a) => !!a.url);
  if (assets.length > 0) {
    return assets.map((a) => ({ url: a.url as string, alt: a.alt_text || listing.title }));
  }
  const photos = listing.photos ?? [];
  if (photos.length > 0) {
    return [...photos]
      .sort((a, b) => Number(b.is_primary) - Number(a.is_primary) || a.sort_order - b.sort_order)
      .map((p) => ({
        url: `${import.meta.env.VITE_API_URL ?? ''}/storage/${p.path}`,
        alt: p.alt_text || listing.title,
      }));
  }
  return [];
}

/** Stable abstract hue seeded from the listing — matches the "no fake photos" placeholder convention used on Browse/Saved. */
function listingHue(seed: string): number {
  let hash = 0;
  for (let i = 0; i < seed.length; i++) hash = (hash * 31 + seed.charCodeAt(i)) & 0xffff;
  return 190 + (hash % 50);
}

function ListingGallery({ images, seed }: { images: GalleryImage[]; seed: string }) {
  const [index, setIndex] = useState(0);

  if (images.length === 0) {
    const hue = listingHue(seed);
    return (
      <div
        className="ld-gallery"
        style={{
          background: `linear-gradient(135deg, hsl(${hue} 28% 92%), hsl(${hue} 22% 84%))`,
          color: `hsl(${hue} 30% 45%)`,
        }}
      >
        <div className="ld-gallery-placeholder">
          <Building2 size={48} strokeWidth={1.25} />
          <span className="ld-gallery-placeholder-note">No photos yet</span>
        </div>
      </div>
    );
  }

  const go = (delta: number) => setIndex((i) => (i + delta + images.length) % images.length);

  return (
    <div
      className="ld-gallery"
      tabIndex={0}
      role="group"
      aria-label="Listing photos"
      onKeyDown={(e) => {
        if (e.key === 'ArrowLeft') go(-1);
        if (e.key === 'ArrowRight') go(1);
      }}
    >
      <img src={images[index].url} alt={images[index].alt} />
      {images.length > 1 && (
        <>
          <button type="button" className="ld-gallery-nav prev" onClick={() => go(-1)} aria-label="Previous photo">
            <ChevronLeft size={18} />
          </button>
          <button type="button" className="ld-gallery-nav next" onClick={() => go(1)} aria-label="Next photo">
            <ChevronRight size={18} />
          </button>
          <span className="ld-gallery-counter">{index + 1} / {images.length}</span>
          <span className="ld-gallery-dots" aria-hidden="true">
            {images.map((img, i) => (
              <span key={img.url} className={`ld-gallery-dot${i === index ? ' on' : ''}`} />
            ))}
          </span>
        </>
      )}
    </div>
  );
}

/* ── small building blocks ───────────────────────────────────────────────── */

function FactRow({ icon, label, value }: { icon: React.ReactNode; label: string; value: React.ReactNode }) {
  return (
    <div className="ld-fact-row">
      <span className="ld-fact-ico">{icon}</span>
      <div>
        <div className="ld-fact-label">{label}</div>
        <div className="ld-fact-value">{value}</div>
      </div>
    </div>
  );
}

function DetailTile({
  icon,
  label,
  value,
  help: helpText,
}: {
  icon: React.ReactNode;
  label: string;
  value: React.ReactNode;
  help?: string;
}) {
  return (
    <div className="ld-tile">
      <span className="ld-tile-ico">{icon}</span>
      <div>
        <div className="ld-tile-label" style={{ display: 'flex', alignItems: 'center', gap: '0.3rem' }}>
          {label}
          {helpText && <InfoHint text={helpText} label={`About ${label}`} />}
        </div>
        <div className="ld-tile-value">{value}</div>
      </div>
    </div>
  );
}

/* ── apply eligibility ───────────────────────────────────────────────────
 *
 * `signed-out` is kept for defensiveness but is UNREACHABLE today: /app/* is
 * wrapped in <RequireAuth> (see App.tsx), so an anonymous visitor is bounced
 * to /login before this component ever mounts — confirmed by hand in the
 * browser.
 *
 * Precedence: an existing application always wins over the verification
 * gate. Verification only matters for STARTING a new application — once one
 * exists (in any non-withdrawn status), the tenant's next step is to look at
 * that record, not to be told they need to verify again.
 */
type ApplyCta =
  | { kind: 'signed-out' }
  | { kind: 'not-verified' }
  | { kind: 'can-apply' }
  | { kind: 'has-application'; label: string; to: string };

/** Real backend status → the button copy + route the tenant should land on next. */
function applicationCtaMeta(application: Application, leaseContractId: string | null): { label: string; to: string } {
  switch (application.status) {
    case 'draft':
      return { label: 'Continue application', to: `/app/applications/${application.id}/apply` };
    case 'needs_action':
      return { label: 'Fix application', to: `/app/applications/${application.id}` };
    case 'approved':
      return leaseContractId
        ? { label: 'Review lease', to: `/app/contracts/${leaseContractId}` }
        : { label: 'View approval', to: `/app/applications/${application.id}` };
    case 'rejected':
      return { label: 'View decision', to: `/app/applications/${application.id}` };
    default: // submitted | in_review | landlord_review
      return { label: 'View application', to: `/app/applications/${application.id}` };
  }
}

function getApplyCtaState(input: {
  signedIn: boolean;
  /** Only meaningful when signedIn — the tenant's verification_status ('verified' is the only status that clears ApplicationController's server-side gate). */
  verificationStatus: string | null | undefined;
  /** The tenant's existing application for this listing, if any (every status but 'withdrawn' blocks a fresh apply). */
  blockingApplication: Application | undefined;
  /** The tenant's own accepted contract for this listing, if one already exists (only relevant once approved). */
  leaseContractId: string | null;
}): ApplyCta {
  if (!input.signedIn) return { kind: 'signed-out' };
  if (input.blockingApplication) {
    return { kind: 'has-application', ...applicationCtaMeta(input.blockingApplication, input.leaseContractId) };
  }
  if (input.verificationStatus !== 'verified') return { kind: 'not-verified' };
  return { kind: 'can-apply' };
}

const STATIC_CTA_LABEL: Record<'signed-out' | 'not-verified' | 'can-apply', string> = {
  'signed-out': 'Sign in to apply',
  'not-verified': 'Verify your identity to apply',
  'can-apply': 'Apply for this home',
};

/* ── landlord / trust card ───────────────────────────────────────────────── */

function LandlordTrustCard({ landlord }: { landlord?: User }) {
  if (!landlord) return null;
  const verified = landlord.identity_verified === true;
  const name = [landlord.first_name, landlord.last_name].filter(Boolean).join(' ') || 'Landlord';

  return (
    <Card>
      <CardBody className="space-y-4">
        <div className="ld-trust-row">
          <span className={`ld-trust-ico ${verified ? 'on' : 'off'}`}>
            <ShieldCheck size={20} />
          </span>
          <div>
            <p className="ld-trust-title">{verified ? 'Verified landlord' : 'Verification pending'}</p>
            <p className="ld-trust-desc">
              {verified
                ? 'This landlord has completed identity verification on Wyncrest.'
                : "This landlord hasn't completed identity verification yet."}
            </p>
          </div>
        </div>
        <div className="ld-landlord-row">
          <Avatar name={name} src={landlord.avatar_url} className="ld-landlord-avatar" />
          <div>
            <p className="ld-landlord-name">{name}</p>
            <p className="ld-landlord-role">Landlord</p>
          </div>
        </div>
      </CardBody>
    </Card>
  );
}

/* ── reviews card (real summary + snippets, then the eligibility-gated write form) ── */

function ReviewsCard({ listing, listingId, isTenant }: { listing: Listing; listingId: number; isTenant: boolean }) {
  const { toast } = useToast();
  const property = listing.unit?.property;
  const avgRating = property?.average_rating ?? null;
  const reviewCount = property?.review_count ?? 0;
  const reviews = property?.approved_reviews ?? [];

  const { data: eligibility, loading: eligLoading } = useApi<ReviewEligibility>(
    () => (isTenant ? tenantApi.reviewEligibility(listingId) : Promise.resolve({ eligible: false, contract_id: null })),
    [listingId, isTenant],
  );

  const [submitted, setSubmitted] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [rating, setRating] = useState(0);
  const [title, setTitle] = useState('');
  const [body, setBody] = useState('');

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (submitting || !eligibility?.contract_id) return;
    if (rating === 0) {
      toast('Please select a star rating.', 'error');
      return;
    }
    if (!body.trim()) {
      toast('Please write a review body.', 'error');
      return;
    }
    setSubmitting(true);
    try {
      await tenantApi.createReview({
        contract_id: eligibility.contract_id,
        rating,
        title: title.trim() || undefined,
        body: body.trim(),
      });
      setSubmitted(true);
      toast('Review submitted. Thank you!', 'success');
    } catch (err) {
      const msg = (err as { message?: string })?.message ?? 'Could not submit. Please try again.';
      toast(msg, 'error');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Card>
      <CardBody className="space-y-4">
        <h2 className="text-base font-semibold text-ink-900">Reviews</h2>

        {reviewCount > 0 ? (
          <>
            <div className="ld-rating-row">
              <span className="ld-rating-num">{avgRating?.toFixed(1)}</span>
              <StarRating value={Math.round(avgRating ?? 0)} readOnly size={16} />
              <span className="ld-rating-count">{reviewCount} review{reviewCount === 1 ? '' : 's'}</span>
            </div>
            <div>
              {reviews.slice(0, 3).map((r) => (
                <div key={r.id} className="ld-review-item">
                  <div className="ld-review-head">
                    <span className="ld-review-name">
                      {[r.reviewer?.first_name, r.reviewer?.last_name].filter(Boolean).join(' ') || 'Tenant'}
                    </span>
                    <span className="ld-review-date">{formatDate(r.created_at)}</span>
                  </div>
                  <StarRating value={r.rating} readOnly size={13} />
                  {r.body && <p className="ld-review-body">{r.body}</p>}
                </div>
              ))}
            </div>
            {reviewCount > reviews.length && (
              <p className="ld-review-more">+{reviewCount - reviews.length} more review{reviewCount - reviews.length === 1 ? '' : 's'}</p>
            )}
          </>
        ) : (
          <p className="text-sm text-ink-500">No reviews yet.</p>
        )}

        {isTenant && !eligLoading && (
          eligibility?.eligible ? (
            submitted ? (
              <p className="flex items-center gap-2 border-t border-ink-200 pt-4 text-sm font-medium text-success-700">
                <Star size={16} className="text-warning-500" />
                Your review has been submitted and is pending moderation.
              </p>
            ) : (
              <form onSubmit={handleSubmit} className="space-y-3 border-t border-ink-200 pt-4">
                <p className="text-sm font-semibold text-ink-900">Write a review</p>
                <StarRating value={rating} onChange={setRating} size={24} />
                <input
                  className="glass-input w-full px-3 py-2.5 text-sm text-ink-900"
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                  maxLength={120}
                  placeholder="Title (optional)"
                  disabled={submitting}
                />
                <textarea
                  className="glass-input w-full px-3 py-2.5 text-sm text-ink-900 placeholder:text-ink-400"
                  rows={3}
                  maxLength={2000}
                  placeholder="Describe your rental experience…"
                  value={body}
                  onChange={(e) => setBody(e.target.value)}
                  required
                  disabled={submitting}
                />
                <Button type="submit" variant="primary" size="sm" loading={submitting} disabled={submitting || rating === 0}>
                  Submit review
                </Button>
              </form>
            )
          ) : (
            <p className="flex items-center gap-2 border-t border-ink-200 pt-4 text-xs text-ink-500">
              <Star size={14} />
              Review available after your lease on this property is active or completed.
            </p>
          )
        )}
      </CardBody>
    </Card>
  );
}

/* ── loading skeleton ─────────────────────────────────────────────────────── */

function ListingDetailSkeleton() {
  return (
    <div className="ld-page">
      <Skeleton className="h-4 w-32 rounded-md" />
      <div>
        <Skeleton className="mb-2 h-8 w-2/3 rounded-md" />
        <Skeleton className="h-4 w-40 rounded-md" />
      </div>
      <div className="ld-hero">
        <Skeleton className="aspect-[4/3] w-full rounded-2xl" />
        <Skeleton className="h-full min-h-[220px] w-full rounded-2xl" />
      </div>
      <div className="ld-body">
        <div className="space-y-4">
          <Skeleton className="h-5 w-40 rounded-md" />
          <Skeleton className="h-4 w-full rounded-md" />
          <Skeleton className="h-4 w-5/6 rounded-md" />
          <Skeleton className="h-24 w-full rounded-xl" />
        </div>
        <Skeleton className="h-72 w-full rounded-2xl" />
      </div>
    </div>
  );
}

/* ========================================================================== */

export function ListingDetail() {
  const { id } = useParams<{ id: string }>();
  const { user } = useAuth();
  const { toast } = useToast();
  const navigate = useNavigate();
  const listingId = Number(id);

  const [applying, setApplying] = useState(false);
  const [saving, setSaving] = useState(false);
  const [savedOverride, setSavedOverride] = useState(false);

  const { data: listing, loading, error } = useApi(
    () => publicApi.show(listingId),
    [listingId],
  );

  const isTenant = user?.role === 'tenant';

  const { data: savedListings } = useApi<Listing[]>(
    () => (isTenant ? tenantApi.savedListings() : Promise.resolve([])),
    [isTenant],
  );
  const { data: applications, reload: reloadApplications } = useApi<Application[]>(
    () => (isTenant ? tenantApi.applications() : Promise.resolve([])),
    [isTenant],
  );
  const { data: contracts } = useApi<Contract[]>(
    () => (isTenant ? tenantApi.contracts() : Promise.resolve([])),
    [isTenant],
  );

  const alreadySaved = savedOverride || (savedListings ?? []).some((l) => l.id === listingId);
  const blockingApplication = findBlockingApplication(applications ?? [], listingId);
  const leaseContractId =
    blockingApplication?.status === 'approved'
      ? (contracts ?? []).find((c) => c.listing_id === listingId)?.id ?? null
      : null;

  const ctaState = getApplyCtaState({
    signedIn: !!user,
    verificationStatus: user && 'verification_status' in user ? user.verification_status : undefined,
    blockingApplication,
    leaseContractId,
  });

  async function handleSave() {
    if (!listing) return;
    setSaving(true);
    try {
      await tenantApi.saveListing(listing.id);
      setSavedOverride(true);
      toast('Listing saved to favorites', 'success');
    } catch {
      toast('Could not save listing', 'error');
    } finally {
      setSaving(false);
    }
  }

  async function handleApply() {
    if (!listing) return;
    setApplying(true);
    try {
      const created = await tenantApi.startApplicationDraft(listing.id);
      navigate(`/app/applications/${created.id}/apply`);
    } catch (err) {
      const apiErr = normalizeError(err);
      if (apiErr.status === 422) {
        // Backend refuses a second open application for the same listing; if we
        // already know which one it is, take the tenant there instead.
        const existing = findBlockingApplication(applications ?? [], listingId);
        if (existing) {
          navigate(
            existing.status === 'draft'
              ? `/app/applications/${existing.id}/apply`
              : `/app/applications/${existing.id}`,
          );
        } else {
          toast(apiErr.message || 'You already have an application for this listing.', 'info');
          reloadApplications();
        }
      } else {
        toast(apiErr.message || 'Could not start your application. Please try again.', 'error');
      }
    } finally {
      setApplying(false);
    }
  }

  if (loading) return <ListingDetailSkeleton />;

  if (error || !listing) {
    return (
      <div className="ld-page">
        <EmptyState
          icon={<Building2 size={28} />}
          title="Listing unavailable"
          description="This rental may have been removed, archived, or is no longer public."
          action={
            <Link to="/app/browse">
              <Button variant="secondary">Back to browse</Button>
            </Link>
          }
        />
      </div>
    );
  }

  const unit = listing.unit;
  const property = unit?.property;
  const rules = property?.rules;
  const location = property ? [property.city, property.state].filter(Boolean).join(', ') : null;
  const images = resolveGalleryImages(listing);

  const propertyAmenities = property?.amenities ?? [];
  const unitAmenities = unit?.amenities ?? [];
  const hasAnyAmenities = propertyAmenities.length > 0 || unitAmenities.length > 0;

  const furnishedTile =
    unit?.amenities != null
      ? unit.amenities.includes('furnished')
        ? 'Furnished'
        : 'Unfurnished'
      : null;

  const showApplyBlock = !user || isTenant;

  return (
    <div className="ld-page">
      <Link to="/app/browse" className="ld-back">
        <ChevronLeft size={16} />
        Back to browse
      </Link>

      <div className="ld-header">
        <div>
          <div className="ld-title-row">
            <h1 className="ld-title">{listing.title}</h1>
            <Badge tone={listingStatusTone(listing.status)}>{humanize(listing.status)}</Badge>
            {listing.featured && <Badge tone="brand">Featured</Badge>}
          </div>
          {location && (
            <p className="ld-location">
              <MapPin size={15} />
              {location}
            </p>
          )}
        </div>
      </div>

      {/* ── hero: gallery + quick facts ──────────────────────────────────── */}
      <div className="ld-hero">
        <ListingGallery images={images} seed={`${listing.id}${listing.title}`} />
        <div className="ld-facts">
          {unit && <FactRow icon={<BedDouble size={16} />} label="Bedrooms" value={`${unit.bedrooms} bd`} />}
          {unit && <FactRow icon={<Bath size={16} />} label="Bathrooms" value={`${unit.bathrooms} ba`} />}
          {unit?.square_feet && (
            <FactRow icon={<Building2 size={16} />} label="Square feet" value={unit.square_feet.toLocaleString()} />
          )}
          <FactRow
            icon={<Calendar size={16} />}
            label="Available from"
            value={unit?.available_from ? formatDate(unit.available_from) : '—'}
          />
          {property?.property_type && (
            <FactRow icon={<Building2 size={16} />} label="Property type" value={propertyTypeLabel(property.property_type)} />
          )}
        </div>
      </div>

      {/* ── main body: content + sidebar ─────────────────────────────────── */}
      <div className="ld-body">
        <div className="ld-content">
          <section className="ld-section">
            <SectionHeader eyebrow="Overview" title="About this rental" />
            {listing.description.trim() ? (
              <p className="ld-prose">{listing.description}</p>
            ) : (
              <p className="ld-empty-note">Description not provided yet.</p>
            )}
          </section>

          {unit && (
            <section className="ld-section">
              <SectionHeader eyebrow="Details" title="Key details" />
              <div className="ld-tiles">
                <DetailTile icon={<Banknote size={16} />} label="Monthly rent" value={formatDollars(unit.rent_amount)} />
                <DetailTile icon={<ShieldCheck size={16} />} label="Security deposit" value={formatDollars(unit.security_deposit)} help={help.securityDeposit} />
                {furnishedTile && <DetailTile icon={<Sofa size={16} />} label="Furnishing" value={furnishedTile} />}
              </div>
            </section>
          )}

          <section className="ld-section">
            <SectionHeader eyebrow="Terms" title="Lease & policies" />
            <div className="ld-tiles">
              <DetailTile
                icon={<Clock size={16} />}
                label="Lease duration"
                value={listing.lease_duration_months ? `${listing.lease_duration_months} months` : '—'}
              />
              <DetailTile
                icon={<Calendar size={16} />}
                label="Move-in date"
                value={listing.move_in_date ? formatDate(listing.move_in_date) : '—'}
              />
              <DetailTile icon={<PawPrint size={16} />} label="Pets" value={listing.pets_allowed ? 'Allowed' : 'Not allowed'} />
              <DetailTile icon={<FileText size={16} />} label="Pet policy" value={listing.pet_policy ?? '—'} />
              {rules?.smoking_allowed != null && (
                <DetailTile icon={<FileText size={16} />} label="Smoking" value={rules.smoking_allowed ? 'Allowed' : 'Not allowed'} />
              )}
              {rules?.guests_allowed != null && (
                <DetailTile icon={<Users size={16} />} label="Guests" value={rules.guests_allowed ? 'Allowed' : 'Not allowed'} />
              )}
              {rules?.max_occupants != null && (
                <DetailTile icon={<Users size={16} />} label="Max occupants" value={rules.max_occupants} />
              )}
              {rules?.quiet_hours && (
                <DetailTile icon={<Clock size={16} />} label="Quiet hours" value={rules.quiet_hours} />
              )}
            </div>
          </section>

          <section className="ld-section">
            <SectionHeader eyebrow="Features" title="Amenities" />
            {hasAnyAmenities ? (
              <div className="ld-amenities">
                {propertyAmenities.map((a) => {
                  const Icon = AMENITY_ICON[a] ?? Check;
                  return (
                    <span key={`p-${a}`} className="ld-chip">
                      <Icon size={14} />
                      {humanize(a)}
                    </span>
                  );
                })}
                {unitAmenities.map((a) => (
                  <span key={`u-${a}`} className="ld-chip">
                    <Check size={14} />
                    {humanize(a)}
                  </span>
                ))}
              </div>
            ) : (
              <p className="ld-empty-note">No amenities listed yet.</p>
            )}
          </section>
        </div>

        {/* ── sidebar (desktop) ─────────────────────────────────────────── */}
        <div className="ld-sidebar">
          <div className="space-y-4">
            <Card>
              <CardBody className="space-y-1">
                <p className="ld-price">
                  {formatDollars(unit?.rent_amount)}
                  <small>/mo</small>
                </p>
                {unit?.security_deposit && <p className="ld-deposit">{formatDollars(unit.security_deposit)} deposit</p>}

                {unit && (
                  <div className="ld-spec-row">
                    <span><BedDouble size={15} /> {unit.bedrooms} bd</span>
                    <span><Bath size={15} /> {unit.bathrooms} ba</span>
                    {unit.square_feet && <span><Building2 size={15} /> {unit.square_feet.toLocaleString()} sqft</span>}
                  </div>
                )}

                {showApplyBlock && (
                  <div className="mt-4 space-y-3">
                    {ctaState.kind === 'signed-out' ? (
                      <Button className="w-full" variant="primary" onClick={() => navigate('/login')}>
                        {STATIC_CTA_LABEL['signed-out']}
                      </Button>
                    ) : ctaState.kind === 'has-application' ? (
                      <Button className="w-full" variant="secondary" onClick={() => navigate(ctaState.to)}>
                        {ctaState.label}
                      </Button>
                    ) : (
                      <>
                        <Button
                          className="w-full"
                          variant="primary"
                          loading={applying}
                          disabled={ctaState.kind !== 'can-apply'}
                          onClick={handleApply}
                        >
                          {STATIC_CTA_LABEL[ctaState.kind]}
                        </Button>
                        {ctaState.kind === 'not-verified' && (
                          <p className="ld-cta-reason">
                            You need to <Link to="/app/verification">complete identity verification</Link> before applying.
                          </p>
                        )}
                      </>
                    )}

                    {isTenant && (
                      <Button
                        className="w-full"
                        variant={alreadySaved ? 'secondary' : 'ghost'}
                        loading={saving}
                        disabled={alreadySaved}
                        onClick={handleSave}
                        leftIcon={<Heart size={16} fill={alreadySaved ? 'currentColor' : 'none'} />}
                      >
                        {alreadySaved ? 'Saved to favorites' : 'Save to favorites'}
                      </Button>
                    )}
                  </div>
                )}
              </CardBody>
            </Card>

            <LandlordTrustCard landlord={listing.landlord} />
            <ReviewsCard listing={listing} listingId={listingId} isTenant={isTenant} />
          </div>
        </div>
      </div>

      {/* ── mobile condensed action bar ──────────────────────────────────── */}
      {showApplyBlock && (
        <div className="ld-mobile-bar">
          <div className="ld-mobile-bar-top">
            <p className="ld-price" style={{ fontSize: 22 }}>
              {formatDollars(unit?.rent_amount)}
              <small>/mo</small>
            </p>
          </div>
          {ctaState.kind === 'signed-out' ? (
            <Button className="w-full" variant="primary" onClick={() => navigate('/login')}>
              {STATIC_CTA_LABEL['signed-out']}
            </Button>
          ) : ctaState.kind === 'has-application' ? (
            <Button className="w-full" variant="secondary" onClick={() => navigate(ctaState.to)}>
              {ctaState.label}
            </Button>
          ) : (
            <Button
              className="w-full"
              variant="primary"
              loading={applying}
              disabled={ctaState.kind !== 'can-apply'}
              onClick={handleApply}
            >
              {STATIC_CTA_LABEL[ctaState.kind]}
            </Button>
          )}
        </div>
      )}
    </div>
  );
}
