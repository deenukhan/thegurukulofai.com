<?php
/** Sessions: every Sunday class, and inside one, its buyers, reminders and join clicks. */
return static function (array $d): array {
    $pdo = $d['pdo'];
    $next = m_next_session($d);
    $at = (string) ($_GET['at'] ?? '');
    $at = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $at) ? $at : '';

    // ---------- One session ----------
    if ($at !== '') {
        $start = (new DateTimeImmutable($at, ist()))->getTimestamp();
        $past = $start <= $d['now'];
        $buyers = rows($pdo, "SELECT b.id, b.contact_id, c.name, c.email, c.phone, b.addon, b.amount_paise, b.paid_at, b.join_clicks,
                b.first_join_at, b.reminders_off_at, b.payment_id, b.addon_sent_at
            FROM bookings b JOIN contacts c ON c.id = b.contact_id
            WHERE b.product = ? AND b.status = 'paid' AND b.session_at = ? ORDER BY c.name", [$d['product'], $at]);
        $sent = [];
        foreach (rows($pdo, "SELECT o.booking_id, o.template, o.status FROM outbox o JOIN bookings b ON b.id = o.booking_id
            WHERE b.session_at = ? AND b.product = ? AND o.channel = 'email'", [$at, $d['product']]) as $o) {
            $sent[$o['booking_id']][$o['template']] = $o['status'];
        }

        if (($_GET['export'] ?? '') === 'csv') {
            tg_audit($d['config'], 'admin', 'export_csv', 'session ' . $at . ': ' . count($buyers) . ' rows');
            $lines = [];
            foreach ($buyers as $b) {
                $lines[] = [$b['name'], $b['email'], $b['phone'] !== '' ? '+91' . $b['phone'] : '', $b['addon'] ? 'yes' : 'no',
                    number_format($b['amount_paise'] / 100, 2, '.', ''), $b['paid_at'], (int) $b['join_clicks'], $b['first_join_at']];
            }
            send_csv('masterclass-session-' . substr($at, 0, 10) . '.csv',
                ['name', 'email', 'whatsapp', 'addon', 'amount_rs', 'paid_at', 'join_clicks', 'first_join_at'], $lines);
        }

        $clicked = count(array_filter($buyers, static fn($b) => (int) $b['join_clicks'] > 0));
        $addons = count(array_filter($buyers, static fn($b) => (int) $b['addon'] === 1));
        $toSend = count(array_filter($buyers, static fn($b) => (int) $b['addon'] === 1 && $b['addon_sent_at'] === null));
        $revenue = array_sum(array_map(static fn($b) => (int) $b['amount_paise'], $buyers));
        $body = ui_kpis([
            ['label' => 'Seats', 'value' => h(num(count($buyers))), 'note' => $past ? 'Held ' . ago($start, $d['now']) : 'Starts ' . until($start, $d['now'])],
            ['label' => 'With add-on', 'value' => h(num($addons)), 'tone' => $past && $toSend > 0 ? 'warn' : null,
             'note' => $addons === 0 ? 'Nobody to send to' : ($toSend === 0 ? 'All sent' : $toSend . ' still to send recording + Vault')],
            ['label' => 'Revenue', 'value' => h(money($revenue))],
            ['label' => 'Opened their join link', 'value' => h(num($clicked)), 'note' => count($buyers) ? pct($clicked, count($buyers)) . ' of buyers' : ''],
        ]);

        // Reminder counts for this session, by email.
        $labels = array_map(static fn($t) => $t['label'], TG_REMINDERS);
        $byTemplate = [];
        foreach ($sent as $templates) {
            foreach ($templates as $t => $status) {
                $byTemplate[$t][$status] = ($byTemplate[$t][$status] ?? 0) + 1;
            }
        }
        $reminders = [];
        foreach ($labels as $t => $label) {
            $c = $byTemplate[$t] ?? [];
            $reminders[] = [h($label), h(num($c['sent'] ?? 0)), h(num($c['queued'] ?? 0)),
                ($c['failed'] ?? 0) + ($c['expired'] ?? 0) > 0 ? ui_pill((string) (($c['failed'] ?? 0) + ($c['expired'] ?? 0)), 'bad') : '<span class="muted">0</span>'];
        }
        $reminderCard = ui_card('Reminder emails', ui_table([['Email', ''], ['Sent', 'num'], ['Waiting', 'num'], ['Failed', 'num']], $reminders),
            ['wide' => true, 'flush' => true, 'sub' => 'For this session']);

        $list = [];
        $mark = static function (?string $status): string {
            return match ($status) {
                'sent'              => '<span class="mark mark-good" title="Sent">' . icon('check') . '</span>',
                'failed', 'expired' => '<span class="mark mark-bad" title="Failed">' . icon('x') . '</span>',
                'cancelled'         => '<span class="mark" title="Stopped by the buyer">off</span>',
                'queued', 'sending' => '<span class="mark" title="Waiting">…</span>',
                default             => '<span class="mark" title="Not planned">—</span>',
            };
        };
        foreach ($buyers as $b) {
            $r = $sent[$b['id']] ?? [];
            $list[] = [
                '<span class="anchor" id="b' . (int) $b['id'] . '"></span>' . m_person($b) . '<small class="sub">' . h($b['email']) . '</small>',
                m_whatsapp((string) $b['phone']),
                (int) $b['addon'] === 1
                    ? ($b['addon_sent_at'] !== null
                        ? ui_post('addon_sent', ['booking_id' => $b['id'], 'sent' => '0', '#' => 'b' . (int) $b['id']], ui_pill('Add-on sent', 'good') . '<small class="sub">' . h(when($b['addon_sent_at'], 'j M')) . '</small>', 'Undo', 'link', 'row-action')
                        : ui_post('addon_sent', ['booking_id' => $b['id'], 'sent' => '1', '#' => 'b' . (int) $b['id']], ui_pill('Add-on', 'info'), 'Mark sent', 'link', 'row-action'))
                    : '<span class="muted">Ticket</span>',
                '<span class="marks">' . $mark($r['remind24'] ?? null) . $mark($r['remind3'] ?? null) . $mark($r['remind15'] ?? null) . $mark($r['live'] ?? null) . '</span>'
                    . ($b['reminders_off_at'] ? '<small class="sub">Stopped reminders</small>' : ''),
                (int) $b['join_clicks'] > 0 ? ui_pill('Opened', 'good') . '<small class="sub">' . h(when($b['first_join_at'], 'g:i A')) . '</small>' : '<span class="muted">Not yet</span>',
            ];
        }
        $buyerCard = ui_card('Buyers', ui_table(
            [['Buyer', ''], ['WhatsApp', 'nowrap'], ['Bought', ''], ['Reminders: day · 3 h · 15 min · live', 'nowrap'], ['Join link', '']],
            $list, ['empty' => 'No seats sold for this session yet.']
        ), ['wide' => true, 'id' => 'buyers', 'flush' => true, 'sub' => count($buyers) . ' paid',
            'action' => $past && $toSend > 0 ? ui_post('addon_sent_session', ['session_at' => $at, '#' => 'buyers'], '', 'Mark all ' . $toSend . ' add-ons sent', 'quiet') : '']);

        $title = (new DateTimeImmutable($at, ist()))->format('l, j F Y');
        return [
            'title'   => $title,
            'intro'   => '3 PM to 6 PM IST, live on Zoom.' . ($at === $next['at'] ? ' This is the next session.' : ''),
            'back'    => ['All sessions', admin_url(['view' => 'sessions'])],
            'body'    => $body . ui_grid($buyerCard, $reminderCard),
            'actions' => ui_button('Download buyers', admin_url(['view' => 'sessions', 'at' => $at, 'export' => 'csv']), 'quiet', 'download'),
        ];
    }

    // ---------- All sessions ----------
    $sessions = rows($pdo, "SELECT b.session_at, COUNT(*) AS seats, SUM(b.addon) AS addons, SUM(b.amount_paise) AS gross,
            SUM(b.join_clicks > 0) AS clicked
        FROM bookings b WHERE b.product = ? AND b.status = 'paid' AND b.session_at IS NOT NULL
        GROUP BY b.session_at ORDER BY b.session_at DESC", [$d['product']]);
    $mail = [];
    foreach (rows($pdo, "SELECT b.session_at, SUM(o.status = 'sent') AS sent, SUM(o.status IN ('failed', 'expired')) AS failed
        FROM outbox o JOIN bookings b ON b.id = o.booking_id WHERE b.product = ? AND o.channel = 'email' GROUP BY b.session_at", [$d['product']]) as $m) {
        $mail[$m['session_at']] = $m;
    }
    $known = array_column($sessions, 'session_at');
    if (!in_array($next['at'], $known, true)) {
        array_unshift($sessions, ['session_at' => $next['at'], 'seats' => 0, 'addons' => 0, 'gross' => 0, 'clicked' => 0]);
    }
    usort($sessions, static fn($a, $b) => strcmp($b['session_at'], $a['session_at']));

    $list = [];
    foreach ($sessions as $s) {
        $start = (new DateTimeImmutable($s['session_at'], ist()))->getTimestamp();
        $past = $start <= $d['now'];
        $state = $s['session_at'] === $next['at'] ? ui_pill('Next', 'info') : ($past ? ui_pill('Done', 'neutral') : ui_pill('Upcoming', 'info'));
        $m = $mail[$s['session_at']] ?? ['sent' => 0, 'failed' => 0];
        $list[] = [
            '<a class="person" href="' . h(admin_url(['view' => 'sessions', 'at' => $s['session_at']])) . '">'
                . h((new DateTimeImmutable($s['session_at'], ist()))->format('D j M Y')) . '</a>',
            $state,
            h(num((int) $s['seats'])),
            h(num((int) $s['addons'])),
            h(money((int) $s['gross'])),
            h(num((int) $m['sent'])) . ((int) $m['failed'] > 0 ? ' ' . ui_pill($m['failed'] . ' failed', 'bad') : ''),
            $past && (int) $s['seats'] > 0 ? h(num((int) $s['clicked'])) . ' <span class="muted">(' . pct((int) $s['clicked'], (int) $s['seats']) . ')</span>' : '<span class="muted">—</span>',
        ];
    }

    $seats = (int) array_sum(array_column($sessions, 'seats'));
    $held = array_filter($sessions, static fn($s) => (new DateTimeImmutable($s['session_at'], ist()))->getTimestamp() <= $d['now'] && (int) $s['seats'] > 0);
    $heldSeats = (int) array_sum(array_column($held, 'seats'));
    $heldClicked = (int) array_sum(array_column($held, 'clicked'));
    $body = ui_kpis([
        ['label' => 'Next session', 'value' => h((new DateTimeImmutable($next['at'], ist()))->format('D j M')), 'note' => 'Starts ' . until($next['unix'], $d['now'])],
        ['label' => 'Booked for it', 'value' => h(num($next['seats'])), 'note' => $next['addons'] . ' with the add-on'],
        ['label' => 'Sessions held', 'value' => h(num(count($held))), 'note' => num($seats) . ' seats in all'],
        ['label' => 'Opened their join link', 'value' => h(pct($heldClicked, $heldSeats)), 'note' => 'Across past sessions'],
    ]);
    $body .= ui_grid(ui_card('All sessions', ui_table(
        [['Session', 'nowrap'], ['', ''], ['Seats', 'num'], ['Add-ons', 'num'], ['Revenue', 'num'], ['Reminders sent', 'num'], ['Opened join link', 'num']],
        $list
    ), ['wide' => true, 'flush' => true, 'sub' => 'Open one to see its buyers, add-ons and reminders.']));
    return ['body' => $body];
};
