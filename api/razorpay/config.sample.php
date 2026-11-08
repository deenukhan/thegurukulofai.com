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

    // No new orders after this moment.
    'sales_close_at' => '2026-11-12T15:00:00+05:30',

    // TODO: dummy link. Replace with the real WhatsApp community invite.
    'community_url' => 'https://chat.whatsapp.com/REPLACE_WITH_REAL_INVITE',

    'event' => [
        'name' => 'AI Video Editing Masterclass',
        'when' => 'Thursday, 12 November 2026, 3 PM to 6 PM IST',
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

    // Marker files that stop a buyer being emailed twice.
    'sent_dir' => __DIR__ . '/razorpay-sent',
];
