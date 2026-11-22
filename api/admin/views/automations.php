<?php
/**
 * WhatsApp > Automations: messages that send themselves when something happens. States:
 *   the list (default)  the built-in messages (locked), your rules, the ready-made rules
 *                       and a form for a new rule;
 *   one rule (?r=ID)    its form, what it does with its switch and test, why some did not
 *                       get it, and its latest messages.
 * ?ask=delete asks first. Every view opens with one row of four KPIs.
 */
require_once dirname(__DIR__) . '/lib/automations.php';

return static function (array $d): array {
    $pdo = $d['pdo'];
    $config = $d['config'];
    $now = $d['now'];
    $listUrl = admin_url(['view' => 'automations']);
    $raw = (string) ($_GET['r'] ?? '');

    // ---------- One rule ----------
    if ($raw !== '') {
        $r = ctype_digit($raw) ? tg_rule_get($pdo, (int) $raw) : null;
        if ($r === null) {
            return ['title' => 'Not found', 'back' => ['Automations', $listUrl],
                'body' => admin_rule_list_kpis($pdo, $now) . ui_grid(ui_card('', ui_empty('This rule is not here.', 'It may have been deleted.'), ['size' => 'full']))];
        }
        $here = ['view' => 'automations', 'r' => $r['id']];
        $s = admin_rule_stats($pdo, $now, $r['id'])[$r['id']] ?? admin_rule_no_stats();
        $confirm = '';
        if (($_GET['ask'] ?? '') === 'delete' && $r['preset'] === null) {
            $confirm = ui_confirm('Delete the rule ' . $r['name'] . '?',
                '<p>' . ($s['waiting'] > 0 ? h(num($s['waiting'])) . ' waiting message' . ($s['waiting'] === 1 ? '' : 's') . ' will be cancelled. ' : '')
                . 'Messages already sent stay sent.</p>',
                ui_post('rule_delete', ['r' => $r['id'], 'confirm' => '1'], '', 'Delete rule', 'primary'), admin_url($here));
        }
        return [
            'title'   => $r['name'],
            'intro'   => ($r['enabled'] ? 'On' : 'Off') . ' · ' . tg_rule_trigger_text($r),
            'back'    => ['Automations', $listUrl],
            'actions' => $r['preset'] === null ? ui_button('Delete rule', admin_url($here + ['ask' => 'delete']) . '#confirm', 'quiet') : ui_pill('Ready-made', 'info'),
            'body'    => admin_rule_kpis($r, $s) . ui_grid(...array_filter([
                $confirm,
                admin_rule_form($pdo, $config, $r),
                admin_rule_summary($pdo, $config, $r, $now),
                admin_rule_reasons($pdo, $r['id']),
                admin_rule_people($pdo, $r['id']),
            ])),
        ];
    }

    // ---------- Every rule ----------
    return [
        'body' => admin_rule_list_kpis($pdo, $now) . ui_grid(
            admin_rule_builtin($pdo, $config, $now),
            admin_rule_list($pdo, $config, $now),
            admin_rule_presets($pdo),
            admin_rule_form($pdo, $config, null)
        ),
    ];
};
