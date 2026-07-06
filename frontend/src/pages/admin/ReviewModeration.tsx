import { useEffect, useState } from 'react';
import { useApi } from '@/hooks/useApi';
import { adminApi } from '@/lib/endpoints';
import { normalizeError } from '@/lib/api';
import { formatDateTime, humanize } from '@/lib/format';
import { useToast } from '@/components/ui/toast';
import { Avatar } from '@/components/ui/Avatar';
import { StarRating } from '@/components/ui/StarRating';
import type {
  AdminReviewSummary,
  AdminReviewDetail,
  AdminReviewSignal,
  ApiError,
} from '@/lib/types';
import './review-moderation.css';

/* ============================================================================
   REVIEW MODERATION — ported from the approved standalone mockup
   (wyncrest-reviews.html) and wired to 100% real backend data.

   The mockup invented toxicity/spam/PII scoring, a "reported by host" flow,
   free-text moderator notes, and an admin edit/redact tool — none of that
   exists in this platform (landlords can only respond to a review, never
   report one; admins can't edit a tenant's words). None of it is reproduced
   here. What IS real and shown: computed signals (flagged / low rating /
   long-pending / reviewer's first review), reviewer history, the real
   contract backing every review, and the actual audit-log decision history.

   The mockup's toy dataset never needed durable status browsing (its demo
   queue just shrinks as you decide), but real reviews persist across five
   statuses — so a status-tab row was added under the panel head; the top
   stat tiles stay purely informational (matching the same convention used
   on Listing Review), while the tabs do the actual filtering.

   Styling lives in review-moderation.css, scoped under `.revmod`.
   ============================================================================ */

type StatusTab = 'queue' | 'approved' | 'rejected' | 'hidden' | 'all';
type ChipFilter = 'all' | 'flagged' | 'low_rating' | 'long_pending';
type SortKey = 'risk' | 'newest' | 'oldest' | 'lowrating';
type ModerationAction = 'approve' | 'reject' | 'hide' | 'flag';

const TABS: { key: StatusTab; label: string }[] = [
  { key: 'queue', label: 'Queue' },
  { key: 'approved', label: 'Approved' },
  { key: 'rejected', label: 'Rejected' },
  { key: 'hidden', label: 'Hidden' },
  { key: 'all', label: 'All' },
];

const CHIPS: { key: ChipFilter; label: string }[] = [
  { key: 'all', label: 'All' },
  { key: 'flagged', label: 'Flagged' },
  { key: 'low_rating', label: 'Low rating' },
  { key: 'long_pending', label: 'Long-pending' },
];

const SORTS: { key: SortKey; label: string }[] = [
  { key: 'risk', label: 'Highest risk first' },
  { key: 'oldest', label: 'Oldest first' },
  { key: 'newest', label: 'Newest first' },
  { key: 'lowrating', label: 'Lowest rating' },
];

const ACTIONS: { key: ModerationAction; label: string; cls: string }[] = [
  { key: 'approve', label: 'Approve & publish', cls: 'btn-ok' },
  { key: 'reject', label: 'Reject', cls: 'btn-danger' },
  { key: 'hide', label: 'Hide', cls: 'btn-warn' },
  { key: 'flag', label: 'Flag', cls: 'btn-ghost' },
];

const PAST_TENSE: Record<ModerationAction, string> = {
  approve: 'approved',
  reject: 'rejected',
  hide: 'hidden',
  flag: 'flagged',
};

function reasonRequired(action: ModerationAction): boolean {
  return action === 'reject' || action === 'hide';
}

/** Highest-severity signal on a review sets the card's left risk stripe. */
function riskColor(signals: AdminReviewSignal[]): string {
  if (signals.some((s) => s.severity === 'high')) return 'var(--oxblood)';
  if (signals.some((s) => s.severity === 'medium')) return 'var(--amber)';
  return 'var(--slate)';
}

/* ── icons (inlined to match the mockup's stroke-icon set exactly) ─────────── */

function SearchIcon() {
  return (
    <svg viewBox="0 0 24 24">
      <circle cx="11" cy="11" r="7" />
      <path d="M21 21l-4-4" />
    </svg>
  );
}
function GuideIcon() {
  return (
    <svg viewBox="0 0 24 24">
      <path d="M12 3v18M6 8h12M6 16h12" strokeLinecap="round" />
    </svg>
  );
}
function ContextChevron() {
  return (
    <svg viewBox="0 0 24 24">
      <path d="M9 6l6 6-6 6" />
    </svg>
  );
}
function CheckIcon() {
  return (
    <svg viewBox="0 0 24 24">
      <path d="M20 6L9 17l-5-5" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

/* ── expandable "context" panel: reviewer history + real audit-log timeline ── */

function ContextPanel({ detail, loading }: { detail: AdminReviewDetail | null; loading: boolean }) {
  if (loading || !detail) {
    return <div className="rc-skel" />;
  }
  return (
    <div className="ctx-pad">
      <div>
        <div className="dl">Reviewer</div>
        <div className="kv">
          <span className="kk">Reviews written (all-time)</span>
          <span>{detail.reviewer_stats?.review_count ?? 1}</span>
        </div>
        <div className="kv">
          <span className="kk">Average rating given</span>
          <span>{detail.reviewer_stats ? `${detail.reviewer_stats.average_rating.toFixed(1)} / 5` : '—'}</span>
        </div>
        <div className="dl" style={{ marginTop: '1.2rem' }}>Property</div>
        <div className="kv">
          <span className="kk">Home</span>
          <span>{detail.property?.name ?? '—'}</span>
        </div>
        <div className="kv">
          <span className="kk">Location</span>
          <span>{detail.property?.city ?? '—'}</span>
        </div>
        <div className="kv">
          <span className="kk">Host</span>
          <span>{detail.landlord?.name ?? '—'}</span>
        </div>
        <div className="kv">
          <span className="kk">Lease status</span>
          <span>{detail.contract_status ? humanize(detail.contract_status) : '—'}</span>
        </div>
      </div>
      <div>
        {detail.landlord_response && (
          <div className="note-box">
            <div className="nn">Landlord response</div>
            <p>{detail.landlord_response}</p>
          </div>
        )}
        <div className="dl">Moderation history</div>
        <div className="tl">
          {detail.timeline.map((event, i) => (
            <div key={`${event.key}-${i}`} className={`tl-item ${event.severity}`}>
              <div className="t">
                <b>{event.label}</b>
                {event.actor && ` · ${event.actor}`}
              </div>
              {event.detail && <div className="td">{event.detail}</div>}
              <div className="tm">{formatDateTime(event.at)}</div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

/* ── one review card ─────────────────────────────────────────────────────── */

interface ReviewRowProps {
  item: AdminReviewSummary;
  contextOpen: boolean;
  detail: AdminReviewDetail | null;
  detailLoading: boolean;
  onToggleContext: () => void;
  onModerated: (updated: AdminReviewDetail) => void;
}

function ReviewRow({ item, contextOpen, detail, detailLoading, onToggleContext, onModerated }: ReviewRowProps) {
  const { toast } = useToast();
  const [action, setAction] = useState<ModerationAction | null>(null);
  const [reason, setReason] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const reviewerName = item.reviewer?.name ?? 'Unknown reviewer';
  const propertyName = item.property?.name ?? 'Unknown property';

  async function submit() {
    if (!action) return;
    if (reasonRequired(action) && !reason.trim()) {
      toast('A reason is required for this action.', 'error');
      return;
    }
    setSubmitting(true);
    try {
      const updated = await adminApi.moderateReview(item.id, action, reason.trim() || undefined);
      toast(`Review ${PAST_TENSE[action]}.`, 'success');
      setAction(null);
      setReason('');
      onModerated(updated);
    } catch (err) {
      const e = normalizeError(err) as ApiError;
      toast(e.message || 'Moderation action failed.', 'error');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="rcard" style={{ ['--risk' as string]: riskColor(item.signals) }}>
      <div className="rc-top">
        <Avatar name={reviewerName} size={40} className="rc-ava" />
        <div className="rc-who">
          <div className="rc-line1">
            <b>{reviewerName}</b> reviewed <span className="prop">{propertyName}</span>
          </div>
          <div className="rc-line2">
            {item.property?.city && <span>{item.property.city}</span>}
            {item.landlord && <span>· host {item.landlord.name}</span>}
            {item.contract_status && <span className="stay">{humanize(item.contract_status)} lease</span>}
            {item.moderator && <span>· last decided by {item.moderator.name}</span>}
          </div>
        </div>
        <div className="rc-rate">
          <StarRating value={item.rating} readOnly size={15} />
          <span className="rnum">{item.rating}.0 / 5</span>
        </div>
      </div>

      {item.signals.length > 0 && (
        <div className="sigs">
          {item.signals.map((s) => (
            <span key={s.key} className={`sig ${s.severity}`}>
              <span className="sd" />
              {s.label}
            </span>
          ))}
        </div>
      )}

      {item.title && <div className="rc-title">{item.title}</div>}
      <div className="rc-text">{item.body}</div>

      <div className="rc-foot">
        {ACTIONS.map((a) => (
          <button
            key={a.key}
            type="button"
            className={`btn ${a.cls} btn-sm`}
            onClick={() => setAction(a.key)}
            disabled={submitting}
          >
            {a.key === 'approve' && <CheckIcon />}
            {a.label}
          </button>
        ))}
        <span className="spacer" />
        <button type="button" className={`rc-more ${contextOpen ? 'open' : ''}`} onClick={onToggleContext}>
          Context <ContextChevron />
        </button>
      </div>

      {action && (
        <div className="actionbar">
          <p className="al">{reasonRequired(action) ? 'Reason (required)' : 'Reason (optional)'}</p>
          <div className="reason-box">
            <textarea
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="Explain the decision — this is written to the audit log."
            />
            <div className="reason-actions">
              <button type="button" className="btn btn-ghost btn-sm" onClick={() => setAction(null)} disabled={submitting}>
                Cancel
              </button>
              <button
                type="button"
                className={`btn ${ACTIONS.find((a) => a.key === action)!.cls} btn-sm`}
                onClick={submit}
                disabled={submitting}
              >
                {submitting ? 'Saving…' : `Confirm ${ACTIONS.find((a) => a.key === action)!.label.toLowerCase()}`}
              </button>
            </div>
          </div>
        </div>
      )}

      {contextOpen && <ContextPanel detail={detail} loading={detailLoading} />}
    </div>
  );
}

/* ── main page ────────────────────────────────────────────────────────────── */

export function ReviewModeration() {
  const [tab, setTab] = useState<StatusTab>('queue');
  const [chip, setChip] = useState<ChipFilter>('all');
  const [sort, setSort] = useState<SortKey>('risk');
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [guideOpen, setGuideOpen] = useState(false);
  const [expandedId, setExpandedId] = useState<number | null>(null);
  const [details, setDetails] = useState<Record<number, AdminReviewDetail>>({});
  const [detailLoading, setDetailLoading] = useState(false);

  useEffect(() => {
    const t = setTimeout(() => setSearch(searchInput.trim()), 300);
    return () => clearTimeout(t);
  }, [searchInput]);

  const { data, loading, error, reload } = useApi(
    () => adminApi.adminReviewQueue({ status: tab, sort, search: search || undefined }),
    [tab, sort, search],
  );

  const counts = data?.counts;
  const rows = (data?.data ?? []).filter((r) => {
    if (chip === 'flagged') return r.signals.some((s) => s.key === 'flagged');
    if (chip === 'low_rating') return r.signals.some((s) => s.key === 'low_rating');
    if (chip === 'long_pending') return r.signals.some((s) => s.key === 'long_pending');
    return true;
  });

  async function toggleContext(id: number) {
    if (expandedId === id) {
      setExpandedId(null);
      return;
    }
    setExpandedId(id);
    if (!details[id]) {
      setDetailLoading(true);
      try {
        const detail = await adminApi.adminReviewDetail(id);
        setDetails((d) => ({ ...d, [id]: detail }));
      } catch {
        // The panel just clears its skeleton; the row's summary fields still show.
      } finally {
        setDetailLoading(false);
      }
    }
  }

  function handleModerated(updated: AdminReviewDetail) {
    setDetails((d) => ({ ...d, [updated.id]: updated }));
    reload();
  }

  return (
    <div className="revmod">
      <section className="pagehead glass reveal">
        <div className="ph-top">
          <div>
            <span className="ph-eyebrow">Trust &amp; safety</span>
            <h1 className="ph-title">
              Review <span className="it">moderation.</span>
            </h1>
            <p className="ph-sub">
              Keep property reviews honest. Every review a tenant leaves is queued for a decision when submitted;
              nothing publishes without one.
            </p>
          </div>
          <div className="ph-controls">
            <button type="button" className="btn btn-glass" onClick={() => setGuideOpen((v) => !v)}>
              <GuideIcon /> Guidelines
            </button>
          </div>
        </div>
        <div className={`guide ${guideOpen ? 'open' : ''}`}>
          <div className="guide-in">
            <div className="guide-pad">
              <b>Remove a review when it contains:</b>
              <ul>
                <li>Harassment, hate, or threats toward a host or tenant</li>
                <li>Personal contact details shared in the review text (phone, email, address)</li>
                <li>Spam, advertising, or content unrelated to the stay</li>
                <li>A clear, verifiable violation of platform policy</li>
              </ul>
              <b style={{ display: 'block', marginTop: '0.6rem' }}>Keep it published</b> when it&apos;s an honest
              opinion, even a harshly negative one. A low rating is not a reason to remove.
            </div>
          </div>
        </div>
      </section>

      <section className="stats">
        <div className="stat glass alert reveal">
          <div className="k"><i style={{ background: 'var(--oxblood)' }} />Awaiting decision</div>
          <div className="v">{counts?.awaiting ?? 0}</div>
          <div className="d">pending + flagged</div>
        </div>
        <div className="stat glass reveal">
          <div className="k"><i style={{ background: 'var(--amber)' }} />Flagged</div>
          <div className="v">{counts?.flagged ?? 0}</div>
          <div className="d">held for a second look</div>
        </div>
        <div className="stat glass reveal">
          <div className="k"><i style={{ background: 'var(--petrol-2)' }} />Low-rated, awaiting</div>
          <div className="v">{counts?.low_rated_awaiting ?? 0}</div>
          <div className="d">2 stars or fewer</div>
        </div>
        <div className="stat glass reveal">
          <div className="k"><i style={{ background: 'var(--green)' }} />Approved · 7 days</div>
          <div className="v">{counts?.approved_week ?? 0}</div>
          <div className="d">published &amp; live</div>
        </div>
      </section>

      <section className="glass reveal">
        <div className="panel-head">
          <div>
            <h2>Moderation queue</h2>
            <div className="ph2-sub">{rows.length} of {counts?.[tab === 'queue' ? 'awaiting' : tab] ?? rows.length} in view</div>
          </div>
          <div className="tabs">
            {TABS.map((t) => (
              <button
                key={t.key}
                type="button"
                className={`tab ${tab === t.key ? 'on' : ''}`}
                onClick={() => { setTab(t.key); setExpandedId(null); }}
              >
                {t.label}
                {counts && <span className="n">{t.key === 'queue' ? counts.awaiting : counts[t.key]}</span>}
              </button>
            ))}
          </div>
        </div>

        <div className="toolbar">
          <label className="search">
            <SearchIcon />
            <input
              type="search"
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              placeholder="Search reviewer, property or text…"
              aria-label="Search reviews"
            />
          </label>
          <div className="chips">
            {CHIPS.map((c) => (
              <button
                key={c.key}
                type="button"
                className={`chip ${chip === c.key ? 'on' : ''}`}
                onClick={() => setChip(c.key)}
              >
                {c.label}
              </button>
            ))}
          </div>
          <select className="sel-input" value={sort} onChange={(e) => setSort(e.target.value as SortKey)} aria-label="Sort">
            {SORTS.map((s) => (
              <option key={s.key} value={s.key}>{s.label}</option>
            ))}
          </select>
        </div>

        {loading && !data ? (
          <div className="queue">
            {[0, 1, 2].map((i) => <div key={i} className="rc-skel" />)}
          </div>
        ) : error ? (
          <div className="empty">
            <span className="it">Something went wrong</span>
            {error.message}
          </div>
        ) : rows.length === 0 ? (
          <div className="empty">
            <CheckIcon />
            <span className="it">
              {tab === 'queue' ? 'Queue clear.' : 'Nothing here.'}
            </span>
            {tab === 'queue' ? 'Every review has been moderated. Nice work.' : 'No reviews match this filter.'}
          </div>
        ) : (
          <div className="queue">
            {rows.map((item) => (
              <ReviewRow
                key={item.id}
                item={item}
                contextOpen={expandedId === item.id}
                detail={details[item.id] ?? null}
                detailLoading={expandedId === item.id && detailLoading && !details[item.id]}
                onToggleContext={() => toggleContext(item.id)}
                onModerated={handleModerated}
              />
            ))}
          </div>
        )}
      </section>
    </div>
  );
}

export default ReviewModeration;
