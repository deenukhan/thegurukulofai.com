<?php
/**
 * WhatsApp > Overview: how WhatsApp is doing in the chosen period. Conversations, the
 * spend estimate (our own log times Deenu's rates, never Meta's invoice), Meta's quality
 * rating and messaging limit, messages per day, the best and weakest templates (read and
 * reply rates, at least 10 sends), the connection and the WhatsApp health checks.
 */
require_once dirname(__DIR__) . '/lib/wa_contacts.php';

return static function (array $d): array {
    $pdo = $d['pdo'];
    $config = $d['config'];
    $now = $d['now'];
    $range = $d['range'];
    $data = admin_wa_overview_data($pdo, $config, $range, $now);
    $meta = tg_wa_ready($config) ? admin_wa_meta($config) : null;
    $phone = !empty($meta['ok']) ? $meta['phone'] : [];

    // Small sums read better with paise: ₹12.90, not ₹13.
    $rupees = static fn(int $paise): string => $paise < 100000 ? '₹' . number_format($paise / 100, 2) : money($paise);
    $by = $data['by_category'];
    [$tier] = admin_wa_tier($config, $phone);
    $people = $tier !== '' ? tg_wa_tier_people($tier) : 0;
    $rating = strtoupper((string) ($phone['quality_rating'] ?? ''));
    $period = strtolower($range['label']);

    $body = ui_kpis([
        ['label' => 'Conversations', 'value' => h(num($data['conversations'])),
         'note' => num($data['in']) . ' in · ' . num($data['out']) . ' out, ' . ($range['days'] === null ? 'all time' : ($range['key'] === 'today' ? 'today' : 'last ' . $period))],
        ['label' => 'Estimated spend', 'value' => h($rupees($data['spend'])),
         'note' => 'Estimate: marketing ' . $rupees($by['MARKETING'][1]) . ' · utility ' . $rupees($by['UTILITY'][1])],
        ['label' => 'Quality', 'value' => $meta === null ? ui_pill('Not set up', 'neutral') : (empty($meta['ok']) ? ui_pill('Not read', 'neutral') : admin_wa_quality_pill($rating)),
         'tone' => in_array($rating, ['RED', 'LOW'], true) ? 'bad' : ($rating === 'YELLOW' ? 'warn' : null),
         'note' => in_array($rating, ['RED', 'LOW'], true) ? 'Low: Meta may limit sending' : 'Meta\'s rating of the number'],
        ['label' => 'Messaging limit', 'value' => $tier !== '' ? h(tg_wa_tier_text($tier)) : '<span class="muted">Not known</span>',
         'tone' => $people !== null && $people > 0 && $data['started'] >= $people * 0.8 ? 'warn' : null,
         'note' => num($data['started']) . ' started in the last 24 hours · your daily limit ' . num($data['cap'])],
    ]);

    // ---- Messages per day, and spend by category ----
    $chart = ui_card('Messages per day', ui_columns($data['days'], static fn(float $v): string => num((int) round($v))),
        ['size' => 'two-thirds', 'sub' => 'In and out together. Hover a day for each.']);
    $bars = [];
    foreach (['MARKETING' => 'Marketing', 'UTILITY' => 'Utility'] as $cat => $label) {
        $bars[] = ['label' => $label, 'value' => $by[$cat][1], 'display' => $rupees($by[$cat][1]),
            'sub' => num($by[$cat][0]) . ' charged message' . ($by[$cat][0] === 1 ? '' : 's') . ' at about ' . $rupees(tg_wa_rate($cat))];
    }
    $spend = ui_card('Spend by category', ($data['spend'] > 0 ? ui_bars($bars) : ui_empty('Nothing charged in this period.', 'Replies and utility templates inside the 24-hour window are free.'))
        . '<p class="notice">' . icon('info') . 'An estimate from your own message log. Meta bills on delivery, so failed sends cost nothing. Meta\'s invoice is the real figure.</p>',
        ['size' => 'third']);

    // ---- Best and weakest templates ----
    $quality = [];
    foreach ((array) ($meta['templates'] ?? []) as $name => $s) {
        $quality[(string) $name] = (string) ($s['quality'] ?? 'UNKNOWN');
    }
    $ranked = $data['templates'];
    $half = min(5, (int) ceil(count($ranked) / 2));
    $best = array_slice($ranked, 0, $half);
    $weak = array_slice(array_reverse(array_slice($ranked, $half)), 0, 5);
    $few = $data['unranked'] > 0 ? ' ' . $data['unranked'] . ' more had fewer than ' . ADMIN_WA_RANK_MIN . ' sends.' : '';
    $bestCard = ui_card('Best templates', admin_wa_rank_table($best, $quality, 'No template had ' . ADMIN_WA_RANK_MIN . ' sends in this period yet.'),
        ['flush' => true, 'id' => 'best', 'sub' => 'Highest read rate, then reply rate. At least ' . ADMIN_WA_RANK_MIN . ' sends.' . $few]);
    $weakCard = ui_card('Weakest templates', admin_wa_rank_table($weak, $quality, count($ranked) === 1
            ? 'Only one template had enough sends to compare.' : 'No template had ' . ADMIN_WA_RANK_MIN . ' sends in this period yet.'),
        ['flush' => true, 'id' => 'weakest', 'sub' => 'Lowest read rate first. Reword or send these to fewer people.']);

    // ---- Connection and health ----
    $checks = [];
    admin_wa_checks($pdo, $config, $now, static function (string $level, string $area, string $title, string $detail = '', string $view = '') use (&$checks): void {
        $checks[] = compact('level', 'area', 'title', 'detail', 'view');
    });
    $health = ui_card('WhatsApp health', ui_checks($checks), ['id' => 'wa-health', 'sub' => 'The same checks as System health.']);

    return [
        'title' => 'WhatsApp overview',
        'body'  => $body . ui_grid($chart, $spend, $bestCard, $weakCard, admin_wa_connection_card($config, $now, $meta), $health),
    ];
};
