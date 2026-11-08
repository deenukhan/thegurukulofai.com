<?php
/**
 * Called when a buyer clicks Pay in the pop-up.
 *
 * The price is set here, never by the browser. The buyer's details go into the
 * order's notes, so every started checkout is visible in the Razorpay dashboard
 * even if the buyer never pays.
 */
require __DIR__ . '/lib.php';

$config = rp_config();
rp_cors($config);
$input = rp_body();

// Optional off switch. Sessions themselves roll forward every week.
if (!empty($config['sales_stop_at']) && time() >= strtotime($config['sales_stop_at'])) {
    rp_json(410, ['ok' => false, 'error' => 'sales_closed']);
}

$name  = rp_clean($input['name'] ?? '', 80);
$email = rp_clean($input['email'] ?? '', 120);
$phone = preg_replace('/\D+/', '', (string) ($input['phone'] ?? ''));
$addon = ($input['addon'] ?? false) === true;

// Indian numbers: accept 10 digits, or 12 with a leading 91.
if (strlen($phone) === 12 && str_starts_with($phone, '91')) {
    $phone = substr($phone, 2);
}
if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[6-9]\d{9}$/', $phone)) {
    rp_json(422, ['ok' => false, 'error' => 'invalid_details']);
}

rp_rate_limit($config, 30, 600);

// The page sends the session it is showing. If sales for that one have just
// closed, tell the buyer before any order exists or a coupon is used.
$session = rp_next_session();
$shown = (int) ($input['session_at'] ?? 0);
if ($shown !== 0 && $shown !== $session->getTimestamp()) {
    rp_json(409, ['ok' => false, 'error' => 'session_moved', 'session_at' => $session->getTimestamp()]);
}

// A coupon lowers the seat only. The add-on always costs its full price.
$seat  = (int) $config['price_paise'];
$code  = rp_clean($input['coupon'] ?? '', 64);
$claim = null;
if ($code !== '') {
    rp_rate_limit($config, 10, 600, 'coupon:');
    $claim = rp_coupon_claim($config, $code);
    $seat  = $claim['seat_paise'];
}
$amount = $seat + ($addon ? (int) $config['addon_paise'] : 0);

$notes = [
    'product' => $config['product'],
    'name'    => $name,
    'email'   => $email,
    'phone'   => $phone,
    'addon'   => $addon ? 'yes' : 'no',
    'session' => rp_session_when($session),
];
if ($claim !== null) {
    $notes['coupon'] = 'yes'; // never the code itself
}
foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'] as $key) {
    $value = rp_clean($input['utm'][$key] ?? '', 100);
    if ($value !== '') {
        $notes[$key] = $value;
    }
}

$order = rp_create_order($config, [
    'amount'   => $amount,
    'currency' => 'INR',
    'receipt'  => 'mc-' . bin2hex(random_bytes(8)),
    'notes'    => $notes,
]);
if ($order === null) {
    rp_json(502, ['ok' => false, 'error' => 'order_failed']);
}
if ($claim !== null) {
    rp_coupon_commit($claim);
}

rp_json(200, [
    'ok'       => true,
    'key'      => $config['key_id'],
    'order_id' => $order['id'],
    'amount'   => $amount,
    'phone'    => $phone,
    'session_at'   => $session->getTimestamp(),
    'session_when' => rp_session_when($session),
]);
