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
const RING_SIZES = ['xs' => 40, 'sm' => 56, 'md' => 80, 'lg' => 120, 'xl' => 160];
function ring(float $value, array $o = []): string {
    $o += ['min' => 0, 'max' => 100, 'limit' => false, 'warn' => 85, 'bad' => 100, 'label' => '', 'text' => null, 'sub' => '', 'format' => 'pct', 'size' => 'md', 'class' => '', 'tone' => 'auto', 'primary' => '', 'secondary' => '', 'variant' => 'track'];
    $m = ring_math($value, (float) $o['min'], (float) $o['max']); $tone = $o['tone'] !== 'auto' ? (string) $o['tone'] : ring_tone($m['real'], (bool) $o['limit'], (float) $o['warn'], (float) $o['bad']);
    $px = is_int($o['size']) ? $o['size'] : (RING_SIZES[$o['size']] ?? 96); $cls = is_string($o['size']) && isset(RING_SIZES[$o['size']]) ? ' mf-ring--' . $o['size'] : '';
    $txt = $o['text'] !== null && $o['text'] !== '' ? (string) $o['text'] : ($o['format'] === 'none' ? '' : ($o['format'] === 'raw' ? (string) $m['whole'] : $m['whole'] . '%'));
    $vt = $m['real'] > 100 ? $m['whole'] . '% (over the limit by ' . ($m['whole'] - 100) . '%)' : $m['whole'] . '%';
    $style = '--p:' . round($m['visual'], 2) . ';--s:' . (round($m['visual']) <= 90 ? 90 - (int) round($m['visual']) : 0) . ($px !== 96 && !$cls ? ';--ring-size:' . $px . 'px' : '');
    if ($o['primary'] !== '') { $style .= ';--ring-primary:' . preg_replace('/[^#a-zA-Z0-9(),.%\s\/-]/', '', (string) $o['primary']); }
    if ($o['secondary'] !== '') { $style .= ';--ring-secondary:' . preg_replace('/[^#a-zA-Z0-9(),.%\s\/-]/', '', (string) $o['secondary']); }
    $attrs = ' data-ring data-value="' . e((string) round((float) $value, 2)) . '"' . ($o['min'] != 0 ? ' data-min="' . e((string) $o['min']) . '"' : '') . ($o['max'] != 100 ? ' data-max="' . e((string) $o['max']) . '"' : '')
        . ($o['limit'] ? ' data-limit="1"' . ($o['warn'] != 85 ? ' data-warn="' . (float) $o['warn'] . '"' : '') . ($o['bad'] != 100 ? ' data-bad="' . (float) $o['bad'] . '"' : '') : '')
        . ($o['tone'] !== 'auto' ? ' data-tone="' . e((string) $o['tone']) . '"' : '') . ($o['text'] !== null && $o['text'] !== '' ? ' data-text="' . e($txt) . '"' : '') . ($o['format'] !== 'pct' ? ' data-format="' . e((string) $o['format']) . '"' : '')
        . ($o['variant'] === 'split' ? ' data-variant="split"' : '') . ($o['sub'] !== '' ? ' data-sub="' . e((string) $o['sub']) . '"' : '');
    if ($o['primary'] !== '') { $attrs .= ' data-primary="' . e((string) $o['primary']) . '"'; } if ($o['secondary'] !== '') { $attrs .= ' data-secondary="' . e((string) $o['secondary']) . '"'; }
    $now = max((float) $o['min'], min((float) $o['max'], $value));
    return '<div class="mf-ring' . $cls . ($o['sub'] !== '' ? ' mf-ring--has-sub' : '') . ($o['class'] !== '' ? ' ' . e((string) $o['class']) : '') . '"' . $attrs . ' data-state="' . e($tone) . '" style="' . e($style) . '" role="progressbar" aria-valuenow="' . e((string) round($now, 2)) . '" aria-valuemin="' . e((string) $o['min']) . '" aria-valuemax="' . e((string) $o['max'])
        . '" aria-valuetext="' . e($vt) . '"' . ($o['label'] !== '' ? ' aria-label="' . e((string) $o['label']) . '"' : '') . '>'
        . '<svg class="mf-ring__svg" viewBox="0 0 100 100" fill="none" aria-hidden="true"><circle class="mf-ring__track" cx="50" cy="50" r="45"/><circle class="mf-ring__arc" cx="50" cy="50" r="45"/></svg>'
        . '<span class="mf-ring__v"><span class="mf-ring__n">' . e($txt) . '</span>' . ($o['sub'] !== '' ? '<small class="mf-ring__s">' . e((string) $o['sub']) . '</small>' : '') . '</span></div>';
}
/** A ring with a title and a line of detail beside it. */
function ring_card(float $value, string $title, string $detail = '', array $o = []): string {
    return '<div class="ring-card">' . ring($value, $o + ['label' => $title]) . '<div class="ring-card__t"><b>' . e($title) . '</b>' . ($detail !== '' ? '<small>' . e($detail) . '</small>' : '') . '</div></div>';
}
/** Percent of $part in $whole (0 when there is no whole). */
function ring_pct(float|int $part, float|int $whole): float { return $whole > 0 ? $part / $whole * 100 : 0.0; }
