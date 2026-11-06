<?php
/**
 * Called by the browser straight after Razorpay reports a payment.
 *
 * It only proves the payment is genuine, then hands back the community link for
 * the thank-you page. The confirmation email comes from webhook.php, so a buyer
 * who closes the tab still gets everything.
 */
require __DIR__ . '/lib.php';

$config = rp_config();
rp_cors($config);
$input = rp_body();

$orderId   = rp_clean($input['razorpay_order_id'] ?? '', 64);
$paymentId = rp_clean($input['razorpay_payment_id'] ?? '', 64);
$signature = rp_clean($input['razorpay_signature'] ?? '', 128);

$expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $config['key_secret']);
if ($orderId === '' || $paymentId === '' || !hash_equals($expected, $signature)) {
    rp_json(400, ['ok' => false, 'error' => 'invalid_signature']);
}

rp_json(200, ['ok' => true, 'community_url' => $config['community_url']]);
