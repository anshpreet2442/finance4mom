<?php
declare(strict_types=1);

function page_reports(): void {
    require_login(); $s = snap(); $month = month_param(); $cur = month_of($s['today']); $prevM = add_months($month, -1);
    $sum = summarize_month($s['accounts'], $s['txs'], $s['contribs'], $month);
    $plan = get_plan($s['uid'], $cur); $planTotal = $plan ? array_sum(array_column($plan['lines'], 'amount')) : null;
    $avail = $plan ? planner_inputs($s, $cur)['available'] : null;
    $purchaseBudget = $plan ? array_sum(array_map(fn($l) => in_array($l['section'], ['house', 'big', 'occasional'], true) ? (int) $l['amount'] : 0, $plan['lines'])) : null;
    $insights = insights_for($s, $planTotal !== null ? $avail - $planTotal : null, $purchaseBudget);

    $incomeByCat = []; foreach ($s['txs'] as $t) { if ($t['type'] === 'income' && $t['status'] === 'completed' && month_of($t['date']) === $month) { $k = $t['category_id'] ? cat_name($s, (int) $t['category_id']) : 'Uncategorised'; $incomeByCat[$k] = ($incomeByCat[$k] ?? 0) + $t['amount']; } }
    $roll = function (string $m) use ($s) { $o = []; foreach (spend_by_category($s['txs'], $m) as $id => $v) { $k = $id ? (cat_top($s, $id)['name'] ?? 'Uncategorised') : 'Uncategorised'; $o[$k] = ($o[$k] ?? 0) + $v; } return $o; };
    $c1 = $roll($month); $c0 = $roll($prevM); $bt = array_column(budget_vs_actual($s, $month), 'budget', 'name');
    $catRows = []; foreach (array_unique(array_merge(array_keys($c1), array_keys($c0))) as $k) { $catRows[] = ['name' => $k, 'cur' => $c1[$k] ?? 0, 'prev' => $c0[$k] ?? 0, 'budget' => $bt[$k] ?? 0]; }
    usort($catRows, fn($a, $b) => $b['cur'] <=> $a['cur']); $totalSpend = array_sum(array_column($catRows, 'cur'));

    $m12 = months_list($cur, 12);
    $growth = ['labels' => array_map(fn($m) => substr(month_label($m, true), 0, 3), $m12), 'reserved' => array_map(fn($m) => reserved_total($s['contribs'], month_end($m)), $m12), 'cash' => array_map(fn($m) => total_cash($s['accounts'], $s['txs'], month_end($m)), $m12)];
    $occ = array_values(array_filter(occurrence_views($s), fn($o) => !$o['skipped'] && $o['outstanding'] > 0)); $yearly = array_values(array_filter(yearly_views($s), fn($y) => $y['active']));
    $forecast = [];
    for ($k = 0; $k < 12; $k++) { $m = add_months($cur, $k); $in = fn($o) => $k === 0 ? month_of($o['due_date']) <= $m : month_of($o['due_date']) === $m;
        $forecast[] = ['m' => $m, 'bills' => array_sum(array_map(fn($o) => $o['bill']['kind'] === 'bill' && $in($o) ? $o['outstanding'] : 0, $occ)), 'yr' => array_sum(array_map(fn($o) => $o['bill']['kind'] === 'yearly' && $in($o) ? $o['outstanding'] : 0, $occ))]; }
    $goals = array_values(array_filter(goal_views($s), fn($g) => $g['kind'] === 'long-term'));
    $over = []; foreach (months_list($cur, 6) as $m) { foreach (budget_vs_actual($s, $m) as $b) { if ($b['spent'] > $b['budget']) { $over[] = ['month' => $m] + $b; } } } $over = array_reverse($over);
    $cf = cashflow_forecast($s, 120); $series = month_series($s, 6, $month);
    $sevIcon = ['danger' => ['circle-x', 'text-neg'], 'warning' => ['triangle-alert', 'text-orange-dk'], 'info' => ['info', 'text-mute'], 'good' => ['circle-check', 'text-lime-dk']];

    layout_start('Reports & Insights', 'reports', $s);
    page_header('Reports & Insights', 'Plain-language checks on your money, each with the calculation behind it.', month_nav($month, '/reports'));
    card_open('Insights', 'Rule-based, from your own numbers');
    if (!$insights) { echo '<p class="text-sm text-mute">Nothing needs attention right now. Insights appear as you add transactions, budgets, bills and goals.</p>'; }
    else { echo '<ul class="divide-y divide-line">'; foreach ($insights as $i) { [$ic, $cl] = $sevIcon[$i['sev']];
        echo '<li class="flex gap-3 py-3.5">' . icon($ic, 'size-5 mt-0.5 ' . $cl) . '<div class="min-w-0 flex-1 text-sm"><p class="font-semibold">' . e($i['title']) . '</p><p class="text-mute">' . e($i['detail']) . '</p><details class="mt-1"><summary class="cursor-pointer text-xs text-mute">How this was calculated</summary><p class="mt-1 text-xs text-mute">' . e($i['calc']) . '</p></details></div>' . ($i['href'] ? '<a class="btn btn-sm self-start" href="' . e(url($i['href'])) . '">Open</a>' : '') . '</li>'; }
        echo '</ul>'; }
    card_close();

    echo '<div class="mt-5 grid gap-5 lg:grid-cols-2">';
    card_flush_open('Income & expense statement', month_label($month)); echo '<dl class="num divide-y divide-line text-sm">';
    $r = fn($l, $v, $cls = '', $pad = false) => '<div class="flex justify-between px-6 py-2.5 ' . $cls . '"><dt class="' . ($pad ? 'pl-3 text-mute' : '') . '">' . $l . '</dt><dd>' . $v . '</dd></div>';
    echo $r('Opening balance', e(fmt($sum['opening'])));
    foreach ($incomeByCat as $k => $v) { echo $r('+ ' . e($k), '<span class="text-lime-dk">' . e(fmt($v)) . '</span>', '', true); }
    echo $r('Income received', '<span class="text-lime-dk">' . e(fmt($sum['income'])) . '</span>', 'font-semibold') . $r('Available funds', e(fmt($sum['available'])), 'font-semibold');
    foreach ($catRows as $c) { if ($c['cur'] > 0) { echo $r('− ' . e($c['name']), e(fmt($c['cur'])), '', true); } }
    echo $r('Expenses (net of ' . e(fmt($sum['refunds'])) . ' refunds)', e(fmt($sum['expenses'])), 'font-semibold');
    if ($sum['adjustments'] !== 0) { echo $r('Adjustments', e(fmt($sum['adjustments']))); }
    echo $r('Remaining balance', e(fmt($sum['remaining'])), 'font-bold') . $r('Saved (income − expenses)', '<span class="' . ($sum['net'] < 0 ? 'text-neg' : 'text-lime-dk') . '">' . e(fmt($sum['net'])) . ($sum['rate'] !== null ? ' · ' . round($sum['rate']) . '%' : '') . '</span>')
        . $r('Moved to savings funds (not an expense)', e(fmt($sum['moved'])), 'text-mute');
    echo '</dl></div></section>';
    card_open('This month vs last month', month_label($month, true) . ' vs ' . month_label($prevM, true));
    echo chart_hbars(array_map(fn($c) => ['name' => $c['name'], 'a' => $c['prev'], 'b' => $c['cur']], array_slice($catRows, 0, 8)), month_label($prevM, true), month_label($month, true), false); card_close();
    echo '</div>';

    echo '<div class="mt-5">'; card_flush_open('Category-wise spending');
    if (!$catRows) { echo '<div class="p-6">' . empty_state('No spending in this month') . '</div>'; }
    else {
        echo '<div class="relative overflow-x-auto"><table class="w-full min-w-[640px]"><thead><tr class="border-b border-line"><th class="th">Category</th><th class="th text-right">Spent</th><th class="th text-right">Share</th><th class="th text-right">Budget</th><th class="th text-right">Last month</th><th class="th text-right">Change</th></tr></thead><tbody class="divide-y divide-line">';
        foreach ($catRows as $c) { $d = $c['cur'] - $c['prev'];
            echo '<tr><td class="td font-medium">' . e($c['name']) . '</td><td class="td num text-right">' . e(fmt($c['cur'])) . '</td><td class="td num text-right text-mute">' . ($totalSpend ? round($c['cur'] / $totalSpend * 100) : 0) . '%</td><td class="td num text-right ' . ($c['budget'] && $c['cur'] > $c['budget'] ? 'text-neg' : 'text-mute') . '">' . ($c['budget'] ? e(fmt($c['budget'])) : '—') . '</td><td class="td num text-right text-mute">' . e(fmt($c['prev'])) . '</td>'
                . '<td class="td num text-right ' . ($d > 0 ? 'text-orange-dk' : ($d < 0 ? 'text-lime-dk' : 'text-mute')) . '">' . ($d === 0 ? '—' : ($d > 0 ? '+' : '−') . e(fmt(abs($d)))) . '</td></tr>'; }
        echo '</tbody></table></div>';
    }
    echo '</div></section></div>';

    echo '<div class="mt-5 grid gap-5 lg:grid-cols-2">';
    card_open('Savings growth', 'Last 12 months'); echo chart_lines($growth['labels'], [['name' => 'Reserved in goals & funds', 'color' => 'lime', 'values' => $growth['reserved']], ['name' => 'Cash in accounts', 'color' => 'dark', 'values' => $growth['cash']]], 240); card_close();
    card_open('Income, expenses and savings', '6 months to ' . month_label($month, true)); echo chart_lines(array_map(fn($m) => substr($m['label'], 0, 3), $series), [['name' => 'Income', 'color' => 'lime', 'values' => array_column($series, 'income')], ['name' => 'Expenses', 'color' => 'orange', 'values' => array_column($series, 'expenses')], ['name' => 'Saved', 'color' => 'dark', 'values' => array_column($series, 'net')]], 240); card_close();
    echo '</div><div class="mt-5 grid gap-5 lg:grid-cols-2">';
    card_flush_open('Annual expense forecast', 'Unpaid bills and yearly obligations by due month. Recommended monthly set-aside for yearly items: ' . e(fmt(array_sum(array_column($yearly, 'standard')))) . '.');
    echo '<div class="relative overflow-x-auto"><table class="w-full min-w-[400px]"><thead><tr class="border-b border-line"><th class="th">Month</th><th class="th text-right">Bills</th><th class="th text-right">Yearly</th><th class="th text-right">Total</th></tr></thead><tbody class="divide-y divide-line">';
    foreach ($forecast as $f) { $t = $f['bills'] + $f['yr']; echo '<tr><td class="td">' . e(month_label($f['m'])) . '</td><td class="td num text-right">' . ($f['bills'] ? e(fmt($f['bills'])) : '—') . '</td><td class="td num text-right">' . ($f['yr'] ? e(fmt($f['yr'])) : '—') . '</td><td class="td num text-right font-semibold">' . ($t ? e(fmt($t)) : '—') . '</td></tr>'; }
    echo '<tr class="bg-soft/60 font-semibold"><td class="td">Next 12 months</td><td class="td num text-right">' . e(fmt(array_sum(array_column($forecast, 'bills')))) . '</td><td class="td num text-right">' . e(fmt(array_sum(array_column($forecast, 'yr')))) . '</td><td class="td num text-right">' . e(fmt(array_sum(array_column($forecast, 'bills')) + array_sum(array_column($forecast, 'yr')))) . '</td></tr></tbody></table></div></div></section>';
    card_flush_open('Goal completion forecast');
    if (!$goals) { echo '<div class="p-6">' . empty_state('No goals yet') . '</div>'; }
    else {
        echo '<div class="relative overflow-x-auto"><table class="w-full min-w-[460px]"><thead><tr class="border-b border-line"><th class="th">Goal</th><th class="th text-right">Remaining</th><th class="th">Finish at plan</th><th class="th">Target</th><th class="th">Status</th></tr></thead><tbody class="divide-y divide-line">';
        foreach ($goals as $g) { echo '<tr><td class="td font-medium">' . e($g['name']) . '</td><td class="td num text-right">' . e(fmt($g['remaining'])) . '</td><td class="td num text-mute">' . ($g['est'] ? e(month_label($g['est'], true)) : '—') . '</td><td class="td num text-mute">' . ($g['target_date'] ? e(month_label(month_of($g['target_date']), true)) : '—') . '</td><td class="td">' . ($g['complete'] ? badge('Complete', 'pos') : ($g['behind'] ? badge('Behind', 'warn') : ($g['target_date'] ? badge('On track', 'pos') : badge('No date')))) . '</td></tr>'; }
        echo '</tbody></table></div>';
    }
    echo '</div></section></div>';

    echo '<div class="mt-5 grid gap-5 lg:grid-cols-2">';
    card_flush_open('Budget overspending', 'Last 6 months');
    if (!$over) { echo '<p class="p-6 text-sm text-mute">No category went over its budget in the last six months' . (budget_vs_actual($s, $cur) ? '' : ' (no budgets are set yet)') . '.</p>'; }
    else { echo '<div class="relative overflow-x-auto"><table class="w-full min-w-[440px]"><thead><tr class="border-b border-line"><th class="th">Month</th><th class="th">Category</th><th class="th text-right">Budget</th><th class="th text-right">Spent</th><th class="th text-right">Over by</th></tr></thead><tbody class="divide-y divide-line">';
        foreach ($over as $o) { echo '<tr><td class="td">' . e(month_label($o['month'], true)) . '</td><td class="td font-medium">' . e($o['name']) . '</td><td class="td num text-right text-mute">' . e(fmt($o['budget'])) . '</td><td class="td num text-right">' . e(fmt($o['spent'])) . '</td><td class="td num text-right text-neg">' . e(fmt($o['spent'] - $o['budget'])) . '</td></tr>'; }
        echo '</tbody></table></div>'; }
    echo '</div></section>';
    card_open('Upcoming cash-flow', 'Next 4 months');
    if (!$cf['points']) { echo '<p class="text-sm text-mute">No unpaid bills or planned items ahead.</p>'; }
    else {
        echo $cf['shortfall'] ? '<p class="mb-3 rounded-2xl bg-neg/10 px-4 py-3 text-sm text-neg">Cash may run short on ' . e(date_label($cf['shortfall']['date'])) . ' (' . e($cf['shortfall']['label']) . '): balance would be ' . e(fmt($cf['shortfall']['balance'])) . '.</p>'
            : '<p class="mb-3 rounded-2xl bg-lime/20 px-4 py-3 text-sm text-lime-dk">No shortfall expected. Lowest projected balance: ' . e(fmt($cf['lowest']['balance'])) . ' on ' . e(date_label($cf['lowest']['date'])) . '.</p>';
        echo '<ul class="max-h-64 divide-y divide-line overflow-y-auto text-sm">'; foreach ($cf['points'] as $p) { echo '<li class="flex justify-between gap-2 py-2"><span class="min-w-0 truncate"><span class="num text-mute">' . e(date_label($p['date'])) . '</span> · ' . e($p['label']) . '</span><span class="num shrink-0 ' . ($p['balance'] < 0 ? 'text-neg' : '') . '">' . e(fmt($p['balance'])) . '</span></li>'; }
        echo '</ul><p class="mt-2 text-xs text-mute">Starts from cash in accounts today, then applies expected income, unpaid bills and planned expenses in date order. It is arithmetic on your entries, not a prediction.</p>';
    }
    card_close(); echo '</div>';
    layout_end();
}
