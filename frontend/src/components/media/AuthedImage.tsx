/**
 * AuthedImage — renders a RESTRICTED media asset (e.g. maintenance evidence)
 * that lives behind the Bearer-authed `GET /api/media/{id}` route. A plain
 * <img src="/api/media/…"> cannot attach the Authorization header, so we fetch
 * the bytes as a Blob through the portal axios client and hand the <img> an
 * object URL instead. The URL is revoked on unmount / asset change.
 */
import { useEffect, useRef, useState } from 'react';

interface AuthedImageProps {
  /** Fetches the asset bytes with the correct portal auth (tenantApi/landlordApi.mediaBlob). */
  fetcher: () => Promise<Blob>;
  alt: string;
  className?: string;
}

export function AuthedImage({ fetcher, alt, className }: AuthedImageProps) {
  const [url, setUrl] = useState<string | null>(null);
  const [failed, setFailed] = useState(false);
  // Keep the fetcher in a ref so a new inline closure each render doesn't re-run
  // the fetch effect. Assigned in an effect (never during render).
  const fetcherRef = useRef(fetcher);
  useEffect(() => {
    fetcherRef.current = fetcher;
  });

  useEffect(() => {
    let objectUrl: string | null = null;
    let cancelled = false;

    fetcherRef
      .current()
      .then((blob) => {
        if (cancelled) return;
        objectUrl = URL.createObjectURL(blob);
        setUrl(objectUrl);
      })
      .catch(() => {
        if (!cancelled) setFailed(true);
      });

    return () => {
      cancelled = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [alt]);

  if (failed) {
    return <div className={className} data-media-failed="true" aria-label={`${alt} (unavailable)`} />;
  }
  if (!url) {
    return <div className={className} data-media-loading="true" aria-label={`Loading ${alt}`} />;
  }
  return <img src={url} alt={alt} className={className} loading="lazy" />;
}
