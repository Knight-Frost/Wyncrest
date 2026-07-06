import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { landlordApi } from '@/lib/endpoints';
import { fieldErrors } from '@/lib/api';
import type { ApiError, Listing, ListingStatus, MediaAsset } from '@/lib/types';
import { humanize, storageUrl, timeAgo } from '@/lib/format';
import { ErrorState, LoadingState } from '@/components/ui/states';
import { Button } from '@/components/ui/Button';
import { DetailDrawer } from '@/components/ui/Drawer';
import { DestructiveConfirmDialog } from '@/components/ui/DestructiveConfirmDialog';
import { Field, Input, Textarea } from '@/components/ui/Field';
import { GalleryManager } from '@/components/media/GalleryManager';
import { useToast } from '@/components/ui/toast';
import { IconEdit, IconImage, IconPlus, IconSearch } from './properties-ui';
import { IconEye, IconUsers, IconDots, IconTrash, IconLink, IconBox, IconUp, IconX, IconDownload } from './listing-ui';
import { moneyDecimal } from './properties-helpers';
import './properties.css';
import './listings.css';

/* ──────────────────────────────────────────────────────────────────────────
   FILTERS
────────────────────────────────────────────────────────────────────────── */

type FilterKey = 'all' | ListingStatus;
type SortKey = 'attention' | 'apps' | 'renthi' | 'rentlo' | 'newest';

const ATTENTION_ORDER: Record<string, number> = {
  rejected: 0, draft: 1, pending_review: 2, inactive: 3, active: 4, archived: 5,
};

const STATUS_TABS: { key: FilterKey; label: string }[] = [
  { key: 'all', label: 'All' },
  { key: 'active' as ListingStatus, label: 'Active' },
  { key: 'draft' as ListingStatus, label: 'Draft' },
  { key: 'pending_review' as ListingStatus, label: 'Pending review' },
  { key: 'inactive' as ListingStatus, label: 'Inactive' },
  { key: 'rejected' as ListingStatus, label: 'Rejected' },
  { key: 'archived' as ListingStatus, label: 'Archived' },
];

interface ListingForm {
  title: string;
  description: string;
  pets_allowed: boolean;
  pet_policy: string;
  lease_duration_months: string;
  move_in_date: string;
}

function emptyListingForm(): ListingForm {
  return { title: '', description: '', pets_allowed: false, pet_policy: '', lease_duration_months: '', move_in_date: '' };
}

function listingFormFrom(l: Listing): ListingForm {
  return {
    title: l.title,
    description: l.description,
    pets_allowed: l.pets_allowed,
    pet_policy: l.pet_policy ?? '',
    lease_duration_months: l.lease_duration_months != null ? String(l.lease_duration_months) : '',
    move_in_date: l.move_in_date ?? '',
  };
}

function validateListing(form: ListingForm): Record<string, string> {
  const errs: Record<string, string> = {};
  if (!form.title.trim()) errs.title = 'A title is required.';
  if (form.description.trim().length < 50) errs.description = 'Description must be at least 50 characters.';
  return errs;
}

/** What action moves a listing forward from each status — mirrors the real backend lifecycle. */
type LifecycleAction = 'submit' | 'withdraw' | 'deactivate' | 'reactivate' | 'archive' | 'restore';

/* ──────────────────────────────────────────────────────────────────────────
   COMPONENT
────────────────────────────────────────────────────────────────────────── */

export function LandlordListings() {
  const { toast } = useToast();
  const navigate = useNavigate();
  const { data, loading, error, reload } = useApi(() => landlordApi.listings(), []);

  const [searchParams, setSearchParams] = useSearchParams();
  const [tab, setTab] = useState<FilterKey>(() => {
    const s = searchParams.get('status');
    return STATUS_TABS.some((t) => t.key === s) ? (s as FilterKey) : 'all';
  });

  // Consume the Analytics page's ?status= deep link once, then clean the URL.
  useEffect(() => {
    if (searchParams.has('status')) {
      const next = new URLSearchParams(searchParams);
      next.delete('status');
      setSearchParams(next, { replace: true });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const [search, setSearch] = useState('');
  const [sort, setSort] = useState<SortKey>('attention');
  const [exporting, setExporting] = useState(false);
  const [openMenuId, setOpenMenuId] = useState<number | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  // Edit drawer
  const [editing, setEditing] = useState<Listing | null>(null);
  const [form, setForm] = useState<ListingForm>(emptyListingForm());
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  // Delete confirm
  const [toDelete, setToDelete] = useState<Listing | null>(null);
  const [deleting, setDeleting] = useState(false);

  // Gallery drawer
  const [galleryListing, setGalleryListing] = useState<Listing | null>(null);
  const [galleryItems, setGalleryItems] = useState<MediaAsset[]>([]);
  const [galleryLoading, setGalleryLoading] = useState(false);

  useEffect(() => {
    function onDocClick() { setOpenMenuId(null); }
    document.addEventListener('click', onDocClick);
    return () => document.removeEventListener('click', onDocClick);
  }, []);

  const listings = useMemo(() => data ?? [], [data]);

  const counts = useMemo(() => {
    const c: Record<string, number> = {};
    for (const l of listings) c[l.status] = (c[l.status] ?? 0) + 1;
    return c;
  }, [listings]);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    let rows = tab === 'all' ? listings : listings.filter((l) => l.status === tab);
    if (q) {
      rows = rows.filter((l) => {
        const hay = [l.title, l.unit?.unit_number, l.unit?.property?.name, l.unit?.property?.city]
          .filter(Boolean).join(' ').toLowerCase();
        return hay.includes(q);
      });
    }
    const rentOf = (l: Listing) => parseFloat(l.unit?.rent_amount ?? '0') || 0;
    return [...rows].sort((a, b) => {
      switch (sort) {
        case 'apps': return (b.applications_count ?? 0) - (a.applications_count ?? 0);
        case 'renthi': return rentOf(b) - rentOf(a);
        case 'rentlo': return rentOf(a) - rentOf(b);
        case 'newest': return +new Date(b.updated_at) - +new Date(a.updated_at);
        case 'attention':
        default:
          return (ATTENTION_ORDER[a.status] ?? 9) - (ATTENTION_ORDER[b.status] ?? 9);
      }
    });
  }, [listings, tab, search, sort]);

  function openCreate() {
    navigate('/app/listings/create');
  }
  function openEdit(listing: Listing) {
    setEditing(listing);
    setForm(listingFormFrom(listing));
    setFormErrors({});
  }
  async function loadGallery(listingId: number) {
    setGalleryLoading(true);
    try {
      const full = await landlordApi.listing(listingId);
      setGalleryItems((full.media_assets ?? []).slice().sort((a, b) => a.sort_order - b.sort_order));
    } catch (err) {
      toast((err as ApiError).message, 'error');
    } finally {
      setGalleryLoading(false);
    }
  }
  function openGallery(listing: Listing) {
    setGalleryListing(listing);
    setGalleryItems([]);
    void loadGallery(listing.id);
  }

  async function handleSave(e: React.FormEvent) {
    e.preventDefault();
    if (!editing) return;
    const localErrors = validateListing(form);
    if (Object.keys(localErrors).length > 0) { setFormErrors(localErrors); return; }
    setSaving(true);
    setFormErrors({});
    try {
      await landlordApi.updateListing(editing.id, {
        title: form.title.trim(),
        description: form.description.trim(),
        pets_allowed: form.pets_allowed,
        pet_policy: form.pets_allowed ? form.pet_policy || null : null,
        lease_duration_months: form.lease_duration_months ? Number(form.lease_duration_months) : null,
        move_in_date: form.move_in_date || null,
      });
      toast('Listing updated', 'success');
      setEditing(null);
      reload();
    } catch (err) {
      const e2 = err as ApiError;
      const fe = fieldErrors(e2);
      setFormErrors(fe);
      if (Object.keys(fe).length === 0) toast(e2.message, 'error');
    } finally {
      setSaving(false);
    }
  }

  async function runLifecycle(listing: Listing, action: LifecycleAction) {
    setBusyId(listing.id);
    setOpenMenuId(null);
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
      setBusyId(null);
    }
  }

  async function handleDelete() {
    if (!toDelete) return;
    setDeleting(true);
    try {
      await landlordApi.deleteListing(toDelete.id);
      toast('Draft deleted', 'success');
      setToDelete(null);
      reload();
    } catch (err) {
      toast((err as ApiError).message, 'error');
    } finally {
      setDeleting(false);
    }
  }

  async function handleExport() {
    setExporting(true);
    try {
      await landlordApi.exportListings();
      toast('Listings exported', 'success');
    } catch {
      toast('Export failed', 'error');
    } finally {
      setExporting(false);
    }
  }

  if (loading) {
    return <div className="wprop"><LoadingState label="Loading listings…" /></div>;
  }
  if (error) {
    return <div className="wprop"><ErrorState message={error.message} onRetry={reload} /></div>;
  }

  const noneAtAll = listings.length === 0;

  return (
    <div className="wprop animate-rise">
      {/* Page head */}
      <section className="glass pagehead">
        <div>
          <span className="ph-eyebrow">Portfolio</span>
          <h1 className="ph-title">Your <b>listings.</b></h1>
          <p className="ph-sub">Publish and track rental listings across your units. Listings are what tenants see and apply for.</p>
        </div>
        <div style={{ display: 'flex', gap: '.45rem', flexWrap: 'wrap' }}>
          <button className="btn" onClick={handleExport} disabled={exporting || noneAtAll}>
            <IconDownload /> {exporting ? 'Exporting…' : 'Export'}
          </button>
          <button className="btn btn-dark" onClick={openCreate}><IconPlus /> Create listing</button>
        </div>
      </section>

      {/* Summary cards */}
      <div className="sumcards">
        <SCard label="Total listings" dot="var(--wp-petrol-2)" value={listings.length} sub="across your portfolio" />
        <SCard label="Active" dot="var(--wp-green)" value={counts.active ?? 0} sub="visible to tenants" cls="occ" />
        <SCard label="Pending review" dot="var(--wp-amber)" value={counts.pending_review ?? 0} sub="awaiting admin" />
        <SCard label="Drafts" dot="var(--wp-slate)" value={counts.draft ?? 0} sub="not yet submitted" />
        {(counts.rejected ?? 0) > 0 && (
          <SCard label="Rejected" dot="var(--wp-oxblood)" value={counts.rejected ?? 0} sub="changes required" cls="att" />
        )}
      </div>

      {/* Toolbar */}
      {!noneAtAll && (
        <section className="glass">
          <div className="toolbar" style={{ flexDirection: 'column', alignItems: 'stretch', gap: '.8rem' }}>
            <div className="chips">
              {STATUS_TABS.map((t) => {
                const n = t.key === 'all' ? listings.length : (counts[t.key] ?? 0);
                return (
                  <button key={t.key} className={`chip ${tab === t.key ? 'on' : ''}`} onClick={() => setTab(t.key)}>
                    {t.label} <span className="c" style={{ fontFamily: 'var(--wp-mono)', fontSize: '.66rem', opacity: .75 }}>{n}</span>
                  </button>
                );
              })}
            </div>
            <div className="filt2">
              <div className="search">
                <IconSearch />
                <input placeholder="Search listings by property, unit, title, or city…" value={search} onChange={(e) => setSearch(e.target.value)} aria-label="Search listings" />
              </div>
              <select className="sel" aria-label="Sort" value={sort} onChange={(e) => setSort(e.target.value as SortKey)}>
                <option value="attention">Sort: Needs attention first</option>
                <option value="apps">Sort: Most applications</option>
                <option value="renthi">Sort: Highest rent</option>
                <option value="rentlo">Sort: Lowest rent</option>
                <option value="newest">Sort: Recently updated</option>
              </select>
            </div>
          </div>
        </section>
      )}

      {/* Grid / empty states */}
      {noneAtAll ? (
        <section className="glass">
          <div className="empty">
            <div className="ic"><IconEdit /></div>
            <span className="et">No listings yet</span>
            <p>Create a listing when you are ready to advertise one of your available units. Listings are what tenants see and apply for.</p>
            <button className="btn btn-dark" onClick={openCreate}><IconPlus /> Create your first listing</button>
          </div>
        </section>
      ) : filtered.length === 0 ? (
        <section className="glass">
          <div className="empty">
            <div className="ic"><IconSearch /></div>
            <span className="et">No listings match</span>
            <p>No listings match your search or filter. Try changing the term or clearing filters.</p>
            <button className="btn" onClick={() => { setTab('all'); setSearch(''); }}>Clear filters</button>
          </div>
        </section>
      ) : (
        <div className="lgrid">
          {filtered.map((listing) => (
            <ListingRow
              key={listing.id}
              listing={listing}
              busy={busyId === listing.id}
              menuOpen={openMenuId === listing.id}
              onToggleMenu={(e) => { e.stopPropagation(); setOpenMenuId((cur) => (cur === listing.id ? null : listing.id)); }}
              onOpen={() => navigate(`/app/listings/${listing.id}`)}
              onEdit={() => openEdit(listing)}
              onPhotos={() => openGallery(listing)}
              onDelete={() => setToDelete(listing)}
              onLifecycle={(action) => runLifecycle(listing, action)}
              onPublicView={() => navigate(`/app/listing/${listing.id}`)}
            />
          ))}
        </div>
      )}

      {/* Edit listing drawer */}
      <DetailDrawer
        open={editing !== null}
        onClose={() => setEditing(null)}
        eyebrow="LISTING"
        title="Edit listing"
        description="Update the listing details. Rejected listings can be resubmitted for review once fixed."
        footer={
          <>
            <Button variant="secondary" onClick={() => setEditing(null)} disabled={saving}>Cancel</Button>
            <Button type="submit" form="listing-form" loading={saving}>Save changes</Button>
          </>
        }
      >
        <form id="listing-form" onSubmit={handleSave} className="space-y-4">
          <Field label="Unit">
            {(fid) => (
              <Input id={fid} disabled value={
                editing?.unit ? `Unit ${editing.unit.unit_number}` + (editing.unit.internal_name ? ` · ${editing.unit.internal_name}` : '') : `Unit #${editing?.unit_id ?? ''}`
              } />
            )}
          </Field>
          <Field label="Title" error={formErrors.title} required>
            {(fid, invalid) => (
              <Input id={fid} invalid={invalid} placeholder="e.g. Bright 2-bed apartment in East Legon" value={form.title} onChange={(e) => setForm((p) => ({ ...p, title: e.target.value }))} />
            )}
          </Field>
          <Field label="Description" error={formErrors.description} hint={`At least 50 characters · ${form.description.trim().length}/50`} required>
            {(fid, invalid) => (
              <Textarea id={fid} invalid={invalid} rows={5} placeholder="Describe the home, the neighbourhood, and what makes it a great rental…" value={form.description} onChange={(e) => setForm((p) => ({ ...p, description: e.target.value }))} />
            )}
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Lease duration (months)" error={formErrors.lease_duration_months}>
              {(fid, invalid) => (
                <Input id={fid} type="number" min="1" invalid={invalid} value={form.lease_duration_months} onChange={(e) => setForm((p) => ({ ...p, lease_duration_months: e.target.value }))} />
              )}
            </Field>
            <Field label="Available move-in date" error={formErrors.move_in_date}>
              {(fid, invalid) => (
                <Input id={fid} type="date" invalid={invalid} value={form.move_in_date} onChange={(e) => setForm((p) => ({ ...p, move_in_date: e.target.value }))} />
              )}
            </Field>
          </div>
          <Field label="Pets">
            {(fid) => (
              <label htmlFor={fid} className="flex items-center gap-2 text-sm text-ink-700">
                <input id={fid} type="checkbox" className="h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-500" checked={form.pets_allowed} onChange={(e) => setForm((p) => ({ ...p, pets_allowed: e.target.checked }))} />
                Pets allowed
              </label>
            )}
          </Field>
          {form.pets_allowed && (
            <Field label="Pet policy" error={formErrors.pet_policy}>
              {(fid, invalid) => (
                <Input id={fid} invalid={invalid} placeholder="e.g. Cats and small dogs welcome, GH₵ 500 pet deposit" value={form.pet_policy} onChange={(e) => setForm((p) => ({ ...p, pet_policy: e.target.value }))} />
              )}
            </Field>
          )}
        </form>
      </DetailDrawer>

      {/* Delete confirm */}
      <DestructiveConfirmDialog
        open={toDelete !== null}
        onClose={() => setToDelete(null)}
        onConfirm={handleDelete}
        title="Delete draft?"
        description={toDelete ? `This permanently removes the draft listing "${toDelete.title || 'Untitled listing'}".` : undefined}
        confirmLabel="Delete draft"
        loading={deleting}
      />

      {/* Gallery drawer */}
      <DetailDrawer
        open={galleryListing !== null}
        onClose={() => setGalleryListing(null)}
        title={galleryListing ? `Photos: ${galleryListing.title || 'Untitled listing'}` : 'Photos'}
        widthClass="sm:max-w-[820px]"
        footer={<Button variant="secondary" onClick={() => setGalleryListing(null)}>Close</Button>}
      >
        {galleryListing && (
          <GalleryManager
            target={{ type: 'listing', id: galleryListing.id }}
            items={galleryItems}
            loading={galleryLoading}
            onRefetch={() => { void loadGallery(galleryListing.id); reload(); }}
          />
        )}
      </DetailDrawer>
    </div>
  );
}

/* ──────────────────────────────────────────────────────────────────────────
   SUBCOMPONENTS
────────────────────────────────────────────────────────────────────────── */

function SCard({ label, dot, value, sub, cls }: { label: string; dot: string; value: number; sub: string; cls?: string }) {
  return (
    <div className={`scard glass ${cls ?? ''}`}>
      <div className="sl"><i style={{ background: dot }} />{label}</div>
      <div className="sv">{value}</div>
      <div className="ss">{sub}</div>
    </div>
  );
}

function coverSrc(listing: Listing): string | null {
  const asset = (listing.media_assets ?? [])[0];
  if (asset?.url) return asset.url;
  const legacy = listing.primary_photo?.path;
  return legacy ? storageUrl(legacy) : null;
}

function primaryActionLabel(status: ListingStatus): string {
  switch (status) {
    case 'draft': return 'Continue editing';
    case 'rejected': return 'Fix listing';
    case 'inactive': return 'Reactivate';
    default: return 'View details';
  }
}

function ListingRow({
  listing, busy, menuOpen, onToggleMenu, onOpen, onEdit, onPhotos, onDelete, onLifecycle, onPublicView,
}: {
  listing: Listing;
  busy: boolean;
  menuOpen: boolean;
  onToggleMenu: (e: React.MouseEvent) => void;
  onOpen: () => void;
  onEdit: () => void;
  onPhotos: () => void;
  onDelete: () => void;
  onLifecycle: (action: LifecycleAction) => void;
  onPublicView: () => void;
}) {
  const cover = coverSrc(listing);
  const rent = listing.unit?.rent_amount;
  const missing = listing.missing_requirements ?? [];

  function stop(fn: () => void) {
    return (e: React.MouseEvent) => { e.stopPropagation(); fn(); };
  }

  const menuItems: { label: string; icon: React.ReactNode; onClick: () => void; danger?: boolean }[] = (() => {
    switch (listing.status) {
      case 'draft':
        return [
          { label: 'Continue editing', icon: <IconEdit />, onClick: onEdit },
          { label: 'Submit for review', icon: <IconUp />, onClick: () => onLifecycle('submit') },
          { label: 'Archive', icon: <IconBox />, onClick: () => onLifecycle('archive') },
          { label: 'Delete draft', icon: <IconTrash />, onClick: onDelete, danger: true },
        ];
      case 'pending_review':
        return [
          { label: 'View details', icon: <IconEye />, onClick: onOpen },
          { label: 'Withdraw submission', icon: <IconX />, onClick: () => onLifecycle('withdraw'), danger: true },
        ];
      case 'active':
        return [
          { label: 'View public listing', icon: <IconLink />, onClick: onPublicView },
          { label: 'View applications', icon: <IconUsers />, onClick: onOpen },
          { label: 'Manage photos', icon: <IconImage />, onClick: onPhotos },
          { label: 'Deactivate', icon: <IconX />, onClick: () => onLifecycle('deactivate') },
        ];
      case 'rejected':
        return [
          { label: 'View rejection reason', icon: <IconEye />, onClick: onOpen },
          { label: 'Fix listing', icon: <IconEdit />, onClick: onEdit },
          { label: 'Resubmit for review', icon: <IconUp />, onClick: () => onLifecycle('submit') },
          { label: 'Archive', icon: <IconBox />, onClick: () => onLifecycle('archive') },
        ];
      case 'inactive':
        return [
          { label: 'Reactivate', icon: <IconUp />, onClick: () => onLifecycle('reactivate') },
          { label: 'Edit listing', icon: <IconEdit />, onClick: onEdit },
          { label: 'Manage photos', icon: <IconImage />, onClick: onPhotos },
          { label: 'Archive', icon: <IconBox />, onClick: () => onLifecycle('archive') },
        ];
      default: // archived
        return [
          { label: 'View details', icon: <IconEye />, onClick: onOpen },
          { label: 'Restore to draft', icon: <IconUp />, onClick: () => onLifecycle('restore') },
        ];
    }
  })();

  return (
    <div className="lcard glass" onClick={onOpen} role="button" tabIndex={0} onKeyDown={(e) => { if (e.key === 'Enter') onOpen(); }}>
      <div className={`lc-photo ${cover ? '' : 'missing'}`} style={cover ? undefined : undefined}>
        {cover ? <img src={cover} alt={listing.title} /> : <div className="cvicon"><IconImage /></div>}
      </div>
      <div className="lc-body">
        <div className="lc-top">
          <div>
            <div className="lc-title">{listing.title || 'Untitled listing'}</div>
            <div className="lc-sub">
              {listing.unit?.property?.name ?? 'Property'} · Unit {listing.unit?.unit_number ?? listing.unit_id}
              {listing.unit?.property?.city ? ` · ${listing.unit.property.city}` : ''}
            </div>
          </div>
          <span className={`statuspill ${listing.status}`}><span className="sd" style={{ background: 'currentColor' }} />{humanize(listing.status)}</span>
        </div>

        <div className="lc-stats">
          <div className="lc-stat"><div className="n rent">{rent ? moneyDecimal(rent) : '—'}</div><div className="l">{rent ? 'per month' : 'rent'}</div></div>
          <div className="lc-stat"><div className="n">{listing.applications_count ?? 0}</div><div className="l">Applications</div></div>
          {listing.view_count > 0 && <div className="lc-stat"><div className="n">{listing.view_count}</div><div className="l">Views</div></div>}
          <div className="lc-stat"><div className="n">{listing.unit?.bedrooms ?? '—'}/{listing.unit?.bathrooms ?? '—'}</div><div className="l">Bed / Bath</div></div>
        </div>

        {listing.status === 'rejected' && listing.rejection_reason && (
          <div className="rejnote"><b>Reason:</b> {listing.rejection_reason}</div>
        )}
        {listing.status === 'draft' && missing.length > 0 && (
          <div className="missnote"><b>Missing:</b> {missing.join(', ')}</div>
        )}

        <div className="lc-foot">
          <span className="upd">Updated {timeAgo(listing.updated_at)}</span>
          <button
            className={`btn btn-sm ${listing.status === 'draft' || listing.status === 'inactive' ? 'btn-petrol' : listing.status === 'rejected' ? 'btn-blood' : ''}`}
            disabled={busy}
            onClick={stop(() => {
              if (listing.status === 'draft' || listing.status === 'rejected') onEdit();
              else if (listing.status === 'inactive') onLifecycle('reactivate');
              else onOpen();
            })}
          >
            {primaryActionLabel(listing.status)}
          </button>
          <div className={`menu ${menuOpen ? 'open' : ''}`}>
            <button className="menu-btn" aria-label="More actions" onClick={onToggleMenu}><IconDots /></button>
            <div className="menu-list" onClick={(e) => e.stopPropagation()}>
              {menuItems.map((item, i) => (
                <button key={i} className={item.danger ? 'danger' : ''} onClick={item.onClick}>{item.icon}{item.label}</button>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
