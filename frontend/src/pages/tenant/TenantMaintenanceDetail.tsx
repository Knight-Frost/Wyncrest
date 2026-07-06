/**
 * TenantMaintenanceDetail — the tenant's own view of a maintenance request
 * (/app/maintenance/:id). Shows the full repair report they filed (issue,
 * location, urgency, when it started, safety flags, access preferences), their
 * uploaded PHOTOS (restricted evidence streamed via AuthedImage), the
 * append-only activity timeline, a message thread with the landlord, and a
 * cancel action while the request is still OPEN. Everything is real backend
 * data — no fabricated states.
 */
import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import { ArrowLeft, AlertTriangle, MessageSquare, Camera } from 'lucide-react';
import { useApi } from '@/hooks/useApi';
import { tenantApi } from '@/lib/endpoints';
import { useToast } from '@/components/ui/toast';
import { LoadingState, ErrorState } from '@/components/ui/states';
import { AuthedImage } from '@/components/media/AuthedImage';
import { formatDate, formatDateTime } from '@/lib/format';
import { maintenanceCategoryLabel, maintenancePriorityLabel, maintenanceStatusLabel } from '@/lib/statusMaps';
import type { MaintenanceMessage, MaintenanceRequest } from '@/lib/types';
import { areaLabel, onsetLabel, accessLabel, visitLabel, contactLabel, safetyLabel } from './maintenanceIntake';
import './maintenance.css';

const NEXT_STEP: Record<string, string> = {
  open: 'Your landlord will review this and acknowledge it soon.',
  acknowledged: 'Your landlord has seen this and will arrange the repair.',
  assigned: 'Someone has been assigned to carry out the work.',
  in_progress: 'The repair is being worked on.',
  waiting: 'This is paused, waiting on a part or an appointment.',
  resolved: 'The repair was marked complete. Let your landlord know if the issue returns.',
  closed: 'This request has been closed and archived.',
  cancelled: 'You cancelled this request.',
};

export function TenantMaintenanceDetail() {
  const { id } = useParams();
  const requestId = Number(id);
  const navigate = useNavigate();
  const { toast } = useToast();
  const { data: request, loading, error, reload } = useApi(() => tenantApi.maintenanceRequest(requestId), [requestId]);
  const [cancelling, setCancelling] = useState(false);

  const back = (
    <button className="mn-back" onClick={() => navigate('/app/maintenance')}>
      <ArrowLeft size={16} /> Back to Maintenance
    </button>
  );

  if (loading) return <div className="mn-page mn-newpage">{back}<LoadingState label="Loading your request…" /></div>;
  if (error) return <div className="mn-page mn-newpage">{back}<ErrorState message={error.message} onRetry={reload} /></div>;
  if (!request) return <div className="mn-page mn-newpage">{back}<ErrorState message="Request not found." /></div>;

  async function cancel() {
    if (!request || cancelling) return;
    setCancelling(true);
    try {
      await tenantApi.cancelMaintenance(request.id);
      toast('Request cancelled.', 'success');
      reload();
    } catch {
      toast('Could not cancel the request.', 'error');
    } finally {
      setCancelling(false);
    }
  }

  const media = request.media ?? [];
  const flags = request.safety_flags ?? [];

  return (
    <div className="mn-page mn-detail">
      {back}

      <header className="mn-dhead mn-card">
        <div className="mn-dhead-main">
          <div className="mn-dtags">
            <span className={`mn-tag mn-tag--${request.priority}`}>{maintenancePriorityLabel[request.priority]}</span>
            <span className="mn-tag">{maintenanceStatusLabel[request.status]}</span>
            <span className="mn-tag mn-tag--muted">{maintenanceCategoryLabel[request.category]}</span>
            <span className="mn-tag mn-tag--muted">MR-{String(request.id).padStart(4, '0')}</span>
          </div>
          <h1 className="mn-dtitle">{request.title}</h1>
          <p className="mn-dloc">
            {request.property?.name ?? 'Your unit'}
            {request.unit?.unit_number ? ` · Unit ${request.unit.unit_number}` : ''}
            {request.area ? ` · ${areaLabel[request.area]}` : ''}
          </p>
          <p className="mn-dnext">{NEXT_STEP[request.status] ?? ''}</p>
        </div>
        {request.status === 'open' && (
          <button className="mn-btn-ghost" onClick={cancel} disabled={cancelling}>
            {cancelling ? 'Cancelling…' : 'Cancel request'}
          </button>
        )}
      </header>

      {request.has_severe_safety_flag && (
        <div className="mn-warn">
          <AlertTriangle size={16} />
          <span>You flagged a safety issue on this request. If it's an emergency, contact local emergency services directly.</span>
        </div>
      )}

      {/* Report */}
      <section className="mn-sec mn-card">
        <div className="mn-sec-head"><h3 className="mn-sec-title">Your report</h3></div>
        <p className="mn-prose">{request.description}</p>
        <div className="mn-review">
          <DetailRow k="Category" v={maintenanceCategoryLabel[request.category]} />
          <DetailRow k="Urgency" v={maintenancePriorityLabel[request.priority]} />
          <DetailRow k="Room / area" v={request.area ? areaLabel[request.area] : '—'} />
          <DetailRow k="Specific spot" v={request.specific_location || '—'} />
          <DetailRow k="Started" v={request.onset ? onsetLabel[request.onset] : '—'} />
          <DetailRow k="Reported" v={formatDate(request.submitted_at ?? request.created_at)} />
        </div>
        {flags.length > 0 && (
          <div className="mn-flag-row">
            {flags.map((f) => <span key={f} className="mn-flag-chip">{safetyLabel[f]}</span>)}
          </div>
        )}
      </section>

      {/* Access & contact */}
      <section className="mn-sec mn-card">
        <div className="mn-sec-head"><h3 className="mn-sec-title">Access &amp; contact</h3></div>
        <div className="mn-review">
          <DetailRow k="Entry when you're out" v={request.access_permission ? accessLabel[request.access_permission] : '—'} />
          <DetailRow k="Preferred visit" v={request.preferred_visit_window ? visitLabel[request.preferred_visit_window] : 'No preference'} />
          <DetailRow k="Preferred contact" v={request.preferred_contact_method ? contactLabel[request.preferred_contact_method] : 'No preference'} />
        </div>
        {request.access_instructions && (
          <p className="mn-prose mn-prose--sm">{request.access_instructions}</p>
        )}
      </section>

      {/* Photos */}
      <section className="mn-sec mn-card">
        <div className="mn-sec-head">
          <h3 className="mn-sec-title">Photos</h3>
          <span className="mn-sec-hint">{media.length} attached</span>
        </div>
        {media.length === 0 ? (
          <div className="mn-photos-empty"><Camera size={20} /> No photos were attached to this request.</div>
        ) : (
          <div className="mn-thumbs">
            {media.map((m) => (
              <div className="mn-thumb mn-thumb--static" key={m.id}>
                <AuthedImage fetcher={() => tenantApi.mediaBlob(m.id)} alt={m.caption || m.original_filename} />
              </div>
            ))}
          </div>
        )}
      </section>

      {/* Timeline */}
      <section className="mn-sec mn-card">
        <div className="mn-sec-head"><h3 className="mn-sec-title">Activity</h3></div>
        <div className="mn-timeline">
          {(request.events ?? []).length === 0 ? (
            <p className="mn-hint">No activity yet.</p>
          ) : (
            (request.events ?? []).map((e) => (
              <div className="mn-tl-row" key={e.id}>
                <span className="mn-tl-dot" />
                <div>
                  <div className="mn-tl-desc">{e.description}</div>
                  <div className="mn-tl-time">{formatDateTime(e.created_at)}</div>
                </div>
              </div>
            ))
          )}
        </div>
      </section>

      <MessagesPanel request={request} />
    </div>
  );
}

function DetailRow({ k, v }: { k: string; v: string }) {
  return (
    <div className="mn-review-row">
      <span className="mn-review-k">{k}</span>
      <span className="mn-review-v">{v}</span>
    </div>
  );
}

function MessagesPanel({ request }: { request: MaintenanceRequest }) {
  const { toast } = useToast();
  const { data, loading, reload } = useApi(() => tenantApi.maintenanceMessages(request.id), [request.id]);
  const [body, setBody] = useState('');
  const [sending, setSending] = useState(false);
  const messages: MaintenanceMessage[] = data?.messages ?? [];

  async function send() {
    if (!body.trim() || sending) return;
    setSending(true);
    try {
      await tenantApi.sendMaintenanceMessage(request.id, body.trim());
      setBody('');
      reload();
    } catch {
      toast('Could not send the message.', 'error');
    } finally {
      setSending(false);
    }
  }

  return (
    <section className="mn-sec mn-card">
      <div className="mn-sec-head">
        <h3 className="mn-sec-title">Messages</h3>
        <span className="mn-sec-hint"><MessageSquare size={13} /> with your landlord</span>
      </div>
      {loading ? (
        <p className="mn-hint">Loading messages…</p>
      ) : (
        <div className="mn-thread">
          {messages.length === 0 ? (
            <p className="mn-hint">No messages yet. Ask your landlord about this request below.</p>
          ) : (
            messages.map((m) => (
              <div className={`mn-msg${m.sender.is_me ? ' me' : ''}`} key={m.id}>
                <div className="mn-msg-body">{m.body}</div>
                <div className="mn-msg-meta">{m.sender.name ?? (m.sender.is_me ? 'You' : 'Landlord')} · {formatDateTime(m.created_at)}</div>
              </div>
            ))
          )}
        </div>
      )}
      <div className="mn-composer">
        <input
          className="mn-input" placeholder="Write a message about this request…"
          value={body} onChange={(e) => setBody(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') send(); }} disabled={sending}
        />
        <button className="mn-btn-update" onClick={send} disabled={sending || !body.trim()}>Send</button>
      </div>
    </section>
  );
}
