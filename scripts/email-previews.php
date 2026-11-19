<?php
/**
 * Renders every Phase 2 email with sample data into docs/phase-2-email-previews.html,
 * so the wording can be checked in a browser before anything goes live.
 *
 *   php scripts/email-previews.php
 *
 * Uses the real template functions, so the preview is exactly what buyers get.
 * Sample people and links only; no config or secrets are read.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
require $root . '/api/razorpay/lib.php';
require $root . '/api/razorpay/store.php';
require $root . '/api/razorpay/emails.php';

$config = [
    'event'            => ['name' => 'AI Video Editing Masterclass'],
    'community_url'    => 'https://chat.whatsapp.com/SAMPLE-COMMUNITY-LINK',
    'mail_from'        => 'deenu@thegurukulofai.com',
    'mail_notify'      => 'deenu@thegurukulofai.com',
    'support_whatsapp' => '+91 83838 40943',
    'zoom'             => ['join_url' => 'https://us06web.zoom.us/j/SAMPLE'],
];
$token = '3f9c1a7e5b2d8c4f6a0e9b1d';
$session = '2026-11-29 15:00:00';
$sessionUnix = tg_session_parts($session)['unix'];
$join = tg_join_url($config, $token);

$emails = [];
$ics = tg_ics($config, $sessionUnix, 'pay_SAMPLE123', $join);
$confirm = rp_buyer_email($config, [
    'name' => 'Priya Nair', 'email' => 'priya@example.com', 'addon' => true, 'amount' => 199800,
    'payment_id' => 'pay_SAMPLE123', 'session' => rp_session_when(new DateTimeImmutable($session, new DateTimeZone('Asia/Kolkata'))),
    'join_url' => $join, 'ics' => $ics,
]);
$emails[] = ['When', 'Straight after payment (already live today; now with the join link and calendar invite)',
    'To', 'Priya Nair <priya@example.com>', 'mail' => $confirm, 'attach' => 'masterclass.ics'];

$when = [
    'remind24' => 'Saturday 3 PM, the day before',
    'remind3'  => 'Sunday 12 noon',
    'remind15' => 'Sunday 2:45 PM',
    'live'     => 'Sunday 3 PM, as the session starts',
];
foreach ($when as $template => $label) {
    $mail = tg_reminder_email($config, $template, ['name' => 'Priya Nair', 'session_at' => $session, 'join_token' => $token]);
    $emails[] = ['When', $label . '. Only if it is still ahead of the time she paid.', 'To', 'Priya Nair <priya@example.com>', 'mail' => $mail];
}

$digest = tg_digest_email($config, [
    'paid' => 6, 'addons' => 2, 'revenue' => 998400, 'unpaid' => 9, 'failed' => 2,
    'next_label' => 'Sunday 29 Nov, 3 PM', 'next_short' => 'Sun 29 Nov', 'next_paid' => 14, 'next_addons' => 5,
    'zoom_set' => true, 'sent' => 31, 'queued' => 28, 'failed_msgs' => 0,
    'last_session_label' => 'Sunday 22 Nov', 'last_paid' => 18, 'last_clicked' => 15,
    'reconcile' => 'last ran 23 Nov, 3:00 AM. Checked 41 Razorpay orders since 20 Nov 2026, 3:00 AM IST. This product: 27 (14 paid, 11 started, 2 failed). Store: 0 added, 1 updated, 26 already right, 0 errors.',
]);
$emails[] = ['When', 'Every day at 9 AM IST', 'To', 'Deenu (mail_notify)', 'mail' => $digest];

$alert = tg_alert_email($config, [[
    'label' => '3-hour reminder', 'name' => 'Rahul Verma', 'email' => 'rahul@example.com',
    'session_label' => 'Sun 29 Nov, 3 PM', 'error' => 'not sent, SMTP failed: connect: Connection timed out',
]]);
$emails[] = ['When', 'Only when an email has finally failed, once per failure', 'To', 'Deenu (mail_notify)', 'mail' => $alert];

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$linkify = static fn(string $s): string => preg_replace('#(https?://[^\s<]+)#', '<a href="$1">$1</a>', $s);

$cards = '';
foreach ($emails as $i => $e) {
    $m = $e['mail'];
    $headers = '';
    foreach ($m['headers'] ?? [] as $line) {
        if (str_starts_with($line, 'List-Unsubscribe:')) {
            $headers = '<p class="meta"><b>Mail apps also show</b> an "Unsubscribe" button that stops these reminders.</p>';
        }
    }
    $cards .= '<article class="mail"><div class="head"><span class="n">' . ($i + 1) . '</span>'
        . '<div><p class="meta"><b>' . $h($e[0]) . ':</b> ' . $h($e[1]) . '</p>'
        . '<p class="meta"><b>From:</b> The Gurukul of AI &lt;deenu@thegurukulofai.com&gt; · <b>' . $h($e[2]) . ':</b> ' . $h($e[3]) . '</p>'
        . $headers . '<h2>' . $h($m['subject']) . '</h2></div></div>'
        . '<div class="body">' . $linkify($h(str_replace("\r\n", "\n", $m['body']))) . '</div>'
        . (isset($e['attach']) ? '<p class="attach">📎 ' . $h($e['attach']) . ' (calendar invite, shown below)</p>' : '')
        . '</article>';
}

$start = (new DateTimeImmutable('@' . $sessionUnix))->setTimezone(new DateTimeZone('Asia/Kolkata'));
$calendar = '<article class="mail"><div class="head"><span class="n">' . (count($emails) + 1) . '</span><div>'
    . '<p class="meta"><b>Attached to:</b> the confirmation email (1)</p><h2>Calendar invite: masterclass.ics</h2></div></div>'
    . '<div class="cal"><div class="cal-day"><span>' . $start->format('D') . '</span><b>' . $start->format('j') . '</b><span>' . $start->format('M') . '</span></div>'
    . '<div><p><b>AI Video Editing Masterclass</b></p><p>' . $start->format('l, j F Y') . ' · 3:00 PM to 6:00 PM IST</p>'
    . '<p>Location: Zoom</p><p>Alert: 30 minutes before</p><p>Notes: Live on Zoom. Your personal join link: ' . $linkify($h($join)) . '</p></div></div>'
    . '<details><summary>The file itself</summary><div class="body small">' . $h($ics) . '</div></details></article>';

$html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<meta name="robots" content="noindex"><title>Phase 2 email previews</title>'
    . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;600;700&display=swap"><style>'
    . ':root{--ink:#1A1A1A;--teal:#3ECFCF;--slate:#4A4A4A;--mist:#E8E4DE;--bg:#F5F5F7;--forest:#2D4A3E}'
    . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 "Instrument Sans",-apple-system,sans-serif}'
    . 'main{max-width:760px;margin:0 auto;padding:40px 16px 80px}h1{font-size:32px;letter-spacing:-.03em;margin:0 0 8px}'
    . '.lede{color:var(--slate);margin:0 0 8px}.rule{width:48px;height:2px;background:var(--teal);margin:16px 0 32px}'
    . '.mail{background:#fff;border:1px solid var(--mist);border-radius:20px;margin:0 0 20px;overflow:hidden}'
    . '.head{display:flex;gap:14px;padding:18px 20px;border-bottom:1px solid var(--mist)}.n{flex:none;width:28px;height:28px;border-radius:50%;background:var(--teal);font-weight:700;display:grid;place-items:center;font-size:13px}'
    . '.meta{margin:0 0 2px;color:var(--slate);font-size:13px}h2{font-size:18px;letter-spacing:-.02em;margin:8px 0 0}'
    . '.body{white-space:pre-wrap;word-break:break-word;padding:18px 20px;font-size:15px}.body.small{font:12px/1.5 ui-monospace,Menlo,monospace;color:var(--slate)}'
    . 'a{color:var(--forest)}.attach{margin:0;padding:12px 20px;border-top:1px solid var(--mist);font-size:14px;color:var(--slate)}'
    . '.cal{display:flex;gap:16px;padding:18px 20px}.cal p{margin:0 0 4px}.cal-day{flex:none;width:64px;border:1px solid var(--mist);border-radius:12px;text-align:center;padding:6px 0;font-size:12px;color:var(--slate)}'
    . '.cal-day b{display:block;font-size:24px;color:var(--ink)}details{padding:0 20px 18px}summary{cursor:pointer;color:var(--slate);font-size:13px}'
    . '</style></head><body><main><h1>Phase 2 email previews</h1>'
    . '<p class="lede">Every email the reminder system sends, exactly as buyers and Deenu will get them. The sample buyer is Priya, booked for Sunday 29 November.</p>'
    . '<p class="lede">The links are samples. In real emails each buyer gets their own join link, which opens the Zoom room.</p><div class="rule"></div>'
    . $cards . $calendar . '</main></body></html>';

file_put_contents($root . '/docs/phase-2-email-previews.html', $html);
echo "Wrote docs/phase-2-email-previews.html\n";
