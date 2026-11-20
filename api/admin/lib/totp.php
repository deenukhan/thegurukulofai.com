<?php
/**
 * Two-step sign-in: 6-digit codes from an authenticator app (Google Authenticator,
 * Microsoft Authenticator, 1Password and the like), the standard TOTP method
 * (RFC 6238: HMAC-SHA1, 30 seconds, 6 digits), plus one-time backup codes.
 *
 * The key lives in the admin_auth table. To start over (a lost phone and no
 * backup codes), delete that table's row in hPanel > Databases > phpMyAdmin:
 * the next sign-in sets it up again.
 */

const TOTP_STEP = 30;
const TOTP_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function totp_new_secret(): string
{
    $bytes = random_bytes(20);
    $bits = '';
    foreach (str_split($bytes) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= TOTP_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function totp_decode(string $base32): string
{
    $bits = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $base32))) as $char) {
        $bits .= str_pad(decbin(strpos(TOTP_ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

/** The 6-digit code for one 30-second step. */
function totp_code(string $base32, int $step): string
{
    $hash = hash_hmac('sha1', pack('J', $step), totp_decode($base32), true);
    $offset = ord($hash[19]) & 0x0F;
    $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
    return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
}

/**
 * The step a code belongs to, allowing one step of clock drift either way, or null.
 * Steps at or before $after are refused, so a code seen once can't be used again.
 */
function totp_match(string $base32, string $code, int $now, int $after = 0): ?int
{
    $code = preg_replace('/\D+/', '', $code);
    if (strlen($code) !== 6) {
        return null;
    }
    $current = intdiv($now, TOTP_STEP);
    foreach ([$current, $current - 1, $current + 1] as $step) {
        if ($step > $after && hash_equals(totp_code($base32, $step), $code)) {
            return $step;
        }
    }
    return null;
}

/** The link that adds the key to an authenticator app when tapped on a phone. */
function totp_uri(string $base32): string
{
    return 'otpauth://totp/' . rawurlencode('Gurukul dashboard') . '?' . http_build_query([
        'secret' => $base32, 'issuer' => 'Gurukul', 'digits' => 6, 'period' => TOTP_STEP,
    ]);
}

/** Eight fresh backup codes like "k7m2q-x9p4t", and their hashes for storage. */
function totp_backup_codes(): array
{
    $plain = [];
    for ($i = 0; $i < 8; $i++) {
        $raw = strtolower(totp_new_secret());
        $plain[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
    }
    return [$plain, array_map(static fn($c) => password_hash($c, PASSWORD_DEFAULT), $plain)];
}

/** The admin's two-step record, or null before it is set up. */
function totp_record(PDO $pdo): ?array
{
    $row = $pdo->query('SELECT * FROM admin_auth WHERE id = 1')->fetch();
    return $row ?: null;
}

/**
 * Checks a sign-in code: an authenticator code (used once) or a backup code (then
 * crossed off). Returns 'app', 'backup' or null.
 */
function totp_sign_in(PDO $pdo, string $code, int $now): ?string
{
    $rec = totp_record($pdo);
    if ($rec === null) {
        return null;
    }
    $step = totp_match($rec['totp_secret'], $code, $now, (int) $rec['last_step']);
    if ($step !== null) {
        // Only the request that moves last_step forward wins, so a code can't be used twice at once.
        $claim = $pdo->prepare('UPDATE admin_auth SET last_step = ? WHERE id = 1 AND last_step < ?');
        $claim->execute([$step, $step]);
        return $claim->rowCount() === 1 ? 'app' : null;
    }
    $typed = strtolower(trim($code));
    if (!preg_match('/^[a-z2-7]{5}-?[a-z2-7]{5}$/', $typed)) {
        return null;
    }
    $typed = substr($typed, 0, 5) . '-' . substr($typed, -5);
    $hashes = json_decode((string) $rec['backup_codes'], true) ?: [];
    foreach ($hashes as $i => $hash) {
        if (password_verify($typed, $hash)) {
            unset($hashes[$i]);
            $pdo->prepare('UPDATE admin_auth SET backup_codes = ? WHERE id = 1')->execute([json_encode(array_values($hashes))]);
            return 'backup';
        }
    }
    return null;
}

/** True when a code from the app is right now. Used to confirm each settings change. */
function totp_confirm(PDO $pdo, string $code, int $now): bool
{
    $rec = totp_record($pdo);
    return $rec !== null && totp_match($rec['totp_secret'], $code, $now) !== null;
}
