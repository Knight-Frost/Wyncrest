/**
 * Step 4 — Features & amenities.
 *
 * Amenity tags (furnishing, parking, utilities, accessibility, etc.) persist to
 * the unit's real `amenities` json array. Pets policy persists to the listing's
 * dedicated `pets_allowed` / `pet_policy` columns. Every control here saves to a
 * real column — nothing decorative.
 */
import { Field, Textarea } from '../fields';
import { AMENITY_OPTIONS, type StepProps } from '../types';

function CheckIcon() {
  return (
    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M20 6 9 17l-5-5" />
    </svg>
  );
}

export function AmenitiesStep({ form, set, errors }: StepProps) {
  function toggle(amenity: string) {
    const has = form.amenities.includes(amenity);
    set('amenities', has ? form.amenities.filter((a) => a !== amenity) : [...form.amenities, amenity]);
  }

  return (
    <>
      <Field label="Amenities & features" help="Select everything this unit offers. Saved with the unit.">
        <div className="cl-chips" role="group" aria-label="Amenities">
          {AMENITY_OPTIONS.map((a) => {
            const on = form.amenities.includes(a);
            return (
              <button
                key={a}
                type="button"
                className={`cl-chip${on ? ' on' : ''}`}
                aria-pressed={on}
                onClick={() => toggle(a)}
              >
                <span className="cl-chip-check">{on && <CheckIcon />}</span>
                {a}
              </button>
            );
          })}
        </div>
      </Field>

      <p className="cl-group-label">Pets policy</p>
      <div className="cl-toggle-row">
        <div>
          <div style={{ fontSize: 14, fontWeight: 600, color: 'var(--color-ink-800)' }}>Pets allowed</div>
          <div style={{ fontSize: 12.5, color: 'var(--color-ink-500)', marginTop: 2 }}>
            Whether tenants can keep pets in this unit.
          </div>
        </div>
        <button
          type="button"
          role="switch"
          aria-checked={form.petsAllowed}
          aria-label="Pets allowed"
          className={`cl-toggle${form.petsAllowed ? ' on' : ''}`}
          onClick={() => set('petsAllowed', !form.petsAllowed)}
        />
      </div>

      {form.petsAllowed && (
        <Field
          label="Pet policy"
          required
          htmlFor="cl-petpolicy"
          error={errors.petPolicy}
          counter={`${form.petPolicy.length}/1000`}
          help="Describe any pet rules, deposits, or restrictions."
        >
          <Textarea
            id="cl-petpolicy"
            value={form.petPolicy}
            maxLength={1000}
            invalid={!!errors.petPolicy}
            placeholder="e.g. Small pets welcome with an additional deposit. No more than two pets per unit."
            onChange={(e) => set('petPolicy', e.target.value)}
          />
        </Field>
      )}
    </>
  );
}
