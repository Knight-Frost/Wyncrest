/**
 * Admin permission helpers — the single client-side source of truth for the
 * Wyncrest RBAC rule. Nav gating, route guards, and per-page checks all call
 * these so the logic can never drift between call sites.
 *
 * The rule (mirrors the backend `Admin::hasCapability()` / `isSuperAdmin()`):
 *
 *   if super admin        → allow (implicitly holds every capability)
 *   else if scoped admin  → allow only when the exact capability is granted
 *   else (non-admin)      → never holds an admin capability
 *
 * IMPORTANT: this is UI reflection ONLY. The API independently enforces the
 * same rule and remains the real security boundary — a `true` result here is a
 * courtesy to avoid showing dead links, never an authorization decision.
 */
import type { AdminCapability } from '@/lib/types';

/**
 * Minimal structural shape needed to evaluate an admin capability. Both the
 * full `AuthUser` and the sidebar's lighter `NavGateUser` satisfy it, so every
 * caller can share one implementation.
 */
export interface CapabilitySubject {
  role: string;
  is_super_admin?: boolean;
  capabilities?: readonly (AdminCapability | string)[];
}

/** True when the user is an administrator of any level. */
export function isAdmin(user?: CapabilitySubject | null): boolean {
  return !!user && user.role === 'admin';
}

/**
 * True only for a super admin — the master authority that bypasses every scoped
 * capability check. Never depends on capabilities being listed manually.
 */
export function isSuperAdmin(user?: CapabilitySubject | null): boolean {
  return isAdmin(user) && user!.is_super_admin === true;
}

/**
 * Does this admin hold `capability`? Super admins implicitly hold all of them;
 * scoped admins only their granted set; non-admins none.
 */
export function adminHasCapability(
  user: CapabilitySubject | null | undefined,
  capability: AdminCapability | string,
): boolean {
  if (!isAdmin(user)) return false;
  if (user!.is_super_admin === true) return true;
  return (user!.capabilities ?? []).some((c) => c === capability);
}
