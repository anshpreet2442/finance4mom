<?php
declare(strict_types=1);
/**
 * PDF export (H3): the monthly report and the spending analysis as a branded PDF.
 *  - engine "fpdf" (default): the vendored FPDF 1.9 (app/vendor/fpdf) with charts drawn by GD as PNG; DejaVu Sans subset embedded so the rupee sign prints; plain "Rs." when the fonts are missing
 *  - engine "reportlab" (optional, admin switch): python3 + reportlab when detected, fed the same data and chart images through a JSON file; falls back to FPDF on any problem
 *  - delivery: download, email attachment (notify.php send_email with attachments) or a signed temporary link (HMAC, expiry, revocable per person)
 */
const PDF_BRAND = [11, 107, 77];
const PDF_COLORS = ['#0b6b4d', '#d4560f', '#e0a800', '#4f7cac', '#8c6bb1', '#7a8b82', '#2f9e8f', '#b5483a'];

// ============================ access + settings ============================
function pdf_gate(?array $u): ?string { $fa = feature_access($u, 'pdf_export'); return $fa['ok'] ? null : $fa['reason']; }
function pdf_fonts_ok(): bool { return !getenv('MFNC_PDF_NOFONTS') && is_file(__DIR__ . '/vendor/fpdf/font/dejavu.json') && is_file(__DIR__ . '/vendor/fpdf/font/dejavu.z') && is_file(__DIR__ . '/vendor/fpdf/font/dejavub.json'); }
/** Is python3 with reportlab usable on this server? Cached for the request. */
function pdf_reportlab_available(): bool {
    static $ok = null; if (!empty($GLOBALS['__pdf_no_rl'])) { return false; } if ($ok !== null) { return $ok; } if (getenv('MFNC_NO_REPORTLAB') || !function_exists('proc_open') || !empty($GLOBALS['__pdf_no_rl'])) { return $ok = false; }
    $r = pdf_run(['python3', '-I', '-c', 'import reportlab;print(reportlab.Version)'], 6); return $ok = $r[0] === 0 && trim($r[1]) !== '';
}
function pdf_engine(): string { return site_setting('pdf_engine', 'fpdf') === 'reportlab' && pdf_reportlab_available() ? 'reportlab' : 'fpdf'; }
/** Runs a command without a shell (argument array), with a time limit. Returns [exitCode, stdout, stderr]; exit code -1 when it timed out or could not start. */
function pdf_run(array $cmd, int $timeout = 20): array {
    $p = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, sys_get_temp_dir()); if (!is_resource($p)) { return [-1, '', 'could not start']; }
    fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false); $out = ''; $err = ''; $t0 = time();
    while (true) { $st = proc_get_status($p); $out .= (string) stream_get_contents($pipes[1]); $err .= (string) stream_get_contents($pipes[2]); if (!$st['running']) { break; } if (time() - $t0 > $timeout) { proc_terminate($p, 9); proc_close($p); return [-1, $out, 'timed out']; } usleep(30000); }
    $out .= (string) stream_get_contents($pipes[1]); $err .= (string) stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $code = $st['exitcode']; proc_close($p); return [(int) $code, $out, $err];
}

// ============================ data ============================
function pdf_prev_month(string $m): string { return add_months($m, -1); }
function pdf_category_totals(array $s, string $month): array {
    $o = []; foreach (spend_by_category($s['txs'], $month) as $id => $v) { $n = $id ? (cat_top($s, (int) $id)['name'] ?? 'Uncategorised') : 'Uncategorised'; $o[$n] = ($o[$n] ?? 0) + (int) $v; } arsort($o); return array_filter($o, fn($v) => $v > 0);
}
/** Everything both reports need, from the person's own data. */
function pdf_report_data(int $uid, string $month): array {
    $s = load_snapshot($uid); $d = dashboard_data($s, $month); $u = one('SELECT name FROM {users} WHERE id=?', [$uid]); $prevM = pdf_prev_month($month);
    $sum = $d['sum']; $prev = summarize_month($s['accounts'], $s['txs'], $s['contribs'], $prevM);
    $cur = pdf_category_totals($s, $month); $old = pdf_category_totals($s, $prevM); $tot = max(1, array_sum($cur)); $cats = [];
    foreach ($cur as $n => $v) { $p = $old[$n] ?? 0; $cats[] = ['name' => (string) $n, 'amount' => $v, 'share' => round($v / $tot * 100, 1), 'prev' => $p, 'change' => $p > 0 ? round(($v - $p) / $p * 100) : null]; }
    $pay = []; $meth = []; $daily = []; $days = days_in_month($month); $run = 0; $per = array_fill(1, $days, 0);
    foreach ($s['txs'] as $t) {
        if ($t['status'] !== 'completed' || month_of($t['date']) !== $month || !in_array($t['type'], ['expense', 'refund'], true)) { continue; } $a = $t['type'] === 'refund' ? -(int) $t['amount'] : (int) $t['amount'];
        $per[(int) substr($t['date'], 8, 2)] += $a; if ($t['type'] !== 'expense') { continue; }
        $k = mb_strtolower(trim((string) $t['description'])); if ($k === '') { $k = '(no description)'; } $pay[$k] = ['name' => mb_substr(trim((string) $t['description']) ?: '(no description)', 0, 36), 'amount' => ($pay[$k]['amount'] ?? 0) + $a, 'count' => ($pay[$k]['count'] ?? 0) + 1];
        $mm = trim((string) ($t['payment_method'] ?? '')) ?: 'Other'; $meth[$mm] = ($meth[$mm] ?? 0) + $a;
    }
    usort($pay, fn($a, $b) => $b['amount'] <=> $a['amount']); arsort($meth); $lastDay = $month === month_of($s['today']) ? (int) substr($s['today'], 8, 2) : $days;
    for ($i = 1; $i <= $lastDay; $i++) { $run += $per[$i]; $daily[] = $run; }
    $goals = array_map(fn($g) => ['name' => mb_substr((string) $g['name'], 0, 40), 'saved' => (int) $g['saved'], 'target' => (int) $g['target_amount'], 'percent' => (int) round($g['percent']), 'behind' => (bool) $g['behind'], 'complete' => (bool) $g['complete']], array_slice($d['goals'], 0, 8));
    $bills = array_map(fn($o) => ['name' => mb_substr((string) $o['bill']['name'], 0, 40), 'due' => $o['due_date'], 'amount' => (int) $o['outstanding'], 'overdue' => (bool) $o['overdue']], array_slice($d['upcoming'], 0, 8));
    return ['user' => explode(' ', (string) ($u['name'] ?? ''))[0], 'month' => $month, 'month_label' => month_label($month), 'generated' => date_label($s['today']),
        'sum' => ['income' => (int) $sum['income'], 'expenses' => (int) $sum['expenses'], 'net' => (int) $sum['net'], 'rate' => $sum['rate'] === null ? null : round((float) $sum['rate']), 'prev' => ['income' => (int) $prev['income'], 'expenses' => (int) $prev['expenses'], 'net' => (int) $prev['net']], 'prev_label' => month_label($prevM)],
        'cash' => (int) $d['cash'], 'reserved' => (int) $d['reserved'], 'series' => array_map(fn($m) => ['label' => substr((string) $m['label'], 0, 3), 'income' => (int) $m['income'], 'expenses' => (int) $m['expenses'], 'net' => (int) $m['net']], $d['series']),
        'categories' => $cats, 'payees' => array_slice($pay, 0, 8), 'methods' => array_map(fn($n, $v) => ['name' => (string) $n, 'amount' => (int) $v], array_keys($meth), array_values($meth)), 'daily' => $daily, 'goals' => $goals, 'bills' => $bills,
        'budget' => ['has' => (bool) $d['has_budgets'], 'spent' => (int) $d['budget_spent'], 'total' => (int) $d['total_budget']]];
}

// ============================ GD charts ============================
function pdf_font_file(bool $bold = false): ?string { $f = __DIR__ . '/../assets/fonts/DejaVuSans' . ($bold ? '-Bold' : '') . '.ttf'; return is_file($f) && function_exists('imagettftext') ? $f : null; }
function pdf_hex($im, string $h, int $alpha = 0) { return imagecolorallocatealpha($im, hexdec(substr($h, 1, 2)), hexdec(substr($h, 3, 2)), hexdec(substr($h, 5, 2)), $alpha); }
function pdf_txt_gd($im, float $size, int $x, int $y, $col, string $t, bool $bold = false, string $align = 'left'): void {
    $f = pdf_font_file($bold); if (!$f) { imagestring($im, 3, $x, $y - 12, preg_replace('/[^\x20-\x7e]/', '', $t), $col); return; }
    $bb = imagettfbbox($size, 0, $f, $t); $w = abs($bb[2] - $bb[0]); if ($align === 'center') { $x -= (int) ($w / 2); } elseif ($align === 'right') { $x -= $w; } imagettftext($im, $size, 0, $x, $y, $col, $f, $t);
}
function pdf_short_money(int $v): string { $a = abs($v) / 100; $s = $a >= 10000000 ? round($a / 10000000, 1) . 'Cr' : ($a >= 100000 ? round($a / 100000, 1) . 'L' : ($a >= 1000 ? round($a / 1000, 1) . 'k' : (string) round($a))); return ($v < 0 ? '-' : '') . $s; }
/** Saves a GD image as a temporary PNG, downsampled from a 2x canvas for smooth edges. Returns the path. */
function pdf_save_png($im, int $w, int $h): string {
    $out = imagecreatetruecolor((int) ($w / 2), (int) ($h / 2)); imagealphablending($out, true); imagecopyresampled($out, $im, 0, 0, 0, 0, (int) ($w / 2), (int) ($h / 2), $w, $h);
    $f = tempnam(sys_get_temp_dir(), 'mfpdf') . '.png'; imagepng($out, $f, 6); imagedestroy($im); imagedestroy($out); return $f;
}
/** Grouped bar chart. $series = [[name, colour, values[]], ...]. */
function pdf_chart_bars(array $labels, array $series, int $w = 1200, int $h = 460): string {
    if (!gd_png_ok()) { return ''; }   // no GD on this host: the report simply has no chart images
    $im = imagecreatetruecolor($w, $h); imageantialias($im, true); $bg = pdf_hex($im, '#ffffff'); imagefilledrectangle($im, 0, 0, $w, $h, $bg); $grid = pdf_hex($im, '#e6e8e4'); $ink = pdf_hex($im, '#44504a');
    $max = 1; foreach ($series as $s) { $max = max($max, ...array_map('abs', $s[2] ?: [0])); } $step = $max <= 0 ? 1 : 10 ** floor(log10($max)); foreach ([1, 2, 2.5, 5, 10] as $k) { if ($max / ($step * $k) <= 5) { $step *= $k; break; } } $top = (int) (ceil($max / $step) * $step);
    $L = 120; $R = 20; $T = 40; $B = 70; $cw = $w - $L - $R; $ch = $h - $T - $B;
    for ($i = 0; $i <= $top / $step; $i++) { $y = (int) ($T + $ch - $i * $step / $top * $ch); imageline($im, $L, $y, $w - $R, $y, $grid); pdf_txt_gd($im, 17, $L - 12, $y + 6, $ink, pdf_short_money((int) ($i * $step)), false, 'right'); }
    $n = max(1, count($labels)); $gw = $cw / $n; $k = count($series); $bw = $gw * 0.72 / $k;
    foreach ($labels as $i => $lab) { foreach ($series as $j => $s) { $v = max(0, (int) ($s[2][$i] ?? 0)); $bh = (int) ($v / $top * $ch); $x = (int) ($L + $i * $gw + $gw * 0.14 + $j * $bw); $col = pdf_hex($im, $s[1]);
        if ($bh > 0) { imagefilledrectangle($im, $x, $T + $ch - $bh, (int) ($x + $bw - 6), $T + $ch, $col); } } pdf_txt_gd($im, 18, (int) ($L + $i * $gw + $gw / 2), $h - 38, $ink, (string) $lab, false, 'center'); }
    $lx = $L; foreach ($series as $s) { imagefilledrectangle($im, $lx, $h - 22, $lx + 18, $h - 8, pdf_hex($im, $s[1])); pdf_txt_gd($im, 16, $lx + 26, $h - 8, $ink, (string) $s[0]); $lx += 170; }
    return pdf_save_png($im, $w, $h);
}
/** Donut chart with the slices [[label, value]] in PDF_COLORS order. */
function pdf_chart_pie(array $slices, int $size = 700): string {
    if (!gd_png_ok()) { return ''; }   // no GD on this host: the report simply has no chart images
    $im = imagecreatetruecolor($size, $size); imageantialias($im, true); imagefilledrectangle($im, 0, 0, $size, $size, pdf_hex($im, '#ffffff')); $tot = max(1, array_sum(array_column($slices, 1))); $cx = $cy = (int) ($size / 2); $r = (int) ($size * 0.46); $a = 270.0;
    foreach ($slices as $i => $sl) { $deg = $sl[1] / $tot * 360; $st = (int) round($a) % 360; $en = (int) round($a + $deg) % 360; if ($en === $st) { $en = ($st + 360 - 1) % 360; } if ($deg > 0.5) { imagefilledarc($im, $cx, $cy, $r * 2, $r * 2, $st, $en, pdf_hex($im, PDF_COLORS[$i % count(PDF_COLORS)]), IMG_ARC_PIE); } $a += $deg; }
    imagefilledellipse($im, $cx, $cy, (int) ($r * 1.1), (int) ($r * 1.1), pdf_hex($im, '#ffffff')); if (!$slices) { imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, pdf_hex($im, '#e6e8e4')); imagefilledellipse($im, $cx, $cy, (int) ($r * 1.1), (int) ($r * 1.1), pdf_hex($im, '#ffffff')); }
    return pdf_save_png($im, $size, $size);
}
/** Line / area chart of one series with labels along the bottom. */
function pdf_chart_line(array $labels, array $values, string $color = '#0b6b4d', int $w = 1200, int $h = 460): string {
    if (!gd_png_ok()) { return ''; }   // no GD on this host: the report simply has no chart images
    $im = imagecreatetruecolor($w, $h); imageantialias($im, true); imagefilledrectangle($im, 0, 0, $w, $h, pdf_hex($im, '#ffffff')); $grid = pdf_hex($im, '#e6e8e4'); $ink = pdf_hex($im, '#44504a'); $L = 120; $R = 30; $T = 30; $B = 60; $cw = $w - $L - $R; $ch = $h - $T - $B;
    $max = max(1, ...($values ?: [0])); $min = min(0, ...($values ?: [0])); $range = max(1, $max - $min); $n = count($values);
    for ($i = 0; $i <= 4; $i++) { $y = (int) ($T + $ch - $i / 4 * $ch); imageline($im, $L, $y, $w - $R, $y, $grid); pdf_txt_gd($im, 17, $L - 12, $y + 6, $ink, pdf_short_money((int) ($min + $range * $i / 4)), false, 'right'); }
    $pts = []; foreach ($values as $i => $v) { $pts[] = [(int) ($L + ($n > 1 ? $i / ($n - 1) : 0.5) * $cw), (int) ($T + $ch - ($v - $min) / $range * $ch)]; }
    if (count($pts) > 1) { $poly = [$pts[0][0], $T + $ch]; foreach ($pts as $p) { array_push($poly, $p[0], $p[1]); } array_push($poly, end($pts)[0], $T + $ch); imagefilledpolygon($im, $poly, pdf_hex($im, $color, 100)); imagesetthickness($im, 4); for ($i = 1; $i < count($pts); $i++) { imageline($im, $pts[$i - 1][0], $pts[$i - 1][1], $pts[$i][0], $pts[$i][1], pdf_hex($im, $color)); } imagesetthickness($im, 1); }
    foreach ($pts as $p) { imagefilledellipse($im, $p[0], $p[1], 12, 12, pdf_hex($im, $color)); } $every = max(1, (int) ceil(count($labels) / 10)); foreach ($labels as $i => $lab) { if ($i % $every === 0) { pdf_txt_gd($im, 16, (int) ($L + ($n > 1 ? $i / ($n - 1) : 0.5) * $cw), $h - 22, $ink, (string) $lab, false, 'center'); } }
    return pdf_save_png($im, $w, $h);
}

// ============================ FPDF document ============================
require_once __DIR__ . '/vendor/fpdf/fpdf.php';
class MfPdf extends FPDF {
    /** A chart that could not be drawn ('' path, e.g. no GD) is skipped instead of raising an error. */
    function Image($file, $x = null, $y = null, $w = 0, $h = 0, $type = '', $link = ''): void { if ($file === '' || !is_file($file)) { return; } parent::Image($file, $x, $y, $w, $h, $type, $link); }
    public bool $uni = false; public string $docTitle = ''; public string $docSub = '';
    function __construct() { parent::__construct('P', 'mm', 'A4'); $this->uni = pdf_fonts_ok(); if ($this->uni) { $this->AddFont('dejavu', '', 'dejavu.json'); $this->AddFont('dejavu', 'B', 'dejavub.json'); } $this->SetMargins(14, 14, 14); $this->SetAutoPageBreak(true, 18); $this->AliasNbPages('{nb}'); $this->SetCreator(APP_NAME); }
    /** UTF-8 to the embedded code page (byte 0x80 = rupee sign) or to Latin-1 with "Rs." when the fonts are missing. */
    function t(string $s): string {
        $s = str_replace(['€', "\u{2011}", "\u{00a0}", '−'], ['EUR ', '-', ' ', '-'], $s);
        if (!$this->uni) { $s = str_replace('₹', 'Rs.', $s); return (string) @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s); }
        return implode("\x80", array_map(fn($p) => (string) @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $p), explode('₹', $s)));
    }
    /** Every cell shrinks its text (down to 70%) and then ends with "..." instead of running into the next cell or off the page. */
    function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = ''): void {
        $txt = (string) $txt; $avail = ((float) $w > 0 ? (float) $w : $this->w - $this->rMargin - $this->x) - 2 * $this->cMargin; $orig = $this->FontSizePt; $size = $orig;
        if ($txt !== '' && $this->GetStringWidth($txt) > $avail) {
            while ($size > $orig * 0.7 && $this->GetStringWidth($txt) > $avail) { $size -= 0.5; $this->SetFontSize($size); }
            if ($this->GetStringWidth($txt) > $avail) { while ($txt !== '' && $this->GetStringWidth($txt . '...') > $avail) { $txt = substr($txt, 0, -1); } $txt .= '...'; }
        }
        parent::Cell($w, $h, $txt, $border, $ln, $align, $fill, $link); if ($size !== $orig) { $this->SetFontSize($orig); }
    }
    function f(string $style = '', float $size = 10): void { $this->SetFont($this->uni ? 'dejavu' : 'Helvetica', $style === 'B' ? 'B' : '', $size); }
    function rgb(array $c): array { return $c; }
    function Header(): void {
        $this->SetFillColor(...PDF_BRAND); $first = $this->PageNo() === 1; $this->Rect(0, 0, 210, $first ? 30 : 14, 'F');
        $this->SetTextColor(255, 255, 255); $this->f('B', $first ? 17 : 10); $this->SetXY(14, $first ? 8 : 4); $this->Cell(120, 8, $this->t(APP_NAME), 0, 0, 'L');
        $this->f('', $first ? 10 : 8); $this->SetXY(100, $first ? 9 : 4.5); $this->Cell(96, 6, $this->t($this->docTitle), 0, 0, 'R');
        if ($first) { $this->f('', 9); $this->SetXY(100, 16); $this->Cell(96, 5, $this->t($this->docSub), 0, 0, 'R'); }
        $this->SetTextColor(30, 40, 35); $this->SetY($first ? 36 : 20);
    }
    function Footer(): void {
        $this->SetY(-12); $this->SetDrawColor(215, 220, 215); $this->Line(14, $this->GetY(), 196, $this->GetY()); $this->f('', 8); $this->SetTextColor(110, 120, 115);
        $this->Cell(120, 8, $this->t('Generated by ' . APP_NAME . ' · ' . date_label(today())), 0, 0, 'L'); $this->Cell(62, 8, $this->t('Page ' . $this->PageNo() . ' of {nb}'), 0, 0, 'R');
    }
    function h2(string $t): void { $this->Ln(3); $this->SetTextColor(...PDF_BRAND); $this->f('B', 12); $this->Cell(0, 7, $this->t($t), 0, 1); $this->SetTextColor(30, 40, 35); }
    function need(float $h): void { if ($this->GetY() + $h > 279) { $this->AddPage(); } }
    function tile(float $x, float $y, float $w, string $label, string $value, string $note = '', array $accent = PDF_BRAND): void {
        $this->SetFillColor(243, 246, 243); $this->Rect($x, $y, $w, 24, 'F'); $this->SetFillColor(...$accent); $this->Rect($x, $y, 1.4, 24, 'F');
        $this->SetXY($x + 4, $y + 3); $this->SetTextColor(100, 112, 106); $this->f('', 8); $this->Cell($w - 6, 4, $this->t($label)); $this->SetXY($x + 4, $y + 9); $this->SetTextColor(25, 35, 30); $this->f('B', 13); $this->Cell($w - 6, 7, $this->t($value));
        $this->SetXY($x + 4, $y + 17); $this->SetTextColor(100, 112, 106); $this->f('', 7.5); $this->Cell($w - 6, 4, $this->t($note)); $this->SetTextColor(30, 40, 35);
    }
    /** $cols = [[title, width, align]], $rows = list of cell-string lists. */
    function table(array $cols, array $rows, string $empty = 'Nothing to show.'): void {
        $this->SetFillColor(236, 241, 237); $this->SetTextColor(70, 84, 77); $this->f('B', 8.5); foreach ($cols as [$t, $w, $a]) { $this->Cell($w, 7, $this->t($t), 0, 0, $a, true); } $this->Ln();
        $this->SetTextColor(30, 40, 35); $this->f('', 9); if (!$rows) { $this->SetTextColor(110, 120, 115); $this->Cell(0, 8, $this->t($empty), 0, 1); $this->SetTextColor(30, 40, 35); return; }
        foreach ($rows as $i => $r) { $this->need(7); if ($i % 2) { $this->SetFillColor(250, 251, 250); } else { $this->SetFillColor(255, 255, 255); } foreach ($cols as $k => [$t, $w, $a]) { $this->Cell($w, 6.6, $this->t((string) ($r[$k] ?? '')), 0, 0, $a, true); } $this->Ln(); }
    }
    function bar(float $x, float $y, float $w, float $pct, array $fill = PDF_BRAND): void { $this->SetFillColor(230, 235, 231); $this->Rect($x, $y, $w, 3, 'F'); $this->SetFillColor(...$fill); $this->Rect($x, $y, max(0.4, $w * max(0, min(100, $pct)) / 100), 3, 'F'); }
}
function pdf_pct(?float $v): string { return $v === null ? '–' : ($v > 0 ? '+' : '') . round($v) . '%'; }
function pdf_chg(int $cur, int $prev): string { return $prev > 0 ? pdf_pct(($cur - $prev) / $prev * 100) : '–'; }
function pdf_font_note(MfPdf $p): string { return $p->uni ? '' : ' (Rs. = rupees)'; }

/** The monthly report as PDF bytes. */
function pdf_build_report(array $d, string $engine = 'fpdf'): string {
    $tmp = []; $p = new MfPdf(); $p->docTitle = 'Monthly report'; $p->docSub = $d['month_label'] . ' · prepared for ' . $d['user'] . ' · ' . $d['generated']; $p->AddPage(); $s = $d['sum']; $y = $p->GetY();
    $p->tile(14, $y, 43, 'Income received', fmt($s['income']), pdf_chg($s['income'], $s['prev']['income']) . ' vs ' . substr($s['prev_label'], 0, 3));
    $p->tile(60, $y, 43, 'Total expenses', fmt($s['expenses']), pdf_chg($s['expenses'], $s['prev']['expenses']) . ' vs ' . substr($s['prev_label'], 0, 3), [212, 86, 15]);
    $p->tile(106, $y, 43, 'Saved this month', fmt($s['net']), pdf_chg($s['net'], $s['prev']['net']) . ' vs ' . substr($s['prev_label'], 0, 3), [224, 168, 0]);
    $p->tile(152, $y, 44, 'Savings rate', $s['rate'] === null ? '–' : $s['rate'] . '%', 'of income received', [79, 124, 172]); $p->SetY($y + 30);
    $p->f('', 9); $p->SetTextColor(90, 102, 96); $p->MultiCell(0, 5, $p->t('Balance across accounts: ' . fmt($d['cash']) . ' · reserved for goals: ' . fmt($d['reserved']) . ' · spendable: ' . fmt($d['cash'] - $d['reserved'])), 0, 'L'); $p->SetTextColor(30, 40, 35);
    $p->h2('Income and expenses, last six months'); $img = pdf_chart_bars(array_column($d['series'], 'label'), [['Income', '#0b6b4d', array_column($d['series'], 'income')], ['Expenses', '#d4560f', array_column($d['series'], 'expenses')]]); $tmp[] = $img; $p->Image($img, 14, $p->GetY(), 182); $p->SetY($p->GetY() + 72);
    $p->need(90); $p->h2('Where the money went'); $y = $p->GetY(); $top = array_slice($d['categories'], 0, 8);
    if ($top) { $pie = pdf_chart_pie(array_map(fn($c) => [$c['name'], $c['amount']], $top)); $tmp[] = $pie; $p->Image($pie, 14, $y, 62); foreach ($top as $i => $c) { $yy = $y + 4 + $i * 7.2; $col = sscanf(PDF_COLORS[$i % count(PDF_COLORS)], '#%02x%02x%02x'); $p->SetFillColor(...$col); $p->Rect(84, $yy + 1, 3.6, 3.6, 'F'); $p->SetXY(90, $yy); $p->f('', 9); $p->Cell(58, 6, $p->t($c['name']), 0, 0); $p->Cell(26, 6, $p->t(fmt($c['amount'])), 0, 0, 'R'); $p->Cell(18, 6, $p->t($c['share'] . '%'), 0, 0, 'R'); } $p->SetY($y + 66); }
    else { $p->f('', 9); $p->Cell(0, 8, $p->t('No spending recorded this month.'), 0, 1); }
    $p->need(60); $p->h2('Spending by category'); $p->table([['Category', 70, 'L'], ['This month', 36, 'R'], ['Share', 22, 'R'], [$d['sum']['prev_label'], 32, 'R'], ['Change', 22, 'R']], array_map(fn($c) => [$c['name'], fmt($c['amount']), $c['share'] . '%', $c['prev'] ? fmt($c['prev']) : '–', pdf_pct($c['change'])], $d['categories']), 'No expenses this month.');
    $p->need(50); $p->h2('Top payees'); $p->table([['Paid to', 90, 'L'], ['Payments', 30, 'R'], ['Total', 62, 'R']], array_map(fn($x) => [$x['name'], (string) $x['count'], fmt($x['amount'])], $d['payees']), 'No payments yet.');
    $p->need(46); $p->h2('Month on month'); $p->table([['', 60, 'L'], [$d['month_label'], 40, 'R'], [$s['prev_label'], 40, 'R'], ['Change', 42, 'R']], [['Income', fmt($s['income']), fmt($s['prev']['income']), pdf_chg($s['income'], $s['prev']['income'])], ['Expenses', fmt($s['expenses']), fmt($s['prev']['expenses']), pdf_chg($s['expenses'], $s['prev']['expenses'])], ['Saved', fmt($s['net']), fmt($s['prev']['net']), pdf_chg($s['net'], $s['prev']['net'])]]);
    if ($d['budget']['has']) { $p->need(24); $p->h2('Budget'); $pc = $d['budget']['total'] ? $d['budget']['spent'] / $d['budget']['total'] * 100 : 0; $y = $p->GetY(); $p->bar(14, $y + 1, 120, $pc, $pc > 100 ? [180, 35, 24] : PDF_BRAND); $p->SetXY(138, $y - 1); $p->f('', 9); $p->Cell(58, 6, $p->t(fmt($d['budget']['spent']) . ' of ' . fmt($d['budget']['total']) . ' (' . round($pc) . '%)'), 0, 1, 'R'); $p->Ln(4); }
    $p->need(40); $p->h2('Savings goals'); if (!$d['goals']) { $p->f('', 9); $p->Cell(0, 7, $p->t('No goals yet.'), 0, 1); }
    foreach ($d['goals'] as $g) { $p->need(12); $y = $p->GetY(); $p->f('', 9); $p->SetXY(14, $y); $p->Cell(70, 5, $p->t($g['name'] . ($g['behind'] ? ' (behind plan)' : ($g['complete'] ? ' (reached)' : ''))), 0, 0); $p->Cell(112, 5, $p->t(fmt($g['saved']) . ' of ' . fmt($g['target']) . ' · ' . $g['percent'] . '%'), 0, 1, 'R'); $p->bar(14, $y + 6, 182, (float) $g['percent'], $g['behind'] ? [212, 86, 15] : PDF_BRAND); $p->SetY($y + 11); }
    $p->need(40); $p->h2('Upcoming bills'); $p->table([['Bill', 90, 'L'], ['Due', 40, 'L'], ['Amount', 52, 'R']], array_map(fn($b) => [$b['name'], date_label($b['due']) . ($b['overdue'] ? ' (overdue)' : ''), fmt($b['amount'])], $d['bills']), 'Nothing due soon.');
    $out = $p->Output('S'); foreach ($tmp as $f) { @unlink($f); } return $out;
}
/** The spending analysis as PDF bytes. */
function pdf_build_analysis(array $d): string {
    $tmp = []; $p = new MfPdf(); $p->docTitle = 'Spending analysis'; $p->docSub = $d['month_label'] . ' · prepared for ' . $d['user'] . ' · ' . $d['generated']; $p->AddPage(); $s = $d['sum']; $y = $p->GetY(); $cats = $d['categories']; $biggest = $cats[0] ?? null;
    $p->tile(14, $y, 43, 'Spent', fmt($s['expenses']), pdf_chg($s['expenses'], $s['prev']['expenses']) . ' vs ' . substr($s['prev_label'], 0, 3), [212, 86, 15]); $p->tile(60, $y, 43, 'Categories', (string) count($cats), 'with spending'); $p->tile(106, $y, 43, 'Biggest category', $biggest ? $biggest['name'] : '–', $biggest ? fmt($biggest['amount']) . ' · ' . $biggest['share'] . '%' : '', [224, 168, 0]);
    $days = max(1, count($d['daily'])); $p->tile(152, $y, 44, 'Average per day', fmt((int) round($s['expenses'] / $days)), 'over ' . $days . ' days', [79, 124, 172]); $p->SetY($y + 30);
    $p->h2('Spending through the month (running total)'); if ($d['daily']) { $img = pdf_chart_line(array_map(fn($i) => (string) ($i + 1), array_keys($d['daily'])), $d['daily'], '#d4560f'); $tmp[] = $img; $p->Image($img, 14, $p->GetY(), 182); $p->SetY($p->GetY() + 72); }
    $p->need(90); $p->h2('Category split'); $top = array_slice($cats, 0, 8); $y = $p->GetY();
    if ($top) { $pie = pdf_chart_pie(array_map(fn($c) => [$c['name'], $c['amount']], $top)); $tmp[] = $pie; $p->Image($pie, 14, $y, 62); foreach ($top as $i => $c) { $yy = $y + 4 + $i * 7.2; $col = sscanf(PDF_COLORS[$i % count(PDF_COLORS)], '#%02x%02x%02x'); $p->SetFillColor(...$col); $p->Rect(84, $yy + 1, 3.6, 3.6, 'F'); $p->SetXY(90, $yy); $p->f('', 9); $p->Cell(58, 6, $p->t($c['name']), 0, 0); $p->Cell(26, 6, $p->t(fmt($c['amount'])), 0, 0, 'R'); $p->Cell(18, 6, $p->t($c['share'] . '%'), 0, 0, 'R'); } $p->SetY($y + 66); }
    $p->need(60); $p->h2('Categories compared with ' . $s['prev_label']); $p->table([['Category', 70, 'L'], ['This month', 36, 'R'], [$s['prev_label'], 36, 'R'], ['Change', 40, 'R']], array_map(fn($c) => [$c['name'], fmt($c['amount']), $c['prev'] ? fmt($c['prev']) : '–', pdf_pct($c['change'])], $cats), 'No expenses this month.');
    $p->need(50); $p->h2('How you paid'); $p->table([['Method', 90, 'L'], ['Total', 50, 'R'], ['Share', 42, 'R']], array_map(fn($m) => [$m['name'], fmt($m['amount']), $s['expenses'] > 0 ? round($m['amount'] / $s['expenses'] * 100) . '%' : '–'], $d['methods']), 'No payments yet.');
    $p->need(50); $p->h2('Top payees'); $p->table([['Paid to', 90, 'L'], ['Payments', 30, 'R'], ['Total', 62, 'R']], array_map(fn($x) => [$x['name'], (string) $x['count'], fmt($x['amount'])], $d['payees']), 'No payments yet.');
    $p->need(60); $p->h2('Six-month view'); $img = pdf_chart_bars(array_column($d['series'], 'label'), [['Income', '#0b6b4d', array_column($d['series'], 'income')], ['Expenses', '#d4560f', array_column($d['series'], 'expenses')]]); $tmp[] = $img; $p->Image($img, 14, $p->GetY(), 182);
    $out = $p->Output('S'); foreach ($tmp as $f) { @unlink($f); } return $out;
}

// ============================ ReportLab engine (optional) ============================
function pdf_build_reportlab(array $d, string $kind): ?string {
    if (!pdf_reportlab_available() || !gd_png_ok()) { return null; } $dir = sys_get_temp_dir() . '/mfpdf-' . bin2hex(random_bytes(6)); if (!@mkdir($dir, 0700)) { return null; } $tmp = [];
    try {
        $d['charts'] = ['bars' => pdf_chart_bars(array_column($d['series'], 'label'), [['Income', '#0b6b4d', array_column($d['series'], 'income')], ['Expenses', '#d4560f', array_column($d['series'], 'expenses')]])];
        if ($d['categories']) { $d['charts']['pie'] = pdf_chart_pie(array_map(fn($c) => [$c['name'], $c['amount']], array_slice($d['categories'], 0, 8))); }
        if ($kind === 'analysis' && $d['daily']) { $d['charts']['line'] = pdf_chart_line(array_map(fn($i) => (string) ($i + 1), array_keys($d['daily'])), $d['daily'], '#d4560f'); }
        $tmp = array_values($d['charts']); $d['kind'] = $kind; $d['app'] = APP_NAME; $d['font'] = realpath(__DIR__ . '/../assets/fonts/DejaVuSans.ttf') ?: ''; $d['font_bold'] = realpath(__DIR__ . '/../assets/fonts/DejaVuSans-Bold.ttf') ?: '';
        foreach (['income', 'expenses', 'net'] as $k) { $d['sum']['fmt_' . $k] = fmt($d['sum'][$k]); } $d['fmt'] = ['cash' => fmt($d['cash']), 'reserved' => fmt($d['reserved'])];
        foreach ($d['categories'] as &$c) { $c['fmt'] = fmt($c['amount']); $c['fmt_prev'] = $c['prev'] ? fmt($c['prev']) : '–'; } unset($c); foreach ($d['payees'] as &$c) { $c['fmt'] = fmt($c['amount']); } unset($c);
        foreach ($d['goals'] as &$c) { $c['fmt'] = fmt($c['saved']) . ' of ' . fmt($c['target']); } unset($c); foreach ($d['bills'] as &$c) { $c['fmt'] = fmt($c['amount']); $c['due_fmt'] = date_label($c['due']); } unset($c);
        file_put_contents($dir . '/in.json', json_encode($d, JSON_UNESCAPED_UNICODE)); [$code] = pdf_run(['python3', '-I', __DIR__ . '/vendor/pdf_reportlab.py', $dir . '/in.json', $dir . '/out.pdf'], 25);
        $pdf = $code === 0 && is_file($dir . '/out.pdf') ? (string) file_get_contents($dir . '/out.pdf') : ''; return str_starts_with($pdf, '%PDF') ? $pdf : null;
    } catch (Throwable $e) { log_error($e); return null; }
    finally { foreach ($tmp as $f) { @unlink($f); } @unlink($dir . '/in.json'); @unlink($dir . '/out.pdf'); @rmdir($dir); }
}
/** Builds a PDF with the chosen engine; ReportLab problems fall back to FPDF. Returns [bytes, engine used]. */
function pdf_generate(int $uid, string $kind, string $month, ?string $engine = null): array {
    $kind = $kind === 'analysis' ? 'analysis' : 'report'; $d = pdf_report_data($uid, $month); $engine ??= pdf_engine();
    if ($engine === 'reportlab') { $b = pdf_build_reportlab($d, $kind); if ($b !== null) { return [$b, 'reportlab']; } }
    return [$kind === 'analysis' ? pdf_build_analysis($d) : pdf_build_report($d), 'fpdf'];
}
function pdf_page_count(string $pdf): int { return preg_match_all('~/Type\s*/Page\b(?!s)~', $pdf) ?: 0; }
function pdf_filename(string $kind, string $month): string { return ($kind === 'analysis' ? 'spending-analysis-' : 'monthly-report-') . $month . '.pdf'; }

// ============================ signed links ============================
function pdf_link_epoch(int $uid): int { return (int) site_setting('pdf_rev_' . $uid, '0'); }
function pdf_signed_token(int $uid, string $kind, string $month, int $ttl = 604800): string {
    $p = c_b64u(json_encode(['u' => $uid, 'k' => $kind === 'analysis' ? 'analysis' : 'report', 'm' => $month, 'e' => time() + max(60, min($ttl, 30 * 86400)), 'r' => pdf_link_epoch($uid)])); return $p . '.' . substr(c_hmac('pdf|' . $p), 0, 32);
}
/** Returns the payload when the token is genuine, unexpired and not revoked, otherwise null. */
function pdf_verify_token(string $tok): ?array {
    $x = explode('.', $tok); if (count($x) !== 2 || !hash_equals(substr(c_hmac('pdf|' . $x[0]), 0, 32), $x[1])) { return null; } $p = json_decode(c_b64u_dec($x[0]), true);
    if (!is_array($p) || (int) ($p['e'] ?? 0) < time() || (int) ($p['r'] ?? -1) !== pdf_link_epoch((int) ($p['u'] ?? 0)) || !preg_match('/^\d{4}-\d{2}$/', (string) ($p['m'] ?? '')) || !in_array($p['k'] ?? '', ['report', 'analysis'], true)) { return null; } return $p;
}
function pdf_shared_download(string $tok): void {
    if (!c_rate_ok('pdfshare:' . client_ip(), 20, 60)) { c_status(429); echo 'Too many requests.'; return; } $p = pdf_verify_token($tok);
    if (!$p) { c_status(404); c_hdr('Content-Type: text/plain; charset=utf-8'); c_hdr('X-Robots-Tag: noindex'); echo 'This link has expired or is not valid.'; return; }
    $u = one('SELECT * FROM {users} WHERE id=?', [(int) $p['u']]); if (!$u || $u['status'] !== 'active' || pdf_gate($u) !== null) { c_status(404); echo 'This link has expired or is not valid.'; return; }
    [$bytes] = pdf_generate((int) $p['u'], $p['k'], $p['m']); pdf_send($bytes, pdf_filename($p['k'], $p['m']), false);
}
function pdf_send(string $bytes, string $name, bool $download = true): void {
    c_hdr('Content-Type: application/pdf'); c_hdr('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $name . '"'); c_hdr('Content-Length: ' . strlen($bytes)); c_hdr('Cache-Control: private, no-store'); c_hdr('X-Robots-Tag: noindex'); echo $bytes;
}

// ============================ HTTP + actions ============================
function pdf_month_param(): string { return month_param(); }
function pdf_http(string $kind): void {
    $u = require_login(); if ($g = pdf_gate($u)) { flash('err', $g); redirect(url('/plugins') . '#plug-pdf_export'); } $m = pdf_month_param();
    if (!c_rate_ok('pdf:' . $u['id'], 12, 60)) { flash('err', 'Please wait a moment before making another PDF.'); redirect(url('/reports')); }
    [$bytes] = pdf_generate((int) $u['id'], $kind, $m); if (function_exists('charge_unit') && feature_cfg('pdf_export')['mode'] === 'payg') { charge_unit($u, 'pdf_export', 'PDF ' . $kind); } pdf_send($bytes, pdf_filename($kind, $m), empty($_GET['inline']));
}
function pdf_http_report(): void { pdf_http('report'); }
function pdf_http_analysis(): void { pdf_http('analysis'); }
/** The "Download PDF / Email me / Share link" strip used on Reports and Analysis. */
function pdf_actions_html(array $u, string $kind, string $month): string {
    $fa = feature_access($u, 'pdf_export'); $kindLabel = $kind === 'analysis' ? 'analysis' : 'report';
    if (!$fa['ok']) { return '<a class="btn btn-sm" href="' . e(url('/plugins') . '#plug-pdf_export') . '" title="' . e($fa['reason']) . '">' . icon('file-down', 'size-4') . 'PDF (plugin)</a>'; }
    return '<a class="btn btn-sm" href="' . e(url('/pdf/' . $kind, ['month' => $month])) . '" download>' . icon('file-down', 'size-4') . 'Download PDF</a>'
        . post_btn(icon('mail', 'size-4') . 'Email me', 'pdf-email', ['kind' => $kind, 'month' => $month], 'btn btn-sm') . post_btn(icon('link', 'size-4') . 'Share link', 'pdf-link', ['kind' => $kind, 'month' => $month], 'btn btn-sm', 'title="A link that works for 7 days without signing in"');
}
function do_pdf_email(array $u): string {
    if ($g = pdf_gate($u)) { throw new UserError($g); } $kind = in_enum('kind', ['report', 'analysis'], 'report'); $m = in_str('month', 7); if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) { throw new UserError('Pick a month.'); }
    if (!c_rate_ok('pdfmail:' . $u['id'], 5, 600)) { throw new UserError('You can email up to 5 PDFs every 10 minutes.'); }
    $c = email_cfg(); if (!$c['enabled'] || !email_configured($c)) { throw new UserError('Email is not set up on this server yet, so the PDF cannot be sent. You can still download it.'); }
    [$bytes] = pdf_generate((int) $u['id'], $kind, $m); $name = pdf_filename($kind, $m); $label = $kind === 'analysis' ? 'spending analysis' : 'monthly report';
    [$ok, $err] = send_email((string) $u['email'], APP_NAME . ': your ' . $label . ' for ' . month_label($m), "Hi " . explode(' ', (string) $u['name'])[0] . ",\n\nYour " . $label . ' for ' . month_label($m) . " is attached as a PDF.\n\n" . APP_NAME, (int) $u['id'], 'pdf.export', [['name' => $name, 'type' => 'application/pdf', 'data' => $bytes]]);
    if (!$ok) { throw new UserError('The email could not be sent: ' . $err); } return 'Sent to ' . $u['email'] . '.';
}
function do_pdf_link(array $u): string {
    if ($g = pdf_gate($u)) { throw new UserError($g); } $kind = in_enum('kind', ['report', 'analysis'], 'report'); $m = in_str('month', 7); if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) { throw new UserError('Pick a month.'); }
    $url = abs_url('/pdf/shared/' . pdf_signed_token((int) $u['id'], $kind, $m)); $_SESSION['pdf_link'] = $url; return 'Link ready (valid for 7 days): ' . $url;
}
function do_pdf_revoke_links(array $u): string { set_setting('pdf_rev_' . (int) $u['id'], (string) (pdf_link_epoch((int) $u['id']) + 1)); return 'All your earlier PDF share links no longer work.'; }
function do_admin_pdf_settings(array $u): string {
    $e = in_enum('pdf_engine', ['fpdf', 'reportlab'], 'fpdf'); if ($e === 'reportlab' && !pdf_reportlab_available()) { throw new UserError('python3 with reportlab was not found on this server, so the engine stays on FPDF.'); } set_setting('pdf_engine', $e); audit('admin.pdf', 'site', $e); return 'PDF engine saved.';
}
