import { Navigate, useLocation } from 'react-router';
import { useAuth } from '@/context/auth';
import { LoadingState, ForbiddenState } from '@/components/ui/states';
import { adminHasCapability } from '@/lib/permissions';
import { getActivePortal } from '@/lib/storage';
import type { AdminCapability, Role } from '@/lib/types';

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

/**
 * Capability gate for admins on capability-backed pages. UI reflection only —
 * the API is the real boundary and still returns 403 for a missing capability.
 *
 * Non-admins fall through untouched: some gated routes are SHARED with
 * tenants & landlords, who reach their own role-scoped view and are gated by
 * their own API rules. Only an admin lacking the capability is shown a clean
 * "no access" state — never a broken page. Super admins always pass
 * (adminHasCapability bypasses for them).
 *
 * Note: Users/Contracts/Ledger are deliberately NOT wrapped in this guard —
 * viewing those areas is a baseline admin privilege; only specific mutating
 * actions inside those pages require manage_users/manage_contracts/manage_ledger.
 */
export function RequireCapability({
  capability,
  children,
}: {
  capability: AdminCapability;
  children: React.ReactNode;
}) {
  const { user } = useAuth();
  if (user && user.role === 'admin' && !adminHasCapability(user, capability)) {
    return (
      <div className="mx-auto max-w-2xl px-4 py-12">
        <ForbiddenState
          title="You don't have access to this area"
          message="This section needs a permission your account hasn't been granted. Ask a super admin if you need it."
        />
      </div>
    );
  }
  return <>{children}</>;
}

/**
 * Admin-only AND capability-gated: the role gate runs first (blocking
 * tenants/landlords), then the capability gate. Use for admin-exclusive pages;
 * use bare `RequireCapability` for routes shared with other roles.
 */
export function RequireAdminCapability({
  capability,
  children,
}: {
  capability: AdminCapability;
  children: React.ReactNode;
}) {
  return (
    <RequireRole roles={['admin']}>
      <RequireCapability capability={capability}>{children}</RequireCapability>
    </RequireRole>
  );
}

/** Sends already-authenticated users away from auth pages. */
export function RedirectIfAuthed({ children }: { children: React.ReactNode }) {
  const { user, initializing } = useAuth();
  if (initializing) return null;
  if (user) return <Navigate to="/app" replace />;
  return <>{children}</>;
}
