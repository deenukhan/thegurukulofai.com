<?php
/**
 * Inbox: every WhatsApp conversation with the business number. Three states:
 *   the list (default)      reloads itself every 30 seconds and holds no forms but search;
 *   one conversation (?c=)  never reloads, so a half-typed reply is never lost;
 *   manage (?manage=1)      quick replies and the reply emails switch; never reloads either.
 * Opening a conversation marks it read (a display flag only, so not audited).
 */
require_once dirname(__DIR__) . '/lib/settings.php';

return static function (array $d): array {
    $pdo = $d['pdo'];
    $config = $d['config'];
    $now = $d['now'];
    $c = ctype_digit((string) ($_GET['c'] ?? '')) ? (int) $_GET['c'] : 0;
    $listUrl = admin_url(['view' => 'inbox']);
    $manageUrl = admin_url(['view' => 'inbox', 'manage' => '1']);

    // ---------- One conversation ----------
    if ($c > 0) {
        $wc = admin_inbox_thread($pdo, $c);
        if ($wc === null) {
            return ['title' => 'Not found', 'back' => ['Inbox', $listUrl],
                'body' => admin_inbox_kpis($pdo, $now) . ui_grid(ui_card('', ui_empty('This conversation is not in the inbox.'), ['size' => 'full']))];
        }
        if ((int) $wc['unread'] > 0) {
            $pdo->prepare('UPDATE wa_contacts SET unread = 0 WHERE id = ?')->execute([$c]);
        }
        $phone = (string) $wc['phone'];
        $here = ['view' => 'inbox', 'c' => $c];

        // KPIs: the window, their messages, ours, and when they last read one of ours.
        $count = row($pdo, "SELECT COALESCE(SUM(direction = 'in'), 0) AS i, COALESCE(SUM(direction = 'out'), 0) AS o,
                MAX(CASE WHEN direction = 'out' THEN read_at END) AS last_read,
                COALESCE(SUM(direction = 'out' AND billable = 1), 0) AS paid
            FROM wa_messages WHERE wa_contact_id = ?", [$c]);
        $left = tg_wa_window_left($wc['last_in_at'], $now);
        $saidStop = admin_inbox_said_stop($pdo, $phone);
        $windowKpi = $saidStop
            ? ['label' => 'Window', 'value' => ui_pill('Said STOP', 'warn'), 'note' => 'Nothing can go until they write again']
            : ($left > 0
            ? ['label' => 'Window', 'value' => ui_pill('Open', 'good'), 'note' => admin_inbox_span($left) . ' left for free replies']
            : ['label' => 'Window', 'value' => ui_pill('Closed', 'neutral'),
               'note' => $wc['last_in_at'] !== null ? 'Closed ' . ago(tg_wa_ts($wc['last_in_at']) + TG_WA_WINDOW, $now) : 'They have not written to you']);
        $body = ui_kpis([
            $windowKpi,
            ['label' => 'Their messages', 'value' => h(num((int) $count['i'])),
             'note' => $wc['last_in_at'] !== null ? 'Last ' . ago(tg_wa_ts($wc['last_in_at']), $now) : 'None yet'],
            ['label' => 'Our messages', 'value' => h(num((int) $count['o'])), 'note' => num((int) $count['paid']) . ' charged by Meta'],
            ['label' => 'Last read', 'value' => $count['last_read'] !== null ? h(ago(tg_wa_ts($count['last_read']), $now)) : '<span class="muted">—</span>',
             'note' => 'When they last read one of ours'],
        ]);

        // Ask first before stopping WhatsApp for this number.
        $confirm = '';
        if (($_GET['ask'] ?? '') === 'optout' && tg_wa_stop_at($pdo, $phone) === null) {
            $confirm = ui_confirm('Stop WhatsApp for ' . tg_wa_pretty($phone) . '?',
                '<p>They will get no more WhatsApp messages from you: no reminders, no templates. Their queued WhatsApp messages are cancelled. '
                . 'Emails still go. It works like them replying STOP.</p>',
                ui_post('wa_optout', ['c' => $c, 'confirm' => '1', '#' => 'who'], '', 'Stop WhatsApp', 'primary'),
                admin_url($here) . '#who');
        }

        // The conversation, then what can be sent now.
        $chat = ui_chat(admin_inbox_messages($pdo, $c), $now);
        $replyWhy = tg_wa_can_message($pdo, $config, $phone, 'reply', $now);
        $compose = '';
        if ($replyWhy === null) {
            $prefill = '';
            if (ctype_digit((string) ($_GET['qr'] ?? ''))) {
                $prefill = (string) (value($pdo, 'SELECT body FROM wa_quick_replies WHERE id = ?', [(int) $_GET['qr']]) ?: '');
            }
            $compose .= ui_post('wa_reply', ['c' => $c, 'once' => admin_once(), '#' => 'reply'],
                ui_textarea('Your reply', 'text', $prefill, 'rows="4" maxlength="' . TG_WA_TEXT_MAX . '" required',
                    'Free inside the window, which closes in ' . admin_inbox_span($left) . '.'), 'Send', 'primary', 'stack');
            $quick = rows($pdo, 'SELECT id, title, body FROM wa_quick_replies ORDER BY title, id');
            if ($quick) {
                $items = '';
                foreach ($quick as $q) {
                    $items .= '<li class="quick"><div><strong>' . h($q['title']) . '</strong><small class="sub clip">' . h(mb_substr(str_replace("\n", ' ', $q['body']), 0, 90)) . '</small></div>'
                        . '<div class="quick-actions"><a class="row-link" href="' . h(admin_url($here + ['qr' => $q['id']])) . '#reply">Edit first</a>'
                        . ui_post('wa_reply_quick', ['c' => $c, 'qr' => $q['id'], 'once' => admin_once(), '#' => 'reply'], '', 'Send', 'quiet', 'inline') . '</div></li>';
                }
                $compose .= '<h3 class="sub-head">Quick replies</h3><ul class="quick-list">' . $items . '</ul>';
            }
            $compose .= '<p class="muted small"><a class="row-link" href="' . h($manageUrl) . '#quick">' . ($quick ? 'Change quick replies' : 'Add quick replies') . '</a></p>';
        } elseif ($left === 0) {
            $compose .= '<p class="notice">' . icon('info') . h($wc['last_in_at'] !== null
                ? 'The 24-hour window closed ' . ago(tg_wa_ts($wc['last_in_at']) + TG_WA_WINDOW, $now) . '. Only an approved template can restart it.'
                : 'They have not written to you, so only an approved template can start a conversation.') . '</p>';
            $templateWhy = tg_wa_can_message($pdo, $config, $phone, 'template', $now);
            if ($templateWhy !== null) {
                $compose .= '<p class="notice notice-bad">' . icon('alert') . h($templateWhy) . '</p>';
            } else {
                // The follow-up's wording ("following up on your message") fits only people who wrote first.
                $wrote = tg_wa_messaged_us($pdo, $phone);
                $fit = array_filter(admin_inbox_templates($config), static fn(array $t): bool => !$t['wrote_first'] || $wrote);
                $options = [];
                foreach ($fit as $key => $t) {
                    if ($t['approved']) {
                        $options[$key] = $t['label'] . ' (' . $t['name'] . ')';
                    }
                }
                if (!$fit) {
                    $compose .= '<p class="notice">' . icon('info') . 'The follow-up template (gurukul_follow_up) says you are following up on their message. '
                        . 'They agreed to booking updates but have never written to you, so it is not offered here. '
                        . 'A template for buyers who have not written comes with the Templates page.</p>';
                } elseif (!$options) {
                    $compose .= '<p class="notice notice-bad">' . icon('alert') . 'Meta has not approved the follow-up template (gurukul_follow_up) yet. '
                        . 'Submit it on the <a href="' . h(admin_url(['view' => 'emails', 'tab' => 'whatsapp'])) . '#wa-templates">WhatsApp tab</a>.</p>';
                } else {
                    $first = array_key_first($options);
                    $name = admin_inbox_name($wc) === tg_wa_pretty($phone) ? '' : admin_inbox_name($wc);
                    $compose .= ui_post('wa_send_template', ['c' => $c, 'once' => admin_once(), '#' => 'reply'],
                        ui_select('Template', 'template', $options, (string) $first, 'Meta charges for this one: about ₹0.15 for utility.')
                        . '<p class="muted small">They will see:</p>' . ui_wa_bubble(tg_wa_render($config, $first, tg_wa_values($config, $first, ['name' => $name]))),
                        'Send template', 'primary', 'stack');
                }
            }
        } else {
            $compose .= '<p class="notice notice-bad">' . icon('alert') . h($replyWhy) . '</p>';
        }

        $chatCard = ui_card('Conversation', $chat . '<div id="reply" class="anchor"></div>' . $compose, ['size' => 'two-thirds', 'id' => 'chat',
            'sub' => 'The last 200 messages. Text is kept for 30 days.',
            'action' => ui_button('Check for new messages', admin_url($here) . '#reply', 'quiet')]);

        return [
            'title' => admin_inbox_name($wc),
            'intro' => tg_wa_pretty($phone) . ($wc['closed_at'] !== null ? ' · marked done' : ''),
            'back'  => ['Inbox', $listUrl],
            'body'  => $body . ui_grid(...array_filter([$confirm, $chatCard, admin_inbox_person($pdo, $config, $wc, $now)])),
        ];
    }

    // ---------- Quick replies and reply emails (no auto-refresh: these are forms) ----------
    $editId = ctype_digit((string) ($_GET['eqr'] ?? '')) ? (int) $_GET['eqr'] : 0;
    if (($_GET['manage'] ?? '') === '1' || $editId > 0) {
        $quick = rows($pdo, 'SELECT * FROM wa_quick_replies ORDER BY title, id');
        $editing = null;
        $rowsQ = [];
        foreach ($quick as $qr) {
            if ((int) $qr['id'] === $editId) {
                $editing = $qr;
            }
            $rowsQ[] = ['<strong>' . h($qr['title']) . '</strong><small class="sub clip">' . h(mb_substr(str_replace("\n", ' ', $qr['body']), 0, 90)) . '</small>',
                '<div class="row-actions"><a class="row-link" href="' . h(admin_url(['view' => 'inbox', 'manage' => '1', 'eqr' => $qr['id']])) . '#quick">Edit</a>'
                . ui_post('wa_quick_delete', ['id' => $qr['id'], '#' => 'quick'], '', 'Delete', 'link', 'inline') . '</div>'];
        }
        $quickCard = ui_card('Quick replies', ui_table([['Reply', ''], ['', 'num']], $rowsQ, ['empty' => 'No quick replies yet.'])
            . ui_post('wa_quick_save', ['id' => $editing['id'] ?? '', '#' => 'quick'],
                '<h3 class="sub-head">' . ($editing ? 'Edit quick reply' : 'Add a quick reply') . '</h3>'
                . ui_field('Title', 'title', 'maxlength="40" required value="' . h($editing['title'] ?? '') . '" placeholder="Recording question"')
                . ui_textarea('Text', 'body', (string) ($editing['body'] ?? ''), 'rows="5" maxlength="1000" required', 'Up to 1,000 characters. Sent as written.'),
                $editing ? 'Save changes' : 'Add quick reply', 'quiet', 'stack')
            . ($editing ? '<p class="muted small"><a class="row-link" href="' . h($manageUrl) . '#quick">Cancel editing</a></p>' : ''),
            ['size' => 'half', 'id' => 'quick', 'sub' => 'Answers you send often, one click away in each conversation.']);

        [$forward, $from] = admin_setting($config, 'whatsapp.forward_replies');
        $forward = $forward !== false;
        $emailCard = ui_card('Reply emails', ui_facts([
            ['Status', $forward ? ui_pill('On', 'good') : ui_pill('Off', 'neutral')],
            ['Comes from', $from === 'dashboard' ? ui_pill('Set here', 'info') : ui_pill('From the config file', 'neutral')],
        ]) . '<p class="notice">' . icon('info') . 'When on, each WhatsApp message to the business number is also emailed to you, with a link to answer here. Keep it on if you do not check the inbox often.</p>'
            . ui_post('setting_save', ['#' => 'emails'],
                ui_select('Email me each WhatsApp reply', 'values[whatsapp.forward_replies]', ['1' => 'On: email me each one', '0' => 'Off: the inbox only'], $forward ? '1' : '0')
                . ui_code_input(), 'Save', 'primary', 'stack'),
            ['size' => 'half', 'id' => 'emails', 'sub' => 'A copy of each message in your mailbox.']);

        $week = row($pdo, "SELECT COALESCE(SUM(kind = 'text'), 0) AS replies, COALESCE(SUM(kind = 'template'), 0) AS templates
            FROM wa_messages WHERE direction = 'out' AND sent_by = 'admin' AND status <> 'failed' AND created_at >= ?", [tg_ist($now - 7 * 86400)]);
        return [
            'title' => 'Quick replies and emails',
            'intro' => 'Answers you send often, and whether each WhatsApp message is also emailed to you.',
            'back'  => ['Inbox', $listUrl],
            'body'  => ui_kpis([
                ['label' => 'Quick replies', 'value' => h(num(count($quick))), 'note' => 'Ready to send in a conversation'],
                ['label' => 'Reply emails', 'value' => $forward ? ui_pill('On', 'good') : ui_pill('Off', 'neutral'), 'note' => 'A copy of each message by email'],
                ['label' => 'Replies this week', 'value' => h(num((int) $week['replies'])), 'note' => 'Free text you sent from the inbox'],
                ['label' => 'Templates this week', 'value' => h(num((int) $week['templates'])), 'note' => 'Sent from the inbox, charged by Meta'],
            ]) . ui_grid($quickCard, $emailCard),
        ];
    }

    // ---------- Every conversation (reloads itself; no forms here but search) ----------
    $show = in_array($_GET['show'] ?? '', ['unread', 'waiting', 'buyers', 'leads', 'done', 'all'], true) ? (string) $_GET['show'] : '';
    $q = rp_clean($_GET['q'] ?? '', 60);
    $list = admin_inbox_list($pdo, ['show' => $show, 'q' => $q], $now, $d['product']);
    $table = [];
    foreach ($list as $r) {
        $type = $r['contact_id'] === null ? ui_pill('WhatsApp only', 'neutral') : ((int) $r['buyer'] === 1 ? ui_pill('Buyer', 'good') : ui_pill('Lead', 'info'));
        if ($r['last_body'] === null && $r['last_dir'] === null) {
            $preview = '<span class="muted">No messages kept</span>';
        } elseif ((int) $r['last_gone'] === 1 || $r['last_body'] === null) {
            $preview = '<span class="muted">Text removed</span>';
        } else {
            $text = mb_substr(preg_replace('/\s+/u', ' ', (string) $r['last_body']) ?? '', 0, 80);
            $preview = ($r['last_dir'] === 'out' ? '<span class="muted">You: </span>' : '') . h($text);
        }
        $unread = (int) $r['unread'] > 0 ? ' <b class="badge badge-info" aria-label="' . (int) $r['unread'] . ' unread">' . (int) $r['unread'] . '</b>' : '';
        $table[] = [
            '<a class="person' . ((int) $r['unread'] > 0 ? ' unread' : '') . '" href="' . h(admin_url(['view' => 'inbox', 'c' => $r['id']])) . '">'
                . h(admin_inbox_name($r)) . '</a>' . $unread . '<small class="sub">' . h(tg_wa_pretty((string) $r['phone'])) . '</small>' . $type,
            '<span class="clip preview">' . $preview . '</span>',
            $r['last_msg_at'] !== null ? h(ago(tg_wa_ts($r['last_msg_at']), $now)) : '<span class="muted">—</span>',
            admin_inbox_window_pill($r['last_in_at'], $now, $r['last_kind'] === 'stop') . ($r['closed_at'] !== null ? ' ' . ui_pill('Done', 'neutral') : ''),
        ];
    }
    $base = ['view' => 'inbox', 'show' => $show, 'q' => $q];
    $tools = '<div class="toolbar">' . ui_chips(['' => 'Wrote to you', 'unread' => 'Unread', 'waiting' => 'Waiting', 'buyers' => 'Buyers', 'leads' => 'Not buyers',
            'done' => 'Done', 'all' => 'All'], $show, 'show', array_diff_key($base, ['show' => 1]))
        . ui_search($q, array_diff_key($base, ['q' => 1]), 'Search name or number') . '</div>';
    $empty = $q !== '' ? 'Nothing matches.' : match ($show) {
        ''      => 'Nobody has written to the business number yet. Booking messages you sent are under All.',
        'all'   => 'No WhatsApp conversations yet. When someone writes to the business number, it shows here.',
        default => 'Nothing matches.',
    };
    $conversations = ui_card('Conversations', $tools . ui_table([['Person', ''], ['Last message', ''], ['When', 'muted nowrap'], ['Window', 'nowrap']], $table,
        ['empty' => $empty, 'note' => count($list) === 100 ? 'Showing the newest 100. Search to find older ones.' : '']),
        ['size' => 'full', 'flush' => true, 'id' => 'conversations',
         'sub' => ($show === '' ? 'People who wrote to you, newest message from them first.' : 'Newest first.') . ' This list checks for new messages every 30 seconds.']);

    return [
        'actions' => ui_button('Quick replies and emails', $manageUrl, 'quiet'),
        'body'    => admin_inbox_kpis($pdo, $now) . ui_grid($conversations),
        'refresh' => 30,
    ];
};
