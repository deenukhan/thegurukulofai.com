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
    exit;
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

/**
 * Sends one plain-text email. It goes through the mailbox when the config has a
 * password, because PHP mail() fails DMARC here and risks the spam folder.
 * mail() stays as the backup, so a buyer still hears from us if SMTP is down.
 */
function rp_mail(array $config, string $to, string $subject, string $body): bool
{
    $from = $config['mail_from'];
    $subject = mb_encode_mimeheader($subject, 'UTF-8', 'B');
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: The Gurukul of AI <' . $from . '>',
        'Reply-To: ' . $from,
    ];

    if (!empty($config['smtp']['password'])
        && rp_smtp_send($config['smtp'], $from, $to, $subject, $body, $headers)) {
        return true;
    }
    return mail($to, $subject, $body, implode("\r\n", $headers), '-f' . $from);
}

/** Talks to the mail server directly. Short timeouts, because Razorpay gives a webhook 5 seconds. */
function rp_smtp_send(array $smtp, string $from, string $to, string $subject, string $body, array $headers): bool
{
    $socket = @stream_socket_client($smtp['host'] . ':' . $smtp['port'], $errno, $error, 4);
    if ($socket === false) {
        error_log('smtp connect failed: ' . $error);
        return false;
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
        ['Content-Transfer-Encoding: quoted-printable', '', quoted_printable_encode($body)]
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
            error_log('smtp failed at ' . $name . ': got ' . $code);
            fclose($socket);
            return false;
        }
    }
    fwrite($socket, "QUIT\r\n");
    fclose($socket);
    return true;
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

/** Sends the buyer's confirmation and a sale note to Deenu. True if the buyer's mail was accepted. */
function rp_send_emails(array $config, array $sale): bool
{
    $event = $config['event'];
    $lines = [
        'Hi ' . $sale['name'] . ',',
        '',
        'You are in. Your seat for the ' . $event['name'] . ' is confirmed.',
        '',
        'When: ' . $event['when'],
        'Where: Live on Zoom',
        '',
        'What to do now:',
        '1. Join the WhatsApp community: ' . $config['community_url'],
        '   We will share the Zoom link and reminders there.',
        '2. Keep a laptop ready, and a paid Claude plan (Pro or higher).',
        '',
    ];
    if ($sale['addon']) {
        $lines[] = 'You also added the recording + resource pack. We will send it after the session.';
        $lines[] = '';
    }
    $lines[] = 'Amount paid: Rs ' . number_format($sale['amount'] / 100, 2);
    $lines[] = 'Payment ID: ' . $sale['payment_id'];
    $lines[] = '';
    $lines[] = 'Questions? Reply to this email, or message us on WhatsApp: +91 88001 28247';
    $lines[] = '';
    $lines[] = 'Deenu Khan';
    $lines[] = 'The Gurukul of AI';

    $subject = 'You are in: ' . $event['name'];
    $ok = rp_mail($config, $sale['email'], $subject, implode("\r\n", $lines));

    $note = [
        'New masterclass seat',
        'Name: ' . $sale['name'],
        'Email: ' . $sale['email'],
        'Phone: ' . $sale['phone'],
        'Add-on: ' . ($sale['addon'] ? 'Yes' : 'No'),
        'Amount: Rs ' . number_format($sale['amount'] / 100, 2),
        'Payment ID: ' . $sale['payment_id'],
        'Buyer email sent: ' . ($ok ? 'Yes' : 'NO, please send it by hand'),
    ];
    rp_mail($config, $config['mail_notify'], 'New seat: ' . $sale['name'], implode("\r\n", $note));

    return $ok;
}
