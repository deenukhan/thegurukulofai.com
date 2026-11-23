<?php
/**
 * The Broadcasts page (views/broadcasts.php): send one approved template to a group,
 * safely. Four steps: the message, who gets it (with a preview of real names, the cost
 * and the plan against the daily limit), a test to your own number, and the send with a
 * typed confirmation of the count. Then the results.
 *
 * Who may get it, the daily limit and the sending itself live in api/razorpay/wa_bulk.php
 * and outbox.php; this file is the dashboard's side. Every change is a POST through
 * lib/actions.php into admin_bc_action: page token, audit, message, back.
 */

/** "Draft", "Scheduled", "Sending", "Done" or "Cancelled", as a pill. */
function admin_bc_status_pill(string $status): string
{
    return match ($status) {
        'draft'     => ui_pill('Draft', 'neutral'),
        'scheduled' => ui_pill('Scheduled', 'info'),
        'sending'   => ui_pill('Sending', 'info'),
        'done'      => ui_pill('Done', 'good'),
        'cancelled' => ui_pill('Cancelled', 'neutral'),
        default     => ui_pill(ucfirst($status), 'neutral'),
    };
}

/** SQL for "they wrote to us within 72 hours after this row was sent", on the outbox alias o. */
function admin_bc_replied_sql(string $o = 'o'): string
{
    return "EXISTS (SELECT 1 FROM wa_inbound wi WHERE wi.phone = {$o}.to_addr AND wi.kind = 'reply' AND wi.wa_id NOT LIKE 'admin:%'
        AND wi.received_at > {$o}.sent_at AND wi.received_at <= DATE_ADD({$o}.sent_at, INTERVAL " . (TG_WA_REPLY_WINDOW / 3600) . ' HOUR))';
}

/** The numbers for each broadcast (or one): total, sent, delivered, seen, replied, failed, not_sent, waiting, est_paise. Keyed by id. */
function admin_bc_stats(PDO $pdo, ?int $id = null): array
{
    $out = [];
    $rows = rows($pdo, "SELECT o.source_id AS id, o.template, COUNT(*) AS total, COALESCE(SUM(o.status = 'sent'), 0) AS sent,
            COALESCE(SUM(o.status = 'sent' AND o.delivered_at IS NOT NULL), 0) AS delivered,
            COALESCE(SUM(o.status = 'sent' AND o.read_at IS NOT NULL), 0) AS seen,
            COALESCE(SUM(o.status = 'sent' AND " . admin_bc_replied_sql() . "), 0) AS replied,
            COALESCE(SUM(o.status = 'failed'), 0) AS failed,
            COALESCE(SUM(o.status IN ('expired', 'cancelled')), 0) AS not_sent,
            COALESCE(SUM(o.status IN ('queued', 'sending')), 0) AS waiting
        FROM outbox o WHERE o.source = 'broadcast'" . ($id !== null ? ' AND o.source_id = ?' : '') . ' GROUP BY o.source_id, o.template',
        $id !== null ? [$id] : []);
    $cats = admin_bc_categories($pdo);
    foreach ($rows as $r) {
        $k = (int) $r['id'];
        $cat = $cats[(string) $r['template']] ?? 'MARKETING';
        $o = $out[$k] ?? ['total' => 0, 'sent' => 0, 'delivered' => 0, 'seen' => 0, 'replied' => 0, 'failed' => 0, 'not_sent' => 0, 'waiting' => 0, 'est_paise' => 0];
        foreach (['total', 'sent', 'delivered', 'seen', 'replied', 'failed', 'not_sent', 'waiting'] as $f) {
            $o[$f] += (int) $r[$f];
        }
        // The estimate counts what went or may still go, never what failed or was dropped.
        $o['est_paise'] += ((int) $r['sent'] + (int) $r['waiting']) * tg_wa_rate($cat);
        $out[$k] = $o;
    }
    return $out;
}

/** The category each custom template is charged at, keyed by its outbox name (c:<id>). */
function admin_bc_categories(PDO $pdo): array
{
    $out = [];
    foreach (rows($pdo, 'SELECT id, category, meta_category FROM wa_templates') as $t) {
        $out['c:' . $t['id']] = tg_wat_category($t);
    }
    return $out;
}

/** The four numbers at the top of the list. */
function admin_bc_list_kpis(PDO $pdo, int $now): string
{
    $month = tg_ist($now - 30 * 86400);
    $cats = admin_bc_categories($pdo);
    $sent = $seen = $replied = $paise = 0;
    foreach (rows($pdo, "SELECT o.template, COUNT(*) AS n, COALESCE(SUM(o.read_at IS NOT NULL), 0) AS seen,
            COALESCE(SUM(" . admin_bc_replied_sql() . "), 0) AS replied
        FROM outbox o WHERE o.source = 'broadcast' AND o.status = 'sent' AND o.sent_at >= ? GROUP BY o.template", [$month]) as $r) {
        $sent += (int) $r['n'];
        $seen += (int) $r['seen'];
        $replied += (int) $r['replied'];
        $paise += (int) $r['n'] * tg_wa_rate($cats[(string) $r['template']] ?? 'MARKETING');
    }
    return ui_kpis([
        ['label' => 'Sent this month', 'value' => h(num($sent)), 'note' => 'Broadcast messages, last 30 days'],
        ['label' => 'Read rate', 'value' => h(pct($seen, $sent)), 'note' => num($seen) . ' read'],
        ['label' => 'Replies', 'value' => h(num($replied)), 'note' => 'Wrote back within 72 hours'],
        ['label' => 'Estimated spend this month', 'value' => h(money($paise)), 'note' => 'Our estimate. Meta\'s invoice is the truth.'],
    ]);
}

/** Every broadcast, newest first. */
function admin_bc_list(PDO $pdo, int $now): string
{
    $stats = admin_bc_stats($pdo);
    $list = rows($pdo, 'SELECT b.*, t.name AS template_name FROM wa_broadcasts b LEFT JOIN wa_templates t ON t.id = b.template_id ORDER BY b.id DESC LIMIT 200');
    $table = [];
    foreach ($list as $b) {
        $s = $stats[(int) $b['id']] ?? null;
        $when = $b['status'] === 'draft' ? '<span class="muted">Not sent</span>'
            : h(when($b['send_at'])) . ($b['finished_at'] ? '<small class="sub">Finished ' . h(when($b['finished_at'])) . '</small>' : '');
        $table[] = [
            '<a class="person" href="' . h(admin_url(['view' => 'broadcasts', 'b' => $b['id']])) . '">' . h($b['name']) . '</a>'
                . '<small class="sub"><span class="tpl-name">' . h((string) ($b['template_name'] ?? 'template gone')) . '</span></small>',
            admin_bc_status_pill((string) $b['status']),
            $when,
            $b['status'] === 'draft' ? '<span class="muted">—</span>' : h(num((int) $b['total'])),
            $s ? h(num($s['sent'])) : '<span class="muted">0</span>',
            $s ? h(num($s['seen'])) . '<small class="sub">' . h(pct($s['seen'], $s['sent'])) . '</small>' : '<span class="muted">0</span>',
            $s ? h(num($s['replied'])) : '<span class="muted">0</span>',
            $s && $s['failed'] > 0 ? '<span class="tone-bad">' . h(num($s['failed'])) . '</span>' : '<span class="muted">0</span>',
            '<a class="row-link" href="' . h(admin_url(['view' => 'broadcasts', 'b' => $b['id']])) . '">Open</a>',
        ];
    }
    return ui_card('Broadcasts', ui_table([['Broadcast', ''], ['Status', ''], ['When', 'nowrap'], ['People', 'num'], ['Sent', 'num'], ['Read', 'num'],
        ['Replied', 'num'], ['Failed', 'num'], ['', 'num']], $table,
        ['empty' => 'No broadcasts yet. Press "New broadcast" to write one.']),
        ['size' => 'full', 'flush' => true, 'id' => 'broadcasts', 'sub' => 'One approved template to a group, always tested first.']);
}

/**
 * The note about the checkout pop-up's offers box, until it is settled: shown until you
 * have sent a marketing broadcast to box-tickers only, or a new pop-up wording exists.
 * Null once settled.
 */
function admin_bc_offers_note(PDO $pdo): ?string
{
    if (count(TG_CONSENT_VERSIONS) > 1) {
        return null;
    }
    foreach (rows($pdo, "SELECT b.audience, t.category, t.meta_category FROM wa_broadcasts b JOIN wa_templates t ON t.id = b.template_id
        WHERE b.status IN ('scheduled', 'sending', 'done')") as $b) {
        $aud = json_decode((string) $b['audience'], true);
        if (tg_wat_category($b) === 'MARKETING' && !empty($aud['offers_box'])) {
            return null;
        }
    }
    return 'Before your first marketing broadcast, make the checkout pop-up and the privacy policy agree. The pop-up has a separate, unticked box for '
        . 'offers, and many people left it empty. Either send marketing broadcasts only with "Only people who ticked the offers box" on, or ask Claude to add '
        . 'a new pop-up wording that mentions occasional WhatsApp offers.';
}

/** Approved templates a broadcast can use: id => "name (marketing)". */
function admin_bc_template_options(PDO $pdo): array
{
    $out = [];
    foreach (tg_wat_approved($pdo) as $t) {
        $out[(string) $t['id']] = $t['name'] . ' (' . strtolower(tg_wat_category($t)) . ')';
    }
    return $out;
}

/** Sessions with paid buyers, newest first: stored time => "Sun 29 Nov, 3 PM". */
function admin_bc_sessions(PDO $pdo, string $product): array
{
    $out = [];
    foreach (rows($pdo, "SELECT DISTINCT session_at FROM bookings WHERE status = 'paid' AND product = ? AND session_at IS NOT NULL ORDER BY session_at DESC LIMIT 30",
        [$product]) as $r) {
        $out[(string) $r['session_at']] = session_label((string) $r['session_at']);
    }
    return $out;
}

/** Tags in use: tag => "tag (N)". */
function admin_bc_tags(PDO $pdo): array
{
    $out = [];
    foreach (rows($pdo, 'SELECT tag, COUNT(*) AS n FROM wa_tags GROUP BY tag ORDER BY tag LIMIT 200') as $r) {
        $out[(string) $r['tag']] = $r['tag'] . ' (' . num((int) $r['n']) . ')';
    }
    return $out;
}

/** The numbers a test may go to now: the test list while it has numbers, else your own number from Settings. */
function admin_bc_test_targets(array $config): array
{
    $test = tg_wa_test_numbers($config);
    if ($test !== []) {
        return $test;
    }
    $mine = tg_wa_my_number($config);
    return $mine !== null ? [$mine] : [];
}

/** Step 1: the name, the template and what fills each variable. $b null is a new broadcast. */
function admin_bc_step_message(PDO $pdo, ?array $b): string
{
    $options = admin_bc_template_options($pdo);
    if ($options === []) {
        return ui_card('1. Message', ui_empty('No approved templates yet',
            'A broadcast sends one of your own templates. Write one and get it approved first.',
            ui_button('Open Templates', admin_url(['view' => 'wa_templates']), 'primary')), ['size' => 'half', 'id' => 'step1']);
    }
    $tid = $b !== null ? (string) $b['template_id'] : (string) array_key_first($options);
    $inner = ui_field('Name', 'name', 'maxlength="80" required autocomplete="off" placeholder="December class invite" value="' . h((string) ($b['name'] ?? '')) . '"',
        'Only you see it.')
        . ui_select('Template', 'template_id', ($b !== null && !isset($options[$tid]) ? [$tid => 'Not approved any more'] : []) + $options, $tid,
            'Only approved templates. Changing it resets the choices below.');
    $t = $b !== null ? tg_bc_template($pdo, $b) : null;
    if ($t !== null) {
        $slots = tg_wat_slots($t);
        if ($slots) {
            $inner .= '<h3 class="sub-head">What fills each part</h3><p class="muted small">For each person, from their details, or the same text for everyone.</p>';
            foreach ($slots as $key => $field) {
                $label = $key[0] === 'b' ? '{{' . substr($key, 1) . '}}' : 'The link in button ' . ((int) substr($key, 1) + 1);
                $inner .= '<div class="fields">'
                    . ui_select($label . ' is filled with', 'fields[' . $key . ']', TG_WA_FIELDS, $field)
                    . ui_field('Text for ' . $label, 'fixed[' . $key . ']', 'maxlength="200" autocomplete="off" value="' . h((string) ($b['fields']['fixed'][$key] ?? '')) . '"',
                        'Used when it is filled with "Text you type".')
                    . '</div>';
            }
        }
    } elseif ($b === null) {
        $inner .= '<p class="muted small">After you save, you choose what fills each part of the template, then who gets it.</p>';
    }
    return ui_card('1. Message', ui_post('bc_save', ['b' => $b['id'] ?? '', 'step' => 'message', '#' => $b === null ? 'step1' : 'step2'], $inner,
        $b === null ? 'Save and continue' : 'Save message', 'primary', 'stack'),
        ['size' => 'half', 'id' => 'step1', 'sub' => 'One approved template, filled in for each person.']);
}

/** Step 2: who gets it. */
function admin_bc_step_who(PDO $pdo, array $config, array $b): string
{
    $a = $b['audience'];
    $bases = TG_BC_BASES + ($a['base'] === 'not_read_of' ? ['not_read_of' => 'People who did not read the first send'] : []);
    $days = TG_BC_DAYS[$a['base']] ?? null;
    $tags = admin_bc_tags($pdo);
    $inner = ui_select('Send to', 'base', $bases, $a['base'], 'Only people who agreed to booking updates or messaged you. Never anyone who said STOP.')
        . ui_select('Session', 'session', ['' => 'Any session'] + admin_bc_sessions($pdo, (string) $config['product']), $a['session'],
            'For paid buyers only.')
        . ui_select('Period', 'days', ['' => 'Default'] + (TG_BC_DAYS['unpaid'] + TG_BC_DAYS['messaged']), $days !== null ? (string) $a['days'] : '',
            'For checkouts not paid (7, 30 or 90 days) and people who messaged you (24 hours, 7 or 30 days, or ever).')
        . '<div class="fields">'
        . ui_select('Only with the tag', 'tag_in', ['' => 'Any'] + $tags, $a['tag_in'])
        . ui_select('Leave out the tag', 'tag_out', ['' => 'None'] + $tags, $a['tag_out'])
        . '</div>'
        . '<label class="tick tick-plain"><input type="checkbox" name="offers_box" value="1"' . ($a['offers_box'] ? ' checked' : '') . '> Only people who ticked the offers box at checkout</label>';
    return ui_card('2. Who', ui_post('bc_save', ['b' => $b['id'], 'step' => 'who', '#' => 'preview'], $inner, 'Save and preview', 'primary', 'stack'),
        ['size' => 'half', 'id' => 'step2', 'sub' => tg_bc_audience_text($a)]);
}

/**
 * The preview: how many, who was left out and why, the offers box, the first ten people
 * with their own message, the cost, and the plan against the daily limit. $plan is
 * tg_bc_people's answer; $days the day plan.
 */
function admin_bc_preview(array $config, array $b, ?array $plan, array $cap, array $days): string
{
    if ($plan === null) {
        return ui_card('Preview', ui_empty('The template is gone', 'Pick another template in step 1.'), ['size' => 'full', 'id' => 'preview']);
    }
    $count = count($plan['people']);
    $category = $plan['category'];
    $rate = tg_wa_rate($category);
    $html = '<p class="lead-line"><strong>' . h(num($count)) . ' ' . ($count === 1 ? 'person' : 'people') . '</strong> would get it now. '
        . h(tg_bc_audience_text($b['audience'])) . '.</p>';

    $facts = [];
    $facts[] = ['Estimated cost', h(money($count * $rate)) . ' <span class="muted">(' . h(num($count)) . ' × ₹' . h(number_format($rate / 100, 2))
        . ', ' . h(strtolower($category)) . ($category === 'UTILITY' ? '; free for anyone whose 24-hour window is open' : '') . ')</span>'];
    $facts[] = ['Ticked the offers box', h(num($plan['offers_box'])) . ' of ' . h(num($count))];
    $limit = num($cap['cap']) . ' people a day';
    if ($count > 0 && $days === []) {
        $facts[] = ['Daily limit', h($limit) . '. <span class="tone-bad">At a limit of 0 nothing can go. Raise it in Settings.</span>'];
    } elseif ($count > 0) {
        $facts[] = ['Daily limit', 'About ' . h(num($days[0])) . ' can go today. At your limit (' . h($limit) . ') this takes '
            . h(num(count($days))) . ' day' . (count($days) === 1 ? '' : 's') . '.'];
    }
    $html .= ui_facts($facts);
    if (count($days) > TG_BC_MAX_DAYS) {
        $html .= '<p class="notice notice-bad">' . icon('alert') . 'That is more than 7 days of sends. Narrow the audience or raise the limit.</p>';
    }
    if ($category === 'MARKETING' && $plan['offers_box'] < $count) {
        $html .= '<p class="notice notice-warn">' . icon('alert') . h(num($count - $plan['offers_box'])) . ' of these did not tick the offers box. '
            . 'The others agreed to booking updates or messaged you, but did not tick the offers box.</p>';
    }
    if ($plan['skipped']) {
        $items = [];
        foreach ($plan['skipped'] as $why => $n) {
            $items[] = '<li>' . h($why) . ': <strong>' . h(num($n)) . '</strong></li>';
        }
        $html .= '<h3 class="sub-head">Left out</h3><ul class="plain skipped">' . implode('', $items) . '</ul>';
    }
    $rows = [];
    foreach (array_slice($plan['people'], 0, 10) as $p) {
        $rows[] = [h($p['name'] !== '' ? $p['name'] : 'No name') . '<small class="sub">' . h(tg_wa_pretty($p['phone'])) . '</small>',
            '<span class="msg-preview">' . h(tg_wat_text(tg_wat_render($plan['template'], $p['values']))) . '</span>'];
    }
    $html .= '<h3 class="sub-head">The first ' . min(10, $count) . ', as each will read it</h3>'
        . ui_table([['Person', ''], ['Their message', '']], $rows, ['empty' => 'Nobody yet. Change who it goes to.']);
    return ui_card('Preview', $html, ['size' => 'full', 'id' => 'preview', 'sub' => 'Worked out again every time this page opens.']);
}

/** Step 3: the test, needed again after any change to steps 1 and 2. */
function admin_bc_step_test(array $config, array $b, ?array $plan): string
{
    $tested = $b['test_hash'] !== null && hash_equals((string) $b['test_hash'], tg_bc_hash($b));
    $status = $tested
        ? '<p class="notice notice-good">' . icon('check') . h('Tested ' . when($b['test_at']) . ' to ' . tg_wa_pretty((string) $b['test_to']) . '. Nothing changed since.') . '</p>'
        : ($b['test_at'] !== null
            ? '<p class="notice notice-warn">' . icon('alert') . 'Something changed since the last test. Send a new one.</p>'
            : '<p class="notice">' . icon('info') . 'Not tested yet. A test is required before sending.</p>');
    $targets = admin_bc_test_targets($config);
    $listSet = tg_wa_test_numbers($config) !== [];
    if (!tg_wa_enabled($config) || tg_wa_paused($config)) {
        $form = '<p class="notice">' . icon('info') . (tg_wa_paused($config) ? 'WhatsApp is paused in Settings, so no test can go.' : 'WhatsApp sending is off in the config, so no test can go.') . '</p>';
    } elseif ($targets === []) {
        $form = '<p class="notice">' . icon('info') . 'Add your own number in Settings (Broadcasts) to send tests.</p>';
    } elseif ($plan === null || $plan['people'] === []) {
        $form = '<p class="notice">' . icon('info') . 'Nobody is in this audience yet, so there is nothing to test.</p>';
    } else {
        $first = $plan['people'][0];
        $form = ui_post('bc_test', ['b' => $b['id'], 'once' => admin_once(), '#' => 'step3'],
            ui_select('Send the test to', 'to', array_combine($targets, array_map('tg_wa_pretty', $targets)), $targets[0],
                $listSet ? 'While the test list is set, tests go only to numbers on it.' : 'Your own number, from Settings.')
            . '<p class="muted small">Filled with ' . h($first['name'] !== '' ? $first['name'] : 'the first person') . '\'s real details, the first person in the preview. '
            . 'Meta charges for it like any template.</p>', 'Send a test', 'quiet', 'stack');
    }
    return ui_card('3. Test', $status . $form, ['size' => 'half', 'id' => 'step3', 'sub' => 'See it on your own phone before anyone else does.']);
}

/** Step 4: send now or at a time, with the count typed to confirm. */
function admin_bc_step_send(array $config, array $b, ?array $plan, array $days, int $now): string
{
    $count = $plan !== null ? count($plan['people']) : 0;
    $tested = $b['test_hash'] !== null && hash_equals((string) $b['test_hash'], tg_bc_hash($b));
    $marketing = $plan !== null && $plan['category'] === 'MARKETING';
    $blocked = [];
    if (!tg_wa_enabled($config) || tg_wa_paused($config)) {
        $blocked[] = tg_wa_paused($config) ? 'WhatsApp is paused in Settings.' : 'WhatsApp sending is off in the config.';
    }
    if ($count === 0) {
        $blocked[] = 'Nobody is in this audience.';
    }
    if (!$tested) {
        $blocked[] = 'Send a test first (step 3).';
    }
    if ($count > 0 && ($days === [] || count($days) > TG_BC_MAX_DAYS)) {
        $blocked[] = $days === [] ? 'Your daily limit is 0.' : 'That is more than 7 days of sends at your daily limit.';
    }
    $note = $marketing ? '<p class="muted small">Marketing never goes between 9 PM and 9 AM. "Send now" during those hours starts at 9 AM.'
        . ($marketing && tg_wa_quiet_hours($now) ? ' It is quiet hours now, so it would start at ' . h(date('g A', tg_wa_quiet_end($now))) . '.' : '') . '</p>'
        : '<p class="muted small">Utility messages may go at any hour.</p>';
    if ($blocked) {
        return ui_card('4. Send', '<ul class="plain blocked">' . implode('', array_map(static fn($x) => '<li>' . icon('info') . h($x) . '</li>', $blocked)) . '</ul>' . $note,
            ['size' => 'half', 'id' => 'step4', 'sub' => 'Nothing goes until you type the count.']);
    }
    $later = (new DateTimeImmutable('@' . ($now + 3600)))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m-d\TH:00');
    $inner = ui_choice('When', 'when', ['now' => 'Send now', 'later' => 'At a time'], 'now')
        . ui_field('Date and time (IST)', 'at', 'type="datetime-local" value="' . h($later) . '"', 'Only for "At a time". Up to 30 days ahead.')
        . $note
        . ui_field('Type the number of people to confirm: ' . num($count), 'count', 'inputmode="numeric" required autocomplete="off" pattern="[0-9,]*"',
            'If the audience changed since this page opened, you will be told the new number.');
    return ui_card('4. Send', ui_post('bc_confirm', ['b' => $b['id'], '#' => 'top'], $inner, 'Confirm and send', 'primary', 'stack'),
        ['size' => 'half', 'id' => 'step4', 'sub' => 'Goes in batches, after the booking messages, within your daily limit.']);
}

/** The results of a broadcast that was confirmed: counts with %, what to do next. */
function admin_bc_results(PDO $pdo, array $b, array $s, int $now): string
{
    $base = max(1, $s['total']);
    $line = static fn(string $label, int $n, string $tone = '') => [$label, '<strong' . ($tone !== '' ? ' class="tone-' . $tone . '"' : '') . '>' . h(num($n)) . '</strong> '
        . '<span class="muted">' . h(pct($n, $base)) . '</span>'];
    $html = ui_facts([
        $line('Sent', $s['sent']),
        $line('Delivered', $s['delivered']),
        $line('Read', $s['seen']),
        $line('Replied', $s['replied']),
        $line('Failed', $s['failed'], $s['failed'] > 0 ? 'bad' : ''),
        $line('Not sent', $s['not_sent']),
        $line('Waiting', $s['waiting']),
    ]);
    $html .= '<p class="muted small">"Replied" means they wrote to you within 72 hours of getting it. Percentages are of everyone queued.</p>';
    if (in_array($b['status'], ['scheduled', 'sending'], true)) {
        $html .= '<p class="notice">' . icon('info') . h($b['status'] === 'scheduled' && tg_wa_ts((string) $b['send_at']) > $now
            ? 'Starts ' . when($b['send_at']) . '.' : 'Going out in batches every 5 minutes, within your daily limit.') . '</p>'
            . ui_button('Cancel the rest', admin_url(['view' => 'broadcasts', 'b' => $b['id'], 'ask' => 'cancel']) . '#confirm', 'quiet');
    } elseif ($b['status'] === 'done') {
        $ready = $b['finished_at'] !== null && tg_wa_ts((string) $b['finished_at']) + TG_BC_RESEND_AFTER <= $now;
        $unread = (int) value($pdo, "SELECT COUNT(*) FROM outbox WHERE source = 'broadcast' AND source_id = ? AND status = 'sent' AND read_at IS NULL", [$b['id']]);
        if ($b['audience']['base'] !== 'not_read_of' && $unread > 0) {
            $html .= $ready
                ? ui_post('bc_resend', ['b' => $b['id']], '<p class="muted small">' . h(num($unread)) . ' got it but have not read it. '
                    . 'This makes a new draft for them, with the same message. It needs its own test and confirmation.</p>', 'Resend to people who did not read', 'quiet', 'stack')
                : '<p class="notice">' . icon('info') . h('Resend to people who did not read opens ' . when(tg_ist(tg_wa_ts((string) $b['finished_at']) + TG_BC_RESEND_AFTER)) . ', 12 hours after it finished.') . '</p>';
        }
    }
    return ui_card('Results', $html, ['size' => 'half', 'id' => 'results']);
}

/** Why some did not get it, grouped. */
function admin_bc_reasons(PDO $pdo, int $id): string
{
    $rows = [];
    foreach (rows($pdo, "SELECT status, COALESCE(last_error, 'No reason given') AS why, COUNT(*) AS n FROM outbox
        WHERE source = 'broadcast' AND source_id = ? AND status IN ('failed', 'expired', 'cancelled') GROUP BY status, why ORDER BY n DESC", [$id]) as $r) {
        $rows[] = [h((string) $r['why']), $r['status'] === 'failed' ? ui_pill('Failed', 'bad') : ui_pill('Not sent', 'neutral'), h(num((int) $r['n']))];
    }
    return ui_card('Why some did not get it', ui_table([['Reason', ''], ['', ''], ['People', 'num']], $rows, ['empty' => 'Everyone got it, or is still waiting.']),
        ['size' => 'half', 'flush' => true, 'id' => 'reasons']);
}

/** Everyone in a confirmed broadcast, with what happened. */
function admin_bc_people_table(PDO $pdo, int $id, int $now): string
{
    $list = rows($pdo, "SELECT o.id, o.to_addr, o.status, o.due_at, o.next_try_at, o.sent_at, o.delivered_at, o.read_at, o.last_error, o.params,
            c.name AS cname, wc.profile_name, wc.id AS wcid, o.status = 'sent' AND " . admin_bc_replied_sql() . " AS replied
        FROM outbox o LEFT JOIN wa_contacts wc ON wc.phone = o.to_addr LEFT JOIN contacts c ON c.id = wc.contact_id
        WHERE o.source = 'broadcast' AND o.source_id = ? ORDER BY o.due_at, o.id LIMIT 300", [$id]);
    $table = [];
    foreach ($list as $o) {
        $params = json_decode((string) ($o['params'] ?? ''), true);
        $name = trim((string) ($o['cname'] ?? '')) ?: (trim((string) ($params['name'] ?? '')) ?: trim((string) ($o['profile_name'] ?? '')));
        $who = $o['wcid'] ? '<a class="person" href="' . h(admin_url(['view' => 'inbox', 'c' => $o['wcid']])) . '">' . h($name !== '' ? $name : tg_wa_pretty($o['to_addr'])) . '</a>'
            : h($name !== '' ? $name : tg_wa_pretty($o['to_addr']));
        $state = match ($o['status']) {
            'sent'              => $o['read_at'] ? ui_pill('Read', 'good') : ($o['delivered_at'] ? ui_pill('Delivered', 'good') : ui_pill('Sent', 'neutral')),
            'queued', 'sending' => ui_pill('Waiting', 'info'),
            'failed'            => ui_pill('Failed', 'bad'),
            default             => ui_pill('Not sent', 'neutral'),
        };
        if ((int) $o['replied'] === 1) {
            $state .= ' ' . ui_pill('Replied', 'info');
        }
        $note = $o['status'] === 'queued' && $o['last_error'] ? h((string) $o['last_error']) . ($o['next_try_at'] ? ', next try ' . h(when($o['next_try_at'])) : '')
            : ($o['status'] === 'queued' ? 'Goes ' . h(when($o['due_at'])) : ($o['last_error'] ? h((string) $o['last_error'])
            : ($o['read_at'] ? 'Read ' . h(when($o['read_at'])) : ($o['sent_at'] ? 'Sent ' . h(when($o['sent_at'])) : ''))));
        $table[] = [$who . '<small class="sub">' . h(tg_wa_pretty($o['to_addr'])) . '</small>', $state, '<span class="muted">' . $note . '</span>'];
    }
    return ui_card('People', ui_table([['Person', ''], ['Result', ''], ['Note', '']], $table,
        ['empty' => 'Nobody was queued.', 'note' => count($list) === 300 ? 'Showing the first 300.' : '']),
        ['size' => 'full', 'flush' => true, 'id' => 'people']);
}

/** The four numbers at the top of one broadcast. */
function admin_bc_kpis(array $b, ?array $plan, array $s): string
{
    if ($b['status'] === 'draft') {
        $count = $plan !== null ? count($plan['people']) : 0;
        $cost = $plan !== null ? $count * tg_wa_rate($plan['category']) : 0;
        return ui_kpis([
            ['label' => 'People', 'value' => h(num($count)), 'note' => 'In the audience now'],
            ['label' => 'Sent', 'value' => '<span class="muted">—</span>', 'note' => 'Not sent yet'],
            ['label' => 'Read', 'value' => '<span class="muted">—</span>', 'note' => 'Shows once sent'],
            ['label' => 'Estimated cost', 'value' => h(money($cost)), 'note' => $plan !== null ? num($count) . ' × ₹' . number_format(tg_wa_rate($plan['category']) / 100, 2) : ''],
        ]);
    }
    return ui_kpis([
        ['label' => 'People', 'value' => h(num((int) $b['total'])), 'note' => 'Queued at confirmation'],
        ['label' => 'Sent', 'value' => h(num($s['sent'])), 'note' => $s['waiting'] > 0 ? num($s['waiting']) . ' still waiting' : ($s['failed'] > 0 ? num($s['failed']) . ' failed' : 'All done'),
         'tone' => $s['failed'] > 0 ? 'bad' : null],
        ['label' => 'Read', 'value' => h(num($s['seen'])), 'note' => pct($s['seen'], $s['sent']) . ' of those sent'],
        ['label' => 'Estimated cost', 'value' => h(money($s['est_paise'])), 'note' => 'Sent or still to go, not failed'],
    ]);
}

/** The broadcast from the form: name, template and fields (step "message"), or the audience (step "who"). */
function admin_bc_from_post(PDO $pdo, ?array $old, string $step): array
{
    $b = $old ?? ['id' => 0, 'name' => '', 'template_id' => 0, 'fields' => ['fields' => [], 'fixed' => []],
        'audience' => tg_bc_clean_audience(['base' => 'buyers']), 'parent_id' => null];
    if ($step === 'message') {
        $b['name'] = rp_clean($_POST['name'] ?? '', 80);
        $tid = (int) ($_POST['template_id'] ?? 0);
        $t = tg_wat_get($pdo, $tid);
        $changed = $tid !== (int) $b['template_id'];
        $b['template_id'] = $tid;
        $map = [];
        $fixed = [];
        if ($t !== null) {
            $postedFields = is_array($_POST['fields'] ?? null) ? $_POST['fields'] : [];
            $postedFixed = is_array($_POST['fixed'] ?? null) ? $_POST['fixed'] : [];
            foreach (tg_wat_slots($t) as $key => $default) {
                $f = !$changed && is_string($postedFields[$key] ?? null) ? $postedFields[$key] : $default;
                $map[$key] = isset(TG_WA_FIELDS[$f]) ? $f : $default;
                $text = !$changed && is_string($postedFixed[$key] ?? null) ? tg_wat_clean_value($postedFixed[$key]) : '';
                if ($text !== '') {
                    $fixed[$key] = mb_substr($text, 0, 200);
                }
            }
        }
        $b['fields'] = ['fields' => $map, 'fixed' => $fixed];
    } else {
        $base = is_string($_POST['base'] ?? null) ? $_POST['base'] : 'buyers';
        $b['audience'] = tg_bc_clean_audience([
            'base'       => $base,
            'session'    => $_POST['session'] ?? '',
            'tag_in'     => $_POST['tag_in'] ?? '',
            'tag_out'    => $_POST['tag_out'] ?? '',
            'days'       => $_POST['days'] ?? '',
            'offers_box' => ($_POST['offers_box'] ?? '') === '1',
            'parent'     => $base === 'not_read_of' ? (int) ($old['audience']['parent'] ?? 0) : 0,
        ]);
        if ($base === 'not_read_of' && (int) ($old['audience']['parent'] ?? 0) === 0) {
            $b['audience'] = tg_bc_clean_audience(['base' => 'buyers']);
        }
    }
    return $b;
}

/** Every change the Broadcasts page makes. Called from admin_handle_post with the token already checked. */
function admin_bc_action(PDO $pdo, array $config, string $action): void
{
    $now = rp_now($config);
    $at = tg_ist($now);
    $id = (int) ($_POST['b'] ?? 0);
    $b = $id > 0 ? tg_bc_get($pdo, $id) : null;
    $page = static fn(int $bid, string $anchor = ''): string => './?view=broadcasts&b=' . $bid . ($anchor !== '' ? '#' . $anchor : '');
    if ($id > 0 && $b === null) {
        admin_flash('bad', 'That broadcast is not here. Nothing changed.');
        $_POST['back'] = './?view=broadcasts';
        return;
    }
    $label = $b !== null ? $b['name'] . ' (broadcast ' . $b['id'] . ')' : '';

    switch ($action) {
        case 'bc_save':
            if ($b !== null && $b['status'] !== 'draft') {
                admin_flash('bad', 'Only a draft can be changed. Nothing was saved.');
                return;
            }
            $step = ($_POST['step'] ?? '') === 'who' && $b !== null ? 'who' : 'message';
            $new = admin_bc_from_post($pdo, $b, $step);
            if ($step === 'message') {
                if ($new['name'] === '') {
                    admin_flash('bad', 'Give the broadcast a name. Nothing was saved.');
                    return;
                }
                $t = tg_wat_get($pdo, (int) $new['template_id']);
                if ($t === null || $t['status'] !== 'APPROVED' || $t['deleted_at'] !== null) {
                    admin_flash('bad', 'Pick an approved template. Nothing was saved.');
                    return;
                }
            }
            $json = static fn($v): string => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($b === null) {
                $pdo->prepare("INSERT INTO wa_broadcasts (name, template_id, fields, audience, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'draft', ?, ?)")
                    ->execute([$new['name'], $new['template_id'], $json($new['fields']), $json($new['audience']), $at, $at]);
                $id = (int) $pdo->lastInsertId();
            } else {
                $keep = $b['test_hash'] !== null && hash_equals((string) $b['test_hash'], tg_bc_hash($new));
                $pdo->prepare("UPDATE wa_broadcasts SET name = ?, template_id = ?, fields = ?, audience = ?, test_hash = ?, updated_at = ? WHERE id = ? AND status = 'draft'")
                    ->execute([$new['name'], $new['template_id'], $json($new['fields']), $json($new['audience']), $keep ? $b['test_hash'] : null, $at, $id]);
            }
            tg_audit($config, 'admin', 'broadcast_saved', $new['name'] . ' (broadcast ' . $id . '), ' . $step . ': ' . tg_bc_audience_text($new['audience']));
            $retest = $b !== null && $b['test_hash'] !== null && !hash_equals((string) $b['test_hash'], tg_bc_hash($new));
            admin_flash('good', 'Saved.' . ($retest ? ' It changed since the test, so send a new test before sending.' : ''));
            $_POST['back'] = $page($id, $step === 'message' ? ($b === null ? 'step2' : 'step1') : 'preview');
            return;

        case 'bc_test':
            if ($b === null || $b['status'] !== 'draft') {
                admin_flash('bad', 'Only a draft is tested. Nothing was sent.');
                return;
            }
            $_POST['back'] = $page($id, 'step3');
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
            $plan = tg_bc_people($pdo, $config, $b, $now);
            $t = $plan['template'] ?? null;
            if ($plan === null || $t === null || $t['status'] !== 'APPROVED' || $t['deleted_at'] !== null) {
                admin_flash('bad', 'Not sent. The template is not approved any more.');
                return;
            }
            if ($plan['people'] === []) {
                admin_flash('bad', 'Not sent. Nobody is in this audience, so there is nothing to test.');
                return;
            }
            if (!admin_once_take()) {
                admin_flash('warn', 'That test was already sent, so it was not sent again.');
                return;
            }
            $first = $plan['people'][0];
            $result = tg_wat_send($config, $t, $to, $first['values']);
            // Source 'test': never in the summary's counts, and a late failure from Meta never alerts.
            $pdo->prepare("INSERT INTO outbox (dedupe_key, channel, template, booking_id, to_addr, due_at, expires_at, status, tries, last_error, sent_at, provider_id, alerted_at, source)
                VALUES (?, 'whatsapp', ?, NULL, ?, ?, ?, ?, 1, ?, ?, ?, ?, 'test')")
                ->execute(['wa:bctest:' . $id . ':' . bin2hex(random_bytes(6)), 'c:' . $t['id'], $to, $at, $at, $result['ok'] ? 'sent' : 'failed',
                    $result['ok'] ? 'Broadcast test' : mb_substr((string) $result['error'], 0, 255), $result['ok'] ? $at : null, $result['id'], $at]);
            tg_wa_log_out($pdo, $to, ['kind' => 'template', 'body' => tg_wat_text(tg_wat_render($t, $first['values'])), 'template' => $t['name'],
                'category' => $plan['category'], 'sent_by' => 'test', 'wa_id' => $result['id'], 'outbox_id' => (int) $pdo->lastInsertId(),
                'status' => $result['ok'] ? 'sent' : 'failed', 'error' => $result['ok'] ? null : $result['error']], $now);
            if ($result['ok']) {
                $pdo->prepare('UPDATE wa_broadcasts SET test_hash = ?, test_at = ?, test_to = ?, updated_at = ? WHERE id = ?')
                    ->execute([tg_bc_hash($b), $at, $to, $at, $id]);
            }
            tg_audit($config, 'admin', 'broadcast_test', $label . ' to ' . tg_wa_pretty($to) . ': ' . ($result['ok'] ? 'sent' : $result['error']));
            admin_flash($result['ok'] ? 'good' : 'bad', $result['ok']
                ? 'Test sent to ' . tg_wa_pretty($to) . ', filled with ' . ($first['name'] !== '' ? tg_first_name($first['name']) . '\'s' : 'the first person\'s') . ' details. Check your phone.'
                : 'The test was not sent. ' . admin_wa_next_step((string) $result['error']));
            return;

        case 'bc_confirm':
            if ($b === null || $b['status'] !== 'draft') {
                admin_flash('bad', 'Only a draft can be sent. Nothing changed.');
                return;
            }
            $_POST['back'] = $page($id, 'step4');
            admin_bc_confirm($pdo, $config, $b, $now);
            return;

        case 'bc_cancel':
            if ($b === null || !in_array($b['status'], ['scheduled', 'sending'], true) || ($_POST['confirm'] ?? '') !== '1') {
                admin_flash('bad', 'Nothing changed. Only a broadcast that is scheduled or sending can be cancelled, after you confirm.');
                return;
            }
            $q = $pdo->prepare("UPDATE outbox SET status = 'cancelled', last_error = 'Broadcast cancelled' WHERE source = 'broadcast' AND source_id = ? AND status = 'queued'");
            $q->execute([$id]);
            $n = $q->rowCount();
            $pdo->prepare("UPDATE wa_broadcasts SET status = 'cancelled', cancelled_at = ?, updated_at = ? WHERE id = ?")->execute([$at, $at, $id]);
            tg_audit($config, 'admin', 'broadcast_cancelled', $label . ': ' . $n . ' not sent');
            admin_flash('good', 'Cancelled. ' . num($n) . ' ' . ($n === 1 ? 'person' : 'people') . ' will not get it. Messages already sent stay sent.');
            $_POST['back'] = $page($id);
            return;

        case 'bc_resend':
            if ($b === null || $b['status'] !== 'done' || $b['finished_at'] === null || tg_wa_ts((string) $b['finished_at']) + TG_BC_RESEND_AFTER > $now) {
                admin_flash('bad', 'Resend opens 12 hours after a broadcast finishes. Nothing changed.');
                return;
            }
            if ($b['audience']['base'] === 'not_read_of') {
                admin_flash('bad', 'This is already a resend. Nothing changed.');
                return;
            }
            $name = mb_substr($b['name'] . ' (resend)', 0, 80);
            $pdo->prepare("INSERT INTO wa_broadcasts (name, template_id, fields, audience, parent_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'draft', ?, ?)")
                ->execute([$name, $b['template_id'], json_encode($b['fields'], JSON_UNESCAPED_UNICODE),
                    json_encode(['base' => 'not_read_of', 'parent' => $id]), $id, $at, $at]);
            $new = (int) $pdo->lastInsertId();
            tg_audit($config, 'admin', 'broadcast_resend', $label . ' copied as broadcast ' . $new . ' for people who did not read');
            admin_flash('good', 'A new draft for people who did not read. Test it, then confirm it.');
            $_POST['back'] = $page($new, 'preview');
            return;

        case 'bc_delete':
            if ($b === null || $b['status'] !== 'draft' || ($_POST['confirm'] ?? '') !== '1') {
                admin_flash('bad', 'Nothing changed. Only a draft can be deleted, after you confirm.');
                return;
            }
            $pdo->prepare("DELETE FROM wa_broadcasts WHERE id = ? AND status = 'draft'")->execute([$id]);
            tg_audit($config, 'admin', 'broadcast_deleted', $label);
            admin_flash('good', 'Draft ' . $b['name'] . ' deleted.');
            $_POST['back'] = './?view=broadcasts';
            return;
    }
}

/**
 * Confirms a tested draft: the count typed must match a fresh recount, the time must be
 * fine (and outside quiet hours for marketing), the day plan must fit in 7 days. Then
 * every person becomes an outbox row, spread by day at the daily limit, each with its own
 * 48-hour expiry, and the broadcast is scheduled.
 */
function admin_bc_confirm(PDO $pdo, array $config, array $b, int $now): void
{
    $id = $b['id'];
    if (!tg_wa_enabled($config) || tg_wa_paused($config)) {
        admin_flash('bad', 'Nothing was sent. ' . (tg_wa_paused($config) ? 'WhatsApp is paused in Settings.' : 'WhatsApp sending is off in the config.'));
        return;
    }
    if ($b['test_hash'] === null || !hash_equals((string) $b['test_hash'], tg_bc_hash($b))) {
        admin_flash('bad', 'Send a test first. Anything changed since the last test needs a new one. Nothing was sent.');
        return;
    }
    $plan = tg_bc_people($pdo, $config, $b, $now);
    $t = $plan['template'] ?? null;
    if ($plan === null || $t === null || $t['status'] !== 'APPROVED' || $t['deleted_at'] !== null) {
        admin_flash('bad', 'The template is not approved any more. Nothing was sent.');
        return;
    }
    $count = count($plan['people']);
    if ($count === 0) {
        admin_flash('bad', 'Nobody is in this audience. Nothing was sent.');
        return;
    }
    $typed = preg_replace('/[\s,]/', '', is_string($_POST['count'] ?? null) ? $_POST['count'] : '');
    if ($typed === '' || !ctype_digit($typed) || (int) $typed !== $count) {
        admin_flash('bad', 'The audience is ' . num($count) . ' ' . ($count === 1 ? 'person' : 'people') . ' now. Check the preview, then type '
            . num($count) . ' to confirm. Nothing was sent.');
        return;
    }
    $marketing = $plan['category'] === 'MARKETING';
    $note = '';
    if (($_POST['when'] ?? 'now') === 'later') {
        $raw = is_string($_POST['at'] ?? null) ? $_POST['at'] : '';
        $when = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw, new DateTimeZone('Asia/Kolkata'));
        if ($when === false) {
            admin_flash('bad', 'Pick a date and time. Nothing was sent.');
            return;
        }
        $start = $when->getTimestamp();
        if ($start < $now - 60) {
            admin_flash('bad', 'That time has passed. Pick a time ahead. Nothing was sent.');
            return;
        }
        if ($start > $now + TG_BC_MAX_AHEAD) {
            admin_flash('bad', 'Pick a time within 30 days. Nothing was sent.');
            return;
        }
        if ($marketing && tg_wa_quiet_hours($start)) {
            admin_flash('bad', 'Marketing messages do not go out between 9 PM and 9 AM. Pick a time in the day. Nothing was sent.');
            return;
        }
        $start = max($start, $now);
    } else {
        $start = $now;
        if ($marketing && tg_wa_quiet_hours($now)) {
            $start = tg_wa_quiet_end($now);
            $note = ' It is quiet hours, so it starts at ' . date('g A, j M', $start) . '.';
        }
    }
    $cap = tg_wa_cap_state($pdo, $config, $now);
    $days = tg_bc_day_plan($count, $cap['cap'], $start <= $now + 86400 ? $cap['room'] : $cap['cap']);
    if ($days === []) {
        admin_flash('bad', 'Your daily limit is 0, so nothing can go. Raise it in Settings. Nothing was sent.');
        return;
    }
    if (count($days) > TG_BC_MAX_DAYS) {
        admin_flash('bad', 'That is more than 7 days of sends. Narrow the audience or raise the limit. Nothing was sent.');
        return;
    }
    $rate = tg_wa_rate($plan['category']);
    $pdo->beginTransaction();
    try {
        $claim = $pdo->prepare("UPDATE wa_broadcasts SET status = 'scheduled', send_at = ?, confirmed_at = ?, updated_at = ? WHERE id = ? AND status = 'draft'");
        $claim->execute([tg_ist($start), tg_ist($now), tg_ist($now), $id]);
        if ($claim->rowCount() !== 1) {
            $pdo->rollBack();
            admin_flash('bad', 'This broadcast was already confirmed. Nothing more was sent.');
            return;
        }
        $insert = $pdo->prepare("INSERT IGNORE INTO outbox (dedupe_key, channel, template, booking_id, to_addr, due_at, expires_at, params, source, source_id)
            VALUES (?, 'whatsapp', ?, NULL, ?, ?, ?, ?, 'broadcast', ?)");
        $queued = 0;
        $day = 0;
        $onDay = 0;
        foreach ($plan['people'] as $p) {
            while ($onDay >= $days[$day]) {
                $day++;
                $onDay = 0;
            }
            $onDay++;
            $due = $start + $day * 86400;
            $params = ['body' => $p['values']['body'], 'buttons' => (object) $p['values']['buttons'], 'name' => tg_first_name((string) $p['name'])];
            $insert->execute(['wa:bc' . $id . ':' . $p['phone'], 'c:' . $t['id'], $p['phone'], tg_ist($due), tg_ist($due + TG_WA_BULK_EXPIRY),
                json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
            $queued += $insert->rowCount();
        }
        $pdo->prepare('UPDATE wa_broadcasts SET total = ?, est_paise = ? WHERE id = ?')->execute([$queued, $queued * $rate, $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('broadcasts: confirm failed: ' . $e->getMessage());
        admin_flash('bad', 'The server could not save it, so nothing was sent. Try again.');
        return;
    }
    $span = count($days) . ' day' . (count($days) === 1 ? '' : 's');
    tg_audit($config, 'admin', 'broadcast_confirmed', $b['name'] . ': ' . $queued . ' people over ' . $span . ', ' . money($queued * $rate) . ' est.');
    admin_flash('good', 'Confirmed. ' . num($queued) . ' ' . ($queued === 1 ? 'person' : 'people') . ', '
        . ($start > $now ? 'starting ' . date('j M, g:i A', $start) : 'starting within 5 minutes') . ', over ' . $span . ' at your daily limit.' . $note);
    $_POST['back'] = './?view=broadcasts&b=' . $id;
}
