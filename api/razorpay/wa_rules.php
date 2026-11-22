<?php
/**
 * WhatsApp automations (schema version 10): rules that send one approved template, or
 * for a keyword a short text, when something happens. Required by whatsapp.php, so the
 * worker, the webhook and the dashboard share it.
 *
 * A rule is a trigger (someone paid, a checkout was not paid, X hours before or after the
 * class, joined or not, a tag added, a keyword in a message), a wait, a template and an
 * optional audience filter. The worker (step 2c, tg_rules_plan) turns each trigger into
 * one bulk outbox row per person (source 'automation', source_id the rule, booking_id
 * NULL), so the daily limit, quiet hours, pause, the test list, STOP, consent, the
 * template's status, retries and the send log all apply exactly as for broadcasts
 * (tg_outbox_bulk in outbox.php). Keyword rules run in the webhook (tg_rules_keyword).
 *
 * Only things that happen after a rule was switched on count, and only within the last
 * 24 hours: switching a rule on never reaches back. One message per rule per person and
 * event, by the dedupe key.
 *
 * The built-in booking messages (confirmation, reminders, checkout coupon) are not rules:
 * they stay in code and show on the Automations page as locked rows.
 */

/** What can start a rule, in the words the form shows. */
const TG_RULE_TRIGGERS = [
    'paid'           => 'Someone pays for a seat',
    'addon_paid'     => 'Someone pays with the add-on',
    'unpaid'         => 'A checkout is not paid after some minutes',
    'before_session' => 'Some hours before the class',
    'after_session'  => 'Some hours after the class ends',
    'joined'         => 'The class ends: people who opened their join link',
    'not_joined'     => 'The class ends: people who did not open their join link',
    'tag_added'      => 'A tag is added to someone',
    'keyword'        => 'Someone sends a message with a keyword',
];
/** The class runs 3 hours: "after the class" counts from here. */
const TG_RULE_CLASS_LENGTH = 3 * 3600;
/** A trigger older than this is never acted on (and never before the rule was switched on). */
const TG_RULE_LOOKBACK = 86400;
/** A rule's message may go up to this long after its time, then it is dropped and shown as not sent. */
const TG_RULE_EXPIRY = 86400;
/** One keyword reply per rule per number in this long. */
const TG_RULE_KEYWORD_GAP = 6 * 3600;
/** The longest wait after a trigger: 7 days. */
const TG_RULE_DELAY_MAX = 7 * 1440;
/** Keywords: how many, how long each, and in all. */
const TG_RULE_KEYWORDS_MAX = 10;
const TG_RULE_KEYWORD_MIN = 2;
const TG_RULE_KEYWORD_LEN = 30;
const TG_RULE_VALUE_MAX = 320;
/** The unpaid statuses: a checkout that never became a paid seat (the same as the broadcast audience). */
const TG_RULE_UNPAID = ['started', 'failed', 'order_failed'];

/**
 * The ready-made rules (seeded off in store.php by their key). Each suggests a template
 * that "Make its template" writes as a draft, with the link button left empty, so the
 * checks fail until Deenu adds his own page. Nothing is invented: no price, no link.
 */
const TG_RULE_PRESETS = [
    'recording_offer' => [
        'about'    => 'Two hours after the class ends, people who paid without the add-on get one message about the recording and the Vault. A marketing template.',
        'template' => [
            'name'     => 'recording_offer',
            'category' => 'MARKETING',
            'body'     => "Hi {{1}}, thank you for joining the {{2}} today. The full recording and the AI Editor's Vault are ready if you want to watch it again at your own pace. Tap below to get them.",
            'examples' => ['Priya', 'AI Video Editing Masterclass'],
            'fields'   => ['first_name', 'event'],
            'button'   => 'Get the recording',
        ],
    ],
    'feedback' => [
        'about'    => 'A day after the class ends, people who opened their join link are asked how it went. A utility template; Meta may call it marketing.',
        'template' => [
            'name'     => 'class_feedback',
            'category' => 'UTILITY',
            'body'     => "Hi {{1}}, thank you for attending the {{2}}. Could you tell us how it went? It takes two minutes and helps us make the next class better.",
            'examples' => ['Priya', 'AI Video Editing Masterclass'],
            'fields'   => ['first_name', 'event'],
            'button'   => 'Give feedback',
        ],
    ],
];

/** A rule's audience with every value checked: tag_in, tag_out, addon ('', 'yes' or 'no'), offers_box. */
function tg_rule_clean_audience(array $a): array
{
    $tag = static function ($v): string {
        $v = strtolower(trim(is_scalar($v) ? (string) $v : ''));
        return preg_match('/^[a-z0-9][a-z0-9-]{0,29}$/', $v) ? $v : '';
    };
    $addon = is_scalar($a['addon'] ?? null) ? (string) $a['addon'] : '';
    return [
        'tag_in'     => $tag($a['tag_in'] ?? ''),
        'tag_out'    => $tag($a['tag_out'] ?? ''),
        'addon'      => in_array($addon, ['yes', 'no'], true) ? $addon : '',
        'offers_box' => !empty($a['offers_box']),
    ];
}

/** The audience in a few words: "Everyone it applies to", or "without the add-on, tag vip". */
function tg_rule_audience_text(array $a): string
{
    $a = tg_rule_clean_audience($a);
    $parts = [];
    if ($a['addon'] !== '') {
        $parts[] = $a['addon'] === 'yes' ? 'with the add-on' : 'without the add-on';
    }
    if ($a['tag_in'] !== '') {
        $parts[] = 'tag ' . $a['tag_in'];
    }
    if ($a['tag_out'] !== '') {
        $parts[] = 'not tag ' . $a['tag_out'];
    }
    if ($a['offers_box']) {
        $parts[] = 'ticked the offers box';
    }
    return $parts ? ucfirst(implode(', ', $parts)) : 'Everyone it applies to';
}

/** A rule row with its JSON decoded, or null. */
function tg_rule_get(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    try {
        $q = $pdo->prepare('SELECT * FROM wa_rules WHERE id = ?');
        $q->execute([$id]);
        $r = $q->fetch();
    } catch (Throwable $e) {
        return null;
    }
    return $r ? tg_rule_decode($r) : null;
}

function tg_rule_decode(array $r): array
{
    $fields = json_decode((string) ($r['fields'] ?? ''), true);
    $r['fields'] = [
        'fields' => is_array($fields['fields'] ?? null) ? $fields['fields'] : [],
        'fixed'  => is_array($fields['fixed'] ?? null) ? $fields['fixed'] : [],
    ];
    $aud = json_decode((string) ($r['audience'] ?? ''), true);
    $r['audience'] = tg_rule_clean_audience(is_array($aud) ? $aud : []);
    foreach (['id', 'delay_minutes', 'enabled', 'sent'] as $k) {
        $r[$k] = (int) $r[$k];
    }
    $r['template_id'] = $r['template_id'] !== null ? (int) $r['template_id'] : null;
    return $r;
}

/** The rule's template with its fields applied, or null when it has none or it is gone. */
function tg_rule_template(PDO $pdo, array $r): ?array
{
    if (empty($r['template_id'])) {
        return null;
    }
    $t = tg_wat_get($pdo, (int) $r['template_id']);
    return $t === null ? null : tg_bc_apply_fields($t, $r['fields']['fields']);
}

/** The keywords in a stored value: lowercase, single spaces, no repeats. */
function tg_rule_keywords(string $value): array
{
    $out = [];
    foreach (explode(',', $value) as $k) {
        $k = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $k) ?? ''));
        if ($k !== '') {
            $out[$k] = true;
        }
    }
    return array_map('strval', array_keys($out));
}

/** The keyword a message matches (the whole message, or a whole word or phrase in it, any case), or null. */
function tg_rule_match(array $keywords, string $text): ?string
{
    $text = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? ''));
    if ($text === '') {
        return null;
    }
    foreach ($keywords as $k) {
        if ($text === $k || preg_match('/(?<![\p{L}\p{M}\p{N}])' . preg_quote($k, '/') . '(?![\p{L}\p{M}\p{N}])/u', $text)) {
            return $k;
        }
    }
    return null;
}

/** A word as the STOP check compares it: letters, numbers and single spaces, lowercase. */
function tg_rule_stop_form(string $k): string
{
    $k = trim(mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', '', $k) ?? ''));
    return preg_replace('/\s+/', ' ', $k) ?? '';
}

/**
 * Checks a trigger's value. Returns [clean value, null] or [null, the reason in plain English].
 * unpaid: minutes 10 to 1,440. before/after: hours 1 to 168. tag_added: a tag.
 * keyword: 1 to 10 keywords of 2 to 30 characters, 320 in all, never a STOP or START word.
 * The others take no value.
 */
function tg_rule_check_value(string $type, string $raw): array
{
    $raw = trim($raw);
    switch ($type) {
        case 'unpaid':
            return ctype_digit($raw) && (int) $raw >= 10 && (int) $raw <= 1440 ? [(string) (int) $raw, null]
                : [null, 'For a checkout not paid, type the minutes to wait: 10 to 1,440.'];
        case 'before_session':
        case 'after_session':
            return ctype_digit($raw) && (int) $raw >= 1 && (int) $raw <= 168 ? [(string) (int) $raw, null]
                : [null, 'Type the hours ' . ($type === 'before_session' ? 'before the class' : 'after the class ends') . ': 1 to 168.'];
        case 'tag_added':
            $tag = strtolower($raw);
            return preg_match('/^[a-z0-9][a-z0-9-]{0,29}$/', $tag) ? [$tag, null]
                : [null, 'Type the tag: up to 30 letters, numbers and hyphens, starting with a letter or number.'];
        case 'keyword':
            if (mb_strlen($raw) > TG_RULE_VALUE_MAX) {
                return [null, 'The keywords are too long: 320 characters in all at most.'];
            }
            $words = tg_rule_keywords($raw);
            if ($words === [] || count($words) > TG_RULE_KEYWORDS_MAX) {
                return [null, 'Type 1 to 10 keywords, separated by commas.'];
            }
            foreach ($words as $w) {
                if (mb_strlen($w) < TG_RULE_KEYWORD_MIN || mb_strlen($w) > TG_RULE_KEYWORD_LEN) {
                    return [null, 'Each keyword is 2 to 30 characters. "' . $w . '" is not.'];
                }
                if (!preg_match('/^[\p{L}\p{M}\p{N}][\p{L}\p{M}\p{N} \'-]*$/u', $w)) {
                    return [null, 'Keywords are letters, numbers, spaces and hyphens. "' . $w . '" has something else.'];
                }
                $stop = tg_rule_stop_form($w);
                if (in_array($stop, TG_WA_STOP_WORDS, true) || in_array($stop, TG_WA_START_WORDS, true)) {
                    return [null, '"' . $w . '" is a STOP or START word. Those always turn messages off or on, so they can\'t start a rule.'];
                }
            }
            $value = implode(', ', $words);
            return mb_strlen($value) <= TG_RULE_VALUE_MAX ? [$value, null] : [null, 'The keywords are too long: 320 characters in all at most.'];
        default:
            return ['', null];
    }
}

/** "2 hours", "1 hour", "45 minutes", "1 day". */
function tg_rule_span(int $minutes): string
{
    if ($minutes > 0 && $minutes % 1440 === 0) {
        $d = intdiv($minutes, 1440);
        return $d . ' day' . ($d === 1 ? '' : 's');
    }
    if ($minutes > 0 && $minutes % 60 === 0) {
        $h = intdiv($minutes, 60);
        return $h . ' hour' . ($h === 1 ? '' : 's');
    }
    return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
}

/** The trigger and its wait in words: "2 hours after the class ends", "30 minutes after someone pays". */
function tg_rule_trigger_text(array $r): string
{
    $v = (string) ($r['trigger_value'] ?? '');
    $delay = (int) ($r['delay_minutes'] ?? 0);
    $after = static fn(string $event): string => $delay > 0 ? tg_rule_span($delay) . ' after ' . $event : 'When ' . $event;
    switch ((string) $r['trigger_type']) {
        case 'paid':
            return $after('someone pays for a seat');
        case 'addon_paid':
            return $after('someone pays with the add-on');
        case 'unpaid':
            return tg_rule_span((int) $v + $delay) . ' after a checkout that is still not paid';
        case 'before_session':
            return tg_rule_span(max(0, (int) $v * 60 - $delay)) . ' before the class';
        case 'after_session':
            return tg_rule_span((int) $v * 60 + $delay) . ' after the class ends';
        case 'joined':
            return ($delay > 0 ? tg_rule_span($delay) . ' after the class ends' : 'When the class ends') . ', to people who opened their join link';
        case 'not_joined':
            return ($delay > 0 ? tg_rule_span($delay) . ' after the class ends' : 'When the class ends') . ', to people who did not open their join link';
        case 'tag_added':
            return $after('the tag ' . $v . ' is added');
        case 'keyword':
            return 'When a message has ' . implode(' or ', array_map(static fn($k) => '"' . $k . '"', tg_rule_keywords($v)));
    }
    return (string) $r['trigger_type'];
}

/** True when the trigger is about a paid booking (its row carries the booking, checked again when it goes). */
function tg_rule_is_booking(string $type): bool
{
    return in_array($type, ['paid', 'addon_paid', 'before_session', 'after_session', 'joined', 'not_joined'], true);
}

/**
 * Does this number pass the rule's audience filter? $addon is the booking's add-on for a
 * booking trigger; otherwise any paid seat with the add-on under this number counts.
 */
function tg_rule_audience_ok(PDO $pdo, array $config, string $phone, array $a, ?bool $addon): bool
{
    $ten = substr($phone, -10);
    if ($a['tag_in'] !== '' || $a['tag_out'] !== '') {
        $q = $pdo->prepare('SELECT t.tag FROM wa_tags t JOIN wa_contacts wc ON wc.id = t.wa_contact_id WHERE wc.phone = ?');
        $q->execute([$phone]);
        $tags = array_fill_keys(array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN)), true);
        if (($a['tag_in'] !== '' && !isset($tags[$a['tag_in']])) || ($a['tag_out'] !== '' && isset($tags[$a['tag_out']]))) {
            return false;
        }
    }
    if ($a['addon'] !== '') {
        if ($addon === null) {
            $q = $pdo->prepare("SELECT 1 FROM bookings b JOIN contacts c ON c.id = b.contact_id
                WHERE (c.phone = ? OR c.phone = ?) AND b.status = 'paid' AND b.addon = 1 AND b.product = ? LIMIT 1");
            $q->execute([$phone, $ten, (string) $config['product']]);
            $addon = (bool) $q->fetchColumn();
        }
        if ($addon !== ($a['addon'] === 'yes')) {
            return false;
        }
    }
    if ($a['offers_box']) {
        $q = $pdo->prepare('SELECT 1 FROM contacts WHERE (phone = ? OR phone = ?) AND marketing_optin = 1 AND email_unsub_at IS NULL LIMIT 1');
        $q->execute([$phone, $ten]);
        if (!$q->fetchColumn()) {
            return false;
        }
    }
    return true;
}

/**
 * Worker step 2c: every enabled template rule (not keyword) turns what happened since it
 * was switched on, within the last 24 hours, into outbox rows. Returns how many were planned.
 * Called only while WhatsApp is on and not paused. A rule whose template is no longer
 * approved plans nothing (a health warning says so).
 */
function tg_rules_plan(PDO $pdo, array $config, int $now): int
{
    try {
        $rules = $pdo->query("SELECT * FROM wa_rules WHERE enabled = 1 AND action = 'template' AND trigger_type <> 'keyword' AND enabled_at IS NOT NULL ORDER BY id")->fetchAll();
    } catch (Throwable $e) {
        return 0; // before version 10
    }
    $planned = 0;
    foreach ($rules as $raw) {
        $r = tg_rule_decode($raw);
        try {
            $planned += tg_rule_plan_one($pdo, $config, $r, $now);
        } catch (Throwable $e) {
            error_log('whatsapp rules: rule ' . $r['id'] . ' not planned: ' . $e->getMessage());
        }
    }
    return $planned;
}

/**
 * The people a rule's trigger reached in (lower, now]: each with key (the dedupe key),
 * phone (as stored), moment (when it happened), until (the hard end, or null), booking
 * (id or null), session_at (for the "session" field), addon (the booking's, or null),
 * check ('paid' for booking triggers, 'unpaid' for checkouts).
 */
function tg_rule_subjects(PDO $pdo, array $config, array $r, int $lower, int $now): array
{
    $type = (string) $r['trigger_type'];
    $id = (int) $r['id'];
    $hours = (int) $r['trigger_value'] * 3600;
    $product = (string) $config['product'];
    $out = [];
    $paid = static function (string $where, array $args) use ($pdo, $product): array {
        $q = $pdo->prepare("SELECT b.id, b.session_at, b.addon, b.join_clicks, b.first_join_at, COALESCE(b.paid_at, b.created_at) AS paid_at, c.phone
            FROM bookings b JOIN contacts c ON c.id = b.contact_id
            WHERE b.status = 'paid' AND b.product = ? AND {$where} ORDER BY b.id");
        $q->execute(array_merge([$product], $args));
        return $q->fetchAll();
    };
    $add = static function (array $b, int $moment, ?int $until) use (&$out, $id): void {
        $out[] = ['key' => 'wa:r' . $id . ':b' . $b['id'], 'phone' => (string) $b['phone'], 'moment' => $moment, 'until' => $until,
            'booking' => (int) $b['id'], 'session_at' => $b['session_at'], 'addon' => (int) $b['addon'] === 1, 'check' => 'paid'];
    };
    switch ($type) {
        case 'paid':
        case 'addon_paid':
            $rows = $paid('COALESCE(b.paid_at, b.created_at) > ? AND COALESCE(b.paid_at, b.created_at) <= ?' . ($type === 'addon_paid' ? ' AND b.addon = 1' : ''),
                [tg_ist($lower), tg_ist($now)]);
            foreach ($rows as $b) {
                $add($b, tg_wa_ts((string) $b['paid_at']), null);
            }
            break;
        case 'before_session':
            // The moment is the session minus X hours; never after the class has started,
            // and only for someone who had paid by then (like the booking reminders).
            foreach ($paid('b.session_at > ? AND b.session_at <= ? AND b.session_at > ?', [tg_ist($lower + $hours), tg_ist($now + $hours), tg_ist($now)]) as $b) {
                $start = tg_wa_ts((string) $b['session_at']);
                $moment = $start - $hours;
                if (tg_wa_ts((string) $b['paid_at']) <= $moment) {
                    $add($b, $moment, $start);
                }
            }
            break;
        case 'after_session':
        case 'joined':
        case 'not_joined':
            $offset = TG_RULE_CLASS_LENGTH + ($type === 'after_session' ? $hours : 0);
            $where = 'b.session_at > ? AND b.session_at <= ?'
                . ($type === 'joined' ? ' AND (b.join_clicks > 0 OR b.first_join_at IS NOT NULL)' : '')
                . ($type === 'not_joined' ? ' AND b.join_clicks = 0 AND b.first_join_at IS NULL' : '');
            foreach ($paid($where, [tg_ist($lower - $offset), tg_ist($now - $offset)]) as $b) {
                $start = tg_wa_ts((string) $b['session_at']);
                // Only seats bought before the class ended.
                if (tg_wa_ts((string) $b['paid_at']) <= $start + TG_RULE_CLASS_LENGTH) {
                    $add($b, $start + $offset, null);
                }
            }
            break;
        case 'unpaid':
            // Each person's latest checkout, still not paid, X minutes after it was made.
            $wait = (int) $r['trigger_value'] * 60;
            $in = "'" . implode("', '", TG_RULE_UNPAID) . "'";
            $q = $pdo->prepare("SELECT b.id, b.contact_id, b.created_at, b.session_at, c.phone FROM bookings b JOIN contacts c ON c.id = b.contact_id
                WHERE b.product = ? AND b.status IN ({$in}) AND b.created_at > ? AND b.created_at <= ?
                  AND NOT EXISTS (SELECT 1 FROM bookings n WHERE n.contact_id = b.contact_id AND n.id > b.id) ORDER BY b.id");
            $q->execute([$product, tg_ist($lower - $wait), tg_ist($now - $wait)]);
            foreach ($q->fetchAll() as $b) {
                if (tg_nudge_paid_since($pdo, (int) $b['id'], $now)) {
                    continue;
                }
                $session = $b['session_at'] !== null ? tg_wa_ts((string) $b['session_at']) : null;
                $out[] = ['key' => 'wa:r' . $id . ':c' . $b['contact_id'] . ':' . ($session !== null ? gmdate('YmdHi', $session) : 'none'),
                    'phone' => (string) $b['phone'], 'moment' => tg_wa_ts((string) $b['created_at']) + $wait, 'until' => $session,
                    'booking' => (int) $b['id'], 'session_at' => null, 'addon' => null, 'check' => 'unpaid'];
            }
            break;
        case 'tag_added':
            $q = $pdo->prepare('SELECT t.wa_contact_id, t.added_at, wc.phone FROM wa_tags t JOIN wa_contacts wc ON wc.id = t.wa_contact_id
                WHERE t.tag = ? AND t.added_at > ? AND t.added_at <= ? ORDER BY t.added_at');
            $q->execute([(string) $r['trigger_value'], tg_ist($lower), tg_ist($now)]);
            foreach ($q->fetchAll() as $t) {
                $out[] = ['key' => 'wa:r' . $id . ':t' . $t['wa_contact_id'] . ':' . $r['trigger_value'], 'phone' => (string) $t['phone'],
                    'moment' => tg_wa_ts((string) $t['added_at']), 'until' => null, 'booking' => null, 'session_at' => null, 'addon' => null, 'check' => null];
            }
            break;
    }
    return $out;
}

/**
 * Plans one rule's messages. Someone who may not get it (STOP, no consent, the test list,
 * a field that can't be filled) gets a row already cancelled with the reason, so the
 * rule's page says why and the same event never plans again. Returns rows added.
 */
function tg_rule_plan_one(PDO $pdo, array $config, array $r, int $now): int
{
    $t = tg_rule_template($pdo, $r);
    if ($t === null || $t['deleted_at'] !== null || !in_array($t['status'], ['APPROVED', 'PAUSED'], true)) {
        return 0;
    }
    $lower = max(tg_wa_ts((string) $r['enabled_at']), $now - TG_RULE_LOOKBACK);
    if ($lower >= $now) {
        return 0;
    }
    $subjects = tg_rule_subjects($pdo, $config, $r, $lower, $now);
    if (!$subjects) {
        return 0;
    }
    $category = tg_wat_category($t);
    $fixed = array_map('strval', array_filter($r['fields']['fixed'], 'is_scalar'));
    $exists = $pdo->prepare('SELECT 1 FROM outbox WHERE dedupe_key = ?');
    $insert = $pdo->prepare("INSERT IGNORE INTO outbox (dedupe_key, channel, template, booking_id, to_addr, due_at, expires_at, status, last_error, alerted_at, params, source, source_id)
        VALUES (?, 'whatsapp', ?, NULL, ?, ?, ?, ?, ?, ?, ?, 'automation', ?)");
    $at = tg_ist($now);
    $planned = 0;
    foreach ($subjects as $s) {
        $phone = tg_wa_number($s['phone']);
        if ($phone === null) {
            continue;
        }
        $exists->execute([$s['key']]);
        if ($exists->fetchColumn()) {
            continue;
        }
        if (!tg_rule_audience_ok($pdo, $config, $phone, $r['audience'], $s['addon'])) {
            continue;
        }
        $due = $s['moment'] + $r['delay_minutes'] * 60;
        $expires = $due + TG_RULE_EXPIRY;
        if ($s['until'] !== null) {
            $expires = min($expires, (int) $s['until']);
        }
        if ($expires <= $now) {
            continue; // its time has passed
        }
        $person = tg_wat_person($pdo, $config, $phone);
        if (!empty($s['session_at'])) {
            $person['session_at'] = (string) $s['session_at'];
        }
        $values = tg_wat_values($config, $t, $person, $fixed, $now);
        $why = $values['missing'] ? 'Nothing to fill ' . implode(', ', $values['missing']) : null;
        // Who may get it, checked now and again when it goes. Quiet hours and the daily limit only hold it.
        if ($why === null) {
            $why = tg_wa_can_message($pdo, $config, $phone, 'template', $now);
        }
        if ($why === null && $category === 'MARKETING' && tg_wa_marketing_off($pdo, $phone)) {
            $why = 'They turned off marketing from you in WhatsApp';
        }
        unset($values['missing']);
        $params = ['body' => $values['body'], 'buttons' => (object) $values['buttons'], 'name' => tg_first_name((string) $person['name'])];
        if ($s['booking'] !== null) {
            $params['booking'] = $s['booking'];
            $params[$s['check'] === 'unpaid' ? 'check_paid' : 'need_paid'] = 1;
        }
        $insert->execute([$s['key'], 'c:' . $t['id'], $phone, tg_ist($due), tg_ist($expires), $why === null ? 'queued' : 'cancelled',
            $why !== null ? mb_substr($why, 0, 255) : null, $why !== null ? $at : null,
            json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $r['id']]);
        $planned += $insert->rowCount();
    }
    return $planned;
}

/**
 * A message someone sent us ($r from tg_wa_inbound, kind reply), checked against the
 * enabled keyword rules, in the webhook after Meta has its answer. The first rule that
 * matches decides: a short text (free, inside the 24-hour window they just opened) or a
 * template planned for the worker. Never to a STOP number, at most one reply per rule
 * per number in 6 hours, only while WhatsApp is on, not paused and the number allowed.
 * Returns the rule id that acted, or null.
 */
function tg_rules_keyword(PDO $pdo, array $config, array $r, int $now): ?int
{
    $words = trim((string) ($r['words'] ?? ''));
    $phone = (string) ($r['from'] ?? '');
    if ($words === '' || $phone === '' || ($r['kind'] ?? '') !== 'reply') {
        return null;
    }
    try {
        $rules = $pdo->query("SELECT * FROM wa_rules WHERE enabled = 1 AND trigger_type = 'keyword' AND enabled_at IS NOT NULL ORDER BY id")->fetchAll();
    } catch (Throwable $e) {
        return null;
    }
    $sentAt = (int) ($r['sent_at'] ?? $now);
    foreach ($rules as $raw) {
        $rule = tg_rule_decode($raw);
        if ($sentAt <= tg_wa_ts((string) $rule['enabled_at']) || tg_rule_match(tg_rule_keywords((string) $rule['trigger_value']), $words) === null) {
            continue;
        }
        // The first rule that matches decides, so one message never gets two automatic answers.
        if (!tg_wa_enabled($config) || tg_wa_paused($config) || !tg_wa_allowed($config, $phone) || tg_wa_stop_at($pdo, $phone) !== null) {
            return null;
        }
        if (!tg_rule_audience_ok($pdo, $config, $phone, $rule['audience'], null)) {
            return null;
        }
        $recent = tg_wa_value($pdo, "SELECT 1 FROM outbox WHERE source = 'automation' AND source_id = ? AND to_addr = ? AND due_at > ?
            AND status IN ('queued', 'sending', 'sent') LIMIT 1", [$rule['id'], $phone, tg_ist($now - TG_RULE_KEYWORD_GAP)]);
        if ($recent !== null) {
            return null;
        }
        $key = 'wa:r' . $rule['id'] . ':m' . (!empty($r['message_id']) ? (int) $r['message_id'] : substr(hash('sha256', (string) ($r['wa_id'] ?? '')), 0, 16));
        $at = tg_ist($now);
        if ($rule['action'] === 'text') {
            $text = trim((string) $rule['reply_text']);
            if ($text === '' || tg_wa_can_message($pdo, $config, $phone, 'reply', $now) !== null) {
                return null;
            }
            $claim = $pdo->prepare("INSERT IGNORE INTO outbox (dedupe_key, channel, template, booking_id, to_addr, due_at, expires_at, status, tries, next_try_at, source, source_id)
                VALUES (?, 'whatsapp', 'text', NULL, ?, ?, ?, 'sending', 0, ?, 'automation', ?)");
            $claim->execute([$key, $phone, $at, $at, $at, $rule['id']]);
            if ($claim->rowCount() !== 1) {
                return null; // already answered
            }
            $outboxId = (int) $pdo->lastInsertId();
            $sent = tg_wa_send_text_result($config, $phone, $text);
            // A keyword reply that fails concerns one person: it shows on the rule's page, not in an alert email.
            $pdo->prepare('UPDATE outbox SET status = ?, tries = 1, sent_at = ?, provider_id = ?, last_error = ?, alerted_at = ? WHERE id = ?')
                ->execute([$sent['ok'] ? 'sent' : 'failed', $sent['ok'] ? $at : null, $sent['id'], $sent['ok'] ? null : mb_substr((string) $sent['error'], 0, 255),
                    $sent['ok'] ? null : $at, $outboxId]);
            tg_wa_log_out($pdo, $phone, ['kind' => 'text', 'body' => $text, 'sent_by' => 'auto', 'wa_id' => $sent['id'], 'outbox_id' => $outboxId,
                'status' => $sent['ok'] ? 'sent' : 'failed', 'error' => $sent['ok'] ? null : $sent['error'], 'billable' => false], $now);
            if ($sent['ok']) {
                $pdo->prepare('UPDATE wa_rules SET sent = sent + 1 WHERE id = ?')->execute([$rule['id']]);
            }
            return $rule['id'];
        }
        // A template: planned now, sent by the worker within 5 minutes with every send-time check.
        $t = tg_rule_template($pdo, $rule);
        if ($t === null || $t['deleted_at'] !== null || $t['status'] !== 'APPROVED') {
            return null;
        }
        if (tg_wa_can_message($pdo, $config, $phone, 'template', $now) !== null) {
            return null;
        }
        $person = tg_wat_person($pdo, $config, $phone);
        $values = tg_wat_values($config, $t, $person, array_map('strval', array_filter($rule['fields']['fixed'], 'is_scalar')), $now);
        if ($values['missing']) {
            return null;
        }
        $params = ['body' => $values['body'], 'buttons' => (object) $values['buttons'], 'name' => tg_first_name((string) $person['name'])];
        $q = $pdo->prepare("INSERT IGNORE INTO outbox (dedupe_key, channel, template, booking_id, to_addr, due_at, expires_at, params, source, source_id)
            VALUES (?, 'whatsapp', ?, NULL, ?, ?, ?, ?, 'automation', ?)");
        $q->execute([$key, 'c:' . $t['id'], $phone, $at, tg_ist($now + TG_RULE_EXPIRY),
            json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $rule['id']]);
        return $q->rowCount() === 1 ? $rule['id'] : null;
    }
    return null;
}

/** Cancels a rule's waiting messages, with the reason. Returns how many. */
function tg_rule_cancel_queued(PDO $pdo, int $id, string $why): int
{
    $q = $pdo->prepare("UPDATE outbox SET status = 'cancelled', last_error = ? WHERE source = 'automation' AND source_id = ? AND status = 'queued'");
    $q->execute([$why, $id]);
    return $q->rowCount();
}
