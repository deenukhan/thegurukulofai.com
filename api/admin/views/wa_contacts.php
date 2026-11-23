<?php
/**
 * WhatsApp > Contacts: everyone you can reach on WhatsApp, one row per number, with tags.
 * Buyers and leads come from the booking form; WhatsApp-only numbers from the inbox.
 * Search and chips narrow the list; 100 rows a page (&p=N). Tick rows to add or remove a
 * tag for them (one page at a time). Tags added here never start a "tag is added" rule.
 * "Download CSV" gives the current chip and search.
 */
require_once dirname(__DIR__) . '/lib/wa_contacts.php';

return static function (array $d): array {
    $pdo = $d['pdo'];
    $config = $d['config'];
    $chips = admin_wac_chips($pdo);
    $raw = (string) ($_GET['show'] ?? '');
    $show = array_key_exists($raw, $chips) || preg_match('/^t:[a-z0-9][a-z0-9-]{0,29}$/', $raw) ? $raw : '';
    if ($show !== '' && !isset($chips[$show])) {
        $chips[$show] = '#' . substr($show, 2); // a tag no one has any more still shows as chosen
    }
    $q = rp_clean($_GET['q'] ?? '', 80);
    $filter = ['show' => $show, 'q' => $q];
    $base = ['view' => 'wa_contacts', 'show' => $show, 'q' => $q];

    if (($_GET['export'] ?? '') === 'csv') {
        admin_wac_export($pdo, $config, $filter);
    }

    $list = admin_wac_list($pdo, $config, $filter);
    $total = count($list);
    $pages = max(1, (int) ceil($total / ADMIN_WAC_PAGE));
    $page = ctype_digit((string) ($_GET['p'] ?? '')) ? max(1, min($pages, (int) $_GET['p'])) : 1;
    $slice = array_slice($list, ($page - 1) * ADMIN_WAC_PAGE, ADMIN_WAC_PAGE);

    $types = ['buyer' => ['Buyer', 'good'], 'lead' => ['Lead', 'neutral'], 'whatsapp' => ['WhatsApp only', 'info']];
    $table = [];
    foreach ($slice as $r) {
        $name = $r['name'] !== '' ? $r['name'] : tg_wa_pretty($r['phone']);
        $href = $r['wa_contact_id'] !== null ? admin_url(['view' => 'inbox', 'c' => $r['wa_contact_id']])
            : ($r['contact_id'] !== null ? admin_url(['view' => 'people', 'id' => $r['contact_id']]) : '');
        $tags = '';
        foreach ($r['tags'] as $t) {
            $tags .= ui_pill($t, 'info');
        }
        $table[] = [
            ui_tick('phones[]', $r['phone'], 'Tick ' . $name),
            ($href !== '' ? '<a class="person" href="' . h($href) . '">' . h($name) . '</a>' : h($name))
                . '<small class="sub">' . h(tg_wa_pretty($r['phone'])) . ($r['email'] !== '' ? ' · ' . h($r['email']) : '') . '</small>',
            ui_pill($types[$r['type']][0], $types[$r['type']][1]),
            $tags !== '' ? '<span class="tag-cell">' . $tags . '</span>' : '<span class="muted">—</span>',
            $r['last_msg_at'] !== null ? h(ago(tg_wa_ts((string) $r['last_msg_at']), $d['now'])) : '<span class="muted">None yet</span>',
            $r['consent'] !== null ? h($r['consent']) . ($r['messaged'] ? '<small class="sub">and messaged you</small>' : '')
                : ($r['messaged'] ? 'Messaged you' : '<span class="muted">No</span>'),
            $r['stop'] ? ui_pill('Said STOP', 'warn') : ($r['marketing_off'] ? ui_pill('Marketing off', 'neutral') : '<span class="muted">—</span>'),
        ];
    }

    $tools = '<div class="toolbar">' . ui_chips($chips, $show, 'show', array_diff_key($base, ['show' => 1]))
        . ui_search($q, array_diff_key($base, ['q' => 1]), 'Search name, number or email') . '</div>';
    $from = $total === 0 ? 0 : ($page - 1) * ADMIN_WAC_PAGE + 1;
    $shown = $total === 0 ? 'Nobody matches.' : 'Showing ' . num($from) . ' to ' . num($from + count($slice) - 1) . ' of ' . num($total) . '.';
    $tableHtml = ui_table([['', 'tick-col'], ['Person', ''], ['Type', ''], ['Tags', ''], ['Last message', 'muted nowrap'], ['Consent', ''], ['STOP', '']],
        $table, ['empty' => 'Nobody matches.']);
    $tagRules = admin_wac_tag_rules($pdo);
    $form = $slice
        ? ui_form('wac_tag', ['#' => 'contacts'], $tableHtml . '<div class="bulk">'
            . ui_field('Tag', 'tag', 'maxlength="30" required pattern="[A-Za-z0-9][A-Za-z0-9-]{0,29}" placeholder="for-example-vip" autocomplete="off"',
                'Letters, numbers and hyphens. For the people you ticked on this page. Tags added here never send a message: '
                . ($tagRules !== [] ? 'the rule' . (count($tagRules) === 1 ? ' ' : 's ') . implode(', ', $tagRules) . ' start' . (count($tagRules) === 1 ? 's' : '')
                    . ' only when you add the tag in someone\'s chat.' : '"tag is added" rules start only when you add the tag in someone\'s chat.')) . '</div>',
            [['Add tag to ticked', 'op', 'add', 'primary'], ['Remove tag from ticked', 'op', 'remove', 'quiet']], 'bulk-form')
        : $tableHtml;

    $body = admin_wac_kpis($pdo, $config) . ui_grid(ui_card('Contacts', $tools . $form
        . ui_pager($page, $pages, array_filter($base, static fn($v) => $v !== ''), 'contacts'),
        ['size' => 'full', 'flush' => true, 'id' => 'contacts', 'sub' => $shown . ' Open a name for the conversation, or their booking history.']));

    return [
        'body'    => $body,
        'actions' => ui_button('Download CSV', admin_url($base + ['export' => 'csv']), 'quiet', 'download'),
    ];
};
