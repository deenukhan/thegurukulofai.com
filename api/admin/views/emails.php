<?php
/** Emails: every template exactly as it is sent, and the log of every reminder. */
require_once dirname(__DIR__, 2) . '/razorpay/outbox.php';

return static function (array $d): array {
    $pdo = $d['pdo'];
    $config = $d['config'];
    $tab = ($_GET['tab'] ?? '') === 'log' ? 'log' : 'templates';
    $tabs = ui_chips(['templates' => 'Templates', 'log' => 'Send log'], $tab, 'tab', ['view' => 'emails']);

    // ---------- Send log ----------
    if ($tab === 'log') {
        $status = in_array($_GET['status'] ?? '', ['sent', 'queued', 'failed', 'cancelled'], true) ? (string) $_GET['status'] : '';
        $week = tg_ist($d['now'] - 7 * 86400);
        $k = row($pdo, "SELECT COALESCE(SUM(status = 'sent' AND sent_at >= ?), 0) AS sent, COALESCE(SUM(status IN ('queued', 'sending')), 0) AS waiting,
                COALESCE(SUM(status IN ('failed', 'expired') AND due_at >= ?), 0) AS failed, COALESCE(SUM(status = 'cancelled'), 0) AS stopped
            FROM outbox", [$week, $week]);
        $body = $tabs . ui_kpis([
            ['label' => 'Sent this week', 'value' => h(num((int) $k['sent']))],
            ['label' => 'Waiting to send', 'value' => h(num((int) $k['waiting'])), 'note' => 'Planned for upcoming sessions'],
            ['label' => 'Failed this week', 'value' => h(num((int) $k['failed'])), 'tone' => (int) $k['failed'] > 0 ? 'bad' : null,
             'note' => (int) $k['failed'] > 0 ? 'You got an alert for each' : 'None'],
            ['label' => 'Stopped by buyers', 'value' => h(num((int) $k['stopped'])), 'note' => 'Clicked "no more reminders"'],
        ]);

        $where = '1 = 1';
        if ($status === 'failed') {
            $where = "o.status IN ('failed', 'expired')";
        } elseif ($status === 'queued') {
            $where = "o.status IN ('queued', 'sending')";
        } elseif ($status !== '') {
            $where = 'o.status = ' . $pdo->quote($status);
        }
        $list = rows($pdo, "SELECT o.template, o.status, o.to_addr, o.due_at, o.sent_at, o.tries, o.last_error, b.contact_id, b.session_at, c.name, c.email
            FROM outbox o LEFT JOIN bookings b ON b.id = o.booking_id LEFT JOIN contacts c ON c.id = b.contact_id
            WHERE {$where} ORDER BY o.status IN ('queued', 'sending'), CASE WHEN o.status IN ('queued', 'sending') THEN o.due_at END ASC, o.due_at DESC, o.id DESC LIMIT 300");
        $table = [];
        foreach ($list as $o) {
            $label = $o['template'] === 'digest' ? 'Daily summary' : (TG_REMINDERS[$o['template']]['label'] ?? $o['template']);
            $to = $o['contact_id'] ? m_person($o) . '<small class="sub">' . h($o['to_addr']) . '</small>' : h($o['to_addr']) . '<small class="sub">You</small>';
            $table[] = [
                h(when($o['due_at'])),
                h($label),
                $to,
                $o['session_at'] ? h(session_label($o['session_at'])) : '<span class="muted">—</span>',
                m_outbox_pill($o['status']) . ((int) $o['tries'] > 1 ? '<small class="sub">' . (int) $o['tries'] . ' tries</small>' : ''),
                $o['last_error'] ? '<span class="muted">' . h($o['last_error']) . '</span>' : ($o['sent_at'] ? '<span class="muted">Sent ' . h(when($o['sent_at'], 'g:i A')) . '</span>' : ''),
            ];
        }
        $body .= ui_grid(ui_card('Send log', ui_chips(['' => 'All', 'sent' => 'Sent', 'queued' => 'Waiting', 'failed' => 'Failed or missed', 'cancelled' => 'Stopped'],
            $status, 'status', ['view' => 'emails', 'tab' => 'log'])
            . ui_table([['Due', 'muted nowrap'], ['Email', ''], ['To', ''], ['Session', 'nowrap'], ['Status', ''], ['Note', '']], $table,
                ['empty' => 'No emails here yet.', 'note' => count($list) === 300 ? 'Showing the newest 300.' : '']),
            ['wide' => true, 'flush' => true, 'sub' => 'Reminders and your daily summary. Payment confirmations are on the Sales page.']));
        return ['body' => $body];
    }

    // ---------- Templates ----------
    $next = rp_next_session($d['now']);
    $sample = [
        'name'       => 'Priya Sharma',
        'email'      => 'priya@example.com',
        'phone'      => '9876543210',
        'addon'      => true,
        'coupon'     => false,
        'session'    => rp_session_when($next),
        'amount'     => (int) $config['price_paise'] + (int) $config['addon_paise'],
        'payment_id' => 'pay_SampleOnly123',
        'join_url'   => tg_join_url($config, 'sample0000000000000000ab'),
        'ics'        => 'yes',
    ];
    $booking = ['name' => $sample['name'], 'session_at' => $next->format('Y-m-d H:i:s'), 'join_token' => 'sample0000000000000000ab'];
    $buyer = $sample['name'] . ' <' . $sample['email'] . '>';
    $you = (string) $config['mail_notify'];
    $from = 'Deenu Khan <' . $config['mail_from'] . '>';

    $catalog = [
        'confirm'  => ['Payment confirmation', 'Buyer', 'Right after payment', static function () use ($config, $sample) {
            return rp_buyer_email($config, $sample) + ['attached' => 'masterclass.ics (calendar invite)'];
        }],
        'remind24' => ['Day-before reminder', 'Buyer', '24 hours before the session', static fn() => tg_reminder_email($config, 'remind24', $booking)],
        'remind3'  => ['3-hour reminder', 'Buyer', '3 hours before', static fn() => tg_reminder_email($config, 'remind3', $booking)],
        'remind15' => ['15-minute reminder', 'Buyer', '15 minutes before', static fn() => tg_reminder_email($config, 'remind15', $booking)],
        'live'     => ['"We\'re live" email', 'Buyer', 'When the session starts', static fn() => tg_reminder_email($config, 'live', $booking)],
        'sale'     => ['New seat note', 'You', 'After each sale', static fn() => rp_sale_note($sample, 'SMTP', 1)],
        'delay'    => ['Confirmation delayed', 'You', 'When a buyer email fails', static fn() => rp_delay_note($sample, 'SMTP: mail server did not answer')],
        'digest'   => ['Daily summary', 'You', 'Every day at 9 AM', static fn() => tg_digest_email($config, tg_digest_data($pdo, $config, $d['now']))],
        'alert'    => ['Reminder failed', 'You', 'When a reminder finally fails', static fn() => tg_alert_email($config, [[
            'label' => '15-minute reminder', 'name' => $sample['name'], 'email' => $sample['email'],
            'session_label' => $next->format('D j M, g A'), 'error' => 'Mail server did not answer',
        ]])],
    ];
    $pick = array_key_exists($_GET['t'] ?? '', $catalog) ? (string) $_GET['t'] : 'confirm';

    $menu = '<nav class="tpl-list" aria-label="Email templates">';
    $group = null;
    foreach ($catalog as $key => [$label, $who, $when]) {
        if ($who !== $group) {
            $group = $who;
            $menu .= '<p class="nav-group">' . h($who === 'Buyer' ? 'To buyers' : 'To you') . '</p>';
        }
        $on = $key === $pick;
        $menu .= '<a class="tpl' . ($on ? ' on' : '') . '"' . ($on ? ' aria-current="true"' : '') . ' href="'
            . h(admin_url(['view' => 'emails', 't' => $key])) . '"><strong>' . h($label) . '</strong><small>' . h($when) . '</small></a>';
    }
    $menu .= '</nav>';

    [$label, $who, $when, $build] = $catalog[$pick];
    $mail = $build();
    $mail['from'] = $from;
    $mail['to'] = $who === 'Buyer' ? $buyer : $you;
    $note = $pick === 'digest'
        ? 'Built from today\'s real numbers.'
        : ($who === 'Buyer' ? 'Shown with a sample buyer. Each real email uses that buyer\'s name, session and payment.' : 'Shown with a sample sale.');
    if ($who === 'Buyer' && $pick !== 'confirm' && tg_zoom_url($config) === null) {
        $note .= ' The join link shows "room not open yet" until the Zoom link is set.';
    }
    $preview = '<p class="tpl-note">' . icon('info') . h($when . '. ' . $note) . '</p>' . ui_mail($mail);

    $body = $tabs . '<div class="split">' . ui_card('Templates', $menu, ['flush' => true]) . ui_card($label, $preview, ['flush' => false]) . '</div>';
    return ['body' => $body];
};
