<?php
/**
 * The masterclass dashboard. It shows everything. It changes only a few things:
 * the settings on the Settings page, whether an add-on was delivered, and resending
 * a buyer's confirmation email.
 *
 * Not linked from anywhere and kept out of search. Sign-in is the password (hash in
 * the private config's `admin` block). Two-step sign-in (a code from an authenticator
 * app, lib/totp.php) is off unless the admin block has 'two_step' => true; with it on,
 * every settings change also needs a fresh code. Five wrong tries from one address
 * lock it for 15 minutes. It signs out after 30 minutes idle. Sign-ins and downloads
 * are written to the audit table.
 *
 * Pages are listed in lib/sections.php; each one is a file in views/.
 */
require dirname(__DIR__) . '/razorpay/lib.php';
require dirname(__DIR__) . '/razorpay/store.php';
require dirname(__DIR__) . '/razorpay/emails.php';
require dirname(__DIR__) . '/razorpay/whatsapp.php';
require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/checks.php';
require __DIR__ . '/lib/whatsapp.php';
require __DIR__ . '/lib/metrics.php';
require __DIR__ . '/lib/totp.php';
require __DIR__ . '/lib/actions.php';

$config = rp_config();
date_default_timezone_set('Asia/Kolkata'); // the store keeps IST times

const ADMIN_CSS_VERSION = 11;

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$hash = (string) ($config['admin']['password_hash'] ?? '');
if ($hash === '' || str_starts_with($hash, 'PASTE')) {
    http_response_code(503);
    exit('Not available.');
}

define('ADMIN_TWO_STEP', !empty($config['admin']['two_step']));

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
session_name('tg_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/api/admin/',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

const ADMIN_IDLE = 30 * 60;
const ADMIN_MAX_FAILS = 5;
const ADMIN_LOCK_SECONDS = 15 * 60;

function admin_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function admin_token_ok(): bool
{
    return is_string($_POST['csrf'] ?? null) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

/** Wrong-password counter per address, kept in the private folder. Returns [count, window start]. */
function admin_fails(array $config, bool $add = false, bool $clear = false): array
{
    $dir = dirname($config['sent_dir']) . '/razorpay-admin-fails';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $path = $dir . '/' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if ($clear) {
        @unlink($path);
        return [0, 0];
    }
    [$count, $start] = array_map('intval', explode(' ', trim((string) @file_get_contents($path)) . ' 0 0'));
    if (time() - $start >= ADMIN_LOCK_SECONDS) {
        [$count, $start] = [0, time()];
    }
    if ($add) {
        $count++;
        @file_put_contents($path, $count . ' ' . $start, LOCK_EX);
    }
    return [$count, $start];
}

/** The page skeleton: fonts, styles and the body. */
function admin_document(string $title, string $body, string $bodyClass = ''): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">',
        '<meta name="viewport" content="width=device-width, initial-scale=1">',
        '<meta name="robots" content="noindex, nofollow">',
        '<title>', h($title), ' · Gurukul dashboard</title>',
        '<link rel="icon" href="/assets/images/favicon.png">',
        '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>',
        '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&display=swap">',
        '<link rel="stylesheet" href="admin.css?v=', ADMIN_CSS_VERSION, '">',
        '</head><body', $bodyClass !== '' ? ' class="' . h($bodyClass) . '"' : '', '>', $body, '</body></html>';
    exit;
}

// ---------- Sign out, and idle timeout ----------
if (($_POST['action'] ?? '') === 'logout' && admin_token_ok()) {
    tg_audit($config, 'admin', 'logout');
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}
if (!empty($_SESSION['auth']) && time() - (int) ($_SESSION['seen'] ?? 0) > ADMIN_IDLE) {
    $_SESSION = [];
    session_regenerate_id(true);
}

/** The sign-in card: logo, heading, any error, then the form fields (html). */
function admin_login_page(string $heading, string $text, string $error, string $fields): void
{
    admin_document('Sign in', '<main class="login"><div class="login-card">'
        . '<img src="/assets/images/logo-tgai.png" alt="The Gurukul of AI" class="login-logo">'
        . '<h1>' . h($heading) . '</h1><p class="muted">' . $text . '</p>'
        . ($error !== '' ? '<p class="login-error" role="alert">' . h($error) . '</p>' : '')
        . '<form method="post"><input type="hidden" name="csrf" value="' . h(admin_token()) . '">' . $fields . '</form></div></main>', 'is-login');
}

function admin_code_field(string $label = 'Code from your authenticator app'): string
{
    return '<label for="code">' . h($label) . '</label>'
        . '<input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="11" required autofocus>';
}

// ---------- Sign in, step 1: the password ----------
// Fully signed in means $_SESSION['auth'] is 'mfa' (password and code), or 'pw'
// (password only) while two-step sign-in is off. Turning it on sends 'pw' sessions
// back here. Anything else, such as an old 'admin' session, signs in again too.
$signedIn = ($_SESSION['auth'] ?? '') === 'mfa' || (!ADMIN_TWO_STEP && ($_SESSION['auth'] ?? '') === 'pw');
if (!$signedIn) {
    $error = '';
    [$fails] = admin_fails($config);
    $passwordOk = !empty($_SESSION['pw_ok']) && time() - (int) $_SESSION['pw_ok'] < 300;
    $action = (string) ($_POST['action'] ?? '');

    if (!$passwordOk) {
        if ($action === 'login') {
            if ($fails >= ADMIN_MAX_FAILS) {
                $error = 'Too many wrong tries. Wait 15 minutes.';
            } elseif (!admin_token_ok() || !password_verify((string) ($_POST['password'] ?? ''), $hash)) {
                [$fails] = admin_fails($config, true);
                tg_audit($config, 'admin', 'login_failed');
                $error = $fails >= ADMIN_MAX_FAILS ? 'Too many wrong tries. Wait 15 minutes.' : 'Wrong password.';
            } else {
                session_regenerate_id(true);
                if (!ADMIN_TWO_STEP) {
                    admin_fails($config, false, true);
                    $_SESSION = ['auth' => 'pw', 'seen' => time(), 'csrf' => bin2hex(random_bytes(16))];
                    tg_audit($config, 'admin', 'login', 'password');
                } else {
                    $_SESSION = ['pw_ok' => time(), 'csrf' => bin2hex(random_bytes(16))];
                }
                header('Location: ./');
                exit;
            }
        }
        admin_login_page('Masterclass dashboard', 'Sign in to see sales, people and emails.', $error,
            '<input type="hidden" name="action" value="login"><label for="pw">Password</label>'
            . '<input id="pw" type="password" name="password" autocomplete="current-password" required autofocus>'
            . '<button type="submit" class="btn btn-primary">' . (ADMIN_TWO_STEP ? 'Continue' : 'Sign in') . '</button>');
    }

    // ---------- Step 2: a code from the authenticator app ----------
    $pdo = tg_db($config);
    if ($pdo === null) {
        admin_login_page('The database is not answering', 'Check the db block in the private config, then reload this page.', '', '');
    }
    $record = totp_record($pdo);
    $finish = static function (string $how) use ($config): void {
        admin_fails($config, false, true);
        session_regenerate_id(true);
        $_SESSION = ['auth' => 'mfa', 'seen' => time(), 'csrf' => bin2hex(random_bytes(16))] + array_intersect_key($_SESSION, ['flash' => 1]);
        tg_audit($config, 'admin', 'login', $how);
        header('Location: ./');
        exit;
    };

    if ($record === null) {
        // First sign-in since two-step sign-in arrived: set it up now.
        $_SESSION['new_secret'] ??= totp_new_secret();
        $secret = $_SESSION['new_secret'];
        if ($action === 'mfa_setup' && admin_token_ok()) {
            if ($fails >= ADMIN_MAX_FAILS) {
                $error = 'Too many wrong tries. Wait 15 minutes.';
            } elseif (($step = totp_match($secret, (string) ($_POST['code'] ?? ''), time())) === null) {
                admin_fails($config, true);
                $error = 'That code did not match. Check the app shows "Gurukul" and try the newest code.';
            } else {
                [$plain, $hashes] = totp_backup_codes();
                $save = $pdo->prepare('INSERT IGNORE INTO admin_auth (id, totp_secret, backup_codes, last_step) VALUES (1, ?, ?, ?)');
                $save->execute([$secret, json_encode($hashes), $step]); // this code is now used up
                if ($save->rowCount() === 1) {
                    tg_audit($config, 'admin', 'mfa_setup');
                    $_SESSION['flash'] = ['tone' => 'good', 'text' => 'Two-step sign-in is on. Save these backup codes somewhere safe, such as your password manager. Each works once, if you ever lose your phone.', 'codes' => $plain];
                    unset($_SESSION['new_secret']);
                    $finish('set up two-step sign-in');
                }
                $error = 'Two-step sign-in was just set up elsewhere. Reload to sign in with that.';
            }
        }
        $grouped = trim(chunk_split($secret, 4, ' '));
        admin_login_page('Set up two-step sign-in', 'One time only. From now on, signing in needs your password and a 6-digit code from your phone.', $error,
            '<input type="hidden" name="action" value="mfa_setup">'
            . '<ol class="setup-steps"><li>Install <strong>Google Authenticator</strong> (or any authenticator app) on your phone.</li>'
            . '<li>On your phone, tap <a href="' . h(totp_uri($secret)) . '">this link</a>. Or in the app tap <strong>+</strong>, then <strong>Enter a setup key</strong>, name it Gurukul, and type this key:</li></ol>'
            . '<p class="setup-key"><code>' . h($grouped) . '</code></p>'
            . '<p class="muted small">Keep this key private. Anyone with it can make your codes.</p>'
            . admin_code_field('3. Type the 6-digit code the app shows')
            . '<button type="submit" class="btn btn-primary">Turn on and sign in</button>');
    }

    if ($action === 'mfa' && admin_token_ok()) {
        if ($fails >= ADMIN_MAX_FAILS) {
            $error = 'Too many wrong tries. Wait 15 minutes.';
        } elseif (($how = totp_sign_in($pdo, (string) ($_POST['code'] ?? ''), time())) !== null) {
            if ($how === 'backup') {
                $left = count(json_decode((string) (totp_record($pdo)['backup_codes'] ?? '[]'), true) ?: []);
                $_SESSION['flash'] = ['tone' => 'warn', 'text' => 'You signed in with a backup code. ' . $left . ' left. Make new ones in Settings if you are running low.'];
            }
            $finish($how === 'backup' ? 'backup code' : 'app code');
        } else {
            [$fails] = admin_fails($config, true);
            tg_audit($config, 'admin', 'code_failed');
            $error = $fails >= ADMIN_MAX_FAILS ? 'Too many wrong tries. Wait 15 minutes.' : 'That code did not work. Codes change every 30 seconds, and each works once.';
        }
    }
    admin_login_page('Enter your code', 'Open your authenticator app and type the 6-digit code for Gurukul. Lost your phone? Use a backup code.', $error,
        '<input type="hidden" name="action" value="mfa">' . admin_code_field('Code')
        . '<button type="submit" class="btn btn-primary">Sign in</button>');
}
$_SESSION['seen'] = time();

// ---------- Signed in ----------
$pdo = tg_db($config);
if ($pdo === null) {
    admin_document('Dashboard', '<main class="login"><div class="login-card"><h1>The database is not answering</h1>'
        . '<p class="muted">Check the db block in the private config, then reload this page.</p></div></main>', 'is-login');
}

// Changes (settings, add-on delivery, resending an email) arrive as POST and come back as a page.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    admin_handle_post($pdo, $config);
}

// Filters are plain words and numbers. Anything else (such as ?q[]=) is ignored.
$_GET = array_filter($_GET, 'is_string');

$sections = require __DIR__ . '/lib/sections.php';
$key = (string) ($_GET['view'] ?? 'overview');
if (!array_key_exists($key, $sections)) {
    $key = 'overview';
}
$section = $sections[$key];
$now = time();
$d = [
    'pdo'     => $pdo,
    'config'  => $config,
    'now'     => $now,
    'product' => $config['product'],
    'view'    => $key,
    'range'   => admin_range((string) ($_GET['range'] ?? ''), $now),
];

$render = require __DIR__ . '/views/' . $key . '.php';
$page = $render($d) + ['title' => $section['label'], 'intro' => $section['intro'], 'actions' => '', 'back' => null, 'body' => ''];

// ---------- Menu ----------
$checks = admin_checks($pdo, $config, $now);
$bad = count(array_filter($checks, static fn($c) => $c['level'] === 'bad'));
$warn = count(array_filter($checks, static fn($c) => $c['level'] === 'warn'));
$badges = ['health' => [$bad + $warn, $bad > 0 ? 'bad' : 'warn']];

$nav = '';
$group = null;
foreach ($sections as $k => $s) {
    if ($s['group'] !== $group) {
        $group = $s['group'];
        $nav .= '<p class="nav-group">' . h($group) . '</p>';
    }
    $on = $k === $key;
    $badge = '';
    if (!empty($badges[$k][0])) {
        $badge = '<b class="badge badge-' . $badges[$k][1] . '" aria-label="' . (int) $badges[$k][0] . ' to check">' . (int) $badges[$k][0] . '</b>';
    }
    $range = $s['range'] && $d['range']['key'] !== '30d' ? $d['range']['key'] : '';
    $nav .= '<a class="nav-item' . ($on ? ' on' : '') . '"' . ($on ? ' aria-current="page"' : '')
        . ' href="' . h(admin_url(['view' => $k === 'overview' ? '' : $k, 'range' => $range])) . '">'
        . icon($s['icon']) . '<span>' . h($s['label']) . '</span>' . $badge . '</a>';
}

// ---------- Period picker: keeps the page's other filters ----------
$tools = '';
if ($section['range']) {
    $keep = array_diff_key($_GET, ['range' => 1, 'p' => 1, 'export' => 1]);
    $tools .= '<nav class="segmented" aria-label="Period">';
    foreach (ADMIN_RANGES as $rk => $r) {
        $on = $rk === $d['range']['key'];
        $tools .= '<a' . ($on ? ' class="on" aria-current="true"' : '') . ' href="'
            . h(admin_url(['range' => $rk === '30d' ? '' : $rk] + $keep)) . '">' . h($r['label']) . '</a>';
    }
    $tools .= '</nav>';
}
$tools .= $page['actions'];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$flashHtml = '';
if (is_array($flash)) {
    $codes = '';
    if (!empty($flash['codes'])) {
        $codes = '<ol class="codes">' . implode('', array_map(static fn($c) => '<li><code>' . h($c) . '</code></li>', $flash['codes'])) . '</ol>';
    }
    // Backup codes stay in the page so they can't fade away. Other messages float at
    // the bottom of the screen, so they are seen even when the page reopens part-way down.
    // Good news fades on its own; a problem stays until closed (a CSS-only checkbox).
    $flashHtml = $codes !== ''
        ? '<div class="flash flash-' . h($flash['tone']) . '" role="status">' . icon('alert')
            . '<div><p>' . h($flash['text']) . '</p>' . $codes . '</div></div>'
        : '<input type="checkbox" id="toast-close" class="toast-close" hidden>'
            . '<div class="flash toast flash-' . h($flash['tone']) . '" role="' . ($flash['tone'] === 'good' ? 'status' : 'alert') . '">'
            . icon($flash['tone'] === 'good' ? 'check' : 'alert') . '<div><p>' . h($flash['text']) . '</p></div>'
            . '<label for="toast-close" class="toast-x" aria-label="Close">' . icon('x') . '</label></div>';
}

$back = $page['back'] ? '<a class="back" href="' . h($page['back'][1]) . '">' . icon('back') . h($page['back'][0]) . '</a>' : '';

admin_document($page['title'], '<a class="skip" href="#main">Skip to content</a><div class="app">'
    . '<aside class="side"><a class="brand" href="./"><img src="/assets/images/favicon.png" alt="" width="32" height="32">'
    . '<span><strong>Gurukul</strong><small>Masterclass dashboard</small></span></a>'
    . '<nav class="nav" aria-label="Dashboard">' . $nav . '</nav>'
    . '<form class="side-foot" method="post"><input type="hidden" name="action" value="logout">'
    . '<input type="hidden" name="csrf" value="' . h(admin_token()) . '">'
    . '<button type="submit" class="nav-item">' . icon('logout') . '<span>Sign out</span></button></form></aside>'
    . '<main id="main" class="main"><header class="page-head"><div class="page-title">' . $back
    . '<h1>' . h($page['title']) . '</h1><p>' . h($page['intro']) . '</p></div>'
    . ($tools !== '' ? '<div class="page-tools">' . $tools . '</div>' : '') . '</header>'
    . $flashHtml . $page['body']
    . '<footer class="page-foot">All times IST · Every change is logged · Loaded ' . h(date('j M, g:i A', $now)) . '</footer></main></div>');
