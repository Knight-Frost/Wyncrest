import { useMemo, useState } from 'react';
import {
  Plus, Wrench, Hourglass, CircleCheck, Check, ChevronRight,
  CalendarDays, MapPin, Lightbulb, MessageCircle, HelpCircle,
  Snowflake, Droplets, Fan, Zap, Hammer, Settings, Bug, Lock,
  PanelTop, LayoutGrid, Building2,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Link, useNavigate } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { tenantApi } from '@/lib/endpoints';
import { formatDate, humanize } from '@/lib/format';
import type { ApiError } from '@/lib/types';
import type { MaintenanceCategory, MaintenanceRequest } from '@/lib/types';
import {
  LoadingState,
  EmptyState,
  ErrorState,
  ForbiddenState,
  UnavailableState,
  SkeletonCard,
} from '@/components/ui/states';
import {
  NexusCard,
  StatusCard,
  SemanticBadge,
  DashboardSection,
  DataCardGrid,
  getMaintenanceVariant,
} from '@/components/cards';
import { IconWrench } from '@/components/ui/icons';
import { InfoHint } from '@/components/ui/InfoHint';
import { help } from '@/lib/helpText';
import './maintenance.css';

/* ---- Category icons (lucide) -------------------------------------------- */
const CATEGORY_ICONS: Record<MaintenanceCategory, LucideIcon> = {
  plumbing:     Droplets,
  electrical:   Zap,
  appliance:    Settings,
  hvac:         Snowflake,
  structural:   Hammer,
  pest:         Bug,
  security:     Lock,
  locks:        Lock,
  windows:      PanelTop,
  flooring:     LayoutGrid,
  water_damage: Droplets,
  shared_area:  Building2,
  general:      Wrench,
};

const TIPS = [
  { icon: Snowflake, text: 'Clean AC filters monthly for better cooling.' },
  { icon: Droplets,  text: 'Report leaks early to prevent water damage.' },
  { icon: Fan,       text: 'Ensure vents are unobstructed for airflow.' },
];

const STAGES = ['Reported', 'Acknowledged', 'In progress', 'Resolved'];

/* ---- Status step mapping (for the progress stepper) ---------------------- */
const STATUS_STEP: Record<string, number> = {
  open: 0, acknowledged: 1, in_progress: 2, resolved: 3, closed: 3, cancelled: 0,
};
/** For the stepper, use the semantic role color via CSS var. */
const STATUS_ACCENT: Record<string, string> = {
  open:         'var(--color-info-500)',
  acknowledged: 'var(--color-warning-500)',
  in_progress:  'var(--color-brand-600)',
  resolved:     'var(--color-success-500)',
  closed:       'var(--color-success-500)',
  cancelled:    'var(--color-ink-400)',
};

/* ---- Stepper ------------------------------------------------------------- */
function Stepper({ step, accent }: { step: number; accent: string }) {
  return (
    <div className="mn-steps" style={{ '--mn-accent': accent } as React.CSSProperties}>
      {STAGES.map((s, i) => {
        const done    = i < step;
        const current = i === step;
        return (
          <div key={s} className={`mn-step${done ? ' done' : ''}${current ? ' current' : ''}`}>
            <span className="mn-step-node">
              {done ? <Check size={12} strokeWidth={3} /> : current ? <span className="mn-dot" /> : null}
            </span>
            <span className="mn-step-label">{s}</span>
          </div>
        );
      })}
    </div>
  );
}

/* ---- Active request card ------------------------------------------------- */
function RequestCard({
  req,
  onCancel,
  cancelling,
}: {
  req: MaintenanceRequest;
  onCancel: (id: number) => void;
  cancelling: number | null;
}) {
  const role     = getMaintenanceVariant(req.priority, req.status);
  const Icon     = CATEGORY_ICONS[req.category] ?? Wrench;
  const step     = STATUS_STEP[req.status] ?? 0;
  const accent   = STATUS_ACCENT[req.status] ?? STATUS_ACCENT.open;
  const canCancel = req.status === 'open' || req.status === 'acknowledged' || req.status === 'in_progress';
  const isCancelling = cancelling === req.id;

  const locationParts = [
    req.unit?.unit_number ? `Unit ${req.unit.unit_number}` : null,
    req.property?.name ?? null,
  ].filter(Boolean);
  const location = locationParts.length > 0 ? locationParts.join(' · ') : null;

  /* The card border/tint uses NexusCard with the semantic role; urgent = danger. */
  return (
    <NexusCard role={role} as="article" className="mn-req">
      <span className="mn-req-icon" aria-hidden="true">
        <Icon size={24} />
      </span>
      <div className="mn-req-body">
        <div className="mn-req-title-row">
          <h3 className="mn-req-title">{req.title}</h3>
          {/* Priority badge — semantic role */}
          <SemanticBadge role={role} status={req.priority} dot />
        </div>
        <p className="mn-req-desc">{req.description}</p>
        <div className="mn-req-meta">
          {location && (
            <span className="mn-meta"><MapPin size={13} /> {location}</span>
          )}
          <span className="mn-meta">
            <CalendarDays size={13} /> Submitted {formatDate(req.submitted_at ?? req.created_at)}
          </span>
          {req.resolved_at && (
            <span className="mn-meta"><Check size={13} /> Resolved {formatDate(req.resolved_at)}</span>
          )}
        </div>
        {req.resolution_notes && (
          <p className="mn-resolution-note">{req.resolution_notes}</p>
        )}
      </div>
      <div className="mn-req-side">
        <div className="mn-side-top">
          {/* Status badge — semantic role */}
          <SemanticBadge role={role} status={req.status} dot />
        </div>
        <Stepper step={step} accent={accent} />
        <div className="mn-actions">
          <Link to={`/app/maintenance/${req.id}`} className="mn-btn-ghost">View details</Link>
          {canCancel && (
            <button
              className="mn-btn-ghost"
              onClick={() => onCancel(req.id)}
              disabled={isCancelling}
            >
              {isCancelling ? 'Cancelling…' : 'Cancel request'}
            </button>
          )}
        </div>
      </div>
    </NexusCard>
  );
}

/* ---- Resolved row -------------------------------------------------------- */
function ResolvedRow({ req }: { req: MaintenanceRequest }) {
  const Icon = CATEGORY_ICONS[req.category] ?? Wrench;
  return (
    <Link to={`/app/maintenance/${req.id}`} className="mn-rrow-link">
    <NexusCard as="div" role="neutral" className="mn-rrow">
      <span className="mn-rrow-icon" aria-hidden="true"><Icon size={20} /></span>
      <div className="mn-rrow-body">
        <div className="mn-rrow-title-row">
          <h3 className="mn-rrow-title">{req.title}</h3>
          {/* Priority — neutral role for resolved items since risk is gone */}
          <SemanticBadge role="neutral" status={req.priority} dot />
        </div>
        <p className="mn-rrow-desc">{req.description}</p>
        {req.resolution_notes && (
          <p className="mn-rrow-notes">{req.resolution_notes}</p>
        )}
      </div>
      <div className="mn-rrow-right">
        {/* Status = resolved/closed → success */}
        <SemanticBadge role="success" dot>
          {humanize(req.status)}
        </SemanticBadge>
        <span className="mn-rrow-date">
          {req.resolved_at ? `Resolved ${formatDate(req.resolved_at)}` : formatDate(req.created_at)}
        </span>
        <ChevronRight size={18} className="mn-rrow-chev" aria-hidden="true" />
      </div>
    </NexusCard>
    </Link>
  );
}

/* ---- Main page ----------------------------------------------------------- */
export function MaintenancePage() {
  const navigate = useNavigate();
  const [cancelling, setCancelling]       = useState<number | null>(null);
  const [cancelNotice, setCancelNotice]   = useState<string | null>(null);

  const contractsQ = useApi(() => tenantApi.contracts(), []);
  const activeContract = useMemo(
    () => (contractsQ.data ?? []).find((c) => c.status === 'active') ?? null,
    [contractsQ.data],
  );

  const reqQ = useApi(
    () => (activeContract ? tenantApi.maintenance() : Promise.resolve([])),
    [activeContract?.id],
  );

  async function handleCancel(id: number) {
    setCancelling(id);
    setCancelNotice(null);
    try {
      await tenantApi.cancelMaintenance(id);
      reqQ.reload();
    } catch (err) {
      const apiErr = err as ApiError;
      setCancelNotice(`Could not cancel request: ${apiErr.message}`);
    } finally {
      setCancelling(null);
    }
  }

  const requests = useMemo<MaintenanceRequest[]>(() => reqQ.data ?? [], [reqQ.data]);

  const stats = useMemo(() => {
    const open       = requests.filter((r) => r.status === 'open' || r.status === 'acknowledged').length;
    const inProgress = requests.filter((r) => r.status === 'in_progress').length;
    const resolved   = requests.filter((r) => r.status === 'resolved' || r.status === 'closed').length;
    return { open, inProgress, resolved };
  }, [requests]);

  const activeRequests   = useMemo(
    () => requests.filter((r) => r.status !== 'resolved' && r.status !== 'closed' && r.status !== 'cancelled'),
    [requests],
  );
  const resolvedRequests = useMemo(
    () => requests.filter((r) => r.status === 'resolved' || r.status === 'closed'),
    [requests],
  );

  /* ---- Loading: contracts fetch ------------------------------------------ */
  if (contractsQ.loading) {
    return (
      <div className="mn-page">
        <div className="mn-header">
          <div>
            <p className="mn-eyebrow">My Rental</p>
            <h1 className="mn-title">Maintenance</h1>
          </div>
        </div>
        <DataCardGrid cols={3}>
          {[0, 1, 2].map((i) => <SkeletonCard key={i} />)}
        </DataCardGrid>
        <LoadingState label="Loading your maintenance history…" />
      </div>
    );
  }

  /* ---- Error: contracts fetch -------------------------------------------- */
  if (contractsQ.error) {
    if (contractsQ.error.status === 403) {
      return (
        <div className="mn-page">
          <ForbiddenState title="Access denied" message="You don't have permission to view maintenance requests." />
        </div>
      );
    }
    return (
      <div className="mn-page">
        <ErrorState
          title="Could not load your contracts"
          message={contractsQ.error.message}
          onRetry={contractsQ.reload}
        />
      </div>
    );
  }

  /* ---- No active contract: unavailable gate ------------------------------ */
  if (!activeContract) {
    return (
      <div className="mn-page">
        <div className="mn-header">
          <div>
            <p className="mn-eyebrow">My Rental</p>
            <h1 className="mn-title">Maintenance</h1>
            <p className="mn-sub">Report issues, track progress, and stay updated on every request for your unit.</p>
          </div>
        </div>
        <UnavailableState
          icon={<Wrench size={26} />}
          title="Maintenance requests require an active lease"
          description="Once a landlord approves your rental and your contract is active, you can report maintenance issues here and track progress in real time."
          action={
            <button className="mn-btn-ghost" onClick={() => navigate('/app/browse')}>
              Browse listings
            </button>
          }
        />
        <div className="mn-info">
          <NexusCard role="neutral" className="mn-info-card">
            <div className="mn-info-head"><Lightbulb size={18} /> Maintenance tips</div>
            {TIPS.map((t) => { const I = t.icon; return (
              <div key={t.text} className="mn-tip">
                <span className="mn-tip-ico" aria-hidden="true"><I size={15} /></span> {t.text}
              </div>
            ); })}
          </NexusCard>
          <NexusCard role="neutral" className="mn-info-card">
            <div className="mn-info-head"><HelpCircle size={18} /> Need help?</div>
            <p className="mn-help-text">Our support team is here to help you with any questions about the platform.</p>
            <button className="mn-help-btn" onClick={() => navigate('/app/messages')}>
              <MessageCircle size={16} /> Contact support
            </button>
          </NexusCard>
        </div>
      </div>
    );
  }

  /* ---- Active contract: full maintenance view ---------------------------- */
  return (
    <div className="mn-page">
      {/* Header */}
      <div className="mn-header">
        <div>
          <p className="mn-eyebrow">My Rental</p>
          <h1 className="mn-title">Maintenance</h1>
          <p className="mn-sub">Report issues, track progress, and stay updated on every request for your unit.</p>
        </div>
        <Link to="/app/maintenance/new" className="mn-new">
          <Plus size={17} /> New Request
        </Link>
      </div>

      {/* Cancel notice */}
      {cancelNotice && (
        <div role="alert" className="mn-notice mn-notice--error">{cancelNotice}</div>
      )}

      {/* Stats — StatusCard with semantic roles from real counts */}
      {!reqQ.loading && (
        <DataCardGrid cols={3}>
          {/* Open tickets: warning when items need attention, success when clear */}
          <StatusCard
            label="Open"
            value={stats.open}
            sub={stats.open === 1 ? 'needs attention' : 'need attention'}
            icon={<IconWrench size={20} />}
            role={stats.open > 0 ? 'warning' : 'success'}
          />
          {/* In progress: info (support/active work) */}
          <StatusCard
            label="In Progress"
            value={stats.inProgress}
            sub="being worked on"
            icon={<Hourglass size={20} />}
            role={stats.inProgress > 0 ? 'info' : 'neutral'}
          />
          {/* Resolved: success */}
          <StatusCard
            label={<>Resolved <InfoHint text={help.maintenanceResolved} label="About resolved" /></>}
            value={stats.resolved}
            sub="completed"
            icon={<CircleCheck size={20} />}
            role={stats.resolved > 0 ? 'success' : 'neutral'}
          />
        </DataCardGrid>
      )}

      {/* Requests: loading */}
      {reqQ.loading && (
        <div className="mn-list">
          {[0, 1].map((i) => <SkeletonCard key={i} className="h-48" />)}
        </div>
      )}

      {/* Requests: error */}
      {reqQ.error && !reqQ.loading && (
        <ErrorState
          title="Could not load maintenance requests"
          message={reqQ.error.message}
          onRetry={reqQ.reload}
        />
      )}

      {/* Active requests */}
      {!reqQ.loading && !reqQ.error && (
        <>
          {activeRequests.length > 0 ? (
            <DashboardSection
              eyebrow="My Rental"
              title={
                <div className="mn-shead-l">
                  <h2 className="mn-shead-title">Active Requests</h2>
                  <span className="mn-count" aria-label={`${activeRequests.length} active`}>
                    {activeRequests.length}
                  </span>
                </div>
              }
            >
              <div className="mn-list">
                {activeRequests.map((r) => (
                  <RequestCard
                    key={r.id}
                    req={r}
                    onCancel={handleCancel}
                    cancelling={cancelling}
                  />
                ))}
              </div>
            </DashboardSection>
          ) : (
            <EmptyState
              icon={<Wrench size={28} />}
              title="No active maintenance requests"
              description="Report an issue using the New Request button and your landlord will see it here."
            />
          )}

          {/* Resolved requests */}
          {resolvedRequests.length > 0 && (
            <DashboardSection
              title={
                <div className="mn-shead-l">
                  <h2 className="mn-shead-title">Resolved Requests</h2>
                  <span className="mn-count" aria-label={`${resolvedRequests.length} resolved`}>
                    {resolvedRequests.length}
                  </span>
                </div>
              }
            >
              <div className="mn-list">
                {resolvedRequests.map((r) => <ResolvedRow key={r.id} req={r} />)}
              </div>
            </DashboardSection>
          )}
        </>
      )}

      {/* Info footer */}
      <div className="mn-info">
        <NexusCard role="neutral" className="mn-info-card">
          <div className="mn-info-head"><Lightbulb size={18} /> Maintenance tips</div>
          {TIPS.map((t) => { const I = t.icon; return (
            <div key={t.text} className="mn-tip">
              <span className="mn-tip-ico" aria-hidden="true"><I size={15} /></span> {t.text}
            </div>
          ); })}
        </NexusCard>

        <NexusCard role="neutral" className="mn-info-card">
          <div className="mn-info-head"><HelpCircle size={18} /> In an emergency</div>
          <p className="mn-help-text">For fire, medical, or police emergencies, contact your local Ghana emergency services immediately. Do not wait for a maintenance request to be processed.</p>
        </NexusCard>

        <NexusCard role="neutral" className="mn-info-card">
          <div className="mn-info-head"><HelpCircle size={18} /> Need help?</div>
          <p className="mn-help-text">Our support team can help with billing, repairs, and anything else you need.</p>
          <button className="mn-help-btn" onClick={() => navigate('/app/messages')}>
            <MessageCircle size={16} /> Contact support
          </button>
        </NexusCard>
      </div>
    </div>
  );
}
