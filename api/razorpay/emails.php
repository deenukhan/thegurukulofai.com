<?php
/**
 * Every reminder-system email, in one place, so the wording is easy to change.
 * Plain text and short sentences, like the rest of the site.
 *
 * The buyer confirmation itself lives in lib.php (rp_send_buyer_email).
 */

/**
 * When each reminder goes out, measured from the session's start, and how long
 * after that moment it may still go. Past its window it is dropped, never sent late.
 */
const TG_REMINDERS = [
    'remind24' => ['offset' => -86400, 'window' => 7200, 'label' => 'Day-before reminder'],
    'remind3'  => ['offset' => -10800, 'window' => 3600, 'label' => '3-hour reminder'],
    'remind15' => ['offset' => -900,   'window' => 900,  'label' => '15-minute reminder'],
    'live'     => ['offset' => 0,      'window' => 1800, 'label' => '"We\'re live" email'],
];

function tg_site(array $config): string
{
    return rtrim((string) ($config['site_url'] ?? 'https://thegurukulofai.com'), '/');
}

function tg_support_whatsapp(array $config): string
{
    return (string) ($config['support_whatsapp'] ?? '+91 83838 40943');
}

function tg_join_url(array $config, string $token): string
{
    return tg_site($config) . '/join/?t=' . $token;
}

function tg_stop_url(array $config, string $token): string
{
    return tg_site($config) . '/join/stop/?t=' . $token;
}

/** The Zoom link from the private config, or null until a real one is pasted in. */
function tg_zoom_url(array $config): ?string
{
    $url = (string) ($config['zoom']['join_url'] ?? '');
    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $ok = ($parts['scheme'] ?? '') === 'https' && ($host === 'zoom.us' || str_ends_with($host, '.zoom.us'));
    return $ok ? $url : null;
}

/** "Sunday, 22 November" and "3 PM to 6 PM IST", from a stored IST session time. */
function tg_session_parts(string $sessionAt): array
{
    $start = new DateTimeImmutable($sessionAt, new DateTimeZone('Asia/Kolkata'));
    $end = $start->modify('+3 hours');
    return [
        'day'  => $start->format('l, j F'),
        'time' => $start->format('g A'),
        'span' => $start->format('g A') . ' to ' . $end->format('g A') . ' IST',
        'unix' => $start->getTimestamp(),
    ];
}

function tg_first_name(string $name): string
{
    $first = trim(explode(' ', trim($name))[0] ?? '');
    return $first !== '' ? $first : 'there';
}

/** The sign-off every buyer email shares. */
function tg_signoff(array $config): array
{
    return [
        'Questions? Reply to this email, or message us on WhatsApp: ' . tg_support_whatsapp($config),
        '',
        'Deenu Khan',
        'The Gurukul of AI',
    ];
}

/**
 * One reminder for one paid booking.
 * $b needs: name, session_at (IST, as stored), join_token.
 * Returns ['subject', 'body', 'headers'].
 */
function tg_reminder_email(array $config, string $template, array $b): array
{
    $event = $config['event']['name'];
    $when = tg_session_parts($b['session_at']);
    $join = tg_join_url($config, $b['join_token']);
    $stop = tg_stop_url($config, $b['join_token']);
    $hi = 'Hi ' . tg_first_name($b['name']) . ',';

    switch ($template) {
        case 'remind24':
            $subject = 'Tomorrow at ' . $when['time'] . ': ' . $event;
            $lines = [
                $hi, '',
                'Your ' . $event . ' is tomorrow.', '',
                'When: ' . $when['day'] . ', ' . $when['span'],
                'Where: Live on Zoom', '',
                'Your personal join link:', $join,
                'It opens the Zoom room. Please keep it to yourself.', '',
                'Before tomorrow:',
                '- Use a laptop. A phone screen is too small for the hands-on parts.',
                '- Keep a paid Claude plan ready (Pro or higher).',
                '- Plan to join 5 minutes early.', '',
            ];
            break;
        case 'remind3':
            $subject = 'Today at ' . $when['time'] . ': ' . $event;
            $lines = [
                $hi, '',
                'Your ' . $event . ' is today, ' . $when['span'] . ', live on Zoom.', '',
                'Your personal join link:', $join, '',
                'Charge your laptop and sign in to Claude before we start.', '',
            ];
            break;
        case 'remind15':
            $subject = 'We start at ' . $when['time'] . '. Your join link is inside';
            $lines = [
                $hi, '',
                'We go live at ' . $when['time'] . ' IST. Join now, so you are settled before we start:', '',
                $join, '',
            ];
            break;
        case 'live':
            $subject = "We're live now: " . $event;
            $lines = [
                $hi, '',
                'The ' . $event . ' has started. Join here:', '',
                $join, '',
            ];
            break;
        default:
            throw new InvalidArgumentException('Unknown reminder: ' . $template);
    }

    $lines = array_merge($lines, tg_signoff($config), [
        '',
        '--',
        'Don\'t want more reminders for this session? ' . $stop,
    ]);
    return [
        'subject' => $subject,
        'body'    => implode("\r\n", $lines),
        'headers' => [
            'List-Unsubscribe: <' . $stop . '>',
            'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
        ],
    ];
}

/**
 * The 9 AM summary for Deenu. $d holds the numbers worked out in outbox.php.
 * Counts only, no names: the admin page has the people.
 */
function tg_digest_email(array $config, array $d): array
{
    $rs = static fn(int $paise): string => 'Rs ' . number_format($paise / 100);
    $lines = [
        'Good morning. Here is the masterclass summary for the last 24 hours.', '',
        'SALES',
        '- New paid seats: ' . $d['paid'] . ' (' . $d['addons'] . ' with the add-on)',
        '- Money in: ' . $rs($d['revenue']),
        '- Checkouts not paid: ' . $d['unpaid'],
        '- Failed payment tries: ' . $d['failed'], '',
        'NEXT SESSION',
        '- ' . $d['next_label'] . ': ' . $d['next_paid'] . ' paid seats (' . $d['next_addons'] . ' with the add-on)',
        '- Zoom link in the config: ' . ($d['zoom_set'] ? 'yes' : 'NO. Reminders point to a page that says the room is not ready yet.'), '',
        'REMINDER EMAILS',
        '- Sent: ' . $d['sent'],
        '- Waiting to go out in the next 24 hours: ' . $d['queued'],
        '- Failed or missed: ' . $d['failed_msgs'] . ($d['failed_msgs'] > 0 ? ' (you got a separate alert for each)' : ''), '',
    ];
    if ($d['last_session_label'] !== null) {
        $lines[] = 'LAST SESSION';
        $lines[] = '- ' . $d['last_session_label'] . ': ' . $d['last_clicked'] . ' of ' . $d['last_paid'] . ' buyers opened their join link';
        $lines[] = '';
    }
    $lines[] = 'SAFETY NET';
    $lines[] = '- Nightly Razorpay check: ' . ($d['reconcile'] ?? 'has not run yet. Check the cron job in hPanel.');
    $lines[] = '';
    $lines[] = 'Everyone, with names: ' . tg_site($config) . '/api/admin/';
    return [
        'subject' => 'Masterclass summary: ' . $d['paid'] . ' new seat' . ($d['paid'] === 1 ? '' : 's') . ', next session ' . $d['next_short'],
        'body'    => implode("\r\n", $lines),
    ];
}

/**
 * Tells Deenu which messages finally failed, so he can send them by hand.
 * Each $row: label, name, email, session_label, error.
 */
function tg_alert_email(array $config, array $rows): array
{
    $lines = [
        count($rows) === 1 ? 'One email could not be sent.' : count($rows) . ' emails could not be sent.',
        'The system tried more than once and has now stopped trying.', '',
    ];
    foreach ($rows as $row) {
        $lines[] = '- ' . $row['label'] . ' for ' . $row['session_label'];
        $lines[] = '  To: ' . $row['name'] . ' <' . $row['email'] . '>';
        $lines[] = '  Why: ' . $row['error'];
    }
    $lines[] = '';
    $lines[] = 'What to do:';
    $lines[] = '1. If the session has not started yet, send these people the Zoom link by hand on WhatsApp or email.';
    $lines[] = '2. Then check the mailbox: can you send from ' . $config['mail_from'] . ' in Hostinger webmail?';
    return [
        'subject' => 'ACTION: ' . count($rows) . ' reminder email' . (count($rows) === 1 ? '' : 's') . ' not sent',
        'body'    => implode("\r\n", $lines),
    ];
}

/** Escapes text for a calendar file. */
function tg_ics_text(string $text): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $text);
}

/** Calendar lines may be at most 75 bytes; longer ones continue on the next line after a space. */
function tg_ics_fold(string $line): string
{
    $out = '';
    $limit = 75; // the first line; continuation lines start with a space, so they carry 74
    while (strlen($line) > $limit) {
        $cut = $limit;
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
            $cut--; // never split a UTF-8 character
        }
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
        $limit = 74;
    }
    return $out . $line;
}

/**
 * A calendar invite for one booking (attached to the confirmation email).
 * METHOD:PUBLISH adds it to the calendar without asking the buyer to reply.
 */
function tg_ics(array $config, int $sessionUnix, string $uid, ?string $joinUrl): string
{
    $utc = static fn(int $t): string => gmdate('Ymd\THis\Z', $t);
    $event = $config['event']['name'];
    $where = $joinUrl !== null ? 'Zoom. Your personal join link: ' . $joinUrl : 'Zoom. The join link comes in your reminder emails.';
    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//The Gurukul of AI//Masterclass//EN',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:' . preg_replace('/[^A-Za-z0-9_.-]/', '', $uid) . '@thegurukulofai.com',
        'DTSTAMP:' . $utc(time()),
        'DTSTART:' . $utc($sessionUnix),
        'DTEND:' . $utc($sessionUnix + 3 * 3600),
        'SUMMARY:' . tg_ics_text($event),
        'LOCATION:' . tg_ics_text('Zoom'),
        'DESCRIPTION:' . tg_ics_text("Live on {$where}\n\nQuestions? Reply to your confirmation email, or message us on WhatsApp: " . tg_support_whatsapp($config)),
    ];
    if ($joinUrl !== null) {
        $lines[] = 'URL:' . $joinUrl;
    }
    $lines = array_merge($lines, [
        'BEGIN:VALARM',
        'TRIGGER:-PT30M',
        'ACTION:DISPLAY',
        'DESCRIPTION:' . tg_ics_text($event . ' starts in 30 minutes'),
        'END:VALARM',
        'END:VEVENT',
        'END:VCALENDAR',
    ]);
    return implode("\r\n", array_map('tg_ics_fold', $lines)) . "\r\n";
}

/** A small public page for the join and stop links: plain, on brand, kept out of search. */
function tg_page(int $status, string $title, string $html): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">',
        '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">',
        '<title>', $t, ' · The Gurukul of AI</title><style>',
        ':root{--ink:#1A1A1A;--teal:#3ECFCF;--slate:#4A4A4A;--mist:#E8E4DE;--forest:#2D4A3E}',
        '*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#F5F5F7;color:var(--ink);',
        'font:16px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;padding:24px 16px}',
        '.card{background:#fff;border:1px solid var(--mist);border-radius:20px;padding:32px 28px;max-width:460px;width:100%}',
        'h1{font-size:24px;line-height:1.25;letter-spacing:-.03em;margin:0 0 12px}h1:after{content:"";display:block;width:40px;height:2px;background:var(--teal);margin-top:12px}',
        'p{color:var(--slate);margin:0 0 12px}button{font:inherit;font-weight:600;background:var(--forest);color:#fff;border:0;border-radius:10px;padding:12px 20px;cursor:pointer;margin-top:8px}',
        'small{display:block;margin-top:20px;color:var(--slate);font-size:13px}',
        '</style></head><body><main class="card"><h1>', $t, '</h1>', $html,
        '<small>The Gurukul of AI</small></main></body></html>';
    exit;
}
