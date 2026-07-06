/**
 * NewMaintenanceRequestPage — dedicated FULL PAGE for creating a maintenance
 * request (/app/maintenance/new). This deliberately replaces the old inline
 * expanding form on the Maintenance overview: no drawer, no modal, no overlay,
 * no layout jump — the "+ New Request" button is a plain route navigation.
 *
 * TRUTHFULNESS / GATING:
 *  - The request is tied to the tenant's REAL active lease. We fetch the
 *    tenant's contracts and use the one with status === 'active'.
 *  - No active lease → a clear unavailable state, not a fabricated unit/lease.
 *    (The backend also enforces this: it 422/403s a request without an active
 *    contract, so this gate is the honest cosmetic half.)
 */
import { ArrowLeft, Wrench } from 'lucide-react';
import { Link, useNavigate } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { tenantApi } from '@/lib/endpoints';
import { SectionHeader } from '@/components/cards';
import {
  LoadingState,
  ErrorState,
  ForbiddenState,
  UnavailableState,
} from '@/components/ui/states';
import { MaintenanceRequestForm } from './MaintenanceRequestForm';
import './maintenance.css';

export function NewMaintenanceRequestPage() {
  const navigate = useNavigate();

  const contractsQ = useApi(() => tenantApi.contracts(), []);
  const activeContract = (contractsQ.data ?? []).find((c) => c.status === 'active') ?? null;

  /** Back link shared by the header and the "back" affordances. */
  const BackLink = (
    <Link to="/app/maintenance" className="mn-back">
      <ArrowLeft size={16} /> Back to Maintenance
    </Link>
  );

  /* ---- Loading -------------------------------------------------------- */
  if (contractsQ.loading) {
    return (
      <div className="mn-page mn-newpage">
        {BackLink}
        <SectionHeader
          eyebrow="My Rental"
          title="New Maintenance Request"
          description="Tell us what needs fixing so your landlord can understand, prioritize, and respond quickly."
        />
        <LoadingState label="Checking your lease…" />
      </div>
    );
  }

  /* ---- Error ---------------------------------------------------------- */
  if (contractsQ.error) {
    if (contractsQ.error.status === 403) {
      return (
        <div className="mn-page mn-newpage">
          {BackLink}
          <ForbiddenState
            title="Access denied"
            message="You don't have permission to submit maintenance requests."
          />
        </div>
      );
    }
    return (
      <div className="mn-page mn-newpage">
        {BackLink}
        <ErrorState
          title="Could not load your lease"
          message={contractsQ.error.message}
          onRetry={contractsQ.reload}
        />
      </div>
    );
  }

  /* ---- No active lease: honest gate ----------------------------------- */
  if (!activeContract) {
    return (
      <div className="mn-page mn-newpage">
        {BackLink}
        <SectionHeader
          eyebrow="My Rental"
          title="New Maintenance Request"
          description="Tell us what needs fixing so your landlord can understand, prioritize, and respond quickly."
        />
        <UnavailableState
          icon={<Wrench size={26} />}
          title="You need an active lease to submit a request"
          description="Once a landlord approves your rental and your contract is active, you can report maintenance issues tied to your unit here."
          action={
            <button className="mn-btn-ghost" onClick={() => navigate('/app/maintenance')}>
              Back to Maintenance
            </button>
          }
        />
      </div>
    );
  }

  /* ---- Active lease: the create form ---------------------------------- */
  return (
    <div className="mn-page mn-newpage">
      {BackLink}
      <SectionHeader
        eyebrow="My Rental"
        title="New Maintenance Request"
        description="Tell us what needs fixing so the right person can help."
      />
      <div className="mn-newpage-body">
        <MaintenanceRequestForm
          contractId={activeContract.id}
          leaseLabel={activeContract.listing?.title}
          onViewRequest={(id) => navigate(`/app/maintenance/${id}`)}
          onBackToList={() => navigate('/app/maintenance')}
        />
      </div>
    </div>
  );
}
