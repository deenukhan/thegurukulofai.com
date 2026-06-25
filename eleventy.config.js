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
  eleventyConfig.addShortcode("bunnyVideo", (guid, caption = "") => {
    const cap = caption ? `<figcaption>${caption}</figcaption>` : "";
    return `<figure class="post-video"><div class="post-video-frame"><iframe src="https://iframe.mediadelivery.net/embed/${STREAM_LIBRARY_ID}/${guid}?autoplay=false&loop=false&muted=false&preload=true&responsive=true" loading="lazy" allow="accelerometer;gyroscope;autoplay;encrypted-media;picture-in-picture;" allowfullscreen></iframe></div>${cap}</figure>`;
  });

  return {
    pathPrefix: "/blog/",
    dir: { input: "blog-src", output: "blog", includes: "_includes", data: "_data" },
    markdownTemplateEngine: "njk",
    htmlTemplateEngine: "njk",
  };
};
