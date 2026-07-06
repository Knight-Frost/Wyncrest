import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router';
import type { DashboardPriorityCase } from '@/lib/types';

/* ============================================================================
   SECTION 2 — PRIORITY CASES
   The main working list: real cases involving real people, never an abstract
   total. Filterable by domain, capped to what the backend already ranked as
   top-priority (severity, then age) — no client-side re-sorting needed.
   ============================================================================ */

const TABS: { key: DashboardPriorityCase['case_type'] | 'all'; label: string }[] = [
  { key: 'all', label: 'All' },
  { key: 'rent', label: 'Rent' },
  { key: 'verification', label: 'Verifications' },
  { key: 'listing', label: 'Listings' },
  { key: 'maintenance', label: 'Maintenance' },
  { key: 'notification', label: 'Notifications' },
];

const CASE_TYPE_LABEL: Record<DashboardPriorityCase['case_type'], string> = {
  rent: 'Overdue rent',
  verification: 'Verification',
  listing: 'Listing review',
  maintenance: 'Maintenance',
  notification: 'Delivery failure',
};

export function PriorityCasesTable({ cases }: { cases: DashboardPriorityCase[] }) {
  const navigate = useNavigate();
  const [tab, setTab] = useState<(typeof TABS)[number]['key']>('all');

  const visible = useMemo(
    () => (tab === 'all' ? cases : cases.filter((c) => c.case_type === tab)),
    [cases, tab],
  );

  const availableTabs = useMemo(
    () => TABS.filter((t) => t.key === 'all' || cases.some((c) => c.case_type === t.key)),
    [cases],
  );

  return (
    <section className="wadm-section">
      <div className="wadm-section-head">
        <h2>Priority cases</h2>
        <div className="ph-sub">Real people, real cases — highest priority first</div>
      </div>

      {availableTabs.length > 1 && (
        <div className="pc-tabs" role="tablist">
          {availableTabs.map((t) => (
            <button
              key={t.key}
              type="button"
              role="tab"
              aria-selected={tab === t.key}
              className={`pc-tab${tab === t.key ? ' on' : ''}`}
              onClick={() => setTab(t.key)}
            >
              {t.label}
            </button>
          ))}
        </div>
      )}

      {visible.length === 0 ? (
        <div className="pc-empty glass">
          All clear. No priority cases need admin attention right now.
        </div>
      ) : (
        <div className="pc-list">
          {visible.map((c, i) => (
            <article className={`pc-row glass sev-${c.priority}`} key={`${c.case_type}-${i}`}>
              <div className="pc-row-main">
                <span className={`pc-badge sev-${c.priority}`}>
                  {c.priority === 'high' ? 'High' : c.priority === 'medium' ? 'Medium' : 'Low'}
                </span>
                <span className="pc-type mono-l">{CASE_TYPE_LABEL[c.case_type]}</span>
                <span className="pc-person">
                  {c.person}
                  {c.role && <span className="pc-role"> · {c.role}</span>}
                </span>
                {c.related_property && <span className="pc-property">{c.related_property}</span>}
              </div>
              <div className="pc-row-detail">
                <span className="pc-issue">{c.issue_summary}</span>
                <span className="pc-age mono-l">{c.age_days === 0 ? 'today' : `${c.age_days}d`}</span>
              </div>
              <button
                type="button"
                className="btn btn-glass pc-action"
                onClick={() => navigate(c.action_route)}
              >
                {c.action_label} <span aria-hidden="true">&rarr;</span>
              </button>
            </article>
          ))}
        </div>
      )}
    </section>
  );
}
