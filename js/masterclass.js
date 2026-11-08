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

  // "What people are saying" wall: real comments, hosted on Bunny under masterclass/.
  // List each with its pixel size, which stops the page jumping while they load.
  screensDir: 'https://tgai-cdn-2.b-cdn.net/masterclass/',
  screens: [
    { src: 'proof-sahil.webp', w: 640, h: 154, bg: '#171C1C', col: 0, alt: 'sahilverma.ai: Deenu has helped me a lot with integrating AI into my video editing workflow. I have become 10x faster because of him.' },
    { src: 'proof-abhay.webp', w: 640, h: 103, bg: '#171C1C', col: 0, alt: 'abhaycreate: This is one of the most important reels I have seen, because it literally changed my video editing. Thanks sir.' },
    { src: 'proof-talkpro.webp', w: 640, h: 81, bg: '#171C1C', col: 0, alt: 'talkprowithaman: This is very helpful bhai, thanks for sharing. Kaafi time save ho gaya.' },
    { src: 'proof-dm.webp', w: 640, h: 145, bg: '#25292E', col: 1, alt: 'A message from a creator: Hii bro, your video editing workflow is really helpful and even I am using it to automate my video editing. People must try this.' },
    { src: 'proof-farhan.webp', w: 640, h: 106, bg: '#171C1C', col: 1, alt: 'farhannrakhangi: Deenu, honestly your workflow has helped me a lot, especially making the edits look much more premium.' },
    { src: 'proof-fullstack.webp', w: 640, h: 81, bg: '#171C1C', col: 1, alt: 'fullstackmodiji: This helped us a lot in our workflow. Gem video on Instagram. Thanks a lot.' },
    { src: 'proof-rahul.webp', w: 640, h: 151, bg: '#171C1C', col: 2, alt: 'socialcanvasbyrahul: This will save me countless hours while focusing more on content planning rather than editing videos.' },
    { src: 'proof-tirth.webp', w: 640, h: 83, bg: '#191B1F', col: 2, alt: 'tirthpatel00: Bye bye editors.' },
    { src: 'proof-nandini.webp', w: 640, h: 83, bg: '#171C1C', col: 2, alt: 'techwithnandini.ai: Amazing.' },
    { src: 'proof-atul.webp', w: 640, h: 78, bg: '#171C1C', col: 2, alt: 'atul.aicoach: AI ;)' },
    { src: 'proof-rajhere.webp', w: 640, h: 149, bg: '#171C1C', col: 3, alt: 'rajhere.in: This workflow is genuinely useful. Things that would normally require good editing skills can now be done by writing a prompt.' },
    { src: 'proof-abhishek.webp', w: 640, h: 118, bg: '#212328', col: 3, alt: 'abhishek1594: Can we input multiple raw videos and ask Claude to combine them into one edited video? neuraltech.ai: Yes we can do that.' },
    { src: 'proof-rishav.webp', w: 640, h: 81, bg: '#191B1F', col: 3, alt: 'rishavbhaiyaa: crazyyyyyyyy' },
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
      too_many_attempts: 'Too many attempts. Please wait a few minutes and try again.',
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
    track.append(setA);
    host.append(track);
    // Very wide screens: repeat the set so the loop never shows a gap.
    const originals = [...setA.children];
    const fill = Math.max(window.innerWidth, window.screen.width) + 100;
    for (let i = 0; i < 3 && setA.scrollWidth < fill; i++) {
      originals.forEach((n) => setA.append(n.cloneNode(true)));
    }
    const setB = setA.cloneNode(true);
    setB.setAttribute('aria-hidden', 'true');
    $$('button', setB).forEach((b) => { b.tabIndex = -1; });
    track.append(setB);
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
    buildMarquee(shotsHost, () => {
      const cols = [];
      CONFIG.screens.forEach((s, i) => {
        if (!cols[s.col]) {
          cols[s.col] = document.createElement('div');
          cols[s.col].className = 'mc-shot-col';
        }
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'mc-shot';
        b.dataset.i = i;
        b.style.background = s.bg;
        b.setAttribute('aria-label', `Open: ${s.alt}`);
        const img = document.createElement('img');
        img.src = CONFIG.screensDir + s.src;
        img.alt = s.alt;
        img.width = s.w;
        img.height = s.h;
        img.loading = 'lazy';
        img.decoding = 'async';
        b.append(img);
        cols[s.col].append(b);
      });
      return cols;
    }, 28);
    shotsHost.addEventListener('pick', (e) => {
      const s = CONFIG.screens[e.detail];
      const img = new Image();
      img.src = CONFIG.screensDir + s.src;
      img.alt = s.alt;
      img.style.background = s.bg;
      openLightbox(img);
    });
  }

  const reelsHost = $('[data-reels]');
  if (reelsHost && CONFIG.reels && CONFIG.reels.length) {
    buildMarquee(reelsHost, () => {
      const out = [];
      for (let k = 0; out.length < CONFIG.reels.length; k++) {
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

  /* ---------- Photo and video wall ---------- */
  const aura = $('.mc-aura-grid');
  if (aura) {
    const GALLERY_CDN = 'https://tgai-cdn-2.b-cdn.net/gallery/';
    const canAutoplay = 'IntersectionObserver' in window && !reduceMotion && !(navigator.connection && navigator.connection.saveData);
    const seen = canAutoplay ? new IntersectionObserver((entries) => entries.forEach(({ target: v, isIntersecting }) => {
      if (isIntersecting) {
        if (!v.src) v.src = v.dataset.src;
        v.play().catch(() => {});
      } else if (v.src) {
        v.pause();
      }
    }), { threshold: 0.25 }) : null;
    if (seen) $$('video', aura).forEach((v) => seen.observe(v));

    aura.addEventListener('click', (e) => {
      const tile = e.target.closest('.mc-aura-tile');
      if (!tile) return;
      if (tile.dataset.video) {
        const vid = document.createElement('video');
        vid.src = tile.dataset.video;
        vid.controls = true;
        vid.playsInline = true;
        vid.autoplay = true;
        vid.className = 'is-reel';
        openLightbox(vid);
        vid.play().catch(() => {});
      } else {
        const img = new Image();
        img.src = tile.dataset.image;
        img.alt = tile.getAttribute('aria-label').replace(/^Open: /, '');
        openLightbox(img);
      }
    });

    // "See my full journey" opens every gallery photo and video right here on the page.
    const makeTile = (item) => {
      const li = document.createElement('li');
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'mc-aura-tile';
      const base = `../assets/gallery/${item.s}-800`;
      const cap = document.createElement('span');
      cap.className = 'mc-aura-cap';
      cap.textContent = item.c;
      if (item.v) {
        b.dataset.video = GALLERY_CDN + item.v;
        b.setAttribute('aria-label', `Play: ${item.c}`);
        const v = document.createElement('video');
        v.muted = true;
        v.loop = true;
        v.playsInline = true;
        v.preload = 'none';
        v.poster = `${base}.webp`;
        v.dataset.src = GALLERY_CDN + item.l;
        const play = document.createElement('span');
        play.className = 'mc-aura-play';
        play.setAttribute('aria-hidden', 'true');
        play.innerHTML = '<svg viewBox="0 0 24 24"><path d="M8 5.5v13l11-6.5z"/></svg>';
        b.append(v, play, cap);
        if (seen) seen.observe(v);
      } else {
        b.dataset.image = `../assets/gallery/${item.s}-1400.webp`;
        b.setAttribute('aria-label', `Open: ${item.c}`);
        const pic = document.createElement('picture');
        const src = document.createElement('source');
        src.srcset = `${base}.avif`;
        src.type = 'image/avif';
        const img = document.createElement('img');
        img.src = `${base}.webp`;
        img.alt = item.c;
        img.width = item.w;
        img.height = item.h;
        img.loading = 'lazy';
        img.decoding = 'async';
        pic.append(src, img);
        b.append(pic, cap);
      }
      li.append(b);
      return li;
    };

    const more = $('[data-more]');
    let extras = null;
    let isOpen = false;
    if (more) more.addEventListener('click', async (e) => {
      e.preventDefault();
      if (!extras) {
        try {
          const r = await fetch('../js/masterclass-gallery.json?v=1');
          extras = (await r.json()).map(makeTile);
        } catch (err) {
          window.location.href = more.href;
          return;
        }
      }
      isOpen = !isOpen;
      if (isOpen) {
        aura.append(...extras);
      } else {
        extras.forEach((li) => {
          const v = $('video', li);
          if (v && v.src) v.pause();
          li.remove();
        });
        more.closest('.mc-aura').scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
      aura.classList.toggle('is-open', isOpen);
      more.classList.toggle('is-open', isOpen);
      more.setAttribute('aria-expanded', String(isOpen));
      $('span', more).textContent = isOpen ? 'Show less' : 'See my full journey';
    });
  }

  /* ---------- Before / after wipe ---------- */
  const wipe = $('[data-wipe]');
  if (wipe) {
    const raw = $('.mc-wipe-raw', wipe);
    const edit = $('.mc-wipe-edit', wipe);
    const sound = $('.mc-wipe-sound', wipe);
    const stillOnly = reduceMotion || (navigator.connection && navigator.connection.saveData);
    const setLine = (percent) => wipe.style.setProperty('--x', `${percent}%`);

    // The line rests in the middle and only moves when someone slides it.
    const playBoth = () => {
      [raw, edit].forEach((v) => {
        if (!v.src) v.src = v.dataset.src;
        v.play().catch(() => {});
      });
    };
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(([e]) => {
        if (e.isIntersecting) {
          if (!stillOnly) playBoth();
        } else if (edit.src) {
          raw.pause();
          edit.pause();
        }
      }, { threshold: 0.2 }).observe(wipe);
    }
    // The reel is the master clock, so the two never drift apart.
    edit.addEventListener('timeupdate', () => {
      if (Math.abs(raw.currentTime - edit.currentTime) > 0.12) raw.currentTime = edit.currentTime;
    });
    const fromPointer = (e) => {
      const box = wipe.getBoundingClientRect();
      setLine(Math.min(100, Math.max(0, ((e.clientX - box.left) / box.width) * 100)));
    };
    wipe.addEventListener('pointerdown', (e) => {
      if (e.target === sound) return;
      wipe.setPointerCapture(e.pointerId);
      fromPointer(e);
    });
    wipe.addEventListener('pointermove', (e) => {
      if (wipe.hasPointerCapture(e.pointerId)) fromPointer(e);
    });
    sound.addEventListener('click', () => {
      edit.muted = !edit.muted;
      sound.textContent = edit.muted ? 'Sound on' : 'Sound off';
      sound.setAttribute('aria-pressed', String(!edit.muted));
      if (!edit.muted) playBoth();
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
      // The old number slides down and out while the new one drops in from the top.
      const roll = (el, value) => {
        const old = el.dataset.value;
        if (old === value) return;
        el.dataset.value = value;
        if (old === undefined || reduceMotion) {
          el.textContent = value;
          return;
        }
        el.innerHTML = `<i class="cd-out">${old}</i><i class="cd-in">${value}</i>`;
      };
      const tick = () => {
        const ms = Math.max(0, start - new Date());
        const t = Math.floor(ms / 1000);
        roll(out.d, pad(Math.floor(t / 86400)));
        roll(out.h, pad(Math.floor((t % 86400) / 3600)));
        roll(out.m, pad(Math.floor((t % 3600) / 60)));
        roll(out.s, pad(t % 60));
        if (ms === 0) { cd.hidden = true; clearInterval(timer); }
      };
      tick();
      timer = setInterval(tick, 1000);
    }
  }

  /* ---------- Timeline animation (plays when scrolled into view) ---------- */
  if (!reduceMotion && 'IntersectionObserver' in window) $$('.tl').forEach((tl) => {
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
  });

  /* ---------- Stats count up when they scroll into view ---------- */
  if (!reduceMotion && 'IntersectionObserver' in window) {
    $$('[data-count]').forEach((el) => {
      const target = Number(el.dataset.count);
      const finalText = el.textContent;
      el.textContent = '0+';
      const io = new IntersectionObserver(([e]) => {
        if (!e.isIntersecting) return;
        io.disconnect();
        const startedAt = performance.now();
        const step = (now) => {
          const p = Math.min(1, (now - startedAt) / 1800);
          const eased = 1 - (1 - p) ** 3;
          if (p < 1) {
            el.textContent = `${Math.round(target * eased).toLocaleString('en-IN')}+`;
            requestAnimationFrame(step);
          } else {
            el.textContent = finalText;
          }
        };
        requestAnimationFrame(step);
      }, { threshold: 0.5 });
      io.observe(el);
    });
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
