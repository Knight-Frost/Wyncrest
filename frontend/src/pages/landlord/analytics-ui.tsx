/**
 * Shared SVG icons + hand-rolled chart primitives for the landlord Analytics
 * page, ported from wyncrest-landlord-analytics.html. Component-only module
 * (pure helpers live in analytics-helpers.ts) so React Fast Refresh stays
 * happy. Charts are plain SVG (no charting library dependency), matching the
 * mockup's own approach.
 */
import type { CSSProperties, ReactNode } from 'react';
import { InfoHint } from '@/components/ui/InfoHint';

type IconProps = { className?: string };

export const IconFilter = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M22 3H2l8 9.5V19l4 2v-8.5z" /></svg>
);
export const IconChevronDown = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="m6 9 6 6 6-6" /></svg>
);
export const IconChevronRight = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="m9 6 6 6-6 6" /></svg>
);
export const IconUp = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className} strokeWidth={2.4}><path d="m6 15 6-6 6 6" /></svg>
);
export const IconDown = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className} strokeWidth={2.4}><path d="m6 9 6 6 6-6" /></svg>
);
export const IconInfo = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><circle cx="12" cy="12" r="10" /><path d="M12 16v-4M12 8h.01" /></svg>
);
export const IconShield = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z" /><path d="m9 12 2 2 4-4" /></svg>
);
export const IconCash = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><rect x="2" y="6" width="20" height="12" rx="2" /><circle cx="12" cy="12" r="2.5" /></svg>
);
export const IconBuilding = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><rect x="4" y="2" width="16" height="20" rx="2" /><path d="M9 22v-4h6v4M9 6h.01M15 6h.01M9 10h.01M15 10h.01M9 14h.01M15 14h.01" /></svg>
);
export const IconWrench = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M14.7 6.3a4 4 0 0 0-5.4 5.3l-6 6a1.4 1.4 0 0 0 2 2l6-6a4 4 0 0 0 5.3-5.4l-2.4 2.4-2-2z" /></svg>
);
export const IconList = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" /></svg>
);
export const IconClock = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>
);
export const IconEye = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7Z" /><circle cx="12" cy="12" r="3" /></svg>
);
export const IconDoor = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M13 4h3a2 2 0 0 1 2 2v14M2 20h20M13 2v20M9 12h.01" /></svg>
);
export const IconChart = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M3 3v18h18M7 15l3-4 3 3 5-7" /></svg>
);
export const IconExport = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><path d="M17 8l-5-5-5 5M12 3v12" /></svg>
);
export const IconRefresh = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M21 12a9 9 0 1 1-3-6.7M21 3v6h-6" /></svg>
);

/**
 * Tooltip trigger matching the mockup's `.tip` info bubble. Delegates to the
 * accessible InfoHint (keyboard focus + ARIA) instead of the old CSS
 * hover-only implementation, without changing any call site's signature.
 */
export function Tip({ text }: { text: string }): ReactNode {
  return <InfoHint text={text} />;
}

export function Legend({ items }: { items: Array<{ label: string; color: string }> }): ReactNode {
  return (
    <div className="legend">
      {items.map((i) => (
        <span className="li" key={i.label}>
          <span className="sw" style={{ background: i.color }} />
          {i.label}
        </span>
      ))}
    </div>
  );
}

/* ---- chart geometry -------------------------------------------------------- */

const CHART_W = 560;
const CHART_H = 250;
const PAD = { l: 56, r: 16, t: 14, b: 30 };

function niceMax(values: number[]): number {
  const m = Math.max(...values, 1);
  return m * 1.14;
}

/** Single- or multi-series line chart with an optional filled area. */
export function LineChart({
  labels,
  series,
  area,
  yMax,
  fmt,
  w = CHART_W,
  h = CHART_H,
}: {
  labels: string[];
  series: Array<{ name: string; color: string; vals: number[] }>;
  area?: boolean;
  yMax?: number;
  fmt?: (v: number) => string;
  w?: number;
  h?: number;
}): ReactNode {
  const iw = w - PAD.l - PAD.r;
  const ih = h - PAD.t - PAD.b;
  const all = series.flatMap((s) => s.vals);
  const max = yMax ?? niceMax(all);
  const min = 0;
  const x = (i: number) => PAD.l + (labels.length <= 1 ? iw / 2 : (i * iw) / (labels.length - 1));
  const y = (v: number) => PAD.t + ih - ((v - min) / (max - min || 1)) * ih;
  const format = fmt ?? ((v: number) => String(Math.round(v)));

  return (
    <svg className="cs" viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="xMidYMid meet" role="img">
      {[0, 1, 2, 3, 4].map((g) => {
        const gv = min + ((max - min) * g) / 4;
        const gy = y(gv);
        return (
          <g key={g}>
            <line className="grid-l" x1={PAD.l} y1={gy} x2={w - PAD.r} y2={gy} />
            <text className="axis-v" x={PAD.l - 8} y={gy + 3} textAnchor="end">{format(gv)}</text>
          </g>
        );
      })}
      {labels.map((l, i) => (
        <text className="axis-l" key={l} x={x(i)} y={h - 8} textAnchor="middle">{l}</text>
      ))}
      {series.map((s) => (
        <g key={s.name}>
          {area && (
            <path
              d={`M${x(0)},${y(min)} ${s.vals.map((v, i) => `L${x(i)},${y(v)}`).join(' ')} L${x(s.vals.length - 1)},${y(min)} Z`}
              fill={s.color}
              opacity={0.08}
            />
          )}
          <polyline
            points={s.vals.map((v, i) => `${x(i)},${y(v)}`).join(' ')}
            fill="none"
            stroke={s.color}
            strokeWidth={2.5}
            strokeLinejoin="round"
            strokeLinecap="round"
          />
          {s.vals.map((v, i) => (
            <circle key={i} className="dot" cx={x(i)} cy={y(v)} r={3.6} fill="var(--wa-white)" stroke={s.color} strokeWidth={2}>
              <title>{labels[i]}: {format(v)}</title>
            </circle>
          ))}
        </g>
      ))}
    </svg>
  );
}

/** Two-series grouped bar chart (e.g. Expected vs Collected). */
export function GroupedBarChart({
  labels,
  a,
  b,
  fmt,
  w = CHART_W,
  h = CHART_H,
}: {
  labels: string[];
  a: { name: string; color: string; vals: number[] };
  b: { name: string; color: string; vals: number[] };
  fmt?: (v: number) => string;
  w?: number;
  h?: number;
}): ReactNode {
  const iw = w - PAD.l - PAD.r;
  const ih = h - PAD.t - PAD.b;
  const max = niceMax([...a.vals, ...b.vals]);
  const y = (v: number) => PAD.t + ih - (v / max) * ih;
  const gw = iw / labels.length;
  const bw = Math.min(22, gw * 0.28);
  const gap = 5;
  const format = fmt ?? ((v: number) => String(Math.round(v)));
  const base = PAD.t + ih;

  return (
    <svg className="cs" viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="xMidYMid meet" role="img">
      {[0, 1, 2, 3, 4].map((g) => {
        const gv = (max * g) / 4;
        const gy = y(gv);
        return (
          <g key={g}>
            <line className="grid-l" x1={PAD.l} y1={gy} x2={w - PAD.r} y2={gy} />
            <text className="axis-v" x={PAD.l - 8} y={gy + 3} textAnchor="end">{format(gv)}</text>
          </g>
        );
      })}
      {labels.map((l, i) => {
        const cx = PAD.l + i * gw + gw / 2;
        const x1 = cx - bw - gap / 2;
        const x2 = cx + gap / 2;
        return (
          <g key={l}>
            <rect x={x1} y={y(a.vals[i])} width={bw} height={base - y(a.vals[i])} rx={3} fill={a.color}>
              <title>{l} · {a.name}: {format(a.vals[i])}</title>
            </rect>
            <rect x={x2} y={y(b.vals[i])} width={bw} height={base - y(b.vals[i])} rx={3} fill={b.color}>
              <title>{l} · {b.name}: {format(b.vals[i])}</title>
            </rect>
            <text className="axis-l" x={cx} y={h - 8} textAnchor="middle">{l}</text>
          </g>
        );
      })}
    </svg>
  );
}

/** Horizontal bar list — used for revenue/vacancy-by-property, listing status, maintenance, etc. */
export function HBarChart({
  rows,
  fmt,
  labelW = 190,
  rowH = 40,
  valueW = 130,
  w = CHART_W,
  onRowClick,
}: {
  rows: Array<{ label: string; value: number; color?: string }>;
  fmt?: (v: number) => string;
  labelW?: number;
  rowH?: number;
  valueW?: number;
  w?: number;
  onRowClick?: (label: string) => void;
}): ReactNode {
  const W = w;
  const padT = 8;
  const padB = 8;
  const format = fmt ?? ((v: number) => String(v));

  if (rows.length === 0) {
    return <p className="wa-chart-empty">No data yet.</p>;
  }

  // A genuinely all-zero dataset (e.g. no outstanding balance in any aging
  // bucket) can't be shown as proportional bars — every bar would collapse to
  // the same indistinguishable sliver, which reads as broken rather than
  // "zero". Render a calm flat state instead: a muted track + the real value.
  const trueMax = Math.max(...rows.map((r) => r.value), 0);
  if (trueMax === 0) {
    return (
      <div className="wa-hbar-flat" style={{ '--wa-flat-label-w': `${labelW}px` } as CSSProperties}>
        {rows.map((r) => (
          <div className="wa-hbar-flat-row" key={r.label}>
            <span className="wa-hbar-flat-label">{r.label}</span>
            <span className="wa-hbar-flat-track" />
            <span className="wa-hbar-flat-value">{format(r.value)}</span>
          </div>
        ))}
      </div>
    );
  }

  const H = rows.length * rowH + padT + padB;
  const barArea = Math.max(40, W - labelW - valueW);

  return (
    <svg className="cs" viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="xMinYMin meet" role="img">
      {rows.map((r, i) => {
        const y = padT + i * rowH;
        const bw = Math.max(3, (r.value / trueMax) * barArea);
        const color = r.color ?? 'var(--wa-c1)';
        return (
          <g
            key={r.label}
            className={`hbar-row${onRowClick ? ' clk' : ''}`}
            onClick={onRowClick ? () => onRowClick(r.label) : undefined}
          >
            <title>{r.label}: {format(r.value)}</title>
            <text className="bar-lbl" x={4} y={y + rowH / 2 + 4}>{r.label}</text>
            <rect className="bar" x={labelW} y={y + rowH / 2 - 10} width={bw} height={20} rx={5} fill={color} />
            <text className="bar-val" x={W - 4} y={y + rowH / 2 + 4} textAnchor="end">{format(r.value)}</text>
          </g>
        );
      })}
    </svg>
  );
}

/** Stacked monthly bars (on-time vs late payments). */
export function StackedBarChart({
  labels,
  segs,
  rows,
  w = CHART_W,
  h = CHART_H,
}: {
  labels: string[];
  segs: Array<{ key: string; name: string; color: string }>;
  rows: Array<Record<string, number | string>>;
  w?: number;
  h?: number;
}): ReactNode {
  const W = w;
  const H = h;
  const P = { l: 40, r: 16, t: 14, b: 30 };
  const iw = W - P.l - P.r;
  const ih = H - P.t - P.b;
  const totals = rows.map((r) => segs.reduce((s, seg) => s + Number(r[seg.key] ?? 0), 0));
  const max = Math.max(...totals, 1) * 1.16;
  const y = (v: number) => P.t + ih - (v / max) * ih;
  const bw = Math.min(28, (iw / labels.length) * 0.5);

  return (
    <svg className="cs" viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="xMidYMid meet" role="img">
      {[0, 1, 2, 3, 4].map((g) => {
        const gv = (max * g) / 4;
        const gy = y(gv);
        return (
          <g key={g}>
            <line className="grid-l" x1={P.l} y1={gy} x2={W - P.r} y2={gy} />
            <text className="axis-v" x={P.l - 8} y={gy + 3} textAnchor="end">{Math.round(gv)}</text>
          </g>
        );
      })}
      {labels.map((l, i) => {
        const cx = P.l + (i + 0.5) * (iw / labels.length);
        let acc = 0;
        const bars = segs.map((seg) => {
          const v = Number(rows[i]?.[seg.key] ?? 0);
          if (v <= 0) return null;
          const h = (v / max) * ih;
          const yy = y(acc + v);
          acc += v;
          return (
            <rect key={seg.key} x={cx - bw / 2} y={yy} width={bw} height={h} fill={seg.color}>
              <title>{l} · {seg.name}: {v}</title>
            </rect>
          );
        });
        return (
          <g key={l}>
            {bars}
            <text className="axis-l" x={cx} y={H - 8} textAnchor="middle">{l}</text>
          </g>
        );
      })}
    </svg>
  );
}

/** Waterfall-style funnel of decreasing bars. */
export function Funnel({ steps }: { steps: Array<{ label: string; value: number }> }): ReactNode {
  // Real data is not guaranteed to decrease monotonically (e.g. "Views" can be
  // 0 while later, tracked-independently steps like "Applications" are not),
  // so the bar scale must be the true max across ALL steps, not just the
  // first one — and every bar is capped at 100% width.
  const max = Math.max(...steps.map((s) => s.value), 1);

  return (
    <div className="funnel">
      {steps.map((s, i) => {
        const w = Math.min(100, Math.max(8, (s.value / max) * 100));
        const prev = i > 0 ? steps[i - 1].value : null;
        const color = `var(--wa-c${(i % 6) + 1})`;
        let stepNote: ReactNode = null;
        if (prev !== null && prev > 0) {
          const change = Math.round(((s.value - prev) / prev) * 100);
          stepNote = <div className="fdrop">{change > 0 ? `+${change}` : change}% step</div>;
        }
        return (
          <div className="fstep" key={s.label}>
            <div className="fbar" style={{ width: `${w}%`, background: color }}>{s.value}</div>
            <div className="fmeta"><b>{s.label}</b></div>
            {stepNote}
          </div>
        );
      })}
    </div>
  );
}

/** Single segmented horizontal bar (unit status: occupied / vacant-listed / vacant-draft). */
export function SegBar({ segs }: { segs: Array<{ label: string; value: number; color: string }> }): ReactNode {
  const total = segs.reduce((s, x) => s + x.value, 0) || 1;

  return (
    <div className="segbar">
      {segs.filter((s) => s.value > 0).map((s) => (
        <div className="seg" key={s.label} style={{ width: `${(s.value / total) * 100}%`, background: s.color }} title={s.label}>
          {s.value}
        </div>
      ))}
    </div>
  );
}
