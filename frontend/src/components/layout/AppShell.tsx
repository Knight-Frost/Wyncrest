import { useCallback, useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router';
import {
  PanelLeftClose,
  PanelLeftOpen,
  LogOut,
  User as UserIcon,
  Settings as SettingsIcon,
  ChevronsUpDown,
} from 'lucide-react';
import { cn } from '@/lib/cn';
import { useAuth } from '@/context/auth';
import type { AuthUser } from '@/lib/types';
import { useTheme } from '@/context/theme';
import { LogoMark } from '@/components/brand/Logo';
import { brand } from '@/config/brand';
import { ThemeToggle } from '@/components/ui/ThemeToggle';
import { Avatar } from '@/components/ui/Avatar';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { navForRole, mobileNavItems, roleLabel } from '@/routes/nav';
import type { NavItem } from '@/routes/nav';
import { notificationApi } from '@/lib/endpoints';

/* ============================================================================
   SIDE NAVIGATION — self-contained, boundary-locked.
   ----------------------------------------------------------------------------
   Rebuilt from scratch. All styling is co-located in the <style> below (it
   ships in the same module as the markup, so it can NEVER desync from a stale
   stylesheet — the failure mode that plagued earlier attempts). Brand-new
   `nvx-` class names avoid any collision with leftover rules. White-glass
   fallback colors avoid any dependency on design tokens loading.

   The hard boundary: `.nvx-side` is a fixed-width box with `overflow: hidden`.
   The active-route highlight (and everything else) is physically clipped to
   the sidebar — it cannot bleed across the page.
   ============================================================================ */

const COLLAPSE_KEY = 'nexus_nav_collapsed';
const RAIL = 76;   // icon-rail width (px)
const PANEL = 300; // rail + label panel (px)

/* Colors are CSS variables (defined per-theme in editorial.css) so the sidebar
   themes with the rest of the app. Literal fallbacks keep it correct if the
   skin stylesheet hasn't loaded yet. */
const NAV_CSS = `
.nvx-shell { display:flex; align-items:stretch; min-height:100vh; background:var(--nvx-shell-bg,#F6F8FB); }

/* ---- the boundary box ---- */
.nvx-side {
  flex:0 0 auto; width:${PANEL}px;
  position:sticky; top:0; height:100vh;
  overflow:hidden;                 /* HARD clip — nothing escapes the sidebar */
  display:flex; flex-direction:column;
  background:var(--nvx-side-bg,#FFFFFF); border-right:1px solid var(--nvx-border,#E2E8F0);
  transition:width .24s cubic-bezier(.16,1,.3,1);
}
.nvx-side[data-collapsed="true"]{ width:${RAIL}px; }

/* brand */
.nvx-brand { display:flex; align-items:center; height:72px; flex:0 0 auto; }
.nvx-brand-mark { width:${RAIL}px; flex:0 0 auto; display:flex; align-items:center; justify-content:center; }
.nvx-brand-txt { display:flex; flex-direction:column; white-space:nowrap; overflow:hidden; }
.nvx-brand-name { font-family:'Fraunces',Georgia,serif; font-weight:700; font-size:19px; color:var(--nvx-text-strong,#111827); line-height:1; }
.nvx-brand-role { font-family:'IBM Plex Mono',monospace; font-size:10px; letter-spacing:.16em; text-transform:uppercase; color:var(--nvx-muted,#64748B); margin-top:4px; }

/* scrolling nav body */
.nvx-scroll { flex:1 1 auto; min-height:0; overflow-y:auto; overflow-x:hidden; padding:4px 0; }
.nvx-scroll::-webkit-scrollbar { width:0; }
.nvx-grouptitle { margin:0; padding:14px 0 4px ${RAIL + 20}px; font-family:'IBM Plex Mono',monospace; font-size:10px; letter-spacing:.16em; text-transform:uppercase; color:var(--nvx-muted,#64748B); white-space:nowrap; }

.nvx-link { display:flex; align-items:center; height:46px; text-decoration:none; color:var(--nvx-muted,#64748B); position:relative; }
.nvx-link-ico { width:${RAIL}px; flex:0 0 auto; display:flex; align-items:center; justify-content:center; }
.nvx-link-body { flex:1 1 auto; min-width:0; display:flex; align-items:center; justify-content:space-between; gap:8px; padding-right:14px; }
.nvx-link-lab { min-width:0; font-size:14px; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.nvx-link:hover { background:var(--nvx-hover-bg,#F4F7FA); color:var(--nvx-text-strong,#111827); }
.nvx-link.active { background:var(--nvx-active-bg,#E6F2F1); color:var(--nvx-active-text,#096058); }
.nvx-link.active .nvx-link-lab { font-weight:600; }
.nvx-link.active::before { content:''; position:absolute; left:0; top:0; bottom:0; width:3px; background:var(--nvx-accent,#0A7068); }
.nvx-link:focus-visible { outline:2px solid var(--nvx-accent,#0D8278); outline-offset:-2px; }
.nvx-badge { flex:0 0 auto; min-width:20px; height:18px; padding:0 6px; border-radius:999px; background:var(--nvx-accent,#0A7068); color:var(--nvx-on-accent,#FFFFFF); font-family:'IBM Plex Mono',monospace; font-size:10px; font-weight:600; display:inline-flex; align-items:center; justify-content:center; }
.nvx-dot { position:absolute; top:12px; left:44px; width:7px; height:7px; border-radius:50%; background:var(--nvx-accent,#0A7068); border:2px solid var(--nvx-side-bg,#FFFFFF); }

/* footer pinned to the bottom */
.nvx-foot { margin-top:auto; flex:0 0 auto; border-top:1px solid var(--nvx-border,#E2E8F0); padding:10px; display:flex; flex-direction:column; gap:8px; }
.nvx-collapse { display:flex; align-items:center; height:38px; width:100%; border:none; background:none; cursor:pointer; color:var(--nvx-muted,#64748B); border-radius:9px; }
.nvx-collapse:hover { background:var(--nvx-hover-bg,#F4F7FA); color:var(--nvx-text-strong,#111827); }
.nvx-collapse-ico { width:${RAIL}px; flex:0 0 auto; display:flex; align-items:center; justify-content:center; }
.nvx-collapse-lab { font-size:12px; font-weight:600; white-space:nowrap; }
.nvx-collapse:focus-visible { outline:2px solid var(--nvx-accent,#0D8278); outline-offset:-2px; }
.nvx-user { display:flex; align-items:center; }
.nvx-user-av { width:${RAIL}px; flex:0 0 auto; display:flex; align-items:center; justify-content:center; }
.nvx-avatar { width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center; background:var(--nvx-accent,#0A7068); color:var(--nvx-on-accent,#FFFFFF); font-family:'IBM Plex Mono',monospace; font-size:13px; font-weight:600; }
.nvx-user-info { flex:1 1 auto; min-width:0; display:flex; flex-direction:column; white-space:nowrap; overflow:hidden; }
.nvx-user-name { font-size:14px; font-weight:600; color:var(--nvx-text-strong,#111827); overflow:hidden; text-overflow:ellipsis; }
.nvx-user-role { font-size:12px; color:var(--nvx-muted,#64748B); }
.nvx-iconbtn { width:34px; height:34px; flex:0 0 auto; border:none; background:none; cursor:pointer; color:var(--nvx-muted,#64748B); border-radius:8px; display:flex; align-items:center; justify-content:center; }
.nvx-iconbtn:hover { background:var(--nvx-iconbtn-hover,#EEF2F6); color:var(--nvx-text-strong,#111827); }
.nvx-iconbtn:focus-visible { outline:2px solid var(--nvx-accent,#0D8278); outline-offset:-2px; }
.nvx-footrail { display:flex; flex-direction:column; align-items:center; gap:6px; }

/* account trigger (expanded footer) — the whole user block is the menu button */
.nvx-user-btn { width:100%; border:none; background:none; cursor:pointer; text-align:left; border-radius:10px; padding-right:8px; }
.nvx-user-btn:hover { background:var(--nvx-hover-bg,#F4F7FA); }
.nvx-user-btn:focus-visible { outline:2px solid var(--nvx-accent,#0D8278); outline-offset:-2px; }
.nvx-user-chev { flex:0 0 auto; color:var(--nvx-muted,#64748B); }

/* account dropdown — position:fixed to escape the sidebar overflow clip */
.nvx-menu-scrim { position:fixed; inset:0; z-index:60; }
.nvx-menu { position:fixed; z-index:61; width:244px; max-width:calc(100vw - 24px);
  background:var(--nvx-side-bg,#FFFFFF); border:1px solid var(--nvx-border,#E2E8F0);
  border-radius:14px; box-shadow:0 12px 40px rgba(15,23,42,.16); padding:8px; }
.nvx-menu-expanded, .nvx-menu-rail { left:12px; bottom:70px; }
.nvx-menu-mobile { right:12px; top:56px; }
.nvx-menu-head { display:flex; align-items:center; gap:10px; padding:6px 8px 8px; }
.nvx-menu-id { min-width:0; display:flex; flex-direction:column; }
.nvx-menu-name { font-size:14px; font-weight:600; color:var(--nvx-text-strong,#111827); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.nvx-menu-mail { font-size:12px; color:var(--nvx-muted,#64748B); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.nvx-menu-sep { height:1px; background:var(--nvx-border,#E2E8F0); margin:4px 0; }
.nvx-menu-item { display:flex; align-items:center; gap:10px; width:100%; height:40px; padding:0 10px; border:none; background:none; cursor:pointer; border-radius:9px; font-size:14px; font-weight:500; color:var(--nvx-text-strong,#111827); text-align:left; }
.nvx-menu-item:hover { background:var(--nvx-hover-bg,#F4F7FA); }
.nvx-menu-item:focus-visible { outline:2px solid var(--nvx-accent,#0D8278); outline-offset:-2px; }
.nvx-menu-danger { color:var(--color-danger-solid,#C0453B); }
.nvx-menu-danger:hover { background:var(--nvx-danger-hover,#FCEEEC); }

/* avatar-only account trigger — used by the collapsed rail and the mobile bar */
.nvx-avtrigger { width:40px; height:40px; flex:0 0 auto; border:none; background:none; cursor:pointer; border-radius:50%; padding:0; display:flex; align-items:center; justify-content:center; }
.nvx-avtrigger:focus-visible { outline:2px solid var(--nvx-accent,#0D8278); outline-offset:2px; }

/* mobile top-bar action cluster */
.nvx-mtop-actions { margin-left:auto; display:flex; align-items:center; gap:8px; }

/* main content */
.nvx-main { flex:1 1 auto; min-width:0; display:flex; flex-direction:column; }
.nvx-main-inner { flex:1 1 auto; min-width:0; padding:32px; }

/* mobile */
.nvx-mtop { display:none; position:sticky; top:0; z-index:10; height:56px; align-items:center; gap:12px; padding:0 16px; background:var(--nvx-mtop-bg,rgba(246,248,251,.92)); backdrop-filter:blur(12px); border-bottom:1px solid var(--nvx-border,#E2E8F0); }
.nvx-mtop-name { font-family:'Fraunces',Georgia,serif; font-weight:700; font-size:17px; color:var(--nvx-text-strong,#111827); }
.nvx-mbot { display:none; position:fixed; left:0; right:0; bottom:0; z-index:30; height:62px; background:var(--nvx-side-bg,#FFFFFF); border-top:1px solid var(--nvx-border,#E2E8F0); }
.nvx-mbot a { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:3px; text-decoration:none; color:var(--nvx-muted,#64748B); }
.nvx-mbot a.active { color:var(--nvx-accent,#0A7068); }
.nvx-mbot-lab { font-size:9px; font-weight:600; }
@media (max-width:1023px){
  .nvx-side { display:none; }
  .nvx-mtop, .nvx-mbot { display:flex; }
  .nvx-main-inner { padding:20px 16px 84px; }
}
@media (prefers-reduced-motion:reduce){ .nvx-side { transition:none; } }
`;

const COLLAPSED_CLS = ({ isActive }: { isActive: boolean }) => cn('nvx-link', isActive && 'active');

function useCollapsed() {
  const [collapsed, setCollapsed] = useState(() => {
    try { return localStorage.getItem(COLLAPSE_KEY) === '1'; } catch { return false; }
  });
  const toggle = useCallback(() => {
    setCollapsed((c) => {
      const next = !c;
      try { localStorage.setItem(COLLAPSE_KEY, next ? '1' : '0'); } catch { /* ignore */ }
      return next;
    });
  }, []);
  return { collapsed, toggle };
}

function NavRow({ item, collapsed, badge }: { item: NavItem; collapsed: boolean; badge: number }) {
  return (
    <NavLink to={item.to} end={item.end} title={collapsed ? item.label : undefined} className={COLLAPSED_CLS}>
      <span className="nvx-link-ico">{item.icon}</span>
      <span className="nvx-link-body">
        <span className="nvx-link-lab">{item.label}</span>
        {badge > 0 && <span className="nvx-badge">{badge > 99 ? '99+' : badge}</span>}
      </span>
      {collapsed && badge > 0 && <span className="nvx-dot" aria-hidden="true" />}
    </NavLink>
  );
}

/* ----------------------------------------------------------------------------
   ACCOUNT MENU — the single sign-out path, shared by the desktop sidebar and
   the mobile top bar. The dropdown is `position: fixed` so it escapes the
   sidebar's `overflow: hidden` boundary (the same technique `Modal` uses).
   Sign-out routes through a neutral confirmation so it is never an accidental
   one-tap action. Menu items only link where a real destination exists — admins
   have no `/app/profile`, so they see Settings + Sign out only (no dead links).
   -------------------------------------------------------------------------- */
type MenuVariant = 'expanded' | 'rail' | 'mobile';

function AccountMenu({ user, variant }: { user: AuthUser; variant: MenuVariant }) {
  const { logout } = useAuth();
  const { resolved } = useTheme();
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [signingOut, setSigningOut] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const name = 'full_name' in user ? user.full_name : user.name;
  const email = 'email' in user ? user.email : undefined;
  const avatarUrl = 'avatar_url' in user ? user.avatar_url : null;
  const hasProfile = user.role !== 'admin'; // only tenants/landlords have /app/profile

  // Escape closes the menu — matches the app's lightweight dialog conventions.
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open]);

  const go = (to: string) => { setOpen(false); navigate(to); };

  const doSignOut = async () => {
    setSigningOut(true);
    setError(null);
    try {
      await logout();
      navigate('/');
    } catch {
      // Keep the dialog open and tell the truth rather than silently failing.
      setError('Could not sign out. Please try again.');
      setSigningOut(false);
    }
  };

  return (
    <>
      {variant === 'expanded' && (
        <button
          type="button"
          className="nvx-user nvx-user-btn"
          onClick={() => setOpen((o) => !o)}
          aria-haspopup="menu"
          aria-expanded={open}
          aria-label="Account menu"
        >
          <span className="nvx-user-av"><Avatar name={name} src={avatarUrl} className="nvx-avatar" /></span>
          <span className="nvx-user-info">
            <span className="nvx-user-name">{name}</span>
            <span className="nvx-user-role">{roleLabel[user.role]}</span>
          </span>
          <ChevronsUpDown size={16} className="nvx-user-chev" aria-hidden="true" />
        </button>
      )}
      {(variant === 'rail' || variant === 'mobile') && (
        <button
          type="button"
          className="nvx-avtrigger"
          onClick={() => setOpen((o) => !o)}
          aria-haspopup="menu"
          aria-expanded={open}
          aria-label="Account menu"
          title="Account"
        >
          <Avatar name={name} src={avatarUrl} className="nvx-avatar" />
        </button>
      )}

      {/* The overlay + dialog are PORTALED to <body> so they escape the sticky
          sidebar's stacking context (which otherwise traps them below page
          content — breaking outside-click dismiss and occluding the dialog on
          the dashboard). The portal wrapper re-declares BOTH `data-skin` and
          `data-theme` because the editorial design tokens are scoped to the
          compound `[data-skin='editorial'][data-theme='…']` selector, which the
          bare <body> would not match (see editorial.css / index.css:301). */}
      {open && createPortal(
        <div data-skin="editorial" data-theme={resolved}>
          <div className="nvx-menu-scrim" onClick={() => setOpen(false)} aria-hidden="true" />
          <div className={`nvx-menu nvx-menu-${variant}`} role="menu" aria-label="Account">
            <div className="nvx-menu-head">
              <Avatar name={name} src={avatarUrl} className="nvx-avatar" />
              <div className="nvx-menu-id">
                <span className="nvx-menu-name">{name}</span>
                {email && <span className="nvx-menu-mail">{email}</span>}
              </div>
            </div>
            <div className="nvx-menu-sep" />
            {hasProfile && (
              <button type="button" role="menuitem" className="nvx-menu-item" onClick={() => go('/app/profile')}>
                <UserIcon size={17} /> Profile
              </button>
            )}
            <button type="button" role="menuitem" className="nvx-menu-item" onClick={() => go('/app/settings')}>
              <SettingsIcon size={17} /> Settings
            </button>
            <div className="nvx-menu-sep" />
            <button
              type="button"
              role="menuitem"
              className="nvx-menu-item nvx-menu-danger"
              onClick={() => { setOpen(false); setConfirmOpen(true); }}
            >
              <LogOut size={17} /> Sign out
            </button>
          </div>
        </div>,
        document.body,
      )}

      {createPortal(
        <div data-skin="editorial" data-theme={resolved}>
          <ConfirmDialog
            open={confirmOpen}
            onClose={() => { if (!signingOut) { setConfirmOpen(false); setError(null); } }}
            onConfirm={() => void doSignOut()}
            title="Sign out?"
            description={`You'll need to sign in again to access ${brand.appName}.`}
            confirmLabel="Sign out"
            cancelLabel="Cancel"
            loading={signingOut}
            error={error}
          />
        </div>,
        document.body,
      )}
    </>
  );
}

function Sidebar({ collapsed, toggle, unread }: { collapsed: boolean; toggle: () => void; unread: number }) {
  const { user } = useAuth();
  if (!user) return null;

  // Pass the user so capability-gated items (Manage Users & Permissions) are
  // hidden unless a super admin granted access. The API enforces this too.
  const groups = navForRole(user.role, user);
  const badgeFor = (item: NavItem) => (item.to === '/app/notifications' ? unread : item.badge ?? 0);

  return (
    <aside className="nvx-side" data-collapsed={collapsed} aria-label="Primary navigation">
      <div className="nvx-brand">
        <NavLink to="/app" className="nvx-brand-mark" aria-label={`${brand.appName} home`}><LogoMark size={36} /></NavLink>
        {!collapsed && (
          <div className="nvx-brand-txt">
            <span className="nvx-brand-name">{brand.appName}</span>
            <span className="nvx-brand-role">{roleLabel[user.role]}</span>
          </div>
        )}
      </div>

      <div className="nvx-scroll">
        {groups.map((group) => (
          <div key={group.title}>
            {!collapsed && <p className="nvx-grouptitle">{group.title}</p>}
            {collapsed && <div style={{ height: 14 }} aria-hidden="true" />}
            {group.items.map((item) => (
              <NavRow key={item.to} item={item} collapsed={collapsed} badge={badgeFor(item)} />
            ))}
          </div>
        ))}
      </div>

      <div className="nvx-foot">
        <button className="nvx-collapse" onClick={toggle} aria-label={collapsed ? 'Expand navigation' : 'Collapse navigation'} aria-pressed={collapsed}>
          <span className="nvx-collapse-ico">{collapsed ? <PanelLeftOpen size={18} /> : <PanelLeftClose size={18} />}</span>
          {!collapsed && <span className="nvx-collapse-lab">Collapse</span>}
        </button>

        {collapsed ? (
          <div className="nvx-footrail">
            <AccountMenu user={user} variant="rail" />
            <ThemeToggle variant="minimal" className="nvx-iconbtn" />
          </div>
        ) : (
          <>
            <ThemeToggle variant="segmented" className="w-full" />
            <AccountMenu user={user} variant="expanded" />
          </>
        )}
      </div>
    </aside>
  );
}

function MobileBottomNav() {
  const { user } = useAuth();
  if (!user) return null;
  const items = mobileNavItems(user.role, user);
  return (
    <nav className="nvx-mbot" aria-label="Primary mobile navigation">
      {items.map((item) => (
        <NavLink key={item.to} to={item.to} end={item.end} className={({ isActive }) => cn(isActive && 'active')}>
          {item.icon}
          <span className="nvx-mbot-lab">{item.label}</span>
        </NavLink>
      ))}
    </nav>
  );
}

export function AppShell() {
  const location = useLocation();
  const { user } = useAuth();
  const { resolved } = useTheme();
  const { collapsed, toggle } = useCollapsed();
  const [unread, setUnread] = useState(0);

  useEffect(() => {
    if (!user) return;
    let active = true;
    const fetch = () => {
      notificationApi.unreadCount().then((n) => { if (active) setUnread(n); }).catch(() => { /* silent */ });
    };
    fetch();
    const id = setInterval(fetch, 60_000);
    return () => { active = false; clearInterval(id); };
  }, [user]);

  return (
    <div data-skin="editorial" data-theme={resolved} className="nvx-shell">
      <style>{NAV_CSS}</style>

      <Sidebar collapsed={collapsed} toggle={toggle} unread={unread} />

      <div className="nvx-main">
        <header className="nvx-mtop">
          <LogoMark size={28} />
          <span className="nvx-mtop-name">{brand.appName}</span>
          <div className="nvx-mtop-actions">
            <ThemeToggle variant="minimal" className="nvx-iconbtn" />
            {user && <AccountMenu user={user} variant="mobile" />}
          </div>
        </header>
        <main key={location.pathname} className="nvx-main-inner">
          <Outlet />
        </main>
      </div>

      <MobileBottomNav />
    </div>
  );
}
