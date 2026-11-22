<?php
/**
 * WhatsApp > Broadcasts: one approved template to a group, always tested first. States:
 *   the list (default)    every broadcast with its results;
 *   a new one (?b=new)    step 1 only: the name and the template;
 *   a draft (?b=ID)       the four steps: message, who (with the preview), test, send;
 *   a sent one (?b=ID)    the results, the reasons, the people, cancel or resend.
 * ?ask=delete and ?ask=cancel ask first. Every view opens with one row of four KPIs.
 */
require_once dirname(__DIR__) . '/lib/broadcasts.php';

return static function (array $d): array {
    $pdo = $d['pdo'];
    $config = $d['config'];
    $now = $d['now'];
    $listUrl = admin_url(['view' => 'broadcasts']);
    $raw = (string) ($_GET['b'] ?? '');

    // ---------- A new broadcast ----------
    if ($raw === 'new') {
        return [
            'title' => 'New broadcast',
            'intro' => 'Pick the template first. Then choose who gets it, test it and send it.',
            'back'  => ['Broadcasts', $listUrl],
            'body'  => ui_kpis([
                ['label' => 'People', 'value' => '<span class="muted">—</span>', 'note' => 'Choose who in step 2'],
                ['label' => 'Sent', 'value' => '<span class="muted">—</span>', 'note' => 'Not sent yet'],
                ['label' => 'Read', 'value' => '<span class="muted">—</span>', 'note' => 'Shows once sent'],
                ['label' => 'Daily limit', 'value' => h(num(tg_wa_daily_cap($config))), 'note' => 'People a day, set in Settings'],
            ]) . ui_grid(admin_bc_step_message($pdo, null), ui_card('How it works',
                '<ol class="steps-list"><li>Message: an approved template, filled in for each person.</li>'
                . '<li>Who: buyers, add-on buyers, checkouts not paid, people who messaged you, tags. You see the count, real names and the cost.</li>'
                . '<li>Test: it goes to your own phone first. Any change needs a new test.</li>'
                . '<li>Send: now or at a time, after you type the number of people. It goes in batches within your daily limit.</li></ol>'
                . '<p class="muted small">Only people who agreed to booking updates or messaged you can get it. Never anyone who said STOP.</p>',
                ['size' => 'half'])),
        ];
    }

    // ---------- One broadcast ----------
    if (ctype_digit($raw) && (int) $raw > 0) {
        $b = tg_bc_get($pdo, (int) $raw);
        if ($b === null) {
            return ['title' => 'Not found', 'back' => ['Broadcasts', $listUrl],
                'body' => admin_bc_list_kpis($pdo, $now) . ui_grid(ui_card('', ui_empty('This broadcast is not here.', 'It may have been deleted.'), ['size' => 'full']))];
        }
        $here = ['view' => 'broadcasts', 'b' => $b['id']];
        $t = tg_wat_get($pdo, $b['template_id']);
        $intro = 'Template ' . ($t['name'] ?? 'gone') . ' · ' . tg_bc_audience_text($b['audience']);

        if ($b['status'] === 'draft') {
            $plan = tg_bc_people($pdo, $config, $b, $now);
            $cap = tg_wa_cap_state($pdo, $config, $now);
            $days = $plan !== null ? tg_bc_day_plan(count($plan['people']), $cap['cap'], $cap['room']) : [];
            $confirm = '';
            if (($_GET['ask'] ?? '') === 'delete') {
                $confirm = ui_confirm('Delete the draft ' . $b['name'] . '?', '<p>Nothing was sent from it, so nothing else changes.</p>',
                    ui_post('bc_delete', ['b' => $b['id'], 'confirm' => '1'], '', 'Delete draft', 'primary'), admin_url($here));
            }
            $offers = $plan !== null && $plan['category'] === 'MARKETING' ? admin_bc_offers_note($pdo) : null;
            return [
                'title'   => $b['name'],
                'intro'   => 'Draft · ' . $intro,
                'back'    => ['Broadcasts', $listUrl],
                'actions' => ui_button('Delete draft', admin_url($here + ['ask' => 'delete']) . '#confirm', 'quiet'),
                'body'    => admin_bc_kpis($b, $plan, []) . ui_grid(...array_filter([
                    $confirm,
                    $offers !== null ? ui_card('Before you send marketing', ui_checks([['level' => 'info', 'title' => 'The offers box', 'detail' => $offers]]), ['size' => 'full']) : '',
                    admin_bc_step_message($pdo, $b),
                    admin_bc_step_who($pdo, $config, $b),
                    admin_bc_preview($config, $b, $plan, $cap, $days),
                    admin_bc_step_test($config, $b, $plan),
                    admin_bc_step_send($config, $b, $plan, $days, $now),
                ])),
            ];
        }

        $s = admin_bc_stats($pdo, $b['id'])[$b['id']] ?? ['total' => 0, 'sent' => 0, 'delivered' => 0, 'seen' => 0, 'replied' => 0, 'failed' => 0,
            'not_sent' => 0, 'waiting' => 0, 'est_paise' => 0];
        $confirm = '';
        if (($_GET['ask'] ?? '') === 'cancel' && in_array($b['status'], ['scheduled', 'sending'], true)) {
            $confirm = ui_confirm('Cancel the rest of ' . $b['name'] . '?',
                '<p>' . h(num($s['waiting'])) . ' ' . ($s['waiting'] === 1 ? 'person' : 'people') . ' still waiting will not get it. Messages already sent stay sent.</p>',
                ui_post('bc_cancel', ['b' => $b['id'], 'confirm' => '1'], '', 'Cancel the rest', 'primary'), admin_url($here));
        }
        // The message as the first person got it, while their values are kept (30 days), else with the examples.
        $first = row($pdo, "SELECT params FROM outbox WHERE source = 'broadcast' AND source_id = ? AND params IS NOT NULL ORDER BY id LIMIT 1", [$b['id']]);
        $values = $first ? json_decode((string) $first['params'], true) : null;
        $bubble = $t !== null ? ui_wa_bubble(tg_wat_render($t, is_array($values) && is_array($values['body'] ?? null)
            ? ['body' => $values['body'], 'buttons' => (array) ($values['buttons'] ?? [])] : tg_wat_sample_values($t))) : ui_empty('The template is gone.');
        $dayRows = [];
        foreach (rows($pdo, "SELECT DATE(due_at) AS d, COUNT(*) AS n, COALESCE(SUM(status = 'sent'), 0) AS sent FROM outbox
            WHERE source = 'broadcast' AND source_id = ? GROUP BY DATE(due_at) ORDER BY d", [$b['id']]) as $r) {
            $dayRows[] = [h(when($r['d'] . ' 00:00:00', 'D j M')), h(num((int) $r['n'])), h(num((int) $r['sent']))];
        }
        return [
            'title' => $b['name'],
            'intro' => ucfirst($b['status']) . ' · ' . $intro,
            'back'  => ['Broadcasts', $listUrl],
            'body'  => admin_bc_kpis($b, null, $s) . ui_grid(...array_filter([
                $confirm,
                admin_bc_results($pdo, $b, $s, $now),
                ui_card('Message', $bubble . ui_facts([
                    ['Status', admin_bc_status_pill((string) $b['status'])],
                    ['Starts', h(when($b['send_at']))],
                    ['Confirmed', h(when($b['confirmed_at']))],
                    ['Tested', h(when($b['test_at'])) . ($b['test_to'] ? ' <span class="muted">to ' . h(tg_wa_pretty((string) $b['test_to'])) . '</span>' : '')],
                ]) . ui_table([['Day', ''], ['Planned', 'num'], ['Sent', 'num']], $dayRows), ['size' => 'half', 'id' => 'message', 'sub' => 'As the first person got it.']),
                admin_bc_reasons($pdo, $b['id']),
                admin_bc_people_table($pdo, $b['id'], $now),
            ])),
        ];
    }

    // ---------- Every broadcast ----------
    $offers = admin_bc_offers_note($pdo);
    $cap = tg_wa_cap_state($pdo, $config, $now);
    $limit = ui_card('Daily limit', ui_facts([
        ['Limit', h(num($cap['cap'])) . ' people in any 24 hours <a class="row-link" href="' . h(admin_url(['view' => 'settings'])) . '#whatsapp">Change in Settings</a>'],
        ['Reached in the last 24 hours', h(num(count($cap['used'])))],
        ['Kept for booking messages', h(num($cap['reserve']))],
        ['Room for broadcasts now', h(num($cap['room']))],
    ]) . '<p class="muted small">Every template counts, booking messages too. Booking messages always go; broadcasts wait for room. '
        . 'Marketing never goes between 9 PM and 9 AM.</p>', ['size' => 'half', 'id' => 'limit']);
    $note = $offers !== null ? ui_card('Before you send marketing', ui_checks([['level' => 'info', 'title' => 'The offers box', 'detail' => $offers]]), ['size' => 'half']) : '';
    return [
        'actions' => ui_button('New broadcast', admin_url(['view' => 'broadcasts', 'b' => 'new']), 'primary', 'send'),
        'body'    => admin_bc_list_kpis($pdo, $now) . ui_grid(...array_filter([admin_bc_list($pdo, $now), $limit, $note])),
    ];
};
