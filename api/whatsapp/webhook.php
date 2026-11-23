<?php
/**
 * Meta calls this for the business WhatsApp number (Callback URL in the Meta app).
 *
 * GET:  the one-time check when the webhook is set up. Meta sends the verify token
 *       from the private config; we answer with its challenge.
 * POST: news from Meta, signed with the app secret. We act on:
 *       - delivery news for our messages (sent, delivered, read, failed),
 *       - STOP or START from a buyer (never message them again, or again),
 *       - any other message: shown in the dashboard's inbox, answered by the first
 *         keyword rule it matches (Automations), and emailed to Deenu unless he
 *         switched reply emails off (this number has no app),
 *       - template news: a review result, a new category or a quality change. Templates
 *         written on the dashboard (wa_templates) are updated; a rejected, paused or
 *         re-categorised template, or one whose quality drops to red, is emailed.
 *       - number news (phone_number_quality_update): flagged, or the messaging limit went
 *         down or up. Saved for the dashboard's WhatsApp overview, which then reads Meta
 *         again; a drop or a limit change is emailed.
 * Message text is kept for 30 days in wa_messages so Deenu can answer from the inbox,
 * then removed by the worker; wa_inbound keeps only id, number and kind, for good.
 * The automatic STOP and START answers go only while WhatsApp is on, not paused, and
 * (in test mode) to a test number; the STOP or START itself is always recorded.
 * Meta resends anything we don't answer with 200, so each incoming message is
 * recorded once by its id and repeats are ignored.
 */
require dirname(__DIR__) . '/razorpay/lib.php';
require dirname(__DIR__) . '/razorpay/store.php';
require dirname(__DIR__) . '/razorpay/emails.php';
require dirname(__DIR__) . '/razorpay/whatsapp.php';

$config = rp_config();
if (!tg_wa_ready($config)) {
    rp_json(503, ['ok' => false, 'error' => 'not_configured']);
}
$wa = $config['whatsapp'];
$method = $_SERVER['REQUEST_METHOD'] ?? '';

// ---- Setup check. PHP turns "hub.mode" into "hub_mode". ----
if ($method === 'GET') {
    $challenge = (string) ($_GET['hub_challenge'] ?? '');
    $ok = ($_GET['hub_mode'] ?? '') === 'subscribe'
        && hash_equals((string) $wa['verify_token'], (string) ($_GET['hub_verify_token'] ?? ''))
        && preg_match('/^[A-Za-z0-9_-]{1,128}$/', $challenge);
    http_response_code($ok ? 200 : 403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $ok ? $challenge : 'Forbidden';
    exit;
}
if ($method !== 'POST') {
    rp_json(405, ['ok' => false]);
}

ignore_user_abort(true);
$raw = file_get_contents('php://input', false, null, 0, 1048576) ?: '';
$signature = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
$expected = 'sha256=' . hash_hmac('sha256', $raw, (string) $wa['app_secret']);
if ($signature === '' || !hash_equals($expected, $signature)) {
    rp_json(400, ['ok' => false, 'error' => 'invalid_signature']);
}
$event = json_decode($raw, true);
if (!is_array($event) || ($event['object'] ?? '') !== 'whatsapp_business_account') {
    rp_json(200, ['ok' => true, 'ignored' => 'object']);
}

// The dashboard reads this to show that Meta is reaching us.
@file_put_contents(dirname($config['sent_dir']) . '/whatsapp-webhook.last', json_encode(['at' => time()]), LOCK_EX);

$pdo = tg_db($config);
if ($pdo === null) {
    // Meta retries for days, so nothing is lost while the database is down.
    rp_json(503, ['ok' => false, 'error' => 'store_down']);
}

$later = [];   // replies and emails, done after Meta has its answer
$notes = [];   // template news for Deenu
$custom = false; // a note is about a template written on the dashboard
$numberMail = null; // news about the number's quality or limit, for Deenu
try {
    foreach ($event['entry'] ?? [] as $entry) {
        foreach ($entry['changes'] ?? [] as $change) {
            $field = (string) ($change['field'] ?? '');
            $v = is_array($change['value'] ?? null) ? $change['value'] : [];

            if ($field === 'messages') {
                // A business account can hold other numbers; only ours counts.
                if ((string) ($v['metadata']['phone_number_id'] ?? '') !== (string) $wa['phone_number_id']) {
                    continue;
                }
                foreach ($v['statuses'] ?? [] as $s) {
                    if (is_array($s)) {
                        tg_wa_status($pdo, $s);
                    }
                }
                foreach ($v['messages'] ?? [] as $m) {
                    $r = is_array($m) ? tg_wa_inbound($pdo, $config, $m, $v['contacts'] ?? []) : null;
                    if ($r !== null && $r['kind'] !== 'skip') {
                        $later[] = $r;
                    }
                }
            } elseif ($field === 'message_template_status_update') {
                $name = rp_clean($v['message_template_name'] ?? '', 80);
                $what = strtoupper(rp_clean($v['event'] ?? '', 30));
                $mine = tg_wat_event($pdo, $config, $field, $v, rp_now($config));
                tg_audit($config, 'system', 'whatsapp_template', $name . ': ' . $what);
                if (!in_array($what, ['APPROVED', 'PENDING', 'IN_APPEAL', 'DELETED', 'PENDING_DELETION'], true)) {
                    $notes[] = 'Template ' . $name . ' is now ' . strtolower($what)
                        . (!empty($v['reason']) && $v['reason'] !== 'NONE' ? ' (Meta says: ' . rp_clean($v['reason'], 200) . ')' : '') . '.';
                    $custom = $custom || $mine !== null;
                }
            } elseif ($field === 'template_category_update') {
                $name = rp_clean($v['message_template_name'] ?? '', 80);
                $new = strtolower(rp_clean($v['new_category'] ?? ($v['correct_category'] ?? ''), 30));
                $mine = tg_wat_event($pdo, $config, $field, $v, rp_now($config));
                tg_audit($config, 'system', 'whatsapp_template', $name . ': category ' . $new);
                // A template we wrote as marketing moving to utility is good news: no email.
                if ($new !== '' && $new !== 'utility' && strtolower((string) ($mine['category'] ?? 'utility')) !== $new) {
                    $notes[] = 'Meta moved template ' . $name . ' to the ' . $new . ' category, so each message now costs more.';
                    $custom = $custom || $mine !== null;
                }
            } elseif ($field === 'phone_number_quality_update') {
                $numberMail = tg_wa_number_event($config, $v, rp_now($config)) ?? $numberMail;
                tg_audit($config, 'system', 'whatsapp_number', strtolower(rp_clean($v['event'] ?? '', 30)) . ' '
                    . strtolower(rp_clean($v['current_limit'] ?? '', 30)));
            } elseif ($field === 'message_template_quality_update') {
                $name = rp_clean($v['message_template_name'] ?? '', 80);
                $new = tg_wat_quality($v['new_quality_score'] ?? '');
                $mine = tg_wat_event($pdo, $config, $field, $v, rp_now($config));
                tg_audit($config, 'system', 'whatsapp_template', $name . ': quality ' . strtolower($new));
                if ($new === 'RED') {
                    $notes[] = 'Template ' . $name . ' has low quality now: people blocked or reported it. Meta may pause it. '
                        . 'Send it to fewer people, or only to people who asked for it.';
                    $custom = $custom || $mine !== null;
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('whatsapp webhook: ' . $e->getMessage());
    rp_json(500, ['ok' => false]); // Meta will send it again
}

rp_json_early(200, ['ok' => true]);

foreach ($later as $r) {
    if ($r['kind'] === 'stop' || $r['kind'] === 'start') {
        // Pause stops everything, the courtesy answer included. The STOP itself is already recorded.
        if (!tg_wa_enabled($config) || tg_wa_paused($config) || !tg_wa_allowed($config, $r['from'])) {
            continue;
        }
        $text = $r['kind'] === 'stop' ? TG_WA_STOPPED : TG_WA_STARTED;
        $sent = tg_wa_send_text_result($config, $r['from'], $text);
        if ($sent['ok']) {
            tg_wa_log_out($pdo, $r['from'], ['kind' => 'text', 'body' => $text, 'sent_by' => 'auto', 'wa_id' => $sent['id'],
                'status' => 'sent', 'billable' => false], rp_now($config));
        }
        continue;
    }
    // Keyword rules answer first, so the person hears back at once (wa_rules.php).
    try {
        tg_rules_keyword($pdo, $config, $r, rp_now($config));
    } catch (Throwable $e) {
        error_log('whatsapp webhook: keyword rule not run: ' . $e->getMessage());
    }
    if (($wa['forward_replies'] ?? true) === false) {
        continue;
    }
    $mail = tg_wa_reply_email($config, $r, tg_wa_bookings($pdo, $r['contacts']));
    if (!rp_mail($config, $config['mail_notify'], $mail['subject'], $mail['body'])) {
        error_log('whatsapp webhook: reply from a buyer could not be emailed');
    }
}
if ($notes) {
    $body = implode("\r\n", array_merge($notes, ['',
        'Until it is fixed, that message does not go out on WhatsApp. The email reminders are not affected.',
        'Open the dashboard: ' . tg_site($config) . '/api/admin/?view=' . ($custom ? 'wa_templates' : 'emails&tab=whatsapp')]));
    rp_mail($config, $config['mail_notify'], 'ACTION: a WhatsApp template needs you', $body);
}
if ($numberMail !== null) {
    rp_mail($config, $config['mail_notify'], $numberMail['subject'], $numberMail['body']);
}
