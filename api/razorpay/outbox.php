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
 *   5. sends what is due, a limited number per run, for up to 150 seconds,
 *   6. retries failures every 5 minutes until the message's window closes,
 *   7. emails Deenu once about anything that finally failed.
 * Old inbox text is removed before the sends (tg_wa_retention), so a run that stops
 * early never skips it. A pause set in Settings during a run stops its WhatsApp sends.
 *
 * WhatsApp (whatsapp.php) rides along: while it is switched on, each paid buyer who
 * agreed to booking updates gets the confirmation and the same four reminders on
 * WhatsApp too, as rows with channel 'whatsapp'. Email and WhatsApp fail separately,
 * so a WhatsApp problem never holds up an email, and the other way round.
 *
 * Every WhatsApp message that goes (or finally fails) is also stored in the inbox's
 * wa_messages with its text, and each run removes the text of messages older than 30
 * days (tg_wa_retention in wa_store.php).
 *
 * The checkout coupon (template 'nudge') also rides along when switched on: someone who
 * filled the pop-up but hasn't paid 10 minutes after their last try gets one WhatsApp
 * with a personal coupon, made at the moment it is sent. See TG_NUDGE_* in whatsapp.php.
 *
 * Broadcasts (wa_bulk.php) are "bulk rows": source set, no booking. They go after the
 * booking messages in each run, up to outbox.wa_bulk_per_run (200), and are checked again
 * one by one as they go (tg_outbox_bulk): the test list, STOP, consent, the broadcast still
 * on, the template still approved, quiet hours for marketing and the daily limit. A row
 * that has to wait gets next_try_at; one that may not go is cancelled with the reason.
 *
 * Automation rules (wa_rules.php) plan their messages in step 2c as bulk rows too
 * (source 'automation'), so they get the same checks, plus "the rule is still on".
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
    $sum = ['planned' => 0, 'sent' => 0, 'retrying' => 0, 'failed' => 0, 'expired' => 0, 'cancelled' => 0, 'alerted' => 0, 'undelivered' => 0,
        'paused' => false, 'wa_paused' => false, 'wa_paused_now' => false, 'out_of_time' => false];
    $pdo = tg_db($config);
    if ($pdo === null) {
        return $sum + ['error' => 'The contact store is off or unreachable.'];
    }
    $at = tg_ist($now);
    $wa = tg_wa_enabled($config) && !tg_wa_paused($config);
    // Wall-clock time, not the test clock: a run stops sending once this passes, so it
    // never outlives the 5-minute cron. What is left stays queued for the next run.
    $deadline = microtime(true) + tg_outbox_budget($config);

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

    // 2c. Automation rules: what happened since each was switched on becomes bulk rows.
    if ($wa) {
        $sum['planned'] += tg_rules_plan($pdo, $config, $now);
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
    // A broadcast row still waiting (daily limit, quiet hours, a paused template) when its
    // 48 hours end is not sent. It shows on the broadcast's page and in the 9 AM summary,
    // not in an alert email.
    $bulkExpire = $pdo->prepare("UPDATE outbox SET status = 'expired', alerted_at = COALESCE(alerted_at, ?),
            last_error = CASE WHEN last_error LIKE 'Waiting: %' THEN CONCAT('Not sent: ', SUBSTRING(last_error, 10))
                ELSE COALESCE(last_error, 'Not sent: its time passed') END
        WHERE status = 'queued' AND source IS NOT NULL AND expires_at <= ?");
    $bulkExpire->execute([$at, $at]);
    $expire = $pdo->prepare("UPDATE outbox SET status = 'expired',
            last_error = COALESCE(last_error, 'Its time passed before it could be sent')
        WHERE status = 'queued' AND expires_at <= ?");
    $expire->execute([$at]);
    $sum['expired'] = $expire->rowCount() + $bulkExpire->rowCount();

    // The inbox keeps message text for 30 days only. Done before the sends, so a run
    // that runs out of time (or is stopped by the host) never skips it.
    tg_wa_retention($pdo, $now);

    // 5. Send what is due: booking messages first. A limited number per run keeps the
    // mailbox under its sending limits. Broadcast rows (source set) go after, in 5b.
    $max = max(1, (int) ($config['outbox']['max_per_run'] ?? 60));
    $pauseMs = max(0, (int) ($config['outbox']['pause_ms'] ?? 200));
    $channels = $wa ? "'email', 'whatsapp'" : "'email'";
    $due = $pdo->prepare("SELECT o.*, b.session_at, b.join_token, c.name
        FROM outbox o LEFT JOIN bookings b ON b.id = o.booking_id LEFT JOIN contacts c ON c.id = b.contact_id
        WHERE o.status = 'queued' AND o.channel IN ({$channels}) AND o.source IS NULL AND o.due_at <= ? AND (o.next_try_at IS NULL OR o.next_try_at <= ?)
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
        if (microtime(true) >= $deadline) {
            $sum['out_of_time'] = true;
            break; // out of time: the rest wait for the next run
        }
        $channel = $row['channel'] === 'whatsapp' ? 'whatsapp' : 'email';
        if ($streak[$channel] >= 3) {
            continue;
        }
        // Paused in Settings while this run was going: no more WhatsApp from it.
        if ($channel === 'whatsapp' && $wa && tg_wa_paused_now($config)) {
            $wa = false;
            $sum['wa_paused_now'] = true;
        }
        if ($channel === 'whatsapp' && !$wa) {
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
        $final = false;
        $values = null;
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
                $values = tg_wa_values($config, $row['template'], $row);
                $sent = tg_wa_send($config, $row['template'], $row['to_addr'], $values);
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
        // The inbox shows each WhatsApp message as the buyer got it (or why it failed).
        if ($channel === 'whatsapp' && ($ok || $final)) {
            tg_outbox_log_wa($pdo, $config, $row, $values, $ok, $messageId, $route, $now);
        }
        if ($pauseMs > 0) {
            usleep($pauseMs * 1000);
        }
    }

    // 5b. Broadcast rows, after the booking messages, while WhatsApp is not failing.
    if ($wa && $streak['whatsapp'] < 3) {
        tg_outbox_bulk($pdo, $config, $now, $sum, $streak, $deadline);
    }
    tg_bc_finish($pdo, $now);

    // 6. One alert to Deenu about everything that finally failed or was missed.
    $sum['alerted'] = tg_outbox_alert($pdo, $config, $now);
    return $sum;
}

/**
 * Seconds a run may spend before it stops sending (outbox.run_seconds, default 150).
 * Cron starts a new run every 5 minutes and shared hosting may stop a long one, so a
 * slow Meta never leaves a row stuck half-sent. At least 1.
 */
function tg_outbox_budget(array $config): int
{
    return max(1, (int) ($config['outbox']['run_seconds'] ?? 150));
}

/**
 * Is WhatsApp paused right now? Reads the Settings file again (the run's $config was
 * loaded when it started), so a pause Deenu sets mid-run stops the rest of the batch.
 */
function tg_wa_paused_now(array $config): bool
{
    return tg_wa_paused(rp_apply_settings($config));
}

/** Stores one booking WhatsApp message in the inbox, with the words the buyer saw. */
function tg_outbox_log_wa(PDO $pdo, array $config, array $row, ?array $values, bool $ok, ?string $messageId, string $error, int $now): void
{
    $t = tg_wa_templates($config)[$row['template']] ?? null;
    $text = null;
    if ($t !== null && $values !== null) {
        try {
            $text = tg_wa_render($config, $row['template'], $values)['body'];
        } catch (Throwable $e) {
            $text = null;
        }
    }
    tg_wa_log_out($pdo, (string) $row['to_addr'], [
        'kind'      => 'template',
        'body'      => $text,
        'template'  => $t['name'] ?? (string) $row['template'],
        'category'  => $t['category'] ?? 'UTILITY',
        'sent_by'   => 'system',
        'wa_id'     => $messageId,
        'outbox_id' => (int) $row['id'],
        'status'    => $ok ? 'sent' : 'failed',
        'error'     => $ok ? null : $error,
    ], $now);
}

/**
 * Step 5b: broadcast (and rule) rows, after the booking messages, up to
 * outbox.wa_bulk_per_run a run. Each row is checked again as it goes, in this order:
 * the test list, STOP, consent (or messaged us) and marketing-off, the broadcast still
 * on, a paid-since check for rows that ask for one, the template still approved (from
 * wa_templates, no call to Meta), quiet hours for marketing, then the daily limit. A row
 * that fails a check is cancelled with the reason, or waits (next_try_at) until it may go.
 * A Meta error about one person (TG_WA_PERSON_ERRORS) fails just that row, with no alert
 * and without counting toward "WhatsApp is down"; anything else counts, as for bookings.
 * Before each row it stops if the run is out of time, or if WhatsApp was paused in
 * Settings since the run started; the rest stay queued.
 */
function tg_outbox_bulk(PDO $pdo, array $config, int $now, array &$sum, array &$streak, ?float $deadline = null): void
{
    $at = tg_ist($now);
    $max = max(1, (int) ($config['outbox']['wa_bulk_per_run'] ?? 200));
    $pauseMs = max(0, (int) ($config['outbox']['pause_ms'] ?? 200));
    $q = $pdo->prepare("SELECT * FROM outbox WHERE status = 'queued' AND channel = 'whatsapp' AND source IN ('broadcast', 'automation', 'inbox')
        AND due_at <= ? AND (next_try_at IS NULL OR next_try_at <= ?) ORDER BY due_at, id LIMIT {$max}");
    $q->execute([$at, $at]);
    $rows = $q->fetchAll();
    if (!$rows) {
        return;
    }
    $cap = tg_wa_cap_state($pdo, $config, $now);
    $room = $cap['room'];
    $used = $cap['used'];
    $templates = [];
    $sources = [];
    $drop = $pdo->prepare("UPDATE outbox SET status = 'cancelled', last_error = ? WHERE id = ? AND status = 'queued'");
    $hold = $pdo->prepare("UPDATE outbox SET next_try_at = ?, last_error = ? WHERE id = ? AND status = 'queued'");
    $claim = $pdo->prepare("UPDATE outbox SET status = 'sending', next_try_at = ? WHERE id = ? AND status = 'queued'");
    $done = $pdo->prepare("UPDATE outbox SET status = 'sent', sent_at = ?, tries = tries + 1, last_error = NULL, provider_id = ? WHERE id = ?");
    $personFail = $pdo->prepare("UPDATE outbox SET status = 'failed', tries = tries + 1, last_error = ?, alerted_at = ? WHERE id = ?");
    $retry = $pdo->prepare('UPDATE outbox SET status = ?, tries = tries + 1, next_try_at = ?, last_error = ? WHERE id = ?');
    $unclaim = $pdo->prepare("UPDATE outbox SET status = 'queued', next_try_at = ?, last_error = ? WHERE id = ? AND status = 'sending'");
    $starting = $pdo->prepare("UPDATE wa_broadcasts SET status = 'sending', updated_at = ? WHERE id = ? AND status = 'scheduled'");

    foreach ($rows as $row) {
        if ($streak['whatsapp'] >= 3) {
            break; // WhatsApp is failing: the rest wait for the next run
        }
        if ($deadline !== null && microtime(true) >= $deadline) {
            $sum['out_of_time'] = true;
            break; // out of time: the rest wait for the next run
        }
        if (tg_wa_paused_now($config)) {
            $sum['wa_paused_now'] = true;
            break; // paused in Settings while this run was going: the rest wait
        }
        $id = (int) $row['id'];
        $to = (string) $row['to_addr'];
        $cancel = static function (string $why) use ($drop, $id, &$sum): void {
            $drop->execute([mb_substr($why, 0, 255), $id]);
            $sum['cancelled'] += $drop->rowCount();
        };

        // 1-2. The test list and STOP. STOP keeps its own wording, so START never revives it.
        if (!tg_wa_allowed($config, $to)) {
            $cancel('Not on the WhatsApp test list');
            continue;
        }
        if (tg_wa_stop_at($pdo, $to) !== null) {
            $cancel('Said STOP before it was sent');
            continue;
        }
        $tid = str_starts_with((string) $row['template'], 'c:') ? (int) substr((string) $row['template'], 2) : 0;
        if (!array_key_exists($tid, $templates)) {
            $templates[$tid] = $tid > 0 ? tg_wat_get($pdo, $tid) : null;
        }
        $t = $templates[$tid];
        $category = $t !== null ? tg_wat_category($t) : 'MARKETING';

        // 3. Consent or messaged us, and for marketing, not turned off in WhatsApp.
        $why = tg_wa_can_message($pdo, $config, $to, 'template', $now);
        if ($why === null && $category === 'MARKETING' && tg_wa_marketing_off($pdo, $to)) {
            $why = 'They turned off marketing from you in WhatsApp';
        }
        if ($why !== null) {
            $cancel($why);
            continue;
        }

        // 4. Its broadcast is still on.
        $source = (string) $row['source'];
        $sourceId = (int) $row['source_id'];
        if ($source === 'broadcast') {
            $key = 'b' . $sourceId;
            if (!array_key_exists($key, $sources)) {
                $sources[$key] = tg_wa_value($pdo, 'SELECT status FROM wa_broadcasts WHERE id = ?', [$sourceId]);
            }
            if (!in_array($sources[$key], ['scheduled', 'sending'], true)) {
                $cancel('Broadcast cancelled');
                continue;
            }
        } elseif ($source === 'automation') {
            // Its rule is still on (switching it off cancels its rows; this catches the rest).
            $key = 'r' . $sourceId;
            if (!array_key_exists($key, $sources)) {
                try {
                    $sources[$key] = tg_wa_value($pdo, 'SELECT enabled FROM wa_rules WHERE id = ?', [$sourceId]);
                } catch (Throwable $e) {
                    $sources[$key] = '1'; // before version 10: nothing to check
                }
            }
            if ((int) $sources[$key] !== 1) {
                $cancel($sources[$key] === null ? 'Rule deleted' : 'Rule switched off');
                continue;
            }
        }

        // 5. A row that is only for people who have not paid (a rule's), checked again now.
        $params = json_decode((string) ($row['params'] ?? ''), true);
        $params = is_array($params) ? $params : null;
        if (!empty($params['check_paid']) && !empty($params['booking']) && tg_nudge_paid_since($pdo, (int) $params['booking'], $now)) {
            $cancel('Paid in the meantime');
            continue;
        }
        // A rule row about a paid seat: the seat must still be paid (not refunded or moved).
        if (!empty($params['need_paid']) && !empty($params['booking'])
            && tg_wa_value($pdo, 'SELECT status FROM bookings WHERE id = ?', [(int) $params['booking']]) !== 'paid') {
            $cancel('The booking is no longer paid');
            continue;
        }

        // 6. The template, as the webhook and the sync last saw it.
        if ($t === null || $t['deleted_at'] !== null || !in_array($t['status'], ['APPROVED', 'PAUSED'], true)) {
            $sum['failed'] += tg_bulk_template_dead($pdo, (string) $row['template'], $now);
            continue;
        }
        if ($t['status'] === 'PAUSED') {
            $hold->execute([tg_ist($now + 3600), tg_bulk_hold_reason('the template is paused at Meta'), $id]);
            continue;
        }

        // 7. Quiet hours: no marketing between 9 PM and 9 AM.
        if ($category === 'MARKETING' && tg_wa_quiet_hours($now)) {
            $hold->execute([tg_ist(tg_wa_quiet_end($now)), tg_bulk_hold_reason('quiet hours (9 PM to 9 AM)'), $id]);
            continue;
        }

        // 8. The daily limit. Someone already sent a template in the last 24 hours costs nothing more.
        if (!isset($used[$to]) && $room <= 0) {
            $hold->execute([tg_ist(max($now + 300, (int) ($cap['free_at'] ?? $now + 3600))), tg_bulk_hold_reason('daily limit reached'), $id]);
            continue;
        }

        if ($params === null || !is_array($params['body'] ?? null)) {
            $personFail->execute(['Its values were removed before it could be sent', $at, $id]);
            $sum['failed']++;
            continue;
        }
        $values = [
            'body'    => array_values(array_map('strval', $params['body'])),
            'buttons' => array_map('strval', is_array($params['buttons'] ?? null) ? $params['buttons'] : []),
        ];
        $claim->execute([$at, $id]);
        if ($claim->rowCount() !== 1) {
            continue;
        }
        if ($source === 'broadcast') {
            $starting->execute([$at, $sourceId]);
        }
        $sent = tg_wat_send($config, $t, $to, $values);
        $final = false;
        if ($sent['ok']) {
            $done->execute([$at, $sent['id'], $id]);
            $sum['sent']++;
            $streak['whatsapp'] = 0;
            if (!isset($used[$to])) {
                $room--;
                $used[$to] = $now + 86400;
            }
            if ($source === 'automation') {
                $pdo->prepare('UPDATE wa_rules SET sent = sent + 1 WHERE id = ?')->execute([$sourceId]);
            }
        } else {
            $code = (int) ($sent['code'] ?? 0);
            if (isset(TG_WA_PERSON_ERRORS[$code]) || ($code === 100 && preg_match('/phone|recipient/i', (string) $sent['error']))) {
                // About this one person: fail it quietly; it shows on the broadcast's page.
                $personFail->execute([TG_WA_PERSON_ERRORS[$code] ?? 'Not a valid WhatsApp number', $at, $id]);
                $sum['undelivered']++; // normal in a broadcast: not a failed run
                $final = true;
                if ($code === 131050) {
                    tg_wa_set_marketing_off($pdo, $to, $now);
                }
                $sent['error'] = TG_WA_PERSON_ERRORS[$code] ?? 'Not a valid WhatsApp number';
            } elseif ($code === 132015) {
                // Meta paused the template: every row waits, this one included.
                $pdo->prepare("UPDATE wa_templates SET status = 'PAUSED', status_at = ?, updated_at = ? WHERE id = ?")->execute([$at, $at, $tid]);
                $templates[$tid]['status'] = 'PAUSED';
                $unclaim->execute([tg_ist($now + 3600), tg_bulk_hold_reason('the template is paused at Meta'), $id]);
                $streak['whatsapp']++;
            } elseif ($code === 132016 || $code === 132001) {
                // Disabled, or Meta no longer has it: the rest of its rows fail, with one alert.
                $pdo->prepare("UPDATE wa_templates SET status = 'DISABLED', reason = ?, status_at = ?, updated_at = ? WHERE id = ?")
                    ->execute([mb_substr((string) $sent['error'], 0, 255), $at, $at, $tid]);
                $templates[$tid]['status'] = 'DISABLED';
                $sum['failed'] += tg_bulk_template_dead($pdo, (string) $row['template'], $now);
                $streak['whatsapp']++;
                $final = true;
                $sent['error'] = 'That template is no longer approved';
            } else {
                $tries = (int) $row['tries'] + 1;
                $final = !$sent['retry'] || $tries >= TG_MAX_TRIES || $now + TG_RETRY_SECONDS >= tg_unix($row['expires_at']);
                $retry->execute([$final ? 'failed' : 'queued', tg_ist($now + TG_RETRY_SECONDS), mb_substr((string) $sent['error'], 0, 255), $id]);
                $sum[$final ? 'failed' : 'retrying']++;
                if (++$streak['whatsapp'] >= 3) {
                    $sum['wa_paused'] = true;
                }
            }
        }
        if ($sent['ok'] || $final) {
            tg_wa_log_out($pdo, $to, [
                'kind'      => 'template',
                'body'      => tg_wat_text(tg_wat_render($t, $values)),
                'template'  => $t['name'],
                'category'  => $category,
                'sent_by'   => $source === 'automation' ? 'auto' : 'broadcast',
                'wa_id'     => $sent['id'],
                'outbox_id' => $id,
                'status'    => $sent['ok'] ? 'sent' : 'failed',
                'error'     => $sent['ok'] ? null : $sent['error'],
            ], $now);
        }
        if ($pauseMs > 0) {
            usleep($pauseMs * 1000);
        }
    }
}

/**
 * A template that is no longer approved (disabled, rejected, deleted): every bulk row
 * still waiting to use it fails at once with one reason, and Deenu gets one alert for
 * the lot (the first row is left for the alert email, the rest are marked told).
 * Returns how many rows failed.
 */
function tg_bulk_template_dead(PDO $pdo, string $template, int $now): int
{
    $q = $pdo->prepare("SELECT id FROM outbox WHERE template = ? AND source IS NOT NULL AND status IN ('queued', 'sending') ORDER BY id");
    $q->execute([$template]);
    $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) {
        return 0;
    }
    $alerted = (int) tg_wa_value($pdo, "SELECT COUNT(*) FROM outbox WHERE template = ? AND source IS NOT NULL AND status = 'failed'
        AND last_error = 'That template is no longer approved' AND alerted_at IS NULL", [$template]) > 0;
    $first = $alerted ? 0 : $ids[0];
    $pdo->prepare("UPDATE outbox SET status = 'failed', last_error = 'That template is no longer approved',
            alerted_at = IF(id = ?, NULL, ?) WHERE id IN (" . implode(',', $ids) . ')')
        ->execute([$first, tg_ist($now)]);
    return count($ids);
}

/** Marks broadcasts done once none of their rows are waiting. */
function tg_bc_finish(PDO $pdo, int $now): void
{
    try {
        $pdo->prepare("UPDATE wa_broadcasts b SET b.status = 'done', b.finished_at = ?, b.updated_at = ?
            WHERE b.status IN ('scheduled', 'sending') AND b.send_at <= ?
              AND NOT EXISTS (SELECT 1 FROM outbox o WHERE o.source = 'broadcast' AND o.source_id = b.id AND o.status IN ('queued', 'sending'))")
            ->execute([tg_ist($now), tg_ist($now), tg_ist($now)]);
    } catch (Throwable $e) {
        error_log('broadcasts: status not updated: ' . $e->getMessage());
    }
}

/** A bulk row in words, for the alert email: WhatsApp broadcast "Nov offer", never c:12. */
function tg_bulk_label(PDO $pdo, array $r): string
{
    $tid = str_starts_with((string) $r['template'], 'c:') ? (int) substr((string) $r['template'], 2) : 0;
    $tpl = $tid > 0 ? (string) (tg_wa_value($pdo, 'SELECT name FROM wa_templates WHERE id = ?', [$tid]) ?? 'your template') : (string) $r['template'];
    switch ((string) $r['source']) {
        case 'broadcast':
            $name = tg_wa_value($pdo, 'SELECT name FROM wa_broadcasts WHERE id = ?', [(int) $r['source_id']]);
            return 'WhatsApp broadcast "' . ($name ?? 'deleted') . '"';
        case 'automation':
            try {
                $name = tg_wa_value($pdo, 'SELECT name FROM wa_rules WHERE id = ?', [(int) $r['source_id']]);
            } catch (Throwable $e) {
                $name = null;
            }
            return 'WhatsApp rule "' . ($name ?? $tpl) . '"';
        case 'test':
            return 'WhatsApp test of ' . $tpl;
        default:
            return 'WhatsApp inbox template ' . $tpl;
    }
}

/** Emails Deenu about failed or missed messages he has not been told about. Returns how many. */
function tg_outbox_alert(PDO $pdo, array $config, int $now): int
{
    $rows = $pdo->query("SELECT o.id, o.channel, o.template, o.to_addr, o.last_error, o.source, o.source_id, o.due_at, b.session_at, c.name
        FROM outbox o LEFT JOIN bookings b ON b.id = o.booking_id LEFT JOIN contacts c ON c.id = b.contact_id
        WHERE o.status IN ('failed', 'expired') AND o.alerted_at IS NULL ORDER BY o.due_at, o.id LIMIT 200")->fetchAll();
    if (!$rows) {
        return 0;
    }
    $waLabels = array_map(static fn(array $t): string => $t['label'], tg_wa_templates($config));
    $items = array_map(static function (array $r) use ($pdo, $config, $waLabels): array {
        if ($r['source'] !== null) {
            // A broadcast, rule or inbox send: named by what it was, never "c:12".
            return [
                'channel'       => 'whatsapp',
                'label'         => tg_bulk_label($pdo, $r),
                'name'          => ($n = tg_wat_person($pdo, $config, (string) $r['to_addr'])['name']) !== '' ? $n : 'Someone',
                'email'         => tg_wa_pretty((string) $r['to_addr']),
                'session_label' => (new DateTimeImmutable((string) $r['due_at']))->format('D j M'),
                'error'         => $r['last_error'] ?? 'unknown',
                'bulk'          => true,
            ];
        }
        return [
            'channel'       => $r['channel'],
            'label'         => $r['channel'] === 'whatsapp' ? 'WhatsApp ' . lcfirst($waLabels[$r['template']] ?? $r['template'])
                : ($r['template'] === 'digest' ? 'Daily summary' : (TG_REMINDERS[$r['template']]['label'] ?? $r['template'])),
            'name'          => $r['name'] ?? 'Deenu',
            'email'         => $r['channel'] === 'whatsapp' ? tg_wa_pretty($r['to_addr']) : $r['to_addr'],
            'session_label' => $r['session_at'] ? (new DateTimeImmutable($r['session_at']))->format('D j M, g A') : 'today',
            'error'         => $r['last_error'] ?? 'unknown',
        ];
    }, $rows);
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
    // Booking messages only; broadcasts and rules get their own line.
    $wa = $one("SELECT COALESCE(SUM(status = 'sent' AND sent_at >= ?), 0) AS sent, COALESCE(SUM(read_at >= ?), 0) AS seen,
            COALESCE(SUM(status IN ('failed', 'expired') AND due_at >= ? AND last_error <> 'WhatsApp was off or paused'), 0) AS failed
        FROM outbox WHERE channel = 'whatsapp' AND source IS NULL", [$since, $since, $since]);
    $bulk = $one("SELECT COALESCE(SUM(status = 'sent' AND sent_at >= ?), 0) AS sent,
            COALESCE(SUM(status = 'failed' AND COALESCE(sent_at, due_at) >= ?), 0) AS failed,
            COALESCE(SUM(status = 'expired' AND expires_at >= ?), 0) AS held
        FROM outbox WHERE channel = 'whatsapp' AND source IN ('broadcast', 'automation')", [$since, $since, $since]);

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
            'bulk'   => ['sent' => (int) ($bulk['sent'] ?? 0), 'failed' => (int) ($bulk['failed'] ?? 0), 'held' => (int) ($bulk['held'] ?? 0)],
        ] : null,
        'last_session_label' => !empty($last['s']) ? (new DateTimeImmutable($last['s']))->format('l j M') : null,
        'last_paid'          => (int) ($lastRow['paid'] ?? 0),
        'last_clicked'       => (int) ($lastRow['clicked'] ?? 0),
        'reconcile'          => $reconcile ? 'last ran ' . (new DateTimeImmutable($reconcile['at']))->format('j M, g:i A') . '. '
            . preg_replace('/\s+/', ' ', (string) $reconcile['detail']) : null,
    ];
}
