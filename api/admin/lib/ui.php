<?php
/**
 * The dashboard's building blocks. Every page is made only from these, so the
 * whole dashboard looks and behaves the same, and a new page is quick to add.
 *
 * Callers pass text already escaped with h() wherever a parameter says "html".
 */

/** Line icons, 20px, drawn with the text colour. */
function icon(string $name): string
{
    $paths = [
        'home'     => '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
        'rupee'    => '<path d="M6 4h12M6 9h12M6 4h3a5 5 0 0 1 0 10H6l9 7"/>',
        'funnel'   => '<path d="M3 4h18l-7 9v6l-4 2v-8z"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M18 14.2a6.5 6.5 0 0 1 3.5 5.8"/>',
        'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'megaphone'=> '<path d="M3 10v4a1 1 0 0 0 1 1h3l9 5V4L7 9H4a1 1 0 0 0-1 1zM19 9a4 4 0 0 1 0 6"/>',
        'pulse'    => '<path d="M3 12h4l2-6 4 12 2-6h6"/>',
        'list'     => '<path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>',
        'logout'   => '<path d="M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4M10 17l5-5-5-5M15 12H3"/>',
        'download' => '<path d="M12 4v11M7 10l5 5 5-5M4 20h16"/>',
        'whatsapp' => '<path d="M4 20l1.3-3.9A8 8 0 1 1 8 18.8z"/><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5l1.2-1.4-2-1-.9.8a4 4 0 0 1-2.2-2.2l.8-.9-1-2z"/>',
        'check'    => '<path d="m5 12 5 5 9-10"/>',
        'alert'    => '<path d="M12 3 2 20h20zM12 10v4M12 17h.01"/>',
        'x'        => '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/>',
        'info'     => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'back'     => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'search'   => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
        'sliders'  => '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
        'send'     => '<path d="M21 3 10 14M21 3l-7 18-4-7-7-4z"/>',
        'video'    => '<rect x="3" y="6" width="13" height="12" rx="2"/><path d="m16 10 5-3v10l-5-3z"/>',
        'tag'      => '<path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9z"/><circle cx="8" cy="8" r="1.5"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">'
        . ($paths[$name] ?? '') . '</svg>';
}

/**
 * A row of headline numbers. Each item: label, value (html), and optional
 * note (text) with tone good, bad or warn.
 */
function ui_kpis(array $items): string
{
    $html = '<div class="kpis">';
    foreach ($items as $k) {
        $tone = isset($k['tone']) ? ' tone-' . $k['tone'] : '';
        $html .= '<div class="kpi"><div class="kpi-label">' . h($k['label']) . '</div>'
            . '<div class="kpi-value">' . $k['value'] . '</div>'
            . (isset($k['note']) && $k['note'] !== '' ? '<div class="kpi-note' . $tone . '">' . h($k['note']) . '</div>' : '')
            . '</div>';
    }
    return $html . '</div>';
}

/**
 * A white card. Options: sub (text under the title), action (html on the right),
 * size (third, half, two-thirds or full; half by default), flush (no inner padding, for tables).
 * 'wide' => true is the same as size full.
 */
function ui_card(string $title, string $body, array $o = []): string
{
    $size = !empty($o['wide']) ? 'full' : ($o['size'] ?? 'half');
    $classes = 'card size-' . $size . (!empty($o['flush']) ? ' flush' : '');
    $id = !empty($o['id']) ? ' id="' . h($o['id']) . '"' : '';
    $head = '';
    if ($title !== '' || !empty($o['action'])) {
        $head = '<header class="card-head"><div><h2>' . h($title) . '</h2>'
            . (!empty($o['sub']) ? '<p>' . h($o['sub']) . '</p>' : '') . '</div>'
            . ($o['action'] ?? '') . '</header>';
    }
    return '<section class="' . $classes . '"' . $id . '>' . $head . '<div class="card-body">' . $body . '</div></section>';
}

/** Cards on a six-column grid (see ui_card sizes) that drops to one column on small screens. */
function ui_grid(string ...$cards): string
{
    return '<div class="grid">' . implode('', $cards) . '</div>';
}

/**
 * A table. $cols: list of [label, class] where class "num" right-aligns numbers.
 * $rows: list of lists of html cells. Options: empty (text when there are no rows), note (html under it).
 */
function ui_table(array $cols, array $rows, array $o = []): string
{
    if (!$rows) {
        return ui_empty($o['empty'] ?? 'Nothing here yet.');
    }
    $html = '<div class="table-wrap"><table><thead><tr>';
    foreach ($cols as [$label, $class]) {
        $html .= '<th' . ($class ? ' class="' . h($class) . '"' : '') . ' scope="col">' . h($label) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $cells) {
        $html .= '<tr>';
        foreach (array_values($cells) as $i => $cell) {
            $class = $cols[$i][1] ?? '';
            $html .= '<td' . ($class ? ' class="' . h($class) . '"' : '') . '>' . $cell . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';
    return $html . (!empty($o['note']) ? '<p class="table-note">' . $o['note'] . '</p>' : '');
}

/** A short status label. Tones: good, warn, bad, info, neutral. */
function ui_pill(string $text, string $tone = 'neutral'): string
{
    return '<span class="pill pill-' . h($tone) . '">' . h($text) . '</span>';
}

/** A quiet "nothing here" message, with an optional next step (html). */
function ui_empty(string $title, string $text = '', string $action = ''): string
{
    return '<div class="empty"><p class="empty-title">' . h($title) . '</p>'
        . ($text !== '' ? '<p>' . h($text) . '</p>' : '') . $action . '</div>';
}

/**
 * Columns over time. Each point: label, value, tip (text shown on hover).
 * $format turns a value into axis text.
 */
function ui_columns(array $points, callable $format): string
{
    $max = $points ? max(array_column($points, 'value')) : 0;
    if ($max <= 0) {
        return ui_empty('Nothing in this period yet.');
    }
    [$top, $step] = nice_scale((float) $max);
    $n = count($points);
    $every = max(1, (int) ceil($n / 7));

    $grid = '';
    for ($v = 0.0; $v <= $top + 1e-9; $v += $step) {
        $grid .= '<div class="cc-tick" style="bottom:' . round($v / $top * 100, 3) . '%"><span>' . h($format($v)) . '</span></div>';
    }
    $cols = '';
    $labels = '';
    foreach ($points as $i => $p) {
        $height = $p['value'] > 0 ? max(1.5, $p['value'] / $top * 100) : 0;
        $cols .= '<div class="cc-col" tabindex="0" data-tip="' . h($p['tip']) . '" aria-label="' . h($p['tip']) . '">'
            . ($height > 0 ? '<span class="cc-bar" style="height:' . round($height, 3) . '%"></span>' : '') . '</div>';
        if ($i % $every === 0 || $i === $n - 1 && $n <= 14) {
            $labels .= '<span style="grid-column:' . ($i + 1) . '">' . h($p['label']) . '</span>';
        }
    }
    return '<div class="cc" style="--n:' . $n . '"><div class="cc-plot">' . $grid
        . '<div class="cc-cols">' . $cols . '</div></div><div class="cc-x">' . $labels . '</div></div>';
}

/** A clean axis top and step (1, 2 or 5 times a power of ten) with about four lines. */
function nice_scale(float $max): array
{
    $raw = $max / 4;
    $power = 10 ** floor(log10(max($raw, 1e-9)));
    foreach ([1, 2, 2.5, 5, 10] as $m) {
        if ($m * $power >= $raw) {
            $step = $m * $power;
            break;
        }
    }
    $step = max($step ?? 1, 1);
    return [ceil($max / $step) * $step ?: $step, $step];
}

/**
 * Horizontal bars, for comparing a few named things. Each row: label (text),
 * value (number), display (text at the end) and optional sub (text) and href.
 */
function ui_bars(array $rows, ?float $max = null): string
{
    if (!$rows) {
        return ui_empty('Nothing to show yet.');
    }
    $max ??= max(array_column($rows, 'value')) ?: 1;
    $html = '<div class="bars">';
    foreach ($rows as $r) {
        $width = $max > 0 ? $r['value'] / $max * 100 : 0;
        $label = h($r['label']);
        if (!empty($r['href'])) {
            $label = '<a href="' . h($r['href']) . '">' . $label . '</a>';
        }
        $html .= '<div class="bar-row"><div class="bar-label">' . $label
            . (!empty($r['sub']) ? '<small>' . h($r['sub']) . '</small>' : '') . '</div>'
            . '<div class="bar-track"><span style="width:' . round($width, 2) . '%"></span></div>'
            . '<div class="bar-value">' . h($r['display']) . '</div></div>';
    }
    return $html . '</div>';
}

/** Label and value pairs. Values are html. */
function ui_facts(array $pairs): string
{
    $html = '<dl class="facts">';
    foreach ($pairs as [$label, $value]) {
        $html .= '<div><dt>' . h($label) . '</dt><dd>' . $value . '</dd></div>';
    }
    return $html . '</dl>';
}

/** Filter chips: one link per option, the current one marked. $base holds the other query values. */
function ui_chips(array $options, string $current, string $param, array $base): string
{
    // Each link ends in #f-<param>, so the page reopens at these chips, not at the top.
    $html = '<nav class="chips" id="f-' . h($param) . '" aria-label="Filter">';
    foreach ($options as $key => $label) {
        $on = (string) $key === $current;
        $html .= '<a class="chip' . ($on ? ' on' : '') . '"' . ($on ? ' aria-current="true"' : '')
            . ' href="' . h(admin_url([$param => $key] + $base)) . '#f-' . h($param) . '">' . h($label) . '</a>';
    }
    return $html . '</nav>';
}

/** A search box that keeps the page's other filters. */
function ui_search(string $value, array $base, string $placeholder): string
{
    $hidden = '';
    foreach ($base as $k => $v) {
        if ($v !== '' && $v !== null) {
            $hidden .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
        }
    }
    return '<form class="search" id="search" action="./#search" method="get" role="search">' . $hidden . icon('search')
        . '<input type="search" name="q" value="' . h($value) . '" placeholder="' . h($placeholder) . '" aria-label="' . h($placeholder) . '">'
        . '</form>';
}

/** A button-styled link. Kinds: primary, quiet. */
function ui_button(string $label, string $href, string $kind = 'quiet', string $iconName = ''): string
{
    // A forward arrow reads after the words; every other icon goes before them.
    $after = $iconName === 'arrow';
    return '<a class="btn btn-' . h($kind) . '" href="' . h($href) . '">' . ($iconName && !$after ? icon($iconName) : '') . h($label)
        . ($after ? icon($iconName) : '') . '</a>';
}

/** A list of checks: each has level (ok, warn, bad), title, detail and an optional view to open. */
function ui_checks(array $checks): string
{
    $icons = ['ok' => 'check', 'warn' => 'alert', 'bad' => 'x', 'info' => 'info'];
    $here = (string) ($_GET['view'] ?? '');
    $html = '<ul class="checks">';
    foreach ($checks as $c) {
        $html .= '<li class="check check-' . h($c['level']) . '">' . icon($icons[$c['level']] ?? 'info')
            . '<div><p class="check-title">' . h($c['title']) . '</p>'
            . ($c['detail'] !== '' ? '<p class="check-detail">' . h($c['detail']) . '</p>' : '') . '</div>'
            . (!empty($c['view']) && $c['view'] !== $here ? '<a class="check-link" href="' . h(admin_url(['view' => $c['view']])) . '">Open' . icon('arrow') . '</a>' : '')
            . '</li>';
    }
    return $html . '</ul>';
}

/** One email as the reader sees it. $mail: subject, body, and optional to, from, note. */
function ui_mail(array $mail): string
{
    $meta = '';
    foreach (['From' => $mail['from'] ?? '', 'To' => $mail['to'] ?? '', 'Attached' => $mail['attached'] ?? ''] as $k => $v) {
        if ($v !== '') {
            $meta .= '<div><span>' . h($k) . '</span>' . h($v) . '</div>';
        }
    }
    $body = h(str_replace("\r\n", "\n", $mail['body']));
    $body = preg_replace('~(https?://[^\s<]+)~', '<span class="mail-link">$1</span>', $body);
    return '<article class="mail"><header><p class="mail-subject">' . h($mail['subject']) . '</p>'
        . ($meta !== '' ? '<div class="mail-meta">' . $meta . '</div>' : '') . '</header>'
        . '<pre class="mail-body">' . $body . '</pre></article>';
}

/** The current page's address, so a change can come back to it. */
/** This page's address, without one-time steps (a confirm box, a download), to come back to after a change. */
function admin_here(): string
{
    return admin_url(array_diff_key($_GET, ['undo' => 1, 'resend' => 1, 'export' => 1, 'delete' => 1]));
}

/**
 * A small form that changes something. $inner holds its fields (html). It always
 * carries the page token and comes back to the current page. $hidden['#'] names the
 * element id to come back to (a row or card), so the page doesn't jump to the top.
 */
function ui_post(string $action, array $hidden, string $inner, string $button, string $kind = 'quiet', string $class = ''): string
{
    $anchor = (string) ($hidden['#'] ?? '');
    unset($hidden['#']);
    $fields = '<input type="hidden" name="action" value="' . h($action) . '">'
        . '<input type="hidden" name="csrf" value="' . h(admin_token()) . '">'
        . '<input type="hidden" name="back" value="' . h(admin_here() . ($anchor !== '' ? '#' . $anchor : '')) . '">';
    foreach ($hidden as $k => $v) {
        $fields .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    }
    return '<form method="post" class="' . h(trim('post ' . $class)) . '">' . $fields . $inner
        . '<button type="submit" class="btn btn-' . h($kind) . '">' . h($button) . '</button></form>';
}

/** A labelled input. $attrs is html (type, value and so on, already escaped). */
function ui_field(string $label, string $name, string $attrs = '', string $hint = ''): string
{
    return '<label class="field"><span>' . h($label) . '</span><input name="' . h($name) . '" ' . $attrs . '>'
        . ($hint !== '' ? '<small>' . h($hint) . '</small>' : '') . '</label>';
}

/**
 * A row of choice chips (radio buttons): pick one. $options maps value => label.
 * A form can show or hide other fields by the choice with CSS alone (see .make in admin.css).
 */
function ui_choice(string $label, string $name, array $options, string $current): string
{
    $html = '<fieldset class="choice"><legend>' . h($label) . '</legend><div class="choice-opts">';
    foreach ($options as $value => $text) {
        $html .= '<label><input type="radio" name="' . h($name) . '" value="' . h($value) . '"'
            . ((string) $value === $current ? ' checked' : '') . '><span>' . h($text) . '</span></label>';
    }
    return $html . '</div></fieldset>';
}

/** Text picked up whole with one click or tap, ready to copy (no JavaScript on this dashboard). */
function ui_copy(string $text): string
{
    return '<span class="copy" title="Click once to select it all, then copy">' . h($text) . '</span>';
}

/** The authenticator code every settings change asks for. */
/** The code box on settings forms. Empty while two-step sign-in is off. */
function ui_code_input(): string
{
    if (!ADMIN_TWO_STEP) {
        return '';
    }
    return ui_field('Code from your app', 'code', 'inputmode="numeric" autocomplete="one-time-code" maxlength="7" required placeholder="123 456" class="code-input"');
}
