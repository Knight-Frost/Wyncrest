import { useEffect, useState } from 'react';
import { Link, useLocation } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { adminApi } from '@/lib/endpoints';
import { normalizeError } from '@/lib/api';
import { formatDate, humanize, formatCents } from '@/lib/format';
import { useAuth } from '@/context/auth';
import { adminHasCapability } from '@/lib/permissions';
import { useToast } from '@/components/ui/toast';
import { DestructiveConfirmDialog } from '@/components/ui/DestructiveConfirmDialog';
import { Avatar } from '@/components/ui/Avatar';
import { Spinner } from '@/components/ui/Spinner';
import { ForbiddenState, ErrorState } from '@/components/ui/states';
import { help } from '@/lib/helpText';
import { InfoHint } from '@/components/ui/InfoHint';
import {
  IconSearch,
  IconShield,
  IconMail,
  IconPhone,
  IconCheck,
  IconChevronRight,
  IconChevronLeft,
  IconLock,
  IconUnlock,
  IconInbox,
  IconAlertTriangle,
  IconExternalLink,
} from '@/components/ui/icons';
import type {
  AdminUserSummary,
  AdminUserDetail,
  ApiError,
  Contract,
  Application,
} from '@/lib/types';
import './users-directory.css';

/* ---- Filter / sort option types ----------------------------------------- */
type TypeFilter = 'all' | 'tenant' | 'landlord';
type StatusFilter = 'all' | 'active' | 'suspended' | 'blocked' | 'archived' | 'unverified';
type SortOpt = 'review' | 'joined' | 'name';
type ModAction = 'suspend' | 'block' | 'archive';

/* ---- Truthful status derivation ----------------------------------------- */
/** Account status displayed as a pill. Archived rows are soft-deleted and only
 *  appear under the Archived filter. Verification is a *separate* axis. */
type DerivedStatus = 'active' | 'suspended' | 'blocked' | 'archived';
function derivedStatus(u: AdminUserSummary): DerivedStatus {
  if (u.account_status === 'archived') return 'archived';
  if (u.account_status === 'blocked') return 'blocked';
  if (u.suspended_at) return 'suspended';
  return 'active';
}

/** The pill shown in the list. An *active but unverified* account reads as the
 *  amber "Unverified" signal (the platform's real "needs review" state); every
 *  other account shows its account status. */
function pillFor(u: AdminUserSummary): { cls: string; label: string } {
  const s = derivedStatus(u);
  if (s === 'active' && !u.identity_verified) return { cls: 'unverified', label: 'Unverified' };
  const label = s.charAt(0).toUpperCase() + s.slice(1);
  return { cls: s, label };
}

function initials(u: { first_name?: string; last_name?: string; email: string; initials?: string }): string {
  if (u.initials) return u.initials;
  const a = u.first_name?.[0] ?? '';
  const b = u.last_name?.[0] ?? '';
  return (a + b || u.email[0] || '?').toUpperCase();
}

/** Landlord teal / tenant petrol avatar tint, matching the mockup. */
function avatarTint(type: string): string {
  return type === 'landlord' ? 'var(--petrol)' : 'var(--petrol-2)';
}

/* ---- minipill tone for contract / application status -------------------- */
function statusTone(status: string): 'ok' | 'warn' | 'muted' | 'bad' {
  const s = status.toLowerCase();
  if (['active', 'approved', 'paid', 'accepted'].includes(s)) return 'ok';
  if (['pending', 'sent', 'draft', 'submitted', 'under_review'].includes(s)) return 'warn';
  if (['rejected', 'withdrawn', 'declined'].includes(s)) return 'bad';
  return 'muted';
}

/* ========================================================================== */
/* Dossier — lazily fetches full detail when a row is expanded                */
/* ========================================================================== */
function UserDossier({
  user,
  refreshSignal,
  acting,
  canManage,
  onModerate,
}: {
  user: AdminUserSummary;
  refreshSignal: number;
  acting: boolean;
  canManage: boolean;
  onModerate: (action: ModAction | 'reactivate', user: AdminUserSummary) => void;
}) {
  const { data, loading, error, reload } = useApi<AdminUserDetail>(
    () => adminApi.user(user.id),
    [user.id, refreshSignal],
  );

  if (loading) {
    return (
      <div className="dos-pad">
        <div className="dos-loading">
          <Spinner size={16} /> Loading dossier…
        </div>
      </div>
    );
  }
  if (error) {
    return (
      <div className="dos-pad">
        <ErrorState message={error.message} onRetry={reload} />
      </div>
    );
  }
  if (!data) return null;

  const isLandlord = user.user_type === 'landlord';
  const status = derivedStatus(user);
  const v = data.verification;

  /* identity document state */
  const identity = ((): { cls: string; label: string; help?: string } => {
    if (v.identity_verified) return { cls: 'ok', label: 'Verified', help: help.verifApproved };
    const req = v.latest_request;
    if (!req) return { cls: 'neutral', label: 'Not submitted' };
    if (req.status === 'pending' || req.status === 'under_review')
      return { cls: 'pending', label: 'Pending review', help: help.verifPending };
    if (req.status === 'needs_more_information') return { cls: 'pending', label: 'Needs info', help: help.verifNeedsInfo };
    if (req.status === 'rejected') return { cls: 'no', label: 'Rejected', help: help.verifRejected };
    return { cls: 'neutral', label: humanize(req.status) };
  })();

  return (
    <div className="dos-pad">
      {/* LEFT — identity, verification, moderation */}
      <div>
        <div className="dsec">
          <div className="dl">Identity &amp; verification</div>

          <div className="vrow">
            <div className="vi"><IconShield size={16} /></div>
            <div className="vt">Identity document<small>Government-issued ID</small></div>
            <span className={`vstate ${identity.cls}`}>{identity.label}</span>
            {identity.help && <InfoHint text={identity.help} label={`About ${identity.label}`} />}
          </div>

          <div className="vrow">
            <div className="vi"><IconMail size={16} /></div>
            <div className="vt">Email<small>{user.email}</small></div>
            <span className={`vstate ${v.email_verified ? 'ok' : 'neutral'}`}>
              {v.email_verified ? 'Verified' : 'Unconfirmed'}
            </span>
          </div>

          {/* Phone is contact-only: the system has no phone verification, so we
              never claim one. */}
          <div className="vrow">
            <div className="vi"><IconPhone size={16} /></div>
            <div className="vt">Phone<small>{user.phone ?? 'Not provided'}</small></div>
            <span className="vstate neutral">Contact only</span>
          </div>

          {v.latest_request && (
            <div style={{ marginTop: '0.9rem' }}>
              <Link className="mbtn glass" to={`/app/verifications/${v.latest_request.id}`}>
                <IconExternalLink size={14} /> Review verification
              </Link>
            </div>
          )}
        </div>

        <div className="dsec">
          <div className="dl">Moderation</div>
          {status === 'archived' ? (
            <p className="emptyrow">Archived account — restore is handled through support.</p>
          ) : (
            <div className="modbar">
              {canManage && status === 'active' && (
                <button
                  className="mbtn danger"
                  disabled={acting}
                  onClick={() => onModerate('suspend', user)}
                >
                  <IconLock size={14} /> Suspend
                </button>
              )}
              {canManage && (status === 'suspended' || status === 'blocked') && (
                <button
                  className="mbtn ok"
                  disabled={acting}
                  onClick={() => onModerate('reactivate', user)}
                >
                  <IconUnlock size={14} /> Reactivate
                </button>
              )}
              {canManage && status !== 'blocked' && (
                <button
                  className="mbtn danger"
                  disabled={acting}
                  onClick={() => onModerate('block', user)}
                >
                  <IconAlertTriangle size={14} /> Block
                </button>
              )}
              {canManage && (
                <button
                  className="mbtn danger"
                  disabled={acting}
                  onClick={() => onModerate('archive', user)}
                >
                  <IconInbox size={14} /> Archive
                </button>
              )}
              {!canManage && (
                <p className="emptyrow">
                  You can view this account, but you don't have permission to make changes.
                </p>
              )}
              <Link className="mbtn glass" to="/app/audit">
                <IconExternalLink size={14} /> Audit log
              </Link>
            </div>
          )}
        </div>
      </div>

      {/* RIGHT — activity + footprint */}
      <div>
        <div className="dsec">
          <div className="dl">Activity</div>
          <div className="mgrid">
            {isLandlord ? (
              <>
                <Metric label="Listings" value={String(data.stats.listings)} />
                <Metric label="Properties" value={String(data.stats.properties)} />
                <Metric label="Active leases" value={String(data.stats.active_contracts)} />
                <Metric
                  label="Host rating"
                  value={data.stats.rating != null ? String(data.stats.rating) : '—'}
                  sub={
                    data.stats.rating != null
                      ? `/ 5 · ${data.stats.review_count} ${data.stats.review_count === 1 ? 'review' : 'reviews'}`
                      : 'no reviews'
                  }
                />
              </>
            ) : (
              <>
                <Metric label="Active leases" value={String(data.stats.active_contracts)} />
                <Metric label="Applications" value={String(data.stats.applications)} />
                <Metric label="Identity" value={v.identity_verified ? 'Yes' : 'No'} />
                <Metric label="Email" value={v.email_verified ? 'Confirmed' : 'No'} />
              </>
            )}
          </div>
        </div>

        <Footprint
          title="Recent contracts"
          empty="No contracts on record."
          items={data.recent_contracts.map((c: Contract) => ({
            key: c.id,
            name: c.listing?.title ?? `Contract ${c.id.slice(0, 8)}`,
            sub: `${formatCents(c.rent_amount)}/mo · from ${formatDate(c.start_date)}`,
            pillLabel: humanize(c.status),
            pillTone: statusTone(c.status),
          }))}
        />

        <Footprint
          title="Recent applications"
          empty="No applications on record."
          items={data.recent_applications.map((a: Application) => ({
            key: String(a.id),
            name: a.listing?.title ?? `Application #${a.id}`,
            sub: `Applied ${formatDate(a.submitted_at ?? a.created_at)}`,
            pillLabel: humanize(a.status),
            pillTone: statusTone(a.status),
          }))}
        />
      </div>
    </div>
  );
}

function Metric({ label, value, sub }: { label: string; value: string; sub?: string }) {
  return (
    <div className="mcell">
      <div className="ml">{label}</div>
      <div className="mv">
        {value} {sub && <small>{sub}</small>}
      </div>
    </div>
  );
}

function Footprint({
  title,
  empty,
  items,
}: {
  title: string;
  empty: string;
  items: { key: string; name: string; sub: string; pillLabel: string; pillTone: string }[];
}) {
  return (
    <div className="dsec">
      <div className="dl">{title}</div>
      {items.length === 0 ? (
        <p className="emptyrow">{empty}</p>
      ) : (
        items.map((it) => (
          <div className="prop" key={it.key}>
            <div className="pn">
              {it.name}
              <small>{it.sub}</small>
            </div>
            <span className={`minipill ${it.pillTone}`}>{it.pillLabel}</span>
          </div>
        ))
      )}
    </div>
  );
}

/* ========================================================================== */
/* User row — header + expandable dossier                                     */
/* ========================================================================== */
function UserRow({
  user,
  open,
  onToggle,
  refreshSignal,
  acting,
  canManage,
  onModerate,
}: {
  user: AdminUserSummary;
  open: boolean;
  onToggle: () => void;
  refreshSignal: number;
  acting: boolean;
  canManage: boolean;
  onModerate: (action: ModAction | 'reactivate', user: AdminUserSummary) => void;
}) {
  const pill = pillFor(user);
  return (
    <div className={`uwrap${open ? ' open' : ''}`}>
      <button type="button" className="user" aria-expanded={open} onClick={onToggle}>
        <div className="u-ava" style={{ background: avatarTint(user.user_type) }}>
          {user.avatar_url ? (
            <Avatar name={user.full_name} src={user.avatar_url} fallback={initials(user)} size={44} />
          ) : (
            initials(user)
          )}
          {user.identity_verified && (
            <span className="vr" title="Identity verified">
              <IconCheck size={9} />
            </span>
          )}
        </div>
        <div className="u-id">
          <div className="u-name">{user.full_name}</div>
          <div className="u-mail">{user.email}</div>
        </div>
        <span className="typebadge" style={{ ['--tc' as string]: avatarTint(user.user_type) }}>
          {user.user_type}
        </span>
        <span className="u-city">{user.city ?? '—'}</span>
        <span className="u-metric">{metricNodes(user)}</span>
        <span className={`statuspill ${pill.cls}`}>
          <span className="sd" />
          {pill.label}
        </span>
        <span className="u-chev">
          <IconChevronRight size={16} />
        </span>
      </button>
      <div className="dossier">
        <div className="dos-in">
          {open && (
            <UserDossier
              user={user}
              refreshSignal={refreshSignal}
              acting={acting}
              canManage={canManage}
              onModerate={onModerate}
            />
          )}
        </div>
      </div>
    </div>
  );
}

/** Row metric with the leading counts bolded — built as JSX (no innerHTML). */
function metricNodes(u: AdminUserSummary) {
  if (u.user_type === 'landlord') {
    return (
      <>
        <b>{u.properties_count}</b> {u.properties_count === 1 ? 'property' : 'properties'} ·{' '}
        <b>{u.listings_count}</b> {u.listings_count === 1 ? 'listing' : 'listings'}
      </>
    );
  }
  return (
    <>
      <b>{u.applications_count}</b> {u.applications_count === 1 ? 'application' : 'applications'}
    </>
  );
}

/* ========================================================================== */
/* Segment tiles                                                              */
/* ========================================================================== */
function SegmentTiles({
  counts,
  active,
  onPick,
}: {
  counts: { all: number; landlords: number; tenants: number; unverified: number };
  active: 'all' | 'landlord' | 'tenant' | 'review' | null;
  onPick: (seg: 'all' | 'landlord' | 'tenant' | 'review') => void;
}) {
  const tiles = [
    { seg: 'all' as const, dot: 'var(--ink)', k: 'All users', v: counts.all, d: 'on the platform' },
    { seg: 'landlord' as const, dot: 'var(--petrol)', k: 'Landlords', v: counts.landlords, d: 'listing properties' },
    { seg: 'tenant' as const, dot: 'var(--petrol-2)', k: 'Tenants', v: counts.tenants, d: 'renting & applying' },
    { seg: 'review' as const, dot: 'var(--oxblood)', k: 'Needs review', v: counts.unverified, d: 'unverified identity', alert: true },
  ];
  return (
    <section className="stats">
      {tiles.map((t) => (
        <button
          key={t.seg}
          type="button"
          className={`stat glass${t.alert ? ' alert' : ''}${active === t.seg ? ' sel' : ''}`}
          onClick={() => onPick(t.seg)}
        >
          <div className="k">
            <i style={{ background: t.dot }} />
            {t.k}
          </div>
          <div className="v">{t.v}</div>
          <div className="d">{t.d}</div>
        </button>
      ))}
    </section>
  );
}

/* ========================================================================== */
/* Main page                                                                  */
/* ========================================================================== */
export function UsersPage() {
  const { toast } = useToast();
  const { user: viewer } = useAuth();
  const canManage = adminHasCapability(viewer, 'manage_users');
  // A caller (e.g. the Contracts case file's "View tenant"/"View landlord")
  // can seed an initial search via navigate(..., { state: { search } }) so
  // the link actually lands on that person instead of an unfiltered list.
  const location = useLocation();
  const seededSearch = (location.state as { search?: string } | null)?.search ?? '';

  const [typeFilter, setTypeFilter] = useState<TypeFilter>('all');
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');
  const [sort, setSort] = useState<SortOpt>('joined');
  const [searchInput, setSearchInput] = useState(seededSearch);
  const [search, setSearch] = useState(seededSearch);
  const [page, setPage] = useState(1);
  const [openId, setOpenId] = useState<number | null>(null);

  // Debounce the search box into the query that actually hits the backend.
  useEffect(() => {
    const t = setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 350);
    return () => clearTimeout(t);
  }, [searchInput]);

  const { data, loading, error, reload } = useApi(
    () =>
      adminApi.users({
        type: typeFilter === 'all' ? undefined : typeFilter,
        status: statusFilter === 'all' ? undefined : statusFilter,
        search: search || undefined,
        sort,
        page,
      }),
    [typeFilter, statusFilter, search, sort, page],
  );

  const [actingId, setActingId] = useState<number | null>(null);
  const [refreshSignal, setRefreshSignal] = useState(0);
  const [modTarget, setModTarget] = useState<{ action: ModAction; user: AdminUserSummary } | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const users = data?.data ?? [];
  const counts = data?.counts ?? { all: 0, landlords: 0, tenants: 0, unverified: 0 };
  const currentPage = data?.current_page ?? 1;
  const lastPage = data?.last_page ?? 1;
  const total = data?.total ?? 0;

  /* which segment tile reads as selected given current filters */
  const activeSeg: 'all' | 'landlord' | 'tenant' | 'review' | null =
    statusFilter === 'unverified' && typeFilter === 'all'
      ? 'review'
      : statusFilter === 'all' && typeFilter === 'landlord'
        ? 'landlord'
        : statusFilter === 'all' && typeFilter === 'tenant'
          ? 'tenant'
          : statusFilter === 'all' && typeFilter === 'all'
            ? 'all'
            : null;

  function pickSegment(seg: 'all' | 'landlord' | 'tenant' | 'review') {
    setPage(1);
    if (seg === 'review') {
      setTypeFilter('all');
      setStatusFilter('unverified');
    } else {
      setStatusFilter('all');
      setTypeFilter(seg === 'all' ? 'all' : seg);
    }
  }

  async function handleReactivate(user: AdminUserSummary) {
    setActingId(user.id);
    try {
      await adminApi.activateUser(user.id);
      toast(`${user.full_name} reinstated.`, 'success');
      reload();
      setRefreshSignal((s) => s + 1);
    } catch (err) {
      const e = normalizeError(err) as ApiError;
      toast(e.message || 'Could not reinstate this account.', e.status === 422 ? 'info' : 'error');
      if (e.status === 422) {
        reload();
        setRefreshSignal((s) => s + 1);
      }
    } finally {
      setActingId(null);
    }
  }

  function onModerate(action: ModAction | 'reactivate', user: AdminUserSummary) {
    if (action === 'reactivate') {
      handleReactivate(user);
    } else {
      setModTarget({ action, user });
    }
  }

  async function confirmModeration(reason?: string) {
    if (!modTarget || !reason) return;
    const { action, user } = modTarget;
    setSubmitting(true);
    setActingId(user.id);
    try {
      if (action === 'suspend') await adminApi.suspendUser(user.id, reason);
      else if (action === 'block') await adminApi.blockUser(user.id, reason);
      else await adminApi.archiveUser(user.id, reason);
      toast(`${user.full_name} ${action === 'archive' ? 'archived' : `${action}ed`}.`, 'success');
      setModTarget(null);
      if (action === 'archive') setOpenId(null); // soft-deleted; drops out of the list
      reload();
      setRefreshSignal((s) => s + 1);
    } catch (err) {
      const e = normalizeError(err) as ApiError;
      if (e.status === 422) {
        toast(e.message || 'This account is already in that state.', 'info');
        setModTarget(null);
        reload();
      } else {
        toast(e.message || `Could not ${action} this account.`, 'error');
      }
    } finally {
      setSubmitting(false);
      setActingId(null);
    }
  }

  // 403 — admin gate failed server-side (viewing the roster needs no
  // capability, so this only fires if the account isn't an admin at all).
  if (error?.status === 403) {
    return (
      <div className="wusers animate-rise">
        <ForbiddenState
          title="Users unavailable"
          message="Your account doesn't have access to this area."
        />
      </div>
    );
  }

  const modCopy: Record<ModAction, { title: string; confirm: string; placeholder: string }> = {
    suspend: {
      title: 'Suspend account',
      confirm: 'Suspend account',
      placeholder: 'e.g. Reported fraudulent listings; pending investigation.',
    },
    block: {
      title: 'Block account',
      confirm: 'Block account',
      placeholder: 'e.g. Confirmed fraud; block from the platform.',
    },
    archive: {
      title: 'Archive account',
      confirm: 'Archive account',
      placeholder: 'e.g. Account closed at user request; archive record.',
    },
  };

  return (
    <div className="wusers animate-rise" style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
      {/* Page head */}
      <section className="pagehead glass">
        <span className="ph-eyebrow">Community</span>
        <h1 className="ph-title">
          The <span className="it">people.</span>
        </h1>
        <p className="ph-sub">
          Every tenant and landlord on the platform in one place. Inspect a person's real history,
          confirm who they say they are, and act on anything that needs moderating.
        </p>
      </section>

      {/* Segment tiles */}
      <SegmentTiles counts={counts} active={activeSeg} onPick={pickSegment} />

      {/* Directory */}
      <section className="glass">
        <div className="panel-head">
          <div>
            <h2>Directory</h2>
            <div className="ph2-sub">
              {loading ? 'Loading…' : `${total} of ${counts.all} shown`}
            </div>
          </div>
        </div>

        <div className="toolbar">
          <label className="search">
            <IconSearch size={15} />
            <input
              type="search"
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              placeholder="Search name, email or city…"
            />
          </label>
          <div className="chips">
            {(['all', 'landlord', 'tenant'] as TypeFilter[]).map((t) => (
              <button
                key={t}
                type="button"
                className={`chip${typeFilter === t ? ' on' : ''}`}
                onClick={() => {
                  setTypeFilter(t);
                  setPage(1);
                }}
              >
                {t === 'all' ? 'Everyone' : t === 'landlord' ? 'Landlords' : 'Tenants'}
              </button>
            ))}
          </div>
          <select
            className="sel-input"
            aria-label="Status"
            value={statusFilter}
            onChange={(e) => {
              setStatusFilter(e.target.value as StatusFilter);
              setPage(1);
            }}
          >
            <option value="all">Any status</option>
            <option value="unverified">Needs review</option>
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
            <option value="blocked">Blocked</option>
            <option value="archived">Archived</option>
          </select>
          <select
            className="sel-input"
            aria-label="Sort"
            value={sort}
            onChange={(e) => setSort(e.target.value as SortOpt)}
          >
            <option value="joined">Recently joined</option>
            <option value="review">Needs review first</option>
            <option value="name">Name A–Z</option>
          </select>
        </div>

        <div className="users">
          {error ? (
            <ErrorState message={error.message} onRetry={reload} />
          ) : loading ? (
            <div className="dos-loading" style={{ padding: '2rem 0.8rem' }}>
              <Spinner size={16} /> Loading people…
            </div>
          ) : users.length === 0 ? (
            <p className="emptyrow" style={{ padding: '2.5rem 0.8rem', textAlign: 'center' }}>
              No one matches these filters. Try widening your search.
            </p>
          ) : (
            users.map((user) => (
              <UserRow
                key={user.id}
                user={user}
                open={openId === user.id}
                onToggle={() => setOpenId((id) => (id === user.id ? null : user.id))}
                refreshSignal={refreshSignal}
                acting={actingId === user.id}
                canManage={canManage}
                onModerate={onModerate}
              />
            ))
          )}
        </div>
      </section>

      {/* Pagination */}
      {!loading && !error && (
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 16 }}>
          <p style={{ fontSize: '0.78rem', color: 'var(--ink-3)' }}>
            {total} {total === 1 ? 'person' : 'people'} total
          </p>
          {lastPage > 1 && (
            <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
              <button
                className="mbtn glass"
                disabled={currentPage <= 1}
                onClick={() => setPage((p) => Math.max(1, p - 1))}
              >
                <IconChevronLeft size={14} /> Previous
              </button>
              <span style={{ fontSize: '0.84rem', color: 'var(--ink-3)' }}>
                Page {currentPage} of {lastPage}
              </span>
              <button
                className="mbtn glass"
                disabled={currentPage >= lastPage}
                onClick={() => setPage((p) => p + 1)}
              >
                Next <IconChevronRight size={14} />
              </button>
            </div>
          )}
        </div>
      )}

      {/* Moderation confirm (suspend / block / archive) */}
      <DestructiveConfirmDialog
        open={modTarget !== null}
        onClose={() => {
          if (!submitting) setModTarget(null);
        }}
        onConfirm={confirmModeration}
        title={modTarget ? modCopy[modTarget.action].title : ''}
        description={
          modTarget
            ? `${modTarget.user.full_name} will be affected immediately. This is written to the audit log.`
            : undefined
        }
        confirmLabel={modTarget ? modCopy[modTarget.action].confirm : 'Confirm'}
        loading={submitting}
        reasonField={{
          label: 'Reason',
          placeholder: modTarget ? modCopy[modTarget.action].placeholder : '',
          required: true,
        }}
      />
    </div>
  );
}
