/**
 * Step 5 — Photos.
 *
 * Reuses the real GalleryManager (drag-drop upload, preview thumbnails, remove,
 * reorder) wired to the listing media endpoints. Photos attach to the actual
 * draft listing created in Step 1 — no placeholder-only behaviour. The draft id
 * is always present here because the flow won't advance past Step 1 without it.
 */
import { GalleryManager } from '@/components/media/GalleryManager';
import type { MediaAsset } from '@/lib/types';

interface PhotosStepProps {
  listingId: number | null;
  media: MediaAsset[];
  loading: boolean;
  onRefetch: () => void;
}

export function PhotosStep({ listingId, media, loading, onRefetch }: PhotosStepProps) {
  if (!listingId) {
    return (
      <div className="cl-banner warn">
        Complete Step 1 first. A draft listing must exist before you can upload photos.
      </div>
    );
  }

  return (
    <>
      <p className="cl-help" style={{ marginTop: -4 }}>
        Upload clear photos of the unit. The first image becomes the listing's cover. JPG, PNG or WebP, up to 10&nbsp;MB each.
      </p>
      <GalleryManager
        target={{ type: 'listing', id: listingId }}
        items={media}
        loading={loading}
        onRefetch={onRefetch}
        maxImages={15}
      />
    </>
  );
}
