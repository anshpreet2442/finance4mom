<?php
declare(strict_types=1);
/**
 * PNG charts for Telegram, drawn with PHP's GD (no external services). Drawn at 2x and scaled down for smooth edges.
 * tg_chart_png(['kind' => bar|line|pie|progress, 'title', 'subtitle', 'labels' => [...], 'series' => [['name', 'values' => [...]]], 'money' => bool]) returns PNG bytes.
 * tg_chart_data($uid, 'spending'|'trend'|'goals'|'balance') builds the spec from the person's own data.
 */

const TG_CHART_W = 900;
const TG_CHART_H = 560;
const TG_CHART_COLORS = ['#2563EB', '#16A34A', '#D97706', '#DC2626', '#7C3AED', '#0D9488', '#DB2777', '#64748B', '#0EA5E9'];

function tg_font(bool $bold = false): ?string {
    $n = $bold ? 'DejaVuSans-Bold.ttf' : 'DejaVuSans.ttf';
    foreach ([__DIR__ . '/../assets/fonts/' . $n, '/usr/share/fonts/truetype/dejavu/' . $n, '/usr/share/fonts/dejavu/' . $n, '/usr/share/fonts/TTF/' . $n, '/Library/Fonts/Arial.ttf', 'C:\\Windows\\Fonts\\arial.ttf'] as $f) { if (is_file($f)) { return $f; } }
    return null;
}
function tg_hex($im, string $hex, int $alpha = 0) { return imagecolorallocatealpha($im, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)), $alpha); }
function tg_short_num(float $v, bool $money): string {
    $a = abs($v); $sign = $v < 0 ? '-' : ''; $pre = $money ? (tg_font() ? '₹' : 'Rs ') : '';
    if ($a >= 1e7) { $t = round($a / 1e7, 1) . 'Cr'; } elseif ($a >= 1e5) { $t = round($a / 1e5, 1) . 'L'; } elseif ($a >= 1e3) { $t = round($a / 1e3, 1) . 'K'; } else { $t = (string) round($a, $a < 10 && $a != floor($a) ? 1 : 0); }
    return $sign . $pre . $t;
}
/** Draws text. $anchor: l, c, r (horizontal), baseline at $y. Falls back to GD's built-in font (ASCII only) when no TTF is available. */
function tg_text($im, float $size, int $x, int $y, $color, string $text, bool $bold = false, string $anchor = 'l'): int {
    $font = tg_font($bold);
    if ($font) {
        $bb = imagettfbbox($size, 0, $font, $text); $w = $bb[2] - $bb[0];
        $xx = $anchor === 'c' ? (int) ($x - $w / 2) : ($anchor === 'r' ? $x - $w : $x); imagettftext($im, $size, 0, $xx, $y, $color, $font, $text); return (int) $w;
    }
    $t = preg_replace('/[^\x20-\x7e]/', '', str_replace('₹', 'Rs ', $text)) ?? ''; $f = $size >= 20 ? 5 : 3; $w = imagefontwidth($f) * strlen($t);
    $xx = $anchor === 'c' ? (int) ($x - $w / 2) : ($anchor === 'r' ? $x - $w : $x); imagestring($im, $f, $xx, $y - imagefontheight($f) + 2, $t, $color); return $w;
}
function tg_text_w($im, float $size, string $text, bool $bold = false): int { $font = tg_font($bold); if ($font) { $bb = imagettfbbox($size, 0, $font, $text); return (int) ($bb[2] - $bb[0]); } return imagefontwidth($size >= 20 ? 5 : 3) * strlen($text); }
function tg_fit(string $s, $im, float $size, int $maxW, bool $bold = false): string {
    if (tg_text_w($im, $size, $s, $bold) <= $maxW) { return $s; }
    while (mb_strlen($s) > 1 && tg_text_w($im, $size, $s . '…', $bold) > $maxW) { $s = mb_substr($s, 0, -1); } return $s . '…';
}
function tg_nice(float $x, bool $round): float {
    if ($x <= 0) { return 1; } $exp = floor(log10($x)); $f = $x / (10 ** $exp);
    $nf = $round ? ($f < 1.5 ? 1 : ($f < 3 ? 2 : ($f < 7 ? 5 : 10))) : ($f <= 1 ? 1 : ($f <= 2 ? 2 : ($f <= 5 ? 5 : 10))); return $nf * (10 ** $exp);
}
/** [min, max, step] with the axis rounded to friendly numbers; always includes zero. */
function tg_axis(float $min, float $max, int $ticks = 5): array {
    $min = min($min, 0.0); $max = max($max, 0.0); if ($max == $min) { $max = $min + 1; }
    $step = tg_nice(tg_nice($max - $min, false) / ($ticks - 1), true); return [floor($min / $step) * $step, ceil($max / $step) * $step, $step];
}

/** Returns PNG bytes. Never throws for odd input: empty or oversized data are clamped. */
function tg_chart_png(array $spec): string {
    if (!function_exists('imagecreatetruecolor')) { throw new RuntimeException('The GD extension is not available.'); }
    $S = 2; $W = TG_CHART_W * $S; $H = TG_CHART_H * $S; $im = imagecreatetruecolor($W, $H); imagealphablending($im, true); imageantialias($im, true);
    $white = tg_hex($im, '#FFFFFF'); $ink = tg_hex($im, '#141414'); $mute = tg_hex($im, '#6B7280'); $grid = tg_hex($im, '#E5E7EB'); imagefilledrectangle($im, 0, 0, $W, $H, $white);
    $kind = in_array($spec['kind'] ?? '', ['bar', 'line', 'pie', 'progress'], true) ? $spec['kind'] : 'bar'; $money = !empty($spec['money']);
    $labels = array_map(fn($v) => mb_substr((string) $v, 0, 24), array_slice(array_values((array) ($spec['labels'] ?? [])), 0, 36));
    $series = []; foreach (array_slice((array) ($spec['series'] ?? []), 0, 4) as $sx) { $series[] = ['name' => mb_substr((string) ($sx['name'] ?? ''), 0, 24), 'values' => array_map(fn($v) => is_numeric($v) ? (float) $v : 0.0, array_slice(array_values((array) ($sx['values'] ?? [])), 0, count($labels)))]; }
    $pad = 44 * $S; $title = mb_substr((string) ($spec['title'] ?? ''), 0, 70); $top = $pad;
    if ($title !== '') { tg_text($im, 21 * $S * 0.75, $pad, $pad + 20 * $S, $ink, tg_fit($title, $im, 21 * $S * 0.75, $W - 2 * $pad, true), true); $top = $pad + 40 * $S; }
    if (!empty($spec['subtitle'])) { tg_text($im, 13 * $S * 0.75, $pad, $top + 4 * $S, $mute, tg_fit((string) $spec['subtitle'], $im, 13 * $S * 0.75, $W - 2 * $pad), false); $top += 26 * $S; }
    $colors = array_map(fn($h) => tg_hex($im, $h), TG_CHART_COLORS);
    if (!$labels || !$series) { tg_text($im, 16 * $S * 0.75, (int) ($W / 2), (int) ($H / 2), $mute, 'Nothing to show yet', false, 'c'); return tg_png($im, $S); }

    if ($kind === 'pie') {
        $vals = array_map(fn($v) => max(0.0, $v), $series[0]['values']); $items = []; foreach ($labels as $i => $l) { if (($vals[$i] ?? 0) > 0) { $items[] = [$l, $vals[$i]]; } }
        usort($items, fn($a, $b) => $b[1] <=> $a[1]); if (count($items) > 8) { $rest = array_sum(array_column(array_slice($items, 7), 1)); $items = array_slice($items, 0, 7); $items[] = ['Other', $rest]; }
        $total = array_sum(array_column($items, 1)); if ($total <= 0) { tg_text($im, 16 * $S * 0.75, (int) ($W / 2), (int) ($H / 2), $mute, 'Nothing to show yet', false, 'c'); return tg_png($im, $S); }
        $areaH = $H - $top - $pad; $d = min($areaH, (int) ($W * 0.5)); $cx = $pad + (int) ($d / 2); $cy = $top + (int) ($areaH / 2); $start = -90.0;
        foreach ($items as $i => [$l, $v]) { $sweep = $v / $total * 360; imagefilledarc($im, $cx, $cy, $d, $d, (int) round($start), (int) round($start + $sweep), $colors[$i % count($colors)], IMG_ARC_PIE); $start += $sweep; }
        imagefilledellipse($im, $cx, $cy, (int) ($d * 0.56), (int) ($d * 0.56), $white);
        tg_text($im, 12 * $S * 0.75, $cx, $cy - 6 * $S, $mute, 'Total', false, 'c'); tg_text($im, 19 * $S * 0.75, $cx, $cy + 22 * $S, $ink, tg_short_num($total, $money), true, 'c');
        $lx = $pad + $d + 36 * $S; $rowH = min(44 * $S, (int) ($areaH / max(1, count($items)))); $ly = $cy - (int) (count($items) * $rowH / 2) + $rowH / 2;
        foreach ($items as $i => [$l, $v]) { $y = (int) ($ly + $i * $rowH); imagefilledrectangle($im, $lx, $y - 9 * $S, $lx + 14 * $S, $y + 5 * $S, $colors[$i % count($colors)]);
            tg_text($im, 14 * $S * 0.75, $lx + 24 * $S, $y + 4 * $S, $ink, tg_fit($l, $im, 14 * $S * 0.75, $W - $lx - 24 * $S - $pad - 150 * $S), false);
            tg_text($im, 14 * $S * 0.75, $W - $pad, $y + 4 * $S, $mute, tg_short_num($v, $money) . '  ' . round($v / $total * 100) . '%', false, 'r'); }
        return tg_png($im, $S);
    }
    if ($kind === 'progress') {
        $vals = $series[0]['values']; $targets = isset($series[1]) ? $series[1]['values'] : array_fill(0, count($labels), 100.0); $n = count($labels); $rowH = min(100 * $S, (int) (($H - $top - $pad) / max(1, $n)));
        foreach ($labels as $i => $l) { $y = $top + $i * $rowH; $t = max(0.0001, (float) ($targets[$i] ?? 100)); $v = max(0.0, (float) ($vals[$i] ?? 0)); $pct = min(100, $v / $t * 100); $bx = $pad; $bw = $W - 2 * $pad; $by = $y + 22 * $S; $bh = 20 * $S;
            tg_text($im, 14 * $S * 0.75, $bx, $y + 14 * $S, $ink, tg_fit($l, $im, 14 * $S * 0.75, (int) ($bw * 0.55), true), true);
            tg_text($im, 13 * $S * 0.75, $W - $pad, $y + 14 * $S, $mute, isset($series[1]) ? tg_short_num($v, $money) . ' of ' . tg_short_num($t, $money) . '  ·  ' . round($pct) . '%' : round($pct) . '%', false, 'r');
            imagefilledrectangle($im, $bx, $by, $bx + $bw, $by + $bh, $grid); $col = $pct >= 100 ? $colors[1] : ($pct >= 50 ? $colors[0] : $colors[2]); if ($pct > 0) { imagefilledrectangle($im, $bx, $by, $bx + max(2, (int) ($bw * $pct / 100)), $by + $bh, $col); } }
        return tg_png($im, $S);
    }

    // bar / line: shared axes
    $all = array_merge(...array_column($series, 'values')); [$lo, $hi, $step] = tg_axis(min($all), max($all)); $n = count($labels); $ns = count($series);
    $legendH = $ns > 1 ? 30 * $S : 0; $pl = 80 * $S; $pr = $pad; $pt = $top + $legendH + 8 * $S; $pb = $H - 70 * $S; $cw = $W - $pl - $pr; $ch = $pb - $pt;
    $yOf = fn(float $v) => (int) ($pb - ($v - $lo) / ($hi - $lo) * $ch);
    for ($v = $lo; $v <= $hi + $step / 2; $v += $step) { $y = $yOf($v); imageline($im, $pl, $y, $W - $pr, $y, abs($v) < 1e-9 ? tg_hex($im, '#9CA3AF') : $grid); tg_text($im, 12 * $S * 0.75, $pl - 10 * $S, $y + 5 * $S, $mute, tg_short_num($v, $money), false, 'r'); }
    if ($ns > 1) { $lx = $pl; foreach ($series as $si => $sx) { imagefilledrectangle($im, $lx, $top + 6 * $S, $lx + 14 * $S, $top + 20 * $S, $colors[$si % count($colors)]); $w = tg_text($im, 13 * $S * 0.75, $lx + 22 * $S, $top + 19 * $S, $ink, $sx['name']); $lx += 22 * $S + $w + 28 * $S; } }
    $slot = $cw / $n; $every = max(1, (int) ceil($n / 12)); $zero = $yOf(0);
    for ($i = 0; $i < $n; $i++) { if ($i % $every === 0) { tg_text($im, 12 * $S * 0.75, (int) ($pl + $slot * ($i + 0.5)), $pb + 28 * $S, $mute, tg_fit($labels[$i], $im, 12 * $S * 0.75, (int) ($slot * $every - 6 * $S)), false, 'c'); } }
    if ($kind === 'bar') {
        $gw = $slot * 0.72; $bw = $gw / $ns;
        foreach ($series as $si => $sx) { foreach ($sx['values'] as $i => $v) { $x0 = (int) ($pl + $slot * $i + ($slot - $gw) / 2 + $si * $bw); $y = $yOf($v); imagefilledrectangle($im, $x0, min($y, $zero), (int) ($x0 + $bw - 2 * $S), max($y, $zero), $colors[$si % count($colors)]);
            if ($n <= 8 && $ns <= 2) { tg_text($im, 12 * $S * 0.75, (int) ($x0 + $bw / 2), $v >= 0 ? $y - 8 * $S : $y + 20 * $S, $ink, tg_short_num($v, $money), true, 'c'); } } }
    } else {
        foreach ($series as $si => $sx) {
            $pts = []; foreach ($sx['values'] as $i => $v) { $pts[] = [(int) ($pl + $slot * ($i + 0.5)), $yOf($v)]; }
            if ($ns === 1 && count($pts) > 1) { $poly = [$pts[0][0], $zero]; foreach ($pts as [$x, $y]) { array_push($poly, $x, $y); } array_push($poly, $pts[count($pts) - 1][0], $zero); $fill = imagecolorallocatealpha($im, hexdec(substr(TG_CHART_COLORS[0], 1, 2)), hexdec(substr(TG_CHART_COLORS[0], 3, 2)), hexdec(substr(TG_CHART_COLORS[0], 5, 2)), 108); imagefilledpolygon($im, $poly, $fill); }
            imagesetthickness($im, 4 * $S); for ($i = 1; $i < count($pts); $i++) { imageline($im, $pts[$i - 1][0], $pts[$i - 1][1], $pts[$i][0], $pts[$i][1], $colors[$si % count($colors)]); } imagesetthickness($im, 1);
            foreach ($pts as $i => [$x, $y]) { imagefilledellipse($im, $x, $y, 11 * $S, 11 * $S, $white); imagefilledellipse($im, $x, $y, 8 * $S, 8 * $S, $colors[$si % count($colors)]); if ($ns === 1 && ($n <= 8 || $i === $n - 1)) { tg_text($im, 12 * $S * 0.75, $x, $y - 14 * $S, $ink, tg_short_num($sx['values'][$i], $money), true, 'c'); } }
        }
    }
    return tg_png($im, $S);
}
function tg_png($im, int $scale): string {
    $out = imagecreatetruecolor(TG_CHART_W, TG_CHART_H); imagecopyresampled($out, $im, 0, 0, 0, 0, TG_CHART_W, TG_CHART_H, imagesx($im), imagesy($im)); imagedestroy($im);
    ob_start(); imagepng($out, null, 6); $png = (string) ob_get_clean(); imagedestroy($out); return $png;
}

// ============================ charts built from the person's data ============================
/** Returns a chart spec for one of the whitelisted kinds, from this person's own data (or null when there is nothing to draw). */
function tg_chart_data(int $uid, string $kind): ?array {
    $s = load_snapshot($uid); $r = fn(int $minor) => round($minor / 100, 2);
    switch ($kind) {
        case 'spending': case 's':
            $m = month_of($s['today']); $c = array_slice(category_breakdown($s, $m), 0, 9); if (!$c) { return null; }
            return ['kind' => 'pie', 'title' => 'Where your money went', 'subtitle' => month_label($m), 'money' => true, 'labels' => array_column($c, 'name'), 'series' => [['name' => 'Spent', 'values' => array_map(fn($x) => $r((int) $x['value']), $c)]]];
        case 'trend': case 't':
            $ms = month_series($s, 6); return ['kind' => 'bar', 'title' => 'Income vs spending', 'subtitle' => 'Last 6 months', 'money' => true, 'labels' => array_column($ms, 'label'), 'series' => [['name' => 'Income', 'values' => array_map(fn($x) => $r((int) $x['income']), $ms)], ['name' => 'Spent', 'values' => array_map(fn($x) => $r((int) $x['expenses']), $ms)]]];
        case 'balance': case 'b':
            $labels = []; $vals = []; foreach (months_list(month_of($s['today']), 6) as $m) { $end = min(month_end($m), $s['today']); $labels[] = month_label($m, true); $vals[] = $r(total_cash($s['accounts'], $s['txs'], $end)); }
            return ['kind' => 'line', 'title' => 'Your cash over time', 'subtitle' => 'Total across accounts, end of each month', 'money' => true, 'labels' => $labels, 'series' => [['name' => 'Cash', 'values' => $vals]]];
        case 'goals': case 'g':
            $g = array_slice(goal_views($s), 0, 7); if (!$g) { return null; }
            return ['kind' => 'progress', 'title' => 'Goal progress', 'money' => true, 'labels' => array_column($g, 'name'), 'series' => [['name' => 'Saved', 'values' => array_map(fn($x) => $r((int) $x['saved']), $g)], ['name' => 'Target', 'values' => array_map(fn($x) => $r((int) $x['target_amount']), $g)]]];
    }
    return null;
}
