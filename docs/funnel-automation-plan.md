# Masterclass funnel: analysis and automation plan

Written 19 Nov 2026. This is a plan only. No code has changed.

> **Do not merge this file to `main` as it is.** Everything in the repo is served publicly, and `.htaccess` does not block `docs/` yet. Add `docs` to the 404 rule in `.htaccess` first, or keep this file off `main`.

**The short version**

- Today, a sale works and the buyer gets one email. Everything after that is done by hand.
- There is no list of leads anywhere. Contact details sit in Razorpay order notes and in Deenu's inbox.
- The fix is one private contact table on Hostinger, a cron job that sends scheduled messages, and a WhatsApp provider.
- New running cost is about **₹3,000 to ₹6,000 a month** at today's volume.
- Build order: contact store first, then email reminders, then WhatsApp, then abandoned-checkout recovery. Those four should exist before ad spend goes up.

---

## 1. What happens today, step by step

### Step 1: A visitor lands on `/masterclass/`

- `js/masterclass.js` works out the next Sunday 3 PM IST and shows it. `rp_next_session` in `lib.php` makes the real decision.
- `js/meta-pixel.js` sends PageView and ViewContent to Meta, from the live domain only.
- UTM tags and `fbclid` stay in the page address. Nothing is stored yet.
- **Contact data stored:** none.

### Step 2: The pop-up collects name, email and WhatsApp number

- Every "Save my seat" button opens the `<dialog>` pop-up. Meta gets InitiateCheckout.
- The form asks for name, email and a 10-digit number. The only wording under the number is "We'll use this for class updates."
- There is no WhatsApp opt-in line, no marketing checkbox and no privacy link in the pop-up.
- **Nothing is saved until the buyer taps Pay.** Someone who fills the form and closes it is lost.

### Step 3: The buyer taps Pay, and Razorpay opens

- The browser calls `create-order.php`. It checks the details, rate-limits, checks the session and coupon, and sets the price.
- It creates a Razorpay order. The buyer's details go into the order **notes**: product tag, name, email, phone, add-on, session (as text), coupon yes/no, UTM tags.
- `rp_meta_remember` saves the Meta cookies, IP and browser for that order in a private file.
- The pop-up closes and Razorpay's window opens. Meta gets AddPaymentInfo.
- **If Razorpay's API is down, no order is created and the lead is lost.** Nothing is saved before that call.

### Step 4a: Payment succeeds

- Razorpay calls `handler`, and the browser posts to `verify.php`. It checks the signature and returns the WhatsApp community link.
- The browser stores the link, payment ID, date and amount in `sessionStorage`, then opens the thank-you page.

### Step 4b: The buyer abandons checkout

- If they close Razorpay's window, the pop-up comes back with a "try again" line.
- If they leave the page, the order stays "created" in Razorpay with their details in the notes.
- **Nobody follows up.** Deenu would have to open the Razorpay dashboard and read orders one by one.
- Failed payments (`payment.failed`) are not handled at all. The webhook ignores every event except `order.paid`.

### Step 5: Thank-you page (`/masterclass/thank-you/`)

- Shows the join button for the WhatsApp community, the session date, an "Add to Google Calendar" link and the add-on note.
- Fires the browser Purchase event with ID `purchase_<payment id>`.
- If `sessionStorage` is empty (another device, private mode), the page says "check your email".
- There is no `.ics` file, so Apple and Outlook calendar users get nothing.

### Step 6: Webhook emails (`webhook.php`)

- Razorpay posts `order.paid`. The signature is checked. Orders for other products are ignored by the `product` note.
- A marker file in `sent_dir` makes sure each payment is handled once.
- The buyer email goes through SMTP only (since 19 Nov, `bf7dae9`). If SMTP fails, the webhook returns 500 so Razorpay retries for up to 24 hours, and Deenu gets one "ACTION: email not sent yet" note.
- After success, Deenu gets a "New seat" note (SMTP, with `mail()` as backup), then Meta gets the server Purchase.
- The new SMTP-only route has not yet been proven by a real sale.

### Step 7: The Sunday 3 PM IST session

- **All by hand.** Deenu posts the Zoom link and reminders in the WhatsApp community.
- Buyers who never joined the community get no Zoom link and no reminder.
- Nobody knows who attended.

### Step 8: After the session

- **All by hand.** Recording and Vault go to add-on buyers manually.
- No replay or no-show follow-up. No testimonial request.
- The ₹14,788 1:1 offer is only a link on the sales page. It is never pitched to buyers afterwards.
- 1:1 sales happen on a separate platform (`learn.thegurukulofai.com`), so this site never knows who bought it.

### Where contact data ends up today

The brief assumed `sent_dir` and the Meta temp files hold contact data. They don't. Here is the full list.

| Place | What it holds | Who it covers | How long |
|---|---|---|---|
| Razorpay order notes | Name, email, phone, add-on, session text, coupon flag, UTM | Everyone who tapped Pay | Forever, in Razorpay |
| Razorpay payment record | Email and phone as typed in Razorpay's window | Payers only | Forever, in Razorpay |
| Deenu's inbox (`mail_notify`) | "New seat" and "ACTION" notes with full details | Payers only | Until deleted |
| Buyer's inbox | Their own confirmation | Payers only | Theirs |
| `private/razorpay-sent/` | Payment ID and a status word. **No contact data.** | Payers | Forever |
| `private/razorpay-mailfail/` | Payment ID and a failure count | Failed emails | Until success |
| `private/razorpay-meta/` | Meta cookies, IP, browser, page. **No name, email or phone.** | Everyone who tapped Pay | Deleted on payment, or after about 7 days |
| `private/razorpay-rate/`, `razorpay-coupon/` | Hashed IP counters, coupon use count | — | Short / forever |
| Meta | Hashed email, phone and name with each Purchase | Payers | Meta's policy |
| WhatsApp community | Phone numbers of people who joined | Joiners only | Until they leave |

So there is **no single list**. Getting "everyone from Sunday's session" today means reading Razorpay orders or the inbox by hand.

---

## 2. Gaps, in priority order

### Must-have before scaling ads

1. **No contact store.** Leads and buyers are scattered. Abandoned leads are only in Razorpay notes. Fix: one MySQL table set (Phase 1).
2. **Leads are saved too late.** Saving happens inside the Razorpay order, so a Razorpay outage loses the lead. Fix: save the lead first, then create the order.
3. **The session is stored as text.** "Sunday, 22 November 2026, 3 PM to 6 PM IST" is hard to schedule against. Fix: store the session's timestamp too.
4. **No reminders.** No T-24h, T-3h, T-15m or "we're live" message on any channel. Buyers who skip the community never get the Zoom link. Fix: scheduled email (Phase 2), then WhatsApp (Phase 3).
5. **No WhatsApp automation.** Confirmation, community link, reminders and Zoom link are all manual. Fix: a WhatsApp provider plus approved templates (Phase 3).
6. **No abandoned-checkout recovery.** Every unpaid order is lost money. Fix: one email and one WhatsApp about 45 minutes after an unpaid order (Phase 4).
7. **No consent wording.** The pop-up has no WhatsApp opt-in line and no privacy link. Meta requires opt-in before business-started WhatsApp messages. Fix: two lines of copy and one optional checkbox (Phase 1).
8. **No safety net for missed webhooks.** If a webhook never arrives, nothing notices. Fix: a nightly check against Razorpay's order list that fills any gap (Phase 1).
9. **No health alerts.** Server errors go to a private log nobody reads. Fix: a daily digest email to Deenu, plus an alert when a message finally fails (Phase 2).
10. **Hosting and domain expire 26 Jan 2027 with auto-renew off.** If either lapses, the whole funnel stops. This is Deenu's task, and it is close.

### Nice-to-have, after the above

11. **Attendance tracking.** Who joined, who didn't. Needed for no-show follow-up and a fair 1:1 pitch.
12. **Replay and Vault delivery.** Send add-on buyers their recording and Vault link automatically.
13. **No-show follow-up.** "Sorry we missed you" with the replay offer (₹499 add-on).
14. **Post-session 1:1 pitch.** A short email and WhatsApp sequence for the ₹14,788 offer.
15. **Testimonial capture.** A form after the session, with a clear "you may show this publicly" tick, per the proof rules.
16. **`.ics` calendar invite.** For Apple and Outlook users, attached to the confirmation email.
17. **One-click segment broadcasts from a private admin page.** Phase 1 gives a CSV export that works with the provider's broadcast screen. The full in-site button comes later.
18. **Move bulk email to a provider** (Brevo) once broadcasts start, to protect the main mailbox's reputation.
19. **`verify.php` trusts any valid payment on the shared Razorpay account.** A buyer of another product on the same key could get the masterclass link. Low risk. Fix: check the order's `product` note before returning the link.
20. **One WhatsApp group for every batch.** Groups have a member cap and the invite link can be forwarded. Consider a fresh group or community per month.

---

## 3. Options compared, and what I recommend

### 3.1 WhatsApp sending

| | Meta Cloud API directly | AiSensy | Interakt | Wati | Gallabox |
|---|---|---|---|---|---|
| Platform fee (₹/month, ex-GST) | 0 | about 1,500–2,500 | about 2,500–3,000 | about 1,200–2,600 | about 2,400–3,000 |
| Message price | Meta's rate | Meta's rate plus markup (about 25% on marketing) | Meta's rate plus about 10% | Own rate card | Own rate card |
| Inbox for replies | You build it | Yes | Yes | Yes | Yes |
| Template approval | WhatsApp Manager | In the dashboard | In the dashboard | In the dashboard | In the dashboard |
| Calling from PHP | Graph API, token from a system user | One simple HTTP call per message | One simple HTTP call | One HTTP call | One HTTP call |
| Effort for us | Highest: tokens, status webhooks, opt-outs, inbox | Low | Low | Low | Low |

Vendor prices change often and the sources disagree. Confirm on each vendor's own page before signing up.

**Meta's own India rates** (per message, ex-GST, as reported in 2026): marketing about ₹0.86, utility about ₹0.115, authentication about ₹0.115. Replies inside a 24-hour window the buyer opened are free. Billing is per message since July 2025.

**Which message is which:**
- Utility: payment confirmation, community link, session reminders, Zoom link, replay delivery for a paid add-on. These must contain no selling, or Meta reclassifies them as marketing.
- Marketing: abandoned-checkout nudges, the 1:1 pitch, "next Sunday's session" broadcasts. Meta also caps how many marketing messages one person gets from all businesses, so some won't be delivered.

**Recommendation: AiSensy.** It was already suggested on 8 Nov. It's Indian, cheap at the entry plan, has an inbox for buyer replies, and sends a template with one HTTP call that PHP on Hostinger can make with `curl`. The provider sits behind one function (`wa_send`), so switching to Interakt or to Meta directly later is a small change. Move to the Cloud API only if message volume grows enough that the markup matters.

**How it's triggered:** `webhook.php` does not call AiSensy itself. It adds a row to an **outbox** table and returns. The cron worker sends it within five minutes, and the webhook also tries one send right after replying to Razorpay. That keeps the webhook fast, so a slow WhatsApp provider can never cause a Razorpay retry.

**Which number:** WhatsApp API needs a number that isn't active in the normal WhatsApp app (some providers offer "coexistence" to share one; check before choosing). Never Deenu's personal number. Options: a fresh number, or move the public +91 83838 40943. Deenu decides.

### 3.2 Email

**What goes out today:** the buyer confirmation and Deenu's notes, through the `mail_from` mailbox over Hostinger SMTP. SPF, DKIM and DMARC pass.

**What's missing:** reminders at T-24h, T-3h, T-15m and "we're live"; replay or no-show follow-up; the 1:1 pitch; abandoned-checkout nudge; testimonial request; unsubscribe handling; an `.ics` invite.

| | Keep Hostinger SMTP | Brevo | Resend |
|---|---|---|---|
| Cost | ₹0 | Free up to 300 a day; Starter about ₹625/month | Free up to 100 a day; Pro about $20 (₹1,700)/month |
| Sequences | We build them (same as WhatsApp) | Built in, or we drive them | We build them |
| Unsubscribe | We build it | Built in | We build it |
| Broadcast to 1,000+ | Risky: daily send cap and mailbox reputation | Fine | Fine on Pro |

**Recommendation:** keep Hostinger SMTP for the confirmation and reminders. It's proven, and reminder volume is small (about 300 a week at 50 buyers). Add **Brevo** in Phase 6 for bulk broadcasts and marketing, sending from its own subdomain so a spam complaint can't hurt `deenu@`. Our own server stays in charge of who gets what and when. Brevo is only the pipe.

### 3.3 Scheduling

| Option | Fits our rolling Sunday rule? | Notes |
|---|---|---|
| **Hostinger cron hitting a PHP script** | Yes. The script reads each booking's own session timestamp. | Free, on the plan we already have. Every 5 minutes is plenty. |
| Provider's scheduler (AiSensy, Brevo) | Poorly. They don't know our session rule, and data would split across two tools. | Fine for one-off broadcasts. |
| Scheduled cloud job | Yes | Another account, and it would need secrets outside Hostinger. Not worth it. |

**Recommendation: Hostinger cron, every 5 minutes**, running `php .../api/jobs/run.php`.

How it works:
- When a booking is paid, we queue its messages with a **due time** worked out from its session timestamp: T-24h, T-3h, T-15m, T+0.
- Each message also has an **expiry**. A "starts in 15 minutes" message is dropped, not sent, once the session has started. Someone who buys at 2 PM gets only the messages still in the future.
- The session timestamp comes from `rp_next_session` at order time and is stored on the booking. It's never worked out again, so a later rule change can't move existing bookings.
- The Zoom link comes from the private config, or from the admin page each week. It never goes in the repo.
- The script refuses to run from the web. It only runs from the command line.

### 3.4 One central contact store

| | MySQL on Hostinger + private admin page | Google Sheet | Provider's audience (AiSensy/Brevo contacts) |
|---|---|---|---|
| Cost | ₹0, included in the plan | ₹0 | Included |
| Safe for personal data | Yes. Same server, private credentials. | Weaker. A Google key on the server; sheets get shared by mistake. | Split across two vendors |
| Reliable from the webhook | Yes. Fast, transactional, unique keys stop duplicates. | Slow API calls, rate limits | Each only knows its own channel |
| Segment by session, status, add-on | Yes | By hand | Partly |
| One-click send | Built into the admin page | Needs Apps Script | Yes, but one channel each |

**Recommendation: MySQL on Hostinger is the one source of truth.** A small private admin page lists people, filters them, exports CSV and later sends broadcasts. The providers only send. A Google Sheet export can be added later for viewing if Deenu wants it.

**Tables, roughly:**
- `contacts`: name, email, phone, consent flags with timestamp and wording version, email unsubscribe time, WhatsApp opt-out time, bought 1:1 (yes/no).
- `bookings`: one per Razorpay order. Order ID (unique), payment ID (unique), session timestamp, status (`started`, `paid`, `failed`, `abandoned`), add-on, coupon, amount, UTM, created and paid times, attended (`unknown`, `yes`, `no`).
- `outbox`: channel, template, a unique **dedupe key** (for example `wa:confirm:pay_ABC`), due time, expiry, status, tries, last error, provider message ID.
- `audit`: who did what in the admin page, and when.

**Abandoned leads are captured** because `create-order.php` writes the contact and a `started` booking **before** calling Razorpay. A nightly reconcile job also reads Razorpay's order list, so nothing is missed. On day one it backfills every order since the 8 Nov launch.

**Admin page safety:** it lives under `/api/admin/`, is not linked anywhere, has `noindex`, requires a password whose hash lives in the private config, locks out after repeated wrong tries, and logs out after inactivity. Adding hPanel's directory password on top is a cheap second lock.

### 3.5 Abandoned-checkout recovery

- A booking still `started` after **45 minutes** becomes `abandoned`.
- Before sending anything, check that the same email or phone hasn't paid since (people often retry with a new order).
- Send **one email and one WhatsApp** (marketing template): "Your seat for Sunday isn't confirmed yet," with a link back to `/masterclass/` that pre-fills their details.
- At most one more nudge, at T-24h before that session. Then stop.
- Never if they unsubscribed, opted out, or the session has started.
- Razorpay's `payment.failed` webhook can mark a failed try straight away, so the nudge can say "your payment didn't go through".

### 3.6 Robustness

- **One message per payment.** Every outbox row has a unique dedupe key. Razorpay retries, double cron runs and double clicks all hit the same key and do nothing.
- **The checkout never depends on the new parts.** If MySQL is down, `create-order.php` still creates the order and the webhook still sends the confirmation email. The nightly reconcile fills the gap.
- **Retries with a limit.** Failed sends retry after 5, 15, 60 and 180 minutes, then stop as `failed`. Expired messages are marked `skipped`, never sent late.
- **Two channels cover each other.** Confirmations and reminders go by email and WhatsApp. If WhatsApp is down, email still arrives.
- **No overlapping workers.** The cron script takes a lock file, so two runs can't send the same batch.
- **Throttled sending.** Each cron run sends at most a set number (say 60), so a broadcast can't hit PHP's time limit or the mailbox's daily cap.
- **Alerts to Deenu.** One email when a message finally fails. A **daily digest at 9 AM**: yesterday's sales, new leads, abandoned, messages sent and failed, queue size. If the digest doesn't arrive, cron is broken. A free external uptime check can be added too.
- **Logs.** Every send is a row in `outbox` with the provider's reply. Errors go to the private log, and the admin page shows recent failures.
- **Backups.** Check how often Hostinger backs up the database on this plan. The admin page gets a CSV export button.

### 3.7 Compliance

Not legal advice. These are the basics to build in now.

**WhatsApp opt-in (pop-up).** Under the number field, replace "We'll use this for class updates." with:

> We'll send your seat confirmation, Zoom link and reminders to this number on WhatsApp, and by email.

Then add one optional, **unticked** checkbox:

> Also tell me about future sessions and offers on WhatsApp and email. You can stop anytime.

And a line above the Pay button:

> By continuing you agree to our Terms and Privacy policy. If you don't finish paying, we may send you one reminder.

Store which wording they saw (a version number), when, and from which IP.

**Opt-out.** A buyer who replies STOP on WhatsApp is marked opted out through the provider's webhook. Marketing stops on both channels.

**Email.** Every non-essential email has an unsubscribe link and a one-click `List-Unsubscribe` header. Reminders for a paid session get a "stop reminders" link too.

**DPDP Act basics.** The DPDP Rules were notified in Nov 2025, and most duties start around May 2027. Building to them now costs little.
- A clear notice at collection: what we collect, why, how to withdraw, and who to contact.
- Use data only for the stated purpose. Marketing needs the separate tick.
- Keep it only as long as needed. Suggested: abandoned leads deleted after 90 days if they never buy; buyers kept while they're customers. Deenu sets the numbers.
- Reasonable security: private credentials, prepared SQL statements, admin login, HTTPS.
- Honour deletion requests. The admin page gets a "delete this person" button.
- Report a breach to the Data Protection Board and the people affected.
- Update the privacy policy to name the WhatsApp and email providers, the retention periods and a grievance contact.

SMS isn't used, so TRAI DLT registration doesn't apply.

### 3.8 Other webinar-funnel pieces

- **Attendance.** Each buyer's reminder carries their own join link, `/join/?t=<token>`. It logs the click and redirects to Zoom. A click means "probably attended". For exact numbers, Deenu uploads Zoom's participant report (Zoom Pro) to the admin page, which matches people by email. Zoom's API can automate this later.
- **Replay access.** Add-on buyers get a private replay page checked against the contact store. The video sits on Bunny Stream with token links, so the link can't simply be shared. The Vault link lives in the config.
- **Calendar invite.** A small `ics.php` builds the invite from the booking's session time. Attach it to the confirmation email and link it on the thank-you page.
- **Reviews and testimonials.** A short form the day after: a rating, one line, and an optional "you may show this publicly with my name" tick. Only ticked answers can go on the page.

---

## 4. Monthly cost (₹, ex-GST)

Estimated for about 50 buyers and 150 abandoned checkouts a week.

| Item | Cost |
|---|---|
| MySQL, cron, admin page | ₹0 (current Hostinger plan) |
| AiSensy platform | about ₹1,500–2,500 |
| WhatsApp messages: about 6 utility + 1 marketing per buyer, 2 marketing per abandoned lead | about ₹1,500–2,500 with markup |
| Email: Hostinger SMTP for confirmations and reminders | ₹0 |
| Brevo, once broadcasts start | ₹0 on the free tier, about ₹625–1,350 on Starter |
| Zoom Pro (already needed for 3-hour sessions) | No change |
| Uptime check | ₹0 on a free tier |
| **Total new spend** | **about ₹3,000–6,000 a month** |

One recovered abandoned checkout (₹1,499) pays for most of a month.

---

## 5. Build order

Each step is small, tested with a throwaway config first (`TGAI_CONFIG`), shown to Deenu, and only pushed live after "push it".

### Phase 1: Contact store (no new vendor) — must-have

1. Create the tables. Add a `db` block to `config.sample.php` (placeholders only).
2. `create-order.php` saves the contact and a `started` booking **before** calling Razorpay. Adds `session_at` (timestamp) to the order notes. A database error never blocks the order.
3. `webhook.php` marks the booking `paid`. Also handle `payment.failed`.
4. Nightly reconcile job: read Razorpay orders, fill in anything missed. Run it once to backfill everything since 8 Nov.
5. Private admin page: login, list, filter by session, status and add-on, CSV export.
6. Consent wording and checkbox on the pop-up. Privacy policy update.
7. `verify.php` checks the order's product before returning the community link.

*Test:* a test-mode payment and an abandoned test order both show up correctly. Turning the database off still lets a sale through. Running reconcile twice changes nothing.

### Phase 2: Scheduler and email reminders (no new vendor) — must-have

1. `outbox` table and the cron worker with lock, retries, expiry and throttle.
2. Paid bookings queue T-24h, T-3h, T-15m and "we're live" emails, with each buyer's join link.
3. Zoom link from the config.
4. `.ics` attached to the confirmation.
5. Daily digest and final-failure alert to Deenu.
6. "Stop reminders" link.

*Test:* a fake session 30 minutes away with a clock override. A late buyer gets only future reminders. Killing SMTP mid-run retries and then alerts. Running the worker twice sends nothing twice.

### Phase 3: WhatsApp — must-have, needs Deenu's account and approved templates first

1. `wa_send()` for AiSensy, with the API key in the private config.
2. Confirmation (with community link), the four reminders, and the Zoom link, on WhatsApp too.
3. Provider webhook for STOP and delivery status.

*Test:* send to Deenu's own test number only. Check the outbox shows one row per message, and STOP marks the opt-out.

### Phase 4: Abandoned-checkout recovery — must-have

1. 45-minute rule, the "has this person paid since" check, one email plus one WhatsApp.
2. A pre-filled return link.
3. One last nudge at T-24h, then stop.

*Test:* an abandoned test order gets exactly two messages. Paying in between cancels them.

### Phase 5: After the session — nice-to-have

1. Join-link click tracking, plus Zoom report upload to mark attended and no-show.
2. Replay page and automatic Vault delivery for add-on buyers.
3. No-show message with the replay offer.
4. 1:1 pitch: email on day 1 and day 3, one WhatsApp. A "bought 1:1" tag to stop it (set by hand, or from the 1:1 platform if it offers a webhook).
5. Testimonial form with the public-use tick.

### Phase 6: One-click broadcasts — nice-to-have

1. Admin page: choose a segment, choose an approved template, see the count, **send a test to yourself**, then confirm.
2. Queue through the outbox, throttled.
3. Brevo for bulk email from a subdomain.

---

## 6. Tasks only Deenu can do

**Now (not part of the build, but urgent)**
- Renew hosting and the domain, or turn auto-renew on. Both expire **26 Jan 2027**.
- Two-factor logins on Razorpay, Hostinger, GitHub, Bunny and Google (still open from launch).

**Decisions**
- Pick the first phase to build.
- WhatsApp provider: AiSensy (recommended) or another.
- Which number to send WhatsApp from. A new one, or move the public number. Never the personal one.
- Approve the consent wording and the retention periods.
- Approve every message template's wording.

**Accounts and setup (I'll give exact steps for each)**
- Create the MySQL database and user in hPanel. Put the details in the private config yourself.
- Add the cron job in hPanel (I'll give the exact command).
- Sign up for the WhatsApp provider. Connect a Meta Business account.
- **Meta Business verification:** business documents (GST or Udyam), website domain verification, display name approval for "The Gurukul of AI". This can take days, so start early.
- Submit the WhatsApp templates in the provider's dashboard. Meta approval usually takes minutes to a day.
- Paste the provider API key into the private config.
- Zoom: confirm the plan, set up the Sunday meeting, and put the link in the config or admin page each week.
- If Brevo is added: create the account and add its DNS records (DKIM, SPF) in Hostinger DNS.
- Upload each recording, until recording delivery is automated.
- 1:1 platform: check whether it can send a webhook on purchase; otherwise mark 1:1 buyers by hand in the admin page.
- Optional: a free uptime-check account.

---

## Sources for prices

Prices change. Confirm on each vendor's own page before signing up.

- [Meta: Pricing on the WhatsApp Business Platform](https://developers.facebook.com/documentation/business-messaging/whatsapp/pricing.md)
- [baat.ai: What the WhatsApp Business API costs in India (2026)](https://baat.ai/blog/whatsapp-api-pricing-india)
- [Monty Mobile: WhatsApp API pricing in India, INR rates and GST](https://montymobile.com/blogs/whatsapp-business-api-pricing-in-india-inr-rates-gst-and-the-2026-currency-migration-deadline)
- [productgrowth.in: AiSensy vs Interakt vs Gallabox prices](https://productgrowth.in/tools/compare/aisensy-vs-interakt-vs-gallabox/)
- [go4whatsup: AiSensy vs Wati vs Interakt pricing, 2026](https://go4whatsup.com/blog/aisensy-vs-wati-vs-interakt-pricing-showdown-2026/)
- [codingclave: Gallabox pricing India 2026](https://codingclave.com/blog/gallabox-pricing-india-2026)
- [Brevo pricing guide 2026](https://tajo.io/blog/brevo-pricing-guide)
- [Resend pricing 2026](https://www.activepieces.com/blog/resend-pricing-2026-free-tier-plans-and-limits)
