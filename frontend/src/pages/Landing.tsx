import { useEffect, useRef, useState } from 'react';
import type { CSSProperties } from 'react';
import { Link } from 'react-router';
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import Lenis from 'lenis';
import { useAuth } from '@/context/auth';
import { brand } from '@/config/brand';
import './landing.css';

import hero from '@/assets/landing/iconic/hero.jpg';
import focus from '@/assets/landing/iconic/focus.jpg';
import closing from '@/assets/landing/iconic/closing.jpg';
import dusk from '@/assets/landing/iconic/dusk.jpg';
import home1 from '@/assets/landing/iconic/home-1.jpg';
import home2 from '@/assets/landing/iconic/home-2.jpg';
import home3 from '@/assets/landing/iconic/home-3.jpg';
import home4 from '@/assets/landing/iconic/home-4.jpg';

gsap.registerPlugin(ScrollTrigger);

/* The collection shown in the gallery "viewer". Mirrors the source design's
   per-thumbnail metadata; rendered against real listings once browse is public. */
interface Home {
  img: string;
  loc: string;
  name: string;
  spec: string;
  rent: string;
  status: string;
  verified: boolean;
}

const HOMES: Home[] = [
  { img: home1, loc: 'East Legon, Accra', name: 'Wyncrest House', spec: '5 Beds · 4 Baths · Garden residence', rent: 'GH₵ 14,000', status: 'Available', verified: true },
  { img: home2, loc: 'Labadi, Accra', name: 'Casa del Mar', spec: '4 Beds · 5 Baths · Ocean view', rent: 'GH₵ 9,400', status: 'Available', verified: true },
  { img: home3, loc: 'Aburi, Eastern Region', name: 'Aburi Ridge Lodge', spec: '5 Beds · 4 Baths · Mountain view', rent: 'GH₵ 8,900', status: 'Available', verified: true },
  { img: home4, loc: 'Cantonments, Accra', name: 'Ridgeview Penthouse', spec: '3 Beds · 3 Baths · Skyline', rent: 'GH₵ 12,500', status: 'Occupied', verified: false },
];

export function Landing() {
  const { user } = useAuth();
  const rootRef = useRef<HTMLDivElement>(null);
  const fadeTimer = useRef<number | undefined>(undefined);

  const [navSolid, setNavSolid] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);
  const [vfDark, setVfDark] = useState(true);
  const [vfSection, setVfSection] = useState({ num: '01', label: 'Discover' });
  const [vfProg, setVfProg] = useState('000');
  const [active, setActive] = useState(0);
  const [stageVisible, setStageVisible] = useState(true);

  const ctaTo = user ? '/app' : '/register';

  /* ── Viewfinder + nav state (always on; cheap, no animation library) ─────── */
  useEffect(() => {
    const root = rootRef.current;
    if (!root) return;

    const onScroll = () => {
      const doc = document.documentElement;
      setNavSolid(window.scrollY > window.innerHeight * 0.7);
      const p = doc.scrollTop / (doc.scrollHeight - doc.clientHeight || 1);
      setVfProg(('00' + Math.round(p * 100)).slice(-3));
    };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });

    let io: IntersectionObserver | undefined;
    if ('IntersectionObserver' in window) {
      io = new IntersectionObserver(
        (entries) => {
          entries.forEach((e) => {
            if (!e.isIntersecting) return;
            const lbl = e.target.getAttribute('data-vf');
            if (lbl) {
              const [num, label] = lbl.split(' · ');
              setVfSection({ num, label: label ?? '' });
            }
            setVfDark(
              e.target.classList.contains('photo-scene') || e.target.hasAttribute('data-dark'),
            );
          });
        },
        { threshold: 0.5 },
      );
      root.querySelectorAll('[data-vf]').forEach((s) => io!.observe(s));
    }

    return () => {
      window.removeEventListener('scroll', onScroll);
      io?.disconnect();
    };
  }, []);

  /* ── GSAP scroll choreography + Lenis smooth scroll (skipped if reduced) ─── */
  useEffect(() => {
    const root = rootRef.current;
    if (!root) return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    let lenis: Lenis | null = null;
    const tickerFn = (t: number) => lenis?.raf(t * 1000);

    const ctx = gsap.context(() => {
      lenis = new Lenis({ duration: 1.15, smoothWheel: true });
      lenis.on('scroll', ScrollTrigger.update);
      gsap.ticker.add(tickerFn);
      gsap.ticker.lagSmoothing(0);

      // Smooth-scroll in-page anchors through Lenis.
      root.querySelectorAll<HTMLAnchorElement>('a[href^="#"]').forEach((a) => {
        a.addEventListener('click', (e) => {
          const target = root.querySelector(a.getAttribute('href') || '');
          if (target) {
            e.preventDefault();
            lenis?.scrollTo(target as HTMLElement, { offset: 0 });
          }
        });
      });

      // HERO: dolly-in + drift, headline rises, copy fades in.
      gsap.to('.hero-cam', { scale: 1.16, yPercent: 7, xPercent: -2, ease: 'none', scrollTrigger: { trigger: '.hero', start: 'top top', end: 'bottom top', scrub: true } });
      gsap.fromTo('.hero h1 .ln > span', { yPercent: 118 }, { yPercent: 0, duration: 1.1, ease: 'power3.out', stagger: 0.11, delay: 0.12 });
      gsap.fromTo('.hero-eyebrow,.hero-bottom', { y: 24, opacity: 0 }, { y: 0, opacity: 1, duration: 0.85, ease: 'power2.out', stagger: 0.12, delay: 0.55 });
      gsap.fromTo('.scroll-cue', { opacity: 0 }, { opacity: 1, duration: 1, delay: 1.1 });
      gsap.to('.hero-inner', { yPercent: -16, opacity: 0.35, ease: 'none', scrollTrigger: { trigger: '.hero', start: '32% top', end: 'bottom top', scrub: true } });

      // Reveal-on-scroll.
      gsap.utils.toArray<HTMLElement>('.reveal').forEach((el) => {
        gsap.to(el, { y: 0, opacity: 1, duration: 0.85, ease: 'power3.out', scrollTrigger: { trigger: el, start: 'top 88%' } });
      });

      // SIGNATURE: focus pull — blurred + scaled, snaps sharp as it centres.
      gsap.fromTo('.focus-cam', { filter: 'blur(22px)', scale: 1.18 }, { filter: 'blur(0px)', scale: 1.04, ease: 'none', scrollTrigger: { trigger: '.focus', start: 'top bottom', end: 'center center', scrub: true } });
      gsap.to('.focus-cam', { yPercent: 6, ease: 'none', scrollTrigger: { trigger: '.focus', start: 'top bottom', end: 'bottom top', scrub: true } });

      // Closing parallax.
      gsap.fromTo('.closing-cam', { scale: 1.14 }, { scale: 1, ease: 'none', scrollTrigger: { trigger: '.closing', start: 'top bottom', end: 'top top', scrub: true } });

      // THE POINT: the photo pans inside the word as you scroll.
      gsap.fromTo('.point-word', { backgroundPosition: '20% 40%' }, { backgroundPosition: '80% 44%', ease: 'none', scrollTrigger: { trigger: '.point-sec', start: 'top bottom', end: 'bottom top', scrub: true } });
      gsap.fromTo('.point-word', { scale: 0.94 }, { scale: 1, ease: 'none', scrollTrigger: { trigger: '.point-sec', start: 'top bottom', end: 'center center', scrub: true } });
    }, rootRef);

    const onLoad = () => ScrollTrigger.refresh();
    window.addEventListener('load', onLoad);

    return () => {
      window.removeEventListener('load', onLoad);
      gsap.ticker.remove(tickerFn);
      gsap.ticker.lagSmoothing(500, 33);
      lenis?.destroy();
      ctx.revert();
    };
  }, []);

  /* Gallery: cross-fade the stage when a frame is chosen. */
  function pick(i: number) {
    if (i === active) return;
    setStageVisible(false);
    window.clearTimeout(fadeTimer.current);
    fadeTimer.current = window.setTimeout(() => {
      setActive(i);
      setStageVisible(true);
    }, 170);
  }
  useEffect(() => () => window.clearTimeout(fadeTimer.current), []);

  function closeMenu() {
    setMenuOpen(false);
  }

  const cur = HOMES[active];

  return (
    <div className="wc" id="top" ref={rootRef}>
      {/* ── Viewfinder (the iconic framing device) ─────────────────────────── */}
      <div className={'viewfinder' + (vfDark ? ' on-dark' : '')} aria-hidden="true">
        <span className="vf-corner tl" /><span className="vf-corner tr" /><span className="vf-corner bl" /><span className="vf-corner br" />
        <span className="vf-meta tr">Accra · 5.56°N 0.20°W</span>
        <span className="vf-meta bl"><b>{vfSection.num}</b> &middot; {vfSection.label}</span>
        <span className="vf-meta br">GH₵ · {vfProg}</span>
      </div>

      {/* ── Nav ────────────────────────────────────────────────────────────── */}
      <nav className={'nav' + (navSolid || menuOpen ? ' solid' : '')}>
        <div className="nav-inner">
          <a href="#top" className="brand" onClick={closeMenu}>{brand.appName}<span className="dot" /></a>
          <div className={'nav-links' + (menuOpen ? ' open' : '')}>
            <a href="#discover" onClick={closeMenu}>Discover</a>
            <a href="#sides" onClick={closeMenu}>For Tenants</a>
            <a href="#sides" onClick={closeMenu}>For Landlords</a>
            <a href="#trust" onClick={closeMenu}>Trust</a>
          </div>
          <div className="nav-act">
            {!user && <Link to="/login" className="nav-signin">Sign in</Link>}
            <Link to={ctaTo} className="nav-cta">{user ? 'Dashboard' : 'Get started'}</Link>
          </div>
          <button
            className="burger"
            aria-label="Menu"
            aria-expanded={menuOpen}
            onClick={() => setMenuOpen((o) => !o)}
          >
            <span /><span /><span />
          </button>
        </div>
      </nav>

      {/* ── Hero ───────────────────────────────────────────────────────────── */}
      <header className="hero photo-scene" data-vf="01 · Discover">
        <div className="hero-cam"><img src={hero} alt="A verified Wyncrest home in Accra" /></div>
        <div className="hero-veil" />
        <div className="hero-inner">
          <div className="hero-eyebrow"><span className="label">Verified rentals · Ghana</span></div>
          <h1 className="disp">
            <span className="ln"><span>Before the keys,</span></span>
            <span className="ln"><span>know <span className="blood">everything.</span></span></span>
          </h1>
          <div className="hero-bottom">
            <div>
              <p className="hero-sub">Verified rentals across Accra, Tema and Kumasi, found, leased and paid for in one calm, honest place.</p>
              <div className="hero-ctas">
                <a href="#discover" className="btn btn-glass">Explore homes <span className="a">&rarr;</span></a>
                <Link to={ctaTo} className="btn btn-ghost-w">List a property</Link>
              </div>
            </div>
            <div className="now-viewing">
              <div className="nv-k">Now viewing</div>
              <div className="nv-name">{HOMES[0].name}</div>
              <div className="nv-loc">{HOMES[0].loc}</div>
            </div>
          </div>
        </div>
        <a href="#statement" className="scroll-cue"><span className="scl">Scroll</span><span className="bar" /></a>
      </header>

      {/* ── Statement ──────────────────────────────────────────────────────── */}
      <section className="statement" id="statement" data-vf="02 · The idea">
        <div className="wrap">
          <p className="reveal">Discovery, leasing, payments and trust. <span className="accent">Brought into one honest view.</span></p>
          <div className="st-note reveal">Built for the two people in every rental: the one moving in, and the one handing over the keys.</div>
        </div>
      </section>

      {/* ── Discovery (gallery viewer) ─────────────────────────────────────── */}
      <section className="sec" id="discover" data-vf="03 · The collection">
        <div className="wrap">
          <div className="sec-head">
            <div>
              <span className="idx reveal"><b>03</b> The collection</span>
              <h2 className="reveal" style={{ marginTop: '1rem' }}>A collection worth<br /><span className="it" style={{ color: 'var(--oxblood)' }}>coming home to.</span></h2>
            </div>
            <p className="sh-r reveal">Hand-reviewed rentals across the country. Choose a frame, then meet a home that is verified before it reaches you.</p>
          </div>
          <div className="viewer reveal">
            <div className="viewer-stage">
              <img src={cur.img} alt={cur.name} style={{ opacity: stageVisible ? 1 : 0 }} />
              <div className="vs-veil" />
              <span className="vs-corner tl" /><span className="vs-corner br" />
              <div className="vs-status">
                <span className="t"><span className="d" />{cur.status}</span>
                {cur.verified && <span className="t ver">Verified</span>}
              </div>
              <div className="vs-info">
                <div className="vs-loc">{cur.loc}</div>
                <div className="vs-name">{cur.name}</div>
                <div className="vs-spec">{cur.spec}</div>
              </div>
              <div className="vs-rent">{cur.rent}<small> /mo</small></div>
            </div>
            <div className="viewer-strip">
              {HOMES.map((h, i) => (
                <button
                  key={h.name}
                  className={'thumb' + (i === active ? ' active' : '')}
                  onClick={() => pick(i)}
                  onMouseEnter={() => pick(i)}
                  aria-label={'View ' + h.name}
                >
                  <img src={h.img} alt={h.name} loading="lazy" />
                  <span className="tn">{h.name}</span>
                </button>
              ))}
            </div>
            <div className="viewer-foot"><Link to={ctaTo} className="btn btn-line">View all homes <span className="a">&rarr;</span></Link></div>
          </div>
        </div>
      </section>

      {/* ── Two sides ──────────────────────────────────────────────────────── */}
      <section className="sec sides" id="sides" data-vf="04 · Two sides">
        <div className="wrap">
          <div className="sec-head">
            <div>
              <span className="idx reveal"><b>04</b> Two sides</span>
              <h2 className="reveal" style={{ marginTop: '1rem' }}>Both sides<br />of the lease.</h2>
            </div>
            <p className="sh-r reveal">The same {brand.appName}, shaped around what you came to do.</p>
          </div>
          <div className="sides-grid">
            <div className="side reveal">
              <span className="side-k">I&rsquo;m renting</span>
              <h3>Find a home</h3>
              <ul>
                <li>Browse verified listings and save the ones you love</li>
                <li>Apply once, then track your status in real time</li>
                <li>Lease, payments and notices in one calm view</li>
              </ul>
              <Link to={ctaTo} className="side-link">Start your search <span className="a">&rarr;</span></Link>
            </div>
            <div className="side reveal">
              <span className="side-k">I&rsquo;m listing</span>
              <h3>Manage your portfolio</h3>
              <ul>
                <li>List properties and units, reviewed before they go live</li>
                <li>Screen applicants and handle contracts in one place</li>
                <li>Collect rent and field maintenance from one console</li>
              </ul>
              <Link to={ctaTo} className="side-link">List a property <span className="a">&rarr;</span></Link>
            </div>
          </div>
        </div>
      </section>

      {/* ── Signature: focus pull ──────────────────────────────────────────── */}
      <section className="focus photo-scene" data-vf="05 · In focus">
        <div className="focus-cam"><img src={focus} alt="" /></div>
        <div className="focus-veil" />
        <div className="focus-inner">
          <h2 className="reveal">Every home,<br />brought into focus.</h2>
          <p className="reveal">Nothing dropped, nothing hidden between the two sides.</p>
        </div>
      </section>

      {/* ── Trust ──────────────────────────────────────────────────────────── */}
      <section className="sec trust" id="trust" data-vf="06 · On the record">
        <div className="wrap">
          <span className="idx reveal" style={{ justifyContent: 'center' }}><b>06</b> On the record</span>
          <h2 className="reveal">No one takes the <span className="it">other side&rsquo;s word for it.</span></h2>
          <p className="tr-sub reveal">Every meaningful action leaves a clear, reviewable record. That is what turns a rental from a leap of faith into a documented agreement.</p>
          <div className="chips reveal">
            {['Verified listings', 'Role-based access', 'Application status', 'Lease visibility', 'Payment history', 'Audit trail'].map((c) => (
              <span className="chip" key={c}>
                <svg viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5" strokeLinecap="round" strokeLinejoin="round" /></svg>{c}
              </span>
            ))}
          </div>
        </div>
      </section>

      {/* ── The point (image-in-type) ──────────────────────────────────────── */}
      <section className="point-sec" data-vf="07 · The point">
        <div className="wrap point-pin">
          <span className="idx point-eyebrow reveal" style={{ justifyContent: 'center' }}><b>07</b> The point of it all</span>
          <div className="point-word" style={{ '--fill-img': `url(${dusk})` } as CSSProperties}>HOME</div>
          <p className="point-sub reveal">More than a listing. A place you can actually trust.</p>
          <p className="point-note reveal">From the home you choose to the record that holds it, everything stays in one honest, shared view.</p>
        </div>
      </section>

      {/* ── Closing ────────────────────────────────────────────────────────── */}
      <section className="closing photo-scene" id="closing" data-vf="08 · Enter">
        <div className="closing-cam"><img src={closing} alt="" /></div>
        <div className="closing-veil" />
        <div className="closing-inner">
          <span className="label" style={{ color: 'rgba(255,255,255,.78)' }}>Get started</span>
          <h2 className="reveal" style={{ marginTop: '1rem' }}>Step inside<br /><span className="it">{brand.appName}.</span></h2>
          <p className="reveal">Find, manage and trust the journey home, all in one place.</p>
          <div className="closing-ctas reveal">
            <Link to={ctaTo} className="btn btn-glass">{user ? 'Open dashboard' : 'Create your account'} <span className="a">&rarr;</span></Link>
            <Link to={user ? '/app' : '/login'} className="btn btn-ghost-w">{user ? 'Dashboard' : 'Sign in'}</Link>
          </div>
        </div>
      </section>

      {/* ── Footer ─────────────────────────────────────────────────────────── */}
      <footer className="foot" data-vf="" data-dark>
        <div className="foot-cols">
          <div>
            <div className="brand disp" style={{ fontSize: '2rem' }}>{brand.appName}<span style={{ color: 'var(--oxblood)' }}>.</span></div>
            <p className="fc-tag">The complete platform for Ghana&rsquo;s rental market: verified, documented, and built for both sides.</p>
          </div>
          <div className="foot-links">
            <div className="fc"><h4>Platform</h4><a href="#discover">Discover</a><a href="#sides">Tenants</a><a href="#sides">Landlords</a><a href="#trust">Trust</a></div>
            <div className="fc"><h4>Company</h4><a href="#statement">About</a><a href="#trust">Security</a><a href={`mailto:${brand.supportEmail}`}>Contact</a></div>
            <div className="fc"><h4>Account</h4><Link to="/login">Sign in</Link><Link to={ctaTo}>Get started</Link><Link to="/admin/login">Admin console</Link></div>
          </div>
        </div>
        <div className="foot-bottom"><span>© {new Date().getFullYear()} {brand.appName} · Secure rental infrastructure</span><span>Accra, Ghana</span></div>
      </footer>
    </div>
  );
}
