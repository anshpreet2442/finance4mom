<?php
declare(strict_types=1);
/**
 * Circular progress ring (M3). Server side of assets/ring.js: prints the final state as static markup (no layout shift, works without JavaScript),
 * the script then animates it from 0 the first time it is seen. The percentages are worked out here, never in the browser.
 *
 *   ring(72)                                     a plain ring, 72 %
 *   ring($spent, ['max' => $limit, 'limit' => true, 'label' => 'Groceries budget'])   amber near the limit, red when over (shows the real 112 %)
 *   ring($pct, ['size' => 'sm', 'text' => '₹3,000', 'sub' => 'left'])                  custom centre text
 */
function ring_math(float $value, float $min = 0.0, float $max = 100.0): array {
    $span = $max - $min; $real = $span > 0 ? ($value - $min) / $span * 100 : 0.0;
    return ['real' => $real, 'visual' => max(0.0, min(100.0, $real)), 'whole' => (int) round($real)];
}
/** Tone: limit rings turn amber from $warn % and red above $bad %; goal rings stay neutral until complete ('done'). */
function ring_tone(float $real, bool $limit = false, float $warn = 85.0, float $bad = 100.0): string {
    if ($limit) { return $real > $bad ? 'bad' : ($real >= $warn ? 'warn' : 'ok'); }
    return $real >= 100 ? 'done' : 'ok';
}
/** A CSS colour that is safe to print into a style attribute: #hex, rgb()/hsl()/oklch(), var(--token) or a plain colour name; anything else is dropped. */
function ring_color(string $c): string {
    $c = trim($c); if ($c === '') { return ''; }
    if (preg_match('/^#[0-9a-f]{3,8}$/i', $c) || preg_match('/^(?:rgb|rgba|hsl|hsla|oklch|oklab)\([#0-9a-z\s,.%\/-]{1,60}\)$/i', $c) || preg_match('/^var\(--[a-z0-9-]{1,30}\)$/i', $c) || preg_match('/^[a-z]{3,20}$/i', $c)) { return $c; }
    return '';
}
const RING_SIZES = ['xs' => 40, 'sm' => 56, 'md' => 80, 'lg' => 120, 'xl' => 160];
function ring(float $value, array $o = []): string {
    $o += ['min' => 0, 'max' => 100, 'limit' => false, 'warn' => 85, 'bad' => 100, 'label' => '', 'text' => null, 'sub' => '', 'format' => 'pct', 'size' => 'md', 'class' => '', 'tone' => 'auto', 'primary' => '', 'secondary' => '', 'variant' => 'track'];
    $m = ring_math($value, (float) $o['min'], (float) $o['max']); $tone = $o['tone'] !== 'auto' ? (string) $o['tone'] : ring_tone($m['real'], (bool) $o['limit'], (float) $o['warn'], (float) $o['bad']);
    $px = is_int($o['size']) ? $o['size'] : (RING_SIZES[$o['size']] ?? 96); $cls = is_string($o['size']) && isset(RING_SIZES[$o['size']]) ? ' mf-ring--' . $o['size'] : '';
    $txt = $o['text'] !== null && $o['text'] !== '' ? (string) $o['text'] : ($o['format'] === 'none' ? '' : ($o['format'] === 'raw' ? (string) $m['whole'] : $m['whole'] . '%'));
    $vt = $m['real'] > 100 ? $m['whole'] . '% (over the limit by ' . ($m['whole'] - 100) . '%)' : $m['whole'] . '%';
    $style = '--p:' . round($m['visual'], 2) . ';--s:' . (round($m['visual']) <= 90 ? 90 - (int) round($m['visual']) : 0) . ($px !== 96 && !$cls ? ';--ring-size:' . $px . 'px' : '');
    $o['primary'] = ring_color((string) $o['primary']); $o['secondary'] = ring_color((string) $o['secondary']);
    if ($o['primary'] !== '') { $style .= ';--ring-primary:' . $o['primary']; }
    if ($o['secondary'] !== '') { $style .= ';--ring-secondary:' . $o['secondary']; }
    $attrs = ' data-ring data-value="' . e((string) round((float) $value, 2)) . '"' . ($o['min'] != 0 ? ' data-min="' . e((string) $o['min']) . '"' : '') . ($o['max'] != 100 ? ' data-max="' . e((string) $o['max']) . '"' : '')
        . ($o['limit'] ? ' data-limit="1"' . ($o['warn'] != 85 ? ' data-warn="' . (float) $o['warn'] . '"' : '') . ($o['bad'] != 100 ? ' data-bad="' . (float) $o['bad'] . '"' : '') : '')
        . ($o['tone'] !== 'auto' ? ' data-tone="' . e((string) $o['tone']) . '"' : '') . ($o['text'] !== null && $o['text'] !== '' ? ' data-text="' . e($txt) . '"' : '') . ($o['format'] !== 'pct' ? ' data-format="' . e((string) $o['format']) . '"' : '')
        . ($o['variant'] === 'split' ? ' data-variant="split"' : '') . ($o['sub'] !== '' ? ' data-sub="' . e((string) $o['sub']) . '"' : '');
    if ($o['primary'] !== '') { $attrs .= ' data-primary="' . e((string) $o['primary']) . '"'; } if ($o['secondary'] !== '') { $attrs .= ' data-secondary="' . e((string) $o['secondary']) . '"'; }
    $now = max((float) $o['min'], min((float) $o['max'], $value));
    return '<div class="mf-ring' . $cls . ($o['sub'] !== '' ? ' mf-ring--has-sub' : '') . ($o['class'] !== '' ? ' ' . e((string) $o['class']) : '') . '"' . $attrs . ' data-state="' . e($tone) . '" style="' . e($style) . '" role="progressbar" aria-valuenow="' . e((string) round($now, 2)) . '" aria-valuemin="' . e((string) $o['min']) . '" aria-valuemax="' . e((string) $o['max'])
        . '" aria-valuetext="' . e($vt) . '"' . ($o['label'] !== '' ? ' aria-label="' . e((string) $o['label']) . '"' : '') . '>'
        . '<svg class="mf-ring__svg" viewBox="0 0 100 100" fill="none" aria-hidden="true"><circle class="mf-ring__track" cx="50" cy="50" r="45" stroke="currentColor" stroke-opacity=".12" stroke-width="10"/><circle class="mf-ring__arc" cx="50" cy="50" r="45" stroke="currentColor" stroke-width="10" stroke-linecap="round" stroke-dasharray="' . round($m['visual'] / 100 * 282.743, 3) . ' 282.743" transform="rotate(-90 50 50)"/></svg>'   // presentation attributes = a complete static ring even where ring.css is not loaded (previews, print, mail); the stylesheet takes over where it is
        . '<span class="mf-ring__v"><span class="mf-ring__n">' . e($txt) . '</span>' . ($o['sub'] !== '' ? '<small class="mf-ring__s">' . e((string) $o['sub']) . '</small>' : '') . '</span></div>';
}
/** A ring with a title and a line of detail beside it. */
function ring_card(float $value, string $title, string $detail = '', array $o = []): string {
    return '<div class="ring-card">' . ring($value, $o + ['label' => $title]) . '<div class="ring-card__t"><b>' . e($title) . '</b>' . ($detail !== '' ? '<small>' . e($detail) . '</small>' : '') . '</div></div>';
}
/** Percent of $part in $whole (0 when there is no whole). */
function ring_pct(float|int $part, float|int $whole): float { return $whole > 0 ? $part / $whole * 100 : 0.0; }

// ============================ usage meters (Plans, Plugins, AI header) ============================
/** What this person has used: [title, detail, percent|null, ring options]. AI budget (month), voice limits (today) and wallet credit left. */
function usage_meters(array $u): array {
    $uid = (int) $u['id']; $out = []; $exempt = is_exempt_user($u);
    if (site_setting('ai_enabled', '0') === '1' || $exempt) {
        $bu = ai_budget($uid); $sp = ai_month_spend($uid);
        if ($bu > 0 && !$exempt) { $out[] = ['AI budget this month', micro_to_inr($sp) . ' of ' . micro_to_inr($bu), ring_pct($sp, $bu), ['limit' => true, 'label' => 'AI budget used this month', 'sub' => 'used']]; }
        elseif ($sp > 0) { $out[] = ['AI usage this month', micro_to_inr($sp) . ($exempt ? '' : ', no limit set'), null, []]; }
    }
    $c = voice_cfg();
    if ($c['enabled'] && voice_el_ready($c) && !$exempt && feature_on($u, 'voice')) {
        $d = voice_usage_today($uid);
        if ($c['daily_chars'] > 0) { $out[] = ['Spoken replies today', number_format($d['chars']) . ' of ' . number_format($c['daily_chars']) . ' characters', ring_pct($d['chars'], $c['daily_chars']), ['limit' => true, 'label' => 'Voice characters used today', 'sub' => 'used']]; }
        if ($c['daily_stt_sec'] > 0) { $out[] = ['Voice typing today', round($d['seconds'] / 60, 1) . ' of ' . round($c['daily_stt_sec'] / 60, 1) . ' minutes', ring_pct($d['seconds'], $c['daily_stt_sec']), ['limit' => true, 'label' => 'Voice typing used today', 'sub' => 'used']]; }
    }
    $in = (int) val("SELECT COALESCE(SUM(amount_micro),0) FROM {wallet_ledger} WHERE user_id=? AND kind='topup' AND amount_micro>0", [$uid]); $bal = wallet_balance($uid);
    if ($in > 0) { $left = min(100.0, ring_pct(max(0, $bal), $in)); $out[] = ['Wallet credit left', micro_to_inr($bal) . ' left, ' . micro_to_inr($in) . ' added in all', $left, ['label' => 'Wallet credit left: ' . micro_to_inr($bal), 'sub' => 'left', 'tone' => $bal <= 0 ? 'bad' : ($left < 15 ? 'warn' : 'ok')]]; }
    return $out;
}
function usage_meters_html(array $u, string $title = 'Your usage'): string {
    $m = usage_meters($u); if (!$m) { return ''; }
    $o = '<section class="card p-5" aria-label="' . e($title) . '"><h2 class="text-lg font-semibold tracking-tight">' . e($title) . '</h2><div class="ring-list mt-4">';
    foreach ($m as [$t, $detail, $pct, $ro]) { $o .= $pct === null ? '<div class="ring-stack"><b class="text-sm font-semibold">' . e($t) . '</b><span class="num text-sm text-mute">' . e($detail) . '</span></div>' : '<div class="ring-stack">' . ring((float) $pct, $ro + ['size' => 'md']) . '<div><b class="block text-sm font-semibold">' . e($t) . '</b><span class="num block text-xs text-mute">' . e($detail) . '</span></div></div>'; }
    return $o . '</div></section>';
}
