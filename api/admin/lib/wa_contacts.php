<?php
/**
 * WhatsApp > Contacts and WhatsApp > Overview (Phase 5).
 *
 * Contacts: one row per WhatsApp number, merging the contact store (people who filled
 * the booking form, keyed by email) with wa_contacts (everyone we talked to on WhatsApp,
 * keyed by number). A number in both shows once. A wa_contacts row is made only when it
 * is needed (a tag is added), with tg_wa_contact_id().
 *
 * The list is built in PHP from a handful of indexed queries (never one per row),
 * because numbers in `contacts` are stored as typed (10 digits, or with 91) and must be
 * normalised with tg_wa_number before they can be merged. Search and chips then narrow
 * the merged list, and the page shows 100 rows of it.
 *
 * Overview: conversations, the spend estimate, Meta's quality rating and messaging
 * limit, messages per day, and the best and weakest templates, all from our own message
 * log (wa_messages keeps its rows after the text is removed, so old periods stay right).
 */

/** Rows a page of the contact list shows, and the most a bulk tag change may touch. */
const ADMIN_WAC_PAGE = 100;
/** A template needs this many sends in the period to be ranked best or weakest. */
const ADMIN_WA_RANK_MIN = 10;

/**
 * Everyone on WhatsApp we know of, one row per number, newest message first. Each row:
 * phone, name, email, contact_id (the newest contact with this number), wa_contact_id,
 * type (buyer, lead or whatsapp), tags (list), last_msg_at, consent (the newest consent
 * version, or null), messaged (wrote to us first), stop, marketing_off, offers (ticked
 * the offers box and still subscribed), reachable (passes the person part of the
 * template purpose rule: consent or messaged us, and no STOP).
 */
function admin_wac_all(PDO $pdo, array $config): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $buyers = array_fill_keys(array_map('intval', array_column(rows($pdo, "SELECT DISTINCT contact_id FROM bookings WHERE status = 'paid' AND product = ?",
        [(string) $config['product']]), 'contact_id')), true);
    $list = [];
    $blank = static fn(string $phone): array => ['phone' => $phone, 'name' => '', 'email' => '', 'contact_id' => null, 'wa_contact_id' => null,
        'type' => 'whatsapp', 'tags' => [], 'last_msg_at' => null, 'consent' => null, 'messaged' => false, 'stop' => false, 'optout' => false,
        'marketing_off' => false, 'offers' => false, 'reachable' => false, 'profile' => '', 'seen' => ''];

    // The contact store, newest first, so the first contact seen for a number is its newest.
    foreach ($pdo->query("SELECT id, name, email, phone, consent_version, marketing_optin, email_unsub_at, wa_optout_at, created_at
            FROM contacts WHERE phone <> '' ORDER BY id DESC")->fetchAll() as $c) {
        $phone = tg_wa_number((string) $c['phone']);
        if ($phone === null) {
            continue;
        }
        $r = $list[$phone] ?? $blank($phone);
        if ($r['contact_id'] === null) {
            $r['contact_id'] = (int) $c['id'];
            $r['email'] = (string) $c['email'];
            $r['seen'] = (string) $c['created_at'];
            $r['type'] = 'lead';
        }
        if ($r['name'] === '' && trim((string) $c['name']) !== '') {
            $r['name'] = trim((string) $c['name']);
        }
        if ($r['consent'] === null && $c['consent_version'] !== null) {
            $r['consent'] = (string) $c['consent_version'];
        }
        $r['offers'] = $r['offers'] || ((int) $c['marketing_optin'] === 1 && $c['email_unsub_at'] === null);
        $r['optout'] = $r['optout'] || $c['wa_optout_at'] !== null;
        if (isset($buyers[(int) $c['id']])) {
            $r['type'] = 'buyer';
        }
        $list[$phone] = $r;
    }

    // Everyone we talked to on WhatsApp, with their tags.
    $tags = [];
    foreach ($pdo->query('SELECT wa_contact_id, tag FROM wa_tags ORDER BY tag')->fetchAll() as $t) {
        $tags[(int) $t['wa_contact_id']][] = (string) $t['tag'];
    }
    foreach ($pdo->query('SELECT id, phone, profile_name, last_msg_at, marketing_off_at, created_at FROM wa_contacts')->fetchAll() as $w) {
        $phone = (string) $w['phone'];
        $r = $list[$phone] ?? $blank($phone);
        $r['wa_contact_id'] = (int) $w['id'];
        $r['profile'] = trim((string) $w['profile_name']);
        $r['last_msg_at'] = $w['last_msg_at'];
        $r['marketing_off'] = $w['marketing_off_at'] !== null;
        $r['tags'] = $tags[(int) $w['id']] ?? [];
        if ($r['seen'] === '') {
            $r['seen'] = (string) $w['created_at'];
        }
        $list[$phone] = $r;
    }

    // STOP: the latest STOP or START on record wins; else a contact marked opted out (older data).
    $stopKind = [];
    foreach ($pdo->query("SELECT phone, kind FROM wa_inbound WHERE kind IN ('stop', 'start') ORDER BY id")->fetchAll() as $s) {
        $stopKind[(string) $s['phone']] = (string) $s['kind'];
    }
    $messaged = array_fill_keys(array_map('strval', $pdo->query("SELECT DISTINCT phone FROM wa_inbound WHERE kind IN ('reply', 'start') AND wa_id NOT LIKE 'admin:%'")
        ->fetchAll(PDO::FETCH_COLUMN)), true);
    foreach ($list as $phone => &$r) {
        $phone = (string) $phone;
        $r['stop'] = isset($stopKind[$phone]) ? $stopKind[$phone] === 'stop' : $r['optout'];
        $r['messaged'] = isset($messaged[$phone]);
        $r['reachable'] = !$r['stop'] && ($r['consent'] !== null || $r['messaged']);
        if ($r['name'] === '') {
            $r['name'] = $r['profile'];
        }
    }
    unset($r);

    // Newest message first; people with no message yet after them, newest first.
    usort($list, static fn(array $a, array $b): int => [(string) $b['last_msg_at'], $b['seen'], $b['phone']] <=> [(string) $a['last_msg_at'], $a['seen'], $a['phone']]);
    return $memo = $list;
}

/** The chips on the contact list: key => label, with one chip per tag in use ("t:<tag>"). */
function admin_wac_chips(PDO $pdo): array
{
    $chips = ['' => 'All', 'buyers' => 'Buyers', 'leads' => 'Leads', 'messaged' => 'Messaged us', 'stop' => 'STOP'];
    foreach (rows($pdo, 'SELECT tag FROM wa_tags GROUP BY tag ORDER BY tag LIMIT 30') as $t) {
        $chips['t:' . $t['tag']] = '#' . $t['tag'];
    }
    return $chips + ['offers' => 'Ticked the offers box'];
}

/**
 * The contact list narrowed by a chip (show) and a search (q: name, email or number).
 * Returns the rows, in order.
 */
function admin_wac_list(PDO $pdo, array $config, array $filter): array
{
    $show = (string) ($filter['show'] ?? '');
    $q = mb_strtolower(trim((string) ($filter['q'] ?? '')));
    $digits = preg_replace('/\D+/', '', $q) ?? '';
    $numberLike = $digits !== '' && preg_match('/^[\d\s+()-]+$/', $q);
    $tag = str_starts_with($show, 't:') ? substr($show, 2) : null;
    return array_values(array_filter(admin_wac_all($pdo, $config), static function (array $r) use ($show, $q, $digits, $numberLike, $tag): bool {
        $keep = match (true) {
            $tag !== null        => in_array($tag, $r['tags'], true),
            $show === 'buyers'   => $r['type'] === 'buyer',
            $show === 'leads'    => $r['type'] === 'lead',
            $show === 'messaged' => $r['messaged'],
            $show === 'stop'     => $r['stop'],
            $show === 'offers'   => $r['offers'],
            default              => true,
        };
        if (!$keep || $q === '') {
            return $keep;
        }
        if ($numberLike) {
            return str_contains($r['phone'], $digits);
        }
        return str_contains(mb_strtolower($r['name']), $q) || str_contains(mb_strtolower($r['email']), $q) || str_contains(mb_strtolower($r['profile']), $q);
    }));
}

/** The four numbers at the top of the contact list, over everyone (not the filter). */
function admin_wac_kpis(PDO $pdo, array $config): string
{
    $all = admin_wac_all($pdo, $config);
    $reach = count(array_filter($all, static fn($r) => $r['reachable']));
    $stop = count(array_filter($all, static fn($r) => $r['stop']));
    $off = count(array_filter($all, static fn($r) => $r['marketing_off']));
    $tagged = count(array_filter($all, static fn($r) => $r['tags'] !== []));
    $test = tg_wa_test_numbers($config);
    return ui_kpis([
        ['label' => 'Reachable', 'value' => h(num($reach)), 'note' => $test !== []
            ? 'Test mode: only ' . count($test) . (count($test) === 1 ? ' test number gets' : ' test numbers get') . ' messages now'
            : 'Agreed to booking updates or messaged you, no STOP'],
        ['label' => 'Said STOP', 'value' => h(num($stop)), 'note' => 'Nothing goes to them unless they write again'],
        ['label' => 'Marketing off', 'value' => h(num($off)), 'note' => 'Turned off your marketing in WhatsApp'],
        ['label' => 'Tagged', 'value' => h(num($tagged)), 'note' => num(count($all)) . ' numbers in all'],
    ]);
}

/** The CSV download of the list: the current chip and search, every page. Never message text. */
function admin_wac_export(PDO $pdo, array $config, array $filter): void
{
    $list = admin_wac_list($pdo, $config, $filter);
    tg_audit($config, 'admin', 'export_csv', 'whatsapp contacts: ' . count($list) . ' rows' . (($filter['show'] ?? '') !== '' ? ', ' . $filter['show'] : '')
        . (($filter['q'] ?? '') !== '' ? ', searched' : ''));
    $types = ['buyer' => 'buyer', 'lead' => 'lead', 'whatsapp' => 'whatsapp only'];
    $lines = [];
    foreach ($list as $r) {
        $lines[] = [$r['name'], $r['phone'], $r['email'], $types[$r['type']], implode(' ', $r['tags']), (string) $r['consent'],
            $r['stop'] ? 'yes' : 'no', $r['marketing_off'] ? 'yes' : 'no', (string) $r['last_msg_at']];
    }
    send_csv('whatsapp-contacts-' . date('Y-m-d') . '.csv',
        ['name', 'number', 'email', 'type', 'tags', 'consent_version', 'stop', 'marketing_off', 'last_message_at'], $lines);
}

/**
 * wac_tag: adds or removes one tag for up to 100 ticked numbers (the rows of one page).
 * Every number must be a WhatsApp number as the list shows it. Adding makes the
 * wa_contacts row when there is none; a tag added here can fire a "tag added" rule
 * (only rules switched on before it, as always).
 */
function admin_wac_action(PDO $pdo, array $config, string $action): void
{
    if ($action !== 'wac_tag') {
        return;
    }
    $now = rp_now($config);
    $op = (string) ($_POST['op'] ?? '');
    $tag = strtolower(trim(is_string($_POST['tag'] ?? null) ? $_POST['tag'] : ''));
    $raw = is_array($_POST['phones'] ?? null) ? array_values($_POST['phones']) : [];
    if (!in_array($op, ['add', 'remove'], true)) {
        admin_flash('bad', 'Choose to add or remove the tag. Nothing changed.');
        return;
    }
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,29}$/', $tag)) {
        admin_flash('bad', 'A tag is up to 30 letters, numbers and hyphens, starting with a letter or number. Nothing changed.');
        return;
    }
    if (!$raw) {
        admin_flash('bad', 'Tick at least one person first. Nothing changed.');
        return;
    }
    if (count($raw) > ADMIN_WAC_PAGE) {
        admin_flash('bad', 'Up to ' . ADMIN_WAC_PAGE . ' people at a time, one page of the list. Nothing changed.');
        return;
    }
    $phones = [];
    foreach ($raw as $p) {
        $p = is_string($p) ? $p : '';
        if (!preg_match('/^\d{8,15}$/', $p) || tg_wa_number($p) !== $p) {
            admin_flash('bad', 'One of the ticked numbers is not a WhatsApp number. Nothing changed.');
            return;
        }
        $phones[$p] = true;
    }
    $changed = 0;
    if ($op === 'add') {
        $add = $pdo->prepare("INSERT IGNORE INTO wa_tags (wa_contact_id, tag, added_at, added_by) VALUES (?, ?, ?, 'admin')");
        foreach (array_keys($phones) as $p) {
            $id = tg_wa_contact_id($pdo, (string) $p, $now);
            if ($id > 0) {
                $add->execute([$id, $tag, tg_ist($now)]);
                $changed += $add->rowCount();
            }
        }
    } else {
        $del = $pdo->prepare('DELETE t FROM wa_tags t JOIN wa_contacts wc ON wc.id = t.wa_contact_id WHERE wc.phone = ? AND t.tag = ?');
        foreach (array_keys($phones) as $p) {
            $del->execute([(string) $p, $tag]);
            $changed += $del->rowCount();
        }
    }
    $n = count($phones);
    tg_audit($config, 'admin', $op === 'add' ? 'whatsapp_tag_added' : 'whatsapp_tag_removed',
        'tag ' . $tag . ' ' . ($op === 'add' ? 'added to ' : 'removed from ') . $changed . ' of ' . $n . ' ticked');
    $people = static fn(int $k): string => num($k) . ($k === 1 ? ' person' : ' people');
    if ($op === 'add') {
        admin_flash('good', 'Tag ' . $tag . ' added to ' . $people($changed) . '.' . ($changed < $n ? ' ' . $people($n - $changed) . ' had it already.' : ''));
    } else {
        admin_flash('good', 'Tag ' . $tag . ' removed from ' . $people($changed) . '.' . ($changed < $n ? ' ' . $people($n - $changed) . ' did not have it.' : ''));
    }
}

// ---------------------------------------------------------------------------
// The WhatsApp overview
// ---------------------------------------------------------------------------

/** The days the messages chart covers: the period, the last 14 days for "today", or since the first message (up to 180). */
function admin_wa_chart_days(PDO $pdo, array $range, int $now): array
{
    $today = (new DateTimeImmutable('@' . $now))->setTimezone(ist())->setTime(0, 0);
    $days = $range['days'];
    if ($days === null) {
        $first = value($pdo, 'SELECT MIN(DATE(created_at)) FROM wa_messages');
        $start = $first ? new DateTimeImmutable((string) $first, ist()) : $today->modify('-13 days');
        $days = max(1, min(180, (int) $start->diff($today)->days + 1));
    } elseif ($days < 7) {
        $days = 14;
    }
    return [$today->modify('-' . ($days - 1) . ' days'), $days];
}

/**
 * Everything the overview shows for the period. Returns conversations, in, out, spend
 * (paise), by_category (MARKETING and UTILITY => [messages, paise]), started (different
 * people sent a business-started template in the last 24 hours, as the daily limit counts
 * them), cap, days (chart points), templates (ranked, best first: name, category, sends,
 * read, replied, read_rate, reply_rate) and unranked (templates under the minimum sends).
 */
function admin_wa_overview_data(PDO $pdo, array $config, array $range, int $now): array
{
    [$cond, $args] = in_range($range, 'm.created_at');
    $k = row($pdo, "SELECT COUNT(DISTINCT m.wa_contact_id) AS convs, COALESCE(SUM(m.direction = 'in'), 0) AS i, COALESCE(SUM(m.direction = 'out'), 0) AS o
        FROM wa_messages m WHERE {$cond}", $args);

    // Meta bills a delivered template by its category. A free reply, a utility template
    // inside an open window (billable 0) and a failed send cost nothing.
    $by = ['MARKETING' => [0, 0], 'UTILITY' => [0, 0]];
    foreach (rows($pdo, "SELECT UPPER(COALESCE(m.category, 'MARKETING')) AS cat, COUNT(*) AS n FROM wa_messages m
            WHERE m.direction = 'out' AND m.billable = 1 AND m.status <> 'failed' AND {$cond} GROUP BY cat", $args) as $r) {
        $cat = isset(TG_WA_RATE_PAISE[$r['cat']]) ? (string) $r['cat'] : 'MARKETING';
        $by[$cat][0] += (int) $r['n'];
        $by[$cat][1] += (int) $r['n'] * tg_wa_rate($cat);
    }

    // Messages per day, in and out.
    [$start, $days] = admin_wa_chart_days($pdo, $range, $now);
    $perDay = [];
    foreach (rows($pdo, "SELECT DATE(created_at) AS day, COALESCE(SUM(direction = 'in'), 0) AS i, COALESCE(SUM(direction = 'out'), 0) AS o
            FROM wa_messages WHERE created_at >= ? GROUP BY DATE(created_at)", [$start->format('Y-m-d 00:00:00')]) as $r) {
        $perDay[(string) $r['day']] = [(int) $r['i'], (int) $r['o']];
    }
    $points = [];
    for ($i = 0; $i < $days; $i++) {
        $day = $start->modify('+' . $i . ' days');
        [$in, $out] = $perDay[$day->format('Y-m-d')] ?? [0, 0];
        $points[] = ['label' => $day->format('j M'), 'value' => $in + $out,
            'tip' => $day->format('D j M') . ': ' . num($in) . ' in · ' . num($out) . ' out'];
    }

    // Templates: read rate and reply rate. Test sends are left out, and a failed send is not
    // a send. A reply is a real message from them (not STOP or START) within 72 hours of ours.
    $ranked = [];
    $unranked = 0;
    foreach (rows($pdo, "SELECT m.template, MAX(UPPER(COALESCE(m.category, ''))) AS cat, COUNT(*) AS sends, COALESCE(SUM(m.read_at IS NOT NULL), 0) AS seen,
            COALESCE(SUM(EXISTS (SELECT 1 FROM wa_messages r WHERE r.wa_contact_id = m.wa_contact_id AND r.direction = 'in'
                AND r.kind NOT IN ('stop', 'start') AND r.created_at > m.created_at AND r.created_at <= m.created_at + INTERVAL 72 HOUR)), 0) AS replied
        FROM wa_messages m WHERE m.direction = 'out' AND m.kind = 'template' AND m.template IS NOT NULL AND m.status <> 'failed'
            AND m.sent_by <> 'test' AND {$cond} GROUP BY m.template", $args) as $r) {
        if ((int) $r['sends'] < ADMIN_WA_RANK_MIN) {
            $unranked++;
            continue;
        }
        $ranked[] = ['name' => (string) $r['template'], 'category' => (string) $r['cat'], 'sends' => (int) $r['sends'], 'read' => (int) $r['seen'],
            'replied' => (int) $r['replied'], 'read_rate' => (int) $r['seen'] / (int) $r['sends'], 'reply_rate' => (int) $r['replied'] / (int) $r['sends']];
    }
    usort($ranked, static fn($a, $b) => [$b['read_rate'], $b['reply_rate'], $b['sends'], $a['name']] <=> [$a['read_rate'], $a['reply_rate'], $a['sends'], $b['name']]);

    $cap = tg_wa_cap_state($pdo, $config, $now);
    return [
        'conversations' => (int) $k['convs'],
        'in'            => (int) $k['i'],
        'out'           => (int) $k['o'],
        'spend'         => $by['MARKETING'][1] + $by['UTILITY'][1],
        'by_category'   => $by,
        'started'       => count($cap['used']),
        'cap'           => $cap['cap'],
        'days'          => $points,
        'templates'     => $ranked,
        'unranked'      => $unranked,
    ];
}

/** A table of ranked templates for the overview. $quality: Meta name => GREEN, YELLOW, RED or UNKNOWN. */
function admin_wa_rank_table(array $list, array $quality, string $empty): string
{
    $rows = [];
    foreach ($list as $t) {
        $q = $quality[$t['name']] ?? 'UNKNOWN';
        $rows[] = [
            '<code>' . h($t['name']) . '</code><small class="sub">' . h($t['category'] !== '' ? ucfirst(strtolower($t['category'])) : 'Template')
                . ($q !== 'UNKNOWN' ? ' · Meta quality ' . h(strtolower($q)) : '') . '</small>',
            h(num($t['sends'])),
            h(pct($t['read'], $t['sends'])),
            h(pct($t['replied'], $t['sends'])),
        ];
    }
    return ui_table([['Template', ''], ['Sent', 'num'], ['Read', 'num'], ['Replied', 'num']], $rows, ['empty' => $empty]);
}
