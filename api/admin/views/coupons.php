<?php
/**
 * Coupons: make one code or many, set the discount, dates and use limit, then watch
 * the uses and pause or delete. Codes made together (a batch) show as one row here,
 * and open into their own list with a download. The config file's coupon is separate
 * and stays on the Settings page.
 */
require_once dirname(__DIR__) . '/lib/coupons.php';

return static function (array $d): array {
    $pdo = $d['pdo'];
    $config = $d['config'];
    $now = rp_now($config); // the test clock in local tests, the real time live
    $price = (int) $config['price_paise'];
    $show = in_array($_GET['show'] ?? '', ['paused', 'ended', 'all'], true) ? (string) $_GET['show'] : 'live';
    $batch = preg_match('/^[0-9a-f]{8}$/', (string) ($_GET['batch'] ?? '')) ? (string) $_GET['batch'] : '';

    $coupons = rows($pdo, 'SELECT * FROM coupons' . ($batch !== '' ? ' WHERE batch = ?' : '') . ' ORDER BY id DESC', $batch !== '' ? [$batch] : []);
    $paid = [];
    foreach (rows($pdo, "SELECT coupon_id, COUNT(*) AS n FROM bookings WHERE status = 'paid' AND coupon_id IS NOT NULL GROUP BY coupon_id") as $r) {
        $paid[(int) $r['coupon_id']] = (int) $r['n'];
    }
    foreach ($coupons as &$c) {
        $c['state'] = tg_coupon_state($c, $now);
        $c['paid'] = $paid[(int) $c['id']] ?? 0;
    }
    unset($c);

    $stateNames = ['active' => 'active', 'scheduled' => 'starts later', 'paused' => 'paused', 'used_up' => 'used up', 'expired' => 'expired'];
    $valid = static function (array $c) use ($now): string {
        $start = $c['starts_at'] !== null ? (new DateTimeImmutable($c['starts_at'], ist()))->getTimestamp() : null;
        $end = $c['ends_at'] !== null ? (new DateTimeImmutable($c['ends_at'], ist()))->getTimestamp() : null;
        $lines = [];
        if ($start !== null && $start > $now) {
            $lines[] = 'Starts ' . when($c['starts_at']) . '<small class="sub">' . h(until($start, $now)) . '</small>';
        }
        if ($end === null) {
            $lines[] = '<span class="muted">No end date</span>';
        } elseif ($end > $now) {
            $lines[] = 'Ends ' . when($c['ends_at']) . ($start === null || $start <= $now ? '<small class="sub">' . h(until($end, $now)) . '</small>' : '');
        } else {
            $lines[] = '<span class="muted">Ended ' . when($c['ends_at']) . '</span>';
        }
        return implode('', $lines);
    };
    $uses = static fn(array $c): string => h(num((int) $c['used'])) . ($c['max_uses'] !== null ? ' of ' . h(num((int) $c['max_uses'])) : ' <span class="muted">· no limit</span>')
        . '<small class="sub">' . ($c['paid'] > 0 ? h(num($c['paid'])) . ' paid' : 'none paid yet') . '</small>';
    $discount = static fn(array $c): string => h(admin_coupon_discount($c)) . '<small class="sub">Seat ' . h(money(admin_coupon_seat($config, $c))) . '</small>';

    // ---------- Download a batch's codes and links ----------
    if ($batch !== '' && ($_GET['export'] ?? '') === 'csv' && $coupons) {
        tg_audit($config, 'admin', 'export_csv', 'coupons: batch ' . $batch . ', ' . count($coupons) . ' codes');
        $lines = [];
        foreach (array_reverse($coupons) as $c) {
            $lines[] = [$c['code'], admin_coupon_link($c['code']), admin_coupon_discount($c), number_format(admin_coupon_seat($config, $c) / 100, 2, '.', ''),
                $c['starts_at'] ?? '', $c['ends_at'] ?? '', $c['max_uses'] ?? '', $c['used'], $c['paid'], $stateNames[$c['state']], $c['note']];
        }
        send_csv('coupons-' . $batch . '.csv', ['code', 'link', 'discount', 'seat_rs', 'starts_ist', 'ends_ist', 'use_limit', 'used', 'paid', 'status', 'note'], $lines);
    }

    // ---------- Delete asks first ----------
    $confirm = '';
    $deleteId = (int) ($_GET['delete'] ?? 0);
    foreach ($coupons as $c) {
        if ((int) $c['id'] === $deleteId) {
            $confirm = ui_card('Delete ' . $c['code'] . '?', '<p>It stops working at once, and buyers who type it are told it is not valid. '
                . 'Its ' . num((int) $c['used']) . ' use' . ((int) $c['used'] === 1 ? '' : 's') . ' so far stay in the Activity log. This cannot be undone. To stop it for a while instead, pause it.</p>'
                . '<div class="confirm-actions">' . ui_post('coupon_delete', ['id' => $c['id'], '#' => 'list'], ui_code_input(), 'Delete it', 'primary', 'inline')
                . ui_button('Cancel', admin_url(['view' => 'coupons', 'batch' => $batch, 'show' => $show === 'live' ? '' : $show]) . '#c' . (int) $c['id'], 'quiet') . '</div>', ['wide' => true, 'id' => 'confirm']);
        }
    }
    if ($batch !== '' && ($_GET['delete'] ?? '') === 'all' && $coupons) {
        $confirm = ui_card('Delete all ' . count($coupons) . ' codes?', '<p>They all stop working at once. Their uses so far stay in the Activity log. This cannot be undone.</p>'
            . '<div class="confirm-actions">' . ui_post('coupon_delete', ['batch' => $batch], ui_code_input(), 'Delete all ' . count($coupons), 'primary', 'inline')
            . ui_button('Cancel', admin_url(['view' => 'coupons', 'batch' => $batch]) . '#list', 'quiet') . '</div>', ['wide' => true, 'id' => 'confirm']);
    }

    // ---------- One coupon's row ----------
    $row = static function (array $c) use ($now, $valid, $uses, $discount, $batch, $show): array {
        $id = (int) $c['id'];
        $toggle = (int) $c['paused'] === 1
            ? ui_post('coupon_resume', ['id' => $id, '#' => 'c' . $id], ui_code_input(), 'Resume', 'quiet', 'inline')
            : ui_post('coupon_pause', ['id' => $id, '#' => 'c' . $id], ui_code_input(), 'Pause', 'quiet', 'inline');
        $delete = '<a class="row-link" href="' . h(admin_url(['view' => 'coupons', 'batch' => $batch, 'show' => $show === 'live' ? '' : $show, 'delete' => $id])) . '#confirm">Delete</a>';
        return [
            '<span class="anchor" id="c' . $id . '"></span><span class="coupon-code">' . h($c['code']) . '</span> ' . admin_coupon_pill($c, $now)
                . ($c['note'] !== '' && $batch === '' ? '<small class="sub">' . h($c['note']) . '</small>' : '')
                . ui_copy(admin_coupon_link($c['code'])),
            $discount($c),
            $valid($c),
            $uses($c),
            '<div class="row-actions">' . $toggle . $delete . '</div>',
        ];
    };

    $cols = [['Code, status and link', ''], ['Discount', 'nowrap'], ['Valid', ''], ['Uses', 'num'], ['', 'num']];
    $inFilter = static fn(string $state): bool => match ($show) {
        'live'   => in_array($state, ['active', 'scheduled'], true),
        'paused' => $state === 'paused',
        'ended'  => in_array($state, ['expired', 'used_up'], true),
        default  => true,
    };

    // ---------- A batch's own page ----------
    if ($batch !== '') {
        if (!$coupons) {
            return ['title' => 'Codes', 'back' => ['All coupons', admin_url(['view' => 'coupons'])],
                'body' => ui_grid(ui_card('', ui_empty('These codes no longer exist', 'They were deleted.'), ['wide' => true]))];
        }
        $first = $coupons[0];
        $counts = array_count_values(array_column($coupons, 'state'));
        $summary = [];
        foreach ($stateNames as $k => $label) {
            if (!empty($counts[$k])) {
                $summary[] = $counts[$k] . ' ' . $label;
            }
        }
        $anyPaused = !empty($counts['paused']);
        $tools = '<div class="toolbar"><p class="muted small">' . h(implode(' · ', $summary)) . '</p><div class="confirm-actions">'
            . ui_button('Download codes', admin_url(['view' => 'coupons', 'batch' => $batch, 'export' => 'csv']), 'primary', 'download')
            . ui_post('coupon_pause', ['batch' => $batch, '#' => 'list'], ui_code_input(), 'Pause all', 'quiet', 'inline')
            . ($anyPaused ? ui_post('coupon_resume', ['batch' => $batch, '#' => 'list'], ui_code_input(), 'Resume all', 'quiet', 'inline') : '')
            . ui_button('Delete all', admin_url(['view' => 'coupons', 'batch' => $batch, 'delete' => 'all']) . '#confirm', 'quiet') . '</div></div>';
        $list = array_map($row, $coupons);
        $title = count($coupons) . ' codes' . ($first['note'] !== '' ? ': ' . $first['note'] : '');
        return [
            'title' => $title,
            'intro' => admin_coupon_discount($first) . ', made ' . when($first['created_at']) . '. Download the list to share the links, one per buyer.',
            'back'  => ['All coupons', admin_url(['view' => 'coupons'])],
            'body'  => ui_grid($confirm, ui_card('', $tools . '<div class="coupon-list">' . ui_table($cols, $list) . '</div>', ['wide' => true, 'flush' => true, 'id' => 'list'])),
        ];
    }

    // ---------- KPIs ----------
    $count = static fn(string $state): int => count(array_filter($coupons, static fn($c) => $c['state'] === $state));
    $active = $count('active');
    $scheduled = $count('scheduled');
    $usedTotal = array_sum(array_map('intval', array_column($coupons, 'used')));
    $paidTotal = (int) value($pdo, "SELECT COUNT(*) FROM bookings WHERE status = 'paid' AND coupon_id IS NOT NULL");
    $soon = count(array_filter($coupons, static function ($c) use ($now) {
        return $c['state'] === 'active' && $c['ends_at'] !== null
            && (new DateTimeImmutable($c['ends_at'], ist()))->getTimestamp() - $now <= 86400;
    }));
    $kpis = ui_kpis([
        ['label' => 'Active now', 'value' => h(num($active)), 'note' => $scheduled > 0 ? $scheduled . ' more start later' : 'Codes buyers can use'],
        ['label' => 'Uses so far', 'value' => h(num($usedTotal)), 'note' => 'Payment windows opened'],
        ['label' => 'Paid seats', 'value' => h(num($paidTotal)), 'note' => 'Bought with these coupons'],
        ['label' => 'Ending in 24 h', 'value' => h(num($soon)), 'note' => $soon > 0 ? 'Still active, ending soon' : 'None', 'tone' => $soon > 0 ? 'warn' : null],
    ]);

    // ---------- Make coupons ----------
    $form = ui_choice('Codes', 'mode', ['one' => 'One code I type', 'many' => 'Many random codes'], 'one')
        . '<div class="if-one">' . ui_field('Code', 'code', 'maxlength="30" placeholder="DIWALI50" autocomplete="off" autocapitalize="characters" spellcheck="false"',
            'Letters and digits, 4 to 24. Buyers can type it in small letters or with spaces: it still works.') . '</div>'
        . '<div class="if-many fields">' . ui_field('How many codes', 'count', 'type="number" min="2" max="' . ADMIN_COUPON_MAX_BATCH . '" value="10"', '2 to ' . ADMIN_COUPON_MAX_BATCH . ' at a time.')
        . ui_field('Each code starts with (optional)', 'prefix', 'maxlength="12" placeholder="INSTA" autocomplete="off" autocapitalize="characters" spellcheck="false"',
            'Then 6 random letters, such as INSTA-7KQ4MX.') . '</div>'
        . '<div class="fields">' . ui_field('Discount', 'off_value', 'type="number" min="1" required placeholder="500"',
            'The seat is ' . money($price) . '. It never drops below ₹1.')
        . ui_choice('Taken off as', 'off_type', ['amount' => '₹ off', 'percent' => '% off'], 'amount') . '</div>'
        . ui_choice('Starts', 'starts', ['now' => 'Now', 'later' => 'Later'], 'now')
        . '<div class="if-later">' . ui_field('Start date and time (IST)', 'starts_at', 'type="datetime-local"') . '</div>'
        . ui_choice('Ends', 'ends', array_map(static fn($e) => $e[0], ADMIN_COUPON_ENDS), '24h')
        . '<div class="if-exact">' . ui_field('End date and time (IST)', 'ends_at', 'type="datetime-local"', 'It stops working at exactly this minute.') . '</div>'
        . '<p class="muted small if-length">1 hour, 24 hours and 7 days count from the start.</p>'
        . ui_field('Use limit', 'max_uses', 'type="number" min="1" max="' . ADMIN_COUPON_MAX_USES . '" placeholder="No limit"',
            'Leave empty for no limit. With many codes, the limit is for each code: 1 makes every code single-use.')
        . ui_field('Note for you (optional)', 'note', 'maxlength="80" placeholder="Instagram story, 22 Nov" autocomplete="off"')
        . ui_code_input();
    $make = ui_card('Make coupons', ui_post('coupon_create', ['#' => 'make'], $form, 'Make coupon', 'primary', 'make'),
        ['id' => 'make', 'size' => 'two-thirds', 'sub' => 'Live the moment you save. Every coupon gets a link that fills the code in for the buyer.']);

    // ---------- How they work ----------
    $configCoupon = admin_coupon($config);
    $how = ui_card('How coupons work', ui_facts([
        ['Seat price now', h(money($price))],
        ['Lowers', 'The seat only. The add-on stays ' . h(money((int) $config['addon_paise'])) . '.'],
        ['A use counts', 'When the buyer opens the payment window'],
        ['Price decided by', 'The server, never the browser'],
        ['Config file coupon', $configCoupon === null ? '<span class="muted">None</span>' : 'Still works. <a href="' . h(admin_url(['view' => 'settings'])) . '#coupon">Settings</a>'],
    ]) . '<p class="notice">' . icon('info') . '<span>A buyer who closes the payment window and tries again uses a second go. For single-use codes, a limit of 2 is kinder.</span></p>',
        ['size' => 'third']);

    // ---------- The list: single coupons, and each batch as one row ----------
    $list = [];
    $groups = [];
    foreach ($coupons as $c) {
        if ($c['batch'] === null) {
            if ($inFilter($c['state'])) {
                $list[] = $row($c);
            }
            continue;
        }
        if (!isset($groups[$c['batch']])) {
            $groups[$c['batch']] = ['at' => count($list), 'codes' => []];
            $list[] = null; // keeps the batch's place, newest first
        }
        $groups[$c['batch']]['codes'][] = $c;
    }
    foreach ($groups as $b => $g) {
        $codes = $g['codes'];
        if (!array_filter($codes, static fn($c) => $inFilter($c['state']))) {
            continue;
        }
        $first = $codes[0];
        $counts = array_count_values(array_column($codes, 'state'));
        $states = [];
        foreach ($stateNames as $k => $label) {
            if (!empty($counts[$k])) {
                $states[] = $counts[$k] . ' ' . $label;
            }
        }
        $usedSum = array_sum(array_map('intval', array_column($codes, 'used')));
        $paidSum = array_sum(array_column($codes, 'paid'));
        $open = admin_url(['view' => 'coupons', 'batch' => $b]);
        $list[$g['at']] = [
            '<span class="coupon-code">' . h(count($codes) . ' codes') . '</span>'
                . '<small class="sub">' . h(implode(', ', $states)) . '</small>'
                . '<small class="sub">' . h(($first['note'] !== '' ? $first['note'] . ' · ' : '') . 'such as ' . $first['code']) . '</small>'
                . '<a class="row-link" href="' . h($open) . '#list">See the codes and download</a>',
            $discount($first),
            $valid($first),
            h(num($usedSum)) . ($first['max_uses'] !== null ? ' of ' . h(num((int) $first['max_uses'] * count($codes))) : ' <span class="muted">· no limit</span>')
                . '<small class="sub">' . ($paidSum > 0 ? h(num($paidSum)) . ' paid' : 'none paid yet') . '</small>',
            ui_button('Open', $open . '#list', 'quiet'),
        ];
    }
    $list = array_values(array_filter($list));

    $chips = ui_chips(['live' => 'Live and coming', 'paused' => 'Paused', 'ended' => 'Ended', 'all' => 'All'], $show, 'show', ['view' => 'coupons']);
    $empty = $coupons ? 'No coupons here. Try another filter.' : 'No coupons yet. Make your first one above.';
    $table = ui_card('Your coupons', $chips . '<div class="coupon-list">' . ui_table($cols, $list, ['empty' => $empty]) . '</div>',
        ['wide' => true, 'flush' => true, 'id' => 'list', 'sub' => 'Click a link once to select all of it, then copy. Codes made together show as one row.']);

    return [
        'body' => $kpis . ui_grid($confirm, $make, $how, $table),
    ];
};
