<?php
/** Overview: the few numbers that matter, the next session, and anything that needs Deenu. */
return static function (array $d): array {
    $sales = m_sales($d);
    $funnel = m_funnel($d);
    $next = m_next_session($d);
    $issues = array_filter(admin_checks($d['pdo'], $d['config'], $d['now']), static fn($c) => in_array($c['level'], ['bad', 'warn'], true));

    $body = '';
    if ($issues) {
        $body .= ui_card(count($issues) === 1 ? '1 thing needs you' : count($issues) . ' things need you', ui_checks(array_values($issues)),
            ['wide' => true, 'flush' => true]);
    }

    $convert = $funnel['visits'] > 0 ? pct($funnel['paid'], $funnel['visits']) : pct($funnel['paid'], $funnel['forms']);
    $body .= ui_kpis([
        ['label' => 'Revenue', 'value' => h(money($sales['gross'])), 'note' => $d['range']['label']],
        ['label' => 'Seats sold', 'value' => h(num($sales['seats'])), 'note' => $sales['addons'] . ' with the add-on'],
        ['label' => 'Add-on taken', 'value' => h(pct($sales['addons'], $sales['seats'])), 'note' => 'of buyers'],
        ['label' => $funnel['visits'] > 0 ? 'Visitors who paid' : 'Forms that paid', 'value' => h($convert),
         'note' => $funnel['paid'] . ' of ' . num($funnel['visits'] > 0 ? $funnel['visits'] : $funnel['forms'])],
    ]);

    $chart = ui_card('Revenue by day', ui_columns(m_daily_revenue($d), 'm_axis_money'),
        ['size' => 'two-thirds', 'sub' => 'Hover a day for seats and amount', 'action' => ui_button('All sales', admin_url(['view' => 'sales', 'range' => $d['range']['key'] === '30d' ? '' : $d['range']['key']]), 'quiet', 'arrow')]);

    $closed = $d['now'] >= $next['close'];
    $session = '<p class="hero-date">' . h((new DateTimeImmutable($next['at'], ist()))->format('l, j F')) . '</p>'
        . '<p class="muted">3 PM to 6 PM · starts ' . h(until($next['unix'], $d['now'])) . '</p>'
        . ui_facts([
            ['Seats booked', '<strong>' . num($next['seats']) . '</strong>'],
            ['With add-on', num($next['addons'])],
            ['Sales close', h(date('D j M, g:i A', $next['close'])) . ($closed ? ' ' . ui_pill('Closed', 'neutral') : '')],
            ['Zoom link', $next['zoom_set'] ? ui_pill('Set', 'good') : ui_pill('Not set', 'bad')],
        ]);
    $sessionCard = ui_card('Next session', $session,
        ['size' => 'third', 'action' => ui_button('Open', admin_url(['view' => 'sessions', 'at' => $next['at']]), 'quiet', 'arrow')]);

    $latest = rows($d['pdo'], "SELECT b.contact_id, c.name, c.email, b.amount_paise, b.addon, b.paid_at, b.utm_campaign, b.utm_content, b.utm_source
        FROM bookings b JOIN contacts c ON c.id = b.contact_id WHERE b.product = ? AND b.status = 'paid'
        ORDER BY b.paid_at DESC LIMIT 6", [$d['product']]);
    $list = [];
    foreach ($latest as $b) {
        $list[] = [m_person($b), h(when($b['paid_at'])), $b['addon'] ? ui_pill('Add-on', 'info') : '', h(money((int) $b['amount_paise']))];
    }
    $latestCard = ui_card('Latest sales', ui_table([['Buyer', ''], ['Paid', 'muted'], ['', ''], ['Amount', 'num']], $list,
        ['empty' => 'No sales yet.']), ['flush' => true, 'action' => ui_button('All sales', admin_url(['view' => 'sales']), 'quiet', 'arrow')]);

    $steps = [
        ['Page visits', $funnel['visits']], ['Opened the booking pop-up', $funnel['popups']],
        ['Filled the form', $funnel['forms']], ['Paid', $funnel['paid']],
    ];
    $bars = [];
    foreach ($steps as [$label, $n]) {
        $bars[] = ['label' => $label, 'value' => $n, 'display' => num($n)];
    }
    $funnelCard = ui_card('Funnel', ui_bars($bars, (float) max($funnel['visits'], $funnel['forms'], 1)),
        ['sub' => $d['range']['label'], 'action' => ui_button('Details', admin_url(['view' => 'funnel', 'range' => $d['range']['key'] === '30d' ? '' : $d['range']['key']]), 'quiet', 'arrow')]);

    $body .= ui_grid($chart, $sessionCard, $latestCard, $funnelCard);
    if (!$issues) {
        $body .= '<p class="all-clear">' . icon('check') . 'Everything is working. Nothing needs you right now.</p>';
    }
    return ['body' => $body];
};
