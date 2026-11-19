<?php
/**
 * Called by the browser straight after Razorpay reports a payment.
 *
 * It only proves the payment is genuine, then hands back the community link for
 * the thank-you page. The confirmation email comes from webhook.php, so a buyer
 * who closes the tab still gets everything.
 *
 * The Razorpay account is shared, so a valid payment is not enough: the order must
 * be for this product. The contact store answers first; Razorpay is asked only when
 * the store can't say. If Razorpay can't be reached either, the link is still given:
 * the buyer has paid, and the risk is small.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/store.php';

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

$ours = tg_known_order($config, $orderId);
if ($ours === null) {
    $order = rp_api_get($config, 'orders/' . rawurlencode($orderId));
    if ($order === null) {
        error_log('verify: could not check the product of ' . $orderId . '; link given');
    } else {
        $ours = (($order['notes']['product'] ?? '') === $config['product']);
    }
}
if ($ours === false) {
    // The thank-you page then points the buyer to their email instead.
    rp_json(403, ['ok' => false, 'error' => 'other_product']);
}

rp_json(200, ['ok' => true, 'community_url' => $config['community_url']]);
