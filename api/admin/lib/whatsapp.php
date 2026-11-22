<?php
/**
 * The dashboard's WhatsApp parts: what Meta says about the number and the templates
 * (read only, cached 10 minutes), the health checks, and the WhatsApp tab on the
 * Messages page. Sending and the webhook live in api/razorpay/whatsapp.php.
 */

const ADMIN_WA_CACHE = 600;

function admin_wa_cache_key(array $config): string
{
    return 'wa:' . (string) ($config['whatsapp']['phone_number_id'] ?? '') . ':' . (string) ($config['whatsapp']['waba_id'] ?? '');
}

/** Forgets the saved answer, so the next page shows Meta's latest after a change. */
function admin_wa_forget(array $config): void
{
    @unlink(admin_private($config) . '/razorpay-dash-cache/' . hash('sha256', admin_wa_cache_key($config)) . '.json');
}

/**
 * The number, the templates and the webhook link as Meta sees them.
 * Returns ok, error, phone (fields), templates (name => state, or null) and subscribed (count, or null).
 */
function admin_wa_meta(array $config): array
{
    return admin_cached($config, admin_wa_cache_key($config), ADMIN_WA_CACHE, static function () use ($config): array {
        $id = rawurlencode((string) $config['whatsapp']['phone_number_id']);
        [$status, $phone] = tg_wa_graph($config, 'GET', $id, null, ['fields' => 'display_phone_number,verified_name,name_status,quality_rating,platform_type,status']);
        if ($status !== 200) {
            // Older or newer Graph versions may not know every field; ask for the basics.
            [$status, $phone] = tg_wa_graph($config, 'GET', $id, null, ['fields' => 'display_phone_number,verified_name,quality_rating']);
        }
        if ($status !== 200 || !is_array($phone)) {
            return ['ok' => false, 'error' => tg_wa_error_text($config, $status, $phone)];
        }
        [$s2, $subs] = tg_wa_graph($config, 'GET', rawurlencode((string) $config['whatsapp']['waba_id']) . '/subscribed_apps');
        return [
            'ok'         => true,
            'phone'      => array_map(static fn($v) => is_scalar($v) ? (string) $v : '', $phone),
            'templates'  => tg_wa_template_states($config),
            'subscribed' => $s2 === 200 && is_array($subs['data'] ?? null) ? count($subs['data']) : null,
        ];
    });
}

/** True when Meta says the number is not registered for the Cloud API (only when Meta told us). */
function admin_wa_needs_register(array $phone): bool
{
    $platform = strtoupper((string) ($phone['platform_type'] ?? ''));
    $status = strtoupper((string) ($phone['status'] ?? ''));
    return ($platform !== '' && $platform !== 'CLOUD_API') || in_array($status, ['PENDING', 'UNVERIFIED', 'DISCONNECTED'], true);
}

/** When Meta last reached our webhook, or null. */
function admin_wa_heard(array $config): ?int
{
    $data = json_decode((string) @file_get_contents(admin_private($config) . '/whatsapp-webhook.last'), true);
    return is_array($data) && isset($data['at']) ? (int) $data['at'] : null;
}

/** How many of our templates Meta approved, and the ones that need a look. */
function admin_wa_template_summary(array $config, ?array $states): array
{
    $out = ['approved' => 0, 'total' => 0, 'missing' => 0, 'pending' => 0, 'rejected' => 0, 'recategorised' => 0];
    foreach (tg_wa_templates($config) as $t) {
        $out['total']++;
        $s = $states[$t['name']] ?? null;
        if ($s === null) {
            $out['missing']++;
            continue;
        }
        $state = strtoupper($s['status']);
        $out['approved'] += $state === 'APPROVED' ? 1 : 0;
        $out['pending'] += in_array($state, ['PENDING', 'IN_APPEAL'], true) ? 1 : 0;
        $out['rejected'] += in_array($state, ['REJECTED', 'PAUSED', 'DISABLED', 'FLAGGED'], true) ? 1 : 0;
        $out['recategorised'] += $s['category'] !== '' && strtoupper($s['category']) !== 'UTILITY' ? 1 : 0;
    }
    return $out;
}

/** The WhatsApp health checks. $add is admin_checks' adder. */
function admin_wa_checks(PDO $pdo, array $config, int $now, callable $add): void
{
    $block = $config['whatsapp'] ?? null;
    if (!is_array($block) || array_keys($block) === ['paused']) {
        $add('info', 'WhatsApp', 'WhatsApp messages are not set up', 'Buyers get emails only. The setup steps are in docs/whatsapp-setup.md.');
        return;
    }
    if (!tg_wa_ready($config)) {
        $add('warn', 'WhatsApp', 'The WhatsApp settings are not finished', 'A value in the private config\'s whatsapp block still says PASTE.');
        return;
    }
    $meta = admin_wa_meta($config);
    if (empty($meta['ok'])) {
        $add('bad', 'WhatsApp', 'Meta refused the WhatsApp token', (string) ($meta['error'] ?? ''), 'emails');
        return;
    }
    $t = admin_wa_template_summary($config, $meta['templates']);
    if ($meta['templates'] === null) {
        $add('warn', 'WhatsApp', 'Could not read the WhatsApp templates', 'The token may be missing whatsapp_business_management.', 'emails');
    } elseif ($t['rejected'] > 0) {
        $add('bad', 'WhatsApp', $t['rejected'] . ' WhatsApp template' . ($t['rejected'] === 1 ? ' was' : 's were') . ' rejected or paused', 'Those messages are not going out. See the reason on the WhatsApp tab.', 'emails');
    } elseif ($t['missing'] > 0) {
        $add('warn', 'WhatsApp', $t['missing'] . ' WhatsApp template' . ($t['missing'] === 1 ? ' is' : 's are') . ' not with Meta yet', 'Submit them from the WhatsApp tab.', 'emails');
    } elseif ($t['pending'] > 0) {
        $add('info', 'WhatsApp', $t['pending'] . ' WhatsApp template' . ($t['pending'] === 1 ? ' is' : 's are') . ' waiting for Meta\'s review', 'Usually minutes, sometimes a day.', 'emails');
    } else {
        $add('ok', 'WhatsApp', 'All ' . $t['total'] . ' WhatsApp templates are approved');
    }
    if ($t['recategorised'] > 0) {
        $add('warn', 'WhatsApp', 'Meta moved a template out of "utility"', 'Each of those messages now costs the marketing rate. See the WhatsApp tab.', 'emails');
    }
    if (admin_wa_needs_register($meta['phone'])) {
        $add('bad', 'WhatsApp', 'The WhatsApp number is not registered for the Cloud API', 'Register it once on the WhatsApp tab.', 'emails');
    }
    $name = strtoupper((string) ($meta['phone']['name_status'] ?? ''));
    if ($name !== '' && $name !== 'APPROVED' && $name !== 'AVAILABLE_WITHOUT_REVIEW') {
        $add('warn', 'WhatsApp', 'The WhatsApp display name is not approved yet', 'Meta says: ' . strtolower($name) . '. Check WhatsApp Manager > Phone numbers.');
    }
    $quality = strtoupper((string) ($meta['phone']['quality_rating'] ?? ''));
    if (in_array($quality, ['RED', 'LOW'], true)) {
        $add('bad', 'WhatsApp', 'WhatsApp number quality is low', 'Too many people blocked or reported it. Meta may limit sending.');
    }
    if ($meta['subscribed'] === 0) {
        $add('bad', 'WhatsApp', 'Meta is not sending delivery news to this site', 'Press "Connect webhook" on the WhatsApp tab.', 'emails');
    }
    $heard = admin_wa_heard($config);
    if ($heard === null) {
        $add('warn', 'WhatsApp', 'Meta has not reached the WhatsApp webhook yet', 'Set the callback URL in the Meta app, then send a test message.', 'emails');
    }
    $failed = (int) value($pdo, "SELECT COUNT(*) FROM outbox WHERE channel = 'whatsapp' AND status IN ('failed', 'expired')
        AND due_at >= ? AND COALESCE(last_error, '') <> 'WhatsApp was off or paused'", [tg_ist($now - 7 * 86400)]);
    if ($failed > 0) {
        $add('warn', 'WhatsApp', $failed . ' WhatsApp message' . ($failed === 1 ? '' : 's') . ' failed this week', 'See the send log for who and why.', 'emails');
    }

    if (empty($config['whatsapp']['enabled'])) {
        $add('info', 'WhatsApp', 'WhatsApp sending is switched off in the config', 'Set \'enabled\' => true in the whatsapp block when the templates are approved.');
    } elseif (tg_wa_paused($config)) {
        $add('warn', 'WhatsApp', 'WhatsApp messages are paused', 'Nothing goes out on WhatsApp until you switch it on in Settings.', 'settings');
    } elseif (tg_wa_test_numbers($config) !== []) {
        $n = count(tg_wa_test_numbers($config));
        $add('warn', 'WhatsApp', 'WhatsApp is in test mode', 'Only ' . $n . ' test number' . ($n === 1 ? '' : 's') . ' get messages. Empty test_numbers in the config to send to buyers.');
    } else {
        $add('ok', 'WhatsApp', 'WhatsApp messages are on', 'Buyers get the confirmation and reminders on WhatsApp and by email.');
    }
}

function admin_wa_state_pill(?array $s): string
{
    if ($s === null) {
        return ui_pill('Not submitted', 'neutral');
    }
    return match (strtoupper($s['status'])) {
        'APPROVED'              => ui_pill('Approved', 'good'),
        'PENDING', 'IN_APPEAL'  => ui_pill('In review', 'info'),
        'REJECTED'              => ui_pill('Rejected', 'bad'),
        'PAUSED', 'DISABLED'    => ui_pill(ucfirst(strtolower($s['status'])), 'bad'),
        default                 => ui_pill(ucfirst(strtolower($s['status'])), 'warn'),
    };
}

/** One WhatsApp message as the buyer sees it. */
function ui_wa_bubble(array $msg): string
{
    $buttons = '';
    foreach ($msg['buttons'] as $b) {
        $buttons .= '<span class="wa-button" title="' . h($b['url']) . '">' . icon('arrow') . h($b['text']) . '</span>';
    }
    return '<div class="wa-chat"><div class="wa-bubble"><p class="wa-text">' . h($msg['body']) . '</p>'
        . '<p class="wa-foot">' . h($msg['footer']) . '</p></div>'
        . ($buttons !== '' ? '<div class="wa-buttons">' . $buttons . '</div>' : '') . '</div>';
}

/** The WhatsApp tab of the Messages page. */
function admin_wa_page(PDO $pdo, array $config, int $now): string
{
    $templates = tg_wa_templates($config);
    $ready = tg_wa_ready($config);
    $meta = $ready ? admin_wa_meta($config) : null;
    $states = $meta['templates'] ?? null;
    $sum = admin_wa_template_summary($config, $states);
    $test = tg_wa_test_numbers($config);

    $mode = !$ready ? ['Not set up', 'neutral', 'Fill in the whatsapp block in the private config']
        : (empty($config['whatsapp']['enabled']) ? ['Off', 'neutral', 'Switched off in the config']
        : (tg_wa_paused($config) ? ['Paused', 'warn', 'Switch it back on in Settings']
        : ($test !== [] ? ['Test mode', 'warn', count($test) . ' test number' . (count($test) === 1 ? '' : 's') . ' only'] : ['On', 'good', 'Every buyer who agreed'])));
    $week = tg_ist($now - 7 * 86400);
    $k = row($pdo, "SELECT COALESCE(SUM(status = 'sent' AND sent_at >= ?), 0) AS sent, COALESCE(SUM(read_at >= ?), 0) AS seen,
            COALESCE(SUM(status IN ('failed', 'expired') AND due_at >= ? AND COALESCE(last_error, '') <> 'WhatsApp was off or paused'), 0) AS failed
        FROM outbox WHERE channel = 'whatsapp'", [$week, $week, $week]);
    $stops = (int) value($pdo, 'SELECT COUNT(*) FROM contacts WHERE wa_optout_at IS NOT NULL');
    $html = ui_kpis([
        ['label' => 'WhatsApp', 'value' => ui_pill($mode[0], $mode[1]), 'note' => $mode[2]],
        ['label' => 'Templates approved', 'value' => h($sum['approved'] . ' of ' . $sum['total']),
         'tone' => $sum['rejected'] > 0 ? 'bad' : null, 'note' => $states === null ? 'Not read from Meta' : ($sum['pending'] > 0 ? $sum['pending'] . ' in review' : '')],
        ['label' => 'Sent this week', 'value' => h(num((int) $k['sent'])), 'tone' => (int) $k['failed'] > 0 ? 'bad' : null,
         'note' => num((int) $k['seen']) . ' read' . ((int) $k['failed'] > 0 ? ' · ' . num((int) $k['failed']) . ' failed' : '')],
        ['label' => 'Said STOP', 'value' => h(num($stops)), 'note' => 'They get emails only'],
    ]);

    // ---- Connection ----
    if (!$ready) {
        $connection = ui_card('Connection', ui_empty('Not connected yet', 'Paste the values from Meta into the whatsapp block of the private config. The steps are in docs/whatsapp-setup.md.'));
    } elseif (empty($meta['ok'])) {
        $connection = ui_card('Connection', '<p class="notice notice-bad">' . icon('alert') . h((string) ($meta['error'] ?? 'Meta did not answer.')) . '</p>'
            . '<p class="notice">' . icon('info') . 'Check the access token and the phone number ID in the private config.</p>');
    } else {
        $p = $meta['phone'];
        $heard = admin_wa_heard($config);
        $nameStatus = strtoupper($p['name_status'] ?? '');
        $connection = ui_card('Connection', ui_facts([
            ['Number', h($p['display_phone_number'] ?? '') . (!empty($p['verified_name']) ? ' <span class="muted">· ' . h($p['verified_name']) . '</span>' : '')],
            ['Display name', $nameStatus === '' ? '<span class="muted">Meta did not say</span>'
                : ($nameStatus === 'APPROVED' ? ui_pill('Approved', 'good') : ui_pill(ucfirst(strtolower(str_replace('_', ' ', $nameStatus))), 'warn'))],
            ['Quality', h(ucfirst(strtolower($p['quality_rating'] ?? 'unknown')))],
            ['Webhook', ($meta['subscribed'] === 0 ? ui_pill('Not connected', 'bad') : ($meta['subscribed'] === null ? ui_pill('Unknown', 'neutral') : ui_pill('Connected', 'good')))
                . ' <span class="muted">' . ($heard ? 'Meta last called ' . h(ago($heard, $now)) : 'Meta has not called yet') . '</span>'],
            ['Test numbers', $test ? h(implode(', ', array_map('tg_wa_pretty', $test))) : '<span class="muted">None: every buyer who agreed gets messages</span>'],
        ]) . ($meta['subscribed'] === 0 ? ui_post('wa_subscribe', ['#' => 'wa-connection'], '', 'Connect webhook', 'primary', 'stack') : '')
            . (admin_wa_needs_register($p) ? ui_post('wa_register', ['#' => 'wa-connection'],
                '<p class="notice notice-bad">' . icon('alert') . 'Meta says this number is not on the Cloud API yet. Register it once to start sending.</p>'
                . ui_field('Choose a 6-digit PIN', 'pin', 'inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="off"',
                    'This becomes the number\'s two-step PIN. Keep it in your password manager. Meta allows 10 tries in 3 days.')
                . ui_code_input(), 'Register number', 'primary', 'stack') : '')
            . '<p class="notice">' . icon('info') . 'Replies to this number are emailed to you. It has no app, so reply from your own WhatsApp.</p>',
            ['id' => 'wa-connection', 'sub' => 'As Meta sees it. Refreshed every 10 minutes.']);
    }

    // ---- Templates ----
    $rows = [];
    foreach ($templates as $key => $t) {
        $s = $states[$t['name']] ?? null;
        $note = $s && $s['reason'] !== '' && $s['reason'] !== 'NONE' ? '<small class="sub">' . h($s['reason']) . '</small>' : '';
        if ($s && in_array(strtoupper($s['status']), ['REJECTED', 'PAUSED', 'DISABLED'], true)) {
            $note .= '<small class="sub">Needs new wording under a new name. Ask Claude.</small>';
        }
        $cat = $s && $s['category'] !== '' && strtoupper($s['category']) !== 'UTILITY' ? ' ' . ui_pill(ucfirst(strtolower($s['category'])), 'warn') : '';
        $rows[] = [h($t['label']) . '<small class="sub">' . h($t['when']) . ' · <code>' . h($t['name']) . '</code></small>', admin_wa_state_pill($s) . $cat . $note];
    }
    $submit = $ready && !empty($meta['ok']) && $sum['missing'] > 0
        ? ui_post('wa_submit', ['#' => 'wa-templates'], '<p class="muted small">Sends the ' . $sum['missing'] . ' missing template' . ($sum['missing'] === 1 ? '' : 's')
            . ' to Meta for review as "utility". Ones already there are left alone.</p>', 'Submit to Meta', 'primary', 'stack')
        : '';
    $templateCard = ui_card('Templates', ui_table([['Message', ''], ['Status', '']], $rows) . $submit,
        ['id' => 'wa-templates', 'flush' => $submit === '', 'sub' => 'Meta must approve each one before it can be sent.']);

    // ---- Preview ----
    $pick = array_key_exists($_GET['t'] ?? '', $templates) ? (string) $_GET['t'] : 'confirm';
    $next = rp_next_session($now);
    $sample = ['name' => 'Priya Sharma', 'session_at' => $next->format('Y-m-d H:i:s'), 'join_token' => 'sample0000000000000000ab'];
    $sampleConfig = $config;
    if (!preg_match('~^https://chat\.whatsapp\.com/[A-Za-z0-9]{10,40}$~', (string) ($config['community_url'] ?? ''))) {
        $sampleConfig['community_url'] = 'https://chat.whatsapp.com/YourCommunityLink';
    }
    $chips = [];
    foreach ($templates as $key => $t) {
        $chips[$key] = $t['label'];
    }
    $preview = ui_chips($chips, $pick, 't', ['view' => 'emails', 'tab' => 'whatsapp'])
        . '<p class="tpl-note">' . icon('info') . h($templates[$pick]['when'] . '. Shown with a sample buyer; each real message uses that buyer\'s name, session and join link.') . '</p>'
        . ui_wa_bubble(tg_wa_render($config, $pick, tg_wa_values($sampleConfig, $pick, $sample)));
    $previewCard = ui_card('What buyers see', $preview, ['id' => 'wa-preview', 'sub' => 'Sent as well as the email, never instead of it.']);

    // ---- Test send ----
    if ($ready && $test !== []) {
        $options = '';
        foreach ($templates as $key => $t) {
            $options .= '<option value="' . h($key) . '"' . ($key === $pick ? ' selected' : '') . '>' . h($t['label']) . '</option>';
        }
        $numbers = '';
        foreach ($test as $n) {
            $numbers .= '<option value="' . h($n) . '">' . h(tg_wa_pretty($n)) . '</option>';
        }
        $testCard = ui_card('Send a test', ui_post('wa_test', ['#' => 'wa-test'],
            '<label class="field"><span>Message</span><select name="template">' . $options . '</select></label>'
            . '<label class="field"><span>To</span><select name="to">' . $numbers . '</select></label>'
            . '<p class="muted small">Uses sample details and a join link that goes nowhere. Meta charges about ₹0.15. The template must be approved first.</p>',
            'Send test message', 'quiet', 'stack'), ['id' => 'wa-test', 'sub' => 'Only to the test numbers in the config.']);
    } else {
        $testCard = ui_card('Send a test', ui_empty('No test numbers', 'Add your own number to test_numbers in the whatsapp block of the private config.'), ['id' => 'wa-test']);
    }

    return $html . ui_grid($connection, $templateCard, $previewCard, $testCard);
}
