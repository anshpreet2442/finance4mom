<?php
declare(strict_types=1);

/** A liquid-level gauge (assets/slosh.js). $pct 0-100; $text replaces the number inside. */
function gauge(float $pct, string $text, array $o = []): string {
    $o += ['w' => 84, 'h' => 150, 'r' => 22, 'liquid' => 'var(--lime)', 'label' => 'Level', 'ticks' => 4, 'idle' => 'true'];
    return '<div data-slosh data-value="' . round(max(0, min(100, $pct)), 1) . '" data-text="' . e($text) . '" data-w="' . (int) $o['w'] . '" data-h="' . (int) $o['h'] . '" data-r="' . (int) $o['r'] . '" data-ticks="' . (int) $o['ticks'] . '" data-idle="' . e($o['idle']) . '" style="--sg-liquid:' . e($o['liquid']) . ';--sg-on-liquid:#fff" role="meter" aria-label="' . e($o['label']) . '" aria-valuetext="' . e($text) . '"></div>';
}
const PURPOSE_STYLE = ['income' => ['briefcase', 'var(--c-lime)', 'Income from job'], 'savings' => ['piggy-bank', 'var(--c-teal)', 'Savings account'], 'spending' => ['wallet', 'var(--c-orange)', 'Everyday spending'], 'other' => ['landmark', 'var(--c-grey)', 'Other']];
const PRIORITY_COLOR = ['high' => 'var(--neg)', 'medium' => 'var(--c-orange)', 'low' => 'var(--c-teal)'];

function account_fields(?array $a = null, array $s = []): array {
    $types = ['bank' => 'Bank account', 'cash' => 'Cash', 'wallet' => 'Wallet / UPI', 'card' => 'Card'];
    return array_merge($a ? [['name' => 'id', 'type' => 'hidden', 'value' => $a['id']]] : [], [
        ['name' => 'name', 'label' => 'Account name', 'required' => true, 'value' => $a['name'] ?? '', 'placeholder' => 'e.g. HDFC Salary account'],
        ['name' => 'purpose', 'label' => 'What is it for?', 'type' => 'select', 'required' => true, 'options' => ACCOUNT_PURPOSES, 'value' => $a['purpose'] ?? 'spending'],
        ['name' => 'label', 'label' => 'Your own label (optional)', 'value' => $a['label'] ?? '', 'placeholder' => 'e.g. Monthly salary, Emergency fund'],
        ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true, 'options' => $types, 'half' => true, 'value' => $a['type'] ?? 'bank'],
        ['name' => 'opening_balance', 'label' => $a ? 'Balance on the opening date' : 'Current balance', 'type' => 'money', 'half' => true, 'value' => $a ? money_input((int) $a['opening_balance']) : ''],
        ['name' => 'opening_date', 'label' => 'Balance as of', 'type' => 'date', 'required' => true, 'value' => $a['opening_date'] ?? today(), 'hint' => 'Transactions dated before this are not counted in the balance.'],
    ]);
}

function page_accounts(): void {
    $u = require_login(); $s = snap(); $month = month_of($s['today']); $accts = active_accounts($s);
    $cash = total_cash($s['accounts'], $s['txs'], $s['today']); $reserved = reserved_total($s['contribs']); $pos = array_sum(array_map(fn($a) => max($a['balance'], 0), $accts));
    $plan = savings_plan($s); $items = $plan['items']; $accOpts = acct_options($s);
    $savingsAcc = array_values(array_filter($accts, fn($a) => $a['purpose'] === 'savings')); $defaultFrom = $savingsAcc[0]['id'] ?? ($accts[0]['id'] ?? '');
    layout_start('Accounts', 'accounts', $s, ['hero' => true]);
    echo m_banner($u, 'Across all accounts', '<span data-count data-live="cash">' . e(fmt_parts($cash)[0]) . '</span><small>' . e(fmt_parts($cash)[1]) . '</small>', icon('landmark', 'size-4') . count($accts) . ' account' . (count($accts) === 1 ? '' : 's'));
    echo '<div class="hidden lg:block">'; page_header('Accounts & savings plan', 'Your bank accounts and what each one is for, and what to move into savings this month.', dialog_form('acc-add', icon('plus', 'size-4') . ' Add account', 'Add an account', 'account-save', account_fields(null, $s), 'Add account', 'btn btn-dark')); echo '</div>';
    echo '<div class="m-sheet -mt-6 px-4 pb-8 pt-6 lg:mt-0 lg:bg-transparent lg:px-0 lg:pt-0 lg:shadow-none">';
    echo '<div class="mb-4 flex justify-end lg:hidden">' . dialog_form('acc-add-m', icon('plus', 'size-4') . ' Add account', 'Add an account', 'account-save', account_fields(null, $s), 'Add account', 'btn btn-dark') . '</div>';
    echo '<div class="grid grid-cols-3 gap-3"><div class="card p-4 text-center lg:text-left"><p class="text-xs text-mute">Total cash</p><p class="num mt-1 text-lg font-semibold sm:text-2xl" data-count>' . e(fmt($cash)) . '</p></div><div class="card p-4 text-center lg:text-left"><p class="text-xs text-mute">Set aside for plans</p><p class="num mt-1 text-lg font-semibold text-lime-dk sm:text-2xl" data-count>' . e(fmt($reserved)) . '</p></div><div class="card p-4 text-center lg:text-left"><p class="text-xs text-mute">Free to spend</p><p class="num mt-1 text-lg font-semibold sm:text-2xl ' . ($cash - $reserved < 0 ? 'text-neg' : '') . '" data-count>' . e(fmt($cash - $reserved)) . '</p></div></div>';

    // ---------- accounts ----------
    echo '<h2 class="mb-3 mt-8 text-xl font-semibold tracking-tight">Your accounts</h2>';
    if (!$accts) { echo empty_state('No accounts yet', 'Add the bank accounts you use, and say what each is for.'); }
    else {
        echo '<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">';
        foreach ($accts as $a) {
            $id = (int) $a['id']; [$ic, $col, $pl] = PURPOSE_STYLE[$a['purpose']]; $share = $pos > 0 ? max($a['balance'], 0) / $pos * 100 : 0; $inM = $outM = 0;
            foreach ($s['txs'] as $t) { if ($t['status'] !== 'completed' || month_of($t['date']) !== $month) { continue; } $mine = (int) $t['account_id'] === $id; $dst = (int) $t['to_account_id'] === $id;
                if ($t['type'] === 'income' && $mine) { $inM += $t['amount']; } elseif ($t['type'] === 'expense' && $mine) { $outM += $t['amount']; } elseif ($t['type'] === 'refund' && $mine) { $inM += $t['amount']; } elseif ($t['type'] === 'transfer') { if ($dst) { $inM += $t['amount']; } if ($mine) { $outM += $t['amount']; } } }
            $card = '<article class="card lift flex gap-4 p-5"><div class="shrink-0">' . gauge($share, fmt_short($a['balance']), ['w' => 84, 'h' => 168, 'liquid' => $col, 'label' => $a['name'] . ' share of your cash']) . '</div>'
                . '<div class="flex min-w-0 flex-1 flex-col"><div class="flex flex-wrap items-center gap-1.5"><span class="inline-flex items-center gap-1 rounded-full bg-soft px-2.5 py-0.5 text-xs font-medium" style="color:' . $col . '">' . icon($ic, 'size-3.5') . e($a['label'] ?: $pl) . '</span></div>'
                . '<h3 class="mt-2 truncate text-lg font-semibold" title="' . e($a['name']) . '">' . e($a['name']) . '</h3><p class="text-xs capitalize text-mute">' . e($a['type']) . ' · ' . e($pl) . '</p>'
                . '<p class="num mt-2 text-2xl font-semibold tracking-tight ' . ($a['balance'] < 0 ? 'text-neg' : '') . '" data-count>' . e(fmt($a['balance'])) . '</p>'
                . '<p class="num text-xs text-mute"><span class="text-lime-dk">+' . e(fmt_short($inM)) . '</span> in · −' . e(fmt_short($outM)) . ' out this month · ' . round($share) . '% of cash</p>'
                . '<div class="mt-auto flex flex-wrap gap-1.5 pt-3">'
                . dialog_form("acc-bal-$id", 'Set balance', 'Set balance: ' . $a['name'], 'account-balance', [['name' => 'id', 'type' => 'hidden', 'value' => $id], ['name' => 'balance', 'label' => "What the bank shows now", 'type' => 'money', 'required' => true, 'value' => money_input((int) $a['balance']), 'hint' => 'The difference is saved as a balance correction, so your history stays honest. It is not counted as income or an expense.'], ['name' => 'date', 'label' => 'As of', 'type' => 'date', 'required' => true, 'value' => $s['today'], 'half' => true], ['name' => 'note', 'label' => 'Note (optional)', 'half' => true]], 'Update balance', 'btn btn-sm')
                . '<button type="button" class="btn btn-sm" data-tx-new="transfer" data-account="' . $id . '">' . icon('arrow-left-right', 'size-3.5') . ' Move money</button>'
                . dialog_form("acc-edit-$id", 'Edit', 'Edit ' . $a['name'], 'account-save', account_fields($a, $s), 'Save changes', 'btn btn-ghost btn-sm')
                . confirm_btn('Remove', 'account-delete', ['id' => $id], 'Remove ' . $a['name'] . '?', 'An account with transactions is archived (kept in your history); an empty one is deleted.', 'Remove') . '</div></div></article>';
            echo swipe_row($card, [['type' => 'open', 'open' => "#acc-bal-$id", 'label' => 'Balance', 'icon' => 'pencil', 'tone' => 'primary'], ['type' => 'open', 'open' => "#acc-edit-$id", 'label' => 'Edit', 'icon' => 'sliders-horizontal', 'tone' => 'dark']], [['action' => 'account-delete', 'fields' => ['id' => $id], 'label' => 'Remove', 'icon' => 'trash-2', 'tone' => 'danger', 'undo' => $a['name'] . ' removed']], $a['name'], ['class' => 'swipe-card']);
        }
        echo '</div>';
    }

    // ---------- this month's savings moves ----------
    echo '<h2 class="mb-1 mt-10 text-xl font-semibold tracking-tight">This month: what to move into savings</h2><p class="mb-4 text-sm text-mute">In priority order (high first, then the nearest date). Based on what you usually save: ' . e(fmt($plan['capacity'])) . ' a month, the average of income minus expenses over your last 3 full months.</p>';
    if (!$items) { echo empty_state('Nothing to save for yet', 'Add a goal, a purchase or a yearly bill and it will show up here with the amount to set aside this month.'); }
    else {
        $segCols = ['var(--c-lime)', 'var(--c-orange)', 'var(--c-yellow)', 'var(--c-teal)', 'var(--c-plum)', 'var(--c-dark)']; $tot = max($plan['capacity'], $plan['due'], 1);
        echo '<div class="card mb-4 p-5"><div class="flex flex-wrap items-baseline justify-between gap-2"><p class="text-sm"><span class="num text-2xl font-semibold" data-count>' . e(fmt($plan['give'])) . '</span> <span class="text-mute">of ' . e(fmt($plan['capacity'])) . ' available to move</span></p>'
            . ($plan['short'] > 0 ? '<span class="rounded-full bg-neg/10 px-3 py-1 text-xs font-medium text-neg">' . e(fmt($plan['short'])) . ' short of what is due</span>' : '<span class="rounded-full bg-lime/20 px-3 py-1 text-xs font-medium text-lime-dk">Everything due is covered' . ($plan['left'] > 0 ? ', ' . e(fmt($plan['left'])) . ' to spare' : '') . '</span>') . '</div>'
            . '<div class="mt-3 flex h-4 w-full gap-1 overflow-hidden rounded-full bg-soft" role="img" aria-label="How this month\'s saving is shared between your plans">';
        foreach ($items as $n => $i) { if ($i['give'] > 0) { echo '<span class="hatch-seg h-full" style="width:' . round($i['give'] / $tot * 100, 2) . '%;--seg:' . $segCols[$n % count($segCols)] . '" title="' . e($i['name'] . ': ' . fmt($i['give'])) . '"></span>'; } }
        echo '</div><p class="mt-2 text-xs text-mute">Moved already this month: ' . e(fmt($plan['moved'])) . '. Still due: ' . e(fmt($plan['due'])) . '.</p></div>';
        echo '<div class="grid gap-4 md:grid-cols-2">';
        foreach ($items as $n => $i) {
            $acc = $i['account_id'] ? ($accOpts[$i['account_id']] ?? null) : null; $from = $i['account_id'] ?: $defaultFrom; $amt = $i['give'] ?: $i['due'];
            $fields = [['name' => 'target_type', 'type' => 'hidden', 'value' => $i['type']], ['name' => 'target_id', 'type' => 'hidden', 'value' => $i['id']],
                ['name' => 'amount', 'label' => 'Amount to move (use − to take money back)', 'type' => 'money', 'required' => true, 'value' => $amt ? money_input($amt) : ''],
                ['name' => 'date', 'label' => 'Date', 'type' => 'date', 'required' => true, 'value' => $s['today'], 'half' => true], ['name' => 'account_id', 'label' => 'Taken from account', 'type' => 'select', 'options' => $accOpts, 'half' => true, 'value' => $from], ['name' => 'note', 'label' => 'Note (optional)']];
            echo '<article class="card lift flex gap-4 p-5"><div class="shrink-0">' . gauge($i['pct'], round($i['pct']) . '%', ['w' => 64, 'h' => 128, 'r' => 18, 'ticks' => 4, 'liquid' => PRIORITY_COLOR[$i['priority']], 'label' => $i['name'] . ' saved so far']) . '</div>'
                . '<div class="min-w-0 flex-1"><div class="flex flex-wrap items-center gap-1.5"><span class="grid size-6 place-items-center rounded-full bg-ink text-[11px] font-semibold text-card">' . $i['order'] . '</span>' . priority_badge($i['priority']) . '<span class="text-xs text-mute">' . e($i['what']) . '</span></div>'
                . '<h3 class="mt-1.5 truncate text-lg font-semibold">' . e($i['name']) . '</h3><p class="num text-sm text-mute">Saved ' . e(fmt($i['saved'])) . ' of ' . e(fmt($i['target'])) . ($i['date'] ? ' · by ' . e(date_label($i['date'])) : '') . '</p>'
                . '<div class="mt-3 rounded-2xl bg-soft p-3 text-sm">' . ($i['need'] <= 0 ? '<p class="text-mute">Nothing needs to move this month.</p>'
                    : '<p class="flex items-center gap-1.5 font-medium">' . icon('arrow-right', 'size-4 text-lime-dk') . 'Move <span class="num" data-count>' . e(fmt($i['need'])) . '</span> this month' . ($acc ? ' → ' . e($acc) : '') . '</p>'
                    . '<div class="mt-2">' . progress($i['need'] > 0 ? $i['moved'] / $i['need'] * 100 : 100, $i['moved'] >= $i['need'] ? 'dark' : 'lime', 'Moved this month') . '</div><p class="mt-1 text-xs text-mute">' . ($i['moved'] >= $i['need'] ? 'Done for this month. ' . e(fmt($i['moved'])) . ' moved.' : e(fmt($i['moved'])) . ' moved, ' . e(fmt($i['due'])) . ' to go' . ($i['short'] > 0 ? ' · <span class="text-neg">' . e(fmt($i['short'])) . ' not covered by your usual saving</span>' : '')) . '</p>') . '</div>'
                . '<div class="mt-3">' . ($i['due'] > 0 || $i['need'] > 0 ? dialog_form('mv-' . $i['key'], 'Move money now', 'Move money to ' . $i['name'], 'contribute', $fields, 'Move', 'btn btn-sm btn-dark') : '') . '</div></div></article>';
        }
        echo '</div>';
    }

    // ---------- future plans ----------
    $future = array_values(array_filter($items, fn($i) => $i['date'])); usort($future, fn($a, $b) => $a['date'] <=> $b['date']);
    echo '<div class="mb-3 mt-10 flex flex-wrap items-end justify-between gap-2"><div><h2 class="text-xl font-semibold tracking-tight">Plan ahead</h2><p class="text-sm text-mute">Flight tickets, repairs, a gift, a new appliance: add it with a date and a price and see what to save each month.</p></div>'
        . dialog_form('plan-ahead', icon('plus', 'size-4') . ' Plan something', 'Plan something for the future', 'plan-future', [
            ['name' => 'name', 'label' => 'What is it?', 'required' => true, 'placeholder' => 'e.g. Flight tickets to Goa'],
            ['name' => 'kind', 'label' => 'Type', 'type' => 'select', 'required' => true, 'options' => ['trip' => 'Trip or travel', 'occasion' => 'Occasion or gift', 'big' => 'Big purchase', 'house' => 'Something for the house'], 'value' => 'trip', 'half' => true],
            ['name' => 'priority', 'label' => 'Priority', 'type' => 'select', 'required' => true, 'options' => priority_options(), 'value' => 'medium', 'half' => true],
            ['name' => 'estimated', 'label' => 'Estimated cost', 'type' => 'money', 'required' => true, 'half' => true], ['name' => 'target_date', 'label' => 'Needed by', 'type' => 'date', 'required' => true, 'half' => true, 'value' => add_months(month_of($s['today']), 3) . '-15'],
            ['name' => 'account_id', 'label' => 'Keep the savings in', 'type' => 'select', 'options' => $accOpts, 'value' => $defaultFrom, 'hint' => 'Just a label, so you know where this money lives.']], 'Add to my plans', 'btn btn-dark') . '</div>';
    if (!$future) { echo empty_state('Nothing planned yet', 'Use “Plan something” to add your first future expense.'); }
    else {
        echo '<ol class="relative space-y-3 border-l-2 border-line pl-6">';
        foreach ($future as $f) {
            $mleft = max(months_between(month_of($s['today']), month_of($f['date'])), 0);
            echo '<li class="relative"><span class="absolute -left-[33px] top-5 size-3 rounded-full ring-4 ring-[var(--shell)]" style="background:' . PRIORITY_COLOR[$f['priority']] . '"></span><div class="card lift flex flex-wrap items-center gap-4 p-4">'
                . '<div class="min-w-0 flex-1"><p class="font-semibold">' . e($f['name']) . ' <span class="ml-1 text-xs font-normal text-mute">' . e($f['what']) . '</span></p><p class="text-sm text-mute">' . e(date_label($f['date'])) . ' · ' . ($mleft === 0 ? 'this month' : $mleft . ' month' . ($mleft === 1 ? '' : 's') . ' away') . '</p></div>'
                . '<div class="w-40 shrink-0 text-right text-sm"><p class="num font-semibold">' . e(fmt($f['target'])) . '</p><p class="text-xs text-mute">save ' . e(fmt($f['need'])) . ' / month</p></div><div class="w-full">' . progress($f['pct'], $f['pct'] >= 100 ? 'dark' : 'lime', $f['name'] . ' saved') . '<p class="mt-1 text-xs text-mute">' . e(fmt($f['saved'])) . ' saved so far (' . round($f['pct']) . '%)</p></div></div></li>';
        }
        echo '</ol>';
    }
    echo '</div>';
    layout_end();
}
