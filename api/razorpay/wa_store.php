<?php
/**
 * The WhatsApp conversations: who we talk to (wa_contacts), every message in and out
 * (wa_messages), and the rules for who may get what. Required by whatsapp.php, so the
 * webhook, the worker and the dashboard all share it.
 *
 * Message text is kept for 30 days so Deenu can read and answer in the dashboard's
 * inbox, then removed by tg_wa_retention (the worker runs it every 5 minutes). The rows
 * stay, so counts, ticks and spend estimates never change for old periods. wa_inbound
 * (one row per message someone sent us, no text) is never changed by any job: it is the
 * record of STOP, START and "they messaged us first".
 *
 * Nothing here may block a sale or a webhook: the logging functions catch their own errors.
 * Times come from PHP (rp_now, or Meta's own timestamp), never SQL's NOW().
 */

/** Meta's price per delivered template, in paise, tax included (Deenu's figures, Nov 2026). An estimate only. */
const TG_WA_RATE_PAISE = ['MARKETING' => 86, 'UTILITY' => 15];
/** Days the words of a message are kept. */
const TG_WA_KEEP_TEXT_DAYS = 30;
/** Longest text Meta takes in one message. */
const TG_WA_TEXT_MAX = 4096;
/** A WhatsApp-only conversation's profile name is cleared after this many quiet days. */
const TG_WA_FORGET_NAME_DAYS = 90;
/** Meta's customer service window: free replies for 24 hours after their last message. */
const TG_WA_WINDOW = 86400;
/** No marketing template leaves between these hours, IST. */
const TG_WA_QUIET_FROM = 21;
const TG_WA_QUIET_TO = 9;

/** Unix time of a stored IST DATETIME. */
function tg_wa_ts(string $ist): int
{
    return (new DateTimeImmutable($ist, new DateTimeZone('Asia/Kolkata')))->getTimestamp();
}

/** Text as it may be stored and sent: no control characters except new lines and tabs. */
function tg_wa_clean_text(string $text, int $max = TG_WA_TEXT_MAX): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    return mb_substr($text, 0, $max);
}

/** The first value of a query, or null. */
function tg_wa_value(PDO $pdo, string $sql, array $args)
{
    $q = $pdo->prepare($sql);
    $q->execute($args);
    $v = $q->fetchColumn();
    return $v === false ? null : $v;
}

/**
 * The conversation for a number: made if new, its profile name refreshed when Meta sent
 * one, and linked to the newest contact with that number. Returns its id (0 on error).
 */
function tg_wa_contact_id(PDO $pdo, string $phone, int $now, string $profile = ''): int
{
    try {
        $pdo->prepare("INSERT INTO wa_contacts (phone, profile_name, created_at) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE profile_name = IF(VALUES(profile_name) <> '', VALUES(profile_name), profile_name), id = LAST_INSERT_ID(id)")
            ->execute([$phone, mb_substr($profile, 0, 80), tg_ist($now)]);
        $id = (int) $pdo->lastInsertId();
        $contacts = tg_wa_contacts($pdo, $phone);
        $pdo->prepare('UPDATE wa_contacts SET contact_id = ? WHERE id = ?')->execute([$contacts ? (int) $contacts[0]['id'] : null, $id]);
        return $id;
    } catch (Throwable $e) {
        error_log('whatsapp inbox: conversation not saved: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Stores one message someone sent us. $m is Meta's message (id, timestamp); $kind is
 * text, image, stop and so on; $text the words or a label such as "[a photo]".
 * Refreshes the 24-hour window and reopens a conversation marked done. It counts as
 * unread unless it is a STOP or START, which the webhook has already handled.
 */
function tg_wa_log_in(PDO $pdo, int $waContactId, array $m, string $kind, string $text): int
{
    if ($waContactId <= 0) {
        return 0;
    }
    try {
        $at = tg_ist((int) ($m['timestamp'] ?? time()));
        $add = $pdo->prepare("INSERT IGNORE INTO wa_messages (wa_contact_id, direction, kind, body, sent_by, wa_id, status, created_at)
            VALUES (?, 'in', ?, ?, 'contact', ?, 'received', ?)");
        $add->execute([$waContactId, $kind, tg_wa_clean_text($text), ((string) ($m['id'] ?? '')) ?: null, $at]);
        if ($add->rowCount() !== 1) {
            return 0;
        }
        $id = (int) $pdo->lastInsertId();
        $bump = $kind === 'stop' || $kind === 'start' ? 0 : 1;
        $pdo->prepare("UPDATE wa_contacts SET last_in_at = GREATEST(COALESCE(last_in_at, ?), ?), last_msg_at = GREATEST(COALESCE(last_msg_at, ?), ?),
                unread = LEAST(unread + ?, 9999), closed_at = NULL WHERE id = ?")
            ->execute([$at, $at, $at, $at, $bump, $waContactId]);
        return $id;
    } catch (Throwable $e) {
        error_log('whatsapp inbox: incoming message not saved: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Stores one message we sent (or tried to). $msg keys: kind (text or template), body,
 * template, category, billable (null works it out), sent_by, wa_id, outbox_id, status,
 * error. Returns its id (0 on error, or when this Meta id is already stored).
 */
function tg_wa_log_out(PDO $pdo, string $phone, array $msg, int $now): int
{
    try {
        $waContactId = tg_wa_contact_id($pdo, $phone, $now);
        if ($waContactId <= 0) {
            return 0;
        }
        $kind = (string) ($msg['kind'] ?? 'text');
        $category = isset($msg['category']) ? strtoupper((string) $msg['category']) : null;
        $billable = $msg['billable'] ?? null;
        if ($billable === null) {
            // Meta charges for templates; a utility template inside an open window is free.
            $lastIn = tg_wa_value($pdo, 'SELECT last_in_at FROM wa_contacts WHERE id = ?', [$waContactId]);
            $billable = $kind === 'template' && !($category === 'UTILITY' && tg_wa_window_open($lastIn, $now));
        }
        $status = (string) ($msg['status'] ?? 'sent');
        $at = tg_ist($now);
        $add = $pdo->prepare("INSERT IGNORE INTO wa_messages (wa_contact_id, direction, kind, body, template, category, billable, sent_by, wa_id, outbox_id, status, error, created_at)
            VALUES (?, 'out', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $add->execute([$waContactId, $kind, isset($msg['body']) ? tg_wa_clean_text((string) $msg['body']) : null,
                $msg['template'] ?? null, $category, $billable && $status !== 'failed' ? 1 : 0, (string) ($msg['sent_by'] ?? 'system'),
                ((string) ($msg['wa_id'] ?? '')) ?: null, $msg['outbox_id'] ?? null, $status,
                isset($msg['error']) ? mb_substr((string) $msg['error'], 0, 255) : null, $at]);
        if ($add->rowCount() !== 1) {
            return 0; // already stored under this Meta message id
        }
        $id = (int) $pdo->lastInsertId();
        if ($status !== 'failed') {
            $pdo->prepare('UPDATE wa_contacts SET last_out_at = GREATEST(COALESCE(last_out_at, ?), ?), last_msg_at = GREATEST(COALESCE(last_msg_at, ?), ?) WHERE id = ?')
                ->execute([$at, $at, $at, $at, $waContactId]);
        }
        return $id;
    } catch (Throwable $e) {
        error_log('whatsapp inbox: sent message not saved: ' . $e->getMessage());
        return 0;
    }
}

/** True while the 24-hour window from their last message is open. */
function tg_wa_window_open(?string $lastIn, int $now): bool
{
    return tg_wa_window_left($lastIn, $now) > 0;
}

/** Seconds left in the 24-hour window, 0 when closed or never opened. */
function tg_wa_window_left(?string $lastIn, int $now): int
{
    if ($lastIn === null || $lastIn === '') {
        return 0;
    }
    return max(0, tg_wa_ts($lastIn) + TG_WA_WINDOW - $now);
}

/** True when $now is inside the marketing quiet hours (9 PM to 9 AM IST). */
function tg_wa_quiet_hours(int $now): bool
{
    $hour = (int) (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('G');
    return $hour >= TG_WA_QUIET_FROM || $hour < TG_WA_QUIET_TO;
}

/** When this number last said STOP and has not said START since, as a stored time, or null. */
function tg_wa_stop_at(PDO $pdo, string $phone): ?string
{
    $q = $pdo->prepare("SELECT kind, received_at FROM wa_inbound WHERE phone = ? AND kind IN ('stop', 'start') ORDER BY id DESC LIMIT 1");
    $q->execute([$phone]);
    $last = $q->fetch();
    if ($last) {
        return $last['kind'] === 'stop' ? (string) $last['received_at'] : null;
    }
    // A contact marked opted out with no STOP on record here (older data).
    $c = $pdo->prepare('SELECT MAX(wa_optout_at) FROM contacts WHERE (phone = ? OR phone = ?) AND wa_optout_at IS NOT NULL');
    $c->execute([$phone, substr($phone, -10)]);
    $at = $c->fetchColumn();
    return is_string($at) && $at !== '' ? $at : null;
}

/**
 * True if this number ever wrote to us first: a message or a START (wa_inbound, which is
 * never deleted). A START recorded from the dashboard (wa_id "admin:...") does not count:
 * that was Deenu, not them.
 */
function tg_wa_messaged_us(PDO $pdo, string $phone): bool
{
    $q = $pdo->prepare("SELECT 1 FROM wa_inbound WHERE phone = ? AND kind IN ('reply', 'start') AND wa_id NOT LIKE 'admin:%' LIMIT 1");
    $q->execute([$phone]);
    return (bool) $q->fetchColumn();
}

/** True if a contact with this number agreed to booking updates in the pop-up. */
function tg_wa_has_consent(PDO $pdo, string $phone): bool
{
    $q = $pdo->prepare('SELECT 1 FROM contacts WHERE (phone = ? OR phone = ?) AND consent_version IS NOT NULL LIMIT 1');
    $q->execute([$phone, substr($phone, -10)]);
    return (bool) $q->fetchColumn();
}

/**
 * May we message this number for this purpose now? Null when yes, or the reason not,
 * in plain English. Checked when a message is planned and again when it is sent.
 *
 * reply:     free text. Their last message is under 24 hours old, and if they said STOP,
 *            they wrote again after it.
 * template:  one approved template. They agreed to booking updates, or messaged us first.
 *            Never after STOP.
 * marketing: as template, and not in the quiet hours (9 PM to 9 AM IST).
 * The switches come first, in this order: set up and enabled, not paused, the test list.
 */
function tg_wa_can_message(PDO $pdo, array $config, string $phone, string $purpose, int $now): ?string
{
    if (!tg_wa_ready($config)) {
        return 'WhatsApp is not set up yet.';
    }
    if (!tg_wa_enabled($config)) {
        return 'WhatsApp sending is switched off in the config.';
    }
    if (tg_wa_paused($config)) {
        return 'WhatsApp messages are paused in Settings.';
    }
    if (!tg_wa_allowed($config, $phone)) {
        return 'This number is not on the WhatsApp test list, so nothing can be sent to it.';
    }
    $stopAt = tg_wa_stop_at($pdo, $phone);
    if ($purpose === 'reply') {
        $lastIn = tg_wa_value($pdo, 'SELECT last_in_at FROM wa_contacts WHERE phone = ?', [$phone]);
        if (!tg_wa_window_open($lastIn, $now)) {
            return $lastIn === null ? 'They have not written to you, so only an approved template can start a conversation.'
                : 'The 24-hour window has closed. Only an approved template can restart it.';
        }
        if ($stopAt !== null) {
            $q = $pdo->prepare("SELECT 1 FROM wa_inbound WHERE phone = ? AND kind = 'reply' AND received_at > ? LIMIT 1");
            $q->execute([$phone, $stopAt]);
            if (!$q->fetchColumn()) {
                return 'They said STOP. You can answer only if they write to you again.';
            }
        }
        return null;
    }
    if ($stopAt !== null) {
        return 'They said STOP, so no templates go to them. Only a reply after they write again.';
    }
    if (!tg_wa_has_consent($pdo, $phone) && !tg_wa_messaged_us($pdo, $phone)) {
        return 'They have not agreed to booking updates and have not messaged you, so no template may go to them.';
    }
    if ($purpose === 'marketing' && tg_wa_quiet_hours($now)) {
        return 'Marketing messages do not go out between 9 PM and 9 AM.';
    }
    return null;
}

/**
 * Sends a plain text message, possible only inside the 24-hour window.
 * Returns ok, id (Meta's message id), error, retry and code (Meta's error code, 0 if none).
 */
function tg_wa_send_text_result(array $config, string $to, string $text): array
{
    [$status, $body] = tg_wa_graph($config, 'POST', rawurlencode((string) $config['whatsapp']['phone_number_id']) . '/messages', [
        'messaging_product' => 'whatsapp',
        'recipient_type'    => 'individual',
        'to'                => $to,
        'type'              => 'text',
        'text'              => ['body' => $text, 'preview_url' => false],
    ]);
    $id = (string) ($body['messages'][0]['id'] ?? '');
    if ($status >= 200 && $status < 300 && $id !== '') {
        return ['ok' => true, 'id' => $id, 'error' => null, 'retry' => false, 'code' => 0];
    }
    $error = tg_wa_error_text($config, $status, $body);
    // The number and the length only, never the words.
    error_log('whatsapp text to ' . tg_wa_pretty($to) . ' (' . mb_strlen($text) . ' chars) not sent: ' . $error);
    return ['ok' => false, 'id' => null, 'error' => $error, 'retry' => tg_wa_retryable($status, $body), 'code' => (int) ($body['error']['code'] ?? 0)];
}

/** Tells Meta we read their message (blue ticks on their phone). Best effort; never counted as a send. */
function tg_wa_mark_read(array $config, string $waId): void
{
    if ($waId === '' || str_starts_with($waId, 'admin:')) {
        return;
    }
    tg_wa_graph($config, 'POST', rawurlencode((string) $config['whatsapp']['phone_number_id']) . '/messages', [
        'messaging_product' => 'whatsapp',
        'status'            => 'read',
        'message_id'        => $waId,
    ]);
}

/** Removes the words of messages older than 30 days, up to 2,000 a run. The rows stay. Returns how many. */
function tg_wa_retention(PDO $pdo, int $now): int
{
    try {
        $q = $pdo->prepare('UPDATE wa_messages SET body = NULL, body_gone = 1 WHERE body IS NOT NULL AND created_at < ? LIMIT 2000');
        $q->execute([tg_ist($now - TG_WA_KEEP_TEXT_DAYS * 86400)]);
        return $q->rowCount();
    } catch (Throwable $e) {
        error_log('whatsapp inbox: old text not removed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Nightly (cron/reconcile.php): clears the profile name of WhatsApp-only conversations
 * quiet for 90 days. Nothing is deleted: the number is the key for STOP and for the
 * "messaged us" record, and the messages hold no text by then. Returns how many.
 */
function tg_wa_anonymise(PDO $pdo, int $now): int
{
    $q = $pdo->prepare("UPDATE wa_contacts SET profile_name = '' WHERE contact_id IS NULL AND profile_name <> ''
        AND COALESCE(last_msg_at, created_at) < ?");
    $q->execute([tg_ist($now - TG_WA_FORGET_NAME_DAYS * 86400)]);
    return $q->rowCount();
}
