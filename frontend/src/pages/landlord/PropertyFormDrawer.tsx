import { useEffect, useMemo, useRef, useState } from 'react';
import { landlordApi } from '@/lib/endpoints';
import { fieldErrors } from '@/lib/api';
import { humanize } from '@/lib/format';
import type { ApiError, AddressVisibility, Property, PropertyType } from '@/lib/types';
import { useToast } from '@/components/ui/toast';
import { Button } from '@/components/ui/Button';
import { Field, Input, Select, Textarea } from '@/components/ui/Field';
import {
  Drawer,
  DrawerHeader,
  DrawerBody,
  DrawerFooter,
} from '@/components/ui/Drawer';
import { StepIndicator } from '@/components/ui/StepIndicator';
import { DestructiveConfirmDialog } from '@/components/ui/DestructiveConfirmDialog';
import { IconArrowRight, IconArrowLeft } from '@/components/ui/icons';
import { ADDRESS_VISIBILITY_OPTIONS, PROPERTY_TYPES } from './property-constants';
import {
  type PropertyForm,
  PROPERTY_FIELD_STEP as FIELD_STEP,
  countryOptionsFor,
  emptyPropertyForm as emptyForm,
  propertyFormFromModel as formFromProperty,
  propertyPayloadFromForm,
  regionOptionsFor,
  validatePropertyBasics,
  validatePropertyLocation,
} from './property-form-shared';

const STEP_LABELS = ['Property basics', 'Location details'];

interface PropertyFormDrawerProps {
  open: boolean;
  /** null = create, a Property = edit. */
  editing: Property | null;
  onClose: () => void;
  /** Fired after a successful create/update so the page can reload real data. */
  onSaved: () => void;
}

export function PropertyFormDrawer({
  open,
  editing,
  onClose,
  onSaved,
}: PropertyFormDrawerProps) {
  const { toast } = useToast();
  const [step, setStep] = useState<0 | 1>(0);
  const [form, setForm] = useState<PropertyForm>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [showDiscard, setShowDiscard] = useState(false);
  const initialRef = useRef<string>('');

  /* Reset to a clean state whenever the drawer opens. */
  useEffect(() => {
    if (!open) return;
    const next = editing ? formFromProperty(editing) : emptyForm();
    setForm(next);
    initialRef.current = JSON.stringify(next);
    setErrors({});
    setStep(0);
    setSaving(false);
    setShowDiscard(false);
  }, [open, editing]);

  const dirty = useMemo(
    () => JSON.stringify(form) !== initialRef.current,
    [form],
  );

  /* Edit may carry a region/country code not in our curated lists — surface it. */
  const regionOptions = useMemo(() => regionOptionsFor(form.state), [form.state]);
  const countryOptions = useMemo(() => countryOptionsFor(form.country), [form.country]);

  function update<K extends keyof PropertyForm>(key: K, value: PropertyForm[K]) {
    setForm((prev) => ({ ...prev, [key]: value }));
    setErrors((prev) => (prev[key] ? { ...prev, [key]: '' } : prev));
  }

  /* ── Validation (mirrors StorePropertyRequest, no invented rules) ── */
  function validate(which: 0 | 1 | 'all'): Record<string, string> {
    const checkBasics = which === 0 || which === 'all';
    const checkLocation = which === 1 || which === 'all';
    return {
      ...(checkBasics ? validatePropertyBasics(form) : {}),
      ...(checkLocation ? validatePropertyLocation(form) : {}),
    };
  }

  function goNext() {
    const e = validate(0);
    setErrors(e);
    if (Object.keys(e).length === 0) setStep(1);
  }

  async function handleSubmit(ev?: React.FormEvent) {
    ev?.preventDefault();
    if (saving) return; // guard double submission
    // On the basics step, Enter advances rather than submitting the whole form.
    if (step === 0) {
      goNext();
      return;
    }

    const e = validate('all');
    if (Object.keys(e).length > 0) {
      setErrors(e);
      // jump to the earliest step that has an error
      const firstStep = Object.keys(e).some((k) => FIELD_STEP[k] === 0) ? 0 : 1;
      setStep(firstStep);
      return;
    }

    setSaving(true);
    setErrors({});
    const payload = propertyPayloadFromForm(form);

    try {
      if (editing) {
        await landlordApi.updateProperty(editing.id, payload);
        toast('Property updated', 'success');
      } else {
        await landlordApi.createProperty(payload);
        toast('Property created', 'success');
      }
      onSaved();
      onClose();
    } catch (err) {
      const e2 = err as ApiError;
      const fe = fieldErrors(e2);
      if (Object.keys(fe).length > 0) {
        setErrors(fe);
        const firstStep = Object.keys(fe).some((k) => FIELD_STEP[k] === 0) ? 0 : 1;
        setStep(firstStep);
      } else {
        toast(e2.message, 'error');
      }
    } finally {
      setSaving(false);
    }
  }

  /* ── Close flow with unsaved-changes guard ── */
  function requestClose() {
    if (saving) return;
    if (dirty) setShowDiscard(true);
    else onClose();
  }

  return (
    <>
      <Drawer
        open={open}
        onOpenChange={(o) => {
          if (!o) requestClose();
        }}
        blockInteractions={showDiscard}
      >
        <DrawerHeader
          title={editing ? 'Edit property' : 'Add property'}
          description="Provide the property details and full address."
          onClose={requestClose}
          accessory={<StepIndicator steps={STEP_LABELS} current={step} onStepClick={(i) => setStep(i as 0 | 1)} />}
        />

        <DrawerBody>
          <form id="property-drawer-form" onSubmit={handleSubmit} className="space-y-5">
            {step === 0 ? (
              <>
                <Field label="Property name" error={errors.name} required>
                  {(id, invalid) => (
                    <Input
                      id={id}
                      invalid={invalid}
                      autoFocus
                      placeholder="e.g. East Legon Heights"
                      value={form.name}
                      onChange={(e) => update('name', e.target.value)}
                    />
                  )}
                </Field>

                <Field label="Property type" error={errors.property_type} required>
                  {(id, invalid) => (
                    <Select
                      id={id}
                      invalid={invalid}
                      value={form.property_type}
                      onChange={(e) => update('property_type', e.target.value as PropertyType)}
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

                <Field label="Year built" error={errors.year_built} hint="Optional">
                  {(id, invalid) => (
                    <Input
                      id={id}
                      type="number"
                      inputMode="numeric"
                      invalid={invalid}
                      placeholder="e.g. 2019"
                      value={form.year_built}
                      onChange={(e) => update('year_built', e.target.value)}
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
                      onChange={(e) => update('description', e.target.value)}
                    />
                  )}
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                  <Field label="Parking" error={errors.parking} hint="Optional">
                    {(id, invalid) => (
                      <Input
                        id={id}
                        invalid={invalid}
                        placeholder="e.g. 1 covered space per unit"
                        value={form.parking}
                        onChange={(e) => update('parking', e.target.value)}
                      />
                    )}
                  </Field>
                  <Field label="Pet policy" error={errors.pet_policy} hint="Optional">
                    {(id, invalid) => (
                      <Input
                        id={id}
                        invalid={invalid}
                        placeholder="e.g. Cats and small dogs allowed"
                        value={form.pet_policy}
                        onChange={(e) => update('pet_policy', e.target.value)}
                      />
                    )}
                  </Field>
                </div>

                <Field label="Smoking policy" error={errors.smoking_policy} hint="Optional">
                  {(id, invalid) => (
                    <Input
                      id={id}
                      invalid={invalid}
                      placeholder="e.g. No smoking indoors"
                      value={form.smoking_policy}
                      onChange={(e) => update('smoking_policy', e.target.value)}
                    />
                  )}
                </Field>

                <p className="text-xs text-ink-400">You can add more details later.</p>
              </>
            ) : (
              <>
                <Field label="Street address" error={errors.street_address} required>
                  {(id, invalid) => (
                    <Input
                      id={id}
                      invalid={invalid}
                      autoFocus
                      placeholder="e.g. 14 Boundary Road"
                      value={form.street_address}
                      onChange={(e) => update('street_address', e.target.value)}
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
                      onChange={(e) => update('street_address_2', e.target.value)}
                    />
                  )}
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                  <Field label="City" error={errors.city} required>
                    {(id, invalid) => (
                      <Input
                        id={id}
                        invalid={invalid}
                        placeholder="e.g. Accra"
                        value={form.city}
                        onChange={(e) => update('city', e.target.value)}
                      />
                    )}
                  </Field>
                  <Field label="Region" error={errors.state} required>
                    {(id, invalid) => (
                      <Select
                        id={id}
                        invalid={invalid}
                        value={form.state}
                        onChange={(e) => update('state', e.target.value)}
                      >
                        {regionOptions.map((r) => (
                          <option key={r.code} value={r.code}>
                            {r.name} ({r.code})
                          </option>
                        ))}
                      </Select>
                    )}
                  </Field>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                  <Field label="Digital address / Postcode" error={errors.zip_code} required>
                    {(id, invalid) => (
                      <Input
                        id={id}
                        invalid={invalid}
                        placeholder="GA-123-4567"
                        value={form.zip_code}
                        onChange={(e) => update('zip_code', e.target.value)}
                      />
                    )}
                  </Field>
                  <Field label="Country" error={errors.country} required>
                    {(id, invalid) => (
                      <Select
                        id={id}
                        invalid={invalid}
                        value={form.country}
                        onChange={(e) => update('country', e.target.value)}
                      >
                        {countryOptions.map((c) => (
                          <option key={c.code} value={c.code}>
                            {c.name} ({c.code})
                          </option>
                        ))}
                      </Select>
                    )}
                  </Field>
                </div>

                <Field
                  label="Address visibility"
                  error={errors.address_visibility}
                  hint={ADDRESS_VISIBILITY_OPTIONS.find((o) => o.value === form.address_visibility)?.hint}
                  required
                >
                  {(id, invalid) => (
                    <Select
                      id={id}
                      invalid={invalid}
                      value={form.address_visibility}
                      onChange={(e) =>
                        update('address_visibility', e.target.value as AddressVisibility)
                      }
                    >
                      {ADDRESS_VISIBILITY_OPTIONS.map((o) => (
                        <option key={o.value} value={o.value}>
                          {o.label}
                        </option>
                      ))}
                    </Select>
                  )}
                </Field>
              </>
            )}
          </form>
        </DrawerBody>

        <DrawerFooter>
          {step === 0 ? (
            <>
              <Button variant="secondary" onClick={requestClose} disabled={saving}>
                Cancel
              </Button>
              <Button onClick={goNext} rightIcon={<IconArrowRight size={16} />}>
                Next
              </Button>
            </>
          ) : (
            <>
              <Button
                variant="secondary"
                onClick={() => setStep(0)}
                disabled={saving}
                leftIcon={<IconArrowLeft size={16} />}
              >
                Back
              </Button>
              <Button
                type="submit"
                form="property-drawer-form"
                loading={saving}
              >
                {editing ? 'Save changes' : 'Create property'}
              </Button>
            </>
          )}
        </DrawerFooter>
      </Drawer>

      {/* Unsaved-changes guard */}
      <DestructiveConfirmDialog
        open={showDiscard}
        onClose={() => setShowDiscard(false)}
        onConfirm={() => {
          setShowDiscard(false);
          onClose();
        }}
        title="Discard changes?"
        description="You have unsaved details. If you leave now, they’ll be lost."
        confirmLabel="Discard changes"
        cancelLabel="Keep editing"
      />
    </>
  );
}
