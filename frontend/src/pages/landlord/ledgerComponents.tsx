/*
 * Shared React components for the landlord Rent Ledger console. Kept separate
 * from ledgerShared.ts (pure constants/helpers) so each module has a single
 * export kind — satisfies react-refresh and keeps fast-refresh working.
 */
import { useEffect, useId, useMemo, useRef, useState, type ReactNode } from 'react';
import { landlordApi } from '@/lib/endpoints';
import type { LedgerEntry, PaymentMethod } from '@/lib/types';
import { formatCents } from '@/lib/format';
import { useToast } from '@/components/ui/toast';
import { I, ENTRY } from './ledgerShared';

export function Badge({ badge, children }: { badge: string; children: ReactNode }) {
  return <span className={`badge ${badge}`}>{children}</span>;
}

const METHODS: { value: PaymentMethod; label: string }[] = [
  { value: 'mobile_money_mtn', label: 'Mobile money · MTN' },
  { value: 'mobile_money_vodafone', label: 'Mobile money · Vodafone' },
  { value: 'bank_transfer', label: 'Bank transfer' },
  { value: 'cash', label: 'Cash' },
];

/**
 * Record a full-amount offline payment against one open rent/late-fee entry.
 * `obligations` is the set of open entries the landlord may settle (one on the
 * case file, potentially several from a Balances row). No free-typed amount —
 * the full display amount of the chosen entry is what's recorded.
 */
export function RecordPaymentModal({
  obligations,
  tenantName,
  onClose,
  onDone,
}: {
  obligations: LedgerEntry[];
  tenantName?: string | null;
  onClose: () => void;
  onDone: () => void;
}) {
  const { toast } = useToast();
  const [entryId, setEntryId] = useState<string>(obligations[0]?.id ?? '');
  const [method, setMethod] = useState<PaymentMethod>('mobile_money_mtn');
  const [reference, setReference] = useState('');
  const [saving, setSaving] = useState(false);

  // Dialog a11y — Escape closes, focus lands on the dialog when it opens, and
  // the visible title names it (mirrors the shared Modal component).
  const titleId = useId();
  const dialogRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    dialogRef.current?.focus();
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [onClose]);

  const selected = useMemo(() => obligations.find((o) => o.id === entryId), [obligations, entryId]);

  async function save() {
    if (!selected) {
      toast('Pick an obligation to settle', 'error');
      return;
    }
    setSaving(true);
    try {
      await landlordApi.recordLedgerPayment(selected.id, { method, reference: reference.trim() || undefined });
      toast('Payment recorded', 'success');
      onDone();
    } catch {
      toast('Could not record the payment', 'error');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="wled">
      <div className="scrim on" onClick={(e) => e.target === e.currentTarget && onClose()}>
        <div ref={dialogRef} tabIndex={-1} className="modal" role="dialog" aria-modal="true" aria-labelledby={titleId}>
          <div className="mhead">
            <div className="eyebrow">Offline payment</div>
            <h3 id={titleId}>Record payment</h3>
            <p>
              Log a cash, mobile money, or bank transfer paid outside the app{tenantName ? ` by ${tenantName}` : ''}. This
              settles the obligation in full and posts a matching payment entry to the ledger.
            </p>
          </div>
          <div className="mbody">
            <div className="mnote">
              {I.info}
              <div>Partial payments aren't supported — the full amount of the selected charge is recorded.</div>
            </div>

            <div className="field">
              <label>Obligation to settle</label>
              <select value={entryId} onChange={(e) => setEntryId(e.target.value)}>
                {obligations.map((o) => (
                  <option key={o.id} value={o.id}>
                    {ENTRY[o.type].label} · {o.reference} · {formatCents(o.display_amount_cents)}
                  </option>
                ))}
              </select>
            </div>

            <div className="field">
              <label>Method</label>
              <select value={method} onChange={(e) => setMethod(e.target.value as PaymentMethod)}>
                {METHODS.map((m) => (
                  <option key={m.value} value={m.value}>
                    {m.label}
                  </option>
                ))}
              </select>
            </div>

            <div className="field">
              <label>Reference (optional, recorded on the entry)</label>
              <input
                value={reference}
                onChange={(e) => setReference(e.target.value)}
                placeholder="e.g. MTN-TX-5731209, GCB deposit slip #…"
                maxLength={100}
              />
            </div>
          </div>
          <div className="mfoot">
            <button className="btn btn-g" onClick={onClose} disabled={saving}>
              Cancel
            </button>
            <button className="btn btn-p" onClick={save} disabled={saving || !selected}>
              {I.check} {selected ? `Record ${formatCents(selected.display_amount_cents)}` : 'Record payment'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
