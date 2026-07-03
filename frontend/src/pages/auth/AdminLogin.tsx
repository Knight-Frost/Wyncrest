import { useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router';
import { useAuth } from '@/context/auth';
import { fieldErrors } from '@/lib/api';
import type { ApiError } from '@/lib/types';
import {
  AuthShell,
  AuthVisualPanel,
  AuthCard,
  AuthIcons,
  AuthTextField,
  AuthPasswordField,
  AuthFieldLabel,
  AuthFieldError,
  AuthErrorBanner,
} from '@/components/auth';

/**
 * Admin console sign-in — a surface DISTINCT from the tenant/landlord login.
 *
 * Admins authenticate with a first-party HttpOnly cookie session (no bearer
 * token is ever stored in the browser). This isolation is why admin auth has its
 * own page and endpoint (POST /api/admin/login) rather than sharing /login.
 */
export function AdminLogin() {
  const { adminLogin } = useAuth();
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
      await adminLogin(email, password, remember);
      navigate(from, { replace: true });
    } catch (err) {
      const apiErr = err as ApiError;
      const fields = fieldErrors(apiErr);
      setErrors(fields);
      if (Object.keys(fields).length === 0) {
        setFormError(apiErr.message || 'Could not sign in. Please try again.');
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <AuthShell panel={<AuthVisualPanel mode="login" />}>
      <AuthCard
        label="Administrator"
        title="Console sign-in"
        subtitle="Secure access for platform administrators."
      >
        <form
          onSubmit={onSubmit}
          noValidate
          style={{ display: 'flex', flexDirection: 'column', gap: '1.125rem' }}
        >
          {formError && <AuthErrorBanner message={formError} />}

          <label className="block">
            <AuthFieldLabel>Email</AuthFieldLabel>
            <AuthTextField
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              invalid={!!errors.email}
              placeholder="you@example.com"
              autoComplete="email"
              leftIcon={<AuthIcons.mail />}
              required
            />
            <AuthFieldError message={errors.email} />
          </label>

          <label className="block">
            <AuthFieldLabel>Password</AuthFieldLabel>
            <AuthPasswordField
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              invalid={!!errors.password}
              placeholder="Enter your password"
              autoComplete="current-password"
              withIcon
            />
            <AuthFieldError message={errors.password} />
          </label>

          <div className="flex items-center justify-between">
            <label
              className="flex cursor-pointer items-center gap-2"
              style={{ fontSize: '0.875rem', color: 'var(--auth-text-primary)' }}
            >
              <input
                type="checkbox"
                checked={remember}
                onChange={(e) => setRemember(e.target.checked)}
                style={{
                  width: 16,
                  height: 16,
                  borderRadius: 4,
                  accentColor: 'var(--auth-focus)',
                  cursor: 'pointer',
                }}
              />
              Keep me signed in
            </label>
            <Link
              to="/forgot-password"
              style={{
                fontSize: '0.875rem',
                fontWeight: 700,
                color: 'var(--auth-focus)',
                textDecoration: 'none',
              }}
            >
              Forgot password?
            </Link>
          </div>

          <button
            type="submit"
            disabled={submitting}
            className="auth-btn-primary"
            style={{ marginTop: '0.125rem' }}
          >
            {submitting ? (
              <>
                <span
                  style={{
                    width: 18,
                    height: 18,
                    borderRadius: '50%',
                    border: '2px solid rgba(255,255,255,0.35)',
                    borderTopColor: '#fff',
                    display: 'inline-block',
                    animation: 'spin 0.7s linear infinite',
                  }}
                />
                Signing in&hellip;
              </>
            ) : (
              <>
                Sign in
                <AuthIcons.arrow />
              </>
            )}
          </button>
        </form>

        <p
          className="text-center"
          style={{
            fontSize: '0.875rem',
            color: 'var(--auth-text-muted)',
            marginTop: '1.25rem',
          }}
        >
          Not an administrator?{' '}
          <Link
            to="/login"
            style={{ fontWeight: 700, color: 'var(--auth-focus)', textDecoration: 'none' }}
          >
            Go to the main sign-in
          </Link>
        </p>
      </AuthCard>
    </AuthShell>
  );
}
