<?php
/**
 * The outbox: every scheduled email is one row with a unique dedupe key, so a
 * message is never sent twice, however often the worker runs.
 *
 * cron/worker.php runs tg_outbox_run() every 5 minutes. Each run:
 *   1. gives every paid booking its personal join token,
 *   2. plans the four reminders for each paid booking, from its own session time,
 *   3. plans the 9 AM summary for Deenu,
 *   4. cancels reminders a buyer stopped, and drops any whose time has passed,
 *   5. sends what is due, a limited number per run,
 *   6. retries failures every 5 minutes until the message's window closes,
 *   7. emails Deenu once about anything that finally failed.
 *
 * WhatsApp (whatsapp.php) rides along: while it is switched on, each paid buyer who
 * agreed to booking updates gets the confirmation and the same four reminders on
 * WhatsApp too, as rows with channel 'whatsapp'. Email and WhatsApp fail separately,
 * so a WhatsApp problem never holds up an email, and the other way round.
 *
 * The checkout coupon (template 'nudge') also rides along when switched on: someone who
 * filled the pop-up but hasn't paid 20 minutes after their last try gets one WhatsApp
 * with a personal coupon, made at the moment it is sent. See TG_NUDGE_* in whatsapp.php.
 *
 * All times are worked out here in PHP and passed to SQL, never SQL's NOW(),
 * so the test clock (`test_now`) moves everything together.
 */
require_once __DIR__ . '/whatsapp.php';

const TG_RETRY_SECONDS = 300;
const TG_MAX_TRIES = 4;
/** How long after payment the WhatsApp confirmation may still go (never after the session starts). */
const TG_WA_CONFIRM_WINDOW = 6 * 3600;

/** Unix time of a stored IST DATETIME. */
function tg_unix(string $ist): int
{
    return (new DateTimeImmutable($ist, new DateTimeZone('Asia/Kolkata')))->getTimestamp();
}

function tg_outbox_run(array $config, int $now): array
{
    $sum = ['planned' => 0, 'sent' => 0, 'retrying' => 0, 'failed' => 0, 'expired' => 0, 'cancelled' => 0, 'alerted' => 0,
        'paused' => false, 'wa_paused' => false];
    $pdo = tg_db($config);
    if ($pdo === null) {
        return $sum + ['error' => 'The contact store is off or unreachable.'];
    }
    $at = tg_ist($now);
    $wa = tg_wa_enabled($config) && !tg_wa_paused($config);

    // 1. Personal tokens for any paid booking without one (for example, added by reconcile).
    $missing = $pdo->query("SELECT id FROM bookings WHERE status = 'paid' AND join_token IS NULL")->fetchAll(PDO::FETCH_COLUMN);
    $setToken = $pdo->prepare('UPDATE bookings SET join_token = ? WHERE id = ? AND join_token IS NULL');
    foreach ($missing as $id) {
        $setToken->execute([tg_new_token(), $id]);
    }

    // 2. Reminders. A reminder is planned only if its moment came after the buyer
    // paid (so a late buyer gets future reminders only), and only while its window
    // is still open (so nothing is ever sent late). The dedupe key makes this safe
    // to repeat on every run.
    $paid = $pdo->prepare("SELECT b.id, b.session_at, COALESCE(b.paid_at, b.created_at) AS paid_at, c.email,
            c.phone, c.consent_version, c.wa_optout_at
        FROM bookings b JOIN contacts c ON c.id = b.contact_id
        WHERE b.status = 'paid' AND b.product = ? AND b.reminders_off_at IS NULL
          AND b.session_at IS NOT NULL AND b.session_at > ?");
    $paid->execute([$config['product'], tg_ist($now - 3600)]);
    $plan = $pdo->prepare('INSERT INTO outbox (dedupe_key, template, booking_id, to_addr, due_at, expires_at)
        VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id');
    $planWa = $pdo->prepare("INSERT INTO outbox (dedupe_key, channel, template, booking_id, to_addr, due_at, expires_at)
        VALUES (?, 'whatsapp', ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id");
    foreach ($paid->fetchAll() as $b) {
        $start = tg_unix($b['session_at']);
        $paidAt = tg_unix($b['paid_at']);
        foreach (TG_REMINDERS as $template => $r) {
            $due = $start + $r['offset'];
            $expires = $due + $r['window'];
            if ($due < $paidAt || $expires <= $now) {
                continue;
            }
            $key = 'email:' . $template . ':b' . $b['id'] . ':' . gmdate('YmdHi', $start);
            $plan->execute([$key, $template, $b['id'], $b['email'], tg_ist($due), tg_ist($expires)]);
            $sum['planned'] += $plan->rowCount() === 1 ? 1 : 0;
        }

        // The same on WhatsApp, only for someone who agreed to booking updates there
        // (the pop-up's consent wording), has a usable number and never said STOP.
        $number = $wa ? tg_wa_number((string) $b['phone']) : null;
        if ($number === null || $b['consent_version'] === null || $b['wa_optout_at'] !== null
            || !tg_wa_allowed($config, $number) || tg_wa_opted_out($pdo, $number)) {
            continue;
        }
        $waPlan = ['confirm' => [$paidAt, min($paidAt + TG_WA_CONFIRM_WINDOW, $start)]];
        foreach (TG_REMINDERS as $template => $r) {
            $waPlan[$template] = [$start + $r['offset'], $start + $r['offset'] + $r['window']];
        }
        foreach ($waPlan as $template => [$due, $expires]) {
            if (($template !== 'confirm' && $due < $paidAt) || $expires <= $now) {
                continue;
            }
            $key = 'wa:' . $template . ':b' . $b['id'] . ':' . gmdate('YmdHi', $start);
            $planWa->execute([$key, $template, $b['id'], $number, tg_ist($due), tg_ist($expires)]);
            $sum['planned'] += $planWa->rowCount() === 1 ? 1 : 0;
        }
    }

    // 2b. Checkout coupon. Only each person's latest try counts, only once it is 20
    // minutes old, and only tries from the last few hours (switching it on never
    // reaches back to old leads). One per person per session, by the dedupe key.
    if ($wa && tg_nudge_on($config)) {
        $unpaid = $pdo->prepare("SELECT b.id, b.contact_id, b.created_at, b.session_at, c.phone, c.consent_version, c.wa_optout_at
            FROM bookings b JOIN contacts c ON c.id = b.contact_id
            WHERE b.product = ? AND b.status IN ('started', 'failed') AND b.created_at <= ? AND b.created_at > ?
              AND NOT EXISTS (SELECT 1 FROM bookings n WHERE n.contact_id = b.contact_id AND n.id > b.id)");
        $unpaid->execute([$config['product'], tg_ist($now - TG_NUDGE_AFTER), tg_ist($now - TG_NUDGE_LOOKBACK)]);
        foreach ($unpaid->fetchAll() as $b) {
            $number = tg_wa_number((string) $b['phone']);
            if ($number === null || $b['consent_version'] === null || $b['wa_optout_at'] !== null
                || !tg_wa_allowed($config, $number) || tg_wa_opted_out($pdo, $number)
                || tg_nudge_paid_since($pdo, (int) $b['id'], $now)) {
                continue;
            }
            $tried = tg_unix($b['created_at']);
            $session = $b['session_at'] !== null ? gmdate('YmdHi', tg_unix($b['session_at'])) : 'none';
            $planWa->execute(['wa:nudge:c' . $b['contact_id'] . ':' . $session, 'nudge', $b['id'], $number,
                tg_ist($tried + TG_NUDGE_AFTER), tg_ist($tried + TG_NUDGE_LOOKBACK)]);
            $sum['planned'] += $planWa->rowCount() === 1 ? 1 : 0;
        }
    }

    // 3. Deenu's summary, at 9 AM IST, sendable until noon.
    $nine = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Asia/Kolkata'))->setTime(9, 0);
    if ($nine->getTimestamp() + 3 * 3600 > $now) {
        $plan->execute(['email:digest:' . $nine->format('Y-m-d'), 'digest', null, $config['mail_notify'],
            tg_ist($nine->getTimestamp()), tg_ist($nine->getTimestamp() + 3 * 3600)]);
    }

    // 4. Cancel reminders that should no longer go, and drop those whose time passed.
    $cancel = $pdo->prepare("UPDATE outbox o JOIN bookings b ON b.id = o.booking_id
        SET o.status = 'cancelled', o.last_error = 'Buyer stopped reminders, or the booking is no longer paid'
        WHERE o.status = 'queued' AND o.template <> 'nudge' AND (b.reminders_off_at IS NOT NULL OR b.status <> 'paid')");
    $cancel->execute();
    $sum['cancelled'] = $cancel->rowCount();
    if (!tg_nudge_on($config)) {
        $off = $pdo->prepare("UPDATE outbox SET status = 'cancelled', last_error = 'Checkout coupon switched off', alerted_at = ?
            WHERE template = 'nudge' AND status = 'queued'");
        $off->execute([$at]);
        $sum['cancelled'] += $off->rowCount();
    }
    // A checkout coupon is for someone who hasn't paid: once they have, it never goes.
    $cancelNudge = $pdo->prepare("UPDATE outbox SET status = 'cancelled', last_error = 'Paid in the meantime' WHERE id = ? AND status = 'queued'");
    foreach ($pdo->query("SELECT id, booking_id FROM outbox WHERE template = 'nudge' AND status = 'queued'")->fetchAll() as $n) {
        if (tg_nudge_paid_since($pdo, (int) $n['booking_id'], $now)) {
            $cancelNudge->execute([$n['id']]);
            $sum['cancelled'] += $cancelNudge->rowCount();
        }
    }
    $cancelWa = $pdo->prepare("UPDATE outbox o JOIN bookings b ON b.id = o.booking_id JOIN contacts c ON c.id = b.contact_id
        SET o.status = 'cancelled', o.last_error = 'Replied STOP on WhatsApp'
        WHERE o.channel = 'whatsapp' AND o.status = 'queued' AND c.wa_optout_at IS NOT NULL");
    $cancelWa->execute();
    $sum['cancelled'] += $cancelWa->rowCount();

    // A send that never finished (the run was killed) may or may not have gone out.
    // It is not retried, so no one gets it twice; Deenu is told instead.
    $pdo->prepare("UPDATE outbox SET status = 'failed', last_error = 'Interrupted while sending; it may not have gone out'
        WHERE status = 'sending' AND next_try_at < ?")->execute([tg_ist($now - 900)]);

    // WhatsApp messages that ran out of time while WhatsApp was off or paused are
    // dropped quietly: pausing was Deenu's choice, so no alert, and the email still went.
    if (!$wa) {
        $pdo->prepare("UPDATE outbox SET status = 'expired', last_error = 'WhatsApp was off or paused', alerted_at = ?
            WHERE channel = 'whatsapp' AND status = 'queued' AND expires_at <= ?")->execute([$at, $at]);
    }
    $expire = $pdo->prepare("UPDATE outbox SET status = 'expired',
            last_error = COALESCE(last_error, 'Its time passed before it could be sent')
        WHERE status = 'queued' AND expires_at <= ?");
    $expire->execute([$at]);
    $sum['expired'] = $expire->rowCount();

    // 5. Send what is due. A limited number per run keeps the mailbox under its sending limits.
    $max = max(1, (int) ($config['outbox']['max_per_run'] ?? 60));
    $pauseMs = max(0, (int) ($config['outbox']['pause_ms'] ?? 200));
    $channels = $wa ? "'email', 'whatsapp'" : "'email'";
    $due = $pdo->prepare("SELECT o.*, b.session_at, b.join_token, c.name
        FROM outbox o LEFT JOIN bookings b ON b.id = o.booking_id LEFT JOIN contacts c ON c.id = b.contact_id
        WHERE o.status = 'queued' AND o.channel IN ({$channels}) AND o.due_at <= ? AND (o.next_try_at IS NULL OR o.next_try_at <= ?)
        ORDER BY o.due_at, o.id LIMIT {$max}");
    $due->execute([$at, $at]);
    $claim = $pdo->prepare("UPDATE outbox SET status = 'sending', next_try_at = ? WHERE id = ? AND status = 'queued'");
    $done = $pdo->prepare("UPDATE outbox SET status = 'sent', sent_at = ?, tries = tries + 1, last_error = NULL,
        provider_id = COALESCE(?, provider_id) WHERE id = ?");
    $retry = $pdo->prepare('UPDATE outbox SET status = ?, tries = tries + 1, next_try_at = ?, last_error = ? WHERE id = ?');
    $drop = $pdo->prepare("UPDATE outbox SET status = 'cancelled', last_error = ? WHERE id = ? AND status = 'queued'");

    // Failures in a row, per channel. Three means that channel is down: its rest wait for the next run.
    $streak = ['email' => 0, 'whatsapp' => 0];
    foreach ($due->fetchAll() as $row) {
        $channel = $row['channel'] === 'whatsapp' ? 'whatsapp' : 'email';
        if ($streak[$channel] >= 3) {
            continue;
        }
        // Checked again at the last moment: the test list may have changed, or they said STOP.
        if ($channel === 'whatsapp') {
            $why = !tg_wa_allowed($config, $row['to_addr']) ? 'Not on the WhatsApp test list'
                : (tg_wa_opted_out($pdo, $row['to_addr']) ? 'Replied STOP on WhatsApp' : null);
            if ($why !== null) {
                $drop->execute([$why, $row['id']]);
                $sum['cancelled'] += $drop->rowCount();
                continue;
            }
        }
        $claim->execute([$at, $row['id']]);
        if ($claim->rowCount() !== 1) {
            continue;
        }
        $route = '';
        $messageId = null;
        $permanent = false;
        try {
            if ($channel === 'whatsapp' && $row['template'] === 'nudge') {
                // Made at the last moment, so the 2 hours start when the message goes.
                $coupon = tg_nudge_coupon($pdo, (string) $row['name'], (int) $row['booking_id'], $now);
                if ($coupon === null) {
                    throw new RuntimeException('the personal coupon could not be made');
                }
                $row['coupon_code'] = $coupon['code'];
                $row['coupon_until'] = $coupon['until'];
            }
            if ($channel === 'whatsapp') {
                $sent = tg_wa_send($config, $row['template'], $row['to_addr'], tg_wa_values($config, $row['template'], $row));
                $ok = $sent['ok'];
                $messageId = $sent['id'];
                $route = (string) $sent['error'];
                $permanent = !$ok && !$sent['retry'];
            } elseif ($row['template'] === 'digest') {
                $mail = tg_digest_email($config, tg_digest_data($pdo, $config, $now));
                $ok = rp_mail($config, $row['to_addr'], $mail['subject'], $mail['body'], $route, true);
            } else {
                $mail = tg_reminder_email($config, $row['template'], $row);
                // No mail() backup for buyers: it can claim success while the email is binned.
                $ok = rp_mail($config, $row['to_addr'], $mail['subject'], $mail['body'], $route, false, ['headers' => $mail['headers']]);
            }
        } catch (Throwable $e) {
            $ok = false;
            $route = ($channel === 'whatsapp' ? 'could not build the WhatsApp message: ' : 'could not build the email: ') . $e->getMessage();
        }

        if ($ok) {
            $done->execute([$at, $messageId, $row['id']]);
            $sum['sent']++;
            $streak[$channel] = 0;
        } else {
            $tries = (int) $row['tries'] + 1;
            $final = $permanent || $tries >= TG_MAX_TRIES || $now + TG_RETRY_SECONDS >= tg_unix($row['expires_at']);
            $retry->execute([$final ? 'failed' : 'queued', tg_ist($now + TG_RETRY_SECONDS), mb_substr($route, 0, 255), $row['id']]);
            $sum[$final ? 'failed' : 'retrying']++;
            if (++$streak[$channel] >= 3) {
                $sum[$channel === 'whatsapp' ? 'wa_paused' : 'paused'] = true;
            }
        }
        if ($pauseMs > 0) {
            usleep($pauseMs * 1000);
        }
    }

    // 6. One alert to Deenu about everything that finally failed or was missed.
    $sum['alerted'] = tg_outbox_alert($pdo, $config, $now);
    return $sum;
}

/** Emails Deenu about failed or missed messages he has not been told about. Returns how many. */
function tg_outbox_alert(PDO $pdo, array $config, int $now): int
{
    $rows = $pdo->query("SELECT o.id, o.channel, o.template, o.to_addr, o.last_error, b.session_at, c.name
        FROM outbox o LEFT JOIN bookings b ON b.id = o.booking_id LEFT JOIN contacts c ON c.id = b.contact_id
        WHERE o.status IN ('failed', 'expired') AND o.alerted_at IS NULL ORDER BY o.due_at, o.id LIMIT 200")->fetchAll();
    if (!$rows) {
        return 0;
    }
    $waLabels = array_map(static fn(array $t): string => $t['label'], tg_wa_templates($config));
    $items = array_map(static fn(array $r): array => [
        'channel'       => $r['channel'],
        'label'         => $r['channel'] === 'whatsapp' ? 'WhatsApp ' . lcfirst($waLabels[$r['template']] ?? $r['template'])
            : ($r['template'] === 'digest' ? 'Daily summary' : (TG_REMINDERS[$r['template']]['label'] ?? $r['template'])),
        'name'          => $r['name'] ?? 'Deenu',
        'email'         => $r['channel'] === 'whatsapp' ? tg_wa_pretty($r['to_addr']) : $r['to_addr'],
        'session_label' => $r['session_at'] ? (new DateTimeImmutable($r['session_at']))->format('D j M, g A') : 'today',
        'error'         => $r['last_error'] ?? 'unknown',
    ], $rows);
    $mail = tg_alert_email($config, $items);
    // mail() is allowed as the backup here: when SMTP is down, this is how Deenu hears about it.
    if (!rp_mail($config, $config['mail_notify'], $mail['subject'], $mail['body'])) {
        error_log('outbox: could not send the failure alert');
        return 0;
    }
    $ids = implode(',', array_map('intval', array_column($rows, 'id')));
    $pdo->prepare("UPDATE outbox SET alerted_at = ? WHERE id IN ({$ids})")->execute([tg_ist($now)]);
    return count($rows);
}

/** The numbers for the 9 AM summary: the last 24 hours, the next session and the last one. */
function tg_digest_data(PDO $pdo, array $config, int $now): array
{
    $since = tg_ist($now - 86400);
    $at = tg_ist($now);
    $product = $config['product'];
    $one = static function (string $sql, array $args) use ($pdo): array {
        $q = $pdo->prepare($sql);
        $q->execute($args);
        return $q->fetch() ?: [];
    };

    $sales = $one("SELECT COUNT(*) AS paid, COALESCE(SUM(addon), 0) AS addons, COALESCE(SUM(amount_paise), 0) AS revenue
        FROM bookings WHERE product = ? AND status = 'paid' AND paid_at >= ? AND paid_at <= ?", [$product, $since, $at]);
    $tries = $one("SELECT COALESCE(SUM(status IN ('started', 'order_failed')), 0) AS unpaid, COALESCE(SUM(status = 'failed'), 0) AS failed
        FROM bookings WHERE product = ? AND created_at >= ? AND created_at <= ?", [$product, $since, $at]);

    $next = rp_next_session($now);
    $nextRow = $one("SELECT COUNT(*) AS paid, COALESCE(SUM(addon), 0) AS addons FROM bookings
        WHERE product = ? AND status = 'paid' AND session_at = ?", [$product, $next->format('Y-m-d H:i:s')]);

    $last = $one("SELECT MAX(session_at) AS s FROM bookings WHERE product = ? AND status = 'paid' AND session_at <= ?", [$product, $at]);
    $lastRow = [];
    if (!empty($last['s'])) {
        $lastRow = $one("SELECT COUNT(*) AS paid, COALESCE(SUM(join_clicks > 0), 0) AS clicked FROM bookings
            WHERE product = ? AND status = 'paid' AND session_at = ?", [$product, $last['s']]);
    }

    $msgs = $one("SELECT COALESCE(SUM(status = 'sent' AND sent_at >= ?), 0) AS sent,
            COALESCE(SUM(status = 'queued' AND due_at <= ?), 0) AS queued,
            COALESCE(SUM(status IN ('failed', 'expired') AND due_at >= ?), 0) AS failed
        FROM outbox WHERE template <> 'digest' AND channel = 'email'", [$since, tg_ist($now + 86400), $since]);
    $wa = $one("SELECT COALESCE(SUM(status = 'sent' AND sent_at >= ?), 0) AS sent, COALESCE(SUM(read_at >= ?), 0) AS seen,
            COALESCE(SUM(status IN ('failed', 'expired') AND due_at >= ? AND last_error <> 'WhatsApp was off or paused'), 0) AS failed
        FROM outbox WHERE channel = 'whatsapp'", [$since, $since, $since]);

    $reconcile = $one("SELECT at, detail FROM audit WHERE actor = 'reconcile' ORDER BY id DESC LIMIT 1", []);

    return [
        'paid'               => (int) ($sales['paid'] ?? 0),
        'addons'             => (int) ($sales['addons'] ?? 0),
        'revenue'            => (int) ($sales['revenue'] ?? 0),
        'unpaid'             => (int) ($tries['unpaid'] ?? 0),
        'failed'             => (int) ($tries['failed'] ?? 0),
        'next_label'         => $next->format('l j M, g A'),
        'next_short'         => $next->format('D j M'),
        'next_paid'          => (int) ($nextRow['paid'] ?? 0),
        'next_addons'        => (int) ($nextRow['addons'] ?? 0),
        'zoom_set'           => tg_zoom_url($config) !== null,
        'sent'               => (int) ($msgs['sent'] ?? 0),
        'queued'             => (int) ($msgs['queued'] ?? 0),
        'failed_msgs'        => (int) ($msgs['failed'] ?? 0),
        'wa'                 => tg_wa_enabled($config) ? [
            'paused' => tg_wa_paused($config),
            'sent'   => (int) ($wa['sent'] ?? 0),
            'seen'   => (int) ($wa['seen'] ?? 0),
            'failed' => (int) ($wa['failed'] ?? 0),
        ] : null,
        'last_session_label' => !empty($last['s']) ? (new DateTimeImmutable($last['s']))->format('l j M') : null,
        'last_paid'          => (int) ($lastRow['paid'] ?? 0),
        'last_clicked'       => (int) ($lastRow['clicked'] ?? 0),
        'reconcile'          => $reconcile ? 'last ran ' . (new DateTimeImmutable($reconcile['at']))->format('j M, g:i A') . '. '
            . preg_replace('/\s+/', ' ', (string) $reconcile['detail']) : null,
    ];
}
