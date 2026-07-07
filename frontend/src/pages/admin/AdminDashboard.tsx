import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { useAuth } from '@/context/auth';
import { isSuperAdmin as userIsSuperAdmin, adminHasCapability } from '@/lib/permissions';
import { adminApi } from '@/lib/endpoints';
import { timeAgo } from '@/lib/format';
import { ErrorState, ForbiddenState } from '@/components/ui/states';
import { PageHeader } from '@/components/layout/PageHeader';
import type { AdminDashboard as AdminDashboardData } from '@/lib/types';
import { SuperDashboard } from './dashboard/SuperDashboard';
import heroArt1 from '@/assets/dashboard/home-7.jpg';
import heroArt2 from '@/assets/dashboard/home-2.jpg';
import heroArt3 from '@/assets/dashboard/home-6.jpg';
import heroArt4 from '@/assets/dashboard/home-3.jpg';
import './admin-dashboard.css';
import './dashboard/dashboard-sections.css';

/* Decorative hero photography — cross-faded over time (respects reduced-motion). */
const HERO_SLIDES = [heroArt1, heroArt2, heroArt3, heroArt4];

/* ============================================================================
   ADMIN DASHBOARD — hero is APPROVED and untouched (lines below). Everything
   below the hero is the Phase A command center: Attention Queue, Priority
   Cases, Platform Snapshot, Rent Risk Monitor, Review Queues, System Health,
   Recent Important Activity — all fed by one expanded adminApi.dashboard()
   call. Nothing here is fabricated: every number traces to a real person,
   property, or case, and sections a scoped admin can't act on are hidden
   rather than shown with a dead/403 button. See AdminOperationsDashboardService
   (backend) for what's real vs. deliberately labelled approximate/hidden.
   ============================================================================ */

/* ── small motion utilities ──────────────────────────────────────────────── */

function usePrefersReducedMotion() {
  const [reduced, setReduced] = useState(
    () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false,
  );
  useEffect(() => {
    const mq = window.matchMedia('(prefers-reduced-motion: reduce)');
    const handler = () => setReduced(mq.matches);
    mq.addEventListener?.('change', handler);
    return () => mq.removeEventListener?.('change', handler);
  }, []);
  return reduced;
}

/** Decorative hero photography that cross-fades between slides over time. */
function HeroPhotos() {
  const reduced = usePrefersReducedMotion();
  const [slide, setSlide] = useState(0);
  useEffect(() => {
    if (reduced) return;
    const id = setInterval(() => setSlide((p) => (p + 1) % HERO_SLIDES.length), 6000);
    return () => clearInterval(id);
  }, [reduced]);
  return (
    <div className="hc-photos" aria-hidden="true">
      {HERO_SLIDES.map((src, i) => (
        <img key={src} className={`hc-photo${i === slide ? ' on' : ''}`} src={src} alt="" />
      ))}
    </div>
  );
}

/* ── derivations from real data ──────────────────────────────────────────── */

function greetWord(hour: number) {
  return hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
}

/** Joins phrases into a readable list: "a", "a and b", "a, b and c". */
function joinPhrases(parts: string[]): string {
  if (parts.length <= 1) return parts.join('');
  if (parts.length === 2) return `${parts[0]} and ${parts[1]}`;
  return `${parts.slice(0, -1).join(', ')} and ${parts[parts.length - 1]}`;
}

/**
 * Live, never-invented hero status line. Sums the four real attention signals
 * (pending listings + pending verifications + overdue rent cases + failed
 * notifications) into one truthful one-liner. If nothing is outstanding it says so.
 */
function HeroStatus({ data }: { data: AdminDashboardData | null | undefined }) {
  if (!data) return <>Bringing the platform overview together…</>;
  const q = data.attention_queue;
  const pendingListings = q.listings.pending;
  const pendingVerifs = q.verification.pending;
  const overdueEntries = q.rent_risk.overdue_count;
  const failed = q.notifications.failed_total;
  const total = pendingListings + pendingVerifs + overdueEntries + failed;

  if (total === 0) {
    return (
      <>
        No urgent admin tasks — the review queue is clear, no verifications are waiting, nothing is
        overdue, and every notification has been delivered.
      </>
    );
  }

  const parts: string[] = [];
  if (pendingListings > 0)
    parts.push(`${pendingListings} ${pendingListings === 1 ? 'listing' : 'listings'} to review`);
  if (pendingVerifs > 0)
    parts.push(
      `${pendingVerifs} ${pendingVerifs === 1 ? 'verification' : 'verifications'} to decide`,
    );
  if (overdueEntries > 0)
    parts.push(`${overdueEntries} overdue rent ${overdueEntries === 1 ? 'case' : 'cases'}`);
  if (failed > 0)
    parts.push(`${failed} failed ${failed === 1 ? 'notification' : 'notifications'}`);

  return (
    <>
      <b>{total}</b> {total === 1 ? 'item needs' : 'items need'} your attention today:{' '}
      {joinPhrases(parts)}.
    </>
  );
}

/* ── icons (inline, matching the mockup's hairline SVG style) ────────────── */

const ArrowRight = () => (
  <svg className="a" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M5 12h14M13 6l6 6-6 6" />
  </svg>
);

/* ── page ────────────────────────────────────────────────────────────────── */

export function AdminDashboard() {
  const navigate = useNavigate();
  const { user } = useAuth();
  const adminName = user && user.role === 'admin' ? user.name : 'Administrator';
  const firstName = adminName.split(' ')[0];
  const isSuperAdmin = userIsSuperAdmin(user);
  const canListings = adminHasCapability(user, 'moderate_listings');

  const [range, setRange] = useState<'7d' | '30d' | '90d' | 'this_month' | 'ytd'>('30d');

  const dashboardReq = useApi(() => adminApi.dashboard(), []);
  // Platform analytics powers the cross-domain sections. A scoped admin without
  // the `view_analytics` capability gets a 403 here — we swallow it and pass
  // null so those sections gracefully hide (operational sections still render).
  const analyticsReq = useApi(() => adminApi.platformAnalytics({ range }), [range]);
  // Real maintenance rows for the maintenance table (read-only, any admin).
  const maintReq = useApi(() => adminApi.maintenanceQueue({ status: 'open', limit: 8 }), []);

  const [now] = useState(() => new Date());

  const analytics =
    analyticsReq.error?.status === 403 ? null : analyticsReq.data?.analytics ?? null;
  // A non-403 error (500/network) shouldn't silently hide every analytics
  // section the same way a lack-of-capability 403 does — surface it instead.
  const analyticsOutage = Boolean(analyticsReq.error) && analyticsReq.error?.status !== 403;
  const generatedLabel = analyticsReq.data?.analytics.generated_at
    ? timeAgo(analyticsReq.data.analytics.generated_at)
    : 'just now';

  const handleRefresh = () => {
    dashboardReq.reload();
    analyticsReq.reload();
    maintReq.reload();
  };

  // A 403 on the primary endpoint means this account isn't an admin.
  if (dashboardReq.error?.status === 403) {
    return (
      <div className="animate-rise">
        <PageHeader eyebrow="Platform" title="Admin console" />
        <ForbiddenState
          title="Admin access required"
          message="This area is restricted to platform administrators."
        />
      </div>
    );
  }
  if (dashboardReq.error) {
    return (
      <div className="animate-rise">
        <PageHeader eyebrow="Platform" title="Admin console" />
        <ErrorState message={dashboardReq.error.message} onRetry={dashboardReq.reload} />
      </div>
    );
  }

  const data = dashboardReq.data;

  const dateLabel = now.toLocaleDateString('en-GB', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  });

  return (
    <div className="wadm">
      <div className="wadm-bg" aria-hidden="true">
        <div className="blob b1" />
        <div className="blob b2" />
        <div className="blob b3" />
      </div>

      {/* GREETING HERO — approved, do not modify. */}
      <section className="hero glass reveal" style={{ '--i': 0 } as React.CSSProperties}>
        <div className="hc-left">
          <div>
            <div className="hc-date">
              <span className="pill">{dateLabel}</span>
              <span className="mono-l">{isSuperAdmin ? 'Super admin console' : 'Admin console'}</span>
            </div>
            <h1 className="hc-greet">
              {greetWord(now.getHours())}, <span className="it">{firstName}.</span>
            </h1>
            <p className="hc-status">
              <HeroStatus data={data} />
            </p>
          </div>
          <div className="hc-actions">
            {canListings && (
              <button className="btn btn-blood" type="button" onClick={() => navigate('/app/listing-review')}>
                Review listings <ArrowRight />
              </button>
            )}
          </div>
        </div>
        <div className="hc-art">
          <HeroPhotos />
        </div>
      </section>

      {analyticsOutage && (
        <ErrorState
          title="Platform analytics unavailable"
          message={analyticsReq.error?.message ?? 'Could not load platform analytics right now.'}
          onRetry={analyticsReq.reload}
        />
      )}

      {data && (
        <SuperDashboard
          dashboard={data}
          analytics={analytics}
          maintenanceRows={maintReq.data?.data ?? []}
          user={user}
          range={range}
          onRange={setRange}
          onRefresh={handleRefresh}
          refreshing={dashboardReq.loading || analyticsReq.loading}
          updatedLabel={generatedLabel}
        />
      )}
    </div>
  );
}
