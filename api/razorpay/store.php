<?php
/**
 * The contact store: one MySQL database on Hostinger holds every lead and buyer.
 *
 * Rule one: nothing here may block a sale. Every function catches its own errors,
 * logs them to the private log and carries on. The Razorpay order notes stay the
 * backup copy, and the nightly reconcile job (cron/reconcile.php) fills any gap.
 *
 * All times are stored in IST (the connection runs at +05:30).
 * The login details live only in the private config's `db` block.
 */

/** Which consent wording the pop-up showed. Bump it whenever that wording changes. */
const TG_CONSENT_VERSIONS = ['v1'];

/** Booking states, in order of strength. A weaker one never replaces a stronger one. */
const TG_STATUS_RANK = ['order_failed' => 0, 'started' => 1, 'failed' => 2, 'paid' => 3];

/** The shared connection, or null when the store is switched off or down. */
function tg_db(array $config): ?PDO
{
    static $pdo = false; // false: not tried yet in this request
    if ($pdo !== false) {
        return $pdo;
    }
    $pdo = null;
    if (!tg_db_enabled($config)) {
        return null;
    }
    $db = $config['db'];
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $db['host'] ?? 'localhost',
            (int) ($db['port'] ?? 3306),
            $db['name']
        );
        $conn = new PDO($dsn, (string) $db['user'], (string) $db['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 3,
        ]);
        $conn->exec("SET time_zone = '+05:30'");
        tg_db_migrate($conn, $config);
        $pdo = $conn;
    } catch (Throwable $e) {
        // PDO's message names the user and host, never the password.
        error_log('contact store: cannot connect: ' . $e->getMessage());
    }
    return $pdo;
}

/** The store is on only when the config has a filled-in `db` block. */
function tg_db_enabled(array $config): bool
{
    $db = $config['db'] ?? null;
    if (!is_array($db)) {
        return false;
    }
    foreach (['name', 'user', 'password'] as $key) {
        $value = (string) ($db[$key] ?? '');
        if ($value === '' || str_starts_with($value, 'PASTE')) {
            return false;
        }
    }
    return true;
}

/**
 * Creates the tables on first use, so the only setup is an empty database.
 * A marker file next to the config skips this on every later request.
 * Version 2 (Phase 2) adds the outbox and the join and stop fields on bookings.
 * Version 3 (dashboard) adds Razorpay fees and refunds, and the funnel's day counts.
 * Version 4 (dashboard settings) adds two-step sign-in, the settings history and add-on delivery.
 * Version 5 (coupon builder) adds the coupons table and which coupon each booking used.
 * Version 6 (WhatsApp) adds Meta's message id and delivery times to the outbox, and
 * wa_inbound: one row per message someone sent us (for STOP, START and repeats).
 */
/** The table layout version. Raise it with every change below. */
const TG_DB_VERSION = 6;

function tg_db_migrate(PDO $pdo, array $config): void
{
    $version = TG_DB_VERSION;
    $marker = dirname($config['sent_dir']) . '/razorpay-db-v' . $version . '-'
        . substr(hash('sha256', ($config['db']['host'] ?? 'localhost') . '/' . $config['db']['name']), 0, 12);
    if (is_file($marker)) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS contacts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(120) NOT NULL,
        name VARCHAR(80) NOT NULL DEFAULT '',
        phone VARCHAR(15) NOT NULL DEFAULT '',
        consent_version VARCHAR(12) NULL COMMENT 'Pop-up wording seen; NULL = before consent wording existed',
        consent_at DATETIME NULL COMMENT 'When they agreed to booking updates on WhatsApp and email',
        consent_ip VARCHAR(45) NULL,
        marketing_optin TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Ticked the optional offers box',
        marketing_optin_at DATETIME NULL,
        email_unsub_at DATETIME NULL,
        wa_optout_at DATETIME NULL,
        bought_1on1 TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_email (email),
        KEY idx_phone (phone)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS bookings (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        contact_id INT UNSIGNED NOT NULL,
        order_id VARCHAR(40) NULL COMMENT 'Razorpay order; NULL only if the order was never created',
        payment_id VARCHAR(40) NULL COMMENT 'The payment that paid it',
        product VARCHAR(60) NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'started' COMMENT 'started, paid, failed, order_failed',
        session_at DATETIME NULL COMMENT 'Session start, IST',
        addon TINYINT(1) NOT NULL DEFAULT 0,
        coupon TINYINT(1) NOT NULL DEFAULT 0,
        amount_paise INT UNSIGNED NOT NULL DEFAULT 0,
        utm_source VARCHAR(100) NOT NULL DEFAULT '',
        utm_medium VARCHAR(100) NOT NULL DEFAULT '',
        utm_campaign VARCHAR(100) NOT NULL DEFAULT '',
        utm_content VARCHAR(100) NOT NULL DEFAULT '',
        fail_reason VARCHAR(255) NULL,
        attended VARCHAR(8) NOT NULL DEFAULT 'unknown',
        source VARCHAR(12) NOT NULL DEFAULT 'checkout' COMMENT 'Who first wrote it: checkout, webhook or reconcile',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        paid_at DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_order (order_id),
        UNIQUE KEY uniq_payment (payment_id),
        KEY idx_contact (contact_id),
        KEY idx_status (status),
        KEY idx_session (session_at),
        CONSTRAINT fk_booking_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS audit (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        actor VARCHAR(20) NOT NULL COMMENT 'admin, reconcile or system',
        action VARCHAR(40) NOT NULL,
        detail VARCHAR(500) NOT NULL DEFAULT '',
        ip VARCHAR(45) NULL,
        KEY idx_at (at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ---- Version 2: reminders ----
    $columns = [
        'join_token'       => "CHAR(24) NULL COMMENT 'Personal /join/?t= link, also used to stop reminders'",
        'join_clicks'      => "INT UNSIGNED NOT NULL DEFAULT 0",
        'first_join_at'    => "DATETIME NULL",
        'reminders_off_at' => "DATETIME NULL COMMENT 'Buyer asked for no more reminders for this session'",
    ];
    foreach ($columns as $name => $definition) {
        $has = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $has->execute(['bookings', $name]);
        if ((int) $has->fetchColumn() === 0) {
            tg_db_alter($pdo, "ALTER TABLE bookings ADD COLUMN {$name} {$definition}");
        }
    }
    $index = $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND INDEX_NAME = 'uniq_join_token'")->fetchColumn();
    if ((int) $index === 0) {
        tg_db_alter($pdo, 'ALTER TABLE bookings ADD UNIQUE KEY uniq_join_token (join_token)');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS outbox (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        dedupe_key VARCHAR(120) NOT NULL COMMENT 'One row per message ever, e.g. email:live:b12:202611221500',
        channel VARCHAR(10) NOT NULL DEFAULT 'email',
        template VARCHAR(30) NOT NULL,
        booking_id INT UNSIGNED NULL,
        to_addr VARCHAR(120) NOT NULL,
        due_at DATETIME NOT NULL COMMENT 'IST',
        expires_at DATETIME NOT NULL COMMENT 'Never sent after this, IST',
        status VARCHAR(10) NOT NULL DEFAULT 'queued' COMMENT 'queued, sending, sent, failed, expired, cancelled',
        tries TINYINT UNSIGNED NOT NULL DEFAULT 0,
        next_try_at DATETIME NULL,
        last_error VARCHAR(255) NULL,
        sent_at DATETIME NULL,
        alerted_at DATETIME NULL COMMENT 'Deenu was told it failed',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_dedupe (dedupe_key),
        KEY idx_due (status, due_at),
        KEY idx_booking (booking_id),
        CONSTRAINT fk_outbox_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ---- Version 3: dashboard ----
    $columns = [
        'fee_paise'      => "INT UNSIGNED NULL COMMENT 'Razorpay fee for the payment, GST included'",
        'tax_paise'      => "INT UNSIGNED NULL COMMENT 'The GST part of the fee'",
        'refunded_paise' => "INT UNSIGNED NOT NULL DEFAULT 0",
    ];
    foreach ($columns as $name => $definition) {
        $has = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $has->execute(['bookings', $name]);
        if ((int) $has->fetchColumn() === 0) {
            tg_db_alter($pdo, "ALTER TABLE bookings ADD COLUMN {$name} {$definition}");
        }
    }
    // Day totals for the funnel: page visits and pop-up opens. No people, no addresses.
    $pdo->exec("CREATE TABLE IF NOT EXISTS events (
        day DATE NOT NULL,
        name VARCHAR(20) NOT NULL,
        hits INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (day, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ---- Version 4: dashboard settings ----
    $has = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $has->execute(['bookings', 'addon_sent_at']);
    if ((int) $has->fetchColumn() === 0) {
        tg_db_alter($pdo, "ALTER TABLE bookings ADD COLUMN addon_sent_at DATETIME NULL COMMENT 'Recording and Vault sent to this buyer'");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_auth (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY COMMENT 'Always 1: there is one admin',
        totp_secret VARCHAR(64) NOT NULL COMMENT 'Base32 key for the authenticator app',
        backup_codes TEXT NOT NULL COMMENT 'JSON list of hashed one-time codes',
        last_step INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Last 30-second step used to sign in, so a code works once',
        enabled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS setting_changes (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        name VARCHAR(40) NOT NULL,
        old_value VARCHAR(500) NULL COMMENT 'NULL: the config file value was in use',
        new_value VARCHAR(500) NULL COMMENT 'NULL: back to the config file value',
        ip VARCHAR(45) NULL,
        KEY idx_at (at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ---- Version 5: coupons made on the dashboard ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS coupons (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Capitals, no spaces',
        discount_type VARCHAR(8) NOT NULL COMMENT 'amount (paise off the seat) or percent',
        discount_value INT UNSIGNED NOT NULL,
        starts_at DATETIME NULL COMMENT 'IST; NULL = from when it was made',
        ends_at DATETIME NULL COMMENT 'IST; stops working at this exact second. NULL = never',
        max_uses INT UNSIGNED NULL COMMENT 'NULL = no limit',
        used INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Counted when the payment window opens',
        paused TINYINT(1) NOT NULL DEFAULT 0,
        batch CHAR(8) NULL COMMENT 'Codes made together share this',
        note VARCHAR(80) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_code (code),
        KEY idx_batch (batch)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $has = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $has->execute(['bookings', 'coupon_id']);
    if ((int) $has->fetchColumn() === 0) {
        tg_db_alter($pdo, "ALTER TABLE bookings ADD COLUMN coupon_id INT UNSIGNED NULL COMMENT 'Dashboard coupon used, if any', ADD KEY idx_coupon (coupon_id)");
    }

    // ---- Version 6: WhatsApp ----
    $columns = [
        'provider_id'  => "VARCHAR(128) NULL COMMENT 'WhatsApp message id from Meta'",
        'delivered_at' => "DATETIME NULL",
        'read_at'      => "DATETIME NULL",
    ];
    foreach ($columns as $name => $definition) {
        $has = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $has->execute(['outbox', $name]);
        if ((int) $has->fetchColumn() === 0) {
            tg_db_alter($pdo, "ALTER TABLE outbox ADD COLUMN {$name} {$definition}");
        }
    }
    $index = $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'outbox' AND INDEX_NAME = 'idx_provider'")->fetchColumn();
    if ((int) $index === 0) {
        tg_db_alter($pdo, 'ALTER TABLE outbox ADD KEY idx_provider (provider_id)');
    }
    // Message text is never kept here: replies are emailed to Deenu, STOP is recorded.
    $pdo->exec("CREATE TABLE IF NOT EXISTS wa_inbound (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        wa_id VARCHAR(128) NOT NULL COMMENT 'Meta message id, so a repeat is ignored',
        phone VARCHAR(16) NOT NULL COMMENT 'Sender, country code first, e.g. 919876543210',
        kind VARCHAR(8) NOT NULL COMMENT 'stop, start, reply or skip',
        received_at DATETIME NOT NULL,
        UNIQUE KEY uniq_wa (wa_id),
        KEY idx_phone (phone, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    @file_put_contents($marker, (string) time());
}

/** Adds one to today's count for a funnel step, such as a page visit. */
function tg_count_event(array $config, string $name): void
{
    $pdo = tg_db($config);
    if ($pdo === null) {
        return;
    }
    try {
        $pdo->prepare('INSERT INTO events (day, name, hits) VALUES (CURDATE(), ?, 1)
            ON DUPLICATE KEY UPDATE hits = hits + 1')->execute([$name]);
    } catch (Throwable $e) {
        error_log('contact store: event not counted: ' . $e->getMessage());
    }
}

/**
 * Saves what Razorpay kept and gave back for one payment: its fee (GST included),
 * the GST part, and any refund. A null leaves that figure as it was.
 */
function tg_record_money(array $config, string $paymentId, ?int $fee, ?int $tax, ?int $refunded): void
{
    $pdo = tg_db($config);
    if ($pdo === null || $paymentId === '') {
        return;
    }
    try {
        $pdo->prepare('UPDATE bookings SET fee_paise = COALESCE(?, fee_paise), tax_paise = COALESCE(?, tax_paise),
                refunded_paise = COALESCE(?, refunded_paise) WHERE payment_id = ?')
            ->execute([$fee, $tax, $refunded, $paymentId]);
    } catch (Throwable $e) {
        error_log('contact store: fee for ' . $paymentId . ' not saved: ' . $e->getMessage());
    }
}

/** Runs one schema change, ignoring "already there" when two requests race. */
function tg_db_alter(PDO $pdo, string $sql): void
{
    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        $code = (int) ($e->errorInfo[1] ?? 0);
        if (!in_array($code, [1060, 1061], true)) { // duplicate column, duplicate key name
            throw $e;
        }
    }
}

/** A fresh personal token for the join and stop links. */
function tg_new_token(): string
{
    return bin2hex(random_bytes(12));
}

/** The paid booking's personal token, for the confirmation email's join link. */
function tg_join_token(array $config, string $orderId): ?string
{
    $pdo = tg_db($config);
    if ($pdo === null || $orderId === '') {
        return null;
    }
    try {
        $find = $pdo->prepare("SELECT join_token FROM bookings WHERE order_id = ? AND status = 'paid'");
        $find->execute([$orderId]);
        $token = $find->fetchColumn();
        return is_string($token) && $token !== '' ? $token : null;
    } catch (Throwable $e) {
        error_log('contact store: token lookup failed: ' . $e->getMessage());
        return null;
    }
}

function tg_audit(array $config, string $actor, string $action, string $detail = ''): void
{
    $pdo = tg_db($config);
    if ($pdo === null) {
        return;
    }
    try {
        $pdo->prepare('INSERT INTO audit (actor, action, detail, ip) VALUES (?, ?, ?, ?)')
            ->execute([$actor, $action, mb_substr($detail, 0, 500), PHP_SAPI === 'cli' ? null : rp_clean($_SERVER['REMOTE_ADDR'] ?? '', 45)]);
    } catch (Throwable $e) {
        error_log('contact store: audit failed: ' . $e->getMessage());
    }
}

/** IST "Y-m-d H:i:s" for a Unix time, as the DATETIME columns expect. */
/**
 * Orders made before 19 Nov 2026 kept the session only as text, such as
 * "Sunday, 22 November 2026, 3 PM to 6 PM IST". Returns its start as a Unix
 * time (3 PM IST), or 0 if the text does not look like that.
 */
function tg_session_from_text(string $text): int
{
    if (!preg_match('/^([A-Z][a-z]+), (\d{1,2} [A-Z][a-z]+ \d{4}), 3 PM to 6 PM IST$/', trim($text), $m)) {
        return 0;
    }
    $day = DateTimeImmutable::createFromFormat('!j F Y H:i', $m[2] . ' 15:00', new DateTimeZone('Asia/Kolkata'));
    if ($day === false || $day->format('l') !== $m[1] || $day->format('j F Y') !== $m[2]) {
        return 0;
    }
    return $day->getTimestamp();
}

function tg_ist(int $unix): string
{
    return (new DateTimeImmutable('@' . $unix))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m-d H:i:s');
}

/**
 * Adds or refreshes a person, keyed by email. Returns their id.
 * The newest name and phone win. An earlier "yes" to offers is never undone here:
 * an unticked box is not a withdrawal. Withdrawal comes from unsubscribe or STOP.
 */
function tg_upsert_contact(PDO $pdo, array $person): int
{
    $email = mb_strtolower(trim($person['email']));
    $consentAt = $person['consent_version'] !== null ? $person['at'] : null;
    $marketingAt = $person['marketing'] ? $person['at'] : null;

    $pdo->prepare("INSERT INTO contacts
            (email, name, phone, consent_version, consent_at, consent_ip, marketing_optin, marketing_optin_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            id = LAST_INSERT_ID(id),
            name = IF(VALUES(name) <> '', VALUES(name), name),
            phone = IF(VALUES(phone) <> '', VALUES(phone), phone),
            consent_ip = IF(VALUES(consent_version) IS NOT NULL AND (consent_at IS NULL OR VALUES(consent_at) > consent_at), VALUES(consent_ip), consent_ip),
            consent_version = IF(VALUES(consent_version) IS NOT NULL AND (consent_at IS NULL OR VALUES(consent_at) > consent_at), VALUES(consent_version), consent_version),
            consent_at = IF(VALUES(consent_at) IS NOT NULL AND (consent_at IS NULL OR VALUES(consent_at) > consent_at), VALUES(consent_at), consent_at),
            marketing_optin_at = IF(VALUES(marketing_optin) = 1 AND marketing_optin = 0, VALUES(marketing_optin_at), marketing_optin_at),
            marketing_optin = GREATEST(marketing_optin, VALUES(marketing_optin))")
        ->execute([
            $email,
            $person['name'],
            $person['phone'],
            $person['consent_version'],
            $consentAt,
            $person['consent_ip'] ?? null,
            $person['marketing'] ? 1 : 0,
            $marketingAt,
        ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Saves the lead and a `started` booking before Razorpay is called, so the lead
 * survives even if Razorpay is down. Returns the booking id, or null if the
 * store is off or failed (the sale carries on regardless).
 */
function tg_save_lead(array $config, array $lead): ?int
{
    $pdo = tg_db($config);
    if ($pdo === null) {
        return null;
    }
    try {
        $pdo->beginTransaction();
        $contactId = tg_upsert_contact($pdo, [
            'email'           => $lead['email'],
            'name'            => $lead['name'],
            'phone'           => $lead['phone'],
            'consent_version' => $lead['consent_version'],
            'consent_ip'      => rp_clean($_SERVER['REMOTE_ADDR'] ?? '', 45),
            'marketing'       => $lead['marketing'],
            'at'              => tg_ist(time()),
        ]);
        $pdo->prepare("INSERT INTO bookings
                (contact_id, product, status, session_at, addon, coupon, coupon_id, amount_paise,
                 utm_source, utm_medium, utm_campaign, utm_content, source, created_at)
            VALUES (?, ?, 'started', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'checkout', ?)")
            ->execute([
                $contactId,
                $lead['product'],
                tg_ist($lead['session_at']),
                $lead['addon'] ? 1 : 0,
                $lead['coupon'] ? 1 : 0,
                $lead['coupon_id'] ?? null,
                $lead['amount'],
                $lead['utm']['utm_source'] ?? '',
                $lead['utm']['utm_medium'] ?? '',
                $lead['utm']['utm_campaign'] ?? '',
                $lead['utm']['utm_content'] ?? '',
                // From PHP, not the database clock, so the test clock moves the checkout coupon's 20 minutes too.
                tg_ist(rp_now($config) ?: time()),
            ]);
        $bookingId = (int) $pdo->lastInsertId();
        $pdo->commit();
        return $bookingId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('contact store: lead not saved: ' . $e->getMessage());
        return null;
    }
}

/** Links the saved lead to its Razorpay order, or records that Razorpay refused. */
function tg_attach_order(array $config, ?int $bookingId, ?string $orderId): void
{
    $pdo = $bookingId === null ? null : tg_db($config);
    if ($pdo === null) {
        return;
    }
    try {
        if ($orderId === null) {
            $pdo->prepare("UPDATE bookings SET status = 'order_failed', fail_reason = 'Razorpay did not create the order'
                WHERE id = ? AND status = 'started'")->execute([$bookingId]);
        } else {
            $pdo->prepare('UPDATE bookings SET order_id = ? WHERE id = ? AND order_id IS NULL')->execute([$orderId, $bookingId]);
        }
    } catch (Throwable $e) {
        error_log('contact store: order ' . $orderId . ' not linked: ' . $e->getMessage());
    }
}

/**
 * Records what Razorpay says about one order: from the webhook or from reconcile.
 * Safe to run any number of times. Finds the booking by order id, then by the
 * `ref` note (a lead whose order id never got saved), and otherwise creates it.
 *
 * $status is paid, failed or started. Returns what it did, for reconcile's summary:
 * 'added', 'updated', 'unchanged' or 'error'.
 */
function tg_record_order(array $config, array $order, string $status, array $extra = []): string
{
    $pdo = tg_db($config);
    if ($pdo === null) {
        return 'error';
    }
    $notes = is_array($order['notes'] ?? null) ? $order['notes'] : [];
    $orderId = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($order['id'] ?? ''));
    $email = mb_strtolower(rp_clean($notes['email'] ?? ($extra['email'] ?? ''), 120));
    if ($orderId === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'error';
    }
    $source = $extra['source'] ?? 'webhook';
    $createdAt = (int) ($order['created_at'] ?? time());
    [$consent, $marketing] = tg_parse_consent((string) ($notes['consent'] ?? ''));
    $sessionAt = (int) ($notes['session_at'] ?? 0) ?: tg_session_from_text((string) ($notes['session'] ?? ''));
    $paymentId = isset($extra['payment_id']) ? preg_replace('/[^A-Za-z0-9_]/', '', (string) $extra['payment_id']) : '';
    $amount = $status === 'paid'
        ? (int) ($extra['amount'] ?? ($order['amount_paid'] ?? ($order['amount'] ?? 0)))
        : (int) ($order['amount'] ?? 0);
    $reason = isset($extra['reason']) ? mb_substr(rp_clean($extra['reason'], 255), 0, 255) : null;
    $paidAt = $status === 'paid' ? tg_ist((int) ($extra['paid_at'] ?? time())) : null;

    try {
        $pdo->beginTransaction();
        $contactId = tg_upsert_contact($pdo, [
            'email'           => $email,
            'name'            => rp_clean($notes['name'] ?? '', 80),
            'phone'           => preg_replace('/\D+/', '', (string) ($notes['phone'] ?? '')),
            'consent_version' => $consent,
            'consent_ip'      => null,
            'marketing'       => $marketing,
            'at'              => tg_ist($createdAt),
        ]);

        $find = $pdo->prepare('SELECT id, status, payment_id, order_id, session_at FROM bookings WHERE order_id = ? FOR UPDATE');
        $find->execute([$orderId]);
        $row = $find->fetch();
        if (!$row && ctype_digit((string) ($notes['ref'] ?? ''))) {
            $find = $pdo->prepare('SELECT id, status, payment_id, order_id, session_at FROM bookings
                WHERE id = ? AND order_id IS NULL AND contact_id = ? FOR UPDATE');
            $find->execute([(int) $notes['ref'], $contactId]);
            $row = $find->fetch();
        }

        if (!$row) {
            $pdo->prepare("INSERT INTO bookings
                    (contact_id, order_id, payment_id, product, status, session_at, addon, coupon, amount_paise,
                     utm_source, utm_medium, utm_campaign, utm_content, fail_reason, source, created_at, paid_at, join_token)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([
                    $contactId,
                    $orderId,
                    $paymentId !== '' ? $paymentId : null,
                    rp_clean($notes['product'] ?? '', 60),
                    $status,
                    $sessionAt > 0 ? tg_ist($sessionAt) : null,
                    ($notes['addon'] ?? '') === 'yes' ? 1 : 0,
                    ($notes['coupon'] ?? '') === 'yes' ? 1 : 0,
                    $amount,
                    rp_clean($notes['utm_source'] ?? '', 100),
                    rp_clean($notes['utm_medium'] ?? '', 100),
                    rp_clean($notes['utm_campaign'] ?? '', 100),
                    rp_clean($notes['utm_content'] ?? '', 100),
                    $status === 'failed' ? $reason : null,
                    $source,
                    tg_ist($createdAt),
                    $paidAt,
                    $status === 'paid' ? tg_new_token() : null,
                ]);
            $pdo->commit();
            return 'added';
        }

        // A booking saved without its session date (an order from before the date
        // was kept as a number) gets it now, so its reminders can be planned.
        $fillSession = $row['session_at'] === null && $sessionAt > 0;
        if ($fillSession) {
            $pdo->prepare('UPDATE bookings SET session_at = ? WHERE id = ? AND session_at IS NULL')
                ->execute([tg_ist($sessionAt), $row['id']]);
        }

        $current = TG_STATUS_RANK[$row['status']] ?? 0;
        $wanted  = TG_STATUS_RANK[$status] ?? 0;
        $fillPayment = $status === 'paid' && $paymentId !== '' && $row['payment_id'] === null;
        if ($wanted > $current && $status === 'paid') {
            $pdo->prepare("UPDATE bookings SET status = 'paid', order_id = ?, payment_id = COALESCE(payment_id, ?),
                    amount_paise = ?, paid_at = COALESCE(paid_at, ?), fail_reason = NULL,
                    join_token = COALESCE(join_token, ?) WHERE id = ?")
                ->execute([$orderId, $paymentId !== '' ? $paymentId : null, $amount, $paidAt, tg_new_token(), $row['id']]);
        } elseif ($wanted > $current) {
            $pdo->prepare('UPDATE bookings SET status = ?, order_id = ?, fail_reason = COALESCE(fail_reason, ?) WHERE id = ?')
                ->execute([$status, $orderId, $status === 'failed' ? $reason : null, $row['id']]);
        } elseif ($fillPayment || $row['order_id'] === null) {
            // Same or weaker news, but a gap to fill: the order id or the payment id.
            $pdo->prepare('UPDATE bookings SET order_id = ?, payment_id = COALESCE(payment_id, ?) WHERE id = ?')
                ->execute([$orderId, $fillPayment ? $paymentId : null, $row['id']]);
        } elseif (!$fillSession) {
            $pdo->commit();
            return 'unchanged';
        }
        $pdo->commit();
        return 'updated';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('contact store: order ' . $orderId . ' not recorded: ' . $e->getMessage());
        return 'error';
    }
}

/**
 * A payment try failed (Razorpay's payment.failed). Only a booking we already
 * know is touched, so payments for other products on the shared account never
 * land here. A paid booking is never downgraded. Returns true if a booking changed.
 */
function tg_mark_failed(array $config, string $orderId, string $reason): bool
{
    $pdo = tg_db($config);
    if ($pdo === null || $orderId === '') {
        return false;
    }
    try {
        $update = $pdo->prepare("UPDATE bookings SET status = 'failed', fail_reason = ?
            WHERE order_id = ? AND status IN ('started', 'failed')");
        $update->execute([mb_substr($reason, 0, 255), $orderId]);
        return $update->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('contact store: failure for ' . $orderId . ' not recorded: ' . $e->getMessage());
        return false;
    }
}

/** The order's `consent` note: "v1" or "v1 offers". Returns [version or null, offers yes/no]. */
function tg_parse_consent(string $note): array
{
    $parts = preg_split('/\s+/', trim($note)) ?: [];
    $version = in_array($parts[0] ?? '', TG_CONSENT_VERSIONS, true) ? $parts[0] : null;
    return [$version, $version !== null && in_array('offers', $parts, true)];
}

/** True when this order id belongs to a booking we know, for verify.php. Null when the store can't say. */
function tg_known_order(array $config, string $orderId): ?bool
{
    $pdo = tg_db($config);
    if ($pdo === null) {
        return null;
    }
    try {
        $find = $pdo->prepare('SELECT product FROM bookings WHERE order_id = ?');
        $find->execute([$orderId]);
        $product = $find->fetchColumn();
        return $product === false ? null : $product === $config['product'];
    } catch (Throwable $e) {
        error_log('contact store: lookup failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * A paid booking found by its personal token, for the join and stop pages.
 * Returns the row, false when no paid booking has this token, or null when the
 * store can't say (switched off or down).
 */
function tg_booking_by_token(array $config, string $token)
{
    $pdo = tg_db($config);
    if ($pdo === null) {
        return null;
    }
    try {
        $find = $pdo->prepare("SELECT b.id, b.session_at, b.reminders_off_at, c.name FROM bookings b
            JOIN contacts c ON c.id = b.contact_id WHERE b.join_token = ? AND b.status = 'paid'");
        $find->execute([$token]);
        return $find->fetch() ?: false;
    } catch (Throwable $e) {
        error_log('contact store: token lookup failed: ' . $e->getMessage());
        return null;
    }
}

/** Counts a click on a buyer's join link. A click means "probably attended". */
function tg_log_join(array $config, int $bookingId): void
{
    $pdo = tg_db($config);
    if ($pdo === null) {
        return;
    }
    try {
        $pdo->prepare('UPDATE bookings SET join_clicks = join_clicks + 1, first_join_at = COALESCE(first_join_at, ?) WHERE id = ?')
            ->execute([tg_ist(rp_now($config)), $bookingId]);
    } catch (Throwable $e) {
        error_log('contact store: join click not logged: ' . $e->getMessage());
    }
}

/** Stops reminder emails for one booking and cancels any already queued. */
function tg_stop_reminders(array $config, int $bookingId): bool
{
    $pdo = tg_db($config);
    if ($pdo === null) {
        return false;
    }
    try {
        $at = tg_ist(rp_now($config));
        $pdo->prepare('UPDATE bookings SET reminders_off_at = COALESCE(reminders_off_at, ?) WHERE id = ?')->execute([$at, $bookingId]);
        $pdo->prepare("UPDATE outbox SET status = 'cancelled', last_error = 'Buyer stopped reminders'
            WHERE booking_id = ? AND status = 'queued'")->execute([$bookingId]);
        return true;
    } catch (Throwable $e) {
        error_log('contact store: stop failed: ' . $e->getMessage());
        return false;
    }
}

/* ---------- Coupons made on the dashboard ----------
 * The config file's coupon keeps working (rp_coupon_claim in lib.php checks it first).
 * These live in the coupons table so Deenu can make them without editing the config.
 * The seat price is always worked out here from the server's price_paise.
 */

/** Codes are stored and compared in capitals with no spaces, so " diwali 50 " finds DIWALI50. */
function tg_coupon_norm(string $code): string
{
    return strtoupper(preg_replace('/\s+/u', '', $code) ?? '');
}

/** A code that can exist: 4 to 24 capital letters, digits or hyphens, starting and ending with a letter or digit. */
function tg_coupon_code_ok(string $code): bool
{
    return (bool) preg_match('/^[A-Z0-9][A-Z0-9-]{2,22}[A-Z0-9]$/', $code);
}

/**
 * The seat price with a coupon, in paise. A percent comes off to the nearest rupee.
 * Never above the full price, never below Razorpay's ₹1 minimum.
 */
function tg_coupon_seat(int $price, string $type, int $value): int
{
    $off = $type === 'percent' ? (int) round($price * max(0, min(100, $value)) / 10000) * 100 : max(0, $value);
    return max(100, min($price, $price - $off));
}

/** Where a coupon stands at a moment: active, scheduled, paused, expired or used_up. */
function tg_coupon_state(array $c, int $now): string
{
    $ist = new DateTimeZone('Asia/Kolkata');
    $at = static fn(?string $t): ?int => $t === null ? null : (new DateTimeImmutable($t, $ist))->getTimestamp();
    $start = $at($c['starts_at']);
    $ends = $at($c['ends_at']);
    return match (true) {
        (int) $c['paused'] === 1                                          => 'paused',
        $ends !== null && $now >= $ends                                   => 'expired',
        $c['max_uses'] !== null && (int) $c['used'] >= (int) $c['max_uses'] => 'used_up',
        $start !== null && $now < $start                                  => 'scheduled',
        default                                                           => 'active',
    };
}

/**
 * Finds a dashboard coupon that works right now and returns ['id', 'seat_paise'], or null.
 * With $reserve, one use is taken by the same statement that checks the limit, so two
 * buyers at once can never both take the last use. Null too when the store is off or down.
 */
function tg_coupon_claim(array $config, string $code, bool $reserve): ?array
{
    $code = tg_coupon_norm($code);
    $pdo = tg_coupon_code_ok($code) ? tg_db($config) : null;
    if ($pdo === null) {
        return null;
    }
    $now = tg_ist(rp_now($config));
    $live = 'code = ? AND paused = 0 AND (starts_at IS NULL OR starts_at <= ?) AND (ends_at IS NULL OR ends_at > ?)
        AND (max_uses IS NULL OR used < max_uses)';
    try {
        if ($reserve) {
            $take = $pdo->prepare("UPDATE coupons SET used = used + 1 WHERE {$live}");
            $take->execute([$code, $now, $now]);
            if ($take->rowCount() !== 1) {
                return null;
            }
            $find = $pdo->prepare('SELECT id, discount_type, discount_value FROM coupons WHERE code = ?');
            $find->execute([$code]);
        } else {
            $find = $pdo->prepare("SELECT id, discount_type, discount_value FROM coupons WHERE {$live}");
            $find->execute([$code, $now, $now]);
        }
        $c = $find->fetch();
    } catch (Throwable $e) {
        error_log('contact store: coupon check failed: ' . $e->getMessage());
        return null;
    }
    if (!$c) {
        return null;
    }
    return [
        'id'         => (int) $c['id'],
        'seat_paise' => tg_coupon_seat((int) $config['price_paise'], (string) $c['discount_type'], (int) $c['discount_value']),
    ];
}

/** Gives back a use taken by tg_coupon_claim, when Razorpay did not create the order. */
function tg_coupon_release(array $config, int $id): void
{
    $pdo = tg_db($config);
    if ($pdo === null) {
        return;
    }
    try {
        $pdo->prepare('UPDATE coupons SET used = used - 1 WHERE id = ? AND used > 0')->execute([$id]);
    } catch (Throwable $e) {
        error_log('contact store: coupon use not given back: ' . $e->getMessage());
    }
}
