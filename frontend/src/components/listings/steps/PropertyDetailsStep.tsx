/**
 * Step 2 — Property details.
 *
 * The property already exists (created in Properties), so its identity/address
 * are shown as a READ-ONLY real summary — never invented. The one editable field
 * is "Building notes", which persists to the property's `description` column.
 * Full property edits live in the Properties area (linked).
 */
import { Link } from 'react-router';
import type { Property } from '@/lib/types';
import { Field, Textarea } from '../fields';
import type { StepProps } from '../types';

const TYPE_LABELS: Record<string, string> = {
  single_family: 'Single-family home',
  multi_family: 'Multi-family',
  apartment: 'Apartment',
  condo: 'Condo',
  townhouse: 'Townhouse',
  commercial: 'Commercial',
  other: 'Other',
};

interface PropertyDetailsStepProps extends StepProps {
  property: Property | null;
}

export function PropertyDetailsStep({ form, set, property }: PropertyDetailsStepProps) {
  if (!property) {
    return <div className="cl-banner info">Select a unit in Step 1 to load its property.</div>;
  }

  const address = [property.street_address, property.street_address_2, property.city, property.state]
    .filter(Boolean)
    .join(', ');

  return (
    <>
      <div className="cl-review-card">
        <h4>From the property record</h4>
        <dl style={{ margin: 0 }}>
          <div className="cl-review-row"><dt>Property name</dt><dd>{property.name}</dd></div>
          <div className="cl-review-row"><dt>Type</dt><dd>{TYPE_LABELS[property.property_type] ?? property.property_type}</dd></div>
          <div className="cl-review-row"><dt>Address</dt><dd>{address || '—'}</dd></div>
          <div className="cl-review-row"><dt>Area / city</dt><dd>{property.city || '—'}</dd></div>
          {property.year_built && <div className="cl-review-row"><dt>Year built</dt><dd>{property.year_built}</dd></div>}
        </dl>
      </div>

      <p className="cl-help">
        These details come from the property record.{' '}
        <Link to="/app/properties" style={{ color: 'var(--color-brand-700)', fontWeight: 600 }}>
          Edit in Properties
        </Link>{' '}
        if anything is out of date.
      </p>

      <Field
        label="Building notes"
        htmlFor="cl-bnotes"
        counter={`${form.buildingNotes.length}/1000`}
        help="Optional. Add nearby landmarks, access notes, or anything else useful about the building."
      >
        <Textarea
          id="cl-bnotes"
          value={form.buildingNotes}
          maxLength={1000}
          placeholder="e.g. Gated community near A&C Mall, 24-hour security, ample street parking…"
          onChange={(e) => set('buildingNotes', e.target.value)}
        />
      </Field>
    </>
  );
}
