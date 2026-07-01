/**
 * ContractCreatePage — dedicated FULL PAGE for landlord contract creation
 * (/app/contracts/new). This deliberately replaces the old create drawer: a
 * lease is a consequential document, so it gets a spacious, reviewable page.
 *
 * TRUTHFULNESS / SECURITY:
 *  - Landlord-only. A non-landlord is bounced back to /app/contracts (the API
 *    also 403s any non-landlord create — this guard is the cosmetic half).
 *  - The listing selector is populated from the landlord's OWN listings
 *    (GET /landlord/listings, unit.property eager-loaded). No invented options.
 *  - Fields map 1:1 to StoreContractRequest — no deposit, no terms (the backend
 *    has neither). Rent is entered in GH₵ (major units) and sent as integer
 *    cents; a blank end date is omitted (open-ended lease).
 *  - Two steps: (1) edit, (2) review before the final submit. Server 422 field
 *    errors map back to their fields and return to editing with data intact.
 */
import { useMemo, useState } from 'react';
import { Link, Navigate, useNavigate } from 'react-router';
import { useAuth } from '@/context/auth';
import { useApi } from '@/hooks/useApi';
import { landlordApi } from '@/lib/endpoints';
import { fieldErrors, normalizeError } from '@/lib/api';
import { formatCents } from '@/lib/format';
import { brand } from '@/config/brand';
import { PageHeader } from '@/components/layout/PageHeader';
import { StepIndicator } from '@/components/ui/StepIndicator';
import { Field, Input, Select } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/states';
import { IconArrowRight, IconArrowLeft, IconCheckCircle, IconDoc } from '@/components/ui/icons';
import type { ApiError, Contract, Listing } from '@/lib/types';
import './contract-create.css';

/* The six fields the backend accepts (StoreContractRequest) — nothing more. */
interface ContractForm {
  listing_id: string;
  tenant_email: string;
  rent_amount: string; // GH₵ major units in the input; × 100 → cents on submit
  payment_day: string;
  start_date: string;
  end_date: string; // optional; omitted from the payload when blank
}

type FieldErrors = Partial<Record<keyof ContractForm, string>>;

const EMPTY_FORM: ContractForm = {
  listing_id: '', tenant_email: '', rent_amount: '', payment_day: '1', start_date: '', end_date: '',
};

const STEPS = ['Details', 'Review'];

/** Today as YYYY-MM-DD (local) for the start-date min + validation. */
function todayISO(): string {
  const d = new Date();
  const off = d.getTimezoneOffset();
  return new Date(d.getTime() - off * 60000).toISOString().slice(0, 10);
}

/** Human label for a listing: title · property — Unit N. Never invents data. */
function listingLabel(l: Listing): string {
  const property = l.unit?.property?.name;
  const unitNo = l.unit?.unit_number;
  const parts = [l.title];
  if (property) parts.push(property);
  const base = parts.join(' · ');
  return unitNo ? `${base} — Unit ${unitNo}` : base;
}

export function ContractCreatePage() {
  const { user } = useAuth();
  const role = user?.role;
  const navigate = useNavigate();

  // Landlord-only guard (mirrors RequireRole). Bounce everyone else.
  const isLandlord = role === 'landlord';

  const { data, loading, error, reload } = useApi<Listing[]>(
    () => landlordApi.listings(),
    [],
  );
  const listings = useMemo(() => data ?? [], [data]);

  const [step, setStep] = useState(0); // 0 = details, 1 = review
  const [form, setForm] = useState<ContractForm>(EMPTY_FORM);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [submitting, setSubmitting] = useState(false);
  const [generalError, setGeneralError] = useState<string | null>(null);
  const [created, setCreated] = useState<Contract | null>(null);

  const selectedListing = useMemo(
    () => listings.find((l) => String(l.id) === form.listing_id) ?? null,
    [listings, form.listing_id],
  );

  if (!isLandlord) return <Navigate to="/app/contracts" replace />;

  function setField(key: keyof ContractForm, value: string) {
    setForm((prev) => ({ ...prev, [key]: value }));
    if (errors[key]) setErrors((prev) => ({ ...prev, [key]: undefined }));
    setGeneralError(null);
  }

  /** Client validation mirroring StoreContractRequest. Returns the error map. */
  function validate(): FieldErrors {
    const e: FieldErrors = {};
    if (!form.listing_id) e.listing_id = 'Select one of your listings.';
    const email = form.tenant_email.trim();
    if (!email) e.tenant_email = 'Tenant email is required.';
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) e.tenant_email = 'Enter a valid email address.';
    const rent = Number(form.rent_amount);
    if (!form.rent_amount || !Number.isFinite(rent) || rent <= 0) e.rent_amount = 'Enter a valid monthly rent in GH₵.';
    const day = Number(form.payment_day);
    if (!form.payment_day || !Number.isInteger(day) || day < 1 || day > 28) e.payment_day = 'Payment day must be between 1 and 28.';
    if (!form.start_date) e.start_date = 'Start date is required.';
    else if (form.start_date < todayISO()) e.start_date = 'Start date cannot be in the past.';
    if (form.end_date && form.start_date && form.end_date <= form.start_date) e.end_date = 'End date must be after the start date.';
    return e;
  }

  function goReview() {
    const e = validate();
    if (Object.keys(e).length) { setErrors(e); return; }
    setErrors({});
    setStep(1);
  }

  async function submit() {
    const e = validate();
    if (Object.keys(e).length) { setErrors(e); setStep(0); return; }

    setSubmitting(true);
    setGeneralError(null);
    try {
      const rentCents = Math.round(Number(form.rent_amount) * 100);
      const contract = await landlordApi.createContract({
        listing_id: Number(form.listing_id),
        tenant_email: form.tenant_email.trim(), // resolved to a tenant id server-side
        rent_amount: rentCents,
        payment_day: Number(form.payment_day),
        start_date: form.start_date,
        // Omit end_date entirely for an open-ended lease.
        ...(form.end_date ? { end_date: form.end_date } : {}),
      });
      setCreated(contract);
      // Brief success state, then route to the new contract's detail.
      setTimeout(() => navigate(`/app/contracts/${contract.id}`), 900);
    } catch (err) {
      const apiErr = normalizeError(err) as ApiError;
      const flat = fieldErrors(apiErr);
      const mapped: FieldErrors = {};
      for (const key of Object.keys(EMPTY_FORM) as (keyof ContractForm)[]) {
        if (flat[key]) mapped[key] = flat[key];
      }
      if (Object.keys(mapped).length) {
        setErrors(mapped);
        setGeneralError(null);
      } else {
        setGeneralError(apiErr.message || 'Could not create the contract. Please review and try again.');
      }
      setStep(0); // return to editing, data preserved
    } finally {
      setSubmitting(false);
    }
  }

  /* ── success state ──────────────────────────────────────────────────────── */
  if (created) {
    return (
      <div className="cc-page">
        <div className="cc-success" role="status">
          <span className="cc-success-ico"><IconCheckCircle size={30} /></span>
          <h2 className="cc-success-title">Contract created</h2>
          <p className="cc-success-text">Opening the contract so you can review and send it to your tenant…</p>
        </div>
      </div>
    );
  }

  return (
    <div className="cc-page">
      <PageHeader
        eyebrow="Operations"
        title="Create New Contract"
        description="Set up a rental agreement between a tenant and one of your approved units."
        action={
          <Button variant="secondary" onClick={() => navigate('/app/contracts')} disabled={submitting}>
            Cancel
          </Button>
        }
      />

      {loading ? (
        <div className="cc-card">
          <LoadingState label="Loading your listings…" />
        </div>
      ) : error ? (
        <div className="cc-card">
          <ErrorState message={error.message} onRetry={reload} />
        </div>
      ) : listings.length === 0 ? (
        <div className="cc-card">
          <EmptyState
            icon={<IconDoc size={28} />}
            title="You need a listing first"
            description="Contracts are created against one of your listings. Publish an approved listing for a unit, then come back to draft the lease."
            action={<Link to="/app/listings"><Button>Go to your listings</Button></Link>}
          />
        </div>
      ) : (
        <div className="cc-card">
          <div className="cc-steps">
            <StepIndicator steps={STEPS} current={step} />
          </div>

          {generalError && (
            <p className="cc-general-error" role="alert">{generalError}</p>
          )}

          {step === 0 ? (
            <form
              className="cc-form"
              onSubmit={(ev) => { ev.preventDefault(); goReview(); }}
            >
              <Field label="Listing" required error={errors.listing_id} hint="The unit this lease covers — one of your own listings.">
                {(id, invalid) => (
                  <Select id={id} invalid={invalid} value={form.listing_id} onChange={(e) => setField('listing_id', e.target.value)}>
                    <option value="">Select a listing…</option>
                    {listings.map((l) => (
                      <option key={l.id} value={l.id}>{listingLabel(l)}</option>
                    ))}
                  </Select>
                )}
              </Field>

              {selectedListing && (
                <div className="cc-picked" aria-live="polite">
                  <span className="cc-picked-label">Selected unit</span>
                  <span className="cc-picked-value">{listingLabel(selectedListing)}</span>
                </div>
              )}

              <Field label="Tenant email" required error={errors.tenant_email} hint={`The tenant must already have a ${brand.appName} account.`}>
                {(id, invalid) => (
                  <Input id={id} invalid={invalid} type="email" placeholder="tenant@example.com" value={form.tenant_email} onChange={(e) => setField('tenant_email', e.target.value)} />
                )}
              </Field>

              <div className="cc-grid">
                <Field label="Monthly rent (GH₵)" required error={errors.rent_amount}>
                  {(id, invalid) => (
                    <div className="cc-money">
                      <span className="cc-money-prefix" aria-hidden="true">GH₵</span>
                      <Input id={id} invalid={invalid} className="cc-money-input" type="number" min={1} step="1" placeholder="1500" value={form.rent_amount} onChange={(e) => setField('rent_amount', e.target.value)} />
                    </div>
                  )}
                </Field>
                <Field label="Payment day" required error={errors.payment_day} hint="Day of each month, between 1 and 28.">
                  {(id, invalid) => (
                    <Input id={id} invalid={invalid} type="number" min={1} max={28} value={form.payment_day} onChange={(e) => setField('payment_day', e.target.value)} />
                  )}
                </Field>
              </div>

              <div className="cc-grid">
                <Field label="Start date" required error={errors.start_date}>
                  {(id, invalid) => (
                    <Input id={id} invalid={invalid} type="date" min={todayISO()} value={form.start_date} onChange={(e) => setField('start_date', e.target.value)} />
                  )}
                </Field>
                <Field label="End date (optional)" error={errors.end_date} hint="Leave blank for an open-ended lease.">
                  {(id, invalid) => (
                    <Input id={id} invalid={invalid} type="date" min={form.start_date || todayISO()} value={form.end_date} onChange={(e) => setField('end_date', e.target.value)} />
                  )}
                </Field>
              </div>

              <footer className="cc-actions">
                <Button type="button" variant="secondary" onClick={() => navigate('/app/contracts')}>Cancel</Button>
                <Button type="submit" rightIcon={<IconArrowRight size={16} />}>Review</Button>
              </footer>
            </form>
          ) : (
            <div className="cc-review">
              <p className="cc-review-lead">Check the details before creating the contract.</p>
              <dl className="cc-summary">
                <ReviewRow label="Tenant email" value={form.tenant_email.trim()} />
                <ReviewRow label="Listing" value={selectedListing ? listingLabel(selectedListing) : '—'} />
                {selectedListing?.unit?.property?.name && (
                  <ReviewRow label="Property" value={selectedListing.unit.property.name} />
                )}
                {selectedListing?.unit?.unit_number && (
                  <ReviewRow label="Unit" value={selectedListing.unit.unit_number} />
                )}
                <ReviewRow label="Monthly rent" value={formatCents(Math.round(Number(form.rent_amount) * 100))} />
                <ReviewRow label="Payment day" value={`Day ${form.payment_day} of each month`} />
                <ReviewRow label="Start date" value={form.start_date} />
                <ReviewRow label="End date" value={form.end_date || 'Open-ended'} />
              </dl>

              <p className="cc-note" role="note">
                This creates a DRAFT contract you can review and send to the tenant.
              </p>

              <footer className="cc-actions">
                <Button type="button" variant="secondary" leftIcon={<IconArrowLeft size={16} />} onClick={() => setStep(0)} disabled={submitting}>Back</Button>
                <Button type="button" onClick={submit} loading={submitting}>Create contract</Button>
              </footer>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

function ReviewRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="cc-summary-row">
      <dt className="cc-summary-label">{label}</dt>
      <dd className="cc-summary-value">{value}</dd>
    </div>
  );
}
