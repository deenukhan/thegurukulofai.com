# The Gurukul of AI — Website

## Project Overview

Website for **The Gurukul of AI**, an AI education brand founded by **Deenu Khan** (ML Engineer, 7+ years, 300K+ Instagram followers at @neuraltech.ai).

**Target audience:** Indian creators, D2C brand owners, freelancers, social media managers, aged 22–38.

**Current scope:** Single-file HTML homepage (`index.html`) — vanilla HTML/CSS/JS only, no frameworks.

---

## Brand Identity

### Colors (CSS variables)
```css
--ink:        #1A1A1A   /* Primary dark — headlines, backgrounds */
--clay:       #C4A882   /* Brand accent — bars, underlines, CTAs */
--clay-light: #D9C4A8   /* Clay hover */
--warm-white: #F7F4EF   /* Page background — NEVER pure white */
--white:      #FFFFFF   /* Cards, overlays */
--slate:      #4A4A4A   /* Body text */
--mist:       #E8E4DE   /* Borders, dividers */
--forest:     #2D4A3E   /* Strong CTA blocks */
--forest-mid: #3D6454   /* Forest hover */
```

### Typography
- **Display/Headlines:** Lora (Google Fonts) — 400, 600, 700, italic
- **Body/UI:** Instrument Sans (Google Fonts) — 400, 500, 600, 700

### Visual Signature
- 3–4px clay vertical bar as left anchor on wordmarks and section headers
- 2px clay underline rule after key headlines (60–80px wide)
- Oversized ghost letter ("G") as background texture — ink color, 2–3% opacity
- Section eyebrow: 11px, Instrument Sans SemiBold, letter-spacing 0.35em, uppercase, clay
- Page background: always `--warm-white`, never `#FFFFFF`

---

## Tone of Voice

- Plain English, short sentences (under 20 words)
- Lead with outcome or benefit
- No buzzwords, no exclamation marks in headlines
- Active voice only
- Warm but not casual; confident but not arrogant
- Prices in Indian Rupees (₹)
- Refer to audience as "creators," "brand builders," or "you" — never "users"

---

## Page Structure (`index.html`)

0. Navigation (fixed, scroll-aware)
1. Hero (2-col: copy + stat cards, ink ticker bar)
2. Tools Strip (pill buttons: Freepik, Kling AI, HeyGen, Runway, Luma AI, Midjourney, CapCut, Higgsfield, ElevenLabs)
3. What You Learn (dark section, 6-card grid, id="learn")
4. Courses (3 cards: AI Videos Mastery ₹4,999 · Prompt Engineering ₹2,999 · AI Image Mastery ₹3,499, id="courses")
5. Founder (Deenu Khan bio, photo placeholder "DK", id="founder")
6. Testimonials (3 student quotes)
7. CTA Banner (forest bg)
8. Footer (ink bg, 4-col grid)

Full spec: `../gurukul_website_master_prompt.md`

---

## Technical Rules

- **Single HTML file** — all CSS in `<style>`, all JS in `<script>` before `</body>`
- No frameworks — vanilla HTML/CSS/JS only
- External dependency: Google Fonts only
- All images are CSS/HTML placeholders — no `<img>` tags until real assets available
- Semantic HTML: `nav`, `section`, `footer`, `h1`–`h3`, `p`, `ul`, `a`
- Smooth scroll on `html` element; `antialiased` font rendering
- Target browsers: Chrome, Safari, Firefox

---

## Placeholders to Replace Before Launch

1. Founder photo: replace "DK" monogram with actual portrait (800×1000px)
2. Enroll links: replace `href="#"` with actual course platform URLs (TagMango or similar)
3. Social links: Instagram, YouTube, LinkedIn
4. Testimonials: replace with real student quotes
5. Meta/OG tags: title, description, image
6. Favicon: G monogram, 32px PNG
7. Analytics: Google Analytics or equivalent

---

## Git Conventions

- Branch: `main`
- Commit style: imperative, lowercase subject line, max 72 chars
  - `add hero section with stat cards`
  - `fix nav scroll state on mobile`
  - `update course prices`
- No force pushes to `main`
- GitHub repo: `thegurukulofai/thegurukulofai.com` (confirm remote URL with Deenu)
