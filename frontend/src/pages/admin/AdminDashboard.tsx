import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { useAuth } from '@/context/auth';
import { adminApi } from '@/lib/endpoints';
import {
  formatCents,
  formatCedisDecimal,
  storageUrl,
  timeAgo,
  humanize,
} from '@/lib/format';
import { ErrorState, ForbiddenState } from '@/components/ui/states';
import { useToast } from '@/components/ui/toast';
import { DestructiveConfirmDialog } from '@/components/ui/DestructiveConfirmDialog';
import { PageHeader } from '@/components/layout/PageHeader';
import type { AdminDashboard as AdminDashboardData, AuditLog, Listing } from '@/lib/types';
import heroArt1 from '@/assets/dashboard/home-7.jpg';
import heroArt2 from '@/assets/dashboard/home-2.jpg';
import heroArt3 from '@/assets/dashboard/home-6.jpg';
import heroArt4 from '@/assets/dashboard/home-3.jpg';
import './admin-dashboard.css';

/* Decorative hero photography — cross-faded over time (respects reduced-motion). */
const HERO_SLIDES = [heroArt1, heroArt2, heroArt3, heroArt4];

/* ============================================================================
   ADMIN DASHBOARD — "Wyncrest Admin Console" design (ported from the approved
   standalone mockup) wired to 100% real backend data. Three live endpoints:
     • adminApi.dashboard()        → platform aggregates (stats/contracts/ledger)
     • adminApi.pendingListings()  → the review queue
     • adminApi.auditLogs()        → recent activity timeline
   Nothing here is fabricated: panels that the mockup invented placeholder data
   for (weekly bars, verification ring, "by city", a "dispute" line) are mapped
   to real aggregates or relabeled to the truth (catalogue / collection /
   contracts). Styling lives in admin-dashboard.css, scoped under `.wadm`.
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

/** Animates a number toward `target` with an ease-out cubic (count-up).
   Eases from whatever is currently shown (so it never resets to 0 if the effect
   re-runs), only writes state inside async callbacks (rAF / timeout — never
   synchronously in the effect body), and a guaranteed final timeout snaps to the
   exact target even if rAF frames are throttled/stalled (e.g. a backgrounded
   tab). Reduced-motion short-circuits to the final value. */
function useCountUp(target: number, durationMs = 900) {
  const reduced = usePrefersReducedMotion();
  const [value, setValue] = useState(0);
  const valueRef = useRef(0);
  useEffect(() => {
    valueRef.current = value;
  }, [value]);

  useEffect(() => {
    if (reduced) return;
    const from = valueRef.current;
    if (from === target) return;
    let raf = 0;
    let start = 0;
    const step = (t: number) => {
      if (!start) start = t;
      const p = Math.min(1, (t - start) / durationMs);
      const eased = 1 - Math.pow(1 - p, 3);
      setValue(from + (target - from) * eased);
      if (p < 1) raf = requestAnimationFrame(step);
      else setValue(target);
    };
    raf = requestAnimationFrame(step);
    // Safety net: guarantee the exact target even if rAF never delivers a final frame.
    const settle = setTimeout(() => setValue(target), durationMs + 80);
    return () => {
      cancelAnimationFrame(raf);
      clearTimeout(settle);
    };
  }, [target, durationMs, reduced]);

  return reduced ? target : value;
}

function CountUp({ value }: { value: number }) {
  const v = useCountUp(value);
  return <>{Math.round(v).toLocaleString()}</>;
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

/** Compact age badge for queue rows, e.g. "2h", "1d", "3w" — always real. */
function compactAge(iso: string | null | undefined) {
  if (!iso) return '';
  const t = new Date(iso).getTime();
  if (Number.isNaN(t)) return '';
  const secs = Math.max(0, Math.round((Date.now() - t) / 1000));
  const mins = Math.round(secs / 60);
  if (mins < 60) return `${Math.max(1, mins)}m`;
  const hrs = Math.round(mins / 60);
  if (hrs < 24) return `${hrs}h`;
  const days = Math.round(hrs / 24);
  if (days < 7) return `${days}d`;
  const weeks = Math.round(days / 7);
  if (weeks < 5) return `${weeks}w`;
  return `${Math.round(days / 30)}mo`;
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
 * deliveries) into one truthful one-liner. If nothing is outstanding it says so.
 */
function HeroStatus({ data }: { data: AdminDashboardData | null | undefined }) {
  if (!data) return <>Bringing the platform overview together…</>;
  const pendingListings = data.statistics.pending_listings;
  const pendingVerifs = data.statistics.pending_verifications;
  const overdueEntries = data.ledger.overdue_entries;
  const failed = data.notifications.failed_deliveries;
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
    parts.push(`${failed} failed ${failed === 1 ? 'delivery' : 'deliveries'}`);

  return (
    <>
      <b>{total}</b> {total === 1 ? 'item needs' : 'items need'} your attention today:{' '}
      {joinPhrases(parts)}.
    </>
  );
}

/* Resolve a real thumbnail for a queue row, or null → lettered placeholder. */
function listingThumb(listing: Listing): string | null {
  if (listing.primary_photo?.path) return storageUrl(listing.primary_photo.path);
  if (listing.photos?.[0]?.path) return storageUrl(listing.photos[0].path);
  const media = listing.media_assets?.[0]?.url ?? listing.unit?.media_assets?.[0]?.url;
  return media ?? null;
}

/* ── icons (inline, matching the mockup's hairline SVG style) ────────────── */

const ArrowRight = () => (
  <svg className="a" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M5 12h14M13 6l6 6-6 6" />
  </svg>
);
const Check = () => (
  <svg viewBox="0 0 24 24">
    <path d="M20 6L9 17l-5-5" strokeLinecap="round" strokeLinejoin="round" />
  </svg>
);
const Cross = () => (
  <svg viewBox="0 0 24 24">
    <path d="M6 6l12 12M18 6L6 18" strokeLinecap="round" />
  </svg>
);

/* ── panels ──────────────────────────────────────────────────────────────── */

function ReviewQueue({
  listings,
  loading,
  busyId,
  onApprove,
  onReject,
  onViewAll,
}: {
  listings: Listing[];
  loading: boolean;
  busyId: number | null;
  onApprove: (l: Listing) => void;
  onReject: (l: Listing) => void;
  onViewAll: () => void;
}) {
  const rows = listings.slice(0, 5);
  return (
    <section className="panel glass reveal" style={{ '--i': 6 } as React.CSSProperties}>
      <div className="panel-head">
        <div>
          <h2>Awaiting review</h2>
          <div className="ph-sub">Submitted for verification</div>
        </div>
        <button className="link" type="button" onClick={onViewAll}>
          View all <span>&rarr;</span>
        </button>
      </div>
      <div className="queue">
        {loading ? (
          [0, 1, 2].map((i) => <div key={i} className="q-skel" />)
        ) : rows.length === 0 ? (
          <div className="q-empty">
            <span className="it">Queue clear.</span>
            Every submitted listing has been reviewed.
          </div>
        ) : (
          rows.map((l) => {
            const thumb = listingThumb(l);
            const city = l.unit?.property?.city ?? null;
            const landlord = l.landlord?.full_name ?? `Landlord #${l.landlord_id}`;
            const rent = l.unit?.rent_amount ? formatCedisDecimal(l.unit.rent_amount) : null;
            return (
              <div className="q-row" key={l.id}>
                <div className="q-thumb">
                  {thumb ? (
                    <img src={thumb} alt="" />
                  ) : (
                    <span className="q-thumb-ph">{l.title.charAt(0).toUpperCase()}</span>
                  )}
                </div>
                <div>
                  <div className="q-name">{l.title}</div>
                  <div className="q-line">
                    {city && (
                      <>
                        <span>{city}</span>
                        <span className="sep" />
                      </>
                    )}
                    <span>{landlord}</span>
                    {rent && (
                      <>
                        <span className="sep" />
                        <span className="q-rent">{rent}/mo</span>
                      </>
                    )}
                  </div>
                </div>
                <div className="q-act">
                  <span className="mono-l q-time">{compactAge(l.created_at)}</span>
                  <button
                    className="q-btn ok"
                    type="button"
                    aria-label={`Approve ${l.title}`}
                    disabled={busyId !== null}
                    onClick={() => onApprove(l)}
                  >
                    <Check />
                  </button>
                  <button
                    className="q-btn no"
                    type="button"
                    aria-label={`Reject ${l.title}`}
                    disabled={busyId !== null}
                    onClick={() => onReject(l)}
                  >
                    <Cross />
                  </button>
                </div>
              </div>
            );
          })
        )}
      </div>
    </section>
  );
}

function ActivityTimeline({
  data,
  loading,
  onViewAll,
}: {
  data: AuditLog[];
  loading: boolean;
  onViewAll: () => void;
}) {
  const items = data.slice(0, 5);
  return (
    <section className="panel glass reveal" style={{ '--i': 7 } as React.CSSProperties}>
      <div className="panel-head">
        <div>
          <h2>Recent activity</h2>
          <div className="ph-sub">Across the platform</div>
        </div>
        <button className="link" type="button" onClick={onViewAll}>
          Audit log <span>&rarr;</span>
        </button>
      </div>
      <div className="timeline">
        <div className="tl">
          {loading ? (
            <div className="tl-empty">Loading recent activity…</div>
          ) : items.length === 0 ? (
            <div className="tl-empty">No recorded activity yet.</div>
          ) : (
            items.map((log) => {
              const tone =
                log.severity === 'critical' ? 'blood' : log.severity === 'warning' ? 'warn' : '';
              return (
                <div className={`tl-item ${tone}`.trim()} key={log.id}>
                  <div className="t">{log.summary}</div>
                  <div className="tm">
                    {timeAgo(log.created_at)} · {humanize(log.area)}
                  </div>
                </div>
              );
            })
          )}
        </div>
      </div>
    </section>
  );
}

/* Catalogue breakdown — real listings_by_status as a bar chart. */
function CatalogueChart({ data }: { data: AdminDashboardData | null | undefined }) {
  const buckets = useMemo(() => {
    const s = data?.listings_by_status;
    return [
      { label: 'Active', n: s?.active ?? 0 },
      { label: 'Pending', n: s?.pending_review ?? 0 },
      { label: 'Draft', n: s?.draft ?? 0 },
      { label: 'Rejected', n: s?.rejected ?? 0 },
      { label: 'Inactive', n: s?.inactive ?? 0 },
      { label: 'Archived', n: s?.archived ?? 0 },
    ];
  }, [data]);
  const max = Math.max(1, ...buckets.map((b) => b.n));
  const total = buckets.reduce((a, b) => a + b.n, 0);
  const peakIdx = buckets.reduce((best, b, i) => (b.n > buckets[best].n ? i : best), 0);

  return (
    <section className="panel glass reveal" style={{ '--i': 8 } as React.CSSProperties}>
      <div className="panel-head">
        <div>
          <h2>Listings by status</h2>
          <div className="ph-sub">Catalogue breakdown</div>
        </div>
      </div>
      <div className="chart">
        <div className="bars">
          {buckets.map((b, i) => (
            <div className="bar-col" key={b.label}>
              <div className="bar-cap">{b.n}</div>
              <div
                className={`bar${i === peakIdx && b.n > 0 ? ' peak' : ''}`}
                style={{ '--h': `${(b.n / max) * 100}%`, '--bi': i } as React.CSSProperties}
              />
              <div className="bar-lbl">{b.label}</div>
            </div>
          ))}
        </div>
        <div className="chart-foot">
          <span>Total listings</span>
          <b>{total}</b>
        </div>
      </div>
    </section>
  );
}

/* Collection health ring — share of outstanding rent that is current (not yet
   overdue). Always well-defined since `overdue` ⊆ `outstanding`. Higher = healthier. */
function CollectionRing({ data }: { data: AdminDashboardData | null | undefined }) {
  const C = 2 * Math.PI * 54;
  const outstanding = data?.ledger.outstanding_cents ?? 0;
  const overdue = Math.max(0, data?.ledger.overdue_cents ?? 0);
  const current = Math.max(0, outstanding - overdue);
  // 100% healthy when nothing is outstanding at all.
  const pct = outstanding > 0 ? Math.min(1, current / outstanding) : 1;
  const pctInt = Math.round(pct * 100);
  const offset = C * (1 - pct);
  const stroke = pct >= 0.85 ? 'var(--green)' : pct >= 0.6 ? 'var(--amber)' : 'var(--oxblood)';

  return (
    <section className="panel glass reveal" style={{ '--i': 9 } as React.CSSProperties}>
      <div className="panel-head">
        <div>
          <h2>Collection health</h2>
          <div className="ph-sub">Outstanding rent — current vs overdue</div>
        </div>
      </div>
      <div className="ring-wrap">
        <div className="ring">
          <svg width="128" height="128" viewBox="0 0 128 128">
            <circle className="track" cx="64" cy="64" r="54" />
            <circle
              className="prog"
              cx="64"
              cy="64"
              r="54"
              style={
                {
                  stroke,
                  strokeDasharray: C,
                  strokeDashoffset: offset,
                  '--dash': `${C}px`,
                } as React.CSSProperties
              }
            />
          </svg>
          <div className="pct">{pctInt}%</div>
        </div>
        <div className="ring-legend">
          <div className="lg">
            <i style={{ background: stroke }} />
            Current<b>{formatCents(current)}</b>
          </div>
          <div className="lg">
            <i style={{ background: 'var(--oxblood)' }} />
            Overdue<b>{formatCents(overdue)}</b>
          </div>
          <div className="lg note">
            {outstanding > 0 ? (
              <>
                {formatCents(outstanding)} outstanding ·{' '}
                <b
                  style={{ color: overdue > 0 ? 'var(--oxblood)' : 'var(--green)', margin: 0 }}
                >
                  {pctInt}% on track
                </b>
              </>
            ) : (
              <>
                Nothing outstanding —{' '}
                <b style={{ color: 'var(--green)', margin: 0 }}>fully collected</b>
              </>
            )}
          </div>
        </div>
      </div>
    </section>
  );
}

/* Contracts by status — real contract aggregates as track bars. */
function ContractBreakdown({ data }: { data: AdminDashboardData | null | undefined }) {
  const rows = useMemo(() => {
    const c = data?.contracts;
    const list = [
      { label: 'Active', n: c?.active ?? 0 },
      { label: 'Awaiting tenant', n: c?.pending_tenant ?? 0 },
      { label: 'Draft', n: c?.draft ?? 0 },
      { label: 'Terminated', n: c?.terminated ?? 0 },
      { label: 'Expired', n: c?.expired ?? 0 },
    ];
    return list.sort((a, b) => b.n - a.n);
  }, [data]);
  const max = Math.max(1, ...rows.map((r) => r.n));
  const [filled, setFilled] = useState(false);
  useEffect(() => {
    const id = requestAnimationFrame(() => setFilled(true));
    return () => cancelAnimationFrame(id);
  }, []);

  return (
    <section className="panel glass reveal" style={{ '--i': 10 } as React.CSSProperties}>
      <div className="panel-head">
        <div>
          <h2>Contracts by status</h2>
          <div className="ph-sub">Across the platform</div>
        </div>
      </div>
      <div className="city">
        {rows.map((r, i) => (
          <div className={`city-row${i === 0 && r.n > 0 ? ' lead' : ''}`} key={r.label}>
            <div className="ct-top">
              <span>{r.label}</span>
              <b>{r.n}</b>
            </div>
            <div className="track-bar">
              <i style={{ width: filled ? `${(r.n / max) * 100}%` : 0 }} />
            </div>
          </div>
        ))}
      </div>
    </section>
  );
}

/* ── KPI tile ────────────────────────────────────────────────────────────── */

function Kpi({
  label,
  alert,
  value,
  delta,
}: {
  label: string;
  alert?: boolean;
  value: React.ReactNode;
  delta: React.ReactNode;
}) {
  return (
    <div className={`kpi glass reveal${alert ? ' alert' : ''}`}>
      <div className="k">
        <i />
        {label}
      </div>
      <div className="v">{value}</div>
      <div className="delta">{delta}</div>
    </div>
  );
}

/* ── operational-signal card (clickable KPI that deep-links to its queue) ──── */

function SignalCard({
  label,
  value,
  hint,
  alert,
  onClick,
}: {
  label: string;
  value: React.ReactNode;
  hint: React.ReactNode;
  alert?: boolean;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      className={`kpi glass reveal signal${alert ? ' alert' : ''}`}
      onClick={onClick}
    >
      <div className="k">
        <i />
        {label}
      </div>
      <div className="v">{value}</div>
      <div className="delta">
        {hint} <ArrowRight />
      </div>
    </button>
  );
}

/* ── page ────────────────────────────────────────────────────────────────── */

export function AdminDashboard() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const { user } = useAuth();
  const adminName = user && user.role === 'admin' ? user.name : 'Administrator';
  const firstName = adminName.split(' ')[0];
  const isSuperAdmin = !!(user && user.role === 'admin' && user.is_super_admin);

  const dashboardReq = useApi(() => adminApi.dashboard(), []);
  const pendingReq = useApi(() => adminApi.pendingListings(), []);
  const auditReq = useApi(() => adminApi.auditLogs({ page: 1 }), []);

  const [busyId, setBusyId] = useState<number | null>(null);
  const [rejecting, setRejecting] = useState<Listing | null>(null);
  const [submittingReject, setSubmittingReject] = useState(false);

  const nowRef = useRef(new Date());
  const now = nowRef.current;

  async function handleApprove(listing: Listing) {
    setBusyId(listing.id);
    try {
      await adminApi.approveListing(listing.id);
      toast(`Approved “${listing.title}”`, 'success');
      pendingReq.reload();
      dashboardReq.reload();
    } catch {
      toast('Could not approve listing. Please retry.', 'error');
    } finally {
      setBusyId(null);
    }
  }

  async function handleReject(reason?: string) {
    if (!rejecting || !reason) return;
    setSubmittingReject(true);
    try {
      await adminApi.rejectListing(rejecting.id, reason);
      toast(`Rejected “${rejecting.title}”`, 'success');
      setRejecting(null);
      pendingReq.reload();
      dashboardReq.reload();
    } catch {
      toast('Could not reject listing. Please retry.', 'error');
    } finally {
      setSubmittingReject(false);
    }
  }

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
  const stats = data?.statistics;

  // Oldest item still in the queue → real "oldest waiting" KPI sub.
  const oldestPending =
    pendingReq.data && pendingReq.data.length > 0
      ? pendingReq.data.reduce((oldest, l) =>
          new Date(l.created_at).getTime() < new Date(oldest.created_at).getTime() ? l : oldest,
        )
      : null;

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

      {/* GREETING HERO */}
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
            <button className="btn btn-blood" type="button" onClick={() => navigate('/app/moderation')}>
              Review listings <ArrowRight />
            </button>
          </div>
        </div>
        <div className="hc-art">
          <HeroPhotos />
        </div>
      </section>

      {/* KPIs */}
      <section className="kpis">
        <Kpi
          label="Active listings"
          value={<CountUp value={stats?.active_listings ?? 0} />}
          delta={
            <>
              {(stats?.total_listings ?? 0).toLocaleString()} total in catalogue
            </>
          }
        />
        <Kpi
          label="Pending review"
          alert={(stats?.pending_listings ?? 0) > 0}
          value={<CountUp value={stats?.pending_listings ?? 0} />}
          delta={
            oldestPending ? (
              <>
                oldest waiting <b className="down">{compactAge(oldestPending.created_at)}</b>
              </>
            ) : (
              'queue is clear'
            )
          }
        />
        <Kpi
          label="Active contracts"
          value={<CountUp value={stats?.active_contracts ?? 0} />}
          delta={
            <>{(data?.contracts.pending_tenant ?? 0).toLocaleString()} awaiting tenant signature</>
          }
        />
        <Kpi
          label="Outstanding rent"
          alert={(data?.ledger.overdue_cents ?? 0) > 0}
          value={data ? formatCedisDecimal(data.ledger.outstanding_cents / 100) : '—'}
          delta={
            data && data.ledger.overdue_cents > 0 ? (
              <>
                <b className="down">{formatCents(data.ledger.overdue_cents)}</b> overdue
              </>
            ) : (
              'none overdue'
            )
          }
        />
      </section>

      {/* OPERATIONAL SIGNALS — each deep-links to its filtered queue. Zero values
          still render (calm "clear" state), never hidden. */}
      <section className="signals">
        <SignalCard
          label="Pending verifications"
          alert={(stats?.pending_verifications ?? 0) > 0}
          value={<CountUp value={stats?.pending_verifications ?? 0} />}
          hint={
            (stats?.pending_verifications ?? 0) > 0 ? 'Review the queue' : 'None waiting'
          }
          onClick={() => navigate('/app/verifications')}
        />
        <SignalCard
          label="Overdue rent cases"
          alert={(data?.ledger.overdue_entries ?? 0) > 0}
          value={<CountUp value={data?.ledger.overdue_entries ?? 0} />}
          hint={
            data && data.ledger.overdue_entries > 0 ? (
              <>
                <b className="down">{formatCents(data.ledger.overdue_cents)}</b> overdue
              </>
            ) : (
              'Nothing overdue'
            )
          }
          onClick={() => navigate('/app/ledger')}
        />
        <SignalCard
          label="Failed deliveries"
          alert={(data?.notifications.failed_deliveries ?? 0) > 0}
          value={<CountUp value={data?.notifications.failed_deliveries ?? 0} />}
          hint={
            (data?.notifications.failed_deliveries ?? 0) > 0
              ? 'Inspect delivery log'
              : 'All delivered'
          }
          onClick={() => navigate('/app/notifications')}
        />
      </section>

      {/* REVIEW QUEUE + RECENT ACTIVITY */}
      <div className="grid-2">
        <ReviewQueue
          listings={pendingReq.data ?? []}
          loading={pendingReq.loading}
          busyId={busyId}
          onApprove={handleApprove}
          onReject={(l) => setRejecting(l)}
          onViewAll={() => navigate('/app/moderation')}
        />
        <ActivityTimeline
          data={auditReq.data?.data ?? []}
          loading={auditReq.loading}
          onViewAll={() => navigate('/app/audit')}
        />
      </div>

      {/* CATALOGUE CHART + COLLECTION RING */}
      <div className="grid-2">
        <CatalogueChart data={data} />
        <CollectionRing data={data} />
      </div>

      {/* CONTRACTS BY STATUS */}
      <ContractBreakdown data={data} />

      <DestructiveConfirmDialog
        open={rejecting !== null}
        onClose={() => {
          if (!submittingReject) setRejecting(null);
        }}
        onConfirm={handleReject}
        title="Reject listing"
        description={
          rejecting ? `Tell the landlord why “${rejecting.title}” was rejected.` : undefined
        }
        confirmLabel="Reject listing"
        loading={submittingReject}
        reasonField={{
          label: 'Reason for rejection',
          placeholder: 'Explain what needs to change…',
          required: true,
        }}
      />
    </div>
  );
}
