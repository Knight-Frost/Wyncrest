import { useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router';
import { useAuth } from '@/context/auth';
import { fieldErrors } from '@/lib/api';
import { useToast } from '@/components/ui/toast';
import type { ApiError } from '@/lib/types';
import {
  AuthInput,
  AuthScene,
  Eyebrow,
  FeatureItem,
  FieldLabel,
  FormCard,
  GoogleIcon,
  Icons,
  PasswordInput,
} from './authParts';

const FOOTER = [
  { icon: <Icons.lock width={18} height={18} />, title: 'Your data is protected with', sub: 'enterprise-grade security.' },
  { icon: <Icons.globe width={18} height={18} />, title: 'Built for trust. Designed for scale.', sub: 'Available globally, 24/7.' },
  { icon: <Icons.shieldCheck width={18} height={18} />, title: 'SOC 2 Type II Compliant', sub: '256-bit Encryption' },
];

function LeftPanel() {
  return (
    <div className="max-w-xl">
      <Eyebrow>Secure. Seamless. Connected.</Eyebrow>
      <h1 className="mt-6 font-display text-5xl font-medium leading-[1.05] tracking-tight text-ink-950">
        The unified home for property rentals.
      </h1>
      <p className="mt-5 max-w-md text-base leading-relaxed text-ink-500">
        Manage properties, tenants, contracts, and payments, all in one secure platform built for
        trust.
      </p>

      <div className="mt-10 space-y-6">
        <FeatureItem icon={<Icons.shieldCheck />} title="Secure ledger" desc="Immutable records and bank-grade encryption." />
        <FeatureItem icon={<Icons.badgeCheck />} title="Verified listings" desc="Every property and user thoroughly verified." />
        <FeatureItem icon={<Icons.person />} title="Role-aware access" desc="Secure experiences for tenants, landlords, and admins." />
        <FeatureItem icon={<Icons.doc />} title="Complete audit trail" desc="Every action tracked. Every record immutable." />
      </div>
    </div>
  );
}

export function Login() {
  const { login } = useAuth();
  const { toast } = useToast();
  const navigate = useNavigate();
  const location = useLocation();
  const from = (location.state as { from?: string } | null)?.from ?? '/app';

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setErrors({});
    setFormError(null);
    try {
      await login(email, password, remember);
      navigate(from, { replace: true });
    } catch (err) {
      const apiErr = err as ApiError;
      const fields = fieldErrors(apiErr);
      setErrors(fields);
      if (Object.keys(fields).length === 0) setFormError(apiErr.message);
    } finally {
      setSubmitting(false);
    }
  }

  const notAvailable = (what: string) => () =>
    toast(`${what} isn’t available yet. Use email and password for now.`, 'info');

  return (
    <AuthScene left={<LeftPanel />} footer={FOOTER} form={
      <FormCard label="Welcome back">
        <h1 className="text-center font-display text-4xl font-medium text-ink-950">Welcome back</h1>
        <p className="mx-auto mt-2 max-w-xs text-center text-sm leading-relaxed text-ink-500">
          Sign in to access your properties, contracts, and payments.
        </p>

        <div className="my-7 flex items-center gap-4">
          <span className="h-px flex-1 bg-ink-200" />
          <span className="font-display text-lg font-semibold text-brand-400">N</span>
          <span className="h-px flex-1 bg-ink-200" />
        </div>

        <form onSubmit={onSubmit} className="space-y-5" noValidate>
          {formError && (
            <div className="rounded-xl border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-500" role="alert">
              {formError}
            </div>
          )}

          <label className="block">
            <FieldLabel>Email</FieldLabel>
            <AuthInput
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              invalid={!!errors.email}
              placeholder="you@example.com"
              autoComplete="email"
              leftIcon={<Icons.mail width={18} height={18} />}
              required
            />
            {errors.email && <span className="mt-1 block text-xs text-danger-500">{errors.email}</span>}
          </label>

          <label className="block">
            <FieldLabel>Password</FieldLabel>
            <PasswordInput
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              invalid={!!errors.password}
              placeholder="Enter your password"
              autoComplete="current-password"
              withIcon
            />
            {errors.password && <span className="mt-1 block text-xs text-danger-500">{errors.password}</span>}
          </label>

          <div className="flex items-center justify-between">
            <label className="flex cursor-pointer items-center gap-2.5 text-sm text-ink-700">
              <input
                type="checkbox"
                checked={remember}
                onChange={(e) => setRemember(e.target.checked)}
                className="h-4 w-4 rounded border-ink-300 bg-surface text-brand-600 accent-brand-600"
              />
              Remember me
            </label>
            <button
              type="button"
              onClick={notAvailable('Password reset')}
              className="text-sm font-medium text-brand-400 hover:text-brand-800"
            >
              Forgot password?
            </button>
          </div>

          <button
            type="submit"
            disabled={submitting}
            className="flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-b from-brand-400 to-brand-600 py-4 text-sm font-semibold text-canvas shadow-[0_12px_30px_-12px_rgba(201,164,91,0.8)] transition hover:brightness-105 disabled:opacity-60"
          >
            {submitting ? 'Signing in…' : 'Sign in'}
            {!submitting && <Icons.arrow width={18} height={18} />}
          </button>
        </form>

        <div className="my-6 flex items-center gap-4">
          <span className="h-px flex-1 bg-ink-200" />
          <span className="text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-500">Or continue with</span>
          <span className="h-px flex-1 bg-ink-200" />
        </div>

        <div className="grid grid-cols-3 gap-3">
          <button type="button" onClick={notAvailable('Google sign-in')} aria-label="Continue with Google" className="flex h-12 items-center justify-center rounded-xl border border-ink-200 bg-surface/70 transition hover:border-ink-300 hover:bg-ink-100">
            <GoogleIcon />
          </button>
          <button type="button" onClick={notAvailable('Apple sign-in')} aria-label="Continue with Apple" className="flex h-12 items-center justify-center rounded-xl border border-ink-200 bg-surface/70 text-ink-900 transition hover:border-ink-300 hover:bg-ink-100">
            <Icons.apple width={20} height={20} />
          </button>
          <button type="button" onClick={notAvailable('Passkey sign-in')} aria-label="Continue with a passkey" className="flex h-12 items-center justify-center rounded-xl border border-ink-200 bg-surface/70 text-ink-700 transition hover:border-ink-300 hover:bg-ink-100">
            <Icons.passkey width={20} height={20} />
          </button>
        </div>

        <p className="mt-6 text-center text-sm text-ink-500">
          New to Nexus?{' '}
          <Link to="/register" className="font-semibold text-brand-400 hover:text-brand-800">
            Create an account
          </Link>
        </p>
      </FormCard>
    } />
  );
}
