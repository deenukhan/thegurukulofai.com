<?php
/**
 * Coupons made on the dashboard: make one or many, pause, resume, delete. The checkout
 * checks them in store.php (tg_coupon_claim). Each change goes through actions.php
 * (POST, page token, audit, message) and needs an authenticator code while two-step is on.
 * The config file's coupon is separate and stays on the Settings page.
 */

/** Most codes made in one go, and most uses one code can allow. */
const ADMIN_COUPON_MAX_BATCH = 200;
const ADMIN_COUPON_MAX_USES = 100000;

/** How long a new coupon lasts, counted from its start. Null seconds: no fixed length. */
const ADMIN_COUPON_ENDS = [
    '1h'    => ['1 hour', 3600],
    '24h'   => ['24 hours', 86400],
    '7d'    => ['7 days', 7 * 86400],
    'never' => ['Never', null],
    'exact' => ['Exact time', null],
];

/** The ready-to-share link: opens the page with the coupon box shown and the code filled in. */
function admin_coupon_link(string $code): string
{
    return 'https://thegurukulofai.com/masterclass/?coupon=' . rawurlencode($code);
}

/** "₹500 off" or "20% off". */
function admin_coupon_discount(array $c): string
{
    return $c['discount_type'] === 'percent'
        ? (int) $c['discount_value'] . '% off'
        : money((int) $c['discount_value']) . ' off';
}

/** The seat price a buyer pays with this coupon today. */
function admin_coupon_seat(array $config, array $c): int
{
    return tg_coupon_seat((int) $config['price_paise'], (string) $c['discount_type'], (int) $c['discount_value']);
}

/** A status pill for a coupon at this moment. */
function admin_coupon_pill(array $c, int $now): string
{
    return match (tg_coupon_state($c, $now)) {
        'active'    => ui_pill('Active', 'good'),
        'scheduled' => ui_pill('Starts later', 'info'),
        'paused'    => ui_pill('Paused', 'warn'),
        'used_up'   => ui_pill('Used up', 'neutral'),
        default     => ui_pill('Expired', 'neutral'),
    };
}

/** A datetime-local value such as "2026-11-22T18:30", read as IST. Null if empty or not a real time. */
function admin_ist_input(string $value): ?int
{
    $value = trim($value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $value)) {
        return null;
    }
    $t = DateTimeImmutable::createFromFormat(strlen($value) === 16 ? 'Y-m-d\TH:i' : 'Y-m-d\TH:i:s', $value, ist());
    $e = DateTimeImmutable::getLastErrors(); // catches 30 February and the like
    return $t === false || ($e && ($e['warning_count'] || $e['error_count'])) ? null : $t->getTimestamp();
}

/** A random code: no 0/O, 1/I/L, so it reads clearly on a phone. With a prefix: DIWALI-7KQ4MX. */
function admin_coupon_random(string $prefix): string
{
    $letters = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $tail = '';
    for ($i = 0; $i < 6; $i++) {
        $tail .= $letters[random_int(0, strlen($letters) - 1)];
    }
    return $prefix !== '' ? $prefix . '-' . $tail : $tail;
}

/** The config file's coupon code, normalised, so a dashboard coupon can't take the same code. */
function admin_config_coupon_code(array $config): string
{
    $code = tg_coupon_norm((string) ($config['coupon']['code'] ?? ''));
    return str_starts_with($code, 'PASTE') ? '' : $code;
}

/** Stops with a message and nothing changed. */
function admin_coupon_fail(string $text): void
{
    admin_flash('bad', $text . ' Nothing was made.');
    admin_done();
}

/** Reads the "make coupons" form, checks every field, and saves the coupon or codes. */
function admin_coupon_create(PDO $pdo, array $config): void
{
    $now = rp_now($config);
    $price = (int) $config['price_paise'];
    $many = ($_POST['mode'] ?? '') === 'many';

    // Discount: whole rupees off, or a percent. The seat never drops below ₹1.
    $type = ($_POST['off_type'] ?? '') === 'percent' ? 'percent' : 'amount';
    $raw = trim((string) ($_POST['off_value'] ?? ''));
    if (!ctype_digit($raw) || (int) $raw < 1) {
        admin_coupon_fail('Type the discount as a whole number, such as 500 or 20.');
    }
    $value = (int) $raw;
    if ($type === 'percent' && $value > 100) {
        admin_coupon_fail('A percent discount can be 1 to 100.');
    }
    if ($type === 'amount') {
        if ($value * 100 > $price) {
            admin_coupon_fail('That is more than the seat price of ' . money($price) . '.');
        }
        $value *= 100; // stored in paise
    }

    // When it starts.
    $start = null;
    if (($_POST['starts'] ?? 'now') === 'later') {
        $start = admin_ist_input((string) ($_POST['starts_at'] ?? ''));
        if ($start === null) {
            admin_coupon_fail('Pick the start date and time.');
        }
        if ($start <= $now) {
            admin_coupon_fail('That start time has already passed. Choose Now, or a later time.');
        }
    }

    // When it ends: a length counted from the start, never, or an exact time.
    $endsKey = array_key_exists($_POST['ends'] ?? '', ADMIN_COUPON_ENDS) ? (string) $_POST['ends'] : '';
    if ($endsKey === '') {
        admin_coupon_fail('Choose when the coupon ends.');
    }
    $end = null;
    if ($endsKey === 'exact') {
        $end = admin_ist_input((string) ($_POST['ends_at'] ?? ''));
        if ($end === null) {
            admin_coupon_fail('Pick the end date and time.');
        }
        if ($end <= max($now, $start ?? 0)) {
            admin_coupon_fail('The end time must be after ' . ($start !== null ? 'the start time.' : 'now.'));
        }
    } elseif (ADMIN_COUPON_ENDS[$endsKey][1] !== null) {
        $end = ($start ?? $now) + ADMIN_COUPON_ENDS[$endsKey][1];
    }

    // Use limit, per code. Empty means no limit.
    $maxRaw = trim((string) ($_POST['max_uses'] ?? ''));
    $max = null;
    if ($maxRaw !== '') {
        if (!ctype_digit($maxRaw) || (int) $maxRaw < 1 || (int) $maxRaw > ADMIN_COUPON_MAX_USES) {
            admin_coupon_fail('The use limit must be a whole number from 1 to ' . num(ADMIN_COUPON_MAX_USES) . ', or empty for no limit.');
        }
        $max = (int) $maxRaw;
    }

    $note = rp_clean($_POST['note'] ?? '', 80);
    $configCode = admin_config_coupon_code($config);

    // The codes themselves.
    if ($many) {
        $count = trim((string) ($_POST['count'] ?? ''));
        if (!ctype_digit($count) || (int) $count < 2 || (int) $count > ADMIN_COUPON_MAX_BATCH) {
            admin_coupon_fail('Make 2 to ' . ADMIN_COUPON_MAX_BATCH . ' codes at a time. For one code, choose "One code".');
        }
        $count = (int) $count;
        $prefix = tg_coupon_norm((string) ($_POST['prefix'] ?? ''));
        if ($prefix !== '' && !preg_match('/^[A-Z0-9]{2,12}$/', $prefix)) {
            admin_coupon_fail('The start of each code can be 2 to 12 letters or digits, with no spaces or symbols.');
        }
    } else {
        $code = tg_coupon_norm((string) ($_POST['code'] ?? ''));
        if (!tg_coupon_code_ok($code)) {
            admin_coupon_fail('A code is 4 to 24 letters or digits. A hyphen is fine in the middle, such as DIWALI-50.');
        }
        if ($configCode !== '' && hash_equals($configCode, $code)) {
            admin_coupon_fail('That code is already your coupon in the config file. Choose another.');
        }
    }

    if (!admin_code_ok($pdo, $config)) {
        admin_done();
    }

    $insert = $pdo->prepare('INSERT IGNORE INTO coupons (code, discount_type, discount_value, starts_at, ends_at, max_uses, batch, note)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $fields = static fn(string $code, ?string $batch): array => [
        $code, $type, $value, $start === null ? null : tg_ist($start), $end === null ? null : tg_ist($end), $max, $batch, $note,
    ];
    $shown = ($type === 'percent' ? $value . '%' : money($value)) . ' off'
        . ($end === null ? ', no end date' : ', until ' . date('j M, g:i A', $end))
        . ($max === null ? ', no use limit' : ', ' . $max . ' use' . ($max === 1 ? '' : 's') . ($many ? ' each' : ''));

    if (!$many) {
        $insert->execute($fields($code, null));
        if ($insert->rowCount() !== 1) {
            admin_coupon_fail('A coupon called ' . $code . ' already exists. Choose another code.');
        }
        $id = (int) $pdo->lastInsertId();
        tg_audit($config, 'admin', 'coupon_made', $code . ': ' . $shown);
        admin_flash('good', 'Coupon ' . $code . ' is ready: ' . $shown . '. Its link is below, ready to copy.');
        $_POST['back'] = './?view=coupons#c' . $id;
        admin_done();
    }

    // Many: random codes, all in one batch. A clash with an existing code just tries again.
    $batch = bin2hex(random_bytes(4));
    $made = 0;
    $pdo->beginTransaction();
    for ($tries = 0; $made < $count && $tries < $count * 5; $tries++) {
        $code = admin_coupon_random($prefix);
        if ($configCode !== '' && hash_equals($configCode, $code)) {
            continue;
        }
        $insert->execute($fields($code, $batch));
        $made += $insert->rowCount();
    }
    if ($made < $count) {
        $pdo->rollBack();
        admin_coupon_fail('Could not find enough unused codes. Try a different start for the codes.');
    }
    $pdo->commit();
    tg_audit($config, 'admin', 'coupon_made', $count . ' codes, batch ' . $batch . ($prefix !== '' ? ', ' . $prefix . '-…' : '') . ': ' . $shown);
    admin_flash('good', $count . ' codes are ready: ' . $shown . '. Download them below to share.');
    $_POST['back'] = './?view=coupons&batch=' . $batch . '#list';
    admin_done();
}

/** Pause, resume or delete one coupon, or a whole batch. */
function admin_coupon_action(PDO $pdo, array $config, string $action): void
{
    if ($action === 'coupon_create') {
        admin_coupon_create($pdo, $config);
    }
    $id = (int) ($_POST['id'] ?? 0);
    $batch = preg_match('/^[0-9a-f]{8}$/', (string) ($_POST['batch'] ?? '')) ? (string) $_POST['batch'] : '';
    if (($id <= 0 && $batch === '') || !in_array($action, ['coupon_pause', 'coupon_resume', 'coupon_delete'], true)) {
        return;
    }
    if (!admin_code_ok($pdo, $config)) {
        return;
    }
    [$where, $arg] = $batch !== '' ? ['batch = ?', $batch] : ['id = ?', $id];
    $codes = array_column(rows($pdo, "SELECT code FROM coupons WHERE {$where} ORDER BY id", [$arg]), 'code');
    if (!$codes) {
        admin_flash('bad', 'That coupon no longer exists.');
        return;
    }
    $what = $batch !== '' ? count($codes) . ' codes' : $codes[0];
    $detail = $batch !== '' ? 'batch ' . $batch . ', ' . count($codes) . ' codes' : $codes[0];

    if ($action === 'coupon_delete') {
        $pdo->prepare("DELETE FROM coupons WHERE {$where}")->execute([$arg]);
        tg_audit($config, 'admin', 'coupon_deleted', $detail);
        admin_flash('good', 'Deleted ' . $what . '. ' . ($batch !== '' ? 'They stop' : 'It stops') . ' working now.');
        if ($batch !== '') {
            $_POST['back'] = './?view=coupons#list';
        }
        return;
    }
    $pause = $action === 'coupon_pause';
    $pdo->prepare("UPDATE coupons SET paused = ? WHERE {$where}")->execute([$pause ? 1 : 0, $arg]);
    tg_audit($config, 'admin', $pause ? 'coupon_paused' : 'coupon_resumed', $detail);
    admin_flash('good', $pause
        ? 'Paused ' . $what . '. Nobody can use ' . ($batch !== '' ? 'them' : 'it') . ' until you resume.'
        : 'Resumed ' . $what . '. ' . ($batch !== '' ? 'They work' : 'It works') . ' again, inside ' . ($batch !== '' ? 'their' : 'its') . ' dates and use limit.');
}
