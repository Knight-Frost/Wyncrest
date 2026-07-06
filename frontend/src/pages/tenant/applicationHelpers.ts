/**
 * Shared helpers for the tenant Applications experience (list / detail / form).
 * Everything here derives from REAL Application data — no fabricated fields.
 */
import type {
  Application,
  ApplicationFormData,
  ApplicationStatus,
  TenantDocument,
  DocumentType,
} from '@/lib/types';
import type { SemanticRole } from '@/components/cards';

export const STATUS_LABEL: Record<ApplicationStatus, string> = {
  draft: 'Draft',
  submitted: 'Submitted',
  in_review: 'Under review',
  landlord_review: 'Landlord review',
  needs_action: 'Needs action',
  approved: 'Approved',
  rejected: 'Not selected',
  withdrawn: 'Withdrawn',
};

export const STATUS_ROLE: Record<ApplicationStatus, SemanticRole> = {
  draft: 'neutral',
  submitted: 'info',
  in_review: 'info',
  landlord_review: 'info',
  needs_action: 'warning',
  approved: 'success',
  rejected: 'danger',
  withdrawn: 'neutral',
};

export function isPastStatus(s: ApplicationStatus): boolean {
  return s === 'rejected' || s === 'withdrawn';
}

export function isActiveStatus(s: ApplicationStatus): boolean {
  return (
    s === 'submitted' ||
    s === 'in_review' ||
    s === 'landlord_review' ||
    s === 'needs_action'
  );
}

export function canWithdraw(s: ApplicationStatus): boolean {
  return isActiveStatus(s);
}

/* ── Guided-form sections ──────────────────────────────────────────────────── */

export const SECTIONS = [
  'Property',
  'Personal',
  'Employment & income',
  'Rental history',
  'Household',
  'Documents',
  'Review & submit',
] as const;

/** Application documents that fulfil the guided-form requirements. */
export const APP_DOC_REQUIREMENTS: {
  key: DocumentType;
  label: string;
  required: boolean;
  rule: string;
}[] = [
  {
    key: 'proof_of_income',
    label: 'Proof of income',
    required: true,
    rule: 'Pay stub, offer letter, or bank statement · PDF/JPG/PNG · max 10 MB',
  },
  {
    key: 'application_attachment',
    label: 'Supporting document',
    required: false,
    rule: 'References, employment letter, or anything that strengthens your application',
  },
];

export function hasDocOfType(docs: TenantDocument[] | undefined, type: DocumentType): boolean {
  return (docs ?? []).some((d) => d.document_type === type);
}

/**
 * Whether a given form section is complete — from the REAL form_data + attached
 * documents. Section 0 (property) is always complete; 6 (review) is gated by the
 * others.
 */
export function sectionDone(
  form: ApplicationFormData | null,
  docs: TenantDocument[] | undefined,
  index: number,
): boolean {
  const f = form ?? {};
  switch (index) {
    case 0:
      return true;
    case 1:
      return Boolean(
        f.personal?.first && f.personal?.last && f.personal?.email && f.personal?.phone,
      );
    case 2:
      return Boolean(f.employment?.status && f.employment?.income);
    case 3:
      return Boolean(f.rental?.curType && f.rental?.moveIn);
    case 4:
      return Boolean(f.household?.adults);
    case 5:
      return APP_DOC_REQUIREMENTS.filter((r) => r.required).every((r) =>
        hasDocOfType(docs, r.key),
      );
    default:
      return false;
  }
}

export function sectionsComplete(
  form: ApplicationFormData | null,
  docs: TenantDocument[] | undefined,
): number {
  let n = 0;
  for (let i = 0; i < SECTIONS.length - 1; i++) {
    if (sectionDone(form, docs, i)) n++;
  }
  return n;
}

/** How ready a draft is to submit (all pre-review sections complete). */
export function draftReadyToSubmit(
  form: ApplicationFormData | null,
  docs: TenantDocument[] | undefined,
): boolean {
  for (let i = 0; i < SECTIONS.length - 1; i++) {
    if (!sectionDone(form, docs, i)) return false;
  }
  return true;
}

/* ── Progress ──────────────────────────────────────────────────────────────── */

export function progressText(app: Application): string {
  if (app.status === 'draft') {
    const done = sectionsComplete(app.form_data, app.documents);
    return `${done} of ${SECTIONS.length - 1} sections complete`;
  }
  return STATUS_LABEL[app.status];
}

export function progressPercent(app: Application): number {
  if (app.status === 'draft') {
    return (sectionsComplete(app.form_data, app.documents) / (SECTIONS.length - 1)) * 100;
  }
  if (app.status === 'submitted') return 40;
  if (app.status === 'in_review' || app.status === 'needs_action') return 65;
  if (app.status === 'landlord_review') return 85;
  return 100;
}

export type StepState = 'done' | 'current' | 'todo';

/** Post-submission review stepper, driven entirely by real status. */
export function reviewSteps(app: Application): { label: string; state: StepState }[] {
  const s = app.status;
  const steps: { label: string; state: StepState }[] = [
    { label: 'Started', state: 'done' },
    { label: 'Submitted', state: 'done' },
  ];
  if (s === 'submitted') {
    steps.push({ label: 'Under review', state: 'current' }, { label: 'Decision', state: 'todo' });
  } else if (s === 'in_review' || s === 'landlord_review') {
    steps.push({ label: 'Under review', state: 'current' }, { label: 'Decision', state: 'todo' });
  } else if (s === 'needs_action') {
    steps.push(
      { label: 'Needs action', state: 'current' },
      { label: 'Resubmitted', state: 'todo' },
      { label: 'Decision', state: 'todo' },
    );
  } else if (s === 'approved') {
    steps.push(
      { label: 'Reviewed', state: 'done' },
      { label: 'Approved', state: 'done' },
    );
  } else if (s === 'rejected') {
    steps.push(
      { label: 'Reviewed', state: 'done' },
      { label: 'Not selected', state: 'done' },
    );
  } else if (s === 'withdrawn') {
    steps.push({ label: 'Withdrawn', state: 'done' });
  }
  return steps;
}

/* ── Property display helpers ──────────────────────────────────────────────── */

export function homeTitle(app: Application): string {
  return app.listing?.title ?? `Application #${app.id}`;
}

export function unitLabel(app: Application): string | null {
  const n = app.listing?.unit?.unit_number;
  return n ? `Unit ${n}` : null;
}

export function homeAddress(app: Application): string {
  const p = app.listing?.unit?.property;
  if (!p) return '';
  return [p.street_address, p.city, p.state].filter(Boolean).join(', ');
}

/** Unit rent as a decimal dollar string (GH₵) — Unit.rent_amount is "1500.00". */
export function rentAmount(app: Application): string | null {
  return app.listing?.unit?.rent_amount ?? null;
}
