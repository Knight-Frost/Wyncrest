import { cn } from '@/lib/cn';
import { useTheme, type ThemeChoice } from '@/context/theme';
import { IconSun, IconMoon, IconMonitor } from './icons';

const OPTIONS: { value: ThemeChoice; label: string; Icon: typeof IconSun }[] = [
  { value: 'light',  label: 'Light',  Icon: IconSun },
  { value: 'system', label: 'System', Icon: IconMonitor },
  { value: 'dark',   label: 'Dark',   Icon: IconMoon },
];

/**
 * Theme switcher with two presentations:
 *  - "segmented" (default): a Light / System / Dark pill for the sidebar.
 *  - "minimal": single icon button cycling light → dark → system.
 */
export function ThemeToggle({
  variant = 'segmented',
  className,
}: {
  variant?: 'segmented' | 'minimal';
  className?: string;
}) {
  const { choice, resolved, setChoice } = useTheme();

  if (variant === 'minimal') {
    const order: ThemeChoice[] = ['light', 'dark', 'system'];
    const next = order[(order.indexOf(choice) + 1) % order.length];
    const Active =
      choice === 'system' ? IconMonitor : resolved === 'dark' ? IconMoon : IconSun;
    return (
      <button
        type="button"
        onClick={() => setChoice(next)}
        aria-label={`Theme: ${choice}. Switch to ${next}.`}
        title={`Theme: ${choice}. Click to switch to ${next}.`}
        className={cn(
          'inline-flex h-9 w-9 items-center justify-center rounded-lg opacity-80 transition hover:opacity-100 focus-visible:outline-2',
          className,
        )}
      >
        <Active size={18} />
      </button>
    );
  }

  return (
    <div
      role="radiogroup"
      aria-label="Color theme"
      className={cn(
        'flex items-center gap-0.5 rounded-xl bg-ink-100 border border-ink-200 p-1',
        className,
      )}
    >
      {OPTIONS.map(({ value, label, Icon }) => {
        const active = choice === value;
        return (
          <button
            key={value}
            type="button"
            role="radio"
            aria-checked={active}
            title={label}
            onClick={() => setChoice(value)}
            className={cn(
              'flex flex-1 items-center justify-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition',
              active
                ? 'bg-surface text-ink-900 shadow-sm'
                : 'text-ink-500 hover:text-ink-700',
            )}
          >
            <Icon size={14} />
            <span>{label}</span>
          </button>
        );
      })}
    </div>
  );
}
