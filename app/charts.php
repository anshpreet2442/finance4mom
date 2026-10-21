<?php
declare(strict_types=1);
/** Dependency-free SVG/HTML charts. Values are minor units; labels use fmt_short(). Hover shows exact amounts via <title>. */

const CHART_COLORS = ['lime' => 'var(--c-lime)', 'yellow' => 'var(--c-yellow)', 'orange' => 'var(--c-orange)', 'dark' => 'var(--c-dark)', 'grey' => 'var(--c-grey)', 'teal' => 'var(--c-teal)', 'plum' => 'var(--c-plum)'];
const CHART_ORDER = ['lime', 'orange', 'yellow', 'dark', 'teal', 'plum', 'grey'];

function chart_id(): string { static $n = 0; return 'ch' . (++$n); }
function nice_step(float $max, int $ticks = 4): float {
    if ($max <= 0) { return 100.0; }
    $raw = $max / $ticks; $mag = 10 ** floor(log10($raw)); $n = $raw / $mag;
    return ($n <= 1 ? 1 : ($n <= 2 ? 2 : ($n <= 2.5 ? 2.5 : ($n <= 5 ? 5 : 10)))) * $mag;
}
function chart_empty(string $text, int $h = 200): string { return '<div class="grid place-items-center text-sm text-mute" style="height:' . $h . 'px">' . e($text) . '</div>'; }

/** Hatched definitions reused by the bar charts. */
function chart_defs(string $id): string {
    $o = '<defs>';
    foreach (['lime', 'orange', 'yellow', 'dark', 'teal', 'plum', 'grey'] as $c) {
        $o .= '<pattern id="' . $id . '-' . $c . '" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="6" height="6" fill="' . CHART_COLORS[$c] . '"/><rect width="2" height="6" fill="#fff" opacity=".28"/></pattern>';
    }
    return $o . '</defs>';
}

/** Grouped column chart. $groups: [['label'=>'May', 'values'=>[n1,n2]]]; $series: [['name'=>'Income','color'=>'lime']] */
function chart_bars(array $groups, array $series, int $h = 260, bool $hatch = true): string {
    $max = 0; foreach ($groups as $g) { foreach ($g['values'] as $v) { $max = max($max, $v); } }
    if ($max <= 0) { return chart_empty('Nothing to show yet.', $h); }
    $id = chart_id(); $W = 640; $padL = 46; $padR = 8; $padT = 10; $padB = 28; $plotW = $W - $padL - $padR; $plotH = $h - $padT - $padB;
    $step = nice_step($max / 100) * 100; $top = ceil($max / $step) * $step; $n = count($groups); $S = count($series);
    $gw = $plotW / $n; $bw = min(30, $gw * 0.72 / $S);
    $o = '<svg viewBox="0 0 ' . $W . ' ' . $h . '" class="w-full" role="img" aria-label="' . e(implode(', ', array_column($series, 'name'))) . ' by period">' . chart_defs($id);
    for ($t = 0; $t * $step <= $top + 0.1; $t++) {
        $y = $padT + $plotH - ($t * $step / $top) * $plotH;
        $o .= '<line x1="' . $padL . '" x2="' . ($W - $padR) . '" y1="' . round($y, 1) . '" y2="' . round($y, 1) . '" stroke="var(--line)" stroke-dasharray="' . ($t ? '3 4' : '0') . '"/>'
            . '<text x="' . ($padL - 8) . '" y="' . round($y + 4, 1) . '" text-anchor="end" class="fill-mute" font-size="11">' . e(fmt_short((int) round($t * $step))) . '</text>';
    }
    foreach ($groups as $i => $g) {
        $gx = $padL + $i * $gw + ($gw - $bw * $S - 3 * ($S - 1)) / 2;
        foreach ($g['values'] as $j => $v) {
            $bh = $top > 0 ? $v / $top * $plotH : 0; $x = $gx + $j * ($bw + 3); $y = $padT + $plotH - $bh; $c = $series[$j]['color'];
            if ($bh > 0.5) { $o .= '<rect class="bar" style="--bd:' . ($i * 45 + $j * 20) . 'ms" x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($bw, 1) . '" height="' . round($bh, 1) . '" rx="6" fill="' . ($hatch ? 'url(#' . $id . '-' . $c . ')' : CHART_COLORS[$c]) . '"><title>' . e($g['label'] . ' · ' . $series[$j]['name'] . ': ' . fmt((int) $v)) . '</title></rect>'; }
        }
        $o .= '<text x="' . round($padL + $i * $gw + $gw / 2, 1) . '" y="' . ($h - 8) . '" text-anchor="middle" class="fill-mute" font-size="12">' . e($g['label']) . '</text>';
    }
    return $o . '</svg>' . chart_legend($series);
}
function chart_legend(array $series): string {
    if (count($series) < 2) { return ''; }
    $o = '<ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-mute">';
    foreach ($series as $s) { $o .= '<li class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm" style="background:' . CHART_COLORS[$s['color']] . '"></span>' . e($s['name']) . '</li>'; }
    return $o . '</ul>';
}

/** Multi-series line/area chart with negative support. $series: [['name','color','values'=>[...]]], $labels: [...] */
function chart_lines(array $labels, array $series, int $h = 240, bool $area = false): string {
    $all = []; foreach ($series as $s) { foreach ($s['values'] as $v) { $all[] = $v; } }
    if (!$all || (max($all) === 0 && min($all) === 0)) { return chart_empty('Nothing to show yet.', $h); }
    $id = chart_id(); $W = 640; $padL = 46; $padR = 12; $padT = 12; $padB = 28; $plotW = $W - $padL - $padR; $plotH = $h - $padT - $padB;
    $hi = max(max($all), 0); $lo = min(min($all), 0); $step = nice_step(($hi - $lo) / 100) * 100;
    $top = ceil($hi / $step) * $step; $bot = floor($lo / $step) * $step; if ($top === $bot) { $top = $bot + $step; }
    $y = fn($v) => $padT + $plotH - (($v - $bot) / ($top - $bot)) * $plotH; $n = count($labels); $x = fn($i) => $padL + ($n > 1 ? $i / ($n - 1) : 0.5) * $plotW;
    $o = '<svg viewBox="0 0 ' . $W . ' ' . $h . '" class="w-full" role="img" aria-label="' . e(implode(', ', array_column($series, 'name'))) . ' over time"><defs>';
    foreach ($series as $k => $s) { $o .= '<linearGradient id="' . $id . $k . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="' . CHART_COLORS[$s['color']] . '" stop-opacity=".35"/><stop offset="100%" stop-color="' . CHART_COLORS[$s['color']] . '" stop-opacity="0"/></linearGradient>'; }
    $o .= '</defs>';
    for ($v = $bot; $v <= $top + 0.1; $v += $step) { $yy = round($y($v), 1); $o .= '<line x1="' . $padL . '" x2="' . ($W - $padR) . '" y1="' . $yy . '" y2="' . $yy . '" stroke="var(--line)" stroke-dasharray="' . ($v == 0 ? '0' : '3 4') . '"/><text x="' . ($padL - 8) . '" y="' . ($yy + 4) . '" text-anchor="end" class="fill-mute" font-size="11">' . e(fmt_short((int) round($v))) . '</text>'; }
    foreach ($labels as $i => $l) { if ($n <= 8 || $i % (int) ceil($n / 8) === 0) { $o .= '<text x="' . round($x($i), 1) . '" y="' . ($h - 8) . '" text-anchor="middle" class="fill-mute" font-size="12">' . e($l) . '</text>'; } }
    foreach ($series as $k => $s) {
        $pts = []; foreach ($s['values'] as $i => $v) { $pts[] = round($x($i), 1) . ',' . round($y($v), 1); }
        if ($area) { $o .= '<polygon points="' . $pts[0] . ' ' . implode(' ', $pts) . ' ' . round($x(count($pts) - 1), 1) . ',' . round($y(0), 1) . ' ' . round($x(0), 1) . ',' . round($y(0), 1) . '" fill="url(#' . $id . $k . ')" class="area-in"/>'; }
         $o .= '<polyline pathLength="1" class="draw" points="' . implode(' ', $pts) . '" fill="none" stroke="' . CHART_COLORS[$s['color']] . '" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>';
        foreach ($s['values'] as $i => $v) { $o .= '<circle cx="' . round($x($i), 1) . '" cy="' . round($y($v), 1) . '" r="4" fill="var(--card)" stroke="' . CHART_COLORS[$s['color']] . '" stroke-width="2"><title>' . e($labels[$i] . ' · ' . $s['name'] . ': ' . fmt((int) $v)) . '</title></circle>'; }
    }
    return $o . '</svg>' . chart_legend($series);
}

/** Semicircle gauge. $pct 0..100. */
function chart_gauge(float $pct, string $center, string $caption): string {
    $id = chart_id(); $pct = max(0, min(100, $pct)); $r = 96; $cx = 120; $cy = 120; $sw = 26;
    $arc = fn($from, $to) => (function () use ($from, $to, $r, $cx, $cy) {
        $a1 = M_PI * (1 - $from / 100); $a2 = M_PI * (1 - $to / 100);
        return 'M' . round($cx + $r * cos($a1), 2) . ' ' . round($cy - $r * sin($a1), 2) . ' A' . $r . ' ' . $r . ' 0 0 1 ' . round($cx + $r * cos($a2), 2) . ' ' . round($cy - $r * sin($a2), 2);
    })();
    $o = '<svg viewBox="0 0 240 140" class="mx-auto w-full max-w-[280px]" role="img" aria-label="' . e($caption . ': ' . $center) . '">' . chart_defs($id);
    $o .= '<path d="' . $arc(0, 100) . '" fill="none" stroke="var(--soft)" stroke-width="' . $sw . '" stroke-linecap="round"/>';
    if ($pct > 0.5) {  $o .= '<path pathLength="1" class="draw" d="' . $arc(0, $pct) . '" fill="none" stroke="url(#' . $id . '-lime)" stroke-width="' . $sw . '" stroke-linecap="round"/>'; }
    return $o . '<text x="120" y="108" text-anchor="middle" class="fill-ink" font-size="30" font-weight="600">' . e($center) . '</text><text x="120" y="128" text-anchor="middle" class="fill-mute" font-size="11">' . e($caption) . '</text></svg>';
}

/** Segmented bar + legend (cost analysis). $items: [['name','value']] */
function chart_segbar(array $items, int $limit = 6): string {
    $total = array_sum(array_column($items, 'value')); if ($total <= 0) { return '<p class="py-6 text-center text-sm text-mute">No spending this month.</p>'; }
    if (count($items) > $limit) { $rest = array_slice($items, $limit - 1); $items = array_slice($items, 0, $limit - 1); $items[] = ['name' => 'Other', 'value' => array_sum(array_column($rest, 'value'))]; }
    $cols = ['lime', 'orange', 'yellow', 'dark', 'teal', 'plum']; $bar = '<div class="flex h-9 w-full gap-1 overflow-hidden rounded-xl" role="img" aria-label="Spending split by category">'; $leg = '<ul class="mt-4 space-y-2 text-sm">';
    foreach ($items as $i => $it) {
        $pct = $it['value'] / $total * 100; $c = CHART_COLORS[$cols[$i % count($cols)]];
        $bar .= '<span class="h-full hatch-seg" style="width:' . number_format($pct, 2, '.', '') . '%;--seg:' . $c . '" title="' . e($it['name'] . ': ' . fmt($it['value'])) . '"></span>';
        $leg .= '<li class="flex items-center gap-2"><span class="size-3 rounded-[4px]" style="background:' . $c . '"></span><span class="flex-1 truncate">' . e($it['name']) . '</span><span class="num text-mute">' . round($pct) . '%</span></li>';
    }
    return $bar . '</div>' . $leg . '</ul>';
}

/** Horizontal paired bars: [['name','a','b']] with labels for a and b. */
function chart_hbars(array $rows, string $aLabel, string $bLabel, bool $flagOver = true): string {
    if (!$rows) { return '<p class="py-6 text-center text-sm text-mute">Nothing to compare yet.</p>'; }
    $max = 1; foreach ($rows as $r) { $max = max($max, $r['a'], $r['b']); }
    $o = '<ul class="space-y-3">';
    foreach ($rows as $r) {
        $over = $flagOver && $r['a'] > 0 && $r['b'] > $r['a'];
        $o .= '<li><div class="mb-1 flex justify-between gap-2 text-sm"><span class="truncate font-medium">' . e($r['name']) . '</span><span class="num shrink-0 text-xs text-mute">' . e(fmt($r['b'])) . ' / ' . e(fmt($r['a'])) . '</span></div>'
            . '<div class="h-2 overflow-hidden rounded-full bg-soft" title="' . e($aLabel . ': ' . fmt($r['a'])) . '"><div class="h-full rounded-full bg-line-strong" style="width:' . round($r['a'] / $max * 100, 1) . '%"></div></div>'
            . '<div class="mt-1 h-2 overflow-hidden rounded-full bg-soft" title="' . e($bLabel . ': ' . fmt($r['b'])) . '"><div class="h-full rounded-full ' . ($over ? 'bar-neg' : 'bar-lime') . '" style="width:' . round($r['b'] / $max * 100, 1) . '%"></div></div></li>';
    }
    return $o . '</ul><p class="mt-3 flex gap-4 text-xs text-mute"><span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-line-strong"></span>' . e($aLabel) . '</span><span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm" style="background:var(--c-lime)"></span>' . e($bLabel) . '</span></p>';
}
