<?php
/**
 * "Stop reminders" link from each reminder email: /join/stop/?t=<token>.
 *
 * Opening the link only asks; the button does it. Email scanners open links on
 * their own, so a plain visit must never stop anything. Mail apps' one-click
 * unsubscribe (List-Unsubscribe-Post) is a POST and works straight away.
 * The seat stays booked and the join link keeps working.
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
    tg_page(404, 'This link does not work', '<p>Please use the link in your latest reminder email.</p>' . $help);
}
$when = tg_session_parts($booking['session_at']);
$session = $h($when['day'] . ', ' . $when['span']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!tg_stop_reminders($config, (int) $booking['id'])) {
        tg_page(503, 'Please try again in a few minutes', '<p>We could not save that just now.</p>' . $help);
    }
    tg_page(200, 'Reminders stopped', '<p>You will not get more reminder emails for ' . $session . '.</p>'
        . '<p>Your seat is still booked, and your join link still works.</p>' . $help);
}
if (!empty($booking['reminders_off_at'])) {
    tg_page(200, 'Reminders are already off', '<p>You will not get more reminder emails for ' . $session . '.</p>'
        . '<p>Your seat is still booked, and your join link still works.</p>' . $help);
}
tg_page(200, 'Stop reminder emails?', '<p>This stops reminder emails for ' . $session . '.</p>'
    . '<p>Your seat stays booked, and your join link keeps working.</p>'
    . '<form method="post"><button type="submit">Stop reminders</button></form>');
