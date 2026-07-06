import { useState } from 'react';
import { useApi } from '@/hooks/useApi';
import { adminApi } from '@/lib/endpoints';
import { timeAgo } from '@/lib/format';
import { PageHeader } from '@/components/layout/PageHeader';
import { RecordList, RecordCard } from '@/components/ui/RecordCard';
import { Badge, type Tone } from '@/components/ui/Badge';
import { ErrorState, ForbiddenState } from '@/components/ui/states';
import { Spinner } from '@/components/ui/Spinner';
import type { AdminMaintenanceCase } from '@/lib/types';

/**
 * AdminMaintenanceQueue — the first admin-facing view into maintenance
 * requests (previously landlord/tenant only). Viewing is a baseline admin
 * privilege (no capability gate, matching Users/Contracts/Ledger). There is
 * no per-case admin detail page yet (the existing /maintenance/:id route is
 * scoped to the filing tenant/landlord on a different auth guard) — this is
 * the whole picture for Phase A: counts + a filterable, traceable list.
 */

type StatusFilter = 'open' | 'urgent' | 'overdue' | 'waiting' | 'all';

const TABS: { key: StatusFilter; label: string }[] = [
  { key: 'open', label: 'Open' },
  { key: 'urgent', label: 'Urgent' },
  { key: 'overdue', label: 'Overdue' },
  { key: 'waiting', label: 'Waiting' },
  { key: 'all', label: 'All' },
];

function priorityTone(priority: string | null): Tone {
  if (priority === 'urgent') return 'danger';
  if (priority === 'high') return 'warning';
  return 'neutral';
}

function statusTone(status: string | null, isOverdue: boolean): Tone {
  if (isOverdue) return 'danger';
  if (status === 'waiting') return 'warning';
  if (status === 'resolved' || status === 'closed') return 'success';
  return 'info';
}

export function AdminMaintenanceQueue() {
  const [status, setStatus] = useState<StatusFilter>('open');
  const summaryReq = useApi(() => adminApi.maintenanceSummary(), []);
  const queueReq = useApi(() => adminApi.maintenanceQueue({ status, limit: 100 }), [status]);

  if (queueReq.error?.status === 403) {
    return (
      <div>
        <PageHeader eyebrow="Platform" title="Maintenance" />
        <ForbiddenState
          title="Admin access required"
          message="This area is restricted to platform administrators."
        />
      </div>
    );
  }
  if (queueReq.error) {
    return (
      <div>
        <PageHeader eyebrow="Platform" title="Maintenance" />
        <ErrorState message={queueReq.error.message} onRetry={queueReq.reload} />
      </div>
    );
  }

  const summary = summaryReq.data;
  const cases: AdminMaintenanceCase[] = queueReq.data?.data ?? [];

  return (
    <div>
      <PageHeader
        eyebrow="Platform"
        title="Maintenance"
        description="Every open maintenance request across the platform, traced to a tenant, landlord, and property."
      />

      {summary && (
        <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
          <div className="rounded-2xl border border-ink-200 bg-surface p-4">
            <p className="text-xs uppercase tracking-wide text-ink-500">Open</p>
            <p className="mt-1 font-display text-2xl text-ink-950">{summary.open}</p>
          </div>
          <div className="rounded-2xl border border-ink-200 bg-surface p-4">
            <p className="text-xs uppercase tracking-wide text-ink-500">Urgent</p>
            <p className="mt-1 font-display text-2xl text-danger-600">{summary.urgent}</p>
          </div>
          <div className="rounded-2xl border border-ink-200 bg-surface p-4">
            <p className="text-xs uppercase tracking-wide text-ink-500">Overdue</p>
            <p className="mt-1 font-display text-2xl text-danger-600">{summary.overdue}</p>
          </div>
          <div className="rounded-2xl border border-ink-200 bg-surface p-4">
            <p className="text-xs uppercase tracking-wide text-ink-500">Waiting</p>
            <p className="mt-1 font-display text-2xl text-warning-600">{summary.waiting}</p>
          </div>
        </div>
      )}

      <div className="mb-5 flex flex-wrap gap-2">
        {TABS.map((t) => (
          <button
            key={t.key}
            type="button"
            onClick={() => setStatus(t.key)}
            className={`rounded-full border px-4 py-1.5 text-xs font-medium uppercase tracking-wide transition-colors ${
              status === t.key
                ? 'border-brand-600 bg-brand-50 text-brand-700'
                : 'border-ink-200 text-ink-500 hover:border-ink-300'
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {queueReq.loading ? (
        <div className="flex justify-center py-12">
          <Spinner />
        </div>
      ) : cases.length === 0 ? (
        <div className="rounded-2xl border border-ink-200 bg-surface p-8 text-center text-ink-500">
          No maintenance requests match this filter.
        </div>
      ) : (
        <RecordList>
          {cases.map((c) => (
            <RecordCard
              key={c.id}
              title={c.title}
              subtitle={[
                c.tenant?.name ? `Tenant: ${c.tenant.name}` : null,
                c.landlord?.name ? `Landlord: ${c.landlord.name}` : null,
              ].filter(Boolean)}
              related={c.property ?? undefined}
              indicator={
                c.waiting_reason ? (
                  <p className="text-sm text-ink-500">{c.waiting_reason}</p>
                ) : c.has_severe_safety_flag ? (
                  <p className="text-sm text-danger-600">Safety flag raised</p>
                ) : undefined
              }
              status={
                <div className="flex flex-wrap gap-1.5">
                  {c.priority && <Badge tone={priorityTone(c.priority)}>{c.priority}</Badge>}
                  {c.status && (
                    <Badge tone={statusTone(c.status, c.is_overdue)}>
                      {c.is_overdue ? 'overdue' : c.status.replace(/_/g, ' ')}
                    </Badge>
                  )}
                </div>
              }
              timestamp={
                c.submitted_at
                  ? `Filed ${timeAgo(c.submitted_at)}${c.expected_completion_date ? ` · expected ${c.expected_completion_date}` : ''}`
                  : undefined
              }
            />
          ))}
        </RecordList>
      )}
    </div>
  );
}
