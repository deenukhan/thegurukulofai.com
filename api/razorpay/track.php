<?php
/**
 * Counts two funnel steps for the dashboard: a visit to the masterclass page and
 * an opened booking pop-up. js/masterclass.js sends one word, "view" or "popup".
 *
 * Only day totals are kept. No names, cookies or addresses are stored.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/store.php';

$config = rp_config();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rp_json(405, ['ok' => false]);
}
// Only the site itself may count. Browsers name the page's origin, or at least its address.
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin === '' || $origin === 'null') {
    $referer = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    $origin = isset($referer['scheme'], $referer['host']) ? $referer['scheme'] . '://' . $referer['host'] : '';
}
if (!in_array($origin, ['https://thegurukulofai.com', 'https://www.thegurukulofai.com'], true)) {
    rp_json(403, ['ok' => false]);
}
rp_rate_limit($config, 40, 600, 'track');

$name = trim((string) file_get_contents('php://input', false, null, 0, 16));
if (!in_array($name, ['view', 'popup'], true)) {
    rp_json(422, ['ok' => false]);
}
tg_count_event($config, $name);
http_response_code(204);
