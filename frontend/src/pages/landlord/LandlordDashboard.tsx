import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router';
import { motion } from 'framer-motion';
import { useApi } from '@/hooks/useApi';
import { useAuth } from '@/context/auth';
import { landlordApi } from '@/lib/endpoints';
import type {
  Application,
  LandlordDashboard as LandlordDashboardData,
  LandlordOnboarding,
  Listing,
  MaintenanceRequest,
} from '@/lib/types';
import {
  formatCents,
  formatCedisDecimal,
  formatDateTime,
  humanize,
  listingStatusTone,
} from '@/lib/format';
import { Donut, MiniLineChart } from '@/components/ui/charts';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { InfoHint } from '@/components/ui/InfoHint';
import { help } from '@/lib/helpText';
import {
  LoadingState,
  ErrorState,
  ForbiddenState,
  EmptyState,
  Skeleton,
} from '@/components/ui/states';
import {
  IconWallet,
  IconHome,
  IconBuilding,
  IconFileText,
  IconUsers,
  IconWrench,
  IconArrowRight,
  IconArrowUpRight,
  IconPlus,
  IconCheck,
  IconCalendar,
  IconKey,
  IconAlertTriangle,
  IconBarChart,
} from '@/components/ui/icons';
import {
  CommandCard,
  StatusCard,
  NexusCard,
  SemanticBadge,
  DashboardSection,
  DataCardGrid,
  getCollectedVariant,
  getOccupancyVariant,
  getReviewQueueVariant,
} from '@/components/cards';
import { fadeRise, staggerContainer, staggerItem } from '@/lib/motion';
import './landlord-dashboard.css';

/* ── imagery — single warm interior; decorative only (no implied listing) ──── */
import heroImg from '@/assets/dashboard/home-3.jpg';

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

/* Relative-ish time that stays truthful (falls back to absolute date-time). */
function whenLabel(iso: string | null | undefined): string {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '—';
  const diff = Date.now() - d.getTime();
  const mins = Math.floor(diff / 60_000);
  if (mins < 1) return 'Just now';
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  const days = Math.floor(hrs / 24);
  if (days < 7) return `${days}d ago`;
  return formatDateTime(iso);
}

function initialsOf(name: string): string {
  return name
    .split(' ')
    .map((w) => w[0])
    .filter(Boolean)
    .join('')
    .slice(0, 2)
    .toUpperCase();
}

/* ════════════════════════════════════════════════════════════════════════════
   TOP UTILITY BAR — real current date + portfolio entry points.
   ════════════════════════════════════════════════════════════════════════ */
function UtilityBar() {
  return (
    <div className="ld-util">
      <div className="ld-util-acts">
        <Link to="/app/properties">
          <Button variant="secondary" size="sm" leftIcon={<IconPlus size={15} />}>
            Add property
          </Button>
        </Link>
        <Link to="/app/listings">
          <Button size="sm" leftIcon={<IconHome size={15} />}>
            Listings
          </Button>
        </Link>
      </div>
    </div>
  );
}

/* ════════════════════════════════════════════════════════════════════════════
   HERO — warm editorial masthead. Greeting (time-of-day + name) AND the
   subtitle are both live: the subtitle surfaces the single most pressing real
   thing in the portfolio right now, falling back to an all-clear message.
   ════════════════════════════════════════════════════════════════════════ */
function heroSubtitle(d: LandlordDashboardData): string {
  const n = (count: number, one: string, many: string) =>
    `${count} ${count === 1 ? one : many}`;

  if (d.ledger.overdue_cents > 0) {
    return `${formatCents(d.ledger.overdue_cents)} in rent is overdue. Review it today.`;
  }
  if (d.applications.awaiting_review > 0) {
    return `You have ${n(d.applications.awaiting_review, 'application', 'applications')} awaiting your review.`;
  }
  if (d.maintenance.open > 0) {
    return `${n(d.maintenance.open, 'maintenance request needs', 'maintenance requests need')} attention.`;
  }
  if (d.contracts.expiring_soon > 0) {
    return `${n(d.contracts.expiring_soon, 'lease is', 'leases are')} expiring soon.`;
  }
  if (d.contracts.pending_tenant > 0) {
    return `${n(d.contracts.pending_tenant, 'contract is', 'contracts are')} awaiting a tenant signature.`;
  }
  if (d.portfolio.pending_review_listings > 0) {
    return `${n(d.portfolio.pending_review_listings, 'listing is', 'listings are')} pending admin review.`;
  }
  if (d.portfolio.vacant_units > 0) {
    return `${n(d.portfolio.vacant_units, 'unit is', 'units are')} vacant and ready to list.`;
  }
  if (d.portfolio.total_properties === 0) {
    return 'Add your first property to start building your portfolio.';
  }
  return 'Everything\'s running smoothly across your portfolio today.';
}

function DashHero({ firstName, data }: { firstName: string; data: LandlordDashboardData }) {
  const now = useNow();
  const hour = now.getHours();
  const timeOfDay = hour < 12 ? 'morning' : hour < 17 ? 'afternoon' : 'evening';
  const today = now.toLocaleDateString('en-GB', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
  });

  return (
    <header className="ld-hero">
      <div className="ld-hero-stage" aria-hidden="true">
        <div className="ld-hero-photo" style={{ backgroundImage: `url(${heroImg})` }} />
        <div className="ld-hero-scrim" />
        <div className="ld-hero-wash" />
        <div className="ld-hero-grain u-grain" />
      </div>

      <div className="ld-hero-top">
        <span className="ld-hero-date">{today}</span>
      </div>

      <div className="ld-hero-body">
        <span className="ld-hero-eyebrow">Welcome back</span>
        <h1 className="ld-hero-greet">
          Good {timeOfDay}
          {firstName ? <>, <span className="ld-hero-name">{firstName}</span></> : ''}.
        </h1>
        <p className="ld-hero-sub">{heroSubtitle(data)}</p>
        <div className="ld-hero-acts">
          <Link to="/app/listings" className="ld-btn-primary">
            Add a listing <IconArrowUpRight size={16} />
          </Link>
          <Link to="/app/applicants" className="ld-btn-ghost">
            View applicants
          </Link>
        </div>
      </div>
    </header>
  );
}

/* ════════════════════════════════════════════════════════════════════════════
   FINANCIAL METRICS — semantic card row.
   Collected this month: StatusCard (getCollectedVariant) + MiniLineChart footer.
   Outstanding/Overdue:  CommandCard danger if overdue > 0 else StatusCard.
   Occupancy:            StatusCard (getOccupancyVariant) + Donut footer.
   Active leases:        StatusCard neutral.
   ════════════════════════════════════════════════════════════════════════ */
function FinancialMetrics({
  ledger,
  portfolio,
  contracts,
  rentTrend,
}: {
  ledger: LandlordDashboardData['ledger'];
  portfolio: LandlordDashboardData['portfolio'];
  contracts: LandlordDashboardData['contracts'];
  rentTrend: LandlordDashboardData['rent_trend'];
}) {
  const collectedRole = getCollectedVariant(
    ledger.collected_this_month_cents,
    ledger.overdue_cents,
  );

  const occupancyPct =
    portfolio.total_units > 0
      ? Math.round((portfolio.occupied_units / portfolio.total_units) * 100)
      : 0;
  const occupancyRole = getOccupancyVariant(occupancyPct);

  /* Sparkline data from real rent_trend — only render if we have ≥2 points. */
  const collectedSeries = rentTrend.map((t) => t.collected_cents);
  const outstandingSeries = rentTrend.map((t) => t.outstanding_cents);

  const hasOverdue = ledger.overdue_cents > 0;

  return (
    <motion.div
      variants={staggerContainer(0.06)}
      initial="hidden"
      animate="show"
    >
      <DataCardGrid cols={4}>
        {/* ── Collected this month ── */}
        <motion.div variants={staggerItem}>
          <StatusCard
            label={
              <>
                Collected this month
                <InfoHint text={help.collected} label="About collected this month" />
              </>
            }
            value={formatCents(ledger.collected_this_month_cents)}
            sub={
              ledger.collected_this_month_cents > 0
                ? 'Rent received this month'
                : 'No collections recorded yet'
            }
            icon={<IconWallet size={18} />}
            role={collectedRole}
            footer={
              collectedSeries.length >= 2 ? (
                <MiniLineChart
                  data={collectedSeries}
                  color={
                    collectedRole === 'success'
                      ? 'var(--color-success-600)'
                      : collectedRole === 'danger'
                        ? 'var(--color-danger-600)'
                        : 'var(--color-ink-400)'
                  }
                  height={34}
                />
              ) : undefined
            }
          />
        </motion.div>

        {/* ── Outstanding / Overdue — CommandCard when overdue, StatusCard when clear ── */}
        <motion.div variants={staggerItem}>
          {hasOverdue ? (
            <CommandCard
              label="Outstanding balance"
              value={formatCents(ledger.outstanding_cents)}
              sub={`${formatCents(ledger.overdue_cents)} overdue. Action needed.`}
              icon={<IconAlertTriangle size={18} />}
              role="danger"
              help={help.outstandingBalance}
              className="h-full"
            />
          ) : (
            <StatusCard
              label={
                <>
                  Outstanding balance
                  <InfoHint text={help.outstandingBalance} label="About outstanding balance" />
                </>
              }
              value={formatCents(ledger.outstanding_cents)}
              sub="No overdue rent"
              icon={<IconWallet size={18} />}
              role="success"
              footer={
                outstandingSeries.length >= 2 ? (
                  <MiniLineChart
                    data={outstandingSeries}
                    color="var(--color-success-600)"
                    height={34}
                  />
                ) : undefined
              }
            />
          )}
        </motion.div>

        {/* ── Occupancy — Donut footer driven by real occupied/total ── */}
        <motion.div variants={staggerItem}>
          <StatusCard
            label={
              <>
                Occupancy
                <InfoHint text={help.occupancy} label="About occupancy" />
              </>
            }
            value={`${portfolio.occupied_units} / ${portfolio.total_units}`}
            sub={
              portfolio.total_units > 0
                ? `${portfolio.vacant_units} unit${portfolio.vacant_units !== 1 ? 's' : ''} vacant`
                : 'No units added yet'
            }
            icon={<IconBuilding size={18} />}
            role={occupancyRole}
            footer={
              portfolio.total_units > 0 ? (
                <div className="flex items-center gap-3 pt-1">
                  <Donut
                    pct={occupancyPct}
                    size={52}
                    color={
                      occupancyRole === 'success'
                        ? 'var(--color-success-600)'
                        : occupancyRole === 'warning'
                          ? 'var(--color-warning-600)'
                          : 'var(--color-danger-600)'
                    }
                  />
                  <Link to="/app/properties" className="ld-link">
                    Details <IconArrowRight size={12} />
                  </Link>
                </div>
              ) : undefined
            }
          />
        </motion.div>

        {/* ── Active leases ── */}
        <motion.div variants={staggerItem}>
          <StatusCard
            label={
              <>
                Active leases
                <InfoHint text={help.activeLeases} label="About active leases" />
              </>
            }
            value={String(contracts.active)}
            sub={
              contracts.pending_tenant > 0
                ? `${contracts.pending_tenant} awaiting tenant signature`
                : 'Signed and running'
            }
            icon={<IconFileText size={18} />}
            role="neutral"
            footer={
              <Link to="/app/contracts" className="ld-link">
                View leases <IconArrowRight size={12} />
              </Link>
            }
          />
        </motion.div>
      </DataCardGrid>
    </motion.div>
  );
}

/* ════════════════════════════════════════════════════════════════════════════
   NEEDS YOUR ATTENTION — semantic StatusCards, one per action item.
   Roles driven by real count values via variant functions.
   ════════════════════════════════════════════════════════════════════════ */
function NeedsAttention({
  applications,
  maintenance,
  contracts,
}: {
  applications: LandlordDashboardData['applications'];
  maintenance: LandlordDashboardData['maintenance'];
  contracts: LandlordDashboardData['contracts'];
}) {
  /* Applications: warning if any awaiting review, neutral if clear. */
  const appsRole = getReviewQueueVariant(applications.awaiting_review);
  /* Maintenance: danger if any open (property problem), neutral if clear. */
  const maintRole = maintenance.open > 0 ? 'danger' as const : 'neutral' as const;
  /* Expiring leases: warning if any, neutral if clear. */
  const leasesRole = contracts.expiring_soon > 0 ? 'warning' as const : 'neutral' as const;

  return (
    <DashboardSection eyebrow="Action required" title="Needs your attention">
      <DataCardGrid cols={3}>
        <Link to="/app/applicants" className="block">
          <StatusCard
            label="Applications"
            value={String(applications.awaiting_review)}
            sub={
              applications.awaiting_review > 0
                ? 'Awaiting your review'
                : 'No pending applications'
            }
            icon={<IconUsers size={18} />}
            role={appsRole}
          />
        </Link>

        <Link to="/app/maintenance" className="block">
          <StatusCard
            label="Maintenance"
            value={String(maintenance.open)}
            sub={
              maintenance.open > 0
                ? `${maintenance.open} open${maintenance.in_progress > 0 ? `, ${maintenance.in_progress} in progress` : ''}`
                : 'No open requests'
            }
            icon={<IconWrench size={18} />}
            role={maintRole}
          />
        </Link>

        <Link to="/app/contracts" className="block">
          <StatusCard
            label={
              <>
                Leases expiring
                <InfoHint text={help.leasesExpiring} label="About leases expiring" />
              </>
            }
            value={String(contracts.expiring_soon)}
            sub={
              contracts.expiring_soon > 0
                ? 'Expiring soon. Review now.'
                : 'No leases expiring soon'
            }
            icon={<IconCalendar size={18} />}
            role={leasesRole}
          />
        </Link>
      </DataCardGrid>
    </DashboardSection>
  );
}

/* ════════════════════════════════════════════════════════════════════════════
   ACCOUNT SETUP — real onboarding donut + checklist.
   ════════════════════════════════════════════════════════════════════════ */
function AccountSetup({
  setup,
  loading,
  error,
}: {
  setup: LandlordOnboarding | null;
  loading: boolean;
  error: boolean;
}) {
  if (loading) {
    return (
      <NexusCard role="neutral" className="p-6 flex flex-col gap-4">
        <div className="flex items-center justify-between">
          <Skeleton className="h-5 w-32" />
          <Skeleton className="h-5 w-20 rounded-full" />
        </div>
        <div className="flex items-center gap-6">
          <Skeleton className="h-[104px] w-[104px] rounded-full shrink-0" />
          <div className="flex flex-col gap-3 flex-1">
            <Skeleton className="h-4 w-full" />
            <Skeleton className="h-4 w-4/5" />
            <Skeleton className="h-4 w-3/5" />
          </div>
        </div>
      </NexusCard>
    );
  }

  if (error || !setup) {
    return (
      <NexusCard role="neutral" className="p-6 flex flex-col gap-4">
        <h3 className="font-display text-xl font-semibold text-ink-950">Account setup</h3>
        <p className="text-sm text-ink-500">Setup status is unavailable right now.</p>
        <Link to="/app/profile" className="ld-btn-primary ld-btn-block">
          Complete profile <IconArrowUpRight size={16} />
        </Link>
      </NexusCard>
    );
  }

  const pct = Math.round(setup.completion_percentage);
  const firstIncomplete = setup.steps.find((s) => !s.completed);
  const ctaTo = firstIncomplete?.action ?? '/app/profile';
  const setupRole = pct === 100 ? 'success' : pct >= 50 ? 'warning' : 'danger';

  return (
    <NexusCard role="neutral" className="p-6 flex flex-col gap-5">
      <div className="flex items-center justify-between gap-3">
        <h3 className="font-display text-xl font-semibold text-ink-950">Account setup</h3>
        <SemanticBadge role={setupRole}>
          {pct}% ready
        </SemanticBadge>
      </div>

      <div className="flex items-center gap-6 flex-wrap">
        <div className="shrink-0">
          <Donut
            pct={pct}
            size={104}
            color={
              setupRole === 'success'
                ? 'var(--color-success-600)'
                : setupRole === 'warning'
                  ? 'var(--color-warning-600)'
                  : 'var(--color-danger-600)'
            }
            label="Ready"
          />
        </div>
        <ul className="ld-setup-list flex-1 min-w-[180px]">
          {setup.steps.map((step) => (
            <li key={step.key} className={step.completed ? 'is-done' : ''}>
              <span className="ld-setup-mark">
                {step.completed ? <IconCheck size={11} /> : null}
              </span>
              <span className="ld-setup-txt">{step.title}</span>
            </li>
          ))}
        </ul>
      </div>

      {pct < 100 && (
        <Link to={ctaTo} className="ld-btn-primary ld-btn-block">
          Complete profile <IconArrowUpRight size={16} />
        </Link>
      )}
    </NexusCard>
  );
}

/* ════════════════════════════════════════════════════════════════════════════
   RECENT ACTIVITY — merged feed of real applications + maintenance.
   ════════════════════════════════════════════════════════════════════════ */
type FeedItem = {
  id: string;
  kind: 'application' | 'maintenance';
  title: string;
  context: string | null;
  preview: string | null;
  when: string | null;
};

function buildFeed(
  applications: Application[],
  maintenance: MaintenanceRequest[],
): FeedItem[] {
  const fromApps: FeedItem[] = applications.map((a) => ({
    id: `app-${a.id}`,
    kind: 'application',
    title: a.tenant?.full_name || 'New applicant',
    context: a.listing?.title ?? null,
    preview: a.cover_note,
    when: a.submitted_at ?? a.created_at ?? null,
  }));

  const fromMaint: FeedItem[] = maintenance.map((m) => {
    const where = [m.property?.name, m.unit ? `Unit ${m.unit.unit_number}` : null]
      .filter(Boolean)
      .join(' · ');
    return {
      id: `maint-${m.id}`,
      kind: 'maintenance',
      title: `Maintenance: ${m.title}`,
      context: where || null,
      preview: m.description,
      when: m.submitted_at ?? m.created_at ?? null,
    };
  });

  return [...fromApps, ...fromMaint]
    .sort((a, b) => {
      const ta = a.when ? new Date(a.when).getTime() : 0;
      const tb = b.when ? new Date(b.when).getTime() : 0;
      return tb - ta;
    })
    .slice(0, 3);
}

function RecentActivity({ feed }: { feed: FeedItem[] }) {
  return (
    <NexusCard role="neutral" className="p-6 flex flex-col gap-5">
      <div className="flex items-center justify-between gap-3">
        <h3 className="font-display text-xl font-semibold text-ink-950">Recent activity</h3>
        <Link to="/app/notifications" className="ld-link">
          View all <IconArrowRight size={13} />
        </Link>
      </div>

      {feed.length === 0 ? (
        <EmptyState
          icon={<IconUsers size={22} />}
          title="No recent activity yet"
          description="Applications and maintenance requests will appear here as they come in."
        />
      ) : (
        <ul className="ld-feed">
          {feed.map((item) => (
            <li key={item.id} className="ld-feed-row">
              <span
                className={`ld-feed-av ${item.kind === 'maintenance' ? 'is-maint' : ''}`}
                aria-hidden="true"
              >
                {item.kind === 'maintenance' ? <IconWrench size={15} /> : initialsOf(item.title)}
              </span>
              <span className="ld-feed-main">
                <span className="ld-feed-top">
                  <span className="ld-feed-title">{item.title}</span>
                  <span className="ld-feed-time">{whenLabel(item.when)}</span>
                </span>
                {item.context && <span className="ld-feed-ctx">{item.context}</span>}
                {item.preview && <span className="ld-feed-prev">{item.preview}</span>}
              </span>
            </li>
          ))}
        </ul>
      )}
    </NexusCard>
  );
}

/* ════════════════════════════════════════════════════════════════════════════
   YOUR LISTINGS — real portfolio gallery (up to 6).
   ════════════════════════════════════════════════════════════════════════ */
function YourListings({ listings }: { listings: Listing[] }) {
  const display = listings.slice(0, 6);

  return (
    <DashboardSection
      eyebrow="Portfolio"
      title="Your listings"
      action={
        <Link to="/app/listings" className="ld-link">
          View all <IconArrowRight size={13} />
        </Link>
      }
    >
      {display.length === 0 ? (
        <EmptyState
          icon={<IconHome size={22} />}
          title="No listings yet"
          description="Publish a unit to start attracting tenants."
          action={
            <Link to="/app/listings">
              <Button size="sm" leftIcon={<IconPlus size={15} />}>
                Create a listing
              </Button>
            </Link>
          }
        />
      ) : (
        <motion.div
          className="ld-listings"
          variants={staggerContainer(0.06)}
          initial="hidden"
          whileInView="show"
          viewport={{ once: true, margin: '0px 0px -12% 0px' }}
        >
          {display.map((l) => {
            const unit = l.unit;
            const imgSrc = l.primary_photo?.path
              ? `${import.meta.env.VITE_API_URL ?? ''}/storage/${l.primary_photo.path}`
              : null;
            return (
              <motion.div key={l.id} variants={staggerItem}>
                <Link to="/app/listings" className="ld-lcard u-card-hover">
                  <span className="ld-lcard-thumb">
                    {imgSrc ? (
                      <img src={imgSrc} alt={l.title} loading="lazy" />
                    ) : (
                      <span className="ld-lcard-ph" aria-hidden="true">
                        <IconBuilding size={22} />
                      </span>
                    )}
                    <Badge tone={listingStatusTone(l.status)} className="ld-lcard-badge">
                      {humanize(l.status)}
                    </Badge>
                  </span>
                  <span className="ld-lcard-body">
                    <span className="ld-lcard-title">{l.title}</span>
                    {unit?.property?.city && (
                      <span className="ld-lcard-loc">{unit.property.city}</span>
                    )}
                    <span className="ld-lcard-rent num-old">
                      {unit?.rent_amount ? (
                        <>
                          {formatCedisDecimal(unit.rent_amount)}
                          <small> /mo</small>
                        </>
                      ) : (
                        '—'
                      )}
                    </span>
                  </span>
                </Link>
              </motion.div>
            );
          })}
        </motion.div>
      )}
    </DashboardSection>
  );
}

/* ════════════════════════════════════════════════════════════════════════════
   PORTFOLIO SNAPSHOT — 3 real figures in semantic StatusCards.
   ════════════════════════════════════════════════════════════════════════ */
function PortfolioSnapshot({
  portfolio,
  contracts,
}: {
  portfolio: LandlordDashboardData['portfolio'];
  contracts: LandlordDashboardData['contracts'];
}) {
  return (
    <DashboardSection eyebrow="Overview" title="Portfolio snapshot">
      <DataCardGrid cols={3}>
        <StatusCard
          label="Total properties"
          value={String(portfolio.total_properties)}
          sub={`${portfolio.total_units} unit${portfolio.total_units !== 1 ? 's' : ''} across all properties`}
          icon={<IconBuilding size={18} />}
          role="neutral"
        />

        <StatusCard
          label="Occupied units"
          value={String(portfolio.occupied_units)}
          sub={
            portfolio.vacant_units > 0
              ? `${portfolio.vacant_units} vacant`
              : 'All units occupied'
          }
          icon={<IconKey size={18} />}
          role={getOccupancyVariant(
            portfolio.total_units > 0
              ? Math.round((portfolio.occupied_units / portfolio.total_units) * 100)
              : 0,
          )}
        />

        <StatusCard
          label="Leases ending soon"
          value={String(contracts.expiring_soon)}
          sub={
            contracts.expiring_soon > 0
              ? 'Renewal or re-listing needed'
              : 'No leases expiring soon'
          }
          icon={<IconCalendar size={18} />}
          role={contracts.expiring_soon > 0 ? 'warning' : 'neutral'}
          footer={
            <Link to="/app/contracts" className="ld-link">
              View contracts <IconArrowRight size={12} />
            </Link>
          }
        />
      </DataCardGrid>
    </DashboardSection>
  );
}

/* ════════════════════════════════════════════════════════════════════════════
   LISTINGS STATUS SUMMARY — shows listing funnel (draft → pending → active).
   Only renders if the landlord has any listings.
   ════════════════════════════════════════════════════════════════════════ */
function ListingsFunnel({ portfolio }: { portfolio: LandlordDashboardData['portfolio'] }) {
  const total =
    portfolio.active_listings +
    portfolio.pending_review_listings +
    portfolio.draft_listings;

  if (total === 0) return null;

  return (
    <DashboardSection
      eyebrow="Listings"
      title="Listing pipeline"
      action={
        <Link to="/app/listings" className="ld-link">
          Manage <IconArrowRight size={13} />
        </Link>
      }
    >
      <DataCardGrid cols={3}>
        <StatusCard
          label="Active listings"
          value={String(portfolio.active_listings)}
          sub={portfolio.active_listings > 0 ? 'Live and attracting tenants' : 'No active listings'}
          icon={<IconHome size={18} />}
          role={portfolio.active_listings > 0 ? 'success' : 'neutral'}
        />

        <StatusCard
          label="Pending review"
          value={String(portfolio.pending_review_listings)}
          sub={
            portfolio.pending_review_listings > 0
              ? 'Awaiting admin approval'
              : 'Nothing in queue'
          }
          icon={<IconBarChart size={18} />}
          role={portfolio.pending_review_listings > 0 ? 'warning' : 'neutral'}
        />

        <StatusCard
          label={
            <>
              Draft listings
              <InfoHint text={help.listingDraft} label="About draft listings" />
            </>
          }
          value={String(portfolio.draft_listings)}
          sub={
            portfolio.draft_listings > 0
              ? 'Not yet submitted for review'
              : 'No drafts in progress'
          }
          icon={<IconFileText size={18} />}
          role="neutral"
        />
      </DataCardGrid>
    </DashboardSection>
  );
}

/* ════════════════════════════════════════════════════════════════════════════
   MAIN
   ════════════════════════════════════════════════════════════════════════ */
export function LandlordDashboard() {
  const { user } = useAuth();
  const firstName = user && 'first_name' in user ? user.first_name : '';

  const dash = useApi(() => landlordApi.dashboard(), []);
  const setup = useApi(() => landlordApi.onboarding(), []);

  const feed = useMemo(
    () =>
      dash.data
        ? buildFeed(dash.data.recent_applications, dash.data.recent_maintenance)
        : [],
    [dash.data],
  );

  if (dash.loading) return <LoadingState label="Loading your portfolio…" />;

  if (dash.error?.status === 403) {
    return (
      <ForbiddenState
        title="Dashboard unavailable"
        message="You don't have access to this area."
      />
    );
  }

  if (dash.error || !dash.data) {
    return (
      <ErrorState
        title="Could not load your dashboard"
        message="Your data is safe. Please try again."
        onRetry={dash.reload}
      />
    );
  }

  const d = dash.data;

  return (
    <motion.div
      className="ld-root"
      variants={fadeRise}
      initial="hidden"
      animate="show"
    >
      <UtilityBar />

      <DashHero firstName={firstName} data={d} />

      {/* Financial metrics — the heart of the landlord dashboard. */}
      <DashboardSection eyebrow="Financials" title="At a glance">
        <FinancialMetrics
          ledger={d.ledger}
          portfolio={d.portfolio}
          contracts={d.contracts}
          rentTrend={d.rent_trend}
        />
      </DashboardSection>

      {/* Items that require a decision today. */}
      <NeedsAttention
        applications={d.applications}
        maintenance={d.maintenance}
        contracts={d.contracts}
      />

      {/* At-a-glance: setup readiness + recent feed. */}
      <DashboardSection eyebrow="Your account" title="At a glance">
        <div className="ld-glance">
          <AccountSetup setup={setup.data} loading={setup.loading} error={!!setup.error} />
          <RecentActivity feed={feed} />
        </div>
      </DashboardSection>

      {/* Recent listings from the portfolio. */}
      <YourListings listings={d.recent_listings} />

      {/* Listing funnel — only visible when there are listings. */}
      <ListingsFunnel portfolio={d.portfolio} />

      {/* Broader portfolio metrics. */}
      <PortfolioSnapshot portfolio={d.portfolio} contracts={d.contracts} />
    </motion.div>
  );
}
