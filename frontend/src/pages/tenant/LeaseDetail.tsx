/**
 * Lease & Rent (tenant) — detail view.
 *
 * The tenant-only replacement for the generic shared contract detail,
 * rendered from `ContractDetail` when `role === 'tenant'` (same dispatch
 * pattern as the existing admin case-file branch there). Merges lease terms,
 * payment history/next-due, people, property info, timeline, and documents
 * into one view, with an inline sign/decline flow for a lease still awaiting
 * the tenant's signature.
 */
import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { useAuth } from '@/context/auth';
import { useApi } from '@/hooks/useApi';
import { tenantApi } from '@/lib/endpoints';
import { formatCents, formatDate, formatDollars, daysUntil, humanize } from '@/lib/format';
import {
  NexusCard,
  SemanticBadge,
  getContractVariant,
  getLedgerVariant,
  getNextDueVariant,
  type SemanticRole,
} from '@/components/cards';
import { Button } from '@/components/ui/Button';
import { DestructiveConfirmDialog } from '@/components/ui/DestructiveConfirmDialog';
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/states';
import {
  IconArrowLeft,
  IconCalendar,
  IconCheck,
  IconCheckCircle,
  IconAlertTriangle,
  IconDownload,
  IconMessage,
  IconWallet,
  IconHome,
  IconInfo,
  IconClock,
  IconDoc,
} from '@/components/ui/icons';
import { AMENITY_CATEGORIES } from '@/pages/landlord/property-constants';
import { downloadLeaseSummary, downloadTerminationNotice } from './leaseSummary';
import type { Contract, LedgerEntry } from '@/lib/types';
import '../shared/contracts.css';
import './lease.css';

const AMENITY_LABEL: Record<string, string> = Object.fromEntries(
  AMENITY_CATEGORIES.flatMap((cat) => cat.options.map((o) => [o.value, o.label])),
);
function amenityLabel(key: string): string {
  return AMENITY_LABEL[key] ?? humanize(key);
}

/** "2.0" -> "2", "1.5" -> "1.5" — Unit.bedrooms/bathrooms are decimal strings. */
function formatCount(value: string): string {
  const n = parseFloat(value);
  if (!Number.isFinite(n)) return value;
  return n % 1 === 0 ? String(n) : String(n);
}

function monthsBetween(startIso: string, endIso: string): number {
  const start = new Date(startIso).getTime();
  const end = new Date(endIso).getTime();
  return Math.max(1, Math.round((end - start) / (30.44 * 86_400_000)));
}

function isEndingSoon(contract: Contract): boolean {
  if (contract.status !== 'active') return false;
  const days = daysUntil(contract.end_date);
  return days !== null && days > 0 && days <= 60;
}

function isPayable(entry: LedgerEntry): boolean {
  return (
    (entry.type === 'rent' || entry.type === 'late_fee') &&
    (entry.status === 'pending' || entry.status === 'overdue')
  );
}

function Row({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="lr-row">
      <span className="lr-row-k">{label}</span>
      <span className="lr-row-v">{value}</span>
    </div>
  );
}

interface PayState {
  entryId: string;
  loading: boolean;
  done: boolean;
  error: string | null;
}

export function LeaseDetail() {
  const { id = '' } = useParams<{ id: string }>();
  const { user } = useAuth();
  const navigate = useNavigate();

  const contractQ = useApi(() => tenantApi.contract(id), [id]);
  const ledgerQ = useApi(() => tenantApi.ledger(id), [id]);

  const [signOpen, setSignOpen] = useState(false);
  const [agreed, setAgreed] = useState(false);
  const [declineOpen, setDeclineOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [actionResult, setActionResult] = useState<{ type: 'success' | 'error'; message: string } | null>(null);
  const [payState, setPayState] = useState<PayState | null>(null);

  function reload() {
    contractQ.reload();
    ledgerQ.reload();
  }

  async function handleSign() {
    setSubmitting(true);
    setActionResult(null);
    try {
      await tenantApi.acceptContract(id);
      setActionResult({ type: 'success', message: 'Lease signed. Your landlord has been notified.' });
      setSignOpen(false);
      setAgreed(false);
      reload();
    } catch (err) {
      setActionResult({ type: 'error', message: err instanceof Error ? err.message : 'Failed to sign the lease.' });
    } finally {
      setSubmitting(false);
    }
  }

  async function handleDecline(reason?: string) {
    const trimmed = (reason ?? '').trim();
    if (!trimmed) return;
    setSubmitting(true);
    setActionResult(null);
    try {
      await tenantApi.terminateContract(id, trimmed);
      setActionResult({ type: 'success', message: 'Lease declined.' });
      setDeclineOpen(false);
      reload();
    } catch (err) {
      setActionResult({ type: 'error', message: err instanceof Error ? err.message : 'Failed to decline the lease.' });
    } finally {
      setSubmitting(false);
    }
  }

  async function handlePay(entry: LedgerEntry) {
    setPayState({ entryId: entry.id, loading: true, done: false, error: null });
    try {
      await tenantApi.initiatePayment(entry.id);
      setPayState({ entryId: entry.id, loading: false, done: true, error: null });
    } catch {
      setPayState({
        entryId: entry.id,
        loading: false,
        done: false,
        error: 'Could not initiate payment. Try again or contact your landlord.',
      });
    }
  }

  const backLink = (
    <Link to="/app/contracts" className="lr-back">
      <IconArrowLeft size={16} />
      Back to Lease &amp; Rent
    </Link>
  );

  if (contractQ.loading || ledgerQ.loading) {
    return (
      <div>
        {backLink}
        <LoadingState />
      </div>
    );
  }

  if (contractQ.error) {
    return (
      <div>
        {backLink}
        <div className="mt-4">
          <ErrorState message={contractQ.error.message} onRetry={reload} />
        </div>
      </div>
    );
  }

  const contract = contractQ.data;
  if (!contract) {
    return (
      <div>
        {backLink}
        <div className="mt-4">
          <ErrorState title="Lease not found" message="This lease could not be located." />
        </div>
      </div>
    );
  }

  const property = contract.listing?.unit?.property;
  const unit = contract.listing?.unit;
  const home = property
    ? `${property.name}${unit?.unit_number ? `, Unit ${unit.unit_number}` : ''}`
    : (contract.listing?.title ?? `Contract ${contract.id.slice(0, 8)}…`);
  const address = property
    ? `${property.street_address}, ${property.city}, ${property.state} ${property.zip_code}`
    : null;
  const landlordName = contract.landlord?.full_name ?? `Landlord #${contract.landlord_id}`;
  // This page only renders for role === 'tenant' (dispatched from ContractDetail),
  // so `user` is always a User, never an Admin — `full_name` narrows the union.
  const tenantName = (user && 'full_name' in user ? user.full_name : null) ?? 'You';
  const tenantEmail = (user && 'full_name' in user ? user.email : null) ?? '';

  const entries = ledgerQ.data?.entries ?? [];
  const summary = ledgerQ.data?.summary ?? null;
  const nextDue = entries
    .filter((e) => (e.status === 'pending' || e.status === 'overdue') && e.due_date)
    .sort((a, b) => new Date(a.due_date!).getTime() - new Date(b.due_date!).getTime())[0] ?? null;
  const recentPaid = entries
    .filter((e) => e.status === 'paid')
    .sort((a, b) => new Date(b.occurred_at).getTime() - new Date(a.occurred_at).getTime())
    .slice(0, 5);
  const hasOverdue = entries.some((e) => e.status === 'overdue');
  const balanceCents = summary?.outstanding_cents ?? 0;
  const endingSoon = isEndingSoon(contract);

  const houseRules = property?.rules ?? null;
  const amenities = Array.from(
    new Set([...(property?.amenities ?? []), ...(unit?.amenities ?? [])]),
  );

  /* ---- Action box: status-driven, honest to the real state machine ------- */
  let actionRole: SemanticRole = 'neutral';
  let actionIcon = <IconInfo size={22} />;
  let actionTitle = '';
  let actionBody: React.ReactNode = null;

  if (contract.status === 'draft') {
    actionRole = 'info';
    actionTitle = 'Being prepared';
    actionBody = `${landlordName} is still preparing this lease. There's nothing for you to do yet.`;
  } else if (contract.status === 'pending_tenant') {
    actionRole = 'warning';
    actionIcon = <IconAlertTriangle size={22} />;
    actionTitle = 'Signature required';
    actionBody = 'Review the lease details below, then sign or decline.';
  } else if (contract.status === 'active' && endingSoon) {
    actionRole = 'warning';
    actionIcon = <IconCalendar size={22} />;
    actionTitle = 'Lease ending soon';
    actionBody = `Your lease ends ${formatDate(contract.end_date)}. Contact your landlord if you'd like to renew.`;
  } else if (contract.status === 'active') {
    actionRole = 'success';
    actionIcon = <IconCheckCircle size={22} />;
    actionTitle = 'Everything looks good';
    actionBody = nextDue
      ? `Your lease is active. Your next rent payment is due ${formatDate(nextDue.due_date)}.`
      : 'Your lease is active and no action is needed right now.';
  } else if (contract.status === 'terminated') {
    actionRole = 'danger';
    actionIcon = <IconAlertTriangle size={22} />;
    actionTitle = 'Lease terminated';
    actionBody = contract.termination_reason
      ? `This lease was terminated${contract.terminated_by ? ` by the ${humanize(contract.terminated_by).toLowerCase()}` : ''}. Reason: ${contract.termination_reason}`
      : 'This lease was terminated.';
  } else {
    actionRole = 'info';
    actionIcon = <IconCheckCircle size={22} />;
    actionTitle = 'Lease ended';
    actionBody = `This lease ended on ${formatDate(contract.end_date)}. You can still download a summary for your records.`;
  }

  return (
    <div className="lr-page animate-rise">
      {backLink}

      {actionResult && (
        <div
          className={
            actionResult.type === 'success'
              ? 'flex items-center gap-2.5 rounded-xl px-4 py-3 text-sm font-medium bg-success-50 text-success-700 border border-success-200'
              : 'flex items-center gap-2.5 rounded-xl px-4 py-3 text-sm font-medium bg-danger-50 text-danger-700 border border-danger-200'
          }
        >
          {actionResult.type === 'success' ? (
            <IconCheckCircle size={16} className="shrink-0" />
          ) : (
            <IconAlertTriangle size={16} className="shrink-0" />
          )}
          {actionResult.message}
        </div>
      )}

      {/* ── Header ── */}
      <NexusCard as="section" specular className="lr-header">
        <p className="lr-eyebrow">Lease {contract.id.slice(0, 8)}…</p>
        <h1 className="lr-title">{home}</h1>
        {address && <p className="lr-address">{address}</p>}
        <div className="lr-header-meta">
          <SemanticBadge role={getContractVariant(contract.status)} status={contract.status} />
          <span>
            {contract.end_date
              ? `${formatDate(contract.start_date)} to ${formatDate(contract.end_date)}`
              : `${formatDate(contract.start_date)} · open-ended`}
          </span>
          <span>{formatCents(contract.rent_amount)} / month</span>
        </div>
        <div className="lr-header-actions">
          <Button variant="secondary" size="sm" leftIcon={<IconDownload size={15} />} onClick={() => downloadLeaseSummary(contract, tenantName, tenantEmail)}>
            Download lease summary
          </Button>
          <Button variant="secondary" size="sm" leftIcon={<IconMessage size={15} />} onClick={() => navigate('/app/messages')}>
            Contact landlord
          </Button>
          {(contract.status === 'active' || nextDue) && (
            <Button
              variant="secondary"
              size="sm"
              leftIcon={<IconWallet size={15} />}
              onClick={() => document.getElementById('lr-payments')?.scrollIntoView({ behavior: 'smooth', block: 'start' })}
            >
              View payments
            </Button>
          )}
        </div>
      </NexusCard>

      {/* ── Action box ── */}
      <NexusCard role={actionRole} className="lr-action">
        <div className="lr-action-icon">{actionIcon}</div>
        <div className="lr-action-body">
          <p className="lr-action-title">{actionTitle}</p>
          <p className="lr-action-sub">{actionBody}</p>

          {contract.status === 'pending_tenant' && !signOpen && (
            <div className="lr-action-btns">
              <Button size="sm" leftIcon={<IconCheck size={15} />} onClick={() => setSignOpen(true)} disabled={submitting}>
                Review &amp; sign
              </Button>
              <Button variant="danger" size="sm" onClick={() => setDeclineOpen(true)} disabled={submitting}>
                Decline
              </Button>
            </div>
          )}

          {contract.status === 'pending_tenant' && signOpen && (
            <div className="lr-signbox">
              <label className="lr-agree">
                <input type="checkbox" checked={agreed} onChange={(e) => setAgreed(e.target.checked)} />
                I have read the lease terms below and I agree to them. I understand this is a binding
                rental agreement for {home}.
              </label>
              <div className="lr-action-btns">
                <Button size="sm" onClick={handleSign} loading={submitting} disabled={!agreed}>
                  Sign lease
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => { setSignOpen(false); setAgreed(false); }}
                  disabled={submitting}
                >
                  Cancel
                </Button>
              </div>
            </div>
          )}
        </div>
      </NexusCard>

      {/* ── Lease overview ── */}
      <NexusCard as="section" className="lr-section">
        <h2 className="lr-section-h">Lease overview</h2>
        <div className="lr-grid2">
          <Row label="Status" value={humanize(contract.status)} />
          <Row label="Lease ID" value={<code className="lr-code">{contract.id}</code>} />
          <Row label="Start date" value={formatDate(contract.start_date)} />
          <Row label="End date" value={contract.end_date ? formatDate(contract.end_date) : 'Open-ended'} />
          <Row
            label="Lease length"
            value={
              contract.end_date
                ? `${monthsBetween(contract.start_date, contract.end_date)} months`
                : 'Open-ended (no fixed term)'
            }
          />
          <Row label="Monthly rent" value={formatCents(contract.rent_amount)} />
          <Row label="Rent due" value={`Day ${contract.payment_day} of each cycle`} />
          <Row label="Billing cycle" value={humanize(contract.billing_cycle)} />
          <Row label="Property" value={property?.name ?? '—'} />
          <Row label="Unit" value={unit?.unit_number ? `Unit ${unit.unit_number}` : 'Whole property'} />
          <Row label="Landlord" value={landlordName} />
        </div>
      </NexusCard>

      {/* ── Payment terms + history ── */}
      <NexusCard as="section" id="lr-payments" className="lr-section">
        <h2 className="lr-section-h">
          Payment terms
          <span className="lr-section-hint">Rent and payment history for this lease</span>
        </h2>
        <div className="lr-grid2">
          <Row label="Monthly rent" value={formatCents(contract.rent_amount)} />
          <Row label="Rent due" value={`Day ${contract.payment_day} of each cycle`} />
          {unit?.security_deposit && <Row label="Security deposit" value={formatDollars(unit.security_deposit)} />}
          <Row label="Current balance" value={formatCents(balanceCents)} />
          <Row label="Next payment due" value={nextDue ? formatDate(nextDue.due_date) : '—'} />
        </div>

        {contract.status === 'draft' || contract.status === 'pending_tenant' ? (
          <p className="lr-muted">Payments will begin once this lease is active.</p>
        ) : (
          <>
            {nextDue && (
              <NexusCard role={getNextDueVariant(daysUntil(nextDue.due_date))} className="lr-next">
                <div>
                  <span className="eyebrow">Next payment</span>
                  <p className="lr-next-label">{nextDue.display_label}</p>
                  <p className="lr-next-meta">
                    <IconClock size={13} /> Due {formatDate(nextDue.due_date)}
                  </p>
                </div>
                <div className="lr-next-right">
                  <span className="lr-next-amount">{formatCents(nextDue.display_amount_cents)}</span>
                  {isPayable(nextDue) &&
                    (payState?.entryId === nextDue.id && payState.done ? (
                      <p className="lr-next-initiated">Payment initiated — awaiting confirmation.</p>
                    ) : (
                      <Button
                        size="sm"
                        loading={payState?.entryId === nextDue.id && payState.loading}
                        onClick={() => handlePay(nextDue)}
                      >
                        Pay now
                      </Button>
                    ))}
                </div>
              </NexusCard>
            )}

            <NexusCard role="info" className="lr-notice">
              <IconInfo size={16} className="shrink-0" />
              <p>
                <strong>Online card payment via Stripe is being set up.</strong> Pressing "Pay now"
                initiates a payment intent; completing the card charge isn't available in-app yet.
                Contact your landlord to arrange payment in the meantime.
              </p>
            </NexusCard>

            {recentPaid.length === 0 ? (
              <EmptyState icon={<IconWallet size={24} />} title="No payments yet" description="Confirmed payments for this lease will appear here." />
            ) : (
              <div className="lr-paylist">
                {recentPaid.map((entry) => (
                  <div className="lr-payrow" key={entry.id}>
                    <div className="lr-payrow-desc">
                      {entry.display_label}
                      <span className="lr-payrow-date">{formatDate(entry.created_at)}</span>
                    </div>
                    <span className="lr-payrow-amt">{formatCents(entry.display_amount_cents)}</span>
                    <SemanticBadge role={getLedgerVariant(entry.status)} status={entry.status} />
                  </div>
                ))}
              </div>
            )}
            {hasOverdue && (
              <p className="lr-overdue-note">
                <IconAlertTriangle size={14} /> This lease has overdue charges.
              </p>
            )}
          </>
        )}
      </NexusCard>

      {/* ── People involved ── */}
      <NexusCard as="section" className="lr-section">
        <h2 className="lr-section-h">People involved</h2>
        <div className="lr-people">
          <div className="lr-person">
            <div className="lr-person-avatar">{tenantName.slice(0, 1).toUpperCase()}</div>
            <div>
              <p className="lr-person-name">{tenantName} <span className="lr-person-you">(you)</span></p>
              <p className="lr-person-role">Lease holder</p>
              <p className="lr-person-email">{tenantEmail}</p>
            </div>
          </div>
          <div className="lr-person">
            <div className="lr-person-avatar lr-person-avatar-alt">{landlordName.slice(0, 1).toUpperCase()}</div>
            <div>
              <p className="lr-person-name">{landlordName}</p>
              <p className="lr-person-role">Landlord</p>
              <p className="lr-person-email">{contract.landlord?.email}</p>
            </div>
          </div>
        </div>
      </NexusCard>

      {/* ── Property & unit ── */}
      {property && (
        <NexusCard as="section" className="lr-section">
          <h2 className="lr-section-h">Property &amp; unit</h2>
          <div className="lr-grid2">
            <Row label="Property type" value={humanize(property.property_type)} />
            <Row label="Address" value={address} />
            <Row label="Unit" value={unit?.unit_number ? `Unit ${unit.unit_number}` : 'Whole property'} />
            {unit?.bedrooms && <Row label="Bedrooms" value={formatCount(unit.bedrooms)} />}
            {unit?.bathrooms && <Row label="Bathrooms" value={formatCount(unit.bathrooms)} />}
            {unit?.square_feet != null && <Row label="Size" value={`${unit.square_feet} sq ft`} />}
            {contract.listing && (
              <Row label="Pets" value={contract.listing.pets_allowed ? 'Allowed' : 'Not allowed'} />
            )}
            {contract.listing?.pet_policy && <Row label="Pet policy" value={contract.listing.pet_policy} />}
            {houseRules?.smoking_allowed != null && (
              <Row label="Smoking" value={houseRules.smoking_allowed ? 'Allowed' : 'Not allowed'} />
            )}
            {houseRules?.guests_allowed != null && (
              <Row label="Guests" value={houseRules.guests_allowed ? 'Allowed' : 'Not allowed'} />
            )}
            {houseRules?.max_occupants != null && <Row label="Max occupants" value={houseRules.max_occupants} />}
            {houseRules?.quiet_hours && <Row label="Quiet hours" value={houseRules.quiet_hours} />}
            {houseRules?.min_lease_months != null && (
              <Row label="Minimum lease" value={`${houseRules.min_lease_months} months`} />
            )}
            {houseRules?.utility_responsibility && (
              <Row label="Utilities" value={humanize(houseRules.utility_responsibility)} />
            )}
            {houseRules?.maintenance_responsibility && (
              <Row label="Maintenance" value={humanize(houseRules.maintenance_responsibility)} />
            )}
          </div>
          {amenities.length > 0 && (
            <div className="lr-amenities">
              {amenities.map((a) => (
                <span className="lr-chip" key={a}>
                  {amenityLabel(a)}
                </span>
              ))}
            </div>
          )}
        </NexusCard>
      )}

      {/* ── Timeline ── */}
      <NexusCard as="section" className="lr-section">
        <h2 className="lr-section-h">Lease timeline</h2>
        <div className="lr-timeline">
          <div className="lr-tl-item lr-tl-done">
            <p className="lr-tl-e">Lease created</p>
            <p className="lr-tl-t">{formatDate(contract.created_at)}</p>
          </div>
          {contract.status !== 'draft' && (
            <div className="lr-tl-item lr-tl-done">
              <p className="lr-tl-e">Sent to you</p>
              <p className="lr-tl-t">Awaiting or accepted</p>
            </div>
          )}
          {contract.status === 'active' && (
            <div className="lr-tl-item lr-tl-done">
              <p className="lr-tl-e">Lease active</p>
              <p className="lr-tl-t">{contract.end_date ? `Ends ${formatDate(contract.end_date)}` : 'Open-ended'}</p>
            </div>
          )}
          {(contract.status === 'terminated' || contract.status === 'expired') && (
            <div className="lr-tl-item lr-tl-done lr-tl-final">
              <p className="lr-tl-e">{humanize(contract.status)}</p>
              {contract.termination_reason && <p className="lr-tl-t">{contract.termination_reason}</p>}
            </div>
          )}
          {contract.status === 'pending_tenant' && (
            <div className="lr-tl-item lr-tl-current">
              <p className="lr-tl-e">Awaiting your signature</p>
              <p className="lr-tl-t">Sign or decline above</p>
            </div>
          )}
        </div>
      </NexusCard>

      {/* ── Documents ── */}
      <NexusCard as="section" className="lr-section">
        <h2 className="lr-section-h">Documents</h2>
        <div className="lr-docrow">
          <div className="lr-doc-ico"><IconDoc size={18} /></div>
          <div className="lr-doc-body">
            <p className="lr-doc-t">Lease summary</p>
            <p className="lr-doc-s">A plain-text summary of your lease details</p>
          </div>
          <Button variant="secondary" size="sm" onClick={() => downloadLeaseSummary(contract, tenantName, tenantEmail)}>
            Download
          </Button>
        </div>
        {contract.status === 'terminated' && (
          <div className="lr-docrow">
            <div className="lr-doc-ico"><IconAlertTriangle size={18} /></div>
            <div className="lr-doc-body">
              <p className="lr-doc-t">Termination notice</p>
              <p className="lr-doc-s">Reason and terminating party on record</p>
            </div>
            <Button variant="secondary" size="sm" onClick={() => downloadTerminationNotice(contract, tenantName)}>
              Download
            </Button>
          </div>
        )}
      </NexusCard>

      {/* ── Support footer ── */}
      <NexusCard as="section" className="lr-support">
        <div>
          <p className="lr-support-t">Need help with this lease?</p>
          <p className="lr-support-s">
            Questions about your lease, rent, or move-in are best sent to your landlord. Wyncrest
            support can help with anything else.
          </p>
        </div>
        <div className="lr-support-btns">
          <Button size="sm" leftIcon={<IconMessage size={15} />} onClick={() => navigate('/app/messages')}>
            Message landlord
          </Button>
          <Button variant="secondary" size="sm" leftIcon={<IconHome size={15} />} onClick={() => navigate('/app/messages')}>
            Contact support
          </Button>
        </div>
      </NexusCard>

      <DestructiveConfirmDialog
        open={declineOpen}
        onClose={() => !submitting && setDeclineOpen(false)}
        onConfirm={handleDecline}
        title="Decline lease"
        description="This action cannot be undone."
        confirmLabel="Decline lease"
        loading={submitting}
        reasonField={{ label: 'Reason', placeholder: 'Explain why…', required: true }}
      />
    </div>
  );
}
