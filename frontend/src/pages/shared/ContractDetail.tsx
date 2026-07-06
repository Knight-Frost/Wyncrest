import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { useAuth } from '@/context/auth';
import { useApi } from '@/hooks/useApi';
import { AdminContractCaseFile } from '@/pages/admin/AdminContractCaseFile';
import { LeaseDetail } from '@/pages/tenant/LeaseDetail';
import { landlordApi } from '@/lib/endpoints';
import { formatCents, formatDate, humanize } from '@/lib/format';
import { PageHeader } from '@/components/layout/PageHeader';
import { NexusCard } from '@/components/cards/NexusCard';
import { SemanticBadge } from '@/components/cards/SemanticBadge';
import { getContractVariant } from '@/components/cards/variants';
import { Button } from '@/components/ui/Button';
import { DestructiveConfirmDialog } from '@/components/ui/DestructiveConfirmDialog';
import { ErrorState, LoadingState } from '@/components/ui/states';
import {
  IconCalendar,
  IconCheckCircle,
  IconDoc,
  IconUsers,
  IconAlertTriangle,
  IconArrowLeft,
  IconLedger,
} from '@/components/ui/icons';
import type { Contract } from '@/lib/types';

function DetailRow({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-4 py-3 border-t border-ink-100 first:border-t-0">
      <dt className="text-sm text-ink-500">{label}</dt>
      <dd className="text-sm font-medium text-ink-900 text-right">{value}</dd>
    </div>
  );
}

/** Party card using NexusCard (Level 1) with ink teal icon tile */
function PartyCard({
  role,
  name,
  idLabel,
}: {
  role: 'Landlord' | 'Tenant';
  name: string;
  idLabel: string;
}) {
  return (
    <NexusCard role="neutral" className="flex items-center gap-3 p-4">
      <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-info-50 text-info-700">
        <IconUsers size={18} />
      </div>
      <div className="min-w-0">
        <p className="eyebrow mb-1">{role}</p>
        <p className="truncate text-sm font-semibold text-ink-900">{name}</p>
        <p className="text-xs text-ink-500">{idLabel}</p>
      </div>
    </NexusCard>
  );
}

export function ContractDetail() {
  const { id = '' } = useParams<{ id: string }>();
  const { user } = useAuth();
  const role = user?.role;

  // Tenants and admins render their own dedicated pages below (each with its
  // own independent fetch) — this hook is a no-op for them so every render
  // still calls the same hooks in the same order.
  const { data, loading, error, reload } = useApi<Contract | null>(async () => {
    if (role === 'landlord') {
      return (await landlordApi.contracts()).find((c) => c.id === id) ?? null;
    }
    return null;
  }, [id, role]);

  const [confirmSend, setConfirmSend] = useState(false);
  const [terminateOpen, setTerminateOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [actionResult, setActionResult] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

  async function handleSend() {
    setSubmitting(true);
    setActionResult(null);
    try {
      await landlordApi.sendContract(id);
      setActionResult({ type: 'success', message: 'Contract sent to tenant.' });
      setConfirmSend(false);
      reload();
    } catch (err) {
      setActionResult({ type: 'error', message: err instanceof Error ? err.message : 'Failed to send contract.' });
    } finally {
      setSubmitting(false);
    }
  }

  async function handleTerminate(reason?: string) {
    const trimmed = (reason ?? '').trim();
    if (!trimmed) return; // DestructiveConfirmDialog required guard covers this
    setSubmitting(true);
    setActionResult(null);
    try {
      await landlordApi.terminateContract(id, trimmed);
      setActionResult({ type: 'success', message: 'Contract terminated.' });
      setTerminateOpen(false);
      reload();
    } catch (err) {
      setActionResult({ type: 'error', message: err instanceof Error ? err.message : 'Failed to terminate contract.' });
    } finally {
      setSubmitting(false);
    }
  }

  // Tenants get the dedicated Lease & Rent detail (lease terms + payment
  // history/next-due + documents merged into one view).
  // Admins get the dedicated case-file page (ledger, payments, billing
  // schedule, timeline, notes, reconciliation warnings — none of which this
  // shared component has) — checked after every hook above has run.
  if (role === 'tenant') return <LeaseDetail />;
  if (role === 'admin') return <AdminContractCaseFile />;

  const backLink = (
    <Link
      to="/app/contracts"
      className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:text-brand-800 transition mb-4"
    >
      <IconArrowLeft size={16} />
      Back to contracts
    </Link>
  );

  if (loading) {
    return (
      <div>
        {backLink}
        <LoadingState />
      </div>
    );
  }

  if (error) {
    return (
      <div>
        {backLink}
        <div className="mt-4">
          <ErrorState message={error.message} onRetry={reload} />
        </div>
      </div>
    );
  }

  if (!data) {
    return (
      <div>
        {backLink}
        <div className="mt-4">
          <ErrorState title="Contract not found" message="This contract could not be located." />
        </div>
      </div>
    );
  }

  const contract = data;

  const landlordName = contract.landlord
    ? `${contract.landlord.first_name} ${contract.landlord.last_name}`
    : `Landlord #${contract.landlord_id}`;
  const tenantName = contract.tenant
    ? `${contract.tenant.first_name} ${contract.tenant.last_name}`
    : `Tenant #${contract.tenant_id}`;

  const actions: React.ReactNode[] = [];
  if (role === 'landlord' && contract.status === 'draft') {
    if (confirmSend) {
      actions.push(
        <span key="confirm-q" className="text-sm font-medium text-ink-700">
          Send this draft to the tenant?
        </span>,
        <Button key="cancel-send" variant="secondary" onClick={() => setConfirmSend(false)} disabled={submitting}>
          Cancel
        </Button>,
        <Button key="confirm-send" onClick={handleSend} loading={submitting}>
          Confirm
        </Button>,
      );
    } else {
      actions.push(
        <Button key="send" onClick={() => setConfirmSend(true)} disabled={submitting}>
          Send to Tenant
        </Button>,
      );
    }
  }
  if (role === 'landlord' && contract.status === 'active') {
    actions.push(
      <Button key="terminate" variant="danger" onClick={() => setTerminateOpen(true)} disabled={submitting}>
        Terminate
      </Button>,
    );
  }

  return (
    <div className="animate-rise space-y-6">
      {backLink}

      <PageHeader
        eyebrow="Contract"
        title={contract.listing?.title ?? `Contract ${contract.id.slice(0, 8)}…`}
        description={`${formatCents(contract.rent_amount)}/mo · ${humanize(contract.billing_cycle)}`}
        action={actions.length > 0 ? <>{actions}</> : undefined}
      />

      {/* Action result banner */}
      {actionResult && (
        <div
          className={
            actionResult.type === 'success'
              ? 'flex items-center gap-2.5 rounded-xl px-4 py-3 text-sm font-medium bg-success-50 text-success-700 border border-success-200'
              : 'flex items-center gap-2.5 rounded-xl px-4 py-3 text-sm font-medium bg-danger-50 text-danger-700 border border-danger-200'
          }
        >
          {actionResult.type === 'success' ? (
            <IconCheckCircle size={16} className="shrink-0" />
          ) : (
            <IconAlertTriangle size={16} className="shrink-0" />
          )}
          {actionResult.message}
        </div>
      )}

      {/* Parties side by side */}
      <div className="grid gap-3 sm:grid-cols-2">
        <PartyCard
          role="Landlord"
          name={landlordName}
          idLabel={contract.landlord?.email ?? `ID ${contract.landlord_id}`}
        />
        <PartyCard
          role="Tenant"
          name={tenantName}
          idLabel={contract.tenant?.email ?? `ID ${contract.tenant_id}`}
        />
      </div>

      <div className="grid gap-5 lg:grid-cols-[1fr_340px]">
        {/* Contract details — NexusCard Level 1 */}
        <NexusCard role="neutral" className="p-0 overflow-hidden">
          <div className="flex items-center justify-between gap-4 px-6 py-4 border-b border-ink-200">
            <h2 className="font-display text-lg font-semibold text-ink-950">Contract Details</h2>
            <SemanticBadge role={getContractVariant(contract.status)}>
              {humanize(contract.status)}
            </SemanticBadge>
          </div>
          <div className="px-6 py-2">
            <dl>
              <DetailRow
                label="Contract ID"
                value={
                  <code className="rounded bg-ink-100 px-1.5 py-0.5 text-xs font-mono text-ink-700">
                    {contract.id}
                  </code>
                }
              />
              <DetailRow
                label="Rent"
                value={
                  <span style={{ color: 'var(--color-money)' }} className="font-semibold">
                    {formatCents(contract.rent_amount)} {contract.currency}
                  </span>
                }
              />
              <DetailRow label="Billing cycle" value={humanize(contract.billing_cycle)} />
              <DetailRow label="Payment day" value={`Day ${contract.payment_day} of each cycle`} />
              <DetailRow label="Start date" value={formatDate(contract.start_date)} />
              <DetailRow label="End date" value={formatDate(contract.end_date)} />
              {contract.termination_reason && (
                <DetailRow label="Termination reason" value={contract.termination_reason} />
              )}
            </dl>
          </div>
        </NexusCard>

        {/* Timeline — NexusCard Level 1 */}
        <NexusCard role="neutral" className="p-0 overflow-hidden">
          <div className="px-6 py-4 border-b border-ink-200">
            <h2 className="font-display text-lg font-semibold text-ink-950">Timeline</h2>
          </div>
          <div className="px-6 py-4 space-y-3">
            <div className="flex items-start gap-3">
              <div className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-info-50">
                <IconDoc size={14} className="text-info-600" />
              </div>
              <div>
                <p className="text-sm font-medium text-ink-900">Contract created</p>
                <p className="text-xs text-ink-500">{formatDate(contract.created_at)}</p>
              </div>
            </div>
            {contract.status !== 'draft' && (
              <div className="flex items-start gap-3">
                <div className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-warning-100">
                  <IconCalendar size={14} className="text-warning-700" />
                </div>
                <div>
                  <p className="text-sm font-medium text-ink-900">Sent to tenant</p>
                  <p className="text-xs text-ink-500">Awaiting or accepted</p>
                </div>
              </div>
            )}
            {contract.status === 'active' && (
              <div className="flex items-start gap-3">
                <div className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-success-100">
                  <IconCheckCircle size={14} className="text-success-700" />
                </div>
                <div>
                  <p className="text-sm font-medium text-ink-900">Active lease</p>
                  <p className="text-xs text-ink-500">
                    Ends {formatDate(contract.end_date)}
                  </p>
                </div>
              </div>
            )}
            {(contract.status === 'terminated' || contract.status === 'expired') && (
              <div className="flex items-start gap-3">
                <div className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-danger-100">
                  <IconAlertTriangle size={14} className="text-danger-700" />
                </div>
                <div>
                  <p className="text-sm font-medium text-ink-900">
                    {humanize(contract.status)}
                  </p>
                  {contract.termination_reason && (
                    <p className="text-xs text-ink-500">{contract.termination_reason}</p>
                  )}
                </div>
              </div>
            )}
          </div>
        </NexusCard>
      </div>

      {/* Financials summary — NexusCard Level 1 */}
      <NexusCard role="neutral" className="p-0 overflow-hidden">
        <div className="flex items-center gap-3 px-6 py-4 border-b border-ink-200">
          <IconLedger size={18} className="text-ink-400" />
          <h2 className="font-display text-lg font-semibold text-ink-950">Financials</h2>
        </div>
        <div className="px-6 py-5">
          <dl className="grid gap-4 sm:grid-cols-3">
            {[
              { label: 'Monthly Rent', value: formatCents(contract.rent_amount) },
              { label: 'Billing Cycle', value: humanize(contract.billing_cycle) },
              { label: 'Payment Due', value: `Day ${contract.payment_day}` },
            ].map(({ label, value }) => (
              <div
                key={label}
                className="rounded-xl bg-ink-50 border border-ink-100 px-4 py-3"
              >
                <dt className="eyebrow mb-1">{label}</dt>
                <dd className="font-display text-lg font-semibold text-ink-900 num-old">
                  {value}
                </dd>
              </div>
            ))}
          </dl>
          <p className="mt-4 text-xs text-ink-400">
            View full payment history in the{' '}
            <Link to="/app/payments" className="text-brand-700 hover:underline font-medium">
              Payments
            </Link>{' '}
            or{' '}
            <Link to="/app/ledger" className="text-brand-700 hover:underline font-medium">
              Ledger
            </Link>{' '}
            section.
          </p>
        </div>
      </NexusCard>

      {/* Terminate — destructive confirm with required reason */}
      <DestructiveConfirmDialog
        open={terminateOpen}
        onClose={() => !submitting && setTerminateOpen(false)}
        onConfirm={handleTerminate}
        title="Terminate contract"
        description="This action cannot be undone."
        confirmLabel="Confirm"
        loading={submitting}
        reasonField={{ label: 'Reason', placeholder: 'Explain why…', required: true }}
      />
    </div>
  );
}
