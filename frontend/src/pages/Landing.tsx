import { useEffect, useState } from 'react';
import type { ReactElement, SVGProps } from 'react';
import { Link } from 'react-router';
import { useAuth } from '@/context/auth';
import './landing.css';

import villa from '@/assets/landing/villa.jpg';
import cabin from '@/assets/landing/cabin.jpg';
import indigo from '@/assets/landing/indigo.jpg';
import entry from '@/assets/landing/entry.jpg';
import suburban from '@/assets/landing/suburban.jpg';
import spa from '@/assets/landing/spa.jpg';
import rolls from '@/assets/landing/rolls.jpg';

const IMG = { villa, cabin, indigo, entry, suburban, spa, rolls };

/* Reveal-on-scroll: fades elements in as they enter the viewport. */
function useReveal() {
  useEffect(() => {
    const els = document.querySelectorAll('[data-reveal]');
    const io = new IntersectionObserver(
      (entries) => entries.forEach((e) => e.isIntersecting && e.target.classList.add('in')),
      { threshold: 0.12 },
    );
    els.forEach((el) => io.observe(el));
    return () => io.disconnect();
  }, []);
}

const S = (p: SVGProps<SVGSVGElement> = {}): SVGProps<SVGSVGElement> => ({
  width: 18,
  height: 18,
  viewBox: '0 0 24 24',
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 1.6,
  strokeLinecap: 'round',
  strokeLinejoin: 'round',
  ...p,
});

const Ic: Record<string, ReactElement> = {
  shield: <svg {...S()}><path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z" /><path d="m9 12 2 2 4-4" /></svg>,
  lock: <svg {...S()}><rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" /></svg>,
  doc: <svg {...S()}><path d="M7 3h7l4 4v14H7z" /><path d="M14 3v4h4M10 12h6M10 16h6" /></svg>,
  coins: <svg {...S()}><ellipse cx="12" cy="6" rx="7" ry="3" /><path d="M5 6v6c0 1.7 3.1 3 7 3s7-1.3 7-3V6M5 12v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6" /></svg>,
  building: <svg {...S()}><path d="M4 21V5l8-2v18M12 21V9l8 2v10M2 21h20" /><path d="M7 8h0M7 12h0M7 16h0M16 13h0M16 17h0" /></svg>,
  bell: <svg {...S()}><path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6" /><path d="M10 20a2 2 0 0 0 4 0" /></svg>,
  layers: <svg {...S()}><path d="m12 3 9 5-9 5-9-5z" /><path d="m3 13 9 5 9-5M3 17l9 5 9-5" opacity=".55" /></svg>,
  eye: <svg {...S()}><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z" /><circle cx="12" cy="12" r="3" /></svg>,
  search: <svg {...S()}><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>,
  pin: <svg {...S({ width: 14, height: 14 })}><path d="M12 21s7-5.5 7-11a7 7 0 1 0-14 0c0 5.5 7 11 7 11z" /><circle cx="12" cy="10" r="2.4" /></svg>,
  arrow: <svg {...S({ width: 16, height: 16, strokeWidth: 1.8 })}><path d="M5 12h14M13 6l6 6-6 6" /></svg>,
  check: <svg {...S()}><path d="M20 6 9 17l-5-5" /></svg>,
};

const Logo = () => (
  <svg className="nx-logo" viewBox="0 0 32 32" fill="none" aria-hidden="true">
    <path d="M5 27V5h4l14 17V5h4" stroke="#C9A45B" strokeWidth="3" strokeLinecap="square" />
  </svg>
);

interface Slide {
  img: string;
  nm: string;
  lo: string;
}

function Hero({ ctaTo, ctaLabel }: { ctaTo: string; ctaLabel: string }) {
  const slides: Slide[] = [
    { img: IMG.villa, nm: 'Casa del Mar', lo: 'Labadi, Accra' },
    { img: IMG.cabin, nm: 'Aburi Ridge Lodge', lo: 'Aburi, Eastern Region' },
    { img: IMG.suburban, nm: 'Maplewood House', lo: 'East Legon, Accra' },
    { img: IMG.rolls, nm: 'Phantom Court', lo: 'Cantonments, Accra' },
    { img: IMG.indigo, nm: 'The Indigo Loft', lo: 'Osu, Accra' },
  ];
  const [i, setI] = useState(0);
  useEffect(() => {
    const t = setInterval(() => setI((p) => (p + 1) % slides.length), 6000);
    return () => clearInterval(t);
  }, [slides.length]);

  return (
    <header className="nx-hero">
      <div className="nx-hero-bg" aria-hidden="true">
        {slides.map((s, k) => (
          <div
            key={k}
            className={'nx-slide' + (k === i ? ' on' : '')}
            style={{ backgroundImage: `url(${s.img})` }}
          />
        ))}
      </div>
      <div className="nx-hero-scrim" aria-hidden="true" />
      <div className="nx-hero-grain" aria-hidden="true" />
      <div className="nx-hero-content">
        <div className="nx-hero-inner">
          <span className="nx-eyebrow">Secure property rental</span>
          <h1>
            Find it.
            <br />
            Sign it.
            <br />
            <span className="it">Settle in.</span>
          </h1>
          <p className="nx-hero-sub">
            Nexus brings listings, contracts, and payments into one secure platform, so tenants,
            landlords, and administrators always know exactly where they stand.
          </p>
          <div className="nx-hero-acts">
            <a className="nx-btn nx-btn-primary" href="#listings">
              Browse the collection {Ic.arrow}
            </a>
            <Link className="nx-btn nx-btn-ghost" to={ctaTo}>
              {ctaLabel}
            </Link>
          </div>
        </div>
      </div>
      <div className="nx-hero-foot">
        <div className="nx-hero-foot-in">
          <div className="nx-caption">
            <div className="lab">Now viewing</div>
            <div className="nm">{slides[i].nm}</div>
            <div className="lo">{slides[i].lo}</div>
          </div>
          <div className="nx-dots">
            {slides.map((s, k) => (
              <button
                key={k}
                className={k === i ? 'on' : ''}
                aria-label={'Show ' + s.nm}
                onClick={() => setI(k)}
              />
            ))}
          </div>
        </div>
      </div>
    </header>
  );
}

export function Landing() {
  useReveal();
  const { user } = useAuth();
  const [scrolled, setScrolled] = useState(false);
  useEffect(() => {
    const f = () => setScrolled(window.scrollY > 40);
    window.addEventListener('scroll', f, { passive: true });
    f();
    return () => window.removeEventListener('scroll', f);
  }, []);

  // Authenticated visitors go straight to the app; guests are invited to register.
  const ctaTo = user ? '/app' : '/register';
  const ctaLabel = user ? 'Go to dashboard' : 'Request access';
  const exploreTo = user ? '/app' : '/register';

  const listings = [
    { img: IMG.villa, nm: 'Casa del Mar', lo: 'Labadi, Accra', price: '$12,500', meta: '4 Beds · 5 Baths · Ocean view', status: 'Available', tag: 'ok' },
    { img: IMG.cabin, nm: 'Aburi Ridge Lodge', lo: 'Aburi, Eastern Region', price: '$8,900', meta: '5 Beds · 4 Baths · Mountain view', status: 'Available', tag: 'ok' },
    { img: IMG.indigo, nm: 'The Indigo Loft', lo: 'Osu, Accra', price: '$4,200', meta: '2 Beds · 2 Baths · City loft', status: 'Occupied', tag: 'muted' },
    { img: IMG.suburban, nm: 'Maplewood House', lo: 'East Legon, Accra', price: '$5,600', meta: '4 Beds · 3 Baths · Family home', status: 'Available', tag: 'ok' },
    { img: IMG.entry, nm: 'Airport City Townhouse', lo: 'Airport Residential, Accra', price: '$3,800', meta: '3 Beds · 2 Baths · Townhouse', status: 'Available', tag: 'ok' },
    { img: IMG.spa, nm: 'Dune Spa Residence', lo: 'Ada Foah, Greater Accra', price: '$9,400', meta: '3 Beds · 4 Baths · Beachfront', status: 'Reserved', tag: 'warn' },
  ];
  const steps = [
    { n: '01', t: 'Find a home', p: 'Browse verified listings, save your favorites, and request a viewing in a few taps.' },
    { n: '02', t: 'Agree the terms', p: 'Review and sign the contract online. Every clause is laid out in plain language.' },
    { n: '03', t: 'Pay with clarity', p: 'Rent is logged in a shared ledger, so what is due and what is paid is never in question.' },
    { n: '04', t: 'Live with support', p: 'Track requests, notifications, and renewals from one calm dashboard.' },
  ];
  const points = [
    { ic: Ic.shield, t: 'Verified before you see it', p: 'Every listing passes admin review, which keeps fraud and ghost listings off the platform.' },
    { ic: Ic.doc, t: 'Contracts in plain language', p: 'Agreements show state, dates, and terms clearly, with no buried clauses or surprises.' },
    { ic: Ic.coins, t: 'One honest ledger', p: 'Tenants and landlords read from the same record of rent due, paid, and upcoming.' },
  ];
  const roles = [
    { img: IMG.cabin, k: 'I am a tenant', t: 'Find your next home', p: 'Search, save, and apply with confidence, then manage rent and contracts in one place.', cta: 'Browse rentals', feature: false },
    { img: IMG.suburban, k: 'I am a landlord', t: 'Run your properties', p: 'List units, track contracts, and follow rent and tenant activity from a single console.', cta: 'List a property', feature: true },
    { img: IMG.rolls, k: 'I am an admin', t: 'Protect the platform', p: 'Review listings, moderate content, and watch audit logs to keep Nexus fair for everyone.', cta: 'Open console', feature: false },
  ];
  const feats = [
    { ic: Ic.layers, t: 'Role-aware dashboards', p: 'Tenants, landlords, and admins each see exactly what matters to them.' },
    { ic: Ic.search, t: 'Saved listings', p: 'Keep a shortlist of homes and return to compare them when you are ready.' },
    { ic: Ic.doc, t: 'Contract tracking', p: 'Watch every agreement move from draft to active to ended, with no surprises.' },
    { ic: Ic.coins, t: 'Rent ledger', p: 'A running record of rent due, paid, and late, readable at a glance.' },
    { ic: Ic.bell, t: 'Notifications', p: 'Know what needs attention next, grouped by urgency and marked read or unread.' },
    { ic: Ic.eye, t: 'Audit and moderation', p: 'Admins inspect key events and moderate listings to keep Nexus safe.' },
  ];

  return (
    <div className="nx">
      <nav className={'nx-nav' + (scrolled ? ' scrolled' : '')}>
        <div className="nx-nav-inner">
          <Link className="nx-brand" to="/">
            <Logo />
            NEXUS
          </Link>
          <div className="nx-navlinks">
            <a href="#listings">Listings</a>
            <a href="#how">How it works</a>
            <a href="#trust">Security</a>
            <a href="#roles">For you</a>
            <Link to={ctaTo} className="nx-req">
              {user ? 'Dashboard' : 'Request access'}
            </Link>
          </div>
        </div>
      </nav>

      <Hero ctaTo={ctaTo} ctaLabel={ctaLabel} />

      <div className="nx-bar">
        <div className="nx-bar-in">
          <span>{Ic.check} Verified listings</span>
          <span>{Ic.lock} Secure digital contracts</span>
          <span>{Ic.coins} Transparent payments</span>
          <span>{Ic.shield} Enterprise grade security</span>
        </div>
      </div>

      {/* listings */}
      <section className="nx-section" id="listings">
        <div className="nx-wrap">
          <div className="nx-sechead">
            <div data-reveal>
              <span className="nx-eyebrow">Featured homes</span>
              <h2 className="nx-h2">A collection worth coming home to.</h2>
            </div>
            <p className="nx-lead" data-reveal>
              Hand-reviewed rentals across the country. Every one is verified before it reaches you.
            </p>
          </div>
          <div className="nx-grid3">
            {listings.map((l) => (
              <Link className="nx-listing" to={exploreTo} key={l.nm} data-reveal>
                <div className="nx-listing-img">
                  <img src={l.img} alt={l.nm} loading="lazy" />
                  <span className={'nx-listing-badge ' + l.tag}>
                    <i />
                    {l.status}
                  </span>
                </div>
                <div className="nx-listing-body">
                  <span className="nx-listing-loc">{Ic.pin}{l.lo}</span>
                  <span className="nx-listing-nm">{l.nm}</span>
                  <span className="nx-listing-meta">{l.meta}</span>
                  <div className="nx-listing-foot">
                    <span className="nx-listing-price">
                      {l.price}
                      <small> / mo</small>
                    </span>
                    <span className="nx-listing-view">View home {Ic.arrow}</span>
                  </div>
                </div>
              </Link>
            ))}
          </div>
        </div>
      </section>

      {/* how it works */}
      <section className="nx-section alt" id="how">
        <div className="nx-wrap">
          <span className="nx-eyebrow" data-reveal>How it works</span>
          <h2 className="nx-h2" data-reveal>From first viewing to final signature.</h2>
          <div className="nx-flow" data-reveal>
            {steps.map((s) => (
              <div className="nx-step" key={s.n}>
                <div className="num">{s.n}</div>
                <h4>{s.t}</h4>
                <p>{s.p}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* editorial split */}
      <section className="nx-section" id="trust">
        <div className="nx-wrap nx-split">
          <div className="nx-split-media" data-reveal>
            <img src={IMG.spa} alt="Calm, light-filled interior" />
            <span className="tagstrip">Built on trust</span>
          </div>
          <div data-reveal>
            <span className="nx-eyebrow">Trust and security</span>
            <h2 className="nx-h2">No one should have to take the other side's word for it.</h2>
            <p className="nx-lead">
              Every meaningful action on Nexus leaves a clear, reviewable record. That is what turns
              a rental from a leap of faith into a documented agreement.
            </p>
            <div className="nx-points">
              {points.map((p) => (
                <div className="nx-point" key={p.t}>
                  <span className="ic">{p.ic}</span>
                  <div>
                    <h4>{p.t}</h4>
                    <p>{p.p}</p>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      </section>

      {/* full bleed band */}
      <section className="nx-band">
        <div className="nx-band-img" aria-hidden="true">
          <img src={IMG.rolls} alt="" />
        </div>
        <div className="nx-band-scrim" aria-hidden="true" />
        <div className="nx-band-in">
          <span className="nx-eyebrow" data-reveal>The Nexus standard</span>
          <h2 data-reveal>Renting, refined. Every detail handled, every signature secured.</h2>
          <p data-reveal>
            From the first listing to the final payment, Nexus keeps the whole relationship clear,
            calm, and on the record.
          </p>
        </div>
      </section>

      {/* roles */}
      <section className="nx-section alt" id="roles">
        <div className="nx-wrap">
          <span className="nx-eyebrow" data-reveal>Choose your path</span>
          <h2 className="nx-h2" data-reveal>One platform, made for each side of the lease.</h2>
          <div className="nx-roles">
            {roles.map((r) => (
              <Link className={'nx-role' + (r.feature ? ' feature' : '')} to={exploreTo} key={r.t} data-reveal>
                <div className="nx-role-img">
                  <img src={r.img} alt={r.t} />
                </div>
                <div className="nx-role-body">
                  <span className="k">{r.k}</span>
                  <h3>{r.t}</h3>
                  <p>{r.p}</p>
                  <span className="go">{r.cta} {Ic.arrow}</span>
                </div>
              </Link>
            ))}
          </div>
        </div>
      </section>

      {/* features */}
      <section className="nx-section">
        <div className="nx-wrap">
          <span className="nx-eyebrow" data-reveal>Features</span>
          <h2 className="nx-h2" data-reveal>Everything the rental relationship needs.</h2>
          <div className="nx-feat">
            {feats.map((f) => (
              <div className="nx-card" key={f.t} data-reveal>
                <div className="ic">{f.ic}</div>
                <h4>{f.t}</h4>
                <p>{f.p}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* cta */}
      <section className="nx-cta" id="access">
        <div className="nx-cta-img" aria-hidden="true">
          <img src={IMG.entry} alt="" />
        </div>
        <div className="nx-cta-scrim" aria-hidden="true" />
        <div className="nx-cta-in nx-wrap">
          <span className="nx-eyebrow" style={{ display: 'inline-flex' }} data-reveal>Get started</span>
          <h2 data-reveal>Your next home is on Nexus.</h2>
          <div className="acts" data-reveal>
            <Link className="nx-btn nx-btn-primary" to={ctaTo}>
              {ctaLabel} {Ic.arrow}
            </Link>
            <Link className="nx-btn nx-btn-ghost" to={user ? '/app' : '/login'}>
              {user ? 'Open dashboard' : 'Sign in'}
            </Link>
          </div>
        </div>
      </section>

      {/* footer */}
      <footer className="nx-foot">
        <div className="nx-wrap">
          <div className="nx-foot-top">
            <div style={{ maxWidth: 300 }}>
              <div className="nx-brand" style={{ fontSize: 20 }}>
                <Logo />
                NEXUS
              </div>
              <p style={{ color: 'var(--muted)', fontSize: 14, marginTop: 14 }}>
                The secure property-rental platform for tenants, landlords, and administrators.
              </p>
            </div>
            <div className="nx-foot-links">
              <div className="nx-foot-col">
                <h5>Platform</h5>
                <a href="#listings">Listings</a>
                <a href="#how">How it works</a>
                <a href="#roles">For you</a>
                <a href="#trust">Security</a>
              </div>
              <div className="nx-foot-col">
                <h5>Company</h5>
                <a href="#trust">About</a>
                <a href="#roles">For you</a>
                <a href="#access">Get started</a>
                <a href="#how">How it works</a>
              </div>
              <div className="nx-foot-col">
                <h5>Account</h5>
                <Link to="/login">Sign in</Link>
                <Link to="/register">Create account</Link>
                <a href="#trust">Security</a>
                <a href="#access">Request access</a>
              </div>
            </div>
          </div>
          <div className="nx-foot-bot">
            <div className="nx-foot-copy">© {new Date().getFullYear()} Nexus. Secure rental infrastructure.</div>
            <div className="nx-foot-copy">Accra, Ghana</div>
          </div>
        </div>
      </footer>
    </div>
  );
}
