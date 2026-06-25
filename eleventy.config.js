/* Eleventy build for thegurukulofai.com blog.
   Source: blog-src/  ->  Output: blog/  (served at /blog/, committed to git).
   Heavy media lives on Bunny CDN/Stream, never in this repo. */

const STREAM_LIBRARY_ID = 691066; // public (appears in embed URLs); safe to commit

module.exports = function (eleventyConfig) {
  // ---- Date filters ----
  eleventyConfig.addFilter("readableDate", (dt) =>
    new Date(dt).toLocaleDateString("en-IN", { year: "numeric", month: "long", day: "numeric" })
  );
  eleventyConfig.addFilter("isoDate", (dt) => new Date(dt).toISOString());
  eleventyConfig.addFilter("rfc822", (dt) => new Date(dt).toUTCString());

  // ---- Reading time (from rendered HTML) ----
  eleventyConfig.addFilter("readTime", (html) => {
    const words = String(html).replace(/<[^>]+>/g, " ").split(/\s+/).filter(Boolean).length;
    return Math.max(1, Math.round(words / 200));
  });

  // ---- Posts collection (newest first) ----
  eleventyConfig.addCollection("posts", (c) =>
    c.getFilteredByGlob("blog-src/posts/*.md").sort((a, b) => b.date - a.date)
  );

  // ---- Shortcode: captioned figure (Bunny CDN image) ----
  eleventyConfig.addShortcode("figure", (src, alt = "", caption = "") => {
    const cap = caption ? `<figcaption>${caption}</figcaption>` : "";
    return `<figure class="post-figure"><img src="${src}" alt="${alt}" loading="lazy" decoding="async">${cap}</figure>`;
  });

  // ---- Shortcode: Bunny Stream video embed ----
  const iframe = (guid, opts = "autoplay=false&loop=false&muted=false&preload=true&responsive=true") =>
    `<iframe src="https://iframe.mediadelivery.net/embed/${STREAM_LIBRARY_ID}/${guid}?${opts}" loading="lazy" allow="accelerometer;gyroscope;autoplay;encrypted-media;picture-in-picture;" allowfullscreen></iframe>`;

  eleventyConfig.addShortcode("bunnyVideo", (guid, caption = "") => {
    const cap = caption ? `<figcaption>${caption}</figcaption>` : "";
    return `<figure class="post-video"><div class="post-video-frame">${iframe(guid)}</div>${cap}</figure>`;
  });

  // ---- Shortcode: still image -> arrow -> motion video (visualise the transform) ----
  eleventyConfig.addShortcode("stillToMotion", (img, guid, caption = "") => {
    const cap = caption ? `<figcaption>${caption}</figcaption>` : "";
    const arrow = `<div class="s2m-arrow" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 12h15M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>`;
    return `<figure class="s2m"><div class="s2m-row"><div class="s2m-cell"><span class="s2m-label">Still image</span><img src="${img}" alt="Stop-motion graphic still image" loading="lazy" decoding="async"></div>${arrow}<div class="s2m-cell"><span class="s2m-label">Finished video</span><div class="post-video-frame">${iframe(guid)}</div></div></div>${cap}</figure>`;
  });

  // ---- Shortcode: grid of reels. Items: "guid::label" ----
  eleventyConfig.addShortcode("reelGrid", (...items) => {
    const tiles = items.map((it) => {
      const [guid, label = ""] = String(it).split("::");
      const cap = label ? `<span class="reel-tile-cap">${label}</span>` : "";
      return `<div class="reel-tile"><div class="post-video-frame">${iframe(guid.trim(), "responsive=true&preload=true&loop=true&muted=true&autoplay=false")}</div>${cap}</div>`;
    }).join("");
    return `<div class="reel-grid">${tiles}</div>`;
  });

  // ---- Shortcode: gallery of still -> video pairs (horizontal). Items: "imageUrl::guid::label" ----
  eleventyConfig.addShortcode("pairGallery", (...items) => {
    const arrow = `<div class="pair-arrow" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 12h15M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>`;
    const cells = items.map((it) => {
      const [img, guid, label = ""] = String(it).split("::");
      const cap = label ? `<figcaption class="pair-label">${label}</figcaption>` : "";
      return `<figure class="pair">${cap}<div class="pair-row"><div class="pair-cell"><span class="pair-tag">Still</span><img class="pair-still" src="${img.trim()}" alt="${label} still image" loading="lazy" decoding="async"></div>${arrow}<div class="pair-cell"><span class="pair-tag">Video</span><div class="post-video-frame">${iframe(guid.trim(), "responsive=true&preload=true&loop=true&muted=true&autoplay=false")}</div></div></div></figure>`;
    }).join("");
    return `<div class="pair-gallery">${cells}</div>`;
  });

  // ---- Shortcode: download cards. Items: "url::title::subtitle" ----
  eleventyConfig.addShortcode("downloads", (...items) => {
    const ic = `<span class="dl-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>`;
    const cards = items.map((it) => {
      const [url, title, sub = ""] = String(it).split("::");
      return `<a class="dl-card" href="${url.trim()}" download>${ic}<span class="dl-text"><strong>${title}</strong><span>${sub}</span></span></a>`;
    }).join("");
    return `<div class="dl-row">${cards}</div>`;
  });

  // ---- Shortcode: numbered flow infographic with icons. Steps: "Title::Detail::iconKey" ----
  const FLOW_ICONS = {
    pin: '<svg viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="14" rx="2" stroke="currentColor" stroke-width="2"/><circle cx="8.5" cy="9" r="1.4" fill="currentColor"/><path d="M5 16l4-4 3 3 3-3 4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    search: '<svg viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="6" stroke="currentColor" stroke-width="2"/><path d="M20 20l-3.5-3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    wand: '<svg viewBox="0 0 24 24" fill="none"><path d="M5 19L15 9" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M18 3l1 2.2 2.2 1-2.2 1L18 10.4 17 8.2 14.8 7.2 17 6.2 18 3z" fill="currentColor"/></svg>',
    check: '<svg viewBox="0 0 24 24" fill="none"><path d="M4 12l5 5L20 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    play: '<svg viewBox="0 0 24 24" fill="none"><path d="M7 5l12 7-12 7V5z" fill="currentColor"/></svg>',
    grid: '<svg viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="2"/><rect x="14" y="3" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="2"/><rect x="3" y="14" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="2"/><rect x="14" y="14" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="2"/></svg>',
  };
  eleventyConfig.addShortcode("flow", (...steps) => {
    const items = steps.map((s, i) => {
      const [t, d = "", icon = ""] = String(s).split("::");
      const ic = FLOW_ICONS[icon.trim()] ? `<span class="flow-ic" aria-hidden="true">${FLOW_ICONS[icon.trim()]}</span>` : "";
      return `<li class="flow-step"><span class="flow-num">${i + 1}</span><div class="flow-text"><strong>${ic}${t}</strong><span>${d}</span></div></li>`;
    }).join("");
    return `<ol class="flow">${items}</ol>`;
  });

  return {
    pathPrefix: "/blog/",
    dir: { input: "blog-src", output: "blog", includes: "_includes", data: "_data" },
    markdownTemplateEngine: "njk",
    htmlTemplateEngine: "njk",
  };
};
