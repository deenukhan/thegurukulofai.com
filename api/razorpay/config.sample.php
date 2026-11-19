<?php
/**
 * Sample only. Do NOT put real keys in this file: this repo is served publicly.
 *
 * On the server, copy this to:
 *   /home/u886151741/domains/thegurukulofai.com/private/razorpay-config.php
 * That folder sits next to public_html, so visitors can never open it.
 */
return [
    // Test keys first (rzp_test_...). Swap to rzp_live_... when going live.
    'key_id'         => 'PASTE_KEY_ID_HERE',
    'key_secret'     => 'PASTE_KEY_SECRET_HERE',
    'webhook_secret' => 'PASTE_WEBHOOK_SECRET_HERE',

    // Prices in paise. The browser cannot change these.
    'price_paise' => 149900,
    'addon_paise' => 49900,

    // Optional. Lowers the seat price only; the add-on stays full price.
    // Leave this out to switch coupons off. Razorpay's minimum is 100 paise.
    // It never expires. To cap it, add 'max_uses' => 100; a new code starts a fresh count.
    'coupon' => [
        'code'       => 'PASTE_A_LONG_RANDOM_CODE_HERE',
        'seat_paise' => 100,
    ],

    // Tags each order, so the webhook ignores payments for other products.
    'product' => 'masterclass-2026-11-12',

    // Optional off switch: no new orders after this moment. Leave it out to keep selling.
    // Session dates roll forward by themselves (rp_next_session in lib.php).
    // 'sales_stop_at' => '2027-01-01T00:00:00+05:30',

    // TODO: dummy link. Replace with the real WhatsApp community invite.
    'community_url' => 'https://chat.whatsapp.com/REPLACE_WITH_REAL_INVITE',

    'event' => [
        'name' => 'AI Video Editing Masterclass',
    ],

    'mail_from'   => 'deenu@thegurukulofai.com',
    'mail_notify' => 'deenu@thegurukulofai.com',

    // Sends through the mail_from mailbox, so emails pass SPF, DKIM and DMARC.
    // The password is that mailbox's own. Leave it empty to use PHP mail() instead.
    'smtp' => [
        'host'     => 'ssl://smtp.hostinger.com',
        'port'     => 465,
        'password' => 'PASTE_MAILBOX_PASSWORD_HERE',
    ],

    // Optional. Meta Conversions API: the server's copy of each Purchase.
    // pixel_id must match PIXEL_ID in js/meta-pixel.js. Leave this block out to switch it off.
    // test_event_code: from Events Manager > Test events, only while testing. Remove it after.
    'meta' => [
        'pixel_id'        => 'PASTE_PIXEL_ID_HERE',
        'access_token'    => 'PASTE_ACCESS_TOKEN_HERE',
        'test_event_code' => '',
        'graph_version'   => 'v25.0',
    ],

    // The contact store: every lead and buyer, in MySQL on this same server.
    // Create the database and user in hPanel > Databases > Management, then paste them here.
    // Leave this block out, or leave a PASTE value, and checkout works exactly as before.
    'db' => [
        'host'     => 'localhost',
        'name'     => 'PASTE_DATABASE_NAME_HERE',     // looks like u886151741_something
        'user'     => 'PASTE_DATABASE_USER_HERE',     // looks like u886151741_something
        'password' => 'PASTE_DATABASE_PASSWORD_HERE',
        // Unpaid leads who did not tick the offers box are deleted after this many days.
        // Must match the privacy policy. Leave it out to keep everyone.
        'keep_unpaid_days' => 90,
    ],

    // The private admin page at /api/admin/. Only the password's hash goes here,
    // never the password. Make the hash on your own computer (see the setup steps).
    'admin' => [
        'password_hash' => 'PASTE_PASSWORD_HASH_HERE',
    ],

    // Reminder emails (Phase 2). The Zoom link lives ONLY here. Buyers get a personal
    // /join/?t=... link that sends them on to it, so it can change at any time.
    // Until a real https://...zoom.us link is pasted, the join page says the room isn't open yet.
    'zoom' => [
        'join_url' => 'PASTE_ZOOM_INVITE_LINK_HERE',
    ],
    // Shown in every buyer email and on the join pages. Never the personal number.
    'support_whatsapp' => '+91 83838 40943',
    // Most emails sent per 5-minute run, and a short pause between them.
    'outbox' => [
        'max_per_run' => 60,
        'pause_ms'    => 200,
    ],

    // Marker files that stop a buyer being emailed twice.
    'sent_dir' => __DIR__ . '/razorpay-sent',
];
