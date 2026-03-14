# The Gurukul of AI — Website

## Project Overview

Website for **The Gurukul of AI**, an AI education brand founded by **Deenu Khan** (ML Engineer, 7+ years, 300K+ Instagram followers at @neuraltech.ai).

**Target audience:** Indian creators, D2C brand owners, freelancers, social media managers, aged 22–38.

**Current scope:** Multi-file HTML/CSS/JS site — vanilla only, no frameworks.

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
- Oversized ghost letter as background texture — ink color, 2–3% opacity
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

## Current Page Structure (`index.html`)

0. **Navigation** — fixed, scroll-aware, white pill shape, aligned to hero card width
1. **Hero** — full-bleed photo card (`hero-section.webp`), cinematic dark overlay, corner badges, headline with clay italic accent, white CTA button (clay on hover)
2. **Ticker** — scrolling tools marquee, rounded, ink background
3. **Cards Stack** — wrapper div with rounded sections (border-radius: 20px), warm-white bg
4. **Courses** (`id="courses"`) — 3 cards: 100x GenAI Mastery (YouTube embed, ₹1,999) · AI Influencer Mastery (coming soon, ₹2,999) · AI UGC Mastery (coming soon, ₹2,999)
5. **Tools Strip** — pill grid of AI tools
6. **Founder** (`id="founder"`) — Deenu Khan bio, photo, stats, tool pills
7. **Testimonials** — 3 student quote cards
8. **CTA Banner** — forest background
9. **Footer** — ink bg, 4-col grid, social links

---

## File Structure

```
index.html
privacy-policy.html
css/
  base.css        — tokens, reset, typography, globals
  components.css  — logo, buttons, cards, pills, tags
  sections.css    — per-section layout styles
  animations.css  — reveal animations
  responsive.css  — all breakpoints
js/
  main.js
assets/
  images/founder/
    hero-section.webp   — hero background photo
    deenu-khan.webp     — founder photo (used in founder section)
```

---

## Key Design Decisions

- **Hero:** Full-bleed photo card with white text accents. Only `em` (italic) text uses clay colour. Badges use white background with ink text. Button is white → clay on hover.
- **Cards Stack:** All sections below hero are wrapped in `.cards-stack` with `border-radius: 20px` and `gap: 12px`. Creates a stacked card visual language.
- **Courses zoom:** `.courses-inner` has no zoom — natural sizes used throughout.
- **Nav pill:** White background (`#ffffff`), mist border, ink text, aligned to same max-width as hero card (`--content-max: 1200px`).
- **Scroll offset:** `scroll-padding-top: 90px` on `html` to account for fixed nav.
- **Hero padding:** `112px` top on all screen sizes for consistent nav-to-hero gap.

---

## Social Links (Live)
- Instagram: `https://instagram.com/neuraltech.ai`
- YouTube: `https://youtube.com/@neuraltechai`
- LinkedIn: `https://www.linkedin.com/in/deenu-khan-a4a33b356/`
- Facebook: `https://www.facebook.com/neuraltechai/`

## Course Links (Live)
- 100x GenAI Mastery enroll: `https://100xgenai.thegurukulofai.com/`
- Waitlist (AI Influencer & UGC): Google Form link in index.html

---

## Technical Rules

- No frameworks — vanilla HTML/CSS/JS only
- External dependency: Google Fonts only
- Semantic HTML: `nav`, `section`, `footer`, `h1`–`h3`, `p`, `ul`, `a`
- Smooth scroll + `scroll-padding-top: 90px` on `html`
- Target browsers: Chrome, Safari, Firefox
- CSS cache-busting via `?v=N` on all stylesheet links — increment on every change

---

## Hosting & Deployment

- **Live site:** `https://thegurukulofai.com`
- **Hosting:** Hostinger (hPanel)
- **Repo:** `https://github.com/deenukhan/thegurukulofai.com`
- **Auto-deploy:** GitHub webhook → Hostinger, triggers on push to `main`

### Git Workflow
- **Always work on `dev` branch** — all changes go here first
- **To go live:** merge `dev` → `main` and push (say "push to live")
- Commit style: imperative, lowercase, max 72 chars
- No force pushes to `main`

---

## Still To Do
1. Compress `hero-section.webp` to under 300KB (use squoosh.app)
2. Add favicon (G monogram, 32px PNG)
3. Add Google Analytics
4. Update OG image for social sharing (`assets/og-image.jpg`)
5. Replace testimonials with real student quotes
6. Add founder photo to founder section (replace DK monogram)
