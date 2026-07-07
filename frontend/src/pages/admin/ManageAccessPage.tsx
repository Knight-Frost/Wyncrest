import { useEffect, useMemo, useState } from 'react';
import { fieldErrors } from '@/lib/api';
import { adminApi } from '@/lib/endpoints';
import { timeAgo } from '@/lib/format';
import { useApi } from '@/hooks/useApi';
import { useAuth } from '@/context/auth';
import { isSuperAdmin, adminHasCapability } from '@/lib/permissions';
import type {
  AccessMatrixCapability,
  AccessRolesMatrix,
  AdminCapability,
  AdminTeamMember,
  AdminUserSummary,
  ApiError,
} from '@/lib/types';
import { DetailDrawer } from '@/components/ui/Drawer';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { LoadingState, ErrorState, ForbiddenState } from '@/components/ui/states';
import { help } from '@/lib/helpText';
import { InfoHint } from '@/components/ui/InfoHint';
import './manage-access.css';

/* ---- small helpers ------------------------------------------------------- */

// Categorical role-identity colors (used as --rc for chips/badges/avatars).
// admin/super_admin intentionally reuse the shared theme-aware warning/danger
// tokens (they were already the same literal hexes as this page's --amber and
// --oxblood). tenant/landlord have no shared token equivalent — they're a
// page-local categorical pair, not a status color — so they use light-dark()
// with the same hue lifted for legibility on a dark canvas, same approach as
// the --wm-purple precedent in landlord/maintenance.css.
const ROLE_COLOR: Record<string, string> = {
  tenant: 'light-dark(#23596b, #7dc9da)',
  landlord: 'light-dark(#163c47, #5fa9be)',
  admin: 'var(--color-warning-600)',
  super_admin: 'var(--color-danger-600)',
};

function cssVars(rc: string): React.CSSProperties {
  return { ['--rc' as string]: rc } as React.CSSProperties;
}

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return '?';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

const Check = () => (
  <svg viewBox="0 0 24 24" aria-hidden>
    <path d="M20 6L9 17l-5-5" strokeLinecap="round" strokeLinejoin="round" />
  </svg>
);
const Dash = () => (
  <svg viewBox="0 0 24 24" aria-hidden>
    <path d="M5 12h14" strokeLinecap="round" />
  </svg>
);

/* ---- generic confirm action --------------------------------------------- */

interface ConfirmSpec {
  title: string;
  description?: React.ReactNode;
  confirmLabel: string;
  tone: 'default' | 'danger';
  requireReason: boolean;
  run: (reason?: string) => Promise<void>;
}

/* ========================================================================= */

export function ManageAccessPage() {
  const { user } = useAuth();
  const isSuper = isSuperAdmin(user);
  const currentAdminId = user?.role === 'admin' ? user.id : -1;

  const summary = useApi(() => adminApi.accessSummary(), []);
  const roles = useApi(() => adminApi.accessRoles(), []);
  const team = useApi(() => adminApi.accessTeam(), []);

  const [confirm, setConfirm] = useState<ConfirmSpec | null>(null);
  const [confirmBusy, setConfirmBusy] = useState(false);
  const [confirmError, setConfirmError] = useState<string | null>(null);

  const [inviteOpen, setInviteOpen] = useState(false);
  const [editAdmin, setEditAdmin] = useState<AdminTeamMember | null>(null);
  const [detailMember, setDetailMember] = useState<AdminUserSummary | null>(null);

  function reloadTeam() {
    summary.reload();
    team.reload();
    roles.reload();
  }

  async function runConfirm(reason?: string) {
    if (!confirm) return;
    setConfirmBusy(true);
    setConfirmError(null);
    try {
      await confirm.run(reason);
      setConfirm(null);
      reloadTeam();
    } catch (err) {
      setConfirmError((err as ApiError).message ?? 'Action failed. Please try again.');
    } finally {
      setConfirmBusy(false);
    }
  }

  const assignableCaps: AccessMatrixCapability[] = useMemo(() => {
    if (!roles.data) return [];
    return roles.data.groups
      .filter((g) => !g.readonly)
      .flatMap((g) => g.capabilities);
  }, [roles.data]);

  // An admin without the manage_access capability is 403'd on every endpoint.
  // Show an honest, page-level forbidden state rather than a shell of zeros.
  const forbidden = [summary, roles, team].some((s) => s.error?.status === 403);
  // The "read access" note is only truthful once data has actually loaded.
  const hasReadAccess = !!summary.data;

  if (forbidden) {
    return (
      <div className="wacc">
        <section className="pagehead glass">
          <span className="ph-eyebrow">Access control</span>
          <h1 className="ph-title">
            Users &amp; <span className="it">permissions.</span>
          </h1>
        </section>
        <section className="glass" style={{ padding: '1rem' }}>
          <ForbiddenState
            title="Access control is restricted"
            message="Managing users & permissions requires the “Manage access” capability. Ask a super admin if you need it."
          />
        </section>
      </div>
    );
  }

  return (
    <div className="wacc">
      {/* PAGE HEAD */}
      <section className="pagehead glass">
        <div className="ph-top">
          <div>
            <span className="ph-eyebrow">Access control</span>
            <h1 className="ph-title">
              Users &amp; <span className="it">permissions.</span>{' '}
              <InfoHint text={help.manageAccess} label="About managing access" />
            </h1>
            <p className="ph-sub">
              Who&rsquo;s on Wyncrest, what each role can do, and where a single admin needs a
              little more or less. Every change here is written to the audit log.
            </p>
          </div>
          <div className="ph-controls">
            <button className="wbtn wbtn-glass" onClick={() => exportTeamCsv(team.data ?? [])}>
              Export team (CSV)
            </button>
            {isSuper && (
              <button className="wbtn wbtn-blood" onClick={() => setInviteOpen(true)}>
                + Invite admin
              </button>
            )}
          </div>
        </div>
        {!isSuper && hasReadAccess && (
          <div className="note-banner" style={{ marginTop: '1rem', marginLeft: 0, marginRight: 0 }}>
            You have read access to this page. Managing the admin team (invites, roles, capabilities)
            is limited to super admins.
          </div>
        )}
      </section>

      {/* SUMMARY */}
      <SummaryCards summary={summary} />

      {/* MATRIX */}
      <Matrix roles={roles} />

      {/* ADMIN TEAM */}
      <section className="glass">
        <div className="panel-head">
          <div>
            <h2>Admin team</h2>
            <div className="ph2-sub">
              {summary.data
                ? `${summary.data.super_admins} super · ${summary.data.scoped_admins} scoped · ${summary.data.pending_invites} pending · ${summary.data.deactivated_admins} deactivated`
                : '—'}
            </div>
          </div>
        </div>
        {team.loading ? (
          <div style={{ padding: '1rem 1.4rem' }}>
            <LoadingState label="Loading admin team…" />
          </div>
        ) : team.error ? (
          <div style={{ padding: '1rem 1.4rem' }}>
            <ErrorState message={team.error.message} onRetry={team.reload} />
          </div>
        ) : (
          <div className="rows">
            {(team.data ?? []).map((a) => (
              <AdminRow key={a.id} admin={a} onOpen={() => setEditAdmin(a)} />
            ))}
          </div>
        )}
      </section>

      {/* PLATFORM MEMBERS */}
      <MembersPanel onOpen={(m) => setDetailMember(m)} />

      {/* ---- Invite drawer ---- */}
      <InviteDrawer
        open={inviteOpen}
        caps={assignableCaps}
        onClose={() => setInviteOpen(false)}
        onDone={() => {
          setInviteOpen(false);
          reloadTeam();
        }}
      />

      {/* ---- Admin detail / capability editor drawer ---- */}
      <AdminDetailDrawer
        admin={editAdmin}
        caps={assignableCaps}
        isSuper={isSuper}
        currentAdminId={currentAdminId}
        onClose={() => setEditAdmin(null)}
        onSaved={() => {
          setEditAdmin(null);
          reloadTeam();
        }}
        onConfirm={(spec) => {
          setEditAdmin(null);
          setConfirm(spec);
        }}
      />

      {/* ---- Member lifecycle drawer ---- */}
      <MemberDetailDrawer
        member={detailMember}
        isSuper={isSuper}
        canManage={adminHasCapability(user, 'manage_users')}
        onClose={() => setDetailMember(null)}
        onConfirm={(spec) => {
          setDetailMember(null);
          setConfirm(spec);
        }}
      />

      {/* ---- Generic confirm dialog ---- */}
      <ConfirmDialog
        open={!!confirm}
        onClose={() => {
          setConfirm(null);
          setConfirmError(null);
        }}
        onConfirm={(reason) => runConfirm(reason)}
        title={confirm?.title ?? ''}
        description={confirm?.description}
        confirmLabel={confirm?.confirmLabel ?? 'Confirm'}
        tone={confirm?.tone ?? 'default'}
        loading={confirmBusy}
        error={confirmError}
        reasonField={
          confirm?.requireReason
            ? { label: 'Reason', placeholder: 'Why are you making this change?', required: true }
            : undefined
        }
      />
    </div>
  );
}

/* ========================= Summary ======================================= */

function SummaryCards({ summary }: { summary: ReturnType<typeof useApi<Awaited<ReturnType<typeof adminApi.accessSummary>>>> }) {
  const s = summary.data;
  const cards = [
    { k: 'Total members', c: 'var(--color-ink-900)', v: s?.members_total, d: 'across every role' },
    { k: 'Admin team', c: ROLE_COLOR.super_admin, v: s?.admins, d: s ? `${s.super_admins} super · ${s.scoped_admins} scoped` : '—' },
    { k: 'Landlords', c: ROLE_COLOR.landlord, v: s?.landlords, d: 'property owners' },
    { k: 'Tenants', c: ROLE_COLOR.tenant, v: s?.tenants, d: 'renters & applicants' },
  ];
  return (
    <section className="stats">
      {cards.map((card) => (
        <div className="stat glass" key={card.k}>
          <div className="k">
            <i style={{ background: card.c }} />
            {card.k}
          </div>
          <div className="v">{typeof card.v === 'number' ? card.v : '—'}</div>
          <div className="d">{card.d}</div>
        </div>
      ))}
    </section>
  );
}

/* ========================= Matrix ======================================== */

function Matrix({ roles }: { roles: ReturnType<typeof useApi<AccessRolesMatrix>> }) {
  const data = roles.data;
  return (
    <section className="glass">
      <div className="panel-head">
        <div>
          <h2>
            Roles &amp; permissions <InfoHint text={help.capability} label="About roles and permissions" />
          </h2>
          <div className="ph2-sub">What each role can do · applies to all members of that role</div>
        </div>
      </div>
      {roles.loading ? (
        <div style={{ padding: '1rem 1.4rem' }}>
          <LoadingState label="Loading matrix…" />
        </div>
      ) : roles.error ? (
        <div style={{ padding: '1rem 1.4rem' }}>
          <ErrorState message={roles.error.message} onRetry={roles.reload} />
        </div>
      ) : data ? (
        <>
          <div className="matrix-scroll">
            <table className="matrix">
              <thead>
                <tr>
                  <td className="rolehead" />
                  {data.roles.map((r) => (
                    <td className="rolehead" key={r.id}>
                      <div className="role-chip" style={cssVars(ROLE_COLOR[r.id])}>
                        <span className="rc-dot" style={{ background: ROLE_COLOR[r.id] }} />
                        <span className="rc-name" style={{ color: ROLE_COLOR[r.id] }}>
                          {r.label}
                        </span>
                        <span className="rc-count">
                          {r.member_count} member{r.member_count === 1 ? '' : 's'}
                        </span>
                      </div>
                    </td>
                  ))}
                </tr>
              </thead>
              <tbody>
                {data.groups.map((g) => (
                  <MatrixGroup key={g.group} group={g} roleIds={data.roles.map((r) => r.id)} />
                ))}
              </tbody>
            </table>
          </div>
          <div className="matrix-note">
            {data.note}
          </div>
        </>
      ) : null}
    </section>
  );
}

function MatrixGroup({
  group,
  roleIds,
}: {
  group: AccessRolesMatrix['groups'][number];
  roleIds: string[];
}) {
  return (
    <>
      <tr className="grouprow">
        <td colSpan={roleIds.length + 1}>{group.group}</td>
      </tr>
      {group.capabilities.map((cap) => (
        <tr className="caprow" key={cap.key}>
          <td className="cap">
            {cap.label}
            <small>{cap.description}</small>
          </td>
          {roleIds.map((rid) => {
            const cell = cap.cells[rid as 'tenant' | 'landlord' | 'admin' | 'super_admin'];
            return (
              <td className="cell" key={rid} style={cssVars(ROLE_COLOR[rid])}>
                <span
                  className={`cellmark ${cell.state}`}
                  title={cell.reason ?? undefined}
                  aria-label={`${cap.label}: ${cell.state}`}
                >
                  {cell.state === 'granted' ? <Check /> : cell.state === 'assignable' ? <Check /> : <Dash />}
                </span>
              </td>
            );
          })}
        </tr>
      ))}
    </>
  );
}

/* ========================= Admin row + detail ============================ */

function AdminRow({ admin, onOpen }: { admin: AdminTeamMember; onOpen: () => void }) {
  const color = admin.is_super_admin ? ROLE_COLOR.super_admin : ROLE_COLOR.admin;
  const roleLabel = admin.is_super_admin ? 'Super Admin' : 'Admin';
  return (
    <div className="row" style={cssVars(color)}>
      <div className="avatar" style={{ background: color }}>
        {initials(admin.name)}
      </div>
      <div className="r-id">
        <div className="r-name">{admin.name}</div>
        <div className="r-mail">{admin.email}</div>
      </div>
      <span className="rolebadge" style={cssVars(color)}>
        {roleLabel}
      </span>
      <span className={`statuspill ${admin.status}`}>
        <span className="sd" />
        {admin.status === 'active'
          ? admin.is_super_admin
            ? 'Full access'
            : `${admin.capability_count} capabilit${admin.capability_count === 1 ? 'y' : 'ies'}`
          : admin.status === 'invited'
            ? 'Pending invite'
            : 'Deactivated'}
      </span>
      <div className="r-actions">
        <button className="wbtn wbtn-glass wbtn-sm" onClick={onOpen}>
          Manage
        </button>
      </div>
    </div>
  );
}

function AdminDetailDrawer({
  admin,
  caps,
  isSuper,
  currentAdminId,
  onClose,
  onSaved,
  onConfirm,
}: {
  admin: AdminTeamMember | null;
  caps: AccessMatrixCapability[];
  isSuper: boolean;
  currentAdminId: number;
  onClose: () => void;
  onSaved: () => void;
  onConfirm: (spec: ConfirmSpec) => void;
}) {
  const [selected, setSelected] = useState<AdminCapability[]>([]);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  useEffect(() => {
    setSelected(admin?.capabilities ?? []);
    setReason('');
    setErr(null);
  }, [admin]);

  if (!admin) return null;
  const isSelf = admin.id === currentAdminId;
  const editable = isSuper && !admin.is_super_admin && admin.status !== 'invited';

  function toggle(cap: AdminCapability) {
    setSelected((cur) => (cur.includes(cap) ? cur.filter((c) => c !== cap) : [...cur, cap]));
  }

  async function saveCaps() {
    if (!admin) return;
    setBusy(true);
    setErr(null);
    try {
      await adminApi.updateAdminCapabilities(admin.id, selected, reason);
      onSaved();
    } catch (e) {
      const apiErr = e as ApiError;
      const f = fieldErrors(apiErr);
      setErr(f.reason ?? f.capabilities ?? apiErr.message ?? 'Could not update capabilities.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <DetailDrawer
      open={!!admin}
      onClose={onClose}
      eyebrow={admin.is_super_admin ? 'Super Admin' : 'Admin'}
      title={admin.name}
      description={admin.email}
    >
      <div className="wacc">
        <div className="dl">Profile</div>
        <div className="kvs">
          <div className="kv">
            <div className="k">
              Tier <InfoHint text={admin.is_super_admin ? help.superAdmin : help.scopedAdmin} label="About this tier" />
            </div>
            <div className="v">{admin.is_super_admin ? 'Super Admin (full authority)' : 'Regular admin'}</div>
          </div>
          <div className="kv">
            <div className="k">Status</div>
            <div className="v" style={{ textTransform: 'capitalize' }}>{admin.status}</div>
          </div>
          <div className="kv">
            <div className="k">Last active</div>
            <div className="v">{admin.last_login_at ? timeAgo(admin.last_login_at) : '—'}</div>
          </div>
          <div className="kv">
            <div className="k">Capabilities</div>
            <div className="v">{admin.is_super_admin ? 'All' : admin.capability_count}</div>
          </div>
        </div>

        {/* Capability editor */}
        <div className="dl">Capabilities · what this admin can do</div>
        {admin.is_super_admin ? (
          <div className="note-banner" style={{ margin: '0 0 1rem' }}>
            Super admins hold every capability. Demote to a regular admin to scope access.
          </div>
        ) : (
          <>
            <div className="capgrid">
              {caps.map((cap) => {
                const checked = selected.includes(cap.key as AdminCapability);
                return (
                  <label key={cap.key} className={`capitem ${editable ? '' : 'locked'}`}>
                    <input
                      type="checkbox"
                      checked={checked}
                      disabled={!editable}
                      onChange={() => toggle(cap.key as AdminCapability)}
                    />
                    <span>
                      <span className="cap-name">
                        {cap.label}
                        {!cap.enforced && (
                          <span style={{ color: 'var(--amber)', fontSize: '0.7rem' }}> · not yet enforced</span>
                        )}
                      </span>
                      <span className="cap-desc" style={{ display: 'block' }}>
                        {cap.description}
                      </span>
                    </span>
                  </label>
                );
              })}
            </div>
            {editable && (
              <div style={{ marginTop: '1rem' }}>
                <label className="field-label">Reason (recorded in the audit log)</label>
                <textarea
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  rows={2}
                  placeholder="Why are you changing these capabilities?"
                  style={{
                    width: '100%',
                    border: '1px solid var(--gborder)',
                    borderRadius: 10,
                    padding: '0.6rem 0.8rem',
                    fontFamily: 'var(--sans)',
                    fontSize: '0.86rem',
                    resize: 'vertical',
                  }}
                />
                {err && <div style={{ color: 'var(--danger)', fontSize: '0.8rem', marginTop: '0.4rem' }}>{err}</div>}
                <button
                  className="wbtn wbtn-blood"
                  style={{ marginTop: '0.7rem' }}
                  disabled={busy || reason.trim().length < 5}
                  onClick={saveCaps}
                >
                  {busy ? 'Saving…' : 'Save capabilities'}
                </button>
              </div>
            )}
          </>
        )}

        {/* Team actions (super only) */}
        {isSuper && (
          <>
            <div className="dl" style={{ marginTop: '1.6rem' }}>
              Team actions
            </div>
            <div className="r-actions" style={{ justifyContent: 'flex-start' }}>
              {admin.status === 'invited' && (
                <>
                  <button
                    className="wbtn wbtn-glass wbtn-sm"
                    onClick={async () => {
                      await adminApi.resendAdminInvite(admin.id);
                      onSaved();
                    }}
                  >
                    Resend invite
                  </button>
                  <button
                    className="wbtn wbtn-danger wbtn-sm"
                    onClick={() =>
                      onConfirm({
                        title: 'Revoke invitation',
                        description: `Revoke the pending invite for ${admin.email}? This cannot be undone.`,
                        confirmLabel: 'Revoke invite',
                        tone: 'danger',
                        requireReason: true,
                        run: (r) => adminApi.revokeAdminInvite(admin.id, r ?? '').then(() => undefined),
                      })
                    }
                  >
                    Revoke invite
                  </button>
                </>
              )}

              {admin.status !== 'invited' && !admin.is_super_admin && (
                <button
                  className="wbtn wbtn-glass wbtn-sm"
                  onClick={() =>
                    onConfirm({
                      title: 'Promote to Super Admin',
                      description: `Give ${admin.name} full platform authority? Super admins can manage the whole team.`,
                      confirmLabel: 'Promote to super',
                      tone: 'default',
                      requireReason: true,
                      run: (r) => adminApi.promoteAdminToSuper(admin.id, r ?? '').then(() => undefined),
                    })
                  }
                >
                  Promote to super
                </button>
              )}

              {admin.status !== 'invited' && admin.is_super_admin && (
                <button
                  className="wbtn wbtn-glass wbtn-sm"
                  onClick={() =>
                    onConfirm({
                      title: 'Demote from Super Admin',
                      description: `Remove super-admin authority from ${admin.name}? They become a regular admin with no capabilities until you assign some.`,
                      confirmLabel: 'Demote to regular admin',
                      tone: 'danger',
                      requireReason: true,
                      run: (r) => adminApi.demoteAdminFromSuper(admin.id, r ?? '', []).then(() => undefined),
                    })
                  }
                >
                  Demote from super
                </button>
              )}

              {admin.status !== 'invited' && !isSelf && admin.is_active && (
                <button
                  className="wbtn wbtn-danger wbtn-sm"
                  onClick={() =>
                    onConfirm({
                      title: 'Deactivate admin access',
                      description: `Deactivate console access for ${admin.email}? They will be unable to sign in until reactivated.`,
                      confirmLabel: 'Deactivate access',
                      tone: 'danger',
                      requireReason: true,
                      run: (r) => adminApi.deactivateAdmin(admin.id, r ?? '').then(() => undefined),
                    })
                  }
                >
                  Deactivate access
                </button>
              )}

              {admin.status !== 'invited' && !admin.is_active && (
                <button
                  className="wbtn wbtn-glass wbtn-sm"
                  onClick={async () => {
                    await adminApi.activateAdmin(admin.id);
                    onSaved();
                  }}
                >
                  Reactivate access
                </button>
              )}
            </div>
            {isSelf && (
              <p style={{ fontSize: '0.74rem', color: 'var(--ink-3)', marginTop: '0.6rem' }}>
                This is your own account. You cannot deactivate yourself, and you can only step down as
                super admin while another active super admin remains.
              </p>
            )}
          </>
        )}
      </div>
    </DetailDrawer>
  );
}

/* ========================= Members panel ================================= */

function MembersPanel({ onOpen }: { onOpen: (m: AdminUserSummary) => void }) {
  const [role, setRole] = useState<'all' | 'landlord' | 'tenant'>('all');
  const [status, setStatus] = useState<'all' | 'active' | 'suspended' | 'blocked' | 'archived'>('all');
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');
  const [page, setPage] = useState(1);

  useEffect(() => {
    const t = setTimeout(() => setDebounced(search.trim()), 300);
    return () => clearTimeout(t);
  }, [search]);

  const members = useApi(
    () =>
      adminApi.accessMembers({
        type: role === 'all' ? undefined : role,
        status: status === 'all' ? undefined : status,
        search: debounced || undefined,
        page,
      }),
    [role, status, debounced, page],
  );

  return (
    <section className="glass">
      <div className="panel-head">
        <div>
          <h2>Platform members</h2>
          <div className="ph2-sub">
            {members.data ? `${members.data.total} tenants & landlords` : '—'}
          </div>
        </div>
      </div>

      <div className="toolbar">
        <label className="search">
          <svg viewBox="0 0 24 24" width={15} height={15} style={{ stroke: 'var(--ink-3)', fill: 'none', strokeWidth: 1.8 }}>
            <circle cx="11" cy="11" r="7" />
            <path d="M21 21l-4-4" />
          </svg>
          <input
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
            placeholder="Search name or email…"
          />
        </label>
        <div className="chips">
          {(['all', 'landlord', 'tenant'] as const).map((r) => (
            <button
              key={r}
              className={`chip ${role === r ? 'on' : ''}`}
              onClick={() => {
                setRole(r);
                setPage(1);
              }}
            >
              {r === 'all' ? 'All roles' : r === 'landlord' ? 'Landlords' : 'Tenants'}
            </button>
          ))}
          {(['all', 'active', 'suspended', 'blocked', 'archived'] as const).map((s) => (
            <button
              key={s}
              className={`chip ${status === s ? 'on' : ''}`}
              onClick={() => {
                setStatus(s);
                setPage(1);
              }}
            >
              {s === 'all' ? 'Any status' : s.charAt(0).toUpperCase() + s.slice(1)}
            </button>
          ))}
        </div>
      </div>

      {members.loading ? (
        <div style={{ padding: '1rem 1.4rem' }}>
          <LoadingState label="Loading members…" />
        </div>
      ) : members.error ? (
        <div style={{ padding: '1rem 1.4rem' }}>
          <ErrorState message={members.error.message} onRetry={members.reload} />
        </div>
      ) : (members.data?.data.length ?? 0) === 0 ? (
        <div className="empty">
          <span className="it">No matching members.</span>
          Try another search or filter.
        </div>
      ) : (
        <div className="rows">
          {members.data!.data.map((m) => (
            <MemberRow key={m.id} member={m} onOpen={() => onOpen(m)} />
          ))}
        </div>
      )}

      {members.data && members.data.last_page > 1 && (
        <div style={{ display: 'flex', gap: '0.6rem', alignItems: 'center', justifyContent: 'flex-end', padding: '0 1.4rem 1.2rem' }}>
          <button className="wbtn wbtn-ghost wbtn-sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
            Previous
          </button>
          <span style={{ fontSize: '0.78rem', color: 'var(--ink-3)' }}>
            Page {members.data.current_page} of {members.data.last_page}
          </span>
          <button
            className="wbtn wbtn-ghost wbtn-sm"
            disabled={page >= members.data.last_page}
            onClick={() => setPage((p) => p + 1)}
          >
            Next
          </button>
        </div>
      )}
    </section>
  );
}

function memberStatusLabel(m: AdminUserSummary): { label: string; cls: string } {
  if (m.account_status === 'blocked') return { label: 'Blocked', cls: 'blocked' };
  if (m.account_status === 'archived') return { label: 'Archived', cls: 'archived' };
  if (m.suspended_at) return { label: 'Suspended', cls: 'suspended' };
  if (!m.is_active) return { label: 'Inactive', cls: 'deactivated' };
  return { label: 'Active', cls: 'active' };
}

function MemberRow({ member, onOpen }: { member: AdminUserSummary; onOpen: () => void }) {
  const color = ROLE_COLOR[member.user_type];
  const st = memberStatusLabel(member);
  return (
    <div className="row" style={cssVars(color)}>
      <div className="avatar" style={{ background: color }}>
        {initials(member.full_name || member.email)}
      </div>
      <div className="r-id">
        <div className="r-name">{member.full_name || `${member.first_name} ${member.last_name}`}</div>
        <div className="r-mail">{member.email}</div>
      </div>
      <span className="rolebadge" style={cssVars(color)}>
        {member.user_type}
      </span>
      <span className={`statuspill ${st.cls}`}>
        <span className="sd" />
        {st.label}
      </span>
      <div className="r-actions">
        <button className="wbtn wbtn-glass wbtn-sm" onClick={onOpen}>
          Manage
        </button>
      </div>
    </div>
  );
}

function MemberDetailDrawer({
  member,
  isSuper,
  canManage,
  onClose,
  onConfirm,
}: {
  member: AdminUserSummary | null;
  isSuper: boolean;
  canManage: boolean;
  onClose: () => void;
  onConfirm: (spec: ConfirmSpec) => void;
}) {
  if (!member) return null;
  const st = memberStatusLabel(member);
  const isActive = st.cls === 'active';

  return (
    <DetailDrawer
      open={!!member}
      onClose={onClose}
      eyebrow={member.user_type === 'landlord' ? 'Landlord' : 'Tenant'}
      title={member.full_name || member.email}
      description={member.email}
    >
      <div className="wacc">
        <div className="dl">Account</div>
        <div className="kvs">
          <div className="kv">
            <div className="k">Role</div>
            <div className="v" style={{ textTransform: 'capitalize' }}>{member.user_type}</div>
          </div>
          <div className="kv">
            <div className="k">Status</div>
            <div className="v">{st.label}</div>
          </div>
          <div className="kv">
            <div className="k">Verification</div>
            <div className="v">{member.identity_verified ? 'Verified' : 'Unverified'}</div>
          </div>
          <div className="kv">
            <div className="k">Joined</div>
            <div className="v">{member.created_at ? timeAgo(member.created_at) : '—'}</div>
          </div>
        </div>

        <div className="note-banner" style={{ margin: '0 0 1rem' }}>
          Account type is set at signup and cannot be changed here — a landlord owns properties and
          contracts that a tenant role could not hold.
        </div>

        {canManage ? (
          <>
            <div className="dl">Lifecycle actions</div>
            <div className="r-actions" style={{ justifyContent: 'flex-start' }}>
              {isActive ? (
                <>
                  <button
                    className="wbtn wbtn-ghost wbtn-sm"
                    onClick={() =>
                      onConfirm({
                        title: 'Suspend member',
                        description: `Suspend ${member.email}? They will be signed out and unable to use the platform until restored.`,
                        confirmLabel: 'Suspend',
                        tone: 'danger',
                        requireReason: true,
                        run: (r) => adminApi.suspendUser(member.id, r ?? '').then(() => undefined),
                      })
                    }
                  >
                    Suspend
                  </button>
                  <button
                    className="wbtn wbtn-danger wbtn-sm"
                    onClick={() =>
                      onConfirm({
                        title: 'Block member',
                        description: `Block ${member.email}? Blocking is a strong action that requires manual review to reverse.`,
                        confirmLabel: 'Block',
                        tone: 'danger',
                        requireReason: true,
                        run: (r) => adminApi.blockUser(member.id, r ?? '').then(() => undefined),
                      })
                    }
                  >
                    Block
                  </button>
                  <button
                    className="wbtn wbtn-danger wbtn-sm"
                    onClick={() =>
                      onConfirm({
                        title: 'Archive member',
                        description: `Archive ${member.email}? This soft-deletes the account and removes it from active lists.`,
                        confirmLabel: 'Archive',
                        tone: 'danger',
                        requireReason: true,
                        run: (r) => adminApi.archiveUser(member.id, r ?? '').then(() => undefined),
                      })
                    }
                  >
                    Archive
                  </button>
                </>
              ) : st.cls === 'suspended' ? (
                <button
                  className="wbtn wbtn-glass wbtn-sm"
                  onClick={() =>
                    onConfirm({
                      title: 'Restore member',
                      description: `Restore ${member.email} to active status?`,
                      confirmLabel: 'Restore',
                      tone: 'default',
                      requireReason: false,
                      run: () => adminApi.activateUser(member.id).then(() => undefined),
                    })
                  }
                >
                  Restore
                </button>
              ) : (
                <p style={{ fontSize: '0.78rem', color: 'var(--ink-3)' }}>
                  {st.label} accounts are restored from the full Users page after review.
                </p>
              )}
            </div>
          </>
        ) : (
          <p style={{ fontSize: '0.78rem', color: 'var(--ink-3)' }}>
            You need the “Manage users” capability to change member status.
          </p>
        )}
        {!isSuper && canManage && null}
      </div>
    </DetailDrawer>
  );
}

/* ========================= Invite drawer ================================= */

function InviteDrawer({
  open,
  caps,
  onClose,
  onDone,
}: {
  open: boolean;
  caps: AccessMatrixCapability[];
  onClose: () => void;
  onDone: () => void;
}) {
  const [email, setEmail] = useState('');
  const [name, setName] = useState('');
  const [asSuper, setAsSuper] = useState(false);
  const [selected, setSelected] = useState<AdminCapability[]>([]);
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setEmail('');
      setName('');
      setAsSuper(false);
      setSelected([]);
      setNote('');
      setErrors({});
      setFormError(null);
    }
  }, [open]);

  function toggle(cap: AdminCapability) {
    setSelected((cur) => (cur.includes(cap) ? cur.filter((c) => c !== cap) : [...cur, cap]));
  }

  async function submit() {
    setBusy(true);
    setErrors({});
    setFormError(null);
    try {
      await adminApi.inviteAdmin({
        email: email.trim(),
        name: name.trim() || undefined,
        is_super_admin: asSuper,
        capabilities: asSuper ? undefined : selected,
        note: note.trim() || undefined,
      });
      onDone();
    } catch (e) {
      const apiErr = e as ApiError;
      const f = fieldErrors(apiErr);
      setErrors(f);
      if (Object.keys(f).length === 0) setFormError(apiErr.message ?? 'Could not send the invite.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <DetailDrawer
      open={open}
      onClose={onClose}
      eyebrow="Access control"
      title="Invite an admin"
      description="They’ll receive a secure email link to set a password and activate their account."
    >
      <div className="wacc">
        {formError && (
          <div className="note-banner" style={{ margin: '0 0 1rem', borderColor: 'var(--danger)', color: 'var(--danger)' }}>
            {formError}
          </div>
        )}

        <label className="field-label">Email address</label>
        <input
          type="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          placeholder="name@example.com"
          style={{
            width: '100%',
            border: '1px solid var(--gborder)',
            borderRadius: 10,
            padding: '0.6rem 0.8rem',
            fontFamily: 'var(--sans)',
            fontSize: '0.9rem',
            marginBottom: errors.email ? '0.3rem' : '1rem',
          }}
        />
        {errors.email && <div style={{ color: 'var(--danger)', fontSize: '0.78rem', marginBottom: '0.8rem' }}>{errors.email}</div>}

        <label className="field-label">Name (optional)</label>
        <input
          type="text"
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder="Defaults from the email"
          style={{
            width: '100%',
            border: '1px solid var(--gborder)',
            borderRadius: 10,
            padding: '0.6rem 0.8rem',
            fontFamily: 'var(--sans)',
            fontSize: '0.9rem',
            marginBottom: '1rem',
          }}
        />

        <label className="capitem" style={{ marginBottom: '1rem' }}>
          <input type="checkbox" checked={asSuper} onChange={(e) => setAsSuper(e.target.checked)} />
          <span>
            <span className="cap-name">Make this admin a Super Admin</span>
            <span className="cap-desc" style={{ display: 'block' }}>
              Full platform authority, including managing the admin team. Grant sparingly.
            </span>
          </span>
        </label>

        {!asSuper && (
          <>
            <label className="field-label">Capabilities</label>
            <div className="capgrid" style={{ marginBottom: '1rem' }}>
              {caps.map((cap) => (
                <label key={cap.key} className="capitem">
                  <input
                    type="checkbox"
                    checked={selected.includes(cap.key as AdminCapability)}
                    onChange={() => toggle(cap.key as AdminCapability)}
                  />
                  <span>
                    <span className="cap-name">{cap.label}</span>
                    <span className="cap-desc" style={{ display: 'block' }}>
                      {cap.description}
                    </span>
                  </span>
                </label>
              ))}
            </div>
          </>
        )}

        <label className="field-label">Note (optional)</label>
        <input
          type="text"
          value={note}
          onChange={(e) => setNote(e.target.value)}
          placeholder="Context recorded in the audit log"
          style={{
            width: '100%',
            border: '1px solid var(--gborder)',
            borderRadius: 10,
            padding: '0.6rem 0.8rem',
            fontFamily: 'var(--sans)',
            fontSize: '0.9rem',
            marginBottom: '1.2rem',
          }}
        />

        <button className="wbtn wbtn-blood" disabled={busy || !email.trim()} onClick={submit}>
          {busy ? 'Sending…' : 'Send invite'}
        </button>
      </div>
    </DetailDrawer>
  );
}

/* ========================= CSV export ==================================== */

function exportTeamCsv(team: AdminTeamMember[]) {
  const header = ['name', 'email', 'tier', 'status', 'capabilities', 'last_active'];
  const rows = team.map((a) => [
    a.name,
    a.email,
    a.is_super_admin ? 'super_admin' : 'admin',
    a.status,
    a.is_super_admin ? 'all' : a.capabilities.join('|'),
    a.last_login_at ?? '',
  ]);
  const csv = [header, ...rows]
    .map((r) => r.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(','))
    .join('\n');
  const blob = new Blob([csv], { type: 'text/csv' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'wyncrest-admin-team.csv';
  a.click();
  URL.revokeObjectURL(url);
}
