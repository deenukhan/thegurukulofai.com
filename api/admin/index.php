<?php
/**
 * Private admin page for the masterclass contact store.
 *
 * Not linked from anywhere and kept out of search. One password, whose hash lives
 * in the private config (`admin` block). Five wrong tries from one address lock it
 * for 15 minutes. It logs out after 30 minutes idle. Every login and export is
 * written to the audit table.
 *
 * Shows bookings, filters them by session, status and add-on, and exports a CSV.
 */
require dirname(__DIR__) . '/razorpay/lib.php';
require dirname(__DIR__) . '/razorpay/store.php';

$config = rp_config();
date_default_timezone_set('Asia/Kolkata'); // the store keeps IST times

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$hash = (string) ($config['admin']['password_hash'] ?? '');
if ($hash === '' || str_starts_with($hash, 'PASTE')) {
    http_response_code(503);
    exit('Not available.');
}

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

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

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

function admin_page(string $title, string $body): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">',
        '<meta name="viewport" content="width=device-width, initial-scale=1">',
        '<meta name="robots" content="noindex, nofollow">',
        '<title>', h($title), '</title><style>',
        ':root{--ink:#1A1A1A;--teal:#3ECFCF;--slate:#4A4A4A;--mist:#E8E4DE;--bg:#F5F5F7;--forest:#2D4A3E}',
        '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}',
        'main{max-width:1400px;margin:0 auto;padding:24px 16px}h1{font-size:22px;margin:0 0 4px;letter-spacing:-.02em}',
        '.muted{color:var(--slate)}.card{background:#fff;border:1px solid var(--mist);border-radius:14px;padding:16px;margin:16px 0}',
        'form.filters{display:flex;flex-wrap:wrap;gap:10px;align-items:end}label{display:flex;flex-direction:column;gap:4px;font-weight:600;font-size:12px}',
        'input,select,button{font:inherit;padding:8px 10px;border:1px solid var(--mist);border-radius:8px;background:#fff;color:var(--ink)}',
        'button,.btn{background:var(--forest);color:#fff;border-color:var(--forest);cursor:pointer;text-decoration:none;display:inline-block;padding:8px 14px;border-radius:8px}',
        '.btn.light{background:#fff;color:var(--ink);border:1px solid var(--mist)}',
        '.stats{display:flex;flex-wrap:wrap;gap:12px}.stat{background:#fff;border:1px solid var(--mist);border-radius:12px;padding:10px 14px;min-width:120px}',
        '.stat b{display:block;font-size:20px}.wrap{overflow-x:auto}table{border-collapse:collapse;width:100%;background:#fff}',
        'th,td{padding:8px 10px;border-bottom:1px solid var(--mist);text-align:left;white-space:nowrap;vertical-align:top}th{font-size:12px;color:var(--slate)}',
        '.pill{display:inline-block;padding:2px 8px;border-radius:99px;font-size:12px;font-weight:600}',
        '.paid{background:#DDF7F0;color:#0B5345}.unpaid{background:#FFF1D6;color:#7A4B00}.failed{background:#FDE2E1;color:#8A1C14}.open{background:#E6F0FF;color:#1B3F8A}',
        '.top{display:flex;justify-content:space-between;align-items:start;gap:12px;flex-wrap:wrap}.err{color:#8A1C14;font-weight:600}',
        '.login{max-width:360px;margin:12vh auto}.login form{display:flex;flex-direction:column;gap:12px}',
        '</style></head><body><main>', $body, '</main></body></html>';
    exit;
}

// ---------- Log out, and idle timeout ----------
if (($_POST['action'] ?? '') === 'logout' && admin_token_ok()) {
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}
if (!empty($_SESSION['admin']) && time() - (int) ($_SESSION['seen'] ?? 0) > ADMIN_IDLE) {
    $_SESSION = [];
    session_regenerate_id(true);
}

// ---------- Log in ----------
if (empty($_SESSION['admin'])) {
    $error = '';
    [$fails] = admin_fails($config);
    if (($_POST['action'] ?? '') === 'login') {
        if ($fails >= ADMIN_MAX_FAILS) {
            $error = 'Too many wrong tries. Wait 15 minutes.';
        } elseif (!admin_token_ok() || !password_verify((string) ($_POST['password'] ?? ''), $hash)) {
            [$fails] = admin_fails($config, true);
            tg_audit($config, 'admin', 'login_failed');
            $error = $fails >= ADMIN_MAX_FAILS ? 'Too many wrong tries. Wait 15 minutes.' : 'Wrong password.';
        } else {
            admin_fails($config, false, true);
            session_regenerate_id(true);
            $_SESSION = ['admin' => true, 'seen' => time(), 'csrf' => bin2hex(random_bytes(16))];
            tg_audit($config, 'admin', 'login');
            header('Location: ./');
            exit;
        }
    }
    admin_page('Sign in', '<div class="login card"><h1>Masterclass admin</h1>'
        . ($error !== '' ? '<p class="err">' . h($error) . '</p>' : '')
        . '<form method="post"><input type="hidden" name="action" value="login">'
        . '<input type="hidden" name="csrf" value="' . h(admin_token()) . '">'
        . '<label>Password<input type="password" name="password" autocomplete="current-password" required autofocus></label>'
        . '<button type="submit">Sign in</button></form></div>');
}
$_SESSION['seen'] = time();

// ---------- Signed in ----------
$pdo = tg_db($config);
if ($pdo === null) {
    admin_page('Admin', '<h1>Masterclass admin</h1><p class="err">The contact store is not reachable. Check the db block in the private config, then reload.</p>');
}

$statuses = [
    'all'    => 'Everyone',
    'paid'   => 'Paid',
    'unpaid' => 'Not paid (started or failed)',
    'failed' => 'Payment failed',
];
$filter = [
    'session' => preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($_GET['session'] ?? '')) ? (string) $_GET['session'] : '',
    'status'  => array_key_exists($_GET['status'] ?? '', $statuses) ? (string) $_GET['status'] : 'all',
    'addon'   => in_array($_GET['addon'] ?? '', ['yes', 'no'], true) ? (string) $_GET['addon'] : '',
    'offers'  => ($_GET['offers'] ?? '') === 'yes' ? 'yes' : '',
    'q'       => rp_clean($_GET['q'] ?? '', 80),
];

$where = ['b.product = ?'];
$args = [$config['product']];
if ($filter['session'] !== '') {
    $where[] = 'b.session_at = ?';
    $args[] = $filter['session'];
}
if ($filter['status'] === 'paid') {
    $where[] = "b.status = 'paid'";
} elseif ($filter['status'] === 'unpaid') {
    $where[] = "b.status <> 'paid'";
} elseif ($filter['status'] === 'failed') {
    $where[] = "b.status = 'failed'";
}
if ($filter['addon'] !== '') {
    $where[] = 'b.addon = ?';
    $args[] = $filter['addon'] === 'yes' ? 1 : 0;
}
if ($filter['offers'] === 'yes') {
    $where[] = 'c.marketing_optin = 1 AND c.email_unsub_at IS NULL';
}
if ($filter['q'] !== '') {
    $where[] = '(c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)';
    $like = '%' . addcslashes($filter['q'], '%_\\') . '%';
    array_push($args, $like, $like, $like);
}
$sql = 'SELECT b.created_at, c.name, c.email, c.phone, b.session_at, b.status, b.addon, b.coupon, b.amount_paise,
        b.utm_source, b.utm_medium, b.utm_campaign, b.utm_content, c.marketing_optin, c.email_unsub_at,
        b.order_id, b.payment_id, b.paid_at, b.fail_reason, b.source
    FROM bookings b JOIN contacts c ON c.id = b.contact_id
    WHERE ' . implode(' AND ', $where) . ' ORDER BY b.created_at DESC, b.id DESC';

/** How a booking reads to Deenu. A checkout still open under 45 minutes isn't abandoned yet. */
function admin_status(array $row): array
{
    return match ($row['status']) {
        'paid'         => ['Paid', 'paid'],
        'failed'       => ['Payment failed', 'failed'],
        'order_failed' => ['Order error', 'failed'],
        default        => strtotime($row['created_at']) > time() - 45 * 60 ? ['Checking out', 'open'] : ['Not paid', 'unpaid'],
    };
}

function admin_session_label(?string $at): string
{
    return $at ? date('D j M Y, g A', strtotime($at)) : '—';
}

// ---------- CSV export ----------
if (($_GET['export'] ?? '') === 'csv') {
    $rows = $pdo->prepare($sql);
    $rows->execute($args);
    $all = $rows->fetchAll();
    $described = [];
    foreach ($filter as $key => $value) {
        if ($value !== '' && $value !== 'all') {
            $described[] = $key . '=' . $value;
        }
    }
    tg_audit($config, 'admin', 'export_csv', count($all) . ' rows' . ($described ? '; ' . implode(', ', $described) : ''));

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="masterclass-contacts-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // so Excel reads the names correctly
    // A cell starting with = + - @ could run as a formula in Excel or Sheets.
    $safe = static fn($v): string => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
    fputcsv($out, ['booked_at', 'name', 'email', 'whatsapp', 'session', 'status', 'addon', 'coupon', 'amount_rs',
        'offers_optin', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'order_id', 'payment_id', 'paid_at', 'fail_reason'], ',', '"', '');
    foreach ($all as $row) {
        fputcsv($out, array_map($safe, [
            $row['created_at'], $row['name'], $row['email'], $row['phone'] !== '' ? '+91' . $row['phone'] : '',
            admin_session_label($row['session_at']), admin_status($row)[0], $row['addon'] ? 'yes' : 'no',
            $row['coupon'] ? 'yes' : 'no', number_format($row['amount_paise'] / 100, 2, '.', ''),
            $row['marketing_optin'] && !$row['email_unsub_at'] ? 'yes' : 'no',
            $row['utm_source'], $row['utm_medium'], $row['utm_campaign'], $row['utm_content'],
            $row['order_id'], $row['payment_id'], $row['paid_at'], $row['fail_reason'],
        ]), ',', '"', '');
    }
    fclose($out);
    exit;
}

// ---------- List ----------
$rows = $pdo->prepare($sql . ' LIMIT 1000');
$rows->execute($args);
$list = $rows->fetchAll();

$totals = $pdo->prepare("SELECT COUNT(*) AS n,
        SUM(b.status = 'paid') AS paid,
        SUM(b.status = 'paid' AND b.addon = 1) AS addons,
        SUM(CASE WHEN b.status = 'paid' THEN b.amount_paise ELSE 0 END) AS revenue,
        SUM(b.status <> 'paid') AS unpaid
    FROM bookings b JOIN contacts c ON c.id = b.contact_id WHERE " . implode(' AND ', $where));
$totals->execute($args);
$t = $totals->fetch();

$sessions = $pdo->prepare('SELECT session_at, SUM(status = \'paid\') AS paid FROM bookings
    WHERE product = ? AND session_at IS NOT NULL GROUP BY session_at ORDER BY session_at DESC');
$sessions->execute([$config['product']]);

$sessionOptions = '<option value="">All sessions</option>';
foreach ($sessions->fetchAll() as $s) {
    $sessionOptions .= '<option value="' . h($s['session_at']) . '"' . ($filter['session'] === $s['session_at'] ? ' selected' : '') . '>'
        . h(admin_session_label($s['session_at'])) . ' · ' . (int) $s['paid'] . ' paid</option>';
}
$statusOptions = '';
foreach ($statuses as $key => $label) {
    $statusOptions .= '<option value="' . h($key) . '"' . ($filter['status'] === $key ? ' selected' : '') . '>' . h($label) . '</option>';
}
$addonOptions = '';
foreach (['' => 'Any', 'yes' => 'With add-on', 'no' => 'Without add-on'] as $key => $label) {
    $addonOptions .= '<option value="' . h($key) . '"' . ($filter['addon'] === $key ? ' selected' : '') . '>' . h($label) . '</option>';
}
$query = http_build_query(array_filter($filter, static fn($v) => $v !== '' && $v !== 'all'));

$body = '<div class="top"><div><h1>Masterclass contacts</h1><p class="muted">Times are IST. Unpaid checkouts count as abandoned after 45 minutes.</p></div>'
    . '<form method="post"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="' . h(admin_token()) . '">'
    . '<button type="submit" class="btn light">Log out</button></form></div>'
    . '<div class="card"><form class="filters" method="get">'
    . '<label>Session<select name="session">' . $sessionOptions . '</select></label>'
    . '<label>Status<select name="status">' . $statusOptions . '</select></label>'
    . '<label>Add-on<select name="addon">' . $addonOptions . '</select></label>'
    . '<label>Offers<select name="offers"><option value="">Any</option><option value="yes"' . ($filter['offers'] === 'yes' ? ' selected' : '') . '>Said yes to offers</option></select></label>'
    . '<label>Search<input type="search" name="q" value="' . h($filter['q']) . '" placeholder="Name, email or number"></label>'
    . '<button type="submit">Apply</button> <a class="btn light" href="./">Clear</a>'
    . ' <a class="btn light" href="?' . h($query . ($query !== '' ? '&' : '') . 'export=csv') . '">Export CSV</a>'
    . '</form></div>'
    . '<div class="stats">'
    . '<div class="stat"><span class="muted">Paid seats</span><b>' . (int) $t['paid'] . '</b></div>'
    . '<div class="stat"><span class="muted">With add-on</span><b>' . (int) $t['addons'] . '</b></div>'
    . '<div class="stat"><span class="muted">Revenue</span><b>₹' . number_format(((int) $t['revenue']) / 100) . '</b></div>'
    . '<div class="stat"><span class="muted">Not paid</span><b>' . (int) $t['unpaid'] . '</b></div>'
    . '</div>';

if (!$list) {
    $body .= '<div class="card"><p class="muted">No bookings match.</p></div>';
} else {
    $body .= '<div class="card wrap"><table><thead><tr><th>Booked</th><th>Name</th><th>Email</th><th>WhatsApp</th><th>Session</th>'
        . '<th>Status</th><th>Add-on</th><th>Amount</th><th>Offers</th><th>Source</th></tr></thead><tbody>';
    foreach ($list as $row) {
        [$label, $class] = admin_status($row);
        $utm = trim($row['utm_source'] . ($row['utm_campaign'] !== '' ? ' / ' . $row['utm_campaign'] : ''));
        $body .= '<tr><td>' . h(date('j M, g:i A', strtotime($row['created_at']))) . '</td>'
            . '<td>' . h($row['name']) . '</td><td>' . h($row['email']) . '</td>'
            . '<td>' . h($row['phone'] !== '' ? '+91 ' . $row['phone'] : '') . '</td>'
            . '<td>' . h(admin_session_label($row['session_at'])) . '</td>'
            . '<td><span class="pill ' . $class . '"' . ($row['fail_reason'] ? ' title="' . h($row['fail_reason']) . '"' : '') . '>' . h($label) . '</span></td>'
            . '<td>' . ($row['addon'] ? 'Yes' : '') . '</td>'
            . '<td>₹' . h(number_format($row['amount_paise'] / 100)) . ($row['coupon'] ? ' <span class="muted">coupon</span>' : '') . '</td>'
            . '<td>' . ($row['marketing_optin'] && !$row['email_unsub_at'] ? 'Yes' : '') . '</td>'
            . '<td>' . h($utm !== '' ? $utm : '—') . '</td></tr>';
    }
    $body .= '</tbody></table>'
        . (count($list) === 1000 ? '<p class="muted">Showing the newest 1,000. Export CSV for all of them.</p>' : '')
        . '</div>';
}
admin_page('Masterclass contacts', $body);
