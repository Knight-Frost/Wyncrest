import { useNavigate } from 'react-router';
import { adminHasCapability, type CapabilitySubject } from '@/lib/permissions';
import { formatCedisDecimal } from '@/lib/format';
import { help } from '@/lib/helpText';
import { InfoHint } from '@/components/ui/InfoHint';
import type { AdminDashboard } from '@/lib/types';

/* ============================================================================
   SECTION 1 — TODAY'S ATTENTION QUEUE
   Six spacious, permission-aware action cards. Every number here traces to a
   real person/case (oldest waiting applicant, highest-risk tenant, etc.) —
   never a bare count with no context. A card is hidden entirely for admins
   who lack the capability its data/action requires (UI courtesy only; the
   API is the real gate).
   ============================================================================ */

function AttentionCard({
  title,
  headline,
  detail,
  actionLabel,
  onAction,
  severity,
  index,
}: {
  title: string;
  headline: string;
  detail: React.ReactNode;
  actionLabel: string;
  onAction: () => void;
  severity: 'high' | 'medium' | 'low' | 'clear';
  index: number;
}) {
  return (
    <article className={`aq-card glass reveal sev-${severity}`} style={{ '--i': index } as React.CSSProperties}>
      <div className="aq-top">
        <span className="aq-title">{title}</span>
        <span className={`aq-sev sev-${severity}`}>
          {severity === 'clear' ? 'Clear' : severity === 'high' ? 'High' : severity === 'medium' ? 'Medium' : 'Low'}
        </span>
      </div>
      <div className="aq-headline">{headline}</div>
      <div className="aq-detail">{detail}</div>
      <button type="button" className="btn btn-glass aq-action" onClick={onAction}>
        {actionLabel} <span aria-hidden="true">&rarr;</span>
      </button>
    </article>
  );
}

export function AttentionQueue({
  data,
  user,
}: {
  data: AdminDashboard;
  user: CapabilitySubject | null | undefined;
}) {
  const navigate = useNavigate();
  const q = data.attention_queue;

  const canVerifications = adminHasCapability(user, 'review_verifications');
  const canListings = adminHasCapability(user, 'moderate_listings');
  const canAudit = adminHasCapability(user, 'view_audit');

  const cards: React.ReactNode[] = [];

  if (canVerifications) {
    const v = q.verification;
    cards.push(
      <AttentionCard
        key="verification"
        index={cards.length}
        title="Verification reviews"
        severity={v.pending === 0 ? 'clear' : v.pending >= 5 ? 'high' : 'medium'}
        headline={v.pending === 0 ? 'No verification reviews waiting' : `${v.pending} verification ${v.pending === 1 ? 'review' : 'reviews'}`}
        detail={
          v.pending === 0 ? (
            'Every submitted identity document has been decided.'
          ) : (
            <>
              {v.pending_by_role.tenant} {v.pending_by_role.tenant === 1 ? 'tenant' : 'tenants'} ·{' '}
              {v.pending_by_role.landlord} {v.pending_by_role.landlord === 1 ? 'landlord' : 'landlords'}
              {v.oldest && (
                <>
                  <br />
                  Oldest: {v.oldest.user_name}, waiting {v.oldest.waiting_days} {v.oldest.waiting_days === 1 ? 'day' : 'days'}
                </>
              )}
            </>
          )
        }
        actionLabel="Review verifications"
        onAction={() => navigate(q.verification.action_route)}
      />,
    );
  }

  if (canListings) {
    const l = q.listings;
    cards.push(
      <AttentionCard
        key="listings"
        index={cards.length}
        title="Listing reviews"
        severity={l.pending === 0 ? 'clear' : 'medium'}
        headline={l.pending === 0 ? 'No listings need review' : `${l.pending} ${l.pending === 1 ? 'listing needs' : 'listings need'} review`}
        detail={
          l.oldest ? (
            <>
              {l.oldest.title}
              {l.oldest.landlord_name && <> · {l.oldest.landlord_name}</>}
              <br />
              Submitted {l.oldest.age_days === 0 ? 'today' : `${l.oldest.age_days} ${l.oldest.age_days === 1 ? 'day' : 'days'} ago`}
            </>
          ) : (
            'Every submitted listing has been reviewed.'
          )
        }
        actionLabel="Review listings"
        onAction={() => navigate(l.action_route)}
      />,
    );
  }

  {
    const r = q.rent_risk;
    cards.push(
      <AttentionCard
        key="rent"
        index={cards.length}
        title="Rent risk"
        severity={r.overdue_count === 0 ? 'clear' : r.overdue_count >= 3 ? 'high' : 'medium'}
        headline={r.overdue_count === 0 ? 'No overdue rent cases' : `${r.overdue_count} overdue rent ${r.overdue_count === 1 ? 'case' : 'cases'}`}
        detail={
          r.oldest ? (
            <>
              {formatCedisDecimal(r.overdue_total_cents / 100)} overdue
              <br />
              Oldest: {r.oldest.tenant} · {r.oldest.property ?? 'No property on file'} · {r.oldest.days_late}{' '}
              {r.oldest.days_late === 1 ? 'day' : 'days'} late
            </>
          ) : (
            'Every tenant is current on rent.'
          )
        }
        actionLabel="View rent cases"
        onAction={() => navigate(r.action_route)}
      />,
    );
  }

  if (canAudit) {
    const f = q.finance_issues;
    cards.push(
      <AttentionCard
        key="finance"
        index={cards.length}
        title="Payment / ledger issues"
        severity={f.count === 0 ? 'clear' : 'medium'}
        headline={f.count === 0 ? 'No recent payment failures' : `${f.count} finance ${f.count === 1 ? 'issue' : 'issues'} this week`}
        detail={
          f.latest ? (
            <>
              Payment failed for {f.latest.recipient_name}
              {f.latest.amount_cents != null && <> · {formatCedisDecimal(f.latest.amount_cents / 100)}</>}
            </>
          ) : (
            `No failed payments in the last ${f.window_days} days.`
          )
        }
        actionLabel="Review finance issues"
        onAction={() => navigate(f.action_route)}
      />,
    );
  }

  {
    const m = q.maintenance;
    const urgentParts: string[] = [];
    if (m.urgent > 0) urgentParts.push(`${m.urgent} urgent`);
    if (m.overdue > 0) urgentParts.push(`${m.overdue} overdue`);
    if (m.waiting > 0) urgentParts.push(`${m.waiting} waiting`);

    cards.push(
      <AttentionCard
        key="maintenance"
        index={cards.length}
        title="Maintenance escalations"
        severity={m.open === 0 ? 'clear' : m.urgent > 0 || m.overdue > 0 ? 'high' : 'medium'}
        headline={m.open === 0 ? 'No open maintenance cases' : `${m.open} maintenance ${m.open === 1 ? 'case needs' : 'cases need'} attention`}
        detail={
          m.open === 0
            ? 'Every maintenance request has been resolved or closed.'
            : urgentParts.length > 0
              ? urgentParts.join(' · ')
              : m.oldest
                ? `Oldest: ${m.oldest.tenant?.name ?? 'Unknown tenant'} · ${m.oldest.age_days} ${m.oldest.age_days === 1 ? 'day' : 'days'} open`
                : 'Open, no escalation signals yet.'
        }
        actionLabel="Review maintenance"
        onAction={() => navigate(m.action_route)}
      />,
    );
  }

  if (canAudit) {
    const n = q.notifications;
    cards.push(
      <AttentionCard
        key="notifications"
        index={cards.length}
        title="Failed notifications"
        severity={n.failed_total === 0 ? 'clear' : n.critical_failed > 0 ? 'high' : 'low'}
        headline={n.failed_total === 0 ? 'No failed notifications' : `${n.failed_total} failed ${n.failed_total === 1 ? 'notification' : 'notifications'}`}
        detail={
          n.latest ? (
            <>
              {n.latest.type?.replace(/_/g, ' ')} notice failed for {n.latest.recipient_name}
            </>
          ) : (
            'Every notification was delivered.'
          )
        }
        actionLabel="Review delivery failures"
        onAction={() => navigate(n.action_route)}
      />,
    );
  }

  if (cards.length === 0) return null;

  return (
    <section className="wadm-section">
      <div className="wadm-section-head">
        <h2>
          Today&rsquo;s attention queue{' '}
          <InfoHint text={help.needsAttention} label="About the attention queue" />
        </h2>
        <div className="ph-sub">What needs a decision right now</div>
      </div>
      <div className="aq-grid">{cards}</div>
    </section>
  );
}
