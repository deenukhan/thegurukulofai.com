<?php
/** People: everyone who filled the form, and each person's full history. */
return static function (array $d): array {
    $pdo = $d['pdo'];
    $id = ctype_digit((string) ($_GET['id'] ?? '')) ? (int) $_GET['id'] : 0;

    // ---------- One person ----------
    if ($id > 0) {
        $p = row($pdo, 'SELECT * FROM contacts WHERE id = ?', [$id]);
        if (!$p) {
            return ['title' => 'Not found', 'back' => ['People', admin_url(['view' => 'people'])],
                'body' => ui_empty('This person is not in the store.', 'Unpaid leads are removed after the keep limit.')];
        }
        $bookings = rows($pdo, 'SELECT * FROM bookings WHERE contact_id = ? ORDER BY created_at DESC', [$id]);
        $paid = array_filter($bookings, static fn($b) => $b['status'] === 'paid');
        $spent = array_sum(array_map(static fn($b) => (int) $b['amount_paise'] - (int) $b['refunded_paise'], $paid));

        $facts = ui_facts([
            ['Email', h($p['email'])],
            ['WhatsApp', m_whatsapp((string) $p['phone'])],
            ['First seen', h(when($p['created_at'], 'j M Y, g:i A'))],
            ['Booking updates', $p['consent_at'] ? 'Agreed ' . h(when($p['consent_at'], 'j M Y')) . ' <span class="muted">(wording ' . h($p['consent_version']) . ')</span>' : '<span class="muted">Before consent wording existed</span>'],
            ['Offers', $p['email_unsub_at'] ? ui_pill('Unsubscribed', 'warn') : ($p['marketing_optin'] ? ui_pill('Said yes', 'good') : '<span class="muted">No</span>')],
            ['WhatsApp messages', $p['wa_optout_at'] ? ui_pill('Opted out', 'warn') : '<span class="muted">Allowed</span>'],
            ['1:1 setup', $p['bought_1on1'] ? ui_pill('Bought', 'good') : '<span class="muted">No</span>'],
        ]);
        $about = ui_card('Details', $facts, ['size' => 'third']);

        // Resend asks first: the link opens a short confirm box above the table.
        $resendId = (int) ($_GET['resend'] ?? 0);
        $confirm = '';
        foreach ($bookings as $b) {
            if ((int) $b['id'] === $resendId && $b['status'] === 'paid') {
                $confirm = ui_card('Send the confirmation email again?', '<p>To <strong>' . h($p['email']) . '</strong>, the same email as before, with '
                    . 'the session, personal join link, community link and calendar invite.</p>'
                    . '<div class="confirm-actions">' . ui_post('resend_confirm', ['booking_id' => $b['id']], '', 'Send it again', 'primary')
                    . ui_button('Cancel', admin_url(['view' => 'people', 'id' => $id]), 'quiet') . '</div>', ['wide' => true]);
            }
        }
        $list = [];
        foreach ($bookings as $b) {
            $conf = $b['status'] === 'paid' ? admin_confirmation($d['config'], $b['payment_id']) : null;
            $list[] = [
                h(when($b['created_at'])),
                m_status_pill($b, $d['now']) . ($b['fail_reason'] ? '<small class="sub">' . h($b['fail_reason']) . '</small>' : ''),
                $b['session_at'] ? '<a href="' . h(admin_url(['view' => 'sessions', 'at' => $b['session_at']])) . '">' . h(session_label($b['session_at'])) . '</a>' : '—',
                h(money((int) $b['amount_paise'])) . ($b['addon'] ? '<small class="sub">with add-on' . ($b['status'] === 'paid' ? ($b['addon_sent_at'] ? ', sent' : ', not sent yet') : '') . '</small>' : '') . ($b['coupon'] ? '<small class="sub">coupon</small>' : ''),
                $conf ? admin_confirmation_pill($conf) . '<a class="row-link" href="' . h(admin_url(['view' => 'people', 'id' => $id, 'resend' => $b['id']])) . '">Resend</a>' : '<span class="muted">—</span>',
                $b['status'] === 'paid' ? ((int) $b['join_clicks'] > 0 ? ui_pill('Opened ' . (int) $b['join_clicks'] . '×', 'good') : '<span class="muted">Not yet</span>') : '<span class="muted">—</span>',
                '<span class="clip">' . h(m_source($b)) . '</span>',
            ];
        }
        $history = ui_card('Bookings', ui_table(
            [['Started', 'muted nowrap'], ['Status', ''], ['Session', 'nowrap'], ['Amount', 'num'], ['Confirmation', ''], ['Join link', ''], ['Came from', '']],
            $list
        ), ['wide' => true, 'flush' => true]);

        $mails = rows($pdo, 'SELECT o.template, o.status, o.due_at, o.sent_at, o.tries, o.last_error FROM outbox o
            JOIN bookings b ON b.id = o.booking_id WHERE b.contact_id = ? ORDER BY o.due_at DESC LIMIT 50', [$id]);
        $mailRows = [];
        foreach ($mails as $m) {
            $mailRows[] = [h(TG_REMINDERS[$m['template']]['label'] ?? $m['template']), m_outbox_pill($m['status']),
                h(when($m['sent_at'] ?? $m['due_at'])), $m['last_error'] ? '<span class="muted">' . h($m['last_error']) . '</span>' : ''];
        }
        $mailCard = ui_card('Reminder emails', ui_table([['Email', ''], ['Status', ''], ['When', 'muted nowrap'], ['Note', '']], $mailRows,
            ['empty' => 'No reminder emails for this person.']), ['size' => 'two-thirds', 'flush' => true,
            'sub' => 'The payment confirmation is shown on each booking above.']);

        return [
            'title' => $p['name'] !== '' ? $p['name'] : $p['email'],
            'intro' => count($paid) > 0 ? 'Buyer · ' . money($spent) . ' paid in all' : 'Lead · has not paid yet',
            'back'  => ['People', admin_url(['view' => 'people'])],
            'body'  => ui_grid(...array_filter([$confirm, $history, $about, $mailCard])),
        ];
    }

    // ---------- Everyone ----------
    $show = in_array($_GET['show'] ?? '', ['buyers', 'leads', 'offers', 'unsub'], true) ? (string) $_GET['show'] : '';
    $q = rp_clean($_GET['q'] ?? '', 80);
    $where = ['1 = 1'];
    $args = [$d['product']];
    $having = '';
    if ($show === 'buyers') {
        $having = 'HAVING paid > 0';
    } elseif ($show === 'leads') {
        $having = 'HAVING paid = 0';
    } elseif ($show === 'offers') {
        $where[] = 'c.marketing_optin = 1 AND c.email_unsub_at IS NULL';
    } elseif ($show === 'unsub') {
        $where[] = '(c.email_unsub_at IS NOT NULL OR c.wa_optout_at IS NOT NULL)';
    }
    if ($q !== '') {
        $where[] = '(c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)';
        $like = '%' . addcslashes($q, '%_\\') . '%';
        array_push($args, $like, $like, $like);
    }
    $sql = 'SELECT c.id AS contact_id, c.name, c.email, c.phone, c.created_at, c.marketing_optin, c.email_unsub_at,
            COUNT(b.id) AS bookings, COALESCE(SUM(b.status = \'paid\'), 0) AS paid,
            COALESCE(SUM(CASE WHEN b.status = \'paid\' THEN b.amount_paise - b.refunded_paise ELSE 0 END), 0) AS spent,
            MAX(b.created_at) AS last_at
        FROM contacts c LEFT JOIN bookings b ON b.contact_id = c.id AND b.product = ?
        WHERE ' . implode(' AND ', $where) . ' GROUP BY c.id ' . $having . ' ORDER BY last_at DESC, c.id DESC';

    if (($_GET['export'] ?? '') === 'csv') {
        $all = rows($pdo, $sql, $args);
        tg_audit($d['config'], 'admin', 'export_csv', 'people: ' . count($all) . ' rows' . ($show !== '' ? ', ' . $show : ''));
        $lines = [];
        foreach ($all as $p) {
            $lines[] = [$p['name'], $p['email'], $p['phone'] !== '' ? '+91' . $p['phone'] : '', (int) $p['paid'] > 0 ? 'buyer' : 'lead',
                (int) $p['bookings'], number_format($p['spent'] / 100, 2, '.', ''), $p['marketing_optin'] && !$p['email_unsub_at'] ? 'yes' : 'no',
                $p['created_at']];
        }
        send_csv('masterclass-people-' . date('Y-m-d') . '.csv', ['name', 'email', 'whatsapp', 'type', 'bookings', 'paid_rs', 'offers_optin', 'first_seen'], $lines);
    }

    $totals = row($pdo, "SELECT COUNT(*) AS people,
            COALESCE(SUM(EXISTS (SELECT 1 FROM bookings b WHERE b.contact_id = c.id AND b.product = ? AND b.status = 'paid')), 0) AS buyers,
            COALESCE(SUM(c.marketing_optin = 1 AND c.email_unsub_at IS NULL), 0) AS offers,
            COALESCE(SUM((SELECT COUNT(*) FROM bookings b WHERE b.contact_id = c.id AND b.product = ? AND b.status = 'paid') > 1), 0) AS repeat_buyers
        FROM contacts c", [$d['product'], $d['product']]);
    $body = ui_kpis([
        ['label' => 'People', 'value' => h(num((int) $totals['people'])), 'note' => 'Filled the booking form'],
        ['label' => 'Buyers', 'value' => h(num((int) $totals['buyers'])), 'note' => (int) $totals['repeat_buyers'] . ' bought more than once'],
        ['label' => 'Leads', 'value' => h(num((int) $totals['people'] - (int) $totals['buyers'])), 'note' => 'Have not paid yet'],
        ['label' => 'Said yes to offers', 'value' => h(num((int) $totals['offers'])), 'note' => pct((int) $totals['offers'], (int) $totals['people']) . ' of people'],
    ]);

    $list = rows($pdo, $sql . ' LIMIT 500', $args);
    $table = [];
    foreach ($list as $p) {
        $table[] = [
            m_person($p) . '<small class="sub">' . h($p['email']) . '</small>',
            m_whatsapp((string) $p['phone']),
            (int) $p['paid'] > 0 ? ui_pill((int) $p['paid'] > 1 ? 'Buyer ×' . (int) $p['paid'] : 'Buyer', 'good') : ui_pill('Lead', 'neutral'),
            (int) $p['paid'] > 0 ? h(money((int) $p['spent'])) : '<span class="muted">—</span>',
            $p['email_unsub_at'] ? ui_pill('Unsubscribed', 'warn') : ($p['marketing_optin'] ? ui_pill('Yes', 'good') : '<span class="muted">No</span>'),
            h(when($p['last_at'] ?? $p['created_at'])),
        ];
    }
    $base = ['view' => 'people', 'show' => $show, 'q' => $q];
    $tools = '<div class="toolbar">' . ui_chips(['' => 'Everyone', 'buyers' => 'Buyers', 'leads' => 'Leads', 'offers' => 'Said yes to offers', 'unsub' => 'Unsubscribed'],
        $show, 'show', array_diff_key($base, ['show' => 1])) . ui_search($q, array_diff_key($base, ['q' => 1]), 'Search name, email or phone') . '</div>';
    $body .= ui_grid(ui_card('Everyone', $tools . ui_table(
        [['Person', ''], ['WhatsApp', 'nowrap'], ['Type', ''], ['Paid', 'num'], ['Offers', ''], ['Last booking', 'muted nowrap']],
        $table, ['empty' => 'Nobody matches.', 'note' => count($list) === 500 ? 'Showing 500. Download the CSV for everyone.' : '']
    ), ['wide' => true, 'flush' => true, 'sub' => count($list) . ' shown. Open a name for their full history.']));

    return [
        'body'    => $body,
        'actions' => ui_button('Download CSV', admin_url($base + ['export' => 'csv']), 'quiet', 'download'),
    ];
};
