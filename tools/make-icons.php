<?php
// Draws the PWA icons (ember gradient + rising bars) with GD, supersampled 4x for clean edges.  php tools/make-icons.php
function icon_png(int $size, string $file, bool $maskable = false, bool $rounded = true): void {
    $S = $size * 4; $im = imagecreatetruecolor($S, $S); imagealphablending($im, false); imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); imagealphablending($im, true);
    $stops = [[0, [74, 0, 0]], [.35, [143, 22, 0]], [.7, [214, 70, 10]], [1, [247, 138, 40]]];
    for ($y = 0; $y < $S; $y++) {
        $t = $y / ($S - 1); for ($i = 0; $i < count($stops) - 1; $i++) { if ($t <= $stops[$i + 1][0]) { break; } }
        [$t0, $c0] = $stops[$i]; [$t1, $c1] = $stops[$i + 1]; $k = ($t - $t0) / ($t1 - $t0);
        $col = imagecolorallocate($im, (int) ($c0[0] + ($c1[0] - $c0[0]) * $k), (int) ($c0[1] + ($c1[1] - $c0[1]) * $k), (int) ($c0[2] + ($c1[2] - $c0[2]) * $k));
        imageline($im, 0, $y, $S - 1, $y, $col);
    }
    // soft glow at the bottom
    $glow = imagecolorallocatealpha($im, 255, 200, 120, 112); imagefilledellipse($im, (int) ($S * .5), (int) ($S * 1.02), (int) ($S * 1.1), (int) ($S * .55), $glow);
    // rising bars (kept inside the maskable safe zone: central 60%)
    $pad = $maskable ? $S * .26 : $S * .22; $w = $S - 2 * $pad; $bw = $w / 6.2; $gap = $bw * .55; $white = imagecolorallocate($im, 255, 255, 255);
    $hs = [.38, .62, .5, .86]; $baseY = $S - $pad; $x = $pad + ($w - (4 * $bw + 3 * $gap)) / 2;
    foreach ($hs as $h) { $bh = $w * $h * .92; $r = (int) ($bw * .5); imagefilledrectangle($im, (int) ($x + $r), (int) ($baseY - $bh), (int) ($x + $bw - $r), (int) $baseY, $white); imagefilledellipse($im, (int) ($x + $r), (int) ($baseY - $bh + 0), $r * 2, $r * 2, $white); imagefilledellipse($im, (int) ($x + $bw - $r), (int) ($baseY - $bh), $r * 2, $r * 2, $white); imagefilledrectangle($im, (int) $x, (int) ($baseY - $bh), (int) ($x + $bw), (int) $baseY - 1, $white); $x += $bw + $gap; }
    if ($rounded && !$maskable) { // round the corners by erasing outside a rounded square
        $r = (int) ($S * .22); $mask = imagecreatetruecolor($S, $S); imagealphablending($mask, false); imagesavealpha($mask, true); imagefill($mask, 0, 0, imagecolorallocatealpha($mask, 0, 0, 0, 0));
        $clear = imagecolorallocatealpha($mask, 0, 0, 0, 127);
        for ($yy = 0; $yy < $S; $yy++) { for ($xx = 0; $xx < $S; $xx++) { $dx = max($r - $xx, $xx - ($S - 1 - $r), 0); $dy = max($r - $yy, $yy - ($S - 1 - $r), 0); if ($dx * $dx + $dy * $dy > $r * $r) { imagesetpixel($im, $xx, $yy, $clear); } } }
    }
    $out = imagecreatetruecolor($size, $size); imagealphablending($out, false); imagesavealpha($out, true); imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $im, 0, 0, 0, 0, $size, $size, $S, $S); imagepng($out, $file, 9);
}
$d = __DIR__ . '/../assets/icons/';
icon_png(192, $d . 'icon-192.png'); icon_png(512, $d . 'icon-512.png'); icon_png(512, $d . 'maskable-512.png', true); icon_png(180, $d . 'apple-touch-icon.png', true); icon_png(64, $d . 'favicon-64.png');
echo "icons written\n";
