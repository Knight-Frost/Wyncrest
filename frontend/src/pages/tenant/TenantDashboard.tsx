import { useEffect, useMemo, useState } from 'react';
import { useNavigate, Link } from 'react-router';
import { AnimatePresence, motion, useReducedMotion } from 'framer-motion';
import {
  Search, Bell, MessageSquare, MapPin, BedDouble, Bath, Heart,
  ArrowUpRight, ArrowRight, Check,
} from 'lucide-react';
import { useApi } from '@/hooks/useApi';
import { tenantApi } from '@/lib/endpoints';
import { formatDate, formatCents, formatCedisDecimal } from '@/lib/format';
import type { Application, Contract, ConversationSummary, LedgerStatus, LedgerType, Listing, ReadinessItem } from '@/lib/types';
import { Donut } from '@/components/ui/charts';
import { fadeRise, staggerContainer, staggerItem, DUR, EASE_OUT_SOFT } from '@/lib/motion';
import { ErrorState, ForbiddenState, SkeletonCard } from '@/components/ui/states';
import { Avatar } from '@/components/ui/Avatar';
import {
  CommandCard,
  StatusCard,
  DashboardSection,
  DataCardGrid,
  SemanticBadge,
  getPaymentBalanceVariant,
  getPaymentHealthVariant,
  getNextDueVariant,
  getApplicationVariant,
} from '@/components/cards';
import {
  IconWallet,
  IconCalendar,
  IconShield,
  IconCheck,
  IconHome,
  IconHeart,
  IconDoc,
} from '@/components/ui/icons';
import { InfoHint } from '@/components/ui/InfoHint';
import { help } from '@/lib/helpText';
import './tenant-dashboard.css';

/* ── imagery ─────────────────────────────────────────────────────────────── */
import heroImg from '@/assets/dashboard/home-1.jpg';
import h8 from '@/assets/dashboard/home-8.png';
import h9 from '@/assets/dashboard/home-9.png';
import h10 from '@/assets/dashboard/home-10.png';
import h11 from '@/assets/dashboard/home-11.png';
import h12 from '@/assets/dashboard/home-12.png';
import h3 from '@/assets/dashboard/home-3.jpg';
import h4 from '@/assets/dashboard/home-4.jpg';
import h5 from '@/assets/dashboard/home-5.jpg';
import h6 from '@/assets/dashboard/home-6.jpg';
import h13 from '@/assets/dashboard/home-13.png';

const CARD_IMGS = [h8, h9, h10, h11, h12, h3, h4, h5, h6, h13];

/* Static hero slides — decorative photography only (no implied listing name/location). */
const HERO_SLIDES = [heroImg, h9, h11, h4, h12];

/* ── helpers ─────────────────────────────────────────────────────────────── */
function daysUntil(dateStr: string): number {
  const target = new Date(dateStr);
  const today = new Date(); today.setHours(0, 0, 0, 0);
  return Math.ceil((target.getTime() - today.getTime()) / 86_400_000);
}

type DashState = 'no_lease_no_apps' | 'apps_in_progress' | 'active_lease';

function resolveDashState(activeContract: Contract | null, appsCount: number): DashState {
  if (activeContract) return 'active_lease';
  if (appsCount > 0) return 'apps_in_progress';
  return 'no_lease_no_apps';
}

function stepFromStatus(status: Application['status']): number {
  switch (status) {
    case 'submitted':       return 1;
    case 'in_review':       return 2;
    case 'landlord_review': return 3;
    case 'approved':
    case 'rejected':
    case 'withdrawn':       return 4;
    default:                return 0;
  }
}

/* ════════════════════════════════════════════════════════════════════════════
   NOW HOOK — re-renders once a minute so greeting/date stay honest.
   ════════════════════════════════════════════════════════════════════════ */
function useNow() {
  const [now, setNow] = useState(() => new Date());
  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 60_000);
    return () => clearInterval(id);
  }, []);
  return now;
}

/* ════════════════════════════════════════════════════════════════════════════
   HERO — full-bleed editorial masthead.
   ════════════════════════════════════════════════════════════════════════ */
function DashHero({
  firstName,
  state,
  activeContract,
  nextDueDate,
  daysLeft,
  unread,
  city,
}: {
  firstName: string;
  state: DashState;
  activeContract: Contract | null;
  nextDueDate: string | null;
  daysLeft: number | null;
  unread: number;
  city: string | null;
}) {
  const navigate = useNavigate();
  const reduce = useReducedMotion();
  const now = useNow();

  const [slide, setSlide] = useState(0);
  const [line, setLine] = useState(0);

  const hour = now.getHours();
  const timeOfDay = hour < 12 ? 'morning' : hour < 17 ? 'afternoon' : 'evening';

  /* Context-aware micro-copy — only truthful claims */
  const intel: Record<DashState, string[]> = {
    no_lease_no_apps: [
      'Find your next home.',
      'Save a listing to start tracking it here.',
      'Browse verified homes to get started.',
    ],
    apps_in_progress: [
      'Your applications are in progress.',
      'Keep your documents ready. Decisions come fast.',
      'Check the status of each application below.',
    ],
    active_lease: [
      daysLeft != null && daysLeft <= 7
        ? `Rent is due in ${daysLeft} day${daysLeft === 1 ? '' : 's'}.`
        : 'You\'re all settled in.',
      'Your tenancy is in good standing.',
      'Need a repair? Submit a maintenance request anytime.',
    ],
  };

  const cta = state === 'active_lease'
    ? (daysLeft != null && daysLeft <= 7
        ? { a: 'Pay rent', ar: '/app/ledger', b: 'Payment history', br: '/app/ledger' }
        : { a: 'View lease', ar: '/app/contracts', b: 'Payment history', br: '/app/ledger' })
    : state === 'apps_in_progress'
      ? { a: 'View applications', ar: '/app/applications', b: 'Browse homes', br: '/app/browse' }
      : { a: 'Browse homes', ar: '/app/browse', b: 'View saved', br: '/app/saved' };

  const lines = intel[state];

  /* slideshow + subtitle rotation (disabled under reduced-motion) */
  useEffect(() => {
    if (reduce) return;
    const s = setInterval(() => setSlide(p => (p + 1) % HERO_SLIDES.length), 7000);
    return () => clearInterval(s);
  }, [reduce]);
  useEffect(() => {
    if (reduce) return;
    const l = setInterval(() => setLine(p => (p + 1) % lines.length), 4600);
    return () => clearInterval(l);
  }, [reduce, lines.length]);

  const kicker = useMemo(() => {
    const day = now.toLocaleDateString('en-GB', { weekday: 'long' });
    const date = now.toLocaleDateString('en-GB', { day: 'numeric', month: 'long' });
    return city ? `${city} · ${day}, ${date}` : `${day}, ${date}`;
  }, [now, city]);

  /* Chip — truthful label only */
  const chip = state === 'active_lease'
    ? 'Active lease'
    : state === 'apps_in_progress'
      ? 'Application in progress'
      : null;

  /* Hero aside: show real next-due date when on active lease, else hide credit */
  const showLeaseAside = state === 'active_lease' && activeContract;

  return (
    <header className="td-hero">
      {/* interpolating photography — decorative only */}
      <div className="td-hero-stage" aria-hidden="true">
        {HERO_SLIDES.map((src, i) => (
          <div
            key={i}
            className={`td-hero-slide${i === slide ? ' on' : ''}`}
            style={{ backgroundImage: `url(${src})` }}
          />
        ))}
        <div className="td-hero-scrim" />
        <div className="td-hero-grain u-grain" />
      </div>

      {/* top utility line */}
      <div className="td-hero-top">
        <span className="td-kicker">{kicker}</span>
        <div className="td-hero-tools">
          <button className="td-tool" aria-label="Search" onClick={() => navigate('/app/browse')}>
            <Search size={15} strokeWidth={1.75} />
          </button>
          <button className="td-tool" aria-label="Notifications" onClick={() => navigate('/app/notifications')}>
            <Bell size={15} strokeWidth={1.75} />
            {unread > 0 && <span className="td-tool-dot" aria-hidden="true" />}
          </button>
          <button className="td-tool" aria-label="Messages" onClick={() => navigate('/app/messages')}>
            <MessageSquare size={15} strokeWidth={1.75} />
          </button>
        </div>
      </div>

      {/* bottom region */}
      <div className="td-hero-bottom">
        <div className="td-hero-body">
          {chip && <span className="td-hero-chip">{chip}</span>}
          <h1 className="td-hero-greet">
            {firstName
              ? <>Good {timeOfDay}, <span className="td-hero-name">{firstName}.</span></>
              : `Good ${timeOfDay}.`}
          </h1>
          <div className="td-hero-sub-wrap">
            <AnimatePresence mode="wait">
              <motion.p
                key={line}
                className="td-hero-sub"
                initial={reduce ? false : { opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                exit={reduce ? undefined : { opacity: 0, y: -8 }}
                transition={{ duration: DUR.hover, ease: EASE_OUT_SOFT }}
              >
                {lines[line]}
              </motion.p>
            </AnimatePresence>
          </div>
          <div className="td-hero-acts">
            <button className="td-btn-primary" onClick={() => navigate(cta.ar)}>
              {cta.a} <ArrowUpRight size={16} strokeWidth={2} />
            </button>
            <button className="td-btn-ghost" onClick={() => navigate(cta.br)}>
              {cta.b}
            </button>
          </div>
        </div>

        <div className="td-hero-aside">
          {showLeaseAside ? (
            <div className="td-credit">
              <span className="td-credit-lab">Next due</span>
              <span className="td-credit-nm">{nextDueDate ? formatDate(nextDueDate) : '—'}</span>
              {daysLeft != null && (
                <span className="td-credit-lo">
                  {daysLeft <= 0 ? 'Overdue' : `in ${daysLeft} day${daysLeft === 1 ? '' : 's'}`}
                </span>
              )}
            </div>
          ) : null}
          <div className="td-dots">
            {HERO_SLIDES.map((_, i) => (
              <button
                key={i}
                className={`td-dot${i === slide ? ' on' : ''}`}
                onClick={() => setSlide(i)}
                aria-label={`Slide ${i + 1}`}
              />
            ))}
          </div>
        </div>
      </div>
    </header>
  );
}

/* ════════════════════════════════════════════════════════════════════════════
   INDEX STRIP — editorial "figures" bar.
   ════════════════════════════════════════════════════════════════════════ */
function IndexStrip({
  appsCount,
  savedCount,
  readinessPct,
  verifiedCount,
  pendingRent,
}: {
  appsCount: number;
  savedCount: number;
  readinessPct: number;
  verifiedCount: number;
  pendingRent: string | null;
}) {
  const figures = [
    { lab: 'Applications', val: String(appsCount).padStart(2, '0'), to: '/app/applications' },
    { lab: 'Saved homes', val: String(savedCount).padStart(2, '0'), to: '/app/saved' },
    { lab: 'Tenant readiness', val: `${readinessPct}%`, to: '/app/profile' },
    pendingRent
      ? { lab: 'Rent due', val: pendingRent, to: '/app/ledger' }
      : { lab: 'Verified homes', val: String(verifiedCount).padStart(2, '0'), to: '/app/browse' },
  ];
  return (
    <motion.div
      className="td-index"
      variants={staggerContainer(0.07)}
      initial="hidden"
      animate="show"
    >
      {figures.map((f) => (
        <motion.div key={f.lab} variants={staggerItem}>
          <Link to={f.to} className="td-index-cell">
            <span className="td-index-lab">{f.lab}</span>
            <span className="td-index-val num-old">{f.val}</span>
          </Link>
        </motion.div>
      ))}
    </motion.div>
  );
}

/* ════════════════════════════════════════════════════════════════════════════
   PAYMENT SUMMARY ROW — CommandCard + StatusCards via semantic variants.
   Spec: ONE CommandCard for outstanding balance, StatusCards for next due,
   payment health, and lifetime paid (honest: only if confirmed paid data exists).
   ════════════════════════════════════════════════════════════════════════ */
function PaymentSummaryRow({
  balanceCents,
  hasHistory,
  nextDue,
  daysLeft,
}: {
  balanceCents: number;
  hasHistory: boolean;
  nextDue: {
    amount_cents: number;
    due_date: string | null;
    status: LedgerStatus;
    type: LedgerType;
  } | null;
  daysLeft: number | null;
}) {
  const navigate = useNavigate();

  const hasOverdue = daysLeft != null && daysLeft < 0 && balanceCents > 0;
  const balanceVariant = getPaymentBalanceVariant(balanceCents, hasOverdue);
  const nextDueVariant = getNextDueVariant(daysLeft);
  const healthVariant = getPaymentHealthVariant(hasOverdue, hasHistory);

  const balanceSub =
    balanceCents <= 0
      ? 'All clear, nothing owed'
      : hasOverdue
        ? 'Rent is overdue. Take action now.'
        : `Balance outstanding`;

  const nextDueSub = nextDue?.due_date
    ? (daysLeft != null && daysLeft < 0
        ? `Overdue ${Math.abs(daysLeft)} day${Math.abs(daysLeft) === 1 ? '' : 's'}`
        : daysLeft != null && daysLeft === 0
          ? 'Due today'
          : daysLeft != null
            ? `Due in ${daysLeft} day${daysLeft === 1 ? '' : 's'}`
            : formatDate(nextDue.due_date))
    : 'No upcoming entry';

  const healthLabel =
    !hasHistory
      ? 'No ledger data yet'
      : hasOverdue
        ? 'Overdue'
        : 'On time';

  return (
    <DataCardGrid cols={4}>
      {/* Level 3 Command Card — the featured item */}
      <CommandCard
        label="Outstanding balance"
        help={help.outstandingBalance}
        value={formatCents(balanceCents)}
        sub={balanceSub}
        icon={<IconWallet size={18} />}
        role={balanceVariant}
        onClick={() => navigate('/app/ledger')}
      />

      {/* Level 2 Status Card — next payment */}
      <StatusCard
        label={<>Next payment <InfoHint text={help.nextPayment} label="About next payment" /></>}
        value={nextDue ? formatCents(nextDue.amount_cents) : '—'}
        sub={nextDueSub}
        icon={<IconCalendar size={18} />}
        role={nextDueVariant}
        onClick={() => navigate('/app/ledger')}
      />

      {/* Level 2 Status Card — payment health */}
      <StatusCard
        label={<>Payment standing <InfoHint text={help.paymentStanding} label="About payment standing" /></>}
        value={healthLabel}
        sub={hasHistory ? `Based on ledger activity` : 'No payments recorded yet'}
        icon={<IconShield size={18} />}
        role={healthVariant}
      />

      {/* Level 2 Status Card — lifetime paid.
          NOTE: The dashboard endpoint does NOT return a lifetime_paid_cents field.
          The backend only exposes unpaid (outstanding) balance. We show an honest
          "View history" prompt rather than fabricate a figure. */}
      <StatusCard
        label={<>Lifetime paid <InfoHint text={help.lifetimePaid} label="About lifetime paid" /></>}
        value="—"
        sub="View your full payment history"
        icon={<IconCheck size={18} />}
        role="info"
        onClick={() => navigate('/app/ledger')}
      />
    </DataCardGrid>
  );
}

/* ════════════════════════════════════════════════════════════════════════════
   APPLICATION PROGRESS — vertical editorial timeline.
   ════════════════════════════════════════════════════════════════════════ */
const STEP_LABELS = ['Submitted', 'In review', 'Landlord review', 'Decision'] as const;

function VerticalSteps({ step }: { step: number }) {
  return (
    <ol className="td-steps">
      {STEP_LABELS.map((label, i) => {
        const done = i < step;
        const current = i === step;
        return (
          <li key={label} className={`td-step${done ? ' done' : ''}${current ? ' current' : ''}`}>
            <span className="td-step-mark">{done ? <Check size={11} strokeWidth={3} /> : null}</span>
            <span className="td-step-lab">{label}</span>
          </li>
        );
      })}
    </ol>
  );
}

function ApplicationProgress({ applications }: { applications: Application[] }) {
  const navigate = useNavigate();
  const display = applications.slice(0, 3);

  return (
    <DashboardSection
      eyebrow="Applications"
      action={<Link to="/app/applications" className="td-link">All <ArrowRight size={13} /></Link>}
    >
      {display.length === 0 ? (
        <div className="td-saved-empty">
          <IconDoc size={22} />
          <p>No applications yet. Homes you apply for will appear here.</p>
          <button className="td-btn-ghost td-ghost-ink" onClick={() => navigate('/app/browse')}>Browse homes</button>
        </div>
      ) : (
        <div className="td-apps">
          {display.map((app, i) => {
            const listing = app.listing;
            const unit = listing?.unit;
            const prop = unit?.property;
            const title = listing?.title ?? 'Listing';
            const loc = prop ? `${prop.city}${prop.state ? `, ${prop.state}` : ''}` : null;
            const img = listing?.primary_photo?.path
              ? `${import.meta.env.VITE_API_URL ?? ''}/storage/${listing.primary_photo.path}`
              : CARD_IMGS[i % CARD_IMGS.length];
            const step = stepFromStatus(app.status);
            const appRole = getApplicationVariant(app.status);
            return (
              <article key={app.id} className="td-app">
                <div className="u-zoom td-app-img">
                  <img src={img} alt="" loading="lazy" onError={e => { (e.currentTarget as HTMLImageElement).src = CARD_IMGS[0]; }} />
                </div>
                <div className="td-app-main">
                  <div className="td-app-top">
                    <div>
                      <h3 className="td-app-name">{title}</h3>
                      <p className="td-app-meta">
                        {loc && <><MapPin size={12} /> {loc} · </>}
                        Applied {app.submitted_at ? formatDate(app.submitted_at) : formatDate(app.created_at)}
                      </p>
                    </div>
                    <SemanticBadge role={appRole} status={app.status} />
                  </div>
                  <VerticalSteps step={step} />
                </div>
              </article>
            );
          })}
        </div>
      )}
    </DashboardSection>
  );
}

/* ════════════════════════════════════════════════════════════════════════════
   ACTIVE LEASE — feature panel shown when the tenant holds a lease.
   Shows property identity + honest standing indicator only (no payment figures
   duplicated from the PaymentSummaryRow that sits above this panel).
   ════════════════════════════════════════════════════════════════════════ */
function ActiveLeaseSummary({
  contract,
  nextDueDate,
  daysLeft,
  balanceCents,
}: {
  contract: Contract;
  nextDueDate: string | null;
  daysLeft: number | null;
  balanceCents: number;
}) {
  const navigate = useNavigate();
  const unit = contract.listing?.unit;
  const prop = unit?.property;
  const location = prop ? `${prop.city}${prop.state ? `, ${prop.state}` : ''}` : null;
  const beds = unit?.bedrooms ? parseInt(unit.bedrooms, 10) : null;
  const label = beds === 1 ? '1 Bed Studio' : beds ? `${beds} Bed Apartment` : (prop?.name ?? 'Your Property');

  /* Payment standing — an HONEST state from real data, not a fabricated score.
     (Previously rendered a fake 95/72/55 "Health %" donut; there is no such
     metric in the backend, so we show the true cleared/due/overdue posture.) */
  const hasOverdue = balanceCents > 0 && daysLeft != null && daysLeft < 0;
  const standing =
    balanceCents <= 0
      ? { label: 'In good standing', detail: 'No balance due', color: 'var(--color-success-600)', tint: 'var(--color-success-50)' }
      : hasOverdue
        ? { label: 'Action needed', detail: 'Rent overdue', color: 'var(--color-danger-600)', tint: 'var(--color-danger-50)' }
        : { label: 'Payment due', detail: daysLeft != null ? `Due in ${daysLeft} days` : 'Upcoming', color: 'var(--color-warning-600)', tint: 'var(--color-warning-50)' };

  return (
    <DashboardSection
      eyebrow="Your lease"
      action={<Link to="/app/contracts" className="td-link">Lease <ArrowRight size={13} /></Link>}
    >
      <article className="td-lease">
        <div className="td-lease-main">
          <h3 className="td-lease-name">{label}</h3>
          {location && <p className="td-app-meta"><MapPin size={12} /> {location}</p>}
          <div className="td-lease-figs">
            <div>
              <span className="td-fig-lab">Monthly rent</span>
              <span className="td-fig-val num-old">{formatCents(contract.rent_amount)}</span>
            </div>
            <div>
              <span className="td-fig-lab">Next due</span>
              <span className="td-fig-val num-old">{nextDueDate ? formatDate(nextDueDate) : `${contract.payment_day}th`}</span>
              {daysLeft != null && (
                <span className={`td-fig-hint${daysLeft <= 0 ? ' over' : ''}`}>
                  {daysLeft <= 0 ? 'Overdue' : `in ${daysLeft} days`}
                </span>
              )}
            </div>
          </div>
          <div className="td-hero-acts">
            <button className="td-btn-primary" onClick={() => navigate('/app/ledger')}>Pay rent <ArrowUpRight size={16} strokeWidth={2} /></button>
            <button className="td-btn-ghost td-ghost-ink" onClick={() => navigate('/app/contracts')}>View lease</button>
          </div>
        </div>
        <div className="td-lease-health">
          <span
            className="flex h-[104px] w-[104px] flex-col items-center justify-center rounded-full text-center"
            style={{ backgroundColor: standing.tint, color: standing.color }}
            role="img"
            aria-label={`Payment standing: ${standing.label}. ${standing.detail}.`}
          >
            <span className="font-display text-sm font-semibold leading-tight">{standing.label}</span>
          </span>
          <span className="td-health-lab">{standing.detail}</span>
        </div>
      </article>
    </DashboardSection>
  );
}

/* ════════════════════════════════════════════════════════════════════════════
   TENANT READINESS (rail) — donut + checklist driven by real API data.
   ════════════════════════════════════════════════════════════════════════ */
function ReadinessBand({ pct, items }: { pct: number; items: ReadinessItem[] }) {
  const navigate = useNavigate();
  return (
    <DashboardSection
      eyebrow="Tenant readiness"
      action={
        <span className={`td-link${pct === 100 ? ' td-ok' : ''}`}>
          {pct === 100 ? 'Complete' : `${pct}% ready`}
        </span>
      }
    >
      <div className="td-ready-bar">
        <div className="td-ready-gauge">
          <Donut pct={pct} size={96} label="Ready" />
        </div>
        <ul className="td-ready-items">
          {items.map((item) => (
            <li key={item.key} className={item.complete ? 'done' : ''}>
              <span className="td-ready-mark">{item.complete ? <Check size={11} strokeWidth={3} /> : null}</span>
              <span className="td-ready-txt">{item.label}</span>
            </li>
          ))}
        </ul>
        {pct < 100 && (
          <button className="td-btn-primary td-ready-cta" onClick={() => navigate('/app/profile')}>
            Complete profile <ArrowUpRight size={16} strokeWidth={2} />
          </button>
        )}
      </div>
    </DashboardSection>
  );
}

/* ════════════════════════════════════════════════════════════════════════════
   MESSAGES (rail) — real conversations from the dashboard payload.
   ════════════════════════════════════════════════════════════════════════ */
function MessagesRow({ conversations }: { conversations: ConversationSummary[] }) {
  const navigate = useNavigate();
  const display = conversations.slice(0, 3);

  return (
    <DashboardSection
      eyebrow="Messages"
      action={<Link to="/app/messages" className="td-link">All <ArrowRight size={13} /></Link>}
    >
      {display.length === 0 ? (
        <div className="td-saved-empty">
          <MessageSquare size={22} strokeWidth={1.5} />
          <p>No messages yet.</p>
          <button className="td-btn-ghost td-ghost-ink" onClick={() => navigate('/app/messages')}>Go to messages</button>
        </div>
      ) : (
        <div className="td-msg-grid">
          {display.map((conv) => {
            const name = conv.other_participant?.name ?? 'Unknown';
            const avatarSrc = conv.other_participant?.avatar_url ?? null;
            const fallbackInitials = conv.other_participant?.initials
              ?? name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase();
            const preview = conv.preview ?? conv.last_message_preview ?? '';
            const hasUnread = conv.unread_count > 0;
            const time = conv.last_message_at
              ? new Date(conv.last_message_at).toLocaleString('en-GB', { hour: 'numeric', minute: '2-digit', hour12: true })
              : '';
            return (
              <button key={conv.id} className="td-msg-cell" onClick={() => navigate('/app/messages')}>
                <span className="td-msg-cell-top">
                  <Avatar name={name} src={avatarSrc} fallback={fallbackInitials} className={`td-msg-av${hasUnread ? ' unread' : ''}`} />
                  <span className="td-msg-tm">{time}</span>
                </span>
                <span className="td-msg-nm">{name}</span>
                {conv.title && <span className="td-msg-role">{conv.title}</span>}
                <span className="td-msg-prev2">{preview}</span>
              </button>
            );
          })}
        </div>
      )}
    </DashboardSection>
  );
}

/* ════════════════════════════════════════════════════════════════════════════
   CURATED — full-width editorial property gallery.
   ════════════════════════════════════════════════════════════════════════ */
function toCardData(l: Listing, i: number) {
  const unit = l.unit;
  const prop = unit?.property;
  return {
    id: l.id,
    img: l.primary_photo?.path
      ? `${import.meta.env.VITE_API_URL ?? ''}/storage/${l.primary_photo.path}`
      : CARD_IMGS[i % CARD_IMGS.length],
    name: l.title,
    loc: prop ? `${prop.city}${prop.state ? `, ${prop.state}` : ''}` : null,
    price: unit?.rent_amount ?? null,
    beds: unit?.bedrooms ? parseInt(unit.bedrooms, 10) : 0,
    baths: unit?.bathrooms ? parseInt(unit.bathrooms, 10) : 0,
  };
}

function CuratedHomes({ listings, savedIds, onToggle }: {
  listings: Listing[];
  savedIds: Set<number>;
  onToggle: (id: number) => void;
}) {
  const navigate = useNavigate();

  return (
    <DashboardSection
      eyebrow="Curated for you"
      action={<Link to="/app/browse" className="td-link">Browse all <ArrowRight size={13} /></Link>}
    >
      {listings.length === 0 ? (
        <div className="td-saved-empty">
          <IconHome size={22} />
          <p>No curated listings available right now.</p>
          <button className="td-btn-ghost td-ghost-ink" onClick={() => navigate('/app/browse')}>Browse all homes</button>
        </div>
      ) : (
        <motion.div
          className="td-gal-row"
          variants={staggerContainer(0.06)}
          initial="hidden"
          whileInView="show"
          viewport={{ once: true, margin: '0px 0px -12% 0px' }}
        >
          {listings.map((listing, i) => {
            const c = toCardData(listing, i);
            return (
              <motion.article
                key={c.id}
                variants={staggerItem}
                className="td-prop u-card-hover"
                onClick={() => navigate(`/app/listing/${c.id}`)}
              >
                <div className="u-zoom td-prop-img">
                  <img src={c.img} alt={c.name} loading="lazy"
                    onError={e => { (e.currentTarget as HTMLImageElement).src = CARD_IMGS[0]; }} />
                  <span className="td-prop-badge">Verified</span>
                  <button
                    className={`td-prop-heart${savedIds.has(c.id) ? ' on' : ''}`}
                    onClick={e => { e.stopPropagation(); onToggle(c.id); }}
                    aria-label={savedIds.has(c.id) ? 'Remove from saved' : 'Save listing'}
                  >
                    <Heart size={14} strokeWidth={2} fill={savedIds.has(c.id) ? 'currentColor' : 'none'} />
                  </button>
                </div>
                <div className="td-prop-body">
                  {c.loc && <p className="td-prop-loc"><MapPin size={11} /> {c.loc}</p>}
                  <h3 className="td-prop-name">{c.name}</h3>
                  <div className="td-prop-foot">
                    <span className="td-prop-price num-old">
                      {c.price ? formatCedisDecimal(c.price) : '—'}<small> /mo</small>
                    </span>
                    <span className="td-prop-meta">
                      {c.beds > 0 && <span><BedDouble size={13} /> {c.beds}</span>}
                      {c.baths > 0 && <span><Bath size={13} /> {c.baths}</span>}
                    </span>
                  </div>
                </div>
              </motion.article>
            );
          })}
        </motion.div>
      )}
    </DashboardSection>
  );
}

/* ════════════════════════════════════════════════════════════════════════════
   SAVED — clean hairline list panel (sits in the lead column).
   ════════════════════════════════════════════════════════════════════════ */
function SavedRow({ listings }: { listings: Listing[] }) {
  const navigate = useNavigate();
  const display = listings.slice(0, 3);

  return (
    <DashboardSection
      eyebrow="Saved homes"
      action={<Link to="/app/saved" className="td-link">All <ArrowRight size={13} /></Link>}
    >
      {display.length === 0 ? (
        <div className="td-saved-empty">
          <IconHeart size={22} />
          <p>No saved homes yet. Tap the heart on a listing to keep it here.</p>
          <button className="td-btn-ghost td-ghost-ink" onClick={() => navigate('/app/browse')}>Browse homes</button>
        </div>
      ) : (
        <div className="td-saved-grid">
          {display.map((l, i) => {
            const unit = l.unit;
            const img = l.primary_photo?.path
              ? `${import.meta.env.VITE_API_URL ?? ''}/storage/${l.primary_photo.path}`
              : CARD_IMGS[i % CARD_IMGS.length];
            return (
              <button key={l.id} className="td-saved-card u-card-hover" onClick={() => navigate(`/app/listing/${l.id}`)}>
                <span className="u-zoom td-saved-cimg">
                  <img src={img} alt="" onError={e => { (e.currentTarget as HTMLImageElement).src = CARD_IMGS[0]; }} />
                </span>
                <span className="td-saved-cbody">
                  <span className="td-saved-nm">{l.title}</span>
                  <span className="td-saved-lo"><MapPin size={11} /> {unit?.property?.city ?? ''}</span>
                  <span className="td-saved-pr num-old">
                    {unit?.rent_amount ? <>{formatCedisDecimal(unit.rent_amount)}<small> /mo</small></> : '—'}
                  </span>
                </span>
              </button>
            );
          })}
        </div>
      )}
    </DashboardSection>
  );
}

/* ── skeleton ────────────────────────────────────────────────────────────── */
function DashSkeleton() {
  return (
    <div className="td-root">
      <div className="td-sk" style={{ height: 420, borderRadius: 16 }} />
      <div className="td-sk" style={{ height: 88, borderRadius: 12 }} />
      {/* Payment summary skeleton — matching 4-card DataCardGrid */}
      <div className="td-sk-cards-row">
        <SkeletonCard />
        <SkeletonCard />
        <SkeletonCard />
        <SkeletonCard />
      </div>
      <div className="td-sk" style={{ height: 320, borderRadius: 16 }} />
      <div className="td-sk" style={{ height: 200, borderRadius: 16 }} />
    </div>
  );
}

/* ════════════════════════════════════════════════════════════════════════════
   MAIN
   ════════════════════════════════════════════════════════════════════════ */
export function TenantDashboard() {
  const dash = useApi(() => tenantApi.dashboard(), []);

  /* Optimistic saved toggle — persisted to server on real saves pages */
  const [localSaved, setLocalSaved] = useState<Set<number>>(new Set());
  const onToggle = (id: number) =>
    setLocalSaved(p => {
      const n = new Set(p);
      if (n.has(id)) { n.delete(id); } else { n.add(id); }
      return n;
    });

  if (dash.loading) return <DashSkeleton />;

  if (dash.error) {
    if (dash.error.status === 403) {
      return (
        <div className="td-root">
          <ForbiddenState title="Dashboard unavailable" message="You don't have access to this area." />
        </div>
      );
    }
    return (
      <div className="td-root">
        <div className="td-panel" style={{ padding: '4rem 2rem', textAlign: 'center' }}>
          <ErrorState
            title="Could not load your dashboard"
            message="Your data is safe. Please try again."
            onRetry={dash.reload}
          />
        </div>
      </div>
    );
  }

  if (!dash.data) return <DashSkeleton />;

  const d = dash.data;
  const { user, readiness, stats, active_contract, rent_summary, applications,
    curated_listings, saved_listings, recent_conversations } = d;

  const firstName = user.first_name ?? '';
  const dashState = resolveDashState(active_contract, stats.applications_count);

  /* Next due from rent_summary */
  const nextDue = rent_summary?.next_due ?? null;
  const nextDueDate = nextDue?.due_date ?? null;
  const daysLeft = nextDueDate ? daysUntil(nextDueDate) : null;

  /* Pending rent display for index strip */
  const pendingRent = (() => {
    if (!rent_summary || rent_summary.balance_cents <= 0) return null;
    return formatCents(rent_summary.balance_cents);
  })();

  /* Saved IDs = from API + local optimistic */
  const apiSavedIds = new Set<number>(saved_listings.map(l => l.id));
  const savedIds = new Set<number>([...apiSavedIds, ...localSaved]);

  return (
    <div className="td-root">
      <DashHero
        firstName={firstName}
        state={dashState}
        activeContract={active_contract}
        nextDueDate={nextDueDate}
        daysLeft={daysLeft}
        unread={stats.unread_notifications_count}
        city={user.city}
      />

      <IndexStrip
        appsCount={stats.applications_count}
        savedCount={stats.saved_listings_count}
        readinessPct={readiness.percentage}
        verifiedCount={stats.verified_listings_count}
        pendingRent={pendingRent}
      />

      {/* Payment summary — only shown when tenant has an active lease */}
      {dashState === 'active_lease' && rent_summary && (
        <motion.div variants={fadeRise} initial="hidden" animate="show">
          <PaymentSummaryRow
            balanceCents={rent_summary.balance_cents}
            hasHistory={rent_summary.has_history}
            nextDue={nextDue}
            daysLeft={daysLeft}
          />
        </motion.div>
      )}

      <motion.div variants={fadeRise} initial="hidden" animate="show">
        {dashState === 'active_lease' && active_contract
          ? <ActiveLeaseSummary
              contract={active_contract}
              nextDueDate={nextDueDate}
              daysLeft={daysLeft}
              balanceCents={rent_summary?.balance_cents ?? 0}
            />
          : <ApplicationProgress applications={applications} />}
      </motion.div>

      <ReadinessBand pct={readiness.percentage} items={readiness.items} />

      <CuratedHomes listings={curated_listings} savedIds={savedIds} onToggle={onToggle} />

      <MessagesRow conversations={recent_conversations} />

      <SavedRow listings={saved_listings} />
    </div>
  );
}
