/* ==========================================================================
   MASTERCLASS — settings + page behaviour
   Edit CONFIG for each batch. Everything on the page reads from it.
   ========================================================================== */

const CONFIG = {
  startISO: '2026-11-12T15:00:00+05:30', // start time, IST
  durationHours: 3,
  platform: 'Live on Zoom',

  price: 1499,          // masterclass seat
  bumpPrice: 499,       // recording + The AI Editor’s Vault (tick box on the page)
  oneToOnePrice: 14788,

  // Razorpay server files (api/razorpay/). These prices are only for display:
  // what the buyer actually pays is set in the private config on the server.
  apiPath: '/api/razorpay/',
  oneToOneUrl: 'https://learn.thegurukulofai.com/web/checkout/6ae7f96ca307d6636a581fbe',

  // Proof videos. Paste a direct .mp4 link (e.g. Bunny CDN) to show the "See it before you book" section.
  // introVertical: true if the intro video is 9:16.
  videos: {
    intro: '', introPoster: '', introVertical: false,
    raw: '', rawPoster: '',       // the raw clip
    final: '', finalPoster: '',   // the same clip, edited by Claude
  },

  // "What people are saying" wall. Drop real screenshots in assets/images/masterclass/proof/
  // and list them here with their pixel size (stops the page jumping while they load).
  // TODO: placeholders until Deenu sends the originals.
  screensDir: '../assets/images/masterclass/proof/',
  screens: [
    { src: 'placeholder-01.svg', w: 800, h: 390, alt: 'Placeholder screenshot' },
    { src: 'placeholder-02.svg', w: 800, h: 190, alt: 'Placeholder screenshot' },
    { src: 'placeholder-03.svg', w: 800, h: 356, alt: 'Placeholder screenshot' },
    { src: 'placeholder-04.svg', w: 800, h: 326, alt: 'Placeholder screenshot' },
    { src: 'placeholder-05.svg', w: 800, h: 250, alt: 'Placeholder screenshot' },
    { src: 'placeholder-06.svg', w: 800, h: 622, alt: 'Placeholder screenshot' },
    { src: 'placeholder-07.svg', w: 800, h: 190, alt: 'Placeholder screenshot' },
    { src: 'placeholder-08.svg', w: 800, h: 568, alt: 'Placeholder screenshot' },
    { src: 'placeholder-09.svg', w: 800, h: 424, alt: 'Placeholder screenshot' },
    { src: 'placeholder-10.svg', w: 800, h: 190, alt: 'Placeholder screenshot' },
    { src: 'placeholder-11.svg', w: 800, h: 690, alt: 'Placeholder screenshot' },
    { src: 'placeholder-12.svg', w: 800, h: 538, alt: 'Placeholder screenshot' },
  ],

  // "What they're making" strip. The list repeats until the strip is full.
  // loop: a short silent clip (about 6 s, 360px wide) that plays on repeat in the strip.
  // src: the full reel with sound, opened when someone taps a tile.
  // Reels live on Bunny under masterclass/. Upload with scripts/bunny-upload-masterclass.sh.
  reelsDir: 'https://tgai-cdn-2.b-cdn.net/masterclass/',
  reels: [
    { name: 'Deenu Khan',      handle: 'neuraltech.ai',    src: 'deenu-1.mp4', loop: 'deenu-1-loop.mp4', poster: 'deenu-1-poster.webp' },
    { name: 'Nishant Chahar',  handle: 'unscripted.nishant', src: 'nishant.mp4', loop: 'nishant-loop.mp4', poster: 'nishant-poster.webp' },
    { name: 'Vishnu Vijayan',  handle: 'v.i.s.h.ai',       src: 'vishnu.mp4',  loop: 'vishnu-loop.mp4', poster: 'vishnu-poster.webp' },
    { name: 'Farhan Rakhangi', handle: 'farhannrakhangi',  src: 'farhan.mp4',  loop: 'farhan-loop.mp4', poster: 'farhan-poster.webp' },
    { name: 'Achintya Singh', handle: '21.coders', src: 'achintya.mp4', loop: 'achintya-loop.mp4', poster: 'achintya-poster.webp' },
    { name: 'Ankit Jha', handle: 'fullstackmodiji', src: 'ankit.mp4', loop: 'ankit-loop.mp4', poster: 'ankit-poster.webp' },
    { name: 'Deenu Khan',      handle: 'neuraltech.ai',    src: 'deenu-2.mp4', loop: 'deenu-2-loop.mp4', poster: 'deenu-2-poster.webp' },
    { name: 'Nandini Sharma', handle: 'thisis_alaska_', src: 'nandini.mp4', loop: 'nandini-loop.mp4', poster: 'nandini-poster.webp' },
    { name: 'Rishav Jha', handle: 'rishavbhaiyaa', src: 'rishav.mp4', loop: 'rishav-loop.mp4', poster: 'rishav-poster.webp' },
  ],
};

(function () {
  'use strict';

  document.documentElement.classList.add('js');
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const inr = (n) => Number(n).toLocaleString('en-IN');
  const $ = (s, root = document) => root.querySelector(s);
  const $$ = (s, root = document) => [...root.querySelectorAll(s)];

  /* ---------- Dates and values from CONFIG ---------- */
  const start = new Date(CONFIG.startISO);
  const end = new Date(start.getTime() + CONFIG.durationHours * 3600 * 1000);
  const ist = { timeZone: 'Asia/Kolkata' };
  const clock = (d, withMinutes) => {
    const s = d.toLocaleTimeString('en-US', { ...ist, hour: 'numeric', minute: '2-digit' });
    return withMinutes ? s : s.replace(':00', '');
  };
  const bare = (d) => clock(d).replace(/\s?(AM|PM)$/, '');

  const values = {
    price: inr(CONFIG.price),
    bumpPrice: inr(CONFIG.bumpPrice),
    oneToOnePrice: inr(CONFIG.oneToOnePrice),
    platform: CONFIG.platform,
    durationWords: `${CONFIG.durationHours} hours`,
  };
  if (!isNaN(start)) {
    const samePeriod = clock(start).slice(-2) === clock(end).slice(-2);
    values.date = start.toLocaleDateString('en-GB', { ...ist, day: 'numeric', month: 'long', year: 'numeric' });
    values.dateCompact = start.toLocaleDateString('en-GB', { ...ist, weekday: 'short', day: 'numeric', month: 'short' });
    values.dateFull = start.toLocaleDateString('en-GB', { ...ist, weekday: 'long', day: 'numeric', month: 'long' });
    values.time = `${clock(start)} – ${clock(end)} IST`;
    values.timeCompact = `${samePeriod ? bare(start) : clock(start)}–${clock(end)} IST`;
    values.timeStart = clock(start);
    values.dateShort = `${values.dateCompact.replace(',', '')} · ${clock(start)} IST · ${CONFIG.platform.replace(/^Live on /, '')}`;

    // Agenda clock times
    $$('time[data-offset]').forEach((t) => {
      const at = new Date(start.getTime() + Number(t.dataset.offset) * 60000);
      t.textContent = clock(at, true);
      t.dateTime = at.toISOString();
    });
  }
  $$('[data-mc]').forEach((el) => {
    const v = values[el.dataset.mc];
    if (v) el.textContent = v;
  });

  /* ---------- Links (pass ad UTM tags through to checkout) ---------- */
  const withParams = (url) => {
    if (!url) return url;
    const params = new URLSearchParams(window.location.search);
    const u = new URL(url);
    params.forEach((v, k) => { if (!u.searchParams.has(k)) u.searchParams.set(k, v); });
    return u.toString();
  };
  $$('.js-oneone').forEach((a) => { a.href = withParams(CONFIG.oneToOneUrl); });

  /* ---------- Add-on tick box: total + which checkout ---------- */
  // The tick box appears in the pricing card and in the pop-up. They stay in step.
  const bumps = $$('.js-bump');
  let addon = false;
  const showTotals = () => {
    $$('.js-total').forEach((t) => { t.textContent = inr(CONFIG.price + (addon ? CONFIG.bumpPrice : 0)); });
  };
  const syncBump = (on) => {
    addon = on;
    bumps.forEach((b) => { b.checked = on; });
    showTotals();
  };
  bumps.forEach((b) => b.addEventListener('change', () => syncBump(b.checked)));
  syncBump(false);

  /* ---------- Checkout pop-up + Razorpay ---------- */
  const payBox = $('.mc-pay');
  if (payBox && payBox.showModal) {
    const form = $('.mc-pay-form', payBox);
    const msg = $('.mc-pay-msg', payBox);
    const submit = $('.js-pay-submit', payBox);
    const label = $('.js-pay-label', payBox);
    const labelHtml = label.innerHTML;
    const query = new URLSearchParams(window.location.search);

    // Local preview talks to the server files on the live site (or a test server).
    const isLocal = window.location.hostname === 'localhost';
    const api = isLocal ? (query.get('api') || `https://thegurukulofai.com${CONFIG.apiPath}`) : CONFIG.apiPath;

    let sdk = null;
    const loadRazorpay = () => {
      sdk = sdk || new Promise((resolve, reject) => {
        if (window.Razorpay) { resolve(); return; }
        const s = document.createElement('script');
        s.src = 'https://checkout.razorpay.com/v1/checkout.js';
        s.onload = resolve;
        s.onerror = () => { sdk = null; reject(new Error('sdk')); };
        document.head.append(s);
      });
      return sdk;
    };

    const say = (text, tone) => {
      msg.textContent = text || '';
      msg.hidden = !text;
      msg.classList.toggle('is-info', tone === 'info');
    };
    let busy = false;
    const setBusy = (on, text) => {
      busy = on;
      submit.disabled = on;
      if (on) label.textContent = text;
      else {
        label.innerHTML = labelHtml;
        showTotals();
      }
    };

    const openPay = () => {
      say('');
      if (!payBox.open) payBox.showModal();
      loadRazorpay().catch(() => {});
    };
    $$('a.mc-cta[href="#pricing"], .js-pay').forEach((a) => {
      a.addEventListener('click', (e) => { e.preventDefault(); openPay(); });
    });
    $('.mc-pay-x', payBox).addEventListener('click', () => payBox.close());
    payBox.addEventListener('click', (e) => { if (e.target === payBox && !busy) payBox.close(); });
    payBox.addEventListener('cancel', (e) => { if (busy) e.preventDefault(); });

    const check = (d) => {
      if (d.name.length < 2) return ['name', 'Please enter your full name.'];
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(d.email)) return ['email', 'Please enter a valid email.'];
      if (!/^[6-9]\d{9}$/.test(d.phone)) return ['phone', 'Please enter a 10-digit Indian mobile number.'];
      return null;
    };
    const trouble = {
      invalid_details: 'Please check your name, email and number.',
      sales_closed: 'Seats for this batch have closed.',
    };

    // Razorpay already has the money by now, so never show the buyer a failure.
    // If this check can't finish, the thank-you page points them to their email.
    const finish = async (response) => {
      let community = '';
      try {
        const r = await fetch(`${api}verify.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(response),
        });
        const data = await r.json();
        if (data.ok) community = data.community_url || '';
      } catch (e) { /* the thank-you page handles a missing link */ }
      try {
        sessionStorage.setItem('mc_paid', JSON.stringify({ community, addon }));
      } catch (e) { /* private mode: same fallback */ }
      window.location.href = 'thank-you/';
    };

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (busy) return;
      const fields = new FormData(form);
      const details = {
        name: String(fields.get('name') || '').trim(),
        email: String(fields.get('email') || '').trim(),
        phone: String(fields.get('phone') || '').replace(/\D/g, '').replace(/^(?:91|0)(?=\d{10}$)/, ''),
      };
      const problem = check(details);
      if (problem) {
        say(problem[1]);
        form.elements[problem[0]].focus();
        return;
      }

      say('');
      setBusy(true, 'Opening secure payment...');
      let data;
      try {
        await loadRazorpay();
        const utm = {};
        ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'].forEach((k) => {
          if (query.get(k)) utm[k] = query.get(k);
        });
        const r = await fetch(`${api}create-order.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ ...details, addon, utm }),
        });
        data = await r.json();
      } catch (err) {
        data = { ok: false };
      }
      setBusy(false);
      if (!data.ok) {
        say(trouble[data.error] || "We couldn't start the payment. Please try again. If it keeps failing, see the Contact link at the bottom of this page.");
        return;
      }

      const rzp = new window.Razorpay({
        key: data.key,
        order_id: data.order_id,
        amount: data.amount,
        currency: 'INR',
        name: 'The Gurukul of AI',
        description: addon ? 'AI Video Editing Masterclass + recording' : 'AI Video Editing Masterclass',
        image: new URL('../assets/images/favicon.png', window.location.href).href,
        prefill: { name: details.name, email: details.email, contact: `+91${data.phone}` },
        theme: { color: '#023C44' },
        handler: finish,
        modal: {
          confirm_close: true,
          ondismiss: () => {
            payBox.showModal();
            say("Your payment wasn't completed. You can try again. If money left your account, see the Contact link at the bottom of this page.", 'info');
          },
        },
      });
      // A modal <dialog> sits above everything, so it must close first or Razorpay's window is hidden behind it.
      payBox.close();
      rzp.open();
    });
  }

  /* ---------- Proof videos ---------- */
  const v = CONFIG.videos || {};
  const proof = $('#proof');
  const setVideo = (name, src, poster) => {
    const box = $(`[data-video="${name}"]`);
    if (!box || !src) return false;
    const video = $('video', box);
    video.src = src;
    if (poster) video.poster = poster;
    box.hidden = false;
    return true;
  };
  if (proof) {
    const intro = setVideo('intro', v.intro, v.introPoster);
    if (intro && v.introVertical) $('[data-video="intro"]').classList.add('is-vertical');
    const raw = setVideo('raw', v.raw, v.rawPoster);
    const fin = setVideo('final', v.final, v.finalPoster);
    if (raw || fin) $('.mc-pair', proof).hidden = false;
    proof.hidden = !(intro || raw || fin);
  }

  /* ---------- Social proof: screenshot wall + reel strip ---------- */
  const lightbox = $('.mc-lightbox');
  const lbBody = lightbox && $('.mc-lightbox-body', lightbox);
  const openLightbox = (node) => {
    if (!lightbox || !lightbox.showModal) return;
    lbBody.replaceChildren(node);
    lightbox.showModal();
  };
  if (lightbox) {
    const close = () => lightbox.close();
    $('.mc-lightbox-x', lightbox).addEventListener('click', close);
    lightbox.addEventListener('click', (e) => { if (e.target === lightbox) close(); });
    lightbox.addEventListener('close', () => {
      const vid = $('video', lbBody);
      if (vid) vid.pause();
      lbBody.replaceChildren();
    });
  }

  // Builds a looping strip: the set is rendered twice and slid by -50%.
  const buildMarquee = (host, makeItems, pxPerSec) => {
    const track = document.createElement('div');
    track.className = 'mc-track';
    const setA = document.createElement('div');
    setA.className = 'mc-set';
    makeItems().forEach((n) => setA.append(n));
    const setB = setA.cloneNode(true);
    setB.setAttribute('aria-hidden', 'true');
    $$('button', setB).forEach((b) => { b.tabIndex = -1; });
    track.append(setA, setB);
    host.append(track);
    // Same speed on every screen size
    const sync = () => track.style.setProperty('--dur', `${Math.max(20, setA.scrollWidth / pxPerSec)}s`);
    sync();
    window.addEventListener('resize', sync, { passive: true });
    track.addEventListener('click', (e) => {
      const btn = e.target.closest('button[data-i]');
      if (btn) host.dispatchEvent(new CustomEvent('pick', { detail: Number(btn.dataset.i) }));
    });
    // Only animate while on screen
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(([e]) => host.classList.toggle('is-moving', e.isIntersecting)).observe(host);
    } else {
      host.classList.add('is-moving');
    }
  };

  const shotsHost = $('[data-screens]');
  if (shotsHost && CONFIG.screens && CONFIG.screens.length) {
    const COL_H = 560; // stack screenshots into columns about this tall (at 280px wide)
    buildMarquee(shotsHost, () => {
      const cols = [];
      let col = null;
      let used = 0;
      CONFIG.screens.forEach((s, i) => {
        const h = 280 * (s.h / s.w);
        if (!col || used + h > COL_H) {
          col = document.createElement('div');
          col.className = 'mc-shot-col';
          cols.push(col);
          used = 0;
        }
        used += h + 12;
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'mc-shot';
        b.dataset.i = i;
        b.setAttribute('aria-label', `Open: ${s.alt}`);
        const img = document.createElement('img');
        img.src = CONFIG.screensDir + s.src;
        img.alt = s.alt;
        img.width = s.w;
        img.height = s.h;
        img.loading = 'lazy';
        img.decoding = 'async';
        b.append(img);
        col.append(b);
      });
      return cols;
    }, 28);
    shotsHost.addEventListener('pick', (e) => {
      const s = CONFIG.screens[e.detail];
      const img = new Image();
      img.src = CONFIG.screensDir + s.src;
      img.alt = s.alt;
      openLightbox(img);
    });
  }

  const reelsHost = $('[data-reels]');
  if (reelsHost && CONFIG.reels && CONFIG.reels.length) {
    const MIN_TILES = 10;
    buildMarquee(reelsHost, () => {
      const out = [];
      for (let k = 0; out.length < Math.max(MIN_TILES, CONFIG.reels.length); k++) {
        const i = k % CONFIG.reels.length;
        const r = CONFIG.reels[i];
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'mc-reel';
        b.dataset.i = i;
        b.setAttribute('aria-label', `Play reel by ${r.name}`);
        b.innerHTML = `
          <span class="mc-reel-frame">
            <video muted loop playsinline preload="none" data-poster="${CONFIG.reelsDir + r.poster}" data-src="${CONFIG.reelsDir + (r.loop || r.src)}"></video>
            <span class="mc-reel-play" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 5.5v13l11-6.5z"/></svg></span>
          </span>
          <span class="mc-reel-name"></span>
          <span class="mc-reel-handle"></span>`;
        $('.mc-reel-name', b).textContent = r.name;
        $('.mc-reel-handle', b).textContent = `@${r.handle}`;
        out.push(b);
      }
      return out;
    }, 36);
    // Posters load when the strip comes near. Each tile plays only while it is on screen,
    // and people on data saver or reduced motion see the poster alone.
    const loops = $$('video', reelsHost);
    const stillOnly = reduceMotion || (navigator.connection && navigator.connection.saveData);
    const showPosters = () => loops.forEach((v) => { v.poster = v.dataset.poster; });
    loops.forEach((v) => { v.muted = true; });
    if ('IntersectionObserver' in window) {
      const near = new IntersectionObserver(([e]) => {
        if (!e.isIntersecting) return;
        showPosters();
        near.disconnect();
      }, { rootMargin: '400px 0px' });
      near.observe(reelsHost);
      if (!stillOnly) {
        const seen = new IntersectionObserver((entries) => entries.forEach(({ target: v, isIntersecting }) => {
          if (isIntersecting) {
            if (!v.src) v.src = v.dataset.src;
            v.play().catch(() => {});
          } else if (v.src) {
            v.pause();
          }
        }));
        loops.forEach((v) => seen.observe(v));
      }
    } else {
      showPosters();
    }
    reelsHost.addEventListener('pick', (e) => {
      const r = CONFIG.reels[e.detail];
      const vid = document.createElement('video');
      vid.src = CONFIG.reelsDir + r.src;
      vid.poster = CONFIG.reelsDir + r.poster;
      vid.controls = true;
      vid.playsInline = true;
      vid.autoplay = true;
      vid.className = 'is-reel';
      openLightbox(vid);
      vid.play().catch(() => {});
    });
  }

  /* ---------- Countdown ---------- */
  const cd = $('[data-countdown]');
  if (cd) {
    if (isNaN(start) || start <= new Date()) {
      cd.hidden = true;
    } else {
      const out = { d: $('[data-cd="d"]', cd), h: $('[data-cd="h"]', cd), m: $('[data-cd="m"]', cd), s: $('[data-cd="s"]', cd) };
      const pad = (n) => String(n).padStart(2, '0');
      let timer;
      const tick = () => {
        const ms = Math.max(0, start - new Date());
        const t = Math.floor(ms / 1000);
        out.d.textContent = pad(Math.floor(t / 86400));
        out.h.textContent = pad(Math.floor((t % 86400) / 3600));
        out.m.textContent = pad(Math.floor((t % 3600) / 60));
        out.s.textContent = pad(t % 60);
        if (ms === 0) { cd.hidden = true; clearInterval(timer); }
      };
      tick();
      timer = setInterval(tick, 1000);
    }
  }

  /* ---------- Timeline animation (plays when scrolled into view) ---------- */
  const tl = $('.tl');
  if (tl && !reduceMotion && 'IntersectionObserver' in window) {
    $$('.tl-row', tl).forEach((row, r) => {
      $$('.clip', row).forEach((clip, i) => clip.style.setProperty('--d', 700 + r * 220 + i * 60));
    });
    const typed = $('.tl-typed', tl);
    const full = typed.dataset.text;
    tl.classList.add('is-live');
    typed.textContent = '';
    const io = new IntersectionObserver(([e]) => {
      if (!e.isIntersecting) return;
      io.disconnect();
      tl.classList.add('is-playing');
      typed.classList.add('is-typing');
      let i = 0;
      const type = () => {
        typed.textContent = full.slice(0, ++i);
        if (i < full.length) setTimeout(type, 18);
        else setTimeout(() => typed.classList.remove('is-typing'), 1600);
      };
      setTimeout(type, 200);
    }, { threshold: 0.35 });
    io.observe(tl);
  }

  /* ---------- Scroll reveal (fires before the element reaches the screen) ---------- */
  const revealEls = $$('.mc-block > *, .mc-stats li, .mc-final > *, .mc-zig li');
  if ('IntersectionObserver' in window && !reduceMotion) {
    revealEls.forEach((el) => el.classList.add('mc-reveal'));
    const io = new IntersectionObserver((entries) => {
      entries.forEach((e) => {
        if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); }
      });
    }, { rootMargin: '0px 0px 15% 0px' });
    revealEls.forEach((el) => io.observe(el));
  }

  /* ---------- Scroll progress + booking bar ---------- */
  const progress = $('.mc-progress');
  const onScroll = () => {
    const h = document.documentElement;
    const p = h.scrollTop / Math.max(1, h.scrollHeight - h.clientHeight);
    if (progress) progress.style.setProperty('--p', p.toFixed(4));
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  const bar = $('.mc-bar');
  const heroCta = $('#hero-cta');
  const pricing = $('#pricing');
  if (bar && heroCta && pricing && 'IntersectionObserver' in window) {
    let ctaVisible = true;
    let pricingVisible = false;
    const update = () => bar.classList.toggle('is-on', !ctaVisible && !pricingVisible);
    new IntersectionObserver(([e]) => { ctaVisible = e.isIntersecting; update(); }).observe(heroCta);
    new IntersectionObserver(([e]) => { pricingVisible = e.isIntersecting; update(); }, { threshold: 0.15 }).observe(pricing);
  }
})();
