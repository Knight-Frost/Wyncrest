/**
 * MaintenanceRequestForm — the tenant "repair report" intake form, rendered on
 * its own dedicated page (/app/maintenance/new). This is a full report, not a
 * bare note: what's wrong, WHERE it is, HOW urgent, WHEN it started, PHOTOS as
 * evidence, safety/damage flags, and access + contact preferences — everything
 * a landlord needs to triage and schedule without playing detective.
 *
 * TRUTHFULNESS:
 *  - Every option maps 1:1 to a backend enum (App\Enums\Maintenance*); the
 *    payload matches StoreMaintenanceRequest exactly.
 *  - Photos are REAL: on submit we create the request, then upload each queued
 *    photo to POST /tenant/maintenance/{id}/media (restricted MediaAssets the
 *    landlord is authorized to view). No fake "attachments" affordance.
 *  - No "Save draft" button — the maintenance backend has no draft state.
 *  - Priority "Emergency" maps to the backend `urgent`; only the label differs.
 */
import { useRef, useState } from 'react';
import { AlertTriangle, ImagePlus, X, Wrench, CheckCircle2 } from 'lucide-react';
import { tenantApi } from '@/lib/endpoints';
import { fieldErrors } from '@/lib/api';
import type {
  ApiError,
  CreateMaintenancePayload,
  MaintenanceAccess,
  MaintenanceArea,
  MaintenanceCategory,
  MaintenanceContactMethod,
  MaintenanceOnset,
  MaintenancePriority,
  MaintenanceSafetyFlag,
  MaintenanceVisitWindow,
} from '@/lib/types';
import {
  ACCESS_OPTIONS,
  AREA_OPTIONS,
  CATEGORY_OPTIONS,
  CONTACT_OPTIONS,
  ONSET_OPTIONS,
  PRIORITY_OPTIONS,
  PHOTO_ACCEPT,
  PHOTO_ACCEPT_LABEL,
  PHOTO_MAX_BYTES,
  PHOTO_MAX_FILES,
  SAFETY_OPTIONS,
  VISIT_OPTIONS,
  areaLabel,
  onsetLabel,
  accessLabel,
} from './maintenanceIntake';

interface QueuedPhoto {
  id: string;
  file: File;
  url: string;
}

interface Draft {
  title: string;
  description: string;
  category: MaintenanceCategory | '';
  priority: MaintenancePriority | '';
  area: MaintenanceArea | '';
  specific_location: string;
  onset: MaintenanceOnset | '';
  safety_flags: MaintenanceSafetyFlag[];
  access_permission: MaintenanceAccess | '';
  preferred_visit_window: MaintenanceVisitWindow | '';
  preferred_contact_method: MaintenanceContactMethod | '';
  access_instructions: string;
}

const EMPTY: Draft = {
  title: '',
  description: '',
  category: '',
  priority: '',
  area: '',
  specific_location: '',
  onset: '',
  safety_flags: [],
  access_permission: '',
  preferred_visit_window: '',
  preferred_contact_method: '',
  access_instructions: '',
};

interface SuccessInfo {
  id: number;
  reference: string;
  title: string;
  location: string;
  uploaded: number;
  failed: number;
}

interface MaintenanceRequestFormProps {
  /** The tenant's real active lease id (UUID). */
  contractId: string;
  /** Human label for the lease this request is filed against (property · unit). */
  leaseLabel?: string;
  /** Navigate to the request's detail page after success. */
  onViewRequest: (id: number) => void;
  /** Navigate back to the maintenance overview. */
  onBackToList: () => void;
}

let uid = 0;
const nextId = () => `p${uid++}`;

export function MaintenanceRequestForm({
  contractId,
  leaseLabel,
  onViewRequest,
  onBackToList,
}: MaintenanceRequestFormProps) {
  const [form, setForm] = useState<Draft>(EMPTY);
  const [photos, setPhotos] = useState<QueuedPhoto[]>([]);
  const [photoError, setPhotoError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [generalError, setGeneralError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [uploadNote, setUploadNote] = useState<string | null>(null);
  const [success, setSuccess] = useState<SuccessInfo | null>(null);
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  function set<K extends keyof Draft>(key: K, value: Draft[K]) {
    setForm((f) => ({ ...f, [key]: value }));
    setErrors((prev) => {
      if (!prev[key]) return prev;
      const next = { ...prev };
      delete next[key as string];
      return next;
    });
  }

  function toggleSafety(flag: MaintenanceSafetyFlag) {
    setForm((f) => ({
      ...f,
      safety_flags: f.safety_flags.includes(flag)
        ? f.safety_flags.filter((x) => x !== flag)
        : [...f.safety_flags, flag],
    }));
  }

  /* ---- Photos --------------------------------------------------------- */
  function addFiles(list: FileList | null) {
    if (!list || list.length === 0) return;
    setPhotoError(null);
    const accepted = new Set(PHOTO_ACCEPT.split(','));
    const additions: QueuedPhoto[] = [];
    let rejected: string | null = null;

    for (const file of Array.from(list)) {
      if (photos.length + additions.length >= PHOTO_MAX_FILES) {
        rejected = `You can attach up to ${PHOTO_MAX_FILES} photos.`;
        break;
      }
      if (!accepted.has(file.type)) {
        rejected = 'Only JPG, PNG or WEBP images are supported.';
        continue;
      }
      if (file.size > PHOTO_MAX_BYTES) {
        rejected = 'Each photo must be 8 MB or smaller.';
        continue;
      }
      additions.push({ id: nextId(), file, url: URL.createObjectURL(file) });
    }

    if (additions.length) setPhotos((p) => [...p, ...additions]);
    if (rejected) setPhotoError(rejected);
  }

  function removePhoto(id: string) {
    setPhotos((p) => {
      const target = p.find((x) => x.id === id);
      if (target) URL.revokeObjectURL(target.url);
      return p.filter((x) => x.id !== id);
    });
  }

  /* ---- Submit --------------------------------------------------------- */
  function validate(): boolean {
    const e: Record<string, string> = {};
    if (!form.title.trim()) e.title = 'Give the issue a short title.';
    if (!form.description.trim()) e.description = 'Describe what is happening.';
    if (!form.category) e.category = 'Choose a category.';
    if (!form.priority) e.priority = 'Choose how urgent this is.';
    if (!form.area) e.area = 'Where is the issue?';
    if (!form.onset) e.onset = 'When did it start?';
    if (!form.access_permission) e.access_permission = 'Let us know about access.';
    setErrors(e);
    if (Object.keys(e).length > 0) {
      // Scroll the first error into view for long forms.
      const first = document.querySelector<HTMLElement>('[data-invalid="true"]');
      first?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return false;
    }
    return true;
  }

  async function handleSubmit(evt: React.FormEvent) {
    evt.preventDefault();
    if (submitting) return;
    setGeneralError(null);
    if (!validate()) return;

    setSubmitting(true);
    try {
      const payload: CreateMaintenancePayload = {
        contract_id: contractId,
        title: form.title.trim(),
        description: form.description.trim(),
        category: form.category as MaintenanceCategory,
        priority: form.priority as MaintenancePriority,
        area: form.area as MaintenanceArea,
        specific_location: form.specific_location.trim() || null,
        onset: form.onset as MaintenanceOnset,
        safety_flags: form.safety_flags,
        access_permission: form.access_permission as MaintenanceAccess,
        preferred_visit_window: form.preferred_visit_window || null,
        preferred_contact_method: form.preferred_contact_method || null,
        access_instructions: form.access_instructions.trim() || null,
      };

      const request = await tenantApi.createMaintenance(payload);

      // Upload queued photos one at a time so a single failure doesn't lose the rest.
      let uploaded = 0;
      let failed = 0;
      for (let i = 0; i < photos.length; i++) {
        setUploadNote(`Uploading photo ${i + 1} of ${photos.length}…`);
        try {
          await tenantApi.uploadMaintenanceMedia(request.id, photos[i].file);
          uploaded++;
        } catch {
          failed++;
        }
      }
      setUploadNote(null);

      photos.forEach((p) => URL.revokeObjectURL(p.url));
      setSuccess({
        id: request.id,
        reference: `MR-${String(request.id).padStart(4, '0')}`,
        title: request.title,
        location: leaseLabel ?? (request.area ? areaLabel[request.area] : 'Your unit'),
        uploaded,
        failed,
      });
    } catch (err) {
      const apiErr = err as ApiError;
      const fe = fieldErrors(apiErr);
      if (Object.keys(fe).length > 0) setErrors(fe);
      else setGeneralError(apiErr.message || 'Something went wrong. Please try again.');
      setSubmitting(false);
      setUploadNote(null);
    }
  }

  /* ---- Success screen ------------------------------------------------- */
  if (success) {
    return (
      <div className="mn-success mn-card">
        <div className="mn-success-badge">
          <CheckCircle2 size={30} />
        </div>
        <h2 className="mn-success-title">Maintenance request submitted</h2>
        <p className="mn-success-sub">
          Your landlord will review the request and update its status. You'll be notified when
          there's an update.
        </p>
        <dl className="mn-success-grid">
          <div><dt>Reference</dt><dd>{success.reference}</dd></div>
          <div><dt>Issue</dt><dd>{success.title}</dd></div>
          <div><dt>Location</dt><dd>{success.location}</dd></div>
          <div><dt>Status</dt><dd><span className="mn-pill mn-pill--new">New</span></dd></div>
          <div>
            <dt>Photos</dt>
            <dd>
              {success.uploaded > 0 ? `${success.uploaded} attached` : 'None attached'}
              {success.failed > 0 ? ` · ${success.failed} failed to upload` : ''}
            </dd>
          </div>
        </dl>
        <div className="mn-form-actions">
          <button type="button" className="mn-btn-ghost" onClick={onBackToList}>
            Back to Maintenance
          </button>
          <button type="button" className="mn-btn-update" onClick={() => onViewRequest(success.id)}>
            View request
          </button>
        </div>
      </div>
    );
  }

  const priorityLabel = PRIORITY_OPTIONS.find((p) => p.value === form.priority)?.label;
  const reviewReady =
    form.title && form.category && form.priority && form.area && form.onset && form.access_permission;

  /* ---- Form ----------------------------------------------------------- */
  return (
    <div className="mn-layout">
      <form className="mn-form2" onSubmit={handleSubmit} noValidate>
        {generalError && <div role="alert" className="mn-notice mn-notice--error">{generalError}</div>}

        {/* A — Issue details */}
        <section className="mn-sec mn-card">
          <div className="mn-sec-head"><h3 className="mn-sec-title">Issue details</h3></div>

          <div className="mn-field">
            <label className="mn-label" htmlFor="mn-title">Title <span>*</span></label>
            <input
              id="mn-title" className={`mn-input${errors.title ? ' mn-input--err' : ''}`}
              placeholder="e.g. Leaking kitchen tap" value={form.title}
              onChange={(e) => set('title', e.target.value)} maxLength={160}
              disabled={submitting} data-invalid={errors.title ? 'true' : undefined} autoFocus
            />
            {errors.title && <span className="mn-field-err">{errors.title}</span>}
          </div>

          <div className="mn-field">
            <label className="mn-label" htmlFor="mn-category">Category <span>*</span></label>
            <select
              id="mn-category" className={`mn-select${errors.category ? ' mn-input--err' : ''}`}
              value={form.category} onChange={(e) => set('category', e.target.value as MaintenanceCategory)}
              disabled={submitting} data-invalid={errors.category ? 'true' : undefined}
            >
              <option value="">Select category</option>
              {CATEGORY_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
            {errors.category && <span className="mn-field-err">{errors.category}</span>}
          </div>

          <div className="mn-field">
            <label className="mn-label" htmlFor="mn-desc">Description <span>*</span></label>
            <textarea
              id="mn-desc" className={`mn-textarea${errors.description ? ' mn-input--err' : ''}`}
              placeholder="Describe what is happening, when you noticed it, where it is, and how severe it is."
              rows={5} value={form.description} onChange={(e) => set('description', e.target.value)}
              maxLength={2000} disabled={submitting} data-invalid={errors.description ? 'true' : undefined}
            />
            <span className="mn-hint">
              Example: Water leaks under the kitchen sink whenever I turn on the tap. It started
              yesterday evening and the cabinet floor is wet.
            </span>
            {errors.description && <span className="mn-field-err">{errors.description}</span>}
          </div>
        </section>

        {/* B — Location */}
        <section className="mn-sec mn-card">
          <div className="mn-sec-head"><h3 className="mn-sec-title">Location</h3></div>
          {leaseLabel && (
            <p className="mn-lease-note">This request will be filed against your lease at <strong>{leaseLabel}</strong>.</p>
          )}
          <div className="mn-form-row">
            <div className="mn-field">
              <label className="mn-label" htmlFor="mn-area">Room or area <span>*</span></label>
              <select
                id="mn-area" className={`mn-select${errors.area ? ' mn-input--err' : ''}`}
                value={form.area} onChange={(e) => set('area', e.target.value as MaintenanceArea)}
                disabled={submitting} data-invalid={errors.area ? 'true' : undefined}
              >
                <option value="">Select area</option>
                {AREA_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
              </select>
              {errors.area && <span className="mn-field-err">{errors.area}</span>}
            </div>
            <div className="mn-field">
              <label className="mn-label" htmlFor="mn-spot">Specific location</label>
              <input
                id="mn-spot" className="mn-input" placeholder="e.g. Under the kitchen sink"
                value={form.specific_location} onChange={(e) => set('specific_location', e.target.value)}
                maxLength={255} disabled={submitting}
              />
            </div>
          </div>
        </section>

        {/* C — Urgency */}
        <section className="mn-sec mn-card">
          <div className="mn-sec-head"><h3 className="mn-sec-title">How urgent is it? <span className="mn-req-star">*</span></h3></div>
          <div className="mn-choice-grid" data-invalid={errors.priority ? 'true' : undefined}>
            {PRIORITY_OPTIONS.map((o) => (
              <button
                type="button" key={o.value}
                className={`mn-choice${form.priority === o.value ? ' selected' : ''}`}
                onClick={() => set('priority', o.value)} disabled={submitting}
                aria-pressed={form.priority === o.value}
              >
                <span className="mn-choice-title">{o.label}</span>
                <span className="mn-choice-hint">{o.hint}</span>
              </button>
            ))}
          </div>
          {errors.priority && <span className="mn-field-err">{errors.priority}</span>}
          <div className="mn-warn">
            <AlertTriangle size={16} />
            <span>
              If this is a fire, gas leak, flood, break-in, or immediate safety issue, contact
              emergency services first — don't wait for a maintenance response.
            </span>
          </div>
        </section>

        {/* D — When it started */}
        <section className="mn-sec mn-card">
          <div className="mn-sec-head"><h3 className="mn-sec-title">When did this start? <span className="mn-req-star">*</span></h3></div>
          <div className="mn-pill-row" data-invalid={errors.onset ? 'true' : undefined}>
            {ONSET_OPTIONS.map((o) => (
              <button
                type="button" key={o.value}
                className={`mn-optpill${form.onset === o.value ? ' selected' : ''}`}
                onClick={() => set('onset', o.value)} disabled={submitting} aria-pressed={form.onset === o.value}
              >
                {o.label}
              </button>
            ))}
          </div>
          {errors.onset && <span className="mn-field-err">{errors.onset}</span>}
        </section>

        {/* E — Safety & damage */}
        <section className="mn-sec mn-card">
          <div className="mn-sec-head">
            <h3 className="mn-sec-title">Safety &amp; damage</h3>
            <span className="mn-sec-hint">Tick anything that applies so we can triage quickly.</span>
          </div>
          <div className="mn-check-grid">
            {SAFETY_OPTIONS.map((o) => {
              const on = form.safety_flags.includes(o.value);
              return (
                <button
                  type="button" key={o.value}
                  className={`mn-check${on ? ' selected' : ''}${o.severe ? ' severe' : ''}`}
                  onClick={() => toggleSafety(o.value)} disabled={submitting} aria-pressed={on}
                >
                  <span className="mn-check-box" aria-hidden="true">{on ? '✓' : ''}</span>
                  <span>{o.label}</span>
                </button>
              );
            })}
          </div>
          {form.safety_flags.length === 0 && (
            <p className="mn-hint">Nothing ticked — that's fine if none of these apply.</p>
          )}
        </section>

        {/* F — Photos */}
        <section className="mn-sec mn-card">
          <div className="mn-sec-head">
            <h3 className="mn-sec-title">Photos</h3>
            <span className="mn-sec-hint">Clear photos help your landlord understand the problem faster.</span>
          </div>
          <button
            type="button" className="mn-drop" onClick={() => fileInputRef.current?.click()}
            disabled={submitting || photos.length >= PHOTO_MAX_FILES}
          >
            <ImagePlus size={22} />
            <span className="mn-drop-title">Add photos</span>
            <span className="mn-drop-sub">{PHOTO_ACCEPT_LABEL}</span>
          </button>
          <input
            ref={fileInputRef} type="file" accept={PHOTO_ACCEPT} multiple hidden
            onChange={(e) => { addFiles(e.target.files); e.target.value = ''; }}
          />
          {photoError && <span className="mn-field-err">{photoError}</span>}
          {photos.length > 0 && (
            <div className="mn-thumbs">
              {photos.map((p) => (
                <div className="mn-thumb" key={p.id}>
                  <img src={p.url} alt={p.file.name} />
                  <button type="button" className="mn-thumb-x" onClick={() => removePhoto(p.id)} aria-label={`Remove ${p.file.name}`} disabled={submitting}>
                    <X size={13} />
                  </button>
                </div>
              ))}
            </div>
          )}
        </section>

        {/* G — Access & contact */}
        <section className="mn-sec mn-card">
          <div className="mn-sec-head"><h3 className="mn-sec-title">Access &amp; contact</h3></div>
          <div className="mn-field">
            <label className="mn-label">Can maintenance enter if you're not home? <span>*</span></label>
            <div className="mn-pill-row" data-invalid={errors.access_permission ? 'true' : undefined}>
              {ACCESS_OPTIONS.map((o) => (
                <button
                  type="button" key={o.value}
                  className={`mn-optpill${form.access_permission === o.value ? ' selected' : ''}`}
                  onClick={() => set('access_permission', o.value)} disabled={submitting}
                  aria-pressed={form.access_permission === o.value}
                >
                  {o.label}
                </button>
              ))}
            </div>
            {errors.access_permission && <span className="mn-field-err">{errors.access_permission}</span>}
          </div>
          <div className="mn-form-row">
            <div className="mn-field">
              <label className="mn-label" htmlFor="mn-visit">Best time for a visit</label>
              <select id="mn-visit" className="mn-select" value={form.preferred_visit_window}
                onChange={(e) => set('preferred_visit_window', e.target.value as MaintenanceVisitWindow)} disabled={submitting}>
                <option value="">No preference</option>
                {VISIT_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
              </select>
            </div>
            <div className="mn-field">
              <label className="mn-label" htmlFor="mn-contact">Preferred contact</label>
              <select id="mn-contact" className="mn-select" value={form.preferred_contact_method}
                onChange={(e) => set('preferred_contact_method', e.target.value as MaintenanceContactMethod)} disabled={submitting}>
                <option value="">No preference</option>
                {CONTACT_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
              </select>
            </div>
          </div>
          <div className="mn-field">
            <label className="mn-label" htmlFor="mn-access-notes">Access instructions</label>
            <textarea id="mn-access-notes" className="mn-textarea" rows={2}
              placeholder="e.g. Please call before arrival. Dog is in the bedroom."
              value={form.access_instructions} onChange={(e) => set('access_instructions', e.target.value)}
              maxLength={500} disabled={submitting} />
            <span className="mn-hint">Avoid sharing sensitive codes or keys here.</span>
          </div>
        </section>

        {/* H — Review + actions */}
        <section className="mn-sec mn-card">
          <div className="mn-sec-head"><h3 className="mn-sec-title">Review</h3></div>
          {reviewReady ? (
            <div className="mn-review">
              <ReviewRow k="Issue" v={form.title} />
              <ReviewRow k="Category" v={CATEGORY_OPTIONS.find((c) => c.value === form.category)?.label ?? '—'} />
              {leaseLabel && <ReviewRow k="Lease" v={leaseLabel} />}
              <ReviewRow k="Area" v={form.area ? areaLabel[form.area] : '—'} />
              <ReviewRow k="Urgency" v={priorityLabel ?? '—'} />
              <ReviewRow k="Started" v={form.onset ? onsetLabel[form.onset] : '—'} />
              <ReviewRow k="Safety flags" v={form.safety_flags.length ? String(form.safety_flags.length) : 'None'} />
              <ReviewRow k="Photos" v={photos.length ? String(photos.length) : 'None'} />
              <ReviewRow k="Access" v={form.access_permission ? accessLabel[form.access_permission] : '—'} />
            </div>
          ) : (
            <p className="mn-hint">Fill in the required fields above to review your request before sending.</p>
          )}
          {uploadNote && <p className="mn-hint" role="status">{uploadNote}</p>}
          <div className="mn-form-actions">
            <button type="button" className="mn-btn-ghost" onClick={onBackToList} disabled={submitting}>Cancel</button>
            <button type="submit" className="mn-btn-update" disabled={submitting}>
              {submitting ? (uploadNote ? 'Uploading…' : 'Submitting…') : 'Submit request'}
            </button>
          </div>
        </section>
      </form>

      {/* Tips rail */}
      <aside className="mn-rail">
        <div className="mn-rail-card mn-card">
          <div className="mn-rail-head"><Wrench size={17} /> Tips for faster repairs</div>
          <ol className="mn-rail-list">
            <li>Upload clear photos of the issue.</li>
            <li>Describe exactly where the problem is.</li>
            <li>Say when it started.</li>
            <li>Flag anything involving water, electricity, locks, or safety.</li>
          </ol>
        </div>
      </aside>
    </div>
  );
}

function ReviewRow({ k, v }: { k: string; v: string }) {
  return (
    <div className="mn-review-row">
      <span className="mn-review-k">{k}</span>
      <span className="mn-review-v">{v}</span>
    </div>
  );
}
