<?php
/** Ads: which campaigns and ads bring paying buyers, and (once connected) what they cost. */
return static function (array $d): array {
    $pdo = $d['pdo'];
    $config = $d['config'];
    $s = m_sales($d);

    [$cond, $args] = in_range($d['range'], 'created_at');
    $by = static function (string $column) use ($pdo, $d, $cond, $args): array {
        return rows($pdo, "SELECT COALESCE(NULLIF({$column}, ''), '') AS name, COUNT(*) AS forms, COALESCE(SUM(status = 'paid'), 0) AS paid,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN amount_paise ELSE 0 END), 0) AS gross
            FROM bookings WHERE product = ? AND {$cond} GROUP BY name ORDER BY paid DESC, forms DESC LIMIT 25",
            array_merge([$d['product']], $args));
    };
    $table = static function (array $list, string $empty): string {
        $out = [];
        foreach ($list as $r) {
            $out[] = [
                $r['name'] === '' ? '<span class="muted">No ad tag (direct, organic or shared link)</span>' : '<span class="clip wide">' . h($r['name']) . '</span>',
                h(num((int) $r['forms'])),
                h(num((int) $r['paid'])),
                h(pct((int) $r['paid'], (int) $r['forms'])),
                h(money((int) $r['gross'])),
            ];
        }
        return ui_table([['Name', ''], ['Forms', 'num'], ['Paid', 'num'], ['Paid rate', 'num'], ['Revenue', 'num']], $out, ['empty' => $empty]);
    };

    $campaigns = $by('utm_campaign');
    $ads = $by('utm_content');
    [$condP, $argsP] = in_range($d['range'], 'paid_at');
    $fromAds = row($pdo, "SELECT COUNT(*) AS seats, COALESCE(SUM(amount_paise), 0) AS gross FROM bookings
        WHERE product = ? AND status = 'paid' AND utm_source <> '' AND {$condP}", array_merge([$d['product']], $argsP));

    // ---- Spend from Meta, when connected ----
    $spend = null;
    $spendCard = '';
    if (admin_meta_ads_ready($config)) {
        $since = $d['range']['day'] ?? '2026-11-08';
        $until = (new DateTimeImmutable('@' . $d['now']))->setTimezone(ist())->format('Y-m-d');
        $meta = admin_meta_spend($config, $since, $until);
        if (!empty($meta['ok'])) {
            $spend = array_sum(array_column($meta['rows'], 'spend'));
            $rowsHtml = [];
            foreach ($meta['rows'] as $r) {
                $rowsHtml[] = ['<span class="clip wide">' . h($r['name']) . '</span>', h(money($r['spend'])), h(num($r['impressions'])),
                    h(num($r['clicks'])), h(num($r['purchases'])), h($r['purchases'] > 0 ? money(intdiv($r['spend'], $r['purchases'])) : '—')];
            }
            $spendCard = ui_card('Spend by campaign', ui_table([['Campaign', ''], ['Spend', 'num'], ['Shown', 'num'], ['Clicks', 'num'], ['Purchases (Meta)', 'num'], ['Cost per purchase', 'num']],
                $rowsHtml, ['empty' => 'No spend in this period.', 'note' => 'From Meta, checked ' . h(ago((int) $meta['at'], $d['now'])) . '. Meta counts purchases its own way, so they can differ from your sales.']),
                ['wide' => true, 'flush' => true]);
        } else {
            $spendCard = ui_card('Spend by campaign', ui_empty('Meta did not answer', (string) ($meta['error'] ?? 'Try again in a few minutes.')), ['wide' => true]);
        }
    } else {
        $spendCard = ui_card('Connect ad spend', '<div class="steps"><p>Add two lines to the <code>meta</code> block in the private config to see spend, cost per seat and return on ad spend here:</p>'
            . '<pre class="code">\'ad_account_id\' => \'737272332594464\',' . "\n" . '\'ads_token\'     => \'PASTE_A_READ_ONLY_ADS_TOKEN\',</pre>'
            . '<p class="muted">The token needs only the <strong>ads_read</strong> permission. Create it in Meta Business Settings, under System users. Until then, this page shows sales by ad tag only.</p></div>',
            ['wide' => true]);
    }

    $seats = (int) $fromAds['seats'];
    $body = ui_kpis([
        ['label' => 'Ad spend', 'value' => $spend === null ? '<span class="muted">—</span>' : h(money($spend)), 'note' => $spend === null ? 'Not connected yet' : $d['range']['label']],
        ['label' => 'Seats from tagged ads', 'value' => h(num($seats)), 'note' => pct($seats, $s['seats']) . ' of all seats'],
        ['label' => 'Cost per seat', 'value' => $spend !== null && $seats > 0 ? h(money(intdiv($spend, $seats))) : '<span class="muted">—</span>', 'note' => 'Spend ÷ seats from ads'],
        ['label' => 'Return on ad spend', 'value' => $spend ? h(number_format((int) $fromAds['gross'] / $spend, 2) . '×') : '<span class="muted">—</span>', 'note' => 'Revenue from ads ÷ spend'],
    ]);

    // ---- Purchases sent to Meta by the server ----
    $paid = rows($pdo, "SELECT payment_id FROM bookings WHERE product = ? AND status = 'paid' AND {$condP}", array_merge([$d['product']], $argsP));
    $metaOk = $metaFail = $metaNone = 0;
    foreach ($paid as $p) {
        $m = admin_confirmation($config, $p['payment_id'])['meta'];
        $m === 'ok' ? $metaOk++ : ($m === 'failed' ? $metaFail++ : $metaNone++);
    }
    $capi = ui_bars([
        ['label' => 'Sent to Meta', 'value' => $metaOk, 'display' => num($metaOk)],
        ['label' => 'Meta refused', 'value' => $metaFail, 'display' => num($metaFail)],
        ['label' => 'Not sent', 'value' => $metaNone, 'display' => num($metaNone), 'sub' => 'Before tracking was on, or switched off'],
    ], (float) max(count($paid), 1));
    $capiCard = ui_card('Purchases reported to Meta', $capi, ['size' => 'third', 'sub' => 'From the server, so ad blockers can\'t hide them']);

    $campaignCard = ui_card('Sales by campaign', $table($campaigns, 'No bookings in this period.'), ['size' => 'two-thirds', 'flush' => true, 'sub' => 'From the utm_campaign tag on the ad link']);
    $adCard = ui_card('Sales by ad', $table($ads, 'No bookings in this period.'), ['wide' => true, 'flush' => true, 'sub' => 'From the utm_content tag on the ad link']);

    return ['body' => $body . ui_grid($spendCard, $campaignCard, $capiCard, $adCard)];
};
