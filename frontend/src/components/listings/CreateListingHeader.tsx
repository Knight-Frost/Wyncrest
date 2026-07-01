/**
 * CreateListingHeader — the top card of the Create Listing workspace.
 * Eyebrow + title + subtitle on the left, a close button on the right that
 * returns the landlord to the Listings page.
 */

interface CreateListingHeaderProps {
  onClose: () => void;
}

function CloseIcon() {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
      <path d="M18 6 6 18M6 6l12 12" />
    </svg>
  );
}

export function CreateListingHeader({ onClose }: CreateListingHeaderProps) {
  return (
    <header className="cl-header">
      <div>
        <p className="cl-eyebrow">Listings</p>
        <h1 className="cl-title">Create listing</h1>
        <p className="cl-subtitle">Add details about the unit and the home you want to publish.</p>
      </div>
      <button type="button" className="cl-close" onClick={onClose} aria-label="Close and return to listings">
        <CloseIcon />
      </button>
    </header>
  );
}
