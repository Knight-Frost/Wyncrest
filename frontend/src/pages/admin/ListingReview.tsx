import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { adminApi } from '@/lib/endpoints';
import { formatCedisDecimal, timeAgo } from '@/lib/format';
import { EmptyState, ErrorState, Skeleton } from '@/components/ui/states';
import type { ListingReviewSummary, ListingReviewFlags } from '@/lib/types';
import './listing-review.css';
import {
  WIconSearch,
  WIconExport,
  WIconGuide,
  WIconRefresh,
  WIconChevron,
  WIconPhotos,
  WIconBed,
  WIconBath,
  WIconArea,
  WIconCheck,
} from './wlrIcons';

/* ── Filters ──────────────────────────────────────────────────────────────── */

type StatusTab = 'pending' | 'approved' | 'rejected' | 'all';
type SignalKey = 'all' | 'flagged' | 'clean' | keyof ListingReviewFlags;
type SortKey = 'newest' | 'oldest' | 'attention' | 'rent_high' | 'rent_low';

const STATUS_TABS: { key: StatusTab; label: string }[] = [
  { key: 'pending', label: 'Pending' },
  { key: 'approved', label: 'Approved' },
  { key: 'rejected', label: 'Rejected' },
  { key: 'all', label: 'All' },
];

const SIGNAL_CHIPS: { key: SignalKey; label: string }[] = [
  { key: 'all', label: 'All' },
  { key: 'few_photos', label: 'Few photos' },
  { key: 'duplicate', label: 'Duplicate' },
  { key: 'contact_info', label: 'Contact info' },
  { key: 'unverified_host', label: 'Unverified host' },
  { key: 'policy', label: 'Policy' },
];

const SORTS: { key: SortKey; label: string }[] = [
  { key: 'newest', label: 'Newest first' },
  { key: 'oldest', label: 'Oldest first' },
  { key: 'attention', label: 'Needs attention first' },
  { key: 'rent_high', label: 'Highest rent' },
  { key: 'rent_low', label: 'Lowest rent' },
];

/** Row risk: a hard fail is high, any warning is medium, otherwise clean. */
function riskOf(row: ListingReviewSummary): 'high' | 'med' | 'clean' {
  if (row.missing_count > 0) return 'high';
  if (row.warning_count > 0) return 'med';
  return 'clean';
}
const RISK_COLOR: Record<string, string> = {
  high: 'var(--oxblood)',
  med: 'var(--amber)',
  clean: 'var(--green)',
};

function matchSignal(row: ListingReviewSummary, signal: SignalKey): boolean {
  if (signal === 'all') return true;
  if (signal === 'flagged') return row.warning_count > 0;
  if (signal === 'clean') return row.warning_count === 0 && row.missing_count === 0;
  return Boolean(row.flags[signal]);
}

/* ── Signal chips shown on a card ─────────────────────────────────────────── */

function cardSignals(row: ListingReviewSummary) {
  const sigs: { level: 'high' | 'med' | 'info' | 'clean'; label: string }[] = [];
  if (row.flags.duplicate) sigs.push({ level: 'high', label: 'Possible duplicate' });
  if (row.flags.unverified_host) sigs.push({ level: 'high', label: 'Unverified host' });
  if (row.flags.few_photos) sigs.push({ level: 'med', label: 'Few photos' });
  if (row.flags.contact_info) sigs.push({ level: 'med', label: 'Contact info' });
  if (row.flags.policy) sigs.push({ level: 'med', label: 'Policy language' });
  if (sigs.length === 0) {
    if (row.warning_count > 0) {
      sigs.push({ level: 'info', label: `${row.warning_count} to review` });
    } else {
      sigs.push({ level: 'clean', label: 'All checks passed' });
    }
  }
  return sigs;
}

/* ── Queue card ───────────────────────────────────────────────────────────── */

function QueueCard({ row, onOpen }: { row: ListingReviewSummary; onOpen: () => void }) {
  const rent = row.unit ? formatCedisDecimal(row.unit.rent_amount) : null;
  const sigs = cardSignals(row);
  const initials = row.landlord.name
    .split(' ')
    .map((p) => p[0])
    .slice(0, 2)
    .join('')
    .toUpperCase();

  return (
    <div className="lcard" style={{ '--risk': RISK_COLOR[riskOf(row)] } as React.CSSProperties}>
      <div
        className="lc-main"
        role="button"
        tabIndex={0}
        onClick={onOpen}
        onKeyDown={(e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            onOpen();
          }
        }}
        aria-label={`Open full review for ${row.title}`}
      >
        <div className="lc-photo">
          {row.cover_photo ? (
            <img src={row.cover_photo} alt="" loading="lazy" />
          ) : (
            <span className="ph-empty">
              <WIconPhotos />
            </span>
          )}
          <span className={`pcount${row.photo_count < 3 ? ' low' : ''}`}>
            <WIconPhotos />
            {row.photo_count} {row.photo_count === 1 ? 'photo' : 'photos'}
          </span>
        </div>

        <div className="lc-body">
          <div className="lc-top">
            <div>
              <div className="lc-title">{row.title}</div>
              <div className="lc-meta">
                {[row.property_name, row.location].filter(Boolean).join(' · ') || 'Location pending'}
              </div>
            </div>
            {rent && (
              <div className="lc-rent">
                <div className="r">
                  {rent}
                  <small>/mo</small>
                </div>
                <div className="lc-meta">{row.completeness.percent}% complete</div>
              </div>
            )}
          </div>

          {row.unit && (
            <div className="lc-facts">
              <span className="f">
                <WIconBed />
                {Number(row.unit.bedrooms) > 0 ? `${row.unit.bedrooms} bed` : 'Studio'}
              </span>
              <span className="f">
                <WIconBath />
                {row.unit.bathrooms} bath
              </span>
              <span className="f">
                <WIconArea />
                Unit {row.unit.unit_number}
              </span>
            </div>
          )}

          <div className="lc-host">
            <span className="ha">{initials || '—'}</span>
            {row.landlord.name}
            <span className={`hv ${row.landlord.identity_verified ? 'ok' : 'no'}`}>
              {row.landlord.identity_verified ? 'Verified host' : 'Unverified'}
            </span>
            <span style={{ marginLeft: 'auto', fontFamily: 'var(--mono)', fontSize: '.62rem' }}>
              {timeAgo(row.submitted_at)}
            </span>
          </div>

          <div className="sigs">
            {sigs.map((s, i) => (
              <span key={i} className={`sig ${s.level}`}>
                <span className="sd" />
                {s.label}
              </span>
            ))}
          </div>

          {row.status === 'rejected' && row.rejection_reason && (
            <div className="warnrow blood" style={{ marginTop: '.2rem' }}>
              <span className="dl" style={{ color: 'inherit' }}>Rejected:</span> {row.rejection_reason}
            </div>
          )}

          <div className="lc-foot">
            <span className="spacer" />
            <button type="button" className="lc-open" onClick={onOpen}>
              Open full review
              <WIconChevron />
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

/* ── Stat filter card ─────────────────────────────────────────────────────── */

function StatCard({
  tone,
  selected,
  label,
  value,
  detail,
  dot,
  onClick,
}: {
  tone: 'alert' | 'good' | 'plain';
  selected: boolean;
  label: string;
  value: number;
  detail: string;
  dot: string;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      className={`stat glass ${tone === 'alert' ? 'alert' : tone === 'good' ? 'good' : ''} ${selected ? 'sel' : ''}`}
      onClick={onClick}
      aria-pressed={selected}
    >
      <div className="k">
        <i style={{ background: dot }} />
        {label}
      </div>
      <div className="v">{value}</div>
      <div className="d">{detail}</div>
    </button>
  );
}

/* ── Page ─────────────────────────────────────────────────────────────────── */

export function ListingReview() {
  const navigate = useNavigate();
  const [status, setStatus] = useState<StatusTab>('pending');
  const [signal, setSignal] = useState<SignalKey>('all');
  const [sort, setSort] = useState<SortKey>('newest');
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [guideOpen, setGuideOpen] = useState(false);

  useEffect(() => {
    const t = setTimeout(() => setSearch(searchInput.trim()), 300);
    return () => clearTimeout(t);
  }, [searchInput]);

  const { data, loading, error, reload } = useApi(
    () => adminApi.listingReviewQueue({ status, sort, search: search || undefined }),
    [status, sort, search],
  );

  const counts = data?.counts;
  const allRows = useMemo(() => data?.data ?? [], [data]);
  const rows = useMemo(() => allRows.filter((r) => matchSignal(r, signal)), [allRows, signal]);

  // "Ready to publish" = pending minus anything flagged. A hard fail always
  // surfaces as a warning, so needs_attention already subsumes missing_info.
  const readyCount = counts ? Math.max(0, counts.pending - counts.needs_attention) : 0;

  function pickStat(seg: 'awaiting' | 'flagged' | 'ready' | 'approved') {
    if (seg === 'awaiting') {
      setStatus('pending');
      setSignal('all');
    } else if (seg === 'flagged') {
      setStatus('pending');
      setSignal('flagged');
    } else if (seg === 'ready') {
      setStatus('pending');
      setSignal('clean');
    } else {
      setStatus('approved');
      setSignal('all');
    }
  }

  function exportCsv() {
    const header = ['id', 'title', 'location', 'landlord', 'verified', 'rent', 'photos', 'warnings', 'completeness'];
    const lines = allRows.map((r) =>
      [
        r.id,
        r.title,
        r.location ?? '',
        r.landlord.name,
        r.landlord.identity_verified,
        r.unit?.rent_amount ?? '',
        r.photo_count,
        r.warning_count,
        `${r.completeness.percent}%`,
      ]
        .map((c) => `"${String(c).replace(/"/g, '""')}"`)
        .join(','),
    );
    const csv = [header.join(','), ...lines].join('\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    a.download = 'wyncrest-listing-queue.csv';
    a.click();
    URL.revokeObjectURL(a.href);
  }

  return (
    <div className="wlr rise">
      {/* Page head */}
      <section className="pagehead glass">
        <div className="ph-top">
          <div>
            <span className="ph-eyebrow">Publishing gate</span>
            <h1 className="ph-title">
              Listing <span className="it">review.</span>
            </h1>
            <p className="ph-sub">
              Nothing goes live until a human signs off. Open any listing to see its full review case file —
              photos, checks, pricing and history — and decide.
            </p>
          </div>
          <div className="ph-controls">
            <button type="button" className="btn btn-glass" onClick={() => setGuideOpen((v) => !v)}>
              <WIconGuide />
              Guidelines
            </button>
            <button type="button" className="btn btn-glass" onClick={exportCsv} disabled={allRows.length === 0}>
              <WIconExport />
              Export
            </button>
            <button type="button" className="btn btn-glass" onClick={reload}>
              <WIconRefresh />
              Refresh
            </button>
          </div>
        </div>
      </section>

      {/* Stat filters */}
      <section className="stats">
        <StatCard
          tone="alert"
          selected={status === 'pending' && signal === 'all'}
          label="Awaiting review"
          value={counts?.pending ?? 0}
          detail="in the queue"
          dot="var(--oxblood)"
          onClick={() => pickStat('awaiting')}
        />
        <StatCard
          tone="plain"
          selected={status === 'pending' && signal === 'flagged'}
          label="Needs attention"
          value={counts?.needs_attention ?? 0}
          detail="pending with warnings"
          dot="var(--amber)"
          onClick={() => pickStat('flagged')}
        />
        <StatCard
          tone="good"
          selected={status === 'pending' && signal === 'clean'}
          label="Ready to publish"
          value={readyCount}
          detail="no issues found"
          dot="var(--green)"
          onClick={() => pickStat('ready')}
        />
        <StatCard
          tone="plain"
          selected={status === 'approved'}
          label="Approved"
          value={counts?.approved ?? 0}
          detail={`${counts?.approved_today ?? 0} today · live to tenants`}
          dot="var(--petrol-2)"
          onClick={() => pickStat('approved')}
        />
      </section>

      {/* Queue panel */}
      <section className="glass">
        <div className="panel-head">
          <div>
            <h2>Submission queue</h2>
            <div className="ph2-sub">
              {loading && !data
                ? 'Loading…'
                : `${rows.length} shown · ${counts?.all ?? 0} in review overall`}
            </div>
          </div>
        </div>

        <div className={`guide${guideOpen ? ' open' : ''}`}>
          <div className="guide-in">
            <div className="guide-pad">
              <b>Send back or reject when a listing has:</b>
              <ul>
                <li>Too few real photos, or photos that don&apos;t match the unit</li>
                <li>Contact details in the description (tenants book through the platform)</li>
                <li>Discriminatory or exclusionary language</li>
                <li>A rent far outside the area range, or a suspected duplicate</li>
                <li>An unverified host or missing ownership documents</li>
              </ul>
              <b style={{ display: 'block', marginTop: '.6rem' }}>Approve</b> only when accurate, complete and
              safe. Prefer <b>&ldquo;Request changes&rdquo;</b> for anything fixable — it returns the listing to
              the landlord without a rejection on record.
            </div>
          </div>
        </div>

        <div className="toolbar">
          <label className="search">
            <WIconSearch />
            <input
              type="search"
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              placeholder="Search title, host or city…"
              aria-label="Search listings"
            />
          </label>
          <select
            className="sel-input"
            value={sort}
            onChange={(e) => setSort(e.target.value as SortKey)}
            aria-label="Sort listings"
          >
            {SORTS.map((s) => (
              <option key={s.key} value={s.key}>
                {s.label}
              </option>
            ))}
          </select>
        </div>

        <div className="toolbar" style={{ paddingTop: 0 }}>
          <div className="chips" role="tablist" aria-label="Status">
            {STATUS_TABS.map((t) => (
              <button
                key={t.key}
                type="button"
                className={`chip${status === t.key ? ' on' : ''}`}
                onClick={() => {
                  setStatus(t.key);
                  setSignal('all');
                }}
              >
                {t.label}
                {counts && (
                  <span style={{ marginLeft: '.4em', fontFamily: 'var(--mono)', fontSize: '.9em', opacity: 0.7 }}>
                    {counts[t.key]}
                  </span>
                )}
              </button>
            ))}
          </div>
        </div>

        <div className="toolbar" style={{ paddingTop: 0 }}>
          <div className="chips" aria-label="Signal filters">
            {SIGNAL_CHIPS.map((c) => (
              <button
                key={c.key}
                type="button"
                className={`chip${signal === c.key ? ' on' : ''}`}
                onClick={() => setSignal(c.key)}
              >
                {c.label}
              </button>
            ))}
          </div>
        </div>

        {/* Queue */}
        {loading && !data ? (
          <div className="queue">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-48 w-full rounded-2xl" />
            ))}
          </div>
        ) : error ? (
          <div style={{ padding: '1.4rem' }}>
            <ErrorState message={error.message} onRetry={reload} />
          </div>
        ) : rows.length === 0 ? (
          status === 'pending' && signal === 'all' ? (
            <div className="empty">
              <WIconCheck />
              <span className="it">Queue clear.</span>
              Every submitted listing has been reviewed.
            </div>
          ) : (
            <div style={{ padding: '1.4rem' }}>
              <EmptyState
                title="No listings match this filter"
                description="Try a different status, signal or search term."
              />
            </div>
          )
        ) : (
          <div className="queue" role="list">
            {rows.map((row) => (
              <QueueCard key={row.id} row={row} onOpen={() => navigate(`/app/listing-review/${row.id}`)} />
            ))}
          </div>
        )}
      </section>
    </div>
  );
}

export default ListingReview;
