<?php
/** System health: every check grouped by area, the settings in use, and recent server errors. */
return static function (array $d): array {
    $pdo = $d['pdo'];
    $config = $d['config'];
    $checks = admin_checks($pdo, $config, $d['now']);

    $count = ['bad' => 0, 'warn' => 0, 'ok' => 0];
    $byArea = [];
    foreach ($checks as $c) {
        $count[$c['level']] = ($count[$c['level']] ?? 0) + 1;
        $byArea[$c['area']][] = $c;
    }
    $body = ui_kpis([
        ['label' => 'Needs fixing', 'value' => h(num($count['bad'])), 'tone' => $count['bad'] ? 'bad' : null, 'note' => $count['bad'] ? 'Sales or emails are affected' : 'Nothing broken'],
        ['label' => 'Worth a look', 'value' => h(num($count['warn'])), 'tone' => $count['warn'] ? 'warn' : null, 'note' => $count['warn'] ? 'Not urgent' : 'Nothing'],
        ['label' => 'Working', 'value' => h(num($count['ok']))],
        ['label' => 'Database', 'value' => 'Connected', 'note' => 'Version ' . TG_DB_VERSION],
    ]);

    $cards = [];
    foreach (['Selling', 'Emails', 'WhatsApp', 'Session', 'Automatic tasks', 'Tracking', 'Sign-in', 'Server'] as $area) {
        if (!empty($byArea[$area])) {
            $cards[] = ui_card($area, ui_checks($byArea[$area]), ['flush' => true]);
        }
    }

    // ---- Settings in use (never a secret) ----
    $worker = admin_worker_last($config);
    $coupon = admin_coupon($config);
    $settings = ui_facts([
        ['Product', '<code>' . h($config['product']) . '</code>'],
        ['Ticket price', h(money((int) $config['price_paise']))],
        ['Add-on price', h(money((int) $config['addon_paise']))],
        ['Coupon', $coupon === null ? '<span class="muted">Off</span>' : 'Seat for ' . h(money($coupon['seat_paise'])) . ' · ' . $coupon['used'] . ' used' . ($coupon['max'] !== null ? ' of ' . $coupon['max'] : '')],
        ['Razorpay mode', str_starts_with((string) $config['key_id'], 'rzp_live_') ? ui_pill('Live', 'good') : ui_pill('Test', 'bad')],
        ['Emails sent from', h($config['mail_from'])],
        ['Notes go to', h($config['mail_notify'])],
        ['5-minute task', $worker === null ? '<span class="muted">Not reported yet</span>' : 'Last run ' . h(ago((int) $worker['at'], $d['now']))
            . (!empty($worker['sent']) ? ' · sent ' . (int) $worker['sent'] : '')],
        ['Unpaid leads kept for', !empty($config['db']['keep_unpaid_days']) ? (int) $config['db']['keep_unpaid_days'] . ' days' : 'Forever'],
    ]);
    $cards[] = ui_card('Settings in use', $settings, ['sub' => 'From the private config. Passwords and keys are never shown.']);

    // ---- Recent server errors ----
    $errors = admin_error_log($config, 15);
    $rowsHtml = [];
    foreach ($errors as $e) {
        $rowsHtml[] = [$e['at'] ? h(date('j M, g:i A', $e['at'])) : '<span class="muted">—</span>', '<span class="log">' . h($e['text']) . '</span>'];
    }
    $cards[] = ui_card('Recent server errors', ui_table([['When', 'muted nowrap'], ['Message', '']], $rowsHtml, ['empty' => 'No errors logged.']),
        ['wide' => true, 'flush' => true, 'sub' => 'The newest 15 lines of the private error log']);

    return ['body' => $body . ui_grid(...$cards)];
};
