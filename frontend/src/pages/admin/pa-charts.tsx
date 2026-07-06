/* ============================================================================
   Hand-rolled SVG chart primitives for Platform Analytics — structurally
   mirrors wyncrest-admin-analytics.html's own `CH` chart library (grouped
   bars, line, donut, horizontal bars), but every color comes from a CSS
   custom property (--wpa-*, set in platform-analytics.css) rather than a
   literal hex value, so the page re-themes with accent/dark-mode like the
   rest of the app. No chart library — just SVG.
   ============================================================================ */

const AXIS_W = 520;

function svg(width: number, height: number, inner: React.ReactNode) {
  return (
    <svg className="pa-chart-svg" viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="xMidYMid meet">
      {inner}
    </svg>
  );
}

/** Single-series vertical bar chart — month-over-month trends. */
export function BarChart({
  rows,
  color = 'var(--wpa-petrol-2)',
  formatValue = (v: number) => String(Math.round(v)),
  emptyLabel = 'No data for this period yet.',
}: {
  rows: { label: string; value: number }[];
  color?: string;
  formatValue?: (v: number) => string;
  emptyLabel?: string;
}) {
  if (rows.length === 0) return <div className="pa-empty">{emptyLabel}</div>;

  const W = AXIS_W;
  const H = 190;
  const pl = 8;
  const pr = 8;
  const pt = 14;
  const pb = 26;
  const iw = W - pl - pr;
  const ih = H - pt - pb;
  const max = Math.max(...rows.map((r) => r.value), 1) * 1.12;
  const bw = iw / rows.length;

  return svg(
    W,
    H,
    <>
      {[0, 1, 2, 3].map((i) => {
        const y = pt + ih - (ih * i) / 3;
        return <line key={i} className="pa-gl" x1={pl} y1={y} x2={W - pr} y2={y} />;
      })}
      {rows.map((r, i) => {
        const h = max > 0 ? (ih * r.value) / max : 0;
        const x = pl + bw * i + bw * 0.22;
        const w = bw * 0.56;
        return (
          <g key={r.label}>
            <rect x={x} y={pt + ih - h} width={w} height={Math.max(h, r.value > 0 ? 2 : 0)} rx={3} fill={color} />
            <text className="pa-bar-cap" x={x + w / 2} y={pt + ih - h - 6} textAnchor="middle">
              {formatValue(r.value)}
            </text>
            <text className="pa-axis" x={x + w / 2} y={H - 8} textAnchor="middle">
              {r.label}
            </text>
          </g>
        );
      })}
    </>,
  );
}

/** Two-series grouped vertical bars — billed vs. collected, signups by role. */
export function DualBarChart({
  rows,
  colorA = 'var(--wpa-petrol-2)',
  colorB = 'var(--wpa-green)',
  formatValue = (v: number) => String(Math.round(v)),
  emptyLabel = 'No data for this period yet.',
}: {
  rows: { label: string; a: number; b: number }[];
  colorA?: string;
  colorB?: string;
  formatValue?: (v: number) => string;
  emptyLabel?: string;
}) {
  if (rows.length === 0) return <div className="pa-empty">{emptyLabel}</div>;

  const W = AXIS_W;
  const H = 190;
  const pl = 36;
  const pr = 8;
  const pt = 14;
  const pb = 26;
  const iw = W - pl - pr;
  const ih = H - pt - pb;
  const max = Math.max(...rows.flatMap((r) => [r.a, r.b]), 1) * 1.1;
  const bw = iw / rows.length;
  const gw = bw * 0.32;

  return svg(
    W,
    H,
    <>
      {[0, 1, 2, 3].map((i) => {
        const y = pt + ih - (ih * i) / 3;
        const v = (max * i) / 3;
        return (
          <g key={i}>
            <line className="pa-gl" x1={pl} y1={y} x2={W - pr} y2={y} />
            <text className="pa-axis" x={pl - 6} y={y + 3} textAnchor="end">
              {formatValue(v)}
            </text>
          </g>
        );
      })}
      {rows.map((r, i) => {
        const x = pl + bw * i + bw * 0.12;
        const ha = max > 0 ? (ih * r.a) / max : 0;
        const hb = max > 0 ? (ih * r.b) / max : 0;
        return (
          <g key={r.label}>
            <rect x={x} y={pt + ih - ha} width={gw} height={ha} rx={2} fill={colorA} />
            <rect x={x + gw + 4} y={pt + ih - hb} width={gw} height={hb} rx={2} fill={colorB} />
            <text className="pa-axis" x={x + gw} y={H - 8} textAnchor="middle">
              {r.label}
            </text>
          </g>
        );
      })}
    </>,
  );
}

/** Single-series line + area chart — outstanding balance over time, etc. */
export function LineChart({
  values,
  color = 'var(--wpa-oxblood)',
  formatValue = (v: number) => String(Math.round(v)),
}: {
  values: number[];
  color?: string;
  formatValue?: (v: number) => string;
}) {
  if (values.length === 0) return <div className="pa-empty">No data for this period yet.</div>;

  const W = AXIS_W;
  const H = 170;
  const pl = 8;
  const pr = 8;
  const pt = 16;
  const pb = 8;
  const iw = W - pl - pr;
  const ih = H - pt - pb;
  const max = Math.max(...values) * 1.1 || 1;
  const min = Math.min(0, Math.min(...values) * 0.9);
  const xx = (i: number) => pl + (values.length > 1 ? (iw * i) / (values.length - 1) : iw / 2);
  const yy = (v: number) => pt + ih - (ih * (v - min)) / (max - min || 1);

  const linePath = values.map((v, i) => `${i ? 'L' : 'M'}${xx(i)} ${yy(v)}`).join(' ');
  const areaPath = `M${xx(0)} ${pt + ih} ${values.map((v, i) => `L${xx(i)} ${yy(v)}`).join(' ')} L${xx(values.length - 1)} ${pt + ih} Z`;

  return svg(
    W,
    H,
    <>
      {[0, 1, 2].map((i) => {
        const y = pt + ih - (ih * i) / 2;
        return <line key={i} className="pa-gl" x1={pl} y1={y} x2={W - pr} y2={y} />;
      })}
      <path d={areaPath} fill={color} opacity={0.1} />
      <path d={linePath} fill="none" stroke={color} strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round" />
      {values.map((v, i) => (
        <circle key={i} cx={xx(i)} cy={yy(v)} r={3} fill="var(--wpa-card)" stroke={color} strokeWidth={2} />
      ))}
      <text className="pa-axis" x={pl} y={pt - 4} textAnchor="start">
        {formatValue(values[0])}
      </text>
      <text className="pa-axis" x={W - pr} y={pt - 4} textAnchor="end">
        {formatValue(values[values.length - 1])}
      </text>
    </>,
  );
}

/** Colors cycle through the page's own semantic ramp — never a literal hex. */
const PA_SERIES_COLORS = [
  'var(--wpa-petrol-2)',
  'var(--wpa-green)',
  'var(--wpa-amber)',
  'var(--wpa-oxblood)',
  'var(--wpa-slate)',
  'var(--wpa-petrol)',
];

/** Multi-segment donut (pure CSS conic-gradient) — status/priority/category distributions. */
export function DonutChart({
  rows,
  totalLabel,
  formatValue = (v: number) => String(v),
}: {
  rows: { label: string; value: number }[];
  totalLabel: string;
  formatValue?: (v: number) => string;
}) {
  const total = rows.reduce((sum, r) => sum + r.value, 0);
  if (total === 0) return <div className="pa-empty">No data for this period yet.</div>;

  let cursor = 0;
  const stops = rows
    .filter((r) => r.value > 0)
    .map((r, i) => {
      const start = (cursor / total) * 360;
      cursor += r.value;
      const end = (cursor / total) * 360;
      return `${PA_SERIES_COLORS[i % PA_SERIES_COLORS.length]} ${start}deg ${end}deg`;
    });

  return (
    <div className="pa-donut-wrap">
      <div className="pa-donut" style={{ background: `conic-gradient(${stops.join(', ')})` }}>
        <div className="pa-donut-total">
          <b>{formatValue(total)}</b>
          <span>{totalLabel}</span>
        </div>
      </div>
      <div className="pa-legend">
        {rows.map((r, i) => (
          <div className="pa-legend-row" key={r.label}>
            <i style={{ background: PA_SERIES_COLORS[i % PA_SERIES_COLORS.length] }} />
            <span>{r.label}</span>
            <b>{formatValue(r.value)}</b>
          </div>
        ))}
      </div>
    </div>
  );
}

/** Labeled horizontal bar list — outstanding by landlord, rejection reasons, etc. */
export function HBarList({
  rows,
  formatValue = (v: number) => String(v),
  emptyLabel = 'No data for this period yet.',
}: {
  rows: { label: string; value: number; tone?: 'default' | 'danger' }[];
  formatValue?: (v: number) => string;
  emptyLabel?: string;
}) {
  if (rows.length === 0) return <div className="pa-empty">{emptyLabel}</div>;
  const max = Math.max(...rows.map((r) => r.value), 1);

  return (
    <div className="pa-hbars">
      {rows.map((r) => (
        <div className="pa-hbar-row" key={r.label}>
          <div className="pa-hbar-top">
            <span>{r.label}</span>
            <b>{formatValue(r.value)}</b>
          </div>
          <div className="pa-track">
            <i
              className={r.tone === 'danger' ? 'danger' : ''}
              style={{ width: `${Math.max((r.value / max) * 100, r.value > 0 ? 2 : 0)}%` }}
            />
          </div>
        </div>
      ))}
    </div>
  );
}
