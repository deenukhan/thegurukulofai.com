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
 */
function tg_db_migrate(PDO $pdo, array $config): void
{
    $version = 1;
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

    @file_put_contents($marker, (string) time());
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
                (contact_id, product, status, session_at, addon, coupon, amount_paise,
                 utm_source, utm_medium, utm_campaign, utm_content, source)
            VALUES (?, ?, 'started', ?, ?, ?, ?, ?, ?, ?, ?, 'checkout')")
            ->execute([
                $contactId,
                $lead['product'],
                tg_ist($lead['session_at']),
                $lead['addon'] ? 1 : 0,
                $lead['coupon'] ? 1 : 0,
                $lead['amount'],
                $lead['utm']['utm_source'] ?? '',
                $lead['utm']['utm_medium'] ?? '',
                $lead['utm']['utm_campaign'] ?? '',
                $lead['utm']['utm_content'] ?? '',
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
    $sessionAt = (int) ($notes['session_at'] ?? 0);
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

        $find = $pdo->prepare('SELECT id, status, payment_id, order_id FROM bookings WHERE order_id = ? FOR UPDATE');
        $find->execute([$orderId]);
        $row = $find->fetch();
        if (!$row && ctype_digit((string) ($notes['ref'] ?? ''))) {
            $find = $pdo->prepare('SELECT id, status, payment_id, order_id FROM bookings
                WHERE id = ? AND order_id IS NULL AND contact_id = ? FOR UPDATE');
            $find->execute([(int) $notes['ref'], $contactId]);
            $row = $find->fetch();
        }

        if (!$row) {
            $pdo->prepare("INSERT INTO bookings
                    (contact_id, order_id, payment_id, product, status, session_at, addon, coupon, amount_paise,
                     utm_source, utm_medium, utm_campaign, utm_content, fail_reason, source, created_at, paid_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
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
                ]);
            $pdo->commit();
            return 'added';
        }

        $current = TG_STATUS_RANK[$row['status']] ?? 0;
        $wanted  = TG_STATUS_RANK[$status] ?? 0;
        $fillPayment = $status === 'paid' && $paymentId !== '' && $row['payment_id'] === null;
        if ($wanted > $current && $status === 'paid') {
            $pdo->prepare("UPDATE bookings SET status = 'paid', order_id = ?, payment_id = COALESCE(payment_id, ?),
                    amount_paise = ?, paid_at = COALESCE(paid_at, ?), fail_reason = NULL WHERE id = ?")
                ->execute([$orderId, $paymentId !== '' ? $paymentId : null, $amount, $paidAt, $row['id']]);
        } elseif ($wanted > $current) {
            $pdo->prepare('UPDATE bookings SET status = ?, order_id = ?, fail_reason = COALESCE(fail_reason, ?) WHERE id = ?')
                ->execute([$status, $orderId, $status === 'failed' ? $reason : null, $row['id']]);
        } elseif ($fillPayment || $row['order_id'] === null) {
            // Same or weaker news, but a gap to fill: the order id or the payment id.
            $pdo->prepare('UPDATE bookings SET order_id = ?, payment_id = COALESCE(payment_id, ?) WHERE id = ?')
                ->execute([$orderId, $fillPayment ? $paymentId : null, $row['id']]);
        } else {
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
