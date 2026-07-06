import { useEffect, useRef } from 'react';
import { cn } from '@/lib/cn';
import { IconX } from './icons';

/** Everything keyboard-reachable inside the dialog, for the Tab trap. */
const FOCUSABLE =
  'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Centered dialog. Reserved for SHORT, focused interactions — chiefly
 * destructive confirmations (delete / reject / suspend / terminate). Longer
 * create / edit / review / detail workflows belong in a `Drawer` /
 * `DetailDrawer` or a dedicated route, never here.
 *
 * The veil is a calm, token-based scrim (`--color-scrim`), NOT a heavy black
 * blur — the page stays legible behind it in light and dark. `tone="danger"`
 * tints the title rail so a destructive confirm reads as deliberate.
 */
export function Modal({
  open,
  onClose,
  title,
  description,
  children,
  footer,
  size = 'md',
  tone = 'default',
}: {
  open: boolean;
  onClose: () => void;
  title: React.ReactNode;
  description?: React.ReactNode;
  children?: React.ReactNode;
  footer?: React.ReactNode;
  size?: 'sm' | 'md' | 'lg';
  tone?: 'default' | 'danger';
}) {
  const dialogRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    // Initial focus goes to the dialog itself (tabIndex=-1), and focus returns
    // to whatever opened the modal once it closes.
    const previouslyFocused = document.activeElement as HTMLElement | null;
    dialogRef.current?.focus();

    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        onClose();
        return;
      }
      // Trap Tab / Shift+Tab inside the dialog.
      if (e.key !== 'Tab') return;
      const dialog = dialogRef.current;
      if (!dialog) return;
      const focusables = Array.from(dialog.querySelectorAll<HTMLElement>(FOCUSABLE)).filter(
        (el) => el.offsetParent !== null || el === document.activeElement,
      );
      if (focusables.length === 0) {
        e.preventDefault();
        dialog.focus();
        return;
      }
      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      const active = document.activeElement;
      if (e.shiftKey) {
        if (active === first || active === dialog || !dialog.contains(active)) {
          e.preventDefault();
          last.focus();
        }
      } else if (active === last || !dialog.contains(active)) {
        e.preventDefault();
        first.focus();
      }
    };
    document.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = '';
      previouslyFocused?.focus?.();
    };
  }, [open, onClose]);

  if (!open) return null;

  const widths = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-2xl' };

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center">
      {/* Calm scrim — a light dim, never near-black. Token re-themes per mode. */}
      <div
        className="absolute inset-0 animate-fade-in"
        style={{ background: 'var(--color-scrim)', backdropFilter: 'blur(2px)' }}
        onClick={onClose}
        aria-hidden="true"
      />

      {/* Dialog */}
      <div
        ref={dialogRef}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        aria-label={typeof title === 'string' ? title : undefined}
        className={cn(
          'relative w-full mx-4 bg-surface rounded-2xl shadow-lg animate-scale-in',
          'border border-ink-200',
          widths[size],
        )}
      >
        {/* Header */}
        <div className="flex items-start justify-between gap-4 px-6 pt-6 pb-4">
          <div className="min-w-0 flex-1">
            {tone === 'danger' && (
              <span className="mb-2 inline-flex items-center gap-1.5 rounded-full bg-danger-50 px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide text-danger-600">
                <span className="inline-block h-1.5 w-1.5 rounded-full bg-danger-500" aria-hidden="true" />
                Confirm
              </span>
            )}
            <h2 className="font-display text-xl font-semibold text-ink-950 leading-snug">
              {title}
            </h2>
            {description && (
              <p className="mt-1 text-sm text-ink-500">{description}</p>
            )}
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close"
            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-ink-400 transition hover:bg-ink-100 hover:text-ink-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600/30"
          >
            <IconX size={18} />
          </button>
        </div>

        {/* Body */}
        {children && <div className="px-6 pb-5">{children}</div>}

        {/* Footer */}
        {footer && (
          <div className="flex items-center justify-end gap-3 border-t border-ink-200 px-6 py-4">
            {footer}
          </div>
        )}
      </div>
    </div>
  );
}
