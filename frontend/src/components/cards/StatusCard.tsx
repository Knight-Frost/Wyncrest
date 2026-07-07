import { cn } from '@/lib/cn';
import { Skeleton } from '@/components/ui/states';
import { IconTrendingUp, IconTrendingDown } from '@/components/ui/icons';
import { NexusCard } from './NexusCard';
import { IconTile } from './IconTile';
import { valueToneClass, type SemanticRole } from './variants';

interface TrendInfo {
  value: string;
  direction: 'up' | 'down' | 'neutral';
  /** When true, an "up" movement is bad (e.g. failed sign-ins) → colored danger. */
  upIsBad?: boolean;
}

interface StatusCardProps {
  /** Usually plain text; may include inline elements (e.g. an InfoHint) after the text. */
  label: React.ReactNode;
  value: React.ReactNode;
  sub?: React.ReactNode;
  icon?: React.ReactNode;
  /** neutral = quiet Level-1 card; any other role = tinted Level-2 status card. */
  role?: SemanticRole;
  /** Tint the value text with the role color (default true for non-neutral). */
  tintValue?: boolean;
  trend?: TrendInfo;
  /** Optional footer slot (sparkline, donut, mini-chart). */
  footer?: React.ReactNode;
  loading?: boolean;
  onClick?: () => void;
  className?: string;
}

/**
 * The everyday Homecrest metric card. Neutral by default; pass a semantic `role`
 * (driven by a variants.ts mapping) to get a tinted status surface with a
 * matching icon tile and role-colored value.
 */
export function StatusCard({
  label,
  value,
  sub,
  icon,
  role = 'neutral',
  tintValue,
  trend,
  footer,
  loading,
  onClick,
  className,
}: StatusCardProps) {
  const tinted = (tintValue ?? role !== 'neutral') === true;
  const interactive = typeof onClick === 'function';

  return (
    <NexusCard
      role={role}
      interactive={interactive}
      specular
      as={interactive ? 'button' : 'div'}
      className={cn('flex h-full w-full flex-col p-5 text-left', className)}
      onClick={onClick}
      {...(interactive
        ? {
            type: 'button' as const,
            'aria-label': typeof label === 'string' ? label : undefined,
          }
        : null)}
    >
      <div className="flex items-start justify-between gap-3">
        <p className="flex items-center gap-1 font-mono text-[0.7rem] font-medium uppercase tracking-[0.13em] text-ink-500">
          {label}
        </p>
        {icon && <IconTile icon={icon} role={role} size="md" />}
      </div>

      <div className="mt-3">
        {loading ? (
          <Skeleton className="h-9 w-28" />
        ) : (
          <p
            className={cn(
              'font-display text-3xl font-semibold tracking-tight num-old',
              tinted ? valueToneClass[role] : 'text-ink-950',
            )}
          >
            {value}
          </p>
        )}
      </div>

      {(sub || trend) && (
        <div className="mt-2 flex items-center gap-2">
          {loading ? (
            <Skeleton className="h-3.5 w-24" />
          ) : (
            <>
              {sub && <span className="text-xs text-ink-500">{sub}</span>}
              {trend && trend.direction !== 'neutral' && (
                <TrendChip trend={trend} />
              )}
            </>
          )}
        </div>
      )}

      {footer && !loading && <div className="mt-auto pt-4">{footer}</div>}
    </NexusCard>
  );
}

function TrendChip({ trend }: { trend: TrendInfo }) {
  const up = trend.direction === 'up';
  const isBad = up ? trend.upIsBad === true : trend.upIsBad === false;
  return (
    <span
      className={cn(
        'inline-flex items-center gap-0.5 text-xs font-medium',
        isBad ? 'text-danger-500' : 'text-success-500',
      )}
    >
      {up ? <IconTrendingUp size={12} /> : <IconTrendingDown size={12} />}
      {trend.value}
    </span>
  );
}
