<?php
/**
 * The settings Deenu can change from the dashboard. Each has a label, a check and a
 * way to show its value. Saved values go to the private razorpay-settings.json, which
 * every server script lays over the config file (rp_apply_settings in lib.php).
 *
 * To add a setting: add its name to RP_DASHBOARD_SETTINGS in lib.php, describe it
 * here, and give it a form on views/settings.php. Never secrets, prices or the schedule.
 */

function admin_setting_defs(): array
{
    return [
        'zoom.join_url' => [
            'label' => 'Zoom link',
            'check' => static function (string $v): array {
                $v = trim($v);
                $p = parse_url($v);
                $host = strtolower((string) ($p['host'] ?? ''));
                $ok = ($p['scheme'] ?? '') === 'https' && ($host === 'zoom.us' || str_ends_with($host, '.zoom.us'))
                    && strlen($v) <= 400 && !preg_match('/\s/', $v);
                return $ok ? [$v, null] : [null, 'That is not a Zoom link. It should start with https://zoom.us/ or https://us06web.zoom.us/.'];
            },
            'show' => static fn($v): string => (string) $v,
        ],
        'community_url' => [
            'label' => 'WhatsApp community link',
            'check' => static function (string $v): array {
                $v = trim($v);
                return preg_match('~^https://chat\.whatsapp\.com/[A-Za-z0-9]{10,40}$~', $v)
                    ? [$v, null] : [null, 'That is not a WhatsApp invite link. It should look like https://chat.whatsapp.com/AbCdEf123….'];
            },
            'show' => static fn($v): string => (string) $v,
        ],
        'coupon.paused' => [
            'label' => 'Coupon on or paused',
            'check' => static fn(string $v): array => in_array($v, ['0', '1'], true) ? [$v === '1', null] : [null, 'Choose on or paused.'],
            'show' => static fn($v): string => $v ? 'Paused' : 'On',
        ],
        'whatsapp.paused' => [
            'label' => 'WhatsApp messages on or paused',
            'check' => static fn(string $v): array => in_array($v, ['0', '1'], true) ? [$v === '1', null] : [null, 'Choose on or paused.'],
            'show' => static fn($v): string => $v ? 'Paused' : 'On',
        ],
        'whatsapp.checkout_coupon' => [
            'label' => 'WhatsApp checkout coupon on or off',
            'check' => static fn(string $v): array => in_array($v, ['0', '1'], true) ? [$v === '1', null] : [null, 'Choose on or off.'],
            'show' => static fn($v): string => $v ? 'On' : 'Off',
        ],
        'whatsapp.forward_replies' => [
            'label' => 'Email me each WhatsApp reply',
            'check' => static fn(string $v): array => in_array($v, ['0', '1'], true) ? [$v === '1', null] : [null, 'Choose on or off.'],
            'show' => static fn($v): string => $v === false ? 'Off' : 'On',
        ],
        'whatsapp.daily_cap' => [
            'label' => 'WhatsApp daily limit',
            'check' => static function (string $v): array {
                $v = trim($v);
                return ctype_digit($v) && (int) $v <= 10000 ? [(int) $v, null] : [null, 'The daily limit must be a whole number, 0 to 10,000.'];
            },
            'show' => static fn($v): string => $v === null ? (string) TG_WA_DAILY_CAP . ' (default)' : num((int) $v) . ' people a day',
        ],
        'whatsapp.my_number' => [
            'label' => 'Your WhatsApp number for tests',
            'check' => static function (string $v): array {
                $v = trim($v);
                if ($v === '') {
                    return ['', null];
                }
                $n = tg_wa_number($v);
                return $n !== null ? [$n, null] : [null, 'That is not a WhatsApp number. Type it with the country code, such as +91 98765 43210.'];
            },
            'show' => static fn($v): string => (string) $v === '' ? 'None' : tg_wa_pretty((string) $v),
        ],
        'coupon.max_uses' => [
            'label' => 'Coupon use limit',
            'check' => static function (string $v): array {
                $v = trim($v);
                return ctype_digit($v) && (int) $v <= 10000 ? [(int) $v, null] : [null, 'The use limit must be a whole number, 0 to 10,000.'];
            },
            'show' => static fn($v): string => $v === null ? 'No limit' : (string) $v,
        ],
    ];
}

/** The config file alone, without the dashboard's changes. */
function admin_config_file(): array
{
    $raw = require rp_config_path();
    return is_array($raw) ? $raw : [];
}

function admin_dot(array $config, string $name)
{
    [$block, $key] = array_pad(explode('.', $name, 2), 2, null);
    return $key === null ? ($config[$block] ?? null) : (is_array($config[$block] ?? null) ? ($config[$block][$key] ?? null) : null);
}

/** A setting's value in use and where it comes from: 'dashboard' or 'config'. */
function admin_setting(array $config, string $name): array
{
    $saved = rp_settings_read($config);
    if (array_key_exists($name, $saved)) {
        return [$saved[$name], 'dashboard'];
    }
    return [admin_dot(admin_config_file(), $name), 'config'];
}

/**
 * Saves one setting ($value null = go back to the config file), records the change
 * for undo, and logs it. Returns false if the file could not be written.
 */
function admin_setting_write(PDO $pdo, array $config, string $name, $value): bool
{
    $path = rp_settings_path($config);
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        return false;
    }
    $saved = rp_settings_read($config);
    $old = array_key_exists($name, $saved) ? json_encode($saved[$name], JSON_UNESCAPED_SLASHES) : null;
    if ($value === null) {
        unset($saved[$name]);
    } else {
        $saved[$name] = $value;
    }
    $tmp = $path . '.tmp';
    $ok = @file_put_contents($tmp, json_encode($saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false && @chmod($tmp, 0600) && @rename($tmp, $path);
    flock($lock, LOCK_UN);
    fclose($lock);
    if (!$ok) {
        return false;
    }
    $new = $value === null ? null : json_encode($value, JSON_UNESCAPED_SLASHES);
    $pdo->prepare('INSERT INTO setting_changes (name, old_value, new_value, ip) VALUES (?, ?, ?, ?)')
        ->execute([$name, $old, $new, rp_clean($_SERVER['REMOTE_ADDR'] ?? '', 45)]);
    $show = admin_setting_defs()[$name]['show'];
    tg_audit($config, 'admin', $value === null ? 'setting_reset' : 'setting_changed',
        $name . ': ' . ($value === null ? 'back to the config file' : $show($value)));
    return true;
}

/** True during a live session, from 15 minutes before it until it ends. */
function admin_session_live(int $now): bool
{
    $previous = rp_next_session($now)->modify('-7 days')->getTimestamp();
    return $now >= $previous - 15 * 60 && $now <= $previous + 3 * 3600;
}
