<?php
declare(strict_types=1);
/**
 * Pure financial engine (no I/O). All money is integer minor units.
 *  - income/refund/+adjustment raise cash; expense/-adjustment lower it
 *  - transfers between accounts never change total cash and are never income or expense
 *  - savings contributions are NOT transactions: they only reserve cash. spendable = cash - reserved
 *  - paying a bill from a sinking fund = one expense + one matching negative contribution
 *  - only 'completed' transactions count; 'planned' are forecasts; unpaid bills are liabilities
 */

function cash_effect(array $t, ?int $accountId = null): int {
    if ($t['status'] !== 'completed') { return 0; }
    $mine = $accountId === null || (int) $t['account_id'] === $accountId;
    switch ($t['type']) {
        case 'income': case 'refund': case 'adjustment': return $mine ? (int) $t['amount'] : 0;
        case 'expense': return $mine ? -(int) $t['amount'] : 0;
        case 'transfer':
            if ($accountId === null) { return 0; }
            return ((int) $t['to_account_id'] === $accountId ? (int) $t['amount'] : 0) - ((int) $t['account_id'] === $accountId ? (int) $t['amount'] : 0);
    }
    return 0;
}
function account_balance(array $a, array $txs, ?string $asOf = null): int {
    $bal = (int) $a['opening_balance'];
    foreach ($txs as $t) {
        if ($t['date'] < $a['opening_date']) { continue; }
        if ($asOf !== null && $t['date'] > $asOf) { continue; }
        $bal += cash_effect($t, (int) $a['id']);
    }
    return $bal;
}
function total_cash(array $accounts, array $txs, ?string $asOf = null): int {
    $s = 0; foreach ($accounts as $a) { $s += account_balance($a, $txs, $asOf); } return $s;
}
function opening_balance(array $accounts, array $txs, string $month): int {
    $start = month_start($month);
    return total_cash(array_filter($accounts, fn($a) => $a['opening_date'] <= $start), $txs, day_before($start));
}

function summarize_month(array $accounts, array $txs, array $contribs, string $month): array {
    $incomeReceived = $incomeExpected = $gross = $refunds = $adjust = $plannedExp = 0;
    foreach ($txs as $t) {
        if (month_of($t['date']) !== $month) { continue; }
        if ($t['status'] === 'planned') {
            if ($t['type'] === 'income') { $incomeExpected += $t['amount']; } elseif ($t['type'] === 'expense') { $plannedExp += $t['amount']; }
            continue;
        }
        match ($t['type']) {
            'income' => $incomeReceived += $t['amount'], 'expense' => $gross += $t['amount'],
            'refund' => $refunds += $t['amount'], 'adjustment' => $adjust += $t['amount'], default => null,
        };
    }
    $opening = opening_balance($accounts, $txs, $month);
    $expenses = $gross - $refunds;
    $available = $opening + $incomeReceived;
    $net = $incomeReceived - $expenses;
    $moved = 0; foreach ($contribs as $c) { if (month_of($c['date']) === $month && $c['amount'] > 0) { $moved += $c['amount']; } }
    return [
        'month' => $month, 'opening' => $opening, 'income' => $incomeReceived, 'income_expected' => $incomeExpected,
        'expenses' => $expenses, 'refunds' => $refunds, 'adjustments' => $adjust, 'planned_expenses' => $plannedExp,
        'available' => $available, 'remaining' => $available - $expenses + $adjust, 'net' => $net,
        'rate' => $incomeReceived > 0 ? $net / $incomeReceived * 100 : null, 'moved' => $moved,
    ];
}
function reserved_total(array $contribs, ?string $asOf = null): int {
    $s = 0; foreach ($contribs as $c) { if ($asOf === null || $c['date'] <= $asOf) { $s += $c['amount']; } } return $s;
}
function saved_for(array $contribs, string $type, int $id): int {
    $s = 0; foreach ($contribs as $c) { if ($c['target_type'] === $type && (int) $c['target_id'] === $id) { $s += $c['amount']; } } return $s;
}
/** Net spend (expense - refund) per category id for a month; uncategorised = 0. */
function spend_by_category(array $txs, string $month): array {
    $o = [];
    foreach ($txs as $t) {
        if ($t['status'] !== 'completed' || month_of($t['date']) !== $month || !in_array($t['type'], ['expense', 'refund'], true)) { continue; }
        $k = (int) ($t['category_id'] ?? 0);
        $o[$k] = ($o[$k] ?? 0) + ($t['type'] === 'expense' ? $t['amount'] : -$t['amount']);
    }
    return $o;
}

// ---------------- goals ----------------
function goal_progress(array $g, int $saved, string $today, int $observed = 0): array {
    $target = (int) $g['target_amount'];
    $remaining = max($target - $saved, 0);
    $percent = $target > 0 ? min($saved / $target * 100, 100) : 0;
    $complete = $target > 0 && $remaining === 0;
    $months = null; $required = null;
    if (!empty($g['target_date'])) {
        $months = max(months_between(month_of($today), month_of($g['target_date'])) + ($g['target_date'] >= $today ? 1 : 0), 0);
        $required = $remaining === 0 ? 0 : ceil_div($remaining, max($months, 1));
    }
    $plan = (int) $g['monthly_target'] > 0 ? (int) $g['monthly_target'] : $observed;
    $est = null;
    if ($complete) { $est = month_of($today); }
    elseif ($plan > 0) { $est = add_months(month_of($today), max((int) ceil($remaining / $plan) - 1, 0)); } // current month = first contribution month
    $behind = !$complete && !empty($g['target_date']) && ($plan <= 0 || ($est !== null && $est > month_of($g['target_date'])));
    return ['saved' => $saved, 'remaining' => $remaining, 'percent' => $percent, 'complete' => $complete, 'months' => $months, 'required' => $required, 'est' => $est, 'behind' => $behind];
}
function monthly_set_aside(int $amount, string $freq): int { return ceil_div($amount, FREQ_MONTHS[$freq]); }
function catch_up_set_aside(int $amount, int $saved, string $due, string $today): int {
    $rem = max($amount - $saved, 0); if ($rem === 0) { return 0; }
    return ceil_div($rem, max(months_between(month_of($today), month_of($due)) + ($due >= $today ? 1 : 0), 1));
}

// ---------------- bills ----------------
function occurrence_state(int $amount, int $paid, string $due, string $today): array {
    $out = max($amount - $paid, 0);
    $days = days_between($today, $due);
    $overdue = $out > 0 && $due < $today;
    $status = $out === 0 ? 'paid' : ($paid > 0 && !$overdue ? 'partial' : ($overdue ? 'overdue' : 'upcoming'));
    return ['status' => $status, 'paid' => $paid, 'outstanding' => $out, 'overdue' => $overdue, 'days' => $days];
}
function occurrence_dates(string $firstDue, int $anchor, string $freq, string $horizon, int $limit = 400): array {
    $out = []; $m = month_of($firstDue);
    for ($i = 0; $i < $limit; $i++) {
        $d = $m . '-' . str_pad((string) min($anchor, days_in_month($m)), 2, '0', STR_PAD_LEFT);
        if ($d > $horizon) { break; }
        if ($d >= $firstDue) { $out[] = $d; }
        $m = add_months($m, FREQ_MONTHS[$freq]);
    }
    return $out;
}

// ---------------- cash-flow ----------------
function project_cashflow(int $start, array $events): array {
    usort($events, fn($a, $b) => [$a['date'], -$a['amount']] <=> [$b['date'], -$b['amount']]);  // date asc, inflows first
    $bal = $start; $pts = []; $low = null; $short = null;
    foreach ($events as $e) {
        $bal += $e['amount'];
        $p = ['date' => $e['date'], 'balance' => $bal, 'label' => $e['label']];
        $pts[] = $p;
        if ($low === null || $bal < $low['balance']) { $low = $p; }
        if ($short === null && $bal < 0) { $short = $p; }
    }
    return ['points' => $pts, 'lowest' => $low, 'shortfall' => $short];
}

// ---------------- purchases ----------------
const PRIORITY_RANK = ['high' => 0, 'medium' => 1, 'low' => 2];
function purchase_cost(array $p): int { return $p['actual'] !== null ? (int) $p['actual'] : (int) $p['estimated']; }
function purchase_needs(array $p, int $saved, string $today): array {
    $cost = purchase_cost($p); $rem = max($cost - $saved, 0); $months = null; $needed = null;
    if (!empty($p['target_date'])) {
        $months = max(months_between(month_of($today), month_of($p['target_date'])) + ($p['target_date'] >= $today ? 1 : 0), 1);
        $needed = ceil_div($rem, $months);
    }
    return ['cost' => $cost, 'remaining' => $rem, 'needed' => $needed, 'months' => $months];
}
/** Rank open purchases (priority, earliest date, smallest remaining) and share a monthly budget in that order. */
function rank_purchases(array $items, int $monthlyBudget): array {
    $open = array_values(array_filter($items, fn($p) => !in_array($p['status'], ['purchased', 'postponed'], true)));
    usort($open, function ($a, $b) {
        $c = PRIORITY_RANK[$a['priority']] <=> PRIORITY_RANK[$b['priority']]; if ($c) { return $c; }
        $c = ($a['target_date'] ?? '9999-12-31') <=> ($b['target_date'] ?? '9999-12-31'); if ($c) { return $c; }
        return $a['remaining'] <=> $b['remaining'];
    });
    $budget = max($monthlyBudget, 0);
    foreach ($open as $i => &$p) {
        $want = $p['needed'] ?? $p['remaining'];
        $give = min($budget, $want, $p['remaining']);
        $budget -= $give;
        $p['rank'] = $i + 1; $p['suggested'] = $give;
    }
    return $open;
}

// ---------------- monthly planner ----------------
const PLAN_SECTIONS = [
    'bills' => 'Recurring bills', 'yearly' => 'Yearly obligations (set-aside)', 'household' => 'Household & groceries', 'personal' => 'Personal expenses',
    'occasional' => 'Occasional expenses (set-aside)', 'goals' => 'Long-term savings', 'house' => 'House shopping fund',
    'personalFund' => 'Personal savings & spending fund', 'big' => 'Big-ticket purchases',
];
function plan_totals(int $available, array $lines): array {
    $alloc = 0; foreach ($lines as $l) { $alloc += (int) $l['amount']; }
    $un = $available - $alloc;
    return ['available' => $available, 'allocated' => $alloc, 'unallocated' => $un, 'deficit' => max(-$un, 0)];
}
/** Suggestions only; the caller must ask before applying. Essentials are never cut. */
function suggest_adjustments(array $lines, int $deficit): array {
    if ($deficit <= 0) { return []; }
    $out = []; $need = $deficit;
    $personal = array_filter($lines, fn($l) => $l['section'] === 'personal' && $l['amount'] > 0);
    usort($personal, fn($a, $b) => $b['amount'] <=> $a['amount']);
    foreach ($personal as $l) {
        if ($need <= 0) { break; }
        $cut = min(intdiv($l['amount'], 2), $need); if ($cut <= 0) { continue; }
        $out[] = ['key' => $l['key'], 'label' => $l['label'], 'from' => $l['amount'], 'to' => $l['amount'] - $cut, 'saves' => $cut, 'reason' => 'Reduce discretionary personal spending (by up to half)'];
        $need -= $cut;
    }
    $rank = ['low' => 0, 'medium' => 1, 'high' => 2];
    $delay = array_filter($lines, fn($l) => !$l['essential'] && $l['section'] !== 'personal' && $l['amount'] > 0);
    usort($delay, fn($a, $b) => [$rank[$a['priority']], $a['amount']] <=> [$rank[$b['priority']], $b['amount']]);
    foreach ($delay as $l) {
        if ($need <= 0) { break; }
        $cut = min($l['amount'], $need);
        $out[] = ['key' => $l['key'], 'label' => $l['label'], 'from' => $l['amount'], 'to' => $l['amount'] - $cut, 'saves' => $cut,
            'reason' => ($l['priority'] === 'low' ? 'Delay low-priority ' : 'Reduce ') . strtolower(PLAN_SECTIONS[$l['section']]) . ' contribution'];
        $need -= $cut;
    }
    return $out;
}

// ---------------- insights ----------------
function build_insights(array $i): array {
    $out = []; $f = fn(int $n) => fmt($n);
    foreach ($i['category_budgets'] as $c) {
        if ($c['budget'] > 0 && $c['spent'] > $c['budget']) {
            $out[] = ['id' => 'over:' . $c['name'], 'sev' => 'warning', 'title' => "{$c['name']} is over budget",
                'detail' => 'You have spent ' . $f($c['spent']) . ' against a monthly budget of ' . $f($c['budget']) . '.',
                'calc' => $f($c['spent']) . ' spent − ' . $f($c['budget']) . ' budget = ' . $f($c['spent'] - $c['budget']) . ' over (' . round($c['spent'] / $c['budget'] * 100) . '% of budget).', 'href' => '/expenses'];
        }
    }
    foreach ($i['overdue'] as $o) {
        $out[] = ['id' => 'overdue:' . $o['name'] . $o['due'], 'sev' => 'danger', 'title' => "{$o['name']} is overdue", 'detail' => $f($o['outstanding']) . " was due on {$o['due']}.",
            'calc' => 'Bill amount − payments recorded = ' . $f($o['outstanding']) . ' still outstanding after the due date.', 'href' => '/bills'];
    }
    foreach ($i['due_soon'] as $d) {
        if ($d['days'] >= 0 && $d['days'] <= 30) {
            $short = max($d['amount'] - $d['saved'], 0);
            $out[] = ['id' => 'yearly:' . $d['name'] . $d['due'], 'sev' => $short > 0 ? 'warning' : 'info',
                'title' => "{$d['name']} is due in {$d['days']} day" . ($d['days'] === 1 ? '' : 's'),
                'detail' => $short > 0 ? $f($d['amount']) . " due on {$d['due']}; " . $f($d['saved']) . ' set aside, ' . $f($short) . ' short.' : $f($d['amount']) . " due on {$d['due']} and fully set aside.",
                'calc' => "Due {$d['due']} is {$d['days']} days from today ({$i['today']}). Set aside " . $f($d['saved']) . ' of ' . $f($d['amount']) . '.', 'href' => '/yearly'];
        }
    }
    foreach ($i['goals_behind'] as $g) {
        $out[] = ['id' => 'behind:' . $g['name'], 'sev' => 'warning', 'title' => "{$g['name']} is behind schedule",
            'detail' => "To finish by {$g['target_date']} you need about " . $f($g['required']) . ' per month; the current plan is ' . $f($g['plan']) . '.',
            'calc' => 'Remaining ' . $f($g['remaining']) . " ÷ months left until {$g['target_date']} = " . $f($g['required']) . ' per month, versus ' . $f($g['plan']) . ' planned.', 'href' => '/goals'];
    }
    if ($i['plan_unallocated'] !== null && $i['plan_unallocated'] > 0) {
        $out[] = ['id' => 'unallocated', 'sev' => 'info', 'title' => 'You have unallocated money this month', 'detail' => $f($i['plan_unallocated']) . " in this month's plan is not assigned to anything yet.",
            'calc' => 'Opening spendable + expected income − all planned allocations = ' . $f($i['plan_unallocated']) . '.', 'href' => '/budget'];
    }
    if ($i['plan_unallocated'] !== null && $i['plan_unallocated'] < 0) {
        $out[] = ['id' => 'plan-deficit', 'sev' => 'danger', 'title' => "This month's plan exceeds available money", 'detail' => 'The plan is ' . $f(-$i['plan_unallocated']) . ' over what is available.',
            'calc' => 'Opening spendable + expected income − planned allocations = ' . $f($i['plan_unallocated']) . '.', 'href' => '/budget'];
    }
    if ($i['savings_budget'] !== null && $i['purchase_monthly'] > $i['savings_budget']) {
        $out[] = ['id' => 'purchases-exceed', 'sev' => 'warning', 'title' => 'Planned purchases exceed the savings budget',
            'detail' => 'Purchases need ' . $f($i['purchase_monthly']) . ' per month but only ' . $f($i['savings_budget']) . ' is allocated.',
            'calc' => 'Σ monthly contribution needed across open purchases = ' . $f($i['purchase_monthly']) . ' vs allocated ' . $f($i['savings_budget']) . '.', 'href' => '/big-purchases'];
    }
    if ($i['shortfall']) {
        $s = $i['shortfall'];
        $out[] = ['id' => 'shortfall', 'sev' => 'danger', 'title' => 'Cash may run short', 'detail' => 'Projected cash balance falls to ' . $f($s['balance']) . " on {$s['date']} ({$s['label']}).",
            'calc' => "Current cash + expected income − unpaid bills and planned expenses, in date order, first goes below zero on {$s['date']}.", 'href' => '/reports'];
    }
    if ($i['reserved_exceeds_cash']) {
        $out[] = ['id' => 'reserved', 'sev' => 'warning', 'title' => 'Reserved savings exceed cash in accounts', 'detail' => 'Money set aside for goals is more than the cash recorded in your accounts. Check balances or contributions.',
            'calc' => 'Cash in accounts − reserved for goals/funds = ' . $f($i['spendable']) . '.', 'href' => '/transactions'];
    }
    if ($i['rate'] !== null && $i['income'] > 0) {
        if ($i['rate'] >= 20) {
            $out[] = ['id' => 'rate-good', 'sev' => 'good', 'title' => 'Savings rate is ' . round($i['rate']) . '% this month', 'detail' => 'You kept ' . $f($i['income'] - $i['expenses']) . ' of ' . $f($i['income']) . ' income.',
                'calc' => '(' . $f($i['income']) . ' income − ' . $f($i['expenses']) . ' expenses) ÷ ' . $f($i['income']) . ' × 100 = ' . number_format($i['rate'], 1) . '%.', 'href' => null];
        } elseif ($i['rate'] < 0) {
            $out[] = ['id' => 'rate-negative', 'sev' => 'danger', 'title' => 'Spending exceeds income this month', 'detail' => 'Expenses are ' . $f($i['expenses'] - $i['income']) . ' more than income received.',
                'calc' => $f($i['income']) . ' income − ' . $f($i['expenses']) . ' expenses = ' . $f($i['income'] - $i['expenses']) . '.', 'href' => '/reports'];
        }
    }
    $rank = ['danger' => 0, 'warning' => 1, 'info' => 2, 'good' => 3];
    usort($out, fn($a, $b) => $rank[$a['sev']] <=> $rank[$b['sev']]);
    return array_map(fn($x) => $x + ['href' => null], $out);
}
