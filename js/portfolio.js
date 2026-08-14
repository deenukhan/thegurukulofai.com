/* ==========================================================================
   ADS PORTFOLIO — stat counters, filters, hover previews, video player
   Every tile carries its own data-* payload, so the grid works without JS
   and this file only ever enhances what is already in the markup.
   ========================================================================== */
(function () {
  'use strict';

  var grid = document.getElementById('portfolioGrid');
  if (!grid) return;

  var items = Array.prototype.slice.call(grid.querySelectorAll('.p-item'));
  var chips = Array.prototype.slice.call(document.querySelectorAll('.p-chip'));
  var status = document.getElementById('portfolioStatus');
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var visible = items.slice();

  /* ---------- Stat counters ---------- */
  var stats = Array.prototype.slice.call(document.querySelectorAll('[data-count]'));
  if (stats.length && 'IntersectionObserver' in window && !reduceMotion) {
    var statIo = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        statIo.unobserve(e.target);
        countUp(e.target);
      });
    }, { threshold: 0.4 });
    stats.forEach(function (el) { statIo.observe(el); });
  }

  function countUp(el) {
    var target = parseInt(el.dataset.count, 10);
    if (isNaN(target)) return;
    var suffix = /\+$/.test(el.textContent) ? '+' : '';
    var start = null;
    var dur = 1600;
    function frame(now) {
      if (start === null) start = now;
      var t = Math.min((now - start) / dur, 1);
      var eased = 1 - Math.pow(1 - t, 3);
      el.textContent = Math.round(target * eased) + suffix;
      if (t < 1) requestAnimationFrame(frame);
    }
    requestAnimationFrame(frame);
  }

  /* ---------- Reveal on scroll ---------- */
  if ('IntersectionObserver' in window && !reduceMotion) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        e.target.classList.add('is-in');
        io.unobserve(e.target);
      });
    }, { rootMargin: '80px' });
    items.forEach(function (el) { io.observe(el); });
  } else {
    items.forEach(function (el) { el.classList.add('is-in'); });
  }

  /* ---------- Masonry ----------
     CSS multicol fills column-by-column, which would drop the oldest work at the
     top of the last column. Tiles are laid out into explicit columns instead,
     each one going to the shortest column so far — that keeps the newest-first
     order reading left-to-right while still balancing the column heights. */
  function columnCount() {
    var w = window.innerWidth;
    if (w <= 560) return 1;
    if (w <= 900) return 2;
    return 3;
  }

  function ratioOf(el) {
    var img = el.querySelector('img');
    var w = img && +img.getAttribute('width');
    var h = img && +img.getAttribute('height');
    return w && h ? h / w : 1;
  }

  var laidOutCols = 0;

  function layout() {
    var n = columnCount();
    var cols = [];
    var heights = [];
    grid.classList.add('is-masonry');
    grid.textContent = '';
    for (var i = 0; i < n; i++) {
      var c = document.createElement('div');
      c.className = 'p-col';
      grid.appendChild(c);
      cols.push(c);
      heights.push(0);
    }
    visible.forEach(function (el) {
      var k = 0;
      for (var i = 1; i < n; i++) {
        if (heights[i] < heights[k] - 0.0001) k = i;
      }
      cols[k].appendChild(el);
      heights[k] += ratioOf(el) + 0.04;   // +gap, in column-width units
    });
    laidOutCols = n;
  }

  /* ---------- Filters ---------- */
  function applyFilter(key) {
    visible = [];
    items.forEach(function (el) {
      var match = key === 'all' || (' ' + el.dataset.cats + ' ').indexOf(' ' + key + ' ') > -1;
      el.hidden = !match;
      if (match) {
        visible.push(el);
        el.classList.add('is-in');
      }
    });
    chips.forEach(function (c) {
      c.setAttribute('aria-pressed', String(c.dataset.filter === key));
    });
    if (status) {
      status.textContent = visible.length + (visible.length === 1 ? ' ad' : ' ads') + ' shown';
    }
    layout();
  }

  chips.forEach(function (chip) {
    chip.addEventListener('click', function () { applyFilter(chip.dataset.filter); });
  });

  layout();

  var resizeTimer = null;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      if (columnCount() !== laidOutCols) layout();
    }, 150);
  });

  /* ---------- Hover previews (desktop pointers only) ----------
     The silent 4s loop is only fetched once the pointer has settled for 220ms,
     so scrubbing across the grid never kicks off a wall of requests.          */
  var canHover = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  if (canHover && !reduceMotion) {
    items.forEach(function (el) {
      var loop = el.dataset.loop;
      if (!loop) return;
      var vid = null;
      var timer = null;

      function start() {
        timer = setTimeout(function () {
          if (!vid) {
            vid = document.createElement('video');
            vid.className = 'p-preview';
            vid.muted = true;
            vid.loop = true;
            vid.playsInline = true;
            vid.setAttribute('muted', '');
            vid.setAttribute('playsinline', '');
            vid.setAttribute('aria-hidden', 'true');
            vid.preload = 'auto';
            vid.src = loop;
            el.appendChild(vid);
          }
          var p = vid.play();
          if (p && p.catch) p.catch(function () {});
          el.classList.add('is-previewing');
        }, 220);
      }

      function stop() {
        clearTimeout(timer);
        el.classList.remove('is-previewing');
        if (vid) { vid.pause(); vid.currentTime = 0; }
      }

      el.addEventListener('mouseenter', start);
      el.addEventListener('mouseleave', stop);
      el.addEventListener('focus', start);
      el.addEventListener('blur', stop);
    });
  }

  /* ---------- Player ---------- */
  var lb = document.getElementById('portfolioPlayer');
  if (!lb) return;

  var media = lb.querySelector('.p-lb-media');
  var brandEl = lb.querySelector('.p-lb-brand');
  var titleEl = lb.querySelector('.p-lb-title');
  var metaEl = lb.querySelector('.p-lb-meta');
  var btnPrev = lb.querySelector('.p-lb-prev');
  var btnNext = lb.querySelector('.p-lb-next');
  var btnClose = lb.querySelector('.p-lb-close');
  var index = -1;
  var lastFocus = null;

  function render(i) {
    var el = visible[i];
    if (!el) return;
    index = i;
    media.innerHTML = '';

    var v = document.createElement('video');
    v.src = el.dataset.mp4;
    v.poster = el.dataset.poster;
    v.controls = true;
    v.autoplay = true;
    v.playsInline = true;
    v.setAttribute('playsinline', '');
    v.preload = 'auto';
    media.appendChild(v);
    var p = v.play();
    if (p && p.catch) p.catch(function () {});

    brandEl.textContent = el.dataset.brand;
    titleEl.textContent = el.dataset.title;
    metaEl.textContent = el.dataset.kind + '  ·  ' + el.dataset.status +
                         '  ·  ' + (i + 1) + ' / ' + visible.length;
  }

  function open(el) {
    var i = visible.indexOf(el);
    if (i < 0) return;
    lastFocus = document.activeElement;
    lb.classList.add('is-open');
    lb.removeAttribute('aria-hidden');
    document.body.classList.add('p-locked');
    render(i);
    btnClose.focus();
  }

  function close() {
    lb.classList.remove('is-open');
    lb.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('p-locked');
    media.innerHTML = '';   // stops playback and drops the connection
    index = -1;
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }

  function step(delta) {
    if (index < 0 || !visible.length) return;
    render((index + delta + visible.length) % visible.length);
  }

  items.forEach(function (el) {
    el.addEventListener('click', function () { open(el); });
  });

  btnPrev.addEventListener('click', function () { step(-1); });
  btnNext.addEventListener('click', function () { step(1); });
  btnClose.addEventListener('click', close);

  lb.addEventListener('click', function (e) {
    if (e.target === lb || e.target.classList.contains('p-lb-stage') ||
        e.target.classList.contains('p-lb-media')) close();
  });

  document.addEventListener('keydown', function (e) {
    if (!lb.classList.contains('is-open')) return;
    if (e.key === 'Escape') { close(); }
    else if (e.key === 'ArrowLeft') { step(-1); }
    else if (e.key === 'ArrowRight') { step(1); }
    else if (e.key === 'Tab') {
      var focusables = lb.querySelectorAll('button, video[controls]');
      if (!focusables.length) return;
      var first = focusables[0];
      var last = focusables[focusables.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });

  /* swipe */
  var sx = 0, sy = 0;
  lb.addEventListener('touchstart', function (e) {
    sx = e.changedTouches[0].clientX;
    sy = e.changedTouches[0].clientY;
  }, { passive: true });

  lb.addEventListener('touchend', function (e) {
    var dx = e.changedTouches[0].clientX - sx;
    var dy = e.changedTouches[0].clientY - sy;
    if (Math.abs(dx) > 55 && Math.abs(dx) > Math.abs(dy)) step(dx < 0 ? 1 : -1);
    else if (dy > 90 && Math.abs(dy) > Math.abs(dx)) close();
  }, { passive: true });
})();
