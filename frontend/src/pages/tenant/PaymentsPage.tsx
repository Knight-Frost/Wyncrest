import { useMemo, useState } from 'react';
import { Link } from 'react-router';
import {
  Elements,
  PaymentElement,
  useElements,
  useStripe,
} from '@stripe/react-stripe-js';
import type { Appearance } from '@stripe/stripe-js';

import { useApi } from '@/hooks/useApi';
import { tenantApi } from '@/lib/endpoints';
import { getStripe, stripeConfigured } from '@/lib/stripe';
import { brand } from '@/config/brand';
import { normalizeError } from '@/lib/api';
import { formatCents, formatDate, daysUntil } from '@/lib/format';
import type {
  Contract,
  LedgerEntry,
  LedgerFinancialSummary,
  TenantBalance,
} from '@/lib/types';
import {
  EmptyState,
  ErrorState,
  ForbiddenState,
} from '@/components/ui/states';
import {
  IconWallet,
  IconCalendar,
  IconShield,
  IconCheckCircle,
  IconAlertTriangle,
  IconInfo,
  IconRefresh,
  IconChevronRight,
  IconLock,
  IconMessage,
  IconArrowRight,
  IconHome,
} from '@/components/ui/icons';
import './payments.css';

/* Stripe.js is loaded once for the whole page (null when no publishable key). */
const stripePromise = getStripe();

/* ── Domain helpers (all display-only; money math stays on the server) ─────── */

type Posture = 'nolease' | 'paid' | 'ending' | 'due' | 'today' | 'overdue';

/** Rent/late-fee obligation the tenant can actually pay right now. */
function isPayable(e: LedgerEntry): boolean {
  return (
    (e.type === 'rent' || e.type === 'late_fee') &&
    (e.status === 'pending' || e.status === 'overdue')
  );
}

function isCharge(e: LedgerEntry): boolean {
  return e.direction === 'charge';
}

function isSettlement(e: LedgerEntry): boolean {
  return e.direction === 'payment' || e.direction === 'refund';
}

function monthYear(iso: string | null): string {
  if (!iso) return '';
  const d = new Date(iso);
  return Number.isNaN(d.getTime())
    ? ''
    : d.toLocaleDateString('en-GH', { month: 'long', year: 'numeric' });
}

/** Human label for an entry, deferring to the server's display_label. */
function entryLabel(e: LedgerEntry): string {
  if (e.type === 'rent' && e.billing_period_start) {
    return `${e.display_label} · ${monthYear(e.billing_period_start)}`;
  }
  return e.display_label;
}

/**
 * Next calendar date matching the lease's payment day (1–28), on or after
 * today. Used only to *project* the upcoming rent charge from the lease terms —
 * it is never presented as a posted ledger charge.
 */
function nextChargeDate(paymentDay: number): Date {
  const now = new Date();
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
  const candidate = new Date(now.getFullYear(), now.getMonth(), paymentDay);
  if (candidate.getTime() < today) candidate.setMonth(candidate.getMonth() + 1);
  return candidate;
}

function duePhrase(days: number | null): string {
  if (days === null) return '';
  if (days < 0) return `overdue by ${Math.abs(days)} day${Math.abs(days) === 1 ? '' : 's'}`;
  if (days === 0) return 'due today';
  if (days === 1) return 'due tomorrow';
  return `due in ${days} days`;
}

function stripeAppearance(): Appearance {
  const dark = document.documentElement.getAttribute('data-theme') === 'dark';
  // The Stripe Element renders in its own iframe, so CSS variables don't reach
  // it — resolve the app's CURRENT accent (--color-action-600 tracks the
  // user-selectable accent + theme) at open time. The appearance object is
  // rebuilt on every open, so accent/theme switches are picked up next open.
  const accent = getComputedStyle(document.documentElement)
    .getPropertyValue('--color-action-600')
    .trim();
  return {
    theme: dark ? 'night' : 'stripe',
    variables: {
      colorPrimary: accent || '#0A7068',
      borderRadius: '12px',
      fontFamily: '"Hanken Grotesque", system-ui, sans-serif',
      fontSizeBase: '15px',
    },
  };
}

/* ============================================================================
   PaymentsPage
   ============================================================================ */

export function PaymentsPage() {
  const ledgerQ = useApi(() => tenantApi.ledger(), []);
  const balanceQ = useApi(() => tenantApi.balance(), []);
  const contractsQ = useApi(() => tenantApi.contracts(), []);

  /** Which obligation the pay panel is targeting, if open. */
  const [payEntryId, setPayEntryId] = useState<string | null>(null);
  const [flash, setFlash] = useState<string | null>(null);

  function reload() {
    ledgerQ.reload();
    balanceQ.reload();
    contractsQ.reload();
  }

  /* ---- Loading / gate ----------------------------------------------------- */
  const isLoading = ledgerQ.loading || balanceQ.loading || contractsQ.loading;
  const primaryError = ledgerQ.error ?? balanceQ.error;

  /* ---- Derived (memoised so the pay panel doesn't recompute constantly) --- */
  const model = useMemo(
    () => buildModel(ledgerQ.data?.entries ?? [], ledgerQ.data?.summary ?? null, balanceQ.data ?? null, contractsQ.data ?? []),
    [ledgerQ.data, balanceQ.data, contractsQ.data],
  );

  const payEntry = model.payableEntries.find((e) => e.id === payEntryId) ?? null;
  const onlinePaymentsAvailable =
    stripeConfigured && (balanceQ.data?.online_payments_enabled ?? false);

  function openPay(entry: LedgerEntry) {
    setFlash(null);
    setPayEntryId(entry.id);
    // Let the panel mount, then bring it into view.
    requestAnimationFrame(() => {
      document.getElementById('pm-pay-panel')?.scrollIntoView({
        behavior: 'smooth',
        block: 'center',
      });
    });
  }

  function onPaid(status: 'succeeded' | 'processing') {
    setPayEntryId(null);
    setFlash(
      status === 'succeeded'
        ? 'Payment received. Your ledger updates the moment your bank confirms it.'
        : 'Payment is processing. We will mark your rent paid once it clears.',
    );
    reload();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  /* ---- Error states ------------------------------------------------------- */
  if (!isLoading && primaryError?.status === 403) {
    return (
      <div className="pm">
        <IntroHeader onRefresh={reload} lease={null} />
        <ForbiddenState />
      </div>
    );
  }
  if (!isLoading && primaryError) {
    return (
      <div className="pm">
        <IntroHeader onRefresh={reload} lease={null} />
        <ErrorState message={primaryError.message} onRetry={reload} />
      </div>
    );
  }

  /* ---- Loading skeleton --------------------------------------------------- */
  if (isLoading) {
    return (
      <div className="pm">
        <IntroHeader onRefresh={reload} lease={null} />
        <div className="pm-skel-cards">
          {[0, 1, 2, 3].map((i) => (
            <div key={i} className="pm-glass pm-skel-card" />
          ))}
        </div>
        <div className="pm-glass pm-skel-hero" />
      </div>
    );
  }

  /* ---- No active lease ---------------------------------------------------- */
  if (model.posture === 'nolease') {
    return (
      <div className="pm">
        <IntroHeader onRefresh={reload} lease={null} />
        <NoLease />
      </div>
    );
  }

  /* ---- Full page ---------------------------------------------------------- */
  return (
    <div className="pm">
      <IntroHeader onRefresh={reload} lease={model.lease} />

      {flash && (
        <div className="pm-glass pm-flash" role="status">
          <IconCheckCircle size={18} className="pm-flash-icon" />
          <span>{flash}</span>
        </div>
      )}

      <SummaryCards model={model} />

      <PaymentHero
        model={model}
        onPay={() => model.nextDue && openPay(model.nextDue)}
        payOpen={!!payEntry}
      >
        {payEntry && (
          <PayPanel
            key={payEntry.id}
            entry={payEntry}
            available={onlinePaymentsAvailable}
            onClose={() => setPayEntryId(null)}
            onPaid={onPaid}
          />
        )}
      </PaymentHero>

      <BalanceBreakdown model={model} />

      <PaymentHistory entries={model.settlements} />

      <UpcomingCharges model={model} />

      <LedgerTable
        entries={model.entries}
        payableId={payEntry?.id ?? null}
        onPay={openPay}
      />

      <SupportCard landlordName={model.lease?.landlordName ?? null} />
    </div>
  );
}

/* ============================================================================
   Model — one truthful derivation of everything the page renders
   ============================================================================ */

interface LeaseContext {
  propertyName: string | null;
  unitNumber: string | null;
  city: string | null;
  landlordName: string | null;
  rentCents: number | null;
  paymentDay: number | null;
  endDate: string | null;
}

interface PageModel {
  posture: Posture;
  lease: LeaseContext | null;
  entries: LedgerEntry[];
  settlements: LedgerEntry[];
  charges: LedgerEntry[];
  payableEntries: LedgerEntry[];
  nextDue: LedgerEntry | null;
  daysToNextDue: number | null;
  outstandingCents: number;
  overdueCents: number;
  collectedCents: number;
  rentChargedCents: number;
  feesChargedCents: number;
  monthlyRentCents: number | null;
  paymentDay: number | null;
  nextChargeOn: Date | null;
  leaseEndsInDays: number | null;
}

function buildModel(
  entries: LedgerEntry[],
  summary: LedgerFinancialSummary | null,
  balance: TenantBalance | null,
  contracts: Contract[],
): PageModel {
  const active =
    contracts.find((c) => c.status === 'active') ??
    contracts.find((c) => c.status === 'pending_tenant') ??
    null;

  const lease: LeaseContext | null = active
    ? {
        propertyName: active.listing?.unit?.property?.name ?? active.listing?.title ?? null,
        unitNumber: active.listing?.unit?.unit_number ?? null,
        city: active.listing?.unit?.property?.city ?? null,
        landlordName: active.landlord?.full_name ?? null,
        rentCents: active.rent_amount ?? null,
        paymentDay: active.payment_day ?? null,
        endDate: active.end_date ?? null,
      }
    : null;

  const charges = entries.filter(isCharge);
  const settlements = entries.filter(isSettlement);
  const payableEntries = entries
    .filter(isPayable)
    .sort((a, b) => dueTime(a) - dueTime(b));

  const nextDue = payableEntries[0] ?? null;
  const daysToNextDue = nextDue?.due_date ? daysUntil(nextDue.due_date) : null;

  const outstandingCents = balance?.balance_cents ?? summary?.outstanding_cents ?? 0;
  const overdueCents = summary?.overdue_cents ?? 0;
  const collectedCents = summary?.collected_cents ?? 0;
  const rentChargedCents = summary?.rent_charged_cents ?? 0;
  const feesChargedCents = summary?.fees_charged_cents ?? 0;

  const paymentDay = lease?.paymentDay ?? null;
  const leaseEndsInDays = lease?.endDate ? daysUntil(lease.endDate) : null;
  const nextChargeOn = paymentDay ? nextChargeDate(paymentDay) : null;

  /* Posture — derived only from real balance + lease state. */
  let posture: Posture;
  if (!active && entries.length === 0) {
    posture = 'nolease';
  } else if (overdueCents > 0) {
    posture = 'overdue';
  } else if (outstandingCents > 0) {
    posture = daysToNextDue === 0 ? 'today' : 'due';
  } else if (leaseEndsInDays !== null && leaseEndsInDays <= 45) {
    posture = 'ending';
  } else {
    posture = 'paid';
  }

  return {
    posture,
    lease,
    entries,
    settlements,
    charges,
    payableEntries,
    nextDue,
    daysToNextDue,
    outstandingCents,
    overdueCents,
    collectedCents,
    rentChargedCents,
    feesChargedCents,
    monthlyRentCents: lease?.rentCents ?? null,
    paymentDay,
    nextChargeOn,
    leaseEndsInDays,
  };
}

function dueTime(e: LedgerEntry): number {
  return e.due_date ? new Date(e.due_date).getTime() : Number.MAX_SAFE_INTEGER;
}

/* ============================================================================
   Intro header
   ============================================================================ */

function IntroHeader({
  onRefresh,
  lease,
}: {
  onRefresh: () => void;
  lease: LeaseContext | null;
}) {
  const whom = lease
    ? [
        lease.propertyName,
        lease.unitNumber ? `Unit ${lease.unitNumber}` : null,
        lease.city,
        lease.landlordName ? `Landlord ${lease.landlordName}` : null,
      ]
        .filter(Boolean)
        .join(' · ')
    : null;

  return (
    <header className="pm-glass pm-intro">
      <div className="pm-intro-copy">
        <span className="pm-eyebrow">Rent</span>
        <h1 className="pm-intro-title">
          Payments<span className="pm-intro-dot">.</span>
        </h1>
        <p className="pm-intro-sub">
          View your rent balance, pay securely, and keep every receipt in one place.
        </p>
        {whom && <p className="pm-intro-whom">{whom}</p>}
      </div>
      <button className="pm-btn pm-btn-ghost" onClick={onRefresh} type="button">
        <IconRefresh size={15} />
        Refresh
      </button>
    </header>
  );
}

/* ============================================================================
   Summary cards
   ============================================================================ */

function SummaryCards({ model }: { model: PageModel }) {
  const owed = model.outstandingCents > 0;
  const serious = model.posture === 'overdue';

  const nextLabel =
    model.posture === 'paid' || model.posture === 'ending'
      ? model.posture === 'ending' && model.lease?.endDate
        ? formatDate(model.lease.endDate)
        : model.nextChargeOn
          ? formatDate(model.nextChargeOn.toISOString())
          : '—'
      : model.nextDue?.due_date
        ? formatDate(model.nextDue.due_date)
        : '—';

  const nextSub =
    model.posture === 'ending'
      ? 'lease ends'
      : model.posture === 'paid'
        ? 'next rent scheduled'
        : model.daysToNextDue !== null
          ? duePhrase(model.daysToNextDue)
          : 'upcoming';

  const status = postureStatusPill(model.posture);
  const balSub = serious
    ? 'includes overdue charges'
    : owed
      ? model.nextDue?.billing_period_start
        ? `for ${monthYear(model.nextDue.billing_period_start)}`
        : 'currently due'
      : 'nothing outstanding';

  return (
    <section className="pm-cards">
      <article className={`pm-glass pm-card pm-card-bal ${serious ? 'is-over' : owed ? 'is-owed' : 'is-clear'}`}>
        <div className="pm-card-label">Current balance</div>
        <div className="pm-card-value">{formatCents(model.outstandingCents)}</div>
        <div className="pm-card-sub">{balSub}</div>
      </article>

      <article className="pm-glass pm-card">
        <div className="pm-card-label">Next due date</div>
        <div className="pm-card-value pm-card-value-sm">{nextLabel}</div>
        <div className="pm-card-sub">{nextSub}</div>
      </article>

      <article className="pm-glass pm-card">
        <div className="pm-card-label">Monthly rent</div>
        <div className="pm-card-value">
          {model.monthlyRentCents !== null ? formatCents(model.monthlyRentCents) : '—'}
        </div>
        <div className="pm-card-sub">
          {model.paymentDay ? `due the ${ordinal(model.paymentDay)} each month` : 'per your lease'}
        </div>
      </article>

      <article className="pm-glass pm-card">
        <div className="pm-card-label">Payment status</div>
        <div className={`pm-statpill is-${status.tone}`}>
          <span className="pm-statpill-dot" />
          {status.label}
        </div>
        <div className="pm-card-sub">{status.sub}</div>
      </article>
    </section>
  );
}

function postureStatusPill(p: Posture): { tone: string; label: string; sub: string } {
  switch (p) {
    case 'overdue':
      return { tone: 'over', label: 'Overdue', sub: 'action needed' };
    case 'due':
      return { tone: 'due', label: 'Due soon', sub: 'coming up' };
    case 'today':
      return { tone: 'due', label: 'Due today', sub: 'pay to stay ahead' };
    case 'ending':
      return { tone: 'paid', label: 'Paid up', sub: 'lease ending' };
    default:
      return { tone: 'paid', label: 'Paid up', sub: 'all caught up' };
  }
}

/* ============================================================================
   Payment hero (state-aware) + expandable pay panel
   ============================================================================ */

function PaymentHero({
  model,
  onPay,
  payOpen,
  children,
}: {
  model: PageModel;
  onPay: () => void;
  payOpen: boolean;
  children?: React.ReactNode;
}) {
  const msg = heroMessage(model);
  const paidUp = model.posture === 'paid' || model.posture === 'ending';

  return (
    <section className={`pm-glass pm-hero is-${msg.tone}`}>
      <div className="pm-hero-msg">
        <div className="pm-hero-ic">{msg.icon}</div>
        <div>
          <div className="pm-hero-h">{msg.title}</div>
          <div className="pm-hero-s">{msg.sub}</div>
        </div>
      </div>

      <div className="pm-hero-body">
        <div className="pm-hero-amt">
          <div className="pm-hero-amt-label">
            {paidUp ? 'Amount due' : 'Amount due'}
          </div>
          <div className={`pm-hero-amt-value ${paidUp ? 'is-clear' : ''}`}>
            {formatCents(paidUp ? 0 : model.outstandingCents)}
          </div>
          <div className="pm-hero-amt-detail">{msg.detail}</div>
          {model.posture === 'overdue' && model.overdueCents > 0 && (
            <div className="pm-hero-flag">
              <IconAlertTriangle size={14} />
              {formatCents(model.overdueCents)} of this is overdue
            </div>
          )}
        </div>

        <div className="pm-hero-cta">
          {paidUp ? (
            <button
              className="pm-btn pm-btn-glass"
              type="button"
              onClick={() =>
                document.getElementById('pm-ledger')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
              }
            >
              <IconWallet size={16} />
              View payment history
            </button>
          ) : payOpen ? null : (
            <button
              className={`pm-btn ${model.posture === 'overdue' ? 'pm-btn-blood' : 'pm-btn-pay'}`}
              onClick={onPay}
              type="button"
              disabled={!model.nextDue}
            >
              <IconLock size={15} />
              {model.posture === 'overdue' ? 'Settle now' : 'Pay now'}
            </button>
          )}
          {!paidUp && (
            <span className="pm-trust">
              <IconShield size={12} />
              Secured payment via Stripe
            </span>
          )}
        </div>
      </div>

      <div id="pm-pay-panel">{children}</div>
    </section>
  );
}

interface HeroMessage {
  tone: 'paid' | 'due' | 'over';
  icon: React.ReactNode;
  title: string;
  sub: string;
  detail: string;
}

function heroMessage(model: PageModel): HeroMessage {
  const nextChargeStr = model.nextChargeOn ? formatDate(model.nextChargeOn.toISOString()) : null;
  switch (model.posture) {
    case 'overdue':
      return {
        tone: 'over',
        icon: <IconAlertTriangle size={23} />,
        title: 'Payment overdue',
        sub: 'One or more charges have passed their due date. Settling now stops further late fees.',
        detail: model.nextDue?.due_date
          ? `Oldest charge was due ${formatDate(model.nextDue.due_date)}`
          : 'Please settle your balance.',
      };
    case 'today':
      return {
        tone: 'due',
        icon: <IconCalendar size={23} />,
        title: 'Rent is due today',
        sub: 'Your rent is due today. Pay now to avoid any late fees.',
        detail: model.nextDue ? entryLabel(model.nextDue) : 'Rent due today',
      };
    case 'due':
      return {
        tone: 'due',
        icon: <IconCalendar size={23} />,
        title: 'Your rent is coming up',
        sub: 'You have a charge due soon. Paying early keeps you ahead.',
        detail: model.nextDue?.due_date
          ? `${model.nextDue ? entryLabel(model.nextDue) : 'Rent'} · due ${formatDate(model.nextDue.due_date)}`
          : 'Upcoming charge',
      };
    case 'ending':
      return {
        tone: 'paid',
        icon: <IconCheckCircle size={23} />,
        title: 'You are paid up to date',
        sub: model.lease?.endDate
          ? `No payment is needed. Your lease ends on ${formatDate(model.lease.endDate)}.`
          : 'No payment is needed right now.',
        detail: 'You are all caught up — no further rent is scheduled.',
      };
    default:
      return {
        tone: 'paid',
        icon: <IconCheckCircle size={23} />,
        title: 'You are paid up to date',
        sub: nextChargeStr
          ? `Nothing is due right now. Your next rent is scheduled for ${nextChargeStr}.`
          : 'Nothing is due right now.',
        detail: 'You are all caught up. Nice work.',
      };
  }
}

/* ============================================================================
   Pay panel — real Stripe PaymentElement, gated on gateway availability
   ============================================================================ */

type PayStep = 'review' | 'loading' | 'card' | 'error';

function PayPanel({
  entry,
  available,
  onClose,
  onPaid,
}: {
  entry: LedgerEntry;
  available: boolean;
  onClose: () => void;
  onPaid: (status: 'succeeded' | 'processing') => void;
}) {
  const [step, setStep] = useState<PayStep>('review');
  const [clientSecret, setClientSecret] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function beginCheckout() {
    setStep('loading');
    setError(null);
    try {
      const res = await tenantApi.initiatePayment(entry.id);
      setClientSecret(res.client_secret);
      setStep('card');
    } catch (err) {
      const e = normalizeError(err);
      setError(
        e.status === 503
          ? e.message
          : 'We could not start this payment. Please try again or contact your landlord.',
      );
      setStep('error');
    }
  }

  return (
    <div className="pm-pay open">
      <div className="pm-pay-in">
        <div className="pm-pay-pad">
          {/* What is being paid — from real ledger data */}
          <div className="pm-pay-review">
            <div className="pm-pay-review-row">
              <span>{entryLabel(entry)}</span>
              <span className="pm-mono">{formatCents(entry.display_amount_cents)}</span>
            </div>
            {entry.due_date && (
              <div className="pm-pay-review-row pm-muted">
                <span>Due date</span>
                <span>{formatDate(entry.due_date)}</span>
              </div>
            )}
            <div className="pm-pay-review-row is-total">
              <span>Total</span>
              <span className="pm-mono">{formatCents(entry.display_amount_cents)}</span>
            </div>
          </div>

          {!available ? (
            <div className="pm-pay-note">
              <IconInfo size={15} />
              <p>
                Online card payments are not enabled on this environment yet.
                Your balance and receipts here are fully live — please arrange
                this payment with your landlord for now.
              </p>
            </div>
          ) : step === 'review' ? (
            <>
              <div className="pm-pay-note">
                <IconLock size={15} />
                <p>
                  You pay the full obligation shown above. Card details are
                  handled securely by Stripe — {brand.appName} never sees your
                  card number.
                </p>
              </div>
              <div className="pm-pay-actions">
                <button className="pm-btn pm-btn-ghost pm-btn-sm" onClick={onClose} type="button">
                  Cancel
                </button>
                <button className="pm-btn pm-btn-pay pm-btn-sm" onClick={beginCheckout} type="button">
                  Continue to payment
                </button>
              </div>
            </>
          ) : step === 'loading' ? (
            <div className="pm-pay-processing">
              <span className="pm-spinner" />
              <span>Preparing a secure checkout…</span>
            </div>
          ) : step === 'error' ? (
            <>
              <div className="pm-pay-fail">
                <IconAlertTriangle size={16} />
                <p>{error}</p>
              </div>
              <div className="pm-pay-actions">
                <button className="pm-btn pm-btn-ghost pm-btn-sm" onClick={onClose} type="button">
                  Close
                </button>
                <button className="pm-btn pm-btn-pay pm-btn-sm" onClick={beginCheckout} type="button">
                  Try again
                </button>
              </div>
            </>
          ) : clientSecret ? (
            <Elements
              stripe={stripePromise}
              options={{ clientSecret, appearance: stripeAppearance() }}
            >
              <CheckoutForm
                amountLabel={formatCents(entry.display_amount_cents)}
                onCancel={onClose}
                onPaid={onPaid}
              />
            </Elements>
          ) : null}
        </div>
      </div>
    </div>
  );
}

function CheckoutForm({
  amountLabel,
  onCancel,
  onPaid,
}: {
  amountLabel: string;
  onCancel: () => void;
  onPaid: (status: 'succeeded' | 'processing') => void;
}) {
  const stripe = useStripe();
  const elements = useElements();
  const [submitting, setSubmitting] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!stripe || !elements) return;
    setSubmitting(true);
    setMessage(null);

    const { error, paymentIntent } = await stripe.confirmPayment({
      elements,
      // Stay in-app for card payments; Stripe only redirects if a method needs it.
      redirect: 'if_required',
      confirmParams: { return_url: window.location.href },
    });

    if (error) {
      setMessage(error.message ?? 'Your payment could not be completed.');
      setSubmitting(false);
      return;
    }
    if (paymentIntent && (paymentIntent.status === 'succeeded' || paymentIntent.status === 'processing')) {
      onPaid(paymentIntent.status);
      return;
    }
    setMessage('Your payment could not be completed. Please try another card.');
    setSubmitting(false);
  }

  return (
    <form className="pm-checkout" onSubmit={submit}>
      <PaymentElement options={{ layout: 'tabs' }} />
      {message && (
        <div className="pm-pay-fail">
          <IconAlertTriangle size={16} />
          <p>{message}</p>
        </div>
      )}
      <div className="pm-pay-actions">
        <button className="pm-btn pm-btn-ghost pm-btn-sm" onClick={onCancel} type="button" disabled={submitting}>
          Cancel
        </button>
        <button className="pm-btn pm-btn-pay" type="submit" disabled={!stripe || submitting}>
          {submitting ? <span className="pm-spinner pm-spinner-sm" /> : <IconLock size={15} />}
          {submitting ? 'Confirming…' : `Pay ${amountLabel}`}
        </button>
      </div>
    </form>
  );
}

/* ============================================================================
   Balance breakdown — straight from the server summary
   ============================================================================ */

function BalanceBreakdown({ model }: { model: PageModel }) {
  if (model.entries.length === 0) return null;
  return (
    <section className="pm-glass pm-sec">
      <div className="pm-sec-h">
        Balance breakdown
        <span className="pm-sec-hint">Your account at a glance</span>
      </div>
      <div className="pm-brk">
        <Row label="Rent charged to date" value={formatCents(model.rentChargedCents)} />
        <Row label="Fees charged" value={formatCents(model.feesChargedCents)} />
        <Row
          label="Payments received"
          value={model.collectedCents > 0 ? `– ${formatCents(model.collectedCents)}` : formatCents(0)}
          credit={model.collectedCents > 0}
        />
        <Row label="Outstanding balance" value={formatCents(model.outstandingCents)} total />
      </div>
    </section>
  );
}

function Row({
  label,
  value,
  credit,
  total,
}: {
  label: string;
  value: string;
  credit?: boolean;
  total?: boolean;
}) {
  return (
    <div className={`pm-brk-row ${total ? 'is-total' : ''}`}>
      <span className="pm-brk-label">{label}</span>
      <span className={`pm-brk-value pm-mono ${credit ? 'is-credit' : ''}`}>{value}</span>
    </div>
  );
}

/* ============================================================================
   Payment history — expandable receipts (real payment/refund entries)
   ============================================================================ */

function PaymentHistory({ entries }: { entries: LedgerEntry[] }) {
  return (
    <section className="pm-glass pm-sec">
      <div className="pm-sec-h">
        Payment history
        <span className="pm-sec-hint">Tap a payment for its receipt</span>
      </div>
      {entries.length === 0 ? (
        <EmptyState
          icon={<IconWallet size={24} />}
          title="No payments yet"
          description="Once you make a rent payment, your receipts and records appear here."
        />
      ) : (
        <div className="pm-hist">
          {entries.map((e) => (
            <HistoryRow key={e.id} entry={e} />
          ))}
        </div>
      )}
    </section>
  );
}

function HistoryRow({ entry }: { entry: LedgerEntry }) {
  const [open, setOpen] = useState(false);
  const method = entry.stripe_payment_intent_id ? 'Card · Stripe' : '—';
  return (
    <div className={`pm-hrow ${open ? 'is-open' : ''}`}>
      <button className="pm-hrow-main" onClick={() => setOpen((v) => !v)} type="button">
        <span className="pm-hrow-ic">
          <IconCheckCircle size={18} />
        </span>
        <span className="pm-hrow-desc">
          <span className="pm-hrow-title">{entry.display_label}</span>
          <span className="pm-hrow-meta pm-mono">
            {formatDate(entry.occurred_at)} · {method}
          </span>
        </span>
        <span className="pm-hrow-amt pm-mono">{formatCents(entry.display_amount_cents)}</span>
        <span className="pm-chip is-paid">Received</span>
        <span className="pm-hrow-chev">
          <IconChevronRight size={15} />
        </span>
      </button>
      <div className="pm-hdetail">
        <div className="pm-hdetail-in">
          <div className="pm-hdetail-pad">
            <KV k="Amount" v={formatCents(entry.display_amount_cents)} />
            <KV k="Date" v={formatDate(entry.occurred_at)} />
            <KV k="Payment method" v={method} />
            {entry.billing_period_start && (
              <KV k="Applied to" v={`${monthYear(entry.billing_period_start)} rent`} />
            )}
            {entry.reference && <KV k="Reference" v={entry.reference} mono />}
            {entry.running_balance_cents !== null && (
              <KV k="Balance after" v={formatCents(entry.running_balance_cents)} mono />
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

function KV({ k, v, mono }: { k: string; v: string; mono?: boolean }) {
  return (
    <div className="pm-kv">
      <span className="pm-kv-k">{k}</span>
      <span className={`pm-kv-v ${mono ? 'pm-mono' : ''}`}>{v}</span>
    </div>
  );
}

/* ============================================================================
   Upcoming charges — projected from the lease terms (clearly labelled)
   ============================================================================ */

function UpcomingCharges({ model }: { model: PageModel }) {
  const ending = model.posture === 'ending' || (model.leaseEndsInDays !== null && model.leaseEndsInDays <= 0);

  return (
    <section className="pm-glass pm-sec">
      <div className="pm-sec-h">
        Upcoming charges
        <span className="pm-sec-hint">Scheduled from your lease</span>
      </div>
      {ending || !model.monthlyRentCents || !model.nextChargeOn ? (
        <div className="pm-upcoming is-none">
          <span className="pm-upcoming-ic">
            <IconCalendar size={22} />
          </span>
          <div className="pm-upcoming-m">
            <div className="pm-upcoming-t">No upcoming rent scheduled</div>
            <div className="pm-upcoming-s">
              {model.lease?.endDate
                ? `Your lease ends on ${formatDate(model.lease.endDate)}.`
                : 'Nothing is scheduled right now.'}
            </div>
          </div>
        </div>
      ) : (
        <div className="pm-upcoming">
          <span className="pm-upcoming-ic">
            <IconCalendar size={22} />
          </span>
          <div className="pm-upcoming-m">
            <div className="pm-upcoming-t">Monthly rent</div>
            <div className="pm-upcoming-s">
              Due {formatDate(model.nextChargeOn.toISOString())} · not yet charged
            </div>
          </div>
          <div className="pm-upcoming-a">{formatCents(model.monthlyRentCents)}</div>
        </div>
      )}
    </section>
  );
}

/* ============================================================================
   Ledger table — the full immutable record with running balance
   ============================================================================ */

function LedgerTable({
  entries,
  payableId,
  onPay,
}: {
  entries: LedgerEntry[];
  payableId: string | null;
  onPay: (e: LedgerEntry) => void;
}) {
  return (
    <section className="pm-glass pm-sec" id="pm-ledger">
      <div className="pm-sec-h">
        Ledger details
        <span className="pm-sec-hint">The full record</span>
      </div>
      <div className="pm-ledger-note">
        <IconShield size={14} />
        Ledger entries can never be edited after they are created — corrections
        are made with new entries. This is what keeps your history trustworthy.
      </div>
      {entries.length === 0 ? (
        <EmptyState
          icon={<IconWallet size={24} />}
          title="No ledger activity"
          description="Rent charges and payments will appear here once your lease is active."
        />
      ) : (
        <div className="pm-tbl-scroll">
          <table className="pm-led">
            <thead>
              <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Description</th>
                <th className="pm-r">Charge</th>
                <th className="pm-r">Payment</th>
                <th className="pm-r">Balance</th>
                <th aria-label="Actions" />
              </tr>
            </thead>
            <tbody>
              {entries.map((e) => {
                const charge = isCharge(e);
                const payable = isPayable(e);
                return (
                  <tr key={e.id}>
                    <td className="pm-led-date">{formatDate(e.occurred_at)}</td>
                    <td>
                      <span className={`pm-tag is-${ledgerTag(e)}`}>{ledgerTag(e)}</span>
                    </td>
                    <td className="pm-led-desc">{entryLabel(e)}</td>
                    <td className="pm-r pm-mono">{charge ? formatCents(e.display_amount_cents) : '—'}</td>
                    <td className="pm-r pm-mono pm-pay-amt">
                      {!charge ? formatCents(e.display_amount_cents) : '—'}
                    </td>
                    <td className="pm-r pm-mono">
                      {e.running_balance_cents !== null ? formatCents(e.running_balance_cents) : '—'}
                    </td>
                    <td className="pm-r">
                      {payable ? (
                        <button
                          className="pm-btn-pay-inline"
                          onClick={() => onPay(e)}
                          type="button"
                          disabled={payableId === e.id}
                        >
                          {payableId === e.id ? 'Paying…' : 'Pay'}
                        </button>
                      ) : null}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

function ledgerTag(e: LedgerEntry): 'rent' | 'fee' | 'payment' | 'refund' {
  if (e.type === 'late_fee') return 'fee';
  if (e.type === 'payment') return 'payment';
  if (e.type === 'refund') return 'refund';
  return 'rent';
}

/* ============================================================================
   Support + empty lease
   ============================================================================ */

function SupportCard({ landlordName }: { landlordName: string | null }) {
  return (
    <section className="pm-glass pm-sec">
      <div className="pm-support">
        <div>
          <div className="pm-support-t">Payment help</div>
          <div className="pm-support-s">
            Questions about a charge or a receipt? Your landlord can help with
            rent{landlordName ? ` — reach ${landlordName} directly` : ''}, and{' '}
            {brand.supportName} can help with anything else.
          </div>
        </div>
        <div className="pm-support-actions">
          <Link className="pm-btn pm-btn-pay pm-btn-sm" to="/app/messages">
            <IconMessage size={15} />
            Message landlord
          </Link>
          <a className="pm-btn pm-btn-glass pm-btn-sm" href={`mailto:${brand.supportEmail}`}>
            Contact support
          </a>
        </div>
      </div>
    </section>
  );
}

function NoLease() {
  return (
    <section className="pm-glass pm-empty">
      <span className="pm-empty-ic">
        <IconHome size={28} />
      </span>
      <h2 className="pm-empty-t">No active lease found</h2>
      <p className="pm-empty-p">
        Payments become available once you have an active rental contract. When
        your landlord activates a lease, your balance and receipts will appear
        here.
      </p>
      <div className="pm-empty-actions">
        <Link className="pm-btn pm-btn-pay" to="/app/browse">
          Browse listings
          <IconArrowRight size={15} />
        </Link>
        <a className="pm-btn pm-btn-glass" href={`mailto:${brand.supportEmail}`}>
          Contact support
        </a>
      </div>
    </section>
  );
}

/* ── tiny util ─────────────────────────────────────────────────────────────── */
function ordinal(n: number): string {
  const s = ['th', 'st', 'nd', 'rd'];
  const v = n % 100;
  return n + (s[(v - 20) % 10] || s[v] || s[0]);
}
