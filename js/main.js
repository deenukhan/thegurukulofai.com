/**
 * The Gurukul of AI — main.js
 * Handles: custom cursor, nav scroll state, scroll reveal, smooth anchor scroll
 */

(function () {
  'use strict';

  // =========================================================================
  // CUSTOM CURSOR
  // Only activates on devices with a precise pointer (mouse), not touch.
  // =========================================================================
  const cursor = document.getElementById('cursor');

  if (cursor && window.matchMedia('(pointer: fine)').matches) {
    let mouseX = 0;
    let mouseY = 0;

    document.addEventListener('mousemove', (e) => {
      mouseX = e.clientX;
      mouseY = e.clientY;
      cursor.style.left = mouseX + 'px';
      cursor.style.top  = mouseY + 'px';
    });

    // Elements that trigger the expanded cursor state
    const hoverTargets = document.querySelectorAll(
      'a, button, .stat-card, .learn-card, .course-card, .testimonial-card, .tool-pill'
    );

    hoverTargets.forEach((el) => {
      el.addEventListener('mouseenter', () => cursor.classList.add('hover'));
      el.addEventListener('mouseleave', () => cursor.classList.remove('hover'));
    });

  } else if (cursor) {
    // Touch device — hide the cursor element entirely
    cursor.style.display = 'none';
  }

  // =========================================================================
  // NAV SCROLL STATE
  // Adds .scrolled class when page scrolls past 40px, enabling the
  // frosted-glass blur effect and mist border.
  // =========================================================================
  const nav = document.getElementById('nav');

  function updateNav() {
    if (!nav) return;
    if (window.scrollY > 40) {
      nav.classList.add('scrolled');
    } else {
      nav.classList.remove('scrolled');
    }
  }

  window.addEventListener('scroll', updateNav, { passive: true });
  updateNav(); // Run once on load in case page is already scrolled

  // =========================================================================
  // SCROLL REVEAL
  // Uses IntersectionObserver to trigger .visible on .reveal elements.
  // Falls back to making everything visible on older browsers.
  // =========================================================================
  const revealEls = document.querySelectorAll('.reveal');

  if ('IntersectionObserver' in window && revealEls.length > 0) {
    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            entry.target.classList.add('visible');
            observer.unobserve(entry.target); // Fire once only
          }
        });
      },
      { threshold: 0.12, rootMargin: '0px 0px -40px 0px' }
    );

    revealEls.forEach((el) => observer.observe(el));

  } else {
    // Fallback: immediately show all elements
    revealEls.forEach((el) => el.classList.add('visible'));
  }

  // =========================================================================
  // SMOOTH ANCHOR SCROLL
  // Accounts for fixed nav height so sections aren't hidden behind the nav.
  // =========================================================================
  document.querySelectorAll('a[href^="#"]').forEach((anchor) => {
    anchor.addEventListener('click', (e) => {
      const href = anchor.getAttribute('href');
      if (href === '#' || href === '#0') return;

      const target = document.querySelector(href);
      if (!target) return;

      e.preventDefault();

      const navHeight = nav ? nav.offsetHeight : 0;
      const targetTop = target.getBoundingClientRect().top + window.scrollY;
      const scrollTo  = targetTop - navHeight - 16; // 16px breathing room

      window.scrollTo({ top: scrollTo, behavior: 'smooth' });
    });
  });

})();
