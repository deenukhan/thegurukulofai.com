<?php
/**
 * The Templates page (views/wa_templates.php): write WhatsApp templates, check them
 * against Meta's rules, submit them, and follow Meta's answer. The rules and the calls
 * to Meta live in api/razorpay/wa_custom.php; this file is the dashboard's side.
 *
 * Every change is a POST through lib/actions.php into admin_wat_action: page token,
 * audit, message, back. The built-in booking templates (tg_wa_templates) are shown read
 * only and are never rows in wa_templates, so nothing here can change or delete them.
 */

/** The page reads Meta's template list at most this often (a press on "Refresh from Meta" reads it at once). */
const ADMIN_WAT_SYNC = 600;

function admin_wat_sync_key(array $config): string
{
    return 'wat-sync:' . (string) ($config['whatsapp']['waba_id'] ?? '');
}

/** Forgets when Meta was last read, so the next page reads it again. */
function admin_wat_forget(array $config): void
{
    @unlink(admin_private($config) . '/razorpay-dash-cache/' . hash('sha256', admin_wat_sync_key($config)) . '.json');
}

/** Notes that Meta was just read (by "Refresh from Meta"), so the page after it doesn't read it again. */
function admin_wat_synced(array $config, int $changed): void
{
    $dir = admin_private($config) . '/razorpay-dash-cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    @file_put_contents($dir . '/' . hash('sha256', admin_wat_sync_key($config)) . '.json',
        json_encode(['at' => time(), 'ok' => true, 'changed' => $changed]), LOCK_EX);
}

/** Brings the templates up to date with Meta when the page opens, at most every 10 minutes. */
function admin_wat_auto_sync(PDO $pdo, array $config, int $now): ?array
{
    if (!tg_wa_ready($config)) {
        return null;
    }
    return admin_cached($config, admin_wat_sync_key($config), ADMIN_WAT_SYNC, static function () use ($pdo, $config, $now): array {
        $n = tg_wat_sync($config, $pdo, $now);
        return ['ok' => $n !== null, 'changed' => $n];
    });
}

/** Names Meta already has in our language (cached 10 minutes), for the name check. Null when unknown. */
function admin_wat_meta_names(array $config): ?array
{
    if (!tg_wa_ready($config)) {
        return null;
    }
    $states = admin_wa_meta($config)['templates'] ?? null;
    return is_array($states) ? array_map('strval', array_keys($states)) : null;
}

/** The four numbers at the top of every Templates view. */
function admin_wat_kpis(PDO $pdo): string
{
    $k = row($pdo, "SELECT COALESCE(SUM(status = 'APPROVED'), 0) AS approved, COALESCE(SUM(status IN ('PENDING', 'IN_APPEAL')), 0) AS review,
            COALESCE(SUM(status IN ('REJECTED', 'PAUSED', 'DISABLED')), 0) AS bad, COALESCE(SUM(status = 'DRAFT'), 0) AS drafts
        FROM wa_templates WHERE status <> 'DELETED'");
    return ui_kpis([
        ['label' => 'Approved', 'value' => h(num((int) $k['approved'])), 'note' => 'Ready to send from the inbox'],
        ['label' => 'In review', 'value' => h(num((int) $k['review'])), 'note' => 'Meta usually answers within minutes'],
        ['label' => 'Rejected or paused', 'value' => h(num((int) $k['bad'])), 'tone' => (int) $k['bad'] > 0 ? 'bad' : null,
         'note' => (int) $k['bad'] > 0 ? 'See Meta\'s reason below' : 'None'],
        ['label' => 'Drafts', 'value' => h(num((int) $k['drafts'])), 'note' => 'Not sent to Meta yet'],
    ]);
}

function admin_wat_status_pill(array $t): string
{
    return match ((string) $t['status']) {
        'DRAFT'                => ui_pill('Draft', 'neutral'),
        'APPROVED'             => ui_pill('Approved', 'good'),
        'PENDING', 'IN_APPEAL' => ui_pill('In review', 'info'),
        'REJECTED'             => ui_pill('Rejected', 'bad'),
        'PAUSED', 'DISABLED'   => ui_pill(ucfirst(strtolower((string) $t['status'])), 'bad'),
        'DELETED'              => ui_pill('Deleted', 'neutral'),
        default                => ui_pill(ucfirst(strtolower(str_replace('_', ' ', (string) $t['status']))), 'warn'),
    };
}

/** Our category, plus a warning pill when Meta put it somewhere else. */
function admin_wat_category_pills(array $t): string
{
    $html = ui_pill(ucfirst(strtolower((string) $t['category'])), 'neutral');
    if (!empty($t['meta_category'])) {
        $html .= ' ' . ui_pill('Meta: ' . ucfirst(strtolower((string) $t['meta_category'])), 'warn');
    }
    return $html;
}

function admin_wat_quality_pill(?string $q): string
{
    return match ((string) $q) {
        'GREEN'  => ui_pill('High', 'good'),
        'YELLOW' => ui_pill('Medium', 'warn'),
        'RED'    => ui_pill('Low', 'bad'),
        default  => '<span class="muted">—</span>',
    };
}

/** Your templates: every one not deleted, drafts first, with what you can do to each. */
function admin_wat_list(PDO $pdo, array $config, int $now, ?array $metaNames): string
{
    $list = array_map('tg_wat_decode', rows($pdo, "SELECT * FROM wa_templates WHERE status <> 'DELETED' ORDER BY status = 'DRAFT' DESC, updated_at DESC, id DESC"));
    $sent = [];
    foreach (rows($pdo, "SELECT template, COUNT(*) AS n, COALESCE(SUM(read_at IS NOT NULL), 0) AS seen FROM wa_messages
            WHERE direction = 'out' AND kind = 'template' AND status <> 'failed' AND created_at >= ? GROUP BY template", [tg_ist($now - 30 * 86400)]) as $r) {
        $sent[(string) $r['template']] = $r;
    }
    $table = [];
    foreach ($list as $t) {
        $id = $t['id'];
        $snippet = mb_substr(preg_replace('/\s+/u', ' ', (string) $t['body']) ?? '', 0, 80);
        $status = admin_wat_status_pill($t);
        $ready = false;
        if ($t['status'] === 'DRAFT') {
            $errors = count(tg_wat_check($config, $pdo, $t, $metaNames, $now)['errors']);
            $ready = $errors === 0;
            $status .= ' ' . ($ready ? ui_pill('Ready', 'good') : ui_pill($errors . ' to fix', 'warn'));
        }
        if (!empty($t['reason'])) {
            $status .= '<small class="sub">' . h(($t['status'] === 'DRAFT' ? 'Last submit refused: ' : 'Meta says: ') . $t['reason']) . '</small>';
        }
        $s = $sent[$t['name']] ?? null;
        $actions = '<a class="row-link" href="' . h(admin_url(['view' => 'wa_templates', 'edit' => $id])) . '#edit">' . ($t['status'] === 'DRAFT' ? 'Edit' : 'Open') . '</a>';
        if ($ready) {
            $actions .= ui_post('wat_submit', ['id' => $id, '#' => 'templates'], '', 'Submit', 'link', 'inline');
        }
        $actions .= ui_post('wat_duplicate', ['id' => $id], '', 'Duplicate', 'link', 'inline')
            . '<a class="row-link" href="' . h(admin_url(['view' => 'wa_templates', 'delete' => $id])) . '#confirm">Delete</a>';
        $table[] = [
            '<span class="tpl-name">' . h($t['name']) . '</span><small class="sub clip">' . h($snippet) . '</small>',
            admin_wat_category_pills($t),
            $status,
            admin_wat_quality_pill($t['quality'] ?? null),
            $s ? h(num((int) $s['n'])) . '<small class="sub">' . h(num((int) $s['seen'])) . ' read</small>' : '<span class="muted">0</span>',
            '<div class="row-actions">' . $actions . '</div>',
        ];
    }
    $blocked = rows($pdo, "SELECT name, deleted_at FROM wa_templates WHERE status = 'DELETED' AND deleted_at >= ? ORDER BY deleted_at DESC LIMIT 20",
        [tg_ist($now - TG_WAT_NAME_BLOCK_DAYS * 86400)]);
    $note = $blocked ? 'Deleted recently, so Meta blocks these names for now: ' . implode(', ', array_map(static fn($b) => h($b['name']) . ' (until about '
        . h(tg_wat_name_free_date((string) $b['deleted_at'])) . ')', $blocked)) . '.' : '';
    return ui_card('Your templates', ui_table([['Template', ''], ['Category', ''], ['Status', ''], ['Quality', ''], ['Sent, 30 days', 'num'], ['', 'num']], $table,
        ['empty' => 'No templates of your own yet. Write one below.', 'note' => $note]),
        ['size' => 'full', 'flush' => true, 'id' => 'templates', 'sub' => 'Meta must approve each one before it can be sent. Approved wording can\'t be changed: duplicate it instead.']);
}

/** The new-template or edit-draft form. $t null is a new template. */
function admin_wat_form(array $config, ?array $t): string
{
    $lang = (string) ($config['whatsapp']['language'] ?? 'en');
    $t ??= ['id' => '', 'name' => '', 'category' => 'UTILITY', 'header_text' => '', 'body' => '', 'footer' => TG_WA_FOOTER,
        'buttons' => [], 'examples' => [], 'fields' => []];
    $new = $t['id'] === '';
    $val = static fn($v): string => 'value="' . h((string) $v) . '"';

    $inner = '<div class="fields">'
        . ui_field('Name', 'name', 'maxlength="60" required autocomplete="off" spellcheck="false" placeholder="course_update_nov" ' . $val($t['name']),
            'Lowercase letters, numbers and underscores. Only you and Meta see it.')
        . ui_select('Language', 'language', [$lang => $lang === 'en' ? 'English (en)' : $lang], $lang, 'One language for now.')
        . '</div>'
        . ui_choice('Category', 'category', ['UTILITY' => 'Utility: about something they booked or did', 'MARKETING' => 'Marketing: offers, news, invitations'],
            (string) $t['category'])
        . '<p class="muted small">Utility costs about ₹0.15 a message and is free inside an open 24-hour window. Marketing costs about ₹0.86. '
        . 'Meta moves a utility template that reads like an offer to marketing.</p>'
        . ui_field('Header (optional)', 'header', 'maxlength="60" autocomplete="off" ' . $val($t['header_text'] ?? ''),
            'A bold line on top. Up to 60 characters, plain text, no variables or emoji.')
        . ui_textarea('Message', 'body', (string) $t['body'], 'rows="8" maxlength="2000" required',
            'Up to 1,024 characters. Use {{1}}, {{2}} for the parts filled in for each person, never at the very start or end.')
        . ui_field('Footer (optional)', 'footer', 'maxlength="60" autocomplete="off" ' . $val($t['footer'] ?? ''),
            'A small grey line under the message. No variables. Keep the STOP line on marketing templates.');

    // ---- Variables: one example and one field each, for the variables in the saved message ----
    $count = tg_wat_var_count((string) $t['body']);
    if ($count > 0) {
        $inner .= '<h3 class="sub-head">Variables</h3><p class="muted small">An example of each for Meta\'s reviewers, and what fills it when you send.</p><div class="var-rows">';
        for ($n = 1; $n <= $count; $n++) {
            $inner .= '<div class="fields">'
                . ui_field('Example for {{' . $n . '}}', 'examples[' . $n . ']', 'maxlength="200" autocomplete="off" ' . $val($t['examples'][$n - 1] ?? ''))
                . ui_select('{{' . $n . '}} is filled with', 'fields[' . $n . ']', TG_WA_FIELDS, (string) ($t['fields'][$n - 1] ?? ($n === 1 ? 'first_name' : 'fixed')))
                . '</div>';
        }
        $inner .= '</div>';
    } elseif ($new) {
        $inner .= '<p class="muted small">After you save, a box for each variable\'s example shows here.</p>';
    }

    // ---- Buttons ----
    $inner .= '<h3 class="sub-head">Buttons (optional)</h3><p class="muted small">Up to 3: links (at most 2) or quick replies. '
        . 'Meta refuses links to WhatsApp itself, so link to a page on your site.</p><div class="btn-rows">';
    for ($i = 0; $i < 3; $i++) {
        $b = $t['buttons'][$i] ?? [];
        $url = (string) ($b['url'] ?? '');
        $inner .= '<fieldset class="btn-row"><legend>Button ' . ($i + 1) . '</legend><div class="fields">'
            . ui_select('Kind', 'buttons[' . $i . '][type]', ['' => 'No button', 'URL' => 'Link', 'QUICK_REPLY' => 'Quick reply'], (string) ($b['type'] ?? ''))
            . ui_field('Text on the button', 'buttons[' . $i . '][text]', 'maxlength="40" autocomplete="off" ' . $val($b['text'] ?? ''), 'Up to 25 characters.')
            . ui_field('Link', 'buttons[' . $i . '][url]', 'maxlength="2000" autocomplete="off" spellcheck="false" placeholder="https://thegurukulofai.com/..." ' . $val($url),
                'Links only. May end in {{1}}, filled in for each person.')
            . ui_field('Example link', 'buttons[' . $i . '][example]', 'maxlength="2000" autocomplete="off" spellcheck="false" ' . $val($b['example'] ?? ''),
                'Only when the link ends in {{1}}: the whole link with a sample in place of {{1}}.')
            . '</div>'
            . (str_ends_with($url, '{{1}}') ? ui_select('{{1}} in the link is filled with', 'buttons[' . $i . '][field]', TG_WA_FIELDS, (string) ($b['field'] ?? 'fixed')) : '')
            . '</fieldset>';
    }
    $inner .= '</div>';

    return ui_card($new ? 'New template' : 'Edit draft', ui_post('wat_save', ['id' => $t['id'], '#' => 'edit'], $inner, 'Save draft', 'primary', 'make'),
        ['size' => 'two-thirds', 'id' => 'edit', 'sub' => $new ? 'Saved as a draft. Nothing goes to Meta until you submit it.' : 'Save, fix anything the checks list, then submit.']);
}

/** The bubble with the examples filled in, then what Meta would refuse, and submit. */
function admin_wat_preview(PDO $pdo, array $config, ?array $t, ?array $metaNames, int $now): string
{
    if ($t === null) {
        return ui_card('Preview and checks', ui_empty('Nothing to check yet', 'Save a draft to see how it looks and what Meta would refuse.'),
            ['size' => 'third', 'id' => 'preview']);
    }
    $html = ui_wa_bubble(tg_wat_render($t, tg_wat_sample_values($t)));
    if ($t['status'] === 'DRAFT') {
        $c = tg_wat_check($config, $pdo, $t, $metaNames, $now);
        $items = [];
        foreach ($c['errors'] as $e) {
            $items[] = ['level' => 'bad', 'title' => $e, 'detail' => ''];
        }
        foreach ($c['warnings'] as $w) {
            $items[] = ['level' => 'warn', 'title' => $w, 'detail' => ''];
        }
        if (!$c['errors']) {
            array_unshift($items, ['level' => 'ok', 'title' => 'Ready to submit', 'detail' => 'Meta usually answers within minutes, sometimes a day.']);
        }
        $html .= '<h3 class="sub-head">' . ($c['errors'] ? count($c['errors']) . ' thing' . (count($c['errors']) === 1 ? '' : 's') . ' to fix before submitting' : 'Checks') . '</h3>'
            . ui_checks($items);
        if (!empty($t['reason'])) {
            $html .= '<p class="notice notice-bad">' . icon('alert') . h('Meta refused the last submit: ' . $t['reason']) . '</p>';
        }
        if (!$c['errors'] && tg_wa_ready($config)) {
            $html .= ui_post('wat_submit', ['id' => $t['id'], '#' => 'preview'],
                '<p class="muted small">Sends it to Meta for review as ' . h(strtolower((string) $t['category'])) . '. You can\'t change it after that, only duplicate it.</p>',
                'Submit to Meta', 'primary', 'stack');
        } elseif (!tg_wa_ready($config)) {
            $html .= '<p class="notice">' . icon('info') . 'WhatsApp is not set up yet, so nothing can be submitted.</p>';
        }
    } else {
        $html .= ui_facts([
            ['Status', admin_wat_status_pill($t)],
            ['Category', admin_wat_category_pills($t)],
            ['Quality', admin_wat_quality_pill($t['quality'] ?? null)],
            ['Submitted', h(when($t['submitted_at']))],
            ['Meta says', !empty($t['reason']) ? h((string) $t['reason']) : '<span class="muted">Nothing</span>'],
        ]) . '<p class="notice">' . icon('info') . 'Wording Meta has seen can\'t be changed. Duplicate it to make a new version under a new name.</p>'
            . ui_post('wat_duplicate', ['id' => $t['id']], '', 'Duplicate to edit', 'quiet', 'stack');
    }
    return ui_card('Preview and checks', $html, ['size' => 'third', 'id' => 'preview', 'sub' => 'Shown with your examples.']);
}

/** The seven booking templates in code: shown, never changed here. */
function admin_wat_system(array $config): string
{
    $states = tg_wa_ready($config) ? (admin_wa_meta($config)['templates'] ?? null) : null;
    $rows = [];
    foreach (tg_wa_templates($config) as $t) {
        $s = $states[$t['name']] ?? null;
        $rows[] = [
            h($t['label']) . '<small class="sub">' . h($t['when']) . ' · <span class="tpl-name">' . h($t['name']) . '</span></small>',
            ui_pill(ucfirst(strtolower((string) ($t['category'] ?? 'UTILITY'))), 'neutral'),
            admin_wa_state_pill($s),
        ];
    }
    return ui_card('Built-in templates', '<p class="notice">' . icon('info') . '<span>These are part of the booking flow. Change them in code. '
        . 'Submit or test them on the <a href="' . h(admin_url(['view' => 'emails', 'tab' => 'whatsapp'])) . '#wa-templates">WhatsApp tab</a>.</span></p>'
        . ui_table([['Message', ''], ['Category', ''], ['Status', '']], $rows),
        ['size' => 'full', 'id' => 'built-in', 'sub' => 'Locked: the seat confirmation, the reminders, the checkout coupon and the inbox follow-up.']);
}

/** The ask-first card for deleting a template, or ''. */
function admin_wat_confirm_delete(PDO $pdo, int $id): string
{
    $t = tg_wat_get($pdo, $id);
    if ($t === null || $t['status'] === 'DELETED') {
        return '';
    }
    $text = $t['status'] === 'DRAFT'
        ? '<p>This draft is removed. It never went to Meta, so its name is free again at once.</p>'
        : '<p>Meta deletes it too, and it can\'t be sent any more. Messages already sent stay. Meta blocks the name ' . h($t['name']) . ' for about 30 days.</p>';
    return ui_confirm('Delete ' . $t['name'] . '?', $text,
        ui_post('wat_delete', ['id' => $t['id'], 'confirm' => '1', '#' => 'templates'], '', 'Delete template', 'primary'),
        admin_url(['view' => 'wa_templates']) . '#templates');
}

/** The next free copy name: promo_nov gives promo_nov_v2, promo_nov_v2 gives promo_nov_v3. */
function admin_wat_next_name(PDO $pdo, array $config, string $name): string
{
    $base = preg_replace('/_v\d+$/', '', $name) ?? $name;
    for ($k = 2; $k < 1000; $k++) {
        $suffix = '_v' . $k;
        $try = substr($base, 0, TG_WAT_NAME_MAX - strlen($suffix)) . $suffix;
        if (!tg_wat_is_system_name($config, $try) && !value($pdo, 'SELECT 1 FROM wa_templates WHERE LOWER(name) = LOWER(?)', [$try])) {
            return $try;
        }
    }
    return substr($base, 0, 40) . '_' . bin2hex(random_bytes(4));
}

/** A template from the form, cleaned and capped (the checks then say what is wrong). $old is the saved draft, if any. */
function admin_wat_from_post(array $config, ?array $old): array
{
    $text = static fn(string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
    // Meta names are plain ASCII. Anything else becomes "?", so the name check names the problem.
    $name = substr(preg_replace('/[^\x21-\x7E]/', '?', trim($text('name'))) ?? '', 0, TG_WAT_NAME_MAX);
    $header = trim(tg_wa_clean_text($text('header'), TG_WAT_LINE_MAX));
    $footer = trim(tg_wa_clean_text($text('footer'), TG_WAT_LINE_MAX));
    $body = trim(tg_wa_clean_text($text('body'), 2000));

    $buttons = [];
    $posted = is_array($_POST['buttons'] ?? null) ? $_POST['buttons'] : [];
    for ($i = 0; $i < 3; $i++) {
        $p = is_array($posted[$i] ?? null) ? $posted[$i] : [];
        $type = (string) ($p['type'] ?? '');
        if (!in_array($type, ['URL', 'QUICK_REPLY'], true)) {
            continue;
        }
        $b = ['type' => $type, 'text' => rp_clean($p['text'] ?? '', 40)];
        if ($type === 'URL') {
            $b['url'] = rp_clean($p['url'] ?? '', TG_WAT_URL_MAX + 100);
            $b['example'] = rp_clean($p['example'] ?? '', TG_WAT_URL_MAX + 100);
            $field = (string) ($p['field'] ?? '');
            $b['field'] = isset(TG_WA_FIELDS[$field]) ? $field : 'fixed';
        }
        $buttons[] = $b;
    }

    $examples = [];
    $fields = [];
    $postedEx = is_array($_POST['examples'] ?? null) ? $_POST['examples'] : [];
    $postedFields = is_array($_POST['fields'] ?? null) ? $_POST['fields'] : [];
    for ($n = 1; $n <= tg_wat_var_count($body); $n++) {
        $examples[] = rp_clean(array_key_exists($n, $postedEx) ? $postedEx[$n] : ($old['examples'][$n - 1] ?? ''), 200);
        $field = is_string($postedFields[$n] ?? null) ? $postedFields[$n] : '';
        $fields[] = isset(TG_WA_FIELDS[$field]) ? $field : (string) ($old['fields'][$n - 1] ?? ($n === 1 ? 'first_name' : 'fixed'));
    }
    $category = in_array($_POST['category'] ?? '', ['UTILITY', 'MARKETING'], true) ? (string) $_POST['category'] : 'UTILITY';
    return [
        'name' => $name, 'language' => (string) ($config['whatsapp']['language'] ?? 'en'), 'category' => $category,
        'header_text' => $header, 'body' => $body, 'footer' => $footer,
        'buttons' => $buttons, 'examples' => $examples, 'fields' => $fields, 'status' => 'DRAFT',
    ];
}

/** Every change the Templates page makes. Called from admin_handle_post with the token already checked. */
function admin_wat_action(PDO $pdo, array $config, string $action): void
{
    $now = rp_now($config);
    $at = tg_ist($now);
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'wat_sync') {
        if (!tg_wa_ready($config)) {
            admin_flash('bad', 'WhatsApp is not set up yet, so Meta can\'t be asked.');
            return;
        }
        $n = tg_wat_sync($config, $pdo, $now);
        admin_wa_forget($config);
        if ($n === null) {
            admin_wat_forget($config);
        } else {
            admin_wat_synced($config, $n);
        }
        tg_audit($config, 'admin', 'wa_template_sync', $n === null ? 'Meta not reached' : $n . ' changed');
        admin_flash($n === null ? 'bad' : 'good', $n === null ? 'Meta could not be reached. Nothing changed.'
            : ($n === 0 ? 'Up to date. Meta has nothing new.' : $n . ' template' . ($n === 1 ? '' : 's') . ' updated.'));
        return;
    }

    if ($action === 'wat_save') {
        $old = $id > 0 ? tg_wat_get($pdo, $id) : null;
        if ($id > 0 && ($old === null || $old['status'] !== 'DRAFT')) {
            admin_flash('bad', 'Only a draft can be changed. Duplicate it to make a new version. Nothing was saved.');
            return;
        }
        $t = admin_wat_from_post($config, $old);
        if ($t['name'] === '') {
            admin_flash('bad', 'Give the template a name. Nothing was saved.');
            return;
        }
        $clash = row($pdo, 'SELECT id, status, deleted_at FROM wa_templates WHERE name = ? AND language = ? AND id <> ?', [$t['name'], $t['language'], $id]);
        if ($clash) {
            if ($clash['status'] !== 'DELETED') {
                admin_flash('bad', 'Another template is already called ' . $t['name'] . '. Pick another name. Nothing was saved.');
                return;
            }
            if ($clash['deleted_at'] === null || tg_wa_ts((string) $clash['deleted_at']) > $now - TG_WAT_NAME_BLOCK_DAYS * 86400) {
                admin_flash('bad', 'A template called ' . $t['name'] . ' was deleted recently. Meta blocks the name until about '
                    . tg_wat_name_free_date((string) ($clash['deleted_at'] ?? $at)) . '. Pick another name. Nothing was saved.');
                return;
            }
            // Deleted over 30 days ago: Meta takes the name again, so the old row lets it go.
            $pdo->prepare("UPDATE wa_templates SET name = CONCAT(LEFT(name, 40), '_old', id) WHERE id = ?")->execute([(int) $clash['id']]);
        }
        $json = static fn(array $v): string => json_encode(array_values($v), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $values = [$t['name'], $t['language'], $t['category'], $t['header_text'] !== '' ? $t['header_text'] : null, $t['body'],
            $t['footer'] !== '' ? $t['footer'] : null, $json($t['buttons']), $json($t['examples']), $json($t['fields'])];
        if ($old === null) {
            $pdo->prepare("INSERT INTO wa_templates (name, language, category, header_text, body, footer, buttons, examples, fields, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'DRAFT', ?, ?)")->execute([...$values, $at, $at]);
            $id = (int) $pdo->lastInsertId();
        } else {
            $pdo->prepare("UPDATE wa_templates SET name = ?, language = ?, category = ?, header_text = ?, body = ?, footer = ?, buttons = ?, examples = ?, fields = ?,
                updated_at = ? WHERE id = ? AND status = 'DRAFT'")->execute([...$values, $at, $id]);
        }
        $errors = tg_wat_check($config, $pdo, $t + ['id' => $id], admin_wat_meta_names($config), $now)['errors'];
        tg_audit($config, 'admin', 'wa_template_saved', $t['name'] . ' (template ' . $id . '), ' . count($errors) . ' to fix');
        admin_flash($errors ? 'warn' : 'good', $errors
            ? 'Saved. ' . count($errors) . ' thing' . (count($errors) === 1 ? '' : 's') . ' to fix before submitting.'
            : 'Saved. Ready to submit.');
        $_POST['back'] = './?view=wa_templates&edit=' . $id . '#edit';
        return;
    }

    $t = tg_wat_get($pdo, $id);
    if ($t === null || $t['status'] === 'DELETED') {
        admin_flash('bad', 'That template is not here. Nothing changed.');
        return;
    }

    switch ($action) {
        case 'wat_submit':
            if ($t['status'] !== 'DRAFT') {
                admin_flash('bad', 'Only a draft can be submitted. ' . $t['name'] . ' is already with Meta.');
                return;
            }
            if (!tg_wa_ready($config)) {
                admin_flash('bad', 'WhatsApp is not set up yet, so nothing was sent to Meta.');
                return;
            }
            $errors = tg_wat_check($config, $pdo, $t, admin_wat_meta_names($config), $now)['errors'];
            if ($errors) {
                admin_flash('bad', 'Not submitted. ' . count($errors) . ' thing' . (count($errors) === 1 ? '' : 's') . ' to fix first: ' . implode(' ', array_slice($errors, 0, 3)));
                return;
            }
            ignore_user_abort(true);
            @set_time_limit(60);
            $r = tg_wat_submit($config, $pdo, $t['id'], $now);
            admin_wa_forget($config);
            tg_audit($config, 'admin', 'wa_template_submitted', $t['name'] . ': ' . ($r['ok'] ? 'Meta says ' . strtolower((string) $r['status']) : 'refused, ' . $r['error']));
            admin_flash($r['ok'] ? 'good' : 'bad', $r['ok']
                ? 'Sent to Meta for review. ' . $t['name'] . ' is ' . ($r['status'] === 'APPROVED' ? 'approved already.' : 'in review. Meta usually answers within minutes, sometimes a day.')
                : 'Meta refused it, so it is still a draft. ' . $r['error']);
            return;

        case 'wat_duplicate':
            $name = admin_wat_next_name($pdo, $config, $t['name']);
            $json = static fn(array $v): string => json_encode(array_values($v), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $pdo->prepare("INSERT INTO wa_templates (name, language, category, header_text, body, footer, buttons, examples, fields, status, copied_from, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'DRAFT', ?, ?, ?)")
                ->execute([$name, $t['language'], $t['category'], $t['header_text'], $t['body'], $t['footer'], $json($t['buttons']), $json($t['examples']),
                    $json($t['fields']), $t['id'], $at, $at]);
            $new = (int) $pdo->lastInsertId();
            tg_audit($config, 'admin', 'wa_template_copied', $t['name'] . ' copied as ' . $name . ' (template ' . $new . ')');
            admin_flash('good', 'Copied as ' . $name . '. Change it, then submit it.');
            $_POST['back'] = './?view=wa_templates&edit=' . $new . '#edit';
            return;

        case 'wat_delete':
            if (($_POST['confirm'] ?? '') !== '1') {
                admin_flash('bad', 'Nothing changed. Confirm first.');
                return;
            }
            if ($t['status'] !== 'DRAFT' && !tg_wa_ready($config)) {
                admin_flash('bad', 'WhatsApp is not set up, so Meta can\'t be asked to delete it. Nothing changed.');
                return;
            }
            $r = tg_wat_delete($config, $pdo, $t['id'], $now);
            admin_wa_forget($config);
            tg_audit($config, 'admin', 'wa_template_deleted', $t['name'] . ($r['ok'] ? ($r['meta'] ? ': deleted at Meta' : ': draft removed') : ': Meta refused, ' . $r['error']));
            admin_flash($r['ok'] ? 'good' : 'bad', !$r['ok'] ? 'Meta refused to delete it, so nothing changed. ' . $r['error']
                : ($r['meta'] ? $t['name'] . ' is deleted at Meta. Meta blocks the name until about ' . tg_wat_name_free_date($at) . '.'
                : 'Draft ' . $t['name'] . ' deleted.'));
            $_POST['back'] = './?view=wa_templates#templates';
            return;
    }
}
