<?php
/** Funnel: each step from page visit to paid seat, why payments failed, and who to follow up. */
return static function (array $d): array {
    $pdo = $d['pdo'];
    $f = m_funnel($d);

    $steps = [
        ['Visited the page', $f['visits'], 'Counted once per browser visit'],
        ['Opened the booking pop-up', $f['popups'], 'Clicked a "Save my seat" button'],
        ['Filled the form', $f['forms'], 'Name, email and WhatsApp saved'],
        ['Opened the payment window', $f['orders'], 'Razorpay order created'],
        ['Paid', $f['paid'], 'Money received'],
    ];
    $top = (float) max($f['visits'], $f['popups'], $f['forms'], 1);
    $bars = [];
    $prev = null;
    foreach ($steps as $i => [$label, $n, $sub]) {
        $display = num($n) . ($prev !== null && $prev > 0 ? ' · ' . pct($n, $prev) . ' of previous' : '');
        $bars[] = ['label' => $label, 'value' => $n, 'display' => $display, 'sub' => $sub];
        $prev = $n;
    }
    $note = $f['since'] === null
        ? '<p class="table-note">Page visits and pop-up opens start counting once this update is live.</p>'
        : '<p class="table-note">Visits and pop-up opens are counted from ' . h(when($f['since'], 'j M Y')) . '.</p>';
    $funnelCard = ui_card('From visit to paid seat', ui_bars($bars, $top) . $note, ['size' => 'two-thirds', 'sub' => $d['range']['label']]);

    // ---- Why payments failed ----
    [$cond, $args] = in_range($d['range'], 'created_at');
    $reasons = rows($pdo, "SELECT COALESCE(NULLIF(fail_reason, ''), 'No reason given') AS reason, COUNT(*) AS n FROM bookings
        WHERE product = ? AND status IN ('failed', 'order_failed') AND {$cond} GROUP BY reason ORDER BY n DESC LIMIT 8",
        array_merge([$d['product']], $args));
    $reasonBars = [];
    foreach ($reasons as $r) {
        $reasonBars[] = ['label' => $r['reason'], 'value' => (int) $r['n'], 'display' => num((int) $r['n'])];
    }
    $reasonCard = ui_card('Why payments failed', $reasonBars ? ui_bars($reasonBars) : ui_empty('No failed payments in this period.'),
        ['size' => 'third', 'sub' => 'As Razorpay reported it']);

    // ---- Abandoned: not paid, older than 45 minutes, and no later paid booking ----
    [$condB, $argsB] = in_range($d['range'], 'b.created_at');
    $abandoned = rows($pdo, "SELECT b.contact_id, b.created_at, b.status, b.fail_reason, b.amount_paise, b.addon, b.utm_source, b.utm_campaign,
            b.utm_content, c.name, c.email, c.phone, c.marketing_optin
        FROM bookings b JOIN contacts c ON c.id = b.contact_id
        WHERE b.product = ? AND b.status <> 'paid' AND b.created_at < ? AND {$condB}
          AND NOT EXISTS (SELECT 1 FROM bookings p WHERE p.contact_id = b.contact_id AND p.product = b.product AND p.status = 'paid')
          AND b.id = (SELECT MAX(x.id) FROM bookings x WHERE x.contact_id = b.contact_id AND x.product = b.product)
        ORDER BY b.created_at DESC LIMIT 300",
        array_merge([$d['product'], tg_ist($d['now'] - 45 * 60)], $argsB));

    if (($_GET['export'] ?? '') === 'csv') {
        tg_audit($d['config'], 'admin', 'export_csv', 'abandoned: ' . count($abandoned) . ' rows, ' . $d['range']['label']);
        $lines = [];
        foreach ($abandoned as $b) {
            $lines[] = [$b['created_at'], $b['name'], $b['email'], $b['phone'] !== '' ? '+91' . $b['phone'] : '',
                number_format($b['amount_paise'] / 100, 2, '.', ''), $b['addon'] ? 'yes' : 'no', $b['status'], $b['fail_reason'],
                $b['marketing_optin'] ? 'yes' : 'no', m_source($b)];
        }
        send_csv('masterclass-abandoned-' . date('Y-m-d') . '.csv',
            ['started_at', 'name', 'email', 'whatsapp', 'amount_rs', 'addon', 'status', 'reason', 'offers_optin', 'came_from'], $lines);
    }

    $list = [];
    foreach ($abandoned as $b) {
        $list[] = [
            h(when($b['created_at'])),
            m_person($b) . '<small class="sub">' . h($b['email']) . '</small>',
            m_whatsapp((string) $b['phone']),
            m_status_pill($b, $d['now']) . ($b['fail_reason'] ? '<small class="sub">' . h($b['fail_reason']) . '</small>' : ''),
            h(money((int) $b['amount_paise'])) . ($b['addon'] ? '<small class="sub">with add-on</small>' : ''),
            '<span class="clip">' . h(m_source($b)) . '</span>',
        ];
    }
    $abandonCard = ui_card('Did not pay', ui_table(
        [['Started', 'muted nowrap'], ['Person', ''], ['WhatsApp', 'nowrap'], ['What happened', ''], ['Was paying', 'num'], ['Came from', '']],
        $list, ['empty' => 'Nobody to follow up in this period.']
    ), ['wide' => true, 'flush' => true, 'sub' => 'Filled the form but never paid, and have not paid since. Tap a number to chat on WhatsApp.']);

    $body = ui_kpis([
        ['label' => 'Visitors who paid', 'value' => h(pct($f['paid'], $f['visits'])), 'note' => $f['visits'] > 0 ? $f['paid'] . ' of ' . num($f['visits']) . ' visits' : 'No visit counts yet'],
        ['label' => 'Forms that paid', 'value' => h(pct($f['paid'], $f['forms'])), 'note' => $f['paid'] . ' of ' . num($f['forms']) . ' forms'],
        ['label' => 'Did not pay', 'value' => h(num(count($abandoned))), 'note' => 'People to follow up'],
        ['label' => 'Payment tries failed', 'value' => h(num(array_sum(array_column($reasons, 'n')))), 'note' => $d['range']['label']],
    ]);

    $keep = ['view' => 'funnel', 'range' => $d['range']['key'] === '30d' ? '' : $d['range']['key']];
    return [
        'body'    => $body . ui_grid($funnelCard, $reasonCard, $abandonCard),
        'actions' => ui_button('Download list', admin_url($keep + ['export' => 'csv']), 'quiet', 'download'),
    ];
};
