import { useCallback, useEffect, useMemo, useState } from 'react';
import { authApi } from '@/lib/endpoints';
import { ensureAdminCsrf, setPortalUnauthorizedHandler } from '@/lib/api';
import {
  type Portal,
  clearActivePortal,
  clearDeprecatedAdminToken,
  clearLegacyToken,
  clearPortalToken,
  getActivePortal,
  getPortalToken,
  portalTokenKey,
  setActivePortal,
  setPortalToken,
} from '@/lib/storage';
import type { AuthUser, UserType } from '@/lib/types';
import { AuthContext, type AuthContextValue, toAuthUser } from './auth';

function roleToPortal(role: AuthUser['role']): Portal {
  if (role === 'admin') return 'admin';
  if (role === 'landlord') return 'landlord';
  return 'tenant';
}

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null);
  const [portal, setPortal] = useState<Portal | null>(null);
  const [initializing, setInitializing] = useState(true);

  // Wire a 401 on this portal's client to clear only this portal's session.
  // For admin there is no token to clear — the cookie session is already invalid
  // server-side by the time a 401 comes back; we just drop the local UI state.
  function bindUnauthorizedHandler(p: Portal) {
    setPortalUnauthorizedHandler(p, () => {
      clearPortalToken(p);
      clearActivePortal();
      setUser(null);
      setPortal(null);
    });
  }

  // One-time migration: erase the old shared 'nexus.token' key AND any deprecated
  // admin bearer token, so neither can ever authenticate an admin request again.
  useEffect(() => {
    clearLegacyToken();
    clearDeprecatedAdminToken();
  }, []);

  // Cross-tab logout / session expiry for the TOKEN portals (tenant/landlord).
  // When another tab clears its token, this fires a `storage` event; if it's this
  // tab's active portal, end the session too. Admin is a cookie session (no token
  // to watch) and is handled by focus revalidation below, so we skip it here.
  useEffect(() => {
    function onStorage(e: StorageEvent) {
      if (e.key === null) return; // localStorage.clear()
      const p = getActivePortal();
      if (!p || p === 'admin') return;
      if (e.key === portalTokenKey(p) && e.newValue === null) {
        clearActivePortal();
        setUser(null);
        setPortal(null);
      }
    }
    window.addEventListener('storage', onStorage);
    return () => window.removeEventListener('storage', onStorage);
  }, []);

  // Revalidate the admin cookie session when the tab regains focus. If it was
  // logged out (or expired) in another tab, the backend returns 401 and the
  // portal's unauthorized handler drops this tab's stale UI. This is the
  // cookie-session equivalent of the token portals' cross-tab `storage` event.
  useEffect(() => {
    function revalidateAdmin() {
      if (document.visibilityState !== 'visible') return;
      if (getActivePortal() !== 'admin') return;
      // A 401 here routes through the admin unauthorized handler (clears state).
      authApi.adminMe().catch(() => {});
    }
    document.addEventListener('visibilitychange', revalidateAdmin);
    window.addEventListener('focus', revalidateAdmin);
    return () => {
      document.removeEventListener('visibilitychange', revalidateAdmin);
      window.removeEventListener('focus', revalidateAdmin);
    };
  }, []);

  // Hydrate from the portal stored in sessionStorage for this tab.
  useEffect(() => {
    let active = true;
    (async () => {
      const p = getActivePortal();
      if (!p) {
        setInitializing(false);
        return;
      }
      bindUnauthorizedHandler(p);
      try {
        if (p === 'admin') {
          // Cookie session: the backend is the source of truth. Prime the CSRF
          // cookie for later mutations, then resolve identity from /admin/me.
          await ensureAdminCsrf();
          const me = await authApi.adminMe();
          if (active) {
            setUser(toAuthUser(me));
            setPortal('admin');
          }
        } else {
          if (!getPortalToken(p)) {
            if (active) setInitializing(false);
            return;
          }
          const me = await authApi.me(p);
          if (active) {
            setUser(toAuthUser(me));
            setPortal(p);
          }
        }
      } catch {
        // Invalid/absent session — clear only this portal.
        clearPortalToken(p);
        clearActivePortal();
      } finally {
        if (active) setInitializing(false);
      }
    })();
    return () => {
      active = false;
    };
  }, []);

  const login = useCallback(
    async (email: string, password: string, remember = true): Promise<AuthUser> => {
      const { user: u, token } = await authApi.login(email, password);
      const authUser = toAuthUser(u);
      const p = roleToPortal(authUser.role);
      setPortalToken(p, token, remember);
      setActivePortal(p); // binds THIS tab's session — other tabs are unaffected
      bindUnauthorizedHandler(p);
      setUser(authUser);
      setPortal(p);
      return authUser;
    },
    [],
  );

  const adminLogin = useCallback(
    async (email: string, password: string, remember = true): Promise<AuthUser> => {
      // Establishes the HttpOnly cookie session server-side; nothing is stored
      // client-side except the (non-sensitive) active-portal marker for this tab.
      const admin = await authApi.adminLogin(email, password, remember);
      const authUser = toAuthUser(admin);
      clearDeprecatedAdminToken(); // belt-and-braces: no stale token may linger
      setActivePortal('admin');
      bindUnauthorizedHandler('admin');
      setUser(authUser);
      setPortal('admin');
      return authUser;
    },
    [],
  );

  const register = useCallback(
    async (payload: {
      email: string;
      password: string;
      password_confirmation: string;
      first_name: string;
      last_name: string;
      phone?: string;
      user_type: UserType;
    }): Promise<AuthUser> => {
      const { user: u, token } = await authApi.register(payload);
      const authUser = toAuthUser(u);
      const p = roleToPortal(authUser.role);
      setPortalToken(p, token);
      setActivePortal(p);
      bindUnauthorizedHandler(p);
      setUser(authUser);
      setPortal(p);
      return authUser;
    },
    [],
  );

  const logout = useCallback(async () => {
    // Use the live sessionStorage value in case React state lags.
    const p = getActivePortal() ?? portal;
    try {
      if (p === 'admin') {
        await authApi.adminLogout();
      } else if (p && getPortalToken(p)) {
        await authApi.logout(p);
      }
    } catch {
      // Session may already be invalid — the client session ends regardless.
    }
    if (p) clearPortalToken(p);
    clearActivePortal();
    setUser(null);
    setPortal(null);
  }, [portal]);

  const value = useMemo<AuthContextValue>(
    () => ({ user, portal, initializing, login, adminLogin, register, logout }),
    [user, portal, initializing, login, adminLogin, register, logout],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
