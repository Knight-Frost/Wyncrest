import { useState } from 'react';
import { Link } from 'react-router';
import { brand } from '@/config/brand';
import { useTheme, type ThemeChoice } from '@/context/theme';
import { useAccent } from '@/context/accent';
import { ACCENTS, DEFAULT_ACCENT_KEY } from '@/config/accents';
import { DARK_THEMES } from '@/config/darkThemes';
import { useAuth, type Portal } from '@/context/auth';
import { isSuperAdmin as userIsSuperAdmin } from '@/lib/permissions';
import { useApi } from '@/hooks/useApi';
import { authApi, notificationApi } from '@/lib/endpoints';
import { fieldErrors } from '@/lib/api';
import type { ApiError } from '@/lib/types';
import { LoadingState, ErrorState } from '@/components/ui/states';
import { useToast } from '@/components/ui/toast';
import { formatDate } from '@/lib/format';
import { SemanticBadge } from '@/components/cards';
import {
  IconSun,
  IconMoon,
  IconMonitor,
  IconCheck,
  IconBell,
  IconShield,
  IconLock,
  IconUser,
  IconClock,
  IconCheckCircle,
  IconChevronRight,
  IconRefresh,
} from '@/components/ui/icons';
import './account.css';

/* ── accessible toggle ───────────────────────────────────────────────────── */
function Toggle({
  on,
  onChange,
  label,
  disabled,
}: {
  on: boolean;
  onChange: () => void;
  label: string;
  disabled?: boolean;
}) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={on}
      aria-label={label}
      disabled={disabled}
      className={`ac-toggle${on ? ' on' : ''}`}
      onClick={onChange}
    />
  );
}

/* ── Notification types surfaced for per-type email/SMS preferences. Each row
   is a real backend NotificationType value and persists a real preference —
   but this is a curated SUBSET of the enum (the high-signal money/lease
   types), not the full list. Types without a row use the backend defaults. ── */
const NOTIF_TYPES: { type: string; name: string; desc: string }[] = [
  { type: 'rent_generated',      name: 'Rent invoice issued', desc: 'When a new rent charge is created' },
  { type: 'rent_overdue',        name: 'Rent overdue',        desc: 'When a payment is past due' },
  { type: 'payment_succeeded',   name: 'Payment received',    desc: 'When your payment is confirmed' },
  { type: 'payment_failed',      name: 'Payment failed',      desc: "When a payment doesn't go through" },
  { type: 'late_fee_added',      name: 'Late fee added',      desc: 'When a late fee is applied' },
  { type: 'contract_signed',     name: 'Lease signed',        desc: 'When a lease is activated' },
  { type: 'contract_terminated', name: 'Lease ended',         desc: 'When a lease is terminated' },
];

type ChannelPrefs = { email: boolean; sms: boolean };
type PrefMap = Record<string, ChannelPrefs>;

type ThemeIconComp = React.ComponentType<{ size?: number; className?: string }>;

const THEMES: { v: ThemeChoice; label: string; Icon: ThemeIconComp }[] = [
  { v: 'light',  label: 'Light',  Icon: IconSun     },
  { v: 'system', label: 'System', Icon: IconMonitor },
  { v: 'dark',   label: 'Dark',   Icon: IconMoon    },
];

/* Support icon not in icons.tsx — inline here */
function HeadphonesIcon({ size = 20 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor"
      strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M3 18v-6a9 9 0 0 1 18 0v6" />
      <path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3z" />
      <path d="M3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z" />
    </svg>
  );
}

/* Palette icon */
function PaletteIcon({ size = 20 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor"
      strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <circle cx="13.5" cy="6.5" r=".5" />
      <circle cx="17.5" cy="10.5" r=".5" />
      <circle cx="8.5" cy="7.5" r=".5" />
      <circle cx="6.5" cy="12.5" r=".5" />
      <path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z" />
    </svg>
  );
}

/* ── Dark-theme (palette) picker ─────────────────────────────────────────── */
/* Controls the dark-mode ATMOSPHERE (surfaces/background) — separate from the
   accent. Only takes visual effect while dark mode is active. */
function DarkThemePicker() {
  const { darkTheme, setDarkTheme, resolved } = useTheme();

  return (
    <div className="ac-accent-picker">
      <div className="ac-accent-picker-header">
        <span className="ac-field-lab" style={{ display: 'block' }}>Dark theme</span>
        {resolved !== 'dark' && (
          <span className="ac-dark-hint-tag">Applies in dark mode</span>
        )}
      </div>

      <div role="radiogroup" aria-label="Choose dark theme" className="ac-dark-grid">
        {DARK_THEMES.map((t) => {
          const isActive = darkTheme === t.key;
          return (
            <button
              key={t.key}
              type="button"
              role="radio"
              aria-checked={isActive}
              aria-label={t.label}
              title={t.hint}
              onClick={() => setDarkTheme(t.key)}
              className={`ac-dark-opt${isActive ? ' on' : ''}`}
            >
              <span
                className="ac-dark-swatch"
                aria-hidden="true"
                style={{ background: t.swatch[0], borderColor: t.swatch[2] }}
              >
                <span className="ac-dark-swatch-surface" style={{ background: t.swatch[1] }} />
                <span className="ac-dark-swatch-edge" style={{ background: t.swatch[2] }} />
                {isActive && (
                  <IconCheck size={11} strokeWidth={3} className="ac-dark-swatch-check" aria-hidden="true" />
                )}
              </span>
              <span className="ac-dark-lab">{t.label}</span>
            </button>
          );
        })}
      </div>

      <p className="ac-accent-note" style={{ marginTop: 10 }}>
        Sets the dark-mode background, surfaces and panels. Independent of your accent
        colour. Saved on this device only.
      </p>
    </div>
  );
}

/* ── Accent picker ───────────────────────────────────────────────────────── */
function AccentPicker() {
  const { accent, setAccentKey, reset } = useAccent();
  const { resolved } = useTheme();
  const isDefault = accent.key === DEFAULT_ACCENT_KEY;
  // Preview swatches reflect the CURRENT mode's ramp so the demo stays truthful.
  const previewVars = resolved === 'dark' ? accent.darkVars : accent.vars;

  return (
    <div className="ac-accent-picker">
      <div className="ac-accent-picker-header">
        <span className="ac-field-lab" style={{ display: 'block' }}>Accent colour</span>
        {!isDefault && (
          <button
            type="button"
            className="ac-accent-reset"
            onClick={reset}
            aria-label="Reset accent to default (Wyncrest Blue)"
          >
            <IconRefresh size={13} aria-hidden="true" />
            Reset to default
          </button>
        )}
      </div>

      {/* Swatch grid — radio semantics via role="radiogroup" */}
      <div
        role="radiogroup"
        aria-label="Choose accent colour"
        className="ac-accent-grid"
      >
        {ACCENTS.map((a) => {
          const isActive = accent.key === a.key;
          return (
            <button
              key={a.key}
              type="button"
              role="radio"
              aria-checked={isActive}
              aria-label={a.label}
              title={a.label}
              onClick={() => setAccentKey(a.key)}
              className={`ac-accent-swatch${isActive ? ' on' : ''}`}
              style={{ '--swatch-fill': a.fill } as React.CSSProperties}
            >
              {isActive && (
                <IconCheck
                  size={12}
                  strokeWidth={3}
                  className="ac-accent-swatch-check"
                  aria-hidden="true"
                />
              )}
              <span className="sr-only">{a.label}</span>
            </button>
          );
        })}
      </div>

      {/* Active accent label + live preview */}
      <div className="ac-accent-preview-row">
        <span className="ac-accent-cur-label">{accent.label}</span>
        <div className="ac-accent-preview" aria-hidden="true">
          <span
            className="ac-accent-preview-btn"
            style={{ background: previewVars['--color-action-600'], color: previewVars['--color-on-action'] }}
          >
            Preview
          </span>
          <span
            className="ac-accent-preview-link"
            style={{ color: previewVars['--color-brand-700'] }}
          >
            Link text
          </span>
          <span
            className="ac-accent-preview-badge"
            style={{
              background: previewVars['--color-brand-50'],
              color: previewVars['--color-brand-700'],
              borderColor: previewVars['--color-brand-200'] ?? previewVars['--color-brand-100'],
            }}
          >
            Badge
          </span>
        </div>
      </div>

      <p className="ac-accent-note" style={{ marginTop: 10 }}>
        Accent applies across the whole app: buttons, links, active states, and badges.
        Saved on this device only.
      </p>
    </div>
  );
}

/* ── Change-password form (real POST /user/password, all roles) ──────────────
   Succeeding revokes every OTHER session (the current one is kept); we surface
   `revoked_other_sessions` so the message is truthful. 422 field errors are
   shown inline against `current_password` / `password`. */
function ChangePasswordForm({ portal }: { portal: Portal }) {
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [confirm, setConfirm] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (submitting) return;
    setSubmitting(true);
    setErrors({});
    setFormError(null);
    setSuccess(null);
    try {
      const res = await authApi.changePassword(portal, current, next, confirm);
      const revoked = res.revoked_other_sessions;
      setSuccess(
        revoked > 0
          ? `Password updated. ${revoked} other ${
              revoked === 1 ? 'session was' : 'sessions were'
            } signed out — this device stays signed in.`
          : 'Password updated. No other sessions were signed out.',
      );
      setCurrent('');
      setNext('');
      setConfirm('');
    } catch (err) {
      const apiErr = err as ApiError;
      const fields = fieldErrors(apiErr);
      setErrors(fields);
      if (Object.keys(fields).length === 0)
        setFormError(apiErr.message ?? 'Could not update your password. Please try again.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form className="ac-pw-form" onSubmit={onSubmit} noValidate>
      <div className="ac-pw-field">
        <label className="ac-field-lab" htmlFor="cp-current">
          Current password
        </label>
        <input
          id="cp-current"
          className="ac-modal-input"
          type="password"
          autoComplete="current-password"
          value={current}
          onChange={(e) => setCurrent(e.target.value)}
          aria-invalid={!!errors.current_password}
          style={{ marginBottom: errors.current_password ? 4 : 0 }}
        />
        {errors.current_password && <span className="ac-err">{errors.current_password}</span>}
      </div>
      <div className="ac-pw-field">
        <label className="ac-field-lab" htmlFor="cp-next">
          New password
        </label>
        <input
          id="cp-next"
          className="ac-modal-input"
          type="password"
          autoComplete="new-password"
          value={next}
          onChange={(e) => setNext(e.target.value)}
          aria-invalid={!!errors.password}
          style={{ marginBottom: errors.password ? 4 : 0 }}
        />
        {errors.password && <span className="ac-err">{errors.password}</span>}
      </div>
      <div className="ac-pw-field">
        <label className="ac-field-lab" htmlFor="cp-confirm">
          Confirm new password
        </label>
        <input
          id="cp-confirm"
          className="ac-modal-input"
          type="password"
          autoComplete="new-password"
          value={confirm}
          onChange={(e) => setConfirm(e.target.value)}
          style={{ marginBottom: 0 }}
        />
      </div>

      {formError && (
        <div className="ac-err" role="alert" style={{ marginTop: 4 }}>
          {formError}
        </div>
      )}
      {success && (
        <div className="ac-ok" role="status" style={{ marginTop: 4 }}>
          {success}
        </div>
      )}

      <div style={{ marginTop: 4 }}>
        <button
          type="submit"
          className="ac-btn ac-btn-primary ac-btn-sm"
          disabled={submitting || !current || !next || !confirm}
        >
          {submitting ? 'Updating…' : 'Update password'}
        </button>
      </div>
    </form>
  );
}

/* ── page ────────────────────────────────────────────────────────────────── */
export function SettingsPage() {
  const { choice, setChoice } = useTheme();
  const { user, portal, logout } = useAuth();
  const isAdmin = user?.role === 'admin';

  /* Notification preferences — loaded from and saved to the real backend
     (GET/PUT /api/notification-preferences), per notification type. The backend
     403s admins on this endpoint (they have no per-type tenant/landlord alerts),
     so admins never call it — the fetcher short-circuits to an empty map. */
  const prefsQ = useApi<PrefMap>(
    () => (isAdmin ? Promise.resolve<PrefMap>({}) : notificationApi.getPreferences()),
    [isAdmin],
  );
  // Optimistic override layered over the fetched prefs — derived during render
  // (no effect mirroring), so the toggles reflect saves immediately.
  const [override, setOverride] = useState<PrefMap | null>(null);
  const prefs: PrefMap | null = override ?? prefsQ.data ?? null;
  const [saveState, setSaveState] = useState<'idle' | 'saving' | 'saved' | 'error'>('idle');

  async function toggleChannel(type: string, channel: 'email' | 'sms') {
    if (!prefs) return;
    const current = prefs[type] ?? { email: true, sms: false };
    const next: PrefMap = { ...prefs, [type]: { ...current, [channel]: !current[channel] } };
    const previous = prefs;
    setOverride(next);
    setSaveState('saving');
    try {
      await notificationApi.updatePreferences(next);
      setSaveState('saved');
    } catch {
      setOverride(previous); // revert — never claim a save that didn't happen
      setSaveState('error');
    }
  }

  /* Resend the email-verification link (tenant/landlord bearer routes only —
     admins have no email-verification flow and never see the button). */
  const { toast } = useToast();
  const [resendingVerification, setResendingVerification] = useState(false);
  async function resendVerificationEmail() {
    if (resendingVerification || !portal || portal === 'admin') return;
    setResendingVerification(true);
    try {
      const res = await authApi.sendEmailVerification(portal);
      toast(res.message || 'Verification email sent — check your inbox.', 'success');
    } catch (err) {
      toast((err as ApiError).message ?? 'Could not send the verification email.', 'error');
    } finally {
      setResendingVerification(false);
    }
  }

  /* Derive truthful account info from auth context */
  const name         = user ? ('full_name' in user ? user.full_name : user.name) : '—';
  const email        = user?.email ?? '—';
  const role         = user?.role ?? '—';
  const isSuperAdmin = userIsSuperAdmin(user);
  const verified     = !!(user && 'identity_verified' in user && user.identity_verified);
  const memberSince  = user && 'created_at' in user ? formatDate(user.created_at) : '—';

  return (
    <div className="ac-page">
      <div className="ac-card ac-hero">
        <p className="ac-eyebrow">Account</p>
        <h1 className="ac-title">Settings</h1>
        <p className="ac-sub">Control your preferences, notifications, appearance, and security.</p>
      </div>

      <div className="ac-grid">
        <div className="ac-main">

          {/* ── Account overview (truthful, from auth) ── */}
          <div className="ac-card">
            <div className="ac-sec">
              <div className="ac-sec-l">
                <span className="ac-sec-ico"><IconUser size={20} /></span>
                <div>
                  <div className="ac-sec-name">Account</div>
                  <div className="ac-sec-desc">Your current account details.</div>
                </div>
              </div>
              <div className="ac-sec-r">
                <div className="ac-fields" style={{ gridTemplateColumns: 'repeat(2, 1fr)' }}>
                  <div className="ac-field">
                    <div className="ac-field-lab">Name</div>
                    <div className="ac-field-val">{name}</div>
                  </div>
                  <div className="ac-field">
                    <div className="ac-field-lab">Email</div>
                    <div className="ac-field-val">{email}</div>
                  </div>
                  <div className="ac-field">
                    <div className="ac-field-lab">Role</div>
                    <div className="ac-field-val">
                      {isAdmin
                        ? (isSuperAdmin
                            ? <SemanticBadge role="info">Super admin</SemanticBadge>
                            : <SemanticBadge role="neutral">Admin</SemanticBadge>)
                        : <span style={{ textTransform: 'capitalize' }}>{role}</span>}
                    </div>
                  </div>
                  {!isAdmin && (
                    <div className="ac-field">
                      <div className="ac-field-lab">Member since</div>
                      <div className="ac-field-val">{memberSince}</div>
                    </div>
                  )}
                  {/* Identity verification only applies to tenants/landlords —
                      admins have no identity_verified field, so never show it. */}
                  {!isAdmin && (
                    <div className="ac-field">
                      <div className="ac-field-lab">Identity verification</div>
                      <div className="ac-field-val">
                        {verified
                          ? <SemanticBadge role="success">Verified</SemanticBadge>
                          : <SemanticBadge role="warning">Pending</SemanticBadge>}
                      </div>
                    </div>
                  )}
                  {/* Admin-only: active state. Access level now lives in the Role field above. */}
                  {isAdmin && user && 'is_active' in user && (
                    <div className="ac-field">
                      <div className="ac-field-lab">Account</div>
                      <div className="ac-field-val">
                        {user.is_active
                          ? <SemanticBadge role="success">Active</SemanticBadge>
                          : <SemanticBadge role="danger">Inactive</SemanticBadge>}
                      </div>
                    </div>
                  )}
                  {!isAdmin && user && 'account_status' in user && (
                    <div className="ac-field">
                      <div className="ac-field-lab">Account status</div>
                      <div className="ac-field-val">
                        {user.account_status === 'active'
                          ? <SemanticBadge role="success">Active</SemanticBadge>
                          : <SemanticBadge role="danger">{user.account_status ?? 'Unknown'}</SemanticBadge>}
                      </div>
                    </div>
                  )}
                  {user && 'verification_status' in user && (
                    <div className="ac-field">
                      <div className="ac-field-lab">Verification status</div>
                      <div className="ac-field-val">
                        {user.verification_status === 'verified'
                          ? <SemanticBadge role="success">Verified</SemanticBadge>
                          : user.verification_status === 'pending'
                          ? <SemanticBadge role="warning">Pending review</SemanticBadge>
                          : user.verification_status === 'rejected'
                          ? <SemanticBadge role="danger">Rejected</SemanticBadge>
                          : <SemanticBadge role="neutral">{user.verification_status ?? 'Unverified'}</SemanticBadge>}
                      </div>
                    </div>
                  )}
                </div>
                <div style={{ marginTop: 16 }}>
                  <Link to="/app/profile" className="ac-btn ac-btn-ghost ac-btn-sm">
                    Edit profile
                  </Link>
                </div>
              </div>
            </div>
          </div>

          {/* ── Appearance (real localStorage prefs) ── */}
          <div className="ac-card">
            <div className="ac-sec">
              <div className="ac-sec-l">
                <span className="ac-sec-ico"><PaletteIcon size={20} /></span>
                <div>
                  <div className="ac-sec-name">Appearance</div>
                  <div className="ac-sec-desc">
                    Three independent controls: <strong>Appearance</strong> (light / dark / system),
                    a <strong>Dark theme</strong> palette, and an <strong>Accent</strong> colour.
                  </div>
                </div>
              </div>
              <div className="ac-sec-r">
                {/* 1 — Appearance mode */}
                <span className="ac-field-lab" style={{ display: 'block', marginBottom: 8 }}>Appearance</span>
                <div className="ac-theme-grid">
                  {THEMES.map(({ v, label, Icon }) => (
                    <button
                      key={v}
                      type="button"
                      className={`ac-theme-opt${choice === v ? ' on' : ''}`}
                      onClick={() => setChoice(v)}
                      aria-pressed={choice === v}
                    >
                      {choice === v && <IconCheck size={11} strokeWidth={3} className="ac-theme-check" />}
                      <Icon size={20} />
                      <span className="ac-theme-lab">{label}</span>
                    </button>
                  ))}
                </div>
                <p className="ac-accent-note">
                  System follows your device's light or dark setting. Your choice is
                  saved on this device and applies across the whole app.
                </p>

                {/* 2 — Dark theme palette */}
                <div style={{ marginTop: 24, paddingTop: 20, borderTop: '1px solid var(--color-ink-100)' }}>
                  <DarkThemePicker />
                </div>

                {/* 3 — Accent colour */}
                <div style={{ marginTop: 24, paddingTop: 20, borderTop: '1px solid var(--color-ink-100)' }}>
                  <AccentPicker />
                </div>
              </div>
            </div>
          </div>

          {/* ── Notification preferences (real backend, per type) ──
              Admins have no per-type tenant/landlord alert prefs (the backend
              403s them on this endpoint), so instead of fake toggles we show an
              honest note. Tenants/landlords get the real, saved preferences. */}
          {isAdmin ? (
            <div className="ac-card">
              <div className="ac-sec">
                <div className="ac-sec-l">
                  <span className="ac-sec-ico"><IconBell size={20} /></span>
                  <div>
                    <div className="ac-sec-name">Notification preferences</div>
                    <div className="ac-sec-desc">How you're alerted to platform events.</div>
                  </div>
                </div>
                <div className="ac-sec-r">
                  <p className="ac-accent-note" style={{ marginTop: 0 }}>
                    Per-channel alert preferences aren't configurable for admin accounts yet.
                    Platform events still appear in your in-app notifications, and you can monitor
                    email/SMS delivery across all users from the{' '}
                    <Link to="/app/notifications" style={{ color: 'var(--color-brand-700)' }}>
                      Notifications
                    </Link>{' '}
                    page.
                  </p>
                </div>
              </div>
            </div>
          ) : (
          <div className="ac-card">
            <div className="ac-sec">
              <div className="ac-sec-l">
                <span className="ac-sec-ico"><IconBell size={20} /></span>
                <div>
                  <div className="ac-sec-name">Notification preferences</div>
                  <div className="ac-sec-desc">Choose how you're notified for each event.</div>
                </div>
              </div>
              <div className="ac-sec-r">
                {prefsQ.loading && !prefs ? (
                  <LoadingState label="Loading preferences…" />
                ) : prefsQ.error && !prefs ? (
                  <ErrorState
                    title="Couldn't load preferences"
                    message={prefsQ.error.message}
                    onRetry={prefsQ.reload}
                  />
                ) : prefs ? (
                  <>
                    <div className="ac-pref-head" aria-hidden="true">
                      <span className="ac-pref-ch">Email</span>
                      <span className="ac-pref-ch">SMS</span>
                    </div>
                    {NOTIF_TYPES.map((n) => {
                      const p = prefs[n.type] ?? { email: true, sms: false };
                      return (
                        <div key={n.type} className="ac-row">
                          <div className="ac-row-main">
                            <div className="ac-row-name">{n.name}</div>
                            <div className="ac-row-desc">{n.desc}</div>
                          </div>
                          <div className="ac-pref-toggles">
                            <Toggle
                              on={p.email}
                              onChange={() => toggleChannel(n.type, 'email')}
                              label={`${n.name} (email)`}
                            />
                            <Toggle
                              on={p.sms}
                              onChange={() => toggleChannel(n.type, 'sms')}
                              label={`${n.name} (SMS)`}
                            />
                          </div>
                        </div>
                      );
                    })}
                    <div className="ac-save-state" role="status" aria-live="polite">
                      {saveState === 'saving' && <span className="ac-pending">Saving…</span>}
                      {saveState === 'saved' && <span className="ac-ok">All changes saved</span>}
                      {saveState === 'error' && (
                        <span className="ac-err">Couldn't save. Please try again</span>
                      )}
                    </div>
                  </>
                ) : null}
              </div>
            </div>
          </div>
          )}

          {/* ── Privacy (informational only) ── */}
          <div className="ac-card">
            <div className="ac-sec">
              <div className="ac-sec-l">
                <span className="ac-sec-ico"><IconShield size={20} /></span>
                <div>
                  <div className="ac-sec-name">Privacy</div>
                  <div className="ac-sec-desc">Data and communication settings.</div>
                </div>
              </div>
              <div className="ac-sec-r">
                <div className="ac-row">
                  <div className="ac-row-main">
                    <div className="ac-row-name">Data export</div>
                    <div className="ac-row-desc">Request a copy of your personal data</div>
                  </div>
                  <Link to="/app/messages" className="ac-btn ac-btn-ghost ac-btn-sm">
                    Contact support
                  </Link>
                </div>
              </div>
            </div>
          </div>

          {/* ── Security (truthful — only what the platform actually offers) ── */}
          <div className="ac-card">
            <div className="ac-sec">
              <div className="ac-sec-l">
                <span className="ac-sec-ico"><IconLock size={20} /></span>
                <div>
                  <div className="ac-sec-name">Security</div>
                  <div className="ac-sec-desc">Keep your account safe.</div>
                </div>
              </div>
              <div className="ac-sec-r">
                {/* Password change — real POST /user/password (all roles). Changing
                    it signs out every other session; we say so with the real count. */}
                <div className="ac-row ac-row-block">
                  <div className="ac-row-main">
                    <div className="ac-row-name">Change password</div>
                    <div className="ac-row-desc">
                      Updating your password signs out all your other devices.
                    </div>
                  </div>
                </div>
                {portal ? (
                  <ChangePasswordForm portal={portal} />
                ) : (
                  <p className="ac-accent-note" style={{ marginTop: 0 }}>
                    Your session is still loading — please try again in a moment.
                  </p>
                )}

                {/* 2FA — not implemented yet; truthfully say so */}
                <div className="ac-row">
                  <div className="ac-row-main">
                    <div className="ac-row-name">Two-step verification</div>
                    <div className="ac-row-desc">Not available yet.</div>
                  </div>
                  <SemanticBadge role="neutral">Not available</SemanticBadge>
                </div>

                {/* Sign out */}
                <div className="ac-row">
                  <div className="ac-row-main">
                    <div className="ac-row-name">Sign out</div>
                    <div className="ac-row-desc">Sign out of this device</div>
                  </div>
                  <button
                    type="button"
                    className="ac-btn ac-btn-neutral ac-btn-sm"
                    onClick={() => logout()}
                  >
                    Sign out
                  </button>
                </div>
              </div>
            </div>
          </div>

        </div>

        {/* ── Account health band — truthful summary (role-aware) ──
            Admins have no identity-verification lifecycle, so their band reflects
            admin standing (active + access level) instead of a "pending" clock. */}
        {isAdmin ? (
          <div className="ac-card ac-completion">
            <div className="ac-completion-head">
              <IconCheckCircle size={56} style={{ color: 'var(--color-success-600)' }} />
              <p className="ac-prog-text">
                <strong>Admin account active</strong>
                {`You have platform administrator access to ${brand.appName}.`}
              </p>
            </div>
            <ul className="ac-check ac-check-grid">
              <li>
                <IconCheckCircle size={17} className="ac-check-ico ac-ok" />
                Account active
                <span className="ac-check-state ac-ok">Active</span>
              </li>
              <li>
                <IconCheckCircle size={17} className="ac-check-ico ac-ok" />
                Access level
                <span className="ac-check-state ac-ok">
                  {isSuperAdmin ? 'Super admin' : 'Admin'}
                </span>
              </li>
            </ul>
          </div>
        ) : (
          <div className="ac-card ac-completion">
            <div className="ac-completion-head">
              {verified
                ? <IconCheckCircle size={56} style={{ color: 'var(--color-success-600)' }} />
                : <IconClock size={56} style={{ color: 'var(--color-warning-500)' }} />}
              <p className="ac-prog-text">
                <strong>{verified ? 'Identity verified' : 'Verification pending'}</strong>
                {verified
                  ? `Your identity has been confirmed by the ${brand.appName} team.`
                  : 'Complete identity verification to unlock all platform features.'}
              </p>
            </div>
            <ul className="ac-check ac-check-grid">
              {user && 'is_active' in user && (
                <li>
                  {user.is_active
                    ? <IconCheckCircle size={17} className="ac-check-ico ac-ok" />
                    : <IconClock size={17} className="ac-check-ico ac-pending" />}
                  Account active
                  <span className={`ac-check-state ${user.is_active ? 'ac-ok' : 'ac-pending'}`}>
                    {user.is_active ? 'Active' : 'Inactive'}
                  </span>
                </li>
              )}
              <li>
                {verified
                  ? <IconCheckCircle size={17} className="ac-check-ico ac-ok" />
                  : <IconClock size={17} className="ac-check-ico ac-pending" />}
                Identity verification
                <span className={`ac-check-state ${verified ? 'ac-ok' : 'ac-pending'}`}>
                  {verified ? 'Verified' : 'Pending'}
                </span>
              </li>
              {user && 'email_verified_at' in user && (
                <li>
                  {user.email_verified_at
                    ? <IconCheckCircle size={17} className="ac-check-ico ac-ok" />
                    : <IconClock size={17} className="ac-check-ico ac-pending" />}
                  Email confirmed
                  <span className={`ac-check-state ${user.email_verified_at ? 'ac-ok' : 'ac-pending'}`}>
                    {user.email_verified_at ? 'Confirmed' : 'Unconfirmed'}
                  </span>
                  {!user.email_verified_at && (
                    <button
                      type="button"
                      className="ac-btn ac-btn-ghost ac-btn-sm"
                      style={{ marginLeft: 8 }}
                      onClick={() => void resendVerificationEmail()}
                      disabled={resendingVerification}
                    >
                      {resendingVerification ? 'Sending…' : 'Resend verification email'}
                    </button>
                  )}
                </li>
              )}
            </ul>
          </div>
        )}

        {/* ── Support rail ── */}
        <aside className="ac-rail">
          <div className="ac-card">
            <div className="ac-mini-head">
              <span className="ac-mini-title"><HeadphonesIcon size={17} /> Quick support</span>
            </div>
            <Link to="/app/messages" className="ac-row" style={{ textDecoration: 'none' }}>
              <div className="ac-row-main">
                <div className="ac-row-name">Contact support</div>
                <div className="ac-row-desc">Get help from our team</div>
              </div>
              <IconChevronRight size={16} style={{ color: 'var(--color-ink-400)' }} />
            </Link>
          </div>
        </aside>

      </div>
    </div>
  );
}
