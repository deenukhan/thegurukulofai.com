<?php
/**
 * Numbers more than one page needs, worked out the same way everywhere.
 * A paid seat is a booking with status "paid"; money is in paise.
 */

/** Sales inside the period: seats, add-ons, coupons, gross, known fees and refunds. */
function m_sales(array $d): array
{
    [$cond, $args] = in_range($d['range'], 'paid_at');
    $r = row($d['pdo'], "SELECT COUNT(*) AS seats, COALESCE(SUM(addon), 0) AS addons, COALESCE(SUM(coupon), 0) AS coupons,
            COALESCE(SUM(amount_paise), 0) AS gross, COALESCE(SUM(fee_paise), 0) AS fees,
            COALESCE(SUM(fee_paise IS NOT NULL), 0) AS fee_known, COALESCE(SUM(refunded_paise), 0) AS refunded
        FROM bookings WHERE product = ? AND status = 'paid' AND {$cond}", array_merge([$d['product']], $args));
    return array_map('intval', $r);
}

/** The days a chart covers: the period, or the last 14 days for "today", or since the first sale. */
function m_chart_days(array $d): array
{
    $today = (new DateTimeImmutable('@' . $d['now']))->setTimezone(ist())->setTime(0, 0);
    $days = $d['range']['days'];
    if ($days === null) {
        $first = value($d['pdo'], "SELECT MIN(DATE(paid_at)) FROM bookings WHERE product = ? AND status = 'paid'", [$d['product']]);
        $start = $first ? new DateTimeImmutable($first, ist()) : $today->modify('-13 days');
        $days = min(180, (int) $start->diff($today)->days + 1);
    } elseif ($days < 7) {
        $days = 14;
    }
    return [$today->modify('-' . ($days - 1) . ' days'), $days];
}

/** Revenue per day as chart points. */
function m_daily_revenue(array $d): array
{
    [$start, $days] = m_chart_days($d);
    $byDay = [];
    foreach (rows($d['pdo'], "SELECT DATE(paid_at) AS day, COUNT(*) AS seats, SUM(amount_paise) AS gross
        FROM bookings WHERE product = ? AND status = 'paid' AND paid_at >= ? GROUP BY DATE(paid_at)",
        [$d['product'], $start->format('Y-m-d 00:00:00')]) as $r) {
        $byDay[$r['day']] = $r;
    }
    $points = [];
    for ($i = 0; $i < $days; $i++) {
        $day = $start->modify('+' . $i . ' days');
        $r = $byDay[$day->format('Y-m-d')] ?? ['seats' => 0, 'gross' => 0];
        $points[] = [
            'label' => $day->format('j M'),
            'value' => (int) $r['gross'],
            'tip'   => $day->format('D j M') . ': ' . money((int) $r['gross']) . ' · ' . (int) $r['seats'] . ' seat' . ((int) $r['seats'] === 1 ? '' : 's'),
        ];
    }
    return $points;
}

/** Axis text for money: ₹0, ₹5K, ₹1.5L. */
function m_axis_money(float $paise): string
{
    $rupees = $paise / 100;
    return match (true) {
        $rupees >= 100000 => '₹' . rtrim(rtrim(number_format($rupees / 100000, 1), '0'), '.') . 'L',
        $rupees >= 1000   => '₹' . rtrim(rtrim(number_format($rupees / 1000, 1), '0'), '.') . 'K',
        default           => '₹' . round($rupees),
    };
}

/** The funnel inside the period: visits, pop-ups, forms, payment windows, paid. */
function m_funnel(array $d): array
{
    $day = $d['range']['day'];
    $events = rows($d['pdo'], 'SELECT name, SUM(hits) AS n FROM events' . ($day ? ' WHERE day >= ?' : '') . ' GROUP BY name', $day ? [$day] : []);
    $hits = array_column($events, 'n', 'name');
    [$cond, $args] = in_range($d['range'], 'created_at');
    $b = row($d['pdo'], "SELECT COUNT(*) AS forms, COALESCE(SUM(order_id IS NOT NULL), 0) AS orders, COALESCE(SUM(status = 'paid'), 0) AS paid
        FROM bookings WHERE product = ? AND {$cond}", array_merge([$d['product']], $args));
    return [
        'visits' => (int) ($hits['view'] ?? 0),
        'popups' => (int) ($hits['popup'] ?? 0),
        'forms'  => (int) ($b['forms'] ?? 0),
        'orders' => (int) ($b['orders'] ?? 0),
        'paid'   => (int) ($b['paid'] ?? 0),
        'since'  => value($d['pdo'], 'SELECT MIN(day) FROM events') ?: null,
    ];
}

/** The next session: when it is, when sales close and how many seats are booked. */
function m_next_session(array $d): array
{
    $next = rp_next_session($d['now']);
    $at = $next->format('Y-m-d H:i:s');
    $r = row($d['pdo'], "SELECT COUNT(*) AS seats, COALESCE(SUM(addon), 0) AS addons, COALESCE(SUM(amount_paise), 0) AS gross
        FROM bookings WHERE product = ? AND status = 'paid' AND session_at = ?", [$d['product'], $at]);
    return [
        'at'       => $at,
        'unix'     => $next->getTimestamp(),
        'close'    => $next->getTimestamp() - 15 * 60,
        'seats'    => (int) $r['seats'],
        'addons'   => (int) $r['addons'],
        'gross'    => (int) $r['gross'],
        'zoom_set' => tg_zoom_url($d['config']) !== null,
    ];
}

/** How a booking reads to a person. A checkout still open under 45 minutes isn't abandoned yet. */
function m_status_pill(array $b, int $now): string
{
    return match ($b['status']) {
        'paid'         => ui_pill('Paid', 'good'),
        'failed'       => ui_pill('Payment failed', 'bad'),
        'order_failed' => ui_pill('Order error', 'bad'),
        default        => (new DateTimeImmutable($b['created_at'], ist()))->getTimestamp() > $now - 45 * 60
            ? ui_pill('Checking out', 'info') : ui_pill('Not paid', 'warn'),
    };
}

/** The ad a booking came from, as short text. */
function m_source(array $b): string
{
    $parts = array_filter([$b['utm_campaign'] ?? '', $b['utm_content'] ?? '']);
    if ($parts) {
        return implode(' · ', $parts);
    }
    return ($b['utm_source'] ?? '') !== '' ? $b['utm_source'] : 'Direct';
}

/** A person's name linking to their page. */
function m_person(array $b): string
{
    $name = trim((string) ($b['name'] ?? '')) !== '' ? $b['name'] : $b['email'];
    return '<a class="person" href="' . h(admin_url(['view' => 'people', 'id' => $b['contact_id']])) . '">' . h($name) . '</a>';
}

/** A small WhatsApp chat link, or the plain number if it can't make one. */
function m_whatsapp(string $phone): string
{
    $link = wa_link($phone);
    if ($phone === '') {
        return '<span class="muted">—</span>';
    }
    return $link === null ? h($phone)
        : '<a class="wa" href="' . h($link) . '" target="_blank" rel="noopener noreferrer">' . icon('whatsapp') . h(phone_label($phone)) . '</a>';
}

/** A reminder email's state as a label. */
/** "Day-before reminder" with a small Email or WhatsApp tag, for any outbox row. */
function m_message_label(array $config, array $o): string
{
    if (($o['channel'] ?? 'email') === 'whatsapp') {
        // A template written on the dashboard is stored as c:<id>; the send log joins its name.
        $label = str_starts_with((string) $o['template'], 'c:') ? (string) ($o['custom_name'] ?? 'Your template')
            : (tg_wa_templates($config)[$o['template']]['label'] ?? $o['template']);
        return h($label) . ' ' . ui_pill('WhatsApp', 'good');
    }
    $label = $o['template'] === 'digest' ? 'Daily summary' : (TG_REMINDERS[$o['template']]['label'] ?? $o['template']);
    return h($label) . ' ' . ui_pill('Email', 'neutral');
}

/** Status, plus WhatsApp's delivered and read ticks once Meta reports them. */
function m_message_status(array $o): string
{
    if (($o['status'] ?? '') === 'sent' && !empty($o['read_at'])) {
        return ui_pill('Read', 'good');
    }
    if (($o['status'] ?? '') === 'sent' && !empty($o['delivered_at'])) {
        return ui_pill('Delivered', 'good');
    }
    return m_outbox_pill((string) $o['status']);
}

function m_outbox_pill(string $status): string
{
    return match ($status) {
        'sent'      => ui_pill('Sent', 'good'),
        'queued'    => ui_pill('Waiting', 'info'),
        'sending'   => ui_pill('Sending', 'info'),
        'failed'    => ui_pill('Failed', 'bad'),
        'expired'   => ui_pill('Missed', 'bad'),
        'cancelled' => ui_pill('Stopped', 'neutral'),
        default     => ui_pill($status, 'neutral'),
    };
}
