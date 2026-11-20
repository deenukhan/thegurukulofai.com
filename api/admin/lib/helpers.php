<?php
/**
 * Small shared helpers for the dashboard: escaping, money, dates, links and queries.
 * Every page in views/ uses these, so a format changes in one place.
 */

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Whole rupees in the Indian style: 1,49,900. */
function inr(int $rupees): string
{
    $sign = $rupees < 0 ? '-' : '';
    $digits = (string) abs($rupees);
    if (strlen($digits) <= 3) {
        return $sign . $digits;
    }
    $last = substr($digits, -3);
    $rest = substr($digits, 0, -3);
    $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
    return $sign . $rest . ',' . $last;
}

/** Paise as whole rupees, rounded: ₹1,499. Downloads keep the exact paise. */
function money(int $paise): string
{
    return ($paise < 0 ? '-' : '') . '₹' . inr((int) round(abs($paise) / 100));
}

/** A count in the Indian style: 1,234 or 1,23,456. */
function num(int $n): string
{
    return inr($n);
}

/** "42%" or "4.2%", or a dash when there is nothing to divide by. */
function pct(float $part, float $whole): string
{
    if ($whole <= 0) {
        return '—';
    }
    $p = $part / $whole * 100;
    return ($p > 0 && $p < 10 ? number_format($p, 1) : (string) round($p)) . '%';
}

function ist(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone('Asia/Kolkata');
}

/** A stored IST time as text, such as "20 Nov, 3:05 PM". */
function when(?string $at, string $format = 'j M, g:i A'): string
{
    if ($at === null || $at === '') {
        return '—';
    }
    return (new DateTimeImmutable($at, ist()))->format($format);
}

function session_label(?string $at): string
{
    return when($at, 'D j M, g A');
}

/** "4 min ago", "3 h ago", "2 days ago". */
function ago(int $unix, ?int $now = null): string
{
    $s = max(0, ($now ?? time()) - $unix);
    return match (true) {
        $s < 60     => 'just now',
        $s < 3600   => floor($s / 60) . ' min ago',
        $s < 86400  => floor($s / 3600) . ' h ago',
        $s < 172800 => 'yesterday',
        default     => floor($s / 86400) . ' days ago',
    };
}

/** "in 2 days, 4 h" or "in 35 min". */
function until(int $unix, ?int $now = null): string
{
    $s = $unix - ($now ?? time());
    if ($s <= 0) {
        return 'now';
    }
    $days = intdiv($s, 86400);
    $hours = intdiv($s % 86400, 3600);
    $mins = intdiv($s % 3600, 60);
    if ($days > 0) {
        return 'in ' . $days . ' day' . ($days === 1 ? '' : 's') . ($hours ? ', ' . $hours . ' h' : '');
    }
    return 'in ' . ($hours ? $hours . ' h ' : '') . $mins . ' min';
}

/** A link inside the dashboard. Empty values are dropped. */
function admin_url(array $params): string
{
    $params = array_filter($params, static fn($v) => $v !== null && $v !== '' && $v !== false);
    return './' . ($params ? '?' . http_build_query($params) : '');
}

/** A WhatsApp chat link for an Indian number, or null when the number looks wrong. */
function wa_link(string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) === 10) {
        $digits = '91' . $digits;
    }
    return preg_match('/^91\d{10}$/', $digits) ? 'https://wa.me/' . $digits : null;
}

function phone_label(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone);
    return strlen($digits) === 10 ? '+91 ' . substr($digits, 0, 5) . ' ' . substr($digits, 5) : $phone;
}

/** The periods a page can be looked at by. */
const ADMIN_RANGES = [
    'today' => ['label' => 'Today', 'days' => 1],
    '7d'    => ['label' => '7 days', 'days' => 7],
    '30d'   => ['label' => '30 days', 'days' => 30],
    '90d'   => ['label' => '90 days', 'days' => 90],
    'all'   => ['label' => 'All time', 'days' => null],
];

/** The chosen period, with its first moment as an IST timestamp string (null for all time). */
function admin_range(string $key, int $now): array
{
    $key = array_key_exists($key, ADMIN_RANGES) ? $key : '30d';
    $days = ADMIN_RANGES[$key]['days'];
    $start = $days === null ? null
        : (new DateTimeImmutable('@' . $now))->setTimezone(ist())->setTime(0, 0)->modify('-' . ($days - 1) . ' days');
    return [
        'key'   => $key,
        'label' => ADMIN_RANGES[$key]['label'],
        'days'  => $days,
        'from'  => $start?->format('Y-m-d H:i:s'),
        'day'   => $start?->format('Y-m-d'),
    ];
}

/** Every row of a query. */
function rows(PDO $pdo, string $sql, array $args = []): array
{
    $q = $pdo->prepare($sql);
    $q->execute($args);
    return $q->fetchAll();
}

/** The first row of a query, or an empty array. */
function row(PDO $pdo, string $sql, array $args = []): array
{
    $q = $pdo->prepare($sql);
    $q->execute($args);
    return $q->fetch() ?: [];
}

/** The first value of a query. */
function value(PDO $pdo, string $sql, array $args = [])
{
    $q = $pdo->prepare($sql);
    $q->execute($args);
    return $q->fetchColumn();
}

/** The SQL condition and arguments for "this happened inside the chosen period". */
function in_range(array $range, string $column): array
{
    return $range['from'] === null ? ['1 = 1', []] : [$column . ' >= ?', [$range['from']]];
}

/** Sends a CSV download and stops. A cell that starts like a formula is made safe for Excel. */
function send_csv(string $filename, array $header, array $lines): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // so Excel reads the names correctly
    $safe = static fn($v): string => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
    fputcsv($out, $header, ',', '"', '');
    foreach ($lines as $line) {
        fputcsv($out, array_map($safe, $line), ',', '"', '');
    }
    fclose($out);
    exit;
}
