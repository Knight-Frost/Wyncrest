/**
 * Step 1 — Unit details.
 *
 * Selecting a property narrows the eligible-unit list; selecting a unit prefills
 * its real stored bedrooms/bathrooms/size/number (no invented values). Title +
 * short description are the listing's own fields. "Floor" and "service charge"
 * from the mockup are intentionally OMITTED — there are no backend columns for
 * them, and this builder never renders fields that save nowhere.
 */
import type { Property, Unit } from '@/lib/types';
import { Field, NativeSelect, TextInput, Textarea } from '../fields';
import type { StepProps } from '../types';

const BEDROOMS = [
  { value: '0', label: 'Studio' },
  { value: '1', label: '1' },
  { value: '2', label: '2' },
  { value: '3', label: '3' },
  { value: '4', label: '4' },
  { value: '5', label: '5' },
  { value: '6', label: '6+' },
];
const BATHROOMS = [
  { value: '1', label: '1' },
  { value: '1.5', label: '1.5' },
  { value: '2', label: '2' },
  { value: '2.5', label: '2.5' },
  { value: '3', label: '3' },
  { value: '3.5', label: '3.5' },
  { value: '4', label: '4+' },
];

interface UnitDetailsStepProps extends StepProps {
  properties: Property[];
  eligibleUnits: Unit[];
  onSelectProperty: (id: number | null) => void;
  onSelectUnit: (id: number | null) => void;
}

export function UnitDetailsStep({
  form,
  set,
  errors,
  properties,
  eligibleUnits,
  onSelectProperty,
  onSelectUnit,
}: UnitDetailsStepProps) {
  const unitsForProperty = eligibleUnits.filter((u) => u.property_id === form.propertyId);

  return (
    <>
      <Field
        label="Unit title"
        required
        htmlFor="cl-title"
        error={errors.title}
        counter={`${form.title.length}/100`}
        help="A short, descriptive title shown to tenants."
      >
        <TextInput
          id="cl-title"
          value={form.title}
          maxLength={100}
          invalid={!!errors.title}
          placeholder="e.g. One-bedroom apartment"
          onChange={(e) => set('title', e.target.value)}
        />
      </Field>

      <div className="cl-grid-2">
        <Field label="Property" required htmlFor="cl-property" error={errors.propertyId}>
          <NativeSelect
            id="cl-property"
            placeholder="Select a property"
            invalid={!!errors.propertyId}
            value={form.propertyId ? String(form.propertyId) : ''}
            options={properties.map((p) => ({ value: String(p.id), label: p.name }))}
            onChange={(e) => onSelectProperty(e.target.value ? Number(e.target.value) : null)}
          />
        </Field>

        <Field
          label="Unit"
          required
          htmlFor="cl-unit"
          error={errors.unitId}
          help={form.propertyId ? `${unitsForProperty.length} eligible unit(s)` : 'Choose a property first'}
        >
          <NativeSelect
            id="cl-unit"
            placeholder={form.propertyId ? 'Select an eligible unit' : 'Select a property first'}
            disabled={!form.propertyId}
            invalid={!!errors.unitId}
            value={form.unitId ? String(form.unitId) : ''}
            options={unitsForProperty.map((u) => ({
              value: String(u.id),
              label: u.unit_number ? `Unit ${u.unit_number}` : u.internal_name || `Unit #${u.id}`,
            }))}
            onChange={(e) => onSelectUnit(e.target.value ? Number(e.target.value) : null)}
          />
        </Field>
      </div>

      <div className="cl-grid-2">
        <Field label="Bedrooms" required htmlFor="cl-beds" error={errors.bedrooms}>
          <NativeSelect
            id="cl-beds"
            placeholder="Select"
            invalid={!!errors.bedrooms}
            value={form.bedrooms}
            options={BEDROOMS}
            onChange={(e) => set('bedrooms', e.target.value)}
          />
        </Field>
        <Field label="Bathrooms" required htmlFor="cl-baths" error={errors.bathrooms}>
          <NativeSelect
            id="cl-baths"
            placeholder="Select"
            invalid={!!errors.bathrooms}
            value={form.bathrooms}
            options={BATHROOMS}
            onChange={(e) => set('bathrooms', e.target.value)}
          />
        </Field>
      </div>

      <div className="cl-grid-2">
        <Field label="Size (sq ft)" htmlFor="cl-size" error={errors.squareFeet} help="Optional">
          <TextInput
            id="cl-size"
            type="number"
            min={0}
            suffix="sq ft"
            value={form.squareFeet}
            invalid={!!errors.squareFeet}
            placeholder="e.g. 850"
            onChange={(e) => set('squareFeet', e.target.value)}
          />
        </Field>
        <Field label="Unit number / name" htmlFor="cl-unitnum" help="Optional internal reference">
          <TextInput
            id="cl-unitnum"
            value={form.unitNumber}
            maxLength={50}
            placeholder="e.g. 1B, Unit 4A"
            onChange={(e) => set('unitNumber', e.target.value)}
          />
        </Field>
      </div>

      <Field
        label="Short description"
        required
        htmlFor="cl-desc"
        error={errors.description}
        counter={`${form.description.length}/1000`}
        help="At least 50 characters. Describe the unit in a few sentences."
      >
        <Textarea
          id="cl-desc"
          value={form.description}
          maxLength={1000}
          invalid={!!errors.description}
          placeholder="Describe the unit in a few sentences…"
          onChange={(e) => set('description', e.target.value)}
        />
      </Field>
    </>
  );
}
