/**
 * AddPropertyPage — dedicated FULL PAGE for landlord property creation
 * (/app/properties/new). Replaces the old PropertyFormDrawer two-step side
 * drawer: a property connects to units, listings, tenants, rent, media, and
 * admin review, so it deserves a spacious, guided page — not a popup.
 *
 * TRUTHFULNESS:
 *  - The real property record is created (POST /landlord/properties) the
 *    moment Basics + Location are valid, before the Units/Photos/Amenities
 *    steps — every later step writes to that real id, exactly like
 *    CreateListing.tsx's per-step persistence. There is no client-only
 *    "draft" that vanishes on refresh.
 *  - Units are created immediately via POST /landlord/properties/{id}/units
 *    as the landlord adds them — never held in local-only state.
 *  - Photos use the real media_assets gallery (GalleryManager) — no fake
 *    thumbnails.
 *  - Amenities/rules persist to the real Property.amenities/rules columns.
 *  - Verification shows the landlord's REAL identity verification status.
 *    There is no property-level verification workflow in this domain (only
 *    listings go through admin review) — this step never invents one.
 *  - Properties have no separate "submit for approval" step in this domain;
 *    "Review & Submit" is a truthful summary + confirmation, not a fake
 *    admin gate. The Review label matches the request; what actually differs
 *    from an ordinary Property is nothing — its data is real from step 2 on.
 */
import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router';
import { landlordApi } from '@/lib/endpoints';
import { fieldErrors } from '@/lib/api';
import { useAuth } from '@/context/auth';
import { useToast } from '@/components/ui/toast';
import { Button } from '@/components/ui/Button';
import { Field, Input, Select, Textarea } from '@/components/ui/Field';
import { StepIndicator } from '@/components/ui/StepIndicator';
import { GalleryManager } from '@/components/media/GalleryManager';
import { UnitFormFields } from '@/components/landlord/UnitFormFields';
import {
  type UnitForm,
  emptyUnitForm,
  unitPayloadFromForm,
  validateUnitForm,
} from '@/components/landlord/unit-form-shared';
import {
  type PropertyForm,
  PROPERTY_FIELD_STEP,
  emptyPropertyForm,
  propertyPayloadFromForm,
  validatePropertyBasics,
  validatePropertyLocation,
} from './property-form-shared';
import {
  ADDRESS_VISIBILITY_OPTIONS,
  AMENITY_CATEGORIES,
  PROPERTY_TYPES,
  RESPONSIBILITY_OPTIONS,
} from './property-constants';
import type { ApiError, MediaAsset, Property, PropertyAmenity, PropertyRules, Unit } from '@/lib/types';
import { humanize } from '@/lib/format';
import {
  IconAlertTriangle,
  IconArrowLeft,
  IconArrowRight,
  IconCheckCircle,
  IconChevronRight,
  IconHome,
  IconPlus,
  IconShield,
} from '@/components/ui/icons';
import './add-property.css';

const STEP_LABELS = ['Basics', 'Location', 'Units', 'Photos', 'Amenities & rules', 'Verification', 'Review'];

type OwnershipType = 'owner' | 'manager' | 'company' | 'other';

const OWNERSHIP_OPTIONS: { value: OwnershipType; label: string }[] = [
  { value: 'owner', label: 'I own this property' },
  { value: 'manager', label: 'I manage this property for the owner' },
  { value: 'company', label: 'I represent a company' },
  { value: 'other', label: 'Other' },
];

export function AddPropertyPage() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const { user } = useAuth();
  const isVerified = !!user && 'verification_status' in user && user.verification_status === 'verified';

  const [step, setStep] = useState(0);
  const [reached, setReached] = useState(0);
  const [saving, setSaving] = useState(false);

  // Basics + Location (client-only until Location is confirmed, then real).
  const [form, setForm] = useState<PropertyForm>(emptyPropertyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [ownership, setOwnership] = useState<OwnershipType | ''>('');

  // The real property record, once created.
  const [property, setProperty] = useState<Property | null>(null);
  const propertyId = property?.id ?? null;

  // Units — always real rows, added immediately.
  const [units, setUnits] = useState<Unit[]>([]);
  const [addingUnit, setAddingUnit] = useState(false);
  const [unitForm, setUnitForm] = useState<UnitForm>(emptyUnitForm);
  const [unitErrors, setUnitErrors] = useState<Record<string, string>>({});
  const [unitSaving, setUnitSaving] = useState(false);

  // Photos
  const [media, setMedia] = useState<MediaAsset[]>([]);
  const [mediaLoading, setMediaLoading] = useState(false);

  // Amenities & rules
  const [amenities, setAmenities] = useState<PropertyAmenity[]>([]);
  const [rules, setRules] = useState<PropertyRules>({});

  function updateForm<K extends keyof PropertyForm>(key: K, value: PropertyForm[K]) {
    setForm((prev) => ({ ...prev, [key]: value }));
    setErrors((prev) => (prev[key] ? { ...prev, [key]: '' } : prev));
  }

  function advance(n: number) {
    setStep(n);
    setReached((r) => Math.max(r, n));
  }

  function goBack() {
    setStep((s) => Math.max(0, s - 1));
  }

  function jumpTo(n: number) {
    if (n <= reached) setStep(n);
  }

  async function refetchMedia() {
    if (!propertyId) return;
    setMediaLoading(true);
    try {
      const fresh = await landlordApi.property(propertyId);
      setMedia(fresh.media_assets ?? []);
      setProperty(fresh);
    } catch {
      /* non-fatal */
    } finally {
      setMediaLoading(false);
    }
  }

  // Refresh from the server whenever Photos or Review are visited, so those
  // steps always reflect what's really been saved (not stale local state).
  useEffect(() => {
    if (!propertyId) return;
    if (step === 3) void refetchMedia();
    if (step === 6) void refetchMedia();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [step, propertyId]);

  async function persistBasicsAndLocation(): Promise<boolean> {
    setSaving(true);
    const payload = propertyPayloadFromForm(form);
    try {
      if (property) {
        const updated = await landlordApi.updateProperty(property.id, payload);
        setProperty(updated);
      } else {
        const created = await landlordApi.createProperty(payload);
        setProperty(created);
      }
      return true;
    } catch (err) {
      const e2 = err as ApiError;
      const fe = fieldErrors(e2);
      if (Object.keys(fe).length > 0) {
        setErrors(fe);
        const firstStep = Object.keys(fe).some((k) => PROPERTY_FIELD_STEP[k] === 0) ? 0 : 1;
        setStep(firstStep);
      } else {
        toast(e2.message, 'error');
      }
      return false;
    } finally {
      setSaving(false);
    }
  }

  async function persistAmenitiesAndRules(): Promise<boolean> {
    if (!propertyId) return true;
    setSaving(true);
    try {
      const updated = await landlordApi.updateProperty(propertyId, { amenities, rules });
      setProperty(updated);
      return true;
    } catch (err) {
      toast((err as ApiError).message, 'error');
      return false;
    } finally {
      setSaving(false);
    }
  }

  async function goNext() {
    if (step === 0) {
      const e = validatePropertyBasics(form);
      setErrors(e);
      if (Object.keys(e).length > 0) return;
      advance(1);
      return;
    }
    if (step === 1) {
      const e = validatePropertyLocation(form);
      if (Object.keys(e).length > 0) {
        setErrors(e);
        return;
      }
      setErrors({});
      if (await persistBasicsAndLocation()) advance(2);
      return;
    }
    if (step === 2) {
      advance(3);
      return;
    }
    if (step === 3) {
      advance(4);
      return;
    }
    if (step === 4) {
      if (await persistAmenitiesAndRules()) advance(5);
      return;
    }
    if (step === 5) {
      advance(6);
      return;
    }
  }

  function openAddUnit() {
    setUnitForm(emptyUnitForm());
    setUnitErrors({});
    setAddingUnit(true);
  }

  async function submitUnit(ev: React.FormEvent) {
    ev.preventDefault();
    if (!propertyId) return;
    const v = validateUnitForm(unitForm);
    if (Object.keys(v).length > 0) {
      setUnitErrors(v);
      return;
    }
    setUnitSaving(true);
    try {
      const created = await landlordApi.createUnit(propertyId, unitPayloadFromForm(unitForm));
      setUnits((prev) => [...prev, created]);
      setAddingUnit(false);
      toast('Unit added', 'success');
    } catch (err) {
      const e2 = err as ApiError;
      const fe = fieldErrors(e2);
      if (Object.keys(fe).length > 0) setUnitErrors(fe);
      else toast(e2.message, 'error');
    } finally {
      setUnitSaving(false);
    }
  }

  function toggleAmenity(a: PropertyAmenity) {
    setAmenities((prev) => (prev.includes(a) ? prev.filter((x) => x !== a) : [...prev, a]));
  }

  function setRule<K extends keyof PropertyRules>(key: K, value: PropertyRules[K]) {
    setRules((prev) => ({ ...prev, [key]: value }));
  }

  function exit() {
    navigate(propertyId ? `/app/properties/${propertyId}` : '/app/properties');
  }

  const occupiedCount = units.filter((u) => u.availability_status === 'occupied').length;
  const vacantCount = units.length - occupiedCount;

  const reviewWarnings: string[] = [];
  if (units.length === 0) reviewWarnings.push('No units added yet — a property needs at least one unit before it can be listed.');
  if (media.length === 0) reviewWarnings.push('No photos yet — listings usually require photos before admin review.');
  if (!isVerified) reviewWarnings.push('Your identity is not verified yet — this is required before submitting a listing for review.');

  const firstVacantUnit = units.find((u) => u.availability_status === 'available' || u.availability_status === 'pending');

  return (
    <div className="wap-page animate-rise">
      <div>
        <nav aria-label="Breadcrumb" className="wap-breadcrumb">
          <span>Portfolio</span>
          <IconChevronRight className="h-3 w-3" />
          <Link to="/app/properties">Properties</Link>
          <IconChevronRight className="h-3 w-3" />
          <span className="font-medium text-ink-600">Add property</span>
        </nav>
        <Link
          to="/app/properties"
          className="mt-2 inline-flex items-center gap-1 text-sm font-medium text-ink-500 transition hover:text-ink-800"
        >
          <IconChevronRight className="h-4 w-4 rotate-180" />
          Back to properties
        </Link>
      </div>

      <div>
        <h1 className="font-display text-2xl font-semibold text-ink-950">Add property</h1>
        <p className="mt-1 text-sm text-ink-500">
          Create a property record, add units, and prepare it for listings.
        </p>
      </div>

      <div className="wap-card">
        <div className="wap-steps">
          <StepIndicator steps={STEP_LABELS} current={step} onStepClick={jumpTo} />
        </div>

        {step === 0 && (
          <BasicsStep form={form} errors={errors} onChange={updateForm} ownership={ownership} setOwnership={setOwnership} />
        )}
        {step === 1 && <LocationStep form={form} errors={errors} onChange={updateForm} />}
        {step === 2 && (
          <UnitsStep
            units={units}
            addingUnit={addingUnit}
            unitForm={unitForm}
            unitErrors={unitErrors}
            unitSaving={unitSaving}
            onOpenAdd={openAddUnit}
            onCancelAdd={() => setAddingUnit(false)}
            onUnitFormChange={(key, value) => {
              setUnitForm((prev) => ({ ...prev, [key]: value }));
              setUnitErrors((prev) => (prev[key] ? { ...prev, [key]: '' } : prev));
            }}
            onSubmitUnit={submitUnit}
          />
        )}
        {step === 3 && propertyId && (
          <div className="wap-form">
            <div className="wap-step-head">
              <h2 className="wap-step-title">Photos</h2>
              <p className="wap-step-sub">
                Upload exterior, interior, and amenity photos. You can save this property without
                photos, but listings usually require photos before admin review.
              </p>
            </div>
            <GalleryManager
              target={{ type: 'property', id: propertyId }}
              items={media}
              onRefetch={refetchMedia}
              loading={mediaLoading}
            />
          </div>
        )}
        {step === 4 && (
          <AmenitiesRulesStep amenities={amenities} onToggleAmenity={toggleAmenity} rules={rules} onSetRule={setRule} />
        )}
        {step === 5 && <VerificationStep isVerified={isVerified} />}
        {step === 6 && property && (
          <ReviewStep
            property={property}
            units={units}
            media={media}
            amenities={amenities}
            rules={rules}
            warnings={reviewWarnings}
            occupiedCount={occupiedCount}
            vacantCount={vacantCount}
          />
        )}

        <footer className="wap-footer">
          <span className={`wap-status${saving ? ' saving' : ''}`}>
            {saving ? 'Saving…' : propertyId ? 'Saved' : 'Not saved yet'}
          </span>

          {step < 6 ? (
            <div className="wap-footer-actions">
              <Button variant="secondary" onClick={exit} disabled={saving}>
                Cancel
              </Button>
              {step > 0 && (
                <Button variant="secondary" leftIcon={<IconArrowLeft size={16} />} onClick={goBack} disabled={saving}>
                  Back
                </Button>
              )}
              <Button rightIcon={<IconArrowRight size={16} />} loading={saving} onClick={goNext}>
                {step === 2 && units.length === 0 ? 'Skip for now' : 'Continue'}
              </Button>
            </div>
          ) : (
            <div className="wap-footer-actions">
              <Button variant="secondary" leftIcon={<IconArrowLeft size={16} />} onClick={goBack}>
                Back
              </Button>
              <Button variant="secondary" onClick={() => advance(2)}>
                Add another unit
              </Button>
              {firstVacantUnit && (
                <Button
                  variant="secondary"
                  onClick={() =>
                    navigate(`/app/properties/${propertyId}/units/${firstVacantUnit.id}/listings/new`)
                  }
                >
                  Create listing
                </Button>
              )}
              <Button
                leftIcon={<IconCheckCircle size={16} />}
                onClick={() => navigate(`/app/properties/${propertyId}`)}
              >
                Finish
              </Button>
            </div>
          )}
        </footer>
      </div>
    </div>
  );
}

/* ── Step 1: Basics ─────────────────────────────────────────────────────── */

function BasicsStep({
  form,
  errors,
  onChange,
  ownership,
  setOwnership,
}: {
  form: PropertyForm;
  errors: Record<string, string>;
  onChange: <K extends keyof PropertyForm>(key: K, value: PropertyForm[K]) => void;
  ownership: OwnershipType | '';
  setOwnership: (v: OwnershipType) => void;
}) {
  return (
    <div className="wap-form">
      <div className="wap-step-head">
        <h2 className="wap-step-title">Property basics</h2>
        <p className="wap-step-sub">What is this property, and what kind of place is it?</p>
      </div>

      <Field label="Property name" error={errors.name} required>
        {(id, invalid) => (
          <Input
            id={id}
            invalid={invalid}
            autoFocus
            placeholder="e.g. East Legon Heights"
            value={form.name}
            onChange={(e) => onChange('name', e.target.value)}
          />
        )}
      </Field>

      <Field label="Property type" error={errors.property_type} required>
        {(id, invalid) => (
          <Select
            id={id}
            invalid={invalid}
            value={form.property_type}
            onChange={(e) => onChange('property_type', e.target.value as PropertyForm['property_type'])}
          >
            <option value="" disabled>
              Select property type
            </option>
            {PROPERTY_TYPES.map((t) => (
              <option key={t} value={t}>
                {humanize(t)}
              </option>
            ))}
          </Select>
        )}
      </Field>

      <Field label="Ownership / management" hint="Helps set the right verification expectations later — not saved as a separate field.">
        {() => (
          <div className="wap-radio-group" role="radiogroup" aria-label="Ownership / management">
            {OWNERSHIP_OPTIONS.map((o) => (
              <label key={o.value} className={`wap-radio-card${ownership === o.value ? ' on' : ''}`}>
                <input
                  type="radio"
                  name="ownership"
                  value={o.value}
                  checked={ownership === o.value}
                  onChange={() => setOwnership(o.value)}
                />
                <span className="wap-radio-label">{o.label}</span>
              </label>
            ))}
          </div>
        )}
      </Field>

      <Field label="Year built" error={errors.year_built} hint="Optional">
        {(id, invalid) => (
          <Input
            id={id}
            type="number"
            inputMode="numeric"
            invalid={invalid}
            placeholder="e.g. 2019"
            value={form.year_built}
            onChange={(e) => onChange('year_built', e.target.value)}
          />
        )}
      </Field>

      <Field label="Description" error={errors.description} hint="Optional">
        {(id, invalid) => (
          <Textarea
            id={id}
            invalid={invalid}
            rows={4}
            placeholder="Brief description of the property…"
            value={form.description}
            onChange={(e) => onChange('description', e.target.value)}
          />
        )}
      </Field>

      <Field label="Parking" error={errors.parking} hint="Optional">
        {(id, invalid) => (
          <Input
            id={id}
            invalid={invalid}
            placeholder="e.g. 1 covered space per unit"
            value={form.parking}
            onChange={(e) => onChange('parking', e.target.value)}
          />
        )}
      </Field>

      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Pet policy" error={errors.pet_policy} hint="Optional">
          {(id, invalid) => (
            <Input
              id={id}
              invalid={invalid}
              placeholder="e.g. Cats and small dogs allowed"
              value={form.pet_policy}
              onChange={(e) => onChange('pet_policy', e.target.value)}
            />
          )}
        </Field>
        <Field label="Smoking policy" error={errors.smoking_policy} hint="Optional">
          {(id, invalid) => (
            <Input
              id={id}
              invalid={invalid}
              placeholder="e.g. No smoking indoors"
              value={form.smoking_policy}
              onChange={(e) => onChange('smoking_policy', e.target.value)}
            />
          )}
        </Field>
      </div>
    </div>
  );
}

/* ── Step 2: Location ───────────────────────────────────────────────────── */

function LocationStep({
  form,
  errors,
  onChange,
}: {
  form: PropertyForm;
  errors: Record<string, string>;
  onChange: <K extends keyof PropertyForm>(key: K, value: PropertyForm[K]) => void;
}) {
  return (
    <div className="wap-form">
      <div className="wap-step-head">
        <h2 className="wap-step-title">Location</h2>
        <p className="wap-step-sub">The full address is always visible to you and to admins. Choose what tenants see.</p>
      </div>

      <Field label="Street address" error={errors.street_address} required>
        {(id, invalid) => (
          <Input
            id={id}
            invalid={invalid}
            autoFocus
            placeholder="e.g. 14 Boundary Road"
            value={form.street_address}
            onChange={(e) => onChange('street_address', e.target.value)}
          />
        )}
      </Field>

      <Field label="Street address 2 / Estate / Area" error={errors.street_address_2} hint="Optional">
        {(id, invalid) => (
          <Input
            id={id}
            invalid={invalid}
            placeholder="e.g. East Legon"
            value={form.street_address_2}
            onChange={(e) => onChange('street_address_2', e.target.value)}
          />
        )}
      </Field>

      <div className="wap-grid">
        <Field label="City" error={errors.city} required>
          {(id, invalid) => (
            <Input id={id} invalid={invalid} value={form.city} onChange={(e) => onChange('city', e.target.value)} />
          )}
        </Field>
        <Field label="Region / State" error={errors.state} required>
          {(id, invalid) => (
            <Input
              id={id}
              invalid={invalid}
              placeholder="2-letter code, e.g. GA"
              value={form.state}
              onChange={(e) => onChange('state', e.target.value.toUpperCase())}
            />
          )}
        </Field>
      </div>

      <div className="wap-grid">
        <Field label="Digital address / Postcode" error={errors.zip_code} required>
          {(id, invalid) => (
            <Input
              id={id}
              invalid={invalid}
              placeholder="GA-123-4567"
              value={form.zip_code}
              onChange={(e) => onChange('zip_code', e.target.value)}
            />
          )}
        </Field>
        <Field label="Country" error={errors.country} required>
          {(id, invalid) => (
            <Input
              id={id}
              invalid={invalid}
              placeholder="2-letter code, e.g. GH"
              value={form.country}
              onChange={(e) => onChange('country', e.target.value.toUpperCase())}
            />
          )}
        </Field>
      </div>

      <Field
        label="Address visibility"
        error={errors.address_visibility}
        required
        hint={ADDRESS_VISIBILITY_OPTIONS.find((o) => o.value === form.address_visibility)?.hint}
      >
        {(id, invalid) => (
          <Select
            id={id}
            invalid={invalid}
            value={form.address_visibility}
            onChange={(e) => onChange('address_visibility', e.target.value as PropertyForm['address_visibility'])}
          >
            {ADDRESS_VISIBILITY_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        )}
      </Field>
    </div>
  );
}

/* ── Step 3: Units ──────────────────────────────────────────────────────── */

function UnitsStep({
  units,
  addingUnit,
  unitForm,
  unitErrors,
  unitSaving,
  onOpenAdd,
  onCancelAdd,
  onUnitFormChange,
  onSubmitUnit,
}: {
  units: Unit[];
  addingUnit: boolean;
  unitForm: UnitForm;
  unitErrors: Record<string, string>;
  unitSaving: boolean;
  onOpenAdd: () => void;
  onCancelAdd: () => void;
  onUnitFormChange: <K extends keyof UnitForm>(key: K, value: UnitForm[K]) => void;
  onSubmitUnit: (e: React.FormEvent) => void;
}) {
  const occupied = units.filter((u) => u.availability_status === 'occupied').length;
  const vacant = units.length - occupied;

  return (
    <div className="wap-form">
      <div className="wap-step-head">
        <h2 className="wap-step-title">Units</h2>
        <p className="wap-step-sub">
          Add the rentable spaces in this property now, or skip and add them later from the property
          page. Each unit is saved immediately.
        </p>
      </div>

      {units.length > 0 && (
        <>
          <p className="text-sm font-medium text-ink-700">
            {units.length} {units.length === 1 ? 'unit' : 'units'}: {occupied} occupied, {vacant} vacant
          </p>
          <div className="wap-unit-list">
            {units.map((u) => (
              <div key={u.id} className="wap-unit-card">
                <div>
                  <p className="wap-unit-name">
                    {u.unit_number}
                    {u.internal_name ? ` (${u.internal_name})` : ''}
                  </p>
                  <p className="wap-unit-meta">
                    {u.bedrooms} bed · {u.bathrooms} bath · {humanize(u.availability_status)}
                  </p>
                </div>
              </div>
            ))}
          </div>
        </>
      )}

      {addingUnit ? (
        <form className="wap-add-unit-form" onSubmit={onSubmitUnit}>
          <UnitFormFields form={unitForm} errors={unitErrors} onChange={onUnitFormChange} />
          <div className="mt-4 flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={onCancelAdd} disabled={unitSaving}>
              Cancel
            </Button>
            <Button type="submit" loading={unitSaving}>
              Save unit
            </Button>
          </div>
        </form>
      ) : (
        <Button variant="secondary" leftIcon={<IconPlus size={16} />} onClick={onOpenAdd}>
          Add unit
        </Button>
      )}
    </div>
  );
}

/* ── Step 5: Amenities & rules ──────────────────────────────────────────── */

function AmenitiesRulesStep({
  amenities,
  onToggleAmenity,
  rules,
  onSetRule,
}: {
  amenities: PropertyAmenity[];
  onToggleAmenity: (a: PropertyAmenity) => void;
  rules: PropertyRules;
  onSetRule: <K extends keyof PropertyRules>(key: K, value: PropertyRules[K]) => void;
}) {
  return (
    <div className="wap-form">
      <div className="wap-step-head">
        <h2 className="wap-step-title">Amenities &amp; rules</h2>
        <p className="wap-step-sub">
          Building-level amenities and house rules. Structured so tenants can search and filter later.
        </p>
      </div>

      {AMENITY_CATEGORIES.map((cat) => (
        <div key={cat.key} className="wap-amenity-group">
          <span className="wap-amenity-group-label">{cat.label}</span>
          <div className="wap-chips" role="group" aria-label={cat.label}>
            {cat.options.map((o) => {
              const on = amenities.includes(o.value);
              return (
                <button
                  key={o.value}
                  type="button"
                  className={`wap-chip${on ? ' on' : ''}`}
                  aria-pressed={on}
                  onClick={() => onToggleAmenity(o.value)}
                >
                  <span className="wap-chip-check">{on && <IconCheckCircle size={11} />}</span>
                  {o.label}
                </button>
              );
            })}
          </div>
        </div>
      ))}

      <p className="wap-amenity-group-label">Rules &amp; policies</p>

      <div className="wap-toggle-row">
        <span className="text-sm font-medium text-ink-800">Pets allowed</span>
        <button
          type="button"
          role="switch"
          aria-checked={!!rules.pets_allowed}
          aria-label="Pets allowed"
          className={`wap-toggle${rules.pets_allowed ? ' on' : ''}`}
          onClick={() => onSetRule('pets_allowed', !rules.pets_allowed)}
        />
      </div>
      <div className="wap-toggle-row">
        <span className="text-sm font-medium text-ink-800">Smoking allowed</span>
        <button
          type="button"
          role="switch"
          aria-checked={!!rules.smoking_allowed}
          aria-label="Smoking allowed"
          className={`wap-toggle${rules.smoking_allowed ? ' on' : ''}`}
          onClick={() => onSetRule('smoking_allowed', !rules.smoking_allowed)}
        />
      </div>
      <div className="wap-toggle-row">
        <span className="text-sm font-medium text-ink-800">Guests allowed</span>
        <button
          type="button"
          role="switch"
          aria-checked={!!rules.guests_allowed}
          aria-label="Guests allowed"
          className={`wap-toggle${rules.guests_allowed ? ' on' : ''}`}
          onClick={() => onSetRule('guests_allowed', !rules.guests_allowed)}
        />
      </div>

      <div className="wap-grid">
        <Field label="Maximum occupants" hint="Optional">
          {(id) => (
            <Input
              id={id}
              type="number"
              min="1"
              value={rules.max_occupants ?? ''}
              onChange={(e) => onSetRule('max_occupants', e.target.value ? Number(e.target.value) : null)}
            />
          )}
        </Field>
        <Field label="Minimum lease (months)" hint="Optional">
          {(id) => (
            <Input
              id={id}
              type="number"
              min="1"
              value={rules.min_lease_months ?? ''}
              onChange={(e) => onSetRule('min_lease_months', e.target.value ? Number(e.target.value) : null)}
            />
          )}
        </Field>
      </div>

      <Field label="Quiet hours" hint="Optional, e.g. 10pm - 6am">
        {(id) => (
          <Input
            id={id}
            value={rules.quiet_hours ?? ''}
            onChange={(e) => onSetRule('quiet_hours', e.target.value || null)}
          />
        )}
      </Field>

      <div className="wap-grid">
        <Field label="Utility responsibility" hint="Optional">
          {(id) => (
            <Select
              id={id}
              value={rules.utility_responsibility ?? ''}
              onChange={(e) => onSetRule('utility_responsibility', (e.target.value || null) as PropertyRules['utility_responsibility'])}
            >
              <option value="">Not specified</option>
              {RESPONSIBILITY_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label="Maintenance responsibility" hint="Optional">
          {(id) => (
            <Select
              id={id}
              value={rules.maintenance_responsibility ?? ''}
              onChange={(e) => onSetRule('maintenance_responsibility', (e.target.value || null) as PropertyRules['maintenance_responsibility'])}
            >
              <option value="">Not specified</option>
              {RESPONSIBILITY_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          )}
        </Field>
      </div>
    </div>
  );
}

/* ── Step 6: Verification ───────────────────────────────────────────────── */

function VerificationStep({ isVerified }: { isVerified: boolean }) {
  return (
    <div className="wap-form">
      <div className="wap-step-head">
        <h2 className="wap-step-title">Verification</h2>
        <p className="wap-step-sub">
          Wyncrest does not yet have a separate property-verification workflow — trust is based on
          your own identity verification.
        </p>
      </div>

      {isVerified ? (
        <div className="wap-banner wap-banner-info">
          <IconShield size={16} className="mt-0.5 shrink-0" />
          <span>Your identity is verified. This property can be used to submit listings for admin review.</span>
        </div>
      ) : (
        <div className="wap-banner wap-banner-warning">
          <IconAlertTriangle size={16} className="mt-0.5 shrink-0" />
          <span>
            Your identity verification may be required before this property can be listed publicly. This
            property can be saved as-is; verify your identity before submitting a listing for review.{' '}
            <Link to="/app/landlord-verification" className="underline">
              Go to verification
            </Link>
            .
          </span>
        </div>
      )}
    </div>
  );
}

/* ── Step 7: Review & Submit ────────────────────────────────────────────── */

function ReviewStep({
  property,
  units,
  media,
  amenities,
  rules,
  warnings,
  occupiedCount,
  vacantCount,
}: {
  property: Property;
  units: Unit[];
  media: MediaAsset[];
  amenities: PropertyAmenity[];
  rules: PropertyRules;
  warnings: string[];
  occupiedCount: number;
  vacantCount: number;
}) {
  const ruleEntries = Object.entries(rules).filter(([, v]) => v !== undefined && v !== null && v !== '');

  return (
    <div className="wap-review">
      <div className="wap-step-head">
        <h2 className="wap-step-title">Review &amp; submit</h2>
        <p className="wap-step-sub">Everything below is already saved to your account.</p>
      </div>

      {warnings.length > 0 && (
        <div className="wap-banner wap-banner-warning">
          <IconAlertTriangle size={16} className="mt-0.5 shrink-0" />
          <div>
            <span>Before this property is ready for a public listing:</span>
            <ul>
              {warnings.map((w) => (
                <li key={w}>{w}</li>
              ))}
            </ul>
          </div>
        </div>
      )}

      <div className="wap-summary-section">
        <div className="wap-summary-section-title">Property</div>
        <div className="wap-summary-row">
          <span className="wap-summary-label">Name</span>
          <span className="wap-summary-value">{property.name}</span>
        </div>
        <div className="wap-summary-row">
          <span className="wap-summary-label">Type</span>
          <span className="wap-summary-value">{humanize(property.property_type)}</span>
        </div>
        <div className="wap-summary-row">
          <span className="wap-summary-label">Address</span>
          <span className="wap-summary-value">
            {property.street_address}, {property.city}, {property.state}
          </span>
        </div>
        <div className="wap-summary-row">
          <span className="wap-summary-label">Address visibility</span>
          <span className="wap-summary-value">{humanize(property.address_visibility)}</span>
        </div>
      </div>

      <div className="wap-summary-section">
        <div className="wap-summary-section-title">Units</div>
        <div className="wap-summary-row">
          <span className="wap-summary-label">Total</span>
          <span className="wap-summary-value">
            {units.length} ({occupiedCount} occupied, {vacantCount} vacant)
          </span>
        </div>
      </div>

      <div className="wap-summary-section">
        <div className="wap-summary-section-title">Photos</div>
        <div className="wap-summary-row">
          <span className="wap-summary-label">Uploaded</span>
          <span className="wap-summary-value">{media.length}</span>
        </div>
      </div>

      <div className="wap-summary-section">
        <div className="wap-summary-section-title">Amenities &amp; rules</div>
        <div className="wap-summary-row">
          <span className="wap-summary-label">Amenities</span>
          <span className="wap-summary-value">{amenities.length > 0 ? amenities.map(humanize).join(', ') : 'None selected'}</span>
        </div>
        <div className="wap-summary-row">
          <span className="wap-summary-label">Rules</span>
          <span className="wap-summary-value">
            {ruleEntries.length > 0
              ? ruleEntries.map(([k, v]) => `${humanize(k)}: ${v === true ? 'Yes' : v === false ? 'No' : String(v)}`).join(' · ')
              : 'None set'}
          </span>
        </div>
      </div>

      <p className="wap-hint">
        <IconHome size={12} className="mr-1 inline align-text-bottom" />
        This is a property record. It does not become a public listing by itself — create a listing for
        a specific unit and submit it for admin review when you're ready.
      </p>
    </div>
  );
}
