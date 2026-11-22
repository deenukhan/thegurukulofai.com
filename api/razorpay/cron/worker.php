<?php
/**
 * Sends scheduled messages: buyer reminders by email and WhatsApp, the 9 AM summary
 * for Deenu, and an alert when a message finally fails. All the logic is in ../outbox.php.
 *
 * Cron job in hPanel, every 5 minutes:
 *   /usr/bin/php /home/u886151741/domains/thegurukulofai.com/public_html/api/razorpay/cron/worker.php
 *
 * Prints one line only when something happened, never names or emails.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/lib.php';
require dirname(__DIR__) . '/store.php';
require dirname(__DIR__) . '/emails.php';
require dirname(__DIR__) . '/outbox.php';

$config = rp_config();

// One run at a time. A run still sending when the next one starts is left alone.
$lock = fopen(dirname($config['sent_dir']) . '/razorpay-worker.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$sum = tg_outbox_run($config, rp_now($config));
flock($lock, LOCK_UN);

// The dashboard reads this to tell whether the cron job is still running.
@file_put_contents(dirname($config['sent_dir']) . '/razorpay-worker.last', json_encode(['at' => time()] + $sum), LOCK_EX);

if (isset($sum['error'])) {
    fwrite(STDERR, $sum['error'] . " Check the db block in the private config.\n");
    exit(1);
}
$parts = [];
foreach (['planned', 'sent', 'retrying', 'failed', 'undelivered', 'expired', 'cancelled', 'alerted'] as $key) {
    if ($sum[$key] > 0) {
        $parts[] = $sum[$key] . ' ' . $key;
    }
}
if ($sum['paused']) {
    $parts[] = 'paused: the mail server kept failing';
}
if ($sum['wa_paused']) {
    $parts[] = 'paused: WhatsApp kept failing';
}
if ($parts) {
    echo date('Y-m-d H:i'), ' ', implode(', ', $parts), "\n";
}
exit($sum['failed'] > 0 ? 1 : 0);
