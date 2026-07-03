import { createContext, useContext } from 'react';
import type { Admin, AuthUser, User, UserType } from '@/lib/types';
import type { Portal } from '@/lib/storage';

export type { Portal };

export interface AuthContextValue {
  user: AuthUser | null;
  /** Which portal this tab is authenticated as — null until login completes. */
  portal: Portal | null;
  /** True only during the initial "do we have a valid session?" check. */
  initializing: boolean;
  login: (email: string, password: string, remember?: boolean) => Promise<AuthUser>;
  /**
   * Admin console sign-in — establishes the first-party cookie session (no token
   * is stored). Separate from `login` (tenant/landlord bearer) by design.
   */
  adminLogin: (email: string, password: string, remember?: boolean) => Promise<AuthUser>;
  register: (payload: {
    email: string;
    password: string;
    password_confirmation: string;
    first_name: string;
    last_name: string;
    phone?: string;
    user_type: UserType;
  }) => Promise<AuthUser>;
  logout: () => Promise<void>;
}

export const AuthContext = createContext<AuthContextValue | null>(null);

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within <AuthProvider>');
  return ctx;
}

/** Derive the client-side role discriminator from the API user shape. */
export function toAuthUser(u: User | Admin): AuthUser {
  if ('user_type' in u) {
    return { ...u, role: u.user_type };
  }
  return { ...u, role: 'admin' };
}
