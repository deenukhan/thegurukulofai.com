# WhatsApp dashboard ("lighter AiSensy"): build plan

Branch `claude/whatsapp-dashboard`, from `main` at `ea82f78`. Written 23 Nov 2026 by the planning agent,
for the overnight build. Deenu was asleep, so every open question was settled here and listed under
"Risks and decisions". Change the plan by editing this file in the same commit as the code.

Revision 2 (same night): every major and minor issue from the critic's first review is resolved below.
Where the plan disagrees with the critic, the reason is under "Risks and decisions" (decision 18 on).

## Status

| Phase | What | Schema | Status |
|---|---|---|---|
| 1 | Inbox | v7 | built (see "Phase 1 as built") |
| 2 | Templates | v8 | built (see "Phase 2 as built") |
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
  Phase 1 changes `api/admin/index.php` line `$now = time();` to `$now = rp_now($config);` (it only differs
  with `rzp_test_` keys and a test clock), then re-runs every existing suite. The 30-minute idle sign-out
  (`$_SESSION['seen']`) stays on real `time()` on purpose: it is a security timer, not a business rule.
- **Dashboard.** One row of four KPIs, then `ui_card`s sized third/half/two-thirds/full in `ui_grid`.
  Pages only from `lib/ui.php` parts (new generic parts go there). Styles only in `api/admin/admin.css`;
  bump `ADMIN_CSS_VERSION`. No JavaScript. Every change is a POST handled in `lib/actions.php`: page token,
  `tg_audit`, `admin_flash`, `admin_done`. One line in `lib/sections.php` and one `views/<key>.php` per page.
  Plain English, short sentences, no exclamation marks, "you" for Deenu. **Every view state opens with its
  own row of four KPIs**, including the inner pages (`inbox&c=`, `wa_templates&edit=`, `broadcasts&b=`,
  `automations&r=`); each phase's suite checks it.
- **No message text in logs.** `tg_audit` details and `error_log` lines name the number (pretty form) and a
  length or template name, never the words of a message.
- **Escaping.** Message text from WhatsApp is untrusted. Always `h()`. CSV cells that start with
  `= + - @` get a leading `'`.
- **Sending switches, in this order, everywhere a WhatsApp message can leave:**
  `tg_wa_enabled` (config) → not `tg_wa_paused` (Settings) → `tg_wa_allowed` (test list) →
  not STOP (`tg_wa_opted_out` and `contacts.wa_optout_at`) → purpose rules (below). Checked when a message
  is planned and again at the moment it is sent (the worker already re-checks the test list and STOP; from
  Phase 3 it re-checks the purpose rule, marketing off and the template status too).
  The automatic STOP and START answers obey the first three switches as well (enabled, not paused, test
  list): "pause stops everything" is taken literally. The STOP or START itself is always recorded, paused or
  not; only the courtesy answer is skipped. Today the webhook sends those answers with no switch check, so
  this is a deliberate change (Phase 1, tested; any whatsapp.py check that expects an answer while paused or
  to a number off the test list is updated and named in the commit).
- **Purpose rules** live in one function, `tg_wa_can_message()` (Phase 1, `api/razorpay/wa_store.php`):
  - `reply` (free text): their last message is under 24 hours old; and if they ever said STOP, they wrote
    again after it.
  - `template` (custom template to one person, a broadcast or a rule): consent (`contacts.consent_version`
    not null on a linked contact) **or** they messaged us first. Never STOP.
  - `marketing`: as `template`, plus not `wa_contacts.marketing_off_at` (Phase 3), plus quiet hours.
  - **"Messaged us first" is defined by `wa_inbound`**: at least one row for that number with kind `reply` or
    `start`. `wa_inbound` rows are never deleted (no job touches them), so this basis cannot vanish when
    message text ages out. `wa_messages` is never used for eligibility.
  - The checkout pop-up's separate, unticked box ("Also tell me about future sessions and offers on WhatsApp
    and email", `masterclass/index.html`, stored as `contacts.marketing_optin`) is **not** required by
    Deenu's rule. Phase 3 and 4 offer it as an extra audience filter, "Ticked the offers box"
    (`marketing_optin = 1 AND email_unsub_at IS NULL`), and the broadcast preview says how many of the
    audience ticked it. See "Marketing consent" under risks.
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
- Phase 1 skips the automatic STOP/START answer while paused or for a number off the test list. A check that
  expects that answer in those states must change.
- Phase 1 moves the dashboard's `$now` to `rp_now`. Suites that set `test_now` and read dashboard times may
  shift; re-run all of them and fix any check that relied on real time.

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
(30 days). Rows are never deleted, so counts, spend estimates, template results and broadcast results stay
the same for old periods. Nightly in `cron/reconcile.php`, one anonymising step (not a delete): clear
`wa_contacts.profile_name` where `contact_id` is NULL and `last_msg_at` (or `created_at`) is over 90 days old.
The number itself stays: it is the key for STOP and for the "messaged us" record in `wa_inbound`, and the
message rows no longer hold text by then. `wa_inbound` is never deleted or changed by any job.

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
rule. STOP/START answers go through `tg_wa_send_text_result` and are logged as outbound (`sent_by = 'auto'`),
only when `tg_wa_enabled`, not `tg_wa_paused` and `tg_wa_allowed` (see ground rules); when skipped, nothing
is logged as sent and the STOP or START is still recorded.
Reply emails follow the new setting (below), which is the existing `forward_replies` key.

STOP and START and outbox rows (`tg_wa_inbound` in `whatsapp.php`), so that later bulk rows can never be
revived by START:
- STOP keeps today's booking-row cancel (`last_error = 'Replied STOP on WhatsApp'`). From Phase 3 it also
  cancels queued bulk rows (`source IS NOT NULL`) for that number by `to_addr`, with a different text,
  `'Said STOP before it was sent'`.
- START re-queues only rows with `source IS NULL` and `last_error = 'Replied STOP on WhatsApp'` (add
  `AND o.source IS NULL` once Phase 3 adds the column). A broadcast or rule message dropped by STOP stays
  cancelled. The worker's send-time drop uses the same split: booking rows `'Replied STOP on WhatsApp'`,
  bulk rows `'Said STOP before it was sent'`.

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
- `ui_confirm(string $title, string $html, string $form, string $cancelHref)`: an ask-first card like the
  one the People page's resend uses today. New pages use it; **People is left as it is** (no refactor of a
  tested page overnight).
- `admin_here()` also strips `qr` and `auto`, so a POST from a quick-reply "Edit first" or from an
  auto-refreshed list comes back to a clean page (no refilled box, no `auto=1` carried along).

`api/admin/index.php`:
- Menu badge for `inbox` from `admin_inbox_unread()`, tone `info` (new `.badge-info` in admin.css).
- A page may return `'refresh' => 30`; `admin_document()` then adds
  `<meta http-equiv="refresh" content="30;url=<this page>&auto=1">`. A request with `auto=1` does **not**
  update `$_SESSION['seen']`, so the 30-minute idle sign-out still works with the inbox left open.
- `$now = rp_now($config);` instead of `time()` (see ground rules). The idle check keeps `time()`.

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
- Default chip "Wrote to you" (last_in_at set, sorted by it); "All" is the last chip (review fix).
- A STOP shows a "Said STOP" pill instead of the window, and is left out of "Windows open" (review fix).
- The list holds no forms but search (review fix: a 30-second reload wiped half-typed quick replies).

Manage (`?view=inbox&manage=1`, also any `eqr=`): no auto-refresh. KPIs: Quick replies, Reply emails,
Replies this week, Templates this week.
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
| `wa_send_template` | c, template | token; template approved (Meta cache) and fillable; `tg_wa_can_message(template)` is null; from Phase 3, room under the daily cap (or the number is already counted in the last 24 hours), else flash "You've reached your daily limit" | `tg_wa_send`; an outbox row like `admin_wa_test` (status sent or failed, `alerted_at` set on failure) so it shows in the send log; log out; audit `whatsapp_template_one` |
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
7. `opening clears unread; auto-refresh does not keep the session alive`. The idle timer uses real time, so
   the test clock cannot age it. The suite runs PHP with its own `session.save_path` (a scratch folder, set
   with `-d` on the built-in server), finds the session file for its cookie, rewrites `seen` to 31 minutes
   ago, and checks that an `auto=1` request lands on sign-in while a plain request a moment earlier (with
   `seen` 29 minutes old) did not, and that the `auto=1` request did not refresh `seen`.
8. `reply inside the window`: the fake gets the exact text to the right number; out row sent; delivered and
   read webhooks tick it; audit has no message text; their message marked read at Meta.
9. `reply after 24 hours refused`: clock +25 h, no reply form, a forced POST refused, no Meta call.
10. `Meta says window closed`: 131047 → failed row, plain flash.
11. `paused / switched off / not on the test list`: reply refused, no Meta call, reason shown.
12. `template outside the window`: `reopen` approved → sent with the first name; not approved → refused.
13. `quick replies`: add, edit, "Edit first" prefill, one-click send, delete.
14. `manual STOP and START`: STOP hides the template form; START needs the box.
15. `STOP from WhatsApp shows in the conversation with the automatic answer`; while paused (and from a
    number off the test list) the STOP is recorded and its queued rows cancelled, but no answer is sent and
    the fake Meta gets no request; START while paused likewise records only.
16. `reply emails switch`: off → no email for a reply; on → email with the inbox link and no phone in the URL.
17. `reminders appear in the conversation`: buy, run the worker, the confirmation bubble shows the rendered text.
18. `text removed after 30 days`: clock +31 d, worker run, body NULL, counts unchanged, page says removed.
19. `old numbers anonymised, never deleted`: reconcile on a 91-day-old WhatsApp-only conversation clears its
    profile name; the conversation, its message rows and counts, its tags and its `wa_inbound` rows (STOP and
    "messaged us") all stay; it is still eligible for a template as "messaged us".
20. `tags`: add, refuse a bad tag, remove.
21. `no real hosts, no token leaks, all pages clean`.
22. `dashboard follows the test clock`: with `test_now` set, "time ago" and the window pill use it; every
    existing suite re-run after the `$now` change.
23. `admin_here drops qr and auto`: after "Edit first" and Send, the box is empty; a POST from `auto=1` comes
    back without it.

### Also in Phase 1

- `privacy-policy.html`, the WhatsApp text. Add: "When you message our WhatsApp number, or we message you, we
  keep the text of those messages for 30 days so we can answer you, then delete it. We keep your number, a
  record that a message was sent, delivered or read (without its text), and any request to stop, so we can
  respect it. Replies may also be forwarded to our email."
  No CSS change, so no `?v=` bump.
- CLAUDE.md "Masterclass WhatsApp": replace "Message text is never stored; `wa_inbound` keeps only id, number
  and kind" with the 30-day rule; add an "Inbox" paragraph; schema version 7.
- `docs/whatsapp-setup.md`: the inbox replaces "reply from your own WhatsApp".
- Health (`admin_wa_checks`): warn "N people are waiting for an answer" when a conversation's last message is
  theirs and over 12 hours old (the window closes at 24).

### Acceptance

Inbox suite all green; baselines hold; php -l clean; pages clean; CLAUDE.md, privacy policy and this plan's
status updated; commit "add whatsapp inbox with 24-hour replies and quick replies".

### Phase 1 as built (builder's notes, 23 Nov 2026)

Where the code differs from the text above, or settles something it left open:
- **`tg_wa_apply_stop()`** (whatsapp.php) holds the STOP/START effects on contacts and outbox rows, shared by the
  webhook and the dashboard's manual STOP/START, so both behave the same. Phase 3 adds its `source` split there.
- **Reactions and system notices** (`skip` kinds) are recorded in `wa_inbound` as before but not stored as messages,
  not counted unread and do not move `last_in_at`. Only real messages open the 24-hour window.
- **"Waiting for you"**: not marked done, their last message (by `wa_inbound`, so backfilled rows count) is a real
  reply under 24 hours old, and no reply by hand or automatic answer (not failed) came after it. STOP and START are
  never "waiting". `last_out_at` is any message we sent, booking messages included.
- **Health warning** fires for people waiting 12 to 24 hours only; after 24 hours the free window is gone, so it
  would never clear by itself.
- **Chips**: "All" shows every conversation, done ones included; "Done" shows only those.
- **Template send order**: the purpose rule (switches, STOP, consent or messaged us) is checked before Meta's
  approval, so the reason shown is the one that matters first.
- **`admin_here()`** also drops `ask` (the ask-first STOP card) and `eqr` (editing a quick reply on the list).
- **The conversation box** opens scrolled to the newest message with CSS alone (`flex-direction: column-reverse`).
- **`tg_wa_send_text_result()`** also returns Meta's error `code`, which the reply action uses to spot 131047.
- **Existing kit changes**: whatsapp.py "Submit to Meta" now expects six missing templates (with
  `gurukul_follow_up`), and "messages without buttons" includes `reopen`. No other existing check changed.
- Test suite `dashboard_inbox.py`: 110 checks.

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
`quality_score` is an object (`{score: "GREEN", date: ...}`): read `quality_score.score`, accept a plain string
too (defensive), store GREEN, YELLOW, RED or UNKNOWN.

Webhook: `message_template_status_update` also updates the `wa_templates` row (status, reason, status_at);
`template_category_update` sets `meta_category`; new `message_template_quality_update` sets `quality` (and
emails Deenu when it drops to RED). Template news emails cover custom templates too.

Outbox in Phase 2: an inbox single send of a custom template goes straight to Meta with `tg_wat_send` (like
`admin_wa_test` today) and writes one outbox row already final (status `sent` or `failed`, `template` =
`c:<id>`, `alerted_at` set on failure) so it shows in the send log. Nothing queues a `c:` row in Phase 2, so
the worker does not learn `c:` rows until Phase 3 (which adds the `params` column they need).

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
    fixed value; stored text matches the render; outbox row `c:<id>` already `sent`; the worker run after it
    sends nothing more.
13. `system templates locked`: no edit or delete controls; a forced POST refused.
14. `no real hosts, no token leaks, all pages clean`.
(The old test 13, "worker refuses a custom template that is no longer approved", moved to Phase 3.)

### Acceptance

Templates suite green, inbox suite still green, baselines hold, CLAUDE.md "WhatsApp templates" paragraph and
schema 8, commit "add whatsapp template builder with pre-submit checks".

### Phase 2 as built (builder's notes, 23 Nov 2026)

Where the code differs from the text above, or settles something it left open:
- **Function shapes.** `tg_wat_values(array $config, array $t, array $person, array $fixed, int $now)` takes the
  person from the new `tg_wat_person(PDO, $config, $phone)` (name from the booking form, else the WhatsApp name; their
  latest paid session) and returns `body`, `buttons` and `missing`. Fields per slot come from the row itself, so there is
  no separate `$fields` argument. `tg_wat_slots($t)` lists what a template needs (`b2` for `{{2}}`, `u0` for the first
  button's link); typed text arrives as `fixed[b2]` / `fixed[u0]`. `tg_wat_submit` and `tg_wat_delete` take `$now`.
  `tg_wat_check` takes an optional list of names Meta already has (from the cached template list) and refuses a draft
  that would clash. `tg_wat_event` handles the three template webhooks in one place.
- **A URL button's `{{1}}` has its own field** (stored in the button's JSON as `field`), chosen once the link ends in `{{1}}`.
- **Saving.** A draft needs a name to be saved (the name is the unique key). A name already used by another row, or
  deleted at Meta in the last 30 days, is refused at save time with the reason; after 30 days the old deleted row is
  renamed `<name>_old<id>` so the name is free. Lengths are capped before saving (header and footer 60, button text 40,
  body 2,000), so the checks can still name a body over 1,024 or button text over 25. Non-ASCII in a name becomes `?`,
  so the name check reports it.
- **Deleting a draft removes the row** with no Meta call, and its name is free at once: it never reached Meta. Only a
  submitted template is deleted at Meta and kept as `DELETED` (the name block).
- **New templates start with the STOP footer** filled in (no JavaScript, so it can't follow the category choice);
  a marketing template without "stop" in the footer or body gets a warning.
- **Quick reply buttons must sit together** (an error otherwise), as Meta requires.
- **Sync.** `tg_wa_template_list` reads every page (up to 5) for both our templates and the built-in states, so
  `tg_wa_template_states` follows paging too and also returns `quality` and `id`. A submitted template Meta no longer
  lists is marked `DELETED`, but only when every page was read. The page syncs at most every 10 minutes when it opens;
  "Refresh from Meta" syncs at once and marks the sync done, so the page after it does not read Meta again.
- **Inbox.** When the window is closed, the follow-up and every approved custom template that can be filled for this
  person are offered as chips (`&tpl=`), each with its own preview and a box for each typed field. A custom template is
  offered to anyone the `template` purpose rule allows (consent or messaged us); quiet hours are not applied to a
  one-off inbox send, only to broadcasts and rules (Phase 3). The send re-reads the row, so a template paused since the
  page loaded is refused.
- **Webhook.** A `DELETED` or `PENDING_DELETION` status no longer emails Deenu (he did it). A marketing template that
  Meta moves to utility does not email either. A quality drop to RED emails him. Notes about a dashboard template link
  to the Templates page instead of the WhatsApp tab.
- **Health** warns when one of your templates is rejected, paused or disabled.
- **Send log** labels `c:<id>` rows with the template's name.
- Test suite `dashboard_templates.py`: 103 checks. No existing kit check changed.

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

Retention: `tg_wa_retention` also clears `outbox.params`, but only on rows in a final status (`sent`,
`failed`, `expired`, `cancelled`) and keyed on PHP-set times: `COALESCE(sent_at, due_at) < now − 30 days`
(`tg_ist`). Never by `created_at` (an SQL default in real time) and never on `queued` or `sending` rows, so a
scheduled or cap-held send never loses its values.

**Bulk rows** (every outbox row with `source` not NULL: broadcast, automation, inbox) follow these rules:
- `booking_id` is always **NULL**. Step 4's cancel ("booking no longer paid or reminders off") and the STOP
  cancel, which both join on `booking_id`, therefore never touch them. Bulk rows have their own checks at send
  time (below), so a broadcast to unpaid checkouts is not cancelled for being unpaid.
- The person is `to_addr` (the number). Anything a check needs beyond that goes in `params`: the values, and
  for rows planned from a booking or checkout, `{"booking": <id>}` (a reference only, no FK).
- Inbox template sends from Phase 2 also get `source = 'inbox'` from Phase 3 on (still written final).

### Settings (`RP_DASHBOARD_SETTINGS` and `admin_setting_defs`, on Settings > WhatsApp)

- `whatsapp.daily_cap`: the most **different people** who may get a business-started template from us in any
  **rolling 24 hours**, which is how Meta counts its messaging limit. Whole number 0–10,000, default **250**
  (the lowest tier). Everything counts toward it: booking confirmations and reminders, the checkout coupon,
  inbox templates, broadcasts and rules (distinct numbers among WhatsApp outbox rows with status `sent` and
  `sent_at` in the last 24 hours, plus Phase 2's final inbox rows, which are outbox rows too). Free replies
  inside the window do not count (Meta does not count them either). **Only bulk rows are ever held**:
  booking rows always go, and bulk rows yield to them. See "Daily cap" under the worker changes.
- `whatsapp.my_number`: your own WhatsApp number, for test sends **after go-live**. While `test_numbers` has
  numbers, every test target must be on that list (`tg_wa_allowed` blocks anything else), so `my_number`
  works as a target only if it is on the list too. The test forms say so: "While the test list is set, tests
  go only to numbers on it."

Quiet hours are fixed, not a setting: **no marketing template leaves between 9 PM and 9 AM IST.**
`TG_WA_QUIET_FROM = 21`, `TG_WA_QUIET_TO = 9`.

### Audience

`audience` JSON: `{base, session, tag_in, tag_out, days, offers_box}` with one base:
- `all`: everyone reachable;
- `buyers`: paid bookings (optionally one `session`);
- `addon`: paid with the add-on (optionally one session);
- `no_addon`: paid without the add-on (optionally one session);
- `unpaid`: checkouts never paid since, within `days` (7, 30 or 90);
- `messaged`: people who wrote to us, within `days` (1, 7, 30, or ever);
- `not_read_of`: used only by "resend" (sent by the parent broadcast, not read, not failed).
Then `tag_in` (has this tag) and `tag_out` (does not), and `offers_box` (on: only people whose linked contact
ticked the checkout offers box, `marketing_optin = 1 AND email_unsub_at IS NULL`; off by default, because
Deenu's rule does not need it).

Always applied, in `tg_wa_audience(PDO $pdo, array $config, array $audience, string $category, int $now): array`
(new `api/razorpay/wa_bulk.php`), returning `['people' => [...], 'skipped' => [reason => count], 'offers_box'
=> N]`: a valid number (`tg_wa_number`), one row per number, consent or messaged us (`wa_inbound`, see ground
rules), not STOP, on the test list while it has numbers, and for marketing not `marketing_off_at`. Skipped
reasons are counted and shown. `offers_box` is how many of the people found ticked the offers box.

### Worker changes (`tg_outbox_run`)

**Order.** Step 5 sends booking messages first (rows with `source IS NULL`, existing limit), then **bulk
rows** (`source IN ('broadcast', 'automation', 'inbox')`) up to `outbox.wa_bulk_per_run` (config, default 200).
The bulk select keeps today's `(next_try_at IS NULL OR next_try_at <= now)`, so held rows (below) are skipped
by the SQL itself and never crowd out sendable rows inside the `LIMIT`.

**Send-time checks for each bulk row**, in this order (a row that fails one is cancelled or held, never sent):
1. the switches (enabled, not paused, test list): as today;
2. STOP → cancelled `'Said STOP before it was sent'` (not the booking-row text, so START never revives it);
3. the purpose rule again (`tg_wa_can_message`, `template` or `marketing` by the template's category):
   consent or messaged us, and for marketing not `marketing_off_at` → cancelled with the reason;
4. its broadcast still `scheduled`/`sending`, or its rule still enabled → else cancelled;
5. rows planned by an `unpaid` **rule** carry `params.booking`; if `tg_nudge_paid_since()` says they paid
   since → cancelled `'Paid in the meantime'`;
6. the template status from `wa_templates` (kept current by the webhook and `tg_wat_sync`; no Graph call per
   row): APPROVED → go on. PAUSED → `next_try_at = now + 1 h`, stays queued until it goes or `expires_at` ends
   it. DISABLED, REJECTED or DELETED (or the row gone) → **every** queued row for that template fails at once
   with "That template is no longer approved", one alert for the lot (the first row left with `alerted_at`
   NULL, the rest set);
7. quiet hours for marketing → `next_try_at` = the next 9:00 IST;
8. the daily cap (below) → `next_try_at` = when the oldest counted send in the window turns 24 hours old.
An `unpaid` broadcast audience is a one-off choice Deenu makes on the preview with real names, so its rows
are not re-checked for payment; only `unpaid` **rule** rows are (the rule's whole point is "has not paid").

**Daily cap.** At the start of the bulk pass: `used` = distinct numbers sent any WhatsApp template in the last
24 hours; `reserve` = distinct numbers with a queued booking WhatsApp row (`source IS NULL`) due in the next
24 hours that are not already in `used`; `room = daily_cap − used − reserve`. Each bulk row to a number not in
`used` takes one from `room`; at 0 the rest are held (rule 8). A bulk row to a number already in `used` costs
nothing extra and may go. Booking rows are never held, so the reserve keeps the next session's reminders safe.

**Errors that concern one person** are normal in a broadcast. For bulk rows, these are written `failed` with
`alerted_at` already set (they show on the broadcast or rule page instead) and **do not count toward the
three-in-a-row down streak**: 131049 "Meta held it back: this person got many marketing messages recently"
(no retry); 131050 "They turned off marketing from us in WhatsApp" (also sets `marketing_off_at`); 131026 "Not
on WhatsApp, or an old app"; 131047 "The 24-hour window was closed"; 131021 and a bad-number 100 "Not a valid
WhatsApp number". **Only systemic failures** still trip the streak and alert: auth errors (190, 10, 200),
template paused or disabled at Meta (132015, 132016, 132001: these also update `wa_templates` and apply rule 6),
and Meta down (5xx, 1, 2, 131000). Booking rows keep today's behaviour unchanged (every failure alerts and
counts), so whatsapp.py stays valid.

**Alerts and the 9 AM summary.** `tg_outbox_alert` labels bulk rows readably: "WhatsApp broadcast "<name>"",
"WhatsApp rule "<name>"" or "WhatsApp inbox template <name>", not `c:12`. The daily summary's failure count
stays booking-only; bulk results go on their own line: "Broadcasts and rules: N sent, N failed, N not sent
(daily limit or quiet hours)", with a link to Broadcasts.

**Expiry.** Each bulk row's `expires_at` is its own `due_at + 48 h`, and `due_at` is spread by day at
confirmation (see `bc_confirm`), so a large broadcast is planned honestly rather than left to expire. Rows that
still expire show as "Not sent: daily limit or quiet hours" on the broadcast page and in the summary line;
never silently.

**Custom template rows** (`template` = `c:<id>`) load the `wa_templates` row and `params`, and send with
`tg_wat_send` (moved here from Phase 2).

Broadcast status: `scheduled` → `sending` when its first row goes → `done` when none are left queued.

### Dashboard page `views/broadcasts.php`

`sections.php`: `'broadcasts' => ['label' => 'Broadcasts', 'icon' => 'send', 'group' => 'WhatsApp', 'range' => false, 'intro' => 'Send one template to a group, with a test first.']`.

List: KPIs **Sent this month**, **Read rate**, **Replies**, **Estimated spend this month**. Card "Broadcasts"
(full): name, template, status, when, people, sent / read / replied / failed, link to open. A "New broadcast"
button makes a draft.

One broadcast (`?view=broadcasts&b=ID`). KPIs first, like every page: **People** (the audience count now, or
queued at confirmation), **Sent**, **Read** (with %), **Estimated cost** (before sending: count × rate; after:
from the rows not failed). Then four numbered steps as cards:
1. **Message**: pick an approved template (`ui_select`); map each variable to a field or fixed text.
2. **Who**: base, session, tags, days, and "Only people who ticked the offers box". Saving shows the count,
   skipped reasons with counts, "N of these ticked the offers box" (a warn notice for a marketing template
   when N is below the count: "The others agreed to booking updates or messaged you, but did not tick the
   offers box"), the first 10 people with their filled-in message (real names), the estimated cost (count ×
   rate for the category, with "free inside an open window" for utility), and the plan against the daily cap:
   "About N can go today. At your limit this takes D days." When D is more than 7 the broadcast cannot be
   confirmed: "That is more than 7 days of sends. Narrow the audience or raise the limit." Once Phase 5 lands,
   Meta's live messaging tier shows next to the cap, with a warning when the cap is above it.
3. **Test**: "Send a test" to a number on the test list (or, once the list is empty, your number), filled
   with the first person's real values. Required, and needed again after any change to steps 1–2
   (`test_hash`). The form says "While the test list is set, tests go only to numbers on it."
4. **Send**: "Send now" or a date and time (IST), and a box "Type the number of people to confirm: 143". For
   marketing, a time inside quiet hours is refused, and "Send now" during quiet hours becomes 9 AM with a note.
   After sending: results (sent, delivered, read, replied, failed, each with %), failure reasons grouped, the
   people table, "Cancel the rest" while sending, and after 12 hours "Resend to people who did not read".

"Replied" means an inbound message from that number within 72 hours after ours was sent.

### Actions

| Action | Fields | Checks | Effect |
|---|---|---|---|
| `bc_save` | b?, name, template_id, fields[], fixed[], base, session, tag_in, tag_out, days | a draft; template approved; fields valid for the template | save; clears `test_hash` if anything changed; audit |
| `bc_test` | b, to | a draft; while `test_numbers` has numbers, `to` must be on it; once empty, `to` must be `my_number`; WhatsApp enabled and not paused; audience not empty | send with the first person's values; outbox row written final (`source NULL`, booking NULL, dedupe `wa:bctest:<id>:<random>`, `alerted_at` set on failure); on success store `test_hash`, `test_at`, `test_to`; audit |
| `bc_confirm` | b, count, when (now or a datetime) | a draft; `test_hash` matches the draft now; `count` equals a fresh recount exactly (else flash the new count, nothing sent); time valid (≥ now, ≤ 30 days, outside quiet hours for marketing); the day plan fits in 7 days; WhatsApp enabled, not paused; template approved | snapshot the audience into outbox rows (dedupe `wa:bc<id>:<phone>`, booking NULL, `params`, `source broadcast`); spread by day: row *i* gets `due_at = send_at + floor(i / per_day) × 24 h`, where `per_day` = the daily cap (day 0 uses today's room if smaller), and `expires_at = due_at + 48 h`; status `scheduled`; audit `broadcast_confirmed` "name: 143 people over 1 day, ₹123 est." |
| `bc_cancel` | b, confirm=1 | scheduled or sending | queued rows → cancelled "Broadcast cancelled"; status `cancelled`; audit |
| `bc_resend` | b | done, finished ≥ 12 h ago | new draft with `parent_id`, base `not_read_of`, same template and fields; audit |
| `bc_delete` | b, confirm=1 | drafts only | delete; audit |
| `setting_save` | values[whatsapp.daily_cap], values[whatsapp.my_number] | existing settings checks | existing |

Template delete (Phase 2) now refuses a template used by a scheduled or sending broadcast.

### Meta API used, and what the fake must answer

Template sends to `POST /{pnid}/messages`, as Phase 2. Fake modes per number (`fail_to`): `freq` → 131049,
`mkt_off` → 131050, `nowa` → 131026, `rate` → 130429, `closed` → 131047, `down` → 500 code 2 (systemic).
Webhook statuses as before. Day-spread rows keep the confirmed time of day, which was already checked to be
outside quiet hours, so later days of a marketing broadcast also land in the daytime.

### Tests: `dashboard_broadcasts.py`

1. `upgrade v6 → v9` and `v8 → v9`.
2. `audience counts`: each base, the session filter, tag in and out, days; skipped reasons (no consent, STOP,
   test list, bad number, duplicate number, marketing off) counted right; people who messaged us count
   without consent.
3. `preview shows real names and the cost estimate` (marketing, 143 × ₹0.86).
4. `cannot confirm without a test`; `a change after the test needs a new test`.
5. `test target`: while the test list is set, only a listed number (my_number off the list refused, form says
   why); with the list empty, only my_number.
6. `typed count must match`; `count changed since the preview` → refused with the new count.
7. `send now`: rows queued with booking NULL; the worker sends in batches of `wa_bulk_per_run`; booking
   reminders go first in the same run.
8. `scheduled`: nothing before the time; sent after.
9. `quiet hours`: a marketing broadcast at 10 PM refused; "send now" at 10 PM moves to 9 AM; utility not held;
   held rows get `next_try_at` 9:00 and 250 held marketing rows do not stop a utility broadcast queued behind
   them from going in the same run.
10. `daily cap and day plan`: cap 5, 12 people → preview "3 days", rows due day 0 (5), day 1 (5), day 2 (2),
    each with its own expiry; each day sends 5; nothing expires. Cap 5, 40 people → confirm refused (8 days).
    A booking confirmation sent today counts toward `used`; a session tomorrow with 3 queued reminders
    reserves 3; booking rows are never held even with `room` at 0.
11. `pause stops a broadcast midway; the test list still limits; STOP between confirm and send cancels that
    row with "Said STOP before it was sent"; START afterwards does not revive it` (booking rows still do).
12. `unpaid audience`: a broadcast to unpaid checkouts is not cancelled by step 4 and is sent.
13. `send-time re-checks`: consent removed (contact deleted, no inbound) → cancelled; marketing off → cancelled;
    template PAUSED → waits an hour then goes; template DISABLED → all its queued rows fail with one alert.
14. `one-person errors do not stall`: three `nowa` numbers in a row, then the rest of the broadcast still
    sends in the same run; no alert email; results show the three as "Not on WhatsApp"; a `down` mode still
    trips the streak and alerts once.
15. `results`: delivered and read webhooks, a reply within 72 h, failures (131049; 131050 sets marketing off;
    131026) with reasons; estimated cost counts only rows not failed.
16. `cancel the rest`.
17. `resend to non-readers`: only sent-not-read people; needs its own test and confirmation.
18. `params cleared after 30 days`: with the test clock, final rows past `COALESCE(sent_at, due_at) + 30 d`
    lose `params`; a queued day-3 row of the same broadcast keeps them.
19. `worker refuses a custom template that is no longer approved` (from Phase 2's old test 13).
20. `9 AM summary`: bulk failures on their own line, not in the booking failure count; alert labels name the
    broadcast, never `c:12`.
21. `offers box`: the filter narrows the audience; the preview shows "N of these ticked the offers box".
22. `no real hosts, no token leaks, all pages clean`.

### Also in Phase 3

**Privacy policy and the offers box, flagged for Deenu.** Facts as they stand: the checkout pop-up shows a
separate, **unticked** box, "Also tell me about future sessions and offers on WhatsApp and email. You can stop
anytime." (`masterclass/index.html`), stored as `contacts.marketing_optin`; `cron/reconcile.php` already keeps
leads who ticked it. The consent wording people agree to covers only seat confirmation, Zoom link and
reminders. Privacy policy line 139 promises news and offers only to people who signed up or ticked the box.

Deenu decided there is no separate WhatsApp marketing opt-in, and that broadcasts may go to anyone who agreed
to WhatsApp booking updates or messaged us. Sending offers on WhatsApp to someone who saw that box and left it
unticked contradicts the choice they made on the same form, so this build does three things and leaves the
rest to Deenu:
1. Keeps Deenu's rule as the outer limit, and adds the "Ticked the offers box" audience filter (broadcasts
   and rules) and the "N of these ticked the offers box" line on every marketing preview.
2. Changes policy line 139 so it describes what the dashboard can do, without dropping the box: "Send you
   news about future sessions, courses and offers by email only if you signed up or ticked the offers box at
   checkout. On WhatsApp we may also send these to people who agreed to WhatsApp booking updates or who
   messaged us. To stop, reply STOP to any of our WhatsApp messages or email us. You can stop anytime."
3. Does **not** change the checkout pop-up (it is the live sales page). Instead the commit message, CLAUDE.md
   and the morning summary say: **before the first marketing broadcast, make the pop-up and the policy
   agree.** Either (a) send marketing broadcasts only with "Ticked the offers box" on, or (b) change the
   pop-up wording, for example a new `TG_CONSENT_VERSIONS` entry whose booking-updates line adds "and
   occasional offers on WhatsApp", so people who agree after that date know. A health info line on the
   Broadcasts page repeats this until Deenu has sent a marketing broadcast with the filter on, or a new
   consent version exists.

CLAUDE.md: a "Broadcasts" paragraph (cap over a rolling 24 hours counting every template, bulk rows yield,
day plan up to 7 days, quiet hours, test first, typed confirmation, bulk rows carry no booking, the offers-box
flag), schema 9.

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
  trigger_value VARCHAR(320) NOT NULL DEFAULT ''  -- minutes (unpaid), hours (before/after), tag, or keywords
                                                  -- (10 keywords × 30 characters + commas fit)
  delay_minutes INT UNSIGNED NOT NULL DEFAULT 0
  action VARCHAR(8) NOT NULL          -- template, or text (keyword rules only)
  template_id INT UNSIGNED NULL
  fields TEXT NULL                    -- JSON, as broadcasts
  reply_text VARCHAR(1000) NULL       -- keyword auto-reply, free text
  audience TEXT NULL                  -- JSON: optional tag_in, tag_out, addon yes/no, offers_box
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

Each planned message is a bulk outbox row (`source automation`, `source_id` = rule id, `booking_id` NULL,
`params` with the values and, for booking-based triggers, `{"booking": id}`), so the cap, quiet hours, pause,
test list, the send-time re-checks (STOP, purpose rule, marketing off, template status, rule still on),
retries, alerts and send log all apply as in Phase 3. `unpaid` rows are also cancelled "Paid in the meantime"
when `tg_nudge_paid_since()` says so at send time (Phase 3 check 5), so a lead who pays during the delay never
gets the "you didn't finish" message.

`expires_at` per trigger (the row never goes after it):
- `before_session`: `session_at` (never after the class has started);
- `unpaid`: `due + 24 h`, and never after the session they tried to book has started;
- every other trigger: `due + 24 h`.
`rule_save` refuses a `before_session` rule whose delay would land at or after the start
(`delay_minutes ≥ X h × 60`): "That delay sends it after the class starts."

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
| `rule_save` | r?, name, trigger_type, trigger_value, delay_minutes, action, template_id, fields[], reply_text, audience | value valid for the trigger (minutes 10–1,440; hours 1–168; tag format; keywords 1–10, each 2–30 characters, 320 in all, never a STOP or START word); `before_session` delay below X h; `text` only for keyword; template approved | save; editing an enabled rule switches it off ("Check it, then switch it on again"); audit |
| `rule_on` | r | template approved (template rules); WhatsApp ready | `enabled = 1`, `enabled_at = now`; flash "Only things that happen from now on count."; audit |
| `rule_off` | r | — | `enabled = 0`; this rule's queued rows → cancelled "Rule switched off"; audit |
| `rule_test` | r, to | as `bc_test`: on the test list while it is set, else `my_number` | send with sample values (the newest matching person, or "Priya"); audit |
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
11. `unpaid rule and payment`: an unpaid rule row with a 60-minute delay is cancelled "Paid in the meantime"
    when the lead pays at minute 30; step 4 never cancels a rule row (booking NULL).
12. `expiry and delay limits`: a before-session row held by the cap past the start expires unsent (shown);
    a `before_session` rule with 2 h and a 120-minute delay is refused; 10 keywords of 30 characters save.
13. `edit page KPIs`: the rules list and `?r=ID` both open with one row of four KPIs.
14. `no real hosts, no token leaks, all pages clean`.

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
  each tag, Ticked the offers box; columns name and number, type pill, tags, last message, consent, STOP.
  **100 rows per page** (`&p=N`, "Newer" and "Older" links; search and chips narrow first, in SQL where
  possible, then the merged list is sliced). Each row on the current page has a checkbox; under the table one
  form "Tag" (text) with buttons "Add tag to ticked" and "Remove tag from ticked", for up to the 100 ticked
  rows on that page. A row opens the conversation (Inbox) or People.
- Page head: "Download CSV" (`?export=csv`, logged like People): name, number, email, type, tags, consent
  version, STOP, marketing off, last message at. Never message text.

`views/wa_overview.php`, first in the WhatsApp group: `'wa_overview' => ['label' => 'Overview', 'icon' => 'whatsapp', 'group' => 'WhatsApp', 'range' => true, 'intro' => 'How WhatsApp is doing: conversations, spend, quality and templates.']`.
- KPIs: **Conversations** (numbers with a message in the period), **Estimated spend** (billable `wa_messages`
  whose status is not `failed`, × rate; Meta bills on delivery, so failed sends never count; marketing and
  utility split in the note; always labelled "estimate"), **Quality** (Meta's rating, toned), **Messaging
  limit** (Meta's tier, and the rolling-24-hour count of different people we started with, the same number the
  daily cap uses).
- Cards: "Messages per day" (`ui_columns`, in and out), "Spend by category" (`ui_bars`), "Best templates" and
  "Weakest templates" (read rate and reply rate, at least 10 sends in the period), "Connection" (reuse the
  WhatsApp tab's card), and the WhatsApp health checks (`admin_wa_checks`).
The Messages > WhatsApp tab stays as it is (tests and links point at it).

### Actions

| Action | Fields | Checks | Effect |
|---|---|---|---|
| `wac_tag` | op (add or remove), tag, phones[] (max 100, the current page) | tag format; each phone valid; count in the flash | make `wa_contacts` rows as needed, insert or delete tags (`added_by admin`), which can fire `tag_added` rules (only after their `enabled_at`); audit "tag x added to 12" |
| CSV download | GET `export=csv` | signed in | `send_csv`; audit `export_csv` |

### Meta API used, and what the fake must answer

`GET /{pnid}?fields=display_phone_number,verified_name,name_status,quality_rating,platform_type,status,messaging_limit_tier,throughput`
→ includes `messaging_limit_tier: "TIER_1K"`, `throughput: {level: "STANDARD"}`. A second mode where the fake
refuses the new fields (code 100) proves the fallback.

### Tests: `dashboard_contacts.py`

1. `list merges buyers, leads and WhatsApp-only numbers, one row per number` (10-digit and 91-prefixed forms).
2. `search, chips and paging`: 250 contacts give three pages of at most 100; search across all pages; a
   forced POST with 101 phones refused.
3. `bulk tag add and remove`; a bad tag refused; a `tag_added` rule fires only for tags added after it was on.
4. `CSV`: right columns, no message text, formula cells escaped, logged.
5. `overview numbers`: the spend estimate matches the billable messages not failed (utility inside the window
   free; a failed marketing send costs nothing), conversations count, best and weakest templates with the
   10-send minimum; quality read from `quality_score.score`.
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
   (SET NULL). **Nothing is deleted by age** (revision 2 dropped the 90-day forget, which was not Deenu's call
   and would have wiped counts and the "messaged us" basis). After 90 quiet days a WhatsApp-only row's profile
   name is cleared; the number, message rows (text already gone) and `wa_inbound` stay.
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
8. **The daily cap counts every business-started template over a rolling 24 hours, by different people**, as
   Meta's messaging limit does (revision 2; the first draft counted only bulk messages per IST day, which let a
   morning broadcast use up Meta's limit before the afternoon reminders). Booking messages are never held;
   bulk rows yield to them and to a reserve for the next 24 hours of queued booking messages. Free replies do
   not count. Default 250, the lowest Meta tier. Broadcasts are spread by day at confirmation, at most 7 days.
9. **Quiet hours (9 PM to 9 AM IST) apply to marketing only.** Utility (booking facts) may go any time.
10. **Test sends go to the test list while it is set, and to a new `whatsapp.my_number` setting after go-live**
    (the list is emptied then). `my_number` is not a way round the test list.
11. **Text headers only; no media headers.** A media header needs an upload handle from Meta's Resumable Upload
    API, which needs the Meta app id and an upload made through that app. Later: add `app_id` to the config, a
    file input on the template form, store the handle, send with a media link. Inbox replies are text only for
    now.
    **Seeing photos people send is a separate, easier job** and does not need the app id: `GET /{media-id}` with
    the Bearer token returns a short-lived URL, fetched with the same token. It is out of scope tonight only for
    time (it needs a private store for the files, size limits and a 30-day purge like the text). Listed under
    "Later" below. Until then incoming media shows as "[a photo]" plus any caption.
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

Decisions added in revision 2, after the critic's review:

18. **Bulk outbox rows never carry a `booking_id`.** Step 4 and the STOP cancel join on it, and would otherwise
    cancel a broadcast to unpaid checkouts before it went. Bulk rows get their own send-time checks instead,
    and keep any booking reference in `params`.
19. **One-person errors in bulk sends do not trip the down streak or email Deenu.** They show on the broadcast or
    rule page. Booking rows keep today's alerting unchanged.
20. **The automatic STOP and START answers obey pause and the test list.** Deenu said pause stops everything.
    The STOP is still recorded; only the courtesy message waits. This changes today's webhook on purpose.
21. **START revives only booking rows.** A broadcast or rule message dropped by STOP stays cancelled.
22. **The dashboard clock follows `rp_now`; the idle sign-out does not.** Security timers stay on real time.
23. **Template status at send time comes from our `wa_templates` table**, kept current by webhooks and sync.
    PAUSED waits an hour at a time; DISABLED, REJECTED or DELETED fail the rest with one alert.
24. **People's resend page is not refactored onto `ui_confirm`.** No gain for Deenu, and it is a tested page.
25. **The offers box becomes an audience filter, not a requirement.** Deenu's rule stays the outer limit; the
    preview shows how many ticked it. The pop-up wording is left alone and flagged (see risks).

Where the plan departs from the critic's suggestion:

- **Cap expiry (critic 4).** The critic offered three fixes: refuse, split, or set `expires_at` by day. The
  plan does both the day spread and a refusal above 7 days, rather than offering an automatic split into
  several broadcasts: one broadcast with a day plan is simpler to follow and to cancel, and each day's rows
  still get their own 48-hour expiry.
- **The cap's reserve (critic 4)** counts only booking rows already queued for the next 24 hours, not a
  forecast of "expected" reminders for a session whose buyers have not paid yet. A forecast would be a guess;
  the queued rows are facts, and new buyers' rows are never held anyway.
- **Paid-since checks (critic 2)** apply to `unpaid` **rule** rows only. An `unpaid` broadcast is a one-off
  that Deenu sends after seeing the names; re-checking payment there would quietly shrink what he confirmed.
  If he wants to skip people who paid meanwhile, the typed-count check already refuses a stale count.
- **Retention (critic 5).** The critic offered dropping the forget or anonymising. The plan does both: no
  deletion, and the profile name is cleared after 90 quiet days. The phone stays, because it is the STOP key.

Later (not tonight):
- Showing photos, voice notes and documents people send (no app id needed; needs private file storage).
- Media template headers (needs the Meta app id and the Resumable Upload API).
- More than one template language.
- Meta's own pricing analytics in place of our estimate.

Risks:

- **Marketing consent.** The checkout pop-up already has a separate, unticked box, "Also tell me about future
  sessions and offers on WhatsApp and email" (`contacts.marketing_optin`). The consent people agreed to covers
  only "seat confirmation, Zoom link and reminders". So sending WhatsApp offers to everyone with a consent
  version, as Deenu decided, goes **against a choice many of those people actively made on the same form**:
  they saw the offers box and left it empty. This is more than stretching consent. It can also hurt the number:
  people who did not expect offers block and report, which lowers quality and can pause the booking templates.
  The build keeps Deenu's rule as the outer limit and gives him the tools to stay inside the box: the
  "Ticked the offers box" filter, the count on every marketing preview, a reworded policy line that matches
  the dashboard, and a health line until he decides. **Before the first marketing broadcast, the pop-up and
  the policy must agree**: either broadcast marketing only to box-tickers, or add a new
  `TG_CONSENT_VERSIONS` entry whose wording mentions occasional WhatsApp offers. The morning summary leads
  with this.
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
- **Database growth.** `wa_messages` keeps rows (without text after 30 days) and nothing is deleted by age. At a
  few thousand messages a month a row is a few hundred bytes without text, so years of history stay small.
- **Worker time with the new checks.** The send-time checks read `wa_templates`, `wa_contacts` and
  `wa_inbound` by indexed keys; the cap numbers are computed once per run, not per row.
- **Existing kit changes named up front.** Phase 1: system template count (`reopen`), the reply email line,
  STOP/START answers now skipped while paused or off the test list. Phase 3: none expected, since booking rows
  keep their behaviour; any check that does change is named in the commit.
