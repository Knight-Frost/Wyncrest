import { useState } from 'react';
import * as TooltipPrimitive from '@radix-ui/react-tooltip';
import { IconInfo } from '@/components/ui/icons';

/**
 * InfoHint — the single, canonical "help" affordance across every portal.
 *
 * A small, low-noise info icon that reveals one short, plain-language
 * explanation of a label, metric, status, or control. Built on Radix Tooltip so
 * we inherit portalling (never clipped inside a card/table/modal), keyboard
 * focus, Escape-to-dismiss, and collision-aware placement for free.
 *
 * Accessibility / reach:
 *  - The trigger is a real <button> with an `aria-label`, so it is reachable and
 *    announced by keyboard and screen readers (hover is never the only path).
 *  - `open` is controlled so a tap TOGGLES the bubble on touch devices, where
 *    Radix's hover/focus model alone would never surface it.
 *  - Colors come entirely from `ink`/`surface` tokens, so it inverts correctly
 *    in light and dark and never fights the active accent.
 *
 * Keep `text` to one or two plain sentences. If a concept needs a real essay,
 * link to the page that already documents it instead of growing the bubble.
 */
export function InfoHint({
  text,
  label,
  side = 'top',
  className,
  iconSize = 14,
}: {
  /** The short explanation shown in the bubble. */
  text: React.ReactNode;
  /** Accessible name for the trigger, e.g. "About outstanding balance". */
  label?: string;
  side?: 'top' | 'right' | 'bottom' | 'left';
  /** Extra classes for the trigger button (spacing/alignment at the call site). */
  className?: string;
  iconSize?: number;
}) {
  const [open, setOpen] = useState(false);

  return (
    <TooltipPrimitive.Provider delayDuration={150}>
      <TooltipPrimitive.Root open={open} onOpenChange={setOpen}>
        <TooltipPrimitive.Trigger asChild>
          <button
            type="button"
            aria-label={label ?? 'More information'}
            onClick={(e) => {
              // Toggle for touch/click; hover & focus are handled by Radix.
              e.preventDefault();
              e.stopPropagation();
              setOpen((o) => !o);
            }}
            className={
              'inline-flex shrink-0 items-center justify-center rounded-full ' +
              'text-ink-400 transition-colors hover:text-ink-600 ' +
              'focus:outline-none focus-visible:ring-2 focus-visible:ring-ink-400/60 ' +
              'focus-visible:ring-offset-1 focus-visible:ring-offset-surface align-middle ' +
              (className ?? '')
            }
          >
            <IconInfo size={iconSize} />
          </button>
        </TooltipPrimitive.Trigger>
        <TooltipPrimitive.Portal>
          <TooltipPrimitive.Content
            side={side}
            align="center"
            sideOffset={6}
            collisionPadding={12}
            className="z-[60] max-w-[260px] rounded-lg border border-ink-200 bg-surface px-3 py-2 text-xs font-normal leading-snug text-ink-700 shadow-lg animate-fade-in"
          >
            {text}
            <TooltipPrimitive.Arrow className="fill-surface" />
          </TooltipPrimitive.Content>
        </TooltipPrimitive.Portal>
      </TooltipPrimitive.Root>
    </TooltipPrimitive.Provider>
  );
}
