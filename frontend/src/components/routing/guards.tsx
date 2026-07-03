import { Navigate, useLocation } from 'react-router';
import { useAuth } from '@/context/auth';
import { LoadingState } from '@/components/ui/states';
import { getActivePortal } from '@/lib/storage';
import type { Role } from '@/lib/types';

/** Blocks unauthenticated access; preserves intended destination for post-login redirect. */
export function RequireAuth({ children }: { children: React.ReactNode }) {
  const { user, initializing } = useAuth();
  const location = useLocation();

  if (initializing) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <LoadingState label="Restoring your session…" />
      </div>
    );
  }
  if (!user) {
    // Send a lapsed admin session back to the admin console sign-in (its own
    // isolated surface), not the tenant/landlord login they can't use.
    const loginPath = getActivePortal() === 'admin' ? '/admin/login' : '/login';
    return <Navigate to={loginPath} state={{ from: location.pathname }} replace />;
  }
  return <>{children}</>;
}

/**
 * Restricts a route to specific roles. UI gating only — the API enforces real
 * authorization server-side. Sends a mismatched role back to their own home.
 */
export function RequireRole({ roles, children }: { roles: Role[]; children: React.ReactNode }) {
  const { user } = useAuth();
  if (user && !roles.includes(user.role)) {
    return <Navigate to="/app" replace />;
  }
  return <>{children}</>;
}

/** Sends already-authenticated users away from auth pages. */
export function RedirectIfAuthed({ children }: { children: React.ReactNode }) {
  const { user, initializing } = useAuth();
  if (initializing) return null;
  if (user) return <Navigate to="/app" replace />;
  return <>{children}</>;
}
