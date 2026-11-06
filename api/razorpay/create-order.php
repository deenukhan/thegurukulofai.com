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

if (time() >= strtotime($config['sales_close_at'])) {
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

$amount = (int) $config['price_paise'] + ($addon ? (int) $config['addon_paise'] : 0);

$notes = [
    'product' => $config['product'],
    'name'    => $name,
    'email'   => $email,
    'phone'   => $phone,
    'addon'   => $addon ? 'yes' : 'no',
];
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

rp_json(200, [
    'ok'       => true,
    'key'      => $config['key_id'],
    'order_id' => $order['id'],
    'amount'   => $amount,
    'phone'    => $phone,
]);
