/**
 * AdminContractCaseFile — the full lease case-file page for admins.
 *
 * Rendered at /app/contracts/:id when the signed-in user is an admin (see the
 * role branch in pages/shared/ContractDetail.tsx). A dedicated routed page —
 * never a modal/drawer — because a lease is a legal + financial record that
 * deserves its own URL, breadcrumb, and loading/error states.
 *
 * Every figure traces to ContractCaseFileService / LedgerComputationEngine.
 * Sections with no real backing data (documents, renewal actions) render a
 * truthful empty/read-only state rather than being cut or faked.
 */
import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { adminApi } from '@/lib/endpoints';
import { formatCents, formatDate, formatDateTime, humanize } from '@/lib/format';
import { useAuth } from '@/context/auth';
import { adminHasCapability } from '@/lib/permissions';
import { Button } from '@/components/ui/Button';
import { SemanticBadge } from '@/components/cards';
import type { SemanticRole } from '@/components/cards';
import { DestructiveConfirmDialog } from '@/components/ui/DestructiveConfirmDialog';
import { ErrorState, LoadingState } from '@/components/ui/states';
import {
  IconArrowLeft,
  IconLedger,
  IconAlertTriangle,
  IconCheckCircle,
  IconDoc,
} from '@/components/ui/icons';
import type {
  ContractBillingPeriod,
  ContractCaseFileDetail,
  ContractLedgerResponse,
  ContractNote,
  ContractPayment,
  ContractTimelineEvent,
  ContractDocument as ContractDocumentType,
  ContractChecklistItem,
} from '@/lib/types';
// Imported here too (not just AdminContractsPage) so a direct deep-link to
// /app/contracts/:id — reached without ever mounting the list page — still
// loads these styles on first paint.
import './contract-case-file.css';

/* ── Local presentational helpers (mirrors AuditLogDetail's Card/DL pattern) ── */

function Section({
  id,
  index,
  title,
  hint,
  children,
}: {
  id: string;
  index: string;
  title: string;
  hint?: string;
  children: React.ReactNode;
}) {
  return (
    <section id={id} className="ccf-sec scroll-mt-32">
      <div className="ccf-sec-head">
        <h2>
          <span className="ccf-sec-n">{index}</span> {title}
        </h2>
        {hint && <span className="ccf-sec-hint">{hint}</span>}
      </div>
      {children}
    </section>
  );
}

function SumCard({
  label,
  value,
  sub,
  tone,
}: {
  label: string;
  value: React.ReactNode;
  sub?: React.ReactNode;
  tone?: 'good' | 'warn' | 'bad';
}) {
  return (
    <div className={`ccf-sumcard${tone ? ` ${tone}` : ''}`}>
      <div className="ccf-sumcard-label">{label}</div>
      <div className="ccf-sumcard-value">{value}</div>
      {sub && <div className="ccf-sumcard-sub">{sub}</div>}
    </div>
  );
}

function DL({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="ccf-kv">
      <span className="ccf-kv-k">{label}</span>
      <span className="ccf-kv-v">{value}</span>
    </div>
  );
}

const CHECKLIST_ICON: Record<ContractChecklistItem['status'], React.ReactNode> = {
  pass: <IconCheckCircle size={13} />,
  warn: <IconAlertTriangle size={13} />,
  fail: <IconAlertTriangle size={13} />,
};

function ChecklistRow({ item }: { item: ContractChecklistItem }) {
  return (
    <div className={`ccf-check ccf-check-${item.status}`}>
      <span className="ccf-check-ic">{CHECKLIST_ICON[item.status]}</span>
      <span>{item.label}</span>
    </div>
  );
}

function PartyCard({
  role,
  party,
  onOpen,
}: {
  role: 'Tenant' | 'Landlord';
  party: ContractCaseFileDetail['parties']['tenant'];
  onOpen: () => void;
}) {
  if (!party) {
    return (
      <div className="ccf-subcard">
        <div className="ccf-eyebrow">{role}</div>
        <p className="ccf-empty-text">Name not provided.</p>
      </div>
    );
  }
  return (
    <div className="ccf-subcard">
      <div className="ccf-person">
        <div className="ccf-person-avatar">{party.name.slice(0, 2).toUpperCase()}</div>
        <div>
          <div className="ccf-person-name">{party.name}</div>
          <div className="ccf-person-role">{role}</div>
        </div>
      </div>
      <DL label="Email" value={party.email ?? 'Not provided'} />
      <DL label="Phone" value={party.phone ?? 'Not provided'} />
      <DL
        label="Verification"
        value={
          <SemanticBadge role={party.identity_verified ? 'success' : 'warning'}>
            {party.identity_verified ? 'Verified' : humanize(party.verification_status ?? 'unverified')}
          </SemanticBadge>
        }
      />
      <DL label="Account status" value={humanize(party.account_status ?? 'unknown')} />
      <DL label="Contract balance" value={formatCents(party.contract_balance_cents)} />
      <div className="ccf-subcard-foot">
        <Button variant="secondary" size="sm" onClick={onOpen}>
          Open {role.toLowerCase()} profile →
        </Button>
      </div>
    </div>
  );
}

const TIMELINE_TONE: Record<ContractTimelineEvent['severity'], string> = {
  info: '',
  success: 'ccf-tl-green',
  danger: 'ccf-tl-blood',
};

const HEALTH_LABEL: Record<ContractCaseFileDetail['health'], string> = {
  draft: 'Draft',
  awaiting_signatures: 'Awaiting signatures',
  good_standing: 'Good standing',
  ending_soon: 'Ending soon',
  overdue: 'Overdue',
  closed: 'Closed',
};

const HEALTH_TONE: Record<ContractCaseFileDetail['health'], SemanticRole> = {
  draft: 'neutral',
  awaiting_signatures: 'warning',
  good_standing: 'success',
  ending_soon: 'warning',
  overdue: 'danger',
  closed: 'neutral',
};

/* ── Page ─────────────────────────────────────────────────────────────────── */

interface CaseFileBundle {
  detail: ContractCaseFileDetail;
  ledger: ContractLedgerResponse;
  payments: ContractPayment[];
  schedule: ContractBillingPeriod[];
  timeline: ContractTimelineEvent[];
  documents: ContractDocumentType[];
}

const SECTIONS: { id: string; label: string }[] = [
  { id: 'overview', label: 'Overview' },
  { id: 'warnings', label: 'Integrity' },
  { id: 'parties', label: 'Parties' },
  { id: 'property', label: 'Property' },
  { id: 'terms', label: 'Terms' },
  { id: 'ledger', label: 'Ledger' },
  { id: 'payments', label: 'Payments' },
  { id: 'schedule', label: 'Schedule' },
  { id: 'documents', label: 'Documents' },
  { id: 'timeline', label: 'Timeline' },
  { id: 'renewal', label: 'Renewal' },
  { id: 'notes', label: 'Notes' },
];

export function AdminContractCaseFile() {
  const navigate = useNavigate();
  const { id = '' } = useParams<{ id: string }>();
  const { user: viewer } = useAuth();
  const canManage = adminHasCapability(viewer, 'manage_contracts');

  const { data, loading, error, reload } = useApi<CaseFileBundle>(async () => {
    const [detail, ledger, payments, schedule, timeline, documents] = await Promise.all([
      adminApi.contract(id),
      adminApi.contractLedger(id),
      adminApi.contractPayments(id),
      adminApi.contractBillingSchedule(id),
      adminApi.contractTimeline(id),
      adminApi.contractDocuments(id),
    ]);
    return { detail, ledger, payments, schedule, timeline, documents };
  }, [id]);

  const [activeSection, setActiveSection] = useState('overview');
  const [terminateOpen, setTerminateOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [noteBody, setNoteBody] = useState('');
  const [notes, setNotes] = useState<ContractNote[]>([]);
  const [notesSubmitting, setNotesSubmitting] = useState(false);

  useEffect(() => {
    if (data) setNotes(data.detail.notes);
  }, [data]);

  // Scroll-spy for the sticky section nav.
  useEffect(() => {
    if (!data) return;
    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) setActiveSection(entry.target.id);
        });
      },
      { rootMargin: '-45% 0px -50% 0px' },
    );
    SECTIONS.forEach((s) => {
      const el = document.getElementById(s.id);
      if (el) observer.observe(el);
    });
    return () => observer.disconnect();
  }, [data]);

  const canTerminate = data?.detail.status === 'active';

  const nextDueDisplay = useMemo(() => {
    const d = data?.detail.financials.next_due_date;
    return d ? formatDate(d) : '—';
  }, [data]);

  async function handleTerminate(reason?: string) {
    const trimmed = (reason ?? '').trim();
    if (!trimmed) return;
    setSubmitting(true);
    setActionError(null);
    try {
      await adminApi.terminateContract(id, trimmed);
      setTerminateOpen(false);
      reload();
    } catch (err) {
      setActionError(err instanceof Error ? err.message : 'Failed to terminate contract.');
    } finally {
      setSubmitting(false);
    }
  }

  async function handleAddNote() {
    const body = noteBody.trim();
    if (!body) return;
    setNotesSubmitting(true);
    try {
      const note = await adminApi.addContractNote(id, body);
      setNotes((prev) => [note, ...prev]);
      setNoteBody('');
    } catch (err) {
      setActionError(err instanceof Error ? err.message : 'Failed to add note.');
    } finally {
      setNotesSubmitting(false);
    }
  }

  const backLink = (
    <div className="ccf-crumb">
      <Link to="/app/contracts" className="ccf-back">
        <IconArrowLeft size={15} />
        Back to Contracts
      </Link>
      <span className="ccf-crumb-sep">/</span>
      <span>Administration</span>
      <span className="ccf-crumb-sep">/</span>
      <span>Contracts</span>
      {data && (
        <>
          <span className="ccf-crumb-sep">/</span>
          <span>Contract #{id.slice(0, 8)}</span>
        </>
      )}
    </div>
  );

  if (!loading && error && error.status === 404) {
    return (
      <div className="ccf-page animate-rise mx-auto max-w-5xl">
        {backLink}
        <div className="ccf-mini-empty">
          <span className="ccf-empty-ico">
            <IconDoc size={28} />
          </span>
          <p className="ccf-empty-title">Contract not found</p>
          <p className="ccf-empty-text">This contract does not exist, or the link is out of date.</p>
          <Button variant="secondary" size="sm" onClick={() => navigate('/app/contracts')}>
            Back to Contracts
          </Button>
        </div>
      </div>
    );
  }

  if (loading && !data) {
    return (
      <div className="ccf-page animate-rise mx-auto max-w-5xl">
        {backLink}
        <LoadingState label="Loading contract case file…" />
      </div>
    );
  }

  if (error) {
    return (
      <div className="ccf-page animate-rise mx-auto max-w-5xl">
        {backLink}
        <ErrorState message={error.message} onRetry={reload} />
      </div>
    );
  }

  if (!data) return null;

  const { detail, ledger, payments, schedule, timeline, documents } = data;

  return (
    <div className="ccf-page ccf-detail animate-rise mx-auto max-w-5xl">
      {backLink}

      {/* ── Header ── */}
      <header className="ccf-chead">
        <div className="ccf-ch-eyebrow">
          Contract WYN-{id.slice(0, 8).toUpperCase()}
        </div>
        <h1 className="ccf-ch-title">
          {detail.property?.name ?? 'Unassigned property'}
          {detail.property?.city ? `, ${detail.property.city}` : ''}
        </h1>
        <div className="ccf-ch-facts">
          <SemanticBadge role={HEALTH_TONE[detail.health]}>{HEALTH_LABEL[detail.health]}</SemanticBadge>
          <span className="ccf-cf">
            Tenant <b>{detail.parties.tenant?.name ?? 'Not provided'}</b>
          </span>
          <span className="ccf-cf">
            Landlord <b>{detail.parties.landlord?.name ?? 'Not provided'}</b>
          </span>
          <span className="ccf-cf">
            <b>{formatCents(detail.rent_amount)}</b>/mo
          </span>
          <span className="ccf-cf">
            {formatDate(detail.start_date)} → {detail.end_date ? formatDate(detail.end_date) : 'open-ended'}
          </span>
        </div>
        <div className="ccf-ch-actions">
          <Button variant="primary" size="sm" onClick={() => document.getElementById('ledger')?.scrollIntoView({ behavior: 'smooth' })}>
            <IconLedger size={15} /> View ledger
          </Button>
          {detail.parties.tenant && (
            <Button
              variant="secondary"
              size="sm"
              onClick={() => navigate('/app/users', { state: { search: detail.parties.tenant!.email ?? detail.parties.tenant!.name } })}
            >
              View tenant
            </Button>
          )}
          {detail.parties.landlord && (
            <Button
              variant="secondary"
              size="sm"
              onClick={() => navigate('/app/users', { state: { search: detail.parties.landlord!.email ?? detail.parties.landlord!.name } })}
            >
              View landlord
            </Button>
          )}
        </div>
      </header>

      {/* ── Sticky section nav ── */}
      <nav className="ccf-secnav" aria-label="Case file sections">
        {SECTIONS.map((s) => (
          <a
            key={s.id}
            href={`#${s.id}`}
            className={activeSection === s.id ? 'active' : ''}
            onClick={(e) => {
              e.preventDefault();
              document.getElementById(s.id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }}
          >
            {s.label}
          </a>
        ))}
        {canTerminate && (
          <a
            href="#actions"
            className="ccf-secnav-danger"
            onClick={(e) => {
              e.preventDefault();
              document.getElementById('actions')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }}
          >
            Actions
          </a>
        )}
      </nav>

      {actionError && (
        <div className="rounded-xl border border-danger-200 bg-danger-50 px-4 py-3 text-sm text-danger-700">
          {actionError}
        </div>
      )}

      {/* 01 — Overview */}
      <Section id="overview" index="01" title="Overview" hint="Computed by the ledger engine">
        <div className="ccf-sumgrid">
          <SumCard label="Monthly rent" value={formatCents(detail.financials.monthly_rent_cents)} sub={`due day ${detail.payment_day}`} />
          <SumCard
            label="Current balance"
            value={formatCents(detail.financials.current_balance_cents)}
            sub={detail.financials.current_balance_cents > 0 ? 'outstanding' : 'nothing outstanding'}
            tone={detail.financials.current_balance_cents > 0 ? 'bad' : 'good'}
          />
          <SumCard
            label="Overdue"
            value={formatCents(detail.financials.overdue_cents)}
            sub={detail.financials.overdue_cents > 0 ? 'past due' : 'no past-due amount'}
            tone={detail.financials.overdue_cents > 0 ? 'bad' : 'good'}
          />
          <SumCard label="Total paid" value={formatCents(detail.financials.total_paid_cents)} />
          <SumCard
            label="Lease remaining"
            value={detail.financials.lease_remaining_days !== null ? `${detail.financials.lease_remaining_days}d` : '—'}
            sub={detail.status === 'terminated' || detail.status === 'expired' ? 'closed' : undefined}
          />
          <SumCard label="Next due" value={nextDueDisplay} />
          <SumCard
            label="Security deposit"
            value={detail.financials.security_deposit_cents !== null ? formatCents(detail.financials.security_deposit_cents) : 'Not specified'}
            sub="held on the unit record"
          />
          <SumCard
            label="Contract health"
            value={<SemanticBadge role={HEALTH_TONE[detail.health]}>{HEALTH_LABEL[detail.health]}</SemanticBadge>}
            sub={detail.warnings.length ? `${detail.warnings.length} note(s)` : 'no issues'}
          />
        </div>
      </Section>

      {/* 02 — Warnings & integrity */}
      <Section id="warnings" index="02" title="Warnings & integrity" hint="From the reconciliation engine">
        <div className={`ccf-integrity-top ${detail.warnings.length ? 'warn' : 'pass'}`}>
          <span className="ccf-integrity-ic">
            {detail.warnings.length ? <IconAlertTriangle size={20} /> : <IconCheckCircle size={20} />}
          </span>
          <div>
            <div className="ccf-integrity-title">
              Ledger integrity: {detail.warnings.length ? `${detail.warnings.length} warning(s)` : 'Passed'}
            </div>
            <div className="ccf-integrity-sub">
              {detail.warnings.length
                ? 'Review the flagged checks below.'
                : `All ${detail.checklist.length} reconciliation checks passed.`}
            </div>
          </div>
        </div>
        {detail.warnings.map((w) => (
          <div key={w.key} className={`ccf-warnrow ${w.severity === 'high' ? 'blood' : 'amber'}`}>
            <IconAlertTriangle size={16} />
            {w.label}
          </div>
        ))}
        <div className="ccf-dl-label">Reconciliation checks</div>
        <div className="ccf-checks">
          {detail.checklist.map((item) => (
            <ChecklistRow key={item.key} item={item} />
          ))}
        </div>
      </Section>

      {/* 03 — Parties */}
      <Section id="parties" index="03" title="Parties involved">
        <div className="ccf-two">
          <PartyCard
            role="Tenant"
            party={detail.parties.tenant}
            onOpen={() =>
              detail.parties.tenant &&
              navigate('/app/users', { state: { search: detail.parties.tenant!.email ?? detail.parties.tenant!.name } })
            }
          />
          <PartyCard
            role="Landlord"
            party={detail.parties.landlord}
            onOpen={() =>
              detail.parties.landlord &&
              navigate('/app/users', { state: { search: detail.parties.landlord!.email ?? detail.parties.landlord!.name } })
            }
          />
        </div>
      </Section>

      {/* 04 — Property & unit */}
      <Section id="property" index="04" title="Property & unit">
        <div className="ccf-two">
          <div className="ccf-subcard">
            <div className="ccf-eyebrow">Property</div>
            {detail.property ? (
              <>
                <DL label="Name" value={detail.property.name} />
                <DL label="Type" value={humanize(detail.property.property_type ?? 'unknown')} />
                <DL label="Address" value={detail.property.full_address ?? 'Not disclosed'} />
                <DL label="City" value={detail.property.city ?? 'Not specified'} />
                <DL label="Verification" value={<SemanticBadge role={detail.property.is_active ? 'success' : 'neutral'}>{detail.property.is_active ? 'Active' : 'Inactive'}</SemanticBadge>} />
              </>
            ) : (
              <p className="ccf-empty-text">No property linked.</p>
            )}
          </div>
          <div className="ccf-subcard">
            <div className="ccf-eyebrow">Unit</div>
            {detail.unit ? (
              <>
                <DL label="Unit" value={detail.unit.display_name} />
                <DL label="Bedrooms / baths" value={`${detail.unit.bedrooms} bed · ${detail.unit.bathrooms} bath`} />
                <DL label="Square feet" value={detail.unit.square_feet ?? 'Not specified'} />
                <DL label="Occupancy" value={<SemanticBadge role={detail.unit.availability_status === 'occupied' ? 'success' : 'warning'}>{detail.unit.availability_label ?? 'Unknown'}</SemanticBadge>} />
                <DL label="Listing status" value={detail.listing ? humanize(detail.listing.status) : 'No listing linked'} />
              </>
            ) : (
              <p className="ccf-empty-text">No unit linked.</p>
            )}
          </div>
        </div>
      </Section>

      {/* 05 — Lease terms */}
      <Section id="terms" index="05" title="Lease terms">
        <div className="ccf-terms">
          <DL label="Lease start" value={formatDate(detail.terms.start_date)} />
          <DL label="Lease end" value={detail.terms.end_date ? formatDate(detail.terms.end_date) : 'Open-ended'} />
          <DL label="Duration" value={detail.terms.duration_months ? `${detail.terms.duration_months} months` : 'Not specified'} />
          <DL label="Rent" value={`${formatCents(detail.terms.rent_amount_cents)} / ${detail.terms.billing_cycle}`} />
          <DL label="Payment due day" value={`Day ${detail.terms.payment_day} of each month`} />
          <DL label="Security deposit" value={detail.terms.security_deposit_cents !== null ? formatCents(detail.terms.security_deposit_cents) : 'Not specified'} />
          <DL label="Grace period" value={detail.terms.grace_period} />
          <DL label="Late fee rule" value={detail.terms.late_fee_rule} />
          <DL label="Utilities" value={detail.terms.utilities_responsibility} />
          <DL label="Maintenance" value={detail.terms.maintenance_responsibility} />
          <DL label="Pets" value={detail.terms.pets_policy} />
          <DL label="Smoking" value={detail.terms.smoking_policy} />
          <DL label="Occupancy limit" value={detail.terms.occupancy_limit} />
          <DL label="Renewal" value={detail.terms.renewal_type} />
          <DL label="Termination notice" value={detail.terms.termination_notice_period} />
          <DL label="Early termination penalty" value={detail.terms.early_termination_penalty} />
        </div>
      </Section>

      {/* 06 — Financial ledger */}
      <Section id="ledger" index="06" title="Financial ledger" hint="This contract only">
        <div className="ccf-tbl-scroll">
          <table className="ccf-tbl">
            <thead>
              <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Period</th>
                <th>Due</th>
                <th>Amount</th>
                <th>Balance impact</th>
                <th>Running balance</th>
                <th>Status</th>
                <th>Reference</th>
              </tr>
            </thead>
            <tbody>
              {ledger.entries.map((entry) => (
                <tr key={entry.id}>
                  <td>{formatDate(entry.occurred_at)}</td>
                  <td>{entry.display_label}</td>
                  <td>{entry.billing_period_start ? formatDate(entry.billing_period_start) : '—'}</td>
                  <td>{entry.due_date ? formatDate(entry.due_date) : '—'}</td>
                  <td className="ccf-num">{formatCents(entry.display_amount_cents)}</td>
                  <td className={`ccf-num ${entry.balance_impact_cents < 0 ? 'ccf-impact-neg' : 'ccf-impact-pos'}`}>
                    {entry.balance_impact_cents < 0 ? '−' : '+'}
                    {formatCents(Math.abs(entry.balance_impact_cents))}
                  </td>
                  <td className="ccf-num">{entry.running_balance_cents !== null ? formatCents(entry.running_balance_cents) : '—'}</td>
                  <td>
                    <span className={`ccf-tag ccf-tag-${entry.status}`}>{entry.status}</span>
                  </td>
                  <td className="ccf-ref">{entry.reference}</td>
                </tr>
              ))}
              {ledger.entries.length === 0 && (
                <tr>
                  <td colSpan={9} className="ccf-empty-text py-4 text-center">
                    No ledger entries yet.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        <div className="ccf-finsum">
          <div className="ccf-fc">
            <div className="ccf-fl">Outstanding</div>
            <div className="ccf-fv">{formatCents(ledger.summary.current_balance_cents)}</div>
          </div>
          <div className="ccf-fc">
            <div className="ccf-fl">Overdue</div>
            <div className="ccf-fv">{formatCents(ledger.summary.overdue_cents)}</div>
          </div>
          <div className="ccf-fc">
            <div className="ccf-fl">Due soon</div>
            <div className="ccf-fv">{formatCents(ledger.summary.due_soon_cents)}</div>
          </div>
          <div className="ccf-fc">
            <div className="ccf-fl">Total paid</div>
            <div className="ccf-fv">{formatCents(ledger.summary.total_paid_cents)}</div>
          </div>
        </div>
      </Section>

      {/* 07 — Payment history */}
      <Section id="payments" index="07" title="Payment history">
        {payments.length === 0 ? (
          <div className="ccf-nodoc">No payments have been recorded for this contract yet.</div>
        ) : (
          <div className="ccf-tbl-scroll">
            <table className="ccf-tbl">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Amount</th>
                  <th>Method</th>
                  <th>Status</th>
                  <th>Applied period</th>
                  <th>Reference</th>
                </tr>
              </thead>
              <tbody>
                {payments.map((p) => (
                  <tr key={p.id}>
                    <td>{formatDate(p.occurred_at)}</td>
                    <td className="ccf-num">{formatCents(p.display_amount_cents)}</td>
                    <td>{p.method}</td>
                    <td>
                      <span className={`ccf-tag ccf-tag-${p.status}`}>{p.status}</span>
                    </td>
                    <td>{p.billing_period_start ? formatDate(p.billing_period_start) : '—'}</td>
                    <td className="ccf-ref">{p.reference}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Section>

      {/* 08 — Billing schedule */}
      <Section id="schedule" index="08" title="Billing schedule" hint={`${schedule.length} period(s)`}>
        {schedule.length === 0 ? (
          <div className="ccf-nodoc">No billing periods have been generated for this contract yet.</div>
        ) : (
          <div className="ccf-sched">
            {schedule.map((p, i) => (
              <div className="ccf-speriod" key={i}>
                <div>
                  <div className="ccf-sp-month">{formatDate(p.billing_period_start)}</div>
                  <div className="ccf-sp-due">due {formatDate(p.due_date)}</div>
                </div>
                <div className="ccf-sp-right">
                  {formatCents(p.amount_cents)}
                  <br />
                  <span className={`ccf-tag ccf-tag-${p.status}`}>{p.generated ? p.status : 'upcoming'}</span>
                </div>
              </div>
            ))}
          </div>
        )}
      </Section>

      {/* 09 — Documents */}
      <Section id="documents" index="09" title="Documents & files">
        {documents.length === 0 ? (
          <div className="ccf-nodoc">No contract document has been generated for this contract.</div>
        ) : (
          <div className="ccf-docs">
            {documents.map((doc) => (
              <div className="ccf-doc" key={doc.id}>
                <div className="ccf-doc-ic">
                  <IconDoc size={18} />
                </div>
                <div className="ccf-doc-meta">
                  <div className="ccf-doc-title">{doc.original_filename ?? humanize(doc.collection)}</div>
                  <div className="ccf-doc-sub">
                    {humanize(doc.visibility)} · {doc.created_at ? formatDate(doc.created_at) : 'unknown date'}
                  </div>
                </div>
                {doc.url && (
                  <a className="ccf-doc-action" href={doc.url} target="_blank" rel="noreferrer">
                    Download
                  </a>
                )}
              </div>
            ))}
          </div>
        )}
      </Section>

      {/* 10 — Lifecycle timeline */}
      <Section id="timeline" index="10" title="Lifecycle timeline" hint="From event & audit records">
        <div className="ccf-tl">
          {timeline.map((event, i) => (
            <div className={`ccf-tl-item ${TIMELINE_TONE[event.severity]}`} key={`${event.key}-${i}`}>
              <div className="ccf-tl-e">{event.label}</div>
              <div className="ccf-tl-m">
                {event.at ? formatDateTime(event.at) : 'unknown time'}
                {event.actor ? ` · ${event.actor}` : ''}
              </div>
              {event.detail && <div className="ccf-tl-n">{event.detail}</div>}
            </div>
          ))}
        </div>
      </Section>

      {/* 11 — Renewal & expiry */}
      <Section id="renewal" index="11" title="Renewal & expiry">
        <div className="ccf-terms">
          <DL label="End date" value={detail.renewal.end_date ? formatDate(detail.renewal.end_date) : 'Open-ended'} />
          <DL label="Days remaining" value={detail.renewal.days_remaining !== null ? `${detail.renewal.days_remaining} days` : '—'} />
          <DL label="Ending-soon alert" value={detail.renewal.ending_soon ? 'Active' : 'Not yet'} />
          <DL label="Notice period" value={detail.renewal.notice_period} />
          <DL label="Renewal request" value={detail.renewal.renewal_request_status} />
        </div>
      </Section>

      {/* 12 — Admin notes */}
      <Section id="notes" index="12" title="Admin notes" hint="Internal only">
        <div className="ccf-notes">
          {notes.length === 0 ? (
            <p className="ccf-empty-text">No internal notes yet.</p>
          ) : (
            notes.map((n) => (
              <div className="ccf-note" key={n.id}>
                {n.body}
                <div className="ccf-note-meta">
                  {n.admin_name ?? 'Admin'} · {n.created_at ? formatDateTime(n.created_at) : ''}
                  <span className="ccf-lock">Internal</span>
                </div>
              </div>
            ))
          )}
        </div>
        {canManage ? (
          <div className="ccf-noteadd">
            <textarea
              value={noteBody}
              onChange={(e) => setNoteBody(e.target.value)}
              placeholder="Add an internal note…"
              aria-label="Add an internal note"
            />
            <Button variant="primary" size="sm" onClick={handleAddNote} loading={notesSubmitting} disabled={!noteBody.trim()}>
              Add note
            </Button>
          </div>
        ) : (
          <p className="ccf-empty-text">You can view these notes, but you don't have permission to add one.</p>
        )}
      </Section>

      {/* 13 — Contract actions (danger zone) */}
      {canTerminate && (
        <Section id="actions" index="13" title="Contract actions">
          {canManage ? (
            <>
              <p className="ccf-danger-note">
                This action changes the legal state of the contract, requires a written reason, and is written to
                the audit log.
              </p>
              <Button variant="danger" onClick={() => setTerminateOpen(true)}>
                Terminate contract
              </Button>
            </>
          ) : (
            <p className="ccf-empty-text">
              You can view this contract, but you don't have permission to terminate it.
            </p>
          )}
        </Section>
      )}

      <DestructiveConfirmDialog
        open={terminateOpen}
        onClose={() => !submitting && setTerminateOpen(false)}
        onConfirm={handleTerminate}
        title="Terminate contract"
        description="This force-terminates the lease as an admin action. Both parties will be notified and this is written to the audit log."
        confirmLabel="Terminate"
        loading={submitting}
        reasonField={{
          label: 'Reason (required, at least 20 characters, written to the audit log)',
          placeholder: 'e.g. Confirmed lease violation reported by the property manager.',
          required: true,
        }}
      />
    </div>
  );
}
