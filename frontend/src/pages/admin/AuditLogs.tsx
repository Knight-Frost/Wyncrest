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
import { Tooltip } from '@/components/ui/Tooltip';
import { help } from '@/lib/helpText';
import { InfoHint } from '@/components/ui/InfoHint';
import {
  IconShield,
  IconChevronLeft,
  IconChevronRight,
  IconDownload,
  IconSearch,
  IconCheckCircle,
  IconAlertTriangle,
  IconAlertCircle,
  IconInbox,
} from '@/components/ui/icons';
import { AuditTimeline } from './audit/AuditTimeline';
import { rangeForPreset, type AuditFilters, type DatePreset } from './audit/auditFilters';
import type { ApiError, AuditVerify } from '@/lib/types';

/**
 * Everything the chain banner needs to render, boiled down from the real
 * verify response. Kept in one place so the JSX below never re-derives
 * banner meaning from raw fields — it only switches on `kind`.
 */
type BannerContent = {
  kind: 'loading' | 'healthy' | 'warning' | 'broken' | 'empty' | 'error';
  title: string;
  subtitle: string;
};

function bannerContent(
  verify: AuditVerify | null | undefined,
  verifyLoading: boolean,
  verifyError: ApiError | null,
): BannerContent {
  if (verifyLoading && !verify) {
    return {
      kind: 'loading',
      title: 'Checking audit chain integrity…',
      subtitle: 'Recomputing the hash chain across every recorded event.',
    };
  }
  if (verifyError) {
    return {
      kind: 'error',
      title: 'Could not check the audit chain',
      subtitle: verifyError.message || 'The verification request failed. Try again.',
    };
  }
  if (!verify) {
    return {
      kind: 'loading',
      title: 'Checking audit chain integrity…',
      subtitle: 'Recomputing the hash chain across every recorded event.',
    };
  }
  if (verify.status === 'empty') {
    return {
      kind: 'empty',
      title: 'No audit events yet',
      subtitle: 'Nothing to verify yet. Events will appear here as they happen.',
    };
  }
  if (verify.status === 'warning') {
    return {
      kind: 'warning',
      title: 'Chain could not be fully verified',
      subtitle: verify.message,
    };
  }
  if (verify.status === 'broken') {
    return {
      kind: 'broken',
      title: 'Audit chain verification failed',
      subtitle: verify.message,
    };
  }
  return {
    kind: 'healthy',
    title: 'Audit chain verified',
    subtitle: `No broken links found across ${verify.checked_count.toLocaleString()} recorded ${
      verify.checked_count === 1 ? 'event' : 'events'
    }.`,
  };
}

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
    verify && verify.total_count > 0
      ? Math.round((verify.checked_count / verify.total_count) * 100)
      : 100;

  // Banner state — one real status from the backend, never guessed on the frontend.
  const banner = bannerContent(verify, verifyLoading, verifyError);
  const bannerClass = `au-banner au-banner--${banner.kind}`;

  return (
    <div className="audit-center animate-rise space-y-6">
      {/* ── Editorial header ── */}
      <header>
        <div className="au-hero glass-panel">
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
        </div>

        {/* Chain integrity banner — one real status from the backend */}
        <div className={`${bannerClass} mt-6`} role="status">
          <Tooltip content="Each audit event links to the previous one. If someone changes old records, the chain breaks.">
            <span className="au-banner__ic" tabIndex={0} aria-label="What is the audit chain?">
              {banner.kind === 'loading' && <IconShield size={20} className="au-spin" />}
              {banner.kind === 'healthy' && <IconCheckCircle size={20} />}
              {banner.kind === 'warning' && <IconAlertTriangle size={20} />}
              {(banner.kind === 'broken' || banner.kind === 'error') && <IconAlertCircle size={20} />}
              {banner.kind === 'empty' && <IconInbox size={20} />}
            </span>
          </Tooltip>
          <div className="min-w-0">
            <div className="au-banner__t">{banner.title}</div>
            <div className="au-banner__s">
              {banner.subtitle}
              {(banner.kind === 'error' || banner.kind === 'warning' || banner.kind === 'broken') && (
                <>
                  {' '}
                  <button type="button" onClick={reVerify} className="underline">
                    Verify again
                  </button>
                </>
              )}
            </div>
          </div>
          {verify && (banner.kind === 'healthy' || banner.kind === 'warning' || banner.kind === 'broken') && (
            <div className="au-banner__facts">
              <div>
                <Tooltip content="The last time the system verified the audit log integrity.">
                  <span className="au-banner__term" tabIndex={0}>Last checked</span>
                </Tooltip>
                : {timeAgo(verify.verified_at)}
              </div>
              <div>
                <Tooltip content={`A secure hashing method (${verify.algorithm}) used to detect changes.`}>
                  <span className="au-banner__term" tabIndex={0}>Protection</span>
                </Tooltip>
                : {verify.algorithm} hash chain
              </div>
              {verify.latest_event_id !== null && (
                <div>Latest event: #{verify.latest_event_id}</div>
              )}
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
          <div className="k">
            <span className="kd" style={{ background: 'var(--color-info-500)' }} />
            Total on record
            <InfoHint text={help.auditLog} label="About total on record" />
          </div>
          <div className="v">{(stats?.total_on_record.value ?? 0).toLocaleString()}</div>
          <div className="d">{stats?.total_on_record.sub ?? '—'}</div>
        </div>
        <div className="au-stat">
          <div className="k">
            <span className="kd" style={{ background: verify && !verify.is_valid ? 'var(--color-danger-500)' : 'var(--color-success-500)' }} />
            Chain integrity
            <InfoHint text={help.chainIntegrity} label="About chain integrity" />
          </div>
          <div className={`v ${verify && !verify.is_valid ? 'bad' : 'ok'}`}>
            {verifyLoading && !verify ? '…' : `${integrityPct}%`}
          </div>
          <div className="d">
            {verify
              ? `${verify.checked_count.toLocaleString()} / ${verify.total_count.toLocaleString()} verified`
              : 'Verifying…'}
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
