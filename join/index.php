<?php
/**
 * A buyer's personal join link: /join/?t=<token>.
 *
 * Counts the click (a click means "probably attended"), then sends the buyer to
 * Zoom. The Zoom link lives only in the private config, never in a page or email,
 * so it can change without resending anything. Until it is set, the buyer sees a
 * "not open yet" page instead.
 */
require dirname(__DIR__) . '/api/razorpay/lib.php';
require dirname(__DIR__) . '/api/razorpay/store.php';
require dirname(__DIR__) . '/api/razorpay/emails.php';

$config = rp_config();
$token = (string) ($_GET['t'] ?? '');
$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$help = '<p>Need help? Message us on WhatsApp: ' . $h(tg_support_whatsapp($config)) . '</p>';

$booking = preg_match('/^[0-9a-f]{24}$/', $token) ? tg_booking_by_token($config, $token) : false;
if ($booking === false) {
    tg_page(404, 'This link does not work', '<p>Please use the join link in your latest email from us.</p>' . $help);
}
if ($booking !== null) {
    tg_log_join($config, (int) $booking['id']);
}
// $booking is null only when the store is down. Paying buyers must still get in
// during a live session, so a well-formed link is let through then.
$zoom = tg_zoom_url($config);
if ($zoom !== null) {
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('Location: ' . $zoom, true, 302);
    exit;
}
$when = $booking !== null ? tg_session_parts($booking['session_at']) : null;
tg_page(200, 'Your seat is confirmed', '<p>The Zoom room is not open yet.'
    . ($when ? ' Come back to this same link a few minutes before ' . $h($when['time']) . ' IST on ' . $h($when['day']) . '.' : ' Come back to this same link a few minutes before the session.')
    . '</p>' . $help);
