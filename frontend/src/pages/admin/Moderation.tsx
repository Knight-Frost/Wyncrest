import { useState } from 'react';
import { useApi } from '@/hooks/useApi';
import { adminApi } from '@/lib/endpoints';
import { formatDate, formatCedisDecimal } from '@/lib/format';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { DestructiveConfirmDialog } from '@/components/ui/DestructiveConfirmDialog';
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/states';
import { IconShield, IconCheck, IconX, IconAlertTriangle } from '@/components/ui/icons';
import {
  NexusCard,
  SemanticBadge,
  CommandCard,
  DashboardSection,
  getListingModerationVariant,
} from '@/components/cards';
import type { Listing, ListingStatus } from '@/lib/types';

/* ---- Filter tabs --------------------------------------------------------- */

type FilterKey = 'pending' | 'all' | 'approved' | 'rejected';

const FILTER_TABS: { key: FilterKey; label: string }[] = [
  { key: 'pending', label: 'Pending' },
  { key: 'all', label: 'All' },
  { key: 'approved', label: 'Approved' },
  { key: 'rejected', label: 'Rejected' },
];

function filterListings(listings: Listing[], filter: FilterKey): Listing[] {
  if (filter === 'pending') return listings.filter((l) => l.status === 'pending_review');
  if (filter === 'approved') return listings.filter((l) => l.status === 'active');
  if (filter === 'rejected') return listings.filter((l) => l.status === 'rejected');
  return listings;
}

function statusLabel(status: ListingStatus): string {
  switch (status) {
    case 'active':         return 'Approved';
    case 'pending_review': return 'Pending review';
    case 'rejected':       return 'Rejected';
    case 'draft':          return 'Draft';
    case 'archived':       return 'Archived';
    default:               return status;
  }
}

/* ---- Main page ----------------------------------------------------------- */

export function Moderation() {
  const { data, loading, error, reload } = useApi(() => adminApi.pendingListings(), []);

  const [filter, setFilter] = useState<FilterKey>('pending');
  const [rejecting, setRejecting] = useState<Listing | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [localOverrides, setLocalOverrides] = useState<Record<number, Listing['status']>>({});

  // All listings with local optimistic overrides applied
  const allListings: Listing[] = (data ?? []).map((l) =>
    localOverrides[l.id] ? { ...l, status: localOverrides[l.id]! } : l,
  );
  const visible = filterListings(allListings, filter);

  async function approve(listing: Listing) {
    setBusyId(listing.id);
    try {
      await adminApi.approveListing(listing.id);
      setLocalOverrides((prev) => ({ ...prev, [listing.id]: 'active' }));
    } catch {
      reload();
    } finally {
      setBusyId(null);
    }
  }

  function openReject(listing: Listing) {
    setRejecting(listing);
  }

  async function handleReject(reason?: string) {
    if (!rejecting || !reason) return;
    setSubmitting(true);
    try {
      await adminApi.rejectListing(rejecting.id, reason);
      setLocalOverrides((prev) => ({ ...prev, [rejecting.id]: 'rejected' }));
      setRejecting(null);
    } catch {
      reload();
    } finally {
      setSubmitting(false);
    }
  }

  const pendingCount = allListings.filter((l) => l.status === 'pending_review').length;

  return (
    <div className="animate-rise space-y-8">
      <PageHeader
        eyebrow="Platform"
        title="Listing Review"
        description="Review and approve rental listings before they go live to prospective tenants."
      />

      {/* Featured: pending queue size — CommandCard gives it editorial weight */}
      {!loading && !error && pendingCount > 0 && (
        <CommandCard
          role="warning"
          label="Listings awaiting review"
          value={String(pendingCount)}
          sub={pendingCount === 1 ? 'One listing needs your decision' : `${pendingCount} listings need your decision`}
          icon={<IconAlertTriangle size={20} />}
        />
      )}

      {/* Filter tabs */}
      <DashboardSection eyebrow="Queue" title="All listings">
        <div className="mb-5 flex gap-0 border-b border-ink-200" role="tablist" aria-label="Filter listings">
          {FILTER_TABS.map((tab) => {
            const count =
              tab.key === 'pending'
                ? allListings.filter((l) => l.status === 'pending_review').length
                : tab.key === 'approved'
                ? allListings.filter((l) => l.status === 'active').length
                : tab.key === 'rejected'
                ? allListings.filter((l) => l.status === 'rejected').length
                : allListings.length;

            return (
              <button
                key={tab.key}
                type="button"
                role="tab"
                onClick={() => setFilter(tab.key)}
                aria-selected={filter === tab.key}
                className={[
                  'inline-flex items-center gap-2 mr-6 py-2.5 px-1 text-sm font-medium border-b-2 -mb-px transition-colors',
                  filter === tab.key
                    ? 'border-brand-600 text-brand-700'
                    : 'border-transparent text-ink-500 hover:text-ink-800',
                ].join(' ')}
              >
                {tab.label}
                {!loading && (
                  <span
                    className={[
                      'inline-flex items-center justify-center rounded-full px-1.5 py-0.5 text-[10px] font-semibold tabular-nums',
                      filter === tab.key
                        ? 'bg-brand-100 text-brand-700'
                        : 'bg-ink-100 text-ink-500',
                    ].join(' ')}
                  >
                    {count}
                  </span>
                )}
              </button>
            );
          })}
        </div>

        {loading ? (
          <LoadingState label="Loading listings…" />
        ) : error ? (
          <ErrorState message={error.message} onRetry={reload} />
        ) : visible.length === 0 ? (
          <EmptyState
            icon={<IconShield />}
            title="Nothing here"
            description={
              filter === 'pending'
                ? 'No listings awaiting review. Check back later.'
                : 'No listings match this filter.'
            }
          />
        ) : (
          <div className="space-y-4">
            {visible.map((listing) => {
              const role = getListingModerationVariant(listing.status);
              return (
                <NexusCard key={listing.id} role={role} specular className="p-6">
                  <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="min-w-0 flex-1">
                      <div className="flex items-start gap-3 flex-wrap">
                        <h3 className="text-base font-semibold text-ink-900 leading-snug font-display">
                          {listing.title}
                        </h3>
                        <SemanticBadge role={role}>
                          {statusLabel(listing.status)}
                        </SemanticBadge>
                      </div>

                      <p className="mt-1 text-sm text-ink-500">
                        Landlord #{listing.landlord_id} · Submitted {formatDate(listing.created_at)}
                      </p>

                      {listing.unit && (
                        <p className="mt-2 text-sm text-ink-600">
                          Unit {listing.unit.unit_number} · {listing.unit.bedrooms} bd ·{' '}
                          {listing.unit.bathrooms} ba ·{' '}
                          <span
                            style={{ color: 'var(--color-money)' }}
                            className="font-semibold"
                          >
                            {formatCedisDecimal(listing.unit.rent_amount)}
                          </span>
                          /mo
                        </p>
                      )}

                      {listing.description && (
                        <p className="mt-2 line-clamp-3 max-w-2xl text-sm text-ink-600">
                          {listing.description}
                        </p>
                      )}

                      {listing.rejection_reason && (
                        <p className="mt-2 text-sm text-danger-600">
                          <span className="font-medium">Rejection reason:</span>{' '}
                          {listing.rejection_reason}
                        </p>
                      )}
                    </div>

                    {listing.status === 'pending_review' && (
                      <div className="flex shrink-0 items-center gap-2">
                        <Button
                          leftIcon={<IconCheck className="h-4 w-4" />}
                          onClick={() => approve(listing)}
                          loading={busyId === listing.id}
                          disabled={busyId !== null}
                        >
                          Approve
                        </Button>
                        <Button
                          variant="danger"
                          leftIcon={<IconX className="h-4 w-4" />}
                          onClick={() => openReject(listing)}
                          disabled={busyId !== null}
                        >
                          Reject
                        </Button>
                      </div>
                    )}
                  </div>
                </NexusCard>
              );
            })}
          </div>
        )}
      </DashboardSection>

      <DestructiveConfirmDialog
        open={rejecting !== null}
        onClose={() => { if (!submitting) setRejecting(null); }}
        onConfirm={handleReject}
        title="Reject listing"
        description={
          rejecting ? `Tell the landlord why "${rejecting.title}" was rejected.` : undefined
        }
        confirmLabel="Reject listing"
        loading={submitting}
        reasonField={{
          label: 'Reason for rejection',
          placeholder: 'Explain what needs to change…',
          required: true,
        }}
      />
    </div>
  );
}
