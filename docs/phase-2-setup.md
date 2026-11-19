# Phase 2 setup: reminder emails

These are the steps Deenu does by hand. None of them needs a secret in chat or in the repo.

Steps 1 and 2 are safe to do before the code goes live, because the current code
ignores the new setting. Step 3 comes after "push it".

The emails themselves are previewed in `docs/phase-2-email-previews.html`.

---

## 1. Zoom: one recurring Sunday meeting

**Check the plan.** Free Zoom ends meetings after 40 minutes, and the session runs for 3 hours.

1. Sign in at zoom.us. Click your profile picture, then **Account management**, then **Billing**.
2. Under **Current Plans**, it should say **Pro** (or higher). If it says **Basic**, go to
   **Plans & Pricing**, choose **Pro**, and pay monthly.

**Make the meeting.**

1. In the left menu, click **Meetings**, then **Schedule a Meeting**.
2. **Topic:** `AI Video Editing Masterclass`
3. **When:** next Sunday, `3:00 PM`.
4. **Duration:** `3` hours, `0` minutes.
5. **Time Zone:** `(GMT+5:30) India`.
6. Tick **Recurring meeting**. Then set:
   - **Recurrence:** `Weekly`
   - **Repeat every:** `1` week
   - **Occurs on:** `Sun` only
   - **End date:** `After 50 occurrences`. That's about a year. Make a new meeting before it runs out.
7. **Registration:** leave **Required** unticked.
8. **Security:** keep **Passcode** ticked. The invite link carries the passcode, so buyers don't need to type it.
   Untick **Waiting Room**, so 50 people don't have to be let in one at a time.
9. **Video:** Host **On**, Participant **Off**.
10. **Options:** tick **Mute participants upon entry**. Leave **Allow participants to join anytime** unticked.
11. Click **Save**.

**Copy the link.**

1. On the meeting's page, find **Invite Link**. Click **Copy Invitation**, or copy the link itself.
2. You only need the link. It looks like `https://us06web.zoom.us/j/1234567890?pwd=...`.
   Keep it in your password manager. Don't paste it in chat.

## 2. Put the Zoom link in the private config

1. In hPanel, open **Files**, then **File Manager**.
2. Open the `private` folder, the one that holds `razorpay-config.php`.
3. **Make a backup first.** Right-click `razorpay-config.php`, choose **Copy**, and name the copy `razorpay-config.backup.php`.
4. Open `razorpay-config.php` to edit it. Find the line that starts with `'sent_dir'`.
5. Just **above** that line, paste this block. Then replace the link:

   ```php
       'zoom' => [
           'join_url' => 'PASTE THE ZOOM INVITE LINK HERE',
       ],
   ```

   Keep the **single quotes**.
6. Save the file.
7. Open `https://thegurukulofai.com/api/admin/`. If the sign-in box shows, the file is fine.
   If you see an error, put the backup back straight away.

**What the link does.** Buyers never see the Zoom link in an email. Each buyer gets
their own link, `thegurukulofai.com/join/?t=...`. It counts the click and then opens
Zoom. Until a real `https://...zoom.us` link is in the config, that page says
"The Zoom room is not open yet". So the week you change meetings, only this one
line in the config changes. No email is resent.

The 9 AM summary tells you if the Zoom link is missing.

## 3. After the code is live: add the 5-minute job

1. In hPanel, open **Advanced**, then **Cron Jobs**.
2. Choose **Custom**.
3. **Command to run:**

   ```text
   /usr/bin/php /home/u886151741/domains/thegurukulofai.com/public_html/api/razorpay/cron/worker.php
   ```

4. Schedule: minute `*/5`, and `*` for hour, day, month and weekday. Some hPanel versions
   have a **Common settings** menu instead. If yours does, pick **Every 5 minutes**.
5. Click **Save**.

Keep the nightly reconcile job. The two jobs do different things.

**What happens next:**
- The first run sets up the new database table and gives every paid buyer a join link.
- It plans reminders only for times that are still ahead. Buyers who paid before
  today get the reminders still to come, never old ones.
- From 9 AM IST tomorrow, you get a summary email every day.
- If an email fails four times, or its time window closes, you get one email
  that starts with "ACTION:". It names the buyer, so you can send them the link by hand.

**View Output** next to the job stays empty when there was nothing to send. When it
sent something, it shows one line of counts.

## Changing the wording

All reminder emails, the summary and the alert are in `api/razorpay/emails.php`.
The support WhatsApp number is `support_whatsapp` in the private config. After any
wording change, run `php scripts/email-previews.php` and check
`docs/phase-2-email-previews.html`.

## When each email goes

| Email | Goes out | Dropped if not sent by |
|---|---|---|
| Day before | Saturday 3 PM | 5 PM |
| 3 hours before | Sunday 12 noon | 1 PM |
| 15 minutes before | Sunday 2:45 PM | 3 PM |
| We're live | Sunday 3 PM | 3:30 PM |
| Summary to Deenu | Every day 9 AM | 12 noon |

A buyer only gets reminders whose time is after they paid. If the mail server is
down, the worker retries every 5 minutes until the drop time. After that, Deenu
gets the "ACTION" alert.
