/**
 * AuditLogDetail — admin-readable "case file" for a single audit event.
 *
 * Reached at /app/audit/:id (linked from every timeline row). Renders ONLY
 * what GET /admin/audit-logs/{id} actually returns. The narrative fields
 * (event_title, plain_summary, key_facts, related_records, financial_context,
 * classification, recommended_steps) are computed server-side by
 * AuditEventPresenter — this page never re-interprets an action key or a raw
 * UUID itself. Absent data renders a truthful fallback (`data_gap_note`,
 * "Record no longer exists") rather than being invented on the frontend.
 *
 * Layout follows a case-file order: headline → plain-English summary → why
 * it matters → related records → what changed → financial impact → actor/
 * source → recommended actions → integrity (collapsed) → technical details
 * (collapsed). Raw/technical content is deliberately pushed to the bottom.
 */
import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { adminApi } from '@/lib/endpoints';
import { formatCents, formatDateTime } from '@/lib/format';
import { Button } from '@/components/ui/Button';
import { ErrorState, LoadingState } from '@/components/ui/states';
import {
  IconChevronLeft,
  IconChevronDown,
  IconShield,
  IconSearch,
  IconArrowUpRight,
  IconInfo,
} from '@/components/ui/icons';
import { SemanticBadge } from '@/components/cards';
import type { SemanticRole } from '@/components/cards/variants';
import { SeverityBadge } from './audit/SeverityBadge';
import { ROLE_SEMANTIC, TINT, initialsOf, actionVisual } from './audit/auditVisuals';
import type {
  AuditLogDetail as AuditLogDetailData,
  AuditKeyFact,
  AuditRelatedRecord,
  AuditVerify,
} from '@/lib/types';

/* ── Presentational primitives ───────────────────────────────────────────── */

function Card({
  title,
  eyebrow,
  children,
}: {
  title: string;
  eyebrow?: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <section className="rounded-2xl border border-ink-200 bg-surface p-5 shadow-sm sm:p-6">
      <div className="mb-3 flex items-center justify-between gap-2">
        <h2 className="font-mono text-[10px] font-semibold uppercase tracking-[0.16em] text-ink-400">
          {title}
        </h2>
        {eyebrow}
      </div>
      {children}
    </section>
  );
}

/** Collapsible variant of Card — used for the two "technical proof" sections. */
function CollapsibleCard({
  title,
  subtitle,
  defaultOpen = false,
  children,
}: {
  title: string;
  subtitle?: string;
  defaultOpen?: boolean;
  children: React.ReactNode;
}) {
  const [open, setOpen] = useState(defaultOpen);
  return (
    <section className="rounded-2xl border border-ink-200 bg-surface shadow-sm">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        className="flex w-full items-center justify-between gap-3 px-5 py-4 text-left sm:px-6"
      >
        <div>
          <h2 className="font-mono text-[10px] font-semibold uppercase tracking-[0.16em] text-ink-400">
            {title}
          </h2>
          {subtitle && <p className="mt-1 text-sm text-ink-600">{subtitle}</p>}
        </div>
        <IconChevronDown
          size={16}
          className={`shrink-0 text-ink-400 transition-transform ${open ? 'rotate-180' : ''}`}
        />
      </button>
      {open && <div className="border-t border-ink-100 px-5 pb-5 pt-4 sm:px-6">{children}</div>}
    </section>
  );
}

function factValue(fact: AuditKeyFact): string {
  if (fact.kind === 'money' && typeof fact.value_cents === 'number') return formatCents(fact.value_cents);
  return fact.value ?? '—';
}

/** Label-over-value fact grid for "What happened". */
function KeyFactsGrid({ facts }: { facts: AuditKeyFact[] }) {
  if (facts.length === 0) return null;
  return (
    <dl className="grid grid-cols-2 gap-x-6 gap-y-4 border-t border-ink-100 pt-4 sm:grid-cols-3">
      {facts.map((fact, i) => (
        <div key={i} className="min-w-0">
          <dt className="font-mono text-[10px] uppercase tracking-wider text-ink-400">{fact.label}</dt>
          <dd className={`mt-0.5 truncate text-sm font-medium text-ink-800 ${fact.kind === 'money' ? 'font-mono' : ''}`}>
            {factValue(fact)}
          </dd>
        </div>
      ))}
    </dl>
  );
}

function classificationRole(label: string): SemanticRole {
  switch (label) {
    case 'Important':
    case 'Needs review':
      return 'warning';
    default:
      return 'success';
  }
}

/** A resolved related-record card — a real name/reference, never a bare UUID. */
function RelatedRecordCard({ record }: { record: AuditRelatedRecord }) {
  const navigate = useNavigate();
  const clickable = !!record.href;

  return (
    <div
      role={clickable ? 'button' : undefined}
      tabIndex={clickable ? 0 : undefined}
      onClick={clickable ? () => navigate(record.href!) : undefined}
      onKeyDown={clickable ? (e) => (e.key === 'Enter' ? navigate(record.href!) : undefined) : undefined}
      className={[
        'rounded-xl border border-ink-200 bg-ink-50/40 px-4 py-3',
        clickable ? 'cursor-pointer transition-colors hover:border-ink-300 hover:bg-ink-50' : '',
      ].join(' ')}
    >
      <p className="font-mono text-[10px] uppercase tracking-wider text-ink-400">{record.type}</p>
      <div className="mt-1 flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="truncate text-sm font-semibold text-ink-900">{record.label}</p>
          {record.sublabel && <p className="mt-0.5 truncate text-xs text-ink-500">{record.sublabel}</p>}
        </div>
        {clickable && <IconArrowUpRight size={14} className="mt-0.5 shrink-0 text-ink-400" />}
      </div>
    </div>
  );
}

function renderValue(v: unknown): React.ReactNode {
  if (v === null) return <span className="italic text-ink-300">null</span>;
  if (typeof v === 'boolean') return <span className="font-mono">{String(v)}</span>;
  if (typeof v === 'string' || typeof v === 'number') return String(v);
  return <span className="font-mono text-ink-500">{JSON.stringify(v)}</span>;
}

/** Before/after diff for old_values → new_values (union of changed keys). Only rendered when at least one key exists. */
function ValuesDiff({
  oldValues,
  newValues,
}: {
  oldValues: Record<string, unknown> | null;
  newValues: Record<string, unknown> | null;
}) {
  const allKeys = Array.from(new Set([...Object.keys(oldValues ?? {}), ...Object.keys(newValues ?? {})]));

  return (
    <div className="flex flex-col gap-2.5">
      {allKeys.map((key) => {
        const before = oldValues ? oldValues[key] : undefined;
        const after = newValues ? newValues[key] : undefined;
        return (
          <div key={key} className="rounded-xl border border-ink-200 bg-warning-50/30 px-4 py-3">
            <p className="mb-2 break-all font-mono text-xs font-semibold uppercase tracking-wider text-ink-500">
              {key}
            </p>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              <div className="rounded-lg border border-danger-500/20 bg-danger-50/40 px-3 py-2">
                <p className="mb-1 font-mono text-[10px] uppercase tracking-wider text-danger-500">Before</p>
                <div className="break-all text-xs text-danger-600">
                  {before === undefined ? <span className="text-ink-300">—</span> : renderValue(before)}
                </div>
              </div>
              <div className="rounded-lg border border-success-500/20 bg-success-50/40 px-3 py-2">
                <p className="mb-1 font-mono text-[10px] uppercase tracking-wider text-success-500">After</p>
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

/** This row's real integrity status, derived from the whole-chain verify result — never assumed. */
function rowIntegrityStatus(
  eventId: number,
  verify: AuditVerify | null,
): { ok: boolean; label: string } {
  if (!verify) return { ok: true, label: 'Checking chain integrity…' };
  if (verify.status === 'healthy' || verify.status === 'empty') {
    return { ok: true, label: "Verified — this row's hash matches what the chain expects." };
  }
  // status === 'broken'
  if (verify.broken_at !== null && eventId < verify.broken_at) {
    return { ok: true, label: "Verified — this row precedes the point where the chain breaks." };
  }
  return {
    ok: false,
    label: `Chain verification failed at event #${verify.broken_at} — this row's integrity cannot be confirmed.`,
  };
}

function BackLink() {
  return (
    <Link to="/app/audit" className="au-back">
      <IconChevronLeft size={14} />
      Back to Audit log
    </Link>
  );
}

/* ── Page ─────────────────────────────────────────────────────────────────── */

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

  // Chain verification is a whole-chain recomputation (GET /audit-logs/verify),
  // not a per-row flag — fetched once here so "Record integrity" can state
  // THIS row's real status instead of a blanket "Verified" claim.
  const { data: verify } = useApi<AuditVerify>(() => adminApi.auditVerify(), []);

  if (!loading && error && (error.status === 404 || !validId)) {
    return (
      <div className="audit-center animate-rise mx-auto max-w-3xl space-y-6">
        <BackLink />
        <div className="flex flex-col items-center gap-3 rounded-2xl border border-dashed border-ink-200 bg-ink-50/40 px-6 py-16 text-center">
          <div
            className="grid h-14 w-14 place-items-center rounded-full"
            style={{ color: 'var(--audit-accent)', background: 'var(--audit-accent-bg)', border: '1px solid var(--audit-accent-border)' }}
          >
            <IconSearch size={24} />
          </div>
          <h1 className="font-display text-xl font-semibold text-ink-900">Audit event not found</h1>
          <p className="max-w-sm text-sm text-ink-500">
            This audit event does not exist, or the link is out of date. It may have been outside your current view.
          </p>
          <Button variant="secondary" size="sm" className="mt-1" onClick={() => navigate('/app/audit')}>
            Back to Audit log
          </Button>
        </div>
      </div>
    );
  }

  const hasFieldChanges = !!(
    (data?.old_values && Object.keys(data.old_values).length > 0) ||
    (data?.new_values && Object.keys(data.new_values).length > 0)
  );
  const hasMetadata = !!(data?.metadata && Object.keys(data.metadata).length > 0);
  const { Icon: HeroIcon } = data ? actionVisual(data.action, data.area) : { Icon: IconShield };

  return (
    <div className="audit-center animate-rise mx-auto max-w-3xl space-y-6">
      <BackLink />

      {/* ── Hero ── */}
      {loading && !data ? (
        <div className="space-y-3">
          <div className="h-2.5 w-40 skeleton rounded" />
          <div className="h-10 w-72 skeleton rounded" />
          <div className="h-5 w-56 skeleton rounded" />
        </div>
      ) : data ? (
        <header className="space-y-3">
          <span className="au-dlabel flex items-center gap-2">
            <HeroIcon size={13} />
            {data.classification.category} · Audit event #{data.id}
          </span>
          <h1 className="au-dtitle">{data.event_title}</h1>
          <p className="max-w-2xl text-base leading-relaxed text-ink-700">{data.plain_summary}</p>
          <div className="flex flex-wrap items-center gap-x-3 gap-y-2 pt-1">
            <SemanticBadge role={classificationRole(data.classification.label)} dot={data.classification.label !== 'Routine'}>
              {data.classification.label}
            </SemanticBadge>
            <SeverityBadge severity={data.severity} />
            {data.classification.sensitivity && (
              <SemanticBadge role="neutral" dot={false}>
                {data.classification.sensitivity}
              </SemanticBadge>
            )}
            <span className="font-mono text-xs text-ink-400">{formatDateTime(data.created_at)}</span>
            <span className="text-xs text-ink-400">· {data.source.label}</span>
          </div>
        </header>
      ) : null}

      {loading && !data && <LoadingState label="Loading event details…" />}
      {error && error.status !== 404 && <ErrorState message={error.message} onRetry={reload} />}

      {data && (
        <div className="space-y-6">
          {/* ── What happened: key facts + honest data-gap note ── */}
          <Card title="What happened">
            <KeyFactsGrid facts={data.key_facts} />
            {data.data_gap_note && (
              <div className="mt-4 flex items-start gap-2.5 rounded-xl border border-dashed border-ink-200 bg-ink-50/50 px-4 py-3 text-xs leading-relaxed text-ink-500">
                <IconInfo size={14} className="mt-0.5 shrink-0" />
                <span>{data.data_gap_note}</span>
              </div>
            )}
          </Card>

          {/* ── Why it matters ── */}
          {data.why_it_matters && (
            <Card title="Why this matters">
              <p className="text-sm leading-relaxed text-ink-700">{data.why_it_matters}</p>
            </Card>
          )}

          {/* ── Related records — resolved names, never a bare UUID ── */}
          {data.related_records.length > 0 && (
            <Card title="Related records">
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                {data.related_records.map((r, i) => (
                  <RelatedRecordCard key={i} record={r} />
                ))}
              </div>
            </Card>
          )}

          {/* ── What changed: created-record summary OR field diff — never an empty box ── */}
          {data.created_record_summary && (
            <Card title="Created record">
              <p className="mb-3 text-sm font-semibold text-ink-800">{data.created_record_summary.type}</p>
              <KeyFactsGrid facts={data.created_record_summary.fields} />
            </Card>
          )}
          {!data.created_record_summary && hasFieldChanges && (
            <Card title="Field changes">
              <ValuesDiff oldValues={data.old_values} newValues={data.new_values} />
            </Card>
          )}

          {/* ── Financial context — ledger/payment events only ── */}
          {data.financial_context && (
            <Card title="Financial impact">
              <dl className="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3">
                <div>
                  <dt className="font-mono text-[10px] uppercase tracking-wider text-ink-400">
                    {data.financial_context.display_label}
                  </dt>
                  <dd className="mt-0.5 font-mono text-sm font-semibold text-ink-800">
                    {formatCents(data.financial_context.display_amount_cents)}
                  </dd>
                </div>
                <div>
                  <dt className="font-mono text-[10px] uppercase tracking-wider text-ink-400">Balance impact</dt>
                  <dd
                    className={`mt-0.5 font-mono text-sm font-semibold ${
                      data.financial_context.balance_impact_cents > 0
                        ? 'text-ink-800'
                        : data.financial_context.balance_impact_cents < 0
                          ? 'text-success-600'
                          : 'text-ink-500'
                    }`}
                  >
                    {data.financial_context.balance_impact_cents === 0
                      ? '—'
                      : `${data.financial_context.balance_impact_cents > 0 ? '+' : '-'}${formatCents(
                          Math.abs(data.financial_context.balance_impact_cents),
                        )}`}
                  </dd>
                </div>
                {data.financial_context.running_balance_cents !== null && (
                  <div>
                    <dt className="font-mono text-[10px] uppercase tracking-wider text-ink-400">Contract balance after</dt>
                    <dd className="mt-0.5 font-mono text-sm font-semibold text-ink-800">
                      {formatCents(data.financial_context.running_balance_cents)}
                    </dd>
                  </div>
                )}
                <div>
                  <dt className="font-mono text-[10px] uppercase tracking-wider text-ink-400">Reference</dt>
                  <dd className="mt-0.5 font-mono text-sm text-ink-700">{data.financial_context.reference}</dd>
                </div>
              </dl>
            </Card>
          )}

          {/* ── Actor / source ── */}
          <Card title="Who caused this">
            <div className="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
              <div className="flex items-center gap-3">
                <ActorAvatar name={data.actor.name} role={data.actor.role} />
                <div className="min-w-0">
                  <p className="font-semibold text-ink-900">{data.actor.name}</p>
                  <p className="font-mono text-[11px] uppercase tracking-wide text-ink-400">{data.actor.role}</p>
                  {data.actor.email && <p className="mt-0.5 text-xs text-ink-500">{data.actor.email}</p>}
                  <p className="mt-1.5 max-w-sm text-xs leading-relaxed text-ink-500">{data.source.description}</p>
                </div>
              </div>
              {(data.ip_address || data.device) && (
                <dl className="grid grid-cols-2 gap-x-6 gap-y-2 text-right">
                  {data.ip_address && (
                    <div>
                      <dt className="font-mono text-[10px] uppercase tracking-wider text-ink-400">IP address</dt>
                      <dd className="mt-0.5 font-mono text-xs text-ink-600">{data.ip_address}</dd>
                    </div>
                  )}
                  {data.device && (
                    <div>
                      <dt className="font-mono text-[10px] uppercase tracking-wider text-ink-400">Device</dt>
                      <dd className="mt-0.5 text-xs text-ink-600">{data.device}</dd>
                    </div>
                  )}
                </dl>
              )}
            </div>
          </Card>

          {/* ── Recommended next steps — event-aware, real routes only ── */}
          {data.recommended_steps.length > 0 && (
            <Card title="Recommended next steps">
              <div className="flex flex-wrap gap-2">
                {data.recommended_steps.map((step, i) =>
                  step.to ? (
                    <Button key={i} variant="secondary" size="sm" onClick={() => navigate(step.to!)}>
                      {step.label}
                    </Button>
                  ) : (
                    <p key={i} className="rounded-xl border border-dashed border-ink-200 px-4 py-2.5 text-sm text-ink-400">
                      {step.label}
                    </p>
                  ),
                )}
              </div>
            </Card>
          )}

          {/* ── Record integrity — collapsed by default, plain-language first ── */}
          <CollapsibleCard title="Record integrity" subtitle={data.integrity_statement}>
            {(() => {
              const status = rowIntegrityStatus(data.id, verify);
              return (
                <div className={`mb-4 flex items-center gap-2 text-sm font-medium ${status.ok ? 'text-success-600' : 'text-danger-600'}`}>
                  <IconShield size={16} />
                  {status.label}
                </div>
              );
            })()}
            <div className="grid gap-3 sm:grid-cols-2">
              <div className="rounded-xl border border-ink-200 bg-ink-50/60 px-4 py-3">
                <p className="mb-1.5 font-mono text-[10px] uppercase tracking-wider text-ink-400">This entry</p>
                <p className="break-all font-mono text-xs leading-relaxed text-ink-700">{data.hash}</p>
              </div>
              <div className="rounded-xl border border-ink-200 bg-ink-50/60 px-4 py-3">
                <p className="mb-1.5 font-mono text-[10px] uppercase tracking-wider text-ink-400">Previous</p>
                <p className="break-all font-mono text-xs leading-relaxed text-ink-500">{data.previous_hash}</p>
              </div>
            </div>
          </CollapsibleCard>

          {/* ── Technical details — collapsed, secondary, raw fields only ── */}
          <CollapsibleCard title="Technical details" subtitle="Raw fields behind this event, for developers and audits.">
            <dl className="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
              <div>
                <dt className="font-mono text-[10px] uppercase tracking-wider text-ink-400">Action key</dt>
                <dd className="mt-0.5 break-all font-mono text-xs text-ink-600">{data.action}</dd>
              </div>
              {data.subject && (
                <div>
                  <dt className="font-mono text-[10px] uppercase tracking-wider text-ink-400">Subject</dt>
                  <dd className="mt-0.5 break-all font-mono text-xs text-ink-600">
                    {data.subject.type} #{data.subject.id}
                  </dd>
                </div>
              )}
              {data.user_agent && (
                <div className="sm:col-span-2">
                  <dt className="font-mono text-[10px] uppercase tracking-wider text-ink-400">User agent</dt>
                  <dd className="mt-0.5 break-all font-mono text-xs text-ink-600">{data.user_agent}</dd>
                </div>
              )}
            </dl>
            {hasMetadata && (
              <div className="mt-4 border-t border-ink-100 pt-4">
                <p className="mb-2 font-mono text-[10px] uppercase tracking-wider text-ink-400">Raw metadata JSON</p>
                <pre className="max-h-72 overflow-auto rounded-xl border border-ink-200 bg-ink-50 p-4 text-xs leading-relaxed text-ink-700">
                  {JSON.stringify(data.metadata, null, 2)}
                </pre>
              </div>
            )}
          </CollapsibleCard>
        </div>
      )}
    </div>
  );
}
