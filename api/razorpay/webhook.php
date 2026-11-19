<?php
/**
 * Razorpay calls this when money arrives. This, not the browser, is what counts.
 *
 * The Razorpay account also serves other products, so anything that is not this
 * batch is acknowledged and ignored. Razorpay retries on failure, so a marker
 * file makes sure each buyer is emailed once.
 *
 * It also keeps the contact store current: order.paid marks the booking paid,
 * and payment.failed marks a failed try. Neither can hold up the buyer's email.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/store.php';
require __DIR__ . '/emails.php';

$config = rp_config();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rp_json(405, ['ok' => false]);
}

// Finish the work even if Razorpay hangs up, and refuse absurdly large bodies.
ignore_user_abort(true);
$raw = file_get_contents('php://input', false, null, 0, 262144) ?: '';
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';
$expected = hash_hmac('sha256', $raw, $config['webhook_secret']);
if ($signature === '' || !hash_equals($expected, $signature)) {
    rp_json(400, ['ok' => false, 'error' => 'invalid_signature']);
}

$event = json_decode($raw, true);
$type = is_array($event) ? (string) ($event['event'] ?? '') : '';

// A failed try. The payment carries no product note, so only an order already in
// the contact store is touched: other products on this account never match.
if ($type === 'payment.failed') {
    $failed = $event['payload']['payment']['entity'] ?? [];
    $orderId = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($failed['order_id'] ?? ''));
    $reason = rp_clean($failed['error_description'] ?? ($failed['error_reason'] ?? 'Payment failed'), 200);
    $known = tg_mark_failed($config, $orderId, $reason);
    rp_json(200, ['ok' => true, 'recorded' => $known]);
}
if ($type !== 'order.paid') {
    rp_json(200, ['ok' => true, 'ignored' => 'event']);
}

$order   = $event['payload']['order']['entity'] ?? [];
$payment = $event['payload']['payment']['entity'] ?? [];
$notes   = $order['notes'] ?? [];
if (!is_array($notes) || ($notes['product'] ?? '') !== $config['product']) {
    rp_json(200, ['ok' => true, 'ignored' => 'product']);
}

$paymentId = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($payment['id'] ?? ''));
if ($paymentId === '') {
    rp_json(400, ['ok' => false, 'error' => 'no_payment']);
}

// The contact store first: it is quick, it can never fail the webhook, and it
// runs on every delivery, so a retry repairs a booking an earlier run missed.
tg_record_order($config, $order, 'paid', [
    'payment_id' => $paymentId,
    'amount'     => (int) ($payment['amount'] ?? ($order['amount_paid'] ?? 0)),
    'paid_at'    => (int) ($payment['created_at'] ?? time()),
    'email'      => $payment['email'] ?? '',
    'source'     => 'webhook',
]);

$dir = $config['sent_dir'];
if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    error_log('razorpay webhook: cannot create ' . $dir);
    rp_json(500, ['ok' => false]);
}
$marker = $dir . '/' . $paymentId;
$handle = @fopen($marker, 'x'); // fails if another delivery already claimed this payment
if ($handle === false) {
    // An empty marker means an earlier run claimed the payment but never finished.
    clearstatcache(true, $marker);
    $abandoned = @filesize($marker) === 0 && time() - (int) @filemtime($marker) > 300;
    if (!$abandoned) {
        rp_json(200, ['ok' => true, 'ignored' => 'already_sent']);
    }
    touch($marker);
} else {
    fclose($handle);
}

$sale = [
    'name'       => rp_clean($notes['name'] ?? 'there', 80),
    'email'      => rp_clean($notes['email'] ?? ($payment['email'] ?? ''), 120),
    'phone'      => rp_clean($notes['phone'] ?? ($payment['contact'] ?? ''), 20),
    'addon'      => ($notes['addon'] ?? '') === 'yes',
    'coupon'     => ($notes['coupon'] ?? '') === 'yes',
    'session'    => rp_clean($notes['session'] ?? '', 120),
    'amount'     => (int) ($payment['amount'] ?? ($order['amount_paid'] ?? 0)),
    'payment_id' => $paymentId,
];

// The buyer's personal join link, and a calendar invite for the session they paid for.
$token = tg_join_token($config, preg_replace('/[^A-Za-z0-9_]/', '', (string) ($order['id'] ?? '')));
$sale['join_url'] = $token !== null ? tg_join_url($config, $token) : '';
$sessionUnix = (int) ($notes['session_at'] ?? 0);
$sale['ics'] = $sessionUnix > 0 ? tg_ics($config, $sessionUnix, $paymentId, $sale['join_url'] ?: null) : '';

if (!rp_send_buyer_email($config, $sale, $route)) {
    // Razorpay retries a failed delivery for up to 24 hours, so the buyer still
    // gets a trusted email once the mail server answers again.
    unlink($marker);
    $failures = rp_mail_failures($config, $paymentId, true);
    rp_json_early(500, ['ok' => false, 'error' => 'mail_failed']);
    if ($failures === 1) {
        rp_send_delay_note($config, $sale, $route);
    }
    exit;
}
file_put_contents($marker, 'sent');
$tries = rp_mail_failures($config, $paymentId, false) + 1;
@unlink(dirname($config['sent_dir']) . '/razorpay-mailfail/' . $paymentId);

// Razorpay gets its answer first, so a slow note or a slow Meta can never cause a retry.
rp_json_early(200, ['ok' => true]);
rp_send_sale_note($config, $sale, $route, $tries);
if (!rp_meta_enabled($config)) {
    exit;
}
$meta = rp_meta_purchase($config, (string) ($order['id'] ?? ''), $paymentId, [
    'name'   => $notes['name'] ?? '',
    'email'  => $notes['email'] ?? ($payment['email'] ?? ''),
    'phone'  => $notes['phone'] ?? '',
    'amount' => (int) ($payment['amount'] ?? ($order['amount_paid'] ?? 0)),
]);
file_put_contents($marker, 'sent meta:' . ($meta ? 'ok' : 'failed'));
