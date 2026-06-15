import { useState } from 'react';
import { Link, useNavigate } from 'react-router';
import { useAuth } from '@/context/auth';
import { fieldErrors } from '@/lib/api';
import { cn } from '@/lib/cn';
import type { ApiError, UserType } from '@/lib/types';
import {
  AuthInput,
  AuthScene,
  Eyebrow,
  FeatureItem,
  FieldLabel,
  FormCard,
  Icons,
  PasswordChecklist,
  PasswordInput,
} from './authParts';

const DIAL_CODES = [
  { code: '+233', label: 'GH' },
  { code: '+234', label: 'NG' },
  { code: '+254', label: 'KE' },
  { code: '+27', label: 'ZA' },
  { code: '+44', label: 'UK' },
  { code: '+1', label: 'US' },
];

const FOOTER = [
  { icon: <Icons.lock width={18} height={18} />, title: 'Your data is protected.', sub: 'We never share your information.' },
  { icon: <Icons.globe width={18} height={18} />, title: 'Built for scale.', sub: 'Available globally, 24/7.' },
  { icon: <Icons.shieldCheck width={18} height={18} />, title: 'Trusted by thousands.', sub: 'Secure. Reliable. Proven.' },
];

function LeftPanel() {
  return (
    <div className="max-w-xl">
      <Eyebrow>Built for trust. Designed for life.</Eyebrow>
      <h1 className="mt-6 font-display text-5xl font-medium leading-[1.05] tracking-tight text-ink-950">
        The unified home for property <span className="italic text-brand-400">rentals.</span>
      </h1>
      <p className="mt-5 max-w-md text-base leading-relaxed text-ink-500">
        Everything you need to find, manage, and grow property rentals in one secure, intelligent
        platform.
      </p>

      <div className="mt-10 space-y-6">
        <FeatureItem
          icon={<Icons.lock />}
          title="Secure by design"
          desc="End-to-end encryption and bank-level security."
        />
        <FeatureItem
          icon={<Icons.badgeCheck />}
          title="Verified & trusted"
          desc="Every listing, every user, thoroughly verified."
        />
        <FeatureItem
          icon={<Icons.person />}
          title="Role-aware access"
          desc="Secure experiences for tenants, landlords, and admins."
        />
      </div>

      <div className="mt-10 flex flex-wrap gap-x-8 gap-y-3 rounded-2xl border border-ink-200/50 bg-canvas/40 px-6 py-4 text-sm text-ink-600">
        <span className="inline-flex items-center gap-2">
          <Icons.shieldCheck width={16} height={16} className="text-brand-400" /> Bank-level Security
        </span>
        <span className="inline-flex items-center gap-2">
          <Icons.doc width={16} height={16} className="text-brand-400" /> Immutable Ledger
        </span>
        <span className="inline-flex items-center gap-2">
          <Icons.badgeCheck width={16} height={16} className="text-brand-400" /> Verified Listings
        </span>
      </div>
    </div>
  );
}

export function Register() {
  const { register } = useAuth();
  const navigate = useNavigate();

  const [form, setForm] = useState({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
  });
  const [dial, setDial] = useState('+233');
  const [userType, setUserType] = useState<UserType>('tenant');
  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);

  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setForm((f) => ({ ...f, [k]: e.target.value }));

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setErrors({});
    setFormError(null);
    try {
      await register({
        ...form,
        phone: form.phone ? `${dial} ${form.phone}` : undefined,
        user_type: userType,
      });
      navigate('/app', { replace: true });
    } catch (err) {
      const apiErr = err as ApiError;
      const fields = fieldErrors(apiErr);
      setErrors(fields);
      if (Object.keys(fields).length === 0) setFormError(apiErr.message);
    } finally {
      setSubmitting(false);
    }
  }

  const roles: { value: UserType; title: string; desc: string }[] = [
    { value: 'tenant', title: 'Tenant', desc: 'Find & rent a home' },
    { value: 'landlord', title: 'Landlord', desc: 'List & manage rentals' },
  ];

  return (
    <AuthScene left={<LeftPanel />} footer={FOOTER} form={
      <FormCard label="Create your account">
        <h1 className="font-display text-3xl font-medium text-ink-950">Create your account</h1>
        <p className="mt-2 text-sm leading-relaxed text-ink-500">
          Join Nexus as a tenant or a landlord and manage everything with confidence.
        </p>

        <form onSubmit={onSubmit} className="mt-7 space-y-5" noValidate>
          {formError && (
            <div className="rounded-xl border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-500" role="alert">
              {formError}
            </div>
          )}

          {/* Role */}
          <div>
            <span className="mb-2.5 block text-xs font-semibold uppercase tracking-[0.12em] text-ink-600">
              I am a…
            </span>
            <div className="grid grid-cols-2 gap-3" role="radiogroup" aria-label="Account type">
              {roles.map((r) => {
                const on = userType === r.value;
                return (
                  <button
                    type="button"
                    key={r.value}
                    role="radio"
                    aria-checked={on}
                    onClick={() => setUserType(r.value)}
                    className={cn(
                      'flex items-center gap-3 rounded-xl border px-4 py-3.5 text-left transition',
                      on
                        ? 'border-brand-600 bg-brand-500/[0.08] ring-1 ring-brand-600'
                        : 'border-ink-200 hover:border-ink-300',
                    )}
                  >
                    <Icons.person width={20} height={20} className={on ? 'text-brand-400' : 'text-ink-500'} />
                    <span>
                      <span className={cn('block text-sm font-semibold', on ? 'text-brand-400' : 'text-ink-900')}>
                        {r.title}
                      </span>
                      <span className="block text-xs text-ink-500">{r.desc}</span>
                    </span>
                  </button>
                );
              })}
            </div>
          </div>

          {/* Names */}
          <div className="grid grid-cols-2 gap-4">
            <label className="block">
              <FieldLabel>First name</FieldLabel>
              <AuthInput value={form.first_name} onChange={set('first_name')} invalid={!!errors.first_name} placeholder="First name" autoComplete="given-name" required />
              {errors.first_name && <span className="mt-1 block text-xs text-danger-500">{errors.first_name}</span>}
            </label>
            <label className="block">
              <FieldLabel>Last name</FieldLabel>
              <AuthInput value={form.last_name} onChange={set('last_name')} invalid={!!errors.last_name} placeholder="Last name" autoComplete="family-name" required />
              {errors.last_name && <span className="mt-1 block text-xs text-danger-500">{errors.last_name}</span>}
            </label>
          </div>

          {/* Email */}
          <label className="block">
            <FieldLabel>Email</FieldLabel>
            <AuthInput type="email" value={form.email} onChange={set('email')} invalid={!!errors.email} placeholder="you@example.com" autoComplete="email" required />
            {errors.email && <span className="mt-1 block text-xs text-danger-500">{errors.email}</span>}
          </label>

          {/* Phone */}
          <label className="block">
            <FieldLabel hint="Optional">Phone</FieldLabel>
            <div className="flex gap-3">
              <div className="relative">
                <select
                  value={dial}
                  onChange={(e) => setDial(e.target.value)}
                  aria-label="Country code"
                  className="h-12 appearance-none rounded-xl border border-ink-200 bg-surface/70 pl-3.5 pr-9 text-sm text-ink-900 transition focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30"
                >
                  {DIAL_CODES.map((d) => (
                    <option key={d.code} value={d.code}>
                      {d.code} {d.label}
                    </option>
                  ))}
                </select>
                <Icons.chevron width={16} height={16} className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-ink-500" />
              </div>
              <AuthInput type="tel" value={form.phone} onChange={set('phone')} invalid={!!errors.phone} placeholder="55 123 4567" autoComplete="tel" className="flex-1" />
            </div>
            {errors.phone && <span className="mt-1 block text-xs text-danger-500">{errors.phone}</span>}
          </label>

          {/* Password */}
          <label className="block">
            <FieldLabel>Password</FieldLabel>
            <PasswordInput value={form.password} onChange={set('password')} invalid={!!errors.password} placeholder="Create a strong password" autoComplete="new-password" />
            {errors.password && <span className="mt-1 block text-xs text-danger-500">{errors.password}</span>}
            <PasswordChecklist value={form.password} />
          </label>

          {/* Confirm */}
          <label className="block">
            <FieldLabel>Confirm password</FieldLabel>
            <PasswordInput value={form.password_confirmation} onChange={set('password_confirmation')} invalid={!!errors.password_confirmation} placeholder="Confirm your password" autoComplete="new-password" />
            {errors.password_confirmation && <span className="mt-1 block text-xs text-danger-500">{errors.password_confirmation}</span>}
          </label>

          <button
            type="submit"
            disabled={submitting}
            className="flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-b from-brand-400 to-brand-600 py-4 text-sm font-semibold text-canvas shadow-[0_12px_30px_-12px_rgba(201,164,91,0.8)] transition hover:brightness-105 disabled:opacity-60"
          >
            {submitting ? 'Creating account…' : 'Create account'}
            {!submitting && <Icons.arrow width={18} height={18} />}
          </button>

          <p className="text-center text-sm text-ink-500">
            Already have an account?{' '}
            <Link to="/login" className="font-semibold text-brand-400 hover:text-brand-800">
              Sign in
            </Link>
          </p>
        </form>
      </FormCard>
    } />
  );
}
