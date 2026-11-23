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
4. **Courses** (`id="courses"`) — 3 cards: 100x GenAI Mastery (YouTube embed, ₹4,999) · AI Influencer Mastery (coming soon, ₹2,999) · AI UGC Mastery (coming soon, ₹2,999)
5. **Tools Strip** — pill grid of AI tools
6. **Founder** (`id="founder"`) — Deenu Khan bio, photo, animated stat counters, tool pills
7. **Testimonials** — 3 student quote cards
8. **CTA Banner** — ink background (not forest), teal italic accent
9. **Footer** — ink bg, 4-col grid, TGAI logo (white-filtered), social links

---

## Gallery (`gallery.html`)

Achievements gallery: 47 curated stills and clips from Google, Microsoft, Meta,
OpenAI, Xbox, Dubai's 1 Billion Followers Summit, stages, and event passes.

- **Generated file.** Edit `scripts/gallery/manifest.json`, then rebuild. Never hand-edit `gallery.html`.
- **Rebuild everything:** `python3 scripts/gallery/build-gallery.py`
  (needs `pip3 install --user pillow imageio-ffmpeg`; originals live in `Images for Website/`, which is gitignored)
- **Rebuild just the page:** `python3 scripts/gallery/build-gallery.py --html-only`
- **After a video change:** `scripts/bunny-upload-gallery.sh scripts/gallery/video-out`

**Where media lives**
- Stills: committed to `assets/gallery/` as AVIF + WebP at 800px (grid) and 1400px (lightbox)
- Videos: Bunny Storage at `https://tgai-cdn-2.b-cdn.net/gallery/`, never committed.
  Each has a full `<slug>.mp4` and a silent 4s `<slug>-loop.mp4` used for desktop hover previews.

**Performance choices**
- Grid tiles are lazy-loaded; the first six are eager and the first two get `fetchpriority="high"`
- Every tile carries an inline base64 blur placeholder, so there is no layout shift
- Videos are `preload="none"` and only fetched on hover or click
- Pure-CSS multicol masonry, so the layout needs no JavaScript to render

---

## Ads Portfolio (`portfolio/index.html`)

Sales page for the AI ad work: 22 spots covering D2C ads, UGC, product films,
and 3D/CGI. Built on the same pipeline as the gallery.

- **Generated file.** Edit `scripts/portfolio/manifest.json`, then rebuild. Never hand-edit `portfolio/index.html`.
- **Rebuild everything:** `python3 scripts/portfolio/build-portfolio.py`
  (needs `pip3 install --user pillow imageio-ffmpeg`; originals live in
  `thegurukulofai x ADS/Our Video Ads/` on Google Drive, outside the repo)
- **Rebuild just the page:** `python3 scripts/portfolio/build-portfolio.py --html-only`
- **After a video change:** `scripts/bunny-upload-portfolio.sh scripts/portfolio/video-out`

**Honest attribution — do not weaken this.** Every tile carries a `status`:
- `client` — paid work. Only these appear in the "Selected clients" wall.
- `spec` — a real brand's product, but unsolicited concept work.
- `concept` — an invented brand, used to show range.

Philips, Mars Cosmetics, Dot & Key, and Zipgo are `spec`, not clients. Never
promote a piece to `client` without Deenu confirming the engagement was real.

**Where media lives**
- Posters: committed to `assets/portfolio/` as AVIF + WebP at 800px and 1200px
- Videos: Bunny Storage at `https://tgai-cdn-2.b-cdn.net/portfolio/`, never committed.
  Each has a full `<slug>.mp4` (longest side 1280, CRF 24) and a silent 4s
  `<slug>-loop.mp4` at 540px for desktop hover previews.
- Source is ~2.1 GB and transcodes down to ~108 MB.

**Enquiry CTA:** every CTA points at `WHATSAPP` in `build-portfolio.py`
(+91 78278 76564, with a prefilled message). Change it there, then rebuild.

---

## File Structure

```
index.html
gallery.html          — generated, do not hand-edit (see Gallery below)
privacy-policy.html
css/
  base.css        — tokens, reset, typography, globals
  components.css  — logo, buttons, cards, pills, tags
  sections.css    — per-section layout styles
  animations.css  — keyframes, hero entry, scroll reveal, cursor
  responsive.css  — all breakpoints
  gallery.css     — gallery page shell, filter chips, masonry, lightbox
js/
  main.js         — cursor, nav scroll state, scroll reveal, stat counters, smooth scroll
  gallery.js      — gallery filters, hover previews, lightbox
assets/
  images/
    logo-tgai.png       — TGAI circles logo (nav + footer)
    favicon.png         — G monogram favicon
    founder/
      hero-section.webp — hero background photo
      deenu-khan.webp   — founder photo
  gallery/              — optimized gallery stills (AVIF + WebP, 800px and 1400px)
scripts/
  bunny-upload-images.sh   — blog media to Bunny Storage
  bunny-upload-video.sh    — video to Bunny Stream
  bunny-upload-gallery.sh  — gallery videos to Bunny Storage under gallery/
  gallery/
    manifest.json          — curation: source file, slug, tags, caption, place
    gallery-data.json      — generated: dimensions + blur placeholders
    gallery.template.html  — page shell with {{CHIPS}} / {{TILES}} placeholders
    build-gallery.py       — rebuilds images, videos, and gallery.html
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
- 100x GenAI Mastery enroll: `https://academy.thegurukulofai.com/l/1ca4e7af03`
- Waitlist (AI Influencer & UGC): Google Form link in index.html

---

## Technical Rules

- No frameworks — vanilla HTML/CSS/JS only
- External dependency: Google Fonts only (exceptions: Razorpay `checkout.js`, loaded only on the masterclass page when the pop-up opens; Meta's `fbevents.js`, loaded by `js/meta-pixel.js` on the masterclass and thank-you pages only)
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

## Poster Sections (portfolio page)

The ads portfolio opens and closes with AI-generated poster artwork that has
the headline **baked into the image** (`assets/images/poster/`):

- Hero — `no-shoot-day-{1400,2688}.{avif,webp}`
- Closing CTA — `ads-that-sell-{1400,2688}.{avif,webp}`

Both were generated with GPT Image 2 from Deenu's character sheet at 16:9.
Working set and the rest of the round lives in `hero-drafts/` (gitignored).

**These are portfolio-only.** The homepage keeps its original photo hero
(`hero-section.webp`). Do not move them back without asking.

**How the layout works.** The poster is a finished composition — type on the
left, subject on the right — so nothing is overlaid on it. It runs full width
of the card at its own 16:9 and the copy sits in a band directly beneath. Same
at every breakpoint on purpose: a 16:9 poster in a tall mobile card gets
cropped by `object-fit: cover`, and the headline, being on the left, is the
first thing lost.

**The live `h1`/`h2` stay in the markup** with `.visually-hidden` (defined in
`base.css`), so the page still has real headings for search engines and screen
readers even though the visible headline is pixels. Do not delete them.

**The page is styled to match the artwork:** warm paper (`#EDE9E1`), charcoal
type, cobalt accent (`#1B47D8`, sampled from the posters), the mug's ember
orange for small highlights, a film-grain overlay, and **Anton** for all
headings — the closest web face to the condensed grotesque burned into the
posters. Note this page deliberately drops the brand teal; it is the one page
that does.

Trade-off to remember: baked-in type does not reflow on mobile and cannot be
edited without regenerating the image.

---

## Masterclass Checkout (`masterclass/`, `api/razorpay/`)

The page follows the home page's language on purpose: warm-white page, white rounded section cards,
pale aqua panels, hairline borders, teal italic accents and forest buttons. No dark panels, no orange.

Every "Save my seat" button opens the pop-up in `masterclass/index.html`. The pop-up collects
name, email, WhatsApp number and the add-on. Razorpay's own window then takes the payment.

- **Prices that count live in the server config**, not in `js/masterclass.js`, which is display only.
- **Secrets never go in this repo.** Everything here is served publicly. The real config is
  `/home/u886151741/domains/thegurukulofai.com/private/razorpay-config.php`, based on
  `api/razorpay/config.sample.php`.
- `create-order.php` sets the price and saves the buyer's details in the order notes, so unpaid
  checkouts are visible in the Razorpay dashboard for manual follow-up.
- Coupons come from two places, and both lower the seat only (the add-on stays full price):
  - **Dashboard coupons** (the usual way): made on the dashboard's Coupons page and kept in the `coupons` table.
    Each has a ₹-off or %-off discount, an optional start, an end (1 hour, 24 hours, 7 days, never, or an exact
    IST time; it stops at that exact second), an optional use limit, and pause. Codes are stored in capitals
    with no spaces, so buyers can type them either way. `tg_coupon_claim` in `store.php` checks them.
  - **The config coupon** (`coupon` block in the server config, never in this repo): never expires, optional
    `max_uses`, counted in a file. Checked first. Remove the block to switch it off.
- The coupon field shows only on `/masterclass/?coupon`. A shared link `/masterclass/?coupon=CODE` also fills the
  code in and applies it when the pop-up opens. The Apply button calls `check-coupon.php`, which uses nothing up.
  A use is counted when the payment window opens: `create-order.php` takes it in the same database statement
  that checks the limit (so two buyers can't both take the last use) and gives it back if Razorpay fails.
  The seat price is always worked out on the server; the browser's number is display only. The seat never
  drops below ₹1 (Razorpay's minimum). A buyer who closes the payment window and tries again uses a second go.
  Each booking records which dashboard coupon it used (`bookings.coupon_id`); the code itself never goes to Razorpay.
- Session dates roll by themselves: every Sunday 3 PM IST from 22 Nov 2026 (the old Wednesday sessions stopped). Sales for a session
  close 15 minutes before it. The rule is in `rp_next_session` (`lib.php`, which decides) and `nextSession` (`js/masterclass.js`, display); change both
  together. Dates in `masterclass/index.html` are placeholders the script replaces, so never type a real date there.
- `verify.php` checks the payment signature and returns the WhatsApp community link for the
  thank-you page. The link is never in the public page source.
- `webhook.php` is the source of truth. It handles `order.paid`, ignores other products (the
  Razorpay account is shared), and emails the buyer and Deenu once per payment.
- A modal `<dialog>` sits above Razorpay's window, so the pop-up must close before `rzp.open()`.
- Emails go through the `mail_from` mailbox over SMTP (`smtp` in the server config). PHP `mail()` is
  only the backup: it fails DMARC here, because the sender is a shared Hostinger address.
- **Meta Pixel + Conversions API.** `js/meta-pixel.js` sends PageView, ViewContent, InitiateCheckout (pop-up opens),
  AddPaymentInfo (Razorpay opens) and Purchase (thank-you page). It only reports from the live domain, never from localhost.
  The server sends its own Purchase from `webhook.php` (`rp_meta_purchase` in `lib.php`) with the same event ID,
  `purchase_<payment id>`, so Meta keeps one. `create-order.php` saves the buyer's `_fbp`/`_fbc` cookies, IP and browser
  for the order in a private folder next to `sent_dir`, and the webhook deletes them after sending. The Pixel ID lives in
  `js/meta-pixel.js` and in the server config's `meta` block, so change both together. The access token lives only in
  the server config. Leave out the `meta` block, or leave a `PASTE` value, and the server sends nothing.
  `test_event_code` sends server events to Events Manager > Test events only, so remove it after testing.
- WhatsApp messages go straight through Meta's WhatsApp Cloud API: see "Masterclass WhatsApp" below.
- Media goes to Bunny under `masterclass/` with `scripts/bunny-upload-masterclass.sh`. It needs a
  local `.env`, which is gitignored.
- Page-sized copies live in `assets/images/masterclass/` (hero background, logo, avatar). Keep them small:
  the originals are far larger than this page needs. Compress real proof screenshots to WebP before adding them.
- Bunny caches for 30 days, so give changed media a new filename rather than overwriting it.
- The page's reels come from Bunny. Deenu confirmed permission for all creators shown (Nishant, Vishnu, Farhan,
  Achintya, Ankit, Nandini, Rishav) on 7 Nov 2026. `masterclass/placeholder-media/` holds local originals and is gitignored.

---

## Masterclass Dashboard (`api/admin/`)

Dashboard at `/api/admin/`. Sign-in is the admin password (hash in the private config's `admin`
block). **Two-step sign-in is built but switched off** (Deenu's call, 21 Nov 2026): it turns on
only with `'two_step' => true` in that `admin` block (`ADMIN_TWO_STEP` in `index.php`). When on,
sign-in also needs a 6-digit code from an authenticator app (`lib/totp.php`, key in the
`admin_auth` table, 8 one-time backup codes), every settings change needs a fresh code, and
password-only sessions are sent back to sign in. The first sign-in after switching it on sets it
up, so Deenu should be the one to do it. Lost phone and backup codes: empty the `admin_auth`
table in phpMyAdmin and sign in again to set it up afresh. Health shows an info line while it is off.

**What it can change (and nothing else):**
- **Settings page:** the Zoom link, the WhatsApp community link, the coupon (pause, use limit), and a WhatsApp messages pause switch.
  Each change is checked (plus a fresh authenticator code when two-step is on), logged in `setting_changes` and `audit`,
  and can be undone from the history. Values are saved to the private `razorpay-settings.json`,
  which `rp_config()` lays over the config file (`rp_apply_settings` in `lib.php`), so every
  script sees them at once. The allowed names are `RP_DASHBOARD_SETTINGS` in `lib.php`.
  Prices, the schedule, email wording and secrets are deliberately not editable: change them in code.
- **Add-on delivered:** marks a buyer's recording + Vault as sent (`bookings.addon_sent_at`), one
  at a time or a whole past session. Unsent ones from past sessions show as a health warning.
- **Resend confirmation:** rebuilds the buyer's email exactly as the webhook does and sends it; it
  then counts as sent, so a late Razorpay retry won't send a second copy. Asks to confirm first.
- **Coupons page:** make one coupon with a typed code, or 2 to 200 random codes at once (a batch, such as
  INSTA-7KQ4MX, shown as one row that opens into its own list and CSV download of codes and links). Each row
  shows uses, paid seats, status (active, starts later, paused, used up, expired) and a ready link to copy
  (click once to select it). Pause, resume and delete (asks first) work on one code or a whole batch.
  Every change goes through `lib/actions.php` into `lib/coupons.php`, is logged in `audit`, and needs a
  fresh authenticator code when two-step is on. The config coupon stays on the Settings page.
- **Inbox:** answer WhatsApp messages from the business number, send the follow-up template after 24 hours,
  quick replies, tags, Mark done, and stop or restart WhatsApp for one number (see "Masterclass WhatsApp").
- **Templates (WhatsApp):** write, check, submit, duplicate and delete your own WhatsApp templates. The built-in
  booking templates stay in code and are shown locked (see "Masterclass WhatsApp").
- **Broadcasts (WhatsApp):** send one approved template to a group, tested first, with a typed confirmation of the
  count; cancel the rest; resend to people who did not read (see "Masterclass WhatsApp"). Settings has the daily
  limit (`whatsapp.daily_cap`) and your own test number (`whatsapp.my_number`).
- **Automations (WhatsApp):** rules that send a template (or, for a keyword, a short text) when something happens;
  switch each on or off, test, edit, delete. The built-in booking messages are shown locked, with the checkout
  coupon's on/off switch (see "Masterclass WhatsApp").
- **Contacts (WhatsApp):** add or remove a tag for the people ticked on one page of the list (up to 100), and
  download the list as CSV (see "Masterclass WhatsApp"). The WhatsApp **Overview** only shows.
- CSV downloads (logged) and WhatsApp chat links.

**Adding a page:** one line in `api/admin/lib/sections.php` (menu label, icon, group, period picker)
and one file `api/admin/views/<key>.php` that returns a closure giving `body` (and optionally
`title`, `intro`, `actions`, `back`). Build pages only from `lib/ui.php` parts (KPIs, cards, tables,
pills, column chart, bars, facts, chips, checks, mail preview, `ui_post` forms, `ui_form` for a form with
several buttons, `ui_tick` row boxes, `ui_pager`), so every page looks the same. Every change goes through `lib/actions.php` (POST, page token, audit, message, back).

- **Layout rule:** one row of four KPIs, then cards on a six-column grid sized `third`, `half`,
  `two-thirds` or `full`. Styles live only in `api/admin/admin.css` (bump `ADMIN_CSS_VERSION` in
  `index.php` when it changes). No JavaScript: the chart tooltips are pure CSS.
- **Health checks** (Overview, System health, the menu badge) are all in `admin_checks` in
  `lib/checks.php`. Add a new check there and it appears everywhere.
- **Data it reads:** the contact store; the webhook's sent markers (confirmation and Meta status per
  payment); `razorpay-worker.last` (the 5-minute task's heartbeat); the coupon counter; the private
  error log; Razorpay payouts (whole account, cached 15 minutes); Meta ad spend (cached 30 minutes,
  only when `meta.ad_account_id` and a read-only `meta.ads_token` are in the private config).
- **Funnel counts:** `js/masterclass.js` sends `view` (once per browser visit) and `popup` to
  `api/razorpay/track.php`, live domain only. Only day totals are kept (`events` table).
- **Fees and refunds:** the webhook saves Razorpay's fee; the nightly reconcile fills any missing fee
  and refreshes refunds (`fee_paise`, `tax_paise`, `refunded_paise` on `bookings`).
- Schema version 11 (5 added `coupons` and `bookings.coupon_id`; 6 added WhatsApp message ids, delivered/read times and `wa_inbound`;
  7 added the inbox: `wa_contacts`, `wa_messages`, `wa_tags`, `wa_quick_replies`; 8 added `wa_templates`; 9 added
  `wa_broadcasts`, `outbox.params`/`source`/`source_id` and `wa_contacts.marketing_off_at`; 10 added `wa_rules` with the two
  ready-made rules; 11 added the cleanup indexes `wa_messages (body_gone, created_at)` and `outbox params(1)`). `api/admin/lib` and `api/admin/views` return 404 from the web (`.htaccess`).

## Masterclass WhatsApp (`api/razorpay/whatsapp.php`, `api/whatsapp/webhook.php`)

Sent directly through Meta's WhatsApp Cloud API (no AiSensy or other middleman), from
**+91 78278 76564**, which is **API only**: it has no WhatsApp app on any phone. Decided by
Deenu on 21 Nov 2026 (cheaper, data stays in our MySQL, one schedule for email and WhatsApp).
Deenu's setup steps are in `docs/whatsapp-setup.md`.

**Setup status (22 Nov 2026):** steps 1–5 are done. The Meta business portfolio is **Neuraltech AI**
(verified), not Black Sheep Media. The app is **Gurukul Website** (use case "Connect with customers through
WhatsApp"), still in Development mode with no webhook yet. The number shows **Registered**: Deenu did it in
Meta's guided Production setup, so the dashboard's Register form should stay hidden. Meta's newer screens call
the WABA ID the **Messaging Account ID**. The system user `website-whatsapp` has a never-expiring token with only
`whatsapp_business_messaging` and `whatsapp_business_management`. The private config has the `whatsapp` block
with `enabled => false` and `test_numbers` set. Still to do after "push it": the webhook, switching on
**Subscribe webhooks** in the app's Production setup, publishing the app, Submit to Meta, a real test, then
emptying `test_numbers`.

- **What goes out:** the seat confirmation (within 5 minutes of paying) and the same four reminders
  as the emails, as well as the emails, never instead of them. All five are "utility" templates.
  v1 has no failed-payment notice and no unpaid nudge (Deenu's call); add them only if he asks.
- **All the wording** is in `tg_wa_templates()` at the top of `whatsapp.php`. Approved wording can't be
  edited: change it under a **new template name**, then press Submit to Meta on the dashboard.
  Keep them free of anything promotional, or Meta moves them to the dearer marketing category.
  Meta did exactly that to `mc_class_live` (23 Nov 2026), so "We're live" is now `mc_class_started`.
  Template buttons can't link to WhatsApp (`chat.whatsapp.com` was refused as "Invalid parameter"), so the
  seat confirmation's community button goes to `/join/community/?t=<token>`, which forwards to the invite link.
- **Join button only in the last two WhatsApp messages** (15-minute and "We're live"), Deenu's call on 23 Nov 2026.
  The confirmation, day-before (`mc_reminder_day_before`) and 3-hour (`mc_reminder_3_hours`) messages say the
  joining link comes at 2:45 PM instead. The emails still carry the join link as before.
- **Checkout coupon (template `nudge`, `mc_checkout_coupon`, marketing):** someone who filled the pop-up but hasn't paid
  20 minutes after their **last** try gets one WhatsApp with a personal coupon named after them (PRIYA200; PRIYA200B if
  taken; FRIEND200 without English letters): ₹200 off, 2 hours from sending, 3 uses (a failed retry takes a use),
  made at send time in coupon batch `c0c0c200`. One per person per session; never if they paid since (same email or
  phone), said STOP, or the try is over 3 hours old. Off until switched on in Settings (`whatsapp.checkout_coupon`).
  Constants `TG_NUDGE_*` in `whatsapp.php`; the amount and hours are also in the template wording. The WhatsApp tab
  shows sent, paid with it and ₹ recovered. Checkout times are stamped from PHP's clock (`tg_save_lead`).
- **Meta's template rules learned the hard way:** a body can't start or end on a `{{n}}` (the 3-hour one was refused for ending "…at {{4}}."), and buttons can't link to WhatsApp. Refusals show Meta's detailed reason in the Activity log.
- **Who gets them:** paid buyers with a consent version (they saw the pop-up wording), a usable number,
  no STOP, and no "no more reminders" click. While `test_numbers` in the config has numbers, only those
  get anything. A buyer who paid before WhatsApp was on gets the reminders still ahead, but no late confirmation.
- **The outbox** plans WhatsApp rows next to the email rows (`channel = 'whatsapp'`, dedupe key `wa:<template>:b<id>:<time>`).
  Email and WhatsApp fail separately: three WhatsApp failures in a row pause WhatsApp for that run only.
  Meta's busy and rate-limit errors retry; anything else (bad number, unapproved template) fails at once and alerts Deenu.
  A run stops sending after 150 seconds (`outbox.run_seconds`) and leaves the rest queued, so it never outlives the
  5-minute cron. Before each WhatsApp row it reads the Settings file again (`tg_wa_paused_now`), so a pause set
  mid-run stops the rest of that batch. Test sends (WhatsApp tab, broadcast and rule tests) are outbox rows with
  `source = 'test'`, marked told from the start: never in the 9 AM summary's counts, never in an alert email.
- **The webhook** answers Meta's GET check with `verify_token`, refuses any POST whose `X-Hub-Signature-256` doesn't
  match `app_secret`, records delivered/read/failed on the outbox row (`provider_id` = Meta's message id), handles
  STOP/START (and Hinglish "band karo"), stores every other message in the inbox, and emails Deenu a copy with a
  link to answer it in the inbox (switch: `whatsapp.forward_replies`, on by default). The automatic STOP/START answers
  go only while WhatsApp is enabled, not paused and (in test mode) to a test number; the STOP itself is always recorded.
  `wa_inbound` keeps only id, number and kind, for good, so Meta's repeats are ignored and STOP is never forgotten.
  It also emails Deenu when a template is rejected, paused, moved to marketing or drops to red quality
  (`message_template_quality_update`), and keeps the status, category and quality of templates written on the dashboard.
  The email says "does not go out" only for rejected, paused or disabled; for quality and category news, "It still sends for now".
- **Message text is kept for 30 days, then deleted** (Deenu's decision, 23 Nov 2026). `wa_messages` holds every
  message in and out with its text; the worker's `tg_wa_retention` (`api/razorpay/wa_store.php`) empties `body` after
  30 days and keeps the row, so counts, ticks and spend estimates don't change. `body_gone` is 1 (text removed) or 2
  (there was no text), so each old row is visited once; it runs before the sends in every worker run. Nightly, reconcile clears the profile
  name of WhatsApp-only conversations quiet for 90 days. Nothing WhatsApp is deleted by age. The privacy policy says so.
  Audit and error-log lines name the number and a length, never the words of a message.
- **Inbox** (dashboard > WhatsApp > Inbox, `views/inbox.php`, `lib/inbox.php`): every conversation (`wa_contacts`,
  one row per number, linked to the newest contact with that number). Free replies only inside Meta's 24-hour window
  from their last message; after it closes, only the approved `reopen` template (`gurukul_follow_up`, utility) can
  restart it. Who may get what is decided in one place, `tg_wa_can_message` (reply / template / marketing), after the
  switches: enabled, not paused, the test list, STOP. Quick replies, tags, Mark done, manual STOP/START (START needs a
  "they asked" tick), an unread badge in the menu, and a health warning when someone waited over 12 hours. The list
  reloads every 30 seconds with `auto=1`, which does not count as activity, so the 30-minute idle sign-out still works.
  It holds no forms but search: Quick replies and the reply emails switch live on `?view=inbox&manage=1`, which never
  reloads, and neither does a conversation. The default list is "Wrote to you" (sorted by their newest message), so
  session-day reminders don't bury questions; "All" adds everyone we only sent booking messages to.
  Every send form carries a one-time code (`admin_once` in `index.php`), so a double click sends once. The follow-up
  template is offered only to people who wrote first (its wording follows up on their message); a dashboard START
  never counts as "messaged us first". Inbox template sends write a final outbox row (dedupe `wa:inbox:...`) so they
  show in the send log.
  The dashboard's clock is `rp_now` (the test clock in tests); the idle sign-out stays on real time.
- **Templates** (dashboard > WhatsApp > Templates, `views/wa_templates.php`, `lib/wa_templates.php`; rules and Meta calls in
  `api/razorpay/wa_custom.php`; table `wa_templates`, schema 8): Deenu writes text templates (header, message with
  `{{1}}`-style variables, footer, up to 3 buttons: links or quick replies), utility or marketing, in the config's one
  language. `tg_wat_check` lists in plain English what Meta would refuse (name format, a built-in name, a name used or
  deleted in the last 30 days, a variable at the start or end even before a full stop, two variables together, a gap in
  the numbers, a missing example, a variable in the header or footer, header newlines or emoji, button text over 25, a
  WhatsApp link in a button, a link variable not at the very end) and warns on offer words in a utility template.
  Errors block submitting; warnings don't. Each variable is filled at send time from a field (`TG_WA_FIELDS`: first
  name, full name, their paid session, the next session, the event name, or text typed when sending). Submitted
  templates are never edited: "Duplicate" makes `<name>_v2`. Deleting a draft just removes it; deleting a submitted
  one deletes it at Meta too and keeps the row, because Meta blocks the name for about 30 days. Status, category and
  quality come from the webhook and from reading Meta's list (all pages) when the page opens, at most every 10 minutes,
  or at once with "Refresh from Meta". The seven built-in templates are never rows here, so nothing on the page can
  change them. Approved templates show in the inbox (once the window has closed) for anyone whose details fill them;
  a send goes straight to Meta and writes a final outbox row with template `c:<id>`, so the worker never sends it again.
  A marketing one follows the marketing rules (not 9 PM to 9 AM, not to someone who turned off our marketing), and
  Meta's 131050 answer marks the number marketing-off. When Meta does not answer at all, the flash says to try again
  in a minute and to open System health if it keeps failing (`admin_wa_next_step`).
  Media headers need Meta's upload API and the app id, so they are left for later.
- **Broadcasts** (dashboard > WhatsApp > Broadcasts, `views/broadcasts.php`, `lib/broadcasts.php`; audience, limit and
  rules in `api/razorpay/wa_bulk.php`; sending in `tg_outbox_bulk` in `outbox.php`; table `wa_broadcasts`, schema 9):
  one approved template of your own to a group, in four steps. **Message:** the template, and what fills each variable.
  **Who:** paid buyers (optionally one session), add-on buyers, buyers without the add-on, checkouts not paid (7, 30 or
  90 days), people who messaged you (24 hours to ever), or everyone; then tag in, tag out, and "Only people who ticked
  the offers box". Always applied (`tg_wa_audience`): a valid number once each, consent (pop-up `consent_version`) **or**
  they messaged us first (`wa_inbound`), never STOP, the test list while it has numbers, and for marketing not
  `marketing_off_at` (Meta's 131050). The preview shows the count, who was left out and why, how many ticked the offers
  box, the first ten people with their own message, the estimated cost (count × ₹0.86 marketing or ₹0.15 utility) and the
  plan against the daily limit. **Test:** required, to a number on the test list while it is set, else to
  `whatsapp.my_number`; any change to steps 1–2 needs a new one (`test_hash`). **Send:** now or at a time (up to 30 days
  ahead), with the number of people typed; a count that changed since the preview is refused with the new number.
  - **Daily limit** (`whatsapp.daily_cap`, default 250, Meta's lowest tier): the most different people sent any template
    in a rolling 24 hours, the way Meta counts its messaging limit. Everything counts (booking messages, the checkout
    coupon, inbox templates, tests, broadcasts); free replies don't. Booking messages are never held; broadcast rows
    yield to them and to a reserve for booking messages queued in the next 24 hours. Confirming spreads the rows by day
    at the limit (day one gets today's room), each row expiring 48 hours after its own time; more than 7 days is refused.
    Inbox template sends are refused once the room is gone.
  - **Quiet hours:** no marketing between 9 PM and 9 AM IST. A time inside them is refused; "Send now" at night starts at
    9 AM; a held row waits until 9 AM. Utility may go any time.
  - **Bulk rows** (`source` broadcast, automation or inbox) never carry a `booking_id`, so the booking cancels never touch
    them; the values (and a first name) are in `params`, cleared 30 days after the row is final. The worker sends them
    after the booking messages, up to `outbox.wa_bulk_per_run` (200) a run, re-checking each: test list, STOP ("Said STOP
    before it was sent", which START never revives), consent and marketing-off, the broadcast still on, the template still
    APPROVED in `wa_templates` (PAUSED waits an hour; DISABLED, REJECTED or deleted fails the rest with one alert), quiet
    hours, then the limit. Meta errors about one person (131049, 131050, 131026, 131047, 131021) fail just that row with
    a plain reason, no alert email and no "WhatsApp is down" streak; anything else counts and alerts as for bookings.
    Rows still waiting when they expire show as "Not sent" on the page and in the 9 AM summary's own line.
  - **Results:** sent, delivered, read, replied (they wrote within 72 hours), failed and not sent, the reasons grouped,
    everyone listed, "Cancel the rest" while sending, and "Resend to people who did not read" 12 hours after it
    finished (a new draft, with its own test and confirmation). A template used by a scheduled or sending broadcast
    can't be deleted. The global pause stops broadcasts too; they carry on when switched back on.
  - **Marketing audience (Deenu's call, 23 Nov 2026):** marketing broadcasts may go to everyone who agreed to WhatsApp
    booking updates or messaged us; no extra permission is required, so the dashboard shows no warning about it.
    "Only people who ticked the offers box" stays as an optional filter. The pop-up's offers box stays **unticked**
    (a pre-ticked box isn't valid consent under India's DPDP Act or Meta's opt-in rules); consent version `v2` gave it
    friendlier wording: "Yes, send me early-bird prices and new session dates on WhatsApp and email."
- **Automations** (dashboard > WhatsApp > Automations, `views/automations.php`, `lib/automations.php`; planning and
  keyword replies in `api/razorpay/wa_rules.php`; table `wa_rules`, schema 10): a rule is a trigger, a wait (0 minutes
  to 7 days), one approved template with what fills each variable (as broadcasts), and an optional filter (tag in, tag
  out, add-on yes or no, ticked the offers box). Triggers: paid, paid with the add-on, a checkout still not paid after
  X minutes (10 to 1,440), X hours before the class (1 to 168; the wait must land before the start), X hours after the
  class ends (the class is 3 hours), joined (opened their join link) or did not join (both at the class end), a tag
  added, and a keyword in a message.
  - **Never reaches back:** only things that happen after the rule was switched on (`enabled_at`) and within the last
    24 hours count. Editing a rule that is on switches it off and cancels its waiting messages; switching off cancels
    them too. One message per rule per person and event (dedupe `wa:r<id>:<trigger><value>:b<booking>`,
    `wa:r<id>:unpaid<minutes>:c<contact>:<session>`, `:t<wa_contact>:<tag>`, `:m<message>`). The trigger is in the key, so
    after a trigger edit (say 1 day before becomes 3 hours before) the new moment is a new message. Booking triggers count only seats paid by the trigger's moment (like the
    reminders).
  - **Planning** (worker step 2c, `tg_rules_plan`, only while WhatsApp is on and not paused): each trigger becomes a bulk
    outbox row (`source` automation, `source_id` the rule, `booking_id` NULL, the booking in `params`). Someone who may
    not get it (no consent and never messaged us, STOP, the test list, marketing off, a field that can't be filled) gets a
    row already cancelled with the reason, so the rule's page says why. Sending is `tg_outbox_bulk`, as for broadcasts
    (quiet hours, daily limit, template status, one-person errors), plus: the rule still on, "Paid in the meantime" for
    not-paid rows, "The booking is no longer paid" for seat rows. Rows expire 24 hours after their time, a before-class row
    at the start and a not-paid row at the session it was for. A rule whose template is no longer approved plans nothing
    (health warning).
  - **Keyword rules** run in the webhook after Meta has its 200: the whole message or a whole word or phrase, any case,
    up to 10 keywords of 2 to 30 characters, never a STOP or START word. Only the first matching rule acts. A text reply
    is free (their window just opened) and is written as a final outbox row with template `text`, which the daily limit
    ignores; a template is planned for the worker. Never to a STOP number, once per rule per number in 6 hours, only
    while WhatsApp is on, not paused and the number allowed.
  - **Ready-made rules** (seeded off, can't be deleted): "Recording offer after class" (2 hours after the class, people
    without the add-on, marketing) and "Feedback request" (a day after the class, people who joined). "Make its template"
    writes a suggested draft with the link button empty, so it can't be submitted until Deenu adds his own page. A rule
    can be saved with its own draft, but switched on only once the template is approved.
  - **Tests** go to the test list while it is set, else to `whatsapp.my_number`, filled with the newest matching buyer
    (or "Priya"). A template used by a rule that is on can't be deleted.
  - A rule's template does not count as answering someone in the inbox's "Waiting for you"; a keyword text reply does.
- **Contacts** (dashboard > WhatsApp > Contacts, `views/wa_contacts.php`, `lib/wa_contacts.php`; no schema change):
  one row per WhatsApp number, merging the contact store (numbers as typed, normalised with `tg_wa_number`) with
  `wa_contacts`; a number in both shows once, with the newest contact's name and email. Type: Buyer (a paid seat),
  Lead, or WhatsApp only. KPIs: Reachable (consent or messaged us, no STOP: the person part of the template rule),
  Said STOP, Marketing off, Tagged. Search (name, email, number) and chips (All, Buyers, Leads, Messaged us, STOP, one
  per tag, Ticked the offers box) narrow the list; 100 rows a page (`&p=N`). Tick rows, type a tag, then "Add tag to
  ticked" or "Remove tag from ticked" (`wac_tag`, at most 100 numbers, every one a valid WhatsApp number or nothing
  changes). Adding makes the `wa_contacts` row if there is none. A tag added here (`added_by` 'list') never starts a
  "tag is added" rule: that would message a page of people with no test and no typed confirmation. The form and the
  flash say so, naming the rule; only a tag added in someone's chat (`added_by` 'admin') starts it.
  Audited with counts ("tag vip added to 12 of 12 ticked"). "Download CSV" (logged) gives the current chip and search:
  name, number, email, type, tags, consent version, STOP, marketing off, last message time. Never message text.
- **Overview** (dashboard > WhatsApp > Overview, `views/wa_overview.php`, first in the WhatsApp menu, with a period
  picker): Conversations (numbers with a message in the period), Estimated spend (billable `wa_messages` not failed ×
  ₹0.86 marketing or ₹0.15 utility; always called an estimate, Meta's invoice is the truth), Quality (Meta's rating,
  green, yellow or red) and Messaging limit (Meta's tier, plus how many different people got a business-started
  template in the last 24 hours, the count the daily limit uses). Cards: messages per day, spend by category, best and
  weakest templates (read rate then reply rate, at least 10 sends in the period, test sends left out; Meta's template
  quality beside each), the Connection card (shared with the WhatsApp tab) and the WhatsApp health checks.
  - `admin_wa_meta` asks the number for `messaging_limit_tier` and `throughput` too; if Meta refuses those fields it asks
    again without them, then for the basics. Quality is read from `quality_rating`, or `quality_score.score` on newer
    Graph versions.
  - The webhook handles `phone_number_quality_update` (`tg_wa_number_event` in `wa_store.php`): it saves the news in the
    private `whatsapp-quality.last` (the overview falls back to that limit when Meta won't say), forgets the dashboard's
    saved copy so the next page reads Meta again, and emails Deenu when the number is flagged or its limit goes down
    ("ACTION: Meta lowered your WhatsApp number's rating") or changes. Subscribe the Meta app to that webhook field.
- **Config:** the private config's `whatsapp` block (see `config.sample.php`). Sending needs `'enabled' => true`
  and every key filled in. The token and app secret never leave the server; the token goes in an
  Authorization header, never a URL or a log.
- **Dashboard:** Messages (was Emails) > WhatsApp shows the number as Meta sees it, template approval,
  a WhatsApp-style preview, Submit to Meta, Connect webhook, Register number (only when Meta says it's needed)
  and Send a test (test numbers only). Settings has a WhatsApp pause switch (`whatsapp.paused`). Health checks
  are in `admin_wa_checks` (`api/admin/lib/whatsapp.php`).
- **Portfolio:** the ads portfolio's WhatsApp buttons point at this same number, so those enquiries now
  arrive in the inbox (and by email) through the webhook.

---

## Still To Do
1. Add Google Analytics
2. Update OG image for social sharing (`assets/og-image.jpg`)
3. Replace testimonials with real student quotes
4. Review `new_design` branch → merge to `dev` when approved
