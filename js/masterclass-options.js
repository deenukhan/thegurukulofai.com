/* Preview only. Add ?option=1 … 6, best or all to the page address to see each hero idea.
   Without that, this file does nothing. Remove it once an option is chosen. */
(function () {
  const OPTIONS = [
    { id: '1', name: 'Video in the hero', on: [1] },
    { id: '2', name: 'Rotating word', on: [2] },
    { id: '3', name: 'Timeline in the hero', on: [3] },
    { id: '4', name: 'Counting numbers', on: [4] },
    { id: '5', name: 'Staged entrance', on: [5] },
    { id: '6', name: 'Moving background', on: [6] },
    { id: 'best', name: 'My pick: 1, 2, 4 and 5', on: [1, 2, 4, 5] },
    { id: 'all', name: 'All six together', on: [1, 2, 3, 4, 5, 6] },
  ];
  const id = new URLSearchParams(window.location.search).get('option');
  const current = OPTIONS.find((o) => o.id === id);
  if (!current) return;
  current.on.forEach((n) => document.documentElement.classList.add(`opt-${n}`));
  const has = (n) => current.on.includes(n);
  const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const heroVideo = () => {
    const inner = document.querySelector('.mc-hero-inner');
    const wipe = document.querySelector('[data-wipe]');
    const text = document.createElement('div');
    text.className = 'mc-hero-text';
    text.append(...inner.children);
    const media = document.createElement('div');
    media.className = 'mc-hero-media';
    media.append(wipe);
    inner.append(text, media);
  };

  const rotatingWord = () => {
    const h1 = document.querySelector('#hero-title');
    h1.innerHTML = h1.innerHTML.replace('Your next reel,', 'Your next <span class="mc-word-wrap"><span class="mc-word"><i>reel</i></span>,</span>');
    const box = h1.querySelector('.mc-word');
    // Short words on phones, so the headline keeps the same number of lines
    const words = window.innerWidth <= 640 ? ['reel', 'ad', 'Short', 'vlog'] : ['reel', 'ad', 'YouTube video', 'client edit'];
    let i = 0;
    const widthOf = (word) => {
      const probe = document.createElement('i');
      probe.textContent = word;
      probe.style.cssText = 'position:absolute;visibility:hidden;white-space:nowrap';
      box.append(probe);
      const w = probe.getBoundingClientRect().width;
      probe.remove();
      return w;
    };
    box.style.width = `${widthOf(words[0])}px`;
    if (calm) return;
    setInterval(() => {
      const old = words[i];
      i = (i + 1) % words.length;
      box.style.width = `${widthOf(words[i])}px`;
      box.innerHTML = `<i class="out">${old}</i><i class="in">${words[i]}</i>`;
    }, 2400);
  };

  const heroTimeline = () => {
    const copy = document.querySelector('.tl').cloneNode(true);
    copy.setAttribute('aria-hidden', 'true');
    copy.classList.add('tl--hero');
    document.querySelector('.mc-hero-note').after(copy);
  };

  const countUp = () => {
    document.querySelectorAll('.mc-stats strong').forEach((el) => {
      const match = el.textContent.match(/^([\d,]+)(.*)$/);
      const target = match ? Number(match[1].replace(/,/g, '')) : 0;
      if (!target || calm) return;
      const tail = match[2];
      el.textContent = `0${tail}`;
      new IntersectionObserver(([e], io) => {
        if (!e.isIntersecting) return;
        io.disconnect();
        const start = performance.now();
        const step = (t) => {
          const p = Math.min(1, (t - start) / 1600);
          const eased = 1 - (1 - p) ** 3;
          el.textContent = `${Math.round(target * eased).toLocaleString('en-US')}${tail}`;
          if (p < 1) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
      }, { threshold: 0.4 }).observe(el);
    });
  };

  const switcher = () => {
    const at = OPTIONS.indexOf(current);
    const link = (o, label) => `<a href="?option=${o.id}" aria-label="${o.name}">${label}</a>`;
    const bar = document.createElement('div');
    bar.className = 'mc-opt-bar';
    bar.innerHTML = `${link(OPTIONS[(at + OPTIONS.length - 1) % OPTIONS.length], '‹')}<span>${current.name}</span>${link(OPTIONS[(at + 1) % OPTIONS.length], '›')}`;
    document.body.append(bar);
  };

  window.mcApplyOptions = () => {
    if (has(1)) heroVideo();
    if (has(2)) rotatingWord();
    if (has(3)) heroTimeline();
    if (has(4)) countUp();
    switcher();
  };
})();
