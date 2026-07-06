import { useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router';
import {
  Search, Bookmark, ArrowLeftRight, Trash2, ChevronDown, ChevronRight,
  LayoutGrid, List as ListIcon, CircleCheck, Clock, Heart, MapPin,
  BedDouble, Bath, Maximize2, Building2, ShieldCheck, ArrowRight, X, Check, Scale,
} from 'lucide-react';
import { useApi } from '@/hooks/useApi';
import { tenantApi } from '@/lib/endpoints';
import { formatCedisDecimal } from '@/lib/format';
import { SemanticBadge } from '@/components/cards';
import { ErrorState, EmptyState, Skeleton } from '@/components/ui/states';
import type { Listing } from '@/lib/types';
import './saved-homes.css';

import bannerImg from '@/assets/dashboard/home-9.png';
import fb1 from '@/assets/dashboard/home-2.jpg';
import fb2 from '@/assets/dashboard/home-5.jpg';
import fb3 from '@/assets/dashboard/home-7.jpg';
const FALLBACKS = [fb1, fb2, fb3];

const TYPE_LABEL: Record<string, string> = {
  single_family: 'House', multi_family: 'Multi-family', apartment: 'Apartment',
  condo: 'Condo', townhouse: 'Townhouse', duplex: 'Duplex', studio: 'Studio',
};
const typeLabel = (t?: string) => (t ? TYPE_LABEL[t] ?? t.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) : 'Home');
const beds = (l: Listing) => (l.unit?.bedrooms ? parseInt(l.unit.bedrooms, 10) : 0);
const imgFor = (l: Listing, i: number) =>
  l.primary_photo?.path ? `${import.meta.env.VITE_API_URL ?? ''}/storage/${l.primary_photo.path}` : FALLBACKS[i % FALLBACKS.length];
const isReady = (l: Listing) => !l.unit?.available_from || new Date(l.unit.available_from).getTime() <= Date.now();
const describe = (l: Listing) => {
  const d = l.unit?.property?.description;
  if (d) return d;
  const t = typeLabel(l.unit?.property?.property_type).toLowerCase();
  const city = l.unit?.property?.city;
  return city ? `${beds(l) ? `${beds(l)}-bedroom ` : ''}${t} in ${city}.` : `${beds(l) ? `${beds(l)}-bedroom ` : ''}${t}.`;
};

const SORTS = [
  { v: 'price_asc', l: 'Price: Low to high' },
  { v: 'price_desc', l: 'Price: High to low' },
  { v: 'beds', l: 'Most bedrooms' },
];

type Tab = 'all' | 'ready' | 'recent';

/* ---- skeleton card ------------------------------------------------------- */
function SavedCardSkeleton() {
  return (
    <div className="sv-sk-card">
      <div className="sv-sk-img"><Skeleton className="h-full w-full" /></div>
      <div className="sv-sk-body">
        <Skeleton className="h-4 w-28 mb-3" />
        <Skeleton className="h-6 w-3/4 mb-2" />
        <Skeleton className="h-4 w-1/2 mb-4" />
        <div style={{ display: 'flex', gap: 14 }}>
          <Skeleton className="h-4 w-14" />
          <Skeleton className="h-4 w-14" />
          <Skeleton className="h-4 w-18" />
        </div>
      </div>
    </div>
  );
}

/* ---- card ---------------------------------------------------------------- */
function SavedCard({ listing, index, selected, onSelect, onRemove }: {
  listing: Listing; index: number; selected: boolean;
  onSelect: (id: number) => void; onRemove: (id: number) => void;
}) {
  const unit = listing.unit;
  const prop = unit?.property;
  const ready = isReady(listing);
  const loc = prop ? `${prop.city}${prop.state ? `, ${prop.state}` : ''}` : '—';
  const rentDisplay = unit?.rent_amount ?? null;

  return (
    <article className="sv-card">
      <Link to={`/app/listing/${listing.id}`} className="sv-card-img">
        <img src={imgFor(listing, index)} alt={listing.title} loading="lazy"
          onError={(e) => { (e.currentTarget as HTMLImageElement).src = FALLBACKS[index % FALLBACKS.length]; }} />
        <span className="sv-verified"><ShieldCheck size={14} /> Verified</span>
        <span className="sv-heart" aria-hidden="true"><Heart size={16} fill="currentColor" /></span>
        {/* Availability badge — semantic: success = now, warning = soon */}
        <span className="sv-avail-wrap">
          <SemanticBadge role={ready ? 'success' : 'warning'} dot>
            {ready ? 'Available now' : 'Available soon'}
          </SemanticBadge>
        </span>
      </Link>
      <div className="sv-card-body">
        <button type="button" className="sv-compare" onClick={() => onSelect(listing.id)} aria-pressed={selected}>
          <span className={`sv-cb${selected ? ' on' : ''}`} aria-hidden="true"><Check size={12} strokeWidth={3} /></span>
          Select to compare
        </button>
        <h3 className="sv-card-name">
          <Link to={`/app/listing/${listing.id}`} style={{ color: 'inherit', textDecoration: 'none' }}>{listing.title}</Link>
        </h3>
        <p className="sv-card-loc"><MapPin size={14} /> {loc}</p>
        <div className="sv-specs">
          {beds(listing) > 0 && <span className="sv-spec"><BedDouble size={15} /> {beds(listing)} bed</span>}
          {unit?.bathrooms && <span className="sv-spec"><Bath size={15} /> {parseFloat(unit.bathrooms)} bath</span>}
          {unit?.square_feet && <span className="sv-spec"><Maximize2 size={15} /> {unit.square_feet.toLocaleString()} sqft</span>}
          <span className="sv-spec"><Building2 size={15} /> {typeLabel(prop?.property_type)}</span>
        </div>
        <p className="sv-desc">{describe(listing)}</p>
        <div className="sv-card-foot">
          <span className="sv-price">
            {rentDisplay != null ? formatCedisDecimal(rentDisplay) : '—'}
            <small> /mo</small>
          </span>
          <Link to={`/app/listing/${listing.id}`} className="sv-details">View details <ArrowRight size={15} /></Link>
        </div>
        <button type="button" className="sv-remove" onClick={() => onRemove(listing.id)}>
          <X size={14} /> Remove
        </button>
      </div>
    </article>
  );
}

/* ---- empty state (rich) -------------------------------------------------- */
const SAVE_BENEFITS = [
  { icon: Scale, title: 'Compare listings', desc: 'Line up your favorites to find the right fit.' },
  { icon: Bookmark, title: 'Track your favorites', desc: "Keep a shortlist of homes you don't want to lose." },
  { icon: ShieldCheck, title: 'Revisit with confidence', desc: 'All saved homes are verified and up to date.' },
];

/** Soft line-art house scene with a heart badge — the empty-state centerpiece. */
function SavedEmptyArt() {
  return (
    <div className="sv-empty-art" aria-hidden="true">
      <svg viewBox="0 0 320 190" fill="none" xmlns="http://www.w3.org/2000/svg">
        {/* halo blob behind the scene */}
        <ellipse cx="160" cy="96" rx="128" ry="84" className="sv-art-halo" />
        {/* sparkles */}
        <path className="sv-art-spark" d="M58 44l3 8 8 3-8 3-3 8-3-8-8-3 8-3 3-8z" />
        <path className="sv-art-spark" d="M268 56l2 6 6 2-6 2-2 6-2-6-6-2 6-2 2-6z" />
        <path className="sv-art-spark" d="M250 118l1.6 4.6 4.6 1.6-4.6 1.6-1.6 4.6-1.6-4.6-4.6-1.6 4.6-1.6 1.6-4.6z" />
        {/* tree */}
        <circle cx="78" cy="86" r="26" className="sv-art-line sv-art-fill" />
        <path className="sv-art-line" d="M78 112v26" />
        {/* back house */}
        <g className="sv-art-line">
          <path d="M186 150V92l30-22 30 22v58" className="sv-art-fill" />
          <path d="M178 96l38-28 38 28" />
          <rect x="208" y="108" width="16" height="16" rx="1.5" />
        </g>
        {/* front house */}
        <g className="sv-art-line">
          <path d="M104 152V86l40-30 40 30v66" className="sv-art-fill-strong" />
          <path d="M96 92l48-36 48 36" />
          <rect x="118" y="104" width="18" height="18" rx="2" />
          <rect x="152" y="104" width="18" height="18" rx="2" />
          <path d="M132 152v-20h24v20" />
        </g>
        {/* ground line */}
        <path className="sv-art-line" d="M40 152h240" />
      </svg>
      <span className="sv-empty-heart"><Heart size={30} fill="currentColor" strokeWidth={0} /></span>
    </div>
  );
}

function SavedEmpty() {
  return (
    <>
      <div className="sv-empty">
        <SavedEmptyArt />
        <h2 className="sv-empty-title">No saved homes yet</h2>
        <p className="sv-empty-desc">
          Save homes you love and they’ll appear here.<br />
          It’s the easiest way to keep track of your favorites.
        </p>
        <Link to="/app/browse" className="sv-btn-primary sv-empty-cta">
          <Search size={18} /> Browse homes
        </Link>

        <div className="sv-empty-rule"><span>Why save homes?</span></div>

        <ul className="sv-empty-benefits">
          {SAVE_BENEFITS.map(({ icon: Icon, title, desc }) => (
            <li key={title} className="sv-benefit">
              <span className="sv-benefit-ic"><Icon size={20} /></span>
              <div>
                <p className="sv-benefit-title">{title}</p>
                <p className="sv-benefit-desc">{desc}</p>
              </div>
            </li>
          ))}
        </ul>
      </div>

      <Link to="/app/verification" className="sv-empty-note">
        <ShieldCheck size={18} />
        <span>
          Only verified homes you’re allowed to apply for can be saved.{' '}
          <strong>Learn more</strong>
        </span>
        <ChevronRight size={18} className="sv-empty-note-chev" />
      </Link>
    </>
  );
}

/* ========================================================================== */
export function SavedListings() {
  const navigate = useNavigate();
  const { data, loading, error, reload } = useApi(() => tenantApi.savedListings(), []);
  const [removed, setRemoved] = useState<Set<number>>(new Set());
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [tab, setTab] = useState<Tab>('all');
  const [sort, setSort] = useState('price_asc');
  const [view, setView] = useState<'grid' | 'list'>('grid');

  const all = useMemo(() => (data ?? []).filter((l) => !removed.has(l.id)), [data, removed]);
  const readyCount = useMemo(() => all.filter(isReady).length, [all]);

  const listings = useMemo(() => {
    let arr = [...all];
    if (tab === 'ready') arr = arr.filter(isReady);
    if (tab === 'recent') arr = [...arr].sort((a, b) => b.id - a.id);
    if (tab !== 'recent') {
      switch (sort) {
        case 'price_asc': arr.sort((a, b) => parseFloat(a.unit?.rent_amount ?? '0') - parseFloat(b.unit?.rent_amount ?? '0')); break;
        case 'price_desc': arr.sort((a, b) => parseFloat(b.unit?.rent_amount ?? '0') - parseFloat(a.unit?.rent_amount ?? '0')); break;
        case 'beds': arr.sort((a, b) => beds(b) - beds(a)); break;
      }
    }
    return arr;
  }, [all, tab, sort]);

  async function remove(id: number) {
    setRemoved((p) => new Set(p).add(id));
    setSelected((p) => { const n = new Set(p); n.delete(id); return n; });
    try { await tenantApi.unsaveListing(id); } catch { setRemoved((p) => { const n = new Set(p); n.delete(id); return n; }); }
  }

  async function clearAll() {
    const ids = all.map((l) => l.id);
    setRemoved(new Set(ids));
    setSelected(new Set());
    await Promise.allSettled(ids.map((id) => tenantApi.unsaveListing(id)));
    reload();
  }

  function toggleSelect(id: number) {
    setSelected((p) => { const n = new Set(p); if (n.has(id)) n.delete(id); else n.add(id); return n; });
  }

  function compare() {
    const ids = [...selected];
    navigate(ids.length ? `/app/compare?ids=${ids.join(',')}` : '/app/compare');
  }

  return (
    <div className="sv-page">
      {/* header */}
      <div className="sv-header">
        <div className="sv-header-text">
          <p className="sv-eyebrow">Find a Home</p>
          <h1 className="sv-title">Saved Homes</h1>
          <p className="sv-sub">Homes you've bookmarked to compare, revisit, and apply when you're ready.</p>
        </div>
        <Link to="/app/browse" className="sv-btn-primary"><Search size={16} /> Browse homes</Link>
      </div>

      {loading ? (
        <div className="sv-grid">
          {Array.from({ length: 3 }, (_, i) => <SavedCardSkeleton key={i} />)}
        </div>
      ) : error ? (
        <ErrorState
          title="Couldn't load your saved homes"
          message="Please try again."
          onRetry={reload}
        />
      ) : all.length === 0 ? (
        <SavedEmpty />
      ) : (
        <>
          {/* summary banner */}
          <div className="sv-banner">
            <div className="sv-banner-img"><img src={bannerImg} alt="" /></div>
            <div className="sv-banner-main">
              <p className="sv-banner-count"><Bookmark size={20} /> {all.length} saved home{all.length === 1 ? '' : 's'}</p>
              <p className="sv-banner-desc">Select homes to compare or remove homes you no longer want.</p>
            </div>
            <div className="sv-banner-divider" />
            <div className="sv-banner-actions">
              <button className="sv-btn-primary" onClick={compare} disabled={selected.size === 0}
                aria-disabled={selected.size === 0}>
                <ArrowLeftRight size={16} /> Compare selected ({selected.size})
              </button>
              <button className="sv-btn-ghost danger" onClick={clearAll}><Trash2 size={15} /> Clear all</button>
            </div>
          </div>

          {/* tabs + sort + view */}
          <div className="sv-toolbar" role="group" aria-label="Filter saved homes">
            <button className={`sv-tab${tab === 'all' ? ' on' : ''}`} onClick={() => setTab('all')}
              aria-pressed={tab === 'all'}>
              <LayoutGrid size={15} /> All saved ({all.length})
            </button>
            <button className={`sv-tab${tab === 'ready' ? ' on' : ''}`} onClick={() => setTab('ready')}
              aria-pressed={tab === 'ready'}>
              <CircleCheck size={15} /> Ready to apply ({readyCount})
            </button>
            <button className={`sv-tab${tab === 'recent' ? ' on' : ''}`} onClick={() => setTab('recent')}
              aria-pressed={tab === 'recent'}>
              <Clock size={15} /> Recently saved
            </button>
            <span className="sv-select">
              <select value={sort} onChange={(e) => setSort(e.target.value)} disabled={tab === 'recent'}
                aria-label="Sort saved homes">
                {SORTS.map((s) => <option key={s.v} value={s.v}>{s.l}</option>)}
              </select>
              <ChevronDown size={16} className="sv-select-chev" />
            </span>
            <div className="sv-views" role="group" aria-label="View mode">
              <button className={`sv-view-btn${view === 'grid' ? ' on' : ''}`} onClick={() => setView('grid')}
                aria-label="Grid view" aria-pressed={view === 'grid'}><LayoutGrid size={16} /></button>
              <button className={`sv-view-btn${view === 'list' ? ' on' : ''}`} onClick={() => setView('list')}
                aria-label="List view" aria-pressed={view === 'list'}><ListIcon size={16} /></button>
            </div>
          </div>

          {/* cards */}
          {listings.length === 0 ? (
            <EmptyState
              title="Nothing here yet"
              description="No saved homes match this filter."
            />
          ) : (
            <div className={`sv-grid${view === 'list' ? ' is-list' : ''}`}>
              {listings.map((l, i) => (
                <SavedCard key={l.id} listing={l} index={i} selected={selected.has(l.id)} onSelect={toggleSelect} onRemove={remove} />
              ))}
            </div>
          )}
        </>
      )}
    </div>
  );
}
