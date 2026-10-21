<?php
declare(strict_types=1);
/**
 * QR code encoder (ISO/IEC 18004, byte mode, error correction L/M/Q/H, versions 1-40) and a "tube" renderer, written for this app.
 * No external service and no library: the same algorithm every QR generator uses (Reed-Solomon over GF(256), 8 masks scored by the standard penalty rules).
 *  - qr_matrix($text, 'M')            => bool[][] (true = dark), without the quiet zone
 *  - qr_svg($matrix, $opts)           => SVG string. style 'tube' joins neighbouring modules like liquid tubes, finder patterns are rounded
 *  - qr_png($matrix, $opts)           => PNG bytes (GD), same look
 * opts: fg, bg, finder (colour of the three eye patterns, default fg), quiet (modules, default 4), size (px of the image), style (tube|square|dots), transparent (SVG/PNG bg none)
 */

const QR_ECL_BITS = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];
const QR_ECC_PER_BLOCK = [
    'L' => [7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    'M' => [10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
    'Q' => [13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    'H' => [17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
];
const QR_BLOCKS = [
    'L' => [1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
    'M' => [1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
    'Q' => [1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
    'H' => [1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
];

function qr_raw_modules(int $ver): int {
    $r = (16 * $ver + 128) * $ver + 64;
    if ($ver >= 2) { $na = intdiv($ver, 7) + 2; $r -= (25 * $na - 10) * $na - 55; if ($ver >= 7) { $r -= 36; } }
    return $r;
}
function qr_data_capacity(int $ver, string $ecl): int { return intdiv(qr_raw_modules($ver), 8) - QR_ECC_PER_BLOCK[$ecl][$ver - 1] * QR_BLOCKS[$ecl][$ver - 1]; }

/** GF(256) multiply, polynomial 0x11D. */
function qr_gf_mul(int $x, int $y): int { $z = 0; for ($i = 7; $i >= 0; $i--) { $z = ($z << 1) ^ (($z >> 7) * 0x11D); $z ^= (($y >> $i) & 1) * $x; } return $z & 0xFF; }
function qr_rs_divisor(int $deg): array {
    $res = array_fill(0, $deg, 0); $res[$deg - 1] = 1; $root = 1;
    for ($i = 0; $i < $deg; $i++) { for ($j = 0; $j < $deg; $j++) { $res[$j] = qr_gf_mul($res[$j], $root); if ($j + 1 < $deg) { $res[$j] ^= $res[$j + 1]; } } $root = qr_gf_mul($root, 2); }
    return $res;
}
function qr_rs_remainder(array $data, array $div): array {
    $res = array_fill(0, count($div), 0);
    foreach ($data as $b) { $f = $b ^ array_shift($res); $res[] = 0; foreach ($div as $i => $c) { $res[$i] ^= qr_gf_mul($c, $f); } }
    return $res;
}

/** Encodes UTF-8 text as a QR module matrix (rows of bool). Picks the smallest version that fits; throws when the text is too long for level $ecl. */
function qr_matrix(string $text, string $ecl = 'M', int $minVersion = 1): array {
    if (!isset(QR_ECL_BITS[$ecl])) { throw new InvalidArgumentException('Unknown error correction level.'); }
    $bytes = array_values(unpack('C*', $text) ?: []); $n = count($bytes); $ver = 0;
    for ($v = max(1, $minVersion); $v <= 40; $v++) { $ccBits = $v <= 9 ? 8 : 16; if (4 + $ccBits + 8 * $n <= qr_data_capacity($v, $ecl) * 8) { $ver = $v; break; } }
    if ($ver === 0) { throw new InvalidArgumentException('That is too much text for a QR code.'); }
    // bit stream
    $bits = []; $put = function (int $val, int $len) use (&$bits) { for ($i = $len - 1; $i >= 0; $i--) { $bits[] = ($val >> $i) & 1; } };
    $put(0b0100, 4); $put($n, $ver <= 9 ? 8 : 16); foreach ($bytes as $b) { $put($b, 8); }
    $cap = qr_data_capacity($ver, $ecl) * 8; $put(0, min(4, $cap - count($bits))); while (count($bits) % 8) { $bits[] = 0; }
    for ($pad = 0xEC; count($bits) < $cap; $pad ^= 0xEC ^ 0x11) { $put($pad, 8); }
    $data = []; for ($i = 0; $i < count($bits); $i += 8) { $v = 0; for ($j = 0; $j < 8; $j++) { $v = ($v << 1) | $bits[$i + $j]; } $data[] = $v; }
    // blocks + ECC + interleave
    $nb = QR_BLOCKS[$ecl][$ver - 1]; $eccLen = QR_ECC_PER_BLOCK[$ecl][$ver - 1]; $raw = intdiv(qr_raw_modules($ver), 8); $short = $nb - $raw % $nb; $shortLen = intdiv($raw, $nb);
    $div = qr_rs_divisor($eccLen); $blocks = []; $k = 0;
    for ($i = 0; $i < $nb; $i++) { $len = $shortLen - $eccLen + ($i < $short ? 0 : 1); $d = array_slice($data, $k, $len); $k += $len; $e = qr_rs_remainder($d, $div); if ($i < $short) { $d[] = -1; } $blocks[] = array_merge($d, $e); }
    $all = []; $blockLen = count($blocks[count($blocks) - 1]);
    for ($i = 0; $i < $blockLen; $i++) { foreach ($blocks as $j => $blk) { if ($i !== $shortLen - $eccLen || $j >= $short) { $all[] = $blk[$i]; } } }
    // function patterns
    $size = $ver * 4 + 17; $mod = array_fill(0, $size, array_fill(0, $size, false)); $fn = array_fill(0, $size, array_fill(0, $size, false));
    $set = function (int $x, int $y, bool $dark) use (&$mod, &$fn, $size) { if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) { $mod[$y][$x] = $dark; $fn[$y][$x] = true; } };
    for ($i = 0; $i < $size; $i++) { $set(6, $i, $i % 2 === 0); $set($i, 6, $i % 2 === 0); }
    foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) { for ($dy = -4; $dy <= 4; $dy++) { for ($dx = -4; $dx <= 4; $dx++) { $d = max(abs($dx), abs($dy)); $set($cx + $dx, $cy + $dy, $d !== 2 && $d !== 4); } } }
    $pos = []; if ($ver > 1) { $na = intdiv($ver, 7) + 2; $step = $ver === 32 ? 26 : intdiv($ver * 8 + $na * 3 + 5, $na * 4 - 4) * 2; $pos = [6]; for ($p = $size - 7, $i = $na - 1; $i >= 1; $i--, $p -= $step) { array_splice($pos, 1, 0, [$p]); } }
    $np = count($pos);
    for ($i = 0; $i < $np; $i++) { for ($j = 0; $j < $np; $j++) { if (($i === 0 && $j === 0) || ($i === 0 && $j === $np - 1) || ($i === $np - 1 && $j === 0)) { continue; }
        for ($dy = -2; $dy <= 2; $dy++) { for ($dx = -2; $dx <= 2; $dx++) { $set($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1); } } } }
    $fmt = function (int $mask) use (&$set, $size, $ecl) {
        $d = (QR_ECL_BITS[$ecl] << 3) | $mask; $r = $d; for ($i = 0; $i < 10; $i++) { $r = ($r << 1) ^ (($r >> 9) * 0x537); } $b = (($d << 10) | $r) ^ 0x5412; $bit = fn($i) => (($b >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) { $set(8, $i, $bit($i)); } $set(8, 7, $bit(6)); $set(8, 8, $bit(7)); $set(7, 8, $bit(8)); for ($i = 9; $i < 15; $i++) { $set(14 - $i, 8, $bit($i)); }
        for ($i = 0; $i < 8; $i++) { $set($size - 1 - $i, 8, $bit($i)); } for ($i = 8; $i < 15; $i++) { $set(8, $size - 15 + $i, $bit($i)); } $set(8, $size - 8, true);
    };
    $fmt(0); // reserve the format area as function modules
    if ($ver >= 7) { $r = $ver; for ($i = 0; $i < 12; $i++) { $r = ($r << 1) ^ (($r >> 11) * 0x1F25); } $b = ($ver << 12) | $r;
        for ($i = 0; $i < 18; $i++) { $bit = (($b >> $i) & 1) === 1; $a = $size - 11 + $i % 3; $c = intdiv($i, 3); $set($a, $c, $bit); $set($c, $a, $bit); } }
    // data placement
    $i = 0; $total = count($all) * 8;
    for ($right = $size - 1; $right >= 1; $right -= 2) { if ($right === 6) { $right = 5; }
        for ($vert = 0; $vert < $size; $vert++) { for ($j = 0; $j < 2; $j++) { $x = $right - $j; $y = ((($right + 1) & 2) === 0) ? $size - 1 - $vert : $vert;
            if (!$fn[$y][$x] && $i < $total) { $mod[$y][$x] = ((($all[$i >> 3]) >> (7 - ($i & 7))) & 1) === 1; $i++; } } } }
    // masks
    $maskFn = [fn($x, $y) => ($x + $y) % 2 === 0, fn($x, $y) => $y % 2 === 0, fn($x, $y) => $x % 3 === 0, fn($x, $y) => ($x + $y) % 3 === 0, fn($x, $y) => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
        fn($x, $y) => $x * $y % 2 + $x * $y % 3 === 0, fn($x, $y) => ($x * $y % 2 + $x * $y % 3) % 2 === 0, fn($x, $y) => (($x + $y) % 2 + $x * $y % 3) % 2 === 0];
    $best = null; $bestScore = PHP_INT_MAX;
    for ($m = 0; $m < 8; $m++) {
        $cand = $mod; for ($y = 0; $y < $size; $y++) { for ($x = 0; $x < $size; $x++) { if (!$fn[$y][$x] && $maskFn[$m]($x, $y)) { $cand[$y][$x] = !$cand[$y][$x]; } } }
        $saved = $mod; $mod = $cand; $fmt($m); $cand = $mod; $mod = $saved;
        $score = qr_penalty($cand, $size); if ($score < $bestScore) { $bestScore = $score; $best = $cand; }
    }
    return $best;
}
function qr_penalty(array $m, int $size): int {
    $p = 0; $dark = 0;
    $line = function (array $cells) use (&$p, $size) {
        $run = 1; for ($i = 1; $i < $size; $i++) { if ($cells[$i] === $cells[$i - 1]) { $run++; } else { if ($run >= 5) { $p += 3 + $run - 5; } $run = 1; } } if ($run >= 5) { $p += 3 + $run - 5; }
        for ($i = 0; $i + 11 <= $size; $i++) { $w = ''; for ($k = 0; $k < 11; $k++) { $w .= $cells[$i + $k] ? '1' : '0'; } if ($w === '10111010000' || $w === '00001011101') { $p += 40; } }
    };
    for ($y = 0; $y < $size; $y++) { $line($m[$y]); $dark += count(array_filter($m[$y])); }
    for ($x = 0; $x < $size; $x++) { $line(array_column($m, $x)); }
    for ($y = 0; $y < $size - 1; $y++) { for ($x = 0; $x < $size - 1; $x++) { if ($m[$y][$x] === $m[$y][$x + 1] && $m[$y][$x] === $m[$y + 1][$x] && $m[$y][$x] === $m[$y + 1][$x + 1]) { $p += 3; } } }
    $tot = $size * $size; $k = (int) ceil(abs($dark * 20 - $tot * 10) / $tot) - 1; return $p + max(0, $k) * 10;
}
function qr_version_of(array $m): int { return (count($m) - 17) / 4; }

// ============================ rendering ============================
function qr_color(string $c, string $default): string { return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : $default; }
/** WCAG contrast ratio of a #rrggbb colour against white (a QR code needs a clearly darker foreground). */
function qr_contrast_white(string $hex): float {
    $l = 0.0; foreach ([1, 3, 5] as $k => $o) { $c = hexdec(substr($hex, $o, 2)) / 255; $c = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4; $l += [0.2126, 0.7152, 0.0722][$k] * $c; }
    return 1.05 / ($l + 0.05);
}
function qr_in_finder(int $x, int $y, int $n): bool { return ($x < 7 && $y < 7) || ($x >= $n - 7 && $y < 7) || ($x < 7 && $y >= $n - 7); }
/** Which modules draw: finders are drawn as rounded shapes, so they are skipped in the generic pass. */
function qr_opts(array $o): array {
    return ['fg' => qr_color((string) ($o['fg'] ?? ''), '#111111'), 'bg' => qr_color((string) ($o['bg'] ?? ''), '#ffffff'), 'finder' => qr_color((string) ($o['finder'] ?? ''), qr_color((string) ($o['fg'] ?? ''), '#111111')),
        'quiet' => max(2, min(8, (int) ($o['quiet'] ?? 4))), 'size' => max(120, min(2400, (int) ($o['size'] ?? 640))), 'style' => in_array($o['style'] ?? 'tube', ['tube', 'square', 'dots'], true) ? ($o['style'] ?? 'tube') : 'tube', 'transparent' => !empty($o['transparent'])];
}
function qr_rrect_path(float $x, float $y, float $w, float $h, float $r): string {
    return sprintf('M%.3F %.3FH%.3FA%.3F %.3F 0 0 1 %.3F %.3FV%.3FA%.3F %.3F 0 0 1 %.3F %.3FH%.3FA%.3F %.3F 0 0 1 %.3F %.3FV%.3FA%.3F %.3F 0 0 1 %.3F %.3FZ',
        $x + $r, $y, $x + $w - $r, $r, $r, $x + $w, $y + $r, $y + $h - $r, $r, $r, $x + $w - $r, $y + $h, $x + $r, $r, $r, $x, $y + $h - $r, $y + $r, $r, $r, $x + $r, $y);
}
function qr_svg(array $m, array $o = []): string {
    $o = qr_opts($o); $n = count($m); $q = $o['quiet']; $dim = $n + 2 * $q; $style = $o['style']; $d = ''; $ov = 0.02;
    $on = fn(int $x, int $y) => $x >= 0 && $y >= 0 && $x < $n && $y < $n && $m[$y][$x] && !qr_in_finder($x, $y, $n);
    for ($y = 0; $y < $n; $y++) { for ($x = 0; $x < $n; $x++) {
        if (!$m[$y][$x] || qr_in_finder($x, $y, $n)) { continue; } $X = $x + $q; $Y = $y + $q;
        if ($style === 'dots') { $d .= sprintf('M%.3F %.3FA0.42 0.42 0 1 1 %.3F %.3FA0.42 0.42 0 1 1 %.3F %.3FZ', $X + 0.08, $Y + 0.5, $X + 0.92, $Y + 0.5, $X + 0.08, $Y + 0.5); continue; }
        if ($style === 'square') { $d .= sprintf('M%d %dh1v1h-1z', $X, $Y); continue; }
        $U = $on($x, $y - 1); $D = $on($x, $y + 1); $L = $on($x - 1, $y); $R = $on($x + 1, $y); $r = 0.5;
        $tl = !$U && !$L; $tr = !$U && !$R; $br = !$D && !$R; $bl = !$D && !$L;
        $x0 = $X - ($L ? $ov : 0); $x1 = $X + 1 + ($R ? $ov : 0); $y0 = $Y - ($U ? $ov : 0); $y1 = $Y + 1 + ($D ? $ov : 0);
        $d .= sprintf('M%.3F %.3F', $x0 + ($tl ? $r : 0), $y0); $d .= sprintf('H%.3F', $x1 - ($tr ? $r : 0)); if ($tr) { $d .= sprintf('A%.3F %.3F 0 0 1 %.3F %.3F', $r, $r, $x1, $y0 + $r); }
        $d .= sprintf('V%.3F', $y1 - ($br ? $r : 0)); if ($br) { $d .= sprintf('A%.3F %.3F 0 0 1 %.3F %.3F', $r, $r, $x1 - $r, $y1); }
        $d .= sprintf('H%.3F', $x0 + ($bl ? $r : 0)); if ($bl) { $d .= sprintf('A%.3F %.3F 0 0 1 %.3F %.3F', $r, $r, $x0, $y1 - $r); }
        $d .= sprintf('V%.3F', $y0 + ($tl ? $r : 0)); if ($tl) { $d .= sprintf('A%.3F %.3F 0 0 1 %.3F %.3F', $r, $r, $x0 + $r, $y0); } $d .= 'Z';
        // concave corners: where both side neighbours are dark but the diagonal is empty, fill the spandrel so tubes bend smoothly
        foreach ([[-1, -1, $L, $U, $X, $Y], [1, -1, $R, $U, $X + 1, $Y], [1, 1, $R, $D, $X + 1, $Y + 1], [-1, 1, $L, $D, $X, $Y + 1]] as [$sx, $sy, $h, $v, $px, $py]) {
            if ($h && $v && !$on($x + $sx, $y + $sy)) { // fillet in the diagonal cell next to corner point P=($px,$py): square r x r minus a quarter circle centred r away from P on both axes
                $cx = $px + $sx * $r; $cy = $py + $sy * $r; $sweep = ($sx * $sy > 0) ? 0 : 1;
                $d .= sprintf('M%.3F %.3FL%.3F %.3FA%.3F %.3F 0 0 %d %.3F %.3FZ', $px, $py, $cx, $py, $r, $r, $sweep, $px, $cy);
            } }
    } }
    $f = $o['finder']; $fg = $o['fg']; $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" width="' . $o['size'] . '" height="' . $o['size'] . '" shape-rendering="geometricPrecision" role="img" aria-label="QR code">';
    if (!$o['transparent']) { $svg .= '<rect width="' . $dim . '" height="' . $dim . '" fill="' . $o['bg'] . '"/>'; }
    if ($d !== '') { $svg .= '<path fill="' . $fg . '" d="' . $d . '"/>'; }
    foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$fx, $fy]) { $X = $fx + $q; $Y = $fy + $q;
        $svg .= '<path fill="' . $f . '" fill-rule="evenodd" d="' . qr_rrect_path($X, $Y, 7, 7, 2.2) . qr_rrect_path($X + 1, $Y + 1, 5, 5, 1.3) . '"/><path fill="' . $f . '" d="' . qr_rrect_path($X + 2, $Y + 2, 3, 3, 1.0) . '"/>'; }
    return $svg . '</svg>';
}
/** Can this host draw PNGs (GD, and FreeType when text is needed)? Every PNG route checks it and falls back to SVG. `$GLOBALS['__no_gd']` lets tests simulate a host without GD. */
function gd_png_ok(bool $text = false): bool {
    if (!empty($GLOBALS['__no_gd'])) { return false; }
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) { return false; }
    if ($text) { if (!function_exists('imagettftext')) { return false; } $i = function_exists('gd_info') ? gd_info() : []; if (empty($i['FreeType Support'])) { return false; } }
    return true;
}
function qr_png(array $m, array $o = []): string {
    $o = qr_opts($o); $n = count($m); $q = $o['quiet']; $dim = $n + 2 * $q; $style = $o['style'];
    $cell = max(4, (int) ceil($o['size'] * 2 / $dim)); $px = $dim * $cell; $im = imagecreatetruecolor($px, $px);
    $hex = fn(string $h) => imagecolorallocate($im, hexdec(substr($h, 1, 2)), hexdec(substr($h, 3, 2)), hexdec(substr($h, 5, 2)));
    $bg = $hex($o['bg']); $fg = $hex($o['fg']); $fc = $hex($o['finder']); imagefilledrectangle($im, 0, 0, $px - 1, $px - 1, $bg); imageantialias($im, true);
    $on = fn(int $x, int $y) => $x >= 0 && $y >= 0 && $x < $n && $y < $n && $m[$y][$x] && !qr_in_finder($x, $y, $n); $h = intdiv($cell, 2);
    $arc = function (int $cx, int $cy, int $r, int $from, int $col) use ($im) { imagefilledarc($im, $cx, $cy, $r * 2, $r * 2, $from, $from + 90, $col, IMG_ARC_PIE); };
    for ($y = 0; $y < $n; $y++) { for ($x = 0; $x < $n; $x++) {
        if (!$m[$y][$x] || qr_in_finder($x, $y, $n)) { continue; } $x0 = ($x + $q) * $cell; $y0 = ($y + $q) * $cell; $x1 = $x0 + $cell - 1; $y1 = $y0 + $cell - 1;
        if ($style === 'dots') { imagefilledellipse($im, $x0 + $h, $y0 + $h, (int) ($cell * 0.84), (int) ($cell * 0.84), $fg); continue; }
        imagefilledrectangle($im, $x0, $y0, $x1, $y1, $fg); if ($style === 'square') { continue; }
        $U = $on($x, $y - 1); $D = $on($x, $y + 1); $L = $on($x - 1, $y); $R = $on($x + 1, $y);
        if (!$U && !$L) { imagefilledrectangle($im, $x0, $y0, $x0 + $h - 1, $y0 + $h - 1, $bg); $arc($x0 + $h, $y0 + $h, $h, 180, $fg); }
        if (!$U && !$R) { imagefilledrectangle($im, $x1 - $h + 1, $y0, $x1, $y0 + $h - 1, $bg); $arc($x1 - $h + 1, $y0 + $h, $h, 270, $fg); }
        if (!$D && !$R) { imagefilledrectangle($im, $x1 - $h + 1, $y1 - $h + 1, $x1, $y1, $bg); $arc($x1 - $h + 1, $y1 - $h + 1, $h, 0, $fg); }
        if (!$D && !$L) { imagefilledrectangle($im, $x0, $y1 - $h + 1, $x0 + $h - 1, $y1, $bg); $arc($x0 + $h, $y1 - $h + 1, $h, 90, $fg); }
    } }
    // concave fillets (second pass so they sit over the neighbours' background)
    if ($style === 'tube') { for ($y = 0; $y < $n; $y++) { for ($x = 0; $x < $n; $x++) { if (!$on($x, $y)) { continue; }
        $x0 = ($x + $q) * $cell; $y0 = ($y + $q) * $cell; $x1 = $x0 + $cell; $y1 = $y0 + $cell;
        foreach ([[-1, -1, $x0, $y0], [1, -1, $x1, $y0], [1, 1, $x1, $y1], [-1, 1, $x0, $y1]] as [$sx, $sy, $ppx, $ppy]) {
            $hh = $sx < 0 ? $on($x - 1, $y) : $on($x + 1, $y); $vv = $sy < 0 ? $on($x, $y - 1) : $on($x, $y + 1);
            if ($hh && $vv && !$on($x + $sx, $y + $sy)) { $ax = $sx < 0 ? $ppx - $h : $ppx; $ay = $sy < 0 ? $ppy - $h : $ppy;
                imagefilledrectangle($im, $ax, $ay, $ax + $h - 1, $ay + $h - 1, $fg); $cx = $ppx + $sx * $h; $cy = $ppy + $sy * $h;
                $from = $sx < 0 ? ($sy < 0 ? 0 : 270) : ($sy < 0 ? 90 : 180); $arc($cx, $cy, $h, $from, $bg); } } } } }
    foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$fx, $fy]) { $X = ($fx + $q) * $cell; $Y = ($fy + $q) * $cell;
        $rr = function (int $x, int $y, int $w, int $r, int $col) use ($im) { imagefilledrectangle($im, $x + $r, $y, $x + $w - $r - 1, $y + $w - 1, $col); imagefilledrectangle($im, $x, $y + $r, $x + $w - 1, $y + $w - $r - 1, $col);
            foreach ([[$x + $r, $y + $r], [$x + $w - $r - 1, $y + $r], [$x + $r, $y + $w - $r - 1], [$x + $w - $r - 1, $y + $w - $r - 1]] as [$cx, $cy]) { imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $col); } };
        $rr($X, $Y, 7 * $cell, (int) (2.2 * $cell), $fc); $rr($X + $cell, $Y + $cell, 5 * $cell, (int) (1.3 * $cell), $bg); $rr($X + 2 * $cell, $Y + 2 * $cell, 3 * $cell, (int) (1.0 * $cell), $fc); }
    $out = imagecreatetruecolor($o['size'], $o['size']); imagecopyresampled($out, $im, 0, 0, 0, 0, $o['size'], $o['size'], $px, $px);
    ob_start(); imagepng($out, null, 6); $png = (string) ob_get_clean(); return $png;
}
