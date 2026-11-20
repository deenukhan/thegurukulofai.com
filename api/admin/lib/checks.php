<?php
/**
 * What the dashboard reads from outside the database: the private folder's files,
 * Razorpay and Meta. It also works out the health checks shown on Overview and
 * System health. Nothing here sends, changes or deletes anything.
 */

function admin_private(array $config): string
{
    return dirname($config['sent_dir']);
}

/**
 * What happened to one buyer's confirmation email, from the webhook's own records.
 * Returns state (sent, sending, retrying, missing), meta (ok, failed or null) and tries.
 */
function admin_confirmation(array $config, ?string $paymentId): array
{
    if ($paymentId === null || !preg_match('/^[A-Za-z0-9_]+$/', $paymentId)) {
        return ['state' => 'missing', 'meta' => null, 'tries' => 0];
    }
    $tries = (int) @file_get_contents(admin_private($config) . '/razorpay-mailfail/' . $paymentId);
    $marker = $config['sent_dir'] . '/' . $paymentId;
    if (is_file($marker)) {
        $text = (string) @file_get_contents($marker);
        $meta = str_contains($text, 'meta:ok') ? 'ok' : (str_contains($text, 'meta:failed') ? 'failed' : null);
        return ['state' => str_starts_with($text, 'sent') ? 'sent' : 'sending', 'meta' => $meta, 'tries' => $tries];
    }
    return ['state' => $tries > 0 ? 'retrying' : 'missing', 'meta' => null, 'tries' => $tries];
}

function admin_confirmation_pill(array $c): string
{
    return match ($c['state']) {
        'sent'     => ui_pill('Sent', 'good'),
        'sending'  => ui_pill('Sending', 'info'),
        'retrying' => ui_pill('Retrying', 'warn'),
        default    => ui_pill('Not sent', 'bad'),
    };
}

/** The 5-minute task's last run, or null if it never ran. */
function admin_worker_last(array $config): ?array
{
    $data = json_decode((string) @file_get_contents(admin_private($config) . '/razorpay-worker.last'), true);
    return is_array($data) && isset($data['at']) ? $data : null;
}

/** The coupon's use count and limit, or null when there is no coupon. */
function admin_coupon(array $config): ?array
{
    $coupon = is_array($config['coupon'] ?? null) ? $config['coupon'] : [];
    $code = strtoupper(trim((string) ($coupon['code'] ?? '')));
    if ($code === '' || str_starts_with($code, 'PASTE')) {
        return null;
    }
    $used = (int) trim((string) @file_get_contents(admin_private($config) . '/razorpay-coupon/' . hash('sha256', $code)));
    $max = isset($coupon['max_uses']) ? (int) $coupon['max_uses'] : null;
    return ['used' => $used, 'max' => $max, 'seat_paise' => (int) ($coupon['seat_paise'] ?? 0)];
}

/** The last lines of the private error log, newest first. */
function admin_error_log(array $config, int $lines = 15): array
{
    $path = admin_private($config) . '/razorpay-errors.log';
    $size = (int) @filesize($path);
    if ($size === 0) {
        return [];
    }
    $file = @fopen($path, 'r');
    if ($file === false) {
        return [];
    }
    fseek($file, max(0, $size - 16384));
    $text = (string) stream_get_contents($file);
    fclose($file);
    $all = array_values(array_filter(explode("\n", $text), static fn($l) => trim($l) !== ''));
    if ($size > 16384) {
        array_shift($all); // probably cut in half
    }
    $out = [];
    foreach (array_reverse(array_slice($all, -$lines)) as $line) {
        $at = null;
        if (preg_match('/^\[([^\]]+)\]\s*(.*)$/', $line, $m)) {
            $parsed = date_create($m[1]);
            $at = $parsed ? $parsed->getTimestamp() : null;
            $line = $m[2];
        }
        $out[] = ['at' => $at, 'text' => mb_substr(preg_replace('/^PHP\s+/', '', $line), 0, 400)];
    }
    return $out;
}

/** A saved answer from Razorpay or Meta, kept for $ttl seconds so pages stay quick. */
function admin_cached(array $config, string $key, int $ttl, callable $fetch): array
{
    $dir = admin_private($config) . '/razorpay-dash-cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $path = $dir . '/' . hash('sha256', $key) . '.json';
    $saved = json_decode((string) @file_get_contents($path), true);
    if (is_array($saved) && time() - (int) ($saved['at'] ?? 0) < $ttl) {
        return $saved;
    }
    $fresh = ['at' => time()] + $fetch();
    // A failed answer is kept for 5 minutes only, so a fix shows up soon.
    if (empty($fresh['ok'])) {
        $fresh['at'] = time() - $ttl + 300;
    }
    @file_put_contents($path, json_encode($fresh), LOCK_EX);
    return $fresh;
}

/** Razorpay's latest payouts to the bank. They cover the whole account, not just this product. */
function admin_settlements(array $config): array
{
    return admin_cached($config, 'settlements', 900, static function () use ($config): array {
        $list = rp_api_get($config, 'settlements', ['count' => 8]);
        if ($list === null) {
            return ['ok' => false];
        }
        $items = [];
        foreach ($list['items'] ?? [] as $s) {
            $items[] = [
                'at'     => (int) ($s['created_at'] ?? 0),
                'amount' => (int) ($s['amount'] ?? 0),
                'fees'   => (int) ($s['fees'] ?? 0),
                'status' => (string) ($s['status'] ?? ''),
                'utr'    => (string) ($s['utr'] ?? ''),
            ];
        }
        return ['ok' => true, 'items' => $items];
    });
}

/** True when the config has what the Ads page needs to read spend from Meta. */
function admin_meta_ads_ready(array $config): bool
{
    $meta = is_array($config['meta'] ?? null) ? $config['meta'] : [];
    foreach (['ads_token', 'ad_account_id'] as $key) {
        $v = (string) ($meta[$key] ?? '');
        if ($v === '' || str_starts_with($v, 'PASTE')) {
            return false;
        }
    }
    return true;
}

/** Ad spend per campaign between two IST dates (Y-m-d), read from Meta. */
function admin_meta_spend(array $config, string $since, string $until): array
{
    $meta = $config['meta'];
    $account = preg_replace('/\D+/', '', (string) $meta['ad_account_id']);
    return admin_cached($config, 'meta:' . $account . ':' . $since . ':' . $until, 1800, static function () use ($meta, $account, $since, $until): array {
        $url = 'https://graph.facebook.com/' . rawurlencode((string) ($meta['graph_version'] ?? 'v25.0')) . '/act_' . $account . '/insights?'
            . http_build_query([
                'level'        => 'campaign',
                'fields'       => 'campaign_name,spend,impressions,clicks,actions',
                'time_range'   => json_encode(['since' => $since, 'until' => $until]),
                'limit'        => 100,
                'access_token' => (string) $meta['ads_token'],
            ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $body = is_string($response) ? json_decode($response, true) : null;
        if ($status !== 200 || !is_array($body)) {
            $why = is_array($body) ? (string) ($body['error']['message'] ?? '') : '';
            return ['ok' => false, 'error' => $why !== '' ? mb_substr($why, 0, 200) : 'Meta did not answer (HTTP ' . $status . ').'];
        }
        $rows = [];
        foreach ($body['data'] ?? [] as $r) {
            $purchases = 0;
            foreach ($r['actions'] ?? [] as $a) {
                if (in_array($a['action_type'] ?? '', ['purchase', 'offsite_conversion.fb_pixel_purchase'], true)) {
                    $purchases = max($purchases, (int) $a['value']);
                }
            }
            $rows[] = [
                'name'        => (string) ($r['campaign_name'] ?? 'Campaign'),
                'spend'       => (int) round((float) ($r['spend'] ?? 0) * 100),
                'impressions' => (int) ($r['impressions'] ?? 0),
                'clicks'      => (int) ($r['clicks'] ?? 0),
                'purchases'   => $purchases,
            ];
        }
        return ['ok' => true, 'rows' => $rows];
    });
}

/**
 * Everything worth a look, worst first. Each check: level (bad, warn, ok), area,
 * title, detail and the page to open. Used by Overview, System health and the menu badge.
 */
function admin_checks(PDO $pdo, array $config, int $now): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $product = $config['product'];
    $c = [];
    $add = static function (string $level, string $area, string $title, string $detail = '', string $view = '') use (&$c): void {
        $c[] = compact('level', 'area', 'title', 'detail', 'view');
    };

    // ---- Selling ----
    if (str_starts_with((string) $config['key_id'], 'rzp_live_')) {
        $add('ok', 'Selling', 'Razorpay is on the live key', 'Real payments are being taken.');
    } else {
        $add('bad', 'Selling', 'Razorpay is on the test key', 'No real money can come in. Put the live key in the private config.');
    }
    $community = (string) ($config['community_url'] ?? '');
    if ($community === '' || str_contains($community, 'REPLACE')) {
        $add('bad', 'Selling', 'WhatsApp community link is missing', 'Buyers get a placeholder link in their confirmation email. Add it in Settings.', 'settings');
    } else {
        $add('ok', 'Selling', 'WhatsApp community link is set');
    }
    $coupon = admin_coupon($config);
    if ($coupon !== null && $coupon['max'] !== null) {
        $left = $coupon['max'] - $coupon['used'];
        $add($left <= 0 ? 'warn' : 'ok', 'Selling', $left <= 0 ? 'Coupon is used up' : 'Coupon has ' . $left . ' use' . ($left === 1 ? '' : 's') . ' left',
            $coupon['used'] . ' of ' . $coupon['max'] . ' used.');
    }

    // ---- Emails ----
    $recent = rows($pdo, "SELECT payment_id FROM bookings WHERE product = ? AND status = 'paid' AND paid_at >= ? AND payment_id IS NOT NULL",
        [$product, tg_ist($now - 14 * 86400)]);
    $stuck = 0;
    $retrying = 0;
    foreach ($recent as $r) {
        $state = admin_confirmation($config, $r['payment_id'])['state'];
        $stuck += $state === 'missing' ? 1 : 0;
        $retrying += $state === 'retrying' ? 1 : 0;
    }
    if ($retrying > 0) {
        $add('bad', 'Emails', $retrying . ' buyer confirmation' . ($retrying === 1 ? ' is' : 's are') . ' retrying',
            'The mail server refused them. Razorpay keeps retrying for 24 hours.', 'sales');
    }
    if ($stuck > 0) {
        $add('bad', 'Emails', $stuck . ' paid buyer' . ($stuck === 1 ? ' has' : 's have') . ' no confirmation email',
            'Their payment reached us without the webhook. Send them the WhatsApp link by hand.', 'sales');
    }
    if ($retrying === 0 && $stuck === 0) {
        $add('ok', 'Emails', 'Every buyer from the last 14 days got a confirmation');
    }
    $failed = (int) value($pdo, "SELECT COUNT(*) FROM outbox WHERE status IN ('failed', 'expired') AND due_at >= ?", [tg_ist($now - 7 * 86400)]);
    if ($failed > 0) {
        $add('warn', 'Emails', $failed . ' reminder email' . ($failed === 1 ? '' : 's') . ' failed this week', 'See the send log for who and why.', 'emails');
    }
    $smtp = (string) ($config['smtp']['password'] ?? '');
    if ($smtp === '' || str_starts_with($smtp, 'PASTE')) {
        $add('bad', 'Emails', 'Mailbox password is missing', 'Buyer emails cannot be sent without it.');
    }

    // ---- Add-on delivery: recording + Vault promised after each session ----
    $owed = row($pdo, "SELECT COUNT(*) AS n FROM bookings WHERE product = ? AND status = 'paid' AND addon = 1
        AND addon_sent_at IS NULL AND session_at < ?", [$product, tg_ist($now - 3 * 3600)]);
    if ((int) ($owed['n'] ?? 0) > 0) {
        $add('warn', 'Session', (int) $owed['n'] . ' add-on buyer' . ((int) $owed['n'] === 1 ? ' is' : 's are') . ' waiting for the recording and Vault',
            'From past sessions. Send them, then mark them sent on the session page.', 'sessions');
    }

    // ---- Next session ----
    $next = rp_next_session($now);
    $hoursAway = ($next->getTimestamp() - $now) / 3600;
    if (tg_zoom_url($config) === null) {
        $add($hoursAway < 48 ? 'bad' : 'warn', 'Session', 'Zoom link is not set for ' . $next->format('D j M'),
            'Join links show "room not open yet" until you add it in Settings.', 'settings');
    } else {
        $add('ok', 'Session', 'Zoom link is set', 'Change it after each session ends, never during one.');
    }

    // ---- Automatic tasks ----
    $worker = admin_worker_last($config);
    if ($worker === null) {
        $add('warn', 'Automatic tasks', 'The 5-minute email task has not reported yet',
            'Reminders and the 9 AM summary need this cron job in hPanel. It reports after its first run.');
    } elseif ($now - (int) $worker['at'] > 20 * 60) {
        $add('bad', 'Automatic tasks', 'The 5-minute email task stopped', 'Last run ' . ago((int) $worker['at'], $now) . '. Check the cron job in hPanel.');
    } else {
        $add('ok', 'Automatic tasks', 'The 5-minute email task is running', 'Last run ' . ago((int) $worker['at'], $now) . '.');
    }
    $reconcile = row($pdo, "SELECT at FROM audit WHERE actor = 'reconcile' ORDER BY id DESC LIMIT 1");
    if (!$reconcile) {
        $add('warn', 'Automatic tasks', 'The nightly Razorpay check has never run', 'Add its cron job in hPanel.');
    } else {
        $age = $now - (new DateTimeImmutable($reconcile['at'], ist()))->getTimestamp();
        $add($age > 30 * 3600 ? 'warn' : 'ok', 'Automatic tasks', 'The nightly Razorpay check ' . ($age > 30 * 3600 ? 'is late' : 'ran'),
            'Last run ' . ago($now - $age, $now) . '.', 'activity');
    }

    // ---- Tracking ----
    $metaOn = function_exists('rp_meta_enabled') && rp_meta_enabled($config);
    if (!$metaOn) {
        $add('warn', 'Tracking', 'Meta purchase events are off on the server', 'Paste the meta block in the private config.');
    } elseif (trim((string) ($config['meta']['test_event_code'] ?? '')) !== '') {
        $add('warn', 'Tracking', 'Meta test code is still switched on', 'Purchases go to Test events only. Remove test_event_code from the config.');
    } else {
        $add('ok', 'Tracking', 'Meta purchase events are on');
    }
    $lastEvent = value($pdo, 'SELECT MAX(day) FROM events');
    if (!$lastEvent) {
        $add('info', 'Tracking', 'Page visits are not counted yet', 'Counting starts once this update is live.');
    } elseif ($lastEvent < (new DateTimeImmutable('@' . ($now - 2 * 86400)))->setTimezone(ist())->format('Y-m-d')) {
        $add('warn', 'Tracking', 'No page visits counted for 2 days', 'Either nobody visited, or the counter stopped.', 'funnel');
    }

    // ---- Server ----
    $errors = admin_error_log($config, 200);
    $today = array_filter($errors, static fn($e) => $e['at'] !== null && $e['at'] >= $now - 86400);
    if ($today) {
        $add('warn', 'Server', count($today) . ' server error' . (count($today) === 1 ? '' : 's') . ' in the last 24 hours', 'See the latest below.', 'health');
    }

    // ---- Sign-in ----
    if (ADMIN_TWO_STEP) {
        $add('ok', 'Sign-in', 'Two-step sign-in is on');
    } else {
        $add('info', 'Sign-in', 'Two-step sign-in is off', 'The password alone opens the dashboard and changes settings. See Settings to turn it on.', 'settings');
    }

    $rank = ['bad' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3];
    usort($c, static fn($a, $b) => $rank[$a['level']] <=> $rank[$b['level']]);
    return $memo = $c;
}
