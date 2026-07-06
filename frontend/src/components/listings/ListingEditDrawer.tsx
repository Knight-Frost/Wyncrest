import { useEffect, useState } from 'react';
import { landlordApi } from '@/lib/endpoints';
import { fieldErrors } from '@/lib/api';
import type { ApiError, Listing } from '@/lib/types';
import { Button } from '@/components/ui/Button';
import { DetailDrawer } from '@/components/ui/Drawer';
import { Field, Input, Textarea } from '@/components/ui/Field';
import { useToast } from '@/components/ui/toast';

interface ListingForm {
  title: string;
  description: string;
  pets_allowed: boolean;
  pet_policy: string;
  lease_duration_months: string;
  move_in_date: string;
}

function emptyForm(): ListingForm {
  return { title: '', description: '', pets_allowed: false, pet_policy: '', lease_duration_months: '', move_in_date: '' };
}

function formFrom(l: Listing): ListingForm {
  return {
    title: l.title,
    description: l.description,
    pets_allowed: l.pets_allowed,
    pet_policy: l.pet_policy ?? '',
    lease_duration_months: l.lease_duration_months != null ? String(l.lease_duration_months) : '',
    move_in_date: l.move_in_date ?? '',
  };
}

function validate(form: ListingForm): Record<string, string> {
  const errs: Record<string, string> = {};
  if (!form.title.trim()) errs.title = 'A title is required.';
  if (form.description.trim().length < 50) errs.description = 'Description must be at least 50 characters.';
  return errs;
}

/**
 * Shared edit drawer for a listing's landlord-writable fields (title,
 * description, lease terms, pets). Rent/bedrooms/bathrooms/amenities live on
 * the Unit/Property and are edited from Properties, not here — the backend
 * FormRequest for PUT /landlord/listings/{id} only accepts these fields.
 */
export function ListingEditDrawer({
  listing, open, onClose, onSaved,
}: {
  listing: Listing | null;
  open: boolean;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { toast } = useToast();
  const [form, setForm] = useState<ListingForm>(() => (listing ? formFrom(listing) : emptyForm()));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (listing) { setForm(formFrom(listing)); setErrors({}); }
  }, [listing]);

  async function handleSave(e: React.FormEvent) {
    e.preventDefault();
    if (!listing) return;
    const v = validate(form);
    if (Object.keys(v).length > 0) { setErrors(v); return; }
    setSaving(true);
    setErrors({});
    try {
      await landlordApi.updateListing(listing.id, {
        title: form.title.trim(),
        description: form.description.trim(),
        pets_allowed: form.pets_allowed,
        pet_policy: form.pets_allowed ? form.pet_policy || null : null,
        lease_duration_months: form.lease_duration_months ? Number(form.lease_duration_months) : null,
        move_in_date: form.move_in_date || null,
      });
      toast('Listing updated', 'success');
      onSaved();
    } catch (err) {
      const e2 = err as ApiError;
      const fe = fieldErrors(e2);
      setErrors(fe);
      if (Object.keys(fe).length === 0) toast(e2.message, 'error');
    } finally {
      setSaving(false);
    }
  }

  return (
    <DetailDrawer
      open={open}
      onClose={onClose}
      eyebrow="LISTING"
      title="Edit listing"
      description="Update the listing details. Rejected listings can be resubmitted for review once fixed."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button type="submit" form="listing-edit-form" loading={saving}>Save changes</Button>
        </>
      }
    >
      <form id="listing-edit-form" onSubmit={handleSave} className="space-y-4">
        <Field label="Unit">
          {(fid) => (
            <Input id={fid} disabled value={
              listing?.unit ? `Unit ${listing.unit.unit_number}` + (listing.unit.internal_name ? ` · ${listing.unit.internal_name}` : '') : `Unit #${listing?.unit_id ?? ''}`
            } />
          )}
        </Field>
        <Field label="Title" error={errors.title} required>
          {(fid, invalid) => (
            <Input id={fid} invalid={invalid} placeholder="e.g. Bright 2-bed apartment in East Legon" value={form.title} onChange={(e) => setForm((p) => ({ ...p, title: e.target.value }))} />
          )}
        </Field>
        <Field label="Description" error={errors.description} hint={`At least 50 characters · ${form.description.trim().length}/50`} required>
          {(fid, invalid) => (
            <Textarea id={fid} invalid={invalid} rows={5} placeholder="Describe the home, the neighbourhood, and what makes it a great rental…" value={form.description} onChange={(e) => setForm((p) => ({ ...p, description: e.target.value }))} />
          )}
        </Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Lease duration (months)" error={errors.lease_duration_months}>
            {(fid, invalid) => (
              <Input id={fid} type="number" min="1" invalid={invalid} value={form.lease_duration_months} onChange={(e) => setForm((p) => ({ ...p, lease_duration_months: e.target.value }))} />
            )}
          </Field>
          <Field label="Available move-in date" error={errors.move_in_date}>
            {(fid, invalid) => (
              <Input id={fid} type="date" invalid={invalid} value={form.move_in_date} onChange={(e) => setForm((p) => ({ ...p, move_in_date: e.target.value }))} />
            )}
          </Field>
        </div>
        <Field label="Pets">
          {(fid) => (
            <label htmlFor={fid} className="flex items-center gap-2 text-sm text-ink-700">
              <input id={fid} type="checkbox" className="h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-500" checked={form.pets_allowed} onChange={(e) => setForm((p) => ({ ...p, pets_allowed: e.target.checked }))} />
              Pets allowed
            </label>
          )}
        </Field>
        {form.pets_allowed && (
          <Field label="Pet policy" error={errors.pet_policy}>
            {(fid, invalid) => (
              <Input id={fid} invalid={invalid} placeholder="e.g. Cats and small dogs welcome, GH₵ 500 pet deposit" value={form.pet_policy} onChange={(e) => setForm((p) => ({ ...p, pet_policy: e.target.value }))} />
            )}
          </Field>
        )}
      </form>
    </DetailDrawer>
  );
}
