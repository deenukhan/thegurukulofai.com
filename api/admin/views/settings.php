<?php
/**
 * Settings: the few things that change often. Each change takes effect at once and
 * can be undone from the history. With two-step sign-in on, each also needs a code.
 */
require_once dirname(__DIR__) . '/lib/settings.php';

return static function (array $d): array {
    $pdo = $d['pdo'];
    $config = $d['config'];
    $defs = admin_setting_defs();

    $source = static function (string $from): string {
        return $from === 'dashboard' ? ui_pill('Set here', 'info') : ui_pill('From the config file', 'neutral');
    };
    $reset = static function (string $name, string $from, string $anchor): string {
        if ($from !== 'dashboard') {
            return '';
        }
        return '<details class="reset"><summary>Use the config file value instead</summary>'
            . ui_post('setting_reset', ['name' => $name, '#' => $anchor], ui_code_input(), 'Switch back', 'quiet', 'inline') . '</details>';
    };

    // ---- Zoom ----
    [$zoom, $zoomFrom] = admin_setting($config, 'zoom.join_url');
    $zoomOk = tg_zoom_url(['zoom' => ['join_url' => (string) $zoom]]) !== null;
    $next = rp_next_session($d['now']);
    $live = admin_session_live($d['now']);
    $zoomBody = ui_facts([
        ['In use', $zoomOk ? '<span class="value-link">' . h((string) $zoom) . '</span>' : ui_pill('Not set', 'bad')],
        ['Comes from', $source($zoomFrom)],
        ['Used by', 'Every buyer\'s personal join link and reminder'],
    ])
        . ($live ? '<p class="notice notice-bad">' . icon('alert') . 'A session is live now. Changing the link would send late joiners to a different room.</p>'
            : '<p class="notice">' . icon('info') . 'Change it after a session ends, ready for ' . h($next->format('D j M')) . '. Never during one.</p>')
        . ui_post('setting_save', ['#' => 'zoom'],
            ui_field('New Zoom link', 'values[zoom.join_url]', 'type="url" required placeholder="https://us06web.zoom.us/j/…" autocomplete="off"')
            . ($live ? '<label class="tick"><input type="checkbox" name="live_ok" value="1"> I know a session is live and still want to change it</label>' : '')
            . ui_code_input(), 'Save Zoom link', 'primary', 'stack')
        . $reset('zoom.join_url', $zoomFrom, 'zoom');
    $zoomCard = ui_card('Zoom link', $zoomBody, ['id' => 'zoom', 'sub' => 'Changes weekly. Buyers never see this link, only their personal join link.']);

    // ---- Community ----
    [$community, $communityFrom] = admin_setting($config, 'community_url');
    $communityOk = is_string($community) && preg_match('~^https://chat\.whatsapp\.com/~', $community) && !str_contains($community, 'REPLACE');
    $communityBody = ui_facts([
        ['In use', $communityOk ? '<span class="value-link">' . h($community) . '</span>' : ui_pill('Not set', 'bad')],
        ['Comes from', $source($communityFrom)],
        ['Used by', 'Payment confirmation email and thank-you page'],
    ])
        . ui_post('setting_save', ['#' => 'community'],
            ui_field('New invite link', 'values[community_url]', 'type="url" required placeholder="https://chat.whatsapp.com/…" autocomplete="off"')
            . ui_code_input(), 'Save community link', 'primary', 'stack')
        . $reset('community_url', $communityFrom, 'community');
    $communityCard = ui_card('WhatsApp community link', $communityBody, ['id' => 'community', 'sub' => 'Where every buyer goes after paying.']);

    // ---- Coupon ----
    $coupon = admin_coupon($config);
    if ($coupon === null) {
        $couponCard = ui_card('Coupon', ui_empty('No coupon in the config file', 'Add a coupon block to the private config first. The code itself is never shown or changed here.'));
    } else {
        [$paused, $pausedFrom] = admin_setting($config, 'coupon.paused');
        [$max, $maxFrom] = admin_setting($config, 'coupon.max_uses');
        $couponBody = ui_facts([
            ['Status', $paused ? ui_pill('Paused', 'warn') : ui_pill('On', 'good')],
            ['Used', h(num($coupon['used'])) . ($max !== null ? ' of ' . h(num((int) $max)) : ' · no limit')],
            ['Seat price with it', h(money($coupon['seat_paise']))],
            ['Comes from', $source($pausedFrom === 'dashboard' || $maxFrom === 'dashboard' ? 'dashboard' : 'config')],
        ])
            . ui_post('setting_save', ['#' => 'coupon'],
                '<label class="field"><span>Coupon</span><select name="values[coupon.paused]">'
                . '<option value="0"' . ($paused ? '' : ' selected') . '>On: people can use it</option>'
                . '<option value="1"' . ($paused ? ' selected' : '') . '>Paused: nobody can use it</option></select></label>'
                . ui_field('Use limit', 'values[coupon.max_uses]', 'type="number" min="0" max="10000" required value="' . h((string) ($max ?? $coupon['used'])) . '"',
                    'Uses so far stay counted. Set it above ' . $coupon['used'] . ' to allow more.')
                . ui_code_input(), 'Save coupon', 'primary', 'stack')
            . $reset('coupon', $pausedFrom === 'dashboard' || $maxFrom === 'dashboard' ? 'dashboard' : 'config', 'coupon');
        $couponCard = ui_card('Coupon', $couponBody, ['id' => 'coupon', 'sub' => 'Pause it, or change how many times it can be used.']);
    }

    // ---- WhatsApp ----
    if (!tg_wa_ready($config) || empty($config['whatsapp']['enabled'])) {
        $waCard = ui_card('WhatsApp messages', ui_empty(tg_wa_ready($config) ? 'Switched off in the config' : 'Not set up yet',
            'A pause switch shows here once WhatsApp is switched on in the private config.'), ['id' => 'whatsapp']);
    } else {
        [$waPaused, $waFrom] = admin_setting($config, 'whatsapp.paused');
        $waPaused = (bool) $waPaused;
        $waCard = ui_card('WhatsApp messages', ui_facts([
            ['Status', $waPaused ? ui_pill('Paused', 'warn') : ui_pill('On', 'good')],
            ['Comes from', $source($waFrom)],
            ['Used by', 'The WhatsApp confirmation and reminders. Emails are not affected.'],
        ])
            . '<p class="notice">' . icon('info') . 'Pause it if a WhatsApp message goes wrong. Reminders whose time passes while paused are skipped, not sent late.</p>'
            . ui_post('setting_save', ['#' => 'whatsapp'],
                '<label class="field"><span>WhatsApp messages</span><select name="values[whatsapp.paused]">'
                . '<option value="0"' . ($waPaused ? '' : ' selected') . '>On: buyers get them</option>'
                . '<option value="1"' . ($waPaused ? ' selected' : '') . '>Paused: nothing goes out on WhatsApp</option></select></label>'
                . ui_code_input(), 'Save', 'primary', 'stack')
            . $reset('whatsapp.paused', $waFrom, 'whatsapp')
            . admin_nudge_setting($config, $reset)
            . admin_forward_setting($config, $reset), ['id' => 'whatsapp', 'sub' => 'A quick off switch, the checkout coupon and reply emails.']);
    }

    // ---- Two-step sign-in ----
    if (!ADMIN_TWO_STEP) {
        $securityCard = ui_card('Sign-in security', ui_facts([
            ['Two-step sign-in', ui_pill('Off', 'neutral') . ' <span class="muted">password only</span>'],
            ['Signs out after', '30 minutes idle'],
        ]) . '<p class="notice">' . icon('info') . '<span>To turn it on, add <code>\'two_step\' => true</code> to the admin block of the private config. The next sign-in then sets it up with your phone.</span></p>', ['id' => 'security']);
    } else {
        $auth = totp_record($pdo);
        $left = count(json_decode((string) ($auth['backup_codes'] ?? '[]'), true) ?: []);
        $securityBody = ui_facts([
            ['Two-step sign-in', ui_pill('On', 'good') . ' <span class="muted">since ' . h(when($auth['enabled_at'] ?? null, 'j M Y')) . '</span>'],
            ['Backup codes left', $left <= 2 ? ui_pill((string) $left, 'warn') : h((string) $left)],
            ['Signs out after', '30 minutes idle'],
        ])
            . '<details class="reset"><summary>Make new backup codes</summary>'
            . ui_post('backup_codes_new', ['#' => 'security'], '<p class="muted small">The old codes stop working.</p>' . ui_code_input(), 'Make new codes', 'quiet', 'stack') . '</details>'
            . '<p class="notice">' . icon('info') . 'Lost your phone and your backup codes? In hPanel, open phpMyAdmin and empty the admin_auth table. The next sign-in sets it up again.</p>';
        $securityCard = ui_card('Sign-in security', $securityBody, ['id' => 'security']);
    }

    // ---- History ----
    $changes = rows($pdo, 'SELECT * FROM setting_changes ORDER BY id DESC LIMIT 50');
    $show = static function (string $name, ?string $json) use ($defs): string {
        if ($json === null) {
            return '<span class="muted">Config file value</span>';
        }
        $text = $defs[$name]['show'](json_decode($json, true));
        return '<span class="clip value-link" title="' . h($text) . '">' . h($text) . '</span>';
    };
    $undoId = (int) ($_GET['undo'] ?? 0);
    $list = [];
    foreach ($changes as $c) {
        if (!isset($defs[$c['name']])) {
            continue;
        }
        $undo = $c['id'] == $undoId
            ? ui_post('setting_undo', ['id' => $c['id'], '#' => 'history'], ui_code_input(), 'Undo now', 'primary', 'inline')
            : ui_button('Undo', admin_url(['view' => 'settings', 'undo' => $c['id']]) . '#c' . (int) $c['id'], 'quiet');
        $list[] = ['<span class="anchor" id="c' . (int) $c['id'] . '"></span>' . h(when($c['at'], 'j M, g:i A')), h($defs[$c['name']]['label']), $show($c['name'], $c['old_value']), $show($c['name'], $c['new_value']), $undo];
    }
    $history = ui_card('Change history', ui_table(
        [['When', 'muted nowrap'], ['Setting', ''], ['Was', ''], ['Now', ''], ['', 'num']],
        $list, ['empty' => 'No changes yet. Everything comes from the config file.']
    ), ['wide' => true, 'id' => 'history', 'flush' => true, 'sub' => 'Undo puts back the value from before that change.']);

    return [
        'intro' => 'The few things that change often. Each change is live at once' . (ADMIN_TWO_STEP ? ' and needs a code from your authenticator app.' : '.'),
        'body'  => ui_grid($zoomCard, $communityCard, $couponCard, $waCard, $securityCard, $history)
            . '<p class="page-note">Prices, the session day, and email and WhatsApp wording stay in code on purpose: they change rarely and break expensively. Ask Claude to change them.</p>',
    ];
};

/** Whether each WhatsApp message to the business number is also emailed, inside the WhatsApp card. Also on the Inbox page. */
function admin_forward_setting(array $config, callable $reset): string
{
    [$on, $from] = admin_setting($config, 'whatsapp.forward_replies');
    $on = $on !== false;
    return '<h3 class="sub-head">Reply emails</h3>'
        . '<p class="notice">' . icon('info') . 'When on, each WhatsApp message people send you is also emailed to you, with a link to answer it in the Inbox.</p>'
        . ui_post('setting_save', ['#' => 'whatsapp'],
            ui_select('Email me each WhatsApp reply', 'values[whatsapp.forward_replies]', ['1' => 'On: email me each one', '0' => 'Off: the inbox only'], $on ? '1' : '0')
            . ui_code_input(), 'Save', 'primary', 'stack')
        . $reset('whatsapp.forward_replies', $from, 'whatsapp');
}

/** The checkout coupon's on/off form, inside the WhatsApp card. */
function admin_nudge_setting(array $config, callable $reset): string
{
    [$on, $from] = admin_setting($config, 'whatsapp.checkout_coupon');
    $on = (bool) $on;
    $off = (int) (TG_NUDGE_OFF_PAISE / 100);
    return '<h3 class="sub-head">Checkout coupon</h3>'
        . '<p class="notice">' . icon('info') . 'When on, someone who filled the pop-up but has not paid 20 minutes later gets one WhatsApp with a personal '
        . h('₹' . $off) . '-off coupon in their name (for example PRIYA' . $off . '), working for 2 hours. One per person per session. Marketing rate, about ₹0.86 each.</p>'
        . ui_post('setting_save', ['#' => 'whatsapp'],
            '<label class="field"><span>Checkout coupon</span><select name="values[whatsapp.checkout_coupon]">'
            . '<option value="1"' . ($on ? ' selected' : '') . '>On: send it to people who did not pay</option>'
            . '<option value="0"' . ($on ? '' : ' selected') . '>Off</option></select></label>'
            . ui_code_input(), 'Save', 'primary', 'stack')
        . $reset('whatsapp.checkout_coupon', $from, 'whatsapp');
}
