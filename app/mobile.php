<?php
declare(strict_types=1);
/** Ember mobile pieces: hero header, bubble chart packing, and the transaction-analysis numbers. */

function fmt_parts(int $minor, ?array $p = null): array {
    $p ??= prefs(); $a = abs($minor); $dec = in_array($p['locale'], ['de-DE', 'fr-FR'], true) ? ',' : '.';
    return [fmt(($minor < 0 ? -1 : 1) * intdiv($a, 100) * 100, $p), $dec . str_pad((string) ($a % 100), 2, '0', STR_PAD_LEFT)];
}

function cat_icon(string $name): string {
    $n = mb_strtolower($name);
    foreach ([['grocer', 'shopping-cart'], ['cloth', 'shirt'], ['shop', 'shirt'], ['health', 'heart-pulse'], ['medic', 'heart-pulse'], ['food', 'utensils'], ['dining', 'utensils'], ['cafe', 'coffee'], ['restaurant', 'utensils'], ['travel', 'plane'], ['transport', 'bus-front'], ['entertain', 'film'], ['personal care', 'sparkles'], ['educat', 'graduation-cap'], ['electric', 'zap'], ['gas', 'flame'], ['internet', 'wifi'], ['mobile', 'wifi'], ['rent', 'house'], ['household', 'house'], ['home', 'house'], ['insur', 'shield-check'], ['subscr', 'repeat'], ['clean', 'spray-can'], ['repair', 'wrench'], ['maint', 'wrench'], ['festival', 'gift'], ['gift', 'gift'], ['emerg', 'siren'], ['sport', 'dumbbell'], ['salary', 'briefcase'], ['business', 'briefcase'], ['interest', 'percent'], ['other', 'circle-dot'], ['uncat', 'circle-dot'], ['misc', 'circle-dot']] as [$k, $ic]) { if (str_contains($n, $k)) { return $ic; } }
    return 'circle-dot';
}

/** Ember header for phones: avatar, bell, balance, chip and the stacked-card peek. */
function m_hero(array $s, array $u, string $variant = 'home', string $extra = ''): string {
    $cash = total_cash($s['accounts'], $s['txs'], $s['today']); $reserved = reserved_total($s['contribs']); $month = month_of($s['today']);
    $sum = summarize_month($s['accounts'], $s['txs'], $s['contribs'], $month); [$main, $dec] = fmt_parts($cash);
    $dot = (int) val('SELECT COUNT(*) FROM {notifications} WHERE user_id=? AND is_read=0', [$u['id']]) > 0;
    $growth = $sum['opening'] > 0 ? ($cash - $sum['opening']) / $sum['opening'] * 100 : null;
    $chip = $growth !== null ? icon($growth >= 0 ? 'arrow-up-right' : 'arrow-down-right', 'size-4') . number_format(abs($growth), 2) . '% this month' : 'Saved ' . fmt($sum['net']) . ' this month';
    $accts = active_accounts($s); $first = $accts[0]['name'] ?? 'Account';
    $o = '<header class="m-hero lg:hidden ' . ($variant === 'home' ? 'pb-0' : 'pb-9') . '"><div class="flex items-center justify-between px-5 pt-[calc(env(safe-area-inset-top)+18px)]">'
        . '<button type="button" data-nav-open class="grid size-12 place-items-center overflow-hidden rounded-full m-glass text-lg font-semibold" aria-label="Open menu">' . e(mb_strtoupper(mb_substr($u['name'], 0, 1))) . '</button>'
        . '<a href="' . e(url('/dues')) . '" class="relative grid size-12 place-items-center rounded-full m-glass" aria-label="Reminders">' . icon('bell', 'size-5') . ($dot ? '<span class="absolute right-3 top-3 size-2.5 rounded-full bg-amber-300 ring-2 ring-red-700/40"></span>' : '') . '</a></div>'
        . '<div class="mt-' . ($variant === 'home' ? '7' : '5') . ' px-5 text-center"><p class="text-lg text-white/90">' . ($variant === 'home' ? 'Balance' : e($extra ?: 'Balance')) . '</p><p class="m-amount mt-2"><span data-count data-live="cash">' . e($main) . '</span><small>' . e($dec) . '</small></p><span class="m-chip mt-4">' . $chip . '</span></div>';
    if ($variant === 'home') {
        $o .= '<div class="m-stack" aria-hidden="true"><i></i><i></i><i><span>•••• ' . e(mb_substr($first, 0, 12)) . '</span><span>Spendable ' . e(fmt($cash - $reserved)) . '</span></i></div>';
    } else { $o .= '<div class="mt-6 flex justify-center gap-3 px-5 text-sm"><span class="m-chip">' . icon('arrow-down-left', 'size-4') . 'In ' . e(fmt($sum['income'])) . '</span><span class="m-chip">' . icon('arrow-up-right', 'size-4') . 'Out ' . e(fmt($sum['expenses'])) . '</span></div>'; }
    return $o . '</header>';
}
/** Income | swap | Expense pills under the hero. */
function m_actions(): string {
    return '<div class="flex items-center gap-2.5 px-5 pb-1 pt-5 lg:hidden"><button type="button" class="m-act" data-tx-new="income">' . icon('arrow-up-right', 'size-5') . ' Income</button><button type="button" class="m-swap" data-tx-new="transfer" aria-label="Transfer between accounts">' . icon('arrow-up-down', 'size-5') . '</button><button type="button" class="m-act" data-tx-new="expense">' . icon('arrow-down-right', 'size-5') . ' Expense</button></div>';
}

/** Packs circles (area ∝ value) into a width×(1.15 width) box. Returns [['x','y','r','name','value','pct'], ...] in container units. */
function pack_bubbles(array $items, float $H = 1.15): array {
    $items = array_values(array_filter($items, fn($i) => $i['value'] > 0)); if (!$items) { return []; }
    usort($items, fn($a, $b) => $b['value'] <=> $a['value']);
    if (count($items) > 8) { $rest = array_slice($items, 7); $items = array_slice($items, 0, 7); $items[] = ['name' => 'Other', 'value' => array_sum(array_column($rest, 'value'))]; }
    $total = array_sum(array_column($items, 'value')); $vmax = $items[0]['value']; $placed = [];
    foreach ($items as $i => $it) {
        $r = max(0.082, 0.285 * sqrt($it['value'] / $vmax)); $spot = null;
        for ($t = 0; $t < 4000 && !$spot; $t++) {
            $ang = $t * 0.62; $rad = 0.0035 * $t; $x = 0.5 + $rad * cos($ang); $y = $H / 2 + $rad * sin($ang) * 0.9;
            if ($x - $r < 0.012 || $x + $r > 0.988 || $y - $r < 0.012 || $y + $r > $H - 0.012) { continue; }
            $ok = true; foreach ($placed as $p) { if (hypot($x - $p['x'], $y - $p['y']) < $r + $p['r'] + 0.016) { $ok = false; break; } }
            if ($ok) { $spot = [$x, $y]; }
        }
        if (!$spot) { continue; }
        $placed[] = ['x' => $spot[0], 'y' => $spot[1], 'r' => $r, 'name' => $it['name'], 'value' => $it['value'], 'pct' => $it['value'] / $total * 100];
    }
    return $placed;
}
function chart_bubbles(array $items): string {
    $b = pack_bubbles($items); if (!$b) { return '<div class="grid place-items-center py-16 text-sm text-white/80">Nothing to show for this period.</div>'; }
    $H = 1.15; $o = '<div class="bubbles" style="aspect-ratio: 1 / ' . $H . '" role="list" aria-label="Share of spending by category">';
    foreach ($b as $i => $x) {
        $small = $x['r'] < 0.11; $tiny = $x['r'] < 0.093;
        $o .= '<div class="bubble ' . ($i === 0 ? 'is-top' : '') . '" role="listitem" tabindex="0" style="left:' . round($x['x'] * 100, 2) . '%;top:' . round($x['y'] / $H * 100, 2) . '%;width:' . round($x['r'] * 200, 2) . '%;--p:' . round($x['pct'], 1) . '%;--d:' . round($i * 0.07, 2) . 's" title="' . e($x['name'] . ': ' . fmt($x['value'])) . '">'
            . '<span>' . icon(cat_icon($x['name']), $tiny ? 'size-4' : ($small ? 'size-5' : 'size-7')) . ($tiny ? '' : '<span class="' . ($small ? 'max-w-[4.4rem] text-[10px]' : 'max-w-[7rem] text-xs') . ' truncate">' . e($x['name']) . '</span>') . '<b class="' . ($small ? 'text-xs' : 'text-base') . ' font-medium">' . number_format($x['pct'], 1) . '%</b></span></div>';
    }
    return $o . '</div>';
}

/** Everything the Analysis page shows for one month and one direction ('expense' | 'income'). */
function analysis_data(array $s, string $month, string $type): array {
    $isExp = $type === 'expense'; $prev = add_months($month, -1); $rows = []; $prevTotal = 0; $total = 0;
    $byCat = []; $byDay = []; $byDesc = []; $byMethod = []; $biggest = [];
    foreach ($s['txs'] as $t) {
        if ($t['status'] !== 'completed') { continue; }
        $m = month_of($t['date']); $sign = $isExp ? ($t['type'] === 'expense' ? 1 : ($t['type'] === 'refund' ? -1 : 0)) : ($t['type'] === 'income' ? 1 : 0); if ($sign === 0) { continue; }
        $amt = $t['amount'] * $sign;
        if ($m === $prev) { $prevTotal += $amt; }
        if ($m !== $month) { continue; }
        $total += $amt; $rows[] = $t;
        $k = $t['category_id'] ? (cat_top($s, (int) $t['category_id'])['name'] ?? 'Uncategorised') : 'Uncategorised'; $byCat[$k] = ($byCat[$k] ?? 0) + $amt;
        $d = (int) substr($t['date'], 8, 2); $byDay[$d] = ($byDay[$d] ?? 0) + $amt;
        $desc = trim($t['description']) ?: cat_name($s, $t['category_id'] ? (int) $t['category_id'] : null); $e = $byDesc[$desc] ?? ['n' => 0, 'v' => 0]; $byDesc[$desc] = ['n' => $e['n'] + 1, 'v' => $e['v'] + $amt];
        $pm = $t['payment_method'] ?: 'Not specified'; $byMethod[$pm] = ($byMethod[$pm] ?? 0) + $amt;
        if ($sign > 0) { $biggest[] = $t; }
    }
    arsort($byCat); uasort($byDesc, fn($a, $b) => $b['v'] <=> $a['v']); arsort($byMethod); usort($biggest, fn($a, $b) => $b['amount'] <=> $a['amount']);
    $days = days_in_month($month); $elapsed = $month === month_of($s['today']) ? (int) substr($s['today'], 8) : $days;
    $series = []; for ($d = 1; $d <= $days; $d++) { $series[] = $byDay[$d] ?? 0; }
    // weekday pattern over the last 90 days (average per calendar weekday)
    $wk = array_fill(0, 7, 0); $wkN = array_fill(0, 7, 0); $from = day_after_n($s['today'], -90);
    for ($d = $from; $d <= $s['today']; $d = day_after_n($d, 1)) { $wkN[(int) date('N', strtotime($d)) - 1]++; }
    foreach ($s['txs'] as $t) { if ($t['status'] === 'completed' && $t['date'] >= $from && $t['date'] <= $s['today']) { $sign = $isExp ? ($t['type'] === 'expense' ? 1 : ($t['type'] === 'refund' ? -1 : 0)) : ($t['type'] === 'income' ? 1 : 0); $wk[(int) date('N', strtotime($t['date'])) - 1] += $t['amount'] * $sign; } }
    $weekday = []; foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $i => $l) { $weekday[] = ['label' => $l, 'values' => [$wkN[$i] ? intdiv($wk[$i], $wkN[$i]) : 0]]; }
    return ['total' => $total, 'prev' => $prevTotal, 'change' => $prevTotal > 0 ? ($total - $prevTotal) / $prevTotal * 100 : null, 'count' => count($rows), 'avg_day' => $elapsed ? intdiv($total, $elapsed) : 0,
        'cats' => array_map(fn($n, $v) => ['name' => $n, 'value' => $v], array_keys($byCat), array_values($byCat)), 'series' => $series, 'descs' => array_slice($byDesc, 0, 6, true), 'methods' => array_map(fn($n, $v) => ['name' => $n, 'value' => $v], array_keys($byMethod), array_values($byMethod)),
        'biggest' => array_slice($biggest, 0, 5), 'weekday' => $weekday];
}

/** Ember header for pages that are not about the balance (dues, etc.). */
function m_banner(array $u, string $label, string $big, string $chip = ''): string {
    return '<header class="m-hero lg:hidden pb-10"><div class="flex items-center justify-between px-5 pt-[calc(env(safe-area-inset-top)+18px)]"><button type="button" data-nav-open class="grid size-12 place-items-center rounded-full m-glass text-lg font-semibold" aria-label="Open menu">' . e(mb_strtoupper(mb_substr($u['name'], 0, 1))) . '</button>'
        . '<button type="button" data-theme-toggle class="grid size-12 place-items-center rounded-full m-glass" aria-label="Toggle dark mode">' . icon('moon', 'size-5') . '</button></div>'
        . '<div class="mt-5 px-5 text-center"><p class="text-lg text-white/90">' . e($label) . '</p><p class="m-amount mt-2">' . $big . '</p>' . ($chip ? '<span class="m-chip mt-4">' . $chip . '</span>' : '') . '</div></header>';
}
