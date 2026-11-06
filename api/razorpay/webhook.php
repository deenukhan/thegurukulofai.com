<?php
/**
 * Razorpay calls this when money arrives. This, not the browser, is what counts.
 *
 * The Razorpay account also serves other products, so anything that is not this
 * batch is acknowledged and ignored. Razorpay retries on failure, so a marker
 * file makes sure each buyer is emailed once.
 */
require __DIR__ . '/lib.php';

$config = rp_config();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rp_json(405, ['ok' => false]);
}

$raw = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';
$expected = hash_hmac('sha256', $raw, $config['webhook_secret']);
if ($signature === '' || !hash_equals($expected, $signature)) {
    rp_json(400, ['ok' => false, 'error' => 'invalid_signature']);
}

$event = json_decode($raw, true);
if (!is_array($event) || ($event['event'] ?? '') !== 'order.paid') {
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

$dir = $config['sent_dir'];
if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    error_log('razorpay webhook: cannot create ' . $dir);
    rp_json(500, ['ok' => false]);
}
$marker = $dir . '/' . $paymentId;
$handle = @fopen($marker, 'x'); // fails if another delivery already claimed this payment
if ($handle === false) {
    rp_json(200, ['ok' => true, 'ignored' => 'already_sent']);
}
fclose($handle);

$sent = rp_send_emails($config, [
    'name'       => rp_clean($notes['name'] ?? 'there', 80),
    'email'      => rp_clean($notes['email'] ?? ($payment['email'] ?? ''), 120),
    'phone'      => rp_clean($notes['phone'] ?? ($payment['contact'] ?? ''), 20),
    'addon'      => ($notes['addon'] ?? '') === 'yes',
    'amount'     => (int) ($payment['amount'] ?? ($order['amount_paid'] ?? 0)),
    'payment_id' => $paymentId,
]);

if (!$sent) {
    // Let Razorpay retry rather than losing the email.
    unlink($marker);
    rp_json(500, ['ok' => false, 'error' => 'mail_failed']);
}
rp_json(200, ['ok' => true]);
