# WhatsApp setup: Meta's WhatsApp Cloud API

These are the steps Deenu does by hand. No token, secret or PIN ever goes in chat or in the repo.
Meta changes its screens often, so a button may have a slightly different name. If a step
doesn't match what you see, send Claude a screenshot with any secret covered.

**The number:** +91 78278 76564, API only (no WhatsApp app on a phone).
**What it sends:** to buyers who agreed in the booking pop-up, the seat confirmation and the four
reminders (day before, 3 hours, 15 minutes, "we're live"). They also still get every email.
**Replies:** this number has no app, so replies land in the dashboard's **Inbox** (WhatsApp > Inbox), where you
answer from the business number itself: free text within 24 hours of their last message, or the approved follow-up
template after that. A copy of each reply is also emailed to deenu@thegurukulofai.com unless you switch that off on
the Inbox page. Message text is kept 30 days, then deleted. STOP and START are handled automatically.
**Your own templates:** write them on **WhatsApp > Templates**. The page checks Meta's rules before you submit,
sends them for review, and shows Meta's answer. Approved ones can be sent from the Inbox once the 24-hour window closes.

**Order:** steps 1 to 5 are done (22 Nov 2026). Step 6 needs the code live ("push it"). Then 7 and 8.

**Where things stand (22 Nov 2026):**
- Business portfolio: **Neuraltech AI** (verified).
- Meta app: **Gurukul Website**, still in Development mode, no webhook yet.
- +91 78278 76564: **Registered**, done in Meta's guided Production setup. Payment method added.
- System user `website-whatsapp`: never-expiring token with only the two WhatsApp permissions.
- Private config: the `whatsapp` block is in, with `enabled` false and your number in `test_numbers`.

**Cost:** each message is a "utility" message. Meta's India rate was about ₹0.115 to ₹0.145
per delivered message in late 2026 (check WhatsApp Manager > Insights for the current rate),
plus 18% GST. Five messages per buyer comes to under ₹1 per buyer.

---

## ⚠️ First: portfolio enquiries

The ads portfolio page's WhatsApp buttons open a chat with +91 78278 76564. Since that number
moved to the API, those messages reach no phone. **Until step 6 is done, they are lost.**
After step 6 they arrive in your email. Deenu chose (21 Nov 2026) to keep the buttons on this
number. +91 83838 40943 stays the "message us" number shown in emails and on the join pages.

---

## 1. Check the number in WhatsApp Manager ✅ done

1. Open **business.facebook.com**. Top left, make sure **Neuraltech AI** is selected.
2. Click **All tools** (the grid icon), then **WhatsApp Manager**.
3. In the left menu, click **Account tools**, then **Phone numbers**.
4. Find **+91 78278 76564**. Check two things:
   - **Status:** it should say **Connected**. "Pending" is fine for now: step 7 fixes it.
   - **Display name:** it should say **Approved**. "In review" means wait; Meta emails you.
     The name is **thegurukulofai** (Deenu's choice, 21 Nov 2026). If Meta ever rejects it,
     the dashboard's health page says so; then edit it here under **Display name**.
5. Tell Claude what the two say (no screenshot of anything else needed).

## 2. Add a payment method ✅ done

Meta won't send business messages without one.

1. Still in **WhatsApp Manager**, open **Overview** in the left menu.
2. Look for **Add payment method** (it may also be under **Account tools > Payment settings**).
3. Add a card. Currency **INR**. Meta charges it monthly for messages sent.

## 3. Make the Meta app and copy two IDs ✅ done

1. Open **developers.facebook.com/apps** and click **Create app**.
2. **App name:** `Gurukul Website`. **Contact email:** deenu@thegurukulofai.com. Click **Next**.
3. **Use case:** pick **Connect with customers through WhatsApp**. Click **Next**.
4. **Business portfolio:** choose **Neuraltech AI**. Click **Next**, then **Create app**.
5. In the app's left menu, open **WhatsApp**, then **API Setup**.
6. If it asks for a WhatsApp Business Account, choose the one that holds +91 78278 76564.
7. In the **From** dropdown, choose **+91 78278 76564**. Under it you'll see:
   - **Phone number ID** (a long number)
   - **WhatsApp Business Account ID** (another long number; Meta's newer screens call it **Messaging Account ID**)
   Keep this tab open. You'll paste both in step 5.
   (Ignore the "temporary access token" on this page. It expires in a day. Step 4 makes a lasting one.)

## 4. Make the lasting token (a "system user") ✅ done

1. Open **business.facebook.com/settings**. Check **Neuraltech AI** is selected.
2. Left menu: **Users**, then **System users**. Click **Add**.
3. **Name:** `website-whatsapp`. **Role:** **Admin**. Click **Create system user**.
4. With it selected, click **Assign assets**.
   - Under **Apps**, pick **Gurukul Website**, switch on **Full control**.
   - Under **WhatsApp accounts**, pick your WhatsApp Business Account, switch on **Full control**.
   - Click **Assign**.
5. Click **Generate token** (or **Generate new token**).
   - **App:** Gurukul Website.
   - **Token expiration:** **Never**.
   - **Permissions:** tick **whatsapp_business_messaging** and **whatsapp_business_management**.
     Leave everything else unticked (for example manage_app_solution and whatsapp_business_manage_events).
   - Click **Generate token**.
6. Meta shows the token **once**. Copy it straight into the config in step 5. Don't paste it anywhere else.

## 5. Paste the values into the private config ✅ done

1. On your Mac, open **Terminal** and run this. It prints a random text for the webhook check:
   ```
   openssl rand -hex 24
   ```
   Copy what it prints.
2. In **hPanel**, open **File Manager**. Go to `domains/thegurukulofai.com/private/` and open
   `razorpay-config.php`.
3. Just above the line `'sent_dir' => ...`, paste this block, then fill each `PASTE_` value:
   ```php
   'whatsapp' => [
       'enabled'         => false,
       'phone_number_id' => 'PASTE_PHONE_NUMBER_ID_HERE',
       'waba_id'         => 'PASTE_WHATSAPP_BUSINESS_ACCOUNT_ID_HERE',
       'access_token'    => 'PASTE_SYSTEM_USER_TOKEN_HERE',
       'app_secret'      => 'PASTE_APP_SECRET_HERE',
       'verify_token'    => 'PASTE_THE_RANDOM_TEXT_FROM_TERMINAL',
       'api_version'     => 'v25.0',
       'language'        => 'en',
       'test_numbers'    => ['91PASTE_YOUR_OWN_10_DIGITS'],
       'forward_replies' => true,
   ],
   ```
   - **phone_number_id** and **waba_id**: from step 3.
   - **access_token**: from step 4.
   - **app_secret**: in the Meta app, left menu **App settings > Basic**, next to **App secret** click **Show**.
   - **test_numbers**: your own WhatsApp number, with 91 in front and no spaces, for example `'919876543210'`.
     While this list has a number in it, **only** that number gets messages.
   - Leave **enabled** as `false` for now.
4. Click **Save**.

Nothing changes on the site yet: the current live code ignores this block.

## 6. After "push it": connect the webhook

1. In the Meta app, left menu **WhatsApp**, then **Configuration**.
2. Under **Webhook**, click **Edit**.
   - **Callback URL:** `https://thegurukulofai.com/api/whatsapp/webhook.php`
   - **Verify token:** the same random text you pasted as `verify_token`.
   - Click **Verify and save**. A green tick means the site answered.
3. Under **Webhook fields**, click **Manage**. Subscribe to:
   - **messages**
   - **message_template_status_update**
   - **template_category_update** (if it's listed)
   - **message_template_quality_update** (if it's listed): the quality of templates you write on the dashboard
4. **Switch on Subscribe webhooks.** In the app's left menu open **WhatsApp**, then **Production setup**
   (it may be called **API Setup**). Find **Subscribe webhooks** and switch it **on**. Without it Meta
   sends nothing to the site, even with the Callback URL saved.
5. **Publish the app.** In **App settings > Basic**, set **Privacy policy URL** to
   `https://thegurukulofai.com/privacy-policy.html`, pick a **Category** (Education), and **Save**.
   Then switch **App mode** at the top from **Development** to **Live** (it may be a **Publish** button
   in the left menu). Meta doesn't send real messages' news to an app in Development mode.

## 7. Check it in the dashboard

1. Open `https://thegurukulofai.com/api/admin/`, sign in, then **Messages > WhatsApp**.
2. **Connection** should show the number and its display name, **thegurukulofai**.
   - If it shows a red error, the token or an ID is wrong. Re-check step 5.
   - If **Webhook** says **Not connected**, click **Connect webhook**. It does the same as the
     Subscribe webhooks switch in step 6.
   - The number is already registered (you did it in Meta's Production setup), so the **Register number**
     box should not appear. If it ever says the number is **not on the Cloud API yet**, choose a 6-digit PIN,
     type it in that box and click **Register number**. Save the PIN in your password manager.
3. Under **Templates**, click **Submit to Meta**. All five go for review as "utility".
   Meta usually answers in minutes, sometimes a day. You get an email if one is rejected.

## 8. Test on your own phone, then go live

1. When all five templates say **Approved**, open `razorpay-config.php` again and change
   `'enabled' => false` to `'enabled' => true`. Save.
2. Dashboard > **Messages > WhatsApp > Send a test**. Pick **Seat confirmed**, click **Send test message**.
   It should arrive on your phone from **thegurukulofai** within a minute.
3. On your phone, reply `hello`. Within a minute an email "WhatsApp from …" should reach you.
4. Reply `STOP`. You should get "Done. You won't get any more WhatsApp messages…". Then reply `START`.
5. Check **Messages > Send log** with **WhatsApp** selected: your test should show **Read**.
6. Tell Claude what happened. When you're happy, change `'test_numbers'` to `[]` (empty) and save.
   From then on every buyer who agreed gets the WhatsApp messages too.

**Off switch:** Dashboard > **Settings > WhatsApp messages > Paused**. Emails keep going.

---

## What happens when

| Moment | WhatsApp | Email |
|---|---|---|
| Within 5 minutes of paying | Seat confirmed, with join link and community link | Confirmation (sent at once by the webhook) |
| 24 hours before | Day-before reminder | Day-before reminder |
| 3 hours before | 3-hour reminder | 3-hour reminder |
| 15 minutes before | 15-minute reminder | 15-minute reminder |
| Start time | "We're live" | "We're live" |

- Only buyers who saw the pop-up's consent wording get WhatsApp (bookings from 19 Nov 2026 on).
  Earlier buyers get emails only.
- Anyone who replies STOP gets no more WhatsApp messages; START turns them back on.
  Clicking "no more reminders" in an email stops both.
- A buyer who paid before WhatsApp was switched on gets the reminders still ahead, but no late confirmation.
- A WhatsApp message is never sent after its window (for example, the 15-minute one never arrives at 3:20).
