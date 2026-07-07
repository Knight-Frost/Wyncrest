import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { adminApi } from '@/lib/endpoints';
import { formatDate } from '@/lib/format';
import { ErrorState, Skeleton } from '@/components/ui/states';
import { help } from '@/lib/helpText';
import { InfoHint } from '@/components/ui/InfoHint';
import type { AdminVerificationRequest, VerificationRequestStatus } from '@/lib/types';
import './verification-review.css';
import {
  WVIconSearch,
  WVIconExport,
  WVIconChevron,
  WVIconCheck,
  WVIconWarn,
} from './wverIcons';

type TabKey = 'pending' | 'needs_more_information' | 'approved' | 'rejected' | 'all';
type RoleFilter = '' | 'tenant' | 'landlord';
type SortKey = 'newest' | 'oldest' | 'needs_attention_first';

const TABS: { key: TabKey; label: string }[] = [
  { key: 'pending', label: 'Pending' },
  { key: 'needs_more_information', label: 'Needs info' },
  { key: 'approved', label: 'Verified' },
  { key: 'rejected', label: 'Rejected' },
  { key: 'all', label: 'All' },
];

const EMPTY_COPY: Record<TabKey, string> = {
  pending: 'No verification requests are waiting for review.',
  needs_more_information: 'No requests are currently waiting on additional information.',
  approved: 'No verified requests yet.',
  rejected: 'No rejected requests.',
  all: 'No verification requests found.',
};

function avatarColor(role: 'landlord' | 'tenant'): string {
  return role === 'landlord' ? 'var(--petrol)' : 'var(--slate)';
}

function initials(name: string): string {
  const parts = name.trim().split(/\s+/);
  return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase();
}

export function VerificationsPage() {
  const navigate = useNavigate();
  const [tab, setTab] = useState<TabKey>('pending');
  const [role, setRole] = useState<RoleFilter>('');
  const [search, setSearch] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [sort, setSort] = useState<SortKey>('newest');
  const [page, setPage] = useState(1);

  useEffect(() => {
    const t = setTimeout(() => setSearch(searchInput.trim()), 300);
    return () => clearTimeout(t);
  }, [searchInput]);

  const summaryReq = useApi(() => adminApi.verificationsSummary(), []);

  const apiStatus: VerificationRequestStatus | undefined = tab === 'all' ? undefined : tab;
  const { data, loading, error, reload } = useApi(
    () =>
      adminApi.verifications({
        status: apiStatus,
        role: role || undefined,
        search: search || undefined,
        sort,
        page,
      }),
    [tab, role, search, sort, page],
  );

  const items = data?.data ?? [];
  const currentPage = data?.current_page ?? 1;
  const lastPage = data?.last_page ?? 1;
  const total = data?.total ?? 0;
  const s = summaryReq.data;

  function changeTab(key: TabKey) {
    setTab(key);
    setPage(1);
  }

  function exportCsv() {
    const header = ['id', 'name', 'email', 'phone', 'role', 'status', 'submitted', 'documents'];
    const lines = items.map((vr: AdminVerificationRequest) =>
      [
        vr.id,
        vr.user?.full_name ?? '',
        vr.user?.email ?? '',
        vr.user?.phone ?? '',
        vr.user?.user_type ?? '',
        vr.status,
        vr.submitted_at ?? vr.created_at,
        vr.documents_count ?? 0,
      ]
        .map((c) => `"${String(c).replace(/"/g, '""')}"`)
        .join(','),
    );
    const csv = [header.join(','), ...lines].join('\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    a.download = 'wyncrest-verifications.csv';
    a.click();
    URL.revokeObjectURL(a.href);
  }

  const statCards: { key: TabKey; label: string; value: number | undefined; dot: string; detail: string; help: string }[] = [
    { key: 'pending', label: 'Pending review', value: s?.pending, dot: 'var(--amber)', detail: 'waiting on a decision', help: help.verifPending },
    { key: 'needs_more_information', label: 'Needs info', value: s?.needs_more_information, dot: 'var(--petrol-2)', detail: 'waiting on the user', help: help.verifNeedsInfo },
    { key: 'approved', label: 'Verified', value: s?.verified, dot: 'var(--green)', detail: 'identity confirmed', help: help.verifApproved },
    { key: 'rejected', label: 'Rejected', value: s?.rejected, dot: 'var(--oxblood)', detail: 'failed verification', help: help.verifRejected },
  ];

  return (
    <div className="wver rise">
      <section className="pagehead glass">
        <div className="ph-top">
          <div>
            <span className="ph-eyebrow">Trust &amp; safety</span>
            <h1 className="ph-title">
              Identity <span className="it">verification.</span>
            </h1>
            <p className="ph-sub">
              Review identity requests, submitted documents, and verification decisions for tenants and
              landlords. Open any request to see its full case file.
            </p>
          </div>
          <div className="ph-controls">
            <button type="button" className="btn btn-glass" onClick={exportCsv} disabled={items.length === 0}>
              <WVIconExport />
              Export
            </button>
          </div>
        </div>
      </section>

      <section className="stats">
        {statCards.map((c) => (
          <button
            key={c.key}
            type="button"
            className={`stat glass${tab === c.key ? ' sel' : ''}`}
            style={{ '--sc': c.dot } as React.CSSProperties}
            aria-pressed={tab === c.key}
            onClick={() => changeTab(c.key)}
          >
            <div className="k">
              <i style={{ background: c.dot }} />
              {c.label}
              <InfoHint text={c.help} label={`About ${c.label}`} />
            </div>
            <div className="v">{summaryReq.loading ? '—' : c.value ?? 0}</div>
            <div className="d">{c.detail}</div>
          </button>
        ))}
      </section>

      <section className="glass">
        <div className="panel-head">
          <div>
            <h2>
              Verification requests <InfoHint text={help.verificationQueue} label="About the verification queue" />
            </h2>
            <div className="ph2-sub">
              {loading ? 'Loading…' : `${items.length} shown · ${total} in this view`}
            </div>
          </div>
          <div className="chips" role="tablist" aria-label="Filter verification requests">
            {TABS.map((t) => (
              <button
                key={t.key}
                type="button"
                role="tab"
                aria-selected={tab === t.key}
                className={`chip${tab === t.key ? ' on' : ''}`}
                onClick={() => changeTab(t.key)}
              >
                {t.label}
              </button>
            ))}
          </div>
        </div>

        <div className="toolbar">
          <label className="search">
            <WVIconSearch />
            <input
              type="search"
              placeholder="Search name, email or phone…"
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              aria-label="Search verification requests"
            />
          </label>
          <div className="chips" aria-label="Filter by role">
            {(['', 'tenant', 'landlord'] as RoleFilter[]).map((r) => (
              <button
                key={r || 'all'}
                type="button"
                className={`chip${role === r ? ' on' : ''}`}
                onClick={() => {
                  setRole(r);
                  setPage(1);
                }}
              >
                {r === '' ? 'All roles' : r === 'tenant' ? 'Tenants' : 'Landlords'}
              </button>
            ))}
          </div>
          <select
            className="sel-input"
            value={sort}
            onChange={(e) => {
              setSort(e.target.value as SortKey);
              setPage(1);
            }}
            aria-label="Sort order"
          >
            <option value="newest">Newest first</option>
            <option value="oldest">Oldest first</option>
            <option value="needs_attention_first">Needs attention first</option>
          </select>
        </div>

        {loading && !data ? (
          <div className="vlist">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-20 w-full rounded-2xl" />
            ))}
          </div>
        ) : error ? (
          <div style={{ padding: '1.4rem' }}>
            <ErrorState message={error.message} onRetry={reload} />
          </div>
        ) : items.length === 0 ? (
          <div className="empty">
            <WVIconCheck />
            <span className="it">Nothing here.</span>
            {EMPTY_COPY[tab]}
          </div>
        ) : (
          <div className="vlist" role="list">
            {items.map((vr: AdminVerificationRequest) => {
              const userName = vr.user?.full_name?.trim() || 'Name not provided';
              const isLandlord = vr.user?.user_type === 'landlord';
              const docCount = vr.documents_count ?? 0;
              return (
                <button
                  key={vr.id}
                  type="button"
                  className="vrow"
                  style={{ '--rk': isLandlord ? 'var(--petrol)' : 'var(--slate)' } as React.CSSProperties}
                  onClick={() => navigate(`/app/verifications/${vr.id}`)}
                >
                  <span className="va" style={{ background: avatarColor(isLandlord ? 'landlord' : 'tenant') }}>
                    {initials(userName) || '—'}
                  </span>
                  <span className="vn">
                    <div className="nm">{userName}</div>
                    <div className="ct">{[vr.user?.email, vr.user?.phone].filter(Boolean).join(' · ')}</div>
                    <div className="dd">
                      <span className={`rolechip ${isLandlord ? 'landlord' : 'tenant'}`}>
                        {isLandlord ? 'Landlord' : 'Tenant'}
                      </span>
                      <span>
                        {docCount} document{docCount === 1 ? '' : 's'}
                      </span>
                      {docCount === 0 && (
                        <span className="warn">
                          <WVIconWarn />
                          No documents
                        </span>
                      )}
                    </div>
                  </span>
                  <span className={`statuspill ${vr.status}`}>
                    <span className="sd" />
                    {TABS.find((t) => t.key === vr.status)?.label ?? vr.status}
                  </span>
                  <span className="v-when">{vr.submitted_at ? formatDate(vr.submitted_at) : formatDate(vr.created_at)}</span>
                  <span className="v-chev">
                    <WVIconChevron />
                  </span>
                </button>
              );
            })}
          </div>
        )}

        {lastPage > 1 && (
          <div className="toolbar" style={{ justifyContent: 'space-between' }}>
            <span className="dl">
              Page {currentPage} of {lastPage}
            </span>
            <div className="chips">
              <button
                type="button"
                className="chip"
                disabled={currentPage <= 1 || loading}
                onClick={() => setPage((p) => Math.max(1, p - 1))}
              >
                Previous
              </button>
              <button
                type="button"
                className="chip"
                disabled={currentPage >= lastPage || loading}
                onClick={() => setPage((p) => p + 1)}
              >
                Next
              </button>
            </div>
          </div>
        )}
      </section>
    </div>
  );
}

export default VerificationsPage;
