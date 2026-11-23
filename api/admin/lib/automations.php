<?php
/**
 * The Automations page (views/automations.php): messages that send themselves when
 * something happens. The built-in booking messages are shown locked (the checkout coupon
 * keeps its on/off switch); your own rules are listed, edited, switched on and off,
 * tested and deleted here; the two ready-made rules start off and need their template.
 *
 * Planning and sending live in api/razorpay/wa_rules.php and outbox.php; this file is the
 * dashboard's side. Every change is a POST through lib/actions.php into admin_rule_action:
 * page token, audit, message, back.
 */
require_once __DIR__ . '/broadcasts.php';

/** "On" or "Off", as a pill. */
function admin_rule_status_pill(array $r): string
{
    return $r['enabled'] ? ui_pill('On', 'good') : ui_pill('Off', 'neutral');
}

/** The numbers for each rule (or one): total, sent, delivered, seen, replied, failed, not_sent, waiting, month. Keyed by rule id. */
function admin_rule_stats(PDO $pdo, int $now, ?int $id = null): array
{
    $out = [];
    $rows = rows($pdo, "SELECT o.source_id AS id, COUNT(*) AS total, COALESCE(SUM(o.status = 'sent'), 0) AS sent,
            COALESCE(SUM(o.status = 'sent' AND o.delivered_at IS NOT NULL), 0) AS delivered,
            COALESCE(SUM(o.status = 'sent' AND o.read_at IS NOT NULL), 0) AS seen,
            COALESCE(SUM(o.status = 'sent' AND " . admin_bc_replied_sql() . "), 0) AS replied,
            COALESCE(SUM(o.status = 'failed'), 0) AS failed,
            COALESCE(SUM(o.status IN ('expired', 'cancelled')), 0) AS not_sent,
            COALESCE(SUM(o.status IN ('queued', 'sending')), 0) AS waiting,
            COALESCE(SUM(o.status = 'sent' AND o.sent_at >= ?), 0) AS month
        FROM outbox o WHERE o.source = 'automation'" . ($id !== null ? ' AND o.source_id = ?' : '') . ' GROUP BY o.source_id',
        $id !== null ? [tg_ist($now - 30 * 86400), $id] : [tg_ist($now - 30 * 86400)]);
    foreach ($rows as $r) {
        $out[(int) $r['id']] = array_map('intval', $r);
    }
    return $out;
}

/** An empty stats row. */
function admin_rule_no_stats(): array
{
    return ['total' => 0, 'sent' => 0, 'delivered' => 0, 'seen' => 0, 'replied' => 0, 'failed' => 0, 'not_sent' => 0, 'waiting' => 0, 'month' => 0];
}

/** The four numbers at the top of the list. */
function admin_rule_list_kpis(PDO $pdo, int $now): string
{
    $on = (int) value($pdo, 'SELECT COUNT(*) FROM wa_rules WHERE enabled = 1');
    $all = (int) value($pdo, 'SELECT COUNT(*) FROM wa_rules');
    $k = row($pdo, "SELECT COUNT(*) AS n, COALESCE(SUM(o.read_at IS NOT NULL), 0) AS seen, COALESCE(SUM(" . admin_bc_replied_sql() . "), 0) AS replied
        FROM outbox o WHERE o.source = 'automation' AND o.status = 'sent' AND o.sent_at >= ?", [tg_ist($now - 30 * 86400)]);
    return ui_kpis([
        ['label' => 'Rules on', 'value' => h(num($on)), 'note' => 'Of ' . num($all) . ' rules'],
        ['label' => 'Sent by rules this month', 'value' => h(num((int) $k['n'])), 'note' => 'Last 30 days'],
        ['label' => 'Read rate', 'value' => h(pct((int) $k['seen'], (int) $k['n'])), 'note' => num((int) $k['seen']) . ' read'],
        ['label' => 'Replies', 'value' => h(num((int) $k['replied'])), 'note' => 'Wrote back within 72 hours'],
    ]);
}

/** The four numbers at the top of one rule. */
function admin_rule_kpis(array $r, array $s): string
{
    return ui_kpis([
        ['label' => 'Status', 'value' => $r['enabled'] ? 'On' : 'Off', 'note' => $r['enabled'] && $r['enabled_at'] ? 'Since ' . when($r['enabled_at']) : 'Switch it on below',
         'tone' => $r['enabled'] ? 'good' : null],
        ['label' => 'Sent', 'value' => h(num($s['sent'])), 'note' => num($s['month']) . ' in the last 30 days'],
        ['label' => 'Read rate', 'value' => h(pct($s['seen'], $s['sent'])), 'note' => num($s['seen']) . ' read, ' . num($s['replied']) . ' replied'],
        ['label' => 'Waiting', 'value' => h(num($s['waiting'])), 'note' => $s['failed'] > 0 ? num($s['failed']) . ' failed' : 'To go when their time comes',
         'tone' => $s['failed'] > 0 ? 'bad' : null],
    ]);
}

/**
 * The built-in messages, locked: the confirmation, the reminders and "we're live" go while
 * WhatsApp is on; the checkout coupon keeps its own switch, here and on Settings.
 */
function admin_rule_builtin(PDO $pdo, array $config, int $now): string
{
    $since = tg_ist($now - 30 * 86400);
    $stats = [];
    foreach (rows($pdo, "SELECT template, COALESCE(SUM(status = 'sent'), 0) AS sent, COALESCE(SUM(read_at IS NOT NULL), 0) AS seen FROM outbox
        WHERE channel = 'whatsapp' AND source IS NULL AND dedupe_key NOT LIKE 'wa:test:%' AND due_at >= ? GROUP BY template", [$since]) as $r) {
        $stats[(string) $r['template']] = $r;
    }
    $live = tg_wa_enabled($config) && !tg_wa_paused($config);
    $state = $live ? ui_pill('On', 'good') : ui_pill(tg_wa_paused($config) ? 'Paused' : 'Off', 'neutral');
    $table = [];
    foreach (tg_wa_templates($config) as $key => $t) {
        if ($key === 'reopen') {
            continue; // sent by hand from the inbox, not automatic
        }
        $s = $stats[$key] ?? ['sent' => 0, 'seen' => 0];
        if ($key === 'nudge') {
            [$on] = admin_setting($config, 'whatsapp.checkout_coupon');
            $status = ($on && $live ? ui_pill('On', 'good') : ui_pill($on ? ($live ? 'On' : 'Waiting for WhatsApp') : 'Off', 'neutral'))
                . ui_post('setting_save', ['#' => 'builtin'],
                    '<label class="field"><span>Checkout coupon</span><select name="values[whatsapp.checkout_coupon]">'
                    . '<option value="1"' . ($on ? ' selected' : '') . '>On</option><option value="0"' . ($on ? '' : ' selected') . '>Off</option></select></label>'
                    . ui_code_input(), 'Save', 'quiet', 'inline');
        } else {
            $status = $state . ' ' . ui_pill('Locked', 'neutral');
        }
        $table[] = [
            h($t['label']) . '<small class="sub">' . h($t['when']) . '</small>',
            '<span class="tpl-name">' . h($t['name']) . '</span><small class="sub">' . h(strtolower($t['category'] ?? 'UTILITY')) . '</small>',
            h(num((int) $s['sent'])) . '<small class="sub">' . h(num((int) $s['seen'])) . ' read</small>',
            $status,
        ];
    }
    return ui_card('Built-in messages', ui_table([['Message', ''], ['Template', ''], ['Sent, 30 days', 'num'], ['Status', '']], $table)
        . '<p class="table-note">These are part of the booking flow and go while WhatsApp is on. Their wording stays in code. '
        . 'Pause everything in <a class="row-link" href="' . h(admin_url(['view' => 'settings'])) . '#whatsapp">Settings</a>.</p>',
        ['size' => 'full', 'flush' => true, 'id' => 'builtin', 'sub' => 'Everyone who agreed to booking updates gets these when they apply, as well as the emails.']);
}

/** The template or reply of a rule, in a table cell. */
function admin_rule_what(PDO $pdo, array $r): string
{
    if ($r['action'] === 'text') {
        return 'Text reply<small class="sub clip">' . h(mb_substr((string) $r['reply_text'], 0, 80)) . '</small>';
    }
    $t = !empty($r['template_id']) ? tg_wat_get($pdo, (int) $r['template_id']) : null;
    if ($t === null) {
        return '<span class="muted">No template yet</span>';
    }
    return '<span class="tpl-name">' . h($t['name']) . '</span> ' . admin_wat_status_pill($t);
}

/** Every rule, with its switch. */
function admin_rule_list(PDO $pdo, array $config, int $now): string
{
    $stats = admin_rule_stats($pdo, $now);
    $table = [];
    foreach (rows($pdo, 'SELECT * FROM wa_rules ORDER BY preset IS NOT NULL, id') as $raw) {
        $r = tg_rule_decode($raw);
        $id = $r['id'];
        $s = $stats[$id] ?? admin_rule_no_stats();
        $open = admin_url(['view' => 'automations', 'r' => $id]);
        $actions = '<a class="row-link" href="' . h($open) . '#edit">Edit</a>'
            . ($r['enabled'] ? ui_post('rule_off', ['r' => $id, '#' => 'rules'], '', 'Switch off', 'link', 'inline')
                : ui_post('rule_on', ['r' => $id, '#' => 'rules'], '', 'Switch on', 'link', 'inline'))
            . '<a class="row-link" href="' . h($open) . '#test">Send a test</a>'
            . ($r['preset'] === null ? '<a class="row-link" href="' . h(admin_url(['view' => 'automations', 'r' => $id, 'ask' => 'delete'])) . '#confirm">Delete</a>' : '');
        $table[] = [
            '<a class="person" href="' . h($open) . '">' . h($r['name']) . '</a>' . ($r['preset'] !== null ? ' ' . ui_pill('Ready-made', 'info') : ''),
            h(tg_rule_trigger_text($r)),
            admin_rule_what($pdo, $r),
            h(tg_rule_audience_text($r['audience'])),
            admin_rule_status_pill($r),
            h(num($s['sent'])) . '<small class="sub">' . h(num($s['seen'])) . ' read</small>',
            '<div class="row-actions">' . $actions . '</div>',
        ];
    }
    return ui_card('Your rules', ui_table([['Rule', ''], ['When', ''], ['Sends', ''], ['Who', ''], ['', ''], ['Sent', 'num'], ['', 'num']], $table,
        ['empty' => 'No rules yet. Make one below.']),
        ['size' => 'full', 'flush' => true, 'id' => 'rules', 'sub' => 'Only things that happen after a rule is switched on count. Editing a rule switches it off.']);
}

/** The ready-made rules: what each does, its template, and the next step. */
function admin_rule_presets(PDO $pdo): string
{
    $html = '';
    foreach (rows($pdo, 'SELECT * FROM wa_rules WHERE preset IS NOT NULL ORDER BY id') as $raw) {
        $r = tg_rule_decode($raw);
        $p = TG_RULE_PRESETS[$r['preset']] ?? null;
        if ($p === null) {
            continue;
        }
        $t = !empty($r['template_id']) ? tg_wat_get($pdo, (int) $r['template_id']) : null;
        $live = $t !== null && $t['status'] !== 'DELETED';
        $step = !$live
            ? ui_post('preset_template', ['preset' => $r['preset']], '<p class="muted small">This writes a suggested draft in Templates. Add your own link to its button, then submit it.</p>',
                'Make its template', 'primary', 'stack')
            : '<p class="muted small">Template <span class="tpl-name">' . h($t['name']) . '</span> ' . admin_wat_status_pill($t)
                . ($t['status'] === 'APPROVED' ? '. Ready to switch on.' : ($t['status'] === 'DRAFT' ? '. Finish and submit it in Templates.' : '.')) . '</p>';
        $html .= '<div class="preset"><h3 class="sub-head">' . h($r['name']) . ' ' . admin_rule_status_pill($r) . '</h3>'
            . '<p>' . h($p['about']) . '</p>' . $step
            . ui_button('Set up', admin_url(['view' => 'automations', 'r' => $r['id']]) . '#edit', 'quiet')
            . ($live ? ' ' . ui_button('Open template', admin_url(['view' => 'wa_templates', 'edit' => $t['id']]) . '#edit', 'quiet') : '')
            . '</div>';
    }
    return ui_card('Ready-made rules', $html !== '' ? $html : ui_empty('No ready-made rules.'),
        ['size' => 'half', 'id' => 'presets', 'sub' => 'Off until you switch them on. Each needs an approved template first.']);
}

/** Templates a rule can use: the approved ones, plus the one it has now (even if not approved yet). */
function admin_rule_template_options(PDO $pdo, ?array $r): array
{
    $out = admin_bc_template_options($pdo);
    $cur = $r['template_id'] ?? null;
    if ($cur !== null && !isset($out[(string) $cur])) {
        $t = tg_wat_get($pdo, (int) $cur);
        if ($t !== null && $t['status'] !== 'DELETED') {
            $out = [(string) $cur => $t['name'] . ' (' . strtolower(str_replace('_', ' ', $t['status'] === 'PENDING' ? 'in review' : (string) $t['status'])) . ')'] + $out;
        }
    }
    return $out;
}

/** The form for a new rule ($r null) or one rule. */
function admin_rule_form(PDO $pdo, array $config, ?array $r): string
{
    $v = $r ?? ['id' => 0, 'name' => '', 'trigger_type' => 'paid', 'trigger_value' => '', 'delay_minutes' => 0, 'action' => 'template',
        'template_id' => null, 'fields' => ['fields' => [], 'fixed' => []], 'reply_text' => '', 'audience' => tg_rule_clean_audience([]), 'enabled' => 0];
    $options = admin_rule_template_options($pdo, $r);
    $tid = $v['template_id'] !== null ? (string) $v['template_id'] : '';
    $tags = admin_bc_tags($pdo);
    foreach (['tag_in', 'tag_out'] as $k) {
        if ($v['audience'][$k] !== '' && !isset($tags[$v['audience'][$k]])) {
            $tags[$v['audience'][$k]] = $v['audience'][$k];
        }
    }
    $inner = ($r !== null && $r['enabled'] ? '<p class="notice notice-warn">' . icon('alert') . 'This rule is on. Saving switches it off and cancels its waiting messages. Check it, then switch it on again.</p>' : '')
        . ui_field('Name', 'name', 'maxlength="80" required autocomplete="off" placeholder="Welcome after payment" value="' . h((string) $v['name']) . '"', 'Only you see it.')
        . ui_select('When', 'trigger_type', TG_RULE_TRIGGERS, (string) $v['trigger_type'])
        . '<div class="fields">'
        . ui_field('Minutes, hours, tag or keywords', 'trigger_value', 'maxlength="320" autocomplete="off" value="' . h((string) $v['trigger_value']) . '"',
            'Checkout not paid: minutes (10 to 1,440). Before or after the class: hours (1 to 168). Tag: the tag. Keyword: up to 10, with commas.')
        . ui_field('Then wait (minutes)', 'delay_minutes', 'type="number" min="0" max="' . TG_RULE_DELAY_MAX . '" value="' . (int) $v['delay_minutes'] . '"',
            '0 sends at once. Up to 7 days.')
        . '</div>'
        . ui_choice('Send', 'send_as', ['template' => 'A template', 'text' => 'A text reply (keyword rules only)'], (string) $v['action'])
        . ($options !== []
            ? ui_select('Template', 'template_id', ['' => 'None yet'] + $options, $tid, 'Approved templates. Changing it resets the choices below.')
            : '<p class="notice">' . icon('info') . 'No approved templates yet. Write one in <a class="row-link" href="' . h(admin_url(['view' => 'wa_templates'])) . '">Templates</a>.</p>'
                . '<input type="hidden" name="template_id" value="' . h($tid) . '">');
    $t = $r !== null ? tg_rule_template($pdo, $r) : null;
    if ($t !== null && (string) $t['id'] === $tid) {
        $slots = tg_wat_slots($t);
        if ($slots) {
            $inner .= '<h3 class="sub-head">What fills each part</h3><p class="muted small">For each person, from their details, or the same text for everyone.</p>';
            foreach ($slots as $key => $field) {
                $label = $key[0] === 'b' ? '{{' . substr($key, 1) . '}}' : 'The link in button ' . ((int) substr($key, 1) + 1);
                $inner .= '<div class="fields">'
                    . ui_select($label . ' is filled with', 'fields[' . $key . ']', TG_WA_FIELDS, $field)
                    . ui_field('Text for ' . $label, 'fixed[' . $key . ']', 'maxlength="200" autocomplete="off" value="' . h((string) ($v['fields']['fixed'][$key] ?? '')) . '"',
                        'Used when it is filled with "Text you type".')
                    . '</div>';
            }
        }
    }
    $inner .= ui_textarea('Text reply', 'reply_text', (string) ($v['reply_text'] ?? ''), 'maxlength="1000" rows="3"',
            'Only for keyword rules: free text, sent at once while their 24-hour window is open.')
        . '<h3 class="sub-head">Who</h3>'
        . ui_select('Add-on', 'addon', ['' => 'Anyone', 'yes' => 'Only people with the add-on', 'no' => 'Only people without the add-on'], $v['audience']['addon'])
        . '<div class="fields">'
        . ui_select('Only with the tag', 'tag_in', ['' => 'Any'] + $tags, $v['audience']['tag_in'])
        . ui_select('Leave out the tag', 'tag_out', ['' => 'None'] + $tags, $v['audience']['tag_out'])
        . '</div>'
        . '<label class="tick tick-plain"><input type="checkbox" name="offers_box" value="1"' . ($v['audience']['offers_box'] ? ' checked' : '') . '> Only people who ticked the offers box at checkout</label>'
        . '<p class="muted small">Always: only people who agreed to booking updates or messaged you, never anyone who said STOP, and the test list while it is set.</p>';
    return ui_card($r === null ? 'New rule' : 'Edit rule', ui_post('rule_save', ['r' => $r['id'] ?? '', '#' => 'edit'], $inner, $r === null ? 'Make the rule' : 'Save rule', 'primary', 'stack'),
        ['size' => 'half', 'id' => 'edit', 'sub' => $r === null ? 'It starts off. Switch it on once it looks right.' : tg_rule_trigger_text($r)]);
}

/** One rule at a glance: what it does, the message, and its switch and test. */
function admin_rule_summary(PDO $pdo, array $config, array $r, int $now): string
{
    $t = tg_rule_template($pdo, $r);
    $html = ui_facts([
        ['Status', admin_rule_status_pill($r) . ($r['enabled'] && $r['enabled_at'] ? ' <span class="muted">since ' . h(when($r['enabled_at'])) . '</span>' : '')],
        ['When', h(tg_rule_trigger_text($r))],
        ['Who', h(tg_rule_audience_text($r['audience']))],
        ['Sends', admin_rule_what($pdo, $r)],
    ]);
    if ($r['action'] === 'text') {
        $html .= ui_wa_bubble(['header' => '', 'body' => (string) $r['reply_text'], 'footer' => '', 'buttons' => []]);
    } elseif ($t !== null) {
        $html .= ui_wa_bubble(tg_wat_render($t, tg_wat_sample_values($t)));
    }
    $why = admin_rule_cannot_start($pdo, $config, $r);
    $html .= $r['enabled']
        ? ui_post('rule_off', ['r' => $r['id'], '#' => 'top'], '<p class="muted small">Switching it off cancels its waiting messages.</p>', 'Switch off', 'quiet', 'stack')
        : ($why === null
            ? ui_post('rule_on', ['r' => $r['id'], '#' => 'top'], '<p class="muted small">Only things that happen from now on count.</p>', 'Switch on', 'primary', 'stack')
            : '<p class="notice">' . icon('info') . h($why) . '</p>');

    // The test.
    $targets = admin_bc_test_targets($config);
    if (!tg_wa_enabled($config) || tg_wa_paused($config)) {
        $test = '<p class="notice">' . icon('info') . (tg_wa_paused($config) ? 'WhatsApp is paused in Settings, so no test can go.' : 'WhatsApp sending is off in the config, so no test can go.') . '</p>';
    } elseif ($targets === []) {
        $test = '<p class="notice">' . icon('info') . 'Add your own number in Settings (Broadcasts) to send tests.</p>';
    } elseif ($r['action'] === 'template' && ($t === null || $t['status'] !== 'APPROVED' || $t['deleted_at'] !== null)) {
        $test = '<p class="notice">' . icon('info') . 'A test needs an approved template.</p>';
    } else {
        $test = ui_post('rule_test', ['r' => $r['id'], 'once' => admin_once(), '#' => 'test'],
            ui_select('Send the test to', 'to', array_combine($targets, array_map('tg_wa_pretty', $targets)), $targets[0],
                tg_wa_test_numbers($config) !== [] ? 'While the test list is set, tests go only to numbers on it.' : 'Your own number, from Settings.')
            . '<p class="muted small">' . ($r['action'] === 'text'
                ? 'A text reply reaches your phone only if you wrote to the business number in the last 24 hours.'
                : 'Filled with the newest person it would go to, or with sample details. Meta charges for it like any template.') . '</p>',
            'Send a test', 'quiet', 'stack');
    }
    $html .= '<h3 class="sub-head" id="test">Test</h3>' . $test;
    return ui_card('What it does', $html, ['size' => 'half', 'id' => 'about']);
}

/** Why a rule can't be switched on now, or null when it can. */
function admin_rule_cannot_start(PDO $pdo, array $config, array $r): ?string
{
    if (!tg_wa_ready($config)) {
        return 'WhatsApp is not set up yet, so the rule can\'t be switched on.';
    }
    if ($r['action'] === 'text') {
        if ($r['trigger_type'] !== 'keyword') {
            return 'A text reply works only for keyword rules. Pick a template, then save.';
        }
        return trim((string) $r['reply_text']) === '' ? 'Write the text reply first, then save.' : null;
    }
    $t = !empty($r['template_id']) ? tg_wat_get($pdo, (int) $r['template_id']) : null;
    if ($t === null || $t['status'] === 'DELETED') {
        return 'Pick a template first. It needs to be approved by Meta before the rule can be switched on.';
    }
    if ($t['status'] !== 'APPROVED') {
        return 'Its template ' . $t['name'] . ' is not approved yet (' . strtolower($t['status'] === 'PENDING' ? 'in review' : (string) $t['status'])
            . '). The rule can be switched on once Meta approves it.';
    }
    return null;
}

/** Why some did not get it, grouped. */
function admin_rule_reasons(PDO $pdo, int $id): string
{
    $rows = [];
    foreach (rows($pdo, "SELECT status, COALESCE(last_error, 'No reason given') AS why, COUNT(*) AS n FROM outbox
        WHERE source = 'automation' AND source_id = ? AND status IN ('failed', 'expired', 'cancelled') GROUP BY status, why ORDER BY n DESC LIMIT 20", [$id]) as $r) {
        $rows[] = [h((string) $r['why']), $r['status'] === 'failed' ? ui_pill('Failed', 'bad') : ui_pill('Not sent', 'neutral'), h(num((int) $r['n']))];
    }
    return ui_card('Why some did not get it', ui_table([['Reason', ''], ['', ''], ['People', 'num']], $rows, ['empty' => 'Everyone got it, or is still waiting.']),
        ['size' => 'half', 'flush' => true, 'id' => 'reasons']);
}

/** The rule's latest messages, with what happened. */
function admin_rule_people(PDO $pdo, int $id): string
{
    $list = rows($pdo, "SELECT o.id, o.to_addr, o.status, o.due_at, o.next_try_at, o.sent_at, o.delivered_at, o.read_at, o.last_error, o.params,
            c.name AS cname, wc.profile_name, wc.id AS wcid, o.status = 'sent' AND " . admin_bc_replied_sql() . " AS replied
        FROM outbox o LEFT JOIN wa_contacts wc ON wc.phone = o.to_addr LEFT JOIN contacts c ON c.id = wc.contact_id
        WHERE o.source = 'automation' AND o.source_id = ? ORDER BY o.id DESC LIMIT 200", [$id]);
    $table = [];
    foreach ($list as $o) {
        $params = json_decode((string) ($o['params'] ?? ''), true);
        $name = trim((string) ($o['cname'] ?? '')) ?: (trim((string) ($params['name'] ?? '')) ?: trim((string) ($o['profile_name'] ?? '')));
        $label = $name !== '' ? $name : tg_wa_pretty((string) $o['to_addr']);
        $who = $o['wcid'] ? '<a class="person" href="' . h(admin_url(['view' => 'inbox', 'c' => $o['wcid']])) . '">' . h($label) . '</a>' : h($label);
        $state = match ($o['status']) {
            'sent'              => $o['read_at'] ? ui_pill('Read', 'good') : ($o['delivered_at'] ? ui_pill('Delivered', 'good') : ui_pill('Sent', 'neutral')),
            'queued', 'sending' => ui_pill('Waiting', 'info'),
            'failed'            => ui_pill('Failed', 'bad'),
            default             => ui_pill('Not sent', 'neutral'),
        };
        if ((int) $o['replied'] === 1) {
            $state .= ' ' . ui_pill('Replied', 'info');
        }
        $note = $o['status'] === 'queued'
            ? ($o['last_error'] ? h((string) $o['last_error']) . ($o['next_try_at'] ? ', next try ' . h(when($o['next_try_at'])) : '') : 'Goes ' . h(when($o['due_at'])))
            : ($o['last_error'] ? h((string) $o['last_error']) : ($o['read_at'] ? 'Read ' . h(when($o['read_at'])) : ($o['sent_at'] ? 'Sent ' . h(when($o['sent_at'])) : '')));
        $table[] = [$who . '<small class="sub">' . h(tg_wa_pretty((string) $o['to_addr'])) . '</small>', $state, '<span class="muted">' . $note . '</span>'];
    }
    return ui_card('Messages', ui_table([['Person', ''], ['Result', ''], ['Note', '']], $table,
        ['empty' => 'Nothing yet. Messages show here once the rule is on and something happens.', 'note' => count($list) === 200 ? 'Showing the newest 200.' : '']),
        ['size' => 'full', 'flush' => true, 'id' => 'people']);
}

/**
 * The rule from the form, checked. Returns [rule, null] or [null, the reason nothing was saved].
 * The template's slots are kept only when the template did not change.
 */
function admin_rule_from_post(PDO $pdo, ?array $old): array
{
    $text = static fn(string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
    $name = rp_clean($text('name'), 80);
    if ($name === '') {
        return [null, 'Give the rule a name.'];
    }
    $type = $text('trigger_type');
    if (!isset(TG_RULE_TRIGGERS[$type])) {
        return [null, 'Pick when it sends.'];
    }
    [$value, $error] = tg_rule_check_value($type, $text('trigger_value'));
    if ($error !== null) {
        return [null, $error];
    }
    $delayRaw = trim($text('delay_minutes'));
    $delay = $delayRaw === '' ? 0 : (ctype_digit($delayRaw) ? (int) $delayRaw : -1);
    if ($delay < 0 || $delay > TG_RULE_DELAY_MAX) {
        return [null, 'The wait is a whole number of minutes, 0 to 10,080 (7 days).'];
    }
    if ($type === 'before_session' && $delay >= (int) $value * 60) {
        return [null, 'That delay sends it after the class starts. Make the wait shorter than ' . tg_rule_span((int) $value * 60) . '.'];
    }
    // Not "action": that name carries the form's own action.
    $action = $text('send_as') === 'text' ? 'text' : 'template';
    if ($action === 'text' && $type !== 'keyword') {
        return [null, 'A text reply works only for keyword rules: it is free text, which WhatsApp allows only right after someone writes. Pick a template.'];
    }
    $reply = trim(tg_wa_clean_text($text('reply_text'), 1000));
    $tid = (int) $text('template_id');
    $map = [];
    $fixed = [];
    if ($action === 'text') {
        if ($reply === '') {
            return [null, 'Write the text reply.'];
        }
        $tid = 0;
    } else {
        $t = $tid > 0 ? tg_wat_get($pdo, $tid) : null;
        if ($tid > 0 && ($t === null || $t['status'] === 'DELETED')) {
            return [null, 'That template is not here any more. Pick another.'];
        }
        // Approved, or the one this rule already has (a ready-made rule's draft, waiting for Meta).
        if ($t !== null && $t['status'] !== 'APPROVED' && $tid !== (int) ($old['template_id'] ?? 0)) {
            return [null, 'Pick an approved template.'];
        }
        if ($t !== null) {
            $changed = $tid !== (int) ($old['template_id'] ?? 0);
            $postedFields = is_array($_POST['fields'] ?? null) ? $_POST['fields'] : [];
            $postedFixed = is_array($_POST['fixed'] ?? null) ? $_POST['fixed'] : [];
            foreach (tg_wat_slots($t) as $key => $default) {
                $f = !$changed && is_string($postedFields[$key] ?? null) ? $postedFields[$key] : $default;
                $map[$key] = isset(TG_WA_FIELDS[$f]) ? $f : $default;
                $typed = !$changed && is_string($postedFixed[$key] ?? null) ? tg_wat_clean_value($postedFixed[$key]) : '';
                if ($typed !== '') {
                    $fixed[$key] = mb_substr($typed, 0, 200);
                }
            }
        }
    }
    return [[
        'name'          => $name,
        'trigger_type'  => $type,
        'trigger_value' => $value,
        'delay_minutes' => $delay,
        'action'        => $action,
        'template_id'   => $tid > 0 ? $tid : null,
        'fields'        => ['fields' => $map, 'fixed' => $fixed],
        'reply_text'    => $reply !== '' ? $reply : null,
        'audience'      => tg_rule_clean_audience(['tag_in' => $text('tag_in'), 'tag_out' => $text('tag_out'), 'addon' => $text('addon'),
            'offers_box' => $text('offers_box') === '1']),
    ], null];
}

/** Sample details for a rule's test: the newest person it would go to, else Priya. */
function admin_rule_sample(PDO $pdo, array $config, array $r, array $t, int $now): array
{
    $fixed = array_map('strval', array_filter($r['fields']['fixed'], 'is_scalar'));
    $person = null;
    if (tg_rule_is_booking((string) $r['trigger_type'])) {
        $b = row($pdo, "SELECT c.phone, b.session_at FROM bookings b JOIN contacts c ON c.id = b.contact_id WHERE b.status = 'paid' AND b.product = ?"
            . ((string) $r['trigger_type'] === 'addon_paid' ? ' AND b.addon = 1' : '') . ' ORDER BY b.id DESC LIMIT 1', [(string) $config['product']]);
        $phone = $b ? tg_wa_number((string) $b['phone']) : null;
        if ($phone !== null) {
            $person = tg_wat_person($pdo, $config, $phone);
            $person['session_at'] = $b['session_at'] ?? $person['session_at'];
        }
    }
    if ($person !== null) {
        $values = tg_wat_values($config, $t, $person, $fixed, $now);
        if (!$values['missing']) {
            return [$values, (string) $person['name']];
        }
    }
    $sample = ['name' => 'Priya Sharma', 'session_at' => rp_next_session($now)->format('Y-m-d H:i:s')];
    return [tg_wat_values($config, $t, $sample, $fixed, $now), 'Priya Sharma'];
}

/** Every change the Automations page makes. Called from admin_handle_post with the token already checked. */
function admin_rule_action(PDO $pdo, array $config, string $action): void
{
    $now = rp_now($config);
    $at = tg_ist($now);
    $page = static fn(int $id, string $anchor = ''): string => './?view=automations&r=' . $id . ($anchor !== '' ? '#' . $anchor : '');
    $json = static fn($v): string => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($action === 'preset_template') {
        $key = is_string($_POST['preset'] ?? null) ? $_POST['preset'] : '';
        $p = TG_RULE_PRESETS[$key] ?? null;
        $raw = $p !== null ? row($pdo, 'SELECT * FROM wa_rules WHERE preset = ?', [$key]) : [];
        if (!$raw) {
            admin_flash('bad', 'That ready-made rule is not here. Nothing changed.');
            return;
        }
        $r = tg_rule_decode($raw);
        $old = !empty($r['template_id']) ? tg_wat_get($pdo, (int) $r['template_id']) : null;
        if ($old !== null && $old['status'] !== 'DELETED') {
            admin_flash('warn', 'It already has a template: ' . $old['name'] . '. Nothing changed.');
            $_POST['back'] = './?view=wa_templates&edit=' . $old['id'] . '#edit';
            return;
        }
        $s = $p['template'];
        $name = $s['name'];
        if (tg_wat_is_system_name($config, $name) || value($pdo, 'SELECT 1 FROM wa_templates WHERE LOWER(name) = LOWER(?)', [$name])) {
            $name = admin_wat_next_name($pdo, $config, $name);
        }
        // The link is left empty on purpose: the checks fail until you add your own page.
        $buttons = [['type' => 'URL', 'text' => $s['button'], 'url' => '', 'example' => '']];
        $pdo->prepare("INSERT INTO wa_templates (name, language, category, header_text, body, footer, buttons, examples, fields, status, created_at, updated_at)
            VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, 'DRAFT', ?, ?)")
            ->execute([$name, (string) ($config['whatsapp']['language'] ?? 'en'), $s['category'], $s['body'], TG_WA_FOOTER, $json($buttons),
                $json($s['examples']), $json($s['fields']), $at, $at]);
        $tid = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE wa_rules SET template_id = ?, fields = NULL, enabled = 0, updated_at = ? WHERE id = ?")->execute([$tid, $at, $r['id']]);
        tg_audit($config, 'admin', 'rule_preset_template', $r['name'] . ' (rule ' . $r['id'] . '): draft template ' . $name . ' (template ' . $tid . ')');
        admin_flash('good', 'A suggested draft, ' . $name . ', is ready. Add your link to its button, then submit it to Meta. The rule can be switched on once it is approved.');
        $_POST['back'] = './?view=wa_templates&edit=' . $tid . '#edit';
        return;
    }

    $id = (int) ($_POST['r'] ?? 0);
    $r = $id > 0 ? tg_rule_get($pdo, $id) : null;
    if ($id > 0 && $r === null) {
        admin_flash('bad', 'That rule is not here. Nothing changed.');
        $_POST['back'] = './?view=automations';
        return;
    }
    if ($action !== 'rule_save' && $r === null) {
        admin_flash('bad', 'Pick a rule first. Nothing changed.');
        return;
    }
    $label = $r !== null ? $r['name'] . ' (rule ' . $r['id'] . ')' : '';

    switch ($action) {
        case 'rule_save':
            [$new, $error] = admin_rule_from_post($pdo, $r);
            if ($error !== null) {
                admin_flash('bad', $error . ' Nothing was saved.');
                return;
            }
            $values = [$new['name'], $new['trigger_type'], $new['trigger_value'], $new['delay_minutes'], $new['action'], $new['template_id'],
                $json($new['fields']), $new['reply_text'], $json($new['audience'])];
            $off = '';
            if ($r === null) {
                $pdo->prepare('INSERT INTO wa_rules (name, trigger_type, trigger_value, delay_minutes, action, template_id, fields, reply_text, audience, enabled, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)')->execute([...$values, $at, $at]);
                $id = (int) $pdo->lastInsertId();
            } else {
                $pdo->prepare('UPDATE wa_rules SET name = ?, trigger_type = ?, trigger_value = ?, delay_minutes = ?, action = ?, template_id = ?, fields = ?,
                    reply_text = ?, audience = ?, enabled = 0, updated_at = ? WHERE id = ?')->execute([...$values, $at, $id]);
                if ($r['enabled']) {
                    $n = tg_rule_cancel_queued($pdo, $id, 'Rule changed, so it was switched off');
                    $off = ' It was on, so it is off now' . ($n > 0 ? ' and ' . num($n) . ' waiting message' . ($n === 1 ? ' was' : 's were') . ' cancelled' : '')
                        . '. Check it, then switch it on again.';
                }
            }
            tg_audit($config, 'admin', 'rule_saved', $new['name'] . ' (rule ' . $id . '): ' . tg_rule_trigger_text($new) . ($off !== '' ? ', switched off' : ''));
            admin_flash($off !== '' ? 'warn' : 'good', 'Saved.' . ($off !== '' ? $off : ($r === null ? ' It is off. Switch it on when it looks right.' : '')));
            $_POST['back'] = $page($id, 'edit');
            return;

        case 'rule_on':
            $why = admin_rule_cannot_start($pdo, $config, $r);
            if ($why !== null) {
                admin_flash('bad', $why . ' Nothing changed.');
                return;
            }
            if ($r['enabled']) {
                admin_flash('good', 'It is already on.');
                return;
            }
            $pdo->prepare('UPDATE wa_rules SET enabled = 1, enabled_at = ?, updated_at = ? WHERE id = ?')->execute([$at, $at, $id]);
            tg_audit($config, 'admin', 'rule_on', $label);
            admin_flash('good', 'On. Only things that happen from now on count.');
            return;

        case 'rule_off':
            $pdo->prepare('UPDATE wa_rules SET enabled = 0, updated_at = ? WHERE id = ?')->execute([$at, $id]);
            $n = tg_rule_cancel_queued($pdo, $id, 'Rule switched off');
            tg_audit($config, 'admin', 'rule_off', $label . ': ' . $n . ' waiting cancelled');
            admin_flash('good', 'Off.' . ($n > 0 ? ' ' . num($n) . ' waiting message' . ($n === 1 ? ' was' : 's were') . ' cancelled.' : ''));
            return;

        case 'rule_delete':
            if (($_POST['confirm'] ?? '') !== '1') {
                admin_flash('bad', 'Nothing changed. Confirm first.');
                return;
            }
            if ($r['preset'] !== null) {
                admin_flash('bad', 'A ready-made rule can be switched off, not deleted. Nothing changed.');
                return;
            }
            $n = tg_rule_cancel_queued($pdo, $id, 'Rule deleted');
            $pdo->prepare('DELETE FROM wa_rules WHERE id = ? AND preset IS NULL')->execute([$id]);
            tg_audit($config, 'admin', 'rule_deleted', $label . ': ' . $n . ' waiting cancelled');
            admin_flash('good', 'Rule ' . $r['name'] . ' deleted.' . ($n > 0 ? ' ' . num($n) . ' waiting message' . ($n === 1 ? ' was' : 's were') . ' cancelled.' : ''));
            $_POST['back'] = './?view=automations';
            return;

        case 'rule_test':
            $_POST['back'] = $page($id, 'test');
            if (!tg_wa_enabled($config) || tg_wa_paused($config)) {
                admin_flash('bad', 'Not sent. ' . (tg_wa_paused($config) ? 'WhatsApp is paused in Settings.' : 'WhatsApp sending is off in the config.'));
                return;
            }
            $to = tg_wa_number(is_string($_POST['to'] ?? null) ? $_POST['to'] : '') ?? '';
            if (tg_wa_test_numbers($config) !== []) {
                if (!in_array($to, tg_wa_test_numbers($config), true)) {
                    admin_flash('bad', 'Not sent. While the test list is set, tests go only to numbers on it.');
                    return;
                }
            } elseif ($to === '' || $to !== tg_wa_my_number($config)) {
                admin_flash('bad', 'Not sent. Tests go only to your own number, set in Settings.');
                return;
            }
            $t = $r['action'] === 'template' ? tg_rule_template($pdo, $r) : null;
            if ($r['action'] === 'template' && ($t === null || $t['status'] !== 'APPROVED' || $t['deleted_at'] !== null)) {
                admin_flash('bad', 'Not sent. A test needs an approved template.');
                return;
            }
            if ($r['action'] === 'text' && trim((string) $r['reply_text']) === '') {
                admin_flash('bad', 'Not sent. Write the text reply first.');
                return;
            }
            if (!admin_once_take()) {
                admin_flash('warn', 'That test was already sent, so it was not sent again.');
                return;
            }
            if ($t !== null) {
                [$values, $who] = admin_rule_sample($pdo, $config, $r, $t, $now);
                unset($values['missing']);
                $result = tg_wat_send($config, $t, $to, $values);
                $body = tg_wat_text(tg_wat_render($t, $values));
            } else {
                $who = '';
                $body = trim((string) $r['reply_text']);
                $result = tg_wa_send_text_result($config, $to, $body);
            }
            // Source 'test': never in the summary's counts, and a late failure from Meta never alerts.
            $pdo->prepare("INSERT INTO outbox (dedupe_key, channel, template, booking_id, to_addr, due_at, expires_at, status, tries, last_error, sent_at, provider_id, alerted_at, source)
                VALUES (?, 'whatsapp', ?, NULL, ?, ?, ?, ?, 1, ?, ?, ?, ?, 'test')")
                ->execute(['wa:rtest:' . $id . ':' . bin2hex(random_bytes(6)), $t !== null ? 'c:' . $t['id'] : 'text', $to, $at, $at, $result['ok'] ? 'sent' : 'failed',
                    $result['ok'] ? 'Rule test' : mb_substr((string) $result['error'], 0, 255), $result['ok'] ? $at : null, $result['id'], $at]);
            tg_wa_log_out($pdo, $to, ['kind' => $t !== null ? 'template' : 'text', 'body' => $body, 'template' => $t['name'] ?? null,
                'category' => $t !== null ? tg_wat_category($t) : null, 'sent_by' => 'test', 'wa_id' => $result['id'], 'outbox_id' => (int) $pdo->lastInsertId(),
                'status' => $result['ok'] ? 'sent' : 'failed', 'error' => $result['ok'] ? null : $result['error'], 'billable' => $t !== null ? null : false], $now);
            tg_audit($config, 'admin', 'rule_test', $label . ' to ' . tg_wa_pretty($to) . ': ' . ($result['ok'] ? 'sent' : $result['error']));
            admin_flash($result['ok'] ? 'good' : 'bad', $result['ok']
                ? 'Test sent to ' . tg_wa_pretty($to) . ($who !== '' ? ', filled with ' . tg_first_name($who) . '\'s details' : '') . '. Check your phone.'
                : 'The test was not sent. ' . admin_wa_next_step((string) $result['error']));
            return;
    }
}
