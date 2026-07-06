import { useEffect, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import { useApi } from '@/hooks/useApi';
import { adminApi } from '@/lib/endpoints';
import { normalizeError } from '@/lib/api';
import { formatCedisDecimal, formatDate, formatDateTime, humanize } from '@/lib/format';
import { LoadingState, ErrorState } from '@/components/ui/states';
import { useToast } from '@/components/ui/toast';
import type {
  ChecklistStatus,
  ListingReviewDetail as ReviewDetail,
  ReviewChecklistItem,
} from '@/lib/types';
import './listing-review.css';
import {
  WIconBack,
  WIconChevron,
  WIconCheck,
  WIconX,
  WIconWarn,
  WIconInfo,
  WIconEye,
  WIconPhotos,
  WIconDuplicate,
} from './wlrIcons';

/* ── Section registry (drives the sticky nav + scrollspy) ─────────────────── */

const SECTIONS = [
  ['summary', 'Summary'],
  ['photos', 'Photos'],
  ['details', 'Details'],
  ['checks', 'Checks'],
  ['pricing', 'Pricing'],
  ['landlord', 'Landlord'],
  ['location', 'Location'],
  ['preview', 'Preview'],
  ['timeline', 'Timeline'],
  ['notes', 'Notes'],
  ['decision', 'Decision'],
] as const;

/* ── Helpers ──────────────────────────────────────────────────────────────── */

function escapeRe(s: string): string {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** Render the description, highlighting detected PII (oxblood) + policy (amber). */
function highlightDescription(text: string, pii: string[], policy: string[]) {
  const all = [...pii, ...policy].filter(Boolean).sort((a, b) => b.length - a.length);
  if (all.length === 0) return text;
  const re = new RegExp(`(${all.map(escapeRe).join('|')})`, 'gi');
  const piiLower = new Set(pii.map((p) => p.toLowerCase()));
  const polLower = new Set(policy.map((p) => p.toLowerCase()));
  return text.split(re).map((part, i) => {
    if (!part) return null;
    const lower = part.toLowerCase();
    if (piiLower.has(lower)) return <mark key={i} className="pii">{part}</mark>;
    if (polLower.has(lower)) return <mark key={i} className="pol">{part}</mark>;
    return <span key={i}>{part}</span>;
  });
}

const CHECK_GLYPH: Record<ChecklistStatus, React.ReactNode> = {
  pass: <WIconCheck />,
  warn: <WIconWarn />,
  fail: <WIconX />,
  na: <WIconInfo />,
};

function ChecklistCell({ item }: { item: ReviewChecklistItem }) {
  const cls = item.status === 'pass' ? 'pass' : item.status === 'warn' ? 'warn' : 'fail';
  const label = item.status === 'pass' ? 'Passed' : item.status === 'warn' ? 'Warning' : 'Failed';
  return (
    <div className={`ck ${cls}`}>
      <span className="ci">{CHECK_GLYPH[item.status]}</span>
      <span className="ct">
        {item.label}
        {item.detail && <small>{item.detail}</small>}
      </span>
      <span className="cr">{label}</span>
    </div>
  );
}

const TL_TONE: Record<string, string> = {
  success: 'green',
  danger: 'blood',
  warning: 'amber',
  info: '',
};

/* ── Page ─────────────────────────────────────────────────────────────────── */

export function ListingReviewDetail() {
  const { listingId } = useParams<{ listingId: string }>();
  const numericId = Number(listingId);
  const validId = Number.isFinite(numericId) && numericId > 0;
  const navigate = useNavigate();
  const { toast } = useToast();

  const { data, loading, error, reload } = useApi<ReviewDetail>(
    () =>
      validId
        ? adminApi.listingReviewDetail(numericId)
        : Promise.reject({ status: 404, message: 'Invalid listing id.' }),
    [numericId],
  );

  const [detail, setDetail] = useState<ReviewDetail | null>(null);
  useEffect(() => {
    if (data) setDetail(data);
  }, [data]);

  // Decision inputs.
  const [changeReason, setChangeReason] = useState('');
  const [rejectReason, setRejectReason] = useState('');
  const [decisionNote, setDecisionNote] = useState('');
  const [busy, setBusy] = useState<'approve' | 'changes' | 'reject' | null>(null);

  // Internal notes composer.
  const [noteBody, setNoteBody] = useState('');
  const [noteBusy, setNoteBusy] = useState(false);

  // Prefill the "request changes" message from the actual failing/warning checks.
  useEffect(() => {
    if (!data) return;
    const items = data.checklist.filter((i) => i.status !== 'pass').map((i) => i.detail || i.label);
    setChangeReason(
      items.length
        ? `Please address the following before resubmitting:\n- ${items.join('\n- ')}`
        : '',
    );
  }, [data]);

  // Scrollspy for the sticky section nav.
  const [activeSec, setActiveSec] = useState<string>('summary');
  const rootRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!detail) return;
    const els = SECTIONS.map(([id]) => document.getElementById(`sec-${id}`)).filter(Boolean) as HTMLElement[];
    if (!('IntersectionObserver' in window) || els.length === 0) return;
    const obs = new IntersectionObserver(
      (entries) => {
        entries.forEach((en) => {
          if (en.isIntersecting) setActiveSec(en.target.id.replace('sec-', ''));
        });
      },
      { rootMargin: '-45% 0px -50% 0px' },
    );
    els.forEach((el) => obs.observe(el));
    return () => obs.disconnect();
  }, [detail]);

  function goToSection(id: string) {
    document.getElementById(`sec-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  async function doApprove() {
    setBusy('approve');
    try {
      const updated = await adminApi.approveListing(numericId, decisionNote.trim() || undefined);
      setDetail(updated);
      setDecisionNote('');
      toast('Listing approved — it is now visible to tenants.', 'success');
    } catch (e) {
      toast(normalizeError(e).message, 'error');
    } finally {
      setBusy(null);
    }
  }

  async function doRequestChanges() {
    if (changeReason.trim().length < 20) {
      toast('Give the landlord at least 20 characters describing what to fix.', 'error');
      return;
    }
    setBusy('changes');
    try {
      const updated = await adminApi.requestListingChanges(numericId, changeReason.trim(), decisionNote.trim() || undefined);
      setDetail(updated);
      setDecisionNote('');
      toast('Sent back to the landlord for changes.', 'success');
    } catch (e) {
      toast(normalizeError(e).message, 'error');
    } finally {
      setBusy(null);
    }
  }

  async function doReject() {
    if (rejectReason.trim().length < 20) {
      toast('Please give a reason of at least 20 characters.', 'error');
      return;
    }
    setBusy('reject');
    try {
      const updated = await adminApi.rejectListing(numericId, rejectReason.trim(), decisionNote.trim() || undefined);
      setDetail(updated);
      setRejectReason('');
      setDecisionNote('');
      toast('Listing rejected — the landlord has been notified.', 'success');
    } catch (e) {
      toast(normalizeError(e).message, 'error');
    } finally {
      setBusy(null);
    }
  }

  async function addNote() {
    if (!noteBody.trim()) return;
    setNoteBusy(true);
    try {
      const note = await adminApi.addListingNote(numericId, noteBody.trim());
      setDetail((c) => (c ? { ...c, notes: [note, ...c.notes] } : c));
      setNoteBody('');
      toast('Note added.', 'success');
    } catch (e) {
      toast(normalizeError(e).message, 'error');
    } finally {
      setNoteBusy(false);
    }
  }

  // ── 404 / invalid ──
  if (!loading && error && (error.status === 404 || !validId)) {
    return (
      <div className="wlr rise">
        <div className="crumb">
          <button type="button" className="back" onClick={() => navigate('/app/listing-review')}>
            <WIconBack />
            Back to Listing Review
          </button>
        </div>
        <section className="glass sec">
          <h1 className="ch-title" style={{ fontSize: '1.8rem' }}>
            Listing not found
          </h1>
          <p className="ph-sub">This listing does not exist or is no longer available for review.</p>
          <div style={{ marginTop: '1rem' }}>
            <button type="button" className="btn btn-glass" onClick={() => navigate('/app/listing-review')}>
              Back to Listing Review
            </button>
          </div>
        </section>
      </div>
    );
  }

  const d = detail;
  if (!d) {
    return (
      <div className="wlr rise">
        {loading && <LoadingState label="Loading listing…" />}
        {error && error.status !== 404 && <ErrorState message={error.message} onRetry={reload} />}
      </div>
    );
  }

  const cover = d.photos.find((p) => p.url)?.url ?? null;
  const blockers = d.checklist.filter((i) => i.status === 'fail');
  const warns = d.checklist.filter((i) => i.status === 'warn');
  const risk = blockers.length ? 'high' : warns.length ? 'med' : 'clean';
  const riskLabel = blockers.length ? 'Blockers present' : warns.length ? 'Needs a decision' : 'No issues';
  const verdictCls = blockers.length ? 'block' : warns.length ? 'warnv' : 'okv';
  const verdictTitle = blockers.length
    ? 'Do not approve yet'
    : warns.length
      ? 'Needs a decision'
      : 'Ready to publish';
  const verdictSub = blockers.length
    ? `${blockers.length} blocker${blockers.length > 1 ? 's' : ''} must be resolved before this can go live.`
    : warns.length
      ? `${warns.length} warning${warns.length > 1 ? 's' : ''} to review; approval is allowed.`
      : 'All checks passed. Approving will make this visible to tenants.';

  const canApprove = d.reviewable && d.ready_for_approval;
  const rent = d.unit ? formatCedisDecimal(d.unit.rent_amount) : null;
  const policyPhrases = d.content_flags.policy_phrases;

  const previewArea = d.address_visibility.tenant_area ?? d.property?.city ?? 'Area';
  const bedsLabel = d.unit ? (Number(d.unit.bedrooms) > 0 ? `${d.unit.bedrooms} bed` : 'Studio') : null;

  return (
    <div className="wlr rise" ref={rootRef}>
      {/* Breadcrumb */}
      <div className="crumb">
        <button type="button" className="back" onClick={() => navigate('/app/listing-review')}>
          <WIconBack />
          Back to Listing Review
        </button>
        <span className="sep">·</span>
        <span>Listing #{d.id}</span>
        <span className="sep">/</span>
        <span>{d.status_label}</span>
      </div>

      {/* Hero */}
      <section className="chead glass">
        <div className="chead-hero">
          {cover ? (
            <img src={cover} alt="" />
          ) : (
            <span className="ph-empty">
              <WIconPhotos />
            </span>
          )}
        </div>
        <div className="chead-b">
          <div className="ch-eyebrow">
            <span>Listing #{d.id}</span>
            <span className="mono" style={{ color: 'var(--ink-3)' }}>
              Submitted {formatDate(d.created_at)}
            </span>
          </div>
          <h1 className="ch-title">{d.title}</h1>
          <div className="ch-facts">
            <span className="cf">
              <span className={`rpill ${risk === 'high' ? 'high' : risk === 'med' ? 'med' : 'clean'}`}>
                <span className="sd" />
                {riskLabel}
              </span>
            </span>
            {d.property?.property_type && <span className="cf">{humanize(d.property.property_type)}</span>}
            {d.address_visibility.tenant_area && <span className="cf">{d.address_visibility.tenant_area}</span>}
            {rent && (
              <span className="cf">
                <b>{rent}</b>/mo
              </span>
            )}
            {d.landlord && (
              <span className="cf">
                by <b>{d.landlord.name}</b>{' '}
                <span className={`vbadge ${d.landlord.identity_verified ? 'ok' : 'no'}`}>
                  {d.landlord.identity_verified ? 'Verified' : 'Unverified'}
                </span>
              </span>
            )}
          </div>
          <div className="ch-actions">
            <button
              type="button"
              className="btn btn-pub"
              disabled={!canApprove || busy !== null}
              title={canApprove ? undefined : 'Resolve the blockers first, or request changes.'}
              onClick={doApprove}
            >
              <WIconCheck />
              Approve &amp; publish
            </button>
            <button type="button" className="btn btn-warn" onClick={() => goToSection('decision')} disabled={!d.reviewable}>
              Request changes
            </button>
            <button type="button" className="btn btn-danger" onClick={() => goToSection('decision')} disabled={!d.reviewable}>
              Reject
            </button>
            <button
              type="button"
              className="btn btn-glass"
              onClick={() => navigate(`/app/listing-review/${d.id}/preview`)}
            >
              <WIconEye />
              Preview as tenant
            </button>
          </div>
        </div>
      </section>

      {/* Section nav */}
      <nav className="secnav" aria-label="Sections">
        {SECTIONS.map(([id, label]) => (
          <button
            key={id}
            type="button"
            className={activeSec === id ? 'active' : ''}
            onClick={() => goToSection(id)}
          >
            {label}
          </button>
        ))}
      </nav>

      {/* 01 Summary */}
      <section className="sec glass" id="sec-summary">
        <div className="sec-h">
          <h2>
            <span className="n">01</span> Review summary
          </h2>
          <span className="hint">Can this go live?</span>
        </div>
        <div className={`verdict ${verdictCls}`}>
          <div className="vi">{blockers.length ? <WIconX /> : warns.length ? <WIconWarn /> : <WIconCheck />}</div>
          <div>
            <div className="vt">{verdictTitle}</div>
            <div className="vs">{verdictSub}</div>
          </div>
        </div>
        {(blockers.length > 0 || warns.length > 0) && (
          <div className="blocklist">
            {blockers.map((b) => (
              <div key={b.key} className="blockline blk">
                <span className="bd">
                  <WIconX />
                </span>
                {b.detail || b.label}
              </div>
            ))}
            {warns.map((w) => (
              <div key={w.key} className="blockline wrn">
                <span className="bd">
                  <WIconWarn />
                </span>
                {w.detail || w.label}
              </div>
            ))}
          </div>
        )}
        <div className="sgrid">
          <div className={`scell ${blockers.length ? 'b' : ''}`}>
            <div className="sn">{blockers.length}</div>
            <div className="sl">Blockers</div>
          </div>
          <div className={`scell ${warns.length ? 'w' : ''}`}>
            <div className="sn">{warns.length}</div>
            <div className="sl">Warnings</div>
          </div>
          <div className="scell">
            <div className="sn">{d.photo_count}</div>
            <div className="sl">Photos</div>
          </div>
          <div className="scell">
            <div className="sn" style={{ fontSize: '1rem', paddingTop: '.4rem' }}>
              {d.completeness.percent}%
            </div>
            <div className="sl">Complete</div>
          </div>
        </div>
      </section>

      {/* 02 Photos */}
      <section className="sec glass" id="sec-photos">
        <div className="sec-h">
          <h2>
            <span className="n">02</span> Photos
          </h2>
          <span className="hint">
            {d.photo_count} uploaded{d.photo_count > 0 ? ' · cover first' : ''}
            {d.photo_count > 0 && d.photo_count < 3 ? ' · below recommended 3' : ''}
          </span>
        </div>
        {d.photo_count === 0 ? (
          <div className="warnrow amber">
            <WIconWarn />
            No photos uploaded. Tenants strongly prefer listings with photos — consider requesting images.
          </div>
        ) : (
          <>
            {d.photo_count < 3 && (
              <div className="warnrow amber">
                <WIconWarn />
                Only {d.photo_count} photo{d.photo_count === 1 ? '' : 's'} uploaded. At least 3 is recommended.
              </div>
            )}
            <div className="gallery">
              {d.photos.map((p, i) =>
                p.url ? (
                  <a key={p.id} className="gph" href={p.url} target="_blank" rel="noopener noreferrer">
                    {i === 0 && <span className="cover">Cover</span>}
                    <img src={p.url} alt={p.alt_text ?? ''} loading="lazy" />
                    {(p.caption || p.alt_text) && <span className="cap">{p.caption ?? p.alt_text}</span>}
                  </a>
                ) : null,
              )}
            </div>
          </>
        )}
      </section>

      {/* 03 Details */}
      <section className="sec glass" id="sec-details">
        <div className="sec-h">
          <h2>
            <span className="n">03</span> Listing details
          </h2>
        </div>
        <div className="terms">
          <div className="kv"><span className="kk">Title</span><span className="vv">{d.title}</span></div>
          <div className="kv"><span className="kk">Property</span><span className="vv">{d.property?.name ?? '—'}</span></div>
          <div className="kv"><span className="kk">Type</span><span className="vv">{d.property?.property_type ? humanize(d.property.property_type) : '—'}</span></div>
          <div className="kv"><span className="kk">Unit</span><span className="vv">{d.unit?.unit_number ?? '—'}</span></div>
          <div className="kv"><span className="kk">Bedrooms</span><span className="vv">{bedsLabel ?? '—'}</span></div>
          <div className="kv"><span className="kk">Bathrooms</span><span className="vv">{d.unit?.bathrooms ?? '—'}</span></div>
          <div className="kv"><span className="kk">Square feet</span><span className="vv">{d.unit?.square_feet ?? '—'}</span></div>
          <div className="kv"><span className="kk">Rent</span><span className="vv">{rent ? `${rent}/mo` : '—'}</span></div>
          <div className="kv"><span className="kk">Deposit</span><span className="vv">{d.unit?.security_deposit ? formatCedisDecimal(d.unit.security_deposit) : '—'}</span></div>
          <div className="kv"><span className="kk">Lease</span><span className="vv">{d.lease_duration_months ? `${d.lease_duration_months} months` : '—'}</span></div>
          <div className="kv"><span className="kk">Available</span><span className="vv">{d.unit?.available_from ? formatDate(d.unit.available_from) : '—'}</span></div>
          <div className="kv"><span className="kk">Pets</span><span className="vv">{d.pets_allowed ? d.pet_policy || 'Allowed' : 'Not allowed'}</span></div>
        </div>
        <div className="dl" style={{ margin: '1.2rem 0 .6rem' }}>
          Description{policyPhrases.length || d.content_flags.pii.length ? ' · flagged content highlighted' : ''}
        </div>
        <div className="descbox">
          {d.description
            ? highlightDescription(d.description, d.content_flags.pii, policyPhrases)
            : <span style={{ color: 'var(--ink-3)' }}>No description provided.</span>}
        </div>
        {d.unit?.amenities?.length ? (
          <>
            <div className="dl" style={{ margin: '1.3rem 0 .5rem' }}>Amenities</div>
            <div className="amen">
              {d.unit.amenities.map((a) => (
                <span key={a}>{humanize(a)}</span>
              ))}
            </div>
          </>
        ) : null}
      </section>

      {/* 04 Checks */}
      <section className="sec glass" id="sec-checks">
        <div className="sec-h">
          <h2>
            <span className="n">04</span> Policy &amp; quality checks
          </h2>
          <span className="hint">{d.completeness.passed}/{d.completeness.total} passed</span>
        </div>
        <div className="checklist">
          {d.checklist.map((item) => (
            <ChecklistCell key={item.key} item={item} />
          ))}
        </div>
        {policyPhrases.length > 0 && (
          <div className="policy-issue">
            <div className="ph">
              <WIconWarn />
              Possible exclusionary language
            </div>
            <div className="quote">&ldquo;{policyPhrases.join('", "')}&rdquo;</div>
            <div className="rw">
              This is an <b>advisory</b> heuristic, not a verdict — read the phrase in context. If it excludes a
              protected or vulnerable group, use <b>Request changes</b> to ask the landlord to rewrite it before
              publication.
            </div>
          </div>
        )}
      </section>

      {/* 05 Pricing */}
      <section className="sec glass" id="sec-pricing">
        <div className="sec-h">
          <h2>
            <span className="n">05</span> Pricing review
          </h2>
          {d.pricing.area && <span className="hint">vs. {d.pricing.area}</span>}
        </div>
        <div className="terms" style={{ marginBottom: '1rem' }}>
          <div className="kv"><span className="kk">Submitted rent</span><span className="vv">{d.pricing.rent != null ? `${formatCedisDecimal(d.pricing.rent)}/mo` : '—'}</span></div>
          <div className="kv"><span className="kk">Deposit</span><span className="vv">{d.pricing.deposit != null ? formatCedisDecimal(d.pricing.deposit) : '—'}</span></div>
          <div className="kv"><span className="kk">Deposit multiple</span><span className="vv">{d.pricing.deposit_months != null ? `${d.pricing.deposit_months} months` : '—'}</span></div>
          <div className="kv"><span className="kk">Area median</span><span className="vv">{d.pricing.has_comparison && d.pricing.median != null ? formatCedisDecimal(d.pricing.median) : 'Not enough data'}</span></div>
        </div>
        {d.pricing.has_comparison && d.pricing.median != null && d.pricing.rent != null ? (
          (() => {
            const maxv = Math.max(d.pricing.rent, d.pricing.median) * 1.15 || 1;
            const over = d.pricing.is_outlier;
            return (
              <div className="market">
                <div className="mrow">
                  <span className="ml">This listing</span>
                  <span className="mbar">
                    <i style={{ width: `${(d.pricing.rent / maxv) * 100}%`, background: over ? 'var(--oxblood)' : 'var(--petrol)' }} />
                  </span>
                  <span className="mv">{formatCedisDecimal(d.pricing.rent)}</span>
                </div>
                <div className="mrow">
                  <span className="ml">Area median</span>
                  <span className="mbar">
                    <i style={{ width: `${(d.pricing.median / maxv) * 100}%`, background: 'var(--slate)' }} />
                  </span>
                  <span className="mv">{formatCedisDecimal(d.pricing.median)}</span>
                </div>
                <div className="mnote" style={{ color: over ? 'var(--oxblood)' : 'var(--ink-3)' }}>
                  {over
                    ? `Price outlier: ${d.pricing.percent_diff}% above the median of ${d.pricing.comparable_count} similar approved listing${d.pricing.comparable_count === 1 ? '' : 's'} in ${d.pricing.area}.`
                    : `Within range — ${d.pricing.percent_diff != null && d.pricing.percent_diff >= 0 ? '+' : ''}${d.pricing.percent_diff}% vs the median of ${d.pricing.comparable_count} similar listing${d.pricing.comparable_count === 1 ? '' : 's'} in ${d.pricing.area}.`}
                </div>
              </div>
            );
          })()
        ) : (
          <div className="warnrow amber">
            <WIconInfo />
            Not enough comparable approved listings in {d.pricing.area ?? 'this area'} to compute a reliable median
            {d.pricing.comparable_count > 0 ? ` (only ${d.pricing.comparable_count} found)` : ''}.
          </div>
        )}
      </section>

      {/* 06 Landlord & verification */}
      <section className="sec glass" id="sec-landlord">
        <div className="sec-h">
          <h2>
            <span className="n">06</span> Landlord &amp; verification
          </h2>
        </div>
        {d.landlord && !d.landlord.identity_verified && (
          <div className="warnrow blood">
            <WIconWarn />
            Landlord identity is not verified. Verification is recommended before this listing is published.
          </div>
        )}
        <div className="two">
          {d.landlord && (
            <div className="subcard">
              <div className="person">
                <div className="pa">
                  {d.landlord.name.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase()}
                </div>
                <div>
                  <div className="pn">{d.landlord.name}</div>
                  <div className="pr">Landlord</div>
                </div>
              </div>
              <div className="kv"><span className="kk">Identity</span><span className="vv"><span className={`vbadge ${d.landlord.identity_verified ? 'ok' : 'no'}`}>{d.landlord.identity_verified ? 'Verified' : humanize(d.landlord.verification_status ?? 'Unverified')}</span></span></div>
              <div className="kv"><span className="kk">Account</span><span className="vv"><span className={`vbadge ${d.landlord.account_status === 'active' ? 'ok' : 'pending'}`}>{humanize(d.landlord.account_status ?? 'unknown')}</span></span></div>
              {d.landlord.email && <div className="kv"><span className="kk">Email</span><span className="vv mono">{d.landlord.email}</span></div>}
              {d.landlord.phone && <div className="kv"><span className="kk">Phone</span><span className="vv mono">{d.landlord.phone}</span></div>}
              <div className="kv"><span className="kk">Member since</span><span className="vv">{d.landlord.created_at ? formatDate(d.landlord.created_at) : '—'}</span></div>
              <div className="kv"><span className="kk">Active listings</span><span className="vv">{d.landlord.active_listings}</span></div>
              <div className="kv"><span className="kk">Rejected before</span><span className="vv">{d.landlord.rejected_listings}</span></div>
              <div style={{ marginTop: '1rem' }}>
                <button type="button" className="btn btn-glass btn-sm" onClick={() => navigate('/app/users', { state: { search: d.landlord!.email ?? d.landlord!.name } })}>
                  Open landlord profile
                  <WIconChevron />
                </button>
              </div>
            </div>
          )}
          <div className="subcard">
            <div className="dl" style={{ marginBottom: '.8rem' }}>Property &amp; unit</div>
            <div className="kv"><span className="kk">Property active</span><span className="vv"><span className={`vbadge ${d.verification.property_active ? 'ok' : 'pending'}`}>{d.verification.property_active ? 'Yes' : 'No'}</span></span></div>
            <div className="kv"><span className="kk">Unit active</span><span className="vv"><span className={`vbadge ${d.verification.unit_active ? 'ok' : 'pending'}`}>{d.verification.unit_active ? 'Yes' : 'No'}</span></span></div>
            <div className="kv"><span className="kk">Availability</span><span className="vv">{d.verification.unit_availability_label ?? '—'}</span></div>
            <div className="kv"><span className="kk">Listable</span><span className="vv"><span className={`vbadge ${d.verification.unit_can_be_listed ? 'ok' : 'pending'}`}>{d.verification.unit_can_be_listed ? 'Yes' : 'No'}</span></span></div>
            <div className="kv"><span className="kk">Duplicate listing</span><span className="vv"><span className={`vbadge ${d.verification.duplicate_active_listing ? 'no' : 'ok'}`}>{d.verification.duplicate_active_listing ? 'Yes' : 'None'}</span></span></div>
            {d.verification.duplicate_active_listing && (
              <div className="warnrow blood" style={{ marginTop: '.7rem' }}>
                <WIconDuplicate />
                Another active or pending listing already exists for this unit. Approving would create two live
                listings for the same home.
              </div>
            )}
          </div>
        </div>
      </section>

      {/* 07 Location */}
      <section className="sec glass" id="sec-location">
        <div className="sec-h">
          <h2>
            <span className="n">07</span> Location &amp; address visibility
          </h2>
        </div>
        <div className="terms">
          <div className="kv"><span className="kk">Admin full address</span><span className="vv">{d.address_visibility.admin_full_address ?? d.property?.full_address ?? '—'}</span></div>
          <div className="kv"><span className="kk">Tenant sees</span><span className="vv">{d.address_visibility.tenant_area ?? '—'}{d.address_visibility.street_public ? '' : ' (area only)'}</span></div>
          <div className="kv"><span className="kk">Street visible?</span><span className="vv"><span className={`vbadge ${d.address_visibility.street_public ? 'ok' : 'pending'}`}>{d.address_visibility.street_public ? 'Public' : 'Hidden'}</span></span></div>
          <div className="kv"><span className="kk">Country</span><span className="vv">{d.property?.country ?? '—'}</span></div>
        </div>
        <div className="warnrow amber" style={{ marginTop: '1rem', background: 'color-mix(in srgb, var(--petrol) 6%, transparent)', color: 'var(--ink-2)' }}>
          <WIconInfo />
          {d.address_visibility.rule}
        </div>
      </section>

      {/* 08 Preview */}
      <section className="sec glass" id="sec-preview">
        <div className="sec-h">
          <h2>
            <span className="n">08</span> Preview as tenant
          </h2>
          <span className="hint">What tenants see once live</span>
        </div>
        <div className="preview">
          <div className="pv-imgs">
            <div className="pa">{d.photos[0]?.url && <img src={d.photos[0].url} alt="" />}</div>
            <div className="col">
              <div className="pa">{d.photos[1]?.url && <img src={d.photos[1].url} alt="" />}</div>
              <div className="pa">{d.photos[2]?.url && <img src={d.photos[2].url} alt="" />}</div>
            </div>
          </div>
          <div className="pv-b">
            <div className="pv-loc">{previewArea} · area only</div>
            <div className="pv-t">{d.title}</div>
            <div className="pv-facts">
              {bedsLabel && <span>{bedsLabel}</span>}
              {d.unit && <span>{d.unit.bathrooms} bath</span>}
              {d.unit?.square_feet && <span>{d.unit.square_feet} sqft</span>}
            </div>
            {rent && (
              <div className="pv-r">
                {rent}
                <small>/mo</small>
              </div>
            )}
            {d.description && <div className="pv-desc">{d.description.slice(0, 130)}…</div>}
            {d.unit?.amenities?.length ? (
              <div className="amen">
                {d.unit.amenities.slice(0, 4).map((a) => (
                  <span key={a}>{humanize(a)}</span>
                ))}
              </div>
            ) : null}
            {d.landlord && (
              <div className="pv-host">
                <span className="ha">{d.landlord.name.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase()}</span>
                Hosted by {d.landlord.name.split(' ')[0]}
                {d.landlord.identity_verified && <> · <span style={{ color: 'var(--green)' }}>verified</span></>}
              </div>
            )}
          </div>
        </div>
        <div style={{ marginTop: '.8rem' }}>
          <button type="button" className="btn btn-glass btn-sm" onClick={() => navigate(`/app/listing-review/${d.id}/preview`)}>
            <WIconEye />
            Open full tenant preview
          </button>
        </div>
      </section>

      {/* 09 Timeline */}
      <section className="sec glass" id="sec-timeline">
        <div className="sec-h">
          <h2>
            <span className="n">09</span> Submission timeline
          </h2>
        </div>
        <div className="tl">
          {d.timeline.map((ev, i) => (
            <div key={`${ev.key}-${i}`} className={`tl-item ${TL_TONE[ev.severity] ?? ''}`}>
              <div className="te">{ev.label}</div>
              <div className="tm">
                {formatDateTime(ev.at)}
                {ev.actor ? ` · ${ev.actor}` : ''}
              </div>
              {ev.detail && <div className="td">{ev.detail}</div>}
            </div>
          ))}
        </div>
      </section>

      {/* 10 Notes */}
      <section className="sec glass" id="sec-notes">
        <div className="sec-h">
          <h2>
            <span className="n">10</span> Internal notes
          </h2>
          <span className="hint">Never shown to the landlord or tenants</span>
        </div>
        {d.notes.length === 0 ? (
          <div style={{ fontSize: '.83rem', color: 'var(--ink-3)' }}>No internal notes yet.</div>
        ) : (
          d.notes.map((n) => (
            <div key={n.id} className="note">
              {n.body}
              <div className="nm">
                {n.admin_name ?? 'Admin'} · {formatDateTime(n.created_at)}
                <span className="lk">Internal</span>
              </div>
            </div>
          ))
        )}
        <div className="noteadd">
          <input
            type="text"
            value={noteBody}
            onChange={(e) => setNoteBody(e.target.value)}
            placeholder="Add an internal note…"
            onKeyDown={(e) => {
              if (e.key === 'Enter') addNote();
            }}
          />
          <button type="button" className="btn btn-blood btn-sm" onClick={addNote} disabled={noteBusy || !noteBody.trim()}>
            Add
          </button>
        </div>
      </section>

      {/* 11 Decision */}
      <section className="sec glass decision" id="sec-decision">
        <div className="sec-h">
          <h2>
            <span className="n">11</span> Decision
          </h2>
          {!d.reviewable && <span className="hint">This listing has been {d.status_label.toLowerCase()}</span>}
        </div>

        {d.reviewable ? (
          <>
            <div className="decision-note">
              <label className="fieldlabel" htmlFor="decision-note-input">
                Internal note for this decision (optional)
              </label>
              <textarea
                id="decision-note-input"
                value={decisionNote}
                onChange={(e) => setDecisionNote(e.target.value)}
                placeholder="Private context for other admins…"
              />
            </div>
            <div className="dpanel">
              <div className={`dopt approve ${canApprove ? '' : 'blocked'}`}>
                <h3>
                  <WIconCheck />
                  Approve &amp; publish
                </h3>
                {canApprove ? (
                  <>
                    <p>This listing meets the publishing requirements. Approving makes it visible to tenants immediately.</p>
                    <button type="button" className="btn btn-pub" onClick={doApprove} disabled={busy !== null}>
                      {busy === 'approve' ? 'Publishing…' : 'Approve & publish now'}
                    </button>
                  </>
                ) : (
                  <>
                    <div className="cannot">
                      <b>Cannot approve yet:</b>
                      <ul>
                        {blockers.map((b) => (
                          <li key={b.key}>{b.detail || b.label}</li>
                        ))}
                      </ul>
                    </div>
                    <button type="button" className="btn btn-pub" disabled>
                      Approve &amp; publish
                    </button>
                    <span style={{ fontSize: '.8rem', color: 'var(--ink-3)', marginLeft: '.5rem' }}>
                      Resolve the blockers, or use Request changes.
                    </span>
                  </>
                )}
              </div>

              <div className="dopt">
                <h3 style={{ color: 'var(--amber)' }}>Request changes</h3>
                <p>Preferred for anything fixable. Returns the listing to the landlord as a draft — no rejection on record.</p>
                <textarea
                  value={changeReason}
                  onChange={(e) => setChangeReason(e.target.value)}
                  placeholder="Tell the landlord what to fix (min 20 characters)…"
                />
                <div className="counter">{changeReason.trim().length}/20</div>
                <button
                  type="button"
                  className="btn btn-warn"
                  onClick={doRequestChanges}
                  disabled={busy !== null || changeReason.trim().length < 20}
                  style={{ marginTop: '.4rem' }}
                >
                  {busy === 'changes' ? 'Sending…' : 'Send back for changes'}
                </button>
              </div>

              <div className="dopt">
                <h3 style={{ color: 'var(--oxblood)' }}>Reject</h3>
                <p>Use when the listing cannot be fixed. A reason the landlord will see is required.</p>
                <textarea
                  value={rejectReason}
                  onChange={(e) => setRejectReason(e.target.value)}
                  placeholder="Reason for the landlord (min 20 characters)…"
                />
                <div className="counter">{rejectReason.trim().length}/20</div>
                <button
                  type="button"
                  className="btn btn-danger"
                  onClick={doReject}
                  disabled={busy !== null || rejectReason.trim().length < 20}
                  style={{ marginTop: '.4rem' }}
                >
                  {busy === 'reject' ? 'Rejecting…' : 'Reject listing'}
                </button>
              </div>
            </div>
          </>
        ) : (
          <div className="warnrow" style={{ background: 'color-mix(in srgb, var(--ink) 3%, transparent)', color: 'var(--ink-2)' }}>
            <WIconInfo />
            <span>
              This listing has been <b>{d.status_label.toLowerCase()}</b>. No decision is required.
              {d.status === 'rejected' && d.rejection_reason ? ` Reason: ${d.rejection_reason}` : ''}
              {d.status === 'draft' && d.changes_requested_reason
                ? ` It was sent back for changes: ${d.changes_requested_reason}`
                : ''}
            </span>
          </div>
        )}
      </section>
    </div>
  );
}

export default ListingReviewDetail;
