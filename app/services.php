<?php
declare(strict_types=1);
/** User-scoped writes. Every function takes the owning $uid and verifies referenced rows belong to it. */

const OWNED = ['accounts', 'categories', 'goals', 'purchases', 'bills', 'bill_occurrences', 'transactions', 'recurring_templates', 'monthly_plans'];

function owned(string $table, ?int $id, int $uid): array {
    if (!in_array($table, OWNED, true)) { throw new LogicException('bad table'); }
    $r = $id ? one("SELECT * FROM {{$table}} WHERE id = ? AND user_id = ?", [$id, $uid]) : null;
    if (!$r) { throw new UserError('Record not found.'); }
    return $r;
}
function owned_or_null(string $table, ?int $id, int $uid): ?array { return $id ? owned($table, $id, $uid) : null; }

// ============================ defaults / registration ============================
const DEFAULT_CATEGORIES = [
    'fixed' => ['Electricity', 'Gas', 'Internet & Mobile', 'Rent', 'Insurance', 'Subscriptions', 'Other Bills'],
    'household' => ['Groceries', 'Cleaning Supplies', 'Home Maintenance', 'Household Necessities'],
    'personal' => ['Shopping', 'Food & Dining', 'Transport & Travel', 'Entertainment', 'Personal Care', 'Education', 'Miscellaneous'],
    'occasional' => ['Home Repairs', 'Festival Shopping', 'Medical', 'Travel', 'Annual Subscriptions', 'Emergencies'],
    'income' => ['Salary', 'Business', 'Rental Income', 'Interest', 'Gift', 'Other Income'],
];
const GROUP_LABELS = ['fixed' => 'Fixed & recurring bills', 'household' => 'Household & grocery', 'personal' => 'Personal', 'occasional' => 'Occasional', 'custom' => 'Custom', 'income' => 'Income'];

function create_user(string $email, string $name, string $password, string $role = 'user'): int {
    return tx(function () use ($email, $name, $password, $role) {
        $id = insert('INSERT INTO {users} (email, name, password_hash, role) VALUES (?,?,?,?)', [strtolower(trim($email)), $name, password_hash($password, PASSWORD_DEFAULT), $role]);
        foreach (DEFAULT_CATEGORIES as $grp => $names) { foreach ($names as $n) { run('INSERT INTO {categories} (user_id, name, grp) VALUES (?,?,?)', [$id, $n, $grp]); } }
        $t = today();
        run('INSERT INTO {accounts} (user_id, name, type, opening_balance, opening_date) VALUES (?,?,?,?,?)', [$id, 'Main Account', 'bank', 0, $t]);
        run('INSERT INTO {accounts} (user_id, name, type, opening_balance, opening_date) VALUES (?,?,?,?,?)', [$id, 'Cash', 'cash', 0, $t]);
        run('INSERT INTO {preferences} (user_id, currency, locale) VALUES (?,?,?)', [$id, site_setting('default_currency', 'INR'), site_setting('default_locale', 'en-IN')]);
        return $id;
    });
}
function category_id_by_name(int $uid, string $name): ?int {
    $v = val('SELECT id FROM {categories} WHERE user_id = ? AND name = ? AND parent_id IS NULL LIMIT 1', [$uid, $name]);
    return $v === null ? null : (int) $v;
}

// ============================ transactions ============================
function validate_tx(int $uid, array $i): void {
    owned_or_null('accounts', $i['account_id'] ?? null, $uid); owned_or_null('accounts', $i['to_account_id'] ?? null, $uid); owned_or_null('categories', $i['category_id'] ?? null, $uid);
    if ($i['type'] === 'transfer') {
        if (!$i['account_id'] || !$i['to_account_id']) { throw new UserError('Choose both accounts for a transfer.'); }
        if ($i['account_id'] === $i['to_account_id']) { throw new UserError('A transfer needs two different accounts.'); }
    } elseif (!$i['account_id']) { throw new UserError('Choose an account.'); }
    if ($i['type'] === 'adjustment') { if ($i['amount'] === 0) { throw new UserError('Adjustment cannot be zero.'); } }
    elseif ($i['amount'] <= 0) { throw new UserError('Amount must be greater than zero.'); }
    if (!empty($i['refund_of'])) {
        $o = owned('transactions', $i['refund_of'], $uid);
        if ($o['type'] !== 'expense') { throw new UserError('Only expenses can be refunded.'); }
        $done = (int) val("SELECT COALESCE(SUM(amount),0) FROM {transactions} WHERE user_id=? AND refund_of=? AND status='completed' AND id <> ?", [$uid, $i['refund_of'], $i['id'] ?? 0]);
        if ($i['status'] === 'completed' && $done + $i['amount'] > (int) $o['amount']) { throw new UserError('Refund would exceed the original expense.'); }
    }
    if (!empty($i['fund_goal_id'])) {
        $g = owned('goals', $i['fund_goal_id'], $uid);
        if ($g['kind'] !== 'personal' || $i['type'] !== 'expense') { throw new UserError('Only expenses can be paid from the personal fund.'); }
    }
}
function sync_fund(int $uid, int $txId, array $i): void {
    run('DELETE FROM {contributions} WHERE user_id=? AND transaction_id=?', [$uid, $txId]);
    if (!empty($i['fund_goal_id']) && $i['type'] === 'expense' && $i['status'] === 'completed') {
        run('INSERT INTO {contributions} (user_id,target_type,target_id,amount,date,account_id,transaction_id,note) VALUES (?,?,?,?,?,?,?,?)',
            [$uid, 'goal', $i['fund_goal_id'], -$i['amount'], $i['date'], $i['account_id'], $txId, $i['description'] ?: 'Spent from fund']);
    }
}
function tx_params(int $uid, array $i): array {
    $t = $i['type'] === 'transfer';
    return [$i['type'], $i['status'], $i['amount'], $i['date'], $i['account_id'], $t ? $i['to_account_id'] : null, $t ? null : ($i['category_id'] ?? null),
        mb_substr($i['description'] ?? '', 0, 200), $i['payment_method'] ?? null, $i['notes'] ?? null, $i['refund_of'] ?? null];
}
function create_transaction(int $uid, array $i): int {
    $i += ['status' => 'completed', 'to_account_id' => null, 'category_id' => null, 'description' => '', 'payment_method' => null, 'notes' => null, 'refund_of' => null, 'fund_goal_id' => null];
    validate_tx($uid, $i);
    return tx(function () use ($uid, $i) {
        $id = insert('INSERT INTO {transactions} (type,status,amount,date,account_id,to_account_id,category_id,description,payment_method,notes,refund_of,user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', array_merge(tx_params($uid, $i), [$uid]));
        sync_fund($uid, $id, $i);
        return $id;
    });
}
function update_transaction(int $uid, int $id, array $i): void {
    $ex = owned('transactions', $id, $uid);
    if ($ex['occurrence_id']) { throw new UserError("Bill payments can't be edited. Delete it and record the payment again."); }
    $i += ['status' => 'completed', 'to_account_id' => null, 'category_id' => null, 'description' => '', 'payment_method' => null, 'notes' => null, 'refund_of' => null, 'fund_goal_id' => null, 'id' => $id];
    validate_tx($uid, $i);
    tx(function () use ($uid, $id, $i) {
        run('UPDATE {transactions} SET type=?,status=?,amount=?,date=?,account_id=?,to_account_id=?,category_id=?,description=?,payment_method=?,notes=?,refund_of=? WHERE id=? AND user_id=?', array_merge(tx_params($uid, $i), [$id, $uid]));
        sync_fund($uid, $id, $i);
    });
}
function delete_transaction(int $uid, int $id): void {
    owned('transactions', $id, $uid);
    tx(function () use ($uid, $id) {
        run('DELETE FROM {transactions} WHERE user_id=? AND refund_of=?', [$uid, $id]);
        run('DELETE FROM {transactions} WHERE id=? AND user_id=?', [$id, $uid]);
    });
}
function complete_planned(int $uid, int $id, string $date): void {
    $t = owned('transactions', $id, $uid);
    if ($t['status'] !== 'planned') { throw new UserError('Already completed.'); }
    run("UPDATE {transactions} SET status='completed', date=? WHERE id=? AND user_id=?", [$date, $id, $uid]);
}

// ============================ accounts / categories / budgets / templates ============================
const ACCOUNT_PURPOSES = ['spending' => 'Everyday spending', 'income' => 'Income from job', 'savings' => 'Savings account', 'other' => 'Other'];
function save_account(int $uid, ?int $id, string $name, string $type, int $opening, string $openingDate, string $purpose = 'spending', ?string $label = null): void {
    if (!isset(ACCOUNT_PURPOSES[$purpose])) { throw new UserError('Choose what this account is for.'); }
    try {
        if ($id) { owned('accounts', $id, $uid); run('UPDATE {accounts} SET name=?,type=?,opening_balance=?,opening_date=?,purpose=?,label=? WHERE id=? AND user_id=?', [$name, $type, $opening, $openingDate, $purpose, $label, $id, $uid]); }
        else { run('INSERT INTO {accounts} (user_id,name,type,opening_balance,opening_date,purpose,label) VALUES (?,?,?,?,?,?,?)', [$uid, $name, $type, $opening, $openingDate, $purpose, $label]); }
    } catch (PDOException $e) { if ($e->getCode() === '23000') { throw new UserError('You already have an account with that name.'); } throw $e; }
}
/** Reconcile an account to the balance the bank shows: records the difference as an adjustment (not income or expense). */
function set_account_balance(int $uid, int $accountId, int $target, string $date, ?string $note): int {
    $a = owned('accounts', $accountId, $uid); $txs = all('SELECT id,type,status,amount,date,account_id,to_account_id FROM {transactions} WHERE user_id=?', [$uid]);
    $diff = $target - account_balance($a, $txs);
    if ($diff === 0) { throw new UserError('That is already the balance.'); }
    create_transaction($uid, ['type' => 'adjustment', 'amount' => $diff, 'date' => max($date, $a['opening_date']), 'account_id' => $accountId, 'description' => 'Balance correction', 'notes' => $note]);
    return $diff;
}

function delete_account(int $uid, int $id): string {
    owned('accounts', $id, $uid);
    $n = (int) val('SELECT COUNT(*) FROM {transactions} WHERE user_id=? AND (account_id=? OR to_account_id=?)', [$uid, $id, $id]);
    if ($n > 0) { run('UPDATE {accounts} SET archived=1 WHERE id=? AND user_id=?', [$id, $uid]); return 'archived'; }
    if ((int) val('SELECT COUNT(*) FROM {accounts} WHERE user_id=? AND archived=0', [$uid]) <= 1) { throw new UserError('You need at least one account.'); }
    run('DELETE FROM {accounts} WHERE id=? AND user_id=?', [$id, $uid]);
    return 'deleted';
}
function save_category(int $uid, ?int $id, string $name, string $grp, ?int $parent): void {
    $p = $parent ? owned('categories', $parent, $uid) : null;
    if ($p && $p['parent_id']) { throw new UserError('Subcategories can only be one level deep.'); }
    if ($id && $parent === $id) { throw new UserError('A category cannot be its own parent.'); }
    $grp = $p['grp'] ?? $grp;
    $dup = val('SELECT id FROM {categories} WHERE user_id=? AND name=? AND ((parent_id IS NULL AND ? IS NULL) OR parent_id = ?) AND id <> ?', [$uid, $name, $parent, $parent, $id ?? 0]);
    if ($dup) { throw new UserError('A category with that name already exists here.'); }
    if ($id) { owned('categories', $id, $uid); run('UPDATE {categories} SET name=?, grp=?, parent_id=? WHERE id=? AND user_id=?', [$name, $grp, $parent, $id, $uid]); }
    else { run('INSERT INTO {categories} (user_id,name,grp,parent_id) VALUES (?,?,?,?)', [$uid, $name, $grp, $parent]); }
}
function delete_category(int $uid, int $id): void { owned('categories', $id, $uid); run('DELETE FROM {categories} WHERE id=? AND user_id=?', [$id, $uid]); }
function set_budget(int $uid, int $cat, int $amount): void {
    owned('categories', $cat, $uid);
    if ($amount <= 0) { run('DELETE FROM {budgets} WHERE user_id=? AND category_id=?', [$uid, $cat]); return; }
    run('INSERT INTO {budgets} (user_id,category_id,amount) VALUES (?,?,?) ON DUPLICATE KEY UPDATE amount=VALUES(amount)', [$uid, $cat, $amount]);
}
function save_template(int $uid, ?int $id, array $i): void {
    owned_or_null('categories', $i['category_id'], $uid); owned('accounts', $i['account_id'], $uid);
    $day = (int) substr($i['next_date'], 8, 2);
    if ($id) {
        owned('recurring_templates', $id, $uid);
        run('UPDATE {recurring_templates} SET type=?,description=?,amount=?,category_id=?,account_id=?,frequency=?,next_date=?,anchor_day=?,active=? WHERE id=? AND user_id=?',
            [$i['type'], $i['description'], $i['amount'], $i['category_id'], $i['account_id'], $i['frequency'], $i['next_date'], $day, (int) $i['active'], $id, $uid]);
    } else {
        run('INSERT INTO {recurring_templates} (user_id,type,description,amount,category_id,account_id,frequency,next_date,anchor_day,active) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$uid, $i['type'], $i['description'], $i['amount'], $i['category_id'], $i['account_id'], $i['frequency'], $i['next_date'], $day, (int) $i['active']]);
    }
}
function delete_template(int $uid, int $id): void { owned('recurring_templates', $id, $uid); run('DELETE FROM {recurring_templates} WHERE id=? AND user_id=?', [$id, $uid]); }
/** Due recurring items become *planned* transactions (never auto-paid). Idempotent. */
function generate_from_templates(int $uid, string $today): void {
    foreach (all('SELECT * FROM {recurring_templates} WHERE user_id=? AND active=1 AND next_date<=?', [$uid, $today]) as $t) {
        tx(function () use ($uid, $t, $today) {
            $date = $t['next_date'];
            for ($n = 0; $n < 36 && $date <= $today; $n++) {
                run("INSERT INTO {transactions} (user_id,type,status,amount,date,account_id,category_id,description,template_id)
                     SELECT ?,?, 'planned', ?,?,?,?,?,? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM {transactions} WHERE user_id=? AND template_id=? AND date=?)",
                    [$uid, $t['type'], $t['amount'], $date, $t['account_id'], $t['category_id'], $t['description'], $t['id'], $uid, $t['id'], $date]);
                $date = next_due($date, $t['frequency'], (int) $t['anchor_day']);
            }
            run('UPDATE {recurring_templates} SET next_date=? WHERE id=?', [$date, $t['id']]);
        });
    }
}

// ============================ savings ============================
const TARGET_TABLE = ['goal' => 'goals', 'purchase' => 'purchases', 'bill' => 'bills'];
function saved_amount(int $uid, string $type, int $id): int {
    return (int) val('SELECT COALESCE(SUM(amount),0) FROM {contributions} WHERE user_id=? AND target_type=? AND target_id=?', [$uid, $type, $id]);
}
/** Move money into (positive) or out of (negative) a pot. A transfer inside the user's own money, never an expense. */
function add_contribution(int $uid, string $type, int $id, int $amount, string $date, ?int $account, ?string $note): int {
    if ($amount === 0) { throw new UserError('Amount cannot be zero.'); }
    owned(TARGET_TABLE[$type], $id, $uid); owned_or_null('accounts', $account, $uid);
    if ($amount < 0 && saved_amount($uid, $type, $id) + $amount < 0) { throw new UserError('You cannot withdraw more than has been saved.'); }
    return insert('INSERT INTO {contributions} (user_id,target_type,target_id,amount,date,account_id,note) VALUES (?,?,?,?,?,?,?)', [$uid, $type, $id, $amount, $date, $account, $note]);
}
function delete_contribution(int $uid, int $id): void {
    $c = one('SELECT transaction_id FROM {contributions} WHERE id=? AND user_id=?', [$id, $uid]);
    if (!$c) { throw new UserError('Record not found.'); }
    if ($c['transaction_id']) { throw new UserError('This entry belongs to a payment. Delete the payment instead.'); }
    run('DELETE FROM {contributions} WHERE id=? AND user_id=?', [$id, $uid]);
}
function save_goal(int $uid, ?int $id, array $g): int {
    if ($g['kind'] === 'long-term' && $g['target_amount'] <= 0) { throw new UserError('Enter a target amount greater than zero.'); }
    if ($id) {
        owned('goals', $id, $uid);
        run('UPDATE {goals} SET name=?,target_amount=?,target_date=?,priority=?,monthly_target=?,monthly_limit=?,notes=? WHERE id=? AND user_id=?',
            [$g['name'], $g['target_amount'], $g['target_date'], $g['priority'], $g['monthly_target'], $g['monthly_limit'], $g['notes'], $id, $uid]);
        return $id;
    }
    return insert('INSERT INTO {goals} (user_id,kind,name,target_amount,target_date,priority,monthly_target,monthly_limit,notes) VALUES (?,?,?,?,?,?,?,?,?)',
        [$uid, $g['kind'], $g['name'], $g['target_amount'], $g['target_date'], $g['priority'], $g['monthly_target'], $g['monthly_limit'], $g['notes']]);
}
function delete_goal(int $uid, int $id): void {
    owned('goals', $id, $uid);
    tx(function () use ($uid, $id) { run("DELETE FROM {contributions} WHERE user_id=? AND target_type='goal' AND target_id=?", [$uid, $id]); run('DELETE FROM {goals} WHERE id=? AND user_id=?', [$id, $uid]); });
}
function save_purchase(int $uid, ?int $id, array $p): int {
    if ($id) {
        $cur = owned('purchases', $id, $uid);
        if ($p['status'] === 'purchased' && $cur['status'] !== 'purchased') { throw new UserError('Use "Mark as purchased" to record what you paid.'); }
        owned_or_null('accounts', $p['account_id'] ?? null, $uid);
        run('UPDATE {purchases} SET name=?,description=?,group_label=?,estimated=?,priority=?,target_date=?,status=?,monthly_setaside=?,account_id=? WHERE id=? AND user_id=?',
            [$p['name'], $p['description'], $p['group_label'], $p['estimated'], $p['priority'], $p['target_date'], $p['status'], $p['monthly_setaside'], $p['account_id'] ?? null, $id, $uid]);
        return $id;
    }
    if ($p['status'] === 'purchased') { throw new UserError('Use "Mark as purchased" after adding the item.'); }
    owned_or_null('accounts', $p['account_id'] ?? null, $uid);
    return insert('INSERT INTO {purchases} (user_id,kind,name,description,group_label,estimated,priority,target_date,status,monthly_setaside,account_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        [$uid, $p['kind'], $p['name'], $p['description'], $p['group_label'], $p['estimated'], $p['priority'], $p['target_date'], $p['status'], $p['monthly_setaside'], $p['account_id'] ?? null]);
}
function delete_purchase(int $uid, int $id): void {
    owned('purchases', $id, $uid);
    tx(function () use ($uid, $id) { run("DELETE FROM {contributions} WHERE user_id=? AND target_type='purchase' AND target_id=?", [$uid, $id]); run('DELETE FROM {purchases} WHERE id=? AND user_id=?', [$id, $uid]); });
}
/** One expense for the real price; the whole reserved pot is released (net spendable effect = saved - actual). */
function mark_purchased(int $uid, int $id, int $actual, string $date, int $account, ?int $category): void {
    $p = owned('purchases', $id, $uid);
    if ($p['status'] === 'purchased') { throw new UserError('Already marked as purchased.'); }
    if ($actual <= 0) { throw new UserError('Enter the price you paid.'); }
    tx(function () use ($uid, $id, $actual, $date, $account, $category, $p) {
        $txId = create_transaction($uid, ['type' => 'expense', 'amount' => $actual, 'date' => $date, 'account_id' => $account, 'category_id' => $category, 'description' => $p['name'], 'notes' => 'Planned purchase']);
        $saved = saved_amount($uid, 'purchase', $id);
        if ($saved > 0) { run('INSERT INTO {contributions} (user_id,target_type,target_id,amount,date,account_id,transaction_id,note) VALUES (?,?,?,?,?,?,?,?)', [$uid, 'purchase', $id, -$saved, $date, $account, $txId, 'Released on purchase']); }
        run("UPDATE {purchases} SET status='purchased', actual=?, purchased_date=? WHERE id=? AND user_id=?", [$actual, $date, $id, $uid]);
    });
}

// ============================ bills ============================
function ensure_occurrences(int $uid, string $today): void {
    foreach (all('SELECT * FROM {bills} WHERE user_id=? AND active=1', [$uid]) as $b) {
        $horizon = month_end(add_months(month_of($today), $b['kind'] === 'yearly' ? 13 : 3));
        foreach (occurrence_dates($b['first_due'], (int) $b['anchor_day'], $b['frequency'], $horizon) as $d) {
            run('INSERT IGNORE INTO {bill_occurrences} (user_id,bill_id,due_date,amount) VALUES (?,?,?,?)', [$uid, $b['id'], $d, $b['amount']]);
        }
    }
}
function save_bill(int $uid, ?int $id, array $b, string $today): int {
    owned_or_null('categories', $b['category_id'], $uid); owned_or_null('accounts', $b['account_id'], $uid);
    $anchor = (int) substr($b['due_date'], 8, 2);
    $first = $b['due_date']; // bring a past date forward to the current month's cycle
    for ($n = 0; $n < 600 && $first < month_start(month_of($today)); $n++) { $first = next_due($first, $b['frequency'], $anchor); }
    return tx(function () use ($uid, $id, $b, $anchor, $first, $today) {
        if ($id) {
            owned('bills', $id, $uid);
            run('UPDATE {bills} SET kind=?,name=?,amount=?,category_id=?,account_id=?,frequency=?,first_due=?,anchor_day=?,reminder_days=?,active=?,notes=? WHERE id=? AND user_id=?',
                [$b['kind'], $b['name'], $b['amount'], $b['category_id'], $b['account_id'], $b['frequency'], $first, $anchor, $b['reminder_days'], (int) $b['active'], $b['notes'], $id, $uid]);
            // rebuild unpaid future occurrences; paid history is kept
            run('DELETE o FROM {bill_occurrences} o WHERE o.user_id=? AND o.bill_id=? AND o.due_date >= ? AND NOT EXISTS (SELECT 1 FROM {transactions} t WHERE t.occurrence_id = o.id)', [$uid, $id, month_start(month_of($today))]);
        } else {
            $id = insert('INSERT INTO {bills} (user_id,kind,name,amount,category_id,account_id,frequency,first_due,anchor_day,reminder_days,active,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [$uid, $b['kind'], $b['name'], $b['amount'], $b['category_id'], $b['account_id'], $b['frequency'], $first, $anchor, $b['reminder_days'], (int) $b['active'], $b['notes']]);
        }
        ensure_occurrences($uid, $today);
        return $id;
    });
}
function delete_bill(int $uid, int $id): void {
    owned('bills', $id, $uid);
    tx(function () use ($uid, $id) {
        run("DELETE FROM {contributions} WHERE user_id=? AND target_type='bill' AND target_id=? AND transaction_id IS NULL", [$uid, $id]);
        run('DELETE FROM {bills} WHERE id=? AND user_id=?', [$id, $uid]); // payments stay as expenses (bill_id set null)
    });
}
function paid_for_occurrence(int $uid, int $occ): int {
    return (int) val("SELECT COALESCE(SUM(CASE type WHEN 'expense' THEN amount WHEN 'refund' THEN -amount END),0) FROM {transactions} WHERE user_id=? AND occurrence_id=? AND status='completed'", [$uid, $occ]);
}
/** One expense for cash actually paid; if money was set aside, $fromFund releases the matching reserve (no double deduction). */
function pay_occurrence(int $uid, int $occId, int $amount, string $date, int $account, bool $fromFund): void {
    $occ = owned('bill_occurrences', $occId, $uid);
    $bill = owned('bills', (int) $occ['bill_id'], $uid);
    owned('accounts', $account, $uid);
    $outstanding = (int) $occ['amount'] - paid_for_occurrence($uid, $occId);
    if ($amount <= 0) { throw new UserError('Enter an amount greater than zero.'); }
    if ($amount > $outstanding) { throw new UserError('That is more than the amount still due.'); }
    tx(function () use ($uid, $occId, $amount, $date, $account, $fromFund, $bill, $outstanding) {
        $txId = create_transaction($uid, ['type' => 'expense', 'amount' => $amount, 'date' => $date, 'account_id' => $account, 'category_id' => $bill['category_id'] ? (int) $bill['category_id'] : null,
            'description' => $bill['name'], 'notes' => $outstanding - $amount > 0 ? 'Partial payment' : null]);
        run('UPDATE {transactions} SET bill_id=?, occurrence_id=? WHERE id=? AND user_id=?', [$bill['id'], $occId, $txId, $uid]);
        $release = $fromFund ? min(saved_amount($uid, 'bill', (int) $bill['id']), $amount) : 0;
        if ($release > 0) { run('INSERT INTO {contributions} (user_id,target_type,target_id,amount,date,account_id,transaction_id,note) VALUES (?,?,?,?,?,?,?,?)', [$uid, 'bill', $bill['id'], -$release, $date, $account, $txId, 'Used to pay bill']); }
    });
}
function skip_occurrence(int $uid, int $id, bool $skipped): void { owned('bill_occurrences', $id, $uid); run('UPDATE {bill_occurrences} SET skipped=? WHERE id=? AND user_id=?', [(int) $skipped, $id, $uid]); }

// ============================ monthly plans ============================
function get_plan(int $uid, string $month): ?array {
    $p = one('SELECT id,status,confirmed_at FROM {monthly_plans} WHERE user_id=? AND month=?', [$uid, $month]);
    if (!$p) { return null; }
    $p['lines'] = all('SELECT * FROM {plan_lines} WHERE plan_id=? AND user_id=? ORDER BY sort,id', [$p['id'], $uid]);
    return $p;
}
/** Persist the edited plan. Confirming only records it (never moves money) and is refused above available funds. */
function save_plan(int $uid, string $month, array $lines, string $status, int $available): void {
    $total = array_sum(array_map(fn($l) => $l['amount'], $lines));
    if ($status === 'confirmed' && $total > $available) { throw new UserError('This plan is over the available money. Reduce some lines, or save it as a draft.'); }
    tx(function () use ($uid, $month, $lines, $status) {
        $ex = one('SELECT id FROM {monthly_plans} WHERE user_id=? AND month=?', [$uid, $month]);
        $applied = [];
        if ($ex) { foreach (all('SELECT section,ref_id,applied FROM {plan_lines} WHERE user_id=? AND plan_id=?', [$uid, $ex['id']]) as $r) { $applied[$r['section'] . ':' . $r['ref_id']] = (int) $r['applied']; } }
        $pid = $ex ? (int) $ex['id'] : insert('INSERT INTO {monthly_plans} (user_id,month,status) VALUES (?,?,?)', [$uid, $month, $status]);
        if ($ex) { run('DELETE FROM {plan_lines} WHERE plan_id=? AND user_id=?', [$pid, $uid]); }
        run('UPDATE {monthly_plans} SET status=?, confirmed_at=? WHERE id=? AND user_id=?', [$status, $status === 'confirmed' ? gmdate('Y-m-d H:i:s') : null, $pid, $uid]);
        foreach ($lines as $idx => $l) {
            run('INSERT INTO {plan_lines} (user_id,plan_id,section,ref_type,ref_id,label,amount,priority,applied,sort) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$uid, $pid, $l['section'], $l['refType'], $l['refId'], mb_substr($l['label'], 0, 120), $l['amount'], $l['priority'], $applied[$l['section'] . ':' . $l['refId']] ?? 0, $idx]);
        }
    });
}
function reset_plan(int $uid, string $month): void { run('DELETE FROM {monthly_plans} WHERE user_id=? AND month=?', [$uid, $month]); }
/** On request, turn a savings line of a confirmed plan into a real contribution. */
function apply_plan_line(int $uid, int $lineId, ?int $account): void {
    $l = one('SELECT * FROM {plan_lines} WHERE id=? AND user_id=?', [$lineId, $uid]);
    if (!$l) { throw new UserError('Record not found.'); }
    if ($l['applied']) { throw new UserError('Already applied.'); }
    if (!$l['ref_id'] || !in_array($l['ref_type'], ['goal', 'purchase', 'bill'], true)) { throw new UserError('This line cannot be applied to a savings pot.'); }
    if ($l['amount'] <= 0) { throw new UserError('Nothing to apply.'); }
    $plan = owned('monthly_plans', (int) $l['plan_id'], $uid);
    if ($plan['status'] !== 'confirmed') { throw new UserError('Confirm the plan first.'); }
    tx(function () use ($uid, $l, $plan, $account, $lineId) {
        add_contribution($uid, $l['ref_type'], (int) $l['ref_id'], (int) $l['amount'], today(), $account, 'Monthly plan ' . $plan['month']);
        run('UPDATE {plan_lines} SET applied=1 WHERE id=? AND user_id=?', [$lineId, $uid]);
    });
}

// ============================ demo data / wipe ============================
function load_demo_data(int $uid, ?string $today = null): void {
    $today ??= today();
    if ((int) val('SELECT COUNT(*) FROM {transactions} WHERE user_id=?', [$uid]) > 0) { throw new UserError('Sample data can only be added to an empty account.'); }
    $accs = all('SELECT id FROM {accounts} WHERE user_id=? ORDER BY id', [$uid]);
    $bank = (int) $accs[0]['id']; $cash = (int) ($accs[1]['id'] ?? $bank);
    run("UPDATE {accounts} SET purpose='income', label='Salary account' WHERE id=?", [$bank]); run("UPDATE {accounts} SET purpose='spending' WHERE id=?", [$cash]);
    $savAcc = (int) insert("INSERT INTO {accounts} (user_id,name,type,opening_balance,opening_date,purpose,label) VALUES (?,?,?,?,?,?,?)", [$uid, 'Savings Account', 'bank', 20000000, month_start(add_months(month_of($today), -6)), 'savings', 'Emergency & goals']);
    $cat = fn(string $n) => category_id_by_name($uid, $n);
    $seed = 7; $rnd = function (int $lo, int $hi) use (&$seed) { $seed = ($seed * 1103515245 + 12345) & 0x7fffffff; return $lo + ($seed % ($hi - $lo + 1)); };
    $this_m = month_of($today);
    tx(function () use ($uid, $today, $bank, $cash, $cat, $rnd, $this_m, $savAcc) {
        $start = month_start(add_months($this_m, -6));
        run('UPDATE {accounts} SET opening_balance=?, opening_date=? WHERE id=?', [8500000, $start, $bank]);
        run('UPDATE {accounts} SET opening_balance=?, opening_date=? WHERE id=?', [8000000, $start, $cash]);
        for ($k = -5; $k <= 0; $k++) {
            $m = add_months($this_m, $k);
            $past = fn(int $day) => $m . '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT) <= $today;
            $d = fn(int $day) => min($m . '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT), $today);
            $inc = function (int $amt, int $day, string $desc, string $c) use ($uid, $past, $d, $bank, $cat) { if ($past($day)) { create_transaction($uid, ['type' => 'income', 'amount' => $amt, 'date' => $d($day), 'account_id' => $bank, 'category_id' => $cat($c), 'description' => $desc]); } };
            $exp = function (int $amt, int $day, string $desc, string $c, ?int $acc = null, string $method = 'UPI') use ($uid, $past, $d, $bank, $cat) { if ($past($day)) { create_transaction($uid, ['type' => 'expense', 'amount' => $amt, 'date' => $d($day), 'account_id' => $acc ?? $bank, 'category_id' => $cat($c), 'description' => $desc, 'payment_method' => $method]); } };
            $inc(7800000, 1, 'Salary', 'Salary');
            if ($k % 2 === 0) { $inc($rnd(6, 14) * 100000, 12, 'Freelance project', 'Business'); }
            if ($k < 0) { // this month's rent and broadband are paid through their bills below
                $exp(2200000, 2, 'House rent', 'Rent', null, 'Bank transfer'); $exp($rnd(18, 32) * 10000, 8, 'Electricity bill', 'Electricity', null, 'Bank transfer'); $exp(119900, 5, 'Home broadband', 'Internet & Mobile', null, 'Card');
            }
            $exp(79900, 6, 'Mobile recharge', 'Internet & Mobile');
            for ($w = 0; $w < 4; $w++) { $exp($rnd(18, 32) * 10000, 3 + $w * 7, 'Weekly groceries', 'Groceries', $cash, 'Cash'); }
            $exp($rnd(8, 15) * 10000, 14, 'Cleaning supplies', 'Cleaning Supplies'); $exp($rnd(25, 60) * 10000, 17, 'Shopping', 'Shopping', null, 'Card');
            $exp($rnd(30, 60) * 10000, 19, 'Dining out', 'Food & Dining', null, 'Card'); $exp($rnd(15, 30) * 10000, 21, 'Fuel & cabs', 'Transport & Travel');
            if ($k === -2) { $exp(1850000, 10, 'Plumber & repairs', 'Home Repairs'); }
            if ($k === -1) { $exp(720000, 9, 'Doctor consultation & medicines', 'Medical'); }
        }
        foreach ([['Groceries', 900000], ['Shopping', 300000], ['Food & Dining', 350000], ['Transport & Travel', 250000], ['Entertainment', 200000], ['Cleaning Supplies', 120000]] as [$n, $a]) { if ($c = $cat($n)) { set_budget($uid, $c, $a); } }
        save_template($uid, null, ['type' => 'income', 'description' => 'Salary', 'amount' => 7800000, 'category_id' => $cat('Salary'), 'account_id' => $bank, 'frequency' => 'monthly', 'next_date' => add_months($this_m, 1) . '-01', 'active' => true]);
        $due = fn(int $day) => $this_m . '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
        $bill = fn(string $kind, string $name, int $amt, string $c, string $freq, string $dueDate, int $rem) => save_bill($uid, null, ['kind' => $kind, 'name' => $name, 'amount' => $amt, 'category_id' => $cat($c), 'account_id' => $bank, 'frequency' => $freq, 'due_date' => $dueDate, 'reminder_days' => $rem, 'active' => true, 'notes' => null], $today);
        $rent = $bill('bill', 'House rent', 2200000, 'Rent', 'monthly', $due(2), 5);
        $bill('bill', 'Electricity', 240000, 'Electricity', 'monthly', $due(min(28, (int) substr($today, 8) + 4)), 5);
        $net = $bill('bill', 'Broadband', 119900, 'Internet & Mobile', 'monthly', $due(5), 5);
        foreach ([[$rent, 2200000], [$net, 119900]] as [$bid, $amt]) {
            $o = one('SELECT id, due_date FROM {bill_occurrences} WHERE bill_id=? AND due_date LIKE ?', [$bid, $this_m . '-%']);
            if ($o && $o['due_date'] <= $today) { pay_occurrence($uid, (int) $o['id'], $amt, $o['due_date'], $bank, false); }
        }
        $bill('bill', 'Society maintenance', 900000, 'Other Bills', 'quarterly', add_months($this_m, 1) . '-10', 10);
        $lic = $bill('yearly', 'LIC premium', 2400000, 'Insurance', 'yearly', add_months($this_m, 2) . '-15', 30);
        $car = $bill('yearly', 'Vehicle insurance', 1150000, 'Insurance', 'yearly', add_months($this_m, 6) . '-03', 30);
        $bill('yearly', 'Property tax', 1400000, 'Other Bills', 'yearly', add_months($this_m, 9) . '-28', 30);
        for ($k = -3; $k <= 0; $k++) { add_contribution($uid, 'bill', $lic, 200000, add_months($this_m, $k) . '-05', $bank, null); }
        add_contribution($uid, 'bill', $car, 200000, $this_m . '-05', $bank, null);
        $g = fn(string $kind, string $name, int $target, ?string $date, string $pri, int $monthly, int $limit) => save_goal($uid, null, ['kind' => $kind, 'name' => $name, 'target_amount' => $target, 'target_date' => $date, 'priority' => $pri, 'monthly_target' => $monthly, 'monthly_limit' => $limit, 'notes' => null]);
        $em = $g('long-term', 'Emergency fund', 30000000, add_months($this_m, 18) . '-28', 'high', 1200000, 0);
        $kid = $g('long-term', 'Higher education', 100000000, add_months($this_m, 60) . '-28', 'medium', 800000, 0);
        $fun = $g('personal', 'Personal fund', 0, null, 'low', 500000, 500000);
        for ($k = -5; $k <= 0; $k++) {
            $day = add_months($this_m, $k) . '-04'; if ($day > $today) { continue; }
            add_contribution($uid, 'goal', $em, 1200000, $day, $bank, null); add_contribution($uid, 'goal', $kid, 800000, $day, $bank, null); add_contribution($uid, 'goal', $fun, 500000, $day, $bank, null);
        }
        $pu = fn(string $kind, string $name, ?string $grp, int $est, string $pri, ?string $date, string $st, int $set, ?string $desc = null) => save_purchase($uid, null, ['kind' => $kind, 'name' => $name, 'description' => $desc, 'group_label' => $grp, 'estimated' => $est, 'priority' => $pri, 'target_date' => $date, 'status' => $st, 'monthly_setaside' => $set, 'account_id' => $savAcc]);
        $sofa = $pu('house', 'Sofa set', 'Furniture', 6500000, 'high', add_months($this_m, 4) . '-20', 'saving', 1000000);
        $pu('house', 'Washing machine', 'Appliances', 3800000, 'medium', add_months($this_m, 8) . '-15', 'planning', 0);
        $pu('house', 'Kitchen chimney', 'Kitchen equipment', 1850000, 'low', null, 'planning', 0);
        add_contribution($uid, 'purchase', $sofa, 2000000, $this_m . '-03', $bank, null);
        $carp = $pu('big', 'Family car', null, 85000000, 'high', add_months($this_m, 30) . '-01', 'saving', 2500000, 'Mid-size hatchback');
        $pu('big', 'Kitchen & wardrobe renovation', null, 32000000, 'medium', add_months($this_m, 14) . '-01', 'planning', 0);
        $pu('big', 'Family vacation abroad', null, 24000000, 'low', add_months($this_m, 20) . '-01', 'planning', 0);
        add_contribution($uid, 'purchase', $carp, 5000000, add_months($this_m, -1) . '-06', $bank, null);
        $pu('occasional', 'Diwali shopping', null, 3000000, 'medium', add_months($this_m, 3) . '-25', 'saving', 750000);
        $pu('occasional', 'Medical check-up', null, 900000, 'medium', add_months($this_m, 5) . '-10', 'planning', 0);
        $pu('occasional', 'Flight tickets to Goa', 'Travel', 2800000, 'low', add_months($this_m, 7) . '-12', 'planning', 0, 'Family trip');
    });
}
function wipe_financial_data(int $uid): void {
    tx(function () use ($uid) {
        foreach (['notifications', 'plan_lines', 'monthly_plans', 'contributions', 'transactions', 'recurring_templates', 'bill_occurrences', 'bills', 'purchases', 'goals', 'budgets'] as $t) { run("DELETE FROM {{$t}} WHERE user_id=?", [$uid]); }
        run('UPDATE {accounts} SET opening_balance=0, opening_date=?, archived=0 WHERE user_id=?', [today(), $uid]);
    });
}
