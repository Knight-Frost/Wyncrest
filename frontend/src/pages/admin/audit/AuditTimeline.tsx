/**
 * AuditTimeline — the day-grouped event list that replaces the old cold table.
 *
 * Events (already sorted newest/oldest by the backend) are grouped into day
 * sections with a "Today / Yesterday / date" divider and a per-day count. Each
 * row is a real <Link> to the dedicated detail page (/app/audit/:id) so it is
 * natively keyboard-accessible (Tab + Enter), openable in a new tab, and needs
 * no custom key handling. The category node and actor avatar are tinted by
 * meaning via the shared semantic ramp; nothing is fabricated.
 */
import { Link } from 'react-router';
import { formatDate } from '@/lib/format';
import { Skeleton } from '@/components/ui/states';
import { SemanticBadge } from '@/components/cards';
import { IconChevronRight } from '@/components/ui/icons';
import { EmptyAuditState } from './EmptyAuditState';
import { ROLE_SEMANTIC, TINT, actionVisual, initialsOf } from './auditVisuals';
import type { AuditLog } from '@/lib/types';

/** 24-hour HH:mm, matching the record-style timeline (e.g. "14:32"). */
function time24(iso: string): string {
  const d = new Date(iso);
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false });
}

/** Severity → dot colour (matches the Routine/Review/Needs-review legend). */
const SEV_DOT: Record<AuditLog['severity'], string> = {
  info: 'var(--color-success-500)',
  warning: 'var(--color-warning-500)',
  critical: 'var(--color-danger-500)',
};

/** Local calendar-day key (client timezone) for grouping. */
function dayKey(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return 'unknown';
  return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
}

/** Human day label: "Today" / "Yesterday" / "Jun 28, 2026". */
function dayLabel(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return 'Unknown date';
  const today = new Date();
  const yesterday = new Date();
  yesterday.setDate(today.getDate() - 1);
  if (dayKey(iso) === dayKey(today.toISOString())) return 'Today';
  if (dayKey(iso) === dayKey(yesterday.toISOString())) return 'Yesterday';
  return formatDate(iso);
}

interface DayGroup {
  key: string;
  label: string;
  logs: AuditLog[];
}

/** Groups a (pre-sorted) list into consecutive same-day sections. */
function groupByDay(logs: AuditLog[]): DayGroup[] {
  const groups: DayGroup[] = [];
  for (const log of logs) {
    const key = dayKey(log.created_at);
    const last = groups[groups.length - 1];
    if (last && last.key === key) {
      last.logs.push(log);
    } else {
      groups.push({ key, label: dayLabel(log.created_at), logs: [log] });
    }
  }
  return groups;
}

function TimelineRow({ log }: { log: AuditLog }) {
  const { Icon, tint } = actionVisual(log.action, log.area);
  const role = ROLE_SEMANTIC[log.actor.role] ?? 'neutral';
  return (
    <Link
      to={`/app/audit/${log.id}`}
      className="au-entry"
      aria-label={`${log.action_label} by ${log.actor.name}, ${log.severity} severity — view details`}
    >
      <span className="au-entry__time">{time24(log.created_at)}</span>

      <span className={`au-node ${TINT[tint]}`} aria-hidden="true">
        <Icon size={17} />
      </span>

      <div className="min-w-0">
        {/* Actor-fronted record sentence */}
        <div className="au-entry__action">
          <b>{log.actor.name}</b>
          <span className="text-ink-400"> · </span>
          {log.summary}
        </div>

        <div className="au-entry__sub">
          <span
            className="au-sev-dot"
            style={{ background: SEV_DOT[log.severity] }}
            aria-label={`${log.severity} severity`}
          />
          <span className={`au-cat ${TINT[tint]}`}>{log.area}</span>
          <span className="inline-flex items-center gap-1.5 text-xs text-ink-500">
            <span
              className={`grid h-[19px] w-[19px] shrink-0 place-items-center rounded-full text-[10px] font-semibold ${TINT[role]}`}
              aria-hidden="true"
            >
              {initialsOf(log.actor.name)}
            </span>
            <span className="truncate">{log.actor.name}</span>
            <SemanticBadge role={role} dot={false}>
              {log.actor.role}
            </SemanticBadge>
          </span>
          {log.subject_label && (
            <span className="truncate text-xs text-ink-400">{log.subject_label}</span>
          )}
        </div>
      </div>

      <span className="au-right">
        <span className="au-hashpill" title={`SHA-256 chain hash: ${log.hash}`}>
          <span className="hk" />
          {log.hash.slice(0, 8)}
        </span>
        <IconChevronRight size={16} className="au-chev" aria-hidden="true" />
      </span>
    </Link>
  );
}

function SkeletonRows() {
  return (
    <div className="rounded-2xl border border-ink-200 bg-surface p-4 shadow-sm">
      <div className="divide-y divide-ink-100">
        {Array.from({ length: 8 }).map((_, i) => (
          <div key={i} className="flex items-center gap-3 py-3.5">
            <Skeleton className="h-9 w-9 rounded-xl" />
            <div className="flex-1 space-y-2">
              <Skeleton className="h-3.5 w-56" />
              <Skeleton className="h-3 w-40" />
            </div>
            <Skeleton className="h-5 w-16 rounded-full" />
          </div>
        ))}
      </div>
    </div>
  );
}

interface AuditTimelineProps {
  logs: AuditLog[];
  loading: boolean;
  onClearFilters?: () => void;
}

export function AuditTimeline({ logs, loading, onClearFilters }: AuditTimelineProps) {
  if (loading) return <SkeletonRows />;

  if (logs.length === 0) {
    return (
      <div className="rounded-2xl border border-ink-200 bg-surface p-6 shadow-sm">
        <EmptyAuditState onClearFilters={onClearFilters} />
      </div>
    );
  }

  const groups = groupByDay(logs);

  return (
    <div className="rounded-2xl border border-ink-200 bg-surface px-3 py-1.5 shadow-sm sm:px-5">
      {groups.map((group) => (
        <section key={group.key} aria-label={group.label}>
          <div className="au-day">
            <h3>{group.label}</h3>
            <span className="ln" />
            <span className="ct">
              {group.logs.length} {group.logs.length === 1 ? 'event' : 'events'}
            </span>
          </div>
          <div>
            {group.logs.map((log) => (
              <TimelineRow key={log.id} log={log} />
            ))}
          </div>
        </section>
      ))}
    </div>
  );
}
