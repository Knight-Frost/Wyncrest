/**
 * Portal-scoped auth storage.
 *
 * The active portal for each browser tab is stored in sessionStorage (tab-scoped,
 * survives F5 refresh, cleared when the tab closes). Per-portal tokens live in
 * localStorage (persistent) or sessionStorage (when "remember me" is off).
 *
 * Key scheme:
 *   sessionStorage  nexus.portal              — which portal is active in this tab
 *   localStorage    nexus.auth.{p}.token      — persistent token for portal p
 *   sessionStorage  nexus.auth.{p}.token      — ephemeral token for portal p
 *
 * Three tabs can each be logged in as tenant, landlord, and admin simultaneously
 * because each tab's portal key points to a different namespaced token slot.
 */

export type Portal = 'tenant' | 'landlord' | 'admin';

const PORTAL_KEY = 'nexus.portal';
const tokenKey = (p: Portal) => `nexus.auth.${p}.token`;

/** The localStorage/sessionStorage key for a portal's token. Exposed so the
 *  auth layer can detect a cross-tab logout via the `storage` event. */
export function portalTokenKey(p: Portal): string {
  return tokenKey(p);
}

// ---- Active portal (tab-scoped via sessionStorage) -----------------------

export function getActivePortal(): Portal | null {
  try {
    const v = sessionStorage.getItem(PORTAL_KEY);
    return v === 'tenant' || v === 'landlord' || v === 'admin' ? v : null;
  } catch {
    return null;
  }
}

export function setActivePortal(portal: Portal): void {
  try {
    sessionStorage.setItem(PORTAL_KEY, portal);
  } catch {
    // storage unavailable (private mode) — in-memory only
  }
}

export function clearActivePortal(): void {
  try {
    sessionStorage.removeItem(PORTAL_KEY);
  } catch {
    // no-op
  }
}

// ---- Portal tokens -------------------------------------------------------

export function getPortalToken(portal: Portal): string | null {
  try {
    return (
      localStorage.getItem(tokenKey(portal)) ??
      sessionStorage.getItem(tokenKey(portal))
    );
  } catch {
    return null;
  }
}

export function setPortalToken(portal: Portal, token: string, remember = true): void {
  try {
    if (remember) {
      localStorage.setItem(tokenKey(portal), token);
      sessionStorage.removeItem(tokenKey(portal));
    } else {
      sessionStorage.setItem(tokenKey(portal), token);
      localStorage.removeItem(tokenKey(portal));
    }
  } catch {
    // storage unavailable (private mode) — session stays in-memory only
  }
}

export function clearPortalToken(portal: Portal): void {
  try {
    localStorage.removeItem(tokenKey(portal));
    sessionStorage.removeItem(tokenKey(portal));
  } catch {
    // no-op
  }
}

// ---- Legacy cleanup ------------------------------------------------------

/** Remove the old single-token key written by pre-portal-isolation builds. */
export function clearLegacyToken(): void {
  try {
    localStorage.removeItem('nexus.token');
    sessionStorage.removeItem('nexus.token');
  } catch {
    // no-op
  }
}

/**
 * Remove any admin BEARER token left by pre-cookie-session builds. Admin auth is
 * now an HttpOnly session cookie the browser manages; a stale localStorage token
 * must never be read or trusted for admin authentication again.
 */
export function clearDeprecatedAdminToken(): void {
  try {
    localStorage.removeItem(tokenKey('admin'));
    sessionStorage.removeItem(tokenKey('admin'));
  } catch {
    // no-op
  }
}
