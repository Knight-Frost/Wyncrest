/**
 * HTTP clients.
 *
 * `http`        — unauthenticated (login, register, public listings).
 * `portalHttp`  — one axios instance per portal, with two DIFFERENT auth models:
 *                   • tenant / landlord — stateless Sanctum BEARER tokens read
 *                     from storage and sent in the Authorization header.
 *                   • admin — a first-party COOKIE SESSION: `withCredentials`
 *                     sends the HttpOnly session cookie, and no token is ever
 *                     attached or read from JavaScript. This is the isolation that
 *                     stops a stored value from diverging from the real admin.
 *                 Each routes 401s to its own registered handler, so a tenant 401
 *                 never logs out the landlord or admin session.
 */
import axios, { AxiosError, type AxiosInstance } from 'axios';
import { type Portal, getPortalToken } from './storage';
import type { ApiError } from './types';

const baseURL = import.meta.env.VITE_API_BASE_URL ?? '/api';

// Sanctum's CSRF-cookie endpoint sits BESIDE /api, not under it.
const csrfCookieUrl = `${baseURL.replace(/\/api\/?$/, '')}/sanctum/csrf-cookie`;

// ---- Unauthenticated client (login / register / public listings) ---------

export const http: AxiosInstance = axios.create({
  baseURL,
  headers: { Accept: 'application/json' },
});

http.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => Promise.reject(normalizeError(error)),
);

// ---- Per-portal 401 handlers --------------------------------------------

const portalUnauthorizedHandlers = new Map<Portal, () => void>();

export function setPortalUnauthorizedHandler(portal: Portal, handler: () => void): void {
  portalUnauthorizedHandlers.set(portal, handler);
}

// ---- Portal-scoped client factories --------------------------------------

/** Routes a 401 to the portal's handler and normalizes the error. */
function attachUnauthorizedHandler(instance: AxiosInstance, portal: Portal): void {
  instance.interceptors.response.use(
    (response) => response,
    (error: AxiosError) => {
      if (error.response?.status === 401) {
        portalUnauthorizedHandlers.get(portal)?.();
      }
      return Promise.reject(normalizeError(error));
    },
  );
}

/** Tenant / landlord: stateless bearer token from storage. */
function makeBearerClient(portal: Portal): AxiosInstance {
  const instance = axios.create({
    baseURL,
    headers: { Accept: 'application/json' },
  });

  instance.interceptors.request.use((config) => {
    const token = getPortalToken(portal);
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  });

  attachUnauthorizedHandler(instance, portal);
  return instance;
}

/**
 * Admin: first-party cookie session. Sends the HttpOnly session cookie
 * (`withCredentials`) and the CSRF header derived from the XSRF-TOKEN cookie
 * (`withXSRFToken`). It NEVER reads or attaches a token from JavaScript.
 */
function makeCookieClient(portal: Portal): AxiosInstance {
  const instance = axios.create({
    baseURL,
    headers: { Accept: 'application/json' },
    withCredentials: true,
    withXSRFToken: true,
  });

  attachUnauthorizedHandler(instance, portal);
  return instance;
}

/** One axios instance per portal — isolated auth, isolated 401 handlers. */
export const portalHttp: Record<Portal, AxiosInstance> = {
  tenant: makeBearerClient('tenant'),
  landlord: makeBearerClient('landlord'),
  admin: makeCookieClient('admin'),
};

/**
 * Prime the CSRF cookie for the admin cookie session. Must be awaited before the
 * first mutating admin request (login, and any POST/PUT/PATCH) so Laravel's
 * ValidateCsrfToken sees a matching X-XSRF-TOKEN header. Safe to call repeatedly.
 */
export async function ensureAdminCsrf(): Promise<void> {
  await axios.get(csrfCookieUrl, { withCredentials: true });
}

// ---- Error helpers -------------------------------------------------------

export function normalizeError(error: unknown): ApiError {
  // Idempotent: the axios response interceptors already reject with a normalized
  // ApiError, so callers (useApi, page submit handlers) frequently pass one back
  // in. Return it unchanged instead of collapsing a real 403/422 message down to
  // the generic fallback below.
  if (isApiError(error)) {
    return error;
  }
  if (axios.isAxiosError(error)) {
    const status = error.response?.status ?? 0;
    const data = error.response?.data as
      | { message?: string; errors?: Record<string, string[]> }
      | undefined;
    return {
      status,
      message:
        data?.message ||
        (status === 0
          ? 'Cannot reach the server. Check your connection and try again.'
          : 'Something went wrong. Please try again.'),
      errors: data?.errors,
    };
  }
  return { status: 0, message: 'An unexpected error occurred.' };
}

/** Type guard: an already-normalized ApiError (not a raw AxiosError). */
function isApiError(error: unknown): error is ApiError {
  return (
    typeof error === 'object' &&
    error !== null &&
    !axios.isAxiosError(error) &&
    typeof (error as ApiError).status === 'number' &&
    typeof (error as ApiError).message === 'string'
  );
}

/** Best-effort flat list of field errors for inline form display. */
export function fieldErrors(error: ApiError): Record<string, string> {
  const flat: Record<string, string> = {};
  if (error.errors) {
    for (const [key, msgs] of Object.entries(error.errors)) {
      if (msgs?.length) flat[key] = msgs[0];
    }
  }
  return flat;
}
