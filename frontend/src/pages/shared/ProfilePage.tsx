import { useRef, useState } from 'react';
import { Link } from 'react-router';
import { brand } from '@/config/brand';
import { useAuth } from '@/context/auth';
import { useApi } from '@/hooks/useApi';
import { authApi, landlordApi, tenantApi } from '@/lib/endpoints';
import { fieldErrors } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { Donut } from '@/components/ui/charts';
import { useToast } from '@/components/ui/toast';
import { Avatar } from '@/components/ui/Avatar';
import {
  IconUser,
  IconMail,
  IconPhone,
  IconCalendar,
  IconCheck,
  IconCheckCircle,
  IconClock,
  IconDoc,
  IconChevronRight,
  IconUpload,
  IconLock,
} from '@/components/ui/icons';
import { SemanticBadge } from '@/components/cards';
import type { TenantProfile, LandlordProfile, Readiness, ApiError, MediaAsset } from '@/lib/types';
import './account.css';

/* ── helpers ─────────────────────────────────────────────────────────────── */

function initials(name: string): string {
  return name.split(' ').map((p) => p[0]).filter(Boolean).slice(0, 2).join('').toUpperCase();
}

/* ── Avatar uploader ─────────────────────────────────────────────────────── */
function AvatarUploader({
  name,
  currentUrl,
  onUploaded,
  upload,
}: {
  name: string;
  currentUrl?: string | null;
  onUploaded: (asset: MediaAsset) => void;
  upload: (file: File) => Promise<MediaAsset>;
}) {
  const { toast } = useToast();
  const inputRef = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = useState(false);
  const [localUrl, setLocalUrl] = useState<string | null>(null);

  const [imgFailed, setImgFailed] = useState(false);
  const displayUrl = localUrl ?? currentUrl ?? null;
  const showImg = displayUrl !== null && !imgFailed;
  const abbrev = initials(name);

  async function handleFile(file: File) {
    if (uploading) return;
    const ok = file.type.startsWith('image/');
    if (!ok) {
      toast('Please upload a JPEG, PNG, or WebP image.', 'error');
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      toast('Avatar must be under 5 MB.', 'error');
      return;
    }
    // Preview immediately
    const preview = URL.createObjectURL(file);
    setImgFailed(false);
    setLocalUrl(preview);
    setUploading(true);
    try {
      const asset = await upload(file);
      onUploaded(asset);
      // Replace preview with server URL if available
      if (asset.url) setLocalUrl(asset.url);
      toast('Avatar updated', 'success');
    } catch {
      setLocalUrl(null); // revert preview
      toast('Upload failed. Please try again.', 'error');
    } finally {
      setUploading(false);
      if (inputRef.current) inputRef.current.value = '';
      URL.revokeObjectURL(preview);
    }
  }

  return (
    <div className="ac-avatar-uploader">
      <div className="ac-avatar-preview">
        {showImg
          ? <img src={displayUrl!} alt={name} className="ac-avatar-img" onError={() => setImgFailed(true)} />
          : <span className="ac-avatar">{abbrev}</span>
        }
        {uploading && <span className="ac-avatar-spinner" aria-label="Uploading avatar…" />}
      </div>
      <div className="ac-avatar-info">
        <p className="ac-avatar-name">{name}</p>
        <button
          type="button"
          className="ac-btn ac-btn-ghost ac-btn-sm"
          style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
          disabled={uploading}
          onClick={() => inputRef.current?.click()}
        >
          <IconUpload size={14} />
          {uploading ? 'Uploading…' : 'Change photo'}
        </button>
        <p style={{ fontSize: 12, color: 'var(--color-ink-400)', marginTop: 3 }}>
          JPEG, PNG or WebP · max 5 MB
        </p>
      </div>
      <input
        ref={inputRef}
        type="file"
        accept="image/*"
        style={{ display: 'none' }}
        onChange={(e) => { const f = e.target.files?.[0]; if (f) void handleFile(f); }}
        disabled={uploading}
      />
    </div>
  );
}

/* Support icon as inline SVG (not in icons.tsx, keeps component self-contained) */
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

/* ── SecurityRailCard (shared across roles) — password change lives on the
   Settings page (one real implementation, POST /user/password for every
   role); this just makes it easy to find from Profile. ── */
function SecurityRailCard() {
  return (
    <div className="ac-card">
      <div className="ac-mini-row">
        <span className="ac-mini-ico"><IconLock size={20} /></span>
        <div>
          <div className="ac-mini-title" style={{ marginBottom: 4 }}>Security</div>
          <div className="ac-mini-text">Change your password or manage sign-in from Settings.</div>
        </div>
      </div>
      <Link to="/app/settings" className="ac-btn ac-btn-ghost" style={{ marginTop: 14, width: '100%' }}>
        Go to Security settings
      </Link>
    </div>
  );
}

/* ── IdentityCard (shared across roles) ──────────────────────────────────── */
function IdentityCard({
  name, abbrev, email, phone, role, memberSince, verified, avatarUrl,
}: {
  name: string;
  abbrev: string;
  email: string;
  phone: string | null;
  role: string;
  memberSince: string;
  verified: boolean;
  avatarUrl?: string | null;
}) {
  return (
    <div className="ac-card">
      <div className="ac-identity">
        <Avatar name={name} src={avatarUrl} fallback={abbrev} className="ac-avatar" />
        <div className="ac-identity-body">
          <div className="ac-identity-top">
            <div>
              <h2 className="ac-identity-name">{name}</h2>
              <span className="ac-badge tenant" style={{ marginTop: 8 }}>
                <IconUser size={13} /> {role.toUpperCase()}
              </span>
            </div>
            {verified && (
              <SemanticBadge role="success">Identity verified</SemanticBadge>
            )}
          </div>
          <div className="ac-identity-contact">
            <span className="ac-contact-item"><IconMail size={15} /> {email}</span>
            {phone && <span className="ac-contact-item"><IconPhone size={15} /> {phone}</span>}
          </div>
          <div className="ac-identity-meta">
            <div className="ac-meta-item">
              <IconCalendar size={16} className="ac-meta-ico" />
              <div>
                <div className="ac-meta-lab">Member since</div>
                <div className="ac-meta-val">{memberSince}</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

/* ── VerificationCard ────────────────────────────────────────────────────── */
function VerificationCard({ verified, verifyHref }: { verified: boolean; verifyHref: string }) {
  const steps = ['Government ID uploaded', 'Selfie verification', 'Admin review', 'Complete'];
  const current = verified ? 4 : 1;
  return (
    <div className="ac-card">
      <div className="ac-card-head">
        <div>
          <h2 className="ac-card-title">Identity &amp; verification</h2>
          <p className="ac-card-desc">We use secure verification to help keep our community safe.</p>
        </div>
        <SemanticBadge role={verified ? 'success' : 'warning'}>
          {verified ? 'Verified' : 'Pending'}
        </SemanticBadge>
      </div>
      <div className="ac-vsteps">
        {steps.map((label, i) => {
          const done = i < current;
          const cur = i === current;
          return (
            <div key={label} className={`ac-vstep${done ? ' done' : ''}${cur ? ' current' : ''}`}>
              <span className="ac-vnode">
                {done ? <IconCheck size={17} strokeWidth={3} /> : i + 1}
              </span>
              <span className="ac-vlabel">{label}</span>
            </div>
          );
        })}
      </div>
      {!verified && (
        <div className="ac-vactions">
          <Link to={verifyHref} className="ac-btn ac-btn-primary">Continue verification</Link>
        </div>
      )}
    </div>
  );
}

/* ── ReadinessCard (tenants only) ────────────────────────────────────────── */
function ReadinessCard({ readiness }: { readiness: Readiness }) {
  const pct = readiness.percentage;
  return (
    <div className="ac-card ac-completion">
      <div className="ac-completion-head">
        <Donut pct={pct} size={128} label="Complete" />
        <p className="ac-prog-text">
          <strong>{pct >= 80 ? 'Almost there!' : 'Keep going!'}</strong>
          Complete these steps to get the most out of {brand.appName}.
        </p>
      </div>
      <ul className="ac-check ac-check-grid">
        {readiness.items.map((item) => (
          <li key={item.key}>
            {item.complete
              ? <IconCheckCircle size={17} className="ac-check-ico ac-ok" />
              : <IconClock size={17} className="ac-check-ico ac-pending" />}
            {item.label}
            <span className={`ac-check-state ${item.complete ? 'ac-ok' : 'ac-pending'}`}>
              {item.complete ? 'Complete' : 'Pending'}
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}

/* ── TenantProfileForm ───────────────────────────────────────────────────── */
function TenantProfileForm({
  profile,
  onSaved,
}: {
  profile: TenantProfile;
  onSaved: (p: TenantProfile) => void;
}) {
  const { toast } = useToast();

  const [form, setForm] = useState({
    first_name: profile.first_name ?? '',
    last_name: profile.last_name ?? '',
    phone: profile.phone ?? '',
    city: profile.city ?? '',
    date_of_birth: profile.date_of_birth ?? '',
    next_of_kin_name: profile.next_of_kin_name ?? '',
    next_of_kin_phone: profile.next_of_kin_phone ?? '',
    next_of_kin_relationship: profile.next_of_kin_relationship ?? '',
  });
  const [saving, setSaving] = useState(false);
  const [ferrors, setFerrors] = useState<Record<string, string>>({});

  function set(key: keyof typeof form, value: string) {
    setForm((f) => ({ ...f, [key]: value }));
    setFerrors((e) => { const n = { ...e }; delete n[key]; return n; });
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (saving) return;
    setSaving(true);
    setFerrors({});
    try {
      const res = await tenantApi.updateProfile({
        first_name: form.first_name || undefined,
        last_name: form.last_name || undefined,
        phone: form.phone || null,
        city: form.city || null,
        date_of_birth: form.date_of_birth || null,
        next_of_kin_name: form.next_of_kin_name || null,
        next_of_kin_phone: form.next_of_kin_phone || null,
        next_of_kin_relationship: form.next_of_kin_relationship || null,
      });
      onSaved(res.user);
      toast('Profile updated', 'success');
    } catch (err) {
      const fe = fieldErrors(err as ApiError);
      if (Object.keys(fe).length) {
        setFerrors(fe);
      } else {
        toast((err as ApiError).message ?? 'Could not save profile', 'error');
      }
    } finally {
      setSaving(false);
    }
  }

  function field(
    label: string,
    key: keyof typeof form,
    opts?: { type?: string; readOnly?: boolean; hint?: string },
  ) {
    return (
      <div className="ac-field">
        <label className="ac-field-lab" htmlFor={`pf-${key}`}>{label}</label>
        <input
          id={`pf-${key}`}
          type={opts?.type ?? 'text'}
          readOnly={opts?.readOnly}
          value={form[key]}
          onChange={(e) => set(key, e.target.value)}
          style={{
            display: 'block',
            width: '100%',
            marginTop: 4,
            padding: '9px 12px',
            borderRadius: 'var(--radius-xl)',
            border: ferrors[key]
              ? '1.5px solid var(--color-danger-600)'
              : '1px solid var(--color-ink-300)',
            fontSize: 14,
            color: opts?.readOnly ? 'var(--color-ink-500)' : 'var(--color-ink-900)',
            background: opts?.readOnly ? 'var(--color-ink-50)' : 'var(--color-surface)',
            fontFamily: 'var(--font-sans)',
            boxSizing: 'border-box',
          }}
          aria-describedby={ferrors[key] ? `pf-${key}-err` : undefined}
        />
        {opts?.hint && !ferrors[key] && (
          <p style={{ fontSize: 12, color: 'var(--color-ink-400)', marginTop: 3 }}>{opts.hint}</p>
        )}
        {ferrors[key] && (
          <p id={`pf-${key}-err`} role="alert" style={{ fontSize: 12, color: 'var(--color-danger-600)', marginTop: 3 }}>
            {ferrors[key]}
          </p>
        )}
      </div>
    );
  }

  return (
    <form onSubmit={handleSubmit}>
      <div className="ac-card">
        <div className="ac-card-head">
          <h2 className="ac-card-title">Personal details</h2>
          <button
            type="submit"
            className="ac-btn ac-btn-primary ac-btn-sm"
            disabled={saving}
          >
            {saving ? 'Saving…' : 'Save changes'}
          </button>
        </div>

        {/* Read-only identifiers */}
        <div style={{ marginBottom: 20 }}>
          <p className="ac-field-lab" style={{ marginBottom: 4 }}>Email address</p>
          <p style={{ fontSize: 14, fontWeight: 600, color: 'var(--color-ink-700)' }}>
            {profile.email}
            <span className="ac-verified" style={{ marginLeft: 10 }}><IconCheck size={11} /> Read-only</span>
          </p>
          <p style={{ fontSize: 12, color: 'var(--color-ink-400)', marginTop: 2 }}>
            Email cannot be changed here. Contact support if needed.
          </p>
        </div>

        <div className="ac-fields">
          {field('First name', 'first_name')}
          {field('Last name', 'last_name')}
          {field('Phone number', 'phone', { type: 'tel' })}
          {field('City', 'city')}
          {field('Date of birth', 'date_of_birth', { type: 'date', hint: 'YYYY-MM-DD' })}
        </div>

        <div style={{ marginTop: 28, paddingTop: 22, borderTop: '1px solid var(--color-ink-200)' }}>
          <p className="ac-field-lab" style={{ marginBottom: 12, fontWeight: 600, fontSize: 13.5, color: 'var(--color-ink-700)' }}>
            Emergency / next of kin
          </p>
          <div className="ac-fields">
            {field('Full name', 'next_of_kin_name')}
            {field('Phone number', 'next_of_kin_phone', { type: 'tel' })}
            {field('Relationship', 'next_of_kin_relationship')}
          </div>
        </div>
      </div>
    </form>
  );
}

/* ── LandlordProfileForm ─────────────────────────────────────────────────── */
function LandlordProfileForm({
  profile,
  onSaved,
}: {
  profile: LandlordProfile;
  onSaved: (p: LandlordProfile) => void;
}) {
  const { toast } = useToast();

  const [form, setForm] = useState({
    first_name: profile.first_name ?? '',
    last_name: profile.last_name ?? '',
    phone: profile.phone ?? '',
  });
  const [saving, setSaving] = useState(false);
  const [ferrors, setFerrors] = useState<Record<string, string>>({});

  function set(key: keyof typeof form, value: string) {
    setForm((f) => ({ ...f, [key]: value }));
    setFerrors((e) => { const n = { ...e }; delete n[key]; return n; });
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (saving) return;
    setSaving(true);
    setFerrors({});
    try {
      const res = await landlordApi.updateProfile({
        first_name: form.first_name || undefined,
        last_name: form.last_name || undefined,
        phone: form.phone || null,
      });
      onSaved(res.user);
      toast('Profile updated', 'success');
    } catch (err) {
      const fe = fieldErrors(err as ApiError);
      if (Object.keys(fe).length) {
        setFerrors(fe);
      } else {
        toast((err as ApiError).message ?? 'Could not save profile', 'error');
      }
    } finally {
      setSaving(false);
    }
  }

  function field(label: string, key: keyof typeof form, opts?: { type?: string }) {
    return (
      <div className="ac-field">
        <label className="ac-field-lab" htmlFor={`lpf-${key}`}>{label}</label>
        <input
          id={`lpf-${key}`}
          type={opts?.type ?? 'text'}
          value={form[key]}
          onChange={(e) => set(key, e.target.value)}
          style={{
            display: 'block',
            width: '100%',
            marginTop: 4,
            padding: '9px 12px',
            borderRadius: 'var(--radius-xl)',
            border: ferrors[key]
              ? '1.5px solid var(--color-danger-600)'
              : '1px solid var(--color-ink-300)',
            fontSize: 14,
            color: 'var(--color-ink-900)',
            background: 'var(--color-surface)',
            fontFamily: 'var(--font-sans)',
            boxSizing: 'border-box',
          }}
          aria-describedby={ferrors[key] ? `lpf-${key}-err` : undefined}
        />
        {ferrors[key] && (
          <p id={`lpf-${key}-err`} role="alert" style={{ fontSize: 12, color: 'var(--color-danger-600)', marginTop: 3 }}>
            {ferrors[key]}
          </p>
        )}
      </div>
    );
  }

  return (
    <form onSubmit={handleSubmit}>
      <div className="ac-card">
        <div className="ac-card-head">
          <h2 className="ac-card-title">Personal details</h2>
          <button type="submit" className="ac-btn ac-btn-primary ac-btn-sm" disabled={saving}>
            {saving ? 'Saving…' : 'Save changes'}
          </button>
        </div>

        {/* Read-only identifiers */}
        <div style={{ marginBottom: 20 }}>
          <p className="ac-field-lab" style={{ marginBottom: 4 }}>Email address</p>
          <p style={{ fontSize: 14, fontWeight: 600, color: 'var(--color-ink-700)' }}>
            {profile.email}
            <span className="ac-verified" style={{ marginLeft: 10 }}><IconCheck size={11} /> Read-only</span>
          </p>
          <p style={{ fontSize: 12, color: 'var(--color-ink-400)', marginTop: 2 }}>
            Email changes require verification and aren't supported here yet. Contact support if needed.
          </p>
        </div>

        <div className="ac-fields">
          {field('First name', 'first_name')}
          {field('Last name', 'last_name')}
          {field('Phone number', 'phone', { type: 'tel' })}
        </div>
      </div>
    </form>
  );
}

/* ── Landlord profile view (fetches real data) ───────────────────────────── */
function LandlordProfileView() {
  const { data, loading, error, reload } = useApi(() => landlordApi.profile(), []);
  const [liveProfile, setLiveProfile] = useState<LandlordProfile | null>(null);
  const [avatarUrl, setAvatarUrl] = useState<string | null>(null);

  if (loading) {
    return (
      <div className="ac-main">
        <div className="ac-card" style={{ padding: 48, textAlign: 'center', color: 'var(--color-ink-400)', fontSize: 14 }}>
          Loading profile…
        </div>
      </div>
    );
  }

  if (error || !data) {
    return (
      <div className="ac-main">
        <div className="ac-card" style={{ padding: 32 }}>
          <p style={{ color: 'var(--color-danger-600)', fontWeight: 600, marginBottom: 10 }}>
            Could not load profile
          </p>
          <p style={{ color: 'var(--color-ink-500)', fontSize: 14, marginBottom: 16 }}>
            {error?.message ?? 'An unexpected error occurred.'}
          </p>
          <button className="ac-btn ac-btn-ghost" onClick={reload}>Try again</button>
        </div>
      </div>
    );
  }

  const profile = liveProfile ?? data.user;
  const currentAvatar = avatarUrl ?? data.user.avatar_url ?? null;

  function handleSaved(p: LandlordProfile) {
    setLiveProfile(p);
  }

  return (
    <>
      <div className="ac-main">
        <AvatarUploader
          name={profile.full_name}
          currentUrl={currentAvatar}
          upload={landlordApi.uploadAvatar}
          onUploaded={(asset) => { if (asset.url) setAvatarUrl(asset.url); }}
        />
        <IdentityCard
          name={profile.full_name}
          abbrev={profile.initials || initials(profile.full_name)}
          avatarUrl={currentAvatar}
          email={profile.email}
          phone={profile.phone}
          role={profile.user_type}
          memberSince={formatDate(profile.created_at)}
          verified={profile.identity_verified}
        />
        <VerificationCard verified={profile.identity_verified} verifyHref="/app/landlord-verification" />
        <LandlordProfileForm profile={profile} onSaved={handleSaved} />
      </div>

      <aside className="ac-rail">
        <div className="ac-card">
          <div className="ac-mini-row">
            <span className="ac-mini-ico"><HeadphonesIcon size={20} /></span>
            <div>
              <div className="ac-mini-title" style={{ marginBottom: 4 }}>Need help?</div>
              <div className="ac-mini-text">Our support team can help with account questions.</div>
            </div>
          </div>
          <Link to="/app/messages" className="ac-btn ac-btn-ghost" style={{ marginTop: 14, width: '100%' }}>
            Contact support
          </Link>
        </div>

        <SecurityRailCard />
      </aside>
    </>
  );
}

/* ── Tenant profile view (fetches real data) ─────────────────────────────── */
function TenantProfileView() {
  const { data, loading, error, reload } = useApi(() => tenantApi.profile(), []);
  const [liveProfile, setLiveProfile] = useState<TenantProfile | null>(null);
  const [avatarUrl, setAvatarUrl] = useState<string | null>(null);

  if (loading) {
    return (
      <div className="ac-main">
        <div className="ac-card" style={{ padding: 48, textAlign: 'center', color: 'var(--color-ink-400)', fontSize: 14 }}>
          Loading profile…
        </div>
      </div>
    );
  }

  if (error || !data) {
    return (
      <div className="ac-main">
        <div className="ac-card" style={{ padding: 32 }}>
          <p style={{ color: 'var(--color-danger-600)', fontWeight: 600, marginBottom: 10 }}>
            Could not load profile
          </p>
          <p style={{ color: 'var(--color-ink-500)', fontSize: 14, marginBottom: 16 }}>
            {error?.message ?? 'An unexpected error occurred.'}
          </p>
          <button className="ac-btn ac-btn-ghost" onClick={reload}>Try again</button>
        </div>
      </div>
    );
  }

  const profile = liveProfile ?? data.user;
  const readiness = data.readiness;
  // The persisted avatar from the server (until the tenant uploads a new one this
  // session). `avatarUrl` (set on upload) takes precedence over the fetched value.
  const currentAvatar = avatarUrl ?? data.user.avatar_url ?? null;

  function handleSaved(p: TenantProfile) {
    setLiveProfile(p);
  }

  return (
    <>
      <div className="ac-main">
        <AvatarUploader
          name={profile.full_name}
          currentUrl={currentAvatar}
          upload={tenantApi.uploadAvatar}
          onUploaded={(asset) => { if (asset.url) setAvatarUrl(asset.url); }}
        />
        <IdentityCard
          name={profile.full_name}
          abbrev={profile.initials || initials(profile.full_name)}
          avatarUrl={currentAvatar}
          email={profile.email}
          phone={profile.phone}
          role={profile.user_type}
          memberSince={formatDate(profile.created_at)}
          verified={profile.identity_verified}
        />
        <VerificationCard verified={profile.identity_verified} verifyHref="/app/verification" />
        <TenantProfileForm profile={profile} onSaved={handleSaved} />
      </div>

      <ReadinessCard readiness={readiness} />

      <aside className="ac-rail">
        <div className="ac-card">
          <div className="ac-mini-row">
            <span className="ac-mini-ico"><HeadphonesIcon size={20} /></span>
            <div>
              <div className="ac-mini-title" style={{ marginBottom: 4 }}>Need help?</div>
              <div className="ac-mini-text">Our support team is here to help with account questions.</div>
            </div>
          </div>
          <Link to="/app/messages" className="ac-btn ac-btn-ghost" style={{ marginTop: 14, width: '100%' }}>
            Contact support
          </Link>
        </div>

        <div className="ac-card">
          <div className="ac-mini-head">
            <span className="ac-mini-title"><IconDoc size={17} /> Documents</span>
            <Link to="/app/documents" className="ac-link">View all <IconChevronRight size={14} /></Link>
          </div>
          <p className="ac-mini-text" style={{ marginTop: 4 }}>
            Your uploaded documents (ID, proof of income, etc.) are managed on the{' '}
            <Link to="/app/documents" style={{ color: 'var(--color-brand-700)', fontWeight: 600 }}>
              Documents page
            </Link>
            .
          </p>
        </div>

        <SecurityRailCard />
      </aside>
    </>
  );
}

/* ── AdminAccountForm (admins & super admins can edit their own identity) ─── */
function adminFieldStyle(hasError: boolean): React.CSSProperties {
  return {
    display: 'block',
    width: '100%',
    marginTop: 4,
    padding: '9px 12px',
    borderRadius: 'var(--radius-xl)',
    border: hasError ? '1.5px solid var(--color-danger-600)' : '1px solid var(--color-ink-300)',
    fontSize: 14,
    color: 'var(--color-ink-900)',
    background: 'var(--color-surface)',
    fontFamily: 'var(--font-sans)',
    boxSizing: 'border-box',
  };
}

function AdminAccountForm({
  name,
  email,
  phone,
  role,
  memberSince,
  verified,
}: {
  name: string;
  email: string;
  phone: string | null;
  role: string;
  memberSince: string;
  verified: boolean;
}) {
  const { toast } = useToast();
  const { updateUser } = useAuth();
  const [form, setForm] = useState({ name, email });
  const [saving, setSaving] = useState(false);
  const [ferrors, setFerrors] = useState<Record<string, string>>({});

  function set(key: 'name' | 'email', value: string) {
    setForm((f) => ({ ...f, [key]: value }));
    setFerrors((e) => { const n = { ...e }; delete n[key]; return n; });
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (saving) return;
    setSaving(true);
    setFerrors({});
    try {
      const admin = await authApi.adminUpdateProfile({ name: form.name, email: form.email });
      updateUser({ ...admin, role: 'admin' });
      toast('Profile updated', 'success');
    } catch (err) {
      const fe = fieldErrors(err as ApiError);
      if (Object.keys(fe).length) {
        setFerrors(fe);
      } else {
        toast((err as ApiError).message ?? 'Could not save profile', 'error');
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit}>
      <div className="ac-card">
        <div className="ac-card-head">
          <h2 className="ac-card-title">Account details</h2>
          <button type="submit" className="ac-btn ac-btn-primary ac-btn-sm" disabled={saving}>
            {saving ? 'Saving…' : 'Save changes'}
          </button>
        </div>
        <div className="ac-fields">
          <div className="ac-field">
            <label className="ac-field-lab" htmlFor="ap-name">Full name</label>
            <input
              id="ap-name"
              type="text"
              value={form.name}
              onChange={(e) => set('name', e.target.value)}
              style={adminFieldStyle(!!ferrors.name)}
              aria-describedby={ferrors.name ? 'ap-name-err' : undefined}
            />
            {ferrors.name && (
              <p id="ap-name-err" role="alert" style={{ fontSize: 12, color: 'var(--color-danger-600)', marginTop: 3 }}>
                {ferrors.name}
              </p>
            )}
          </div>
          <div className="ac-field">
            <label className="ac-field-lab" htmlFor="ap-email">Email address</label>
            <input
              id="ap-email"
              type="email"
              value={form.email}
              onChange={(e) => set('email', e.target.value)}
              style={adminFieldStyle(!!ferrors.email)}
              aria-describedby={ferrors.email ? 'ap-email-err' : undefined}
            />
            {ferrors.email && (
              <p id="ap-email-err" role="alert" style={{ fontSize: 12, color: 'var(--color-danger-600)', marginTop: 3 }}>
                {ferrors.email}
              </p>
            )}
          </div>
          {phone && (
            <div className="ac-field">
              <div className="ac-field-lab">Phone</div>
              <div className="ac-field-val">{phone}</div>
            </div>
          )}
          <div className="ac-field">
            <div className="ac-field-lab">Role</div>
            <div className="ac-field-val" style={{ textTransform: 'capitalize' }}>{role}</div>
          </div>
          <div className="ac-field">
            <div className="ac-field-lab">Member since</div>
            <div className="ac-field-val">{memberSince}</div>
          </div>
          <div className="ac-field">
            <div className="ac-field-lab">Identity</div>
            <div className="ac-field-val">
              {verified
                ? <SemanticBadge role="success">Verified</SemanticBadge>
                : <SemanticBadge role="neutral">Not verified</SemanticBadge>}
            </div>
          </div>
        </div>
      </div>
    </form>
  );
}

/* ── Admin profile view — admins edit name/email inline (AdminAccountForm);
   they have no phone/avatar (Admin is a separate table with no media
   relation, see App\Models\Admin::toAuthPayload()), so neither is offered. ── */
function AdminProfileView() {
  const { user } = useAuth();
  if (!user) return null;

  const name = 'full_name' in user ? user.full_name : user.name;
  const email = user.email;
  const phone = 'phone' in user ? user.phone ?? null : null;
  const role = user.role;
  const verified = 'identity_verified' in user ? user.identity_verified : false;
  const memberSince = 'created_at' in user ? formatDate(user.created_at) : '—';

  return (
    <div className="ac-main">
      <IdentityCard
        name={name}
        abbrev={initials(name)}
        avatarUrl={'avatar_url' in user ? user.avatar_url : null}
        email={email}
        phone={phone}
        role={role}
        memberSince={memberSince}
        verified={verified}
      />

      <AdminAccountForm
        name={name}
        email={email}
        phone={phone}
        role={role}
        memberSince={memberSince}
        verified={verified}
      />

      <div className="ac-card">
        <div className="ac-mini-row">
          <span className="ac-mini-ico"><HeadphonesIcon size={20} /></span>
          <div>
            <div className="ac-mini-title" style={{ marginBottom: 4 }}>Need help?</div>
            <div className="ac-mini-text">Our support team can help with account questions.</div>
          </div>
        </div>
        <Link to="/app/messages" className="ac-btn ac-btn-ghost" style={{ marginTop: 14, display: 'inline-flex' }}>
          Contact support
        </Link>
      </div>

      <SecurityRailCard />
    </div>
  );
}

/* ── Page shell ──────────────────────────────────────────────────────────── */
export function ProfilePage() {
  const { user } = useAuth();

  return (
    <div className="ac-page">
      <div className="ac-card ac-hero">
        <p className="ac-eyebrow">Account</p>
        <h1 className="ac-title">Profile</h1>
        <p className="ac-sub">Manage your personal details and identity information.</p>
      </div>

      <div className="ac-grid">
        {user?.role === 'tenant' ? <TenantProfileView />
          : user?.role === 'admin' ? <AdminProfileView />
          : <LandlordProfileView />}
      </div>
    </div>
  );
}
