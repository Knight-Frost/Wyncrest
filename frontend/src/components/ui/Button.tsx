import { forwardRef } from 'react';
import { cn } from '@/lib/cn';
import { Spinner } from './Spinner';

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'subtle';
type Size = 'sm' | 'md' | 'lg';

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant;
  size?: Size;
  loading?: boolean;
  leftIcon?: React.ReactNode;
}

const base =
  'inline-flex items-center justify-center gap-2 font-medium rounded-xl transition ' +
  'duration-150 ease-out select-none disabled:opacity-50 disabled:cursor-not-allowed ' +
  'active:scale-[0.98] whitespace-nowrap';

const variants: Record<Variant, string> = {
  primary:
    'bg-brand-700 text-canvas font-semibold shadow-sm hover:bg-brand-800 focus-visible:outline-brand-700',
  secondary:
    'bg-surface text-ink-800 border border-ink-200 shadow-sm hover:bg-ink-50 hover:border-ink-300',
  ghost: 'text-ink-700 hover:bg-ink-100',
  subtle: 'bg-ink-100 text-ink-800 hover:bg-ink-200',
  danger: 'bg-danger-600 text-white shadow-sm hover:bg-danger-500',
};

const sizes: Record<Size, string> = {
  sm: 'h-9 px-3 text-sm',
  md: 'h-11 px-4 text-sm',
  lg: 'h-12 px-6 text-base',
};

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant = 'primary', size = 'md', loading, leftIcon, className, children, disabled, ...props },
  ref,
) {
  return (
    <button
      ref={ref}
      className={cn(base, variants[variant], sizes[size], className)}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      {...props}
    >
      {loading ? <Spinner size={16} /> : leftIcon}
      {children}
    </button>
  );
});
