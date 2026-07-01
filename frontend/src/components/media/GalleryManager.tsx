/**
 * GalleryManager — reusable media gallery component for landlord property/unit/listing pages.
 *
 * Truth contract:
 * - Displays media from a pre-fetched, sort_order-ordered array (the resource's `media_assets`,
 *   returned by GET /landlord/properties/{id} · /units/{id} · /listings/{id}).
 * - Upload → landlordApi.uploadPropertyMedia / uploadUnitMedia / uploadListingMedia.
 * - Reorder → PATCH /landlord/media/reorder (ordered UUID array).
 * - Delete → DELETE /landlord/media/{id} (with confirmation modal).
 * - After any mutation, the parent's `onRefetch` re-fetches the resource so the grid reflects
 *   the new state.
 * - No fake data. All actions call real endpoints.
 */
import { useRef, useState } from 'react';
import { landlordApi } from '@/lib/endpoints';
import type { MediaAsset } from '@/lib/types';
import { useToast } from '@/components/ui/toast';
import { DestructiveConfirmDialog } from '@/components/ui/DestructiveConfirmDialog';
import { Button } from '@/components/ui/Button';
import { Field, Input } from '@/components/ui/Field';
import { LoadingState } from '@/components/ui/states';
import {
  IconTrash,
  IconUpload,
  IconImage,
  IconChevronRight,
  IconX,
} from '@/components/ui/icons';

/* ── Types ────────────────────────────────────────────────────────────────── */

export type GalleryTarget =
  | { type: 'property'; id: number }
  | { type: 'unit'; id: number }
  | { type: 'listing'; id: number };

interface GalleryManagerProps {
  target: GalleryTarget;
  /** Current ordered media array from the parent (refetched after mutations). */
  items: MediaAsset[];
  /** Called after any mutation so the parent can refetch. */
  onRefetch: () => void;
  /** Loading state from the parent's data fetch. */
  loading?: boolean;
  /** Optional max images hint shown to the user. */
  maxImages?: number;
  className?: string;
}

/* ── Helpers ──────────────────────────────────────────────────────────────── */

const MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB

async function uploadForTarget(
  target: GalleryTarget,
  file: File,
  meta?: { alt_text?: string; caption?: string },
): Promise<MediaAsset> {
  switch (target.type) {
    case 'property':
      return landlordApi.uploadPropertyMedia(target.id, file, meta);
    case 'unit':
      return landlordApi.uploadUnitMedia(target.id, file, meta);
    case 'listing':
      return landlordApi.uploadListingMedia(target.id, file, meta);
  }
}

function fmtSize(bytes: number) {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/* ── Drop zone ────────────────────────────────────────────────────────────── */

interface DropZoneProps {
  onFiles: (files: File[]) => void;
  disabled?: boolean;
}

function DropZone({ onFiles, disabled }: DropZoneProps) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [dragOver, setDragOver] = useState(false);

  function handleFiles(files: FileList | null) {
    if (!files || disabled) return;
    const valid = Array.from(files).filter((f) => f.type.startsWith('image/'));
    if (valid.length === 0) return;
    onFiles(valid);
  }

  return (
    <div
      className={`gm-dropzone${dragOver ? ' gm-dropzone--active' : ''}${disabled ? ' gm-dropzone--disabled' : ''}`}
      role="button"
      tabIndex={disabled ? -1 : 0}
      aria-label="Upload images by dragging and dropping, or click to browse"
      onClick={() => !disabled && inputRef.current?.click()}
      onKeyDown={(e) => { if ((e.key === 'Enter' || e.key === ' ') && !disabled) inputRef.current?.click(); }}
      onDragOver={(e) => { e.preventDefault(); if (!disabled) setDragOver(true); }}
      onDragLeave={() => setDragOver(false)}
      onDrop={(e) => {
        e.preventDefault();
        setDragOver(false);
        handleFiles(e.dataTransfer.files);
      }}
    >
      <input
        ref={inputRef}
        type="file"
        accept="image/*"
        multiple
        style={{ display: 'none' }}
        disabled={disabled}
        onChange={(e) => handleFiles(e.target.files)}
      />
      <span className="gm-dropzone-icon"><IconUpload size={22} /></span>
      <span className="gm-dropzone-label">
        {disabled ? 'Uploading…' : 'Add photos'}
      </span>
      <span className="gm-dropzone-hint">Images only · max 10 MB each · drag or click</span>
    </div>
  );
}

/* ── Single image tile ────────────────────────────────────────────────────── */

interface ImageTileProps {
  asset: MediaAsset;
  index: number;
  total: number;
  onMoveUp: () => void;
  onMoveDown: () => void;
  onDelete: () => void;
  reordering: boolean;
}

function ImageTile({ asset, index, total, onMoveUp, onMoveDown, onDelete, reordering }: ImageTileProps) {
  const src = asset.url ?? undefined;

  return (
    <div className="gm-tile" key={asset.id}>
      {/* Thumbnail */}
      <div className="gm-tile-img-wrap">
        {src ? (
          <img src={src} alt={asset.alt_text ?? asset.original_filename} className="gm-tile-img" loading="lazy" />
        ) : (
          <div className="gm-tile-img-placeholder"><IconImage size={28} /></div>
        )}
        {index === 0 && <span className="gm-tile-primary-badge">Primary</span>}
      </div>

      {/* Meta */}
      <div className="gm-tile-meta">
        <p className="gm-tile-filename" title={asset.original_filename}>
          {asset.original_filename}
        </p>
        <p className="gm-tile-size">{fmtSize(asset.size_bytes)}</p>
        {asset.alt_text && (
          <p className="gm-tile-alt" title={asset.alt_text}>
            Alt: {asset.alt_text}
          </p>
        )}
      </div>

      {/* Reorder arrows */}
      <div className="gm-tile-actions">
        <button
          className="gm-icon-btn"
          onClick={onMoveUp}
          disabled={index === 0 || reordering}
          title="Move left / up"
          aria-label="Move image earlier"
        >
          <IconChevronRight className="rotate-180" size={15} />
        </button>
        <button
          className="gm-icon-btn"
          onClick={onMoveDown}
          disabled={index === total - 1 || reordering}
          title="Move right / down"
          aria-label="Move image later"
        >
          <IconChevronRight size={15} />
        </button>
        <button
          className="gm-icon-btn gm-icon-btn--danger"
          onClick={onDelete}
          title="Delete image"
          aria-label="Delete image"
        >
          <IconTrash size={15} />
        </button>
      </div>
    </div>
  );
}

/* ── Main component ───────────────────────────────────────────────────────── */

export function GalleryManager({
  target,
  items,
  onRefetch,
  loading = false,
  maxImages = 20,
  className,
}: GalleryManagerProps) {
  const { toast } = useToast();

  const [uploading, setUploading] = useState(false);
  const [reordering, setReordering] = useState(false);
  const [toDelete, setToDelete] = useState<MediaAsset | null>(null);
  const [deleting, setDeleting] = useState(false);

  /* Alt-text prompt state for the next upload batch */
  const [pendingFiles, setPendingFiles] = useState<File[]>([]);
  const [metaAlt, setMetaAlt] = useState('');
  const [metaCaption, setMetaCaption] = useState('');
  const [metaOpen, setMetaOpen] = useState(false);

  function handleFiles(files: File[]) {
    const oversized = files.filter((f) => f.size > MAX_SIZE_BYTES);
    if (oversized.length > 0) {
      toast(`${oversized.map((f) => f.name).join(', ')} exceed the 10 MB limit and were skipped.`, 'error');
    }
    const valid = files.filter((f) => f.size <= MAX_SIZE_BYTES);
    if (valid.length === 0) return;
    setPendingFiles(valid);
    setMetaAlt('');
    setMetaCaption('');
    setMetaOpen(true);
  }

  async function handleUpload() {
    if (pendingFiles.length === 0) return;
    setMetaOpen(false);
    setUploading(true);
    const meta = {
      alt_text: metaAlt.trim() || undefined,
      caption: metaCaption.trim() || undefined,
    };
    let ok = 0;
    let fail = 0;
    for (const file of pendingFiles) {
      try {
        await uploadForTarget(target, file, meta);
        ok++;
      } catch {
        fail++;
        toast(`Failed to upload ${file.name}`, 'error');
      }
    }
    setUploading(false);
    if (ok > 0) {
      toast(`${ok} photo${ok > 1 ? 's' : ''} uploaded`, 'success');
      onRefetch();
    }
    if (fail > 0) toast(`${fail} upload${fail > 1 ? 's' : ''} failed`, 'error');
    setPendingFiles([]);
  }

  async function moveItem(index: number, direction: -1 | 1) {
    const newItems = [...items];
    const target2 = index + direction;
    if (target2 < 0 || target2 >= newItems.length) return;
    [newItems[index], newItems[target2]] = [newItems[target2], newItems[index]];
    setReordering(true);
    try {
      await landlordApi.reorderMedia(newItems.map((a) => a.id));
      onRefetch();
    } catch {
      toast('Could not reorder photos. Please try again.', 'error');
    } finally {
      setReordering(false);
    }
  }

  async function handleDelete() {
    if (!toDelete) return;
    setDeleting(true);
    try {
      await landlordApi.deleteMedia(toDelete.id);
      toast('Photo deleted', 'success');
      setToDelete(null);
      onRefetch();
    } catch {
      toast('Could not delete photo. Please try again.', 'error');
    } finally {
      setDeleting(false);
    }
  }

  const busy = uploading || reordering;
  const atMax = items.length >= maxImages;

  return (
    <div className={`gm-root${className ? ` ${className}` : ''}`}>
      <style>{GM_CSS}</style>

      {/* Upload zone */}
      {!atMax && (
        <DropZone onFiles={handleFiles} disabled={busy || metaOpen} />
      )}
      {atMax && (
        <p className="gm-max-notice">Maximum of {maxImages} photos reached.</p>
      )}

      {/* Inline meta panel — appears below dropzone when files are queued */}
      {metaOpen && pendingFiles.length > 0 && (
        <div className="rounded-xl border border-brand-200 bg-brand-50/40 p-4 space-y-4">
          <div className="flex items-center justify-between gap-3">
            <p className="text-sm font-medium text-ink-800">
              {pendingFiles.length} photo{pendingFiles.length > 1 ? 's' : ''} ready to upload
            </p>
            <button
              type="button"
              aria-label="Cancel upload"
              onClick={() => { setMetaOpen(false); setPendingFiles([]); }}
              className="flex h-7 w-7 items-center justify-center rounded-lg text-ink-400 transition-colors hover:bg-ink-100 hover:text-ink-700"
            >
              <IconX size={15} />
            </button>
          </div>
          <p className="text-xs text-ink-500 truncate">
            {pendingFiles.map((f) => f.name).join(', ')}
          </p>
          <Field label="Alt text" hint="Describe the image for screen readers.">
            {(fid) => (
              <Input
                id={fid}
                placeholder="e.g. Living room with natural light"
                value={metaAlt}
                onChange={(e) => setMetaAlt(e.target.value)}
                maxLength={255}
              />
            )}
          </Field>
          <Field label="Caption" hint="Optional short caption displayed under the image.">
            {(fid) => (
              <Input
                id={fid}
                placeholder="e.g. Spacious open-plan kitchen"
                value={metaCaption}
                onChange={(e) => setMetaCaption(e.target.value)}
                maxLength={255}
              />
            )}
          </Field>
          <div className="flex justify-end gap-2">
            <Button
              variant="secondary"
              size="sm"
              onClick={() => { setMetaOpen(false); setPendingFiles([]); }}
            >
              Cancel
            </Button>
            <Button size="sm" onClick={handleUpload} leftIcon={<IconUpload size={15} />}>
              Upload {pendingFiles.length > 1 ? `${pendingFiles.length} photos` : 'photo'}
            </Button>
          </div>
        </div>
      )}

      {/* Upload in progress indicator */}
      {uploading && (
        <div className="gm-uploading-bar">
          <LoadingState label={`Uploading ${pendingFiles.length} photo${pendingFiles.length > 1 ? 's' : ''}…`} />
        </div>
      )}

      {/* Loading state (parent data) */}
      {loading && !uploading && <LoadingState label="Loading photos…" />}

      {/* Gallery grid */}
      {!loading && items.length === 0 && !uploading && !metaOpen && (
        <div className="gm-empty">
          <IconImage size={32} className="gm-empty-icon" />
          <p className="gm-empty-title">No photos yet</p>
          <p className="gm-empty-sub">Upload photos to make this listing more attractive to tenants.</p>
        </div>
      )}

      {items.length > 0 && (
        <div className="gm-grid">
          {items.map((asset, idx) => (
            <ImageTile
              key={asset.id}
              asset={asset}
              index={idx}
              total={items.length}
              onMoveUp={() => moveItem(idx, -1)}
              onMoveDown={() => moveItem(idx, 1)}
              onDelete={() => setToDelete(asset)}
              reordering={reordering}
            />
          ))}
        </div>
      )}

      {/* Delete confirmation */}
      <DestructiveConfirmDialog
        open={toDelete !== null}
        onClose={() => setToDelete(null)}
        onConfirm={handleDelete}
        title="Delete photo"
        description={toDelete ? `Delete "${toDelete.original_filename}"? This cannot be undone.` : undefined}
        confirmLabel="Delete"
        loading={deleting}
      />
    </div>
  );
}

/* ── Styles ───────────────────────────────────────────────────────────────── */

const GM_CSS = `
.gm-root { display: flex; flex-direction: column; gap: 16px; }

/* Drop zone */
.gm-dropzone {
  border: 2px dashed var(--color-ink-300, #D1D5DB);
  border-radius: 14px;
  background: var(--color-surface, #FFFFFF);
  padding: 28px 24px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  transition: border-color 0.15s, background 0.15s;
  user-select: none;
  outline: none;
}
.gm-dropzone:focus-visible { outline: 2px solid var(--color-brand-500, #0EA5E9); outline-offset: 2px; }
.gm-dropzone:hover:not(.gm-dropzone--disabled),
.gm-dropzone--active {
  border-color: var(--color-brand-500, #0EA5E9);
  background: var(--color-brand-50, #F0F9FF);
}
.gm-dropzone--disabled { opacity: 0.5; cursor: not-allowed; }
.gm-dropzone-icon { color: var(--color-brand-600, #0284C7); }
.gm-dropzone-label { font-size: 0.9375rem; font-weight: 600; color: var(--color-ink-800, #1F2937); }
.gm-dropzone-hint { font-size: 0.8125rem; color: var(--color-ink-400, #9CA3AF); }

.gm-max-notice {
  font-size: 0.875rem;
  color: var(--color-ink-500, #6B7280);
  text-align: center;
  padding: 12px;
}

.gm-uploading-bar { padding: 8px 0; }

/* Empty state */
.gm-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 40px 24px;
  text-align: center;
}
.gm-empty-icon { color: var(--color-ink-300, #D1D5DB); }
.gm-empty-title {
  font-family: 'Fraunces', Georgia, serif;
  font-size: 1.05rem;
  font-weight: 600;
  color: var(--color-ink-700, #374151);
}
.gm-empty-sub { font-size: 0.875rem; color: var(--color-ink-400, #9CA3AF); max-width: 340px; line-height: 1.5; }

/* Grid */
.gm-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 12px;
}

/* Tile */
.gm-tile {
  border: 1.5px solid var(--color-ink-200, #E5E7EB);
  border-radius: 12px;
  background: var(--color-surface, #FFFFFF);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  transition: box-shadow 0.15s;
}
.gm-tile:hover { box-shadow: 0 2px 10px rgba(0,0,0,0.06); }

.gm-tile-img-wrap {
  position: relative;
  width: 100%;
  aspect-ratio: 4/3;
  background: var(--color-ink-100, #F3F4F6);
  overflow: hidden;
}
.gm-tile-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.gm-tile-img-placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--color-ink-300, #D1D5DB);
}
.gm-tile-primary-badge {
  position: absolute;
  top: 6px;
  left: 6px;
  background: var(--color-ink-900, #111827);
  color: #fff;
  font-size: 0.65rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  padding: 2px 7px;
  border-radius: 6px;
}

.gm-tile-meta {
  padding: 8px 10px 4px;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}
.gm-tile-filename {
  font-size: 0.75rem;
  font-weight: 500;
  color: var(--color-ink-800, #1F2937);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.gm-tile-size { font-size: 0.7rem; color: var(--color-ink-400, #9CA3AF); }
.gm-tile-alt {
  font-size: 0.7rem;
  color: var(--color-ink-500, #6B7280);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.gm-tile-actions {
  display: flex;
  align-items: center;
  gap: 2px;
  padding: 6px 8px;
  border-top: 1px solid var(--color-ink-100, #F3F4F6);
}

.gm-icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 7px;
  border: 1.5px solid var(--color-ink-200, #E5E7EB);
  background: transparent;
  color: var(--color-ink-500, #6B7280);
  cursor: pointer;
  transition: background 0.12s, color 0.12s;
  padding: 0;
}
.gm-icon-btn:hover:not(:disabled) {
  background: var(--color-ink-100, #F3F4F6);
  color: var(--color-ink-800, #1F2937);
}
.gm-icon-btn:disabled { opacity: 0.35; cursor: not-allowed; }
.gm-icon-btn--danger:hover:not(:disabled) {
  background: var(--color-danger-50, #FFF5F5);
  color: var(--color-danger-600, #DC2626);
  border-color: var(--color-danger-200, #FCA5A5);
}
.gm-icon-btn:focus-visible { outline: 2px solid var(--color-brand-500, #0EA5E9); outline-offset: 1px; }

@media (prefers-reduced-motion: reduce) {
  .gm-tile, .gm-icon-btn, .gm-dropzone { transition: none; }
}
`;
