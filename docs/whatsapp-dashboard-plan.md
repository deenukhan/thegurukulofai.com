# WhatsApp dashboard ("lighter AiSensy"): build plan

Branch `claude/whatsapp-dashboard`, from `main` at `ea82f78`. Written 23 Nov 2026 by the planning agent,
for the overnight build. Deenu was asleep, so every open question was settled here and listed under
"Risks and decisions". Change the plan by editing this file in the same commit as the code.

## Status

| Phase | What | Schema | Status |
|---|---|---|---|
| 1 | Inbox | v7 | not started |
| 2 | Templates | v8 | not started |
| 3 | Broadcasts | v9 | not started |
| 4 | Automations | v10 | not started |
| 5 | Contacts and WhatsApp overview | none (v10) | not started |

Each phase ships alone: its own schema version, pages, actions, tests and CLAUDE.md update. Nothing in a
phase may need a later phase to work. Menu items for Phase 3 to 5 appear only once their phase lands.

---

## Ground rules for every phase

These come from CLAUDE.md and the existing code. Reviewers check each one.

- **Schema.** New tables and columns go in a new `// ---- Version N ----` block at the end of
  `tg_db_migrate` in `api/razorpay/store.php`, written idempotently (`CREATE TABLE IF NOT EXISTS`,
  `information_schema` checks, `tg_db_alter`). Bump `TG_DB_VERSION` and the comment list above it. It must
  upgrade cleanly from v4, v5 and v6 databases (commits `ee60f55`, `555f762`, `ea82f78`).
- **Time.** Always `rp_now($config)` in PHP and `tg_ist()` into SQL. Never SQL `NOW()`, `CURDATE()` or a
  column default for anything a rule reads. This includes the dashboard (the 24-hour window, schedules, the
  daily cap), so the test clock moves everything. Webhook rows take Meta's own `timestamp`.
- **Dashboard.** One row of four KPIs, then `ui_card`s sized third/half/two-thirds/full in `ui_grid`.
  Pages only from `lib/ui.php` parts (new generic parts go there). Styles only in `api/admin/admin.css`;
  bump `ADMIN_CSS_VERSION`. No JavaScript. Every change is a POST handled in `lib/actions.php`: page token,
  `tg_audit`, `admin_flash`, `admin_done`. One line in `lib/sections.php` and one `views/<key>.php` per page.
  Plain English, short sentences, no exclamation marks, "you" for Deenu.
- **No message text in logs.** `tg_audit` details and `error_log` lines name the number (pretty form) and a
  length or template name, never the words of a message.
- **Escaping.** Message text from WhatsApp is untrusted. Always `h()`. CSV cells that start with
  `= + - @` get a leading `'`.
- **Sending switches, in this order, everywhere a WhatsApp message can leave:**
  `tg_wa_enabled` (config) → not `tg_wa_paused` (Settings) → `tg_wa_allowed` (test list) →
  not STOP (`tg_wa_opted_out` and `contacts.wa_optout_at`) → purpose rules (below). Checked when a message
  is planned and again at the moment it is sent (the worker already re-checks the test list and STOP).
- **Purpose rules** live in one function, `tg_wa_can_message()` (Phase 1, `api/razorpay/wa_store.php`):
  - `reply` (free text): their last message is under 24 hours old; and if they ever said STOP, they wrote
    again after it.
  - `template` (custom template to one person, a broadcast or a rule): consent (`contacts.consent_version`
    not null on a linked contact) **or** they messaged us first (any inbound row). Never STOP.
  - `marketing`: as `template`, plus not `wa_contacts.marketing_off_at` (Phase 3), plus quiet hours.
- **Secrets.** The token goes in the Authorization header only (`tg_wa_graph` already does this). Tests
  assert the token never appears in a URL, a page or a log.
- **php -l** on every changed PHP file. Run the existing suites before each commit (baselines below).

### Test baselines that must hold

whatsapp-v4 141/141, whatsapp-v5 141/141, run 37/37, coupon 74/74, coupons_builder 98/98, meta 30/30,
reminders 69/70, store 61/80 (old stale checks; never more failures). Runner:
`zsh .../scratchpad/runall-dash.sh <worktree> <label> <suites...>`.

Places the existing kit may need a deliberate update (name it in the commit message):
- Phase 1 adds a seventh system template (`reopen`). Any whatsapp.py check that counts the templates
  submitted or listed ("Submit to Meta" sends N) must allow it.
- Phase 1 changes the reply email (adds the inbox link). Checks that match its body exactly may need the line.

New suites go in `.../scratchpad/kit-dash/` as `dashboard_<phase>.py`, modelled on `whatsapp.py`. Add each
to `runall-dash.sh` next to `store|reminders`, so it gets DB port `3396`. Ports:

| Suite | PHP | fake Razorpay | fake SMTP | fake Meta |
|---|---|---|---|---|
| dashboard_inbox.py | 8880 | 8881 | 8882 | 8883 |
| dashboard_templates.py | 8884 | 8885 | 8886 | 8887 |
| dashboard_broadcasts.py | 8890 | 8891 | 8892 | 8893 |
| dashboard_automations.py | 8894 | 8895 | 8896 | 8897 |
| dashboard_contacts.py | 8888 | 8889 | 8898 | 8899 |

Every suite drops and rebuilds its tables, builds the start database with
`git show <ref>:api/razorpay/store.php` (v6 = `ea82f78` by default; Phase 1 also v4 and v5), logs in to the
dashboard, drives the real PHP over HTTP (webhook, worker CLI, pages, POST actions), and checks:
- every page it opens is 200 with no `Warning:`, `Notice:`, `Deprecated:` or `Fatal error`;
- every Graph request went to the fake (config `graph_base` is the fake; the fake records all requests;
  the expected count of sends matches exactly), carried `Bearer <token>` in the header and no token in the
  path or query;
- SMTP only to the fake; no `graph.facebook.com` in the PHP error log.

---

## Meta rules (what the build relies on)

From Meta's WhatsApp Cloud API documentation as known in Nov 2026. Values Meta changes often are read live
(number tier, quality) or kept as constants that are easy to edit.

**Customer service window.** Each message a person sends us opens or refreshes a 24-hour window. Inside it
we may send anything: free text, media, templates. Outside it only an approved template may be sent. Free
text outside the window fails with error **131047** ("re-engagement message"). Free entry points
(click-to-WhatsApp ads, Page buttons) give 72 hours; we do not track those and treat them as 24.

**Pricing (per message since 1 Jul 2025).** Each delivered template is charged by category. Free-form
(service) messages are free. A utility template sent while the window is open is free. Rates for India, tax
included, as Deenu gave them: **marketing about ₹0.86, utility about ₹0.15**. Kept as
`TG_WA_RATE_PAISE = ['MARKETING' => 86, 'UTILITY' => 15]` in `wa_store.php`. All spend on the dashboard is
labelled "estimate"; Meta's invoice is the truth.

**Template rules** (checked before submit, Phase 2):
- Name: lowercase letters, digits and underscores (Meta allows 512 characters; we cap at 60). A deleted name
  cannot be reused for about 30 days.
- Category: UTILITY (facts about something the person did or bought, no promotion) or MARKETING. Meta
  re-categorises utility templates that read as promotion, and sends `template_category_update`.
- Body: up to 1,024 characters. Variables `{{1}}`, `{{2}}` ... in order, none missing. A variable may not be
  the first or last thing in the body. Two variables may not sit next to each other with only spaces between.
  Too many variables for the length is refused (we warn below three words per variable).
- Every variable needs an example value when submitting.
- Variable values at send time: no newlines, no tabs, not more than 4 spaces in a row.
- Header (text): up to 60 characters, no newlines, no emoji or formatting. We allow plain text only, with no
  variable. Media headers need an upload handle (see decisions).
- Footer: up to 60 characters, no variables.
- Buttons: we allow up to 3 in total, at most 2 URL buttons, up to 3 quick replies. Button text up to 25
  characters and unique. URL buttons: https, up to 2,000 characters, an optional single `{{1}}` only at the
  very end, with a full example URL. Meta refuses links to WhatsApp itself (`wa.me`, `whatsapp.com`,
  `chat.whatsapp.com`, `api.whatsapp.com`) in buttons.
- Statuses: PENDING, APPROVED, REJECTED, PAUSED, DISABLED, IN_APPEAL, plus LIMIT_EXCEEDED, ARCHIVED,
  PENDING_DELETION, DELETED. Low-quality templates are PAUSED (first 3 hours, then 6), then DISABLED.
- Approved templates can be edited a limited number of times; we never edit, we duplicate under a new name.

**Rate limits.**
- Throughput: about 80 messages a second per number. Error **130429** means slow down.
- Pair rate: about one message every 6 seconds to the same person. Error **131056**. Retryable.
- Messaging limit: how many different people the business may start conversations with in a rolling
  24 hours (tiers such as 250, 1K, 10K, 100K, unlimited; Meta reworked tiers in 2025, so read the live
  value). Shown on the overview (Phase 5). Our daily cap defaults to the lowest tier.
- Template management calls are limited per hour per account. We call only on Deenu's button presses.

**Marketing frequency caps.** Meta limits how many marketing templates one person gets from all
businesses. A held-back message fails with **131049**: do not retry for 24 hours (we fail it at once,
"Meta held it back"). A person who turned off marketing from us in WhatsApp gives **131050**: we set
`wa_contacts.marketing_off_at` and leave them out of marketing from then on.

**Quality.** The number's quality rating (GREEN, YELLOW, RED) follows blocks and reports. Low quality cuts
the messaging limit and pauses templates. Small, wanted, STOP-friendly marketing protects the booking
messages, which matter most.

**Opt-in and STOP.** Meta requires the person's permission before the business messages them first, and
that we honour opt-out. STOP handling (`TG_WA_STOP_WORDS`) already exists and stays the final word.

Errors mapped to plain English: 131047 window closed; 131049 held back; 131050 marketing off; 131026 not
deliverable (no WhatsApp or an old app); 132000 wrong number of values; 132001 template missing; 132015
template paused; 132016 template disabled; 130429 and 131056 busy (retry).

---

## Phase 1: Inbox

**Goal.** Read every WhatsApp conversation in the dashboard and answer from the business number.

### Database: version 7

```
wa_contacts            -- one row per WhatsApp number we talk to (not `contacts`, which is keyed by email)
  id INT UNSIGNED AI PK
  phone VARCHAR(16) NOT NULL                    -- 919876543210, UNIQUE uniq_phone
  profile_name VARCHAR(80) NOT NULL DEFAULT ''  -- the name on their WhatsApp profile
  contact_id INT UNSIGNED NULL                  -- FK contacts(id) ON DELETE SET NULL; newest contact with this number
  last_in_at DATETIME NULL                      -- their last message: the 24-hour window runs from here
  last_out_at DATETIME NULL
  last_msg_at DATETIME NULL                     -- KEY idx_last
  unread SMALLINT UNSIGNED NOT NULL DEFAULT 0
  closed_at DATETIME NULL                       -- marked done; a new message from them reopens it
  created_at DATETIME NOT NULL                  -- from PHP
  KEY idx_contact (contact_id)

wa_messages            -- every message in or out, with its text for 30 days
  id INT UNSIGNED AI PK
  wa_contact_id INT UNSIGNED NOT NULL   -- FK wa_contacts(id) ON DELETE CASCADE
  direction CHAR(3) NOT NULL            -- in, out
  kind VARCHAR(12) NOT NULL             -- text, template, image, video, audio, document, sticker,
                                        -- location, contacts, button, interactive, stop, start, other
  body TEXT NULL                        -- the words (or "[a photo]" plus caption); NULL after 30 days
  body_gone TINYINT(1) NOT NULL DEFAULT 0
  template VARCHAR(64) NULL             -- Meta template name, for templates
  category VARCHAR(12) NULL             -- MARKETING or UTILITY, for templates
  billable TINYINT(1) NOT NULL DEFAULT 0  -- Meta charges for it (a template, and not utility inside the window)
  sent_by VARCHAR(10) NOT NULL          -- contact, admin, system, auto, broadcast, test
  wa_id VARCHAR(128) NULL               -- Meta message id, UNIQUE uniq_wa
  outbox_id INT UNSIGNED NULL           -- KEY idx_outbox (no FK: outbox rows of deleted bookings vanish)
  status VARCHAR(10) NOT NULL           -- received, sent, delivered, read, failed
  error VARCHAR(255) NULL
  created_at DATETIME NOT NULL          -- KEY idx_created
  delivered_at DATETIME NULL
  read_at DATETIME NULL
  KEY idx_thread (wa_contact_id, id)

wa_tags                -- here now so Phase 3 and 4 can filter on tags; Phase 5 adds bulk tagging
  wa_contact_id INT UNSIGNED NOT NULL   -- FK wa_contacts(id) ON DELETE CASCADE
  tag VARCHAR(30) NOT NULL              -- lowercase a-z, 0-9 and hyphens
  added_at DATETIME NOT NULL
  added_by VARCHAR(10) NOT NULL         -- admin, broadcast
  PRIMARY KEY (wa_contact_id, tag), KEY idx_tag (tag, added_at)

wa_quick_replies
  id INT UNSIGNED AI PK, title VARCHAR(40) NOT NULL, body VARCHAR(1000) NOT NULL,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
```

Upgrade backfill: `INSERT IGNORE INTO wa_contacts (phone, created_at)` for each distinct `wa_inbound.phone`,
so old STOPs show up. No message rows (text was never kept before).

**Retention.** `tg_wa_retention(PDO $pdo, int $now)` runs at the end of every worker run:
`UPDATE wa_messages SET body = NULL, body_gone = 1 WHERE body IS NOT NULL AND created_at < ? LIMIT 2000`
(30 days). Nightly in `cron/reconcile.php`: delete `wa_contacts` rows (cascading to messages and tags) whose
`contact_id` is NULL, whose `last_msg_at` (or `created_at`) is over 90 days old, and that have no tags. STOP
rows in `wa_inbound` are never deleted: they are the record that we must not message that number.

### PHP

New `api/razorpay/wa_store.php` (required by `whatsapp.php`, so the webhook, worker and dashboard all get it):
- `tg_wa_contact_id(PDO, string $phone, int $now, string $profile = ''): int` upserts `wa_contacts`, links the
  newest `contacts` row with that number (`tg_wa_contacts`), returns the id.
- `tg_wa_log_in(PDO, int $waContactId, array $m, string $kind, string $text): int` stores one inbound
  message, sets `last_in_at`, `last_msg_at`, `unread + 1` (not for `skip` kinds), clears `closed_at`.
- `tg_wa_log_out(PDO, string $phone, array $msg, int $now): int` stores one outbound message
  (kind, body, template, category, billable, sent_by, wa_id, outbox_id, status, error).
- `tg_wa_window_open(?string $lastIn, int $now): bool` and `tg_wa_window_left(...)`: seconds left.
- `tg_wa_can_message(PDO, array $config, string $phone, string $purpose, int $now): ?string`: null when
  allowed, or the plain-English reason not (the purpose rules above).
- `tg_wa_send_text_result(array $config, string $to, string $text): array`, same shape as `tg_wa_send`
  (ok, id, error, retry). `tg_wa_send_text()` stays as the bool wrapper used for STOP/START.
- `tg_wa_mark_read(array $config, string $waId): void` (POST messages `status: read`), best effort.
- `tg_wa_retention(PDO, int $now): int`.
- Constants `TG_WA_RATE_PAISE`, `TG_WA_KEEP_TEXT_DAYS = 30`, `TG_WA_TEXT_MAX = 4096`.

`api/razorpay/whatsapp.php`:
- New system template `reopen` in `tg_wa_templates`: name `gurukul_follow_up`, UTILITY, label "Follow-up
  after 24 hours", when "From the inbox, after the 24-hour window closes", body
  `"Hi {{1}}, this is Deenu from The Gurukul of AI, following up on your message. Reply here and we can carry on."`,
  no buttons, example `['Priya']`. Values: first name only (`tg_wa_values` case `reopen`). It is the one
  template the inbox can always offer outside the window.
- Every template definition gets `'category'` (default UTILITY), so `wa_messages.category` is filled.
- `tg_wa_inbound()` also stores the message with `tg_wa_log_in()` (text, button title, interactive title,
  or the label such as "[a photo]" plus any `caption`), and returns the `wa_contact_id` too.
- `tg_wa_status()` also updates `wa_messages` by `wa_id` (delivered_at, read_at, status, error), with the same
  "a late failed never undoes read" rule.
- `tg_wa_reply_email()`: adds "Answer in the dashboard: <site>/api/admin/?view=inbox&c=<wa_contact id>" above
  the wa.me line. The URL carries our row id, never the phone number.

`api/razorpay/outbox.php`: after a WhatsApp send (success or final failure), `tg_wa_log_out()` with the
rendered text (`tg_wa_render`), `sent_by = 'system'`, `billable` = not (UTILITY and window open). At the end
of `tg_outbox_run`, `tg_wa_retention()`.

`api/whatsapp/webhook.php`: replace "Message text is never stored" in the header comment with the 30-day
rule. STOP/START answers go through `tg_wa_send_text_result` and are logged as outbound (`sent_by = 'auto'`).
Reply emails follow the new setting (below), which is the existing `forward_replies` key.

`api/admin/lib/actions.php` `admin_wa_test()`: also `tg_wa_log_out(..., sent_by 'test')`.

New `api/admin/lib/inbox.php` (dashboard only): `admin_inbox_unread(PDO): int` (conversations with unread),
`admin_inbox_list(PDO, array $filter, int $now): array`, `admin_inbox_thread(PDO, int $id): ?array`,
`admin_inbox_person(PDO, array $config, array $wc, int $now): string` (the contact panel html),
`admin_inbox_action(PDO, array $config, string $action): void`.

`api/admin/lib/ui.php`, new generic parts:
- `ui_chat(array $messages, int $now): string` bubbles in and out, time, ticks (sent, delivered, read,
  failed with reason), "Text removed after 30 days" when `body_gone`.
- `ui_textarea(string $label, string $name, string $value, string $attrs = '', string $hint = '')`.
- `ui_select(string $label, string $name, array $options, string $current, string $hint = '')`.
- `ui_confirm(string $title, string $html, string $form, string $cancelHref)`: the ask-first card the People
  page's resend uses today (refactor that one onto it).

`api/admin/index.php`:
- Menu badge for `inbox` from `admin_inbox_unread()`, tone `info` (new `.badge-info` in admin.css).
- A page may return `'refresh' => 30`; `admin_document()` then adds
  `<meta http-equiv="refresh" content="30;url=<this page>&auto=1">`. A request with `auto=1` does **not**
  update `$_SESSION['seen']`, so the 30-minute idle sign-out still works with the inbox left open.

`lib/sections.php`: new group **WhatsApp**, after Messages:
`'inbox' => ['label' => 'Inbox', 'icon' => 'whatsapp', 'group' => 'WhatsApp', 'range' => false, 'intro' => 'Every WhatsApp conversation. Answer from the business number.']`.

Settings: add `whatsapp.forward_replies` to `RP_DASHBOARD_SETTINGS` (lib.php) and `admin_setting_defs`
("Email me each WhatsApp reply", on/off, from the config until changed, default on). Shown on Settings in the
WhatsApp card and as a card on the inbox. Add it to the bool list in `setting_save`.

### Dashboard page `views/inbox.php`

List (no `c`): auto-refresh every 30 seconds.
- KPIs: **Unread** (conversations), **Waiting for you** (their message is the last one and under 24 h old),
  **Windows open** (their last message under 24 h old), **Messages this week** (in and out).
- Card "Conversations" (full, flush): chips All / Unread / Waiting / Buyers / Not buyers / Done, search by name
  or number. Rows: name (contact name, else profile name, else number), pill Buyer / Lead / WhatsApp only, last
  message preview (first 80 characters, or "Text removed", or "[a photo]"), time ago, unread count, window pill
  ("Open, 5 h left" or "Closed"). Newest first, 100 rows.
- Card "Quick replies" (half): list with Edit and Delete; a form to add one (title, text).
- Card "Reply emails" (half): the forward_replies switch (`setting_save`).

Conversation (`?view=inbox&c=ID`): no auto-refresh (it would wipe a half-typed reply); a "Check for new
messages" link instead. Opening it sets `unread = 0` (see decisions). Back link to the list.
- KPIs: **Window** (open with time left, or closed X ago), **Their messages**, **Our messages**, **Last read**.
- Card (two-thirds) "Conversation": `ui_chat` of the last 200 messages, oldest first. Under it:
  - window open and `tg_wa_can_message(reply)` is null: reply form (`ui_textarea`, up to 4,096) with
    "Send"; quick replies as small one-click "Send" forms plus "Edit first" links (`&qr=ID` prefills the box).
  - window closed: "The 24-hour window closed X ago. Only an approved template can restart it." and a
    template form: `ui_select` of approved templates that can be filled for this person (Phase 1: `reopen`;
    Phase 2 adds approved custom templates), a preview bubble, "Send template".
  - not allowed (paused, off, not on the test list, STOP): the reason, no form.
- Card (third) "Who this is": linked contact name and email (link to People), number with wa.me link, every
  booking (status, session, add-on and whether sent, coupon used, checkout coupon sent or used), consent
  version, STOP state with "Stop WhatsApp for this number" / "Turn WhatsApp back on" (ask first), tags with
  add and remove, "Mark done" / "Reopen".

### Actions (in `lib/actions.php`, handled by `admin_inbox_action`)

| Action | Fields | Checks | Effect |
|---|---|---|---|
| `wa_reply` | c, text | token; conversation exists; text 1–4,096 after trim; `tg_wa_can_message(reply)` is null (enabled, not paused, test list, window, STOP rule) | `tg_wa_send_text_result`; log out (`sent_by admin`, billable 0); mark their last message read at Meta; audit `whatsapp_reply` "+91 … (c N), 132 chars"; on 131047 flash "The 24-hour window has closed. Send a template instead." |
| `wa_reply_quick` | c, qr | as above; quick reply exists | same, with the quick reply's text |
| `wa_send_template` | c, template | token; template approved (Meta cache) and fillable; `tg_wa_can_message(template)` is null; daily cap once Phase 3 adds it | `tg_wa_send`; an outbox row like `admin_wa_test` (status sent or failed, `alerted_at` set on failure) so it shows in the send log; log out; audit `whatsapp_template_one` |
| `wa_quick_save` | id?, title, body | title 1–40, body 1–1,000 | insert or update; audit |
| `wa_quick_delete` | id | exists | delete; audit |
| `wa_thread_done` / `wa_thread_open` | c | exists | set or clear `closed_at`; audit |
| `wa_optout` | c, confirm=1 | exists | insert `wa_inbound` kind `stop` with `wa_id = 'admin:<c>:<time>'`, set `contacts.wa_optout_at`, cancel queued WhatsApp rows (same SQL as STOP); audit `whatsapp_stop_by_admin` |
| `wa_optin` | c, asked=1 | box "They asked to get messages again" ticked | insert kind `start` (`admin:` id), clear opt-out; audit |
| `wa_tag_add` / `wa_tag_remove` | c, tag | tag matches `^[a-z0-9][a-z0-9-]{0,29}$` after lowercasing | insert ignore or delete; audit |

### Meta API used, and what the fake must answer

- `POST /{pnid}/messages`, `type: text`, `text: {body, preview_url: false}` → `{messages: [{id}]}`.
  Modes: `closed` → HTTP 400 `{error: {code: 131047, message: "Re-engagement message", error_data: {details: "...24 hours..."}}}`;
  `down` → 500, code 2.
- `POST /{pnid}/messages`, `status: read, message_id` → `{success: true}` (not counted as a send).
- `POST /{pnid}/messages`, `type: template`, name `gurukul_follow_up` → an id, as today.
- The fake keeps every request; `sends()` and `texts()` helpers as in whatsapp.py.

### Tests: `dashboard_inbox.py`

1. `upgrade from v4, v5 and v6`: v7 tables exist, old rows intact, STOP numbers backfilled into `wa_contacts`.
2. `inbound text makes a conversation`: conversation, text stored, unread 1, menu badge 1, page shows it.
3. `repeat from Meta ignored`: same `wamid` twice, one row, unread still 1.
4. `unknown number`: no contact, shows "WhatsApp only"; reply email says it may be a portfolio enquiry.
5. `photo with caption`: shows "[a photo]" and the caption.
6. `script in a message is escaped` in the list and the conversation.
7. `opening clears unread; auto-refresh does not keep the session alive` (an `auto=1` request after 31 idle
   minutes lands on sign-in).
8. `reply inside the window`: the fake gets the exact text to the right number; out row sent; delivered and
   read webhooks tick it; audit has no message text; their message marked read at Meta.
9. `reply after 24 hours refused`: clock +25 h, no reply form, a forced POST refused, no Meta call.
10. `Meta says window closed`: 131047 → failed row, plain flash.
11. `paused / switched off / not on the test list`: reply refused, no Meta call, reason shown.
12. `template outside the window`: `reopen` approved → sent with the first name; not approved → refused.
13. `quick replies`: add, edit, "Edit first" prefill, one-click send, delete.
14. `manual STOP and START`: STOP hides the template form; START needs the box.
15. `STOP from WhatsApp shows in the conversation with the automatic answer`.
16. `reply emails switch`: off → no email for a reply; on → email with the inbox link and no phone in the URL.
17. `reminders appear in the conversation`: buy, run the worker, the confirmation bubble shows the rendered text.
18. `text removed after 30 days`: clock +31 d, worker run, body NULL, counts unchanged, page says removed.
19. `old numbers forgotten`: reconcile deletes a 91-day-old WhatsApp-only conversation, keeps its STOP row.
20. `tags`: add, refuse a bad tag, remove.
21. `no real hosts, no token leaks, all pages clean`.

### Also in Phase 1

- `privacy-policy.html`, the WhatsApp text. Add: "When you message our WhatsApp number, or we message you, we
  keep the text of those messages for 30 days so we can answer you, then delete it. We keep a record that a
  message was sent, delivered or read, and any request to stop. Replies may also be forwarded to our email."
  No CSS change, so no `?v=` bump.
- CLAUDE.md "Masterclass WhatsApp": replace "Message text is never stored; `wa_inbound` keeps only id, number
  and kind" with the 30-day rule; add an "Inbox" paragraph; schema version 7.
- `docs/whatsapp-setup.md`: the inbox replaces "reply from your own WhatsApp".
- Health (`admin_wa_checks`): warn "N people are waiting for an answer" when a conversation's last message is
  theirs and over 12 hours old (the window closes at 24).

### Acceptance

Inbox suite all green; baselines hold; php -l clean; pages clean; CLAUDE.md, privacy policy and this plan's
status updated; commit "add whatsapp inbox with 24-hour replies and quick replies".

---

## Phase 2: Templates

**Goal.** Write, check, submit and track your own WhatsApp templates from the dashboard.

### Database: version 8

```
wa_templates
  id INT UNSIGNED AI PK
  name VARCHAR(60) CHARACTER SET ascii NOT NULL   -- Meta name; UNIQUE uniq_name_lang (name, language)
  language VARCHAR(10) NOT NULL DEFAULT 'en'
  category VARCHAR(12) NOT NULL                    -- what we asked for: UTILITY or MARKETING
  meta_category VARCHAR(12) NULL                   -- what Meta decided, if different
  header_text VARCHAR(60) NULL
  body TEXT NOT NULL
  footer VARCHAR(60) NULL
  buttons TEXT NOT NULL                            -- JSON list: {type: URL|QUICK_REPLY, text, url?, example?}
  examples TEXT NOT NULL                           -- JSON list, one per body variable
  fields TEXT NOT NULL                             -- JSON list, the default contact field per variable
  status VARCHAR(20) NOT NULL DEFAULT 'DRAFT'      -- DRAFT, then Meta's status
  meta_id VARCHAR(40) NULL
  reason VARCHAR(255) NULL                         -- Meta's rejection reason
  quality VARCHAR(10) NULL                         -- GREEN, YELLOW, RED, UNKNOWN
  copied_from INT UNSIGNED NULL
  submitted_at DATETIME NULL, status_at DATETIME NULL, deleted_at DATETIME NULL
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
  KEY idx_status (status)
```

No retention job: templates hold no personal data (examples are Deenu's samples).

### PHP

New `api/razorpay/wa_custom.php` (required by `whatsapp.php`):
- `TG_WA_FIELDS`: what a variable can be filled with, each with a label, a filler and a fallback:
  `first_name` (fallback "there"), `full_name`, `session` (their latest paid session, "Sunday 30 Nov, 3 PM IST"),
  `next_session` (the next Sunday, from `rp_next_session`), `event` (event name), `fixed` (text typed when sending).
- `tg_wat_vars(string $body): array` the variable numbers in order.
- `tg_wat_check(array $config, PDO $pdo, array $t): array` → `['errors' => [...], 'warnings' => [...]]` in plain
  English. Errors block submit; warnings do not. Rules: every template rule under "Meta rules", plus: name not a
  system template name, not used by another row, not deleted in the last 30 days; a utility body with promotion
  words ("offer", "discount", "coupon", "% off", "sale", "free", "limited time", "₹... off") → warning "Meta will
  probably move this to marketing".
- `tg_wat_request(array $config, array $t): array`, Meta's body: `name, language, category,
  components: [HEADER {format TEXT, text}?, BODY {text, example {body_text: [[...]]}}, FOOTER {text}?,
  BUTTONS {buttons: [{type URL, text, url, example: [url]} | {type QUICK_REPLY, text}]}?]`.
- `tg_wat_submit(array $config, PDO $pdo, int $id): array` (ok, error): POST, store meta_id and status.
- `tg_wat_sync(array $config, PDO $pdo): ?int` reads all templates from Meta (paging, up to 5 pages) and updates
  status, meta_category, reason and quality by name and language. Returns rows changed, or null.
- `tg_wat_delete(array $config, PDO $pdo, int $id): array`: DELETE at Meta (skipped for drafts), set
  `deleted_at`, status DELETED.
- `tg_wat_values(array $config, PDO $pdo, array $t, array $fields, array $person, array $fixed): array` fills
  the body values and the URL button value, cleaned (no newline, tab or 4+ spaces; 1,024 max).
- `tg_wat_render(array $t, array $values): array` for previews and the stored text (header, body, footer,
  buttons), in the shape `ui_wa_bubble` takes.
- `tg_wat_send(array $config, array $t, string $to, array $values): array` like `tg_wa_send`: body parameters,
  and `button` components (`sub_type url` with the value) at the right `index`.

`tg_wa_template_states()` also asks for `id,quality_score` and follows `paging.cursors.after`.

Webhook: `message_template_status_update` also updates the `wa_templates` row (status, reason, status_at);
`template_category_update` sets `meta_category`; new `message_template_quality_update` sets `quality` (and
emails Deenu when it drops to RED). Template news emails cover custom templates too.

Outbox: rows whose `template` starts with `c:` (for example `c:12`) are custom templates; the worker loads the
row (it must be APPROVED, else a permanent failure "That template is not approved") and sends with
`tg_wat_send`. In Phase 2 only inbox single sends use this; Phase 3 adds the values column for queued rows.

New `api/admin/lib/wa_templates.php` (dashboard parts) and `views/wa_templates.php`. `sections.php`:
`'wa_templates' => ['label' => 'Templates', 'icon' => 'list', 'group' => 'WhatsApp', 'range' => false, 'intro' => 'Write WhatsApp templates, send them to Meta, and see which are approved.']`.
`ui_wa_bubble` learns an optional header line.

### Dashboard page

The page reads Meta through the existing 10-minute cache (`admin_cached`); "Refresh from Meta" forces a sync.

- KPIs: **Approved**, **In review**, **Rejected or paused** (tone bad above 0), **Drafts**.
- Card "Your templates" (full, flush): name, category (pill; a second warn pill when Meta changed it), status
  pill with reason, quality, sent in the last 30 days (from `wa_messages`), and actions Edit (drafts), Submit
  (drafts that pass), Duplicate, Delete (asks first: `?delete=ID` → `ui_confirm`).
- Card "New template" / "Edit draft" (two-thirds, `?edit=ID`): name, category (`ui_choice`), language
  (`ui_select`, the config language only for now), header, body (`ui_textarea`), footer (prefilled "Reply STOP
  to stop these messages" for marketing), three button rows (type none / URL / quick reply, text, URL, URL
  example). After "Save draft" the page shows one example field and one field select per variable found in the
  saved body (no JavaScript, so the count comes from the saved draft).
- Card "Preview and checks" (third): the bubble with the examples filled in, then `ui_checks` listing each
  error (bad) and warning (warn), or "Ready to submit" (ok).
- Card "Built-in templates" (full): the seven system templates, read only, as on the WhatsApp tab, with
  "These are part of the booking flow. Change them in code."
- Inbox (Phase 1 card): the template select now also lists approved custom templates whose fields can all be
  filled for this person, with a text box for each `fixed` field.

### Actions

| Action | Fields | Checks | Effect |
|---|---|---|---|
| `wat_save` | id?, name, category, language, header, body, footer, buttons[0..2][type,text,url,example], examples[], fields[] | token; a draft (or new); lengths capped before saving | save; run checks; flash "Saved. N things to fix before submitting." or "Saved. Ready to submit."; audit `wa_template_saved` |
| `wat_submit` | id | a draft; `tg_wat_check` has no errors; WhatsApp ready (sending need not be on: submitting messages no one) | `tg_wat_submit`; forget the cache; audit with Meta's answer; on refusal show the reason and keep the draft |
| `wat_duplicate` | id | exists | new draft `<name>_v2` (next free suffix), `copied_from`; go to edit; audit |
| `wat_delete` | id, confirm=1 | exists; not used by a scheduled broadcast or an enabled rule (Phase 3 and 4 add these checks) | `tg_wat_delete`; audit |
| `wat_sync` | — | WhatsApp ready | `tg_wat_sync`; forget the cache; flash "N templates updated." |

### Meta API used, and what the fake must answer

- `POST /{waba}/message_templates` → `{id, status: PENDING, category}`. The fake checks Meta's core rules (name
  format; variable at start or end; examples count; WhatsApp link in a button) and answers HTTP 400
  `{error: {code: 100, message: "Invalid parameter", error_user_title, error_user_msg}}`, so tests prove our checks
  catch them first and that a refusal is shown if one slips through.
- `GET /{waba}/message_templates?fields=name,status,category,language,rejected_reason,id,quality_score&limit=200`
  → `{data: [...], paging: {cursors: {after}, next?}}`. Two pages when more than 3 templates exist, to test paging.
- `DELETE /{waba}/message_templates?name=NAME&hsm_id=ID` → `{success: true}`. The fake needs `do_DELETE`.
- Tests flip a template to APPROVED or REJECTED in the fake and send the matching webhook event.

### Tests: `dashboard_templates.py`

1. `upgrade v6 → v8` and `v7 → v8`: table added, inbox data intact.
2. `draft saved with problems listed`: variable at the start, variable at the end, two variables together, a
   gap in the numbers, a missing example, a footer with a variable, button text over 25, a `wa.me` button, a URL
   variable not at the end, a header with a newline, a name with capitals, a system template's name, a body over
   1,024. Each gets its own message; submit refused; no Meta call.
3. `promotion words in a utility body warn but do not block`.
4. `good draft submits`: Meta gets the exact request body (components, examples, buttons); status PENDING.
5. `Meta refuses`: reason shown, still a draft.
6. `approved by webhook`; `rejected webhook` stores the reason and emails Deenu.
7. `category changed by Meta`: second pill, email.
8. `quality update`: stored; RED emails Deenu.
9. `sync with paging`: statuses from both pages.
10. `duplicate to edit`: `_v2` draft, original untouched.
11. `delete asks first, then calls Meta`; deleting a draft makes no Meta call; the name is blocked for 30 days.
12. `inbox sends an approved custom template` with fields filled from the contact (first name, session) and a
    fixed value; stored text matches the render; outbox row `c:<id>`.
13. `worker refuses a custom template that is no longer approved` (permanent failure, alert).
14. `system templates locked`: no edit or delete controls; a forced POST refused.
15. `no real hosts, no token leaks, all pages clean`.

### Acceptance

Templates suite green, inbox suite still green, baselines hold, CLAUDE.md "WhatsApp templates" paragraph and
schema 8, commit "add whatsapp template builder with pre-submit checks".

---

## Phase 3: Broadcasts

**Goal.** Send one approved template to a chosen group, safely, and see what happened.

### Database: version 9

```
outbox  ADD params TEXT NULL                 -- JSON values for a custom template; NULL after 30 days
        ADD source VARCHAR(12) NULL          -- broadcast, automation, inbox; NULL for booking messages
        ADD source_id INT UNSIGNED NULL      -- the broadcast or rule id
        ADD KEY idx_source (source, source_id)
wa_contacts ADD marketing_off_at DATETIME NULL   -- Meta said 131050: they turned off our marketing

wa_broadcasts
  id INT UNSIGNED AI PK
  name VARCHAR(80) NOT NULL
  template_id INT UNSIGNED NOT NULL          -- wa_templates.id
  fields TEXT NOT NULL                       -- JSON: field per variable, and fixed texts
  audience TEXT NOT NULL                     -- JSON filters (below)
  parent_id INT UNSIGNED NULL                -- "resend to people who did not read" of this broadcast
  status VARCHAR(12) NOT NULL DEFAULT 'draft'  -- draft, scheduled, sending, done, cancelled
  test_hash CHAR(16) NULL                    -- template + fields + audience at the last good test
  test_at DATETIME NULL, test_to VARCHAR(16) NULL
  send_at DATETIME NULL                      -- when it starts (IST)
  total INT UNSIGNED NOT NULL DEFAULT 0      -- people queued at confirmation
  est_paise INT UNSIGNED NOT NULL DEFAULT 0
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
  confirmed_at, finished_at, cancelled_at DATETIME NULL
  KEY idx_status (status, send_at)
```

Retention: `tg_wa_retention` also clears `outbox.params` older than 30 days (they hold names).

### Settings (`RP_DASHBOARD_SETTINGS` and `admin_setting_defs`, on Settings > WhatsApp)

- `whatsapp.daily_cap`: most business-started custom messages per IST day (broadcasts, automations, inbox
  templates). Whole number 0–10,000, default **250**. Booking confirmations, reminders, the checkout coupon and
  free replies do not count and are never held back by it.
- `whatsapp.my_number`: your own WhatsApp number, for test sends after the test list is emptied. Checked with
  `tg_wa_number`.

Quiet hours are fixed, not a setting: **no marketing template leaves between 9 PM and 9 AM IST.**
`TG_WA_QUIET_FROM = 21`, `TG_WA_QUIET_TO = 9`.

### Audience

`audience` JSON: `{base, session, tag_in, tag_out, days}` with one base:
- `all`: everyone reachable;
- `buyers`: paid bookings (optionally one `session`);
- `addon`: paid with the add-on (optionally one session);
- `no_addon`: paid without the add-on (optionally one session);
- `unpaid`: checkouts never paid since, within `days` (7, 30 or 90);
- `messaged`: people who wrote to us, within `days` (1, 7, 30, or ever);
- `not_read_of`: used only by "resend" (sent by the parent broadcast, not read, not failed).
Then `tag_in` (has this tag) and `tag_out` (does not).

Always applied, in `tg_wa_audience(PDO $pdo, array $config, array $audience, string $category, int $now): array`
(new `api/razorpay/wa_bulk.php`), returning `['people' => [...], 'skipped' => [reason => count]]`: a valid
number (`tg_wa_number`), one row per number, consent or messaged us, not STOP, on the test list while it has
numbers, and for marketing not `marketing_off_at`. Skipped reasons are counted and shown.

### Worker changes (`tg_outbox_run`)

- Step 5 sends booking messages first (rows with `source IS NULL`, existing limit), then **bulk rows**
  (`source IN ('broadcast', 'automation', 'inbox')`) up to `outbox.wa_bulk_per_run` (config, default 200).
- Before each bulk row: daily cap not reached (today's sent bulk rows), quiet hours for marketing (the row
  waits, it does not fail), template still APPROVED. A row held by the cap or quiet hours stays queued; its
  `expires_at` (send time + 48 h) ends it as "Not sent: daily limit or quiet hours", with no alert email (one
  line in the 9 AM summary instead).
- Errors: 131049 → failed "Meta held it back: this person got many marketing messages recently" (no retry);
  131050 → failed, and `marketing_off_at` set; 131026 → failed "Not on WhatsApp".
- Broadcast status: `scheduled` → `sending` when its first row goes → `done` when none are left queued.

### Dashboard page `views/broadcasts.php`

`sections.php`: `'broadcasts' => ['label' => 'Broadcasts', 'icon' => 'send', 'group' => 'WhatsApp', 'range' => false, 'intro' => 'Send one template to a group, with a test first.']`.

List: KPIs **Sent this month**, **Read rate**, **Replies**, **Estimated spend this month**. Card "Broadcasts"
(full): name, template, status, when, people, sent / read / replied / failed, link to open. A "New broadcast"
button makes a draft.

One broadcast (`?view=broadcasts&b=ID`), four numbered steps on one page:
1. **Message**: pick an approved template (`ui_select`); map each variable to a field or fixed text.
2. **Who**: base, session, tags, days. Saving shows the count, skipped reasons with counts, the first 10 people
   with their filled-in message (real names), the estimated cost (count × rate for the category, with "free
   inside an open window" for utility), and the daily cap left today, warning when the count is above it
   ("It will take N days at your daily limit").
3. **Test**: "Send a test" to a test number or your number, filled with the first person's real values.
   Required, and needed again after any change to steps 1–2 (`test_hash`).
4. **Send**: "Send now" or a date and time (IST), and a box "Type the number of people to confirm: 143". For
   marketing, a time inside quiet hours is refused, and "Send now" during quiet hours becomes 9 AM with a note.
   After sending: results (sent, delivered, read, replied, failed, each with %), failure reasons grouped, the
   people table, "Cancel the rest" while sending, and after 12 hours "Resend to people who did not read".

"Replied" means an inbound message from that number within 72 hours after ours was sent.

### Actions

| Action | Fields | Checks | Effect |
|---|---|---|---|
| `bc_save` | b?, name, template_id, fields[], fixed[], base, session, tag_in, tag_out, days | a draft; template approved; fields valid for the template | save; clears `test_hash` if anything changed; audit |
| `bc_test` | b, to | a draft; `to` is a test number or `my_number`; WhatsApp enabled and not paused; audience not empty | send with the first person's values; outbox row `source NULL`, dedupe `wa:bctest:<id>:<random>`, `alerted_at` set; on success store `test_hash`, `test_at`, `test_to`; audit |
| `bc_confirm` | b, count, when (now or a datetime) | a draft; `test_hash` matches the draft now; `count` equals a fresh recount exactly (else flash the new count, nothing sent); time valid (≥ now, ≤ 30 days, outside quiet hours for marketing); WhatsApp enabled, not paused; template approved | snapshot the audience into outbox rows (dedupe `wa:bc<id>:<phone>`, `params`, `source broadcast`, due `send_at`, expires `send_at + 48 h`); status `scheduled`; audit `broadcast_confirmed` "name: 143 people, ₹123 est." |
| `bc_cancel` | b, confirm=1 | scheduled or sending | queued rows → cancelled "Broadcast cancelled"; status `cancelled`; audit |
| `bc_resend` | b | done, finished ≥ 12 h ago | new draft with `parent_id`, base `not_read_of`, same template and fields; audit |
| `bc_delete` | b, confirm=1 | drafts only | delete; audit |
| `setting_save` | values[whatsapp.daily_cap], values[whatsapp.my_number] | existing settings checks | existing |

Template delete (Phase 2) now refuses a template used by a scheduled or sending broadcast.

### Meta API used, and what the fake must answer

Template sends to `POST /{pnid}/messages`, as Phase 2. Fake modes per number (`fail_to`): `freq` → 131049,
`mkt_off` → 131050, `nowa` → 131026, `rate` → 130429. Webhook statuses as before.

### Tests: `dashboard_broadcasts.py`

1. `upgrade v6 → v9` and `v8 → v9`.
2. `audience counts`: each base, the session filter, tag in and out, days; skipped reasons (no consent, STOP,
   test list, bad number, duplicate number, marketing off) counted right; people who messaged us count
   without consent.
3. `preview shows real names and the cost estimate` (marketing, 143 × ₹0.86).
4. `cannot confirm without a test`; `a change after the test needs a new test`.
5. `test goes only to a test number or my number`.
6. `typed count must match`; `count changed since the preview` → refused with the new count.
7. `send now`: rows queued; the worker sends in batches of `wa_bulk_per_run`; booking reminders go first in
   the same run.
8. `scheduled`: nothing before the time; sent after.
9. `quiet hours`: a marketing broadcast at 10 PM refused; "send now" at 10 PM moves to 9 AM; utility not held.
10. `daily cap`: cap 5, 8 people → 5 today, 3 tomorrow; booking reminders not held back.
11. `pause stops a broadcast midway; the test list still limits; STOP between confirm and send drops that person`.
12. `results`: delivered and read webhooks, a reply within 72 h, failures (131049; 131050 sets marketing off;
    131026) with reasons.
13. `cancel the rest`.
14. `resend to non-readers`: only sent-not-read people; needs its own test and confirmation.
15. `params cleared after 30 days`.
16. `no real hosts, no token leaks, all pages clean`.

### Also in Phase 3

**Privacy policy, flagged for Deenu.** Line 139 today promises news and offers only to people who signed up or
ticked the box. Deenu decided (no tick box) that broadcasts may go to anyone who agreed to WhatsApp booking
updates or who messaged us. Phase 3 changes line 139 to: "Send you news about future sessions, courses and
offers on WhatsApp if you agreed to WhatsApp booking updates or messaged us, and by email only if you signed up
for it. To stop, reply STOP to any of our WhatsApp messages or email us. You can stop anytime." The commit
message and the morning summary must call this out, so Deenu reads it before the first marketing broadcast.

CLAUDE.md: a "Broadcasts" paragraph (cap, quiet hours, test first, typed confirmation), schema 9.

### Acceptance

Broadcast suite green, earlier phase suites green, baselines hold, commit
"add whatsapp broadcasts with test send, typed confirm and daily cap".

---

## Phase 4: Automations

**Goal.** Send the right template on its own when something happens, with every rule switchable.

### Database: version 10

```
wa_rules
  id INT UNSIGNED AI PK
  name VARCHAR(80) NOT NULL
  trigger_type VARCHAR(16) NOT NULL   -- paid, addon_paid, unpaid, before_session, after_session,
                                      -- joined, not_joined, tag_added, keyword
  trigger_value VARCHAR(120) NOT NULL DEFAULT ''  -- minutes (unpaid), hours (before/after), tag, or keywords
  delay_minutes INT UNSIGNED NOT NULL DEFAULT 0
  action VARCHAR(8) NOT NULL          -- template, or text (keyword rules only)
  template_id INT UNSIGNED NULL
  fields TEXT NULL                    -- JSON, as broadcasts
  reply_text VARCHAR(1000) NULL       -- keyword auto-reply, free text
  audience TEXT NULL                  -- JSON: optional tag_in, tag_out, addon yes/no
  enabled TINYINT(1) NOT NULL DEFAULT 0
  enabled_at DATETIME NULL            -- only things that happen after this count: switching on never reaches back
  preset VARCHAR(20) NULL             -- ready-made rule key, UNIQUE uniq_preset
  sent INT UNSIGNED NOT NULL DEFAULT 0
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
```

Seeded (INSERT IGNORE by `preset`, `enabled = 0`, no template yet):
- `recording_offer`, "Recording offer after class": `after_session`, 2 hours after the class ends, paid buyers
  without the add-on, a MARKETING template.
- `feedback`, "Feedback request": `joined`, 24 hours after the class ends, a UTILITY template (Meta may move it).
Each preset has "Make its template", which creates a suggested draft in Templates with the URL button left
empty, so the checks fail until Deenu adds his payment page or form link. Nothing is invented.

### Triggers (worker step 2c, `tg_rules_plan()` in `wa_bulk.php`)

The moment each trigger happens, plus `delay_minutes`, is the send time. A subject counts only if that moment
is after `enabled_at` and within the last 24 hours. One message per rule per subject, by the dedupe key.

| Trigger | Subject | Moment | Dedupe key |
|---|---|---|---|
| paid | paid booking | `paid_at` | `wa:r<id>:b<booking>` |
| addon_paid | paid booking with the add-on | `paid_at` | same |
| unpaid | latest unpaid try, not paid since (`tg_nudge_paid_since`) | `created_at + X min` | `wa:r<id>:c<contact>:<session>` |
| before_session | paid booking | `session_at − X h` | `wa:r<id>:b<booking>` |
| after_session | paid booking | `session_at + 3 h + X h` (after the class ends) | same |
| joined | paid booking with `first_join_at` | class end | same |
| not_joined | paid booking with `join_clicks = 0` | class end | same |
| tag_added | `wa_tags` row | `added_at` | `wa:r<id>:t<wa_contact>:<tag>` |
| keyword | inbound message (webhook) | received | `wa:r<id>:m<wa_message id>` |

Each planned message is an outbox row (`source automation`, `source_id` = rule id, `params`), so the cap, quiet
hours, pause, test list, STOP re-check, retries, alerts and send log all apply unchanged.

**Keyword rules** run in the webhook after Meta has its 200 (like STOP). Match: the whole message, or a whole
word in it, case-insensitive, any of the comma-separated keywords. Never for STOP or START words, never to a STOP
number, at most one auto-reply per rule per number per 6 hours, only while WhatsApp is enabled, not paused and
the number is allowed. The `text` action sends free text (the window is open because they just wrote) and logs
it `sent_by auto`; the `template` action plans an outbox row due now.

### Built-in messages (shown, locked)

The Automations page lists the built-in messages as locked rows: seat confirmation, day-before, 3-hour,
15-minute and "we're live" ("On while WhatsApp is on; pause everything in Settings"), and the checkout coupon
with its existing on/off (`whatsapp.checkout_coupon` through `setting_save`). Their wording stays in code.

### Dashboard page `views/automations.php`

`sections.php`: `'automations' => ['label' => 'Automations', 'icon' => 'pulse', 'group' => 'WhatsApp', 'range' => false, 'intro' => 'Messages that send themselves when something happens.']`.

- KPIs: **Rules on**, **Sent by rules this month**, **Read rate**, **Replies**.
- Card "Built-in messages" (full): the locked rows, with last-30-days sent and read.
- Card "Your rules" (full): name, trigger in words ("2 hours after the class ends"), template, audience, on/off
  pill, sent, and Edit, Switch on/off, Send a test, Delete (asks first).
- Card "Ready-made rules" (half): the presets, with "Make its template" and "Set up".
- Card "New rule" / "Edit rule" (half, `?r=ID`): trigger (`ui_select`), value, delay, action, template and fields
  or reply text (keyword rules), audience.

### Actions

| Action | Fields | Checks | Effect |
|---|---|---|---|
| `rule_save` | r?, name, trigger_type, trigger_value, delay_minutes, action, template_id, fields[], reply_text, audience | value valid for the trigger (minutes 10–1,440; hours 1–168; tag format; keywords 1–10, each 2–30 characters, never a STOP or START word); `text` only for keyword; template approved | save; editing an enabled rule switches it off ("Check it, then switch it on again"); audit |
| `rule_on` | r | template approved (template rules); WhatsApp ready | `enabled = 1`, `enabled_at = now`; flash "Only things that happen from now on count."; audit |
| `rule_off` | r | — | `enabled = 0`; this rule's queued rows → cancelled "Rule switched off"; audit |
| `rule_test` | r, to | `to` is a test number or `my_number` | send with sample values (the newest matching person, or "Priya"); audit |
| `rule_delete` | r, confirm=1 | — | cancel its queued rows, delete; audit |
| `preset_template` | preset | — | create the suggested draft template and link it; go to edit; audit |
| `setting_save` | values[whatsapp.checkout_coupon] | existing | existing |

Template delete (Phase 2) now also refuses a template used by an enabled rule.

### Meta API used

Template sends and free text, as earlier phases. No new calls.

### Tests: `dashboard_automations.py`

1. `upgrade v6 → v10` and `v9 → v10`: presets seeded once, off.
2. Each trigger fires once at the right time (paid, addon_paid, unpaid after X min, before X h, after X h,
   joined, not_joined, tag added), with the delay, and never twice across worker runs.
3. `switching on never reaches back`: bookings paid before `enabled_at` get nothing.
4. `audience filter` (tag in and out, add-on yes or no) and eligibility (consent, STOP, test list, marketing off).
5. `quiet hours delay a marketing rule to 9 AM; the daily cap holds rule messages but not booking reminders`.
6. `keyword auto-reply`: free text within the window; once per 6 hours; not to STOP numbers; STOP itself never
   matches; a template keyword rule plans an outbox row.
7. `switching a rule off cancels its queued messages`; `editing an enabled rule switches it off`.
8. `ready-made rules`: off by default; "Make its template" gives a draft that fails checks until the URL is
   added; the rule cannot be switched on until its template is approved.
9. `built-in rows locked; the checkout coupon switch works from this page`.
10. `pause stops rules; the test list limits them`.
11. `no real hosts, no token leaks, all pages clean`.

### Acceptance

Automation suite green, earlier suites green, baselines hold, CLAUDE.md "Automations" paragraph and schema 10,
commit "add whatsapp automations with built-in and ready-made rules".

---

## Phase 5: Contacts and WhatsApp overview

**Goal.** One list of everyone reachable on WhatsApp, with tags, and one page that says how WhatsApp is doing.

### Database

No schema change (stays v10). Contacts with a valid number but no `wa_contacts` row are shown from `contacts`
directly; a `wa_contacts` row is made only when needed (a tag is added, a message is sent), with
`tg_wa_contact_id()`.

### PHP

New `api/admin/lib/wa_contacts.php`: `admin_wac_list(PDO, array $filter, int $now): array` (contacts with a phone,
normalised with `tg_wa_number` in PHP, merged with WhatsApp-only `wa_contacts`, one row per number),
`admin_wac_export(...)`, `admin_wa_overview_data(PDO, array $config, int $now): array`.
`admin_wa_meta()` also asks the number for `messaging_limit_tier` and `throughput` (keeping today's fallback when
Meta refuses a field). The webhook handles `phone_number_quality_update`: forget the cache, email Deenu on a
drop or a limit change.

### Dashboard pages

`views/wa_contacts.php`; `sections.php`: `'wa_contacts' => ['label' => 'Contacts', 'icon' => 'users', 'group' => 'WhatsApp', 'range' => false, 'intro' => 'Everyone you can reach on WhatsApp, with tags.']`.
- KPIs: **Reachable** (would pass the `template` purpose rule), **Said STOP**, **Marketing off**, **Tagged**.
- Card "Contacts" (full, flush): search (name, number, email), chips All / Buyers / Leads / Messaged us / STOP /
  each tag; columns name and number, type pill, tags, last message, consent, STOP. Each row has a checkbox; under
  the table one form "Tag" (text) with buttons "Add tag to ticked" and "Remove tag from ticked". A row opens the
  conversation (Inbox) or People.
- Page head: "Download CSV" (`?export=csv`, logged like People): name, number, email, type, tags, consent
  version, STOP, marketing off, last message at. Never message text.

`views/wa_overview.php`, first in the WhatsApp group: `'wa_overview' => ['label' => 'Overview', 'icon' => 'whatsapp', 'group' => 'WhatsApp', 'range' => true, 'intro' => 'How WhatsApp is doing: conversations, spend, quality and templates.']`.
- KPIs: **Conversations** (numbers with a message in the period), **Estimated spend** (billable `wa_messages` ×
  rate; marketing and utility split in the note), **Quality** (Meta's rating, toned), **Messaging limit** (Meta's
  tier, and today's business-started count against it).
- Cards: "Messages per day" (`ui_columns`, in and out), "Spend by category" (`ui_bars`), "Best templates" and
  "Weakest templates" (read rate and reply rate, at least 10 sends in the period), "Connection" (reuse the
  WhatsApp tab's card), and the WhatsApp health checks (`admin_wa_checks`).
The Messages > WhatsApp tab stays as it is (tests and links point at it).

### Actions

| Action | Fields | Checks | Effect |
|---|---|---|---|
| `wac_tag` | op (add or remove), tag, phones[] (max 500) | tag format; each phone valid; count in the flash | make `wa_contacts` rows as needed, insert or delete tags (`added_by admin`), which can fire `tag_added` rules (only after their `enabled_at`); audit "tag x added to 12" |
| CSV download | GET `export=csv` | signed in | `send_csv`; audit `export_csv` |

### Meta API used, and what the fake must answer

`GET /{pnid}?fields=display_phone_number,verified_name,name_status,quality_rating,platform_type,status,messaging_limit_tier,throughput`
→ includes `messaging_limit_tier: "TIER_1K"`, `throughput: {level: "STANDARD"}`. A second mode where the fake
refuses the new fields (code 100) proves the fallback.

### Tests: `dashboard_contacts.py`

1. `list merges buyers, leads and WhatsApp-only numbers, one row per number` (10-digit and 91-prefixed forms).
2. `search and chips`.
3. `bulk tag add and remove`; a bad tag refused; a `tag_added` rule fires only for tags added after it was on.
4. `CSV`: right columns, no message text, formula cells escaped, logged.
5. `overview numbers`: the spend estimate matches the billable messages (utility inside the window free),
   conversations count, best and weakest templates with the 10-send minimum.
6. `quality and limit shown; fallback when Meta refuses the new fields; the quality webhook emails Deenu`.
7. `period picker works; all WhatsApp pages clean; no real hosts; no token leaks`.

### Acceptance

Contacts suite green, all earlier suites green, baselines hold, CLAUDE.md "WhatsApp contacts and overview",
commit "add whatsapp contacts with tags and an overview page".

---

## Final review (after Phase 5)

Both agents review the whole branch together: every suite (existing and the five new), a full upgrade matrix
(v4, v5, v6 → v10), the menu with all WhatsApp pages at 375 px and 1440 px (CSS only), and a read of every new
POST action against the ground rules. Fix, re-run, then write the morning summary for Deenu, leading with the
decisions below that need his eye.

---

## Risks and decisions

Decisions taken overnight (Deenu can reverse any of them):

1. **Message text kept 30 days** (Deenu's decision). Purged by the worker; counts, ticks and STOP records stay.
   Reply emails still put a copy in Deenu's mailbox, outside the database; the privacy policy says replies may
   be forwarded to email.
2. **`wa_contacts` is keyed by number, separate from `contacts`** (keyed by email). People who only messaged us
   have no email. It links to the newest contact with that number and survives that contact's deletion
   (SET NULL). WhatsApp-only numbers are forgotten after 90 quiet days; STOP rows never.
3. **Opening a conversation clears its unread count** on a GET. It is a display flag, not business data, so it
   is the one change that does not go through `actions.php`. Not audited.
4. **The inbox list auto-refreshes every 30 seconds; a conversation does not** (it would wipe a half-typed
   reply). Auto-refresh requests carry `auto=1` and do not keep the session alive.
5. **A seventh system template, `gurukul_follow_up` (utility)**, so the inbox can restart a closed conversation
   before Phase 2 exists. Meta may call it marketing; it is cheap either way.
6. **Tags arrive in Phase 1**, not Phase 5, so broadcasts and automations can filter on them.
7. **Broadcasts and automations go through the outbox** (new `params`, `source`, `source_id`), so pause, the test
   list, STOP re-checks, retries, alerts and the send log all work for them with no second sender. Booking
   messages go first in every run.
8. **The daily cap counts only business-started custom messages** (broadcasts, automations, inbox templates).
   Booking messages and free replies are never held back. Default 250, the lowest Meta tier.
9. **Quiet hours (9 PM to 9 AM IST) apply to marketing only.** Utility (booking facts) may go any time.
10. **Test sends go to the test list or a new `whatsapp.my_number` setting**, since the test list is emptied at
    go-live.
11. **Text headers only; no media headers.** A media header needs an upload handle from Meta's Resumable Upload
    API, which needs the Meta app id and an upload made through that app. Later: add `app_id` to the config, a
    file input on the template form, store the handle, send with a media link. Inbox replies are text only for
    now; incoming media shows as "[a photo]" and is not downloaded.
12. **One language** (the config's `language`, `en`) for custom templates for now.
13. **"Joined the class" means clicked the personal join link.** We cannot see Zoom attendance. "After the
    session" counts from the class end (start + 3 hours).
14. **Rules never reach back.** Only things that happen after a rule is switched on count. Editing an enabled
    rule switches it off.
15. **Spend is our estimate**, from our own message log and Deenu's rates. Meta's pricing analytics can come
    later. Best and weakest templates come from our own log too.
16. **Approved templates are never edited**, only duplicated under a new name, as with the system templates.
17. **Replies to someone who said STOP**: only free text inside the window, and only if they wrote again after
    their STOP. Never templates, broadcasts or rules.

Risks:

- **Marketing consent.** Sending offers to people who agreed only to "booking updates" stretches that consent
  and contradicts privacy policy line 139 as it stands. Phase 3 rewrites the line to match Deenu's decision and
  flags it. Meta's policy also expects people to know they may get offers. Keep the first broadcasts small and
  wanted, watch quality, and consider adding "and offers" to the pop-up wording later (a new
  `TG_CONSENT_VERSIONS` entry).
- **Number quality.** Marketing that people block or report lowers the quality rating, which can pause templates
  and cut the messaging limit, hurting the booking reminders. Mitigations: cap, quiet hours, test first, STOP
  footer prefilled on marketing templates, 131049 and 131050 respected, quality on the overview.
- **The fake Meta is not Meta.** Field names (`messaging_limit_tier`, `quality_score`, paging) and error codes
  come from the docs; the code reads them defensively and falls back. After "push it", Deenu should do one real
  test of each phase on his own number with the test list set.
- **Worker time.** Up to 200 bulk sends plus booking messages per 5-minute run, about 0.3 s each, fits; the
  existing lock stops runs overlapping. Scheduled broadcasts start within 5 minutes of their time.
- **Webhook fields.** The Meta app must subscribe to `messages`, `message_template_status_update`,
  `template_category_update`, `message_template_quality_update` and `phone_number_quality_update`. Added to
  `docs/whatsapp-setup.md`.
- **The number is API only.** The inbox becomes the main place to read replies; reply emails stay on by default
  so nothing is missed when the dashboard is closed.
- **Existing kit checks** that count system templates or match the reply email exactly need a deliberate update
  in Phase 1; each such change is named in the commit message.
- **Database growth.** `wa_messages` keeps rows (without text after 30 days). At a few thousand messages a month
  this stays small; the 90-day forget for WhatsApp-only numbers keeps it bounded.
