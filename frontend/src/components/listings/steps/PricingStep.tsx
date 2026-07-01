/**
 * Step 3 — Pricing.
 *
 * Monthly rent + security deposit persist to the UNIT (GH₵, decimal columns);
 * lease duration + move-in date persist to the LISTING; availability date to the
 * unit. "Service charge"/"additional fees" from the spec are OMITTED — there are
 * no backend columns, and this builder never shows fields that save nowhere.
 */
import { Field, NativeSelect, TextInput } from '../fields';
import type { StepProps } from '../types';

const LEASE_TERMS = [
  { value: '6', label: '6 months' },
  { value: '12', label: '12 months' },
  { value: '18', label: '18 months' },
  { value: '24', label: '24 months' },
  { value: '36', label: '36 months' },
];

export function PricingStep({ form, set, errors }: StepProps) {
  return (
    <>
      <div className="cl-grid-2">
        <Field label="Monthly rent" required htmlFor="cl-rent" error={errors.rentAmount} help="In Ghana cedis.">
          <TextInput
            id="cl-rent"
            type="number"
            min={0}
            step="0.01"
            prefix="GH₵"
            value={form.rentAmount}
            invalid={!!errors.rentAmount}
            placeholder="e.g. 2500"
            onChange={(e) => set('rentAmount', e.target.value)}
          />
        </Field>
        <Field label="Security deposit" htmlFor="cl-deposit" error={errors.securityDeposit} help="Optional.">
          <TextInput
            id="cl-deposit"
            type="number"
            min={0}
            step="0.01"
            prefix="GH₵"
            value={form.securityDeposit}
            invalid={!!errors.securityDeposit}
            placeholder="e.g. 5000"
            onChange={(e) => set('securityDeposit', e.target.value)}
          />
        </Field>
      </div>

      <div className="cl-grid-2">
        <Field label="Lease duration" htmlFor="cl-lease" help="Optional.">
          <NativeSelect
            id="cl-lease"
            placeholder="Select"
            value={form.leaseDurationMonths}
            options={LEASE_TERMS}
            onChange={(e) => set('leaseDurationMonths', e.target.value)}
          />
        </Field>
        <Field label="Available from" htmlFor="cl-avail" error={errors.availableFrom} help="When the unit can be occupied.">
          <TextInput
            id="cl-avail"
            type="date"
            value={form.availableFrom}
            invalid={!!errors.availableFrom}
            onChange={(e) => set('availableFrom', e.target.value)}
          />
        </Field>
      </div>

      <Field label="Earliest move-in date" htmlFor="cl-movein" error={errors.moveInDate} help="Optional. Applicants will see this on the listing.">
        <TextInput
          id="cl-movein"
          type="date"
          value={form.moveInDate}
          invalid={!!errors.moveInDate}
          onChange={(e) => set('moveInDate', e.target.value)}
        />
      </Field>
    </>
  );
}
