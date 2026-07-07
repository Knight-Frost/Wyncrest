import { useMemo, useState } from 'react';
import { Link } from 'react-router';
import {
  Search, MapPin, ChevronDown, Bell, Mail, Sun, Cloud, CloudRain,
  Check, SlidersHorizontal, LayoutGrid, List as ListIcon, Map as MapIcon,
  Heart, BedDouble, Bath, Maximize2, Building2, ShieldCheck, ArrowRight,
  ChevronLeft, ChevronRight,
} from 'lucide-react';
import { useApi } from '@/hooks/useApi';
import { publicApi, tenantApi, weatherApi, notificationApi } from '@/lib/endpoints';
import { useAuth } from '@/context/auth';
import { formatCedisDecimal } from '@/lib/format';
import {
  SemanticBadge,
} from '@/components/cards';
import { ErrorState, EmptyState, Skeleton } from '@/components/ui/states';
import { Avatar } from '@/components/ui/Avatar';
import { InfoHint } from '@/components/ui/InfoHint';
import { help } from '@/lib/helpText';
import type { Listing, TenantProfileResponse } from '@/lib/types';
import './browse-listings.css';

/** Stable abstract hue seeded from the listing id + title — no fake photos. */
function listingHue(seed: string): number {
  let hash = 0;
  for (let i = 0; i < seed.length; i++) hash = (hash * 31 + seed.charCodeAt(i)) & 0xffff;
  return 190 + (hash % 50); // cool blue-teal range that fits the Wyncrest palette
}

/* ---- filters ------------------------------------------------------------- */
interface Filters {
  city: string;
  neighborhood: string;
  max_price: string;
  bedrooms: string;
  property_type: string;
  verified_only: boolean;
}
const EMPTY: Filters = { city: '', neighborhood: '', max_price: '', bedrooms: '', property_type: '', verified_only: false };

const CITIES = ['All cities', 'Accra', 'Tema', 'Kumasi', 'Takoradi', 'Cape Coast'];
const NEIGHBOURHOODS = ['All neighborhoods', 'East Legon', 'Cantonments', 'Osu', 'Airport Residential', 'Labone', 'Spintex', 'Dzorwulu'];
const MAX_PRICES: { v: string; l: string }[] = [
  { v: '', l: 'Any price' }, { v: '300000', l: 'Up to GH₵ 3,000' }, { v: '600000', l: 'Up to GH₵ 6,000' },
  { v: '1000000', l: 'Up to GH₵ 10,000' }, { v: '1500000', l: 'Up to GH₵ 15,000' },
];
const BEDS = [{ v: '', l: 'Any' }, { v: '1', l: '1+' }, { v: '2', l: '2+' }, { v: '3', l: '3+' }, { v: '4', l: '4+' }];
const TYPES: { v: string; l: string }[] = [
  { v: '', l: 'Any' }, { v: 'apartment', l: 'Apartment' }, { v: 'townhouse', l: 'Townhouse' },
  { v: 'single_family', l: 'House' }, { v: 'condo', l: 'Condo' }, { v: 'multi_family', l: 'Multi-family' },
];
const SORTS = [
  { v: 'recommended', l: 'Recommended' }, { v: 'price_asc', l: 'Price: low to high' },
  { v: 'price_desc', l: 'Price: high to low' }, { v: 'beds', l: 'Most bedrooms' },
];

const TYPE_LABEL: Record<string, string> = {
  single_family: 'House', multi_family: 'Multi-family', apartment: 'Apartment',
  condo: 'Condo', townhouse: 'Townhouse', duplex: 'Duplex', studio: 'Studio',
};
const typeLabel = (t?: string) => (t ? TYPE_LABEL[t] ?? t.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) : 'Home');
const rent = (l: Listing) => l.unit?.rent_amount ?? null;
const beds = (l: Listing) => (l.unit?.bedrooms ? parseInt(l.unit.bedrooms, 10) : 0);
const realPhotoSrc = (l: Listing) =>
  l.primary_photo?.path ? `${import.meta.env.VITE_API_URL ?? ''}/storage/${l.primary_photo.path}` : null;

/** Returns true when the listing is available now (no future available_from date). */
function isAvailableNow(l: Listing): boolean {
  const from = l.unit?.available_from;
  if (!from) return true;
  return new Date(from).getTime() <= Date.now();
}

/* ---- reusable select ----------------------------------------------------- */
function Field({ label, value, onChange, children }: { label: string; value: string; onChange: (v: string) => void; children: React.ReactNode }) {
  return (
    <label className="bz-field">
      <span className="bz-field-label">{label}</span>
      <span className="bz-select">
        <select value={value} onChange={(e) => onChange(e.target.value)}>{children}</select>
        <ChevronDown size={16} className="bz-select-chev" />
      </span>
    </label>
  );
}

/* ---- weather chip -------------------------------------------------------- */
function WeatherChip({ city }: { city: string }) {
  const { data } = useApi(() => weatherApi.current(city), [city]);
  if (!data || !data.available) return null;
  const c = data.condition.toLowerCase();
  const Icon = c.includes('rain') || c.includes('drizzle') ? CloudRain : c.includes('cloud') || c.includes('overcast') ? Cloud : Sun;
  return (
    <div className="bz-weather">
      <Icon size={18} className="bz-weather-ico" />
      <span>
        <span className="bz-weather-temp">{Math.round(data.temperature)}°{data.unit}</span>{' '}
        <span className="bz-weather-cond">{data.condition}</span>
      </span>
    </div>
  );
}

/* ---- card ---------------------------------------------------------------- */
function PropertyCard({ listing, saved, onToggle }: {
  listing: Listing;
  saved: boolean;
  onToggle: (id: number, next: boolean) => void;
}) {
  const unit = listing.unit;
  const prop = unit?.property;
  const availableNow = isAvailableNow(listing);
  const loc = prop ? `${prop.city}${prop.state ? `, ${prop.state}` : ''}` : '—';
  const rentDisplay = rent(listing);
  const photoSrc = realPhotoSrc(listing);
  const hue = listingHue(`${listing.id}${listing.title}`);

  const toggle = async (e: React.MouseEvent) => {
    e.preventDefault(); e.stopPropagation();
    const next = !saved;
    onToggle(listing.id, next);
    try {
      if (next) await tenantApi.saveListing(listing.id);
      else await tenantApi.unsaveListing(listing.id);
    } catch { onToggle(listing.id, saved); }
  };

  return (
    <Link to={`/app/listing/${listing.id}`} className="bz-card">
      <div className="bz-card-img">
        {photoSrc ? (
          <img src={photoSrc} alt={listing.title} loading="lazy" />
        ) : (
          <div
            aria-hidden="true"
            style={{
              width: '100%',
              height: '100%',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              background: `linear-gradient(135deg, hsl(${hue} 28% 92%), hsl(${hue} 22% 84%))`,
              color: `hsl(${hue} 30% 52%)`,
            }}
          >
            <Building2 size={40} strokeWidth={1.25} />
          </div>
        )}
        <span className="bz-verified"><ShieldCheck size={14} /> Verified</span>
        <button className={`bz-heart${saved ? ' on' : ''}`} onClick={toggle} aria-label={saved ? 'Remove from saved' : 'Save listing'}>
          <Heart size={16} fill={saved ? 'currentColor' : 'none'} />
        </button>
      </div>
      <div className="bz-card-body">
        {/* Availability badge — semantic (success = now, warning = soon) */}
        <div className="bz-avail-wrap">
          <SemanticBadge
            role={availableNow ? 'success' : 'warning'}
            dot
          >
            {availableNow ? 'Available now' : 'Available soon'}
          </SemanticBadge>
        </div>
        <h3 className="bz-card-name">{listing.title}</h3>
        <p className="bz-card-loc"><MapPin size={14} /> {loc}</p>
        <div className="bz-specs">
          {beds(listing) > 0 && <span className="bz-spec"><BedDouble size={15} /> {beds(listing)} bed</span>}
          {unit?.bathrooms && <span className="bz-spec"><Bath size={15} /> {parseInt(unit.bathrooms, 10)} bath</span>}
          {unit?.square_feet && <span className="bz-spec"><Maximize2 size={15} /> {unit.square_feet.toLocaleString()} sqft</span>}
          <span className="bz-spec"><Building2 size={15} /> {typeLabel(prop?.property_type)}</span>
        </div>
        <div className="bz-card-foot">
          <span className="bz-price">
            {rentDisplay != null ? formatCedisDecimal(rentDisplay) : '—'}
            <small> /mo</small>
          </span>
          <span className="bz-details">View details <ArrowRight size={15} /></span>
        </div>
      </div>
    </Link>
  );
}

/* ---- skeleton card ------------------------------------------------------- */
function ListingSkeleton() {
  return (
    <div className="bz-sk-card">
      <div className="bz-sk-img"><Skeleton className="h-full w-full" /></div>
      <div className="bz-sk-body">
        <Skeleton className="h-5 w-24 mb-3" />
        <Skeleton className="h-6 w-3/4 mb-2" />
        <Skeleton className="h-4 w-1/2 mb-4" />
        <div style={{ display: 'flex', gap: 16 }}>
          <Skeleton className="h-4 w-16" />
          <Skeleton className="h-4 w-16" />
          <Skeleton className="h-4 w-20" />
        </div>
      </div>
    </div>
  );
}

/* ========================================================================== */
export function BrowseListings() {
  const { user } = useAuth();
  const [draft, setDraft] = useState<Filters>(EMPTY);
  const [applied, setApplied] = useState<Filters>(EMPTY);
  const [query, setQuery] = useState('');
  const [appliedQuery, setAppliedQuery] = useState('');
  const [sort, setSort] = useState('recommended');
  const [view, setView] = useState<'grid' | 'list'>('grid');
  const [page, setPage] = useState(1);
  const [savedMap, setSavedMap] = useState<Map<number, boolean>>(new Map());

  // Fetch profile to get the tenant's actual city (may be null)
  const { data: profileData } = useApi<TenantProfileResponse>(() => tenantApi.profile(), []);
  const tenantCity = profileData?.user.city ?? null;

  const { data, loading, error, reload } = useApi(
    () => publicApi.listings({
      city: applied.city || undefined,
      max_price: applied.max_price ? Number(applied.max_price) : undefined,
      bedrooms: applied.bedrooms ? Number(applied.bedrooms) : undefined,
      page,
    }),
    [applied.city, applied.max_price, applied.bedrooms, page],
  );

  useApi<Listing[]>(async () => {
    const saved = await tenantApi.savedListings().catch(() => [] as Listing[]);
    setSavedMap(new Map(saved.map((l) => [l.id, true])));
    return saved;
  }, []);

  const { data: unread } = useApi(() => notificationApi.unreadCount(), []);
  const name = user && 'full_name' in user ? user.full_name : 'You';
  const avatarUrl = (user && 'avatar_url' in user ? user.avatar_url : null) ?? profileData?.user.avatar_url ?? null;

  const set = <K extends keyof Filters>(k: K, v: Filters[K]) => setDraft((p) => ({ ...p, [k]: v }));
  const apply = () => { setApplied(draft); setAppliedQuery(query); setPage(1); };
  const clear = () => { setDraft(EMPTY); setApplied(EMPTY); setQuery(''); setAppliedQuery(''); setPage(1); };

  // Whether the tenant has narrowed the results at all — distinguishes "no homes
  // match your filters" from "the platform has no listings yet" (a clean,
  // intentional empty state on a brand-new product).
  const hasActiveFilters =
    appliedQuery.trim() !== '' ||
    applied.verified_only ||
    (Object.keys(EMPTY) as (keyof Filters)[]).some(
      (k) => k !== 'verified_only' && applied[k] !== EMPTY[k],
    );

  const lastPage = data?.last_page ?? 1;

  const listings = useMemo(() => {
    let arr = [...(data?.data ?? [])];
    const q = appliedQuery.trim().toLowerCase();
    const hood = applied.neighborhood && applied.neighborhood !== 'All neighborhoods' ? applied.neighborhood.toLowerCase() : '';
    if (q || hood) {
      arr = arr.filter((l) => {
        const hay = `${l.title} ${l.unit?.property?.city ?? ''} ${l.unit?.property?.street_address ?? ''} ${l.unit?.property?.name ?? ''}`.toLowerCase();
        return (!q || hay.includes(q)) && (!hood || hay.includes(hood));
      });
    }
    if (applied.property_type) arr = arr.filter((l) => l.unit?.property?.property_type === applied.property_type);
    if (applied.verified_only) arr = arr.filter((l) => l.status === 'active');
    switch (sort) {
      case 'price_asc': arr.sort((a, b) => (parseFloat(a.unit?.rent_amount ?? '0')) - (parseFloat(b.unit?.rent_amount ?? '0'))); break;
      case 'price_desc': arr.sort((a, b) => (parseFloat(b.unit?.rent_amount ?? '0')) - (parseFloat(a.unit?.rent_amount ?? '0'))); break;
      case 'beds': arr.sort((a, b) => beds(b) - beds(a)); break;
    }
    return arr;
  }, [data, appliedQuery, applied.neighborhood, applied.property_type, applied.verified_only, sort]);

  const onToggleSave = (id: number, next: boolean) => setSavedMap((p) => new Map(p).set(id, next));

  return (
    <div className="bz-page">
      {/* top utility bar */}
      <div className="bz-topbar">
        <div className="bz-meta">
          {tenantCity && (
            <div className="bz-loc"><MapPin size={16} /> {tenantCity} <ChevronDown size={15} className="bz-loc-chev" /></div>
          )}
          {tenantCity && <WeatherChip city={tenantCity} />}
        </div>
        <Link to="/app/notifications" className="bz-tb-btn" aria-label="Notifications">
          <Bell size={18} />
          {!!unread && unread > 0 && <span className="bz-tb-badge">{unread > 9 ? '9+' : unread}</span>}
        </Link>
        <Link to="/app/messages" className="bz-tb-btn" aria-label="Messages"><Mail size={18} /></Link>
        <Link to="/app/profile" className="bz-avatar" aria-label="Profile">
          <Avatar name={name} src={avatarUrl} className="bz-avatar-circle" />
          <ChevronDown size={15} />
        </Link>
      </div>

      {/* header */}
      <div className="bz-head">
        <p className="bz-eyebrow">Find a Home</p>
        <h1 className="bz-title">Find verified homes in Ghana</h1>
        <p className="bz-sub">Browse trusted rentals across Accra, Tema, Kumasi, Takoradi, Cape Coast and beyond.</p>
      </div>

      {/* hero search */}
      <div className="bz-search">
        <Search size={20} className="bz-search-ico" />
        <input
          className="bz-search-input"
          placeholder="Search East Legon, Cantonments, Osu, 2 bedroom…"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') apply(); }}
          aria-label="Search listings"
        />
        <button type="button" className="bz-search-check" onClick={() => set('verified_only', !draft.verified_only)}>
          <span className={`bz-cb${draft.verified_only ? ' on' : ''}`} aria-hidden="true"><Check size={13} strokeWidth={3} /></span>
          Verified listings only
        </button>
        <button className="bz-btn-primary bz-search-btn" onClick={apply}>Search</button>
      </div>

      {/* filter panel */}
      <div className="bz-filters">
        <div className="bz-filter-grid">
          <Field label="City" value={draft.city} onChange={(v) => set('city', v === 'All cities' ? '' : v)}>
            {CITIES.map((c) => <option key={c} value={c === 'All cities' ? '' : c}>{c}</option>)}
          </Field>
          <Field label="Neighborhood" value={draft.neighborhood} onChange={(v) => set('neighborhood', v)}>
            {NEIGHBOURHOODS.map((n) => <option key={n} value={n === 'All neighborhoods' ? '' : n}>{n}</option>)}
          </Field>
          <Field label="Max price" value={draft.max_price} onChange={(v) => set('max_price', v)}>
            {MAX_PRICES.map((p) => <option key={p.v} value={p.v}>{p.l}</option>)}
          </Field>
          <Field label="Bedrooms" value={draft.bedrooms} onChange={(v) => set('bedrooms', v)}>
            {BEDS.map((b) => <option key={b.v} value={b.v}>{b.l}</option>)}
          </Field>
          <Field label="Property type" value={draft.property_type} onChange={(v) => set('property_type', v)}>
            {TYPES.map((t) => <option key={t.v} value={t.v}>{t.l}</option>)}
          </Field>
          <label className="bz-field">
            <span className="bz-field-label">
              Verification
              <InfoHint text={help.verifiedRentals} label="About verification" />
            </span>
            <button type="button" className="bz-verif" onClick={() => set('verified_only', !draft.verified_only)}
              aria-pressed={draft.verified_only}>
              <span className={`bz-cb${draft.verified_only ? ' on' : ''}`} aria-hidden="true"><Check size={13} strokeWidth={3} /></span>
              Verified only
            </button>
          </label>
        </div>

        <div className="bz-filter-actions">
          <button className="bz-btn-ghost" onClick={clear}>Clear filters</button>
          <span className="bz-spacer" />
          <div className="bz-sort">
            <span className="bz-sort-label">Sort by</span>
            <span className="bz-select">
              <select value={sort} onChange={(e) => setSort(e.target.value)}>
                {SORTS.map((s) => <option key={s.v} value={s.v}>{s.l}</option>)}
              </select>
              <ChevronDown size={16} className="bz-select-chev" />
            </span>
          </div>
          <button className="bz-btn-primary" onClick={apply}>Apply filters</button>
        </div>
      </div>

      {/* results toolbar */}
      <div className="bz-results">
        <div className="bz-results-info">
          <span className="bz-results-ico" aria-hidden="true"><SlidersHorizontal size={18} /></span>
          <div>
            {loading ? (
              <>
                <div className="bz-results-count"><Skeleton className="h-4 w-32 inline-block" /></div>
                <p className="bz-results-where">{applied.city || 'Ghana'} and nearby</p>
              </>
            ) : (
              <>
                <p className="bz-results-count">Showing {listings.length} verified home{listings.length === 1 ? '' : 's'}</p>
                <p className="bz-results-where">{applied.city || 'Ghana'} and nearby</p>
              </>
            )}
          </div>
        </div>
        <div className="bz-views" role="group" aria-label="View mode">
          <button className={`bz-view-btn${view === 'grid' ? ' on' : ''}`} onClick={() => setView('grid')}
            aria-pressed={view === 'grid'}>
            <LayoutGrid size={16} /> Grid
          </button>
          <button className={`bz-view-btn${view === 'list' ? ' on' : ''}`} onClick={() => setView('list')}
            aria-pressed={view === 'list'}>
            <ListIcon size={16} /> List
          </button>
          <button className="bz-view-btn" disabled aria-disabled="true">
            <MapIcon size={16} /> Map <span className="bz-coming">Coming soon</span>
          </button>
        </div>
      </div>

      {/* content */}
      {loading ? (
        <div className={`bz-grid${view === 'list' ? ' is-list' : ''}`}>
          {Array.from({ length: 6 }, (_, i) => <ListingSkeleton key={i} />)}
        </div>
      ) : error ? (
        <ErrorState
          title="Couldn't load listings"
          message="Something went wrong. Please try again."
          onRetry={reload}
        />
      ) : listings.length === 0 ? (
        hasActiveFilters ? (
          <EmptyState
            icon={<Search size={28} />}
            title="No homes match your search"
            description="Try widening your filters or clearing the search."
            action={
              <button className="bz-btn-ghost" onClick={clear}>Clear filters</button>
            }
          />
        ) : (
          <EmptyState
            icon={<Building2 size={28} />}
            title="No homes are listed yet"
            description="There are no published listings on Wyncrest right now. New homes will appear here as landlords publish them."
          />
        )
      ) : (
        <>
          <div className={`bz-grid${view === 'list' ? ' is-list' : ''}`}>
            {listings.map((l) => (
              <PropertyCard key={l.id} listing={l} saved={savedMap.get(l.id) ?? false} onToggle={onToggleSave} />
            ))}
          </div>

          {lastPage > 1 && (
            <div className="bz-pager">
              <button className="bz-btn-ghost" disabled={page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>
                <ChevronLeft size={15} /> Previous
              </button>
              <span>Page {data?.current_page ?? 1} of {lastPage}</span>
              <button className="bz-btn-ghost" disabled={page >= lastPage} onClick={() => setPage((p) => p + 1)}>
                Next <ChevronRight size={15} />
              </button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
