<?php
/**
 * Nightly safety net for the contact store.
 *
 * Reads Razorpay's own list of orders and fills in anything the store missed:
 * a webhook that never arrived, or a database outage during checkout. Orders for
 * other products on the shared Razorpay account are counted and skipped.
 * Running it twice changes nothing.
 *
 * It also deletes unpaid leads older than `keep_unpaid_days` (db block in the
 * private config), unless they ticked the offers box. Leave the setting out to
 * keep everyone.
 *
 * Cron job in hPanel, once a night:
 *   /usr/bin/php /home/u886151741/domains/thegurukulofai.com/public_html/api/razorpay/cron/reconcile.php
 *
 * The very first run backfills everything since the 8 Nov 2026 launch by itself,
 * so no one has to run it by hand. Later runs look back 3 days.
 *
 * Options:
 *   --since=2026-11-08   start date (IST).
 *   --days=3             look back this many days.
 *   --dry-run            count only; write nothing.
 *
 * It prints counts only, never names, emails or numbers, because cron output can be emailed.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/lib.php';
require dirname(__DIR__) . '/store.php';

$config = rp_config();
$opts = getopt('', ['since:', 'days:', 'dry-run']);
$dry = isset($opts['dry-run']);
$ist = new DateTimeZone('Asia/Kolkata');

if (isset($opts['since'])) {
    $since = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $opts['since'], $ist);
    if ($since === false) {
        fwrite(STDERR, "--since must look like 2026-11-08\n");
        exit(2);
    }
    $from = $since->getTimestamp();
} else {
    $from = time() - max(1, (int) ($opts['days'] ?? 3)) * 86400;
}

if (!$dry && tg_db($config) === null) {
    fwrite(STDERR, "The contact store is off or unreachable. Check the db block in the private config.\n");
    exit(1);
}

// First run ever: go back to launch day, so every earlier order lands in the store.
if (!$dry && !isset($opts['since']) && !isset($opts['days'])) {
    $ran = tg_db($config)->query("SELECT 1 FROM audit WHERE actor = 'reconcile' LIMIT 1")->fetchColumn();
    if (!$ran) {
        $from = (new DateTimeImmutable('2026-11-08 00:00:00', $ist))->getTimestamp();
    }
}

// One run at a time, so a slow backfill and the nightly run never overlap.
$lock = fopen(dirname($config['sent_dir']) . '/razorpay-reconcile.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another reconcile run is still going.\n");
    exit(1);
}

$keepDays = (int) ($config['db']['keep_unpaid_days'] ?? 0);
$counts = ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'error' => 0];
$byStatus = [];
$others = [];
$tooOld = 0;
$seen = 0;
$pdo = $dry ? null : tg_db($config);

for ($skip = 0, $page = 0; $page < 200; $page++, $skip += 100) {
    $list = rp_api_get($config, 'orders', ['from' => $from, 'to' => time(), 'count' => 100, 'skip' => $skip]);
    if ($list === null) {
        fwrite(STDERR, "Razorpay did not answer. Nothing after this point was checked.\n");
        $counts['error']++;
        break;
    }
    $items = is_array($list['items'] ?? null) ? $list['items'] : [];
    foreach ($items as $order) {
        $seen++;
        $product = (string) ($order['notes']['product'] ?? '');
        if ($product !== $config['product']) {
            $others[$product === '' ? '(no product note)' : $product] = ($others[$product === '' ? '(no product note)' : $product] ?? 0) + 1;
            continue;
        }

        $status = match ((string) ($order['status'] ?? '')) {
            'paid'      => 'paid',
            'attempted' => 'failed',
            default     => 'started',
        };
        $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

        // Unpaid leads past the keep limit stay deleted.
        if ($status !== 'paid' && $keepDays > 0 && (int) ($order['created_at'] ?? 0) < time() - $keepDays * 86400) {
            $tooOld++;
            continue;
        }
        if ($dry) {
            continue;
        }

        $extra = ['source' => 'reconcile'];
        if ($status === 'failed') {
            $extra['reason'] = 'Tried to pay; payment not completed';
        }
        if ($status === 'paid') {
            // Ask Razorpay for the payment only when the booking still lacks it.
            $have = $pdo->prepare("SELECT 1 FROM bookings WHERE order_id = ? AND status = 'paid' AND payment_id IS NOT NULL");
            $have->execute([(string) $order['id']]);
            if ($have->fetchColumn()) {
                $counts['unchanged']++;
                continue;
            }
            $payments = rp_api_get($config, 'orders/' . rawurlencode((string) $order['id']) . '/payments');
            foreach ($payments['items'] ?? [] as $payment) {
                if (($payment['status'] ?? '') === 'captured') {
                    $extra['payment_id'] = (string) $payment['id'];
                    $extra['amount'] = (int) ($payment['amount'] ?? 0);
                    $extra['paid_at'] = (int) ($payment['created_at'] ?? 0);
                    $extra['email'] = (string) ($payment['email'] ?? '');
                    break;
                }
            }
        }
        $counts[tg_record_order($config, $order, $status, $extra)]++;
    }
    if (count($items) < 100) {
        break;
    }
}

// Retention: unpaid leads who never ticked the offers box, older than the limit.
$deleted = 0;
if (!$dry && $keepDays > 0) {
    try {
        $delete = $pdo->prepare("DELETE FROM contacts
            WHERE created_at < NOW() - INTERVAL ? DAY
              AND (marketing_optin = 0 OR email_unsub_at IS NOT NULL)
              AND bought_1on1 = 0
              AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.contact_id = contacts.id
                              AND (b.status = 'paid' OR b.created_at >= NOW() - INTERVAL ? DAY))");
        $delete->execute([$keepDays, $keepDays]);
        $deleted = $delete->rowCount();
    } catch (Throwable $e) {
        error_log('reconcile: retention delete failed: ' . $e->getMessage());
        $counts['error']++;
    }
}

$sinceText = (new DateTimeImmutable('@' . $from))->setTimezone($ist)->format('j M Y, g:i A');
$lines = [
    ($dry ? 'DRY RUN. ' : '') . "Checked {$seen} Razorpay orders since {$sinceText} IST.",
    'This product: ' . (array_sum($byStatus) ?: 0) . ' (' . (implode(', ', array_map(
        static fn($k, $v) => "{$v} {$k}", array_keys($byStatus), $byStatus
    )) ?: 'none') . ').',
];
if (!$dry) {
    $lines[] = "Store: {$counts['added']} added, {$counts['updated']} updated, {$counts['unchanged']} already right, {$counts['error']} errors.";
}
if ($tooOld > 0) {
    $lines[] = "{$tooOld} unpaid orders were older than {$keepDays} days and were not added back.";
}
if ($deleted > 0) {
    $lines[] = "Deleted {$deleted} unpaid leads older than {$keepDays} days.";
}
if ($others) {
    arsort($others);
    $lines[] = 'Skipped other products: ' . implode(', ', array_map(
        static fn($k, $v) => "{$k} ({$v})", array_keys($others), $others
    )) . '.';
}
echo implode("\n", $lines), "\n";

if (!$dry) {
    tg_audit($config, 'reconcile', 'run', implode(' ', $lines));
}
flock($lock, LOCK_UN);
exit($counts['error'] > 0 ? 1 : 0);
