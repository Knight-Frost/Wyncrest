import { useState } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router';
import { cn } from '@/lib/cn';
import { useAuth } from '@/context/auth';
import { Logo } from '@/components/brand/Logo';
import { IconLogout, IconMenu, IconX } from '@/components/ui/icons';
import { navForRole, roleLabel } from '@/routes/nav';

function NavList({ onNavigate }: { onNavigate?: () => void }) {
  const { user } = useAuth();
  if (!user) return null;
  const items = navForRole(user.role);
  return (
    <nav className="flex flex-col gap-1 px-3">
      {items.map((item) => (
        <NavLink
          key={item.to}
          to={item.to}
          end={item.end}
          onClick={onNavigate}
          className={({ isActive }) =>
            cn(
              'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition',
              isActive
                ? 'bg-brand-50 text-brand-800'
                : 'text-ink-600 hover:bg-ink-100 hover:text-ink-900',
            )
          }
        >
          <span className="text-current">{item.icon}</span>
          {item.label}
        </NavLink>
      ))}
    </nav>
  );
}

function UserPanel() {
  const { user, logout } = useAuth();
  if (!user) return null;
  const name = 'full_name' in user ? user.full_name : user.name;
  const initials = name
    .split(' ')
    .map((p) => p[0])
    .slice(0, 2)
    .join('')
    .toUpperCase();

  return (
    <div className="border-t border-ink-100 p-3">
      <div className="flex items-center gap-3 rounded-xl px-2 py-2">
        <span className="flex h-9 w-9 items-center justify-center rounded-full bg-brand-700 text-sm font-semibold text-canvas">
          {initials}
        </span>
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-medium text-ink-900">{name}</p>
          <p className="text-xs text-ink-500">{roleLabel[user.role]}</p>
        </div>
        <button
          onClick={() => logout()}
          className="flex h-8 w-8 items-center justify-center rounded-lg text-ink-500 transition hover:bg-ink-100 hover:text-danger-600"
          aria-label="Sign out"
          title="Sign out"
        >
          <IconLogout className="h-[18px] w-[18px]" />
        </button>
      </div>
    </div>
  );
}

export function AppShell() {
  const [mobileOpen, setMobileOpen] = useState(false);
  const location = useLocation();

  return (
    <div className="min-h-screen bg-canvas">
      {/* Desktop sidebar */}
      <aside className="fixed inset-y-0 left-0 hidden w-64 flex-col border-r border-ink-200 bg-surface lg:flex">
        <div className="flex h-16 items-center px-5">
          <Logo size={30} />
        </div>
        <div className="flex-1 overflow-y-auto py-2">
          <NavList />
        </div>
        <UserPanel />
      </aside>

      {/* Mobile drawer */}
      {mobileOpen && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <div
            className="absolute inset-0 bg-black/60 backdrop-blur-sm animate-fade-in"
            onClick={() => setMobileOpen(false)}
            aria-hidden="true"
          />
          <aside className="absolute inset-y-0 left-0 flex w-72 flex-col bg-surface shadow-lg animate-rise">
            <div className="flex h-16 items-center justify-between px-5">
              <Logo size={30} />
              <button
                onClick={() => setMobileOpen(false)}
                className="flex h-9 w-9 items-center justify-center rounded-lg text-ink-500 hover:bg-ink-100"
                aria-label="Close menu"
              >
                <IconX />
              </button>
            </div>
            <div className="flex-1 overflow-y-auto py-2">
              <NavList onNavigate={() => setMobileOpen(false)} />
            </div>
            <UserPanel />
          </aside>
        </div>
      )}

      {/* Main column */}
      <div className="lg:pl-64">
        {/* Mobile top bar */}
        <header className="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-ink-200 bg-surface/80 px-4 backdrop-blur lg:hidden">
          <button
            onClick={() => setMobileOpen(true)}
            className="flex h-10 w-10 items-center justify-center rounded-lg text-ink-700 hover:bg-ink-100"
            aria-label="Open menu"
          >
            <IconMenu />
          </button>
          <Logo size={26} />
        </header>

        <main key={location.pathname} className="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8 animate-fade-in">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
