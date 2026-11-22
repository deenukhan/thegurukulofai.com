<?php
/**
 * WhatsApp messages, sent straight through Meta's WhatsApp Cloud API (no middleman).
 *
 * Buyers get the same messages on WhatsApp as by email: the seat confirmation and the
 * four reminders. Each one is a template that Meta approved in advance; we only fill
 * in the name, the date and the personal join link. The outbox (outbox.php) plans and
 * sends them with the emails, and ../whatsapp/webhook.php hears back from Meta:
 * delivered, read, failed, a "STOP", or any other reply (emailed to Deenu).
 *
 * Settings live in the private config's `whatsapp` block (see config.sample.php).
 * The access token and app secret never leave the server and never reach a log.
 */

/**
 * The templates, keyed by the outbox's template name. Everything a buyer reads on
 * WhatsApp is here. These are "utility" templates: facts about a booking they paid
 * for, nothing promotional, so Meta keeps them in the cheaper utility category.
 *
 * Changing approved wording: give the template a new name (Meta won't let a name be
 * reused for four weeks after it is deleted), then submit it from the dashboard.
 *
 * body:    the text, with {{1}}, {{2}}... filled in at send time (tg_wa_values).
 * example: sample values Meta's reviewers see.
 * buttons: link buttons; the {{1}} at the end of each link is filled in at send time.
 */
const TG_WA_FOOTER = 'Reply STOP to stop these messages';

function tg_wa_templates(array $config): array
{
    $site = tg_site($config);
    $join = ['text' => 'Join the class', 'url' => $site . '/join/?t={{1}}', 'example' => $site . '/join/?t=0a1b2c3d4e5f6a7b8c9d0e1f'];
    $event = (string) ($config['event']['name'] ?? 'AI Video Editing Masterclass');
    return [
        'confirm' => [
            'name'    => 'mc_seat_confirmed',
            'label'   => 'Seat confirmed',
            'when'    => 'Right after payment',
            'body'    => "Hi {{1}}, your seat for the {{2}} is confirmed.\n\n"
                . "When: {{3}}\nWhere: Live on Zoom\n\n"
                . "Your personal joining link will come here on WhatsApp on the day at {{4}}, 15 minutes before we start.\n\n"
                . "Your receipt and calendar invite are in your email. Tap \"WhatsApp community\" to join the other buyers.",
            'example' => ['Priya', $event, 'Sunday, 22 November, 3 PM to 6 PM IST', '2:45 PM IST'],
            // No join button until 15 minutes before (Deenu, 23 Nov 2026): the link comes with
            // the last two messages only. Meta refuses WhatsApp links in template buttons, so
            // the community button goes through the site (/join/community/), which forwards on.
            'buttons' => [['text' => 'WhatsApp community', 'url' => $site . '/join/community/?t={{1}}', 'example' => $site . '/join/community/?t=0a1b2c3d4e5f6a7b8c9d0e1f']],
        ],
        'remind24' => [
            // Renamed from mc_reminder_tomorrow when the join button moved to the last two
            // messages: wording sent to Meta can't be changed under the same name.
            'name'    => 'mc_reminder_day_before',
            'label'   => 'Day-before reminder',
            'when'    => '24 hours before the session',
            'body'    => "Hi {{1}}, a reminder that your {{2}} is tomorrow, {{3}}, live on Zoom.\n\n"
                . "Before you join:\n"
                . "- Use a laptop. A phone screen is too small for the hands-on parts.\n"
                . "- Keep a paid Claude plan ready (Pro or higher).\n"
                . "- Join 5 minutes early.\n\n"
                . "Your personal joining link will come here tomorrow at {{4}}, 15 minutes before we start.",
            'example' => ['Priya', $event, 'Sunday, 3 PM to 6 PM IST', '2:45 PM IST'],
            'buttons' => [],
        ],
        'remind3' => [
            'name'    => 'mc_reminder_3_hours',
            'label'   => '3-hour reminder',
            'when'    => '3 hours before',
            'body'    => "Hi {{1}}, your {{2}} starts today at {{3}}, live on Zoom.\n\n"
                . "Charge your laptop and sign in to Claude before we start. Your personal joining link will come here at {{4}}.",
            'example' => ['Priya', $event, '3 PM IST', '2:45 PM IST'],
            'buttons' => [],
        ],
        'remind15' => [
            'name'    => 'mc_reminder_15min',
            'label'   => '15-minute reminder',
            'when'    => '15 minutes before',
            'body'    => "Hi {{1}}, we start in 15 minutes. Tap the button below to join the masterclass on Zoom.",
            'example' => ['Priya'],
            'buttons' => [$join],
        ],
        // mc_class_live was moved to "marketing" by Meta (23 Nov 2026), so this plainer
        // wording goes under a new name. Approved or reviewed wording can't be edited.
        'live' => [
            'name'    => 'mc_class_started',
            'label'   => '"We\'re live" message',
            'when'    => 'When the session starts',
            'body'    => "Hi {{1}}, your {{2}} has started on Zoom. Your personal join link is the button below.",
            'example' => ['Priya', $event],
            'buttons' => [$join],
        ],
    ];
}

/** The message sent back when someone replies STOP, and when they reply START. */
const TG_WA_STOPPED = 'Done. You won\'t get any more WhatsApp messages from us. Your booking emails still come by email. Reply START to turn WhatsApp messages back on.';
const TG_WA_STARTED = 'Welcome back. WhatsApp messages about your booking are on again. Reply STOP anytime to turn them off.';

/** Words that mean "stop messaging me", and "start again". Matched on the whole reply. */
const TG_WA_STOP_WORDS = ['stop', 'stop all', 'stopall', 'unsubscribe', 'opt out', 'optout', 'band', 'band karo', 'mat bhejo', 'बंद', 'बंद करो'];
const TG_WA_START_WORDS = ['start', 'unstop', 'resume', 'opt in', 'optin'];

// ---------------------------------------------------------------------------
// Switches
// ---------------------------------------------------------------------------

/** Every key is filled in (no PASTE left). Enough for the webhook and the dashboard's checks. */
function tg_wa_ready(array $config): bool
{
    $wa = $config['whatsapp'] ?? null;
    if (!is_array($wa)) {
        return false;
    }
    foreach (['phone_number_id', 'waba_id', 'access_token', 'app_secret', 'verify_token'] as $key) {
        $value = (string) ($wa[$key] ?? '');
        if ($value === '' || str_starts_with($value, 'PASTE')) {
            return false;
        }
    }
    return true;
}

/** Sending is on only with every key filled in and `enabled` => true. */
function tg_wa_enabled(array $config): bool
{
    return tg_wa_ready($config) && !empty($config['whatsapp']['enabled']);
}

/** Paused from the dashboard's Settings page: nothing is planned or sent until it is switched back on. */
function tg_wa_paused(array $config): bool
{
    return !empty($config['whatsapp']['paused']);
}

/** The test list: while it has numbers in it, only those numbers get messages. */
function tg_wa_test_numbers(array $config): array
{
    $list = $config['whatsapp']['test_numbers'] ?? [];
    $out = [];
    foreach (is_array($list) ? $list : [] as $n) {
        $n = tg_wa_number((string) $n);
        if ($n !== null) {
            $out[] = $n;
        }
    }
    return array_values(array_unique($out));
}

function tg_wa_allowed(array $config, string $number): bool
{
    $test = tg_wa_test_numbers($config);
    return $test === [] || in_array($number, $test, true);
}

/**
 * A phone number as WhatsApp wants it: country code and digits, no plus.
 * The booking form takes Indian numbers, so 10 digits get 91 in front.
 * Returns null for anything that can't be a WhatsApp number.
 */
function tg_wa_number(string $phone): ?string
{
    $d = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($d) === 11 && $d[0] === '0') {
        $d = substr($d, 1);
    }
    if (strlen($d) === 10) {
        return preg_match('/^[6-9]/', $d) ? '91' . $d : null;
    }
    if (strlen($d) === 12 && str_starts_with($d, '91')) {
        return preg_match('/^91[6-9]/', $d) ? $d : null;
    }
    // Another country: plausible length only.
    return strlen($d) >= 8 && strlen($d) <= 15 && !str_starts_with($d, '0') ? $d : null;
}

/** "+91 98765 43210" for people to read. */
function tg_wa_pretty(string $number): string
{
    return strlen($number) === 12 && str_starts_with($number, '91')
        ? '+91 ' . substr($number, 2, 5) . ' ' . substr($number, 7)
        : '+' . $number;
}

// ---------------------------------------------------------------------------
// Talking to Meta
// ---------------------------------------------------------------------------

/**
 * One call to Meta's Graph API. Returns [HTTP status, decoded body or null].
 * The token goes in a header, never the address, so it can't land in a server log.
 */
function tg_wa_graph(array $config, string $method, string $path, ?array $body = null, array $query = []): array
{
    $wa = $config['whatsapp'];
    $base = rtrim((string) ($wa['graph_base'] ?? 'https://graph.facebook.com'), '/');
    $version = preg_replace('/[^v0-9.]/', '', (string) ($wa['api_version'] ?? 'v25.0')) ?: 'v25.0';
    $url = $base . '/' . $version . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $wa['access_token'], 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $decoded = is_string($response) ? json_decode($response, true) : null;
    return [$response === false ? 0 : $status, is_array($decoded) ? $decoded : null];
}

/** Meta's reason in one short line, never the token. */
function tg_wa_error_text(array $config, int $status, ?array $body): string
{
    if ($status === 0) {
        return 'WhatsApp: Meta did not answer';
    }
    $e = $body['error'] ?? null;
    if (!is_array($e)) {
        return 'WhatsApp: HTTP ' . $status;
    }
    $details = (string) ($e['error_data']['details'] ?? '');
    $text = 'WhatsApp ' . (int) ($e['code'] ?? 0) . ': ' . (string) ($e['message'] ?? 'error') . ($details !== '' ? ' (' . $details . ')' : '');
    return mb_substr(str_replace((string) $config['whatsapp']['access_token'], '***', $text), 0, 250);
}

/**
 * Errors worth trying again in 5 minutes: Meta busy or down, rate limits, network.
 * Anything else (a bad number, a template that isn't approved) fails at once.
 */
function tg_wa_retryable(int $status, ?array $body): bool
{
    if ($status === 0 || $status >= 500 || $status === 429) {
        return true;
    }
    $code = (int) ($body['error']['code'] ?? 0);
    return in_array($code, [1, 2, 4, 80007, 130429, 131000, 131016, 131048, 131056, 133016], true);
}

/**
 * The values for one template, from an outbox row joined to its booking.
 * $row needs name, session_at and join_token. Returns ['body' => [...], 'buttons' => [...]].
 */
function tg_wa_values(array $config, string $template, array $row): array
{
    $event = (string) ($config['event']['name'] ?? 'AI Video Editing Masterclass');
    $first = tg_first_name((string) ($row['name'] ?? ''));
    $when = tg_session_parts((string) $row['session_at']);
    $token = (string) $row['join_token'];
    // When the joining link arrives: the 15-minute reminder, e.g. "2:45 PM IST".
    $early = (new DateTimeImmutable('@' . ($when['unix'] - 900)))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('g:i A') . ' IST';
    switch ($template) {
        case 'confirm':
            $invite = (string) ($config['community_url'] ?? '');
            if (!preg_match('~^https://chat\.whatsapp\.com/[A-Za-z0-9]{10,40}$~', $invite)) {
                throw new RuntimeException('The WhatsApp community link is not set. Add it in Settings.');
            }
            return ['body' => [$first, $event, $when['day'] . ', ' . $when['span'], $early], 'buttons' => [$token]];
        case 'remind24':
            return ['body' => [$first, $event, (new DateTimeImmutable('@' . $when['unix']))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('l') . ', ' . $when['span'], $early], 'buttons' => []];
        case 'remind3':
            return ['body' => [$first, $event, $when['time'] . ' IST', $early], 'buttons' => []];
        case 'live':
            return ['body' => [$first, $event], 'buttons' => [$token]];
        default:
            return ['body' => [$first], 'buttons' => [$token]];
    }
}

/** The message as the buyer sees it, with the values filled in. For previews and notes. */
function tg_wa_render(array $config, string $template, array $values): array
{
    $t = tg_wa_templates($config)[$template];
    $body = preg_replace_callback('/\{\{(\d+)\}\}/', static fn($m) => (string) ($values['body'][(int) $m[1] - 1] ?? $m[0]), $t['body']);
    $buttons = [];
    foreach ($t['buttons'] as $i => $b) {
        $buttons[] = ['text' => $b['text'], 'url' => str_replace('{{1}}', (string) ($values['buttons'][$i] ?? '{{1}}'), $b['url'])];
    }
    return ['body' => $body, 'footer' => TG_WA_FOOTER, 'buttons' => $buttons];
}

/**
 * Sends one approved template. Returns ok, id (Meta's message id), error and retry
 * (true when trying again later may work).
 */
function tg_wa_send(array $config, string $template, string $to, array $values): array
{
    $t = tg_wa_templates($config)[$template] ?? null;
    if ($t === null) {
        return ['ok' => false, 'id' => null, 'error' => 'Unknown WhatsApp template ' . $template, 'retry' => false];
    }
    $components = [[
        'type'       => 'body',
        'parameters' => array_map(static fn($v) => ['type' => 'text', 'text' => (string) $v], $values['body']),
    ]];
    foreach ($values['buttons'] as $i => $v) {
        $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => (string) $i, 'parameters' => [['type' => 'text', 'text' => (string) $v]]];
    }
    [$status, $body] = tg_wa_graph($config, 'POST', rawurlencode((string) $config['whatsapp']['phone_number_id']) . '/messages', [
        'messaging_product' => 'whatsapp',
        'recipient_type'    => 'individual',
        'to'                => $to,
        'type'              => 'template',
        'template'          => [
            'name'       => $t['name'],
            'language'   => ['code' => (string) ($config['whatsapp']['language'] ?? 'en')],
            'components' => $components,
        ],
    ]);
    $id = (string) ($body['messages'][0]['id'] ?? '');
    if ($status >= 200 && $status < 300 && $id !== '') {
        return ['ok' => true, 'id' => $id, 'error' => null, 'retry' => false];
    }
    $error = tg_wa_error_text($config, $status, $body);
    error_log('whatsapp ' . $template . ' not sent: ' . $error);
    return ['ok' => false, 'id' => null, 'error' => $error, 'retry' => tg_wa_retryable($status, $body)];
}

/** A plain reply inside the 24 hours after someone wrote to us (STOP and START answers). */
function tg_wa_send_text(array $config, string $to, string $text): bool
{
    [$status, $body] = tg_wa_graph($config, 'POST', rawurlencode((string) $config['whatsapp']['phone_number_id']) . '/messages', [
        'messaging_product' => 'whatsapp',
        'recipient_type'    => 'individual',
        'to'                => $to,
        'type'              => 'text',
        'text'              => ['body' => $text, 'preview_url' => false],
    ]);
    $ok = $status >= 200 && $status < 300;
    if (!$ok) {
        error_log('whatsapp reply not sent: ' . tg_wa_error_text($config, $status, $body));
    }
    return $ok;
}

/** What Meta says about each of our templates: name => status, category, reason. Null if Meta can't be read. */
function tg_wa_template_states(array $config): ?array
{
    [$status, $body] = tg_wa_graph($config, 'GET', rawurlencode((string) $config['whatsapp']['waba_id']) . '/message_templates', null, [
        'fields' => 'name,status,category,language,rejected_reason',
        'limit'  => 200,
    ]);
    if ($status !== 200 || !is_array($body['data'] ?? null)) {
        return null;
    }
    $language = (string) ($config['whatsapp']['language'] ?? 'en');
    $out = [];
    foreach ($body['data'] as $t) {
        if (($t['language'] ?? '') !== $language) {
            continue;
        }
        $out[(string) $t['name']] = [
            'status'   => (string) ($t['status'] ?? ''),
            'category' => (string) ($t['category'] ?? ''),
            'reason'   => (string) ($t['rejected_reason'] ?? ''),
        ];
    }
    return $out;
}

/** Meta's request body for one template, as it is submitted for review. */
function tg_wa_template_request(array $config, array $t): array
{
    $buttons = [];
    foreach ($t['buttons'] as $b) {
        $buttons[] = ['type' => 'URL', 'text' => $b['text'], 'url' => $b['url'], 'example' => [$b['example']]];
    }
    return [
        'name'       => $t['name'],
        'language'   => (string) ($config['whatsapp']['language'] ?? 'en'),
        'category'   => 'UTILITY',
        'components' => [
            ['type' => 'BODY', 'text' => $t['body'], 'example' => ['body_text' => [$t['example']]]],
            ['type' => 'FOOTER', 'text' => TG_WA_FOOTER],
            ...($buttons !== [] ? [['type' => 'BUTTONS', 'buttons' => $buttons]] : []),
        ],
    ];
}

/**
 * Sends every template Meta doesn't have yet for review. Ones already there are left
 * alone. Returns a list of [label, outcome] for the dashboard, or null if Meta can't be read.
 */
function tg_wa_submit_templates(array $config): ?array
{
    $have = tg_wa_template_states($config);
    if ($have === null) {
        return null;
    }
    $out = [];
    foreach (tg_wa_templates($config) as $t) {
        if (isset($have[$t['name']])) {
            $out[] = [$t['label'], 'already with Meta (' . strtolower($have[$t['name']]['status']) . ')'];
            continue;
        }
        [$status, $body] = tg_wa_graph($config, 'POST', rawurlencode((string) $config['whatsapp']['waba_id']) . '/message_templates', tg_wa_template_request($config, $t));
        $out[] = [$t['label'], $status >= 200 && $status < 300
            ? 'submitted (' . strtolower((string) ($body['status'] ?? 'pending')) . ')'
            : 'refused: ' . tg_wa_error_text($config, $status, $body)];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// What Meta tells us (called by ../whatsapp/webhook.php)
// ---------------------------------------------------------------------------

/** Delivery news for one message we sent: sent, delivered, read or failed. */
function tg_wa_status(PDO $pdo, array $s): void
{
    $id = (string) ($s['id'] ?? '');
    $state = (string) ($s['status'] ?? '');
    if ($id === '' || strlen($id) > 128) {
        return;
    }
    $at = tg_ist((int) ($s['timestamp'] ?? time()));
    if ($state === 'delivered') {
        $pdo->prepare("UPDATE outbox SET delivered_at = COALESCE(delivered_at, ?) WHERE provider_id = ?")->execute([$at, $id]);
    } elseif ($state === 'read') {
        $pdo->prepare("UPDATE outbox SET delivered_at = COALESCE(delivered_at, ?), read_at = COALESCE(read_at, ?) WHERE provider_id = ?")
            ->execute([$at, $at, $id]);
    } elseif ($state === 'failed') {
        $e = $s['errors'][0] ?? [];
        $details = (string) ($e['error_data']['details'] ?? '');
        $why = 'WhatsApp ' . (int) ($e['code'] ?? 0) . ': ' . (string) ($e['title'] ?? ($e['message'] ?? 'not delivered'))
            . ($details !== '' ? ' (' . $details . ')' : '');
        // Only a message that wasn't delivered can fail; a late "failed" never undoes "read".
        $pdo->prepare("UPDATE outbox SET status = 'failed', last_error = ? WHERE provider_id = ? AND read_at IS NULL")
            ->execute([mb_substr($why, 0, 255), $id]);
    }
}

/** True if this number's latest word to us was STOP (and not START since). */
function tg_wa_opted_out(PDO $pdo, string $number): bool
{
    $q = $pdo->prepare("SELECT kind FROM wa_inbound WHERE phone = ? AND kind IN ('stop', 'start') ORDER BY id DESC LIMIT 1");
    $q->execute([$number]);
    return $q->fetchColumn() === 'stop';
}

/** The buyers and leads with this number (the form keeps 10 digits, Meta sends 91 in front). */
function tg_wa_contacts(PDO $pdo, string $number): array
{
    $ten = substr($number, -10);
    $q = $pdo->prepare('SELECT id, name, email, phone FROM contacts WHERE phone = ? OR phone = ? ORDER BY id DESC LIMIT 5');
    $q->execute([$number, $ten]);
    return $q->fetchAll();
}

/**
 * One message someone sent us. Returns what to do once Meta has its answer:
 * ['kind' => stop|start|reply|skip, 'from', 'name', 'text', 'contacts'], or null for a repeat.
 */
function tg_wa_inbound(PDO $pdo, array $config, array $m, array $profiles): ?array
{
    $id = (string) ($m['id'] ?? '');
    $from = tg_wa_number((string) ($m['from'] ?? ''));
    if ($id === '' || strlen($id) > 128 || $from === null) {
        return null;
    }
    $type = (string) ($m['type'] ?? '');
    $text = match ($type) {
        'text'        => (string) ($m['text']['body'] ?? ''),
        'button'      => (string) ($m['button']['text'] ?? ''),
        'interactive' => (string) ($m['interactive']['button_reply']['title'] ?? ($m['interactive']['list_reply']['title'] ?? '')),
        default       => '',
    };
    $word = trim(mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', '', $text) ?? ''));
    $word = preg_replace('/\s+/', ' ', $word);
    $kind = in_array($word, TG_WA_STOP_WORDS, true) ? 'stop'
        : (in_array($word, TG_WA_START_WORDS, true) ? 'start'
        : (in_array($type, ['reaction', 'system', 'unsupported', 'ephemeral', 'request_welcome'], true) ? 'skip' : 'reply'));

    // The message id makes Meta's repeats harmless.
    $add = $pdo->prepare('INSERT INTO wa_inbound (wa_id, phone, kind, received_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id');
    $add->execute([$id, $from, $kind, tg_ist((int) ($m['timestamp'] ?? time()))]);
    if ($add->rowCount() !== 1) {
        return null;
    }

    $contacts = tg_wa_contacts($pdo, $from);
    if ($kind === 'stop' || $kind === 'start') {
        $ids = array_map('intval', array_column($contacts, 'id'));
        if ($ids) {
            $in = implode(',', $ids);
            if ($kind === 'stop') {
                $pdo->prepare("UPDATE contacts SET wa_optout_at = COALESCE(wa_optout_at, ?) WHERE id IN ({$in})")->execute([tg_ist(time())]);
                $pdo->exec("UPDATE outbox o JOIN bookings b ON b.id = o.booking_id SET o.status = 'cancelled', o.last_error = 'Replied STOP on WhatsApp'
                    WHERE o.channel = 'whatsapp' AND o.status = 'queued' AND b.contact_id IN ({$in})");
            } else {
                $pdo->exec("UPDATE contacts SET wa_optout_at = NULL WHERE id IN ({$in})");
                // Reminders still ahead of them come back; ones whose time passed stay stopped.
                $pdo->prepare("UPDATE outbox o JOIN bookings b ON b.id = o.booking_id SET o.status = 'queued', o.last_error = NULL
                    WHERE o.channel = 'whatsapp' AND o.status = 'cancelled' AND o.last_error = 'Replied STOP on WhatsApp'
                    AND o.expires_at > ? AND b.contact_id IN ({$in})")->execute([tg_ist(rp_now($config))]);
            }
        }
        tg_audit($config, 'system', 'whatsapp_' . $kind, tg_wa_pretty($from) . ($ids ? ' (contact ' . implode(', ', $ids) . ')' : ' (not a contact)'));
    }

    $profile = '';
    foreach ($profiles as $p) {
        if (tg_wa_number((string) ($p['wa_id'] ?? '')) === $from) {
            $profile = rp_clean($p['profile']['name'] ?? '', 80);
        }
    }
    $label = $text !== '' ? $text : match ($type) {
        'image' => '[a photo]', 'video' => '[a video]', 'audio' => '[a voice note]', 'document' => '[a document]',
        'sticker' => '[a sticker]', 'location' => '[a location]', 'contacts' => '[a contact card]', default => '[' . $type . ' message]',
    };
    return ['kind' => $kind, 'from' => $from, 'name' => $profile, 'text' => mb_substr($label, 0, 2000), 'contacts' => $contacts];
}

/** The email Deenu gets for a WhatsApp message, because this number has no app to read it in. */
function tg_wa_reply_email(array $config, array $r, array $bookings): array
{
    $who = $r['name'] !== '' ? $r['name'] : 'Someone';
    $lines = [
        $who . ' wrote to the business WhatsApp (' . tg_wa_pretty($r['from']) . '):', '',
        $r['text'], '',
    ];
    if ($bookings) {
        $lines[] = 'Who this is:';
        foreach ($bookings as $b) {
            $lines[] = '- ' . $b['name'] . ' <' . $b['email'] . '>, ' . ($b['status'] === 'paid' ? 'paid' : 'not paid')
                . ($b['session_at'] ? ', session ' . (new DateTimeImmutable($b['session_at']))->format('D j M') : '');
        }
        $lines[] = '';
    } else {
        $lines[] = 'This number is not a buyer or lead. It may be a portfolio enquiry.';
        $lines[] = '';
    }
    $lines[] = 'This number runs on the WhatsApp API only, so the message is not on any phone.';
    $lines[] = 'Reply from your own WhatsApp: https://wa.me/' . $r['from'];
    return ['subject' => 'WhatsApp from ' . $who . ' (' . tg_wa_pretty($r['from']) . ')', 'body' => implode("\n", $lines)];
}

/** Bookings for the contacts behind a number, newest first, for the reply email. */
function tg_wa_bookings(PDO $pdo, array $contacts): array
{
    $ids = array_map('intval', array_column($contacts, 'id'));
    if (!$ids) {
        return [];
    }
    return $pdo->query('SELECT c.name, c.email, b.status, b.session_at FROM bookings b JOIN contacts c ON c.id = b.contact_id
        WHERE b.contact_id IN (' . implode(',', $ids) . ') ORDER BY b.id DESC LIMIT 3')->fetchAll();
}
