import { useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { landlordApi } from '@/lib/endpoints';
import { fieldErrors } from '@/lib/api';
import type {
  ApiError,
  MediaAsset,
  PropertyDetailContract,
  PropertyDetailLedgerEntry,
  PropertyDetailListing,
  PropertyDetailMaintenance,
  PropertyDetailPayload,
  PropertyDetailUnit,
} from '@/lib/types';
import { formatDate, humanize } from '@/lib/format';
import { ErrorState, LoadingState } from '@/components/ui/states';
import { Button } from '@/components/ui/Button';
import { DetailDrawer } from '@/components/ui/Drawer';
import { DestructiveConfirmDialog } from '@/components/ui/DestructiveConfirmDialog';
import { useToast } from '@/components/ui/toast';
import { GalleryManager } from '@/components/media/GalleryManager';
import { UnitFormFields } from '@/components/landlord/UnitFormFields';
import {
  type UnitForm,
  emptyUnitForm,
  unitFormFrom,
  unitPayloadFromForm,
  validateUnitForm,
} from '@/components/landlord/unit-form-shared';
import { PropertyFormDrawer } from './PropertyFormDrawer';
import { KV, IconBack, IconEdit, IconImage, IconList, IconPlus, IconWarn, CoverGlyph } from './properties-ui';
import {
  gradientFor,
  moneyCents,
  moneyDecimal,
  propertyStatus,
  unitAvailabilityTone,
  listingStatusTone,
  contractStatusTone,
  maintenancePriorityTone,
  maintenanceStatusTone,
  ledgerStatusTone,
  type BadgeTone,
} from './properties-helpers';
import './properties.css';

const TABS: [string, string][] = [
  ['overview', 'Overview'],
  ['units', 'Units'],
  ['listings', 'Listings'],
  ['tenants', 'Tenants & Contracts'],
  ['ledger', 'Payments & Ledger'],
  ['maintenance', 'Maintenance'],
  ['documents', 'Documents'],
  ['photos', 'Photos'],
  ['activity', 'Activity'],
];

export function PropertyDetail() {
  const { id } = useParams();
  const propertyId = Number(id);
  const navigate = useNavigate();
  const { toast } = useToast();

  const { data, loading, error, reload } = useApi(
    () => landlordApi.propertyDetail(propertyId),
    [propertyId],
  );

  const [tab, setTab] = useState('overview');

  /* Edit property drawer */
  const [propOpen, setPropOpen] = useState(false);

  /* Add / edit unit drawer */
  const [unitOpen, setUnitOpen] = useState(false);
  const [editingUnitId, setEditingUnitId] = useState<number | null>(null);
  const [unitForm, setUnitForm] = useState<UnitForm>(emptyUnitForm);
  const [unitErrors, setUnitErrors] = useState<Record<string, string>>({});
  const [savingUnit, setSavingUnit] = useState(false);

  /* Gallery drawers */
  const [propGalleryOpen, setPropGalleryOpen] = useState(false);
  const [propMedia, setPropMedia] = useState<MediaAsset[]>([]);
  const [galleryUnit, setGalleryUnit] = useState<PropertyDetailUnit | null>(null);
  const [unitMedia, setUnitMedia] = useState<MediaAsset[]>([]);
  const [galleryLoading, setGalleryLoading] = useState(false);

  /* Archive/reactivate confirm */
  const [archiveOpen, setArchiveOpen] = useState(false);
  const [archiving, setArchiving] = useState(false);

  const tabCount = useMemo(() => {
    if (!data) return {} as Record<string, number>;
    return {
      units: data.units.length,
      listings: data.listings.length,
      tenants: data.contracts.length,
      maintenance: data.maintenance.length,
      documents: data.documents.length,
      photos: data.photos.length,
    } as Record<string, number>;
  }, [data]);

  /* ── Unit CRUD ── */
  function openAddUnit() {
    setEditingUnitId(null);
    setUnitForm(emptyUnitForm());
    setUnitErrors({});
    setUnitOpen(true);
  }
  async function openEditUnit(unitId: number) {
    setUnitErrors({});
    try {
      const full = await landlordApi.unit(unitId);
      setEditingUnitId(unitId);
      setUnitForm(unitFormFrom(full));
      setUnitOpen(true);
    } catch (err) {
      toast((err as ApiError).message, 'error');
    }
  }
  async function submitUnit(e: React.FormEvent) {
    e.preventDefault();
    const v = validateUnitForm(unitForm);
    if (Object.keys(v).length > 0) {
      setUnitErrors(v);
      return;
    }
    setSavingUnit(true);
    setUnitErrors({});
    try {
      if (editingUnitId) {
        await landlordApi.updateUnit(editingUnitId, unitPayloadFromForm(unitForm));
        toast('Unit updated', 'success');
      } else {
        await landlordApi.createUnit(propertyId, unitPayloadFromForm(unitForm));
        toast('Unit added', 'success');
      }
      setUnitOpen(false);
      reload();
    } catch (err) {
      const e2 = err as ApiError;
      const fe = fieldErrors(e2);
      setUnitErrors(fe);
      if (Object.keys(fe).length === 0) toast(e2.message, 'error');
    } finally {
      setSavingUnit(false);
    }
  }

  /* ── Galleries ── */
  async function openPropGallery() {
    setPropGalleryOpen(true);
    setGalleryLoading(true);
    try {
      const full = await landlordApi.property(propertyId);
      setPropMedia((full.media_assets ?? []).slice().sort((a, b) => a.sort_order - b.sort_order));
    } catch (err) {
      toast((err as ApiError).message, 'error');
    } finally {
      setGalleryLoading(false);
    }
  }
  async function loadUnitMedia(unitId: number) {
    setGalleryLoading(true);
    try {
      const full = await landlordApi.unit(unitId);
      setUnitMedia((full.media_assets ?? []).slice().sort((a, b) => a.sort_order - b.sort_order));
    } catch (err) {
      toast((err as ApiError).message, 'error');
    } finally {
      setGalleryLoading(false);
    }
  }
  function openUnitGallery(u: PropertyDetailUnit) {
    setGalleryUnit(u);
    setUnitMedia([]);
    void loadUnitMedia(u.id);
  }

  /* ── Archive / reactivate ── */
  async function toggleArchive() {
    if (!data) return;
    setArchiving(true);
    try {
      await landlordApi.updateProperty(propertyId, { is_active: !data.property.is_active });
      toast(data.property.is_active ? 'Property archived' : 'Property reactivated', 'success');
      setArchiveOpen(false);
      reload();
    } catch (err) {
      toast((err as ApiError).message, 'error');
    } finally {
      setArchiving(false);
    }
  }

  function createListingForUnit(unitId: number) {
    navigate(`/app/properties/${propertyId}/units/${unitId}/listings/new`);
  }

  if (loading) {
    return (
      <div className="wprop">
        <BackLink onClick={() => navigate('/app/properties')} />
        <LoadingState label="Loading property…" />
      </div>
    );
  }
  if (error || !data) {
    return (
      <div className="wprop">
        <BackLink onClick={() => navigate('/app/properties')} />
        <ErrorState message={error?.message ?? 'Property not found.'} onRetry={reload} />
      </div>
    );
  }

  const p = data.property;
  const status = propertyStatus(p.is_active);
  const s = data.summary;
  const address = [p.street_address, p.city, p.state].filter(Boolean).join(', ');

  return (
    <div className="wprop animate-rise">
      {/* Breadcrumb */}
      <div className="crumb">
        <BackLink onClick={() => navigate('/app/properties')} />
        <span className="sep">/</span>
        <span>{p.name}</span>
      </div>

      {/* Header */}
      <section className="glass dhead">
        {data.photos.find((ph) => ph.is_cover)?.url ? (
          <div className="dcover" style={{ background: gradientFor(p.id) }}>
            <img src={data.photos.find((ph) => ph.is_cover)!.url!} alt={p.name} />
          </div>
        ) : (
          <div className="dcover missing"><CoverGlyph /></div>
        )}
        <div className="dhead-body">
          <div className="dh-type">{humanize(p.property_type)}</div>
          <h1 className="dh-name">{p.name}</h1>
          <div className="dh-addr">{address}</div>
          <div className="dh-meta">
            <span className={`statuspill ${status.cls}`} style={{ position: 'static' }}>
              <span className="sd" style={{ background: 'currentColor' }} />{status.label}
            </span>
            <span>Updated {formatDate(p.updated_at)}</span>
            <span className="mono-sm">PR-{p.id}</span>
          </div>
          <div className="dh-actions">
            <button className="btn" onClick={() => setPropOpen(true)}><IconEdit /> Edit property</button>
            <button className="btn" onClick={openAddUnit}><IconPlus /> Add unit</button>
            <button className="btn" onClick={openPropGallery}><IconImage /> Upload photos</button>
            <button className={`btn ${p.is_active ? 'btn-blood' : 'btn-petrol'}`} onClick={() => setArchiveOpen(true)}>
              {p.is_active ? 'Archive' : 'Reactivate'}
            </button>
          </div>
        </div>
      </section>

      {/* Summary cards */}
      <div className="dsum">
        <DCard label="Units" value={s.units_total} sub={`${s.occupied} occupied · ${s.vacant} vacant`} />
        <DCard label="Occupied" value={s.occupied} sub="active contracts" cls="occ" />
        <DCard label="Available" value={s.vacant} sub="not occupied" />
        <DCard label="Listed" value={s.listed} sub="visible to tenants" />
        <DCard label="Pending review" value={s.pending_review} sub="awaiting admin" cls="att" />
        <DCard label="Expected rent" value={moneyCents(s.expected_rent_cents)} sub="per month from active" />
      </div>

      {/* Tabs */}
      <section className="glass" style={{ padding: '.4rem' }}>
        <div className="dtabs">
          {TABS.map(([key, label]) => {
            const cnt = tabCount[key];
            return (
              <button key={key} className={`dtab ${tab === key ? 'on' : ''}`} onClick={() => setTab(key)}>
                {label}
                {cnt != null && <span className="cnt">{cnt}</span>}
              </button>
            );
          })}
        </div>
      </section>

      {/* Tab body */}
      {tab === 'overview' && <OverviewTab data={data} address={address} />}
      {tab === 'units' && (
        <UnitsTab
          data={data}
          onAdd={openAddUnit}
          onEdit={openEditUnit}
          onList={createListingForUnit}
          onPhotos={openUnitGallery}
        />
      )}
      {tab === 'listings' && <ListingsTab data={data} onManage={() => navigate('/app/listings')} onCreate={() => setTab('units')} />}
      {tab === 'tenants' && <TenantsTab data={data} />}
      {tab === 'ledger' && <LedgerTab data={data} onExport={() => landlordApi.exportLedger()} />}
      {tab === 'maintenance' && <MaintenanceTab data={data} onOpen={() => navigate('/app/maintenance')} />}
      {tab === 'documents' && <DocumentsTab data={data} />}
      {tab === 'photos' && <PhotosTab data={data} onManage={openPropGallery} />}
      {tab === 'activity' && <ActivityTab data={data} />}

      {/* ── Edit property drawer (reused, now carries policy fields) ── */}
      <PropertyFormDrawer
        open={propOpen}
        editing={p}
        onClose={() => setPropOpen(false)}
        onSaved={reload}
      />

      {/* ── Add / edit unit drawer ── */}
      <DetailDrawer
        open={unitOpen}
        onClose={() => setUnitOpen(false)}
        eyebrow="UNIT"
        title={editingUnitId ? 'Edit unit' : 'Add unit'}
        description="Define the unit details and monthly rent."
        footer={
          <>
            <Button variant="secondary" onClick={() => setUnitOpen(false)} disabled={savingUnit}>Cancel</Button>
            <Button type="submit" form="unit-form" loading={savingUnit}>
              {editingUnitId ? 'Save changes' : 'Create unit'}
            </Button>
          </>
        }
      >
        <form id="unit-form" onSubmit={submitUnit}>
          <UnitFormFields form={unitForm} errors={unitErrors} onChange={(k, v) => setUnitForm((prev) => ({ ...prev, [k]: v }))} />
        </form>
      </DetailDrawer>

      {/* ── Property gallery drawer ── */}
      <DetailDrawer
        open={propGalleryOpen}
        onClose={() => setPropGalleryOpen(false)}
        eyebrow="PROPERTY"
        title="Property photos"
        description="Upload, reorder, or remove photos. The first image is the cover."
        widthClass="sm:max-w-[820px]"
        footer={<Button variant="secondary" onClick={() => setPropGalleryOpen(false)}>Close</Button>}
      >
        <GalleryManager
          target={{ type: 'property', id: propertyId }}
          items={propMedia}
          loading={galleryLoading}
          onRefetch={() => { void openPropGallery(); reload(); }}
        />
      </DetailDrawer>

      {/* ── Unit gallery drawer ── */}
      <DetailDrawer
        open={galleryUnit !== null}
        onClose={() => setGalleryUnit(null)}
        eyebrow="UNIT"
        title={galleryUnit ? `Photos: Unit ${galleryUnit.unit_number}` : 'Photos'}
        description="Upload, reorder, or remove photos for this unit."
        widthClass="sm:max-w-[820px]"
        footer={<Button variant="secondary" onClick={() => setGalleryUnit(null)}>Close</Button>}
      >
        {galleryUnit && (
          <GalleryManager
            target={{ type: 'unit', id: galleryUnit.id }}
            items={unitMedia}
            loading={galleryLoading}
            onRefetch={() => { void loadUnitMedia(galleryUnit.id); reload(); }}
          />
        )}
      </DetailDrawer>

      {/* ── Archive / reactivate confirm ── */}
      <DestructiveConfirmDialog
        open={archiveOpen}
        onClose={() => setArchiveOpen(false)}
        onConfirm={toggleArchive}
        title={p.is_active ? 'Archive property?' : 'Reactivate property?'}
        description={
          p.is_active
            ? 'Archiving deactivates this property. Its listings will no longer be publishable until you reactivate it. Units, tenants, and history are preserved.'
            : 'Reactivate this property so you can publish listings for its units again.'
        }
        confirmLabel={p.is_active ? 'Archive' : 'Reactivate'}
        loading={archiving}
      />
    </div>
  );
}

/* ══════════════════════════════════════════════════════════════════════════
   TABS
══════════════════════════════════════════════════════════════════════════ */

function OverviewTab({ data, address }: { data: PropertyDetailPayload; address: string }) {
  const p = data.property;
  const rules = p.rules;
  return (
    <>
      {data.attention.length > 0 && (
        <section className="sec glass">
          <div className="sec-h">Needs attention</div>
          {data.attention.map((a, i) => (
            <div key={i} className={`warnrow ${a.level === 'red' ? 'red' : 'warn'}`}>
              <div className="wi"><IconWarn /></div>
              <div>
                <div className="wt">{a.level === 'red' ? 'Action needed' : 'Recommended'}</div>
                <div className="ws">{a.message}</div>
              </div>
            </div>
          ))}
        </section>
      )}

      <section className="sec glass">
        <div className="sec-h">Property information</div>
        <div className="two">
          <div>
            <KV k="Property name" v={p.name} />
            <KV k="Type" v={humanize(p.property_type)} />
            <KV k="Address" v={address} />
            <KV k="Status" v={p.is_active ? 'Active' : 'Inactive'} />
            <KV k="Year built" v={p.year_built ?? '—'} />
          </div>
          <div>
            <KV k="Parking" v={p.parking} />
            <KV k="Pet policy" v={p.pet_policy ?? (rules?.pets_allowed != null ? (rules.pets_allowed ? 'Pets allowed' : 'No pets') : '—')} />
            <KV k="Smoking" v={p.smoking_policy ?? (rules?.smoking_allowed != null ? (rules.smoking_allowed ? 'Smoking allowed' : 'No smoking') : '—')} />
            <KV k="Created" v={formatDate(p.created_at)} />
            <KV k="Rating" v={p.average_rating != null ? `${p.average_rating} (${p.review_count})` : 'No reviews'} />
          </div>
        </div>

        {p.description && (
          <div style={{ marginTop: '1.2rem' }}>
            <div className="dl" style={{ marginBottom: '.4rem' }}>Description</div>
            <p style={{ fontSize: '.9rem', color: 'var(--wp-ink-2)' }}>{p.description}</p>
          </div>
        )}

        {p.amenities && p.amenities.length > 0 && (
          <div style={{ marginTop: '1.2rem' }}>
            <div className="dl" style={{ marginBottom: '.5rem' }}>Amenities</div>
            <div className="chips">
              {p.amenities.map((a) => <span key={a} className="chip">{humanize(a)}</span>)}
            </div>
          </div>
        )}
      </section>
    </>
  );
}

function Badge({ tone, children }: { tone: BadgeTone; children: React.ReactNode }) {
  return <span className={`badge ${tone}`}>{children}</span>;
}

function UnitsTab({ data, onAdd, onEdit, onList, onPhotos }: {
  data: PropertyDetailPayload;
  onAdd: () => void;
  onEdit: (id: number) => void;
  onList: (id: number) => void;
  onPhotos: (u: PropertyDetailUnit) => void;
}) {
  if (data.units.length === 0) {
    return (
      <EmptyTab
        title="No units yet"
        body="Add a unit to describe the homes inside this property. You need at least one unit before you can publish a listing."
        action={<button className="btn btn-petrol" onClick={onAdd}>Add a unit</button>}
      />
    );
  }
  return (
    <section className="sec glass">
      <div className="sec-h">
        Units
        <span className="hint"><button className="btn btn-sm btn-petrol" onClick={onAdd}><IconPlus /> Add unit</button></span>
      </div>
      <div className="tablewrap">
        <table className="tbl">
          <thead>
            <tr>
              <th>Unit</th><th>Bed / Bath</th><th className="r">Rent</th>
              <th>Occupancy</th><th>Listing</th><th>Tenant</th><th className="r">Action</th>
            </tr>
          </thead>
          <tbody>
            {data.units.map((u: PropertyDetailUnit) => {
              const canList = u.availability_status === 'available' && !u.has_blocking_listing;
              return (
                <tr key={u.id}>
                  <td style={{ fontWeight: 600 }}>{u.unit_number}</td>
                  <td>{Number(u.bedrooms)} bed · {Number(u.bathrooms)} bath</td>
                  <td className="r num">{moneyDecimal(u.rent_amount)}</td>
                  <td><Badge tone={unitAvailabilityTone(u.availability_status)}>{humanize(u.availability_status)}</Badge></td>
                  <td>{u.listing_status === 'none' ? <Badge tone="gray">Not listed</Badge> : <Badge tone={listingStatusTone(u.listing_status)}>{humanize(u.listing_status)}</Badge>}</td>
                  <td>{u.tenant_name ?? '—'}</td>
                  <td className="r">
                    <div style={{ display: 'flex', gap: '.35rem', justifyContent: 'flex-end', flexWrap: 'wrap' }}>
                      {canList && <button className="btn btn-sm btn-petrol" onClick={() => onList(u.id)}>List</button>}
                      <button className="btn btn-sm" onClick={() => onPhotos(u)}>Photos</button>
                      <button className="btn btn-sm" onClick={() => onEdit(u.id)}>Edit</button>
                    </div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </section>
  );
}

function ListingsTab({ data, onManage, onCreate }: { data: PropertyDetailPayload; onManage: () => void; onCreate: () => void }) {
  if (data.listings.length === 0) {
    return (
      <EmptyTab
        title="No listings yet"
        body="Create a listing from a vacant unit to make it visible to tenants. Listings are reviewed by Wyncrest before they go live."
        action={<button className="btn btn-petrol" onClick={onCreate}>Go to units</button>}
      />
    );
  }
  return (
    <section className="sec glass">
      <div className="sec-h">
        Listings
        <span className="hint"><button className="btn btn-sm" onClick={onManage}><IconList /> Manage listings</button></span>
      </div>
      {data.listings.map((l: PropertyDetailListing) => {
        const feedback = l.rejection_reason ?? l.changes_requested_reason;
        return (
          <div key={l.id} className="listcard">
            <div className="lh">
              <div>
                <div className="lt">{l.title}</div>
                <div className="lsub">
                  Unit {l.unit_number}
                  {l.rent_amount ? ` · ${moneyDecimal(l.rent_amount)}/mo` : ''}
                  {l.published_at ? ` · published ${formatDate(l.published_at)}` : ''}
                </div>
              </div>
              <Badge tone={listingStatusTone(l.status)}>{humanize(l.status)}</Badge>
            </div>
            <div className="lstats">
              <div className="lstat"><div className="n">{l.applications_count}</div><div className="l">Applications</div></div>
              <div className="lstat"><div className="n">{l.view_count}</div><div className="l">Views</div></div>
              <div className="lstat"><div className="n">{humanize(l.status)}</div><div className="l">Review</div></div>
            </div>
            {feedback && (
              <div className="feedback"><b>Admin feedback:</b> {feedback}</div>
            )}
            <div className="lf">
              <button className="btn btn-sm" onClick={onManage}>Manage listing</button>
            </div>
          </div>
        );
      })}
    </section>
  );
}

function TenantsTab({ data }: { data: PropertyDetailPayload }) {
  if (data.contracts.length === 0) {
    return <EmptyTab title="No tenants or contracts" body="When a tenant signs a lease for one of your units, they and their contract will appear here." />;
  }
  return (
    <section className="sec glass">
      <div className="sec-h">Tenants &amp; contracts</div>
      <div className="tablewrap">
        <table className="tbl">
          <thead>
            <tr><th>Tenant</th><th>Unit</th><th>Contract</th><th>Start</th><th>End</th><th className="r">Rent</th><th className="r">Balance</th></tr>
          </thead>
          <tbody>
            {data.contracts.map((c: PropertyDetailContract) => (
              <tr key={c.id}>
                <td style={{ fontWeight: 600 }}>{c.tenant_name ?? '—'}</td>
                <td>{c.unit_number}</td>
                <td><Badge tone={contractStatusTone(c.status)}>{humanize(c.status)}</Badge></td>
                <td>{formatDate(c.start_date)}</td>
                <td>{c.end_date ? formatDate(c.end_date) : 'Open'}</td>
                <td className="r num">{moneyCents(c.rent_amount_cents)}</td>
                <td className="r num" style={{ color: c.balance_cents > 0 ? 'var(--wp-oxblood)' : 'var(--wp-green)' }}>
                  {moneyCents(c.balance_cents)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}

function LedgerTab({ data, onExport }: { data: PropertyDetailPayload; onExport: () => void }) {
  if (data.ledger.length === 0) {
    return <EmptyTab title="No financial activity yet" body="Rent charges, payments, and fees for this property will appear here once you have active contracts." />;
  }
  return (
    <section className="sec glass">
      <div className="sec-h">
        Payments &amp; ledger
        <span className="hint"><button className="btn btn-sm" onClick={onExport}>Export CSV</button></span>
      </div>
      <div className="tablewrap">
        <table className="tbl">
          <thead>
            <tr><th>Date</th><th>Unit</th><th>Tenant</th><th>Type</th><th className="r">Amount</th><th>Status</th><th>Reference</th></tr>
          </thead>
          <tbody>
            {data.ledger.map((e: PropertyDetailLedgerEntry) => (
              <tr key={e.id}>
                <td className="num" style={{ fontSize: '.78rem' }}>{formatDate(e.occurred_at ?? e.due_date)}</td>
                <td>{e.unit_number}</td>
                <td>{e.tenant_name ?? '—'}</td>
                <td>{e.display_label}</td>
                <td className="r num" style={{ color: e.direction === 'payment' ? 'var(--wp-green)' : 'var(--wp-ink)' }}>
                  {e.direction === 'payment' ? '−' : ''}{moneyCents(e.display_amount_cents)}
                </td>
                <td><Badge tone={ledgerStatusTone(e.status)}>{humanize(e.status)}</Badge></td>
                <td className="mono-sm">{e.reference}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div style={{ fontSize: '.78rem', color: 'var(--wp-ink-3)', marginTop: '.8rem' }}>
        Balances shown reflect this property only. The full platform ledger is maintained by Wyncrest as the source of truth.
      </div>
    </section>
  );
}

function MaintenanceTab({ data, onOpen }: { data: PropertyDetailPayload; onOpen: () => void }) {
  if (data.maintenance.length === 0) {
    return <EmptyTab title="No maintenance requests" body="There are no maintenance requests for this property. When a tenant reports an issue, it will appear here." />;
  }
  return (
    <section className="sec glass">
      <div className="sec-h">
        Maintenance requests
        <span className="hint"><button className="btn btn-sm" onClick={onOpen}>Open maintenance</button></span>
      </div>
      <div className="tablewrap">
        <table className="tbl">
          <thead>
            <tr><th>Request</th><th>Unit</th><th>Tenant</th><th>Priority</th><th>Status</th><th>Submitted</th></tr>
          </thead>
          <tbody>
            {data.maintenance.map((m: PropertyDetailMaintenance) => (
              <tr key={m.id}>
                <td style={{ fontWeight: 600 }}>{m.title}</td>
                <td>{m.unit_number}</td>
                <td>{m.tenant_name ?? '—'}</td>
                <td><Badge tone={maintenancePriorityTone(m.priority)}>{humanize(m.priority)}</Badge></td>
                <td><Badge tone={maintenanceStatusTone(m.status)}>{humanize(m.status)}</Badge></td>
                <td className="num" style={{ fontSize: '.78rem' }}>{formatDate(m.submitted_at)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}

function DocumentsTab({ data }: { data: PropertyDetailPayload }) {
  if (data.documents.length === 0) {
    return <EmptyTab title="No documents yet" body="Ownership, insurance, and compliance documents attached to this property will appear here." />;
  }
  return (
    <section className="sec glass">
      <div className="sec-h">Documents</div>
      <div className="tablewrap">
        <table className="tbl">
          <thead>
            <tr><th>File</th><th>Type</th><th>Uploaded by</th><th>Date</th><th>Status</th></tr>
          </thead>
          <tbody>
            {data.documents.map((d) => (
              <tr key={d.id}>
                <td style={{ fontWeight: 600 }}>{d.original_filename}</td>
                <td>{d.document_type ? humanize(d.document_type) : '—'}</td>
                <td>{d.uploader_name ?? '—'}</td>
                <td className="num" style={{ fontSize: '.78rem' }}>{formatDate(d.created_at)}</td>
                <td><Badge tone={d.is_verified ? 'green' : 'amber'}>{d.is_verified ? 'Verified' : 'Pending'}</Badge></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}

function PhotosTab({ data, onManage }: { data: PropertyDetailPayload; onManage: () => void }) {
  if (data.photos.length === 0) {
    return (
      <EmptyTab
        title="No photos yet"
        body="Add a cover photo and a gallery so tenants can see what this property looks like. Properties with photos get more applications."
        action={<button className="btn btn-petrol" onClick={onManage}>Upload photos</button>}
      />
    );
  }
  return (
    <section className="sec glass">
      <div className="sec-h">
        Photos
        <span className="hint"><button className="btn btn-sm btn-petrol" onClick={onManage}>Manage photos</button></span>
      </div>
      <div className="photogrid">
        {data.photos.map((ph, i) => (
          <div key={ph.id} className={`phototile ${ph.is_cover ? 'cover-tag' : ''}`} style={{ background: gradientFor(i + 1) }}>
            {ph.url ? <img src={ph.url} alt={ph.alt_text ?? ph.scope} /> : (
              <div className="cvicon"><IconImage /></div>
            )}
            <span className="pl2">{ph.scope}{ph.caption ? ` · ${ph.caption}` : ''}</span>
          </div>
        ))}
      </div>
    </section>
  );
}

function ActivityTab({ data }: { data: PropertyDetailPayload }) {
  if (data.activity.length === 0) {
    return <EmptyTab title="No activity yet" body="Changes to this property, its units, and its listings will show up here as they happen." />;
  }
  return (
    <section className="sec glass">
      <div className="sec-h">
        Activity log
        <span className="hint">Who changed what and when</span>
      </div>
      <div className="tl">
        {data.activity.map((a) => (
          <div key={a.id} className="tl-item">
            <div className="te">{a.description ?? humanize(a.action)}</div>
            <div className="tm">
              {formatDate(a.created_at)} · <span className="actor">{a.actor_name ? `${a.actor_role} · ${a.actor_name}` : a.actor_role}</span>
            </div>
          </div>
        ))}
      </div>
    </section>
  );
}

/* ══════════════════════════════════════════════════════════════════════════
   SMALL PIECES
══════════════════════════════════════════════════════════════════════════ */

function DCard({ label, value, sub, cls }: { label: string; value: React.ReactNode; sub: string; cls?: string }) {
  return (
    <div className={`scard glass ${cls ?? ''}`}>
      <div className="sl">{label}</div>
      <div className="sv">{value}</div>
      <div className="ss">{sub}</div>
    </div>
  );
}

function EmptyTab({ title, body, action }: { title: string; body: string; action?: React.ReactNode }) {
  return (
    <section className="sec glass">
      <div className="emptytab">
        <div className="ei"><IconList /></div>
        <div className="et2">{title}</div>
        <p>{body}</p>
        {action}
      </div>
    </section>
  );
}

function BackLink({ onClick }: { onClick: () => void }) {
  return (
    <button className="back" onClick={onClick}>
      <IconBack /> Back to Properties
    </button>
  );
}
