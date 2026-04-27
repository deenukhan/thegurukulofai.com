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
--teal:       #3ECFCF   /* Primary brand accent — bars, underlines, CTAs, glows */
--teal-light: #65DADA   /* Teal hover state */
--warm-white: #F5F5F7   /* Page background — NEVER pure white */
--white:      #FFFFFF   /* Cards, overlays */
--slate:      #4A4A4A   /* Body text */
--mist:       #E8E4DE   /* Borders, dividers */
--forest:     #2D4A3E   /* Card thumb backgrounds (coming soon cards) */
--forest-mid: #3D6454   /* Forest hover */
```

### Typography
- **All headlines (h1–h4):** Instrument Sans (Google Fonts) — 700, letter-spacing -0.03em
- **Body/UI:** Instrument Sans (Google Fonts) — 400, 500, 600
- **Lora** (Google Fonts) still loaded — used only for the `.quote-mark` decorative element

### Logo
- **Nav + footer:** `assets/images/logo-tgai.png` — TGAI circles-only logo (no text subtitle)
- **Favicon:** `assets/images/favicon.png` — G monogram in teal circle
- Footer logo uses `filter: brightness(0) invert(1)` to render white on dark background

### Visual Signature
- Teal (`--teal`) is the sole brand accent — replaces all former clay usage
- Section eyebrow: 11px, Instrument Sans SemiBold, letter-spacing 0.35em, uppercase, teal
- 2px teal underline rule (`.clay-rule` class, color is now teal) after key headlines
- Animated teal radial glow on hero (`::after` pseudo-element, `heroGlow` keyframe)
- Teal glow `box-shadow` on all button and card hover states
- Page background: always `--warm-white` (#F5F5F7), never pure white

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

0. **Navigation** — fixed, scroll-aware, frosted-glass pill (backdrop-filter blur), teal CTA button
1. **Hero** — full-bleed photo card (`hero-section.webp`), dark overlay + animated teal radial glow, corner badges, headline with teal italic accent, white CTA button (teal on hover)
2. **Ticker** — scrolling tools marquee, rounded, ink background, pulsing teal dots
3. **Cards Stack** — wrapper div with rounded sections (border-radius: 20px), warm-white bg
4. **Courses** (`id="courses"`) — 3 cards: 100x GenAI Mastery (YouTube embed, ₹1,999) · AI Influencer Mastery (coming soon, ₹2,999) · AI UGC Mastery (coming soon, ₹2,999)
5. **Tools Strip** — pill grid of AI tools
6. **Founder** (`id="founder"`) — Deenu Khan bio, photo, animated stat counters, tool pills
7. **Testimonials** — 3 student quote cards
8. **CTA Banner** — ink background (not forest), teal italic accent
9. **Footer** — ink bg, 4-col grid, TGAI logo (white-filtered), social links

---

## File Structure

```
index.html
privacy-policy.html
css/
  base.css        — tokens, reset, typography, globals
  components.css  — logo, buttons, cards, pills, tags
  sections.css    — per-section layout styles
  animations.css  — keyframes, hero entry, scroll reveal, cursor
  responsive.css  — all breakpoints
js/
  main.js         — cursor, nav scroll state, scroll reveal, stat counters, smooth scroll
assets/
  images/
    logo-tgai.png       — TGAI circles logo (nav + footer)
    favicon.png         — G monogram favicon
    founder/
      hero-section.webp — hero background photo
      deenu-khan.webp   — founder photo
```

---

## Key Design Decisions

- **Hero:** Animated teal radial glow (`::after`) breathes over the photo. Dark `::before` overlay for readability. Only `em` (italic) text uses teal. Hero headline max size bumped to 80px.
- **Nav pill:** Frosted glass — `rgba(255,255,255,0.80)` + `backdrop-filter: blur(20px)`. Becomes more opaque on scroll. CTA button is teal with ink text.
- **Cards Stack:** All sections below hero wrapped in `.cards-stack` with `border-radius: 20px` and `gap: 12px`.
- **Buttons:** Teal fill, ink text. All hover states add a teal `box-shadow` glow.
- **Stat counters:** `.founder-stat-num` elements animate from 0 to target value (ease-out cubic, 1.6s) on IntersectionObserver trigger.
- **Scroll offset:** `scroll-padding-top: 90px` on `html` to account for fixed nav.
- **CSS versioning:** `?v=N` on all stylesheet links — current version is **36**, increment on every change.

---

## Social Links (Live)
- Instagram: `https://instagram.com/neuraltech.ai`
- YouTube: `https://youtube.com/@neuraltechai`
- LinkedIn: `https://www.linkedin.com/in/deenu-khan-a4a33b356/`
- Facebook: `https://www.facebook.com/neuraltechai/`

## Course Links (Live)
- 100x GenAI Mastery enroll: `https://learn.thegurukulofai.com/l/1ca4e7af03`
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
- **`new_design` branch** — current experimental redesign (teal colors, sans headlines, logo, animations)
- **`dev` branch** — stable working branch, all non-experimental changes go here
- **To go live:** merge `dev` → `main` and push (say "push to live")
- Commit style: imperative, lowercase, max 72 chars
- No force pushes to `main`

---

## Still To Do
1. Compress `hero-section.webp` to under 300KB (use squoosh.app)
2. Add Google Analytics
3. Update OG image for social sharing (`assets/og-image.jpg`)
4. Replace testimonials with real student quotes
5. Review `new_design` branch → merge to `dev` when approved
