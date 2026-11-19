<?php
/**
 * Shared helpers for the masterclass checkout.
 *
 * Secrets live OUTSIDE public_html, because everything in this repo is served
 * by the website. See config.sample.php for the file to create on the server.
 */

function rp_config(): array
{
    // TGAI_CONFIG lets local tests point at a throwaway config.
    $path = getenv('TGAI_CONFIG') ?: dirname(__DIR__, 3) . '/private/razorpay-config.php';
    if (!is_file($path)) {
        rp_json(503, ['ok' => false, 'error' => 'not_configured']);
    }
    // Never show PHP errors to visitors; keep them in a private log instead.
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', dirname($path) . '/razorpay-errors.log');
    $config = require $path;
    if (!is_array($config) || empty($config['key_id']) || empty($config['key_secret'])) {
        rp_json(503, ['ok' => false, 'error' => 'not_configured']);
    }
    return $config;
}

function rp_json(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    // From cron, an error must show as a failed run, not a quiet success.
    exit(PHP_SAPI === 'cli' && $status >= 400 ? 1 : 0);
}

/** Browser requests only. Local preview is allowed in test mode only. */
function rp_cors(array $config): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = ['https://thegurukulofai.com', 'https://www.thegurukulofai.com'];
    if (str_starts_with($config['key_id'], 'rzp_test_')) {
        $allowed[] = 'http://localhost:8080';
    }
    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Vary: Origin');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        rp_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
    }
    if ($origin !== '' && !in_array($origin, $allowed, true)) {
        rp_json(403, ['ok' => false, 'error' => 'origin_not_allowed']);
    }
}

/**
 * Stops one address from creating orders in bulk. The limit is generous because
 * many Indian mobile users share one address. If the counter cannot be written,
 * the buyer is let through: a sale matters more than the limit.
 */
function rp_rate_limit(array $config, int $max, int $seconds, string $bucket = ''): void
{
    $dir = dirname($config['sent_dir']) . '/razorpay-rate';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return;
    }
    $path = $dir . '/' . hash('sha256', $bucket . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $file = @fopen($path, 'c+');
    if ($file === false || !flock($file, LOCK_EX)) {
        return;
    }
    $now = time();
    [$start, $count] = array_map('intval', explode(' ', trim((string) stream_get_contents($file)) . ' 0 0'));
    if ($now - $start >= $seconds) {
        [$start, $count] = [$now, 0];
    }
    $count++;
    rewind($file);
    ftruncate($file, 0);
    fwrite($file, $start . ' ' . $count);
    flock($file, LOCK_UN);
    fclose($file);

    if (random_int(1, 100) === 1) {
        foreach (glob($dir . '/*') ?: [] as $old) {
            if ($now - (int) @filemtime($old) > 3600) {
                @unlink($old);
            }
        }
    }
    if ($count > $max) {
        rp_json(429, ['ok' => false, 'error' => 'too_many_attempts']);
    }
}

/**
 * Checks a coupon. The code lives only in the private config and never expires.
 * With max_uses set, a lock is held until the order exists so uses cannot race
 * past the cap, and a broken counter refuses the coupon. Without max_uses there
 * is no limit. Every failure gives the same answer. With $reserve false it only
 * checks, for the Apply button, and holds nothing.
 */
function rp_coupon_claim(array $config, string $code, bool $reserve = true): array
{
    $coupon = is_array($config['coupon'] ?? null) ? $config['coupon'] : [];
    $real   = strtoupper(trim((string) ($coupon['code'] ?? '')));
    $max    = isset($coupon['max_uses']) ? (int) $coupon['max_uses'] : null;
    $usable = $real !== '' && !str_starts_with($real, 'PASTE') && ($max === null || $max > 0);
    if (!$usable || !hash_equals($real, strtoupper(trim($code)))) {
        rp_json(422, ['ok' => false, 'error' => 'invalid_coupon']);
    }

    $seat = min((int) $config['price_paise'], max(100, (int) ($coupon['seat_paise'] ?? $config['price_paise'])));
    if ($max === null) {
        return ['seat_paise' => $seat, 'file' => null, 'used' => 0];
    }

    // Named after the code, so a new code starts with a fresh count.
    $dir  = dirname($config['sent_dir']) . '/razorpay-coupon';
    $file = (is_dir($dir) || @mkdir($dir, 0700, true) || is_dir($dir))
        ? @fopen($dir . '/' . hash('sha256', $real), 'c+') : false;
    if ($file === false || !flock($file, LOCK_EX)) {
        error_log('razorpay coupon: cannot open the use counter');
        rp_json(422, ['ok' => false, 'error' => 'invalid_coupon']);
    }
    $used = (int) trim((string) stream_get_contents($file));
    if ($used >= $max) {
        rp_json(422, ['ok' => false, 'error' => 'invalid_coupon']);
    }

    if (!$reserve) {
        flock($file, LOCK_UN);
        fclose($file);
        return ['seat_paise' => $seat, 'file' => null, 'used' => $used];
    }
    return ['seat_paise' => $seat, 'file' => $file, 'used' => $used];
}

/** Counts the use once the order exists, so a Razorpay failure does not burn one. */
function rp_coupon_commit(array $claim): void
{
    if ($claim['file'] === null) {
        return;
    }
    rewind($claim['file']);
    ftruncate($claim['file'], 0);
    fwrite($claim['file'], (string) ($claim['used'] + 1));
    fflush($claim['file']);
    flock($claim['file'], LOCK_UN);
    fclose($claim['file']);
}

/**
 * The current time. A throwaway test config may set `test_now` to move the clock,
 * but only with test keys, so the live site always uses the real time.
 */
function rp_now(array $config): int
{
    if (isset($config['test_now']) && str_starts_with((string) ($config['key_id'] ?? ''), 'rzp_test_')) {
        return (int) $config['test_now'];
    }
    return time();
}

/**
 * The session a buyer who pays now will attend: every Sunday at 3 PM IST, from
 * Sunday 22 Nov 2026. Sales for a session close 15 minutes before it starts.
 * The browser shows the same rule (js/masterclass.js), but this answer decides.
 */
function rp_next_session(?int $now = null): DateTimeImmutable
{
    $now ??= time();
    $cutoff = 15 * 60;
    $tz = new DateTimeZone('Asia/Kolkata');

    $first = new DateTimeImmutable('2026-11-22 15:00:00', $tz);
    if ($now < $first->getTimestamp() - $cutoff) {
        return $first;
    }
    $next = new DateTimeImmutable('2026-11-29 15:00:00', $tz);
    while ($now >= $next->getTimestamp() - $cutoff) {
        $next = $next->modify('+7 days');
    }
    return $next;
}

function rp_session_when(DateTimeImmutable $session): string
{
    return $session->format('l, j F Y') . ', 3 PM to 6 PM IST';
}

function rp_body(): array
{
    $raw = file_get_contents('php://input', false, null, 0, 8192);
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        rp_json(400, ['ok' => false, 'error' => 'bad_request']);
    }
    return $data;
}

/** One line of plain text: no control characters, so it is safe in email headers. */
function rp_clean($value, int $max): string
{
    $value = is_scalar($value) ? (string) $value : '';
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
    return trim(mb_substr($value, 0, $max));
}

function rp_api_base(array $config): string
{
    return rtrim($config['api_base'] ?? 'https://api.razorpay.com/v1', '/');
}

/** Creates a Razorpay order. Returns the decoded order, or null if Razorpay failed. */
function rp_create_order(array $config, array $order): ?array
{
    $ch = curl_init(rp_api_base($config) . '/orders');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($order),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_USERPWD        => $config['key_id'] . ':' . $config['key_secret'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    if ($response === false || $status < 200 || $status >= 300) {
        error_log('razorpay order failed: HTTP ' . $status);
        return null;
    }
    $decoded = json_decode($response, true);
    return is_array($decoded) && !empty($decoded['id']) ? $decoded : null;
}

/** Reads from Razorpay's API, for example orders/order_X. Returns the decoded body, or null. */
function rp_api_get(array $config, string $path, array $query = []): ?array
{
    $url = rp_api_base($config) . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_USERPWD        => $config['key_id'] . ':' . $config['key_secret'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($response === false || $status < 200 || $status >= 300) {
        error_log('razorpay GET ' . strtok($path, '?') . ' failed: HTTP ' . $status);
        return null;
    }
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Sends one plain-text email. It goes through the mailbox when the config has a
 * password, because PHP mail() fails DMARC here and risks the spam folder.
 * mail() is the backup only for notes to Deenu ($fallback). Buyer emails pass
 * false: mail() claims success even when the email ends up binned.
 *
 * $extra may add 'headers' (lines such as List-Unsubscribe) and 'attachments'
 * (each ['name' => ..., 'type' => ..., 'content' => ...]).
 */
function rp_mail(array $config, string $to, string $subject, string $body, ?string &$route = null, bool $fallback = true, array $extra = []): bool
{
    $from = $config['mail_from'];
    $subject = mb_encode_mimeheader($subject, 'UTF-8', 'B');
    $headers = array_merge([
        'MIME-Version: 1.0',
        'From: The Gurukul of AI <' . $from . '>',
        'Reply-To: ' . $from,
    ], array_map(static fn($h) => rp_clean($h, 900), $extra['headers'] ?? []));

    $attachments = $extra['attachments'] ?? [];
    if ($attachments) {
        $boundary = 'tg-' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $raw = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($body) . "\r\n";
        foreach ($attachments as $file) {
            $name = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $file['name']);
            $raw .= "--{$boundary}\r\nContent-Type: {$file['type']}; name=\"{$name}\"\r\n"
                . "Content-Disposition: attachment; filename=\"{$name}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . rtrim(chunk_split(base64_encode((string) $file['content']), 76, "\r\n")) . "\r\n";
        }
        $raw .= "--{$boundary}--";
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: quoted-printable';
        $raw = quoted_printable_encode($body);
    }

    $route = 'PHP mail(), no SMTP password set';
    if (!empty($config['smtp']['password'])) {
        $failure = rp_smtp_send($config['smtp'], $from, $to, $subject, $raw, $headers);
        if ($failure === null) {
            $route = 'SMTP';
            return true;
        }
        if (!$fallback) {
            // mail() reports success even when Gmail later bins or refuses the email,
            // so a buyer email that misses SMTP is treated as not sent.
            $route = 'not sent, SMTP failed: ' . $failure;
            return false;
        }
        $route = 'PHP mail(), because SMTP failed: ' . $failure;
    }
    return mail($to, $subject, $raw, implode("\r\n", $headers), '-f' . $from);
}

/**
 * Talks to the mail server directly and returns why it failed, or null if the mail was accepted.
 * Short timeouts, because Razorpay gives a webhook 5 seconds.
 */
function rp_smtp_send(array $smtp, string $from, string $to, string $subject, string $raw, array $headers): ?string
{
    $socket = @stream_socket_client($smtp['host'] . ':' . $smtp['port'], $errno, $error, 4);
    if ($socket === false) {
        return 'connect: ' . ($error !== '' ? $error : 'TLS or network error');
    }
    stream_set_timeout($socket, 4);

    $domain = substr(strrchr($from, '@'), 1);
    $message = array_merge(
        [
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'To: ' . $to,
            'Subject: ' . $subject,
        ],
        $headers,
        ['', $raw]
    );
    $data = preg_replace('/^\./m', '..', implode("\r\n", $message));

    $steps = [
        ['greeting', null, 220],
        ['ehlo', 'EHLO ' . $domain, 250],
        ['auth', 'AUTH LOGIN', 334],
        ['username', base64_encode($from), 334],
        ['password', base64_encode($smtp['password']), 235],
        ['mail from', 'MAIL FROM:<' . $from . '>', 250],
        ['rcpt to', 'RCPT TO:<' . $to . '>', 250],
        ['data', 'DATA', 354],
        ['message', $data . "\r\n.", 250],
    ];
    foreach ($steps as [$name, $line, $expected]) {
        if ($line !== null) {
            fwrite($socket, $line . "\r\n");
        }
        $code = rp_smtp_reply($socket);
        if ($code !== $expected) {
            fclose($socket);
            return $name . ': got ' . $code;
        }
    }
    fwrite($socket, "QUIT\r\n");
    fclose($socket);
    return null;
}

/** Reads one server reply, which can span several lines, and returns its code. */
function rp_smtp_reply($socket): int
{
    while (($line = fgets($socket, 1024)) !== false) {
        if (($line[3] ?? ' ') !== '-') {
            return (int) substr($line, 0, 3);
        }
    }
    return 0;
}

/**
 * Sends the buyer's confirmation. True only if the mail server accepted it.
 * There is no mail() backup here: Razorpay retries the webhook instead.
 */
function rp_send_buyer_email(array $config, array $sale, ?string &$route = null): bool
{
    $mail = rp_buyer_email($config, $sale);
    return rp_mail($config, $sale['email'], $mail['subject'], $mail['body'], $route, false, $mail['extra']);
}

/** The buyer's confirmation: subject, body and the calendar invite, without sending it. */
function rp_buyer_email(array $config, array $sale): array
{
    $event = $config['event'];
    $lines = [
        'Hi ' . $sale['name'] . ',',
        '',
        'You are in. Your seat for the ' . $event['name'] . ' is confirmed.',
        '',
        'When: ' . ($sale['session'] !== '' ? $sale['session'] : ($event['when'] ?? 'We will confirm the date on WhatsApp')),
        'Where: Live on Zoom',
        '',
        'What to do now:',
        '1. Join the WhatsApp community: ' . $config['community_url'],
        '   We will share the Zoom link and reminders there.',
        '2. Keep a laptop ready, and a paid Claude plan (Pro or higher).',
        '',
    ];
    if (!empty($sale['join_url'])) {
        // The join link carries the Zoom room now; the community is for updates.
        $lines[9] = '   We share updates there too.';
        array_splice($lines, 6, 0, [
            '',
            'Your personal join link: ' . $sale['join_url'],
            'It opens the Zoom room on the day. Please keep it to yourself.',
        ]);
    }
    if ($sale['addon']) {
        $lines[] = 'You also added the recording + The AI Editor’s Vault. We will send them after the session.';
        $lines[] = '';
    }
    $lines[] = 'Amount paid: Rs ' . number_format($sale['amount'] / 100, 2);
    $lines[] = 'Payment ID: ' . $sale['payment_id'];
    $lines[] = '';
    if (!empty($sale['ics'])) {
        $lines[] = 'The calendar invite is attached. Open it to add the session to your calendar.';
        $lines[] = '';
    }
    $lines[] = 'Questions? Reply to this email, or message us on WhatsApp: ' . ($config['support_whatsapp'] ?? '+91 83838 40943');
    $lines[] = '';
    $lines[] = 'Deenu Khan';
    $lines[] = 'The Gurukul of AI';

    $subject = 'You are in: ' . $event['name'];
    $extra = empty($sale['ics']) ? [] : ['attachments' => [
        ['name' => 'masterclass.ics', 'type' => 'text/calendar; charset=UTF-8; method=PUBLISH', 'content' => $sale['ics']],
    ]];
    return ['subject' => $subject, 'body' => implode("\r\n", $lines), 'extra' => $extra];
}

/** The buyer's details, as they appear in every note to Deenu. */
function rp_sale_lines(array $sale): array
{
    return [
        'Name: ' . $sale['name'],
        'Email: ' . $sale['email'],
        'Phone: ' . $sale['phone'],
        'Add-on: ' . ($sale['addon'] ? 'Yes' : 'No'),
        'Coupon: ' . ($sale['coupon'] ? 'Yes' : 'No'),
        'Session: ' . ($sale['session'] !== '' ? $sale['session'] : 'Not recorded'),
        'Amount: Rs ' . number_format($sale['amount'] / 100, 2),
        'Payment ID: ' . $sale['payment_id'],
    ];
}

/** Tells Deenu about a sale whose buyer email went out. $tries counts the deliveries it took. */
function rp_send_sale_note(array $config, array $sale, string $route, int $tries): void
{
    $note = array_merge(['New masterclass seat'], rp_sale_lines($sale), [
        'Buyer email sent: Yes' . ($tries > 1 ? ', on try ' . $tries . ' (the first tries failed)' : ''),
        'Sent through: ' . $route,
    ]);
    rp_mail($config, $config['mail_notify'], 'New seat: ' . $sale['name'], implode("\r\n", $note));
}

/** Warns Deenu once that a buyer's email is late and Razorpay will retry it. */
function rp_send_delay_note(array $config, array $sale, string $route): void
{
    $note = array_merge([
        'A buyer paid, but their confirmation email has NOT gone out yet.',
        '',
        'Razorpay will retry automatically for up to 24 hours. When it works,',
        'you will get the usual "New seat" email for this buyer.',
        'If that has not arrived within an hour, send them the WhatsApp link by hand.',
        '',
    ], rp_sale_lines($sale), [
        'Reason: ' . $route,
    ]);
    rp_mail($config, $config['mail_notify'], 'ACTION: email not sent yet to ' . $sale['name'], implode("\r\n", $note));
}

/** Counts failed deliveries for a payment, so the warning goes out once. Returns the new count. */
function rp_mail_failures(array $config, string $paymentId, bool $add): int
{
    $dir = dirname($config['sent_dir']) . '/razorpay-mailfail';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $file = $dir . '/' . $paymentId;
    $count = (int) @file_get_contents($file);
    if ($add) {
        @file_put_contents($file, (string) ++$count, LOCK_EX);
    }
    return $count;
}

/* ---------- Meta Conversions API ----------
 * The server's copy of each Purchase. The browser Pixel (js/meta-pixel.js) sends
 * the same event with the same ID, and Meta keeps one. This copy still arrives
 * when an ad blocker stops the Pixel. Nothing here may block a sale or an email.
 */

function rp_meta_enabled(array $config): bool
{
    $meta = $config['meta'] ?? null;
    if (!is_array($meta)) {
        return false;
    }
    foreach (['pixel_id', 'access_token'] as $key) {
        $value = (string) ($meta[$key] ?? '');
        if ($value === '' || str_starts_with($value, 'PASTE')) {
            return false;
        }
    }
    return true;
}

function rp_meta_dir(array $config): string
{
    return dirname($config['sent_dir']) . '/razorpay-meta';
}

/**
 * Keeps the buyer's ad click details for this order until Razorpay confirms payment.
 * The webhook comes from Razorpay, not the buyer, so these can't be read then.
 */
function rp_meta_remember(array $config, string $orderId, string $fbclid): void
{
    $orderId = preg_replace('/[^A-Za-z0-9_]/', '', $orderId);
    if ($orderId === '' || !rp_meta_enabled($config)) {
        return;
    }
    $dir = rp_meta_dir($config);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return;
    }

    $cookie = static fn(string $name): string => preg_match('/^fb\.\d\.\d+\.[A-Za-z0-9_.\-]+$/', (string) ($_COOKIE[$name] ?? ''))
        ? substr((string) $_COOKIE[$name], 0, 500) : '';
    $fbc = $cookie('_fbc');
    if ($fbc === '' && preg_match('/^[A-Za-z0-9_\-]+$/', $fbclid)) {
        $fbc = 'fb.1.' . (int) (microtime(true) * 1000) . '.' . $fbclid;
    }

    // Only a page on this site counts as where the sale happened. No query string.
    $url = 'https://thegurukulofai.com/masterclass/';
    $ref = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    if (in_array($ref['host'] ?? '', ['thegurukulofai.com', 'www.thegurukulofai.com'], true) && !empty($ref['path'])) {
        $url = 'https://thegurukulofai.com' . preg_replace('#[^A-Za-z0-9/_\-.]#', '', $ref['path']);
    }

    @file_put_contents($dir . '/' . $orderId, json_encode([
        'fbp' => $cookie('_fbp'),
        'fbc' => $fbc,
        'ip'  => rp_clean($_SERVER['REMOTE_ADDR'] ?? '', 64),
        'ua'  => rp_clean($_SERVER['HTTP_USER_AGENT'] ?? '', 500),
        'url' => $url,
    ]), LOCK_EX);
    @chmod($dir . '/' . $orderId, 0600);

    // Unpaid checkouts leave files behind; clear anything older than a week.
    if (random_int(1, 50) === 1) {
        foreach (glob($dir . '/*') ?: [] as $old) {
            if (time() - (int) @filemtime($old) > 7 * 86400) {
                @unlink($old);
            }
        }
    }
}

/** Sends the response now and keeps running, so slow work can't hold the caller. */
function rp_json_early(int $status, array $body): void
{
    ignore_user_abort(true);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } elseif (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }
}

/** Meta wants matching details lowercased, trimmed and SHA-256 hashed. */
function rp_meta_hash(string $value): string
{
    $value = mb_strtolower(trim($value));
    return $value === '' ? '' : hash('sha256', $value);
}

function rp_meta_purchase(array $config, string $orderId, string $paymentId, array $buyer): bool
{
    $meta = $config['meta'];
    $orderId = preg_replace('/[^A-Za-z0-9_]/', '', $orderId);
    $saved = [];
    $file = $orderId === '' ? '' : rp_meta_dir($config) . '/' . $orderId;
    if ($file !== '' && is_file($file)) {
        $saved = json_decode((string) @file_get_contents($file), true) ?: [];
    }

    $parts = preg_split('/\s+/', trim(preg_replace('/[^\p{L}\s]/u', '', (string) $buyer['name'])) ?: '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $phone = preg_replace('/\D+/', '', (string) $buyer['phone']);
    $user = array_filter([
        'em'                => array_values(array_filter([rp_meta_hash((string) $buyer['email'])])),
        'ph'                => strlen($phone) === 10 ? [hash('sha256', '91' . $phone)] : [],
        'fn'                => isset($parts[0]) ? rp_meta_hash($parts[0]) : '',
        'ln'                => count($parts) > 1 ? rp_meta_hash($parts[count($parts) - 1]) : '',
        'country'           => rp_meta_hash('in'),
        'external_id'       => rp_meta_hash((string) $buyer['email']),
        'fbp'               => (string) ($saved['fbp'] ?? ''),
        'fbc'               => (string) ($saved['fbc'] ?? ''),
        'client_ip_address' => (string) ($saved['ip'] ?? ''),
        'client_user_agent' => (string) ($saved['ua'] ?? ''),
    ]);

    $body = [
        'data' => [[
            'event_name'       => 'Purchase',
            'event_time'       => time(),
            'event_id'         => 'purchase_' . $paymentId,
            'action_source'    => 'website',
            'event_source_url' => (string) ($saved['url'] ?? 'https://thegurukulofai.com/masterclass/'),
            'user_data'        => $user,
            'custom_data'      => [
                'currency'     => 'INR',
                'value'        => round(((int) $buyer['amount']) / 100, 2),
                'content_name' => 'AI Video Editing Masterclass',
                'content_ids'  => ['ai-video-editing-masterclass'],
                'content_type' => 'product',
            ],
        ]],
        'access_token' => (string) $meta['access_token'],
    ];
    if (!empty($meta['test_event_code'])) {
        $body['test_event_code'] = (string) $meta['test_event_code'];
    }

    $base = rtrim($config['meta']['graph_base'] ?? 'https://graph.facebook.com', '/');
    $version = preg_replace('/[^v0-9.]/', '', (string) ($meta['graph_version'] ?? 'v25.0')) ?: 'v25.0';
    $pixel = preg_replace('/\D+/', '', (string) $meta['pixel_id']);
    $ch = curl_init($base . '/' . $version . '/' . $pixel . '/events');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ok = $response !== false && $status >= 200 && $status < 300;
    if (!$ok) {
        // Meta's error text, never the token.
        error_log('meta purchase ' . $paymentId . ' failed: HTTP ' . $status . ' ' . substr(str_replace((string) $meta['access_token'], '***', (string) $response), 0, 300));
    }
    if ($file !== '') {
        @unlink($file);
    }
    return $ok;
}
