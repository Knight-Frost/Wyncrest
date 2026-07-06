/**
 * Compare — Homecrest side-by-side rental comparison.
 *
 * Draws exclusively from the tenant's saved listings (real API, no mock data).
 * The tenant selects 2–3 saved listings to compare side by side; selection is
 * persisted to localStorage so it survives a reload.
 *
 * Comparison rows are derived from real `Unit` and `Listing` fields only —
 * no fake commute times, no invented "best match", no preview banners.
 * "Lowest rent" / "Most space" winners are computed from the selected listings.
 */
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import {
  MapPin, Check, X, CircleDollarSign, BedDouble, Bath, Ruler,
  PawPrint, ShieldCheck, CalendarDays, Scale, Home,
} from 'lucide-react';
import { tenantApi } from '@/lib/endpoints';
import { useApi } from '@/hooks/useApi';
import { EmptyState, ErrorState, ForbiddenState, Skeleton } from '@/components/ui/states';
import { formatCedisDecimal, formatDate } from '@/lib/format';
import {
  NexusCard,
  SemanticBadge,
  getListingModerationVariant,
} from '@/components/cards';
import type { Listing } from '@/lib/types';
import './compare.css';

/* ---- localStorage key for persisting selected listing IDs ---------------- */
const STORAGE_KEY = 'nexus:compare:selected';

function loadPersistedIds(): number[] {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return [];
    const parsed: unknown = JSON.parse(raw);
    if (!Array.isArray(parsed)) return [];
    return parsed.filter((v): v is number => typeof v === 'number');
  } catch {
    return [];
  }
}

function persistIds(ids: number[]): void {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(ids));
  } catch {
    /* storage quota exceeded — best effort, silently skip */
  }
}

/* ---- Winner logic -------------------------------------------------------- */
type Direction = 'min' | 'max';

/**
 * Returns the index of the winning column among `values`, or -1 on tie/empty.
 * 'min' wins on smallest (e.g. rent), 'max' wins on largest (e.g. sq ft).
 * Ties produce no winner — we don't arbitrarily crown one listing.
 */
function pickWinner(values: number[], direction: Direction): number {
  const candidates = values
    .map((value, index) => ({ value, index }))
    .filter((c) => Number.isFinite(c.value));
  if (candidates.length === 0) return -1;

  const best = candidates.reduce((winner, c) =>
    (direction === 'min' ? c.value < winner.value : c.value > winner.value) ? c : winner,
  );
  const tied = candidates.filter((c) => c.value === best.value).length > 1;
  return tied ? -1 : best.index;
}

/* ---- Feature row definitions --------------------------------------------- */
interface FeatureRow {
  key: string;
  Icon: typeof CircleDollarSign;
  name: string;
  desc: string;
  /** Return the display string for this listing, or null to skip. */
  render: (listing: Listing) => string | null;
  /** Numeric metric for winner comparison (null = no winner for this row). */
  metric: (listing: Listing) => number | null;
  direction: Direction | null;
  winnerBadge: string;
}

const FEATURE_ROWS: FeatureRow[] = [
  {
    key: 'rent',
    Icon: CircleDollarSign,
    name: 'Monthly rent',
    desc: 'Total monthly payment',
    render: (l) => l.unit ? `${formatCedisDecimal(l.unit.rent_amount)} /mo` : null,
    metric: (l) => {
      if (!l.unit) return null;
      const n = parseFloat(l.unit.rent_amount);
      return Number.isFinite(n) ? n : null;
    },
    direction: 'min',
    winnerBadge: 'Lowest',
  },
  {
    key: 'beds',
    Icon: BedDouble,
    name: 'Bedrooms',
    desc: 'Number of bedrooms',
    render: (l) => {
      if (!l.unit) return null;
      const n = parseInt(l.unit.bedrooms, 10);
      return Number.isFinite(n) ? `${n} bed${n !== 1 ? 's' : ''}` : null;
    },
    metric: (l) => {
      if (!l.unit) return null;
      const n = parseInt(l.unit.bedrooms, 10);
      return Number.isFinite(n) ? n : null;
    },
    direction: 'max',
    winnerBadge: 'Most',
  },
  {
    key: 'baths',
    Icon: Bath,
    name: 'Bathrooms',
    desc: 'Number of bathrooms',
    render: (l) => {
      if (!l.unit) return null;
      const n = parseFloat(l.unit.bathrooms);
      return Number.isFinite(n) ? `${n} bath${n !== 1 ? 's' : ''}` : null;
    },
    metric: (l) => {
      if (!l.unit) return null;
      const n = parseFloat(l.unit.bathrooms);
      return Number.isFinite(n) ? n : null;
    },
    direction: 'max',
    winnerBadge: 'Most',
  },
  {
    key: 'size',
    Icon: Ruler,
    name: 'Size',
    desc: 'Total area',
    render: (l) => {
      const sq = l.unit?.square_feet;
      return sq != null ? `${sq.toLocaleString('en-GH')} sqft` : null;
    },
    metric: (l) => l.unit?.square_feet ?? null,
    direction: 'max',
    winnerBadge: 'Largest',
  },
  {
    key: 'deposit',
    Icon: ShieldCheck,
    name: 'Security deposit',
    desc: 'Upfront deposit required',
    render: (l) => {
      const dep = l.unit?.security_deposit;
      if (!dep) return null;
      return formatCedisDecimal(dep);
    },
    metric: (l) => {
      const dep = l.unit?.security_deposit;
      if (!dep) return null;
      const n = parseFloat(dep);
      return Number.isFinite(n) ? n : null;
    },
    direction: 'min',
    winnerBadge: 'Lowest',
  },
  {
    key: 'pets',
    Icon: PawPrint,
    name: 'Pets allowed',
    desc: 'Whether pets are welcome',
    render: (l) => (l.pets_allowed ? 'Yes' : 'No'),
    metric: (l) => (l.pets_allowed ? 1 : 0),
    direction: 'max',
    winnerBadge: 'Pet-friendly',
  },
  {
    key: 'available',
    Icon: CalendarDays,
    name: 'Available from',
    desc: 'Earliest move-in date',
    render: (l) => {
      const d = l.unit?.available_from ?? l.move_in_date;
      return d ? formatDate(d) : null;
    },
    metric: (l) => {
      const d = l.unit?.available_from ?? l.move_in_date;
      if (!d) return null;
      const ts = new Date(d).getTime();
      return Number.isFinite(ts) ? ts : null;
    },
    direction: 'min',
    winnerBadge: 'Soonest',
  },
  {
    key: 'location',
    Icon: MapPin,
    name: 'Location',
    desc: 'City / area',
    render: (l) => {
      const prop = l.unit?.property;
      if (!prop) return null;
      return [prop.city, prop.state].filter(Boolean).join(', ');
    },
    metric: () => null,
    direction: null,
    winnerBadge: '',
  },
];

/* ---- Skeleton cards ------------------------------------------------------ */
function SkeletonCards() {
  return (
    <div className="cmp-panel">
      <Skeleton className="h-5 w-56 mb-1" />
      <div className="cmp-selected" style={{ gridTemplateColumns: 'repeat(3, 1fr)' }}>
        {Array.from({ length: 3 }).map((_, i) => (
          <div key={i} className="cmp-card">
            <div className="cmp-card-media"><Skeleton className="h-full w-full" /></div>
            <div className="cmp-card-body" style={{ flex: 1 }}>
              <Skeleton className="h-4 w-full mb-2" />
              <Skeleton className="h-3.5 w-3/4 mb-3" />
              <Skeleton className="h-5 w-1/2" />
            </div>
          </div>
        ))}
      </div>
      <Skeleton className="h-64 w-full" />
    </div>
  );
}

/* ---- Selection picker (when saved listings exist but none selected) ------- */
function ListingPickerCard({
  listing,
  onSelect,
}: {
  listing: Listing;
  onSelect: (id: number) => void;
}) {
  const photo    = listing.primary_photo ?? listing.photos?.[0];
  const location = listing.unit?.property
    ? [listing.unit.property.city, listing.unit.property.state].filter(Boolean).join(', ')
    : null;
  const rent = listing.unit ? formatCedisDecimal(listing.unit.rent_amount) : null;

  return (
    <article
      className="cmp-card"
      style={{ cursor: 'pointer' }}
      onClick={() => onSelect(listing.id)}
      tabIndex={0}
      role="button"
      aria-label={`Add ${listing.title} to comparison`}
      onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') onSelect(listing.id); }}
    >
      <div className="cmp-card-media">
        {photo ? (
          <img src={`/storage/${photo.path}`} alt={photo.alt_text ?? listing.title} loading="lazy" />
        ) : (
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', height: '100%', color: 'var(--color-ink-400)' }}>
            <Home size={28} />
          </div>
        )}
      </div>
      <div className="cmp-card-body">
        <span className="cmp-card-name">{listing.title}</span>
        {location && <span className="cmp-card-loc"><MapPin size={13} /> {location}</span>}
        {rent && <span className="cmp-card-rent">{rent} <span>/mo</span></span>}
        {/* Listing status badge — semantic */}
        <div style={{ marginTop: 4 }}>
          <SemanticBadge role={getListingModerationVariant(listing.status)} status={listing.status} dot size="sm" />
        </div>
        <button
          className="cmp-btn cmp-btn-primary"
          style={{ marginTop: 'auto', fontSize: 12, padding: '6px 12px' }}
          onClick={(e) => { e.stopPropagation(); onSelect(listing.id); }}
        >
          Add to compare
        </button>
      </div>
    </article>
  );
}

/* ====================================================================== page */

export function ComparePage() {
  const savedQ = useApi(() => tenantApi.savedListings(), []);

  /* Selected listing IDs — seeded from ?ids= (e.g. Saved listings → Compare),
     falling back to localStorage, max 3. The persist effect below writes the
     seeded selection back to localStorage so a refresh keeps it. */
  const [searchParams] = useSearchParams();
  const [selectedIds, setSelectedIds] = useState<number[]>(() => {
    const raw = searchParams.get('ids');
    if (raw) {
      const ids = raw
        .split(',')
        .map((s) => Number.parseInt(s.trim(), 10))
        .filter((n) => Number.isInteger(n) && n > 0)
        .slice(0, 3);
      if (ids.length) return ids;
    }
    return loadPersistedIds();
  });

  /* Persist whenever selection changes */
  useEffect(() => {
    persistIds(selectedIds);
  }, [selectedIds]);

  const addListing = useCallback((id: number) => {
    setSelectedIds((prev) => {
      if (prev.includes(id)) return prev;
      if (prev.length >= 3) return prev; /* cap at 3 */
      return [...prev, id];
    });
  }, []);

  const removeListing = useCallback((id: number) => {
    setSelectedIds((prev) => prev.filter((x) => x !== id));
  }, []);

  /* Derive selected Listing objects from saved list */
  const savedListings: Listing[] = useMemo(() => savedQ.data ?? [], [savedQ.data]);

  const selectedListings: Listing[] = useMemo(
    () => selectedIds.flatMap((id) => {
      const found = savedListings.find((l) => l.id === id);
      return found ? [found] : [];
    }),
    [selectedIds, savedListings],
  );

  /* Listings available to add (saved but not yet selected) */
  const addableListings: Listing[] = useMemo(
    () => savedListings.filter((l) => !selectedIds.includes(l.id)),
    [savedListings, selectedIds],
  );

  /* Pre-compute winner index per feature row across selected listings */
  const winners = useMemo(() => {
    const map: Record<string, number> = {};
    for (const row of FEATURE_ROWS) {
      if (row.direction === null) {
        map[row.key] = -1;
        continue;
      }
      const metrics = selectedListings.map((l) => row.metric(l) ?? NaN);
      map[row.key] = pickWinner(metrics, row.direction);
    }
    return map;
  }, [selectedListings]);

  /* Only show rows where at least one selected listing has a value */
  const visibleRows = useMemo(
    () => FEATURE_ROWS.filter((row) =>
      selectedListings.some((l) => row.render(l) !== null),
    ),
    [selectedListings],
  );

  /* "Best value" callout: listing with the lowest rent (computed, not scripted) */
  const lowestRentIdx = useMemo(() => {
    if (selectedListings.length < 2) return -1;
    return pickWinner(
      selectedListings.map((l) => {
        if (!l.unit) return NaN;
        const n = parseFloat(l.unit.rent_amount);
        return Number.isFinite(n) ? n : NaN;
      }),
      'min',
    );
  }, [selectedListings]);

  /* ---- render ---- */

  if (savedQ.loading) return <SkeletonCards />;

  if (savedQ.error) {
    if (savedQ.error.status === 403) {
      return (
        <div className="cmp-page">
          <ForbiddenState
            title="Compare not available"
            message="Your account doesn't have access to the compare feature."
          />
        </div>
      );
    }
    return (
      <div className="cmp-page">
        <ErrorState
          title="Couldn't load your saved homes"
          message={savedQ.error.message}
          onRetry={savedQ.reload}
        />
      </div>
    );
  }

  /* No saved listings at all */
  if (savedListings.length === 0) {
    return (
      <div className="cmp-page">
        <header className="cmp-head">
          <div className="cmp-head-title">
            <p className="cmp-eyebrow">Compare</p>
            <h1 className="cmp-title">Compare Rentals</h1>
            <p className="cmp-sub">Pick two or three saved homes to compare side by side.</p>
          </div>
        </header>
        <NexusCard role="neutral" className="cmp-panel">
          <EmptyState
            icon={<Scale size={26} />}
            title="Save some homes first"
            description="Browse listings, save the ones you like, then come back here to compare them side by side."
            action={
              <Link to="/app/browse" className="cmp-btn cmp-btn-primary">
                Browse listings
              </Link>
            }
          />
        </NexusCard>
      </div>
    );
  }

  return (
    <div className="cmp-page">
      {/* header */}
      <header className="cmp-head">
        <div className="cmp-head-title">
          <p className="cmp-eyebrow">Compare</p>
          <h1 className="cmp-title">Compare Rentals</h1>
          <p className="cmp-sub">
            {selectedListings.length < 2
              ? 'Select two or three saved homes below to compare them side by side.'
              : `Comparing ${selectedListings.length} of your saved homes.`}
          </p>
        </div>
      </header>

      <NexusCard role="neutral" className="cmp-panel">
        {/* ---- selection picker (always visible, shows unselected saved homes) ---- */}
        {addableListings.length > 0 && (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
            <h2 className="cmp-section-title">
              {selectedListings.length === 0
                ? 'Choose homes to compare'
                : `Add another home${selectedListings.length >= 3 ? ' (max 3 reached)' : ''}`}
            </h2>
            {selectedListings.length < 3 && (
              <div className="cmp-selected">
                {addableListings.map((l) => (
                  <ListingPickerCard key={l.id} listing={l} onSelect={addListing} />
                ))}
              </div>
            )}
          </div>
        )}

        {/* ---- nothing selected yet ---- */}
        {selectedListings.length === 0 && addableListings.length === 0 && (
          <div className="cmp-empty">
            <span className="cmp-empty-ico"><Scale size={26} /></span>
            <p className="cmp-empty-title">No homes left to add</p>
            <p className="cmp-empty-text">All your saved homes are already selected for comparison.</p>
          </div>
        )}

        {/* ---- comparison area (only when 2+ selected) ---- */}
        {selectedListings.length >= 2 && (
          <>
            {/* selected cards */}
            <h2 className="cmp-section-title">Comparing ({selectedListings.length})</h2>
            <div className="cmp-selected">
              {selectedListings.map((listing, i) => {
                const photo    = listing.primary_photo ?? listing.photos?.[0];
                const location = listing.unit?.property
                  ? [listing.unit.property.city, listing.unit.property.state].filter(Boolean).join(', ')
                  : null;
                const rent = listing.unit ? formatCedisDecimal(listing.unit.rent_amount) : null;

                return (
                  <article className="cmp-card" key={listing.id}>
                    <div className="cmp-card-media">
                      {photo ? (
                        <img
                          src={`/storage/${photo.path}`}
                          alt={photo.alt_text ?? listing.title}
                          loading="lazy"
                        />
                      ) : (
                        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', height: '100%', color: 'var(--color-ink-400)' }}>
                          <Home size={28} />
                        </div>
                      )}
                      <span className="cmp-rank" aria-hidden="true">{i + 1}</span>
                    </div>
                    <div className="cmp-card-body">
                      <span className="cmp-card-name">{listing.title}</span>
                      {location && (
                        <span className="cmp-card-loc"><MapPin size={13} /> {location}</span>
                      )}
                      {rent && (
                        <span className="cmp-card-rent">{rent} <span>/mo</span></span>
                      )}
                      {/* Listing status badge — semantic */}
                      <div style={{ marginTop: 4 }}>
                        <SemanticBadge
                          role={getListingModerationVariant(listing.status)}
                          status={listing.status}
                          dot
                          size="sm"
                        />
                      </div>
                    </div>
                    <button
                      className="cmp-card-remove"
                      aria-label={`Remove ${listing.title} from comparison`}
                      onClick={() => removeListing(listing.id)}
                    >
                      <X size={16} />
                    </button>
                  </article>
                );
              })}
            </div>

            {/* comparison grid */}
            {visibleRows.length > 0 && (
              <div className="cmp-grid" role="table" aria-label="Feature comparison">
                {/* header row */}
                <div className="cmp-grow head" role="row">
                  <div className="cmp-cell" role="columnheader">
                    <span className="cmp-feature-head">Features</span>
                  </div>
                  {selectedListings.map((listing, i) => (
                    <div className="cmp-cell" key={listing.id} role="columnheader">
                      <span className="cmp-col-head">
                        <span className="cmp-pin" aria-hidden="true">{i + 1}</span>
                        {listing.title}
                      </span>
                    </div>
                  ))}
                </div>

                {visibleRows.map((row) => {
                  const win    = winners[row.key] ?? -1;
                  const RowIcon = row.Icon;
                  return (
                    <div className="cmp-grow" key={row.key} role="row">
                      <div className="cmp-cell" role="rowheader">
                        <div className="cmp-feature">
                          <span className="cmp-feature-ico" aria-hidden="true"><RowIcon size={18} /></span>
                          <div>
                            <div className="cmp-feature-name">{row.name}</div>
                            <div className="cmp-feature-desc">{row.desc}</div>
                          </div>
                        </div>
                      </div>
                      {selectedListings.map((listing, i) => {
                        const value = row.render(listing);
                        return (
                          <div className="cmp-cell" key={listing.id} role="cell">
                            <span className="cmp-val">
                              {value ?? <span style={{ color: 'var(--color-ink-400)' }}>—</span>}
                              {win === i && row.winnerBadge && (
                                <span className="cmp-win">{row.winnerBadge}</span>
                              )}
                            </span>
                          </div>
                        );
                      })}
                    </div>
                  );
                })}
              </div>
            )}

            {/* lowest rent callout — computed from real data, no fiction */}
            {lowestRentIdx >= 0 && selectedListings[lowestRentIdx] && (
              <div className="cmp-best">
                <span className="cmp-best-ico" aria-hidden="true">
                  <CircleDollarSign size={26} />
                </span>
                <div className="cmp-best-body">
                  <div className="cmp-best-eyebrow">Lowest monthly rent</div>
                  <div className="cmp-best-name">{selectedListings[lowestRentIdx].title}</div>
                  <p className="cmp-best-reason">
                    {`This listing has the lowest rent among the ${selectedListings.length} you're comparing`}
                    {selectedListings[lowestRentIdx].unit
                      ? `, at ${formatCedisDecimal(selectedListings[lowestRentIdx].unit!.rent_amount)} /mo.`
                      : '.'}
                  </p>
                  <div className="cmp-best-chips">
                    <span className="cmp-chip"><Check size={13} aria-hidden="true" /> Lowest rent</span>
                    {selectedListings[lowestRentIdx].pets_allowed && (
                      <span className="cmp-chip"><Check size={13} aria-hidden="true" /> Pets allowed</span>
                    )}
                    {selectedListings[lowestRentIdx].status === 'active' && (
                      <span className="cmp-chip"><Check size={13} aria-hidden="true" /> Active listing</span>
                    )}
                  </div>
                </div>
                {(() => {
                  const best  = selectedListings[lowestRentIdx];
                  const photo = best.primary_photo ?? best.photos?.[0];
                  return photo ? (
                    <div className="cmp-best-media">
                      <img
                        src={`/storage/${photo.path}`}
                        alt={photo.alt_text ?? best.title}
                        loading="lazy"
                      />
                    </div>
                  ) : null;
                })()}
              </div>
            )}
          </>
        )}

        {/* prompt when only 1 is selected */}
        {selectedListings.length === 1 && (
          <div className="cmp-empty" style={{ paddingTop: 24, paddingBottom: 24 }}>
            <span className="cmp-empty-ico"><Scale size={22} /></span>
            <p className="cmp-empty-title">Add one more home</p>
            <p className="cmp-empty-text">Select at least one more saved home above to start the comparison.</p>
          </div>
        )}
      </NexusCard>
    </div>
  );
}
