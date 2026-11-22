<?php
/**
 * Everything the dashboard can change, in one place. Each change arrives as a POST
 * with the page's token, is logged in the audit table, and goes back to the page it
 * came from with a short message. While two-step sign-in is on, settings changes also
 * need a fresh code from the authenticator app, so a stolen session alone can't make them.
 */
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/coupons.php';
require_once __DIR__ . '/recordings.php';

/** A short message for the next page: tone good, warn or bad. */
function admin_flash(string $tone, string $text, array $extra = []): void
{
    $_SESSION['flash'] = ['tone' => $tone, 'text' => $text] + $extra;
}

/** Where to go after a change: only a page of this dashboard. */
function admin_back(): string
{
    $back = (string) ($_POST['back'] ?? './');
    return preg_match('~^\./(\?[^\s#\\\\]*)?(#[A-Za-z0-9_-]{1,40})?$~', $back) && !str_contains($back, '//') ? $back : './';
}

function admin_done(): void
{
    header('Location: ' . admin_back(), true, 303);
    exit;
}

/** Checks the authenticator code typed with a settings change. Wrong codes count toward the lockout. */
function admin_code_ok(PDO $pdo, array $config): bool
{
    if (!ADMIN_TWO_STEP) {
        return true; // two-step sign-in is off, so changes need no code
    }
    [$fails] = admin_fails($config);
    if ($fails >= ADMIN_MAX_FAILS) {
        admin_flash('bad', 'Too many wrong codes. Wait 15 minutes, then try again.');
        return false;
    }
    if (totp_confirm($pdo, (string) ($_POST['code'] ?? ''), time())) {
        return true;
    }
    admin_fails($config, true);
    tg_audit($config, 'admin', 'code_failed', 'settings change');
    admin_flash('bad', 'That code did not work, so nothing changed. Use the newest code from your authenticator app.');
    return false;
}

function admin_handle_post(PDO $pdo, array $config): void
{
    if (!admin_token_ok()) {
        admin_flash('bad', 'This page was open too long. Nothing changed. Try again.');
        admin_done();
    }
    $action = (string) ($_POST['action'] ?? '');
    $defs = admin_setting_defs();

    switch ($action) {
        // ---------- Settings ----------
        case 'setting_save':
            $values = is_array($_POST['values'] ?? null) ? $_POST['values'] : [];
            $values = array_filter(array_intersect_key($values, $defs), 'is_string');
            if (!$values) {
                break;
            }
            $clean = [];
            foreach ($values as $name => $raw) {
                [$value, $error] = $defs[$name]['check']((string) $raw);
                if ($error !== null) {
                    admin_flash('bad', $error . ' Nothing changed.');
                    admin_done();
                }
                $clean[$name] = $value;
            }
            if (isset($clean['zoom.join_url']) && admin_session_live(time()) && empty($_POST['live_ok'])) {
                admin_flash('bad', 'A session is live right now, so the Zoom link was not changed. Tick the box if you really mean to change it now.');
                admin_done();
            }
            if (!admin_code_ok($pdo, $config)) {
                admin_done();
            }
            $changed = [];
            foreach ($clean as $name => $value) {
                $current = admin_setting($config, $name)[0];
                if (in_array($name, ['coupon.paused', 'whatsapp.paused'], true) ? (bool) $current === $value : $current === $value) {
                    continue;
                }
                if (!admin_setting_write($pdo, $config, $name, $value)) {
                    admin_flash('bad', 'The server could not save the change. Nothing changed.');
                    admin_done();
                }
                $changed[] = $defs[$name]['label'];
            }
            admin_flash('good', $changed ? 'Saved: ' . implode(', ', $changed) . '. It is live now.' : 'Nothing was different, so nothing changed.');
            break;

        case 'setting_reset':
            // "coupon" resets both coupon settings together.
            $name = (string) ($_POST['name'] ?? '');
            $names = $name === 'coupon' ? ['coupon.paused', 'coupon.max_uses'] : [$name];
            if (array_diff($names, array_keys($defs)) || !admin_code_ok($pdo, $config)) {
                break;
            }
            $ok = true;
            foreach ($names as $n) {
                if (admin_setting($config, $n)[1] === 'dashboard') {
                    $ok = admin_setting_write($pdo, $config, $n, null) && $ok;
                }
            }
            admin_flash($ok ? 'good' : 'bad', $ok ? ($name === 'coupon' ? 'The coupon' : $defs[$name]['label']) . ' now follows the config file again.' : 'The server could not save the change.');
            break;

        case 'setting_undo':
            $change = row($pdo, 'SELECT * FROM setting_changes WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
            if (!$change || !isset($defs[$change['name']]) || !admin_code_ok($pdo, $config)) {
                break;
            }
            $value = $change['old_value'] === null ? null : json_decode($change['old_value'], true);
            $ok = admin_setting_write($pdo, $config, $change['name'], $value);
            admin_flash($ok ? 'good' : 'bad', $ok ? 'Undone: ' . $defs[$change['name']]['label'] . ' is back to what it was.' : 'The server could not save the change.');
            break;

        case 'backup_codes_new':
            if (!ADMIN_TWO_STEP || !admin_code_ok($pdo, $config)) {
                break;
            }
            [$plain, $hashes] = totp_backup_codes();
            $pdo->prepare('UPDATE admin_auth SET backup_codes = ? WHERE id = 1')->execute([json_encode($hashes)]);
            tg_audit($config, 'admin', 'backup_codes_new');
            admin_flash('good', 'New backup codes. The old ones no longer work. Save these somewhere safe.', ['codes' => $plain]);
            break;

        // ---------- Add-on delivery ----------
        case 'addon_sent':
            $id = (int) ($_POST['booking_id'] ?? 0);
            $sent = ($_POST['sent'] ?? '') === '1';
            $q = $pdo->prepare("UPDATE bookings SET addon_sent_at = " . ($sent ? 'COALESCE(addon_sent_at, ?)' : 'NULL') . "
                WHERE id = ? AND addon = 1 AND status = 'paid' AND product = ?");
            $q->execute($sent ? [tg_ist(time()), $id, $config['product']] : [$id, $config['product']]);
            if ($q->rowCount() > 0) {
                tg_audit($config, 'admin', $sent ? 'addon_sent' : 'addon_unsent', 'booking ' . $id);
                admin_flash('good', $sent ? 'Marked as sent.' : 'Marked as not sent yet.');
            }
            break;

        case 'addon_sent_session':
            $at = (string) ($_POST['session_at'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $at)) {
                break;
            }
            $q = $pdo->prepare("UPDATE bookings SET addon_sent_at = ? WHERE session_at = ? AND addon = 1 AND status = 'paid'
                AND product = ? AND addon_sent_at IS NULL");
            $q->execute([tg_ist(time()), $at, $config['product']]);
            tg_audit($config, 'admin', 'addon_sent', $q->rowCount() . ' buyers, session ' . $at);
            admin_flash('good', $q->rowCount() . ' add-on buyer' . ($q->rowCount() === 1 ? '' : 's') . ' marked as sent.');
            break;

        // ---------- Resend a confirmation email ----------
        case 'resend_confirm':
            admin_resend_confirmation($pdo, $config, (int) ($_POST['booking_id'] ?? 0));
            break;

        // ---------- Coupons (lib/coupons.php) ----------
        case 'coupon_create':
        case 'coupon_pause':
        case 'coupon_resume':
        case 'coupon_delete':
            admin_coupon_action($pdo, $config, $action);
            break;

        // ---------- Recordings (lib/recordings.php) ----------
        case 'recording_add':
        case 'recording_delete':
            admin_recording_action($pdo, $config, $action);
            break;

        // ---------- WhatsApp ----------
        case 'wa_submit':
            if (!tg_wa_ready($config)) {
                break;
            }
            $result = tg_wa_submit_templates($config);
            admin_wa_forget($config);
            if ($result === null) {
                admin_flash('bad', 'Meta could not be reached, so nothing was submitted. Check the token in the private config.');
                break;
            }
            tg_audit($config, 'admin', 'whatsapp_submit', implode('; ', array_map(static fn($r) => $r[0] . ': ' . $r[1], $result)));
            admin_flash('good', 'Sent to Meta. ' . implode('. ', array_map(static fn($r) => $r[0] . ': ' . $r[1], $result)) . '.');
            break;

        case 'wa_subscribe':
            if (!tg_wa_ready($config)) {
                break;
            }
            [$status, $body] = tg_wa_graph($config, 'POST', rawurlencode((string) $config['whatsapp']['waba_id']) . '/subscribed_apps');
            admin_wa_forget($config);
            $ok = $status >= 200 && $status < 300;
            tg_audit($config, 'admin', 'whatsapp_subscribe', $ok ? 'connected' : tg_wa_error_text($config, $status, $body));
            admin_flash($ok ? 'good' : 'bad', $ok ? 'Connected. Meta now sends delivery news and replies to this site.'
                : 'Meta refused: ' . tg_wa_error_text($config, $status, $body));
            break;

        case 'wa_register':
            $pin = (string) ($_POST['pin'] ?? '');
            if (!tg_wa_ready($config) || !preg_match('/^\d{6}$/', $pin)) {
                admin_flash('bad', 'The PIN must be 6 digits. Nothing changed.');
                break;
            }
            if (!admin_code_ok($pdo, $config)) {
                break;
            }
            [$status, $body] = tg_wa_graph($config, 'POST', rawurlencode((string) $config['whatsapp']['phone_number_id']) . '/register',
                ['messaging_product' => 'whatsapp', 'pin' => $pin]);
            admin_wa_forget($config);
            $ok = $status >= 200 && $status < 300;
            tg_audit($config, 'admin', 'whatsapp_register', $ok ? 'registered' : tg_wa_error_text($config, $status, $body));
            admin_flash($ok ? 'good' : 'bad', $ok ? 'Registered. The number can send now. Save the PIN in your password manager.'
                : 'Meta refused: ' . tg_wa_error_text($config, $status, $body));
            break;

        case 'wa_test':
            admin_wa_test($pdo, $config, (string) ($_POST['template'] ?? ''), (string) ($_POST['to'] ?? ''));
            break;
    }
    admin_done();
}

/**
 * Sends one WhatsApp template to a test number with sample details, and logs it in the
 * send log so its delivered and read ticks show up there.
 */
function admin_wa_test(PDO $pdo, array $config, string $template, string $to): void
{
    $to = tg_wa_number($to) ?? '';
    if (!tg_wa_ready($config) || !isset(tg_wa_templates($config)[$template]) || !in_array($to, tg_wa_test_numbers($config), true)) {
        admin_flash('bad', 'Only the test numbers in the config can get a test message.');
        return;
    }
    $sample = ['name' => 'Priya Sharma', 'session_at' => rp_next_session(time())->format('Y-m-d H:i:s'), 'join_token' => 'sample0000000000000000ab'];
    try {
        $result = tg_wa_send($config, $template, $to, tg_wa_values($config, $template, $sample));
    } catch (Throwable $e) {
        admin_flash('bad', 'Not sent: ' . $e->getMessage());
        return;
    }
    $at = tg_ist(time());
    $pdo->prepare("INSERT INTO outbox (dedupe_key, channel, template, booking_id, to_addr, due_at, expires_at, status, tries, last_error, sent_at, provider_id)
        VALUES (?, 'whatsapp', ?, NULL, ?, ?, ?, ?, 1, ?, ?, ?)")
        ->execute(['wa:test:' . $template . ':' . bin2hex(random_bytes(6)), $template, $to, $at, $at,
            $result['ok'] ? 'sent' : 'failed', $result['ok'] ? 'Test message' : mb_substr((string) $result['error'], 0, 255),
            $result['ok'] ? $at : null, $result['id']]);
    // A test failure is not news for the alert email.
    if (!$result['ok']) {
        $pdo->prepare('UPDATE outbox SET alerted_at = ? WHERE id = ?')->execute([$at, (int) $pdo->lastInsertId()]);
    }
    tg_audit($config, 'admin', 'whatsapp_test', $template . ' to ' . tg_wa_pretty($to) . ': ' . ($result['ok'] ? 'sent' : $result['error']));
    admin_flash($result['ok'] ? 'good' : 'bad', $result['ok']
        ? 'Sent to ' . tg_wa_pretty($to) . '. Check your phone. Delivered and read show in the send log within a minute.'
        : 'Not sent. ' . $result['error']);
}

/**
 * Sends a paid buyer's confirmation email again, exactly as the webhook builds it.
 * It then counts as sent, so a late Razorpay retry won't send a second copy.
 */
function admin_resend_confirmation(PDO $pdo, array $config, int $bookingId): void
{
    $b = row($pdo, "SELECT b.*, c.name, c.email, c.phone FROM bookings b JOIN contacts c ON c.id = b.contact_id
        WHERE b.id = ? AND b.status = 'paid' AND b.product = ?", [$bookingId, $config['product']]);
    $paymentId = (string) ($b['payment_id'] ?? '');
    if (!$b || !preg_match('/^[A-Za-z0-9_]+$/', $paymentId)) {
        admin_flash('bad', 'That booking is not a paid seat, so no email was sent.');
        return;
    }
    $session = $b['session_at'] ? new DateTimeImmutable($b['session_at'], new DateTimeZone('Asia/Kolkata')) : null;
    $join = $b['join_token'] ? tg_join_url($config, $b['join_token']) : '';
    $sale = [
        'name'       => $b['name'] !== '' ? $b['name'] : 'there',
        'email'      => $b['email'],
        'phone'      => $b['phone'],
        'addon'      => (int) $b['addon'] === 1,
        'coupon'     => (int) $b['coupon'] === 1,
        'session'    => $session ? rp_session_when($session) : '',
        'amount'     => (int) $b['amount_paise'],
        'payment_id' => $paymentId,
        'join_url'   => $join,
        'ics'        => $session ? tg_ics($config, $session->getTimestamp(), $paymentId, $join ?: null) : '',
    ];
    $route = '';
    if (!rp_send_buyer_email($config, $sale, $route)) {
        tg_audit($config, 'admin', 'resend_failed', $paymentId . ': ' . $route);
        admin_flash('bad', 'The mail server did not take it, so it was not sent. Try again in a few minutes. The reason is in the Activity log.');
        return;
    }
    $marker = $config['sent_dir'] . '/' . $paymentId;
    $current = (string) @file_get_contents($marker);
    if (!str_starts_with($current, 'sent')) {
        if (!is_dir($config['sent_dir'])) {
            @mkdir($config['sent_dir'], 0700, true);
        }
        @file_put_contents($marker, 'sent by hand');
    }
    @unlink(admin_private($config) . '/razorpay-mailfail/' . $paymentId);
    tg_audit($config, 'admin', 'resend_confirm', $paymentId . ' to ' . $b['email']);
    admin_flash('good', 'Confirmation email sent again to ' . $b['email'] . '.');
}
