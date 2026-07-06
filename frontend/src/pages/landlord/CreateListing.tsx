/**
 * CreateListing — route-based, multi-step listing builder (/app/listings/create).
 *
 * Renders inside the AppShell (sidebar stays visible), matching the Wyncrest
 * mockup: header card + vertical stepper + spacious form panel + footer.
 *
 * TRUTHFULNESS: everything is backed by real endpoints.
 *  - Eligible units/properties come from GET /landlord/{properties,units,listings}.
 *  - A real DRAFT listing is created after Step 1 (POST /units/{id}/listings); every
 *    "Save & continue" persists to the unit (PUT /units/{id}), the listing
 *    (PUT /listings/{id}), or the property (PUT /properties/{id}).
 *  - Photos attach to the real draft via the media endpoints.
 *  - Final action is "Submit for review" (POST /listings/{id}/submit), gated on
 *    identity verification — never a fake "Publish".
 */
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router';
import { Link } from 'react-router';
import { landlordApi } from '@/lib/endpoints';
import { fieldErrors } from '@/lib/api';
import type { ApiError, Listing, MediaAsset, Property, Unit } from '@/lib/types';
import { useAuth } from '@/context/auth';
import { useToast } from '@/components/ui/toast';
import { Button } from '@/components/ui/Button';
import { CreateListingHeader } from '@/components/listings/CreateListingHeader';
import { CreateListingStepper } from '@/components/listings/CreateListingStepper';
import { UnitDetailsStep } from '@/components/listings/steps/UnitDetailsStep';
import { PropertyDetailsStep } from '@/components/listings/steps/PropertyDetailsStep';
import { PricingStep } from '@/components/listings/steps/PricingStep';
import { AmenitiesStep } from '@/components/listings/steps/AmenitiesStep';
import { PhotosStep } from '@/components/listings/steps/PhotosStep';
import { ReviewPublishStep } from '@/components/listings/steps/ReviewPublishStep';
import { STEPS, type FormErrors, type ListingDraftForm } from '@/components/listings/types';
import './create-listing.css';

const EMPTY_FORM: ListingDraftForm = {
  propertyId: null, unitId: null,
  unitNumber: '', bedrooms: '', bathrooms: '', squareFeet: '',
  rentAmount: '', securityDeposit: '', availableFrom: '', amenities: [],
  title: '', description: '', petsAllowed: false, petPolicy: '',
  leaseDurationMonths: '', moveInDate: '', buildingNotes: '',
};

/** Status values that mean a unit is "taken" by an in-progress/live listing. */
const BLOCKING_STATUSES = ['draft', 'pending_review', 'active'];

/** Map snake_case backend validation keys → our camelCase form fields. */
const ERROR_KEY_MAP: Record<string, keyof ListingDraftForm> = {
  title: 'title', description: 'description', rent_amount: 'rentAmount',
  security_deposit: 'securityDeposit', bedrooms: 'bedrooms', bathrooms: 'bathrooms',
  square_feet: 'squareFeet', pet_policy: 'petPolicy', lease_duration_months: 'leaseDurationMonths',
  move_in_date: 'moveInDate', available_from: 'availableFrom', amenities: 'amenities', unit_number: 'unitNumber',
};

function CloudIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M17.5 19a4.5 4.5 0 0 0 .5-9 6 6 0 0 0-11.6-1.5A4 4 0 0 0 6.5 19z" /><path d="m9 13 2 2 4-4" />
    </svg>
  );
}
const ArrowRight = () => <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>;
const ArrowLeft = () => <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6" /></svg>;
const HomeIcon = () => <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5" /><path d="M5 9.5V21h14V9.5" /><path d="M9 21v-6h6v6" /></svg>;

interface CreateListingProps {
  /** Pre-select a property/unit when arriving from a unit-scoped entry point
   * (e.g. /app/properties/:propertyId/units/:unitId/listings/new). Ignored
   * once the landlord has already picked a unit. */
  initialPropertyId?: number;
  initialUnitId?: number;
}

export function CreateListing({ initialPropertyId, initialUnitId }: CreateListingProps = {}) {
  const navigate = useNavigate();
  const { toast } = useToast();
  const { user } = useAuth();
  // AuthUser is a union (landlord User | Admin); only the User member carries
  // verification_status. Narrow with `in` so this stays type-safe.
  const isVerified = !!user && 'verification_status' in user && user.verification_status === 'verified';

  // ── data load ─────────────────────────────────────────────────────────────
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [properties, setProperties] = useState<Property[]>([]);
  const [units, setUnits] = useState<Unit[]>([]);
  const [listings, setListings] = useState<Listing[]>([]);

  useEffect(() => {
    let alive = true;
    (async () => {
      try {
        const [p, u, l] = await Promise.all([landlordApi.properties(), landlordApi.units(), landlordApi.listings()]);
        if (!alive) return;
        setProperties(p); setUnits(u); setListings(l);
      } catch {
        if (alive) setLoadError('We could not load your properties and units. Please try again.');
      } finally {
        if (alive) setLoading(false);
      }
    })();
    return () => { alive = false; };
  }, []);

  // ── eligibility (real) ──────────────────────────────────────────────────────
  const eligibleUnits = useMemo(() => {
    const taken = new Set(listings.filter((l) => BLOCKING_STATUSES.includes(l.status)).map((l) => l.unit_id));
    return units.filter((u) => !taken.has(u.id));
  }, [units, listings]);
  const eligibleProperties = useMemo(
    () => properties.filter((p) => eligibleUnits.some((u) => u.property_id === p.id)),
    [properties, eligibleUnits],
  );

  // ── flow state ──────────────────────────────────────────────────────────────
  const [step, setStep] = useState(1);
  const [reached, setReached] = useState(1);
  const [form, setForm] = useState<ListingDraftForm>(EMPTY_FORM);
  const [errors, setErrors] = useState<FormErrors>({});
  const [listingId, setListingId] = useState<number | null>(null);
  const [media, setMedia] = useState<MediaAsset[]>([]);
  const [mediaLoading, setMediaLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [draftSaved, setDraftSaved] = useState(false);

  const selectedProperty = properties.find((p) => p.id === form.propertyId) ?? null;

  const set = useCallback(<K extends keyof ListingDraftForm>(key: K, value: ListingDraftForm[K]) => {
    setForm((f) => ({ ...f, [key]: value }));
    setErrors((e) => ({ ...e, [key]: undefined }));
  }, []);

  function onSelectProperty(id: number | null) {
    setForm((f) => ({ ...f, propertyId: id, unitId: null }));
    setErrors((e) => ({ ...e, propertyId: undefined, unitId: undefined }));
  }

  /** Prefill from the REAL unit record — never invents values. */
  function onSelectUnit(id: number | null) {
    const u = units.find((x) => x.id === id);
    setErrors((e) => ({ ...e, unitId: undefined }));
    if (!u) { setForm((f) => ({ ...f, unitId: null })); return; }
    const beds = Math.max(0, Math.min(6, Math.round(parseFloat(u.bedrooms || '0'))));
    const baths = parseFloat(u.bathrooms || '0');
    const prop = properties.find((p) => p.id === u.property_id);
    setForm((f) => ({
      ...f,
      unitId: u.id,
      unitNumber: u.unit_number ?? f.unitNumber,
      bedrooms: String(beds),
      bathrooms: Number.isInteger(baths) ? String(baths) : String(baths),
      squareFeet: u.square_feet != null ? String(u.square_feet) : '',
      rentAmount: u.rent_amount && parseFloat(u.rent_amount) > 0 ? String(parseFloat(u.rent_amount)) : f.rentAmount,
      securityDeposit: u.security_deposit ? String(parseFloat(u.security_deposit)) : f.securityDeposit,
      availableFrom: u.available_from ? u.available_from.slice(0, 10) : f.availableFrom,
      amenities: Array.isArray(u.amenities) ? u.amenities : [],
      buildingNotes: prop?.description ?? f.buildingNotes,
    }));
  }

  // Pre-select property/unit for the unit-scoped entry point. Runs once the
  // real unit/property lists are loaded; a no-op if the ids don't resolve to
  // real, eligible records (never invents a selection).
  useEffect(() => {
    if (loading || form.unitId != null) return;
    if (initialUnitId != null) {
      const unit = eligibleUnits.find((u) => u.id === initialUnitId);
      if (unit) {
        onSelectProperty(unit.property_id);
        onSelectUnit(unit.id);
        return;
      }
    }
    if (initialPropertyId != null && eligibleProperties.some((p) => p.id === initialPropertyId)) {
      onSelectProperty(initialPropertyId);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [loading, initialPropertyId, initialUnitId, eligibleUnits, eligibleProperties]);

  // ── validation per step ─────────────────────────────────────────────────────
  function validate(s: number): FormErrors {
    const e: FormErrors = {};
    if (s === 1) {
      if (!form.propertyId) e.propertyId = 'Select a property.';
      if (!form.unitId) e.unitId = 'Select a unit.';
      if (!form.title.trim()) e.title = 'A unit title is required.';
      if (form.description.trim().length < 50) e.description = 'Add at least 50 characters.';
      if (!form.bedrooms) e.bedrooms = 'Select bedrooms.';
      if (!form.bathrooms) e.bathrooms = 'Select bathrooms.';
    }
    if (s === 3) {
      const rent = Number(form.rentAmount);
      if (!form.rentAmount || !Number.isFinite(rent) || rent <= 0) e.rentAmount = 'Enter a valid monthly rent.';
      if (form.securityDeposit && Number(form.securityDeposit) < 0) e.securityDeposit = 'Deposit cannot be negative.';
    }
    if (s === 4 && form.petsAllowed && !form.petPolicy.trim()) {
      e.petPolicy = 'Describe the pet policy.';
    }
    return e;
  }

  function mapBackendErrors(fe: Record<string, string>): FormErrors {
    const out: FormErrors = {};
    for (const [k, v] of Object.entries(fe)) {
      const mapped = ERROR_KEY_MAP[k];
      if (mapped) out[mapped] = v;
    }
    return out;
  }

  // ── persistence per step (real writes) ──────────────────────────────────────
  async function persist(): Promise<boolean> {
    const e = validate(step);
    if (Object.keys(e).length) { setErrors(e); return false; }
    if (!form.unitId) return false;

    setSaving(true);
    setDraftSaved(false);
    try {
      if (step === 1) {
        await landlordApi.updateUnit(form.unitId, {
          unit_number: form.unitNumber || undefined,
          bedrooms: form.bedrooms,
          bathrooms: form.bathrooms,
          square_feet: form.squareFeet ? Number(form.squareFeet) : null,
        } as Partial<Unit>);
        if (!listingId) {
          const created = await landlordApi.createListing(form.unitId, {
            title: form.title, description: form.description, pets_allowed: form.petsAllowed,
          } as Partial<Listing>);
          setListingId(created.id);
        } else {
          await landlordApi.updateListing(listingId, { title: form.title, description: form.description } as Partial<Listing>);
        }
      } else if (step === 2) {
        if (form.propertyId && (form.buildingNotes || '') !== (selectedProperty?.description ?? '')) {
          await landlordApi.updateProperty(form.propertyId, { description: form.buildingNotes || null } as Partial<Property>);
        }
      } else if (step === 3) {
        await landlordApi.updateUnit(form.unitId, {
          rent_amount: form.rentAmount,
          security_deposit: form.securityDeposit || null,
          available_from: form.availableFrom || null,
        } as Partial<Unit>);
        if (listingId) {
          await landlordApi.updateListing(listingId, {
            lease_duration_months: form.leaseDurationMonths ? Number(form.leaseDurationMonths) : null,
            move_in_date: form.moveInDate || null,
          } as Partial<Listing>);
        }
      } else if (step === 4) {
        await landlordApi.updateUnit(form.unitId, { amenities: form.amenities } as Partial<Unit>);
        if (listingId) {
          await landlordApi.updateListing(listingId, {
            pets_allowed: form.petsAllowed,
            pet_policy: form.petsAllowed ? form.petPolicy : null,
          } as Partial<Listing>);
        }
      }
      // step 5 (photos) persists live via GalleryManager; nothing to write here.
      setDraftSaved(true);
      return true;
    } catch (err) {
      const apiErr = err as ApiError;
      const mapped = mapBackendErrors(fieldErrors(apiErr));
      if (Object.keys(mapped).length) setErrors(mapped);
      toast(apiErr?.message || 'Could not save your changes. Please review and try again.', 'error');
      return false;
    } finally {
      setSaving(false);
    }
  }

  async function refetchMedia() {
    if (!listingId) return;
    setMediaLoading(true);
    try {
      const fresh = await landlordApi.listing(listingId);
      setMedia(fresh.media_assets ?? []);
    } catch {
      /* non-fatal */
    } finally {
      setMediaLoading(false);
    }
  }

  async function goNext() {
    const ok = await persist();
    if (!ok) return;
    const n = Math.min(STEPS.length, step + 1);
    setStep(n);
    setReached((r) => Math.max(r, n));
    if (n === 5) refetchMedia();
  }

  function goBack() {
    setStep((s) => Math.max(1, s - 1));
    setErrors({});
  }

  function jumpTo(target: number) {
    if (target <= reached) { setStep(target); setErrors({}); if (target === 5) refetchMedia(); }
  }

  function leave() { navigate('/app/listings'); }

  async function submit() {
    if (!listingId) return;
    if (!isVerified) { toast('Verify your identity before submitting a listing for review.', 'error'); return; }
    setSaving(true);
    try {
      await landlordApi.submitListing(listingId);
      toast('Listing submitted for review', 'success');
      navigate('/app/listings');
    } catch (err) {
      const apiErr = err as ApiError;
      toast(apiErr?.message || 'Could not submit the listing.', 'error');
    } finally {
      setSaving(false);
    }
  }

  function saveDraftAndExit() {
    toast(listingId ? 'Draft saved' : 'Nothing to save yet', listingId ? 'success' : 'info');
    navigate('/app/listings');
  }

  // review warnings (truthful, derived from the live form/draft)
  const warnings = useMemo(() => {
    const w: string[] = [];
    if (!form.title.trim()) w.push('The listing needs a title.');
    if (form.description.trim().length < 50) w.push('The description should be at least 50 characters.');
    if (!form.rentAmount || Number(form.rentAmount) <= 0) w.push('Set a valid monthly rent.');
    if (media.length === 0) w.push('Listings with photos attract more applicants (optional).');
    return w;
  }, [form, media.length]);

  const meta = STEPS[step - 1];

  // ── render ──────────────────────────────────────────────────────────────────
  if (loading) {
    return (
      <div className="cl-page">
        <div className="cl-skeleton"><div className="cl-skel-bar" style={{ width: '40%' }} /><div className="cl-skel-bar" style={{ width: '70%' }} /></div>
        <div className="cl-skeleton" style={{ minHeight: 300 }}><div className="cl-skel-bar" style={{ width: '30%' }} /><div className="cl-skel-bar" /><div className="cl-skel-bar" style={{ width: '85%' }} /></div>
      </div>
    );
  }

  if (loadError) {
    return (
      <div className="cl-page">
        <CreateListingHeader onClose={leave} />
        <div className="cl-empty">
          <div className="cl-empty-ico"><HomeIcon /></div>
          <h3>Something went wrong</h3>
          <p>{loadError}</p>
          <div className="cl-empty-actions">
            <Button onClick={() => window.location.reload()}>Try again</Button>
            <Button variant="secondary" onClick={leave}>Back to listings</Button>
          </div>
        </div>
      </div>
    );
  }

  // Empty state — no eligible units to list.
  if (eligibleUnits.length === 0) {
    return (
      <div className="cl-page">
        <CreateListingHeader onClose={leave} />
        <div className="cl-empty">
          <div className="cl-empty-ico"><HomeIcon /></div>
          <h3>No units available to list</h3>
          <p>Every unit already has a live listing. Add another unit to a property or edit an existing listing instead.</p>
          <div className="cl-empty-actions">
            <Link to="/app/properties"><Button>Go to Properties</Button></Link>
            <Link to="/app/listings"><Button variant="secondary">View existing listings</Button></Link>
          </div>
        </div>
      </div>
    );
  }

  const isLast = step === STEPS.length;

  return (
    <div className="cl-page">
      <CreateListingHeader onClose={leave} />

      <div className="cl-body">
        <CreateListingStepper current={step} reached={reached} onJump={jumpTo} />

        <section className="cl-panel" aria-labelledby="cl-step-title">
          <div className="cl-panel-head">
            <h2 className="cl-panel-title" id="cl-step-title">{meta.name}</h2>
            <p className="cl-panel-sub">{meta.desc}</p>
          </div>

          <div className="cl-panel-body">
            {step === 1 && (
              <UnitDetailsStep
                form={form} set={set} errors={errors}
                properties={eligibleProperties}
                eligibleUnits={eligibleUnits}
                onSelectProperty={onSelectProperty}
                onSelectUnit={onSelectUnit}
              />
            )}
            {step === 2 && <PropertyDetailsStep form={form} set={set} errors={errors} property={selectedProperty} />}
            {step === 3 && <PricingStep form={form} set={set} errors={errors} />}
            {step === 4 && <AmenitiesStep form={form} set={set} errors={errors} />}
            {step === 5 && <PhotosStep listingId={listingId} media={media} loading={mediaLoading} onRefetch={refetchMedia} />}
            {step === 6 && <ReviewPublishStep form={form} property={selectedProperty} media={media} isVerified={!!isVerified} warnings={warnings} />}
          </div>

          <footer className="cl-footer">
            <span className={`cl-draft${saving ? ' saving' : ''}`}>
              <CloudIcon />
              {saving ? 'Saving…' : draftSaved ? 'Draft saved' : listingId ? 'Draft in progress' : 'Not saved yet'}
            </span>

            <div className="cl-footer-actions">
              <Button variant="secondary" onClick={leave} disabled={saving}>Cancel</Button>
              {step > 1 && <Button variant="secondary" leftIcon={<ArrowLeft />} onClick={goBack} disabled={saving}>Back</Button>}
              {!isLast && (
                <Button rightIcon={<ArrowRight />} loading={saving} onClick={goNext}>Save &amp; continue</Button>
              )}
              {isLast && (
                <>
                  <Button variant="secondary" onClick={saveDraftAndExit} disabled={saving}>Save draft</Button>
                  <Button loading={saving} disabled={!isVerified} onClick={submit}>Submit for review</Button>
                </>
              )}
            </div>
          </footer>
        </section>
      </div>
    </div>
  );
}
