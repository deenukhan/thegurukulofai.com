<?php
/**
 * "WhatsApp community" button in the seat-confirmed WhatsApp message: /join/community/?t=<token>.
 *
 * Meta does not allow WhatsApp links (chat.whatsapp.com) inside template buttons, so
 * the button points here and this page sends the buyer on to the community. The
 * buyer's join token keeps the invite link out of public pages, as everywhere else.
 * The link lives in the config or the dashboard's Settings, so it can change without
 * resending anything.
 */
require dirname(__DIR__, 2) . '/api/razorpay/lib.php';
require dirname(__DIR__, 2) . '/api/razorpay/store.php';
require dirname(__DIR__, 2) . '/api/razorpay/emails.php';

$config = rp_config();
$token = (string) ($_GET['t'] ?? '');
$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$help = '<p>Need help? Message us on WhatsApp: ' . $h(tg_support_whatsapp($config)) . '</p>';

$booking = preg_match('/^[0-9a-f]{24}$/', $token) ? tg_booking_by_token($config, $token) : false;
if ($booking === null) {
    tg_page(503, 'Please try again in a few minutes', '<p>We could not reach our records just now.</p>' . $help);
}
if ($booking === false) {
    tg_page(404, 'This link does not work', '<p>Please use the link in your confirmation email or WhatsApp message.</p>' . $help);
}
$invite = (string) ($config['community_url'] ?? '');
if (!preg_match('~^https://chat\.whatsapp\.com/[A-Za-z0-9]{10,40}$~', $invite)) {
    tg_page(200, 'The community link is not ready yet', '<p>We will share it with you by email before the session.</p>' . $help);
}
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('Location: ' . $invite, true, 302);
exit;
