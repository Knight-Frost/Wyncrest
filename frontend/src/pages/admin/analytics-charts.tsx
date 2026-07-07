import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router';

/* ============================================================================
   Reusable, dependency-free SVG chart + table primitives for the "My
   Analytics" page (my-analytics.css / .wana). Colors are passed as CSS custom
   property references (e.g. "var(--petrol-2)") so every chart re-themes with
   light/dark mode and the accent system automatically — no literal hex here.
   ============================================================================ */

const PAL = {
  petrol: 'var(--petrol)',
  petrol2: 'var(--petrol-2)',
  ox: 'var(--oxblood)',
  green: 'var(--green)',
  amber: 'var(--amber)',
  slate: 'var(--slate)',
};
const STACK_COLORS = [PAL.petrol2, PAL.ox, PAL.green, PAL.amber, PAL.petrol, PAL.slate];

/* ---------- grouped bar chart (e.g. approved / rejected / sent-back per week) ---------- */
export function GroupedBarChart({
  data,
  keys,
  colors,
}: {
  data: Record<string, number | string>[];
  keys: string[];
  colors: string[];
}) {
  const W = 560;
  const H = 210;
  const pl = 40;
  const pr = 12;
  const pt = 14;
  const pb = 30;
  const iw = W - pl - pr;
  const ih = H - pt - pb;
  const max = Math.max(10, ...data.flatMap((d) => keys.map((k) => Number(d[k]) || 0)));
  const roundedMax = Math.ceil(max / 10) * 10;
  const gCount = Math.max(1, data.length);
  const gW = iw / gCount;
  const bw = Math.min(16, (gW - 14) / keys.length);
  const gap = 3;

  const gridLines = Array.from({ length: 5 }, (_, i) => {
    const y = pt + (ih * i) / 4;
    const v = roundedMax - (roundedMax * i) / 4;
    return { y, v };
  });

  return (
    <svg viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="xMidYMid meet" style={{ width: '100%', height: 'auto' }}>
      {gridLines.map((g, i) => (
        <g key={i}>
          <line className="gridline" x1={pl} y1={g.y} x2={W - pr} y2={g.y} />
          <text className="axis" x={pl - 8} y={g.y + 3} textAnchor="end">
            {Math.round(g.v)}
          </text>
        </g>
      ))}
      {data.map((d, gi) => {
        const gx = pl + gW * gi + gW / 2;
        const totalW = keys.length * bw + (keys.length - 1) * gap;
        const sx = gx - totalW / 2;
        return (
          <g key={gi}>
            {keys.map((k, ki) => {
              const val = Number(d[k]) || 0;
              const h = (ih * val) / roundedMax;
              const x = sx + ki * (bw + gap);
              const y = pt + ih - h;
              return <rect key={k} x={x} y={y} width={bw} height={h} rx={2.5} fill={colors[ki % colors.length]} />;
            })}
            <text className="axis" x={gx} y={H - 9} textAnchor="middle">
              {String(d.label ?? '')}
            </text>
          </g>
        );
      })}
    </svg>
  );
}

/* ---------- donut chart ---------- */
export function DonutChart({ segments, label }: { segments: { label: string; value: number }[]; label: string }) {
  const total = segments.reduce((s, x) => s + x.value, 0);
  if (total === 0) {
    return <div style={{ color: 'var(--muted)', fontSize: '13px' }}>No data yet.</div>;
  }
  const safeTotal = total; // guaranteed non-zero past the guard above
  const cx = 70;
  const cy = 70;
  const r = 54;
  const sw = 20;
  const C = 2 * Math.PI * r;
  const arcs = segments.reduce<{ len: number; offset: number }[]>((acc, s) => {
    const prevEnd = acc.length > 0 ? acc[acc.length - 1].offset + acc[acc.length - 1].len : 0;
    acc.push({ len: (s.value / safeTotal) * C, offset: prevEnd });
    return acc;
  }, []);

  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: '20px', flexWrap: 'wrap' }}>
      <svg viewBox="0 0 140 140" style={{ width: '130px', height: '130px', flex: 'none' }}>
        {segments.map((s, i) => (
          <circle
            key={s.label}
            cx={cx}
            cy={cy}
            r={r}
            fill="none"
            stroke={STACK_COLORS[i % STACK_COLORS.length]}
            strokeWidth={sw}
            strokeDasharray={`${arcs[i].len.toFixed(2)} ${(C - arcs[i].len).toFixed(2)}`}
            strokeDashoffset={(-arcs[i].offset).toFixed(2)}
            transform={`rotate(-90 ${cx} ${cy})`}
          />
        ))}
        <text x="70" y="66" textAnchor="middle" style={{ fontFamily: 'var(--disp)', fontWeight: 700, fontSize: '28px', fill: 'var(--ink)' }}>
          {total}
        </text>
        <text x="70" y="84" textAnchor="middle" style={{ fontSize: '10px', fill: 'var(--muted)', letterSpacing: '0.08em' }}>
          {label}
        </text>
      </svg>
      <div className="legend" style={{ flexDirection: 'column', gap: '8px', margin: 0 }}>
        {segments.map((s, i) => (
          <span key={s.label}>
            <i style={{ background: STACK_COLORS[i % STACK_COLORS.length] }} /> {s.label} <b style={{ color: 'var(--ink)' }}>{s.value}</b>
          </span>
        ))}
      </div>
    </div>
  );
}

/* ---------- horizontal reason bars ---------- */
export function ReasonBars({ items }: { items: { reason: string; count: number }[] }) {
  const max = Math.max(1, ...items.map((x) => x.count));
  return (
    <div className="hb">
      {items.map((x) => (
        <div className="row" key={x.reason}>
          <div className="lb" title={x.reason}>
            {x.reason}
          </div>
          <div className="track">
            <div className="fill" style={{ width: `${(x.count / max) * 100}%` }} />
          </div>
          <div className="val">{x.count}</div>
        </div>
      ))}
    </div>
  );
}

/* ---------- stacked single-track split bar (pending by module, etc.) ---------- */
export function StackedSplitBar({ items }: { items: { label: string; value: number }[] }) {
  const filtered = items.filter((x) => x.value > 0);
  const total = filtered.reduce((s, x) => s + x.value, 0);
  if (total === 0) return null;
  return (
    <div>
      <div style={{ display: 'flex', height: '16px', borderRadius: '8px', overflow: 'hidden', gap: '2px' }}>
        {filtered.map((x, i) => (
          <div key={x.label} style={{ width: `${(x.value / total) * 100}%`, background: STACK_COLORS[i % STACK_COLORS.length] }} title={x.label} />
        ))}
      </div>
      <div className="legend">
        {filtered.map((x, i) => (
          <span key={x.label}>
            <i style={{ background: STACK_COLORS[i % STACK_COLORS.length] }} /> {x.label} <b style={{ color: 'var(--ink)' }}>{x.value}</b>
          </span>
        ))}
      </div>
    </div>
  );
}

/* ---------- generic filterable / searchable table, click-through rows ---------- */
export interface TableColumn<T> {
  key: string;
  label: string;
  num?: boolean;
  render: (row: T) => React.ReactNode;
  cellClassName?: (row: T) => string;
}
export interface TableFilter<T> {
  key: string;
  label: string;
  test?: (row: T) => boolean;
}

export function FilterableTable<T extends { id: string | number }>({
  title,
  rows,
  columns,
  filters,
  searchKeys,
  searchPlaceholder,
  rowLink,
  actionLabel = 'Open',
  emptyTitle = 'Nothing here',
  emptyDescription = 'No records match this filter.',
}: {
  title: string;
  rows: T[];
  columns: TableColumn<T>[];
  filters?: TableFilter<T>[];
  searchKeys?: (keyof T)[];
  searchPlaceholder?: string;
  rowLink?: (row: T) => string | null | undefined;
  actionLabel?: string;
  emptyTitle?: string;
  emptyDescription?: string;
}) {
  const navigate = useNavigate();
  const [filter, setFilter] = useState<string>('all');
  const [query, setQuery] = useState('');

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    return rows.filter((r) => {
      const passFilter = filter === 'all' || !filters ? true : (filters.find((f) => f.key === filter)?.test?.(r) ?? true);
      const passQuery = !q || !searchKeys ? true : searchKeys.some((k) => String(r[k] ?? '').toLowerCase().includes(q));
      return passFilter && passQuery;
    });
  }, [rows, filter, query, filters, searchKeys]);

  return (
    <div className="tbl-wrap">
      <div className="tbl-top">
        <div className="tt">{title}</div>
        <div className="tbl-tools">
          {filters?.map((f) => (
            <button key={f.key} type="button" className={`chip${filter === f.key ? ' on' : ''}`} onClick={() => setFilter(f.key)}>
              {f.label}
            </button>
          ))}
          {searchKeys && (
            <div className="search">
              <SearchIcon />
              <input placeholder={searchPlaceholder ?? 'Search'} value={query} onChange={(e) => setQuery(e.target.value)} />
            </div>
          )}
        </div>
      </div>
      <div style={{ overflowX: 'auto' }}>
        <table>
          <thead>
            <tr>
              {columns.map((c) => (
                <th key={c.key} className={c.num ? 'num' : undefined}>
                  {c.label}
                </th>
              ))}
              <th />
            </tr>
          </thead>
          <tbody>
            {filtered.length === 0 ? (
              <tr>
                <td colSpan={columns.length + 1}>
                  <div className="empty">
                    <div className="em-h">{emptyTitle}</div>
                    {emptyDescription}
                  </div>
                </td>
              </tr>
            ) : (
              filtered.map((r) => {
                const link = rowLink?.(r);
                return (
                  <tr key={r.id} className={link ? 'clk' : undefined} onClick={link ? () => navigate(link) : undefined}>
                    {columns.map((c) => (
                      <td key={c.key} className={[c.num ? 'num' : '', c.cellClassName?.(r) ?? ''].filter(Boolean).join(' ')}>
                        {c.render(r)}
                      </td>
                    ))}
                    <td className="num">{link && <span className="rowact">{actionLabel} →</span>}</td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function SearchIcon() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} width={14} height={14}>
      <circle cx="11" cy="11" r="7" />
      <path d="m21 21-4.3-4.3" />
    </svg>
  );
}

export function whoInitials(name: string): string {
  const parts = name.trim().split(/\s+/);
  return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase();
}
const AVATAR_COLORS = [PAL.petrol, PAL.petrol2, PAL.ox, PAL.green, PAL.amber, PAL.slate];
export function avatarColor(seed: string): string {
  let s = 0;
  for (let i = 0; i < seed.length; i++) s += seed.charCodeAt(i);
  return AVATAR_COLORS[s % AVATAR_COLORS.length];
}
export function WhoCell({ name, meta }: { name: string; meta?: string }) {
  return (
    <div className="who">
      <div className="a" style={{ background: avatarColor(name) }}>
        {whoInitials(name)}
      </div>
      <div>
        <div className="nm">{name}</div>
        {meta && <div className="m">{meta}</div>}
      </div>
    </div>
  );
}
