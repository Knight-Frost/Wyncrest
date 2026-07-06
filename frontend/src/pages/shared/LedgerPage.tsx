import { useState } from 'react';
import { useAuth } from '@/context/auth';
import { useApi } from '@/hooks/useApi';
import { adminApi, tenantApi } from '@/lib/endpoints';
import { formatCents, formatDate } from '@/lib/format';
import { Button } from '@/components/ui/Button';
import { ResponsiveTable, type ResponsiveColumn } from '@/components/ui/ResponsiveTable';
import { EmptyState, ErrorState, ForbiddenState, LoadingState, SkeletonCard } from '@/components/ui/states';
import {
  IconChevronRight,
  IconLedger,
  IconCheckCircle,
  IconAlertCircle,
  IconClock,
  IconWallet,
  IconShield,
  IconCalendar,
} from '@/components/ui/icons';
import { cn } from '@/lib/cn';
import {
  StatusCard,
  SemanticBadge,
  DashboardSection,
  DataCardGrid,
  getLedgerVariant,
} from '@/components/cards';
import type { LedgerEntry, LedgerStatus, LedgerFinancialSummary } from '@/lib/types';

function isPayable(entry: LedgerEntry): boolean {
  return (
    (entry.type === 'rent' || entry.type === 'late_fee') &&
    (entry.status === 'pending' || entry.status === 'overdue')
  );
}

const EMPTY_SUMMARY: LedgerFinancialSummary = {
  rent_charged_cents: 0,
  fees_charged_cents: 0,
  collected_cents: 0,
  outstanding_cents: 0,
  overdue_cents: 0,
  due_soon_cents: 0,
  entry_count: 0,
};

type FilterTab = 'all' | LedgerStatus;

const STATUS_TABS: { value: FilterTab; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'pending', label: 'Pending' },
  { value: 'overdue', label: 'Overdue' },
  { value: 'paid', label: 'Paid' },
  { value: 'waived', label: 'Waived' },
];

/**
 * Shared ledger view for tenant and admin roles. Landlords get the richer
 * dedicated LandlordLedger page (see App.tsx routing) — this component is
 * never reached for role === 'landlord'.
 *
 * Every financial figure shown here (the summary cards) comes directly from
 * LedgerComputationEngine via the backend response. This page does not sum
 * amount_cents itself — that client-side summation over a single paginated
 * page is what previously produced a negative "Total Collected" figure.
 */
export function LedgerPage() {
  const { user } = useAuth();
  const role = user?.role;
  const isTenant = role === 'tenant';
  const isAdmin = role === 'admin';

  const [page, setPage] = useState(1);
  const [payingId, setPayingId] = useState<string | null>(null);
  const [paidIds, setPaidIds] = useState<Set<string>>(new Set());
  const [activeFilter, setActiveFilter] = useState<FilterTab>('all');
  const [payResult, setPayResult] = useState<{ id: string; success: boolean } | null>(null);

  const ledger = useApi<{
    entries: LedgerEntry[];
    summary: LedgerFinancialSummary;
    currentPage: number;
    lastPage: number;
  }>(async () => {
    if (isTenant) {
      const res = await tenantApi.ledger();
      return { entries: res.entries, summary: res.summary, currentPage: 1, lastPage: 1 };
    }
    if (isAdmin) {
      const res = await adminApi.ledger({ page });
      return {
        entries: res.data,
        summary: res.summary,
        currentPage: res.current_page,
        lastPage: res.last_page,
      };
    }
    return { entries: [], summary: EMPTY_SUMMARY, currentPage: 1, lastPage: 1 };
  }, [role, page]);

  const balance = useApi(
    () => (isTenant ? tenantApi.balance() : Promise.resolve(null)),
    [role],
  );

  // Admin-only integrity check — a lightweight pass/warning/fail indicator.
  const integrity = useApi(
    () => (isAdmin ? adminApi.ledgerReconciliation() : Promise.resolve(null)),
    [role],
  );

  async function pay(entry: LedgerEntry) {
    setPayingId(entry.id);
    setPayResult(null);
    try {
      await tenantApi.initiatePayment(entry.id);
      setPaidIds((prev) => new Set([...prev, entry.id]));
      setPayResult({ id: entry.id, success: true });
    } catch {
      setPayResult({ id: entry.id, success: false });
    } finally {
      setPayingId(null);
    }
  }

  const title = isTenant ? 'Payments' : 'Ledger';
  const description = isTenant
    ? 'Your rent charges, fees, and payment history.'
    : 'Platform-wide rent entries, fees, and payments.';
  const eyebrow = isAdmin ? 'Governance' : 'My Rental';

  const allEntries = ledger.data?.entries ?? [];
  const summary = ledger.data?.summary ?? EMPTY_SUMMARY;

  const filtered =
    activeFilter === 'all'
      ? allEntries
      : allEntries.filter((e) => e.status === activeFilter);

  /* ── Ledger table columns (shared desktop table + mobile stacked cards) ── */
  const columns: ResponsiveColumn<LedgerEntry>[] = [
    {
      key: 'type',
      header: 'Type',
      primary: true,
      cell: (entry) => <span className="font-medium text-ink-900">{entry.display_label}</span>,
    },
    {
      key: 'date',
      header: 'Date',
      cell: (entry) => (
        <span className="whitespace-nowrap text-ink-600">
          {formatDate(entry.due_date ?? entry.occurred_at)}
        </span>
      ),
    },
    {
      key: 'period',
      header: 'Period',
      hideBelow: 'lg',
      cell: (entry) => (
        <span className="whitespace-nowrap text-ink-600">
          {entry.billing_period_start || entry.billing_period_end
            ? `${formatDate(entry.billing_period_start)} to ${formatDate(entry.billing_period_end)}`
            : '—'}
        </span>
      ),
    },
    {
      key: 'amount',
      header: 'Amount',
      align: 'right',
      cell: (entry) => (
        <span
          className="font-mono font-semibold tabular-nums"
          style={{ color: 'var(--color-money)' }}
        >
          {formatCents(entry.display_amount_cents)}
        </span>
      ),
    },
    {
      key: 'balance_impact',
      header: 'Balance Impact',
      align: 'right',
      hideBelow: 'xl',
      cell: (entry) => (
        <span className="whitespace-nowrap font-mono text-xs tabular-nums text-ink-500">
          {formatCents(entry.balance_impact_cents)}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      align: 'right',
      cell: (entry) => {
        const statusForDisplay = paidIds.has(entry.id) ? 'paid' : entry.status;
        return (
          <SemanticBadge
            role={getLedgerVariant(statusForDisplay as LedgerStatus)}
            status={statusForDisplay}
          />
        );
      },
    },
    ...(isTenant
      ? [
          {
            key: 'action',
            header: '',
            align: 'right' as const,
            cell: (entry: LedgerEntry) => {
              const alreadyPaid = paidIds.has(entry.id);
              if (isPayable(entry) && !alreadyPaid) {
                return (
                  <Button
                    size="sm"
                    onClick={() => pay(entry)}
                    loading={payingId === entry.id}
                    disabled={payingId !== null}
                  >
                    Pay Now
                  </Button>
                );
              }
              if (alreadyPaid) {
                return <span className="text-xs font-medium text-success-600">Initiated</span>;
              }
              return null;
            },
          },
        ]
      : []),
  ];

  /* ── summary stat cards loading state ── */
  const statsLoading = ledger.loading;

  return (
    <div className="animate-rise space-y-10">

      {/* ── Page header (on a glass card, matching the other admin pages) ── */}
      <div className="glass-panel flex flex-wrap items-start justify-between gap-4 p-6 sm:p-8">
        <div>
          <span className="eyebrow mb-2.5">{eyebrow}</span>
          <h1 className="font-display text-[clamp(28px,3vw,40px)] font-semibold leading-tight tracking-tight text-ink-950">
            {title}
          </h1>
          <p className="mt-2.5 text-[15px] text-ink-500 max-w-[64ch]">{description}</p>
        </div>

        {/* ── Ledger integrity indicator (admin only) ── */}
        {isAdmin && integrity.data && (
          <div
            className={cn(
              'flex items-center gap-2 rounded-full border px-3.5 py-2 text-xs font-semibold',
              integrity.data.status === 'pass'
                ? 'border-success-200 bg-success-50 text-success-700'
                : integrity.data.status === 'warning'
                  ? 'border-warning-200 bg-warning-50 text-warning-700'
                  : 'border-danger-200 bg-danger-50 text-danger-700',
            )}
            title={
              integrity.data.issues.length > 0
                ? integrity.data.issues.map((i) => i.message).join('\n')
                : 'All reconciliation checks passed'
            }
          >
            <IconShield size={14} />
            Ledger integrity:{' '}
            {integrity.data.status === 'pass'
              ? 'Passed'
              : `${integrity.data.issues.length} ${integrity.data.status === 'fail' ? 'issue' : 'warning'}${integrity.data.issues.length === 1 ? '' : 's'}`}
          </div>
        )}
      </div>

      {/* ── Summary stat cards (server-computed — never client-summed) ── */}
      {(statsLoading || allEntries.length > 0) && (
        <DashboardSection eyebrow="Summary" title="Financial Overview">
          <DataCardGrid cols={4}>
            {statsLoading ? (
              <>
                <SkeletonCard />
                <SkeletonCard />
                <SkeletonCard />
                <SkeletonCard />
              </>
            ) : (
              <>
                <StatusCard
                  label="Collected"
                  value={formatCents(summary.collected_cents)}
                  sub="Successful payments received"
                  icon={<IconCheckCircle size={18} />}
                  role={summary.collected_cents > 0 ? 'success' : 'neutral'}
                />
                <StatusCard
                  label="Outstanding"
                  value={formatCents(summary.outstanding_cents)}
                  sub="Total unpaid balance"
                  icon={<IconWallet size={18} />}
                  role={summary.outstanding_cents > 0 ? 'warning' : 'neutral'}
                />
                <StatusCard
                  label="Overdue"
                  value={formatCents(summary.overdue_cents)}
                  sub={summary.overdue_cents > 0 ? 'Unpaid, past due' : 'Nothing overdue'}
                  icon={<IconAlertCircle size={18} />}
                  role={summary.overdue_cents > 0 ? 'danger' : 'success'}
                />
                <StatusCard
                  label="Due Soon"
                  value={formatCents(summary.due_soon_cents)}
                  sub="Unpaid, not yet due"
                  icon={<IconClock size={18} />}
                  role={summary.due_soon_cents > 0 ? 'info' : 'neutral'}
                />
              </>
            )}
          </DataCardGrid>
        </DashboardSection>
      )}

      {/* ── Tenant outstanding balance banner ── */}
      {isTenant && balance.data && (balance.data.balance_cents ?? 0) > 0 && (
        <div className="flex items-center gap-3 rounded-xl border border-warning-200 bg-warning-50 px-4 py-3 text-sm">
          <IconAlertCircle size={16} className="shrink-0 text-warning-600" />
          <span className="font-medium text-warning-800">
            Outstanding balance:{' '}
            <span style={{ color: 'var(--color-money)' }} className="font-semibold">
              {formatCents(balance.data.balance_cents)}
            </span>
          </span>
        </div>
      )}

      {/* ── Pay result banner ── */}
      {payResult && (
        <div
          className={cn(
            'flex items-center gap-2 rounded-xl px-4 py-3 text-sm font-medium border',
            payResult.success
              ? 'bg-success-50 text-success-700 border-success-200'
              : 'bg-danger-50 text-danger-700 border-danger-200',
          )}
        >
          {payResult.success ? (
            <IconCheckCircle size={15} className="shrink-0" />
          ) : (
            <IconAlertCircle size={15} className="shrink-0" />
          )}
          {payResult.success
            ? 'Payment initiated. It will appear in your ledger shortly.'
            : 'Payment could not be initiated. Please try again.'}
        </div>
      )}

      {/* ── Ledger entries ── */}
      <DashboardSection
        eyebrow="Entries"
        title="Ledger"
        description="Individual charges, fees, and payments. Amount is always shown positive; Balance Impact is the signed effect on the running balance."
      >
        {ledger.loading ? (
          <LoadingState />
        ) : ledger.error?.status === 403 ? (
          <ForbiddenState
            title="You don't have access to the ledger"
            message="Viewing the platform ledger needs a permission your account hasn't been granted. Ask a super admin if you need it."
          />
        ) : ledger.error ? (
          <ErrorState message={ledger.error.message} onRetry={ledger.reload} />
        ) : allEntries.length === 0 ? (
          <EmptyState
            icon={<IconLedger />}
            title="No ledger entries"
            description="Charges and payments will appear here once a contract is active."
          />
        ) : (
          <>
            {/* Filter tabs */}
            <div className="flex flex-wrap gap-1 rounded-xl bg-ink-100 border border-ink-200 p-1 w-fit">
              {STATUS_TABS.map(({ value, label }) => {
                const count = value === 'all' ? allEntries.length : allEntries.filter((e) => e.status === value).length;
                if (value !== 'all' && count === 0) return null;
                return (
                  <button
                    key={value}
                    type="button"
                    onClick={() => setActiveFilter(value)}
                    className={cn(
                      'flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                      activeFilter === value
                        ? 'bg-surface text-ink-900 shadow-sm'
                        : 'text-ink-500 hover:text-ink-700',
                    )}
                  >
                    {label}
                    {count > 0 && (
                      <span
                        className={cn(
                          'rounded-full px-1.5 py-0.5 text-[10px] font-bold leading-none',
                          activeFilter === value
                            ? 'bg-brand-100 text-brand-700'
                            : 'bg-ink-200 text-ink-500',
                        )}
                      >
                        {count}
                      </span>
                    )}
                  </button>
                );
              })}
            </div>

            {filtered.length === 0 ? (
              <EmptyState
                icon={<IconLedger />}
                title={`No ${activeFilter} entries`}
                description="Try a different filter."
              />
            ) : (
              <ResponsiveTable
                caption="Ledger entries"
                columns={columns}
                rows={filtered}
                keyFn={(entry) => entry.id}
              />
            )}

            {(ledger.data?.lastPage ?? 1) > 1 && (
              <div className="mt-6 flex items-center justify-center gap-4">
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={(ledger.data?.currentPage ?? 1) <= 1}
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                >
                  Previous
                </Button>
                <span className="text-sm text-ink-500">
                  Page {ledger.data?.currentPage ?? 1} of {ledger.data?.lastPage ?? 1}
                </span>
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={(ledger.data?.currentPage ?? 1) >= (ledger.data?.lastPage ?? 1)}
                  onClick={() => setPage((p) => p + 1)}
                  leftIcon={<IconChevronRight className="h-4 w-4" />}
                >
                  Next
                </Button>
              </div>
            )}
          </>
        )}
      </DashboardSection>

      {/* ── Ledger integrity note ── */}
      <div className="flex items-center gap-3 rounded-xl border border-ink-200 bg-surface px-5 py-4 shadow-sm">
        <IconCalendar size={18} className="shrink-0 text-ink-400" />
        <p className="text-sm text-ink-500">
          The ledger is immutable. All charges are recorded as they occur and can never be
          edited. Corrections are made as compensating entries.
        </p>
      </div>
    </div>
  );
}
