/* ==========================================================================
   GALLERY — filters, reveal, hover previews, lightbox
   Data lives in the markup (data-* attributes) so the page works without JS.
   ========================================================================== */
(function () {
  'use strict';

  var grid = document.getElementById('galleryGrid');
  if (!grid) return;

  var items = Array.prototype.slice.call(grid.querySelectorAll('.g-item'));
  var chips = Array.prototype.slice.call(document.querySelectorAll('.g-chip'));
  var status = document.getElementById('galleryStatus');
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var visible = items.slice();

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
      status.textContent = visible.length + (visible.length === 1 ? ' moment' : ' moments') + ' shown';
    }
  }

  chips.forEach(function (chip) {
    chip.addEventListener('click', function () {
      applyFilter(chip.dataset.filter);
    });
  });

  /* ---------- Hover previews (desktop pointers only) ---------- */
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
            vid.className = 'g-preview';
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

  /* ---------- Lightbox ---------- */
  var lb = document.getElementById('galleryLightbox');
  if (!lb) return;

  var media = lb.querySelector('.g-lb-media');
  var titleEl = lb.querySelector('.g-lb-title');
  var metaEl = lb.querySelector('.g-lb-meta');
  var btnPrev = lb.querySelector('.g-lb-prev');
  var btnNext = lb.querySelector('.g-lb-next');
  var btnClose = lb.querySelector('.g-lb-close');
  var index = -1;
  var lastFocus = null;

  function render(i) {
    var el = visible[i];
    if (!el) return;
    index = i;
    media.innerHTML = '';

    if (el.dataset.mp4) {
      var v = document.createElement('video');
      v.src = el.dataset.mp4;
      v.poster = el.dataset.full;
      v.controls = true;
      v.autoplay = true;
      v.playsInline = true;
      v.setAttribute('playsinline', '');
      v.preload = 'auto';
      media.appendChild(v);
      var p = v.play();
      if (p && p.catch) p.catch(function () {});
    } else {
      var pic = document.createElement('picture');
      var s = document.createElement('source');
      s.srcset = el.dataset.fullAvif;
      s.type = 'image/avif';
      var img = document.createElement('img');
      img.src = el.dataset.full;
      img.alt = el.dataset.title;
      pic.appendChild(s);
      pic.appendChild(img);
      media.appendChild(pic);
    }

    titleEl.textContent = el.dataset.title;
    metaEl.textContent = el.dataset.place + '  ·  ' + (i + 1) + ' / ' + visible.length;
    preload(i + 1);
    preload(i - 1);
  }

  function preload(i) {
    var el = visible[i];
    if (!el || el.dataset.mp4) return;
    var im = new Image();
    im.src = el.dataset.full;
  }

  function open(el) {
    var i = visible.indexOf(el);
    if (i < 0) return;
    lastFocus = document.activeElement;
    lb.classList.add('is-open');
    lb.removeAttribute('aria-hidden');
    document.body.classList.add('g-locked');
    render(i);
    btnClose.focus();
  }

  function close() {
    lb.classList.remove('is-open');
    lb.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('g-locked');
    media.innerHTML = '';
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
    if (e.target === lb || e.target.classList.contains('g-lb-stage') ||
        e.target.classList.contains('g-lb-media')) close();
  });

  document.addEventListener('keydown', function (e) {
    if (!lb.classList.contains('is-open')) return;
    if (e.key === 'Escape') { close(); }
    else if (e.key === 'ArrowLeft') { step(-1); }
    else if (e.key === 'ArrowRight') { step(1); }
    else if (e.key === 'Tab') {
      // keep focus inside the lightbox
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
