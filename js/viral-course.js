/**
 * Viral AI Creator Course — landing page interactions
 * Builds the two reel carousels (9:16 then 16:9) from real Instagram data
 * scraped via Apify. Each reel shows a clean branded poster (view-count
 * forward); tapping it loads the live, playable Instagram embed in place.
 * (Scroll-reveal, nav state, smooth-scroll and stat count-up come from main.js.)
 */
(function () {
  'use strict';

  // --- Real reel data (plays = Instagram "views", likes), sorted by plays desc. ---
  var VERTICAL = [
    { c: 'DYl7LASMmOR', v: 25219117, l: 1182522 },
    { c: 'DZDdb1iMmMh', v: 22863051, l: 573638 },
    { c: 'DYoHpm8oAAM', v: 18043607, l: 502532 },
    { c: 'DYVuGu2IlMo', v: 8256552,  l: 523352 },
    { c: 'DZQMb75Txuw', v: 5905318,  l: 110772 },
    { c: 'DYeCK56tLuj', v: 2912424,  l: 74666 },
    { c: 'DZFhDbxA1Xj', v: 2076489,  l: 119148 },
    { c: 'DZSW4LVI1wI', v: 648822,   l: 46841 },
    { c: 'DZaUo5lIYCU', v: 465104,   l: 31574 }
  ];
  var HORIZONTAL = [
    { c: 'DYoyUS9NlAr', v: 119554284, l: 3409043 },
    { c: 'DZdIjoZMxS4', v: 36130618,  l: 1611541 },
    { c: 'DZUKq-yMyWg', v: 27554818,  l: 627115 },
    { c: 'DYfEdxdIOto', v: 20227218,  l: 1493154 },
    { c: 'DY5NV0vMqGy', v: 18037292,  l: 98526 },
    { c: 'DXzW4sWCGJF', v: 14199643,  l: 1165469 },
    { c: 'DZSh0CeMEuz', v: 13049690,  l: 298358 },
    { c: 'DYfDJd7M628', v: 10782786,  l: 250069 },
    { c: 'DY2A6MVqS6K', v: 5762981,   l: 304777 },
    { c: 'DYlTG8GCrlq', v: 5422619,   l: 255325 },
    { c: 'DXO8acCEdsl', v: 4268573,   l: 472777 },
    { c: 'DZXqEHPOsFs', v: 2910336,   l: 155451 },
    { c: 'DYaAuu7xY60', v: 2374332,   l: 142218 },
    { c: 'DYWeBKOuvpb', v: 1731400,   l: 233981 },
    { c: 'DYxh0SwObfg', v: 961205,    l: 86590 },
    { c: 'DYaAIj8xjKP', v: 917718,    l: 52594 },
    { c: 'DZOeywTMDH0', v: 627858,    l: 11743 },
    { c: 'DZHIxxotxBu', v: 515383,    l: 11521 }
  ];

  function fmt(n) {
    if (n >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
    if (n >= 1e3) return Math.round(n / 1e3) + 'K';
    return String(n);
  }

  var PLAY_SVG = '<svg viewBox="0 0 24 24" width="26" height="26" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>';
  var IG_SVG = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17" cy="7" r="1.2" fill="currentColor" stroke="none"/></svg>';

  function cardHTML(reel, rank, orient) {
    var cls = orient === 'v' ? 'vc-reel--v' : 'vc-reel--h';
    var url = 'https://www.instagram.com/reel/' + reel.c + '/';
    var thumb = 'assets/images/viral-ai-course/reels/' + reel.c + '.jpg';
    return '' +
      '<a class="vc-reel ' + cls + '" href="' + url + '" target="_blank" rel="noopener" aria-label="Watch reel on Instagram, ' + fmt(reel.v) + ' views">' +
        '<span class="vc-reel-media">' +
          '<img loading="lazy" src="' + thumb + '" alt="AI-made reel, ' + fmt(reel.v) + ' views">' +
          '<span class="vc-reel-rank">#' + rank + '</span>' +
          '<span class="vc-reel-ig">' + IG_SVG + '</span>' +
          '<span class="vc-reel-play">' + PLAY_SVG + '</span>' +
          '<span class="vc-reel-foot">' +
            '<span class="vc-reel-views">' + fmt(reel.v) + ' <small>views</small></span>' +
            '<span class="vc-reel-likes">&#9829; ' + fmt(reel.l) + ' &middot; Watch on Instagram</span>' +
          '</span>' +
        '</span>' +
      '</a>';
  }

  function build(trackId, data, orient) {
    var track = document.getElementById(trackId);
    if (!track) return null;
    var html = '';
    for (var i = 0; i < data.length; i++) html += cardHTML(data[i], i + 1, orient);
    track.innerHTML = html;
    return track;
  }

  function setupArrows(track, prevBtn, nextBtn) {
    if (!track) return;
    function step() {
      var card = track.querySelector('.vc-reel');
      var gap = 18;
      return card ? card.getBoundingClientRect().width + gap : 340;
    }
    function update() {
      if (!prevBtn || !nextBtn) return;
      prevBtn.disabled = track.scrollLeft <= 4;
      nextBtn.disabled = track.scrollLeft + track.clientWidth >= track.scrollWidth - 4;
    }
    if (prevBtn) prevBtn.addEventListener('click', function () { track.scrollBy({ left: -step() * 1.5, behavior: 'smooth' }); });
    if (nextBtn) nextBtn.addEventListener('click', function () { track.scrollBy({ left: step() * 1.5, behavior: 'smooth' }); });
    track.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update, { passive: true });
    update();
  }

  var vTrack = build('vc-track-v', VERTICAL, 'v');
  var hTrack = build('vc-track-h', HORIZONTAL, 'h');
  setupArrows(vTrack, document.getElementById('vc-prev-v'), document.getElementById('vc-next-v'));
  setupArrows(hTrack, document.getElementById('vc-prev-h'), document.getElementById('vc-next-h'));

  // Scroll progress bar
  var prog = document.getElementById('vc-progress');
  if (prog) {
    var updateProg = function () {
      var h = document.documentElement;
      var max = (h.scrollHeight - h.clientHeight) || 1;
      prog.style.width = Math.min(100, (h.scrollTop / max) * 100) + '%';
    };
    window.addEventListener('scroll', updateProg, { passive: true });
    window.addEventListener('resize', updateProg, { passive: true });
    updateProg();
  }

  // Sticky buy bar
  var bar = document.getElementById('vc-sticky');
  if (bar) {
    var onScroll = function () {
      if (window.scrollY > 760) bar.classList.add('show');
      else bar.classList.remove('show');
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  // FAQ accordion
  var faq = document.getElementById('vc-faq');
  if (faq) {
    faq.addEventListener('click', function (ev) {
      var q = ev.target.closest('.vc-faq-q');
      if (!q) return;
      var item = q.parentElement;
      var open = item.classList.toggle('open');
      var ic = q.querySelector('.vc-faq-ic');
      if (ic) ic.textContent = open ? '–' : '+';
    });
  }
})();
