import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { landlordApi } from '@/lib/endpoints';
import type { Property, PropertyType } from '@/lib/types';
import { humanize } from '@/lib/format';
import { ErrorState, LoadingState } from '@/components/ui/states';
import { InfoHint } from '@/components/ui/InfoHint';
import { help } from '@/lib/helpText';
import { PROPERTY_TYPES } from './property-constants';
import { IconBuilding, IconPlus, IconSearch, IconWarn, CoverGlyph } from './properties-ui';
import { gradientFor, propertyStatus } from './properties-helpers';
import './properties.css';

/* ──────────────────────────────────────────────────────────────────────────
   FILTERS
────────────────────────────────────────────────────────────────────────── */

type StatusFilter = '' | 'active' | 'inactive';
type ListingFilter = '' | 'active' | 'pending' | 'rejected' | 'none';
type OccupancyFilter = '' | 'occupied' | 'vacant' | 'partial';
type TypeFilter = '' | PropertyType;
type SortKey = 'recent' | 'alpha' | 'units' | 'occupancy' | 'attention';

interface Controls {
  q: string;
  status: StatusFilter;
  listing: ListingFilter;
  occupancy: OccupancyFilter;
  type: TypeFilter;
  sort: SortKey;
}

const DEFAULT_CONTROLS: Controls = {
  q: '',
  status: '',
  listing: '',
  occupancy: '',
  type: '',
  sort: 'recent',
};

/* Small helpers over the aggregate fields the index returns. */
const total = (p: Property) => p.units_count ?? 0;
const occ = (p: Property) => p.occupied_units ?? 0;
const listed = (p: Property) => p.listed_units ?? 0;
const pending = (p: Property) => p.pending_units ?? 0;
const rejected = (p: Property) => p.rejected_units ?? 0;
const attentionCount = (p: Property) => p.attention?.length ?? 0;

/* ──────────────────────────────────────────────────────────────────────────
   COMPONENT
────────────────────────────────────────────────────────────────────────── */

export function Properties() {
  const navigate = useNavigate();
  const { data, loading, error, reload } = useApi(() => landlordApi.properties(), []);
  const [c, setC] = useState<Controls>(DEFAULT_CONTROLS);

  const properties = useMemo(() => data ?? [], [data]);

  /* Portfolio totals across all properties. */
  const totals = useMemo(() => {
    return properties.reduce(
      (acc, p) => {
        acc.props += 1;
        acc.units += total(p);
        acc.listed += listed(p);
        acc.occ += occ(p);
        acc.pending += pending(p);
        if (attentionCount(p) > 0) acc.att += 1;
        return acc;
      },
      { props: 0, units: 0, listed: 0, occ: 0, pending: 0, att: 0 },
    );
  }, [properties]);

  /* Filter + sort. */
  const rows = useMemo(() => {
    const q = c.q.trim().toLowerCase();

    let result = properties.filter((p) => {
      if (q) {
        const hay = [p.name, p.street_address, p.city, p.state, humanize(p.property_type)]
          .join(' ')
          .toLowerCase();
        if (!hay.includes(q)) return false;
      }
      if (c.status === 'active' && !p.is_active) return false;
      if (c.status === 'inactive' && p.is_active) return false;
      if (c.type && p.property_type !== c.type) return false;

      if (c.occupancy) {
        const o = occ(p);
        const t = total(p);
        if (c.occupancy === 'occupied' && !(t > 0 && o === t)) return false;
        if (c.occupancy === 'vacant' && o !== 0) return false;
        if (c.occupancy === 'partial' && !(o > 0 && o < t)) return false;
      }

      if (c.listing) {
        if (c.listing === 'active' && listed(p) === 0) return false;
        if (c.listing === 'pending' && pending(p) === 0) return false;
        if (c.listing === 'rejected' && rejected(p) === 0) return false;
        if (c.listing === 'none' && listed(p) + pending(p) > 0) return false;
      }
      return true;
    });

    result = [...result].sort((a, b) => {
      switch (c.sort) {
        case 'alpha':
          return a.name.localeCompare(b.name);
        case 'units':
          return total(b) - total(a);
        case 'occupancy':
          return (b.occupancy_rate ?? 0) - (a.occupancy_rate ?? 0);
        case 'attention':
          return attentionCount(b) - attentionCount(a);
        case 'recent':
        default:
          return new Date(b.created_at).getTime() - new Date(a.created_at).getTime();
      }
    });

    return result;
  }, [properties, c]);

  const set = <K extends keyof Controls>(key: K, value: Controls[K]) =>
    setC((prev) => ({ ...prev, [key]: value }));

  if (loading) {
    return (
      <div className="wprop">
        <LoadingState label="Loading properties…" />
      </div>
    );
  }
  if (error) {
    return (
      <div className="wprop">
        <ErrorState message={error.message} onRetry={reload} />
      </div>
    );
  }

  const noneAtAll = properties.length === 0;

  return (
    <div className="wprop animate-rise">
      {/* Page head */}
      <section className="glass pagehead">
        <div>
          <span className="ph-eyebrow">Portfolio</span>
          <h1 className="ph-title">
            Your <b>properties.</b>
          </h1>
          <p className="ph-sub">
            Manage the buildings, homes, and units in your rental portfolio, and publish them as
            listings for tenants.
          </p>
        </div>
        <button className="btn btn-dark" onClick={() => navigate('/app/properties/new')}>
          <IconPlus /> Add property
        </button>
      </section>

      {/* Summary cards */}
      <div className="sumcards">
        <SummaryCard label="Total properties" dot="var(--wp-petrol-2)" value={totals.props} sub={`${totals.units} total units`} />
        <SummaryCard label="Total units" dot="var(--wp-slate)" value={totals.units} sub="across portfolio" />
        <SummaryCard label="Listed units" dot="var(--wp-green)" value={totals.listed} sub="available to tenants" help={help.listingActive} />
        <SummaryCard label="Occupied" dot="var(--wp-green)" value={totals.occ} sub="active contracts" cls="occ" help={help.occupancy} />
        <SummaryCard label="Pending review" dot="var(--wp-amber)" value={totals.pending} sub="awaiting admin" />
        <SummaryCard label="Needs attention" dot="var(--wp-amber)" value={totals.att} sub="properties to review" cls="att" help={help.needsAttention} />
      </div>

      {/* Toolbar */}
      {!noneAtAll && (
        <section className="glass">
          <div className="toolbar">
            <div className="search">
              <IconSearch />
              <input
                placeholder="Search properties, units, or addresses…"
                value={c.q}
                onChange={(e) => set('q', e.target.value)}
                aria-label="Search properties"
              />
            </div>
            <FilterSelect value={c.status} onChange={(v) => set('status', v as StatusFilter)} label="Property status"
              opts={[['', 'All statuses'], ['active', 'Active'], ['inactive', 'Inactive']]} />
            <FilterSelect value={c.listing} onChange={(v) => set('listing', v as ListingFilter)} label="Listing"
              opts={[['', 'Any listing'], ['active', 'Active'], ['pending', 'Pending review'], ['rejected', 'Rejected'], ['none', 'Not listed']]} />
            <FilterSelect value={c.occupancy} onChange={(v) => set('occupancy', v as OccupancyFilter)} label="Occupancy"
              opts={[['', 'Any occupancy'], ['occupied', 'Fully occupied'], ['vacant', 'Vacant'], ['partial', 'Partially occupied']]} />
            <FilterSelect value={c.type} onChange={(v) => set('type', v as TypeFilter)} label="Type"
              opts={[['', 'All types'], ...PROPERTY_TYPES.map((t) => [t, humanize(t)] as [string, string])]} />
            <FilterSelect value={c.sort} onChange={(v) => set('sort', v as SortKey)} label="Sort"
              opts={[['recent', 'Recently added'], ['alpha', 'Alphabetical'], ['units', 'Most units'], ['occupancy', 'Highest occupancy'], ['attention', 'Needs attention first']]} />
          </div>
        </section>
      )}

      {/* Grid / empty states */}
      {noneAtAll ? (
        <section className="glass">
          <div className="empty">
            <div className="ic"><IconBuilding /></div>
            <span className="et">No properties yet</span>
            <p>
              Add your first property to start building your rental portfolio. You can add photos,
              create units, publish listings, and manage tenants all from one place.
            </p>
            <button className="btn btn-dark" onClick={() => navigate('/app/properties/new')}>
              <IconPlus /> Add your first property
            </button>
            <div className="helper">
              A property can be a house, apartment building, duplex, or any rental space you own.
            </div>
          </div>
        </section>
      ) : rows.length === 0 ? (
        <section className="glass">
          <div className="empty">
            <div className="ic"><IconSearch /></div>
            <span className="et">No matches</span>
            <p>No properties match your search or filters. Try clearing them.</p>
            <button className="btn" onClick={() => setC(DEFAULT_CONTROLS)}>Clear filters</button>
          </div>
        </section>
      ) : (
        <div className="pgrid">
          {rows.map((p) => (
            <PropertyCard key={p.id} p={p} onOpen={() => navigate(`/app/properties/${p.id}`)} />
          ))}
        </div>
      )}
    </div>
  );
}

/* ──────────────────────────────────────────────────────────────────────────
   SUBCOMPONENTS
────────────────────────────────────────────────────────────────────────── */

function SummaryCard({ label, dot, value, sub, cls, help: helpText }: { label: string; dot: string; value: number; sub: string; cls?: string; help?: string }) {
  return (
    <div className={`scard glass ${cls ?? ''}`}>
      <div className="sl">
        <i style={{ background: dot }} />
        {label}
        {helpText && <InfoHint text={helpText} label={`About ${label}`} />}
      </div>
      <div className="sv">{value}</div>
      <div className="ss">{sub}</div>
    </div>
  );
}

function FilterSelect({ value, onChange, label, opts }: {
  value: string;
  onChange: (v: string) => void;
  label: string;
  opts: [string, string][];
}) {
  return (
    <select className="sel" aria-label={label} value={value} onChange={(e) => onChange(e.target.value)}>
      {opts.map(([v, l]) => (
        <option key={v} value={v}>{l}</option>
      ))}
    </select>
  );
}

function PropertyCard({ p, onOpen }: { p: Property; onOpen: () => void }) {
  const status = propertyStatus(p.is_active);
  const att = p.attention?.[0];
  const cover = p.cover_url ?? null;

  return (
    <div className="pcard glass" onClick={onOpen} role="button" tabIndex={0}
      onKeyDown={(e) => { if (e.key === 'Enter') onOpen(); }}>
      {cover ? (
        <div className="cover" style={{ background: gradientFor(p.id) }}>
          <img src={cover} alt={p.name} />
          <span className="ptype">{humanize(p.property_type)}</span>
          <span className={`statuspill ${status.cls}`}><span className="sd" />{status.label}</span>
        </div>
      ) : (
        <div className="cover missing">
          <CoverGlyph />
          <div className="cvtext">No cover photo</div>
          <span className="ptype">{humanize(p.property_type)}</span>
          <span className={`statuspill ${status.cls}`}><span className="sd" />{status.label}</span>
        </div>
      )}

      <div className="pbody">
        <div className="pname">{p.name}</div>
        <div className="paddr">{p.city}{p.state ? `, ${p.state}` : ''}</div>

        <div className="pstats">
          <div className="pstat"><div className="n">{total(p)}</div><div className="l">Units</div></div>
          <div className="pstat"><div className="n occ">{occ(p)}</div><div className="l">Occupied</div></div>
          <div className="pstat"><div className="n">{listed(p)}</div><div className="l">Listed</div></div>
          <div className="pstat"><div className="n pend">{pending(p)}</div><div className="l">Pending</div></div>
        </div>

        {att && (
          <div className={`att-badge ${att.level === 'red' ? 'red' : ''}`}>
            <IconWarn />{att.message}
          </div>
        )}

        <div className="pfoot">
          <span className="upd">Updated {relative(p.updated_at)}</span>
          <button className="btn btn-sm btn-petrol" onClick={(e) => { e.stopPropagation(); onOpen(); }}>
            View details
          </button>
        </div>
      </div>
    </div>
  );
}

/** Compact relative time for "Updated X". */
function relative(iso: string | null | undefined): string {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '—';
  const mins = Math.floor((Date.now() - d.getTime()) / 60_000);
  if (mins < 1) return 'just now';
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  const days = Math.floor(hrs / 24);
  if (days < 30) return `${days}d ago`;
  return d.toLocaleDateString('en-GH', { day: 'numeric', month: 'short', year: 'numeric' });
}
