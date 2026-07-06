/**
 * TenantDetail — Tenant File
 *
 * Faithful port of wyncrest-landlord-tenants.html's tenant-file view
 * (`#/t/:id`), rebuilt on 100% real data at `/app/tenants/:contractId`.
 * Tabs: Overview / Lease / Rent / Maintenance / Messages / Notes. Five action
 * flows collapse to four real modals — record payment / renew / move-out
 * (= the existing immediate `terminateContract`) / add note — because "send
 * reminder" reuses the real Messages thread instead of being a fire-and-
 * forget action with nothing to look back on (see project plan).
 *
 * Deliberately dropped vs the mockup: partial payments (not backend-
 * supported), a simulated signed-lease PDF viewer, a scheduled future
 * move-out date, and deposit-escrow tracking (Unit.security_deposit is only
 * a static advertised amount, never held/tracked).
 */
import { useEffect, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { landlordApi } from '@/lib/endpoints';
import { fieldErrors } from '@/lib/api';
import type { ApiError, ContractLandlordNote, ContractMessage, MaintenanceRequest, PaymentMethod } from '@/lib/types';
import { formatCents, formatDate, formatDateTime, daysUntil, humanize, formatDollars } from '@/lib/format';
import { Modal } from '@/components/ui/Modal';
import { useToast } from '@/components/ui/toast';
import {
  IconBack,
  IconPhone,
  IconMail,
  IconBell,
  IconCash,
  IconRenew,
  IconOut,
  IconNote,
  IconCheck,
  IconWrench,
  IconDoc,
  IconShield,
  IconInfo,
  IconWarn,
  IconPlus,
  IconUsers,
  IconMsg,
} from './tenant-management-ui';
import {
  derivePaymentPosture,
  deriveRenewalStatus,
  deriveOnTimeRecord,
  healthClass,
  contractLocation,
  buildTenancyTimeline,
  relativeDays,
  avatarStyle,
  initials,
  ledgerMethodLabel,
  PAYMENT_METHOD_OPTIONS,
  RENT_LABEL,
  RENT_BADGE,
  RENEWAL_LABEL,
  RENEWAL_BADGE,
} from './tenantHelpers';
import './tenant-management.css';

type Tab = 'overview' | 'lease' | 'rent' | 'maint' | 'messages' | 'notes';

const TABS: { key: Tab; label: string }[] = [
  { key: 'overview', label: 'Overview' },
  { key: 'lease', label: 'Lease' },
  { key: 'rent', label: 'Rent' },
  { key: 'maint', label: 'Maintenance' },
  { key: 'messages', label: 'Messages' },
  { key: 'notes', label: 'Notes' },
];

const MAINT_STYLE: Record<string, { badge: string; ic: string }> = {
  open: { badge: 'b-red', ic: 'rgba(138,36,54,.12)' },
  acknowledged: { badge: 'b-blue', ic: 'rgba(35,89,107,.12)' },
  in_progress: { badge: 'b-amber', ic: 'rgba(154,106,30,.12)' },
  resolved: { badge: 'b-green', ic: 'rgba(44,122,87,.12)' },
  closed: { badge: 'b-gray', ic: 'rgba(91,107,114,.12)' },
  cancelled: { badge: 'b-gray', ic: 'rgba(91,107,114,.12)' },
};

function Kv({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="kv-row">
      <span className="k">{label}</span>
      <span className="v">{value}</span>
    </div>
  );
}

export function TenantDetail() {
  const { contractId } = useParams();
  const id = contractId ?? '';
  const navigate = useNavigate();
  const { toast } = useToast();
  const [searchParams, setSearchParams] = useSearchParams();

  const contractQ = useApi(() => landlordApi.contract(id), [id]);
  const ledgerQ = useApi(() => landlordApi.ledger(), []);
  const maintenanceQ = useApi(() => landlordApi.maintenance(), []);
  const notesQ = useApi(() => landlordApi.contractNotes(id), [id]);

  const [tab, setTab] = useState<Tab>(() => {
    const t = searchParams.get('tab');
    return (['overview', 'lease', 'rent', 'maint', 'messages', 'notes'] as string[]).includes(t ?? '')
      ? (t as Tab)
      : 'overview';
  });

  const [thread, setThread] = useState<ContractMessage[] | null>(null);
  const [threadLoading, setThreadLoading] = useState(false);
  const [messageBody, setMessageBody] = useState('');
  const [sendingMessage, setSendingMessage] = useState(false);

  const [paymentOpen, setPaymentOpen] = useState(false);
  const [renewOpen, setRenewOpen] = useState(false);
  const [moveoutOpen, setMoveoutOpen] = useState(false);
  const [noteOpen, setNoteOpen] = useState(false);

  // Consume the roster's ?tab=&reminder= deep link once, then clean the URL.
  useEffect(() => {
    if (!contractQ.data) return;
    const wantsReminder = searchParams.get('reminder') === '1';
    if (wantsReminder) {
      setTab('messages');
      const tenantFirst = contractQ.data.tenant?.full_name?.split(' ')[0] ?? 'there';
      const entries = (ledgerQ.data?.entries ?? []).filter((e) => e.contract_id === id);
      const posture = derivePaymentPosture(entries);
      setMessageBody(
        posture.outstandingCents > 0
          ? `Hi ${tenantFirst}, a quick reminder that ${formatCents(posture.outstandingCents)} rent is ${
              posture.status === 'overdue' ? 'now overdue' : 'coming due soon'
            }. Please let me know if there is anything I can help with.`
          : `Hi ${tenantFirst}, just checking in on your tenancy — let me know if you need anything.`,
      );
    }
    if (searchParams.has('reminder') || searchParams.has('tab')) {
      const next = new URLSearchParams(searchParams);
      next.delete('reminder');
      next.delete('tab');
      setSearchParams(next, { replace: true });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [contractQ.data]);

  useEffect(() => {
    if (tab !== 'messages' || !contractQ.data || thread) return;
    setThreadLoading(true);
    landlordApi
      .contractMessages(id)
      .then((res) => setThread(res.messages))
      .catch(() => toast('Could not load messages.', 'error'))
      .finally(() => setThreadLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab, contractQ.data]);

  const isLoading = contractQ.loading || ledgerQ.loading || maintenanceQ.loading;
  const primaryError = contractQ.error ?? ledgerQ.error ?? maintenanceQ.error;

  if (isLoading) {
    return (
      <div className="wtenant">
        <p style={{ textAlign: 'center', color: 'var(--wt-slate)', padding: '3rem' }}>Loading tenant file…</p>
      </div>
    );
  }
  if (primaryError || !contractQ.data) {
    return (
      <div className="wtenant">
        <button className="back" onClick={() => navigate('/app/tenants')}>
          <IconBack /> All tenants
        </button>
        <div className="empty glass">
          <div className="ei">
            <IconUsers />
          </div>
          <div className="et">Tenant not found</div>
          <div className="em">
            {primaryError?.message ?? 'This tenancy may have been archived. Return to the roster to see active tenancies.'}
          </div>
        </div>
      </div>
    );
  }

  const contract = contractQ.data;
  const tenant = contract.tenant;
  const tenantName = tenant?.full_name ?? 'Tenant unavailable';
  const location = contractLocation(contract);
  const entries = (ledgerQ.data?.entries ?? []).filter((e) => e.contract_id === contract.id);
  const posture = derivePaymentPosture(entries);
  const renewalStatus = deriveRenewalStatus(contract);
  const onTime = deriveOnTimeRecord(entries);
  const maintenance = (maintenanceQ.data ?? []).filter((m) => m.contract_id === contract.id);
  const openMaint = maintenance.filter((m) => ['open', 'acknowledged', 'in_progress'].includes(m.status)).length;
  const notes = notesQ.data ?? [];
  const isActive = contract.status === 'active';
  const canRecordPayment = posture.openEntries.length > 0;

  function reloadLedger() {
    ledgerQ.reload();
  }
  function reloadContract() {
    contractQ.reload();
  }

  async function sendMessage() {
    const body = messageBody.trim();
    if (!body) return;
    setSendingMessage(true);
    try {
      const res = await landlordApi.sendContractMessage(contract.id, body);
      setThread(res.messages);
      setMessageBody('');
    } catch {
      toast('Could not send the message.', 'error');
    } finally {
      setSendingMessage(false);
    }
  }

  function openReminder() {
    setTab('messages');
    if (!messageBody.trim()) {
      const tenantFirst = tenantName.split(' ')[0];
      setMessageBody(
        posture.outstandingCents > 0
          ? `Hi ${tenantFirst}, a quick reminder that ${formatCents(posture.outstandingCents)} rent is ${
              posture.status === 'overdue' ? 'now overdue' : 'coming due soon'
            }. Please let me know if there is anything I can help with.`
          : `Hi ${tenantFirst}, just checking in on your tenancy — let me know if you need anything.`,
      );
    }
  }

  const tabsWithCounts: { key: Tab; label: string; n: number | null }[] = TABS.map((t) => ({
    ...t,
    n: t.key === 'maint' ? openMaint || null : t.key === 'notes' ? notes.length || null : null,
  }));

  return (
    <div className="wtenant animate-rise">
      <button className="back" onClick={() => navigate('/app/tenants')}>
        <IconBack /> All tenants
      </button>

      <div className="fhead glass">
        <div className="tav" style={avatarStyle(tenantName)}>
          {initials(tenantName)}
        </div>
        <div className="fhinfo">
          <h2>{tenantName}</h2>
          <div className="prop">
            {location.property}
            {location.unit ? ` · Unit ${location.unit}` : ''}
            {location.city ? ` · ${location.city}` : ''}
          </div>
          <div style={{ display: 'flex', gap: 8, marginTop: 9, flexWrap: 'wrap' }}>
            {isActive ? (
              <span className={`badge ${RENT_BADGE[posture.status]}`}>
                <span className="dot" />
                {RENT_LABEL[posture.status]}
              </span>
            ) : (
              <span className="badge b-gray">{humanize(contract.status)}</span>
            )}
            {isActive && (
              <span className={`badge ${RENEWAL_BADGE[renewalStatus]}`}>{RENEWAL_LABEL[renewalStatus]}</span>
            )}
            {tenant?.identity_verified && (
              <span className="badge b-blue">
                <IconShield /> Verified by Wyncrest
              </span>
            )}
            <span className="badge b-gray">Tenant since {new Date(contract.start_date).getFullYear()}</span>
          </div>
          <div className="fhcontact">
            {tenant?.phone && (
              <a href={`tel:${tenant.phone}`}>
                <IconPhone /> {tenant.phone}
              </a>
            )}
            {tenant?.email && (
              <a href={`mailto:${tenant.email}`}>
                <IconMail /> {tenant.email}
              </a>
            )}
          </div>
        </div>
        <div className="fhactions">
          <div className="row">
            <button className="btn btn-g sm" onClick={openReminder}>
              <IconBell /> Reminder
            </button>
            <button className="btn btn-p sm" disabled={!canRecordPayment} onClick={() => setPaymentOpen(true)}>
              <IconCash /> Record payment
            </button>
          </div>
          <div className="row">
            {isActive ? (
              <>
                <button className="btn btn-g sm" onClick={() => setRenewOpen(true)}>
                  <IconRenew /> Renew lease
                </button>
                <button className="btn btn-g sm" onClick={() => setMoveoutOpen(true)}>
                  <IconOut /> Move-out
                </button>
              </>
            ) : (
              <button className="btn btn-g sm" onClick={() => setNoteOpen(true)}>
                <IconNote /> Add note
              </button>
            )}
          </div>
        </div>
      </div>

      <div className="tabs glass-2">
        {tabsWithCounts.map((t) => (
          <button key={t.key} className={tab === t.key ? 'on' : ''} onClick={() => setTab(t.key)}>
            {t.label}
            {t.n ? <span className="n">{t.n}</span> : null}
          </button>
        ))}
      </div>

      {tab === 'overview' && (
        <div className="grid2">
          <div className="col">
            <div className="panel glass">
              <div className="ph">
                <h3>Where things stand</h3>
              </div>
              <div className={`health ${healthClass(onTime.rate)}`}>
                <Ring rate={onTime.rate} />
                <div className="txt">
                  <div className="t">On-time payment record</div>
                  <div className="d">
                    {onTime.onTime} of {onTime.total} settled payment{onTime.total === 1 ? '' : 's'} on time
                  </div>
                </div>
              </div>
              <div style={{ marginTop: 14 }}>
                <Kv
                  label="Rent standing"
                  value={
                    isActive ? (
                      <span className={`badge ${RENT_BADGE[posture.status]}`}>
                        <span className="dot" />
                        {RENT_LABEL[posture.status]}
                      </span>
                    ) : (
                      humanize(contract.status)
                    )
                  }
                />
                <Kv
                  label="Current balance"
                  value={
                    posture.outstandingCents > 0 ? (
                      <span style={{ color: 'var(--wt-oxblood)' }}>{formatCents(posture.outstandingCents)}</span>
                    ) : (
                      formatCents(0)
                    )
                  }
                />
                <Kv
                  label="Next rent due"
                  value={
                    posture.nextPayment
                      ? `${formatCents(posture.nextPayment.amountCents)} · ${
                          posture.nextPayment.dueDate ? formatDate(posture.nextPayment.dueDate) : '—'
                        }`
                      : 'All settled'
                  }
                />
                <Kv
                  label="Lease"
                  value={
                    isActive
                      ? `Ends ${formatDate(contract.end_date)} · ${relativeDays(daysUntil(contract.end_date))}`
                      : `Ended ${formatDate(contract.end_date)}`
                  }
                />
                <Kv label="Open maintenance" value={openMaint ? `${openMaint} request${openMaint > 1 ? 's' : ''}` : 'None'} />
              </div>
            </div>
            <div className="panel glass">
              <div className="ph">
                <h3>Tenancy timeline</h3>
              </div>
              <div className="tl">
                {buildTenancyTimeline(contract, entries).map((ev, i) => (
                  <div key={i} className={`ev ${ev.tone}`}>
                    <div className="et">{ev.title}</div>
                    <div className="em">{ev.detail}</div>
                    <div className="ed">{ev.dateLabel}</div>
                  </div>
                ))}
              </div>
            </div>
          </div>
          <div className="col">
            <div className="panel glass">
              <div className="ph">
                <h3>Quick actions</h3>
              </div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 9 }}>
                <button className="btn btn-p" disabled={!canRecordPayment} onClick={() => setPaymentOpen(true)} style={{ justifyContent: 'flex-start' }}>
                  <IconCash /> Record a payment
                </button>
                <button className="btn btn-g" onClick={openReminder} style={{ justifyContent: 'flex-start' }}>
                  <IconBell /> Send a rent reminder
                </button>
                {isActive && (
                  <>
                    <button className="btn btn-g" onClick={() => setRenewOpen(true)} style={{ justifyContent: 'flex-start' }}>
                      <IconRenew /> Start a renewal
                    </button>
                    <button className="btn btn-g" onClick={() => setMoveoutOpen(true)} style={{ justifyContent: 'flex-start' }}>
                      <IconOut /> Begin move-out
                    </button>
                  </>
                )}
                <button className="btn btn-g" onClick={() => setNoteOpen(true)} style={{ justifyContent: 'flex-start' }}>
                  <IconNote /> Add an internal note
                </button>
              </div>
            </div>
            <div className="panel glass">
              <div className="ph">
                <h3>Contact</h3>
              </div>
              <Kv label="Phone" value={tenant?.phone ? <a href={`tel:${tenant.phone}`} style={{ color: 'var(--wt-petrol-2)' }}>{tenant.phone}</a> : '—'} />
              <Kv label="Email" value={tenant?.email ? <a href={`mailto:${tenant.email}`} style={{ color: 'var(--wt-petrol-2)' }}>{tenant.email}</a> : '—'} />
              <Kv label="Preferred" value="Message via Wyncrest" />
            </div>
          </div>
        </div>
      )}

      {tab === 'lease' && (
        <div className="grid2">
          <div className="panel glass">
            {(renewalStatus === 'up_for_renewal' || renewalStatus === 'holdover') && isActive && (
              <div className="notice">
                <IconWarn />
                <div className="nt">
                  <b>Renewal decision needed.</b>{' '}
                  {renewalStatus === 'holdover'
                    ? `This lease reached holdover ${Math.abs(daysUntil(contract.end_date) ?? 0)} days ago.`
                    : `This lease ends in ${daysUntil(contract.end_date)} days.`}{' '}
                  Renew the term or begin a move-out.
                </div>
              </div>
            )}
            {!isActive && (
              <div className="notice">
                <IconInfo />
                <div className="nt">
                  <b>This tenancy has ended.</b> Status: {humanize(contract.status)}
                  {contract.termination_reason ? ` — ${contract.termination_reason}` : ''}
                </div>
              </div>
            )}
            <div className="ph">
              <h3>Lease terms</h3>
              {isActive && <span className={`badge ${RENEWAL_BADGE[renewalStatus]}`}>{RENEWAL_LABEL[renewalStatus]}</span>}
            </div>
            <Kv label="Property" value={`${location.property}${location.unit ? ' · Unit ' + location.unit : ''}`} />
            <Kv label="Start date" value={formatDate(contract.start_date)} />
            <Kv label="End date" value={formatDate(contract.end_date)} />
            <Kv label="Monthly rent" value={formatCents(contract.rent_amount)} />
            <Kv label="Payment day" value={`Day ${contract.payment_day}`} />
            <Kv label="Billing cycle" value={humanize(contract.billing_cycle)} />
            {contract.listing?.unit?.security_deposit && (
              <Kv label="Security deposit (advertised)" value={formatDollars(contract.listing.unit.security_deposit)} />
            )}
          </div>
          <div className="col">
            <div className="panel glass">
              <div className="ph">
                <h3>Verification</h3>
              </div>
              <div className="mcard">
                <div className="mi" style={{ background: tenant?.identity_verified ? 'rgba(44,122,87,.12)' : 'rgba(91,107,114,.12)', color: tenant?.identity_verified ? 'var(--wt-green)' : 'var(--wt-slate)' }}>
                  <IconShield />
                </div>
                <div style={{ flex: 1 }}>
                  <div className="mt">Identity verification</div>
                  <div className="mm">
                    {tenant?.identity_verified
                      ? 'Verified by Wyncrest. You see verified status only, never the underlying documents.'
                      : 'Not yet verified by Wyncrest.'}
                  </div>
                </div>
              </div>
            </div>
            <div className="panel glass">
              <div className="ph">
                <h3>Lifecycle</h3>
              </div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 9 }}>
                {isActive ? (
                  <>
                    <button className="btn btn-p" onClick={() => setRenewOpen(true)} style={{ justifyContent: 'flex-start' }}>
                      <IconRenew /> Renew this lease
                    </button>
                    <button className="btn btn-g" onClick={() => setMoveoutOpen(true)} style={{ justifyContent: 'flex-start' }}>
                      <IconOut /> Begin move-out
                    </button>
                  </>
                ) : (
                  <button className="btn btn-g" onClick={() => setNoteOpen(true)} style={{ justifyContent: 'flex-start' }}>
                    <IconNote /> Add a note
                  </button>
                )}
              </div>
            </div>
          </div>
        </div>
      )}

      {tab === 'rent' && (
        <div className="grid2">
          <div className="panel glass">
            <div className="ph">
              <h3>Payment ledger</h3>
              <button className="btn btn-p sm" disabled={!canRecordPayment} onClick={() => setPaymentOpen(true)}>
                <IconPlus /> Record payment
              </button>
            </div>
            {entries.length === 0 ? (
              <p style={{ color: 'var(--wt-slate)', fontSize: 13.5, padding: '12px 2px' }}>No ledger entries yet.</p>
            ) : (
              <div className="ledger">
                {[...entries]
                  .sort((a, b) => new Date(b.occurred_at).getTime() - new Date(a.occurred_at).getTime())
                  .map((e) => {
                    const cr = e.direction === 'payment' || e.direction === 'refund';
                    return (
                      <div className="led" key={e.id}>
                        <div className="ic" style={{ background: cr ? 'rgba(44,122,87,.12)' : 'rgba(91,107,114,.12)', color: cr ? 'var(--wt-green)' : 'var(--wt-slate)' }}>
                          {cr ? <IconCash /> : <IconDoc />}
                        </div>
                        <div>
                          <div className="d1">{e.display_label}</div>
                          <div className="d2">
                            {ledgerMethodLabel(e)} · {e.reference ?? e.id.slice(0, 8)}
                          </div>
                        </div>
                        <div className="d2" style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                          {formatDate(e.occurred_at)}
                        </div>
                        <div className={`amt ${cr ? 'cr' : 'db'}`}>
                          {cr ? '+ ' : ''}
                          {formatCents(e.display_amount_cents)}
                        </div>
                      </div>
                    );
                  })}
              </div>
            )}
          </div>
          <div className="col">
            <div className="panel glass">
              <div className="ph">
                <h3>Standing</h3>
              </div>
              <div className={`health ${posture.status === 'overdue' ? 'low' : posture.status === 'due_soon' ? 'mid' : 'ok'}`} style={{ marginBottom: 14 }}>
                <div className="txt">
                  <div className="t" style={{ fontSize: 22, fontFamily: 'var(--wt-disp)', fontWeight: 800 }}>
                    {posture.outstandingCents > 0 ? formatCents(posture.outstandingCents) : 'Paid up'}
                  </div>
                  <div className="d">{posture.outstandingCents > 0 ? 'outstanding balance' : 'no balance owed'}</div>
                </div>
              </div>
              <Kv label="Monthly rent" value={formatCents(contract.rent_amount)} />
              <Kv
                label="Status"
                value={
                  isActive ? (
                    <span className={`badge ${RENT_BADGE[posture.status]}`}>
                      <span className="dot" />
                      {RENT_LABEL[posture.status]}
                    </span>
                  ) : (
                    humanize(contract.status)
                  )
                }
              />
              <Kv
                label="Last payment"
                value={(() => {
                  const payments = entries
                    .filter((e) => e.type === 'payment')
                    .sort((a, b) => new Date(b.occurred_at).getTime() - new Date(a.occurred_at).getTime());
                  return payments[0] ? formatDate(payments[0].occurred_at) : '—';
                })()}
              />
              <Kv label="Next due" value={posture.nextPayment?.dueDate ? formatDate(posture.nextPayment.dueDate) : 'None'} />
              <Kv label="On-time record" value={`${onTime.onTime} / ${onTime.total} (${onTime.rate}%)`} />
            </div>
            <div className="panel glass">
              <div className="ph">
                <h3>Actions</h3>
              </div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 9 }}>
                <button className="btn btn-p" disabled={!canRecordPayment} onClick={() => setPaymentOpen(true)} style={{ justifyContent: 'flex-start' }}>
                  <IconCash /> Record a payment
                </button>
                <button className="btn btn-g" onClick={openReminder} style={{ justifyContent: 'flex-start' }}>
                  <IconBell /> Send a reminder
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {tab === 'maint' && (
        <MaintTab requests={maintenance} />
      )}

      {tab === 'messages' && (
        <div className="panel glass" style={{ maxWidth: 760 }}>
          <div className="ph">
            <h3>Conversation</h3>
            <span className="badge b-blue">
              <IconMsg /> Via Wyncrest
            </span>
          </div>
          {threadLoading ? (
            <p style={{ textAlign: 'center', color: 'var(--wt-slate)', padding: '1.5rem' }}>Loading messages…</p>
          ) : (
            <div className="thread">
              {(thread ?? []).length === 0 ? (
                <p style={{ textAlign: 'center', color: 'var(--wt-slate)', padding: '1.5rem' }}>
                  No messages yet. Start a conversation with {tenantName.split(' ')[0]}.
                </p>
              ) : (
                (thread ?? []).map((m) => (
                  <div key={m.id} className={`msg ${m.sender.is_me ? 'you' : 'them'}`}>
                    <div>{m.body}</div>
                    <div className="mmeta">
                      {m.sender.is_me ? 'You' : tenantName.split(' ')[0]} · {formatDateTime(m.created_at)}
                    </div>
                  </div>
                ))
              )}
            </div>
          )}
          <div className="composer">
            <input
              value={messageBody}
              onChange={(e) => setMessageBody(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') sendMessage();
              }}
              placeholder={`Write a message to ${tenantName.split(' ')[0]}…`}
            />
            <button className="btn btn-p" onClick={sendMessage} disabled={sendingMessage}>
              <IconMsg /> {sendingMessage ? 'Sending…' : 'Send'}
            </button>
          </div>
        </div>
      )}

      {tab === 'notes' && (
        <div className="panel glass" style={{ maxWidth: 760 }}>
          <div className="ph">
            <h3>Internal notes</h3>
            <button className="btn btn-p sm" onClick={() => setNoteOpen(true)}>
              <IconPlus /> Add note
            </button>
          </div>
          <div className="notice">
            <IconInfo />
            <div className="nt">
              <b>These notes are private to you.</b> Keep them factual and about the tenancy. Avoid anything
              relating to a person's background or protected characteristics, and remember a tenant can
              request the personal data you hold on them.
            </div>
          </div>
          {notes.length === 0 ? (
            <div className="empty" style={{ padding: 30 }}>
              <div className="et" style={{ fontSize: 16 }}>No notes yet</div>
              <div className="em">Record context that helps you manage this tenancy well.</div>
            </div>
          ) : (
            notes.map((n: ContractLandlordNote) => (
              <div className="note" key={n.id}>
                <div className="nb">{n.body}</div>
                <div className="nf">
                  <span className="who2">{n.landlord?.full_name ?? 'You'}</span>
                  <span>·</span>
                  <span>{formatDate(n.created_at)}</span>
                </div>
              </div>
            ))
          )}
        </div>
      )}

      <RecordPaymentModal
        open={paymentOpen}
        onClose={() => setPaymentOpen(false)}
        tenantFirstName={tenantName.split(' ')[0]}
        openEntries={posture.openEntries}
        onRecorded={() => {
          reloadLedger();
          setPaymentOpen(false);
        }}
      />
      <RenewModal
        open={renewOpen}
        onClose={() => setRenewOpen(false)}
        tenantFirstName={tenantName.split(' ')[0]}
        contractId={contract.id}
        currentEndDate={contract.end_date}
        currentRentCents={contract.rent_amount}
        onRenewed={() => {
          reloadContract();
          setRenewOpen(false);
        }}
      />
      <MoveoutModal
        open={moveoutOpen}
        onClose={() => setMoveoutOpen(false)}
        tenantFirstName={tenantName.split(' ')[0]}
        contractId={contract.id}
        onTerminated={() => {
          reloadContract();
          setMoveoutOpen(false);
        }}
      />
      <NoteModal
        open={noteOpen}
        onClose={() => setNoteOpen(false)}
        tenantFirstName={tenantName.split(' ')[0]}
        contractId={contract.id}
        onAdded={() => {
          notesQ.reload();
          setNoteOpen(false);
          setTab('notes');
        }}
      />
    </div>
  );
}

/* ── Health ring ─────────────────────────────────────────────────────────── */

function Ring({ rate }: { rate: number }) {
  const r = 22;
  const circ = 2 * Math.PI * r;
  const off = circ * (1 - rate / 100);
  return (
    <div className="ring">
      <svg width="52" height="52" viewBox="0 0 52 52">
        <circle cx="26" cy="26" r={r} fill="none" stroke="rgba(22,60,71,.1)" strokeWidth="5" />
        <circle
          cx="26"
          cy="26"
          r={r}
          fill="none"
          stroke="currentColor"
          strokeWidth="5"
          strokeLinecap="round"
          strokeDasharray={circ.toFixed(1)}
          strokeDashoffset={off.toFixed(1)}
        />
      </svg>
      <div className="pct">{rate}%</div>
    </div>
  );
}

/* ── Maintenance tab ─────────────────────────────────────────────────────── */

function MaintTab({ requests }: { requests: MaintenanceRequest[] }) {
  if (requests.length === 0) {
    return (
      <div className="empty glass">
        <div className="ei">
          <IconWrench />
        </div>
        <div className="et">No maintenance on record</div>
        <div className="em">
          When this tenant reports an issue it will appear here, ready to assign to a contractor and track to
          resolution.
        </div>
      </div>
    );
  }
  return (
    <div className="panel glass" style={{ maxWidth: 760 }}>
      <div className="ph">
        <h3>Maintenance requests</h3>
        <span className="badge b-gray">{requests.length} total</span>
      </div>
      {requests.map((m) => {
        const style = MAINT_STYLE[m.status] ?? MAINT_STYLE.open;
        return (
          <div className="mcard" key={m.id}>
            <div className="mi" style={{ background: style.ic }}>
              <IconWrench />
            </div>
            <div style={{ flex: 1 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, alignItems: 'center' }}>
                <div className="mt">{m.title}</div>
                <span className={`badge ${style.badge}`}>{humanize(m.status)}</span>
              </div>
              <div className="mm">{m.description}</div>
              <div className="mf">
                <span>{humanize(m.category)}</span>
                <span>·</span>
                <span>{m.submitted_at ? formatDate(m.submitted_at) : '—'}</span>
              </div>
            </div>
          </div>
        );
      })}
    </div>
  );
}

/* ── Modals ──────────────────────────────────────────────────────────────── */

function RecordPaymentModal({
  open,
  onClose,
  tenantFirstName,
  openEntries,
  onRecorded,
}: {
  open: boolean;
  onClose: () => void;
  tenantFirstName: string;
  openEntries: { id: string; display_label: string; display_amount_cents: number; due_date: string | null }[];
  onRecorded: () => void;
}) {
  const { toast } = useToast();
  const [entryId, setEntryId] = useState('');
  const [method, setMethod] = useState<PaymentMethod>('mobile_money_mtn');
  const [reference, setReference] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!open) return;
    setEntryId(openEntries[0]?.id ?? '');
    setMethod('mobile_money_mtn');
    setReference('');
    setError('');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const selected = openEntries.find((e) => e.id === entryId) ?? null;

  async function submit() {
    if (!selected) return;
    setSubmitting(true);
    setError('');
    try {
      await landlordApi.recordLedgerPayment(selected.id, { method, reference: reference.trim() || undefined });
      toast(`Payment of ${formatCents(selected.display_amount_cents)} recorded`, 'success');
      onRecorded();
    } catch (err) {
      setError((err as ApiError).message || 'Could not record this payment.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal
      open={open}
      onClose={() => !submitting && onClose()}
      title={`Payment from ${tenantFirstName}`}
      description="Log a rent payment you have received. This updates the ledger and the tenant's standing."
      footer={
        <>
          <button className="btn btn-g sm" onClick={onClose} disabled={submitting}>
            Cancel
          </button>
          <button className="btn btn-p sm" onClick={submit} disabled={submitting || !selected}>
            <IconCheck /> {submitting ? 'Recording…' : 'Record payment'}
          </button>
        </>
      }
    >
      {openEntries.length === 0 ? (
        <p style={{ fontSize: 13.5, color: 'var(--wt-slate)' }}>
          There is no open rent or late-fee entry to record a payment against right now.
        </p>
      ) : (
        <>
          {openEntries.length > 1 && (
            <div className="field">
              <label>Which entry is this payment for?</label>
              <div className="pick">
                {openEntries.map((e) => (
                  <button key={e.id} className={entryId === e.id ? 'on' : ''} onClick={() => setEntryId(e.id)}>
                    {e.display_label}
                    <div className="pd">
                      {formatCents(e.display_amount_cents)}
                      {e.due_date ? ` · due ${formatDate(e.due_date)}` : ''}
                    </div>
                  </button>
                ))}
              </div>
            </div>
          )}
          {selected && (
            <div className="field">
              <label>Amount</label>
              <input value={formatCents(selected.display_amount_cents)} disabled />
              <div className="hint">
                The full amount of the selected entry — Wyncrest does not support partial payments.
              </div>
            </div>
          )}
          <div className="field">
            <label>Method</label>
            <div className="pick">
              {PAYMENT_METHOD_OPTIONS.map((o) => (
                <button key={o.value} className={method === o.value ? 'on' : ''} onClick={() => setMethod(o.value)}>
                  {o.label}
                </button>
              ))}
            </div>
          </div>
          <div className="field">
            <label>Reference (optional)</label>
            <input value={reference} onChange={(e) => setReference(e.target.value)} placeholder="e.g. transaction ID" />
          </div>
          {error && <p className="form-error">{error}</p>}
        </>
      )}
    </Modal>
  );
}

function RenewModal({
  open,
  onClose,
  tenantFirstName,
  contractId,
  currentEndDate,
  currentRentCents,
  onRenewed,
}: {
  open: boolean;
  onClose: () => void;
  tenantFirstName: string;
  contractId: string;
  currentEndDate: string | null;
  currentRentCents: number;
  onRenewed: () => void;
}) {
  const { toast } = useToast();
  const [newEndDate, setNewEndDate] = useState('');
  const [newRent, setNewRent] = useState('');
  const [note, setNote] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!open) return;
    // Open-ended lease (no end date): default the new term to a year from today.
    const base = currentEndDate ? new Date(currentEndDate) : new Date();
    base.setFullYear(base.getFullYear() + 1);
    setNewEndDate(base.toISOString().slice(0, 10));
    setNewRent((currentRentCents / 100).toFixed(2));
    setNote('');
    setError('');
  }, [open, currentEndDate, currentRentCents]);

  async function submit() {
    if (!newEndDate) {
      setError('Choose a new end date.');
      return;
    }
    setSubmitting(true);
    setError('');
    try {
      const parsedRent = parseFloat(newRent);
      const newRentCents = Number.isFinite(parsedRent) && parsedRent > 0 ? Math.round(parsedRent * 100) : undefined;
      await landlordApi.renewContract(contractId, {
        new_end_date: newEndDate,
        new_rent_amount: newRentCents,
        note: note.trim() || undefined,
      });
      toast(`Lease renewed for ${tenantFirstName}`, 'success');
      onRenewed();
    } catch (err) {
      setError(fieldErrors(err as ApiError).new_end_date || (err as ApiError).message || 'Could not renew this lease.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal
      open={open}
      onClose={() => !submitting && onClose()}
      title={`Renew ${tenantFirstName}'s lease`}
      description="This updates the same tenancy in place. The new terms take effect immediately — there is no separate signature step."
      footer={
        <>
          <button className="btn btn-g sm" onClick={onClose} disabled={submitting}>
            Cancel
          </button>
          <button className="btn btn-p sm" onClick={submit} disabled={submitting}>
            <IconRenew /> {submitting ? 'Renewing…' : 'Renew lease'}
          </button>
        </>
      }
    >
      <div className="frow">
        <div className="field">
          <label>New end date</label>
          <input type="date" value={newEndDate} onChange={(e) => setNewEndDate(e.target.value)} />
          <div className="hint">Currently {currentEndDate ? formatDate(currentEndDate) : 'open-ended'}</div>
        </div>
        <div className="field">
          <label>New monthly rent (GH₵)</label>
          <input type="number" step="0.01" value={newRent} onChange={(e) => setNewRent(e.target.value)} />
          <div className="hint">Currently {formatCents(currentRentCents)}</div>
        </div>
      </div>
      <div className="field">
        <label>Note (optional, kept with the renewal history)</label>
        <textarea value={note} onChange={(e) => setNote(e.target.value)} placeholder="e.g. Terms unchanged, thank you for a great year." />
      </div>
      {error && <p className="form-error">{error}</p>}
    </Modal>
  );
}

const MOVEOUT_REASONS = [
  { value: 'tenant', label: 'Tenant giving notice' },
  { value: 'mutual', label: 'Mutual end of term' },
  { value: 'nonrenew', label: 'Not renewing' },
  { value: 'other', label: 'Other' },
];

function MoveoutModal({
  open,
  onClose,
  tenantFirstName,
  contractId,
  onTerminated,
}: {
  open: boolean;
  onClose: () => void;
  tenantFirstName: string;
  contractId: string;
  onTerminated: () => void;
}) {
  const { toast } = useToast();
  const [reasonKey, setReasonKey] = useState('tenant');
  const [note, setNote] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!open) return;
    setReasonKey('tenant');
    setNote('');
    setError('');
  }, [open]);

  async function submit() {
    setSubmitting(true);
    setError('');
    try {
      const label = MOVEOUT_REASONS.find((r) => r.value === reasonKey)?.label ?? 'Other';
      const reason = note.trim() ? `${label} — ${note.trim()}` : label;
      await landlordApi.terminateContract(contractId, reason);
      toast(`Move-out started for ${tenantFirstName}`, 'success');
      onTerminated();
    } catch (err) {
      setError((err as ApiError).message || 'Could not start the move-out.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal
      open={open}
      onClose={() => !submitting && onClose()}
      title={`Start move-out for ${tenantFirstName}`}
      description="This ends the tenancy immediately."
      tone="danger"
      footer={
        <>
          <button className="btn btn-g sm" onClick={onClose} disabled={submitting}>
            Cancel
          </button>
          <button className="btn btn-d sm" onClick={submit} disabled={submitting}>
            <IconOut /> {submitting ? 'Ending tenancy…' : 'Begin move-out'}
          </button>
        </>
      }
    >
      <div className="field">
        <label>Reason</label>
        <div className="pick">
          {MOVEOUT_REASONS.map((r) => (
            <button key={r.value} className={reasonKey === r.value ? 'on' : ''} onClick={() => setReasonKey(r.value)}>
              {r.label}
            </button>
          ))}
        </div>
      </div>
      <div className="field">
        <label>Note (optional)</label>
        <textarea value={note} onChange={(e) => setNote(e.target.value)} placeholder="e.g. Relocating for work." />
      </div>
      <div className="mnote">
        <IconWarn />
        <div>
          This takes effect right away — there is no scheduled future move-out date or deposit-hold tracking in
          Wyncrest today.
        </div>
      </div>
      {error && <p className="form-error">{error}</p>}
    </Modal>
  );
}

function NoteModal({
  open,
  onClose,
  tenantFirstName,
  contractId,
  onAdded,
}: {
  open: boolean;
  onClose: () => void;
  tenantFirstName: string;
  contractId: string;
  onAdded: () => void;
}) {
  const { toast } = useToast();
  const [body, setBody] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!open) return;
    setBody('');
    setError('');
  }, [open]);

  async function submit() {
    if (!body.trim()) {
      setError('Write something first.');
      return;
    }
    setSubmitting(true);
    setError('');
    try {
      await landlordApi.addContractNote(contractId, body.trim());
      toast('Note saved', 'success');
      onAdded();
    } catch (err) {
      setError((err as ApiError).message || 'Could not save this note.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal
      open={open}
      onClose={() => !submitting && onClose()}
      title={`Note on ${tenantFirstName}`}
      description="Private to you. Use it to record context that helps you manage this tenancy."
      footer={
        <>
          <button className="btn btn-g sm" onClick={onClose} disabled={submitting}>
            Cancel
          </button>
          <button className="btn btn-p sm" onClick={submit} disabled={submitting}>
            <IconCheck /> {submitting ? 'Saving…' : 'Save note'}
          </button>
        </>
      }
    >
      <div className="mnote">
        <IconInfo />
        <div>
          Keep notes factual and about the tenancy. Do not record anything about a person's background or
          protected characteristics. Tenants can request the personal data you hold on them.
        </div>
      </div>
      <div className="field">
        <label>Note</label>
        <textarea
          value={body}
          onChange={(e) => setBody(e.target.value)}
          placeholder="What should you remember about this tenancy?"
          style={{ minHeight: 110 }}
        />
      </div>
      {error && <p className="form-error">{error}</p>}
    </Modal>
  );
}
