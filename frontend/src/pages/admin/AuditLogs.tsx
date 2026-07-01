import { useEffect, useRef, useState } from 'react';
import { useApi } from '@/hooks/useApi';
import { adminApi } from '@/lib/endpoints';
import { normalizeError } from '@/lib/api';
import { timeAgo } from '@/lib/format';
import { useToast } from '@/components/ui/toast';
import { brand } from '@/config/brand';
import { Button } from '@/components/ui/Button';
import { Select } from '@/components/ui/Field';
import { ErrorState } from '@/components/ui/states';
import {
  IconShield,
  IconChevronLeft,
  IconChevronRight,
  IconDownload,
  IconSearch,
} from '@/components/ui/icons';
import { AuditTimeline } from './audit/AuditTimeline';
import { rangeForPreset, type AuditFilters, type DatePreset } from './audit/auditFilters';
import type { ApiError } from '@/lib/types';

const AREAS = [
  'Access', 'Users', 'Listings', 'Properties', 'Contracts', 'Ledger',
  'Applications', 'Maintenance', 'Documents', 'Messages', 'Settings', 'System',
];

const PRESET_LABELS: { value: DatePreset; label: string }[] = [
  { value: 'today', label: 'Today' },
  { value: '7d', label: 'Last 7 days' },
  { value: '30d', label: 'Last 30 days' },
  { value: '90d', label: 'Last 90 days' },
  { value: 'all', label: 'All time' },
];

/** Default filter state — defaults to the last 7 days. */
function initialFilters(): AuditFilters {
  return {
    severity: '',
    area: '',
    actor_role: '',
    date_preset: '7d',
    ...rangeForPreset('7d'),
    search: '',
    sort: 'newest',
  };
}

function toApiParams(filters: AuditFilters, page: number, perPage: number) {
  return {
    severity:   filters.severity   || undefined,
    area:       filters.area       || undefined,
    actor_role: filters.actor_role || undefined,
    from_date:  filters.from_date  || undefined,
    to_date:    filters.to_date    || undefined,
    search:     filters.search     || undefined,
    sort:       filters.sort,
    page,
    per_page:   perPage,
  };
}

function toExportParams(filters: AuditFilters) {
  return {
    severity:   filters.severity   || undefined,
    area:       filters.area       || undefined,
    actor_role: filters.actor_role || undefined,
    from_date:  filters.from_date  || undefined,
    to_date:    filters.to_date    || undefined,
    search:     filters.search     || undefined,
    sort:       filters.sort,
  };
}

/** Windowed list of page numbers around the current page (max 5 shown). */
function pageWindow(current: number, last: number): number[] {
  const start = Math.max(1, Math.min(current - 2, last - 4));
  const end = Math.min(last, start + 4);
  const out: number[] = [];
  for (let p = start; p <= end; p++) out.push(p);
  return out;
}

export function AuditLogs() {
  const { toast } = useToast();
  const [filters, setFilters] = useState<AuditFilters>(initialFilters);
  const [searchInput, setSearchInput] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(20);
  const [exporting, setExporting] = useState(false);
  const searchRef = useRef<HTMLInputElement>(null);

  function updateFilters(partial: Partial<AuditFilters>) {
    setFilters((prev) => ({ ...prev, ...partial }));
    setPage(1);
  }

  function onPresetChange(value: DatePreset) {
    updateFilters({ date_preset: value, ...rangeForPreset(value) });
  }

  // Debounce the search box into filters.search
  useEffect(() => {
    const id = setTimeout(() => updateFilters({ search: searchInput.trim() }), 350);
    return () => clearTimeout(id);
  }, [searchInput]);

  // ⌘K / Ctrl+K focuses the search box
  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        searchRef.current?.focus();
      }
    }
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  // Main log list
  const { data, loading, error, reload } = useApi(
    () => adminApi.auditLogs(toApiParams(filters, page, perPage)),
    [filters.severity, filters.area, filters.actor_role, filters.from_date, filters.to_date, filters.search, filters.sort, page, perPage],
  );

  // Headline stats
  const { data: summary } = useApi(() => adminApi.auditSummary(), []);

  // Chain integrity — real SHA-256 recomputation (reload = "Verify integrity")
  const {
    data: verify,
    loading: verifyLoading,
    error: verifyError,
    reload: reVerify,
  } = useApi(() => adminApi.auditVerify(), []);

  const logs = data?.data ?? [];
  const currentPage = data?.current_page ?? 1;
  const lastPage = data?.last_page ?? 1;
  const total = data?.total ?? 0;
  const rangeFrom = total === 0 ? 0 : (currentPage - 1) * perPage + 1;
  const rangeTo = Math.min(currentPage * perPage, total);

  async function handleExport() {
    setExporting(true);
    try {
      await adminApi.auditExport(toExportParams(filters));
      toast('Export downloaded.', 'success');
    } catch (err) {
      const e = normalizeError(err) as ApiError;
      toast(e.message || 'Export failed. Please try again.', 'error');
    } finally {
      setExporting(false);
    }
  }

  const stats = summary?.stats;
  const integrityPct =
    verify && verify.total > 0 ? Math.round((verify.verified / verify.total) * 100) : 100;

  // Banner state (checking → intact → broken/error)
  const bannerClass = verifyLoading
    ? 'au-banner au-banner--checking'
    : verify && !verify.intact
      ? 'au-banner au-banner--broken'
      : verifyError
        ? 'au-banner au-banner--broken'
        : 'au-banner';

  return (
    <div className="audit-center animate-rise space-y-6">
      {/* ── Editorial header ── */}
      <header>
        <div className="flex flex-wrap items-start justify-between gap-x-8 gap-y-5">
          <div className="min-w-0">
            <span className="au-eyebrow">On the record</span>
            <h1 className="au-title">
              Audit <em>log.</em>
            </h1>
            <p className="au-sub">
              Every privileged action on {brand.appName}, written once and never rewritten. Each entry
              is SHA-256 hash-chained to the one before it, so tampering can’t hide.
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <Button
              variant="secondary"
              size="sm"
              leftIcon={<IconDownload size={16} />}
              onClick={handleExport}
              loading={exporting}
              aria-label="Export audit logs as CSV"
            >
              Export
            </Button>
            <button
              type="button"
              onClick={reVerify}
              disabled={verifyLoading}
              className="au-verify inline-flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-medium text-white hover:-translate-y-px disabled:opacity-70"
            >
              <IconShield size={16} className={verifyLoading ? 'au-spin' : undefined} />
              {verifyLoading ? 'Verifying…' : 'Verify integrity'}
            </button>
          </div>
        </div>

        {/* Chain integrity banner — real verification, no theatre */}
        <div className={`${bannerClass} mt-6`}>
          <span className="au-banner__ic" aria-hidden="true">
            <IconShield size={20} className={verifyLoading ? 'au-spin' : undefined} />
          </span>
          <div>
            {verifyLoading ? (
              <>
                <div className="au-banner__t">Verifying chain…</div>
                <div className="au-banner__s">Recomputing SHA-256 links across every record.</div>
              </>
            ) : verifyError ? (
              <>
                <div className="au-banner__t">Couldn’t verify the chain</div>
                <div className="au-banner__s">
                  {verifyError.message}{' '}
                  <button type="button" onClick={reVerify} className="underline">Try again</button>
                </div>
              </>
            ) : verify && !verify.intact ? (
              <>
                <div className="au-banner__t">Chain integrity check failed</div>
                <div className="au-banner__s">
                  Mismatch at event #{verify.broken_at} — {verify.verified} of{' '}
                  {verify.total.toLocaleString()} records verified. Investigate immediately.
                </div>
              </>
            ) : verify ? (
              <>
                <div className="au-banner__t">Chain intact — no tampering detected</div>
                <div className="au-banner__s">
                  Last verified {timeAgo(verify.checked_at)} · {verify.total.toLocaleString()}{' '}
                  {verify.total === 1 ? 'event' : 'events'} on record
                </div>
              </>
            ) : (
              <>
                <div className="au-banner__t">Audit records are append-only</div>
                <div className="au-banner__s">Every entry is written once and hash-chained.</div>
              </>
            )}
          </div>
          {verify?.head && (
            <div className="au-banner__meta">
              head <b>{verify.head.slice(0, 8)}</b>
              <br />
              SHA-256 chained
            </div>
          )}
        </div>
      </header>

      {/* ── Stat strip (all real) ── */}
      <section className="au-stats" aria-label="Audit statistics">
        <div className="au-stat">
          <div className="k"><span className="kd" style={{ background: 'var(--color-brand-600)' }} />Events today</div>
          <div className="v">{(stats?.events_today.value ?? 0).toLocaleString()}</div>
          <div className="d">{stats?.events_today.sub ?? '—'}</div>
        </div>
        <div className="au-stat">
          <div className="k"><span className="kd" style={{ background: 'var(--color-info-500)' }} />Total on record</div>
          <div className="v">{(stats?.total_on_record.value ?? 0).toLocaleString()}</div>
          <div className="d">{stats?.total_on_record.sub ?? '—'}</div>
        </div>
        <div className="au-stat">
          <div className="k">
            <span className="kd" style={{ background: verify && !verify.intact ? 'var(--color-danger-500)' : 'var(--color-success-500)' }} />
            Chain integrity
          </div>
          <div className={`v ${verify && !verify.intact ? 'bad' : 'ok'}`}>
            {verifyLoading && !verify ? '…' : `${integrityPct}%`}
          </div>
          <div className="d">
            {verify ? `${verify.verified.toLocaleString()} / ${verify.total.toLocaleString()} verified` : 'Verifying…'}
          </div>
        </div>
        <div className="au-stat">
          <div className="k"><span className="kd" style={{ background: 'var(--audit-accent)' }} />Actors active · 24h</div>
          <div className="v">{(stats?.actors_active_24h.value ?? 0).toLocaleString()}</div>
          <div className="d">{stats?.actors_active_24h.sub ?? '—'}</div>
        </div>
      </section>

      {/* ── Toolbar: search + selects, then area chips ── */}
      <section className="au-toolbar" aria-label="Filter audit events">
        <div className="au-toolbar__row">
          <label className="au-search">
            <IconSearch size={15} className="shrink-0 text-ink-400" />
            <input
              ref={searchRef}
              type="search"
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              placeholder="Search actor, action, listing, lease…"
              aria-label="Search audit logs"
            />
          </label>
          <Select
            value={filters.severity}
            onChange={(e) => updateFilters({ severity: e.target.value as AuditFilters['severity'] })}
            aria-label="Filter by severity"
            className="w-40"
          >
            <option value="">All severities</option>
            <option value="critical">Critical</option>
            <option value="warning">Warning</option>
            <option value="info">Info</option>
          </Select>
          <Select
            value={filters.actor_role}
            onChange={(e) => updateFilters({ actor_role: e.target.value as AuditFilters['actor_role'] })}
            aria-label="Filter by actor type"
            className="w-36"
          >
            <option value="">All actors</option>
            <option value="admin">Admins</option>
            <option value="landlord">Landlords</option>
            <option value="tenant">Tenants</option>
            <option value="user">Users</option>
            <option value="system">System</option>
          </Select>
          <Select
            value={filters.date_preset}
            onChange={(e) => onPresetChange(e.target.value as DatePreset)}
            aria-label="Filter by date range"
            className="w-40"
          >
            {PRESET_LABELS.map((p) => (
              <option key={p.value} value={p.value}>{p.label}</option>
            ))}
          </Select>
        </div>

        <div className="au-chips" role="group" aria-label="Filter by area">
          <button
            type="button"
            className={`au-chip ${filters.area === '' ? 'on' : ''}`}
            aria-pressed={filters.area === ''}
            onClick={() => updateFilters({ area: '' })}
          >
            All
          </button>
          {AREAS.map((a) => (
            <button
              key={a}
              type="button"
              className={`au-chip ${filters.area === a ? 'on' : ''}`}
              aria-pressed={filters.area === a}
              onClick={() => updateFilters({ area: a })}
            >
              {a}
            </button>
          ))}
        </div>
      </section>

      {/* ── Timeline + pagination ── */}
      <section aria-label="Audit event log">
        {error ? (
          <ErrorState message={error.message} onRetry={reload} />
        ) : (
          <>
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
              <p className="text-sm text-ink-500">
                {loading ? (
                  'Loading…'
                ) : (
                  <>
                    <strong className="text-ink-900">{total.toLocaleString()}</strong>{' '}
                    {total === 1 ? 'event' : 'events'} found
                  </>
                )}
              </p>
              <div className="flex items-center gap-4 text-xs text-ink-500">
                <span className="inline-flex items-center gap-1.5">
                  <span className="h-2 w-2 rounded-full bg-success-500" /> Routine
                </span>
                <span className="inline-flex items-center gap-1.5">
                  <span className="h-2 w-2 rounded-full bg-warning-500" /> Review suggested
                </span>
                <span className="inline-flex items-center gap-1.5">
                  <span className="h-2 w-2 rounded-full bg-danger-500" /> Needs review
                </span>
              </div>
            </div>

            <AuditTimeline logs={logs} loading={loading} onClearFilters={() => { setFilters(initialFilters()); setSearchInput(''); setPage(1); }} />

            {!loading && total > 0 && (
              <div className="mt-4 flex flex-wrap items-center justify-between gap-4">
                <p className="text-sm text-ink-500">
                  Showing <strong className="text-ink-800">{rangeFrom.toLocaleString()}</strong> to{' '}
                  <strong className="text-ink-800">{rangeTo.toLocaleString()}</strong> of{' '}
                  <strong className="text-ink-800">{total.toLocaleString()}</strong> events
                </p>

                <div className="flex items-center gap-3">
                  {lastPage > 1 && (
                    <div className="flex items-center gap-1">
                      <button
                        type="button"
                        className="grid h-8 w-8 place-items-center rounded-lg border border-ink-200 text-ink-500 transition-colors hover:bg-ink-50 disabled:opacity-40"
                        disabled={currentPage <= 1}
                        onClick={() => setPage((p) => Math.max(1, p - 1))}
                        aria-label="Previous page"
                      >
                        <IconChevronLeft size={16} />
                      </button>
                      {pageWindow(currentPage, lastPage).map((p) => (
                        <button
                          key={p}
                          type="button"
                          onClick={() => setPage(p)}
                          aria-current={p === currentPage ? 'page' : undefined}
                          className={[
                            'grid h-8 min-w-8 place-items-center rounded-lg border px-2 text-sm font-medium transition-colors',
                            p === currentPage
                              ? 'border-brand-600 bg-brand-600 text-white'
                              : 'border-ink-200 text-ink-600 hover:bg-ink-50',
                          ].join(' ')}
                        >
                          {p}
                        </button>
                      ))}
                      <button
                        type="button"
                        className="grid h-8 w-8 place-items-center rounded-lg border border-ink-200 text-ink-500 transition-colors hover:bg-ink-50 disabled:opacity-40"
                        disabled={currentPage >= lastPage}
                        onClick={() => setPage((p) => p + 1)}
                        aria-label="Next page"
                      >
                        <IconChevronRight size={16} />
                      </button>
                    </div>
                  )}

                  <Select
                    value={String(perPage)}
                    onChange={(e) => {
                      setPerPage(Number(e.target.value));
                      setPage(1);
                    }}
                    aria-label="Rows per page"
                    className="w-32"
                  >
                    <option value="20">20 / page</option>
                    <option value="50">50 / page</option>
                    <option value="100">100 / page</option>
                  </Select>
                </div>
              </div>
            )}
          </>
        )}
      </section>
    </div>
  );
}
