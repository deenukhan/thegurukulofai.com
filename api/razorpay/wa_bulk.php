<?php
/**
 * WhatsApp to many people at once: broadcasts (schema version 9), and the audience and
 * daily limit that automation rules (wa_rules.php) share.
 * Required by whatsapp.php, so the worker, the webhook and the dashboard share it.
 *
 * A broadcast is one approved template (written on the Templates page) sent to a group.
 * Confirming it writes one outbox row per person ("bulk rows": source 'broadcast',
 * booking_id always NULL, the filled-in values in params), and the worker sends them in
 * batches after the booking messages (tg_outbox_bulk in outbox.php), checking each one
 * again at the moment it goes: the switches, STOP, consent, the template's status, quiet
 * hours for marketing and the daily limit.
 *
 * Who may get a broadcast (Deenu, 23 Nov 2026): people who agreed to WhatsApp booking
 * updates in the pop-up, or who messaged us first. Never anyone who said STOP. While the
 * test list has numbers, only those. "Ticked the offers box" is an extra filter, off by default.
 *
 * The daily limit counts different people sent any template in the last 24 hours, the way
 * Meta counts its messaging limit. Booking messages always go; bulk rows yield to them.
 */

/** The most different people who may get a business-started template in any 24 hours, unless Settings says otherwise. Meta's lowest tier. */
const TG_WA_DAILY_CAP = 250;
/** Each bulk row may go up to this long after its own time, then it is dropped and shown as not sent. */
const TG_WA_BULK_EXPIRY = 48 * 3600;
/** A message from them this soon after ours counts as a reply to it. */
const TG_WA_REPLY_WINDOW = 72 * 3600;
/** A broadcast may be spread over this many days at the daily limit, no more. */
const TG_BC_MAX_DAYS = 7;
/** A scheduled broadcast may start at most this far ahead. */
const TG_BC_MAX_AHEAD = 30 * 86400;
/** Resend to people who did not read: only this long after the broadcast finished. */
const TG_BC_RESEND_AFTER = 12 * 3600;

/**
 * Meta errors that concern one person, not the system. In a broadcast they are normal:
 * the row fails at once, with this reason, without an alert email or the "WhatsApp is
 * down" streak. Code => plain English.
 */
const TG_WA_PERSON_ERRORS = [
    131049 => 'Meta held it back: this person got many marketing messages recently',
    131050 => 'They turned off marketing from you in WhatsApp',
    131026 => 'Not on WhatsApp, or an old app',
    131047 => 'The 24-hour window was closed',
    131021 => 'Not a valid WhatsApp number',
];

/** The audiences a broadcast can go to, for the form. not_read_of is made only by "resend". */
const TG_BC_BASES = [
    'all'      => 'Everyone you may message',
    'buyers'   => 'Paid buyers',
    'addon'    => 'Paid buyers with the add-on',
    'no_addon' => 'Paid buyers without the add-on',
    'unpaid'   => 'Checkouts not paid',
    'messaged' => 'People who messaged you',
];
/** The periods for the bases that have one, and the default for each. */
const TG_BC_DAYS = [
    'unpaid'   => [7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'],
    'messaged' => [1 => 'Last 24 hours', 7 => 'Last 7 days', 30 => 'Last 30 days', 0 => 'Ever'],
];
const TG_BC_DAYS_DEFAULT = ['unpaid' => 7, 'messaged' => 30];

/** The daily limit in use: Settings, then the config, then 250. */
function tg_wa_daily_cap(array $config): int
{
    $v = $config['whatsapp']['daily_cap'] ?? null;
    return is_numeric($v) ? max(0, min(10000, (int) $v)) : TG_WA_DAILY_CAP;
}

/** Deenu's own number for test sends after go-live (Settings), or null. */
function tg_wa_my_number(array $config): ?string
{
    $v = (string) ($config['whatsapp']['my_number'] ?? '');
    return $v !== '' ? tg_wa_number($v) : null;
}

/** The end of the quiet hours: the next 9 AM IST after $now. */
function tg_wa_quiet_end(int $now): int
{
    $nine = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Asia/Kolkata'))->setTime(TG_WA_QUIET_TO, 0);
    if ($nine->getTimestamp() <= $now) {
        $nine = $nine->modify('+1 day');
    }
    return $nine->getTimestamp();
}

/** True when Meta told us (131050) this number turned off marketing from us. */
function tg_wa_marketing_off(PDO $pdo, string $phone): bool
{
    try {
        return tg_wa_value($pdo, 'SELECT marketing_off_at FROM wa_contacts WHERE phone = ?', [$phone]) !== null;
    } catch (Throwable $e) {
        return false;
    }
}

/** Records that this number turned off marketing from us (Meta's 131050). */
function tg_wa_set_marketing_off(PDO $pdo, string $phone, int $now): void
{
    if (tg_wa_contact_id($pdo, $phone, $now) > 0) {
        $pdo->prepare('UPDATE wa_contacts SET marketing_off_at = COALESCE(marketing_off_at, ?) WHERE phone = ?')->execute([tg_ist($now), $phone]);
    }
}

/** What Meta charges for one template of this category, in paise (an estimate). */
function tg_wa_rate(string $category): int
{
    return TG_WA_RATE_PAISE[strtoupper($category)] ?? TG_WA_RATE_PAISE['MARKETING'];
}

/** The category a custom template is charged at: Meta's, if it moved it, else ours. */
function tg_wat_category(array $t): string
{
    $meta = (string) ($t['meta_category'] ?? '');
    return strtoupper($meta !== '' ? $meta : (string) ($t['category'] ?? 'MARKETING'));
}

/**
 * The daily limit right now: cap; used (number => when its latest send in the last 24
 * hours turns 24 hours old); reserve (people with a booking WhatsApp message queued for
 * the next 24 hours who are not in used); room (how many more people bulk rows may reach);
 * free_at (when the next person drops out of the 24 hours, or null).
 */
function tg_wa_cap_state(PDO $pdo, array $config, int $now): array
{
    $cap = tg_wa_daily_cap($config);
    $used = [];
    // Free text (a keyword rule's reply, template 'text') is not a business-started template: it never counts.
    $q = $pdo->prepare("SELECT to_addr, MAX(sent_at) AS last FROM outbox WHERE channel = 'whatsapp' AND status = 'sent' AND template <> 'text' AND sent_at > ? GROUP BY to_addr");
    $q->execute([tg_ist($now - 86400)]);
    foreach ($q->fetchAll() as $r) {
        $used[(string) $r['to_addr']] = tg_wa_ts((string) $r['last']) + 86400;
    }
    $reserve = 0;
    $q = $pdo->prepare("SELECT DISTINCT to_addr FROM outbox WHERE channel = 'whatsapp' AND status IN ('queued', 'sending') AND source IS NULL AND due_at <= ?");
    $q->execute([tg_ist($now + 86400)]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $phone) {
        $reserve += isset($used[(string) $phone]) ? 0 : 1;
    }
    return [
        'cap'     => $cap,
        'used'    => $used,
        'reserve' => $reserve,
        'room'    => max(0, $cap - count($used) - $reserve),
        'free_at' => $used ? min($used) : null,
    ];
}

/** May one more business-started template go to this number under the daily limit? (A send from the inbox.) */
function tg_wa_cap_allows(PDO $pdo, array $config, string $phone, int $now): bool
{
    $s = tg_wa_cap_state($pdo, $config, $now);
    return isset($s['used'][$phone]) || $s['room'] > 0;
}

/** A saved audience with every value checked: base, session, tag_in, tag_out, days, offers_box, parent. */
function tg_bc_clean_audience(array $a): array
{
    $base = (string) ($a['base'] ?? 'buyers');
    if (!isset(TG_BC_BASES[$base]) && $base !== 'not_read_of') {
        $base = 'buyers';
    }
    $session = (string) ($a['session'] ?? '');
    $tag = static function ($v): string {
        $v = strtolower(trim(is_scalar($v) ? (string) $v : ''));
        return preg_match('/^[a-z0-9][a-z0-9-]{0,29}$/', $v) ? $v : '';
    };
    $days = 0;
    if (isset(TG_BC_DAYS[$base])) {
        $days = is_numeric($a['days'] ?? null) ? (int) $a['days'] : -1;
        if (!array_key_exists($days, TG_BC_DAYS[$base])) {
            $days = TG_BC_DAYS_DEFAULT[$base];
        }
    }
    return [
        'base'       => $base,
        'session'    => in_array($base, ['buyers', 'addon', 'no_addon'], true) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $session) ? $session : '',
        'tag_in'     => $tag($a['tag_in'] ?? ''),
        'tag_out'    => $tag($a['tag_out'] ?? ''),
        'days'       => $days,
        'offers_box' => !empty($a['offers_box']),
        'parent'     => $base === 'not_read_of' ? max(0, (int) ($a['parent'] ?? 0)) : 0,
    ];
}

/** The audience in a few words, such as "Paid buyers, session Sun 29 Nov, tag vip". */
function tg_bc_audience_text(array $a): string
{
    $a = tg_bc_clean_audience($a);
    $parts = [$a['base'] === 'not_read_of' ? 'People who did not read the first send' : TG_BC_BASES[$a['base']]];
    if ($a['session'] !== '') {
        $parts[] = 'session ' . (new DateTimeImmutable($a['session'], new DateTimeZone('Asia/Kolkata')))->format('D j M');
    }
    if (isset(TG_BC_DAYS[$a['base']])) {
        $parts[] = strtolower(TG_BC_DAYS[$a['base']][$a['days']] ?? '');
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
    return implode(', ', array_filter($parts, static fn($p) => $p !== ''));
}

/**
 * Everyone a broadcast with this audience would reach, and who was left out and why.
 * Returns people (list of phone, name, session_at, offers; newest contact first),
 * skipped (reason => count) and offers_box (how many of the people ticked the offers box).
 * Always applied: a valid number, once each, not STOP, consent or messaged us, not
 * marketing-off for a marketing template, and the test list while it has numbers.
 * Tags and the offers box narrow the audience; they are not counted as skipped.
 */
function tg_wa_audience(PDO $pdo, array $config, array $audience, string $category, int $now): array
{
    $a = tg_bc_clean_audience($audience);
    $product = (string) $config['product'];
    $marketing = strtoupper($category) === 'MARKETING';

    // Everyone in the contact store, by WhatsApp number (newest contact first).
    $dir = [];
    $byContact = [];
    foreach ($pdo->query('SELECT id, name, phone, consent_version, marketing_optin, email_unsub_at, wa_optout_at FROM contacts ORDER BY id DESC')->fetchAll() as $c) {
        $byContact[(int) $c['id']] = $c;
        $n = tg_wa_number((string) $c['phone']);
        if ($n !== null) {
            $dir[$n][] = $c;
        }
    }
    $sessions = [];
    $q = $pdo->prepare("SELECT contact_id, MAX(session_at) AS s FROM bookings WHERE status = 'paid' AND product = ? AND session_at IS NOT NULL GROUP BY contact_id");
    $q->execute([$product]);
    foreach ($q->fetchAll() as $r) {
        $sessions[(int) $r['contact_id']] = (string) $r['s'];
    }
    $profiles = [];
    $marketingOff = [];
    foreach ($pdo->query('SELECT phone, profile_name, marketing_off_at FROM wa_contacts ORDER BY id DESC')->fetchAll() as $r) {
        $profiles[(string) $r['phone']] = trim((string) $r['profile_name']);
        if ($r['marketing_off_at'] !== null) {
            $marketingOff[(string) $r['phone']] = true;
        }
    }
    // STOP: the latest STOP or START on record wins; else a contact marked opted out (older data).
    $stopKind = [];
    foreach ($pdo->query("SELECT phone, kind FROM wa_inbound WHERE kind IN ('stop', 'start') ORDER BY id")->fetchAll() as $r) {
        $stopKind[(string) $r['phone']] = (string) $r['kind'];
    }
    $messaged = array_fill_keys(array_map('strval', $pdo->query("SELECT DISTINCT phone FROM wa_inbound WHERE kind IN ('reply', 'start') AND wa_id NOT LIKE 'admin:%'")
        ->fetchAll(PDO::FETCH_COLUMN)), true);

    // ---- The base: numbers as stored, in order. ----
    $raw = [];
    $phonesOf = static function (array $ids) use ($byContact): array {
        $out = [];
        foreach ($ids as $id) {
            if (isset($byContact[(int) $id])) {
                $out[] = (string) $byContact[(int) $id]['phone'];
            }
        }
        return $out;
    };
    switch ($a['base']) {
        case 'all':
            foreach ($byContact as $c) {
                if ((string) $c['phone'] !== '') {
                    $raw[] = (string) $c['phone'];
                }
            }
            foreach (array_keys($profiles) as $phone) {
                if (!isset($dir[(string) $phone])) {
                    $raw[] = (string) $phone;
                }
            }
            break;
        case 'buyers':
        case 'addon':
        case 'no_addon':
            $sql = "SELECT contact_id FROM bookings WHERE status = 'paid' AND product = ?" . ($a['session'] !== '' ? ' AND session_at = ?' : '')
                . ' GROUP BY contact_id' . ($a['base'] === 'addon' ? ' HAVING MAX(addon) = 1' : ($a['base'] === 'no_addon' ? ' HAVING MAX(addon) = 0' : ''))
                . ' ORDER BY contact_id DESC';
            $q = $pdo->prepare($sql);
            $q->execute($a['session'] !== '' ? [$product, $a['session']] : [$product]);
            $raw = $phonesOf($q->fetchAll(PDO::FETCH_COLUMN));
            break;
        case 'unpaid':
            // Each person's latest checkout, unpaid, in the period, and no seat since.
            $q = $pdo->prepare("SELECT b.id, b.contact_id FROM bookings b
                WHERE b.product = ? AND b.status IN ('started', 'failed', 'order_failed') AND b.created_at >= ?
                  AND NOT EXISTS (SELECT 1 FROM bookings n WHERE n.contact_id = b.contact_id AND n.id > b.id)
                ORDER BY b.contact_id DESC");
            $q->execute([$product, tg_ist($now - $a['days'] * 86400)]);
            $ids = [];
            foreach ($q->fetchAll() as $b) {
                if (!tg_nudge_paid_since($pdo, (int) $b['id'], $now)) {
                    $ids[] = (int) $b['contact_id'];
                }
            }
            $raw = $phonesOf($ids);
            break;
        case 'messaged':
            $sql = "SELECT phone, MAX(id) AS last FROM wa_inbound WHERE kind IN ('reply', 'start') AND wa_id NOT LIKE 'admin:%'"
                . ($a['days'] > 0 ? ' AND received_at >= ?' : '') . ' GROUP BY phone ORDER BY last DESC';
            $q = $pdo->prepare($sql);
            $q->execute($a['days'] > 0 ? [tg_ist($now - $a['days'] * 86400)] : []);
            $raw = array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
            break;
        case 'not_read_of':
            $q = $pdo->prepare("SELECT to_addr FROM outbox WHERE source = 'broadcast' AND source_id = ? AND status = 'sent' AND read_at IS NULL ORDER BY id");
            $q->execute([$a['parent']]);
            $raw = array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
            break;
    }

    // ---- Filters that narrow it: tags and the offers box. ----
    $tagged = static function (string $tag) use ($pdo): array {
        $q = $pdo->prepare('SELECT wc.phone FROM wa_tags t JOIN wa_contacts wc ON wc.id = t.wa_contact_id WHERE t.tag = ?');
        $q->execute([$tag]);
        return array_fill_keys(array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN)), true);
    };
    $tagIn = $a['tag_in'] !== '' ? $tagged($a['tag_in']) : null;
    $tagOut = $a['tag_out'] !== '' ? $tagged($a['tag_out']) : [];

    $people = [];
    $skipped = [];
    $seen = [];
    $offersCount = 0;
    $skip = static function (string $why) use (&$skipped): void {
        $skipped[$why] = ($skipped[$why] ?? 0) + 1;
    };
    foreach ($raw as $r) {
        $phone = tg_wa_number($r);
        if ($phone === null) {
            $skip('Not a valid WhatsApp number');
            continue;
        }
        if (isset($seen[$phone])) {
            $skip('The same number twice');
            continue;
        }
        $seen[$phone] = true;
        $contacts = $dir[$phone] ?? [];
        $offers = false;
        foreach ($contacts as $c) {
            $offers = $offers || ((int) $c['marketing_optin'] === 1 && $c['email_unsub_at'] === null);
        }
        if (($tagIn !== null && !isset($tagIn[$phone])) || isset($tagOut[$phone]) || ($a['offers_box'] && !$offers)) {
            continue;
        }
        $stopped = isset($stopKind[$phone]) ? $stopKind[$phone] === 'stop'
            : (bool) array_filter($contacts, static fn($c) => $c['wa_optout_at'] !== null);
        if ($stopped) {
            $skip('Said STOP');
            continue;
        }
        $consent = (bool) array_filter($contacts, static fn($c) => $c['consent_version'] !== null);
        if (!$consent && !isset($messaged[$phone])) {
            $skip('Did not agree to booking updates and never messaged you');
            continue;
        }
        if ($marketing && isset($marketingOff[$phone])) {
            $skip('Turned off your marketing in WhatsApp');
            continue;
        }
        if (!tg_wa_allowed($config, $phone)) {
            $skip('Not on the WhatsApp test list');
            continue;
        }
        $name = '';
        $session = null;
        foreach ($contacts as $c) {
            if ($name === '' && trim((string) $c['name']) !== '') {
                $name = trim((string) $c['name']);
            }
            $s = $sessions[(int) $c['id']] ?? null;
            if ($s !== null && ($session === null || $s > $session)) {
                $session = $s;
            }
        }
        if ($name === '') {
            $name = $profiles[$phone] ?? '';
        }
        $people[] = ['phone' => $phone, 'name' => $name, 'session_at' => $session, 'offers' => $offers];
        $offersCount += $offers ? 1 : 0;
    }
    arsort($skipped);
    return ['people' => $people, 'skipped' => $skipped, 'offers_box' => $offersCount];
}

/** A broadcast row with its JSON decoded, or null. */
function tg_bc_get(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $q = $pdo->prepare('SELECT * FROM wa_broadcasts WHERE id = ?');
    $q->execute([$id]);
    $b = $q->fetch();
    if (!$b) {
        return null;
    }
    $fields = json_decode((string) $b['fields'], true);
    $b['fields'] = [
        'fields' => is_array($fields['fields'] ?? null) ? $fields['fields'] : [],
        'fixed'  => is_array($fields['fixed'] ?? null) ? $fields['fixed'] : [],
    ];
    $aud = json_decode((string) $b['audience'], true);
    $b['audience'] = tg_bc_clean_audience(is_array($aud) ? $aud : []);
    $b['id'] = (int) $b['id'];
    $b['template_id'] = (int) $b['template_id'];
    return $b;
}

/**
 * The template as this broadcast fills it: its own field for each slot (b1 for {{1}},
 * u0 for the first button's link) replaced by the broadcast's choice.
 */
function tg_bc_apply_fields(array $t, array $map): array
{
    foreach (tg_wat_slots($t) as $key => $field) {
        $f = (string) ($map[$key] ?? $field);
        $f = isset(TG_WA_FIELDS[$f]) ? $f : $field;
        if ($key[0] === 'b') {
            $t['fields'][(int) substr($key, 1) - 1] = $f;
        } else {
            $t['buttons'][(int) substr($key, 1)]['field'] = $f;
        }
    }
    return $t;
}

/** The broadcast's template with its fields applied, or null when the template is gone. */
function tg_bc_template(PDO $pdo, array $b): ?array
{
    $t = tg_wat_get($pdo, (int) $b['template_id']);
    return $t === null ? null : tg_bc_apply_fields($t, $b['fields']['fields']);
}

/**
 * Who the broadcast reaches now, each with the values filled in for them. People whose
 * message can't be filled (a session they never paid for, a fixed text left empty) are
 * skipped with the reason. Returns people (phone, name, values, ...), skipped, offers_box,
 * category and template. Null when the template is gone.
 */
function tg_bc_people(PDO $pdo, array $config, array $b, int $now): ?array
{
    $t = tg_bc_template($pdo, $b);
    if ($t === null) {
        return null;
    }
    $category = tg_wat_category($t);
    $aud = tg_wa_audience($pdo, $config, $b['audience'], $category, $now);
    $fixed = array_map('strval', array_filter($b['fields']['fixed'], 'is_scalar'));
    $people = [];
    $offers = 0;
    foreach ($aud['people'] as $p) {
        $values = tg_wat_values($config, $t, $p, $fixed, $now);
        if ($values['missing']) {
            $why = 'Nothing to fill ' . implode(', ', $values['missing']);
            $aud['skipped'][$why] = ($aud['skipped'][$why] ?? 0) + 1;
            continue;
        }
        unset($values['missing']);
        $people[] = $p + ['values' => $values];
        $offers += $p['offers'] ? 1 : 0;
    }
    arsort($aud['skipped']);
    return ['people' => $people, 'skipped' => $aud['skipped'], 'offers_box' => $offers, 'category' => $category, 'template' => $t];
}

/** A short fingerprint of what a test checked: the template, its fields and the audience. */
function tg_bc_hash(array $b): string
{
    $fields = ['fields' => (array) $b['fields']['fields'], 'fixed' => (array) $b['fields']['fixed']];
    ksort($fields['fields']);
    ksort($fields['fixed']);
    $aud = tg_bc_clean_audience($b['audience']);
    ksort($aud);
    return substr(hash('sha256', json_encode([(int) $b['template_id'], $fields, $aud], JSON_UNESCAPED_UNICODE)), 0, 16);
}

/**
 * How many people go on each day at the daily limit: the first day takes what is left of
 * today's room (never more than the limit), every later day the full limit. Index 0 is
 * the start day. Empty when nobody is waiting, or the limit is 0.
 */
function tg_bc_day_plan(int $count, int $cap, int $room): array
{
    if ($count <= 0 || $cap <= 0) {
        return [];
    }
    $plan = [min($count, max(0, min($cap, $room)))];
    $left = $count - $plan[0];
    while ($left > 0) {
        $take = min($cap, $left);
        $plan[] = $take;
        $left -= $take;
    }
    return $plan;
}

/** "Waiting: ..." reasons a held bulk row carries; on expiry they become "Not sent: ...". */
function tg_bulk_hold_reason(string $why): string
{
    return 'Waiting: ' . $why;
}
