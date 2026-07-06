import { useEffect, useState } from 'react';
import { adminApi } from '@/lib/endpoints';
import { normalizeError } from '@/lib/api';
import { useToast } from '@/components/ui/toast';
import { Spinner } from '@/components/ui/Spinner';
import { documentTypeLabel, formatBytes, isPreviewableMime } from './verificationVisuals';
import {
  WVIconFile,
  WVIconZoomIn,
  WVIconZoomOut,
  WVIconRotate,
  WVIconExternal,
  WVIconDownload,
  WVIconLock,
} from '../wverIcons';
import type { ApiError, VerificationDocument } from '@/lib/types';

const ZOOM_STEP = 0.25;
const ZOOM_MIN = 0.5;
const ZOOM_MAX = 3;

/**
 * Document inspection area for the verification case-review page. Streams the
 * selected document as a blob through the same admin-gated, audited download
 * route used for downloads (`GET /admin/documents/{id}/download`) — no
 * separate public/preview URL is ever created, so private documents stay
 * private regardless of which mode the admin is using.
 */
export function DocumentViewer({ documents }: { documents: VerificationDocument[] }) {
  const { toast } = useToast();
  const [selectedId, setSelectedId] = useState<number | null>(documents[0]?.id ?? null);
  const [blob, setBlob] = useState<{ url: string; mimeType: string } | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [zoom, setZoom] = useState(1);
  const [rotation, setRotation] = useState(0);
  const [downloading, setDownloading] = useState(false);

  const selected = documents.find((d) => d.id === selectedId) ?? null;

  useEffect(() => {
    setZoom(1);
    setRotation(0);
    setBlob(null);
    setError(null);

    if (!selectedId) return;

    let active = true;
    setLoading(true);
    adminApi
      .previewDocumentBlob(selectedId)
      .then((result) => {
        if (active) setBlob(result);
      })
      .catch((err) => {
        if (active) setError(normalizeError(err).message || 'Could not load this document.');
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
    };
  }, [selectedId]);

  // Revoke the object URL whenever it's replaced or the viewer unmounts.
  useEffect(() => {
    return () => {
      if (blob) URL.revokeObjectURL(blob.url);
    };
  }, [blob]);

  async function download(doc: VerificationDocument) {
    setDownloading(true);
    try {
      await adminApi.downloadDocument(doc.id, doc.original_filename);
    } catch (err) {
      const e = normalizeError(err) as ApiError;
      toast(e.message || 'Could not download this document.', 'error');
    } finally {
      setDownloading(false);
    }
  }

  if (documents.length === 0) {
    return <div className="nodoc">No documents were submitted with this request.</div>;
  }

  const isImage = blob?.mimeType.startsWith('image/');
  const isPdf = blob?.mimeType === 'application/pdf';
  const previewable = blob ? isPreviewableMime(blob.mimeType) : true;

  return (
    <div className="viewer">
      <div className="doclist">
        {documents.map((doc) => (
          <button
            key={doc.id}
            type="button"
            className={`docitem${doc.id === selectedId ? ' sel' : ''}`}
            onClick={() => setSelectedId(doc.id)}
          >
            <div className="dt">
              <WVIconFile />
              {documentTypeLabel(doc.document_type)}
            </div>
            <div className="df">{doc.original_filename}</div>
          </button>
        ))}
      </div>

      <div className="docstage">
        {selected && (
          <div className="dv-top">
            <span className="dv-name">{selected.original_filename}</span>
            {isImage && (
              <>
                <button
                  type="button"
                  className="dv-btn"
                  title="Zoom out"
                  disabled={zoom <= ZOOM_MIN}
                  onClick={() => setZoom((z) => Math.max(ZOOM_MIN, +(z - ZOOM_STEP).toFixed(2)))}
                >
                  <WVIconZoomOut />
                </button>
                <button
                  type="button"
                  className="dv-btn"
                  title="Zoom in"
                  disabled={zoom >= ZOOM_MAX}
                  onClick={() => setZoom((z) => Math.min(ZOOM_MAX, +(z + ZOOM_STEP).toFixed(2)))}
                >
                  <WVIconZoomIn />
                </button>
                <button type="button" className="dv-btn" title="Rotate" onClick={() => setRotation((r) => (r + 90) % 360)}>
                  <WVIconRotate />
                </button>
              </>
            )}
            {blob && (
              <button
                type="button"
                className="dv-btn"
                title="Open in new tab"
                onClick={() => window.open(blob.url, '_blank', 'noopener,noreferrer')}
              >
                <WVIconExternal />
              </button>
            )}
            <button type="button" className="dv-btn" title="Download" disabled={downloading} onClick={() => download(selected)}>
              {downloading ? <Spinner size={14} /> : <WVIconDownload />}
            </button>
          </div>
        )}

        <div className="dv-canvas-wrap">
          {loading ? (
            <div className="dv-loading">
              <Spinner size={22} />
              <span>Loading document…</span>
            </div>
          ) : error ? (
            <div className="dv-loading">
              <p style={{ color: 'var(--oxblood)', fontSize: '.85rem' }}>{error}</p>
            </div>
          ) : !blob ? null : !previewable ? (
            <div className="dv-loading">
              <WVIconFile />
              <p>This file type ({blob.mimeType}) can&apos;t preview in-browser — use Download instead.</p>
            </div>
          ) : isImage ? (
            <img
              src={blob.url}
              alt={selected ? `${documentTypeLabel(selected.document_type)} — ${selected.original_filename}` : 'Document preview'}
              style={{ transform: `scale(${zoom}) rotate(${rotation}deg)` }}
            />
          ) : isPdf ? (
            <embed src={blob.url} type="application/pdf" />
          ) : null}
        </div>

        <div className="dv-lock">
          <WVIconLock />
          Secure preview · admin download logged
        </div>
      </div>

      {selected && (
        <div className="two" style={{ gridColumn: '1 / -1', marginTop: '.2rem' }}>
          <div className="subcard">
            <div className="kv">
              <span className="kk">Document type</span>
              <span className="vv">{documentTypeLabel(selected.document_type)}</span>
            </div>
            <div className="kv">
              <span className="kk">File</span>
              <span className="vv mono">{selected.original_filename}</span>
            </div>
            <div className="kv">
              <span className="kk">Uploaded</span>
              <span className="vv">{new Date(selected.created_at).toLocaleDateString()}</span>
            </div>
          </div>
          <div className="subcard">
            <div className="kv">
              <span className="kk">Size</span>
              <span className="vv">{formatBytes(selected.size_bytes)}</span>
            </div>
            <div className="kv">
              <span className="kk">Verified</span>
              <span className="vv">
                <span className={`vbadge ${selected.is_verified ? 'ok' : 'pending'}`}>
                  {selected.is_verified ? 'Yes' : 'Not marked'}
                </span>
              </span>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
