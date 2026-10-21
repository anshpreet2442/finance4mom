<?php
declare(strict_types=1);
/** Read model: loads one user's data once and derives dashboard / report views from it. */

function prefs(): array { return $GLOBALS['__prefs'] ?? ['currency' => 'INR', 'locale' => 'en-IN', 'theme' => 'system', 'reminder_days' => 7, 'big_purchase_threshold' => 10000000, 'show_import_labels' => 1]; }

function load_snapshot(int $uid, ?string $today = null): array {
    $today ??= today();
    generate_from_templates($uid, $today);
    ensure_occurrences($uid, $today);
    $prefs = one('SELECT currency,locale,theme,reminder_days,big_purchase_threshold,show_import_labels FROM {preferences} WHERE user_id=?', [$uid]) ?? prefs();
    $GLOBALS['__prefs'] = $prefs;
    $txs = all('SELECT id,type,status,amount,date,account_id,to_account_id,category_id,occurrence_id,description,payment_method,notes,refund_of,bill_id,template_id,remarks,source,source_ref,import_id,txn_time,source_meta FROM {transactions} WHERE user_id=? ORDER BY date DESC, id DESC', [$uid]);
    $accounts = all('SELECT id,name,type,opening_balance,opening_date,archived,purpose,label FROM {accounts} WHERE user_id=? ORDER BY archived, id', [$uid]);
    foreach ($accounts as &$a) { $a['balance'] = account_balance($a, $txs); } unset($a);
    $budgets = []; foreach (all('SELECT category_id, amount FROM {budgets} WHERE user_id=?', [$uid]) as $b) { $budgets[(int) $b['category_id']] = (int) $b['amount']; }
    $occ = all("SELECT o.id,o.bill_id,o.due_date,o.amount,o.skipped,
        COALESCE((SELECT SUM(CASE t.type WHEN 'expense' THEN t.amount WHEN 'refund' THEN -t.amount END) FROM {transactions} t WHERE t.occurrence_id=o.id AND t.status='completed'),0) AS paid
        FROM {bill_occurrences} o JOIN {bills} b ON b.id=o.bill_id WHERE o.user_id=? AND b.active=1 ORDER BY o.due_date", [$uid]);
    foreach ($occ as &$o) { $o['paid'] = (int) $o['paid']; } unset($o);
    return [
        'uid' => $uid, 'today' => $today, 'prefs' => $prefs, 'accounts' => $accounts, 'budgets' => $budgets, 'txs' => $txs, 'occurrences' => $occ,
        'templates' => all('SELECT * FROM {recurring_templates} WHERE user_id=? ORDER BY id', [$uid]),
        'categories' => all('SELECT id,name,grp,parent_id FROM {categories} WHERE user_id=? ORDER BY grp, name', [$uid]),
        'contribs' => all('SELECT id,target_type,target_id,amount,date,transaction_id,note,account_id FROM {contributions} WHERE user_id=? ORDER BY date DESC, id DESC', [$uid]),
        'goals' => all('SELECT * FROM {goals} WHERE user_id=? AND archived=0 ORDER BY id', [$uid]),
        'purchases' => all('SELECT * FROM {purchases} WHERE user_id=? ORDER BY id', [$uid]),
        'bills' => all('SELECT * FROM {bills} WHERE user_id=? ORDER BY id', [$uid]),
    ];
}
function active_accounts(array $s): array { return array_values(array_filter($s['accounts'], fn($a) => !$a['archived'])); }

// ---------------- categories ----------------
function cat_by_id(array $s): array {
    static $key = null, $map = [];
    $k = $s['uid'] . ':' . count($s['categories']) . ':' . md5(json_encode($s['categories']));
    if ($k !== $key) { $key = $k; $map = []; foreach ($s['categories'] as $x) { $map[(int) $x['id']] = $x; } }
    return $map;
}
function cat_top(array $s, ?int $id): ?array {
    $by = cat_by_id($s); $c = $id ? ($by[$id] ?? null) : null;
    while ($c && $c['parent_id'] && isset($by[(int) $c['parent_id']])) { $c = $by[(int) $c['parent_id']]; }
    return $c;
}
function cat_desc(array $s, int $id): array { $o = [$id]; foreach ($s['categories'] as $c) { if ((int) $c['parent_id'] === $id) { $o = array_merge($o, cat_desc($s, (int) $c['id'])); } } return $o; }
function cat_name(array $s, ?int $id): string { $by = cat_by_id($s); return $id && isset($by[$id]) ? $by[$id]['name'] : 'Uncategorised'; }
function cat_path(array $s, ?int $id): string {
    $by = cat_by_id($s); if (!$id || !isset($by[$id])) { return 'Uncategorised'; }
    $c = $by[$id]; return $c['parent_id'] && isset($by[(int) $c['parent_id']]) ? $by[(int) $c['parent_id']]['name'] . ' › ' . $c['name'] : $c['name'];
}

// ---------------- goals / purchases / bills ----------------
function observed_monthly(array $s, string $type, int $id, int $months = 3): int {
    $from = month_start(add_months(month_of($s['today']), -$months)); $to = month_start(month_of($s['today'])); $t = 0;
    foreach ($s['contribs'] as $c) { if ($c['target_type'] === $type && (int) $c['target_id'] === $id && $c['amount'] > 0 && !$c['transaction_id'] && $c['date'] >= $from && $c['date'] < $to) { $t += $c['amount']; } }
    return (int) round($t / $months);
}
function goal_views(array $s): array {
    return array_map(function ($g) use ($s) {
        $saved = saved_for($s['contribs'], 'goal', (int) $g['id']);
        return $g + goal_progress($g, $saved, $s['today'], observed_monthly($s, 'goal', (int) $g['id']));
    }, $s['goals']);
}
function purchase_views(array $s): array {
    return array_map(function ($p) use ($s) {
        $saved = saved_for($s['contribs'], 'purchase', (int) $p['id']);
        return $p + ['saved' => $saved] + purchase_needs($p, $saved, $s['today']);
    }, $s['purchases']);
}
function occurrence_views(array $s): array {
    $bills = []; foreach ($s['bills'] as $b) { $bills[(int) $b['id']] = $b; }
    $out = [];
    foreach ($s['occurrences'] as $o) {
        if (!isset($bills[(int) $o['bill_id']])) { continue; }
        $out[] = $o + ['bill' => $bills[(int) $o['bill_id']]] + occurrence_state((int) $o['amount'], (int) $o['paid'], $o['due_date'], $s['today']);
    }
    return $out;
}
function bill_saved(array $s, int $billId): int { return saved_for($s['contribs'], 'bill', $billId); }
function yearly_views(array $s): array {
    $occ = occurrence_views($s); $out = [];
    foreach ($s['bills'] as $b) {
        if ($b['kind'] !== 'yearly') { continue; }
        $mine = array_values(array_filter($occ, fn($o) => (int) $o['bill_id'] === (int) $b['id'] && !$o['skipped']));
        $next = null; foreach ($mine as $o) { if ($o['status'] !== 'paid') { $next = $o; break; } }
        $next ??= $mine ? end($mine) : null;
        $saved = bill_saved($s, (int) $b['id']);
        $out[] = $b + ['saved' => $saved, 'next' => $next, 'standard' => monthly_set_aside((int) $b['amount'], $b['frequency']),
            'catch_up' => $next && $next['status'] !== 'paid' ? catch_up_set_aside((int) $next['amount'], $saved, $next['due_date'], $s['today']) : 0,
            'history' => array_values(array_filter($mine, fn($o) => $o['status'] === 'paid'))];
    }
    return $out;
}

// ---------------- series / breakdowns ----------------
function month_series(array $s, int $count, ?string $end = null): array {
    $out = [];
    foreach (months_list($end ?? month_of($s['today']), $count) as $m) { $out[] = summarize_month($s['accounts'], $s['txs'], $s['contribs'], $m) + ['label' => month_label($m, true)]; }
    return $out;
}
function budget_vs_actual(array $s, string $month): array {
    $by = cat_by_id($s); $spend = spend_by_category($s['txs'], $month); $out = [];
    foreach ($s['budgets'] as $id => $budget) {
        if (!isset($by[$id])) { continue; }
        $spent = 0; foreach (cat_desc($s, $id) as $d) { $spent += $spend[$d] ?? 0; }
        $out[] = ['id' => $id, 'name' => $by[$id]['name'], 'grp' => $by[$id]['grp'], 'budget' => $budget, 'spent' => $spent, 'remaining' => $budget - $spent];
    }
    usort($out, fn($a, $b) => $b['spent'] <=> $a['spent']);
    return $out;
}
function category_breakdown(array $s, string $month): array {
    $o = [];
    foreach (spend_by_category($s['txs'], $month) as $id => $v) { $n = $id ? (cat_top($s, $id)['name'] ?? 'Uncategorised') : 'Uncategorised'; $o[$n] = ($o[$n] ?? 0) + $v; }
    arsort($o); $r = [];
    foreach ($o as $n => $v) { if ($v > 0) { $r[] = ['name' => $n, 'value' => $v]; } }
    return $r;
}
function cashflow_forecast(array $s, int $days = 90): array {
    $end = day_after_n($s['today'], $days); $ev = [];
    foreach ($s['txs'] as $t) {
        if ($t['status'] !== 'planned' || $t['date'] > $end) { continue; }
        $d = max($t['date'], $s['today']);
        if ($t['type'] === 'income') { $ev[] = ['date' => $d, 'amount' => $t['amount'], 'label' => ($t['description'] ?: 'Income') . ' (expected)']; }
        if ($t['type'] === 'expense') { $ev[] = ['date' => $d, 'amount' => -$t['amount'], 'label' => $t['description'] ?: 'Planned expense']; }
    }
    foreach (occurrence_views($s) as $o) {
        if ($o['skipped'] || $o['outstanding'] <= 0 || $o['due_date'] > $end) { continue; }
        $ev[] = ['date' => max($o['due_date'], $s['today']), 'amount' => -$o['outstanding'], 'label' => $o['bill']['name']];
    }
    return project_cashflow(total_cash(active_accounts($s), $s['txs'], $s['today']), $ev);
}

function dashboard_data(array $s, ?string $month = null): array {
    $month ??= month_of($s['today']);
    $sum = summarize_month($s['accounts'], $s['txs'], $s['contribs'], $month);
    $cash = total_cash($s['accounts'], $s['txs'], $s['today']); $reserved = reserved_total($s['contribs']);
    $open = array_values(array_filter(occurrence_views($s), fn($o) => !$o['skipped'] && $o['outstanding'] > 0));
    $overdue = array_values(array_filter($open, fn($o) => $o['overdue']));
    $in30 = array_values(array_filter($open, fn($o) => !$o['overdue'] && $o['days'] <= 30));
    $bva = budget_vs_actual($s, $month);
    $totalBudget = array_sum(array_column($bva, 'budget')); $budgetSpent = array_sum(array_column($bva, 'spent'));
    $goals = goal_views($s); $pv = purchase_views($s);
    $milestones = [];
    foreach ($goals as $g) { if (!$g['complete'] && $g['target_date']) { $milestones[] = ['name' => $g['name'], 'date' => $g['target_date'], 'remaining' => $g['remaining'], 'percent' => $g['percent'], 'behind' => $g['behind']]; } }
    foreach ($pv as $p) { if ($p['target_date'] && $p['status'] !== 'purchased') { $milestones[] = ['name' => $p['name'], 'date' => $p['target_date'], 'remaining' => $p['remaining'], 'percent' => $p['cost'] ? min($p['saved'] / $p['cost'] * 100, 100) : 0, 'behind' => false]; } }
    usort($milestones, fn($a, $b) => $a['date'] <=> $b['date']);
    return [
        'sum' => $sum, 'cash' => $cash, 'reserved' => $reserved, 'spendable' => $cash - $reserved,
        'outstanding' => array_sum(array_column($overdue, 'outstanding')) + array_sum(array_map(fn($o) => $o['status'] === 'partial' ? $o['outstanding'] : 0, $open)),
        'upcoming_total' => array_sum(array_column($in30, 'outstanding')), 'upcoming_count' => count($in30),
        'upcoming' => array_slice(array_values(array_filter($open, fn($o) => $o['days'] <= 45 || $o['overdue'])), 0, 6), 'overdue_count' => count($overdue),
        'total_budget' => $totalBudget, 'budget_spent' => $budgetSpent, 'has_budgets' => count($bva) > 0,
        'series' => month_series($s, 6, $month), 'breakdown' => category_breakdown($s, $month), 'bva' => array_slice($bva, 0, 8),
        'goals' => $goals, 'total_saved' => array_sum(array_column($goals, 'saved')),
        'milestones' => array_slice($milestones, 0, 5),
        'recent' => array_slice(array_values(array_filter($s['txs'], fn($t) => $t['status'] === 'completed')), 0, 8),
        'behind_count' => count(array_filter($goals, fn($g) => $g['behind'])),
    ];
}

// ---------------- monthly planner ----------------
function build_proposal(array $i): array {
    $L = [];
    $add = function (string $sec, string $rt, int $id, string $label, int $amt, string $pri, bool $ess) use (&$L) { if ($amt > 0) { $L[] = ['key' => "$sec:$id", 'section' => $sec, 'refType' => $rt, 'refId' => $id, 'label' => $label, 'amount' => $amt, 'priority' => $pri, 'essential' => $ess]; } };
    foreach ($i['bills'] as $b) { $add('bills', 'bill', $b['id'], $b['name'], $b['outstanding'], 'high', true); }
    foreach ($i['yearly'] as $y) { $add('yearly', 'bill', $y['id'], $y['name'], $y['set_aside'], 'high', true); }
    foreach ($i['household'] as $h) { $add('household', 'category', $h['id'], $h['name'], $h['estimate'], 'high', true); }
    foreach ($i['personal'] as $p) { $add('personal', 'category', $p['id'], $p['name'], $p['estimate'], 'low', false); }
    foreach ($i['occasional'] as $o) { $add('occasional', 'purchase', $o['id'], $o['name'], $o['monthly'], $o['priority'], $o['priority'] === 'high'); }
    foreach ($i['goals'] as $g) { $add('goals', 'goal', $g['id'], $g['name'], $g['monthly'], $g['priority'], $g['priority'] === 'high'); }
    foreach ($i['house'] as $h) { $add('house', 'purchase', $h['id'], $h['name'], $h['monthly'], $h['priority'], false); }
    foreach ($i['personal_fund'] as $p) { $add('personalFund', 'goal', $p['id'], $p['name'], $p['monthly'], 'low', false); }
    foreach ($i['big'] as $b) { $add('big', 'purchase', $b['id'], $b['name'], $b['monthly'], $b['priority'], false); }
    return $L;
}
function planner_inputs(array $s, string $month): array {
    $sum = summarize_month($s['accounts'], $s['txs'], $s['contribs'], $month);
    $openingSpendable = $sum['opening'] - reserved_total($s['contribs'], day_before(month_start($month)));
    $avg = function (array $ids) use ($s, $month) { $t = 0; foreach ([1, 2, 3] as $n) { $sp = spend_by_category($s['txs'], add_months($month, -$n)); foreach ($ids as $id) { $t += $sp[$id] ?? 0; } } return (int) round($t / 3); };
    $estimates = function (string $grp) use ($s, $avg) {
        $o = []; foreach ($s['categories'] as $c) { if ($c['grp'] === $grp && !$c['parent_id']) { $o[] = ['id' => (int) $c['id'], 'name' => $c['name'], 'estimate' => $s['budgets'][(int) $c['id']] ?? max($avg(cat_desc($s, (int) $c['id'])), 0)]; } } return $o;
    };
    $occ = array_filter(occurrence_views($s), fn($o) => !$o['skipped'] && $o['bill']['kind'] === 'bill');
    $bills = [];
    foreach ($occ as $o) {
        if ($o['due_date'] > month_end($month)) { continue; }
        if (month_of($o['due_date']) === $month || $o['outstanding'] > 0) {
            $bills[(int) $o['bill_id']] = ['id' => (int) $o['bill_id'], 'name' => $o['bill']['name'], 'outstanding' => ($o['outstanding'] > 0 && month_of($o['due_date']) < $month) ? $o['outstanding'] : (int) $o['amount']];
        }
    }
    $yearly = []; foreach (yearly_views($s) as $y) { if ($y['active']) { $yearly[] = ['id' => (int) $y['id'], 'name' => $y['name'], 'set_aside' => min($y['standard'], max($y['amount'] - $y['saved'], 0))]; } }
    $pv = array_filter(purchase_views($s), fn($p) => !in_array($p['status'], ['purchased', 'postponed'], true));
    $monthly = fn($p) => $p['remaining'] <= 0 ? 0 : min($p['monthly_setaside'] > 0 ? (int) $p['monthly_setaside'] : ($p['needed'] ?? 0), $p['remaining']);
    $mapP = fn($kind) => array_values(array_map(fn($p) => ['id' => (int) $p['id'], 'name' => $p['name'], 'monthly' => $monthly($p), 'setAside' => $monthly($p), 'priority' => $p['priority']], array_filter($pv, fn($p) => $p['kind'] === $kind)));
    $gv = goal_views($s);
    return [
        'opening' => $sum['opening'], 'opening_spendable' => $openingSpendable, 'available' => $openingSpendable + $sum['income'] + $sum['income_expected'],
        'income' => $sum['income'], 'income_expected' => $sum['income_expected'],
        'bills' => array_values($bills), 'yearly' => $yearly, 'household' => $estimates('household'), 'personal' => $estimates('personal'),
        'occasional' => array_map(fn($x) => $x + ['monthly' => $x['setAside']], $mapP('occasional')),
        'goals' => array_values(array_map(fn($g) => ['id' => (int) $g['id'], 'name' => $g['name'], 'monthly' => min((int) $g['monthly_target'] > 0 ? (int) $g['monthly_target'] : ($g['required'] ?? 0), $g['remaining']), 'priority' => $g['priority']], array_filter($gv, fn($g) => $g['kind'] === 'long-term' && !$g['complete']))),
        'house' => $mapP('house'),
        'personal_fund' => array_values(array_map(fn($g) => ['id' => (int) $g['id'], 'name' => $g['name'], 'monthly' => (int) $g['monthly_target']], array_filter($gv, fn($g) => $g['kind'] === 'personal'))),
        'big' => $mapP('big'),
    ];
}
function proposal_for(array $s, string $month): array { $in = planner_inputs($s, $month); return [$in, build_proposal($in)]; }

// ---------------- insights & reminders ----------------
function insights_for(array $s, ?int $planUnallocated = null, ?int $purchaseBudget = null): array {
    $month = month_of($s['today']); $d = dashboard_data($s, $month);
    $occ = array_filter(occurrence_views($s), fn($o) => !$o['skipped']);
    $cf = cashflow_forecast($s);
    $pvs = array_filter(purchase_views($s), fn($p) => !in_array($p['status'], ['purchased', 'postponed'], true));
    return build_insights([
        'today' => $s['today'],
        'category_budgets' => array_map(fn($b) => ['name' => $b['name'], 'budget' => $b['budget'], 'spent' => $b['spent']], budget_vs_actual($s, $month)),
        'due_soon' => array_values(array_map(fn($y) => ['name' => $y['name'], 'amount' => (int) $y['next']['amount'], 'saved' => $y['saved'], 'due' => date_label($y['next']['due_date']), 'days' => $y['next']['days']], array_filter(yearly_views($s), fn($y) => $y['next'] && $y['next']['outstanding'] > 0))),
        'overdue' => array_values(array_map(fn($o) => ['name' => $o['bill']['name'], 'outstanding' => $o['outstanding'], 'due' => date_label($o['due_date'])], array_filter($occ, fn($o) => $o['overdue']))),
        'goals_behind' => array_values(array_map(fn($g) => ['name' => $g['name'], 'remaining' => $g['remaining'], 'required' => $g['required'] ?? 0, 'plan' => (int) $g['monthly_target'], 'target_date' => date_label($g['target_date'])], array_filter(goal_views($s), fn($g) => $g['behind'] && $g['target_date']))),
        'spendable' => $d['spendable'], 'plan_unallocated' => $planUnallocated, 'purchase_monthly' => array_sum(array_map(fn($p) => $p['needed'] ?? 0, $pvs)),
        'savings_budget' => $purchaseBudget,
        'shortfall' => $cf['shortfall'] ? ['date' => date_label($cf['shortfall']['date'])] + $cf['shortfall'] : null,
        'rate' => $d['sum']['rate'], 'income' => $d['sum']['income'], 'expenses' => $d['sum']['expenses'], 'reserved_exceeds_cash' => $d['reserved'] > 0 && $d['spendable'] < 0,
    ]);
}
function sync_notifications(array $s): void {
    $live = []; $views = occurrence_views($s);
    foreach ($views as $o) { if (!$o['skipped'] && $o['outstanding'] > 0) { $live["overdue:{$o['id']}"] = $live["soon:{$o['id']}"] = true; } }
    foreach (all("SELECT id,dedupe_key FROM {notifications} WHERE user_id=? AND (dedupe_key LIKE 'overdue:%' OR dedupe_key LIKE 'soon:%')", [$s['uid']]) as $n) { if (!isset($live[$n['dedupe_key']])) { run('DELETE FROM {notifications} WHERE id=? AND user_id=?', [$n['id'], $s['uid']]); } }
    $up = 'INSERT INTO {notifications} (user_id,dedupe_key,kind,title,body,due_date) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title), body=VALUES(body), kind=VALUES(kind), due_date=VALUES(due_date)';
    foreach ($views as $o) {
        if ($o['skipped'] || $o['outstanding'] <= 0) { continue; }
        $window = (int) ($o['bill']['reminder_days'] ?? $s['prefs']['reminder_days']);
        if ($o['overdue']) { run($up, [$s['uid'], "overdue:{$o['id']}", 'overdue', $o['bill']['name'] . ' is overdue', fmt($o['outstanding']) . ' was due on ' . date_label($o['due_date']) . '.', $o['due_date']]); }
        elseif ($o['days'] <= $window) { run($up, [$s['uid'], "soon:{$o['id']}", 'upcoming', $o['bill']['name'] . ' due ' . ($o['days'] === 0 ? 'today' : "in {$o['days']} day" . ($o['days'] === 1 ? '' : 's')), fmt($o['outstanding']) . ' due on ' . date_label($o['due_date']) . '.', $o['due_date']]); }
    }
}


// ---------------- savings plan: what must move this month, in priority order ----------------
/** Typical monthly saving capacity: the average of income − expenses over the last 3 full months (never below zero). */
function savings_capacity(array $s): int {
    $cur = month_of($s['today']); $sum = 0;
    foreach ([1, 2, 3] as $n) { $x = summarize_month($s['accounts'], $s['txs'], $s['contribs'], add_months($cur, -$n)); $sum += $x['net']; }
    return max((int) round($sum / 3), 0);
}
/** One row per thing being saved for. need = what should move this month to stay on schedule. */
function savings_items(array $s): array {
    $month = month_of($s['today']); $items = [];
    $movedBy = function (string $type, int $id) use ($s, $month) { $t = 0; foreach ($s['contribs'] as $c) { if ($c['target_type'] === $type && (int) $c['target_id'] === $id && $c['amount'] > 0 && !$c['transaction_id'] && month_of($c['date']) === $month) { $t += $c['amount']; } } return $t; };
    foreach (goal_views($s) as $g) {
        if ($g['kind'] !== 'long-term' || $g['complete']) { continue; }
        $need = min($g['required'] ?? (int) $g['monthly_target'], $g['remaining']); $items[] = ['key' => 'goal:' . $g['id'], 'type' => 'goal', 'id' => (int) $g['id'], 'name' => $g['name'], 'what' => 'Goal', 'priority' => $g['priority'], 'date' => $g['target_date'], 'target' => (int) $g['target_amount'], 'saved' => $g['saved'], 'remaining' => $g['remaining'], 'need' => max($need, 0), 'account_id' => null];
    }
    foreach (purchase_views($s) as $p) {
        if (in_array($p['status'], ['purchased', 'postponed'], true) || $p['remaining'] <= 0) { continue; }
        $need = min($p['needed'] ?? (int) $p['monthly_setaside'], $p['remaining']); $items[] = ['key' => 'purchase:' . $p['id'], 'type' => 'purchase', 'id' => (int) $p['id'], 'name' => $p['name'], 'what' => ['house' => 'House item', 'big' => 'Big purchase', 'occasional' => 'Future plan'][$p['kind']], 'priority' => $p['priority'], 'date' => $p['target_date'], 'target' => $p['cost'], 'saved' => $p['saved'], 'remaining' => $p['remaining'], 'need' => max($need, 0), 'account_id' => $p['account_id'] ? (int) $p['account_id'] : null];
    }
    foreach (yearly_views($s) as $y) {
        if (!$y['active'] || !$y['next'] || $y['next']['status'] === 'paid') { continue; }
        $remaining = max($y['next']['outstanding'] - $y['saved'], 0); if ($remaining <= 0) { continue; }
        $items[] = ['key' => 'bill:' . $y['id'], 'type' => 'bill', 'id' => (int) $y['id'], 'name' => $y['name'], 'what' => 'Yearly bill', 'priority' => 'high', 'date' => $y['next']['due_date'], 'target' => $y['next']['outstanding'], 'saved' => $y['saved'], 'remaining' => $remaining, 'need' => min($y['catch_up'], $remaining), 'account_id' => $y['account_id'] ? (int) $y['account_id'] : null];
    }
    foreach ($items as &$i) { $i['moved'] = $movedBy($i['type'], $i['id']); $i['due'] = max($i['need'] - $i['moved'], 0); $i['pct'] = $i['target'] > 0 ? min($i['saved'] / $i['target'] * 100, 100) : 0; } unset($i);
    return $items;
}
/** Hands this month's capacity to the items in priority order (high first, then earlier dates). */
function savings_plan(array $s): array {
    $items = savings_items($s); $cap = savings_capacity($s); $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
    usort($items, fn($a, $b) => [$rank[$a['priority']], $a['date'] ?? '9999-12-31', $a['remaining']] <=> [$rank[$b['priority']], $b['date'] ?? '9999-12-31', $b['remaining']]);
    $left = $cap; $due = 0; $give = 0;
    foreach ($items as $n => &$i) { $i['order'] = $n + 1; $i['give'] = min($i['due'], $left); $left -= $i['give']; $i['short'] = $i['due'] - $i['give']; $due += $i['due']; $give += $i['give']; } unset($i);
    return ['capacity' => $cap, 'items' => $items, 'due' => $due, 'give' => $give, 'left' => $left, 'short' => $due - $give, 'moved' => array_sum(array_column($items, 'moved'))];
}
