/* ==========================================================================
   NAV MOBILE — hamburger behaviour, shared by every page
   Works with any nav structure: needs .js-nav-pill on the pill, a
   .nav-toggle button inside it, and .js-nav-menu on the link list.
   ========================================================================== */
(function () {
  'use strict';

  var pills = document.querySelectorAll('.js-nav-pill');

  Array.prototype.forEach.call(pills, function (pill) {
    var toggle = pill.querySelector('.nav-toggle');
    var menu = pill.querySelector('.js-nav-menu');
    if (!toggle || !menu) return;

    function setOpen(open) {
      pill.classList.toggle('is-open', open);
      toggle.setAttribute('aria-expanded', String(open));
      toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    }

    toggle.addEventListener('click', function (e) {
      e.stopPropagation();
      setOpen(!pill.classList.contains('is-open'));
    });

    // close after tapping a link
    menu.addEventListener('click', function (e) {
      if (e.target.closest('a')) setOpen(false);
    });

    // close on outside click
    document.addEventListener('click', function (e) {
      if (!pill.classList.contains('is-open')) return;
      if (!pill.contains(e.target)) setOpen(false);
    });

    // close on Escape, returning focus to the button
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape' || !pill.classList.contains('is-open')) return;
      setOpen(false);
      toggle.focus();
    });

    // reset when growing back to desktop so the menu never sticks open
    var wide = window.matchMedia('(min-width: 901px)');
    var onChange = function (e) { if (e.matches) setOpen(false); };
    if (wide.addEventListener) wide.addEventListener('change', onChange);
    else if (wide.addListener) wide.addListener(onChange);
  });
})();
