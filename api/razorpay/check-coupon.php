<?php
/**
 * Called by the Apply button. Says whether a coupon is valid and what the seat
 * then costs. It makes no order and uses nothing up: a use is only counted in
 * create-order.php, when the payment window opens.
 */
require __DIR__ . '/lib.php';

$config = rp_config();
rp_cors($config);
$input = rp_body();

$code = rp_clean($input['coupon'] ?? '', 1000);
if ($code === '') {
    rp_json(422, ['ok' => false, 'error' => 'invalid_coupon']);
}

// Shares its limit with create-order.php, so guesses cannot be split across the two.
rp_rate_limit($config, 10, 600, 'coupon:');
$claim = rp_coupon_claim($config, $code, false);

rp_json(200, ['ok' => true, 'seat_paise' => $claim['seat_paise']]);
