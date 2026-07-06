/**
 * Notifications — Homecrest activity feed.
 *
 * Fetches real notifications from notificationApi.list() and renders them
 * grouped by recency (Today / Earlier). Mark-read and mark-all-read are wired
 * to the live API with optimistic UI updates. No mock data, no MOCK_MODE.
 *
 * Tab breakdown:
 *   All      — every notification
 *   Unread   — where read_at === null
 *   Payments — rent_generated | rent_due_soon | rent_overdue |
 *              payment_succeeded | payment_failed | late_fee_added
 *   Contracts — contract_signed | contract_terminated | contract_renewed
 *
 * NotificationType → visual category (for icon + badge colour):
 *   rent_*  / payment_*  / late_fee_added → "payments"
 *   contract_*                            → "lease"
 *   maintenance_*                         → "maintenance"
 *
 * Rows with a resolvable subject deep-link on click (see deepLinkFor) while
 * still marking themselves read.
 */
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router';
import { adminApi, notificationApi } from '@/lib/endpoints';
import { useAuth } from '@/context/auth';
import { useApi } from '@/hooks/useApi';
import { humanize } from '@/lib/format';
import { SemanticBadge } from '@/components/cards';
import type {
  AdminNotificationDeliveryChannel,
  AppNotification,
  NotificationType,
  Paginated,
} from '@/lib/types';
import {
  IconCheck,
  IconSettings,
  IconSearch,
  IconChevronRight,
  IconBell,
  IconCheckCircle,
  IconDoc,
  IconUsers,
  IconStar,
  IconShield,
  IconWrench,
} from '@/components/ui/icons';
import './notifications.css';

/* ── type → visual category (drives icon + CSS badge colour) ──────────────── */

type VisualCategory =
  | 'payments'
  | 'lease'
  | 'applications'
  | 'maintenance'
  | 'messages'
  | 'system';

/** Every NotificationType maps to a visual category. The Record type makes this
 *  exhaustive — adding a new NotificationType forces a mapping here, so the feed
 *  can never render an unknown type. A runtime `?? 'system'` fallback (below)
 *  guards against any drift between the API enum and this client. */
const TYPE_CATEGORY: Record<NotificationType, VisualCategory> = {
  rent_generated:         'payments',
  rent_due_soon:          'payments',
  rent_overdue:           'payments',
  payment_succeeded:      'payments',
  payment_failed:         'payments',
  late_fee_added:         'payments',
  contract_sent:          'lease',
  contract_signed:        'lease',
  contract_terminated:    'lease',
  contract_renewed:       'lease',
  message_received:       'messages',
  listing_approved:       'applications',
  listing_rejected:       'applications',
  listing_changes_requested: 'applications',
  application_submitted:  'applications',
  application_approved:   'applications',
  application_rejected:   'applications',
  application_needs_action: 'applications',
  application_updated:    'applications',
  maintenance_request_submitted:  'maintenance',
  maintenance_logged_by_landlord: 'maintenance',
  maintenance_status_updated:     'maintenance',
  review_submitted:       'messages',
  review_approved:        'messages',
  review_response:        'messages',
  verification_submitted: 'system',
  verification_approved:  'system',
  verification_rejected:  'system',
  verification_needs_info:'system',
  account_suspended:      'system',
  account_reactivated:    'system',
  account_blocked:        'system',
  account_archived:       'system',
  password_changed:       'system',
};

type CategoryIconComp = React.ComponentType<{ size?: number; className?: string }>;

const CATEGORY_ICON: Record<VisualCategory, CategoryIconComp> = {
  payments:     IconCheckCircle,
  lease:        IconDoc,
  applications: IconUsers,
  maintenance:  IconWrench,
  messages:     IconStar,
  system:       IconShield,
};

const CATEGORY_LABEL: Record<VisualCategory, string> = {
  payments:     'Payments',
  lease:        'Lease & Rent',
  applications: 'Applications & Listings',
  maintenance:  'Maintenance',
  messages:     'Reviews',
  system:       'Account & Verification',
};

/** Default category for any type missing from the map (defensive). */
const FALLBACK_CATEGORY: VisualCategory = 'system';

/* ── tabs ─────────────────────────────────────────────────────────────────── */

type TabKey = 'all' | 'unread' | 'payments' | 'contracts';

const TABS: { key: TabKey; label: string }[] = [
  { key: 'all',       label: 'All' },
  { key: 'unread',    label: 'Unread' },
  { key: 'payments',  label: 'Payments' },
  { key: 'contracts', label: 'Contracts' },
];

const PAYMENT_TYPES = new Set<NotificationType>([
  'rent_generated', 'rent_due_soon', 'rent_overdue',
  'payment_succeeded', 'payment_failed', 'late_fee_added',
]);

const CONTRACT_TYPES = new Set<NotificationType>([
  'contract_sent', 'contract_signed', 'contract_terminated', 'contract_renewed',
]);

/* ── per-type deep links ──────────────────────────────────────────────────────
 * Clicking a notification marks it read AND navigates to the page it is about,
 * when (a) the payload carries the id and (b) a route exists for the viewer's
 * role. Anything ambiguous returns null and the row stays a mark-read button.
 *   payments      → /app/payments (tenants settle rent there; no landlord page)
 *   contracts     → /app/contracts/:id (shared route)
 *   applications  → tenant /app/applications/:id · landlord /app/applicants/:id
 *   maintenance   → /app/maintenance/:id (tenant + landlord route)
 */
function deepLinkFor(n: AppNotification, role: string | undefined): string | null {
  const dataId = (key: string): string | null => {
    const v = n.data?.[key];
    return typeof v === 'string' || typeof v === 'number' ? String(v) : null;
  };
  switch (n.type) {
    case 'rent_generated':
    case 'rent_due_soon':
    case 'rent_overdue':
    case 'payment_succeeded':
    case 'payment_failed':
    case 'late_fee_added':
      return role === 'tenant' ? '/app/payments' : null;
    case 'contract_sent':
    case 'contract_signed':
    case 'contract_terminated':
    case 'contract_renewed': {
      const contractId = dataId('contract_id');
      return contractId ? `/app/contracts/${contractId}` : '/app/contracts';
    }
    case 'message_received':
      return role === 'tenant' || role === 'landlord' ? '/app/messages' : null;
    case 'application_submitted':
    case 'application_approved':
    case 'application_rejected':
    case 'application_needs_action':
    case 'application_updated': {
      const applicationId = dataId('application_id');
      if (!applicationId) return null;
      if (role === 'tenant') return `/app/applications/${applicationId}`;
      if (role === 'landlord') return `/app/applicants/${applicationId}`;
      return null;
    }
    case 'maintenance_request_submitted':
    case 'maintenance_logged_by_landlord':
    case 'maintenance_status_updated': {
      const requestId = dataId('maintenance_request_id');
      if (!requestId) return null;
      return role === 'tenant' || role === 'landlord' ? `/app/maintenance/${requestId}` : null;
    }
    default:
      return null;
  }
}

/* ── date helpers ─────────────────────────────────────────────────────────── */

type Bucket = 'today' | 'earlier';

function daysAgo(iso: string, now: Date): number {
  const d = new Date(iso);
  const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
  const startOfThat  = new Date(d.getFullYear(),   d.getMonth(),   d.getDate()).getTime();
  return Math.round((startOfToday - startOfThat) / (24 * 60 * 60 * 1000));
}

function bucketOf(iso: string, now: Date): Bucket {
  return daysAgo(iso, now) <= 1 ? 'today' : 'earlier';
}

function formatTime(iso: string, now: Date): string {
  const d    = new Date(iso);
  const diff = daysAgo(iso, now);
  const time = d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  if (diff <= 0) return time;
  if (diff === 1) return `Yesterday, ${time}`;
  return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

/* ============================================ admin platform delivery ======= */

type DeliveryFilter = 'all' | 'failed';

/** One channel's outcome as a semantic badge. `not_sent` is neutral — it means
 *  queued, channel-disabled, or digest-deferred, never a failure. */
function ChannelBadge({
  label,
  channel,
}: {
  label: string;
  channel: AdminNotificationDeliveryChannel;
}) {
  if (channel.status === 'delivered')
    return <SemanticBadge role="success">{label} delivered</SemanticBadge>;
  if (channel.status === 'failed')
    return <SemanticBadge role="danger">{label} failed</SemanticBadge>;
  return <SemanticBadge role="neutral">{label} not sent</SemanticBadge>;
}

/**
 * Admin-only monitor of platform-wide email/SMS delivery (GET
 * /admin/notifications/deliveries). Every figure comes from the API's `summary`
 * / `meta`; nothing is computed or invented client-side.
 */
function PlatformDeliveryMonitor() {
  const [filter, setFilter] = useState<DeliveryFilter>('all');
  const [page, setPage] = useState(1);

  const q = useApi(
    () =>
      adminApi.deliveries({
        status: filter === 'failed' ? 'failed' : undefined,
        page,
        per_page: 20,
      }),
    [filter, page],
  );

  const now = new Date();
  const rows = q.data?.data ?? [];
  const summary = q.data?.summary;
  const meta = q.data?.meta;

  function switchFilter(next: DeliveryFilter) {
    if (next === filter) return;
    setFilter(next);
    setPage(1);
  }

  return (
    <section className="nt-panel">
      {/* summary chips (real aggregates) */}
      <div className="nt-del-summary">
        <div className="nt-del-chip">
          <span className="nt-del-chip-lab">Total</span>
          <span className="nt-del-chip-val">{summary ? summary.total : '—'}</span>
        </div>
        <div className={`nt-del-chip${summary && summary.email.failed > 0 ? ' bad' : ''}`}>
          <span className="nt-del-chip-lab">Email failed</span>
          <span className="nt-del-chip-val">{summary ? summary.email.failed : '—'}</span>
        </div>
        <div className={`nt-del-chip${summary && summary.sms.failed > 0 ? ' bad' : ''}`}>
          <span className="nt-del-chip-lab">SMS failed</span>
          <span className="nt-del-chip-val">{summary ? summary.sms.failed : '—'}</span>
        </div>
        <div className={`nt-del-chip${summary && summary.failed_total > 0 ? ' bad' : ''}`}>
          <span className="nt-del-chip-lab">Failed total</span>
          <span className="nt-del-chip-val">{summary ? summary.failed_total : '—'}</span>
        </div>
      </div>

      {/* All / Failed toggle */}
      <div className="nt-toolbar">
        <div className="nt-tabs" role="tablist" aria-label="Delivery filters">
          {(['all', 'failed'] as DeliveryFilter[]).map((f) => (
            <button
              key={f}
              role="tab"
              aria-selected={filter === f}
              className={`nt-tab${filter === f ? ' active' : ''}`}
              onClick={() => switchFilter(f)}
            >
              {f === 'all' ? 'All' : 'Failed only'}
            </button>
          ))}
        </div>
      </div>

      {/* list / states */}
      {q.loading ? (
        Array.from({ length: 5 }).map((_, i) => (
          <div className="nt-skel-row" key={i} aria-hidden="true">
            <span />
            <span className="nt-skel circle" />
            <span className="nt-skel" style={{ width: '55%' }} />
            <span className="nt-skel" style={{ width: 64 }} />
          </div>
        ))
      ) : q.error ? (
        <div className="nt-empty">
          <span className="nt-empty-ico"><IconBell size={26} /></span>
          <p className="nt-empty-title">
            {q.error.status === 403 ? 'Access denied' : "We couldn't load delivery data"}
          </p>
          <p className="nt-empty-text">
            {q.error.status === 403
              ? 'This monitor is available to platform administrators only.'
              : (q.error.message ?? 'Something went wrong fetching platform deliveries.')}
          </p>
          {q.error.status !== 403 && (
            <button className="nt-btn nt-btn-ghost" onClick={() => q.reload()}>Try again</button>
          )}
        </div>
      ) : rows.length === 0 ? (
        <div className="nt-empty">
          <span className="nt-empty-ico"><IconBell size={26} /></span>
          <p className="nt-empty-title">
            {filter === 'failed' ? 'No failed deliveries' : 'No deliveries recorded yet'}
          </p>
          <p className="nt-empty-text">
            {filter === 'failed'
              ? 'Every notification that needed a channel was delivered.'
              : 'Email and SMS delivery outcomes will appear here.'}
          </p>
        </div>
      ) : (
        <div className="nt-del-list">
          {rows.map((d) => {
            const emailFailed = d.email.status === 'failed';
            const smsFailed = d.sms.status === 'failed';
            return (
              <div className="nt-del-row" key={d.id}>
                <div className="nt-del-main">
                  <div className="nt-del-recipient">
                    {d.recipient ? d.recipient.name : 'Unknown recipient'}
                    {d.recipient && <span className="nt-del-email">{d.recipient.email}</span>}
                  </div>
                  <div className="nt-del-type">{humanize(d.type)}</div>
                  {(emailFailed || smsFailed) && (
                    <div className="nt-del-error">
                      {emailFailed && d.email.error && <span>Email: {d.email.error}</span>}
                      {smsFailed && d.sms.error && <span>SMS: {d.sms.error}</span>}
                    </div>
                  )}
                </div>
                <div className="nt-del-channels">
                  <ChannelBadge label="Email" channel={d.email} />
                  <ChannelBadge label="SMS" channel={d.sms} />
                </div>
                <div className="nt-del-time">{formatTime(d.created_at, now)}</div>
              </div>
            );
          })}
        </div>
      )}

      {/* pagination */}
      {meta && meta.last_page > 1 && (
        <div className="nt-del-pager">
          <button
            className="nt-btn nt-btn-ghost"
            disabled={q.loading || meta.current_page <= 1}
            onClick={() => setPage((p) => Math.max(1, p - 1))}
          >
            Previous
          </button>
          <span className="nt-del-pager-info">
            Page {meta.current_page} of {meta.last_page} · {meta.total} total
          </span>
          <button
            className="nt-btn nt-btn-ghost"
            disabled={q.loading || meta.current_page >= meta.last_page}
            onClick={() => setPage((p) => p + 1)}
          >
            Next
          </button>
        </div>
      )}
    </section>
  );
}

/* ================================================================== page ==== */

export function Notifications() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const isAdmin = user?.role === 'admin';
  /** Admins can switch between their personal feed and the platform monitor. */
  const [view, setView] = useState<'personal' | 'platform'>('personal');

  const [pageData, setPageData] = useState<Paginated<AppNotification> | null>(null);
  const [loading,  setLoading]  = useState(true);
  const [error,    setError]    = useState<null | { status?: number; message?: string }>(null);
  const [tab,      setTab]      = useState<TabKey>('all');
  const [query,    setQuery]    = useState('');
  const [notice,   setNotice]   = useState<string | null>(null);
  const [busy,     setBusy]     = useState(false);

  /* ── load ─────────────────────────────────────────────────────────────── */

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await notificationApi.list();
      setPageData(result);
    } catch (err: unknown) {
      const e = err as { response?: { status?: number }; message?: string };
      setError({ status: e?.response?.status, message: e?.message });
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void load(); }, [load]);

  useEffect(() => {
    if (!notice) return;
    const id = setTimeout(() => setNotice(null), 3000);
    return () => clearTimeout(id);
  }, [notice]);

  /* ── derived lists ────────────────────────────────────────────────────── */

  const items = useMemo<AppNotification[]>(() => pageData?.data ?? [], [pageData]);

  const unreadCount = useMemo(() => items.filter((n) => n.read_at === null).length, [items]);

  const visible = useMemo(() => {
    const q = query.trim().toLowerCase();
    return items.filter((n) => {
      const tabOk = (() => {
        switch (tab) {
          case 'all':       return true;
          case 'unread':    return n.read_at === null;
          case 'payments':  return PAYMENT_TYPES.has(n.type);
          case 'contracts': return CONTRACT_TYPES.has(n.type);
        }
      })();
      const queryOk =
        q === '' ||
        n.title.toLowerCase().includes(q) ||
        n.message.toLowerCase().includes(q);
      return tabOk && queryOk;
    });
  }, [items, tab, query]);

  const groups = useMemo(() => {
    const now    = new Date();
    const sorted = [...visible].sort(
      (a, b) => +new Date(b.created_at) - +new Date(a.created_at),
    );
    return {
      today:   sorted.filter((n) => bucketOf(n.created_at, now) === 'today'),
      earlier: sorted.filter((n) => bucketOf(n.created_at, now) === 'earlier'),
    };
  }, [visible]);

  /* ── actions ──────────────────────────────────────────────────────────── */

  const onMarkRead = useCallback(async (n: AppNotification) => {
    if (n.read_at !== null) return;
    setBusy(true);
    try {
      await notificationApi.markRead(n.id);
      /* optimistic update — stamp read_at so the UI reflects immediately */
      setPageData((prev) => {
        if (!prev) return prev;
        return {
          ...prev,
          data: prev.data.map((item) =>
            item.id === n.id ? { ...item, read_at: new Date().toISOString() } : item,
          ),
        };
      });
    } catch {
      setNotice('Could not mark notification as read.');
    } finally {
      setBusy(false);
    }
  }, []);

  const onMarkAll = useCallback(async () => {
    if (unreadCount === 0 || busy) return;
    setBusy(true);
    try {
      await notificationApi.markAllRead();
      setPageData((prev) => {
        if (!prev) return prev;
        const stamp = new Date().toISOString();
        return {
          ...prev,
          data: prev.data.map((item) => ({ ...item, read_at: item.read_at ?? stamp })),
        };
      });
      setNotice('All notifications marked as read.');
    } catch {
      setNotice('Could not mark all as read.');
    } finally {
      setBusy(false);
    }
  }, [unreadCount, busy]);

  /* ── render helpers ───────────────────────────────────────────────────── */

  const now = new Date();

  function renderGroup(label: string, list: AppNotification[]) {
    if (list.length === 0) return null;
    return (
      <div key={label}>
        <div className="nt-group-label">{label}</div>
        {list.map((n) => {
          const cat  = TYPE_CATEGORY[n.type] ?? FALLBACK_CATEGORY;
          const Icon = CATEGORY_ICON[cat];
          const isUnread = n.read_at === null;
          const to = deepLinkFor(n, user?.role);
          return (
            <button
              type="button"
              className={`nt-row${isUnread ? ' unread' : ''}`}
              key={n.id}
              disabled={busy}
              onClick={() => {
                void onMarkRead(n);
                if (to) navigate(to);
              }}
            >
              <span className="nt-dot" aria-hidden="true" />
              <span className={`nt-ico ${cat}`}><Icon size={20} /></span>
              <span className="nt-body">
                <span className="nt-row-title">
                  {n.title}
                  {isUnread && <span className="nt-title-dot" aria-label="Unread" />}
                </span>
                <span className="nt-row-text">{n.message}</span>
                <span className={`nt-cat ${cat}`}>{CATEGORY_LABEL[cat]}</span>
              </span>
              <span className="nt-time">{formatTime(n.created_at, now)}</span>
              <IconChevronRight className="nt-chev" size={18} />
            </button>
          );
        })}
      </div>
    );
  }

  /* ── status states ────────────────────────────────────────────────────── */

  function renderFeed() {
    if (loading) {
      return Array.from({ length: 5 }).map((_, i) => (
        <div className="nt-skel-row" key={i} aria-hidden="true">
          <span />
          <span className="nt-skel circle" />
          <span className="nt-skel" style={{ width: '55%' }} />
          <span className="nt-skel" style={{ width: 64 }} />
        </div>
      ));
    }

    if (error) {
      if (error.status === 403) {
        return (
          <div className="nt-empty">
            <span className="nt-empty-ico"><IconBell size={26} /></span>
            <p className="nt-empty-title">Access denied</p>
            <p className="nt-empty-text">You don't have permission to view notifications.</p>
          </div>
        );
      }
      return (
        <div className="nt-empty">
          <span className="nt-empty-ico"><IconBell size={26} /></span>
          <p className="nt-empty-title">We couldn't load your notifications</p>
          <p className="nt-empty-text">Something went wrong fetching your updates.</p>
          <button className="nt-btn nt-btn-ghost" onClick={() => void load()}>Try again</button>
        </div>
      );
    }

    if (visible.length === 0) {
      const isFiltered = query.trim() !== '' || tab !== 'all';
      return (
        <div className="nt-empty">
          <span className="nt-empty-ico"><IconBell size={26} /></span>
          <p className="nt-empty-title">
            {query.trim()
              ? 'Nothing here'
              : tab === 'unread'
                ? 'All caught up'
                : isFiltered
                  ? 'Nothing here'
                  : "You're all caught up"}
          </p>
          <p className="nt-empty-text">
            {query.trim()
              ? 'No notifications match your search.'
              : tab === 'unread'
                ? 'You have no unread notifications.'
                : isFiltered
                  ? 'No notifications in this category yet.'
                  : 'New notifications will show up here.'}
          </p>
        </div>
      );
    }

    return (
      <>
        {renderGroup('Today',   groups.today)}
        {renderGroup('Earlier', groups.earlier)}
      </>
    );
  }

  /* ── page ─────────────────────────────────────────────────────────────── */

  return (
    <div className="nt-page">
      {/* header */}
      <header className="nt-head">
        <div className="nt-head-title">
          <p className="nt-eyebrow">{isAdmin ? 'Operations' : 'Account'}</p>
          <h1 className="nt-title">Notifications</h1>
          <p className="nt-sub">
            {isAdmin
              ? 'Your admin alerts, plus platform-wide email and SMS delivery health.'
              : 'Updates about your contracts, payments, and listings.'}
          </p>
        </div>
        <div className="nt-actions">
          {view === 'personal' && (
            <button
              className="nt-btn nt-btn-ghost"
              onClick={() => void onMarkAll()}
              disabled={unreadCount === 0 || busy}
            >
              <IconCheck size={16} /> Mark all as read
            </button>
          )}
          <Link className="nt-btn nt-btn-ghost" to="/app/settings">
            <IconSettings size={16} /> Notification settings
          </Link>
        </div>
      </header>

      {/* Admins get a second view: platform-wide email/SMS delivery. Non-admins
          never see this and their personal feed is unchanged. */}
      {isAdmin && (
        <div className="nt-viewswitch" role="tablist" aria-label="Notification view">
          <button
            role="tab"
            aria-selected={view === 'personal'}
            className={`nt-viewswitch-btn${view === 'personal' ? ' active' : ''}`}
            onClick={() => setView('personal')}
          >
            Your notifications
          </button>
          <button
            role="tab"
            aria-selected={view === 'platform'}
            className={`nt-viewswitch-btn${view === 'platform' ? ' active' : ''}`}
            onClick={() => setView('platform')}
          >
            Platform delivery
          </button>
        </div>
      )}

      {isAdmin && view === 'platform' ? (
        <PlatformDeliveryMonitor />
      ) : (
      <>
      <section className="nt-panel">
        {/* toolbar */}
        <div className="nt-toolbar">
          <div className="nt-tabs" role="tablist" aria-label="Notification filters">
            {TABS.map((t) => (
              <button
                key={t.key}
                role="tab"
                aria-selected={tab === t.key}
                className={`nt-tab${tab === t.key ? ' active' : ''}`}
                onClick={() => setTab(t.key)}
              >
                {t.label}
                {t.key === 'unread' && unreadCount > 0 && (
                  <span className="nt-tab-badge">{unreadCount}</span>
                )}
              </button>
            ))}
          </div>
          <div className="nt-tools">
            <div className="nt-search">
              <IconSearch size={16} />
              <input
                type="text"
                placeholder="Search notifications…"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                aria-label="Search notifications"
              />
            </div>
          </div>
        </div>

        {/* feed */}
        {renderFeed()}
      </section>

      <p className="nt-foot">Notifications are kept for 90 days.</p>
      </>
      )}

      {notice && <div role="alert" className="nt-toast">{notice}</div>}
    </div>
  );
}
