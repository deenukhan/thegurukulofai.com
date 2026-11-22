<?php
/**
 * The WhatsApp inbox: every conversation with the business number, read and answered
 * from the dashboard. Free text goes only inside Meta's 24-hour window; after it closes,
 * only an approved template (the follow-up, gurukul_follow_up) can restart it.
 *
 * Who may get what is decided in one place, tg_wa_can_message (api/razorpay/wa_store.php):
 * WhatsApp on, not paused, the test list, STOP, then the window or consent.
 * Every change here is a POST through lib/actions.php: page token, audit, message, back.
 * The audit names the number and a length, never the words of a message.
 */

/**
 * Templates the inbox may send to one person: key => label, name, approved, wrote_first
 * (the wording only fits people who wrote to us), category and custom (the wa_templates
 * row, or null for the built-in follow-up). The follow-up (reopen) says "following up on
 * your message", so it is never offered to a buyer who only agreed to booking updates.
 * Approved templates written on the Templates page are keyed c:<id>.
 */
function admin_inbox_templates(array $config, ?PDO $pdo = null): array
{
    $states = tg_wa_ready($config) ? (admin_wa_meta($config)['templates'] ?? null) : null;
    $out = [];
    foreach (['reopen' => true] as $key => $wroteFirst) {
        $t = tg_wa_templates($config)[$key];
        $out[$key] = ['label' => $t['label'], 'name' => $t['name'], 'wrote_first' => $wroteFirst, 'custom' => null,
            'category' => $t['category'] ?? 'UTILITY', 'approved' => strtoupper((string) ($states[$t['name']]['status'] ?? '')) === 'APPROVED'];
    }
    foreach ($pdo !== null ? tg_wat_approved($pdo) : [] as $t) {
        $out['c:' . $t['id']] = ['label' => $t['name'], 'name' => $t['name'], 'wrote_first' => false, 'custom' => $t,
            'category' => (string) ($t['meta_category'] ?: $t['category']), 'approved' => true];
    }
    return $out;
}

/** True when everything a custom template needs can be filled for this person (text typed at send time aside). */
function admin_inbox_fillable(array $config, array $t, array $person, int $now): bool
{
    $typed = array_fill_keys(array_keys(array_filter(tg_wat_slots($t), static fn(string $f): bool => $f === 'fixed')), 'x');
    return tg_wat_values($config, $t, $person, $typed, $now)['missing'] === [];
}

/** Why a template from the inbox was refused under the daily limit. */
function admin_inbox_cap_text(array $config): string
{
    return 'You\'ve reached your daily limit of ' . num(tg_wa_daily_cap($config)) . ' people in 24 hours, so nothing was sent. '
        . 'Raise it in Settings, or send it later.';
}

/** What Meta charges for one template, in words. */
function admin_inbox_cost(string $category): string
{
    return $category === 'MARKETING' ? 'Meta charges for this one: about ₹0.86 for marketing.' : 'Meta charges for this one: about ₹0.15 for utility.';
}

/** How many conversations have messages Deenu has not opened. For the menu badge. */
function admin_inbox_unread(PDO $pdo): int
{
    try {
        return (int) value($pdo, 'SELECT COUNT(*) FROM wa_contacts WHERE unread > 0');
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * The kind of the last thing this number sent us (reply, stop or start; reactions left
 * out), as an SQL subquery on the wa_contacts alias. A dashboard STOP or START counts too.
 * "stop" here means nothing can go to them: not even a free reply, until they write again.
 */
function admin_inbox_last_kind_sql(string $alias = 'wc'): string
{
    return "(SELECT wi.kind FROM wa_inbound wi WHERE wi.phone = {$alias}.phone AND wi.kind <> 'skip' ORDER BY wi.id DESC LIMIT 1)";
}

/** True when the last thing on record for this number is a STOP (from them or the dashboard). */
function admin_inbox_said_stop(PDO $pdo, string $phone): bool
{
    return value($pdo, "SELECT kind FROM wa_inbound WHERE phone = ? AND kind <> 'skip' ORDER BY id DESC LIMIT 1", [$phone]) === 'stop';
}

/**
 * The SQL for "waiting for you": not marked done, their last message is a real message
 * (not STOP or START) under 24 hours old, and nobody answered it by hand or with an automatic text (a keyword
 * reply). A template a rule sent on its own is not an answer.
 * One placeholder: the moment 24 hours ago. $alias is the wa_contacts alias.
 */
function admin_inbox_waiting_sql(string $alias = 'wc'): string
{
    return "{$alias}.closed_at IS NULL AND {$alias}.last_in_at >= ?
        AND NOT EXISTS (SELECT 1 FROM wa_messages r WHERE r.wa_contact_id = {$alias}.id AND r.direction = 'out'
            AND (r.sent_by = 'admin' OR (r.sent_by = 'auto' AND r.kind = 'text')) AND r.status <> 'failed' AND r.created_at >= {$alias}.last_in_at)
        AND " . admin_inbox_last_kind_sql($alias) . " = 'reply'";
}

/** "This number has a paid seat", as SQL on the wa_contacts alias. One placeholder: the product. */
function admin_inbox_buyer_sql(string $alias = 'wc'): string
{
    return "EXISTS (SELECT 1 FROM contacts pc JOIN bookings pb ON pb.contact_id = pc.id
        WHERE (pc.phone = {$alias}.phone OR pc.phone = RIGHT({$alias}.phone, 10)) AND pb.product = ? AND pb.status = 'paid')";
}

/** The four numbers at the top of the inbox list. */
function admin_inbox_kpis(PDO $pdo, int $now): string
{
    $day = tg_ist($now - TG_WA_WINDOW);
    $k = row($pdo, "SELECT COALESCE(SUM(wc.unread > 0), 0) AS unread,
            COALESCE(SUM(wc.last_in_at >= ? AND COALESCE(" . admin_inbox_last_kind_sql() . ", '') <> 'stop'), 0) AS open
        FROM wa_contacts wc", [$day]);
    $waiting = (int) value($pdo, 'SELECT COUNT(*) FROM wa_contacts wc WHERE ' . admin_inbox_waiting_sql(), [$day]);
    $week = row($pdo, "SELECT COALESCE(SUM(direction = 'in'), 0) AS i, COALESCE(SUM(direction = 'out'), 0) AS o FROM wa_messages WHERE created_at >= ?",
        [tg_ist($now - 7 * 86400)]);
    return ui_kpis([
        ['label' => 'Unread', 'value' => h(num((int) $k['unread'])), 'note' => 'Conversations with new messages', 'tone' => (int) $k['unread'] > 0 ? 'warn' : null],
        ['label' => 'Waiting for you', 'value' => h(num($waiting)), 'note' => 'They wrote last, under 24 hours ago', 'tone' => $waiting > 0 ? 'warn' : null],
        ['label' => 'Windows open', 'value' => h(num((int) $k['open'])), 'note' => 'You can reply for free'],
        ['label' => 'Messages this week', 'value' => h(num((int) $week['i'] + (int) $week['o'])), 'note' => num((int) $week['i']) . ' in · ' . num((int) $week['o']) . ' out'],
    ]);
}

/**
 * Conversations for the list, newest first, up to 100. $filter: show and q (name or number).
 * show: '' (the default) is people who wrote to us, newest message from them first, so
 * session-day reminders to every buyer never bury a real question. 'all' adds everyone we
 * only sent booking messages to. Then unread, waiting, buyers, leads (not buyers) and done.
 */
function admin_inbox_list(PDO $pdo, array $filter, int $now, string $product): array
{
    $where = ['1 = 1'];
    $args = [$product];
    $order = 'COALESCE(wc.last_msg_at, wc.created_at) DESC';
    switch ($filter['show'] ?? '') {
        case '':
            $where[] = 'wc.last_in_at IS NOT NULL';
            $order = 'wc.last_in_at DESC';
            break;
        case 'unread':
            $where[] = 'wc.unread > 0';
            break;
        case 'waiting':
            $where[] = admin_inbox_waiting_sql();
            $args[] = tg_ist($now - TG_WA_WINDOW);
            break;
        case 'buyers':
            $where[] = admin_inbox_buyer_sql();
            $args[] = $product;
            break;
        case 'leads':
            $where[] = 'NOT ' . admin_inbox_buyer_sql();
            $args[] = $product;
            break;
        case 'done':
            $where[] = 'wc.closed_at IS NOT NULL';
            break;
    }
    $q = trim((string) ($filter['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $digits = preg_replace('/\D+/', '', $q) ?? '';
        $where[] = '(c.name LIKE ? OR wc.profile_name LIKE ?' . ($digits !== '' ? ' OR wc.phone LIKE ?' : '') . ')';
        array_push($args, $like, $like);
        if ($digits !== '') {
            $args[] = '%' . $digits . '%';
        }
    }
    return rows($pdo, 'SELECT wc.*, c.name AS contact_name, lm.direction AS last_dir, lm.body AS last_body, lm.body_gone AS last_gone,
            ' . admin_inbox_last_kind_sql() . ' AS last_kind, ' . admin_inbox_buyer_sql() . ' AS buyer
        FROM wa_contacts wc
        LEFT JOIN contacts c ON c.id = wc.contact_id
        LEFT JOIN wa_messages lm ON lm.id = (SELECT MAX(m.id) FROM wa_messages m WHERE m.wa_contact_id = wc.id)
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY ' . $order . ', wc.id DESC LIMIT 100', $args);
}

/** One conversation with its linked contact's name and email, or null. */
function admin_inbox_thread(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $r = row($pdo, 'SELECT wc.*, c.name AS contact_name, c.email AS contact_email FROM wa_contacts wc
        LEFT JOIN contacts c ON c.id = wc.contact_id WHERE wc.id = ?', [$id]);
    return $r ?: null;
}

/** The name to show: their name from the booking form, else their WhatsApp name, else the number. */
function admin_inbox_name(array $wc): string
{
    foreach ([$wc['contact_name'] ?? '', $wc['profile_name'] ?? ''] as $name) {
        if (trim((string) $name) !== '') {
            return trim((string) $name);
        }
    }
    return tg_wa_pretty((string) $wc['phone']);
}

/** A length of time for people: "5 h 20 min", "40 min", "under a minute". */
function admin_inbox_span(int $seconds): string
{
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    if ($h === 0 && $m === 0) {
        return 'under a minute';
    }
    return trim(($h > 0 ? $h . ' h ' : '') . ($m > 0 ? $m . ' min' : ''));
}

/** "Said STOP", "Open, 5 h left", "Closed" or nothing, as a pill. A STOP wins: nothing can be sent. */
function admin_inbox_window_pill(?string $lastIn, int $now, bool $saidStop = false): string
{
    if ($saidStop) {
        return ui_pill('Said STOP', 'warn');
    }
    $left = tg_wa_window_left($lastIn, $now);
    if ($left > 0) {
        return ui_pill('Open, ' . ($left >= 3600 ? intdiv($left, 3600) . ' h' : max(1, intdiv($left, 60)) . ' min') . ' left', 'good');
    }
    return $lastIn === null ? '<span class="muted">—</span>' : ui_pill('Closed', 'neutral');
}

/** The last 200 messages of a conversation, oldest first. */
function admin_inbox_messages(PDO $pdo, int $id): array
{
    return array_reverse(rows($pdo, 'SELECT * FROM wa_messages WHERE wa_contact_id = ? ORDER BY id DESC LIMIT 200', [$id]));
}

/** The "Who this is" card: contact, bookings, consent, STOP, tags, done. */
function admin_inbox_person(PDO $pdo, array $config, array $wc, int $now): string
{
    $id = (int) $wc['id'];
    $phone = (string) $wc['phone'];
    $contacts = tg_wa_contacts($pdo, $phone);
    $ids = array_map('intval', array_column($contacts, 'id'));

    $who = [];
    if ($contacts) {
        foreach ($contacts as $i => $c) {
            $who[] = '<a class="person" href="' . h(admin_url(['view' => 'people', 'id' => $c['id']])) . '">' . h($c['name'] !== '' ? $c['name'] : $c['email']) . '</a>'
                . '<small class="sub">' . h($c['email']) . '</small>';
        }
    }
    $pairs = [
        ['Name', $who ? implode('', $who) : '<span class="muted">Not a buyer or lead</span>'],
        ['WhatsApp', '<a class="wa" href="https://wa.me/' . h($phone) . '" rel="noopener noreferrer" target="_blank">' . h(tg_wa_pretty($phone)) . '</a>'
            . ($wc['profile_name'] !== '' ? '<small class="sub">' . h($wc['profile_name']) . ' on WhatsApp</small>' : '')],
    ];

    // Bookings for every contact with this number.
    $bookings = $ids ? rows($pdo, 'SELECT b.id, b.status, b.session_at, b.addon, b.addon_sent_at, b.amount_paise, cp.code AS coupon_code,
            (SELECT o.status FROM outbox o WHERE o.template = \'nudge\' AND o.booking_id = b.id ORDER BY o.id DESC LIMIT 1) AS nudge_status,
            (SELECT COUNT(*) FROM coupons nc JOIN bookings nb ON nb.coupon_id = nc.id AND nb.status = \'paid\'
                WHERE nc.batch = ? AND nc.note = CONCAT(\'Checkout coupon, booking \', b.id)) AS nudge_used
        FROM bookings b LEFT JOIN coupons cp ON cp.id = b.coupon_id
        WHERE b.contact_id IN (' . implode(',', $ids) . ') AND b.product = ? ORDER BY b.id DESC LIMIT 10', [TG_NUDGE_BATCH, $config['product']]) : [];
    $list = '';
    foreach ($bookings as $b) {
        $pill = match ($b['status']) {
            'paid'   => ui_pill('Paid', 'good'),
            'failed' => ui_pill('Payment failed', 'bad'),
            default  => ui_pill('Did not pay', 'neutral'),
        };
        $notes = [];
        if ((int) $b['addon'] === 1) {
            $notes[] = 'add-on' . ($b['status'] === 'paid' ? ($b['addon_sent_at'] ? ', sent' : ', not sent yet') : '');
        }
        if ($b['coupon_code'] !== null) {
            $notes[] = 'coupon ' . $b['coupon_code'];
        }
        if ($b['nudge_status'] !== null) {
            $notes[] = 'checkout coupon ' . ((int) $b['nudge_used'] > 0 ? 'used' : match ($b['nudge_status']) {
                'sent' => 'sent', 'queued', 'sending' => 'waiting', default => 'not sent',
            });
        }
        $list .= '<li>' . $pill . ' ' . ($b['session_at'] ? h(session_label($b['session_at'])) : '<span class="muted">No session</span>')
            . ($notes ? '<small class="sub">' . h(implode(' · ', $notes)) . '</small>' : '') . '</li>';
    }
    $pairs[] = ['Bookings', $list !== '' ? '<ul class="plain">' . $list . '</ul>' : '<span class="muted">None</span>'];

    $consent = $ids ? row($pdo, 'SELECT consent_version, consent_at FROM contacts WHERE id IN (' . implode(',', $ids) . ')
        AND consent_version IS NOT NULL ORDER BY consent_at DESC LIMIT 1') : [];
    $pairs[] = ['Booking updates', $consent ? 'Agreed ' . h(when($consent['consent_at'], 'j M Y')) . ' <span class="muted">(wording ' . h($consent['consent_version']) . ')</span>'
        : '<span class="muted">Not agreed</span>'];
    $pairs[] = ['Messaged you first', tg_wa_messaged_us($pdo, $phone) ? 'Yes' : '<span class="muted">No</span>'];

    $stopAt = tg_wa_stop_at($pdo, $phone);
    $back = ['view' => 'inbox', 'c' => $id];
    if ($stopAt !== null) {
        $pairs[] = ['WhatsApp messages', ui_pill('Said STOP', 'warn') . '<small class="sub">Since ' . h(when($stopAt)) . '</small>'];
        $stop = ui_post('wa_optin', ['c' => $id, '#' => 'who'],
            '<label class="tick tick-plain"><input type="checkbox" name="asked" value="1" required> They asked to get messages again</label>',
            'Turn WhatsApp back on', 'quiet', 'stack');
    } else {
        $pairs[] = ['WhatsApp messages', ui_pill('On', 'good')];
        $stop = '<p class="notice"><a class="row-link" href="' . h(admin_url($back + ['ask' => 'optout'])) . '#confirm">Stop WhatsApp for this number</a></p>';
    }

    $tags = rows($pdo, 'SELECT tag FROM wa_tags WHERE wa_contact_id = ? ORDER BY tag', [$id]);
    $tagHtml = '';
    foreach ($tags as $t) {
        $tagHtml .= '<span class="tag-item">' . ui_pill($t['tag'], 'info')
            . ui_post('wa_tag_remove', ['c' => $id, 'tag' => $t['tag'], '#' => 'who'], '', 'Remove', 'link', 'tag-remove') . '</span>';
    }
    $pairs[] = ['Tags', $tagHtml !== '' ? '<div class="tag-list">' . $tagHtml . '</div>' : '<span class="muted">None</span>'];

    $tagForm = ui_post('wa_tag_add', ['c' => $id, '#' => 'who'],
        ui_field('Add a tag', 'tag', 'maxlength="30" required pattern="[A-Za-z0-9][A-Za-z0-9-]{0,29}" placeholder="for-example-vip" autocomplete="off"',
            'Letters, numbers and hyphens.'), 'Add tag', 'quiet', 'stack');
    $done = $wc['closed_at'] !== null
        ? ui_post('wa_thread_open', ['c' => $id, '#' => 'who'], '<p class="muted small">Marked done ' . h(when($wc['closed_at'])) . '.</p>', 'Reopen', 'quiet', 'stack')
        : ui_post('wa_thread_done', ['c' => $id, '#' => 'who'], '<p class="muted small">Done takes it off your waiting list. A new message from them reopens it.</p>', 'Mark done', 'quiet', 'stack');

    return ui_card('Who this is', ui_facts($pairs) . $stop . $tagForm . $done, ['size' => 'third', 'id' => 'who']);
}

/** Every change the inbox makes. Called from admin_handle_post with the token already checked. */
function admin_inbox_action(PDO $pdo, array $config, string $action): void
{
    $now = rp_now($config);

    if ($action === 'wa_quick_save') {
        $id = (int) ($_POST['id'] ?? 0);
        $title = rp_clean($_POST['title'] ?? '', 60);
        $body = trim(tg_wa_clean_text(is_string($_POST['body'] ?? null) ? $_POST['body'] : '', 1100));
        if ($title === '' || mb_strlen($title) > 40 || $body === '' || mb_strlen($body) > 1000) {
            admin_flash('bad', 'A quick reply needs a title up to 40 characters and text up to 1,000. Nothing was saved.');
            return;
        }
        if ($id > 0) {
            $q = $pdo->prepare('UPDATE wa_quick_replies SET title = ?, body = ?, updated_at = ? WHERE id = ?');
            $q->execute([$title, $body, tg_ist($now), $id]);
            if (!value($pdo, 'SELECT 1 FROM wa_quick_replies WHERE id = ?', [$id])) {
                admin_flash('bad', 'That quick reply no longer exists.');
                return;
            }
        } else {
            $pdo->prepare('INSERT INTO wa_quick_replies (title, body, created_at, updated_at) VALUES (?, ?, ?, ?)')
                ->execute([$title, $body, tg_ist($now), tg_ist($now)]);
            $id = (int) $pdo->lastInsertId();
        }
        tg_audit($config, 'admin', 'whatsapp_quick_saved', 'quick reply ' . $id . ', ' . mb_strlen($body) . ' chars');
        admin_flash('good', 'Quick reply saved.');
        return;
    }
    if ($action === 'wa_quick_delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $q = $pdo->prepare('DELETE FROM wa_quick_replies WHERE id = ?');
        $q->execute([$id]);
        if ($q->rowCount() > 0) {
            tg_audit($config, 'admin', 'whatsapp_quick_deleted', 'quick reply ' . $id);
            admin_flash('good', 'Quick reply deleted.');
        }
        return;
    }

    // Everything else is about one conversation.
    $wc = admin_inbox_thread($pdo, (int) ($_POST['c'] ?? 0));
    if ($wc === null) {
        admin_flash('bad', 'That conversation is not in the inbox. Nothing changed.');
        return;
    }
    $id = (int) $wc['id'];
    $phone = (string) $wc['phone'];
    $who = tg_wa_pretty($phone) . ' (c ' . $id . ')';

    switch ($action) {
        case 'wa_reply':
        case 'wa_reply_quick':
            if ($action === 'wa_reply_quick') {
                $text = (string) (value($pdo, 'SELECT body FROM wa_quick_replies WHERE id = ?', [(int) ($_POST['qr'] ?? 0)]) ?: '');
                if ($text === '') {
                    admin_flash('bad', 'That quick reply no longer exists. Nothing was sent.');
                    return;
                }
            } else {
                $text = is_string($_POST['text'] ?? null) ? $_POST['text'] : '';
            }
            $text = trim(tg_wa_clean_text($text, TG_WA_TEXT_MAX + 1));
            if ($text === '') {
                admin_flash('bad', 'Type a message first. Nothing was sent.');
                return;
            }
            if (mb_strlen($text) > TG_WA_TEXT_MAX) {
                admin_flash('bad', 'That message is longer than 4,096 characters. Nothing was sent.');
                return;
            }
            $why = tg_wa_can_message($pdo, $config, $phone, 'reply', $now);
            if ($why !== null) {
                admin_flash('bad', 'Not sent. ' . $why);
                return;
            }
            if (!admin_once_take()) {
                admin_flash('warn', 'That was already sent, so it was not sent again. Check the conversation below.');
                return;
            }
            $sent = tg_wa_send_text_result($config, $phone, $text);
            tg_wa_log_out($pdo, $phone, ['kind' => 'text', 'body' => $text, 'sent_by' => 'admin', 'wa_id' => $sent['id'],
                'status' => $sent['ok'] ? 'sent' : 'failed', 'error' => $sent['error'], 'billable' => false], $now);
            tg_audit($config, 'admin', 'whatsapp_reply', $who . ', ' . mb_strlen($text) . ' chars' . ($sent['ok'] ? '' : ': not sent, ' . $sent['error']));
            if ($sent['ok']) {
                // Blue ticks on their phone for the message we just answered.
                $last = (string) (value($pdo, "SELECT wa_id FROM wa_messages WHERE wa_contact_id = ? AND direction = 'in' AND wa_id IS NOT NULL
                    ORDER BY id DESC LIMIT 1", [$id]) ?: '');
                tg_wa_mark_read($config, $last);
                admin_flash('good', 'Sent to ' . tg_wa_pretty($phone) . '.');
            } elseif ($sent['code'] === 131047) {
                admin_flash('bad', 'The 24-hour window has closed. Send a template instead.');
            } else {
                admin_flash('bad', 'Not sent. ' . $sent['error']);
            }
            return;

        case 'wa_send_template':
            $key = (string) ($_POST['template'] ?? '');
            $allowed = admin_inbox_templates($config, $pdo);
            if (!isset($allowed[$key])) {
                admin_flash('bad', 'That template cannot be sent from the inbox. It may not be approved yet. Nothing was sent.');
                return;
            }
            if ($allowed[$key]['custom'] !== null) {
                admin_inbox_send_custom($pdo, $config, $wc, $allowed[$key]['custom'], $now);
                return;
            }
            $why = tg_wa_can_message($pdo, $config, $phone, 'template', $now);
            if ($why !== null) {
                admin_flash('bad', 'Not sent. ' . $why);
                return;
            }
            if ($allowed[$key]['wrote_first'] && !tg_wa_messaged_us($pdo, $phone)) {
                admin_flash('bad', $allowed[$key]['name'] . ' follows up on their message, and they have never written to you. Nothing was sent.');
                return;
            }
            if (!$allowed[$key]['approved']) {
                admin_flash('bad', 'Meta has not approved ' . $allowed[$key]['name'] . ' yet, so nothing was sent. Submit it on the WhatsApp tab.');
                return;
            }
            if (!tg_wa_cap_allows($pdo, $config, $phone, $now)) {
                admin_flash('bad', admin_inbox_cap_text($config));
                return;
            }
            if (!admin_once_take()) {
                admin_flash('warn', 'That template was already sent, so it was not sent again. Check the conversation below.');
                return;
            }
            $t = tg_wa_templates($config)[$key];
            $values = tg_wa_values($config, $key, ['name' => admin_inbox_name($wc) === tg_wa_pretty($phone) ? '' : admin_inbox_name($wc)]);
            $result = tg_wa_send($config, $key, $phone, $values);
            $at = tg_ist($now);
            // An outbox row already final, so it shows in the send log with its ticks.
            $pdo->prepare("INSERT INTO outbox (dedupe_key, channel, template, booking_id, to_addr, due_at, expires_at, status, tries, last_error, sent_at, provider_id, alerted_at, source)
                VALUES (?, 'whatsapp', ?, NULL, ?, ?, ?, ?, 1, ?, ?, ?, ?, 'inbox')")
                ->execute(['wa:inbox:' . $key . ':c' . $id . ':' . bin2hex(random_bytes(6)), $key, $phone, $at, $at,
                    $result['ok'] ? 'sent' : 'failed', $result['ok'] ? null : mb_substr((string) $result['error'], 0, 255),
                    $result['ok'] ? $at : null, $result['id'], $result['ok'] ? null : $at]);
            $outboxId = (int) $pdo->lastInsertId();
            tg_wa_log_out($pdo, $phone, ['kind' => 'template', 'body' => tg_wa_render($config, $key, $values)['body'], 'template' => $t['name'],
                'category' => $t['category'] ?? 'UTILITY', 'sent_by' => 'admin', 'wa_id' => $result['id'], 'outbox_id' => $outboxId,
                'status' => $result['ok'] ? 'sent' : 'failed', 'error' => $result['error']], $now);
            tg_audit($config, 'admin', 'whatsapp_template_one', $t['name'] . ' to ' . $who . ': ' . ($result['ok'] ? 'sent' : $result['error']));
            admin_flash($result['ok'] ? 'good' : 'bad', $result['ok'] ? 'Template sent to ' . tg_wa_pretty($phone) . '. When they reply, the window opens again.'
                : 'Not sent. ' . $result['error']);
            return;

        case 'wa_thread_done':
        case 'wa_thread_open':
            $done = $action === 'wa_thread_done';
            $pdo->prepare('UPDATE wa_contacts SET closed_at = ' . ($done ? '?' : 'NULL') . ' WHERE id = ?')->execute($done ? [tg_ist($now), $id] : [$id]);
            tg_audit($config, 'admin', $done ? 'whatsapp_done' : 'whatsapp_reopened', $who);
            admin_flash('good', $done ? 'Marked done.' : 'Reopened.');
            return;

        case 'wa_optout':
        case 'wa_optin':
            $stopped = tg_wa_stop_at($pdo, $phone) !== null;
            if ($action === 'wa_optout') {
                if (($_POST['confirm'] ?? '') !== '1') {
                    admin_flash('bad', 'Nothing changed. Confirm first.');
                    return;
                }
                if ($stopped) {
                    admin_flash('good', 'WhatsApp is already stopped for this number.');
                    return;
                }
            } else {
                if (($_POST['asked'] ?? '') !== '1') {
                    admin_flash('bad', 'Tick the box to confirm they asked to get messages again. Nothing changed.');
                    return;
                }
                if (!$stopped) {
                    admin_flash('good', 'WhatsApp is already on for this number.');
                    return;
                }
            }
            $kind = $action === 'wa_optout' ? 'stop' : 'start';
            $pdo->prepare('INSERT INTO wa_inbound (wa_id, phone, kind, received_at) VALUES (?, ?, ?, ?)')
                ->execute(['admin:' . $id . ':' . $now . ':' . bin2hex(random_bytes(3)), $phone, $kind, tg_ist($now)]);
            $ids = tg_wa_apply_stop($pdo, $config, $phone, $kind, $now);
            tg_audit($config, 'admin', $kind === 'stop' ? 'whatsapp_stop_by_admin' : 'whatsapp_start_by_admin',
                $who . ($ids ? ', contact ' . implode(', ', $ids) : ''));
            admin_flash('good', $kind === 'stop' ? 'WhatsApp stopped for ' . tg_wa_pretty($phone) . '. Queued WhatsApp messages to them are cancelled. Emails still go.'
                : 'WhatsApp is back on for ' . tg_wa_pretty($phone) . '. Reminders still ahead of them will go.');
            return;

        case 'wa_tag_add':
        case 'wa_tag_remove':
            $tag = strtolower(trim(is_string($_POST['tag'] ?? null) ? $_POST['tag'] : ''));
            if (!preg_match('/^[a-z0-9][a-z0-9-]{0,29}$/', $tag)) {
                admin_flash('bad', 'A tag is up to 30 letters, numbers and hyphens, starting with a letter or number. Nothing changed.');
                return;
            }
            if ($action === 'wa_tag_add') {
                $pdo->prepare("INSERT IGNORE INTO wa_tags (wa_contact_id, tag, added_at, added_by) VALUES (?, ?, ?, 'admin')")->execute([$id, $tag, tg_ist($now)]);
            } else {
                $pdo->prepare('DELETE FROM wa_tags WHERE wa_contact_id = ? AND tag = ?')->execute([$id, $tag]);
            }
            tg_audit($config, 'admin', $action === 'wa_tag_add' ? 'whatsapp_tag_added' : 'whatsapp_tag_removed', $who . ': ' . $tag);
            admin_flash('good', $action === 'wa_tag_add' ? 'Tag ' . $tag . ' added.' : 'Tag ' . $tag . ' removed.');
            return;
    }
}

/**
 * Sends one approved template written on the Templates page to this conversation, filled
 * from their details and any text typed in the form (fixed[b2], fixed[u0]). Goes straight
 * to Meta, like the follow-up, and writes an outbox row already final (template c:<id>)
 * so it shows in the send log (source 'inbox'). Nothing is queued, so the worker never
 * sends it again. It counts toward the daily limit like every template.
 */
function admin_inbox_send_custom(PDO $pdo, array $config, array $wc, array $t, int $now): void
{
    $id = (int) $wc['id'];
    $phone = (string) $wc['phone'];
    $who = tg_wa_pretty($phone) . ' (c ' . $id . ')';
    $why = tg_wa_can_message($pdo, $config, $phone, 'template', $now);
    if ($why !== null) {
        admin_flash('bad', 'Not sent. ' . $why);
        return;
    }
    // The page may be old: Meta's answer since then counts (webhook or sync).
    $fresh = tg_wat_get($pdo, (int) $t['id']);
    if ($fresh === null || $fresh['status'] !== 'APPROVED' || $fresh['deleted_at'] !== null) {
        admin_flash('bad', $t['name'] . ' is not approved any more, so nothing was sent.');
        return;
    }
    $typed = [];
    foreach (is_array($_POST['fixed'] ?? null) ? $_POST['fixed'] : [] as $k => $v) {
        if (is_string($k) && preg_match('/^[bu]\d{1,2}$/', $k) && is_string($v)) {
            $typed[$k] = mb_substr($v, 0, 1024);
        }
    }
    $values = tg_wat_values($config, $fresh, tg_wat_person($pdo, $config, $phone), $typed, $now);
    if ($values['missing']) {
        admin_flash('bad', 'Not sent. Fill in ' . implode(', ', $values['missing']) . '.');
        return;
    }
    if (!tg_wa_cap_allows($pdo, $config, $phone, $now)) {
        admin_flash('bad', admin_inbox_cap_text($config));
        return;
    }
    if (!admin_once_take()) {
        admin_flash('warn', 'That template was already sent, so it was not sent again. Check the conversation below.');
        return;
    }
    $result = tg_wat_send($config, $fresh, $phone, $values);
    $at = tg_ist($now);
    $pdo->prepare("INSERT INTO outbox (dedupe_key, channel, template, booking_id, to_addr, due_at, expires_at, status, tries, last_error, sent_at, provider_id, alerted_at, source)
        VALUES (?, 'whatsapp', ?, NULL, ?, ?, ?, ?, 1, ?, ?, ?, ?, 'inbox')")
        ->execute(['wa:inbox:c' . $fresh['id'] . ':c' . $id . ':' . bin2hex(random_bytes(6)), 'c:' . $fresh['id'], $phone, $at, $at,
            $result['ok'] ? 'sent' : 'failed', $result['ok'] ? null : mb_substr((string) $result['error'], 0, 255),
            $result['ok'] ? $at : null, $result['id'], $result['ok'] ? null : $at]);
    $outboxId = (int) $pdo->lastInsertId();
    tg_wa_log_out($pdo, $phone, ['kind' => 'template', 'body' => tg_wat_text(tg_wat_render($fresh, $values)), 'template' => $fresh['name'],
        'category' => $fresh['meta_category'] ?: $fresh['category'], 'sent_by' => 'admin', 'wa_id' => $result['id'], 'outbox_id' => $outboxId,
        'status' => $result['ok'] ? 'sent' : 'failed', 'error' => $result['error']], $now);
    tg_audit($config, 'admin', 'whatsapp_template_one', $fresh['name'] . ' to ' . $who . ': ' . ($result['ok'] ? 'sent' : $result['error']));
    admin_flash($result['ok'] ? 'good' : 'bad', $result['ok'] ? 'Template sent to ' . tg_wa_pretty($phone) . '. When they reply, the window opens again.'
        : 'Not sent. ' . $result['error']);
}
