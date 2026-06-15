import { Link } from 'react-router';
import { Logo } from '@/components/brand/Logo';

const HIGHLIGHTS = [
  'Listings, contracts, and payments in one place',
  'Immutable financial ledger with a full audit trail',
  'Role-aware access for tenants, landlords, and admins',
];

export function AuthLayout({
  title,
  subtitle,
  children,
  footer,
}: {
  title: string;
  subtitle: string;
  children: React.ReactNode;
  footer: React.ReactNode;
}) {
  return (
    <div className="grid min-h-screen lg:grid-cols-2">
      {/* Brand panel */}
      <div className="relative hidden overflow-hidden bg-canvas lg:block">
        <div className="absolute inset-0 bg-gradient-to-br from-brand-900/35 via-canvas to-canvas" />
        <div
          className="absolute inset-0 opacity-[0.07]"
          style={{
            backgroundImage:
              'radial-gradient(circle at 1px 1px, white 1px, transparent 0)',
            backgroundSize: '28px 28px',
          }}
        />
        <div className="relative flex h-full flex-col justify-between p-12">
          <Link to="/" className="inline-flex">
            <span className="inline-flex items-center gap-2.5">
              <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-500/20 text-brand-400">
                <svg width="20" height="20" viewBox="0 0 32 32">
                  <path d="M9 23V9h2.6l9.8 10.2V9H24v14h-2.6L11.6 12.8V23H9z" fill="currentColor" />
                </svg>
              </span>
              <span className="text-lg font-bold tracking-tight text-white">Nexus</span>
            </span>
          </Link>

          <div className="max-w-md">
            <h2 className="text-3xl font-bold leading-tight text-white">
              The unified home for property rentals.
            </h2>
            <ul className="mt-8 space-y-4">
              {HIGHLIGHTS.map((h) => (
                <li key={h} className="flex items-start gap-3 text-ink-800">
                  <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-brand-500/15 text-brand-400">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3">
                      <path d="M5 13l4 4L19 7" strokeLinecap="round" strokeLinejoin="round" />
                    </svg>
                  </span>
                  <span className="text-sm">{h}</span>
                </li>
              ))}
            </ul>
          </div>

          <p className="text-xs text-ink-500">
            © {new Date().getFullYear()} Nexus. Secure property management.
          </p>
        </div>
      </div>

      {/* Form panel */}
      <div className="flex items-center justify-center px-6 py-12">
        <div className="w-full max-w-sm animate-rise">
          <div className="mb-8 lg:hidden">
            <Logo size={32} />
          </div>
          <h1 className="text-2xl font-bold tracking-tight text-ink-950">{title}</h1>
          <p className="mt-1.5 text-sm text-ink-500">{subtitle}</p>
          <div className="mt-8">{children}</div>
          <div className="mt-6 text-center text-sm text-ink-500">{footer}</div>
        </div>
      </div>
    </div>
  );
}
