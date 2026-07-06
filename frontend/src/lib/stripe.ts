/**
 * Stripe.js loader — the SPA's single entry point to the Stripe SDK.
 *
 * Online card payments require BOTH halves to be wired: a publishable key here
 * (`VITE_STRIPE_PUBLISHABLE_KEY`) and a secret key on the API. When the key is
 * absent — as on the keyless demo box — `getStripe()` returns null and the
 * Payments page shows an honest "not enabled here" state instead of loading a
 * checkout that can only fail. We never hardcode a key.
 */
import { loadStripe, type Stripe } from '@stripe/stripe-js';

const publishableKey = import.meta.env.VITE_STRIPE_PUBLISHABLE_KEY as string | undefined;

/** True when a publishable key is configured for this build. */
export const stripeConfigured = typeof publishableKey === 'string' && publishableKey.length > 0;

let stripePromise: Promise<Stripe | null> | null = null;

/**
 * Lazily resolve the shared Stripe instance. Returns null (never throws) when
 * no publishable key is configured, so callers can branch on availability.
 */
export function getStripe(): Promise<Stripe | null> {
  if (!stripeConfigured) return Promise.resolve(null);
  if (!stripePromise) stripePromise = loadStripe(publishableKey as string);
  return stripePromise;
}
