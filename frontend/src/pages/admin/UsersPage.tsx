import { useEffect, useState } from 'react';
import { useApi } from '@/hooks/useApi';
import { adminApi } from '@/lib/endpoints';
import { normalizeError } from '@/lib/api';
import { formatDate, humanize, formatCents } from '@/lib/format';
import { useToast } from '@/components/ui/toast';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { RecordList, RecordCard, RecordRelated } from '@/components/ui/RecordCard';
import { DetailDrawer } from '@/components/ui/Drawer';
import { DestructiveConfirmDialog } from '@/components/ui/DestructiveConfirmDialog';
import { Field, Input, Select } from '@/components/ui/Field';
import { Spinner } from '@/components/ui/Spinner';
import { Avatar } from '@/components/ui/Avatar';
import {
  LoadingState,
  ErrorState,
  EmptyState,
  ForbiddenState,
  Skeleton,
} from '@/components/ui/states';
import {
  IconUsers,
  IconEye,
  IconLock,
  IconUnlock,
  IconChevronLeft,
  IconChevronRight,
  IconSearch,
  IconShield,
  IconMail,
} from '@/components/ui/icons';
import {
  SemanticBadge,
  NexusCard,
  getApplicationVariant,
  getContractVariant,
} from '@/components/cards';
import { ActionMenu } from '@/components/landlord/primitives';
import type {
  AdminUserSummary,
  AdminUserDetail,
  ApiError,
  Contract,
  Application,
} from '@/lib/types';

/* ---- Filter option types ------------------------------------------------- */
type TypeFilter = 'all' | 'tenant' | 'landlord';
type StatusFilter = 'all' | 'active' | 'suspended';

const TYPE_OPTIONS: { value: TypeFilter; label: string }[] = [
  { value: 'all', label: 'All roles' },
  { value: 'tenant', label: 'Tenants' },
  { value: 'landlord', label: 'Landlords' },
];

const STATUS_OPTIONS: { value: StatusFilter; label: string }[] = [
  { value: 'all', label: 'All statuses' },
  { value: 'active', label: 'Active' },
  { value: 'suspended', label: 'Suspended' },
];

function isSuspended(user: AdminUserSummary): boolean {
  return user.suspended_at !== null;
}

function initials(user: { first_name?: string; last_name?: string; email: string }): string {
  const a = user.first_name?.[0] ?? '';
  const b = user.last_name?.[0] ?? '';
  return (a + b || user.email[0] || '?').toUpperCase();
}

/* ---- Avatar circle — photo when available, else info teal for landlords /
   neutral for tenants initials ------------------------------------------- */
function AvatarCircle({ user }: { user: AdminUserSummary }) {
  return (
    <Avatar
      name={user.full_name ?? user.email}
      src={user.avatar_url}
      fallback={initials(user)}
      className={[
        'flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full text-sm font-bold select-none',
        user.user_type === 'landlord' ? 'bg-info-50 text-info-600' : 'bg-ink-100 text-ink-600',
      ].join(' ')}
    />
  );
}

/* ---- Skeleton cards ------------------------------------------------------ */
function SkeletonCards() {
  return (
    <RecordList>
      {Array.from({ length: 6 }).map((_, i) => (
        <div
          key={i}
          className="flex items-center gap-4 rounded-2xl border border-ink-200 bg-surface px-4 py-4 shadow-sm sm:px-5"
        >
          <Skeleton className="h-10 w-10 shrink-0 rounded-full" />
          <div className="min-w-0 flex-1 space-y-2">
            <Skeleton className="h-4 w-40" />
            <Skeleton className="h-3 w-56" />
          </div>
          <Skeleton className="hidden h-5 w-20 rounded-full lg:block" />
          <Skeleton className="h-8 w-16" />
        </div>
      ))}
    </RecordList>
  );
}

/* ========================================================================== */
/* Detail drawer — fetches the full user record when opened                   */
/* ========================================================================== */
function UserDetailDrawer({
  userId,
  onClose,
}: {
  userId: number;
  onClose: () => void;
}) {
  const { data, loading, error, reload } = useApi<AdminUserDetail>(
    () => adminApi.user(userId),
    [userId],
  );

  const user = data?.user;
  const title = user ? user.full_name : 'User detail';

  return (
    <DetailDrawer
      open
      onClose={onClose}
      eyebrow="USER"
      title={title}
      description={user ? user.email : undefined}
    >
      {loading ? (
        <div className="py-10">
          <LoadingState label="Loading user…" />
        </div>
      ) : error ? (
        <ErrorState message={error.message} onRetry={reload} />
      ) : data && user ? (
        <div className="space-y-6">
          {/* Identity row */}
          <div className="flex items-center gap-3">
            <Avatar
              name={user.full_name}
              src={user.avatar_url}
              fallback={user.initials || initials(user)}
              className="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-full bg-info-50 text-sm font-bold text-info-600"
            />
            <div className="min-w-0">
              <p className="font-display text-lg font-semibold text-ink-900 leading-tight">
                {user.full_name}
              </p>
              <p className="text-sm text-ink-500 truncate">{user.email}</p>
            </div>
          </div>

          {/* Badges — semantic roles */}
          <div className="flex flex-wrap gap-2">
            <SemanticBadge role={user.user_type === 'landlord' ? 'info' : 'neutral'} dot={false}>
              {humanize(user.user_type)}
            </SemanticBadge>
            {user.identity_verified ? (
              <SemanticBadge role="success" dot={false}>Verified</SemanticBadge>
            ) : (
              <SemanticBadge role="warning" dot={false}>Unverified</SemanticBadge>
            )}
            {user.suspended_at ? (
              <SemanticBadge role="danger" dot={false}>Suspended</SemanticBadge>
            ) : (
              <SemanticBadge role="success" dot={false}>Active</SemanticBadge>
            )}
          </div>

          {/* Contact / meta */}
          <div className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
            <Meta label="Phone" value={user.phone ?? '—'} />
            <Meta label="City" value={user.city ?? '—'} />
            <Meta label="Joined" value={formatDate(user.created_at)} />
            {user.suspended_at && (
              <Meta label="Suspended" value={formatDate(user.suspended_at)} />
            )}
          </div>

          {/* Stats — real counts from backend */}
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <DetailStat label="Properties" value={data.stats.properties} />
            <DetailStat label="Listings" value={data.stats.listings} />
            <DetailStat label="Active leases" value={data.stats.active_contracts} />
            <DetailStat label="Applications" value={data.stats.applications} />
          </div>

          {/* Recent contracts */}
          <section>
            <h3 className="mb-2 eyebrow text-ink-500">Recent contracts</h3>
            {data.recent_contracts.length === 0 ? (
              <EmptyRow text="No contracts on record." />
            ) : (
              <ul className="divide-y divide-ink-200 rounded-xl border border-ink-200">
                {data.recent_contracts.map((c: Contract) => (
                  <li key={c.id} className="flex items-center justify-between gap-3 px-4 py-2.5">
                    <div className="min-w-0">
                      <p className="truncate text-sm font-medium text-ink-900">
                        {c.listing?.title ?? `Contract ${c.id.slice(0, 8)}`}
                      </p>
                      <p className="text-xs text-ink-500">
                        {formatCents(c.rent_amount)}/mo · from {formatDate(c.start_date)}
                      </p>
                    </div>
                    <SemanticBadge role={getContractVariant(c.status)} dot={false}>
                      {humanize(c.status)}
                    </SemanticBadge>
                  </li>
                ))}
              </ul>
            )}
          </section>

          {/* Recent applications */}
          <section>
            <h3 className="mb-2 eyebrow text-ink-500">Recent applications</h3>
            {data.recent_applications.length === 0 ? (
              <EmptyRow text="No applications on record." />
            ) : (
              <ul className="divide-y divide-ink-200 rounded-xl border border-ink-200">
                {data.recent_applications.map((a: Application) => (
                  <li key={a.id} className="flex items-center justify-between gap-3 px-4 py-2.5">
                    <div className="min-w-0">
                      <p className="truncate text-sm font-medium text-ink-900">
                        {a.listing?.title ?? `Application #${a.id}`}
                      </p>
                      <p className="text-xs text-ink-500">
                        Applied {formatDate(a.submitted_at ?? a.created_at)}
                      </p>
                    </div>
                    <SemanticBadge role={getApplicationVariant(a.status)} dot={false}>
                      {humanize(a.status)}
                    </SemanticBadge>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>
      ) : null}
    </DetailDrawer>
  );
}

function Meta({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="eyebrow text-ink-400">{label}</p>
      <p className="mt-0.5 text-sm text-ink-800">{value}</p>
    </div>
  );
}

/** Stat tile inside the user detail modal — Level-1 quiet NexusCard. */
function DetailStat({ label, value }: { label: string; value: number }) {
  return (
    <NexusCard role="neutral" className="px-4 py-3 text-center">
      <p className="font-display text-2xl font-semibold text-ink-900 num-old">{value}</p>
      <p className="mt-0.5 text-xs text-ink-500">{label}</p>
    </NexusCard>
  );
}

function EmptyRow({ text }: { text: string }) {
  return (
    <p className="rounded-xl border border-dashed border-ink-200 bg-ink-50/40 px-4 py-4 text-center text-sm text-ink-500">
      {text}
    </p>
  );
}


/* ========================================================================== */
/* Main page                                                                  */
/* ========================================================================== */
export function UsersPage() {
  const { toast } = useToast();

  const [typeFilter, setTypeFilter] = useState<TypeFilter>('all');
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);

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
        page,
      }),
    [typeFilter, statusFilter, search, page],
  );

  const [actingId, setActingId] = useState<number | null>(null);
  const [detailId, setDetailId] = useState<number | null>(null);
  const [suspendTarget, setSuspendTarget] = useState<AdminUserSummary | null>(null);
  const [suspending, setSuspending] = useState(false);

  const users = data?.data ?? [];
  const currentPage = data?.current_page ?? 1;
  const lastPage = data?.last_page ?? 1;
  const total = data?.total ?? 0;

  async function handleReinstate(user: AdminUserSummary) {
    setActingId(user.id);
    try {
      await adminApi.activateUser(user.id);
      toast(`${user.full_name} reinstated.`, 'success');
      reload();
    } catch (err) {
      const e = normalizeError(err) as ApiError;
      toast(
        e.status === 422
          ? e.message || 'This account is already active.'
          : e.message || 'Could not reinstate this account.',
        e.status === 422 ? 'info' : 'error',
      );
      if (e.status === 422) reload();
    } finally {
      setActingId(null);
    }
  }

  async function handleSuspend(reason?: string) {
    if (!suspendTarget || !reason) return;
    setSuspending(true);
    try {
      await adminApi.suspendUser(suspendTarget.id, reason);
      toast(`${suspendTarget.full_name} suspended.`, 'success');
      setSuspendTarget(null);
      reload();
    } catch (err) {
      const e = normalizeError(err) as ApiError;
      if (e.status === 422) {
        toast(e.message || 'This account is already suspended.', 'info');
        setSuspendTarget(null);
        reload();
      } else {
        toast(e.message || 'Could not suspend this account.', 'error');
      }
    } finally {
      setSuspending(false);
    }
  }

  function changeType(value: TypeFilter) {
    setTypeFilter(value);
    setPage(1);
  }

  function changeStatus(value: StatusFilter) {
    setStatusFilter(value);
    setPage(1);
  }

  // 403 — admin gate failed server-side.
  if (error?.status === 403) {
    return (
      <div className="animate-rise space-y-6">
        <PageHeader eyebrow="Platform" title="Users" />
        <ForbiddenState
          title="Users unavailable"
          message="Your account doesn't have access to platform user management."
        />
      </div>
    );
  }

  return (
    <div className="animate-rise space-y-6">
      <PageHeader
        eyebrow="Platform"
        title="Users"
        description="List, inspect, and moderate tenants and landlords across the platform."
      />

      {/* Filters */}
      <div className="flex flex-wrap items-end gap-3">
        <div className="w-44">
          <Field label="Role">
            {(id) => (
              <Select
                id={id}
                value={typeFilter}
                onChange={(e) => changeType(e.target.value as TypeFilter)}
              >
                {TYPE_OPTIONS.map((o) => (
                  <option key={o.value} value={o.value}>{o.label}</option>
                ))}
              </Select>
            )}
          </Field>
        </div>
        <div className="w-44">
          <Field label="Status">
            {(id) => (
              <Select
                id={id}
                value={statusFilter}
                onChange={(e) => changeStatus(e.target.value as StatusFilter)}
              >
                {STATUS_OPTIONS.map((o) => (
                  <option key={o.value} value={o.value}>{o.label}</option>
                ))}
              </Select>
            )}
          </Field>
        </div>
        <div className="min-w-[220px] flex-1 max-w-sm">
          <Field label="Search">
            {(id) => (
              <div className="relative">
                <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-ink-400">
                  <IconSearch size={16} />
                </span>
                <Input
                  id={id}
                  type="search"
                  className="pl-9"
                  value={searchInput}
                  onChange={(e) => setSearchInput(e.target.value)}
                  placeholder="Name or email…"
                />
              </div>
            )}
          </Field>
        </div>
      </div>

      {error ? (
        <ErrorState message={error.message} onRetry={reload} />
      ) : !loading && users.length === 0 ? (
        <EmptyState
          icon={<IconUsers />}
          title="No users found"
          description={
            search || typeFilter !== 'all' || statusFilter !== 'all'
              ? 'No users match these filters. Try widening your search.'
              : 'No users have registered yet.'
          }
        />
      ) : (
        <>
          {/* Record list — one standalone card per user. No table shell, no
              horizontal scroll: identity + role + verified/status + portfolio +
              actions stay visible (stacking on mobile, inline columns on desktop). */}
          {loading ? (
            <SkeletonCards />
          ) : (
            <RecordList>
              {users.map((user) => {
                const suspended = isSuspended(user);
                const busy = actingId === user.id;
                const portfolio =
                  user.user_type === 'landlord'
                    ? `${user.properties_count} ${user.properties_count === 1 ? 'property' : 'properties'} · ${user.listings_count} ${user.listings_count === 1 ? 'listing' : 'listings'}`
                    : `${user.applications_count} ${user.applications_count === 1 ? 'application' : 'applications'}`;

                return (
                  <RecordCard
                    key={user.id}
                    onClick={() => setDetailId(user.id)}
                    leading={<AvatarCircle user={user} />}
                    title={user.full_name}
                    titleMeta={
                      user.identity_verified ? (
                        <IconShield
                          size={13}
                          className="text-success-500"
                          title="Identity verified"
                        />
                      ) : undefined
                    }
                    subtitle={
                      <>
                        <span className="flex items-center gap-1 text-ink-500">
                          <IconMail size={11} className="shrink-0" />
                          {user.email}
                        </span>
                      </>
                    }
                    related={
                      <RecordRelated
                        title={humanize(user.user_type)}
                        lines={[
                          portfolio,
                          user.identity_verified ? 'Identity verified' : 'Unverified',
                        ]}
                      />
                    }
                    status={
                      suspended ? (
                        <SemanticBadge role="danger">Suspended</SemanticBadge>
                      ) : (
                        <SemanticBadge role="success">Active</SemanticBadge>
                      )
                    }
                    timestamp={<>Joined {formatDate(user.created_at)}</>}
                    primaryAction={
                      <Button
                        variant="ghost"
                        size="sm"
                        leftIcon={<IconEye size={14} />}
                        onClick={() => setDetailId(user.id)}
                      >
                        View
                      </Button>
                    }
                    menu={
                      <ActionMenu
                        label={`Actions for ${user.full_name}`}
                        items={
                          suspended
                            ? [
                                {
                                  label: 'Reinstate',
                                  icon: <IconUnlock size={14} />,
                                  onClick: () => handleReinstate(user),
                                  disabled: busy,
                                },
                              ]
                            : [
                                {
                                  label: 'Suspend',
                                  icon: <IconLock size={14} />,
                                  onClick: () => setSuspendTarget(user),
                                  danger: true,
                                  disabled: busy,
                                },
                              ]
                        }
                      />
                    }
                  />
                );
              })}
            </RecordList>
          )}

          {/* Pagination — sits below the record list, never inside a table shell. */}
          <div className="flex items-center justify-between gap-4">
            <p className="text-xs text-ink-500">
              {loading ? (
                <span className="inline-flex items-center gap-2">
                  <Spinner size={14} /> Loading…
                </span>
              ) : (
                `${total} ${total === 1 ? 'user' : 'users'} total`
              )}
            </p>
            {lastPage > 1 && (
              <div className="flex items-center gap-4">
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={currentPage <= 1 || loading}
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  leftIcon={<IconChevronLeft className="h-4 w-4" />}
                >
                  Previous
                </Button>
                <span className="text-sm text-ink-500">
                  Page {currentPage} of {lastPage}
                </span>
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={currentPage >= lastPage || loading}
                  onClick={() => setPage((p) => p + 1)}
                  leftIcon={<IconChevronRight className="h-4 w-4" />}
                >
                  Next
                </Button>
              </div>
            )}
          </div>
        </>
      )}

      {/* Detail drawer */}
      {detailId !== null && (
        <UserDetailDrawer userId={detailId} onClose={() => setDetailId(null)} />
      )}

      {/* Suspend confirm */}
      <DestructiveConfirmDialog
        open={suspendTarget !== null}
        onClose={() => { if (!suspending) setSuspendTarget(null); }}
        onConfirm={handleSuspend}
        title="Suspend account"
        description={
          suspendTarget
            ? `${suspendTarget.full_name} will lose access until reinstated.`
            : undefined
        }
        confirmLabel="Suspend account"
        loading={suspending}
        reasonField={{
          label: 'Reason for suspension',
          placeholder: 'e.g. Reported fraudulent listings; pending investigation.',
          required: true,
        }}
      />
    </div>
  );
}
