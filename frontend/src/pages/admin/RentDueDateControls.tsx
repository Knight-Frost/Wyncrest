/**
 * RentDueDateControls — the two admin due-date controls (launch closeout).
 *
 *  - AdjustDueDateModal: move ONE unpaid rent charge's due date.
 *  - RentSchedulePanel:  from a future billing month, rent for a lease is due
 *                        on day N (or back to the first-of-month default).
 *
 * Both are preview-then-confirm. The preview is computed by the API (the same
 * calculator that posts charges), and its compare-and-swap token is echoed on
 * submit so a stale preview is refused (409) instead of overwriting someone
 * else's change. Nothing here re-derives dates client-side.
 */
import { useEffect, useState } from 'react';
import { adminApi } from '@/lib/endpoints';
import { normalizeError } from '@/lib/api';
import { formatCents, formatDate, humanize } from '@/lib/format';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { useApi } from '@/hooks/useApi';
import type { DueDateAdjustmentPreview, LedgerEntry, RentSchedule, RentScheduleChangePreview } from '@/lib/types';
import './rent-due-date.css';

function ProblemList({ problems }: { problems: string[] }) {
  if (!problems.length) return null;
  return (
    <div className="wrdd-alert wrdd-alert-danger" role="alert">
      {problems.map((p) => (
        <p key={p}>{p}</p>
      ))}
    </div>
  );
}

function ReasonField({ value, onChange, id }: { value: string; onChange: (v: string) => void; id: string }) {
  return (
    <label className="wrdd-field" htmlFor={id}>
      <span className="wrdd-label">Reason (kept permanently in the audit log)</span>
      <textarea
        id={id}
        rows={3}
        value={value}
        maxLength={500}
        placeholder="e.g. Tenant's salary is paid late this month; one-time extension agreed by phone."
        onChange={(e) => onChange(e.target.value)}
      />
    </label>
  );
}

/* ───────────────────────── One charge ───────────────────────── */

export function AdjustDueDateModal({
  entry,
  open,
  onClose,
  onDone,
}: {
  entry: LedgerEntry;
  open: boolean;
  onClose: () => void;
  onDone: () => void;
}) {
  const [date, setDate] = useState('');
  const [reason, setReason] = useState('');
  const [preview, setPreview] = useState<DueDateAdjustmentPreview | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setDate('');
      setReason('');
      setPreview(null);
      setError(null);
    }
  }, [open]);

  async function runPreview(value = date) {
    if (!value) return;
    setBusy(true);
    setError(null);
    try {
      setPreview(await adminApi.previewDueDate(entry.id, value));
    } catch (e) {
      setError(normalizeError(e).message);
    } finally {
      setBusy(false);
    }
  }

  async function confirm() {
    if (!preview) return;
    setBusy(true);
    setError(null);
    try {
      await adminApi.adjustDueDate(entry.id, {
        due_date: date,
        reason: reason.trim(),
        expected_due_date: preview.expected_due_date ?? '',
        expected_status: preview.expected_status,
      });
      onDone();
      onClose();
    } catch (e) {
      const err = normalizeError(e);
      setError(err.message);
      if (err.status === 409) setPreview(null); // force a fresh preview
    } finally {
      setBusy(false);
    }
  }

  const canConfirm = !!preview?.eligible && reason.trim().length >= 5 && !busy;

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="md"
      title="Adjust due date"
      description="Moves when this one charge is due. The amount, billing period and invoice reference never change."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button onClick={confirm} disabled={!canConfirm} loading={busy && !!preview}>
            Confirm new due date
          </Button>
        </>
      }
    >
      <div className="wrdd-stack">
        <div className="wrdd-grid2">
          <div>
            <span className="wrdd-label">Charge</span>
            <div className="wrdd-val">
              {entry.reference} · {formatCents(entry.display_amount_cents)}
            </div>
          </div>
          <div>
            <span className="wrdd-label">Billing period</span>
            <div className="wrdd-val">
              {entry.billing_period_start ? formatDate(entry.billing_period_start) : '—'} –{' '}
              {entry.billing_period_end ? formatDate(entry.billing_period_end) : '—'}
            </div>
          </div>
        </div>

        <div className="wrdd-row">
          <label className="wrdd-field" htmlFor="wrdd-date">
            <span className="wrdd-label">New due date</span>
            <input
              id="wrdd-date"
              type="date"
              value={date}
              min={preview?.window.earliest}
              max={preview?.window.latest}
              onChange={(e) => {
                setDate(e.target.value);
                setPreview(null);
              }}
            />
          </label>
          <Button variant="secondary" size="sm" onClick={() => runPreview()} disabled={!date || busy} loading={busy && !preview}>
            Preview change
          </Button>
        </div>

        {preview && (
          <>
            <ProblemList problems={preview.problems} />
            <table className="wrdd-tbl">
              <thead>
                <tr>
                  <th />
                  <th>Now</th>
                  <th>After</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <th scope="row">Due date</th>
                  <td>{preview.current_due_date ? formatDate(preview.current_due_date) : '—'}</td>
                  <td className="wrdd-strong">{preview.proposed_due_date ? formatDate(preview.proposed_due_date) : '—'}</td>
                </tr>
                <tr>
                  <th scope="row">Status</th>
                  <td>{humanize(preview.current_status)}</td>
                  <td className="wrdd-strong">{preview.resulting_status ? humanize(preview.resulting_status) : '—'}</td>
                </tr>
                {preview.original_due_date && preview.original_due_date !== preview.current_due_date && (
                  <tr>
                    <th scope="row">Originally posted</th>
                    <td colSpan={2}>{formatDate(preview.original_due_date)}</td>
                  </tr>
                )}
              </tbody>
            </table>
            {preview.eligible && (
              <ul className="wrdd-impact">
                {preview.impact.map((line) => (
                  <li key={line}>{line}</li>
                ))}
              </ul>
            )}
            {preview.eligible && <ReasonField id="wrdd-reason" value={reason} onChange={setReason} />}
          </>
        )}
        {error && (
          <div className="wrdd-alert wrdd-alert-danger" role="alert">
            <p>{error}</p>
          </div>
        )}
      </div>
    </Modal>
  );
}

/* ───────────────────────── Future schedule ───────────────────────── */

const DAYS = Array.from({ length: 31 }, (_, i) => i + 1);

function ordinal(n: number): string {
  const s = ['th', 'st', 'nd', 'rd'];
  const v = n % 100;
  return `${n}${s[(v - 20) % 10] || s[v] || s[0]}`;
}

function monthLabel(ym: string): string {
  const [y, m] = ym.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, 1)).toLocaleDateString(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' });
}

export function RentSchedulePanel({ contractId, canManage }: { contractId: string; canManage: boolean }) {
  const { data: schedule, loading, error, reload } = useApi<RentSchedule>(() => adminApi.rentSchedule(contractId), [contractId]);
  const [mode, setMode] = useState<'idle' | 'change' | 'reset'>('idle');
  const [day, setDay] = useState(1);
  const [month, setMonth] = useState('');
  const [reason, setReason] = useState('');
  const [preview, setPreview] = useState<RentScheduleChangePreview | null>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ tone: 'ok' | 'danger'; text: string } | null>(null);

  function start(next: 'change' | 'reset') {
    setMode(next);
    setDay(next === 'reset' ? (schedule?.default_due_day ?? 1) : (schedule?.current_due_day ?? 1));
    setMonth(schedule?.earliest_effective_month ?? '');
    setReason('');
    setPreview(null);
    setMessage(null);
  }

  async function runPreview() {
    if (!month) return;
    setBusy(true);
    setMessage(null);
    try {
      setPreview(await adminApi.previewRentSchedule(contractId, day, month));
    } catch (e) {
      setMessage({ tone: 'danger', text: normalizeError(e).message });
    } finally {
      setBusy(false);
    }
  }

  async function confirm() {
    if (!preview) return;
    setBusy(true);
    setMessage(null);
    try {
      const body = { effective_month: month, reason: reason.trim(), expected_version: preview.expected_version };
      const res =
        mode === 'reset'
          ? await adminApi.resetRentSchedule(contractId, body)
          : await adminApi.changeRentSchedule(contractId, { ...body, due_day: day });
      setMessage({ tone: 'ok', text: res.message });
      setMode('idle');
      setPreview(null);
      reload();
    } catch (e) {
      const err = normalizeError(e);
      setMessage({ tone: 'danger', text: err.message });
      if (err.status === 409) {
        setPreview(null);
        reload();
      }
    } finally {
      setBusy(false);
    }
  }

  if (loading) return <div className="wrdd-muted">Loading rent schedule…</div>;
  if (error || !schedule) return <div className="wrdd-alert wrdd-alert-danger">{error?.message ?? 'Could not load the rent schedule.'}</div>;

  const isDefault = schedule.current_due_day === schedule.default_due_day && schedule.timeline.every((s) => s.due_day === schedule.default_due_day);

  return (
    <div className="wrdd-stack">
      <div className="wrdd-facts">
        <div>
          <span className="wrdd-label">Rent due</span>
          <div className="wrdd-val wrdd-strong">The {ordinal(schedule.current_due_day)} of each month</div>
        </div>
        <div>
          <span className="wrdd-label">Billing periods</span>
          <div className="wrdd-val">{schedule.billing_anchor_label}</div>
        </div>
        <div>
          <span className="wrdd-label">Overdue after</span>
          <div className="wrdd-val">
            {schedule.grace_days === 0 ? 'The due date' : `${schedule.grace_days} day(s) past the due date`} ({schedule.billing_timezone})
          </div>
        </div>
      </div>

      {schedule.upcoming.length > 0 && (
        <table className="wrdd-tbl">
          <caption className="wrdd-caption">Next charges not yet issued</caption>
          <thead>
            <tr>
              <th>Billing period</th>
              <th>Due</th>
              <th className="wrdd-num">Amount</th>
            </tr>
          </thead>
          <tbody>
            {schedule.upcoming.map((p) => (
              <tr key={p.billing_period_start}>
                <td>
                  {formatDate(p.billing_period_start)} – {formatDate(p.billing_period_end)}
                </td>
                <td>{formatDate(p.due_date)}</td>
                <td className="wrdd-num">
                  {formatCents(p.amount_cents)}
                  {p.prorated && <span className="wrdd-tag">prorated</span>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {schedule.history.length > 0 && (
        <div>
          <span className="wrdd-label">Schedule changes</span>
          <ul className="wrdd-history">
            {schedule.history.map((h) => (
              <li key={h.id}>
                From <b>{monthLabel(h.effective_from.slice(0, 7))}</b>: {h.is_default ? 'back to the 1st (default)' : `day ${h.due_day}`}
                <span className="wrdd-muted">
                  {' '}
                  · {h.admin ?? 'Admin'} · {h.created_at ? formatDate(h.created_at) : ''} · “{h.reason}”
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}

      {message && (
        <div className={`wrdd-alert ${message.tone === 'ok' ? 'wrdd-alert-ok' : 'wrdd-alert-danger'}`} role="status">
          <p>{message.text}</p>
        </div>
      )}

      {!canManage && (
        <p className="wrdd-muted">Changing when rent is due needs the “Manage ledger” permission.</p>
      )}
      {canManage && !schedule.changeable && <ProblemList problems={schedule.problems} />}

      {canManage && schedule.changeable && mode === 'idle' && (
        <div className="wrdd-row">
          <Button size="sm" variant="secondary" onClick={() => start('change')}>
            Change future due dates
          </Button>
          {!isDefault && (
            <Button size="sm" variant="secondary" onClick={() => start('reset')}>
              Return to the 1st (default)
            </Button>
          )}
        </div>
      )}

      {canManage && schedule.changeable && mode !== 'idle' && (
        <div className="wrdd-form">
          <div className="wrdd-row">
            {mode === 'change' && (
              <label className="wrdd-field" htmlFor="wrdd-day">
                <span className="wrdd-label">Due day</span>
                <select
                  id="wrdd-day"
                  value={day}
                  onChange={(e) => {
                    setDay(Number(e.target.value));
                    setPreview(null);
                  }}
                >
                  {DAYS.map((d) => (
                    <option key={d} value={d}>
                      {ordinal(d)}
                    </option>
                  ))}
                </select>
              </label>
            )}
            <label className="wrdd-field" htmlFor="wrdd-month">
              <span className="wrdd-label">From billing month</span>
              <input
                id="wrdd-month"
                type="month"
                value={month}
                min={schedule.earliest_effective_month ?? undefined}
                onChange={(e) => {
                  setMonth(e.target.value);
                  setPreview(null);
                }}
              />
            </label>
            <Button size="sm" variant="secondary" onClick={runPreview} disabled={!month || busy} loading={busy && !preview}>
              Preview
            </Button>
          </div>
          <p className="wrdd-muted">
            The earliest month is {schedule.earliest_effective_month ? monthLabel(schedule.earliest_effective_month) : '—'}. Charges already
            issued keep their dates — use “Adjust due date” on the charge instead.
          </p>

          {preview && (
            <>
              <ProblemList problems={preview.problems} />
              {preview.eligible && (
                <>
                  <table className="wrdd-tbl">
                    <caption className="wrdd-caption">
                      First affected: {preview.first_affected_period_start ? formatDate(preview.first_affected_period_start) : '—'}
                    </caption>
                    <thead>
                      <tr>
                        <th>Billing period</th>
                        <th>Due now</th>
                        <th>Due after</th>
                      </tr>
                    </thead>
                    <tbody>
                      {preview.periods.map((p) => (
                        <tr key={p.billing_period_start}>
                          <td>
                            {formatDate(p.billing_period_start)} – {formatDate(p.billing_period_end)}
                          </td>
                          <td>{p.current_due_date ? formatDate(p.current_due_date) : '—'}</td>
                          <td className="wrdd-strong">
                            {formatDate(p.due_date)}
                            {p.clamped && <span className="wrdd-tag">short month</span>}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                  <ul className="wrdd-impact">
                    {preview.notes.map((n) => (
                      <li key={n}>{n}</li>
                    ))}
                  </ul>
                  <ReasonField id="wrdd-sreason" value={reason} onChange={setReason} />
                </>
              )}
            </>
          )}

          <div className="wrdd-row">
            <Button size="sm" variant="secondary" onClick={() => setMode('idle')} disabled={busy}>
              Cancel
            </Button>
            <Button size="sm" onClick={confirm} disabled={!preview?.eligible || reason.trim().length < 5 || busy} loading={busy && !!preview}>
              {mode === 'reset' ? 'Confirm return to the 1st' : 'Confirm schedule change'}
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
