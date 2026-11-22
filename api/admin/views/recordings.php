<?php
/**
 * Recordings: one row per class, with the link, passcode and a ready-to-paste
 * message for WhatsApp. Only signed-in admins see this page.
 */
require_once dirname(__DIR__) . '/lib/recordings.php';

return static function (array $d): array {
    $pdo = $d['pdo'];
    $config = $d['config'];
    $recs = admin_recordings($pdo);

    // ---------- Delete asks first ----------
    $confirm = '';
    $deleteId = (int) ($_GET['delete'] ?? 0);
    foreach ($recs as $r) {
        if ((int) $r['id'] === $deleteId) {
            $confirm = ui_card('Delete the recording for ' . date('j M Y', strtotime($r['session_date'])) . '?',
                '<p>The link and passcode leave this dashboard. The recording itself stays on Zoom. This cannot be undone.</p>'
                . '<div class="confirm-actions">' . ui_post('recording_delete', ['id' => $r['id'], '#' => 'list'], ui_code_input(), 'Delete it', 'primary', 'inline')
                . ui_button('Cancel', admin_url(['view' => 'recordings']) . '#r' . (int) $r['id'], 'quiet') . '</div>',
                ['wide' => true, 'id' => 'confirm']);
        }
    }

    // ---------- The list ----------
    $list = [];
    foreach ($recs as $r) {
        $id = (int) $r['id'];
        $date = date('D j M Y', strtotime($r['session_date']));
        $share = 'Recording: ' . $r['url'] . ($r['passcode'] !== '' ? ' · Passcode: ' . $r['passcode'] : '');
        $list[] = [
            '<span class="anchor" id="r' . $id . '"></span><strong>' . h($date) . '</strong>'
                . ($r['title'] !== '' ? '<small class="sub">' . h($r['title']) . '</small>' : '')
                . ($r['note'] !== '' ? '<small class="sub">' . h($r['note']) . '</small>' : '')
                . '<a class="row-link" href="' . h(admin_url(['view' => 'recordings', 'delete' => $id])) . '#confirm">Delete</a>',
            '<a class="row-link" href="' . h($r['url']) . '" target="_blank" rel="noopener noreferrer">Open recording</a>'
                . ui_copy($r['url'])
                . ($r['passcode'] !== '' ? '<small class="sub">Passcode</small>' . ui_copy($r['passcode']) : '<small class="sub">No passcode</small>'),
            ui_copy($share),
        ];
    }
    $cols = [['Class', 'nowrap'], ['Link and passcode', ''], ['Message to paste', '']];
    $table = ui_card('Your recordings', '<div class="rec-list">' . ui_table($cols, $list, ['empty' => 'No recordings yet. Add the first one below.']) . '</div>',
        ['wide' => true, 'flush' => true, 'id' => 'list', 'sub' => 'Click a box once to select all of it, then copy.']);

    // ---------- Add one ----------
    $today = (new DateTimeImmutable('@' . rp_now($config)))->setTimezone(ist());
    $lastSunday = $today->format('w') === '0' ? $today : $today->modify('last sunday');
    $form = '<div class="fields">'
        . ui_field('Class date', 'session_date', 'type="date" required value="' . h($lastSunday->format('Y-m-d')) . '"')
        . ui_field('Title (optional)', 'title', 'maxlength="120" placeholder="Sunday masterclass" autocomplete="off"')
        . '</div>'
        . ui_field('Recording link', 'url', 'type="url" required maxlength="600" placeholder="https://zoom.us/rec/share/..." autocomplete="off" spellcheck="false"')
        . '<div class="fields">'
        . ui_field('Passcode (optional)', 'passcode', 'maxlength="80" autocomplete="off" spellcheck="false"')
        . ui_field('Note for you (optional)', 'note', 'maxlength="200" placeholder="Shared in the community on 22 Nov" autocomplete="off"')
        . '</div>'
        . ui_code_input();
    $add = ui_card('Add a recording', ui_post('recording_add', ['#' => 'add'], $form, 'Save recording', 'primary', 'make'),
        ['id' => 'add', 'size' => 'two-thirds', 'sub' => 'Paste the share link and passcode from Zoom after each class.']);

    $how = ui_card('Good to know', ui_facts([
        ['Who sees this', 'Only you, after signing in'],
        ['Stored in', 'The database, never in the website code'],
        ['Zoom keeps', 'The recording itself. Deleting here only removes the link.'],
    ]), ['size' => 'third']);

    return [
        'body' => ui_grid($confirm, $table, $add, $how),
    ];
};
