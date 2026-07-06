import type { UnitAvailabilityStatus } from '@/lib/types';
import { humanize } from '@/lib/format';
import { Field, Input, Select } from '@/components/ui/Field';
import { AVAILABILITY_STATUSES, type UnitForm } from './unit-form-shared';

interface UnitFormFieldsProps {
  form: UnitForm;
  errors: Record<string, string>;
  onChange: <K extends keyof UnitForm>(key: K, value: UnitForm[K]) => void;
}

/**
 * The unit fields shared by PropertyDetail's unit drawer and the Add Property
 * wizard's Units step — one place to keep validation/labels in sync.
 */
export function UnitFormFields({ form, errors, onChange }: UnitFormFieldsProps) {
  return (
    <div className="space-y-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Unit number" error={errors.unit_number} required>
          {(fid, invalid) => (
            <Input
              id={fid}
              invalid={invalid}
              placeholder="e.g. A3 or Villa-1"
              value={form.unit_number}
              onChange={(e) => onChange('unit_number', e.target.value)}
            />
          )}
        </Field>
        <Field label="Internal name" error={errors.internal_name}>
          {(fid, invalid) => (
            <Input
              id={fid}
              invalid={invalid}
              placeholder="e.g. Block A, Floor 3"
              value={form.internal_name}
              onChange={(e) => onChange('internal_name', e.target.value)}
            />
          )}
        </Field>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Bedrooms" error={errors.bedrooms} required>
          {(fid, invalid) => (
            <Input
              id={fid}
              type="number"
              step="1"
              min="0"
              invalid={invalid}
              value={form.bedrooms}
              onChange={(e) => onChange('bedrooms', e.target.value)}
            />
          )}
        </Field>
        <Field label="Bathrooms" error={errors.bathrooms} required>
          {(fid, invalid) => (
            <Input
              id={fid}
              type="number"
              step="0.5"
              min="0"
              invalid={invalid}
              value={form.bathrooms}
              onChange={(e) => onChange('bathrooms', e.target.value)}
            />
          )}
        </Field>
      </div>

      <Field label="Size (sq ft)" error={errors.square_feet}>
        {(fid, invalid) => (
          <Input
            id={fid}
            type="number"
            min="0"
            invalid={invalid}
            placeholder="Optional"
            value={form.square_feet}
            onChange={(e) => onChange('square_feet', e.target.value)}
          />
        )}
      </Field>

      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Monthly rent (GH₵)" error={errors.rent_amount} required>
          {(fid, invalid) => (
            <Input
              id={fid}
              type="number"
              step="0.01"
              min="0"
              invalid={invalid}
              placeholder="e.g. 5800.00"
              value={form.rent_amount}
              onChange={(e) => onChange('rent_amount', e.target.value)}
            />
          )}
        </Field>
        <Field label="Security deposit (GH₵)" error={errors.security_deposit}>
          {(fid, invalid) => (
            <Input
              id={fid}
              type="number"
              step="0.01"
              min="0"
              invalid={invalid}
              placeholder="Optional"
              value={form.security_deposit}
              onChange={(e) => onChange('security_deposit', e.target.value)}
            />
          )}
        </Field>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Availability" error={errors.availability_status} required>
          {(fid, invalid) => (
            <Select
              id={fid}
              invalid={invalid}
              value={form.availability_status}
              onChange={(e) => onChange('availability_status', e.target.value as UnitAvailabilityStatus)}
            >
              {AVAILABILITY_STATUSES.map((s) => (
                <option key={s} value={s}>
                  {humanize(s)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label="Available from" error={errors.available_from}>
          {(fid, invalid) => (
            <Input
              id={fid}
              type="date"
              invalid={invalid}
              value={form.available_from}
              onChange={(e) => onChange('available_from', e.target.value)}
            />
          )}
        </Field>
      </div>

      <Field
        label="Amenities"
        error={errors.amenities}
        hint="Comma-separated, e.g. Air conditioning, Parking, Borehole"
      >
        {(fid, invalid) => (
          <Input
            id={fid}
            invalid={invalid}
            placeholder="Air conditioning, Parking, Borehole"
            value={form.amenities}
            onChange={(e) => onChange('amenities', e.target.value)}
          />
        )}
      </Field>
    </div>
  );
}
