<?php
/** Activity log: the audit table, newest first, 100 to a page. */
return static function (array $d): array {
    $pdo = $d['pdo'];
    $who = in_array($_GET['who'] ?? '', ['admin', 'reconcile', 'system'], true) ? (string) $_GET['who'] : '';
    $page = max(1, (int) ($_GET['p'] ?? 1));
    $per = 100;

    $where = $who === '' ? '1 = 1' : 'actor = ?';
    $args = $who === '' ? [] : [$who];
    $total = (int) value($pdo, "SELECT COUNT(*) FROM audit WHERE {$where}", $args);
    $list = rows($pdo, "SELECT at, actor, action, detail, ip FROM audit WHERE {$where} ORDER BY id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per), $args);

    $names = [
        'login'        => ['Signed in', 'good'],
        'login_failed' => ['Wrong password', 'bad'],
        'logout'       => ['Signed out', 'neutral'],
        'export_csv'   => ['Downloaded a CSV', 'info'],
        'run'          => ['Nightly Razorpay check', 'neutral'],
        'code_failed'  => ['Wrong code', 'bad'],
        'mfa_setup'    => ['Two-step sign-in set up', 'good'],
        'setting_changed'  => ['Changed a setting', 'warn'],
        'setting_reset'    => ['Setting back to config', 'warn'],
        'backup_codes_new' => ['New backup codes', 'warn'],
        'addon_sent'     => ['Add-on marked sent', 'info'],
        'addon_unsent'   => ['Add-on unmarked', 'info'],
        'resend_confirm' => ['Resent confirmation', 'info'],
        'resend_failed'  => ['Resend failed', 'bad'],
    ];
    $actors = ['admin' => 'You', 'reconcile' => 'Nightly check', 'system' => 'System'];
    $table = [];
    foreach ($list as $a) {
        [$label, $tone] = $names[$a['action']] ?? [ucfirst(str_replace('_', ' ', $a['action'])), 'neutral'];
        $table[] = [
            h(when($a['at'], 'j M Y, g:i A')),
            h($actors[$a['actor']] ?? $a['actor']),
            ui_pill($label, $tone),
            '<span class="log">' . h($a['detail']) . '</span>',
            '<span class="muted">' . h((string) $a['ip']) . '</span>',
        ];
    }

    $pages = (int) max(1, ceil($total / $per));
    $pager = '';
    if ($pages > 1) {
        $pager = '<nav class="pager" aria-label="Pages">'
            . ($page > 1 ? ui_button('Newer', admin_url(['view' => 'activity', 'who' => $who, 'p' => $page - 1 > 1 ? $page - 1 : '']) . '#f-who', 'quiet', 'back') : '<span></span>')
            . '<span class="muted">Page ' . $page . ' of ' . $pages . '</span>'
            . ($page < $pages ? ui_button('Older', admin_url(['view' => 'activity', 'who' => $who, 'p' => $page + 1]) . '#f-who', 'quiet', 'arrow') : '<span></span>')
            . '</nav>';
    }

    $body = ui_grid(ui_card('Everything that happened', ui_chips(['' => 'Everything', 'admin' => 'You', 'reconcile' => 'Nightly check', 'system' => 'System'],
        $who, 'who', ['view' => 'activity'])
        . ui_table([['When', 'muted nowrap'], ['Who', ''], ['What', ''], ['Details', ''], ['Address', 'muted']], $table, ['empty' => 'Nothing logged yet.'])
        . $pager, ['wide' => true, 'flush' => true, 'sub' => num($total) . ' entries']));
    return ['body' => $body];
};
