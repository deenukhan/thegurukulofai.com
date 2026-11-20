<?php
/** Sales: every payment, money after Razorpay's fee, and payouts to the bank. */
return static function (array $d): array {
    $pdo = $d['pdo'];
    $q = rp_clean($_GET['q'] ?? '', 80);
    $type = in_array($_GET['type'] ?? '', ['addon', 'ticket', 'coupon'], true) ? (string) $_GET['type'] : '';

    [$cond, $args] = in_range($d['range'], 'b.paid_at');
    $where = ["b.product = ?", "b.status = 'paid'", $cond];
    $params = array_merge([$d['product']], $args);
    if ($type === 'addon') {
        $where[] = 'b.addon = 1';
    } elseif ($type === 'ticket') {
        $where[] = 'b.addon = 0';
    } elseif ($type === 'coupon') {
        $where[] = 'b.coupon = 1';
    }
    if ($q !== '') {
        $where[] = '(c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR b.payment_id LIKE ?)';
        $like = '%' . addcslashes($q, '%_\\') . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $sql = 'SELECT b.id AS booking_id, b.contact_id, c.name, c.email, c.phone, b.paid_at, b.session_at, b.amount_paise, b.fee_paise, b.refunded_paise,
            b.addon, b.coupon, b.payment_id, b.order_id, b.utm_source, b.utm_campaign, b.utm_content, b.source
        FROM bookings b JOIN contacts c ON c.id = b.contact_id WHERE ' . implode(' AND ', $where) . ' ORDER BY b.paid_at DESC, b.id DESC';

    if (($_GET['export'] ?? '') === 'csv') {
        $all = rows($pdo, $sql, $params);
        tg_audit($d['config'], 'admin', 'export_csv', 'sales: ' . count($all) . ' rows, ' . $d['range']['label']);
        $lines = [];
        foreach ($all as $b) {
            $lines[] = [$b['paid_at'], $b['name'], $b['email'], $b['phone'] !== '' ? '+91' . $b['phone'] : '', session_label($b['session_at']),
                $b['addon'] ? 'yes' : 'no', $b['coupon'] ? 'yes' : 'no', number_format($b['amount_paise'] / 100, 2, '.', ''),
                $b['fee_paise'] === null ? '' : number_format($b['fee_paise'] / 100, 2, '.', ''),
                number_format($b['refunded_paise'] / 100, 2, '.', ''), $b['utm_source'], $b['utm_campaign'], $b['utm_content'],
                $b['payment_id'], $b['order_id'], admin_confirmation($d['config'], $b['payment_id'])['state']];
        }
        send_csv('masterclass-sales-' . date('Y-m-d') . '.csv', ['paid_at', 'name', 'email', 'whatsapp', 'session', 'addon', 'coupon',
            'amount_rs', 'razorpay_fee_rs', 'refunded_rs', 'utm_source', 'utm_campaign', 'utm_content', 'payment_id', 'order_id', 'confirmation_email'], $lines);
    }

    $s = m_sales($d);
    $net = $s['gross'] - $s['fees'] - $s['refunded'];
    $feeNote = $s['seats'] === 0 ? '' : ($s['fee_known'] < $s['seats']
        ? 'Fee known for ' . $s['fee_known'] . ' of ' . $s['seats'] . ' payments'
        : 'Razorpay fee incl. GST');
    $body = ui_kpis([
        ['label' => 'Revenue', 'value' => h(money($s['gross'])), 'note' => num($s['seats']) . ' seat' . ($s['seats'] === 1 ? '' : 's')],
        ['label' => 'Razorpay fees', 'value' => h(money($s['fees'])), 'note' => $feeNote],
        ['label' => 'Refunds', 'value' => h(money($s['refunded'])), 'note' => $s['refunded'] > 0 ? 'Paid back to buyers' : 'None', 'tone' => $s['refunded'] > 0 ? 'warn' : null],
        ['label' => 'You keep', 'value' => h(money($net)), 'note' => 'After fees and refunds' . ($s['fee_known'] < $s['seats'] ? ', so far' : '')],
    ]);

    $chart = ui_card('Revenue by day', ui_columns(m_daily_revenue($d), 'm_axis_money'), ['size' => 'two-thirds', 'sub' => 'Hover a day for seats and amount']);

    $ticket = $s['seats'] - $s['addons'];
    $mix = ui_bars([
        ['label' => 'Ticket only', 'value' => $ticket, 'display' => num($ticket) . ' · ' . pct($ticket, $s['seats'])],
        ['label' => 'Ticket + add-on', 'value' => $s['addons'], 'display' => num($s['addons']) . ' · ' . pct($s['addons'], $s['seats'])],
        ['label' => 'Used the coupon', 'value' => $s['coupons'], 'display' => num($s['coupons']) . ' · ' . pct($s['coupons'], $s['seats'])],
    ], (float) max($s['seats'], 1));
    $coupon = admin_coupon($d['config']);
    if ($coupon !== null) {
        $mix .= '<p class="table-note">Coupon uses so far: ' . $coupon['used'] . ($coupon['max'] !== null ? ' of ' . $coupon['max'] : '') . '.</p>';
    }
    $mixCard = ui_card('What people bought', $mix, ['size' => 'third', 'sub' => $d['range']['label']]);

    // ---- Payments ----
    $list = rows($pdo, $sql . ' LIMIT 500', $params);
    $table = [];
    foreach ($list as $b) {
        $conf = admin_confirmation($d['config'], $b['payment_id']);
        $amount = h(money((int) $b['amount_paise']));
        if ((int) $b['refunded_paise'] > 0) {
            $amount .= '<small class="sub tone-warn">' . h(money((int) $b['refunded_paise'])) . ' refunded</small>';
        }
        $table[] = [
            h(when($b['paid_at'])) . '<small class="sub"><code>' . h((string) $b['payment_id']) . '</code></small>',
            m_person($b) . '<small class="sub">' . h($b['email']) . '</small>',
            ($b['addon'] ? ui_pill('Add-on', 'info') . ' ' : '') . ($b['coupon'] ? ui_pill('Coupon', 'neutral') : ($b['addon'] ? '' : '<span class="muted">Ticket</span>')),
            h(session_label($b['session_at'])),
            '<span class="clip">' . h(m_source($b)) . '</span>',
            admin_confirmation_pill($conf)
                . (in_array($conf['state'], ['missing', 'retrying'], true) ? '<a class="row-link" href="' . h(admin_url(['view' => 'people', 'id' => $b['contact_id'], 'resend' => $b['booking_id']])) . '">Send by hand</a>' : '')
                . ($conf['tries'] > 0 && $conf['state'] === 'sent' ? '<small class="sub">after ' . $conf['tries'] . ' failed tr' . ($conf['tries'] === 1 ? 'y' : 'ies') . '</small>' : ''),
            $amount,
        ];
    }
    $base = ['view' => 'sales', 'range' => $d['range']['key'] === '30d' ? '' : $d['range']['key'], 'type' => $type, 'q' => $q];
    $filters = '<div class="toolbar">' . ui_chips(['' => 'All', 'ticket' => 'Ticket only', 'addon' => 'With add-on', 'coupon' => 'Coupon'], $type, 'type',
        array_diff_key($base, ['type' => 1])) . ui_search($q, array_diff_key($base, ['q' => 1]), 'Search name, email, phone or payment ID') . '</div>';
    $payments = ui_card('Payments', $filters . ui_table(
        [['Paid', 'muted nowrap'], ['Buyer', ''], ['Bought', ''], ['Session', 'nowrap'], ['Came from', ''], ['Confirmation email', ''], ['Amount', 'num']],
        $table,
        ['empty' => $q !== '' || $type !== '' ? 'No payments match.' : 'No payments in this period.',
         'note' => count($list) === 500 ? 'Showing the newest 500. Download the CSV for all of them.' : '']
    ), ['wide' => true, 'flush' => true, 'sub' => count($list) . ' shown']);

    // ---- Payouts ----
    $settle = admin_settlements($d['config']);
    if (empty($settle['ok'])) {
        $payouts = ui_empty('Razorpay did not answer', 'Payouts show here when Razorpay can be reached. Try again in a few minutes.');
    } else {
        $rowsHtml = [];
        foreach ($settle['items'] as $st) {
            $tone = $st['status'] === 'processed' ? 'good' : ($st['status'] === 'failed' ? 'bad' : 'info');
            $rowsHtml[] = [h(date('j M, g:i A', $st['at'])), ui_pill(ucfirst($st['status'] ?: 'unknown'), $tone),
                '<code>' . h($st['utr'] ?: '—') . '</code>', h(money($st['amount']))];
        }
        $payouts = ui_table([['Date', 'muted'], ['Status', ''], ['Bank reference (UTR)', 'muted'], ['Amount', 'num']], $rowsHtml,
            ['empty' => 'No payouts yet.', 'note' => 'Checked ' . h(ago((int) $settle['at'], $d['now'])) . '.']);
    }
    $payoutCard = ui_card('Payouts to your bank', $payouts, ['wide' => true, 'flush' => true,
        'sub' => 'From Razorpay. These cover the whole Razorpay account, including other products.']);

    return [
        'body'    => $body . ui_grid($chart, $mixCard, $payments, $payoutCard),
        'actions' => ui_button('Download CSV', admin_url($base + ['export' => 'csv']), 'quiet', 'download'),
    ];
};
