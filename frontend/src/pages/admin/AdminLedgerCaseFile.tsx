/**
 * AdminLedgerCaseFile — the single-entry ledger case file for admins.
 *
 * Rendered at /app/ledger/:id. Every section traces to a real query:
 * this entry's own audit-log history (subject_type = LedgerEntry), other
 * ledger entries actually linked to it via related_rent_entry_id, and
 * notifications actually sent about it. There is no dispute/payout/
 * processor section — Wyncrest's ledger schema does not model those.
 */
import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { useAuth } from '@/context/auth';
import { adminApi } from '@/lib/endpoints';
import { adminHasCapability } from '@/lib/permissions';
import { formatCents, formatDate, formatDateTime, humanize } from '@/lib/format';
import { SemanticBadge, getLedgerVariant } from '@/components/cards';
import { Button } from '@/components/ui/Button';
import { DestructiveConfirmDialog } from '@/components/ui/DestructiveConfirmDialog';
import { ErrorState, LoadingState } from '@/components/ui/states';
import { IconArrowLeft, IconCheckCircle, IconInfo } from '@/components/ui/icons';
import type { LedgerEntry, LedgerEntryCaseFile, LedgerType } from '@/lib/types';
import './ledger-case-file.css';

const TYPE_LABEL: Record<LedgerType, string> = {
  rent: 'Rent charge',
  late_fee: 'Late fee',
  payment: 'Payment received',
  refund: 'Refund issued',
};

function Section({ title, hint, children }: { title: string; hint?: string; children: React.ReactNode }) {
  return (
    <section className="adl-sec">
      <div className="adl-sec-head">
        <h2>{title}</h2>
        {hint && <span className="adl-sec-hint">{hint}</span>}
      </div>
      {children}
    </section>
  );
}

function DL({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="adl-kv">
      <span className="adl-kv-k">{label}</span>
      <span className="adl-kv-v">{value ?? '—'}</span>
    </div>
  );
}

function LinkedRow({ entry, onOpen }: { entry: LedgerEntry; onOpen: () => void }) {
  return (
    <tr onClick={onOpen} style={{ cursor: 'pointer' }}>
      <td className="adl-ref">{entry.reference}</td>
      <td>{TYPE_LABEL[entry.type]}</td>
      <td className="adl-num">{formatCents(entry.display_amount_cents)}</td>
      <td>
        <SemanticBadge role={getLedgerVariant(entry.status)} status={entry.status} size="sm" />
      </td>
    </tr>
  );
}

export function AdminLedgerCaseFile() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { user: viewer } = useAuth();
  const canManageLedger = adminHasCapability(viewer, 'manage_ledger');
  const [waiveOpen, setWaiveOpen] = useState(false);
  const [waiving, setWaiving] = useState(false);
  const [waiveError, setWaiveError] = useState<string | null>(null);
  const [lateFeeOpen, setLateFeeOpen] = useState(false);
  const [lateFeeDollars, setLateFeeDollars] = useState('25.00');
  const [lateFeeBusy, setLateFeeBusy] = useState(false);

  const { data: entry, loading, error, reload } = useApi<LedgerEntryCaseFile>(
    () => adminApi.ledgerEntry(id!),
    [id],
  );

  if (loading) return <LoadingState />;
  if (error || !entry) return <ErrorState message={error?.message ?? 'Ledger entry not found.'} onRetry={reload} />;

  const property = entry.contract?.listing?.unit?.property;
  const unit = entry.contract?.listing?.unit;
  const canWaive =
    canManageLedger && ['rent', 'late_fee'].includes(entry.type) && ['pending', 'overdue'].includes(entry.status);
  const hasLateFee = entry.linked_entries.some((e) => e.type === 'late_fee');
  const canGenerateLateFee = canManageLedger && entry.type === 'rent' && entry.status === 'overdue' && !hasLateFee;

  async function handleWaive(reason?: string) {
    if (!reason) return;
    setWaiving(true);
    setWaiveError(null);
    try {
      await adminApi.waiveLedgerEntry(entry!.id, reason);
      setWaiveOpen(false);
      reload();
    } catch (e) {
      setWaiveError(e instanceof Error ? e.message : 'Could not waive this entry.');
    } finally {
      setWaiving(false);
    }
  }

  async function handleGenerateLateFee() {
    const cents = Math.round(parseFloat(lateFeeDollars || '0') * 100);
    if (!cents || cents <= 0) return;
    setLateFeeBusy(true);
    try {
      await adminApi.generateLateFee(entry!.id, cents);
      setLateFeeOpen(false);
      reload();
    } finally {
      setLateFeeBusy(false);
    }
  }

  return (
    <div className="adl-detail animate-rise">
      <div className="adl-crumb">
        <Link to="/app/ledger" className="adl-back">
          <IconArrowLeft size={14} /> Back to Ledger
        </Link>
      </div>

      <section className="adl-chead">
        <div className="adl-ch-top" style={{ display: 'flex', justifyContent: 'space-between', gap: '1rem', flexWrap: 'wrap' }}>
          <div>
            <span className="adl-ch-eyebrow">{entry.reference}</span>
            <h1 className="adl-ch-title">{TYPE_LABEL[entry.type]}</h1>
            <div className="adl-ch-facts">
              <SemanticBadge role={getLedgerVariant(entry.status)} status={entry.status} />
            </div>
          </div>
          <div style={{ textAlign: 'right' }}>
            <div className="adl-dl-label" style={{ margin: 0 }}>
              Amount
            </div>
            <div className="adl-ch-title" style={{ margin: '0.2rem 0' }}>
              {formatCents(entry.display_amount_cents)}
            </div>
          </div>
        </div>
        <div className="adl-ch-actions">
          {canWaive && (
            <Button variant="danger" size="sm" onClick={() => setWaiveOpen(true)}>
              Waive entry
            </Button>
          )}
          {canGenerateLateFee && (
            <Button variant="secondary" size="sm" onClick={() => setLateFeeOpen((v) => !v)}>
              Generate late fee
            </Button>
          )}
          {entry.contract_id && (
            <Button variant="secondary" size="sm" onClick={() => navigate(`/app/contracts/${entry.contract_id}`)}>
              Open contract
            </Button>
          )}
        </div>
        {lateFeeOpen && (
          <div className="adl-waivebox" style={{ marginTop: '1rem', display: 'flex', gap: '0.6rem', alignItems: 'flex-end' }}>
            <label style={{ flex: 1 }}>
              <div className="adl-kv-k" style={{ marginBottom: '0.3rem' }}>
                Late fee amount (GH₵)
              </div>
              <input
                type="number"
                min="0.01"
                step="0.01"
                value={lateFeeDollars}
                onChange={(e) => setLateFeeDollars(e.target.value)}
                style={{ width: '100%', padding: '0.5rem 0.7rem', borderRadius: 9, border: '1px solid var(--color-ink-200)' }}
              />
            </label>
            <Button size="sm" loading={lateFeeBusy} onClick={handleGenerateLateFee}>
              Confirm
            </Button>
          </div>
        )}
      </section>

      <div className="adl-two">
        <Section title="Transaction summary">
          <DL label="Amount" value={formatCents(entry.display_amount_cents)} />
          <DL
            label="Balance impact"
            value={
              entry.balance_impact_cents === 0
                ? 'None'
                : `${entry.balance_impact_cents > 0 ? '+' : '−'}${formatCents(Math.abs(entry.balance_impact_cents))}`
            }
          />
          <DL
            label="Balance after (contract)"
            value={entry.running_balance_cents != null ? formatCents(entry.running_balance_cents) : '—'}
          />
          <DL label="Status" value={humanize(entry.status)} />
          <DL label="Due date" value={entry.due_date ? formatDate(entry.due_date) : '—'} />
          <DL
            label="Billing period"
            value={
              entry.billing_period_start && entry.billing_period_end
                ? `${formatDate(entry.billing_period_start)} – ${formatDate(entry.billing_period_end)}`
                : '—'
            }
          />
          <DL label="Recorded" value={formatDateTime(entry.occurred_at)} />
        </Section>

        <Section title="People & context">
          <DL label="Tenant" value={entry.tenant?.full_name} />
          <DL label="Landlord" value={entry.landlord?.full_name} />
          <DL label="Property" value={property?.name} />
          <DL label="Unit" value={unit?.internal_name ?? unit?.unit_number} />
          <DL
            label="Contract"
            value={
              <Link to={`/app/contracts/${entry.contract_id}`} className="adl-doc-action">
                {entry.contract_id.slice(0, 8)}…
              </Link>
            }
          />
        </Section>
      </div>

      <Section title="Linked entries" hint="Traceable chain">
        {entry.linked_entries.length === 0 ? (
          <p className="adl-linked-empty">No other ledger entries reference this one.</p>
        ) : (
          <div className="adl-tbl-scroll">
            <table className="adl-tbl">
              <thead>
                <tr>
                  <th>Reference</th>
                  <th>Type</th>
                  <th className="adl-num">Amount</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {entry.linked_entries.map((linked) => (
                  <LinkedRow key={linked.id} entry={linked} onOpen={() => navigate(`/app/ledger/${linked.id}`)} />
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Section>

      <div className="adl-two">
        <Section title="Audit trail" hint="Every action recorded">
          {entry.audit_trail.length === 0 ? (
            <p className="adl-linked-empty">No audit events recorded for this entry.</p>
          ) : (
            <div className="adl-tl">
              {entry.audit_trail.map((event) => (
                <div
                  key={event.id}
                  className={`adl-tl-item ${event.severity === 'critical' ? 'adl-tl-blood' : event.severity === 'info' ? 'adl-tl-green' : ''}`}
                >
                  <div className="adl-tl-e">{humanize(event.action)}</div>
                  <div className="adl-tl-m">
                    {event.created_at ? formatDateTime(event.created_at) : '—'} · {event.actor}
                  </div>
                  {event.description && <div className="adl-tl-n">{event.description}</div>}
                </div>
              ))}
            </div>
          )}
        </Section>

        <Section title="Notifications sent" hint="Proof of notice">
          {entry.notifications.length === 0 ? (
            <p className="adl-linked-empty">No notifications linked to this entry.</p>
          ) : (
            <div>
              {entry.notifications.map((n) => (
                <div key={n.id} className="adl-tl-item">
                  <div className="adl-tl-e">{n.title}</div>
                  <div className="adl-tl-m">{n.created_at ? formatDateTime(n.created_at) : '—'}</div>
                  <div className="adl-tl-n">{n.message}</div>
                  <div className="adl-tl-n" style={{ display: 'flex', gap: '0.6rem', marginTop: '0.3rem' }}>
                    {n.delivered_at && (
                      <span style={{ display: 'inline-flex', alignItems: 'center', gap: '0.3rem' }}>
                        <IconCheckCircle size={12} /> Email delivered
                      </span>
                    )}
                    {n.sms_delivered_at && (
                      <span style={{ display: 'inline-flex', alignItems: 'center', gap: '0.3rem' }}>
                        <IconCheckCircle size={12} /> SMS delivered
                      </span>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </Section>
      </div>

      <div className="adl-immutable" style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', color: 'var(--color-ink-500)', fontSize: '0.82rem', padding: '0.5rem 0.2rem' }}>
        <IconInfo size={14} />
        This entry is immutable. It cannot be edited or deleted — corrections are recorded as new,
        linked entries.
      </div>

      <DestructiveConfirmDialog
        open={waiveOpen}
        onClose={() => {
          setWaiveOpen(false);
          setWaiveError(null);
        }}
        onConfirm={handleWaive}
        title="Waive this entry?"
        description={
          <>
            This will not delete {entry.reference}. Wyncrest transitions it to a permanent{' '}
            <b>waived</b> state — the reason is written to the audit log and the charge no longer
            counts toward the tenant's balance.
          </>
        }
        confirmLabel="Waive entry"
        loading={waiving}
        error={waiveError}
        reasonField={{
          label: 'Reason',
          placeholder: 'e.g. Tenant provided proof of on-time bank transfer.',
          required: true,
        }}
      />
    </div>
  );
}
