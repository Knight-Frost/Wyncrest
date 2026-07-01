import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { authApi } from '@/lib/endpoints';
import {
  AuthShell,
  AuthVisualPanel,
  AuthCard,
  AuthIcons,
  DEFAULT_TRUST_ITEMS,
} from '@/components/auth';

type State = 'loading' | 'success' | 'already' | 'error';

export function VerifyEmail() {
  const [searchParams] = useSearchParams();

  const id        = searchParams.get('id') ?? '';
  const hash      = searchParams.get('hash') ?? '';
  const signature = searchParams.get('signature') ?? '';
  const expires   = searchParams.get('expires') ?? '';
  const hasParams = Boolean(id && hash && signature && expires);

  const [state, setState] = useState<State>(hasParams ? 'loading' : 'error');
  const [message, setMessage] = useState(
    hasParams ? '' : 'This verification link is invalid or incomplete.',
  );

  useEffect(() => {
    if (!hasParams) return;

    authApi
      .verifyEmail({ id, hash, signature, expires })
      .then((res) => {
        if (res.message?.toLowerCase().includes('already')) {
          setState('already');
        } else {
          setState('success');
        }
        setMessage(res.message ?? '');
      })
      .catch((err: unknown) => {
        setState('error');
        const msg =
          (err as { response?: { data?: { message?: string } } })?.response?.data?.message ??
          'Verification failed. The link may have expired.';
        setMessage(msg);
      });
  }, [hasParams, id, hash, signature, expires]);

  function title() {
    if (state === 'loading') return 'Verifying…';
    if (state === 'success') return 'Email verified!';
    if (state === 'already') return 'Already verified';
    return 'Verification failed';
  }

  return (
    <AuthShell
      panel={
        <AuthVisualPanel
          headline="A calmer way to manage rentals."
          accentWords={['manage rentals']}
          supporting="Secure access for tenants, landlords, and admins. Everything in one place."
          trustItems={DEFAULT_TRUST_ITEMS}
        />
      }
    >
      <AuthCard label="Verify email" title={title()}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem', textAlign: 'center' }}>
          {state === 'loading' && (
            <div style={{ display: 'flex', justifyContent: 'center', padding: '1.5rem 0' }}>
              <span
                style={{
                  width: 32,
                  height: 32,
                  borderRadius: '50%',
                  border: '2.5px solid var(--auth-border)',
                  borderTopColor: 'var(--auth-focus)',
                  display: 'inline-block',
                  animation: 'spin 0.7s linear infinite',
                }}
              />
            </div>
          )}

          {state === 'success' && (
            <div
              style={{
                borderRadius: '0.75rem',
                border: '1px solid rgba(32,89,192,0.20)',
                background: 'rgba(32,89,192,0.07)',
                padding: '1rem',
                fontSize: '0.875rem',
                color: 'var(--auth-focus)',
              }}
            >
              Your email address has been verified. Your account is now fully active.
            </div>
          )}

          {state === 'already' && (
            <div
              style={{
                borderRadius: '0.75rem',
                border: '1px solid var(--auth-border)',
                background: 'var(--auth-surface)',
                padding: '1rem',
                fontSize: '0.875rem',
                color: 'var(--auth-text-muted)',
              }}
            >
              Your email address is already verified. No action needed.
            </div>
          )}

          {state === 'error' && (
            <>
              <div
                style={{
                  borderRadius: '0.75rem',
                  border: '1px solid rgba(192,32,24,0.25)',
                  background: 'rgba(192,32,24,0.06)',
                  padding: '1rem',
                  fontSize: '0.875rem',
                  color: '#B42318',
                }}
              >
                {message || 'This verification link is invalid or has expired.'}
              </div>
              <p style={{ fontSize: '0.875rem', color: 'var(--auth-text-muted)' }}>
                Sign in to your account and request a new verification email from your settings.
              </p>
            </>
          )}

          {state !== 'loading' && (
            <Link
              to="/login"
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                justifyContent: 'center',
                gap: '0.375rem',
                fontSize: '0.875rem',
                fontWeight: 500,
                color: 'var(--auth-focus)',
                textDecoration: 'none',
              }}
            >
              <AuthIcons.arrowLeft width={16} height={16} />
              Go to sign in
            </Link>
          )}
        </div>
      </AuthCard>
    </AuthShell>
  );
}
