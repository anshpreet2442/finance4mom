<?php
declare(strict_types=1);

function goal_fields(string $kind, ?array $g = null): array {
    $f = [['name' => 'kind', 'type' => 'hidden', 'value' => $kind]];
    if ($g) { $f[] = ['name' => 'id', 'type' => 'hidden', 'value' => $g['id']]; }
    $f[] = ['name' => 'name', 'label' => 'Name', 'required' => true, 'value' => $g['name'] ?? '', 'placeholder' => $kind === 'personal' ? 'e.g. Personal fund' : 'e.g. Emergency fund'];
    if ($kind === 'long-term') {
        array_push($f, ['name' => 'target_amount', 'label' => 'Target amount', 'type' => 'money', 'required' => true, 'half' => true, 'value' => $g ? money_input((int) $g['target_amount']) : ''],
            ['name' => 'target_date', 'label' => 'Target date', 'type' => 'date', 'half' => true, 'value' => $g['target_date'] ?? '', 'hint' => 'Optional'],
            ['name' => 'priority', 'label' => 'Priority', 'type' => 'select', 'required' => true, 'options' => priority_options(), 'half' => true, 'value' => $g['priority'] ?? 'medium'],
            ['name' => 'monthly_target', 'label' => 'Monthly contribution', 'type' => 'money', 'half' => true, 'value' => !empty($g['monthly_target']) ? money_input((int) $g['monthly_target']) : '', 'hint' => 'Your plan; leave empty if unsure.']);
    } else {
        array_push($f, ['name' => 'monthly_target', 'label' => 'Monthly allocation', 'type' => 'money', 'half' => true, 'value' => !empty($g['monthly_target']) ? money_input((int) $g['monthly_target']) : ''],
            ['name' => 'monthly_limit', 'label' => 'Monthly spending limit', 'type' => 'money', 'half' => true, 'value' => !empty($g['monthly_limit']) ? money_input((int) $g['monthly_limit']) : '', 'hint' => 'Optional']);
    }
    $f[] = ['name' => 'notes', 'label' => 'Notes (optional)', 'type' => 'textarea', 'value' => $g['notes'] ?? ''];
    return $f;
}

function page_goals(): void {
    require_login(); $s = snap(); $goals = goal_views($s); $month = month_of($s['today']);
    $long = array_values(array_filter($goals, fn($g) => $g['kind'] === 'long-term')); $personal = array_values(array_filter($goals, fn($g) => $g['kind'] === 'personal'));
    layout_start('Savings Goals', 'goals', $s);
    page_header('Savings Goals', 'Plan savings on purpose. Money you set aside is reserved, not spent, so it never counts as an expense.', dialog_form('goal-new', icon('plus', 'size-4') . ' New goal', 'New savings goal', 'goal-save', goal_fields('long-term'), 'Save', 'btn btn-dark'));
    echo '<div class="grid grid-cols-2 gap-4 lg:grid-cols-4">' . stat_card('Saved toward goals', e(fmt(array_sum(array_column($long, 'saved')))), '', 'pos') . stat_card('Still to save', e(fmt(array_sum(array_column($long, 'remaining')))))
        . stat_card('Monthly plan', e(fmt(array_sum(array_column($long, 'monthly_target')))), 'Sum of monthly contributions') . stat_card('Reserved overall', e(fmt(reserved_total($s['contribs']))), 'Goals, funds, bills, purchases') . '</div>';
    echo '<h2 class="mb-3 mt-8 text-xl font-semibold tracking-tight">Long-term goals</h2>';
    if (!$long) { echo empty_state('No goals yet', 'Start with an emergency fund. Add a target, a date and a monthly amount and the app will show whether you are on track.'); }
    else {
        echo '<div class="grid gap-4 md:grid-cols-2">';
        foreach ($long as $g) {
            $id = (int) $g['id'];
            echo '<article class="card p-6"><div class="flex items-start justify-between gap-2"><div class="min-w-0"><h3 class="truncate text-lg font-semibold">' . e($g['name']) . '</h3><p class="text-xs text-mute">' . ($g['target_date'] ? 'Target ' . e(date_label($g['target_date'])) : 'No target date') . '</p></div><div class="flex gap-1.5">' . priority_badge($g['priority']) . ($g['complete'] ? badge('Complete', 'pos') : ($g['behind'] ? badge('Behind', 'warn') : ($g['target_date'] ? badge('On track', 'pos') : ''))) . '</div></div>'
                . '<div class="mt-4">' . progress($g['percent'], $g['complete'] ? 'dark' : ($g['behind'] ? 'orange' : 'lime'), $g['name'] . ' progress') . '</div>'
                . '<div class="num mt-2 flex justify-between text-sm"><span class="font-semibold">' . e(fmt($g['saved'])) . '</span><span class="text-mute">' . round($g['percent']) . '% of ' . e(fmt((int) $g['target_amount'])) . '</span></div>'
                . '<dl class="mt-4 grid grid-cols-3 gap-2 text-sm"><div><dt class="text-xs text-mute">Remaining</dt><dd class="num font-semibold">' . e(fmt($g['remaining'])) . '</dd></div><div><dt class="text-xs text-mute">Plan / month</dt><dd class="num font-semibold">' . ($g['monthly_target'] ? e(fmt((int) $g['monthly_target'])) : '—') . '</dd></div><div><dt class="text-xs text-mute">Needed / month</dt><dd class="num font-semibold">' . ($g['required'] !== null ? e(fmt($g['required'])) : '—') . '</dd></div></dl>'
                . '<p class="mt-3 text-xs text-mute">' . ($g['complete'] ? 'Goal reached.' : ($g['est'] ? 'At the current plan you finish around ' . e(month_label($g['est'])) . ($g['target_date'] && $g['behind'] ? ', after your target of ' . e(month_label(month_of($g['target_date']))) : '') . '.' : 'Add a monthly contribution to see an estimated completion date.'))
                . ($g['behind'] && $g['required'] ? ' Calculation: ' . e(fmt($g['remaining'])) . ' ÷ ' . (int) $g['months'] . ' month' . ($g['months'] === 1 ? '' : 's') . ' = ' . e(fmt($g['required'])) . ' per month.' : '') . '</p>'
                . '<div class="mt-4 flex flex-wrap gap-1.5 border-t border-line pt-4">'
                . dialog_form("goal-add-$id", 'Add money', 'Add to ' . $g['name'], 'contribute', contribute_fields('goal', $id, $s, (int) $g['monthly_target'] ?: null), 'Save', 'btn btn-sm')
                . dialog_form("goal-edit-$id", 'Edit', 'Edit goal', 'goal-save', goal_fields('long-term', $g), 'Save changes', 'btn btn-ghost btn-sm')
                . confirm_btn('Delete', 'goal-delete', ['id' => $id], 'Delete ' . $g['name'] . '?', ($g['saved'] > 0 ? fmt($g['saved']) . ' saved here will return to your spendable balance. ' : '') . 'This cannot be undone.') . '</div>';
            $hist = array_slice(array_values(array_filter($s['contribs'], fn($c) => $c['target_type'] === 'goal' && (int) $c['target_id'] === $id)), 0, 12);
            if ($hist) {
                echo '<details class="mt-2 text-sm"><summary class="cursor-pointer text-xs text-mute">Recent contributions</summary><ul class="mt-1 divide-y divide-line">';
                foreach ($hist as $c) { echo '<li class="flex items-center justify-between gap-2 py-1.5"><span class="text-xs text-mute">' . e(date_label($c['date'])) . ($c['note'] ? ' · ' . e($c['note']) : '') . '</span><span class="flex items-center gap-1"><span class="num text-xs ' . ($c['amount'] < 0 ? 'text-neg' : '') . '">' . ($c['amount'] < 0 ? '−' : '+') . e(fmt(abs($c['amount']))) . '</span>' . (!$c['transaction_id'] ? confirm_btn('Undo', 'contribution-delete', ['id' => $c['id']], 'Remove this entry?', "The amount is removed from this goal's saved total.", 'Remove', 'btn btn-ghost btn-sm text-xs text-neg') : '') . '</span></li>'; }
                echo '</ul></details>';
            }
            echo '</article>';
        }
        echo '</div>';
    }
    echo '<div class="mb-3 mt-10 flex flex-wrap items-center justify-between gap-2"><div><h2 class="text-xl font-semibold tracking-tight">Personal savings & spending fund</h2><p class="text-sm text-mute">A controlled amount for your own purchases. Pay from it when you add an expense to track what is left.</p></div>'
        . (!$personal ? dialog_form('fund-new', 'Create fund', 'Create personal fund', 'goal-save', goal_fields('personal'), 'Save', 'btn btn-dark') : '') . '</div>';
    if (!$personal) { echo empty_state('No personal fund yet', 'Set a monthly allocation and an optional spending limit for guilt-free spending.'); }
    else {
        echo '<div class="grid gap-4 md:grid-cols-2">';
        foreach ($personal as $g) {
            $id = (int) $g['id']; $spent = 0; $added = 0;
            foreach ($s['contribs'] as $c) { if ($c['target_type'] === 'goal' && (int) $c['target_id'] === $id && month_of($c['date']) === $month) { if ($c['transaction_id'] && $c['amount'] < 0) { $spent -= $c['amount']; } elseif (!$c['transaction_id'] && $c['amount'] > 0) { $added += $c['amount']; } } }
            $left = $g['monthly_limit'] ? (int) $g['monthly_limit'] - $spent : null;
            echo '<article class="card p-6"><h3 class="text-lg font-semibold">' . e($g['name']) . '</h3><p class="num mt-1 text-3xl font-semibold">' . e(fmt($g['saved'])) . '</p><p class="text-xs text-mute">in the fund now</p>';
            if ($g['monthly_limit'] > 0) { echo '<div class="mt-4">' . progress($spent / $g['monthly_limit'] * 100, $spent > $g['monthly_limit'] ? 'neg' : ($spent / $g['monthly_limit'] > 0.85 ? 'orange' : 'lime'), 'Spending limit used') . '<p class="num mt-1.5 text-xs text-mute">' . e(fmt($spent)) . ' spent of ' . e(fmt((int) $g['monthly_limit'])) . ' limit in ' . e(month_label($month)) . ' · ' . ($left >= 0 ? e(fmt($left)) . ' left' : '<span class="text-neg">' . e(fmt(-$left)) . ' over</span>') . '</p></div>'; }
            echo '<p class="mt-3 text-xs text-mute">Allocation ' . ($g['monthly_target'] ? e(fmt((int) $g['monthly_target'])) . '/month' : 'not set') . ' · ' . e(fmt($added)) . ' added this month</p><div class="mt-4 flex flex-wrap gap-1.5 border-t border-line pt-4">'
                . dialog_form("fund-add-$id", 'Add money', 'Add to ' . $g['name'], 'contribute', contribute_fields('goal', $id, $s, (int) $g['monthly_target'] ?: null), 'Save', 'btn btn-sm')
                . dialog_form("fund-edit-$id", 'Edit', 'Edit fund', 'goal-save', goal_fields('personal', $g), 'Save changes', 'btn btn-ghost btn-sm')
                . confirm_btn('Delete', 'goal-delete', ['id' => $id], 'Delete ' . $g['name'] . '?', 'Money left in the fund returns to your spendable balance.') . '</div></article>';
        }
        echo '</div>';
    }
    layout_end();
}

function page_house(): void {
    require_login(); $s = snap(); $all = array_values(array_filter(purchase_views($s), fn($p) => $p['kind'] === 'house'));
    $open = array_values(array_filter($all, fn($p) => $p['status'] !== 'purchased'));
    $budget = array_sum(array_map(fn($p) => $p['monthly_setaside'] ?: ($p['needed'] ?? 0), $open));
    $ranked = rank_purchases($open, $budget); $groups = array_values(array_unique(array_map(fn($p) => $p['group_label'] ?? 'Other', $all)));
    layout_start('House Shopping Fund', 'house', $s);
    page_header('House Shopping Fund', 'Furniture, appliances, electronics, kitchen equipment and home decoration. Plan each item and save toward it.', dialog_form('house-new', icon('plus', 'size-4') . ' Add item', 'Add household item', 'purchase-save', purchase_fields('house'), 'Save', 'btn btn-dark'));
    echo '<div class="grid grid-cols-2 gap-4 lg:grid-cols-4">' . stat_card('Planned items', (string) count($open), (count($all) - count($open)) . ' purchased') . stat_card('Total estimated', e(fmt(array_sum(array_column($open, 'cost'))))) . stat_card('Saved so far', e(fmt(array_sum(array_column($open, 'saved')))), '', 'pos')
        . stat_card('Still needed', e(fmt(array_sum(array_column($open, 'remaining')))), $budget ? 'About ' . e(fmt($budget)) . ' / month planned' : '') . '</div>';
    if ($ranked) {
        echo '<div class="mt-5">'; card_open('Suggested order to fund', 'Ranked by priority, then desired date, then smallest remaining amount. Suggested monthly amounts share your planned monthly total (' . e(fmt($budget)) . ') in that order.');
        echo '<div class="relative overflow-x-auto"><table class="w-full min-w-[420px]"><thead><tr class="border-b border-line"><th class="th">#</th><th class="th">Item</th><th class="th text-right">Remaining</th><th class="th text-right">Suggested / month</th></tr></thead><tbody class="divide-y divide-line">';
        foreach ($ranked as $r) { echo '<tr><td class="td num text-mute">' . $r['rank'] . '</td><td class="td font-medium">' . e($r['name']) . '</td><td class="td num text-right">' . e(fmt($r['remaining'])) . '</td><td class="td num text-right">' . e(fmt($r['suggested'])) . '</td></tr>'; }
        echo '</tbody></table></div>'; card_close(); echo '</div>';
    }
    echo '<div class="mt-6 space-y-6">';
    if (!$all) { echo empty_state('Nothing planned yet', 'Add the things you want for the house, with a price estimate and priority. Then set money aside for each.'); }
    foreach ($groups as $g) { echo '<section><h2 class="mb-2 text-sm font-semibold text-mute">' . e($g) . '</h2><div class="grid gap-4 md:grid-cols-2">'; foreach ($all as $p) { if (($p['group_label'] ?? 'Other') === $g) { echo purchase_card($s, $p, 'house'); } } echo '</div></section>'; }
    echo '</div>'; layout_end();
}

function page_big(): void {
    require_login(); $s = snap(); $all = array_values(array_filter(purchase_views($s), fn($p) => $p['kind'] === 'big'));
    $open = array_values(array_filter($all, fn($p) => !in_array($p['status'], ['purchased', 'postponed'], true)));
    $planned = array_sum(array_column($open, 'monthly_setaside'));
    $typed = parse_money(get_str('budget', 20)); $budget = $typed && $typed > 0 ? $typed : ($planned ?: array_sum(array_map(fn($p) => $p['needed'] ?? 0, $open)));
    $ranked = rank_purchases($open, $budget); $first = $ranked[0] ?? null; $need = array_sum(array_map(fn($r) => $r['needed'] ?? 0, $ranked));
    layout_start('Big Purchases', 'big-purchases', $s);
    page_header('Big Purchases', 'Plan expensive purchases (' . fmt($s['prefs']['big_purchase_threshold']) . ' and above) without losing control of the monthly budget.', dialog_form('big-new', icon('plus', 'size-4') . ' Plan a purchase', 'Plan a big purchase', 'purchase-save', purchase_fields('big'), 'Save', 'btn btn-dark'));
    echo '<div class="grid grid-cols-2 gap-4 lg:grid-cols-4">' . stat_card('Planned purchases', (string) count($open), count(array_filter($all, fn($p) => $p['status'] === 'purchased')) . ' purchased · ' . count(array_filter($all, fn($p) => $p['status'] === 'postponed')) . ' postponed')
        . stat_card('Total cost', e(fmt(array_sum(array_column($open, 'cost'))))) . stat_card('Saved so far', e(fmt(array_sum(array_column($open, 'saved')))), '', 'pos') . stat_card('Remaining', e(fmt(array_sum(array_column($open, 'remaining'))))) . '</div>';
    if (!$open) { echo '<div class="mt-5">' . empty_state('No big purchases planned', 'Add a car, renovation, trip or appliance. Compare them side by side and see which to fund first.') . '</div>'; }
    else {
        echo '<div class="mt-5">'; card_open('Which should be funded first?');
        if ($first) { echo '<p class="mb-4 text-sm"><strong>' . e($first['name']) . '</strong> comes first: ' . e($first['priority']) . ' priority' . ($first['target_date'] ? ', wanted by ' . e(date_label($first['target_date'])) : '') . ', ' . e(fmt($first['remaining'])) . ' still to go. Order is priority, then earliest desired date, then smallest remaining amount.</p>'; }
        echo '<form method="get" class="mb-4 flex flex-wrap items-end gap-2"><div><label for="budget" class="label">Monthly amount available for big purchases</label><input id="budget" name="budget" inputmode="decimal" value="' . e($budget ? money_input($budget) : '') . '" class="input num w-52"></div><button class="btn">Recalculate</button></form>';
        echo '<div class="relative overflow-x-auto"><table class="w-full min-w-[820px]"><thead><tr class="border-b border-line"><th class="th">#</th><th class="th">Purchase</th><th class="th">Priority</th><th class="th">Status</th><th class="th text-right">Cost</th><th class="th text-right">Saved</th><th class="th text-right">Remaining</th><th class="th">Wanted by</th><th class="th text-right">Needed / month</th><th class="th text-right">Suggested now</th></tr></thead><tbody class="divide-y divide-line">';
        foreach ($ranked as $r) { echo '<tr class="' . ($r['rank'] === 1 ? 'bg-soft/60' : '') . '"><td class="td num text-mute">' . $r['rank'] . '</td><td class="td font-medium">' . e($r['name']) . '</td><td class="td">' . priority_badge($r['priority']) . '</td><td class="td text-mute">' . e(PURCHASE_STATUS[$r['status']]) . '</td><td class="td num text-right">' . e(fmt($r['cost'])) . '</td><td class="td num text-right">' . e(fmt($r['saved'])) . '</td><td class="td num text-right">' . e(fmt($r['remaining'])) . '</td><td class="td num whitespace-nowrap text-mute">' . ($r['target_date'] ? e(date_label($r['target_date'])) : '—') . '</td><td class="td num text-right">' . ($r['needed'] !== null ? e(fmt($r['needed'])) : '—') . '</td><td class="td num text-right font-semibold">' . e(fmt($r['suggested'])) . '</td></tr>'; }
        echo '</tbody></table></div>';
        if ($need > $budget) { echo '<p class="mt-4 rounded-2xl bg-orange/20 px-4 py-3 text-sm text-orange-dk">Together these need ' . e(fmt($need)) . ' per month to meet every date, but ' . e(fmt($budget)) . ' is available. Consider moving a date or postponing a lower-priority item.</p>'; }
        card_close(); echo '</div><div class="mt-5 grid gap-4 md:grid-cols-2">'; foreach ($open as $p) { echo purchase_card($s, $p, 'big'); } echo '</div>';
    }
    $rest = array_values(array_filter($all, fn($p) => in_array($p['status'], ['purchased', 'postponed'], true)));
    if ($rest) { echo '<details class="mt-6"><summary class="cursor-pointer text-sm font-medium">Postponed and purchased (' . count($rest) . ')</summary><div class="mt-3 grid gap-4 md:grid-cols-2">'; foreach ($rest as $p) { echo purchase_card($s, $p, 'big'); } echo '</div></details>'; }
    layout_end();
}
