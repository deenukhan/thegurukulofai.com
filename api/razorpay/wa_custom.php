<?php
/**
 * WhatsApp templates written on the dashboard (wa_templates, schema version 8).
 * Required by whatsapp.php, so the webhook, the worker and the dashboard all share it.
 *
 * A template starts as a draft. tg_wat_check lists what Meta would refuse (errors block
 * submitting) and what Meta might not like (warnings do not). Submitting sends it to
 * Meta for review; Meta's answer comes back through the webhook and tg_wat_sync. An
 * approved template is never edited: duplicate it under a new name instead.
 *
 * The seven built-in booking templates stay in code (tg_wa_templates) and are never rows
 * here, so nothing on the dashboard can change or delete them.
 *
 * Templates hold no personal data (the examples are Deenu's samples), so nothing here is
 * purged. A deleted template keeps its row, because Meta blocks its name for 30 days.
 */

/**
 * What a variable can be filled with when a template is sent to one person.
 * fixed is text typed at send time. A field with no value for that person (session for
 * someone who never paid, fixed left empty) means the template can't go to them.
 */
const TG_WA_FIELDS = [
    'first_name'   => 'Their first name ("there" if unknown)',
    'full_name'    => 'Their full name ("there" if unknown)',
    'session'      => 'Their paid session, e.g. Sunday 30 Nov, 3 PM IST',
    'next_session' => 'The next Sunday session',
    'event'        => 'The event name',
    'fixed'        => 'Text you type when sending',
];
/** Meta's limits, and ours where Meta allows more. */
const TG_WAT_NAME_MAX = 60;
const TG_WAT_BODY_MAX = 1024;
const TG_WAT_LINE_MAX = 60;     // header and footer
const TG_WAT_BUTTON_TEXT_MAX = 25;
const TG_WAT_URL_MAX = 2000;
const TG_WAT_NAME_BLOCK_DAYS = 30;  // Meta won't take a deleted name back for about this long
/** Words that make Meta move a "utility" template to marketing. A warning only. */
const TG_WAT_PROMO = '/\b(offers?|discounts?|coupons?|sale|free|limited[- ]time|deals?|cashback|hurry|last chance)\b|\d+\s*%\s*off|₹\s*[\d,]+\s*off|rs\.?\s*[\d,]+\s*off/iu';

/** The variable numbers in a text, in the order they appear: "Hi {{1}}, {{2}}" gives [1, 2]. */
function tg_wat_vars(string $body): array
{
    preg_match_all('/\{\{(\d+)\}\}/', $body, $m);
    return array_map('intval', $m[1]);
}

/** How many different variables a body holds (the highest number). */
function tg_wat_var_count(string $body): int
{
    $vars = tg_wat_vars($body);
    return $vars ? min(max($vars), 50) : 0;
}

/** A stored row with its JSON parts decoded, or null. */
function tg_wat_get(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $q = $pdo->prepare('SELECT * FROM wa_templates WHERE id = ?');
    $q->execute([$id]);
    $row = $q->fetch();
    return $row ? tg_wat_decode($row) : null;
}

function tg_wat_decode(array $row): array
{
    foreach (['buttons', 'examples', 'fields'] as $k) {
        $v = json_decode((string) ($row[$k] ?? '[]'), true);
        $row[$k] = is_array($v) ? array_values($v) : [];
    }
    $row['id'] = (int) $row['id'];
    return $row;
}

/** True when this name is one of the built-in booking templates. */
function tg_wat_is_system_name(array $config, string $name): bool
{
    foreach (tg_wa_templates($config) as $t) {
        if (strcasecmp($t['name'], $name) === 0) {
            return true;
        }
    }
    return false;
}

/** True when a link points at WhatsApp itself, which Meta refuses in buttons. */
function tg_wat_whatsapp_link(string $url): bool
{
    $host = strtolower((string) parse_url(str_replace('{{1}}', 'x', $url), PHP_URL_HOST));
    foreach (['wa.me', 'whatsapp.com', 'whatsapp.net', 'wa.link'] as $bad) {
        if ($host === $bad || str_ends_with($host, '.' . $bad)) {
            return true;
        }
    }
    return false;
}

/** A count with commas, without needing the dashboard's helpers (this file is shared). */
function tg_wat_num(int $n): string
{
    return function_exists('num') ? num($n) : number_format($n);
}

/**
 * Everything Meta would refuse (errors) or might not like (warnings), in plain English.
 * Errors block submitting; warnings do not. $t: name, language, category, header_text,
 * body, footer, buttons, examples, fields (and id and status when saved). $metaNames:
 * names Meta already has (from the cached template list), so a clash is caught first.
 */
function tg_wat_check(array $config, PDO $pdo, array $t, ?array $metaNames = null, ?int $now = null): array
{
    $now ??= rp_now($config);
    $errors = [];
    $warnings = [];
    $id = (int) ($t['id'] ?? 0);

    // ---- Name ----
    $name = (string) ($t['name'] ?? '');
    if ($name === '') {
        $errors[] = 'Give the template a name.';
    } elseif (!preg_match('/^[a-z0-9_]+$/', $name)) {
        $errors[] = 'Use only lowercase letters, numbers and underscores in the name, such as course_update_nov.';
    } elseif (strlen($name) > TG_WAT_NAME_MAX) {
        $errors[] = 'The name is ' . strlen($name) . ' characters. Keep it to ' . TG_WAT_NAME_MAX . '.';
    }
    if ($name !== '') {
        if (tg_wat_is_system_name($config, $name)) {
            $errors[] = $name . ' is one of the built-in booking templates. Pick another name.';
        }
        $q = $pdo->prepare('SELECT id, status, deleted_at FROM wa_templates WHERE LOWER(name) = LOWER(?) AND id <> ?');
        $q->execute([$name, $id]);
        foreach ($q->fetchAll() as $other) {
            if ($other['status'] !== 'DELETED') {
                $errors[] = 'Another template is already called ' . $name . '.';
            } elseif ($other['deleted_at'] !== null && tg_wa_ts((string) $other['deleted_at']) > $now - TG_WAT_NAME_BLOCK_DAYS * 86400) {
                $errors[] = 'A template called ' . $name . ' was deleted recently. Meta blocks the name until about '
                    . tg_wat_name_free_date((string) $other['deleted_at']) . '. Pick another name.';
            }
        }
        if (($t['status'] ?? 'DRAFT') === 'DRAFT' && $metaNames !== null && in_array($name, $metaNames, true)) {
            $errors[] = 'Meta already has a template called ' . $name . '. Pick another name.';
        }
    }

    // ---- Category and language ----
    $category = (string) ($t['category'] ?? '');
    if (!in_array($category, ['UTILITY', 'MARKETING'], true)) {
        $errors[] = 'Pick a category: utility or marketing.';
    }
    $lang = (string) ($config['whatsapp']['language'] ?? 'en');
    if ((string) ($t['language'] ?? '') !== $lang) {
        $errors[] = 'Only one language is set up: ' . $lang . '.';
    }

    // ---- Header ----
    $header = (string) ($t['header_text'] ?? '');
    if ($header !== '') {
        if (mb_strlen($header) > TG_WAT_LINE_MAX) {
            $errors[] = 'The header is ' . mb_strlen($header) . ' characters. Meta allows ' . TG_WAT_LINE_MAX . '.';
        }
        if (preg_match('/[\r\n]/', $header)) {
            $errors[] = 'The header must be one line, with no line breaks.';
        }
        if (str_contains($header, '{{')) {
            $errors[] = 'The header can\'t hold a variable. Keep it plain text.';
        }
        if (preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', $header)) {
            $errors[] = 'The header can\'t hold emoji.';
        }
        if (preg_match('/[*~`]/', $header)) {
            $errors[] = 'The header can\'t use bold, strike-through or code formatting (*, ~ or `).';
        }
    }

    // ---- Body ----
    $body = (string) ($t['body'] ?? '');
    $vars = tg_wat_vars($body);
    $count = tg_wat_var_count($body);
    if (trim($body) === '') {
        $errors[] = 'Write the message.';
    } else {
        if (mb_strlen($body) > TG_WAT_BODY_MAX) {
            $errors[] = 'The message is ' . tg_wat_num(mb_strlen($body)) . ' characters. Meta allows up to 1,024.';
        }
        $rest = preg_replace('/\{\{\d+\}\}/', '', $body) ?? '';
        if (str_contains($rest, '{{') || str_contains($rest, '}}')) {
            $errors[] = 'Write variables as {{1}}, {{2}} and so on, with only a number inside the braces.';
        }
        if ($vars) {
            $distinct = array_values(array_unique($vars));
            $sorted = $distinct;
            sort($sorted);
            if (max($vars) > 50) {
                $errors[] = 'Use fewer variables: up to {{50}}.';
            } elseif ($sorted !== range(1, max($vars))) {
                $errors[] = 'The variables skip a number. Use {{1}}, {{2}}, {{3}} with none missing.';
            } elseif ($distinct !== $sorted) {
                $errors[] = 'Number the variables in the order they appear: {{1}} first, then {{2}}.';
            }
            if (preg_match('/^[\s\p{P}\p{S}]*\{\{\d+\}\}/u', $body)) {
                $errors[] = 'The message can\'t start with a variable. Put a word before it, such as "Hi {{1}}".';
            }
            if (preg_match('/\{\{\d+\}\}[\s\p{P}\p{S}]*$/u', $body)) {
                $errors[] = 'The message can\'t end with a variable, even before a full stop. Add words after it.';
            }
            if (preg_match('/\{\{\d+\}\}\s*\{\{\d+\}\}/', $body)) {
                $errors[] = 'Two variables sit next to each other with nothing between them. Put words between them.';
            }
            $words = preg_match_all('/\p{L}[\p{L}\p{N}\'’-]*/u', $rest);
            if ($words < 3 * $count) {
                $warnings[] = 'Meta may refuse this many variables for a message this short. Add more words around them.';
            }
        }
    }

    // ---- Examples and fields, one per variable ----
    $examples = is_array($t['examples'] ?? null) ? array_values($t['examples']) : [];
    $fields = is_array($t['fields'] ?? null) ? array_values($t['fields']) : [];
    for ($n = 1; $n <= $count; $n++) {
        if (trim((string) ($examples[$n - 1] ?? '')) === '') {
            $errors[] = 'Add an example for {{' . $n . '}}. Meta\'s reviewers need one for each variable.';
        }
        if (!isset(TG_WA_FIELDS[(string) ($fields[$n - 1] ?? '')])) {
            $errors[] = 'Choose what fills {{' . $n . '}} when it is sent.';
        }
    }

    // ---- Footer ----
    $footer = (string) ($t['footer'] ?? '');
    if ($footer !== '') {
        if (mb_strlen($footer) > TG_WAT_LINE_MAX) {
            $errors[] = 'The footer is ' . mb_strlen($footer) . ' characters. Meta allows ' . TG_WAT_LINE_MAX . '.';
        }
        if (str_contains($footer, '{{')) {
            $errors[] = 'The footer can\'t hold a variable.';
        }
        if (preg_match('/[\r\n]/', $footer)) {
            $errors[] = 'The footer must be one line, with no line breaks.';
        }
    }

    // ---- Buttons ----
    $buttons = is_array($t['buttons'] ?? null) ? array_values($t['buttons']) : [];
    $urls = 0;
    $texts = [];
    $kinds = '';
    foreach ($buttons as $i => $b) {
        $n = $i + 1;
        $type = (string) ($b['type'] ?? '');
        $text = (string) ($b['text'] ?? '');
        $kinds .= $type === 'URL' ? 'U' : 'Q';
        if (trim($text) === '') {
            $errors[] = 'Button ' . $n . ' needs its text.';
        } else {
            if (mb_strlen($text) > TG_WAT_BUTTON_TEXT_MAX) {
                $errors[] = 'Button ' . $n . '\'s text is ' . mb_strlen($text) . ' characters. Meta allows ' . TG_WAT_BUTTON_TEXT_MAX . '.';
            }
            $texts[] = mb_strtolower(trim($text));
        }
        if ($type !== 'URL') {
            continue;
        }
        $urls++;
        $url = trim((string) ($b['url'] ?? ''));
        if ($url === '') {
            $errors[] = 'Button ' . $n . ' needs a link.';
            continue;
        }
        if (!preg_match('~^https://[^\s/{}]+\.[^\s/{}]+~i', $url)) {
            $errors[] = 'Button ' . $n . '\'s link must be a full address starting with https://.';
        }
        if (strlen($url) > TG_WAT_URL_MAX) {
            $errors[] = 'Button ' . $n . '\'s link is too long. Meta allows 2,000 characters.';
        }
        if (tg_wat_whatsapp_link($url)) {
            $errors[] = 'Button ' . $n . ' links to WhatsApp. Meta refuses WhatsApp links in buttons, so link to a page on your site that forwards instead.';
        }
        $braces = substr_count($url, '{{');
        if ($braces > 0) {
            if ($braces !== 1 || !str_ends_with($url, '{{1}}')) {
                $errors[] = 'Button ' . $n . '\'s link can have one variable only, {{1}}, at the very end.';
            } else {
                $prefix = substr($url, 0, -5);
                $example = trim((string) ($b['example'] ?? ''));
                if ($example === '' || !str_starts_with($example, $prefix) || strlen($example) <= strlen($prefix) || str_contains($example, '{{')) {
                    $errors[] = 'Button ' . $n . ' needs a full example link, such as ' . $prefix . 'abc123.';
                }
                if (!isset(TG_WA_FIELDS[(string) ($b['field'] ?? '')])) {
                    $errors[] = 'Choose what fills the {{1}} in button ' . $n . '\'s link.';
                }
            }
        }
    }
    if (count($buttons) > 3) {
        $errors[] = 'Use up to 3 buttons.';
    }
    if ($urls > 2) {
        $errors[] = 'Use up to 2 link buttons.';
    }
    if (count($texts) !== count(array_unique($texts))) {
        $errors[] = 'Two buttons have the same text. Each needs its own.';
    }
    if (preg_match('/QU+Q/', $kinds)) {
        $errors[] = 'Keep the quick reply buttons together, before or after the link buttons.';
    }

    // ---- Warnings ----
    if ($category === 'UTILITY' && preg_match(TG_WAT_PROMO, $header . "\n" . $body . "\n" . $footer)) {
        $warnings[] = 'This reads like an offer. Meta will probably move it to marketing, which costs about ₹0.86 a message instead of ₹0.15.';
    }
    if ($category === 'MARKETING' && stripos($footer . ' ' . $body, 'stop') === false) {
        $warnings[] = 'Say how to stop, for example the footer "' . TG_WA_FOOTER . '". People who can\'t stop block the number, which lowers its quality.';
    }
    return ['errors' => array_values(array_unique($errors)), 'warnings' => $warnings];
}

/** The day a deleted name is free again at Meta, such as "23 Dec". */
function tg_wat_name_free_date(string $deletedAt): string
{
    return (new DateTimeImmutable('@' . (tg_wa_ts($deletedAt) + TG_WAT_NAME_BLOCK_DAYS * 86400)))
        ->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('j M');
}

/** Meta's request body for one template, as submitted for review. */
function tg_wat_request(array $config, array $t): array
{
    $components = [];
    if ((string) ($t['header_text'] ?? '') !== '') {
        $components[] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => (string) $t['header_text']];
    }
    $body = ['type' => 'BODY', 'text' => (string) $t['body']];
    $count = tg_wat_var_count((string) $t['body']);
    if ($count > 0) {
        $body['example'] = ['body_text' => [array_map('strval', array_slice(array_pad($t['examples'], $count, ''), 0, $count))]];
    }
    $components[] = $body;
    if ((string) ($t['footer'] ?? '') !== '') {
        $components[] = ['type' => 'FOOTER', 'text' => (string) $t['footer']];
    }
    $buttons = [];
    foreach ($t['buttons'] as $b) {
        if (($b['type'] ?? '') === 'URL') {
            $button = ['type' => 'URL', 'text' => (string) $b['text'], 'url' => (string) $b['url']];
            if (str_contains((string) $b['url'], '{{1}}')) {
                $button['example'] = [(string) ($b['example'] ?? '')];
            }
            $buttons[] = $button;
        } else {
            $buttons[] = ['type' => 'QUICK_REPLY', 'text' => (string) $b['text']];
        }
    }
    if ($buttons) {
        $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
    }
    return [
        'name'       => (string) $t['name'],
        'language'   => (string) $t['language'],
        'category'   => (string) $t['category'],
        'components' => $components,
    ];
}

/**
 * Sends a draft to Meta for review. On success it stores Meta's id and status; on a
 * refusal it keeps the draft and stores Meta's reason. Returns ok, error and status.
 */
function tg_wat_submit(array $config, PDO $pdo, int $id, int $now): array
{
    $t = tg_wat_get($pdo, $id);
    if ($t === null || $t['status'] !== 'DRAFT') {
        return ['ok' => false, 'error' => 'Only a draft can be submitted.', 'status' => null];
    }
    [$status, $body] = tg_wa_graph($config, 'POST', rawurlencode((string) $config['whatsapp']['waba_id']) . '/message_templates', tg_wat_request($config, $t));
    $metaId = (string) ($body['id'] ?? '');
    if ($status >= 200 && $status < 300 && $metaId !== '') {
        $state = strtoupper(preg_replace('/[^A-Za-z_]/', '', (string) ($body['status'] ?? 'PENDING')) ?: 'PENDING');
        $cat = strtoupper((string) ($body['category'] ?? ''));
        $pdo->prepare('UPDATE wa_templates SET status = ?, meta_id = ?, meta_category = ?, reason = NULL, submitted_at = ?, status_at = ?, updated_at = ? WHERE id = ?')
            ->execute([substr($state, 0, 20), substr($metaId, 0, 40), $cat !== '' && $cat !== $t['category'] ? substr($cat, 0, 12) : null,
                tg_ist($now), tg_ist($now), tg_ist($now), $id]);
        return ['ok' => true, 'error' => null, 'status' => $state];
    }
    $error = tg_wa_error_text($config, $status, $body);
    $pdo->prepare('UPDATE wa_templates SET reason = ?, updated_at = ? WHERE id = ?')->execute([mb_substr($error, 0, 255), tg_ist($now), $id]);
    return ['ok' => false, 'error' => $error, 'status' => null];
}

/** Meta's quality score as GREEN, YELLOW, RED or UNKNOWN. Meta sends an object ({score, date}); a plain string is accepted too. */
function tg_wat_quality($score): string
{
    if (is_array($score)) {
        $score = $score['score'] ?? '';
    }
    $score = strtoupper(is_scalar($score) ? (string) $score : '');
    return in_array($score, ['GREEN', 'YELLOW', 'RED'], true) ? $score : 'UNKNOWN';
}

/**
 * Every template Meta has for this business account, all languages, following the pages
 * (up to 5). Null if Meta can't be read. $complete is false when there were more pages.
 */
function tg_wa_template_list(array $config, ?bool &$complete = null): ?array
{
    $complete = false;
    $query = ['fields' => 'name,status,category,language,rejected_reason,id,quality_score', 'limit' => 200];
    $out = [];
    for ($page = 0; $page < 5; $page++) {
        [$status, $body] = tg_wa_graph($config, 'GET', rawurlencode((string) $config['whatsapp']['waba_id']) . '/message_templates', null, $query);
        if ($status !== 200 || !is_array($body['data'] ?? null)) {
            return null;
        }
        foreach ($body['data'] as $t) {
            if (is_array($t) && isset($t['name'])) {
                $out[] = $t;
            }
        }
        $after = (string) ($body['paging']['cursors']['after'] ?? '');
        if (empty($body['paging']['next']) || $after === '') {
            $complete = true;
            break;
        }
        $query['after'] = $after;
    }
    return $out;
}

/**
 * Brings our templates up to date with Meta: status, category, rejection reason, quality
 * and id, by name and language. Drafts are left alone. A template Meta no longer has
 * (deleted in WhatsApp Manager) is marked deleted, but only after reading every page.
 * Returns how many rows changed, or null if Meta can't be read.
 */
function tg_wat_sync(array $config, PDO $pdo, int $now): ?int
{
    $list = tg_wa_template_list($config, $complete);
    if ($list === null) {
        return null;
    }
    $byKey = [];
    foreach ($list as $m) {
        $byKey[(string) $m['name'] . '|' . (string) ($m['language'] ?? '')] = $m;
    }
    $changed = 0;
    $update = $pdo->prepare('UPDATE wa_templates SET status = ?, meta_category = ?, reason = ?, quality = ?, meta_id = ?, status_at = ?, deleted_at = ?, updated_at = ? WHERE id = ?');
    foreach ($pdo->query("SELECT * FROM wa_templates WHERE status NOT IN ('DRAFT', 'DELETED')")->fetchAll() as $row) {
        $m = $byKey[$row['name'] . '|' . $row['language']] ?? null;
        if ($m === null) {
            if (!$complete) {
                continue;
            }
            $new = ['DELETED', $row['meta_category'], $row['reason'], $row['quality'], $row['meta_id']];
        } else {
            $state = substr(strtoupper((string) ($m['status'] ?? $row['status'])), 0, 20);
            $cat = strtoupper((string) ($m['category'] ?? ''));
            $why = trim((string) ($m['rejected_reason'] ?? ''));
            $new = [
                $state,
                $cat !== '' && $cat !== $row['category'] ? substr($cat, 0, 12) : null,
                $why !== '' && strtoupper($why) !== 'NONE' ? mb_substr($why, 0, 255) : ($state === 'APPROVED' ? null : $row['reason']),
                array_key_exists('quality_score', $m) ? tg_wat_quality($m['quality_score']) : $row['quality'],
                isset($m['id']) ? substr((string) $m['id'], 0, 40) : $row['meta_id'],
            ];
        }
        $old = [$row['status'], $row['meta_category'], $row['reason'], $row['quality'], $row['meta_id']];
        if ($new === $old) {
            continue;
        }
        $statusAt = $new[0] !== $row['status'] ? tg_ist($now) : $row['status_at'];
        $deletedAt = $new[0] === 'DELETED' ? ($row['deleted_at'] ?? tg_ist($now)) : $row['deleted_at'];
        $update->execute([...$new, $statusAt, $deletedAt, tg_ist($now), $row['id']]);
        $changed++;
    }
    return $changed;
}

/**
 * Deletes a template. A draft never reached Meta, so it is simply removed and its name is
 * free again. Anything submitted is deleted at Meta first (by name and id, this language
 * only); the row stays as DELETED, because Meta blocks the name for about 30 days.
 * Returns ok, error and meta (true when Meta was asked).
 */
function tg_wat_delete(array $config, PDO $pdo, int $id, int $now): array
{
    $t = tg_wat_get($pdo, $id);
    if ($t === null || $t['status'] === 'DELETED') {
        return ['ok' => false, 'error' => 'That template is not here.', 'meta' => false];
    }
    if ($t['status'] === 'DRAFT') {
        $pdo->prepare("DELETE FROM wa_templates WHERE id = ? AND status = 'DRAFT'")->execute([$id]);
        return ['ok' => true, 'error' => null, 'meta' => false];
    }
    $query = ['name' => $t['name']];
    if ((string) $t['meta_id'] !== '') {
        $query['hsm_id'] = (string) $t['meta_id'];
    }
    [$status, $body] = tg_wa_graph($config, 'DELETE', rawurlencode((string) $config['whatsapp']['waba_id']) . '/message_templates', null, $query);
    if ($status < 200 || $status >= 300) {
        return ['ok' => false, 'error' => tg_wa_error_text($config, $status, $body), 'meta' => true];
    }
    $pdo->prepare("UPDATE wa_templates SET status = 'DELETED', deleted_at = ?, status_at = ?, updated_at = ? WHERE id = ?")
        ->execute([tg_ist($now), tg_ist($now), tg_ist($now), $id]);
    return ['ok' => true, 'error' => null, 'meta' => true];
}

/** Templates Meta approved and that are not deleted: the ones that can be sent. */
function tg_wat_approved(PDO $pdo): array
{
    try {
        return array_map('tg_wat_decode', $pdo->query("SELECT * FROM wa_templates WHERE status = 'APPROVED' AND deleted_at IS NULL ORDER BY name")->fetchAll());
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * The details a template can be filled with for one number: the name from the booking
 * form (else their WhatsApp name) and their latest paid session.
 */
function tg_wat_person(PDO $pdo, array $config, string $phone): array
{
    $contacts = tg_wa_contacts($pdo, $phone);
    $name = '';
    foreach ($contacts as $c) {
        if (trim((string) $c['name']) !== '') {
            $name = trim((string) $c['name']);
            break;
        }
    }
    if ($name === '') {
        $name = trim((string) tg_wa_value($pdo, 'SELECT profile_name FROM wa_contacts WHERE phone = ?', [$phone]));
    }
    $session = null;
    $ids = array_map('intval', array_column($contacts, 'id'));
    if ($ids) {
        $q = $pdo->prepare("SELECT MAX(session_at) FROM bookings WHERE status = 'paid' AND product = ? AND session_at IS NOT NULL
            AND contact_id IN (" . implode(',', $ids) . ')');
        $q->execute([(string) ($config['product'] ?? '')]);
        $at = $q->fetchColumn();
        $session = is_string($at) && $at !== '' ? $at : null;
    }
    return ['name' => $name, 'session_at' => $session];
}

/** "Sunday 30 Nov, 3 PM IST" for a stored session time. */
function tg_wat_session_text(string $sessionAt): string
{
    return (new DateTimeImmutable($sessionAt, new DateTimeZone('Asia/Kolkata')))->format('l j M, g A') . ' IST';
}

/** A value as Meta takes it: no new lines or tabs, no runs of spaces, up to 1,024 characters. */
function tg_wat_clean_value(string $v): string
{
    $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '';
    $v = preg_replace('/ {2,}/', ' ', $v) ?? '';
    return mb_substr(trim($v), 0, 1024);
}

/** One field's value for a person, or null when it can't be filled for them. */
function tg_wat_fill(array $config, string $field, array $person, string $typed, int $now): ?string
{
    $name = trim((string) ($person['name'] ?? ''));
    return match ($field) {
        'first_name'   => tg_first_name($name),
        'full_name'    => $name !== '' ? $name : 'there',
        'session'      => !empty($person['session_at']) ? tg_wat_session_text((string) $person['session_at']) : null,
        'next_session' => tg_wat_session_text(rp_next_session($now)->format('Y-m-d H:i:s')),
        'event'        => (string) ($config['event']['name'] ?? 'AI Video Editing Masterclass'),
        'fixed'        => tg_wat_clean_value($typed) !== '' ? $typed : null,
        default        => null,
    };
}

/** The fields a template uses: body variables, then link buttons with a variable. Key => field ('b2', 'u0'). */
function tg_wat_slots(array $t): array
{
    $out = [];
    $count = tg_wat_var_count((string) $t['body']);
    for ($n = 1; $n <= $count; $n++) {
        $out['b' . $n] = (string) ($t['fields'][$n - 1] ?? 'fixed');
    }
    foreach ($t['buttons'] as $i => $b) {
        if (($b['type'] ?? '') === 'URL' && str_contains((string) ($b['url'] ?? ''), '{{1}}')) {
            $out['u' . $i] = (string) ($b['field'] ?? 'fixed');
        }
    }
    return $out;
}

/**
 * The values for one send. $fixed holds typed text: 'b2' for {{2}} in the message,
 * 'u0' for the link of the first button. Returns body (in order), buttons (button index
 * => value, link buttons with a variable only) and missing (what could not be filled).
 */
function tg_wat_values(array $config, array $t, array $person, array $fixed, int $now): array
{
    $out = ['body' => [], 'buttons' => [], 'missing' => []];
    foreach (tg_wat_slots($t) as $key => $field) {
        $v = tg_wat_fill($config, $field, $person, is_string($fixed[$key] ?? null) ? $fixed[$key] : '', $now);
        $v = $v === null ? '' : tg_wat_clean_value($v);
        if ($key[0] === 'u') {
            $v = preg_replace('/\s+/u', '', $v) ?? '';
        }
        if ($v === '') {
            $out['missing'][] = ($key[0] === 'b' ? '{{' . substr($key, 1) . '}}' : 'the link in button ' . ((int) substr($key, 1) + 1))
                . ' (' . (TG_WA_FIELDS[$field] ?? $field) . ')';
        }
        if ($key[0] === 'b') {
            $out['body'][] = $v;
        } else {
            $out['buttons'][(int) substr($key, 1)] = $v;
        }
    }
    return $out;
}

/** The examples as values, for previews: what Meta's reviewers see. */
function tg_wat_sample_values(array $t): array
{
    $count = tg_wat_var_count((string) $t['body']);
    $body = [];
    for ($n = 1; $n <= $count; $n++) {
        $body[] = trim((string) ($t['examples'][$n - 1] ?? ''));
    }
    $buttons = [];
    foreach ($t['buttons'] as $i => $b) {
        $url = (string) ($b['url'] ?? '');
        if (($b['type'] ?? '') === 'URL' && str_ends_with($url, '{{1}}')) {
            $prefix = substr($url, 0, -5);
            $example = (string) ($b['example'] ?? '');
            $buttons[$i] = str_starts_with($example, $prefix) && strlen($example) > strlen($prefix) ? substr($example, strlen($prefix)) : '';
        }
    }
    return ['body' => $body, 'buttons' => $buttons, 'missing' => []];
}

/** The message as the person sees it: header, body, footer and buttons, for ui_wa_bubble. Empty values show as {{n}}. */
function tg_wat_render(array $t, array $values): array
{
    $body = preg_replace_callback('/\{\{(\d+)\}\}/', static function ($m) use ($values) {
        $v = (string) ($values['body'][(int) $m[1] - 1] ?? '');
        return $v !== '' ? $v : $m[0];
    }, (string) $t['body']);
    $buttons = [];
    foreach ($t['buttons'] as $i => $b) {
        $fill = (string) ($values['buttons'][$i] ?? '');
        $url = ($b['type'] ?? '') === 'URL' ? str_replace('{{1}}', $fill !== '' ? $fill : '{{1}}', (string) ($b['url'] ?? '')) : '';
        $buttons[] = ['text' => (string) ($b['text'] ?? ''), 'url' => $url];
    }
    return ['header' => (string) ($t['header_text'] ?? ''), 'body' => $body, 'footer' => (string) ($t['footer'] ?? ''), 'buttons' => $buttons];
}

/** The words stored in the conversation for a sent template: the header line, then the message. */
function tg_wat_text(array $rendered): string
{
    return ($rendered['header'] !== '' ? $rendered['header'] . "\n\n" : '') . $rendered['body'];
}

/**
 * Sends one approved custom template. Returns ok, id (Meta's message id), error, retry
 * and code, like tg_wa_send.
 */
function tg_wat_send(array $config, array $t, string $to, array $values): array
{
    $components = [];
    if ($values['body']) {
        $components[] = ['type' => 'body', 'parameters' => array_map(static fn($v) => ['type' => 'text', 'text' => (string) $v], $values['body'])];
    }
    foreach ($values['buttons'] as $i => $v) {
        $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => (string) $i, 'parameters' => [['type' => 'text', 'text' => (string) $v]]];
    }
    $template = ['name' => (string) $t['name'], 'language' => ['code' => (string) $t['language']]];
    if ($components) {
        $template['components'] = $components;
    }
    [$status, $body] = tg_wa_graph($config, 'POST', rawurlencode((string) $config['whatsapp']['phone_number_id']) . '/messages', [
        'messaging_product' => 'whatsapp',
        'recipient_type'    => 'individual',
        'to'                => $to,
        'type'              => 'template',
        'template'          => $template,
    ]);
    $id = (string) ($body['messages'][0]['id'] ?? '');
    if ($status >= 200 && $status < 300 && $id !== '') {
        return ['ok' => true, 'id' => $id, 'error' => null, 'retry' => false, 'code' => 0];
    }
    $error = tg_wa_error_text($config, $status, $body);
    error_log('whatsapp template ' . $t['name'] . ' to ' . tg_wa_pretty($to) . ' not sent: ' . $error);
    return ['ok' => false, 'id' => null, 'error' => $error, 'retry' => tg_wa_retryable($status, $body), 'code' => (int) ($body['error']['code'] ?? 0)];
}

/**
 * Template news from Meta's webhook: a review result (message_template_status_update),
 * a new category (template_category_update) or a quality change
 * (message_template_quality_update). Updates our row and returns it as it was before
 * (name, category, quality...), or null when the template is not one of ours.
 */
function tg_wat_event(PDO $pdo, array $config, string $field, array $v, int $now): ?array
{
    $metaId = substr((string) ($v['message_template_id'] ?? ''), 0, 40);
    $name = (string) ($v['message_template_name'] ?? '');
    $language = (string) ($v['message_template_language'] ?? ($config['whatsapp']['language'] ?? 'en'));
    $row = null;
    if ($metaId !== '') {
        $q = $pdo->prepare('SELECT * FROM wa_templates WHERE meta_id = ? ORDER BY id DESC LIMIT 1');
        $q->execute([$metaId]);
        $row = $q->fetch() ?: null;
    }
    if ($row === null && $name !== '') {
        // By name only for templates already submitted: a draft is not at Meta yet.
        $q = $pdo->prepare("SELECT * FROM wa_templates WHERE name = ? AND language = ? AND status <> 'DRAFT' ORDER BY deleted_at IS NULL DESC, id DESC LIMIT 1");
        $q->execute([$name, $language]);
        $row = $q->fetch() ?: null;
    }
    if ($row === null) {
        return null;
    }
    $at = tg_ist($now);
    if ($field === 'message_template_status_update') {
        $state = substr(strtoupper(preg_replace('/[^A-Za-z_]/', '', (string) ($v['event'] ?? '')) ?? ''), 0, 20);
        if ($state === '') {
            return null;
        }
        $why = trim((string) ($v['reason'] ?? ''));
        $reason = $why !== '' && strtoupper($why) !== 'NONE' ? mb_substr($why, 0, 255) : ($state === 'APPROVED' ? null : $row['reason']);
        $deleted = $state === 'DELETED';
        $pdo->prepare('UPDATE wa_templates SET status = ?, reason = ?, status_at = ?, meta_id = COALESCE(meta_id, ?), deleted_at = '
                . ($deleted ? 'COALESCE(deleted_at, ?)' : 'deleted_at') . ', updated_at = ? WHERE id = ?')
            ->execute(array_merge([$state, $reason, $at, $metaId !== '' ? $metaId : null], $deleted ? [$at] : [], [$at, $row['id']]));
    } elseif ($field === 'template_category_update') {
        $new = strtoupper((string) ($v['new_category'] ?? ($v['correct_category'] ?? '')));
        if ($new === '') {
            return null;
        }
        $pdo->prepare('UPDATE wa_templates SET meta_category = ?, updated_at = ? WHERE id = ?')
            ->execute([$new !== $row['category'] ? substr($new, 0, 12) : null, $at, $row['id']]);
    } elseif ($field === 'message_template_quality_update') {
        $pdo->prepare('UPDATE wa_templates SET quality = ?, updated_at = ? WHERE id = ?')
            ->execute([tg_wat_quality($v['new_quality_score'] ?? ''), $at, $row['id']]);
    } else {
        return null;
    }
    return $row;
}
