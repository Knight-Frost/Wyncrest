import { cn } from '@/lib/cn';
import { Skeleton } from './states';
import { IconTrendingUp, IconTrendingDown } from './icons';
import { InfoHint } from './InfoHint';

type StatTone = 'default' | 'success' | 'warning' | 'danger' | 'info' | 'money';

interface TrendInfo {
  value: string;
  direction: 'up' | 'down' | 'neutral';
}

interface StatCardProps {
  label: string;
  value: string | number | React.ReactNode;
  subtext?: string;
  /** @deprecated use subtext */
  hint?: string;
  /** Short plain-language explanation shown in a help tooltip beside the label. */
  help?: string;
  icon?: React.ReactNode;
  tone?: StatTone;
  trend?: TrendInfo;
  loading?: boolean;
  className?: string;
}

const iconTones: Record<StatTone, { ring: string; icon: string }> = {
  default: { ring: 'bg-brand-50',   icon: 'text-brand-700' },
  success: { ring: 'bg-success-50', icon: 'text-success-600' },
  warning: { ring: 'bg-warning-50', icon: 'text-warning-600' },
  danger:  { ring: 'bg-danger-50',  icon: 'text-danger-600' },
  info:    { ring: 'bg-info-50',    icon: 'text-info-600' },
  money:   { ring: 'bg-[var(--color-money-bg)]', icon: 'text-[var(--color-money)]' },
};

export function StatCard({
  label,
  value,
  subtext,
  hint,
  help,
  icon,
  tone = 'default',
  trend,
  loading,
  className,
}: StatCardProps) {
  const subtextFinal = subtext ?? hint;
  const { ring, icon: iconColor } = iconTones[tone];
  const isMoney = tone === 'money';

  return (
    <div
      className={cn(
        'bg-surface rounded-2xl border border-ink-200 shadow-sm p-5',
        className,
      )}
    >
      {/* Top row */}
      <div className="flex items-start justify-between gap-3">
        <p className="flex items-center gap-1 text-sm font-medium text-ink-500">
          {label}
          {help && <InfoHint text={help} label={`About ${label}`} />}
        </p>
        {icon && (
          <span
            className={cn(
              'flex items-center justify-center rounded-full shrink-0',
              ring,
              iconColor,
            )}
            style={{ width: 34, height: 34 }}
          >
            {icon}
          </span>
        )}
      </div>

      {/* Value */}
      <div className="mt-3">
        {loading ? (
          <Skeleton className="h-9 w-28" />
        ) : (
          <p
            className={cn(
              'font-display text-3xl font-semibold tracking-tight',
              isMoney ? 'text-[var(--color-money)]' : 'text-ink-950',
            )}
          >
            {value}
          </p>
        )}
      </div>

      {/* Subtext + trend */}
      {(subtextFinal || trend) && (
        <div className="mt-2 flex items-center gap-2">
          {loading ? (
            <Skeleton className="h-3.5 w-20" />
          ) : (
            <>
              {subtextFinal && (
                <span className="text-xs text-ink-500">{subtextFinal}</span>
              )}
              {trend && (
                <span
                  className={cn(
                    'inline-flex items-center gap-0.5 text-xs font-medium',
                    trend.direction === 'up'
                      ? 'text-success-500'
                      : trend.direction === 'down'
                      ? 'text-danger-500'
                      : 'text-ink-500',
                  )}
                >
                  {trend.direction === 'up' && (
                    <IconTrendingUp size={12} />
                  )}
                  {trend.direction === 'down' && (
                    <IconTrendingDown size={12} />
                  )}
                  {trend.value}
                </span>
              )}
            </>
          )}
        </div>
      )}
    </div>
  );
}
