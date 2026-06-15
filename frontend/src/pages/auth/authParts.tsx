/* eslint-disable react-refresh/only-export-components -- shared auth parts module
   intentionally exports components alongside icons and style constants. */
import { forwardRef, useState, type ReactNode, type SVGProps } from 'react';
import { Link, useLocation } from 'react-router';
import { cn } from '@/lib/cn';
import heroImg from '@/assets/auth/property-hero.jpg';

/* ============================ Icons ===================================== */
function I({ children, ...p }: SVGProps<SVGSVGElement> & { children: ReactNode }) {
  return (
    <svg
      width="20"
      height="20"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.7"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      {...p}
    >
      {children}
    </svg>
  );
}

export const Icons = {
  shieldCheck: (p: SVGProps<SVGSVGElement>) => (
    <I {...p}>
      <path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z" />
      <path d="m9 12 2 2 4-4" />
    </I>
  ),
  badgeCheck: (p: SVGProps<SVGSVGElement>) => (
    <I {...p}>
      <circle cx="12" cy="12" r="9" />
      <path d="m8.5 12 2.2 2.2L15.5 9.5" />
    </I>
  ),
  person: (p: SVGProps<SVGSVGElement>) => (
    <I {...p}>
      <circle cx="12" cy="8" r="3.4" />
      <path d="M5.5 20a6.5 6.5 0 0 1 13 0" />
    </I>
  ),
  doc: (p: SVGProps<SVGSVGElement>) => (
    <I {...p}>
      <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
      <path d="M14 3v5h5M9 13h6M9 17h6" />
    </I>
  ),
  ledger: (p: SVGProps<SVGSVGElement>) => (
    <I {...p}>
      <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
    </I>
  ),
  mail: (p: SVGProps<SVGSVGElement>) => (
    <I {...p}>
      <rect x="3" y="5" width="18" height="14" rx="2" />
      <path d="m4 7 8 6 8-6" />
    </I>
  ),
  lock: (p: SVGProps<SVGSVGElement>) => (
    <I {...p}>
      <rect x="5" y="11" width="14" height="9" rx="2" />
      <path d="M8 11V8a4 4 0 0 1 8 0v3" />
    </I>
  ),
  eye: (p: SVGProps<SVGSVGElement>) => (
    <I {...p}>
      <path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z" />
      <circle cx="12" cy="12" r="3" />
    </I>
  ),
  eyeOff: (p: SVGProps<SVGSVGElement>) => (
    <I {...p}>
      <path d="M3 3l18 18M10.6 10.6a3 3 0 0 0 4 4" />
      <path d="M9.4 5.3A9.7 9.7 0 0 1 12 5c6 0 10 7 10 7a17 17 0 0 1-3.2 3.9M6.3 6.3A17 17 0 0 0 2 12s4 7 10 7a9.6 9.6 0 0 0 3-.5" />
    </I>
  ),
  globe: (p: SVGProps<SVGSVGElement>) => (
    <I {...p}>
      <circle cx="12" cy="12" r="9" />
      <path d="M3 12h18M12 3c2.5 2.5 2.5 15 0 18M12 3c-2.5 2.5-2.5 15 0 18" />
    </I>
  ),
  arrow: (p: SVGProps<SVGSVGElement>) => (
    <I {...p}>
      <path d="M5 12h14M13 6l6 6-6 6" />
    </I>
  ),
  chevron: (p: SVGProps<SVGSVGElement>) => (
    <I {...p}>
      <path d="m6 9 6 6 6-6" />
    </I>
  ),
  arrowLeft: (p: SVGProps<SVGSVGElement>) => (
    <I {...p}>
      <path d="M19 12H5M11 6l-6 6 6 6" />
    </I>
  ),
  apple: (p: SVGProps<SVGSVGElement>) => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" {...p}>
      <path d="M16.4 12.6c0-2.3 1.9-3.4 2-3.5-1.1-1.6-2.8-1.8-3.4-1.8-1.4-.1-2.8.8-3.5.8s-1.8-.8-3-.8c-1.5 0-3 .9-3.8 2.3-1.6 2.8-.4 7 1.2 9.3.8 1.1 1.7 2.4 2.9 2.3 1.2 0 1.6-.7 3-.7s1.8.7 3 .7 2-1.1 2.8-2.2c.9-1.3 1.2-2.5 1.3-2.6-.1 0-2.5-1-2.5-3.8zM14.3 5.6c.6-.8 1.1-1.9 1-3-.9 0-2 .6-2.7 1.4-.6.7-1.1 1.8-1 2.8 1 .1 2-.5 2.7-1.2z" />
    </svg>
  ),
  passkey: (p: SVGProps<SVGSVGElement>) => (
    <I {...p}>
      <circle cx="8" cy="9" r="4" />
      <path d="M8 13v8l2-2 2 2v-8M14 11h7M18 11v4M21 11v3" />
    </I>
  ),
};

export function GoogleIcon({ size = 20 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" aria-hidden="true">
      <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.4 29.3 35 24 35c-6.1 0-11-4.9-11-11s4.9-11 11-11c2.8 0 5.4 1.1 7.3 2.8l5.7-5.7C33.5 6.2 29 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.2-.1-2.3-.4-3.5z" />
      <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 16 19 13 24 13c2.8 0 5.4 1.1 7.3 2.8l5.7-5.7C33.5 6.2 29 4 24 4 16.3 4 9.7 8.3 6.3 14.7z" />
      <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35 26.7 36 24 36c-5.3 0-9.7-2.6-11.3-7l-6.5 5C9.6 39.6 16.2 44 24 44z" />
      <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.3-2.2 4.2-4.1 5.6l6.2 5.2C39.9 36.3 44 31 44 24c0-1.2-.1-2.3-.4-3.5z" />
    </svg>
  );
}

/* ============================ Logo ===================================== */
export function AuthLogo() {
  return (
    <Link to="/" className="inline-flex items-center gap-3">
      <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-gradient-to-br from-brand-400 to-brand-900 font-display text-lg font-semibold text-canvas shadow-[0_6px_18px_-8px_rgba(201,164,91,0.7)]">
        N
      </span>
      <span className="text-lg font-semibold tracking-[0.24em] text-ink-950">NEXUS</span>
    </Link>
  );
}

/* ============================ Scene shell ============================== */
const SCRIM =
  'linear-gradient(100deg, rgba(12,15,17,0.92) 0%, rgba(12,15,17,0.6) 26%, rgba(12,15,17,0.42) 48%, rgba(12,15,17,0.78) 72%, rgba(12,15,17,0.96) 100%), linear-gradient(0deg, rgba(12,15,17,0.85), transparent 22%, transparent 84%, rgba(12,15,17,0.7))';

interface FooterItem {
  icon: ReactNode;
  title: string;
  sub: string;
}

/** Minimal, contextual auth nav: logo home, a quiet exit, and the opposite action. */
function AuthNav() {
  const { pathname } = useLocation();
  const onRegister = pathname.startsWith('/register');
  return (
    <header className="flex items-center justify-between px-6 py-5 sm:px-10">
      <AuthLogo />
      <div className="flex items-center gap-2 sm:gap-5">
        <Link
          to="/"
          className="hidden items-center gap-1.5 text-sm text-ink-600 transition hover:text-ink-900 sm:inline-flex"
        >
          <Icons.arrowLeft width={16} height={16} />
          Back to home
        </Link>
        <span className="hidden text-sm text-ink-500 md:inline">
          {onRegister ? 'Already a member?' : 'New to Nexus?'}
        </span>
        <Link
          to={onRegister ? '/login' : '/register'}
          className="rounded-lg border border-brand-600/70 px-4 py-2 text-sm font-medium text-brand-400 transition hover:bg-brand-600 hover:text-canvas"
        >
          {onRegister ? 'Sign in' : 'Create account'}
        </Link>
      </div>
    </header>
  );
}

function AuthFooter({ items }: { items: FooterItem[] }) {
  return (
    <footer className="border-t border-ink-200/50 px-6 py-5 sm:px-10">
      <div className="grid gap-4 sm:grid-cols-3">
        {items.map((it) => (
          <div key={it.title} className="flex items-start gap-3">
            <span className="mt-0.5 shrink-0 text-ink-500">{it.icon}</span>
            <div className="text-xs leading-relaxed text-ink-500">
              <p className="text-ink-700">{it.title}</p>
              <p>{it.sub}</p>
            </div>
          </div>
        ))}
      </div>
    </footer>
  );
}

export function AuthScene({
  left,
  form,
  footer,
}: {
  left: ReactNode;
  form: ReactNode;
  footer: FooterItem[];
}) {
  return (
    <div className="relative min-h-screen overflow-hidden bg-canvas">
      <div className="absolute inset-0">
        <img src={heroImg} alt="" className="h-full w-full object-cover" />
        <div className="absolute inset-0" style={{ background: SCRIM }} />
      </div>

      <div className="relative z-10 flex min-h-screen flex-col">
        <AuthNav />
        <main className="grid flex-1 items-center gap-12 px-6 py-6 sm:px-10 lg:grid-cols-[1.05fr_minmax(420px,540px)] lg:gap-16">
          <div className="hidden lg:block animate-rise">{left}</div>
          <div className="flex w-full justify-center lg:justify-end">{form}</div>
        </main>
        <AuthFooter items={footer} />
      </div>
    </div>
  );
}

/* ============================ Left column bits ========================= */
export function Eyebrow({ children }: { children: ReactNode }) {
  return (
    <span className="inline-flex items-center gap-2.5 text-xs font-semibold uppercase tracking-[0.18em] text-brand-400">
      <span className="h-px w-6 bg-brand-500/70" />
      {children}
    </span>
  );
}

export function FeatureItem({ icon, title, desc }: { icon: ReactNode; title: string; desc: string }) {
  return (
    <div className="flex gap-4">
      <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-brand-600/25 bg-brand-500/5 text-brand-400">
        {icon}
      </span>
      <div>
        <h3 className="text-xs font-semibold uppercase tracking-[0.12em] text-brand-400">{title}</h3>
        <p className="mt-1 max-w-[28ch] text-sm leading-relaxed text-ink-500">{desc}</p>
      </div>
    </div>
  );
}

/* ============================ Form card + inputs ====================== */
export function FormCard({ children, label }: { children: ReactNode; label?: string }) {
  return (
    <div
      aria-label={label}
      className="w-full max-w-[540px] rounded-3xl border border-brand-600/25 bg-[#11151a]/85 p-6 shadow-lg backdrop-blur-xl sm:p-9 animate-scale-in"
    >
      {children}
    </div>
  );
}

export const authInputCls =
  'h-12 w-full rounded-xl border bg-surface/70 px-4 text-sm text-ink-900 placeholder:text-ink-500 transition focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500';

export function FieldLabel({ children, hint }: { children: ReactNode; hint?: string }) {
  return (
    <span className="mb-2 flex items-baseline justify-between">
      <span className="text-sm font-medium text-ink-800">{children}</span>
      {hint && <span className="text-xs text-ink-500">{hint}</span>}
    </span>
  );
}

export const AuthInput = forwardRef<
  HTMLInputElement,
  React.InputHTMLAttributes<HTMLInputElement> & { invalid?: boolean; leftIcon?: ReactNode }
>(function AuthInput({ invalid, leftIcon, className, ...props }, ref) {
  if (leftIcon) {
    return (
      <span className="relative block">
        <span className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-ink-500">
          {leftIcon}
        </span>
        <input
          ref={ref}
          className={cn(authInputCls, 'pl-11', invalid ? 'border-danger-500' : 'border-ink-200', className)}
          aria-invalid={invalid || undefined}
          {...props}
        />
      </span>
    );
  }
  return (
    <input
      ref={ref}
      className={cn(authInputCls, invalid ? 'border-danger-500' : 'border-ink-200', className)}
      aria-invalid={invalid || undefined}
      {...props}
    />
  );
});

/** Password input with a leading lock (optional) and a show/hide toggle. */
export function PasswordInput({
  value,
  onChange,
  placeholder,
  invalid,
  autoComplete,
  withIcon,
}: {
  value: string;
  onChange: (e: React.ChangeEvent<HTMLInputElement>) => void;
  placeholder?: string;
  invalid?: boolean;
  autoComplete?: string;
  withIcon?: boolean;
}) {
  const [show, setShow] = useState(false);
  return (
    <span className="relative block">
      {withIcon && (
        <span className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-ink-500">
          <Icons.lock width={18} height={18} />
        </span>
      )}
      <input
        type={show ? 'text' : 'password'}
        value={value}
        onChange={onChange}
        placeholder={placeholder}
        autoComplete={autoComplete}
        aria-invalid={invalid || undefined}
        className={cn(
          authInputCls,
          'pr-11',
          withIcon && 'pl-11',
          invalid ? 'border-danger-500' : 'border-ink-200',
        )}
      />
      <button
        type="button"
        onClick={() => setShow((s) => !s)}
        aria-label={show ? 'Hide password' : 'Show password'}
        className="absolute right-2.5 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-lg text-ink-500 transition hover:text-ink-900"
      >
        {show ? <Icons.eyeOff width={18} height={18} /> : <Icons.eye width={18} height={18} />}
      </button>
    </span>
  );
}

/** Live password rules that highlight as each is satisfied. Mirrors the backend
 *  policy: min 8 chars, mixed case, and at least one number. */
export function PasswordChecklist({ value }: { value: string }) {
  const rules = [
    { label: 'At least 8 characters', ok: value.length >= 8 },
    { label: 'Upper & lowercase letters', ok: /[a-z]/.test(value) && /[A-Z]/.test(value) },
    { label: 'At least one number', ok: /\d/.test(value) },
  ];
  return (
    <ul className="mt-2.5 grid gap-1.5">
      {rules.map((r) => (
        <li key={r.label} className="flex items-center gap-2 text-xs">
          <span
            className={cn(
              'flex h-4 w-4 items-center justify-center rounded-full border transition',
              r.ok ? 'border-brand-500 bg-brand-500/15 text-brand-400' : 'border-ink-300 text-transparent',
            )}
          >
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3.5" strokeLinecap="round" strokeLinejoin="round">
              <path d="M5 13l4 4L19 7" />
            </svg>
          </span>
          <span className={cn('transition-colors', r.ok ? 'text-brand-400' : 'text-ink-500')}>{r.label}</span>
        </li>
      ))}
    </ul>
  );
}
