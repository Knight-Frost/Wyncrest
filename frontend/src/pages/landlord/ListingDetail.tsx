import { useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { landlordApi } from '@/lib/endpoints';
import type { ApiError, Application, Listing } from '@/lib/types';
import { formatDate, humanize, storageUrl, timeAgo } from '@/lib/format';
import { ErrorState, LoadingState } from '@/components/ui/states';
import { GalleryManager } from '@/components/media/GalleryManager';
import { ListingEditDrawer } from '@/components/listings/ListingEditDrawer';
import { useToast } from '@/components/ui/toast';
import { IconBack, IconEdit, IconImage, IconWarn, KV } from './properties-ui';
import { IconEye, IconUsers, IconUp, IconX, IconClock, IconAlert, IconDots } from './listing-ui';
import { moneyDecimal } from './properties-helpers';
import { applicationBadgeTone, daysOnMarket, statusPanelClass } from './listing-helpers';
import './properties.css';
import './listings.css';

type LifecycleAction = 'submit' | 'withdraw' | 'deactivate' | 'reactivate' | 'archive' | 'restore';
type TabKey = 'overview' | 'applications' | 'review' | 'preview' | 'media' | 'pricing' | 'activity';

const TABS: { key: TabKey; label: string }[] = [
  { key: 'overview', label: 'Overview' },
  { key: 'applications', label: 'Applications' },
  { key: 'review', label: 'Review & Approval' },
  { key: 'preview', label: 'Public Preview' },
  { key: 'media', label: 'Media' },
  { key: 'pricing', label: 'Pricing' },
  { key: 'activity', label: 'Activity' },
];

export function ListingDetail() {
  const { id } = useParams();
  const listingId = Number(id);
  const navigate = useNavigate();
  const { toast } = useToast();

  const { data: listing, loading, error, reload } = useApi(() => landlordApi.listing(listingId), [listingId]);
  const appsApi = useApi(() => landlordApi.applications({ listing_id: listingId }), [listingId]);
  const historyApi = useApi(() => landlordApi.listingHistory(listingId), [listingId]);

  const [tab, setTab] = useState<TabKey>('overview');
  const [editing, setEditing] = useState(false);
  const [busy, setBusy] = useState(false);

  const applications = useMemo(() => appsApi.data ?? [], [appsApi.data]);
  const newApplications = useMemo(
    () => applications.filter((a) => a.status === 'submitted').length,
    [applications],
  );

  async function runLifecycle(action: LifecycleAction) {
    if (!listing) return;
    setBusy(true);
    try {
      switch (action) {
        case 'submit':
          await landlordApi.submitListing(listing.id);
          toast(listing.status === 'rejected' ? 'Resubmitted for review' : 'Submitted for review', 'success');
          break;
        case 'withdraw':
          await landlordApi.withdrawListing(listing.id);
          toast('Submission withdrawn', 'success');
          break;
        case 'deactivate':
          await landlordApi.deactivateListing(listing.id);
          toast('Listing deactivated', 'success');
          break;
        case 'reactivate':
          await landlordApi.reactivateListing(listing.id);
          toast('Listing is live again', 'success');
          break;
        case 'archive':
          await landlordApi.archiveListing(listing.id);
          toast('Listing archived', 'success');
          break;
        case 'restore':
          await landlordApi.restoreListing(listing.id);
          toast('Listing restored to draft', 'success');
          break;
      }
      reload();
    } catch (err) {
      toast((err as ApiError).message, 'error');
    } finally {
      setBusy(false);
    }
  }

  if (loading) {
    return (
      <div className="wprop">
        <BackLink onClick={() => navigate('/app/listings')} />
        <LoadingState label="Loading listing…" />
      </div>
    );
  }
  if (error || !listing) {
    return (
      <div className="wprop">
        <BackLink onClick={() => navigate('/app/listings')} />
        <ErrorState message={error?.message ?? 'Listing not found.'} onRetry={reload} />
      </div>
    );
  }

  const cover = (listing.media_assets ?? [])[0]?.url ?? (listing.primary_photo?.path ? storageUrl(listing.primary_photo.path) : null);
  const unit = listing.unit;
  const property = unit?.property;
  const rent = unit?.rent_amount;
  const days = daysOnMarket(listing.published_at);

  return (
    <div className="wprop animate-rise">
      <div className="crumb">
        <BackLink onClick={() => navigate('/app/listings')} />
        <span className="sep">/</span>
        <span>{listing.title || 'Untitled listing'}</span>
      </div>

      {/* Header */}
      <section className="glass dhead">
        {cover ? (
          <div className="dcover"><img src={cover} alt={listing.title} /></div>
        ) : (
          <div className="dcover missing"><div className="cvicon"><IconImage /></div></div>
        )}
        <div className="dhead-body">
          <div className="dh-type">{property?.name ?? 'Property'} · Unit {unit?.unit_number ?? listing.unit_id}</div>
          <h1 className="dh-name">{listing.title || 'Untitled listing'}</h1>
          <div className="dh-addr">{property?.city}{property?.state ? `, ${property.state}` : ''}</div>
          <div className="dh-meta">
            <span style={{ fontFamily: 'var(--wp-disp)', fontWeight: 800, fontSize: '1.3rem', color: 'var(--wp-ink)' }}>
              {rent ? `${moneyDecimal(rent)}/mo` : 'Rent not set'}
            </span>
            <span className={`statuspill ${listing.status}`} style={{ position: 'static' }}>
              <span className="sd" style={{ background: 'currentColor' }} />{humanize(listing.status)}
            </span>
            <span>Updated {timeAgo(listing.updated_at)}</span>
            <span className="mono-sm">LS-{listing.id}</span>
          </div>
          <div className="dh-actions">
            <PrimaryHeaderAction listing={listing} onEdit={() => setEditing(true)} onLifecycle={runLifecycle} onPublicView={() => navigate(`/app/listing/${listing.id}`)} busy={busy} onTab={setTab} />
            <button className="btn" onClick={() => setTab('applications')}><IconUsers /> Applications ({listing.applications_count ?? applications.length})</button>
          </div>
        </div>
      </section>

      {/* Status panel */}
      <StatusPanel listing={listing} onEdit={() => setEditing(true)} onLifecycle={runLifecycle} onTab={setTab} busy={busy} />

      {/* Quick stats */}
      <div className="dsum">
        <QStat label="Applications" value={listing.applications_count ?? applications.length} sub="total received" />
        <QStat label="New" value={listing.new_applications_count ?? newApplications} sub="not yet reviewed" />
        <QStat label="Views" value={listing.view_count} sub="since published" />
        <QStat label="Days active" value={days ?? '—'} sub={listing.status === 'active' ? 'public now' : '—'} />
      </div>

      {/* Tabs */}
      <section className="glass" style={{ padding: '.4rem' }}>
        <div className="dtabs">
          {TABS.map((t) => {
            const cnt = t.key === 'applications' ? applications.length : t.key === 'media' ? (listing.media_assets?.length ?? 0) : null;
            return (
              <button key={t.key} className={`dtab ${tab === t.key ? 'on' : ''}`} onClick={() => setTab(t.key)}>
                {t.label}{cnt != null && <span className="cnt">{cnt}</span>}
              </button>
            );
          })}
        </div>
      </section>

      {tab === 'overview' && <OverviewTab listing={listing} onEdit={() => setEditing(true)} />}
      {tab === 'applications' && <ApplicationsTab applications={applications} loading={appsApi.loading} onManage={() => navigate('/app/applicants')} />}
      {tab === 'review' && <ReviewTab listing={listing} history={historyApi.data ?? []} />}
      {tab === 'preview' && <PreviewTab listing={listing} />}
      {tab === 'media' && (
        <section className="sec glass">
          <div className="sec-h">Media</div>
          <GalleryManager
            target={{ type: 'listing', id: listing.id }}
            items={(listing.media_assets ?? []).slice().sort((a, b) => a.sort_order - b.sort_order)}
            loading={false}
            onRefetch={reload}
          />
          {(listing.media_assets?.length ?? 0) === 0 && (
            <div className="warnrow warn" style={{ marginTop: '1rem' }}>
              <div className="wi"><IconWarn /></div>
              <div><div className="wt">A cover photo is required</div><div className="ws">Add at least one clear cover photo before submitting this listing for review.</div></div>
            </div>
          )}
        </section>
      )}
      {tab === 'pricing' && <PricingTab listing={listing} />}
      {tab === 'activity' && <ActivityTab history={historyApi.data ?? []} loading={historyApi.loading} />}

      <ListingEditDrawer listing={listing} open={editing} onClose={() => setEditing(false)} onSaved={() => { setEditing(false); reload(); }} />
    </div>
  );
}

/* ══════════════════════════════════════════════════════════════════════════
   HEADER / STATUS PANEL
══════════════════════════════════════════════════════════════════════════ */

function PrimaryHeaderAction({ listing, onEdit, onLifecycle, onPublicView, busy, onTab }: {
  listing: Listing; onEdit: () => void; onLifecycle: (a: LifecycleAction) => void; onPublicView: () => void; busy: boolean; onTab: (t: TabKey) => void;
}) {
  switch (listing.status) {
    case 'draft':
      return <button className="btn btn-petrol" disabled={busy} onClick={onEdit}><IconEdit /> Continue editing</button>;
    case 'rejected':
      return <button className="btn btn-amber" disabled={busy} onClick={onEdit}>Fix listing</button>;
    case 'pending_review':
      return <button className="btn" onClick={() => onTab('preview')}>View submitted version</button>;
    case 'active':
      return <button className="btn btn-petrol" onClick={onPublicView}><IconEye /> View public listing</button>;
    case 'inactive':
      return <button className="btn btn-petrol" disabled={busy} onClick={() => onLifecycle('reactivate')}><IconUp /> Reactivate</button>;
    default: // archived
      return <button className="btn" disabled={busy} onClick={() => onLifecycle('restore')}><IconUp /> Restore</button>;
  }
}

function StatusPanel({ listing, onEdit, onLifecycle, onTab, busy }: {
  listing: Listing; onEdit: () => void; onLifecycle: (a: LifecycleAction) => void; onTab: (t: TabKey) => void; busy: boolean;
}) {
  const cls = statusPanelClass(listing.status);
  const missing = listing.missing_requirements ?? [];

  const icon = listing.status === 'active' ? <IconEye />
    : listing.status === 'pending_review' ? <IconClock />
    : listing.status === 'rejected' ? <IconX />
    : listing.status === 'draft' ? <IconEdit />
    : <IconWarn />;

  const { title, sub } = (() => {
    switch (listing.status) {
      case 'active': return { title: 'This listing is active', sub: 'Tenants can see this listing and apply.' };
      case 'pending_review': return { title: 'Waiting for admin review', sub: `This listing is not visible to tenants yet.` };
      case 'rejected': return { title: 'This listing needs changes', sub: 'An admin reviewed it and asked for the following:' };
      case 'draft': return { title: 'This listing is still a draft', sub: missing.length ? 'Finish the missing items before submitting for review:' : 'Ready to submit for review.' };
      case 'inactive': return { title: 'This listing is inactive', sub: 'It is hidden from tenants but still saved. Reactivate it to make it visible again.' };
      default: return { title: 'This listing is archived', sub: 'It is kept for your records and read-only until restored.' };
    }
  })();

  return (
    <section className={`statuspanel glass ${cls}`}>
      <div className="spi">{icon}</div>
      <div style={{ flex: 1 }}>
        <div className="spt">{title}</div>
        <div className="sps">{sub}</div>

        {listing.status === 'rejected' && listing.rejection_reason && (
          <div className="warnrow red" style={{ marginTop: '.7rem' }}>
            <div className="wi"><IconAlert /></div>
            <div><div className="ws">{listing.rejection_reason}</div></div>
          </div>
        )}
        {listing.status === 'draft' && missing.length > 0 && (
          <ul style={{ marginTop: '.6rem', display: 'flex', flexDirection: 'column', gap: '.35rem', listStyle: 'none', padding: 0 }}>
            {missing.map((m) => (
              <li key={m} style={{ display: 'flex', gap: '.5rem', fontSize: '.86rem', color: 'var(--wp-ink-2)' }}>
                <IconAlert className="shrink-0" /> Add {m}
              </li>
            ))}
          </ul>
        )}

        <div className="spbtns">
          {listing.status === 'active' && (
            <>
              <button className="btn btn-sm" onClick={() => onTab('applications')}>View applications</button>
              <button className="btn btn-sm btn-blood" disabled={busy} onClick={() => onLifecycle('deactivate')}>Deactivate</button>
            </>
          )}
          {listing.status === 'pending_review' && (
            <>
              <button className="btn btn-sm" onClick={() => onTab('preview')}>View submitted version</button>
              <button className="btn btn-sm btn-blood" disabled={busy} onClick={() => onLifecycle('withdraw')}>Withdraw submission</button>
            </>
          )}
          {listing.status === 'rejected' && (
            <>
              <button className="btn btn-sm btn-amber" onClick={onEdit}>Fix listing</button>
              <button className="btn btn-sm" disabled={busy} onClick={() => onLifecycle('submit')}>Resubmit for review</button>
            </>
          )}
          {listing.status === 'draft' && (
            <>
              <button className="btn btn-sm btn-petrol" onClick={onEdit}>Continue editing</button>
              <button className="btn btn-sm" disabled={busy || missing.length > 0} onClick={() => onLifecycle('submit')}>Submit for review</button>
            </>
          )}
          {listing.status === 'inactive' && (
            <>
              <button className="btn btn-sm btn-petrol" disabled={busy} onClick={() => onLifecycle('reactivate')}>Reactivate</button>
              <button className="btn btn-sm" disabled={busy} onClick={() => onLifecycle('archive')}>Archive</button>
            </>
          )}
          {listing.status === 'archived' && (
            <button className="btn btn-sm" disabled={busy} onClick={() => onLifecycle('restore')}>Restore</button>
          )}
        </div>
      </div>
    </section>
  );
}

function QStat({ label, value, sub }: { label: string; value: React.ReactNode; sub: string }) {
  return (
    <div className="scard glass">
      <div className="sl">{label}</div>
      <div className="sv">{value}</div>
      <div className="ss">{sub}</div>
    </div>
  );
}

function BackLink({ onClick }: { onClick: () => void }) {
  return <button className="back" onClick={onClick}><IconBack /> Back to Listings</button>;
}

/* ══════════════════════════════════════════════════════════════════════════
   TABS
══════════════════════════════════════════════════════════════════════════ */

function OverviewTab({ listing, onEdit }: { listing: Listing; onEdit: () => void }) {
  const unit = listing.unit;
  const property = unit?.property;
  const amenities = [...(unit?.amenities ?? []), ...((property?.amenities as unknown as string[] | null) ?? [])];

  return (
    <section className="sec glass">
      <div className="sec-h">Listing content<span className="hint"><button className="btn btn-sm" onClick={onEdit}>Edit listing</button></span></div>
      {listing.description ? (
        <p style={{ fontSize: '.92rem', color: 'var(--wp-ink-2)', marginBottom: '1.2rem' }}>{listing.description}</p>
      ) : (
        <p style={{ fontSize: '.9rem', color: 'var(--wp-ink-3)', marginBottom: '1.2rem' }}>No description yet.</p>
      )}
      <div className="two">
        <div>
          <KV k="Monthly rent" v={unit?.rent_amount ? moneyDecimal(unit.rent_amount) : '—'} />
          <KV k="Security deposit" v={unit?.security_deposit ? moneyDecimal(unit.security_deposit) : '—'} />
          <KV k="Available from" v={unit?.available_from ? formatDate(unit.available_from) : '—'} />
          <KV k="Lease duration" v={listing.lease_duration_months ? `${listing.lease_duration_months} months` : '—'} />
          <KV k="Bedrooms" v={unit?.bedrooms ?? '—'} />
          <KV k="Bathrooms" v={unit?.bathrooms ?? '—'} />
        </div>
        <div>
          <KV k="Size" v={unit?.square_feet ? `${unit.square_feet} sq ft` : '—'} />
          <KV k="Pets" v={listing.pets_allowed ? (listing.pet_policy || 'Pets allowed') : 'No pets'} />
          <KV k="Parking" v={property?.parking ?? '—'} />
          <KV k="Smoking" v={property?.smoking_policy ?? '—'} />
          <KV k="Move-in date" v={listing.move_in_date ? formatDate(listing.move_in_date) : '—'} />
        </div>
      </div>
      {amenities.length > 0 && (
        <div style={{ marginTop: '1.2rem' }}>
          <div className="dl" style={{ marginBottom: '.5rem' }}>Amenities</div>
          <div className="chips">{amenities.map((a) => <span key={a} className="chip">{humanize(a)}</span>)}</div>
        </div>
      )}
      <div style={{ marginTop: '1.2rem' }}>
        <div className="dl" style={{ marginBottom: '.4rem' }}>Connected to</div>
        <div className="chips">
          <span className="chip">Property: {property?.name ?? '—'}</span>
          <span className="chip">Unit: {unit?.unit_number ?? listing.unit_id}</span>
          {property?.city && <span className="chip">{property.city}{property.state ? `, ${property.state}` : ''}</span>}
        </div>
      </div>
    </section>
  );
}

function ApplicationsTab({ applications, loading, onManage }: { applications: Application[]; loading: boolean; onManage: () => void }) {
  if (loading) return <section className="sec glass"><LoadingState label="Loading applications…" /></section>;
  if (applications.length === 0) {
    return (
      <section className="sec glass">
        <div className="emptytab">
          <div className="ei"><IconUsers /></div>
          <div className="et2">No applications yet</div>
          <p>When tenants apply to this listing, they will appear here for you to review and decide on.</p>
        </div>
      </section>
    );
  }
  return (
    <section className="sec glass">
      <div className="sec-h">Applications<span className="hint">{applications.length} total</span></div>
      <div className="tablewrap">
        <table className="tbl">
          <thead><tr><th>Applicant</th><th>Submitted</th><th>Status</th><th>Documents</th><th className="r">Action</th></tr></thead>
          <tbody>
            {applications.map((a) => (
              <tr key={a.id}>
                <td style={{ fontWeight: 600 }}>{a.tenant ? `${a.tenant.first_name ?? ''} ${a.tenant.last_name ?? ''}`.trim() || a.tenant.email : `Tenant #${a.tenant_id}`}</td>
                <td className="num" style={{ fontSize: '.78rem' }}>{a.submitted_at ? formatDate(a.submitted_at) : '—'}</td>
                <td><span className={`badge ${applicationBadgeTone(a.status)}`}>{humanize(a.status)}</span></td>
                <td><span className={`badge ${(a.documents_count ?? 0) > 0 ? 'green' : 'amber'}`}>{a.documents_count ?? 0} docs</span></td>
                <td className="r"><button className="btn btn-sm" onClick={onManage}>Review in Applicants</button></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div style={{ fontSize: '.78rem', color: 'var(--wp-ink-3)', marginTop: '.8rem' }}>
        Applications are reviewed and decided from the Applicants page. Wyncrest verifies each applicant's identity and documents.
      </div>
    </section>
  );
}

function ReviewTab({ listing, history }: { listing: Listing; history: { id: number; created_at: string; action: string; action_label: string; summary: string; actor: { name: string } }[] }) {
  const reviewActions = new Set(['listing_submitted', 'listing_approved', 'listing_rejected', 'listing_changes_requested', 'listing_withdrawn']);
  const reviewHistory = history.filter((h) => reviewActions.has(h.action) || h.action.startsWith('listing_'));

  let box: React.ReactNode;
  if (listing.status === 'rejected') {
    box = (
      <div className="warnrow red">
        <div className="wi"><IconX /></div>
        <div>
          <div className="wt">Current status: Rejected</div>
          <div className="ws">{listing.rejection_reason}</div>
          {listing.reviewer && <div style={{ fontSize: '.82rem', color: 'var(--wp-ink-3)', marginTop: '.4rem' }}>Reviewed by {listing.reviewer.name}.</div>}
        </div>
      </div>
    );
  } else if (listing.status === 'pending_review') {
    box = (
      <div className="warnrow warn">
        <div className="wi"><IconClock /></div>
        <div><div className="wt">Current status: Pending review</div><div className="ws">This listing has been submitted and is waiting for admin approval.</div></div>
      </div>
    );
  } else if (listing.status === 'active' || listing.status === 'inactive') {
    box = (
      <div className="warnrow good">
        <div className="wi"><IconEye /></div>
        <div><div className="wt">Current status: Approved</div><div className="ws">{listing.reviewer ? `Approved by ${listing.reviewer.name}.` : 'This listing has been approved.'}</div></div>
      </div>
    );
  } else if (listing.changes_requested_reason) {
    box = (
      <div className="warnrow warn">
        <div className="wi"><IconAlert /></div>
        <div><div className="wt">Changes were requested</div><div className="ws">{listing.changes_requested_reason}</div></div>
      </div>
    );
  } else {
    box = (
      <div className="warnrow">
        <div className="wi"><IconEdit /></div>
        <div><div className="wt">Not yet submitted</div><div className="ws">This listing has not been submitted for review.</div></div>
      </div>
    );
  }

  return (
    <section className="sec glass">
      <div className="sec-h">Review &amp; approval</div>
      {box}
      <div className="dl" style={{ margin: '1.2rem 0 .7rem' }}>Review history</div>
      {reviewHistory.length === 0 ? (
        <p style={{ fontSize: '.86rem', color: 'var(--wp-ink-3)' }}>No review activity yet.</p>
      ) : (
        <div className="tl">
          {reviewHistory.map((h) => (
            <div key={h.id} className={`tl-item ${h.action === 'listing_reactivated' ? 'green' : h.action === 'listing_rejected' ? 'red' : ''}`}>
              <div className="te">{h.summary || h.action_label}</div>
              <div className="tm">{formatDate(h.created_at)} · <span className="actor">{h.actor.name}</span></div>
            </div>
          ))}
        </div>
      )}
    </section>
  );
}

function PreviewTab({ listing }: { listing: Listing }) {
  const media = (listing.media_assets ?? []).slice().sort((a, b) => a.sort_order - b.sort_order);
  const cover = media[0]?.url ?? (listing.primary_photo?.path ? storageUrl(listing.primary_photo.path) : null);
  const unit = listing.unit;
  const property = unit?.property;

  return (
    <section className="sec glass">
      <div className="sec-h">Public preview<span className="hint">What tenants will see</span></div>
      <div className="preview-frame">
        <div className="pv-banner">Preview only · not the live page</div>
        {cover ? <div className="pv-cover"><img src={cover} alt={listing.title} /></div> : (
          <div className="pv-cover" style={{ background: 'repeating-linear-gradient(45deg,color-mix(in srgb, var(--wp-ink) 3%, transparent),color-mix(in srgb, var(--wp-ink) 3%, transparent) 12px,color-mix(in srgb, var(--wp-ink) 5%, transparent) 12px,color-mix(in srgb, var(--wp-ink) 5%, transparent) 24px)' }}>
            <div className="cvicon"><IconImage /></div>
          </div>
        )}
        {media.length > 1 && (
          <div className="pv-gallery">
            {media.slice(1, 5).map((m) => <div key={m.id} className="g">{m.url && <img src={m.url} alt={m.alt_text ?? ''} />}</div>)}
          </div>
        )}
        <div className="pv-body">
          <div className="pv-title">{listing.title || 'Untitled listing'}</div>
          <div className="pv-loc">{property?.city}{property?.state ? `, ${property.state}` : ''}</div>
          <div className="pv-rent">
            {unit?.rent_amount ? <>{moneyDecimal(unit.rent_amount)} <span style={{ fontSize: '.9rem', fontFamily: 'var(--wp-sans)', color: 'var(--wp-ink-3)' }}>/ month</span></> : 'Rent not set'}
          </div>
          <div className="pv-facts">
            <div className="pv-fact"><div className="n">{unit?.bedrooms ?? '—'}</div><div className="l">Bedrooms</div></div>
            <div className="pv-fact"><div className="n">{unit?.bathrooms ?? '—'}</div><div className="l">Bathrooms</div></div>
            <div className="pv-fact"><div className="n">{unit?.square_feet ?? '—'}</div><div className="l">sq ft</div></div>
            <div className="pv-fact"><div className="n">{unit?.available_from ? formatDate(unit.available_from) : '—'}</div><div className="l">Available</div></div>
          </div>
          {listing.description ? <p style={{ fontSize: '.9rem', color: 'var(--wp-ink-2)' }}>{listing.description}</p> : <p style={{ fontSize: '.88rem', color: 'var(--wp-ink-3)' }}>No description added yet.</p>}
          <div className="pv-apply"><button className="btn btn-petrol" disabled>Apply now · preview only</button></div>
        </div>
      </div>
      <div style={{ fontSize: '.8rem', color: 'var(--wp-ink-3)', marginTop: '.9rem' }}>This is how your listing appears to tenants. Check the photos, rent, and details look right before publishing.</div>
    </section>
  );
}

function PricingTab({ listing }: { listing: Listing }) {
  const unit = listing.unit;
  return (
    <section className="sec glass">
      <div className="sec-h">Pricing</div>
      <div className="two">
        <div>
          <KV k="Monthly rent" v={unit?.rent_amount ? moneyDecimal(unit.rent_amount) : 'Not set'} />
          <KV k="Security deposit" v={unit?.security_deposit ? moneyDecimal(unit.security_deposit) : 'Not set'} />
        </div>
        <div>
          <KV k="Available from" v={unit?.available_from ? formatDate(unit.available_from) : 'Not set'} />
          <KV k="Lease duration" v={listing.lease_duration_months ? `${listing.lease_duration_months} months` : 'Not set'} />
        </div>
      </div>
      <div style={{ fontSize: '.78rem', color: 'var(--wp-ink-3)', marginTop: '.9rem' }}>
        Rent and deposit are set on the unit record so every listing for it stays in sync. Change them from the property's Units tab.
      </div>
    </section>
  );
}

function ActivityTab({ history, loading }: { history: { id: number; created_at: string; action: string; summary: string; action_label: string; actor: { name: string } }[]; loading: boolean }) {
  if (loading) return <section className="sec glass"><LoadingState label="Loading activity…" /></section>;
  if (history.length === 0) {
    return (
      <section className="sec glass">
        <div className="emptytab">
          <div className="ei"><IconDots /></div>
          <div className="et2">No activity yet</div>
          <p>Changes to this listing will show up here as they happen.</p>
        </div>
      </section>
    );
  }
  return (
    <section className="sec glass">
      <div className="sec-h">Activity<span className="hint">Who did what, and when</span></div>
      <div className="tl">
        {history.map((h) => (
          <div key={h.id} className="tl-item">
            <div className="te">{h.summary || h.action_label}</div>
            <div className="tm">{formatDate(h.created_at)} · <span className="actor">{h.actor.name}</span></div>
          </div>
        ))}
      </div>
    </section>
  );
}
