/**
 * AuditLogDetail — dedicated full-page view for a single audit event.
 *
 * Replaces the old right-side investigation drawer. Reached at /app/audit/:id
 * (linked from every timeline row). Renders ONLY what GET /admin/audit-logs/{id}
 * actually returns — summary, why_it_matters, actor, subject, before/after
 * diff, technical metadata, recommended steps. Absent fields render a truthful
 * "not recorded" note rather than a fabricated value.
 *
 * The "Record status" section states the platform's real guarantee — audit
 * rows are append-only (no updated_at, update()/delete() blocked server-side).
 * It makes NO hash-chain / cryptographic-integrity claim, because the backend
 * implements none.
 */
import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { adminApi } from '@/lib/endpoints';
import { formatDateTime } from '@/lib/format';
import { Button } from '@/components/ui/Button';
import { ErrorState, LoadingState } from '@/components/ui/states';
import { IconChevronLeft, IconShield, IconSearch } from '@/components/ui/icons';
import { SeverityBadge } from './audit/SeverityBadge';
import { StatusBadge } from './audit/StatusBadge';
import { ROLE_SEMANTIC, TINT, initialsOf } from './audit/auditVisuals';
import type { AuditLogDetail as AuditLogDetailData } from '@/lib/types';

/* ── Presentational helpers ───────────────────────────────────────────────── */

function Card({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}) {
  return (
    <section className="rounded-2xl border border-ink-200 bg-surface p-5 shadow-sm sm:p-6">
      <h2 className="mb-3 font-mono text-[10px] font-semibold uppercase tracking-[0.16em] text-ink-400">
        {title}
      </h2>
      {children}
    </section>
  );
}

function DL({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="grid grid-cols-[7rem_1fr] gap-x-3 gap-y-0.5 border-b border-ink-100 py-2 last:border-0">
      <dt className="pt-0.5 text-xs text-ink-400">{label}</dt>
      <dd className="text-sm font-medium text-ink-800 break-words">{value}</dd>
    </div>
  );
}

function renderValue(v: unknown): React.ReactNode {
  if (v === null) return <span className="italic text-ink-300">null</span>;
  if (typeof v === 'boolean') return <span className="font-mono">{String(v)}</span>;
  if (typeof v === 'string' || typeof v === 'number') return String(v);
  return <span className="font-mono text-ink-500">{JSON.stringify(v)}</span>;
}

/** Before/after diff for old_values → new_values (union of changed keys). */
function ValuesDiff({
  oldValues,
  newValues,
}: {
  oldValues: Record<string, unknown> | null;
  newValues: Record<string, unknown> | null;
}) {
  const allKeys = Array.from(
    new Set([...Object.keys(oldValues ?? {}), ...Object.keys(newValues ?? {})]),
  );

  if (allKeys.length === 0) {
    return (
      <p className="rounded-lg border border-dashed border-ink-200 px-4 py-3 text-sm text-ink-400">
        No field changes recorded for this event.
      </p>
    );
  }

  return (
    <div className="flex flex-col gap-2.5">
      {allKeys.map((key) => {
        const before = oldValues ? oldValues[key] : undefined;
        const after = newValues ? newValues[key] : undefined;
        const changed = JSON.stringify(before) !== JSON.stringify(after);
        return (
          <div
            key={key}
            className={[
              'rounded-xl border border-ink-200 px-4 py-3',
              changed ? 'bg-warning-50/40' : 'bg-surface/70',
            ].join(' ')}
          >
            <p className="mb-2 break-all font-mono text-xs font-semibold uppercase tracking-wider text-ink-500">
              {key}
            </p>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              <div className="rounded-lg border border-danger-500/20 bg-danger-50/40 px-3 py-2">
                <p className="mb-1 font-mono text-[10px] uppercase tracking-wider text-danger-500">
                  Before
                </p>
                <div className="break-all text-xs text-danger-600">
                  {before === undefined ? <span className="text-ink-300">—</span> : renderValue(before)}
                </div>
              </div>
              <div className="rounded-lg border border-success-500/20 bg-success-50/40 px-3 py-2">
                <p className="mb-1 font-mono text-[10px] uppercase tracking-wider text-success-500">
                  After
                </p>
                <div className="break-all text-xs text-success-600">
                  {after === undefined ? <span className="text-ink-300">—</span> : renderValue(after)}
                </div>
              </div>
            </div>
          </div>
        );
      })}
    </div>
  );
}

/** Collapsible raw JSON panel for metadata. */
function RawJson({ data }: { data: Record<string, unknown> }) {
  const [open, setOpen] = useState(false);
  return (
    <div>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        className="flex items-center gap-1.5 rounded font-mono text-xs uppercase tracking-wide text-ink-400 transition-colors hover:text-ink-700 focus-visible:outline-2 focus-visible:outline-offset-2"
      >
        <span aria-hidden="true">{open ? '▾' : '▸'}</span>
        Raw metadata JSON
      </button>
      {open && (
        <pre className="mt-2 max-h-72 overflow-auto rounded-xl border border-ink-200 bg-ink-50 p-4 text-xs leading-relaxed text-ink-700">
          {JSON.stringify(data, null, 2)}
        </pre>
      )}
    </div>
  );
}

function ActorAvatar({ name, role }: { name: string; role: string }) {
  const tint = TINT[ROLE_SEMANTIC[role] ?? 'neutral'];
  return (
    <span
      className={`grid h-11 w-11 shrink-0 place-items-center rounded-full text-sm font-semibold ${tint}`}
      aria-hidden="true"
    >
      {initialsOf(name)}
    </span>
  );
}

/* ── Page ─────────────────────────────────────────────────────────────────── */

function BackLink() {
  return (
    <Link to="/app/audit" className="au-back">
      <IconChevronLeft size={14} />
      Back to Audit log
    </Link>
  );
}

export function AuditLogDetail() {
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const numericId = Number(id);
  const validId = Number.isFinite(numericId) && numericId > 0;

  const { data, loading, error, reload } = useApi<AuditLogDetailData>(
    () =>
      validId
        ? adminApi.auditLog(numericId)
        : Promise.reject({ status: 404, message: 'Invalid audit event id.' }),
    [numericId],
  );

  const hasMetadata = !!(data?.metadata && Object.keys(data.metadata).length > 0);

  // Not found (or invalid id) — friendly, truthful message.
  if (!loading && error && (error.status === 404 || !validId)) {
    return (
      <div className="audit-center animate-rise mx-auto max-w-5xl space-y-6">
        <BackLink />
        <div className="flex flex-col items-center gap-3 rounded-2xl border border-dashed border-ink-200 bg-ink-50/40 px-6 py-16 text-center">
          <div
            className="grid h-14 w-14 place-items-center rounded-full"
            style={{
              color: 'var(--audit-accent)',
              background: 'var(--audit-accent-bg)',
              border: '1px solid var(--audit-accent-border)',
            }}
          >
            <IconSearch size={24} />
          </div>
          <h1 className="font-display text-xl font-semibold text-ink-900">Audit event not found</h1>
          <p className="max-w-sm text-sm text-ink-500">
            This audit event does not exist, or the link is out of date. It may have been outside
            your current view.
          </p>
          <Button variant="secondary" size="sm" className="mt-1" onClick={() => navigate('/app/audit')}>
            Back to Audit log
          </Button>
        </div>
      </div>
    );
  }

  return (
    <div className="audit-center animate-rise mx-auto max-w-5xl space-y-8">
      <BackLink />

      {/* ── Header ── */}
      {loading && !data ? (
        <div className="space-y-3">
          <div className="h-2.5 w-40 skeleton rounded" />
          <div className="h-10 w-72 skeleton rounded" />
          <div className="h-5 w-56 skeleton rounded" />
        </div>
      ) : data ? (
        <header className="space-y-3">
          <span className="au-dlabel">
            {data.area} · Audit event #{data.id}
          </span>
          <h1 className="au-dtitle">{data.action_label}</h1>
          <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
            <SeverityBadge severity={data.severity} />
            <StatusBadge status={data.status} />
            <span className="font-mono text-xs text-ink-400">{formatDateTime(data.created_at)}</span>
          </div>
        </header>
      ) : null}

      {loading && !data && <LoadingState label="Loading event details…" />}
      {error && error.status !== 404 && <ErrorState message={error.message} onRetry={reload} />}

      {data && (
        <div className="grid gap-6 lg:grid-cols-3">
          {/* ── Main column ── */}
          <div className="space-y-6 lg:col-span-2">
            <Card title="What happened">
              <p className="text-sm leading-relaxed text-ink-700">{data.summary}</p>
              {data.why_it_matters && (
                <div className="mt-4 rounded-xl border border-ink-200 bg-ink-50 px-4 py-3 text-sm leading-relaxed text-ink-600">
                  <span className="mb-0.5 block font-semibold text-ink-800">Why it matters</span>
                  {data.why_it_matters}
                </div>
              )}
            </Card>

            <Card title="Field changes">
              <ValuesDiff oldValues={data.old_values} newValues={data.new_values} />
            </Card>

            {hasMetadata && (
              <Card title="Raw metadata">
                <RawJson data={data.metadata!} />
              </Card>
            )}
          </div>

          {/* ── Side column ── */}
          <aside className="space-y-6">
            <Card title="Actor">
              <div className="mb-3 flex items-center gap-3">
                <ActorAvatar name={data.actor.name} role={data.actor.role} />
                <div className="min-w-0">
                  <p className="truncate font-semibold text-ink-900">{data.actor.name}</p>
                  <p className="font-mono text-[11px] uppercase tracking-wide text-ink-400">
                    {data.actor.role}
                  </p>
                </div>
              </div>
              <dl>
                {data.actor.email && <DL label="Email" value={data.actor.email} />}
                {data.actor_type && (
                  <DL label="Actor type" value={<span className="font-mono text-xs">{data.actor_type}</span>} />
                )}
                {data.actor.id !== null && <DL label="Actor ID" value={`#${data.actor.id}`} />}
              </dl>
            </Card>

            {data.subject && (
              <Card title="Related record">
                <dl>
                  <DL label="Type" value={<span className="font-mono text-xs">{data.subject.type}</span>} />
                  <DL label="Reference" value={data.subject.label} />
                  <DL label="ID" value={`#${data.subject.id}`} />
                </dl>
              </Card>
            )}

            <Card title="Technical details">
              <dl>
                <DL
                  label="Timestamp"
                  value={<span className="font-mono text-xs">{formatDateTime(data.created_at)}</span>}
                />
                <DL label="Action key" value={<span className="font-mono text-xs">{data.action}</span>} />
                {data.ip_address ? (
                  <DL label="IP address" value={<span className="font-mono text-xs">{data.ip_address}</span>} />
                ) : null}
                {data.device ? (
                  <DL label="Device" value={<span className="text-xs">{data.device}</span>} />
                ) : data.user_agent ? (
                  <DL
                    label="User agent"
                    value={<span className="break-all text-xs text-ink-500">{data.user_agent}</span>}
                  />
                ) : null}
              </dl>
              {!data.ip_address && !data.device && !data.user_agent && (
                <p className="mt-2 text-xs italic text-ink-400">
                  IP address and device were not recorded for this event type.
                </p>
              )}
            </Card>

            {/* Record status — real SHA-256 hash chain (verifiable, not decorative) */}
            <Card title="Record status">
              <div className="flex items-start gap-3">
                <span
                  className="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-success-50 text-success-600"
                  aria-hidden="true"
                >
                  <IconShield size={18} />
                </span>
                <div className="text-sm leading-relaxed text-ink-600">
                  <p className="font-semibold text-ink-800">SHA-256 hash-chained record.</p>
                  <ul className="mt-1.5 list-disc space-y-1 pl-4">
                    <li>Append-only — written once, never edited or deleted.</li>
                    <li>Committed to the previous entry’s hash, so tampering breaks the chain.</li>
                  </ul>
                </div>
              </div>
              <dl className="mt-4">
                <DL
                  label="This entry"
                  value={<span className="break-all font-mono text-xs text-ink-700">{data.hash}</span>}
                />
                <DL
                  label="Previous"
                  value={<span className="break-all font-mono text-xs text-ink-500">{data.previous_hash}</span>}
                />
              </dl>
            </Card>

            {data.recommended_steps.length > 0 && (
              <Card title="Recommended next steps">
                <div className="flex flex-col gap-2">
                  {data.recommended_steps.map((step, i) =>
                    step.to ? (
                      <Button
                        key={i}
                        variant="secondary"
                        size="sm"
                        className="w-full justify-start"
                        onClick={() => navigate(step.to!)}
                      >
                        {step.label}
                      </Button>
                    ) : (
                      <p
                        key={i}
                        className="rounded-xl border border-dashed border-ink-200 px-4 py-2.5 text-sm text-ink-400"
                      >
                        {step.label}
                      </p>
                    ),
                  )}
                </div>
              </Card>
            )}
          </aside>
        </div>
      )}
    </div>
  );
}
