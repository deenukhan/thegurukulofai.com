<?php
/**
 * WhatsApp > Templates: write your own templates, check them against Meta's rules, submit
 * them and see Meta's answer. Two states:
 *   the list (default)  your templates, a new-template form, and the built-in ones (locked);
 *   one template (?edit=ID)  the draft's form with its preview and checks, or, once it is
 *                       with Meta, its status and a way to duplicate it.
 * ?delete=ID asks first. Meta's template list is read at most every 10 minutes when the
 * page opens; "Refresh from Meta" reads it at once.
 */
require_once dirname(__DIR__) . '/lib/wa_templates.php';

return static function (array $d): array {
    $pdo = $d['pdo'];
    $config = $d['config'];
    $now = $d['now'];
    $listUrl = admin_url(['view' => 'wa_templates']);

    admin_wat_auto_sync($pdo, $config, $now);
    $metaNames = admin_wat_meta_names($config);
    $kpis = admin_wat_kpis($pdo);
    $refresh = tg_wa_ready($config) ? ui_post('wat_sync', [], '', 'Refresh from Meta', 'quiet', 'inline') : '';

    // ---------- One template ----------
    $edit = ctype_digit((string) ($_GET['edit'] ?? '')) ? (int) $_GET['edit'] : 0;
    if ($edit > 0) {
        $t = tg_wat_get($pdo, $edit);
        if ($t === null || $t['status'] === 'DELETED') {
            return ['title' => 'Not found', 'back' => ['Templates', $listUrl],
                'body' => $kpis . ui_grid(ui_card('', ui_empty('This template is not here.', 'It may have been deleted.'), ['size' => 'full']))];
        }
        $cards = $t['status'] === 'DRAFT'
            ? [admin_wat_form($config, $t), admin_wat_preview($pdo, $config, $t, $metaNames, $now)]
            : [ui_card('Message', ui_wa_bubble(tg_wat_render($t, tg_wat_sample_values($t))), ['size' => 'two-thirds', 'sub' => 'As Meta approved it, with your examples.']),
               admin_wat_preview($pdo, $config, $t, $metaNames, $now)];
        return [
            'title'   => $t['name'],
            'intro'   => ucfirst(strtolower((string) $t['category'])) . ' template · ' . ($t['status'] === 'DRAFT' ? 'draft' : 'with Meta'),
            'back'    => ['Templates', $listUrl],
            'actions' => $refresh,
            'body'    => $kpis . ui_grid(...$cards),
        ];
    }

    // ---------- Every template ----------
    $delete = ctype_digit((string) ($_GET['delete'] ?? '')) ? admin_wat_confirm_delete($pdo, (int) $_GET['delete']) : '';
    return [
        'actions' => $refresh,
        'body'    => $kpis . ui_grid(...array_filter([
            $delete,
            admin_wat_list($pdo, $config, $now, $metaNames),
            admin_wat_form($config, null),
            admin_wat_preview($pdo, $config, null, $metaNames, $now),
            admin_wat_system($config),
        ])),
    ];
};
