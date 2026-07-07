import { cn } from '@/lib/cn';
import { Skeleton } from '@/components/ui/states';
import { InfoHint } from '@/components/ui/InfoHint';
import { commandFill, type SemanticRole } from './variants';
import { IconTile } from './IconTile';

interface CommandCardProps {
  /** Eyebrow label, e.g. "Outstanding balance". */
  label: string;
  /** The headline figure/status — rendered large in the serif display face. */
  value: React.ReactNode;
  /** A short truthful sub-line, e.g. "All clear" or "Requires your attention". */
  sub?: React.ReactNode;
  icon?: React.ReactNode;
  /** Deep fill role: success (estate), danger (oxblood), warning (clay), info (teal), neutral (ink). */
  role?: SemanticRole;
  /** Optional status dot color overlay on the sub-line. */
  dot?: boolean;
  /** Short plain-language explanation shown in a help tooltip beside the label. */
  help?: string;
  loading?: boolean;
  onClick?: () => void;
  className?: string;
}

/**
 * Level-3 "command / featured" card: a deep filled surface with warm-ivory ink,
 * an oversized serif number, and a translucent icon chip. Reserved for the most
 * important item in a section (outstanding balance, critical alert, needs review).
 */
export function CommandCard({
  label,
  value,
  sub,
  icon,
  role = 'neutral',
  dot = true,
  help,
  loading,
  onClick,
  className,
}: CommandCardProps) {
  const fill = commandFill[role];
  const interactive = typeof onClick === 'function';
  const Tag: React.ElementType = interactive ? 'button' : 'div';

  return (
    <Tag
      type={interactive ? 'button' : undefined}
      onClick={onClick}
      className={cn(
        'relative h-full overflow-hidden rounded-2xl border border-transparent p-6 text-left shadow-md',
        'flex flex-col gap-3',
        interactive &&
          'transition-transform duration-200 hover:-translate-y-1 focus-visible:outline-2',
        className,
      )}
      style={{
        backgroundImage: `linear-gradient(150deg, ${fill.from}, ${fill.to})`,
        color: 'var(--nexus-cmd-fg)',
      }}
    >
      {/* faint editorial texture — single soft radial, never noisy */}
      <span
        aria-hidden="true"
        className="pointer-events-none absolute -right-10 -top-12 h-44 w-44 rounded-full opacity-[0.12]"
        style={{ background: 'radial-gradient(circle, #fff 0%, transparent 70%)' }}
      />

      <div className="relative flex items-start justify-between gap-3">
        <p
          className="flex items-center gap-1 font-mono text-xs uppercase tracking-[0.16em]"
          style={{ color: 'var(--nexus-cmd-sub)' }}
        >
          {label}
          {help && (
            <InfoHint
              text={help}
              label={`About ${label}`}
              className="normal-case tracking-normal !text-current opacity-80 hover:opacity-100"
            />
          )}
        </p>
        {icon && <IconTile icon={icon} role={role} onCommand size="sm" />}
      </div>

      <div className="relative">
        {loading ? (
          <Skeleton className="h-10 w-32" />
        ) : (
          <p className="font-display text-[2.1rem] font-semibold leading-none tracking-tight num-old">
            {value}
          </p>
        )}
      </div>

      {sub && !loading && (
        <p
          className="relative mt-auto flex items-center gap-2 text-sm"
          style={{ color: 'var(--nexus-cmd-sub)' }}
        >
          {dot && (
            <span
              className="inline-block h-1.5 w-1.5 shrink-0 rounded-full"
              style={{ backgroundColor: 'currentColor' }}
              aria-hidden="true"
            />
          )}
          {sub}
        </p>
      )}
    </Tag>
  );
}
