import * as TooltipPrimitive from '@radix-ui/react-tooltip';

/**
 * Small hover/focus tooltip for explaining a technical term inline, without
 * sending the reader to a separate doc. Built on Radix Tooltip so keyboard
 * focus, delay, and portalling come for free.
 */
export function Tooltip({
  content,
  children,
}: {
  content: React.ReactNode;
  /** The trigger element. Provide its own styling (e.g. dotted underline for a
   *  glossary term, or none for an icon) — this component adds none itself. */
  children: React.ReactElement;
}) {
  return (
    <TooltipPrimitive.Provider delayDuration={200}>
      <TooltipPrimitive.Root>
        <TooltipPrimitive.Trigger asChild>
          {children}
        </TooltipPrimitive.Trigger>
        <TooltipPrimitive.Portal>
          <TooltipPrimitive.Content
            side="top"
            align="center"
            sideOffset={6}
            className="z-50 max-w-[240px] rounded-lg border border-ink-200 bg-surface px-3 py-2 text-xs leading-snug text-ink-700 shadow-lg animate-fade-in"
          >
            {content}
            <TooltipPrimitive.Arrow className="fill-surface" />
          </TooltipPrimitive.Content>
        </TooltipPrimitive.Portal>
      </TooltipPrimitive.Root>
    </TooltipPrimitive.Provider>
  );
}
