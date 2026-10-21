<?php
declare(strict_types=1);
/**
 * POST handlers, reached at /do/{name} (dashes become underscores: /do/transaction-save => do_transaction_save).
 * Each takes the signed-in user, validates input, calls a service, and may return a success message.
 * UserError messages are shown to the user; anything else is logged and shown generically.
 */

const PUBLIC_ACTIONS = ['login', 'register'];

// ---------------- auth ----------------
function do_login(?array $u): ?string {
    $email = in_str('email', 190, true, 'Email'); $pw = in_str('password', 200, true, 'Password');
    $id = attempt_login($email, $pw);
    login_user($id);
    $GLOBALS['__redirect'] = url('/');
    return null;
}
function do_register(?array $u): ?string {
    $first = (int) val('SELECT COUNT(*) FROM {users}') === 0;
    if (!$first && site_setting('registration_open', '1') !== '1') { throw new UserError('Registration is currently closed. Ask the administrator for an account.'); }
    $name = in_str('name', 80, true, 'Name'); $email = strtolower(in_str('email', 190, true, 'Email'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new UserError('Enter a valid email address.'); }
    $pw = in_str('password', 200, true, 'Password');
    if (strlen($pw) < 10) { throw new UserError('Use at least 10 characters for your password.'); }
    if (strlen($pw) > 72) { throw new UserError('Use at most 72 characters for your password.'); }
    if ($pw !== ($_POST['confirm'] ?? '')) { throw new UserError('Passwords do not match.'); }
    if (val('SELECT id FROM {users} WHERE email=?', [$email])) { throw new UserError('An account with this email already exists.'); }
    $id = create_user($email, $name, $pw, $first ? 'admin' : 'user');
    login_user($id);
    audit($first ? 'admin.created' : 'user.registered', $email);
    $GLOBALS['__redirect'] = url('/');
    return $first ? 'Welcome! You are the administrator of this installation.' : null;
}
function do_logout(?array $u): ?string { logout_user(); $GLOBALS['__redirect'] = url('/login'); return null; }

// ---------------- transactions ----------------
function do_transaction_save(array $u): string {
    $type = in_enum('type', ['income', 'expense', 'transfer', 'refund', 'adjustment']);
    $id = in_opt_id('id');
    $amount = in_money('amount', true, $type === 'adjustment');
    $status = in_bool('planned') && in_array($type, ['income', 'expense'], true) ? 'planned' : 'completed';
    $i = ['type' => $type, 'status' => $status, 'amount' => $amount, 'date' => in_date('date'), 'account_id' => in_id('account_id', 'account'),
        'to_account_id' => $type === 'transfer' ? in_id('to_account_id', 'destination account') : null,
        'category_id' => in_opt_id('category_id'), 'description' => in_str('description', 200, false), 'payment_method' => in_opt('payment_method', 40),
        'notes' => in_opt('notes', 500), 'refund_of' => $type === 'refund' ? in_opt_id('refund_of') : null, 'fund_goal_id' => $type === 'expense' ? in_opt_id('fund_goal_id') : null];
    if ($id) { update_transaction((int) $u['id'], $id, $i); return 'Transaction updated.'; }
    create_transaction((int) $u['id'], $i); return 'Transaction added.';
}
function do_transaction_delete(array $u): string { delete_transaction((int) $u['id'], in_id('id')); return 'Transaction deleted.'; }
function do_transaction_complete(array $u): string { complete_planned((int) $u['id'], in_id('id'), today()); return 'Marked as done.'; }

function parse_csv_text(string $text): array {
    $fh = fopen('php://temp', 'r+'); fwrite($fh, preg_replace('/^\xEF\xBB\xBF/', '', $text)); rewind($fh);
    $rows = []; while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) { if (count($r) === 1 && ($r[0] === null || $r[0] === '')) { continue; } $rows[] = $r; }
    fclose($fh); return $rows;
}
function do_transactions_import(array $u): string {
    $uid = (int) $u['id']; $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] === 0) { throw new UserError('Choose a CSV file.'); }
    if ($f['size'] > 3_000_000) { throw new UserError('File is too large (max 3 MB).'); }
    $rows = parse_csv_text((string) file_get_contents($f['tmp_name']));
    if (count($rows) < 2) { throw new UserError('The file has no data rows.'); }
    if (count($rows) > 5001) { throw new UserError('Please import at most 5,000 rows at a time.'); }
    $header = array_map(fn($h) => strtolower(trim((string) $h)), $rows[0]);
    foreach (['date', 'type', 'amount'] as $need) { if (!in_array($need, $header, true)) { throw new UserError("Missing required column \"$need\". Expected: date, type, amount, category, account, description, payment_method, notes (optional: status, to_account)."); } }
    $col = fn($r, $n) => ($i = array_search($n, $header, true)) !== false ? trim((string) ($r[$i] ?? '')) : '';
    $accounts = all('SELECT id,name FROM {accounts} WHERE user_id=? AND archived=0 ORDER BY id', [$uid]);
    $findAcc = fn($name) => $name === '' ? ($accounts[0] ?? null) : (array_values(array_filter($accounts, fn($a) => strcasecmp($a['name'], $name) === 0))[0] ?? null);
    $errors = []; $parsed = [];
    foreach (array_slice($rows, 1) as $n => $r) {
        $line = $n + 2; $type = strtolower($col($r, 'type')); $date = $col($r, 'date'); $acct = $findAcc($col($r, 'account')); $to = $col($r, 'to_account') !== '' ? $findAcc($col($r, 'to_account')) : null;
        $amt = parse_money($col($r, 'amount'), $type === 'adjustment');
        if (!in_array($type, ['income', 'expense', 'refund', 'adjustment', 'transfer'], true)) { $errors[] = "Row $line: type must be income, expense, refund, adjustment or transfer."; }
        elseif ($type === 'transfer' && (!$to || !$acct || $to['id'] === $acct['id'])) { $errors[] = "Row $line: a transfer needs two different, existing accounts (account, to_account)."; }
        elseif (!valid_date($date)) { $errors[] = "Row $line: date must be YYYY-MM-DD."; }
        elseif (!$amt || ($type !== 'adjustment' && $amt <= 0)) { $errors[] = "Row $line: invalid amount."; }
        elseif (!$acct) { $errors[] = "Row $line: unknown account \"" . $col($r, 'account') . '".'; }
        else { $parsed[] = ['type' => $type, 'status' => strtolower($col($r, 'status')) === 'planned' && in_array($type, ['income', 'expense'], true) ? 'planned' : 'completed', 'to_account_id' => $to['id'] ?? null, 'date' => $date, 'amount' => $amt, 'account_id' => (int) $acct['id'],
            'category' => $col($r, 'category'), 'description' => mb_substr($col($r, 'description'), 0, 200), 'payment_method' => mb_substr($col($r, 'payment_method'), 0, 40) ?: null, 'notes' => mb_substr($col($r, 'notes'), 0, 500) ?: null]; }
    }
    if ($errors) { throw new UserError(count($errors) . ' problem(s), nothing imported. ' . implode(' ', array_slice($errors, 0, 5))); }
    tx(function () use ($uid, $parsed) {
        $cats = all('SELECT id,name FROM {categories} WHERE user_id=?', [$uid]);
        foreach ($parsed as $p) {
            $catId = null;
            if ($p['category'] !== '' && $p['type'] !== 'transfer') {
                $name = trim(last_part($p['category'])); $hit = null;
                foreach ($cats as $c) { if (strcasecmp($c['name'], $name) === 0) { $hit = $c; break; } }
                if (!$hit) { $newId = insert('INSERT INTO {categories} (user_id,name,grp) VALUES (?,?,?)', [$uid, mb_substr($name, 0, 60), $p['type'] === 'income' ? 'income' : 'custom']); $hit = ['id' => $newId, 'name' => $name]; $cats[] = $hit; }
                $catId = (int) $hit['id'];
            }
            create_transaction($uid, ['type' => $p['type'], 'status' => $p['status'], 'amount' => $p['amount'], 'date' => $p['date'], 'account_id' => $p['account_id'], 'to_account_id' => $p['to_account_id'], 'category_id' => $catId, 'description' => $p['description'], 'payment_method' => $p['payment_method'], 'notes' => $p['notes']]);
        }
    });
    return 'Imported ' . count($parsed) . ' transactions.';
}
function last_part(string $s): string { $p = explode('›', $s); return end($p); }

// ---------------- accounts / categories / budgets / templates ----------------
function do_account_save(array $u): string {
    save_account((int) $u['id'], in_opt_id('id'), in_str('name', 60), in_enum('type', ['bank', 'cash', 'wallet', 'card']), in_money('opening_balance', false, true, true, 'Opening balance'), in_date('opening_date', 'Opening date'), in_enum('purpose', array_keys(ACCOUNT_PURPOSES), 'spending'), in_opt('label', 40));
    return 'Account saved.';
}
function do_account_balance(array $u): string {
    $d = set_account_balance((int) $u['id'], in_id('id', 'account'), in_money('balance', true, true, true, 'Balance'), in_date('date'), in_opt('note', 200));
    return 'Balance updated (' . ($d > 0 ? '+' : '−') . fmt(abs($d)) . ' correction recorded).';
}
function do_account_delete(array $u): string { return delete_account((int) $u['id'], in_id('id')) === 'archived' ? 'Account has transactions, so it was archived instead.' : 'Account deleted.'; }
function do_account_restore(array $u): string { owned('accounts', in_id('id'), (int) $u['id']); run('UPDATE {accounts} SET archived=0 WHERE id=? AND user_id=?', [in_id('id'), $u['id']]); return 'Account restored.'; }
function do_category_save(array $u): string { save_category((int) $u['id'], in_opt_id('id'), in_str('name', 60), in_enum('grp', ['fixed', 'household', 'personal', 'occasional', 'custom', 'income']), in_opt_id('parent_id')); return 'Category saved.'; }
function do_category_delete(array $u): string { delete_category((int) $u['id'], in_id('id')); return 'Category deleted. Its transactions are now uncategorised.'; }
function do_budget_set(array $u): string { set_budget((int) $u['id'], in_id('category_id'), in_money('amount', false, false, true, 'Budget')); return 'Budget saved.'; }
function do_template_save(array $u): string {
    save_template((int) $u['id'], in_opt_id('id'), ['type' => in_enum('type', ['income', 'expense']), 'description' => in_str('description', 100, true, 'Name'), 'amount' => in_money('amount'), 'category_id' => in_opt_id('category_id'),
        'account_id' => in_id('account_id', 'account'), 'frequency' => in_enum('frequency', array_keys(FREQ_MONTHS)), 'next_date' => in_date('next_date', 'Next date'), 'active' => in_bool('active')]);
    return 'Recurring item saved.';
}
function do_template_delete(array $u): string { delete_template((int) $u['id'], in_id('id')); return 'Recurring item deleted.'; }

// ---------------- savings ----------------
function do_goal_save(array $u): string {
    save_goal((int) $u['id'], in_opt_id('id'), ['kind' => in_enum('kind', ['long-term', 'personal']), 'name' => in_str('name', 80), 'target_amount' => in_money('target_amount', false, false, true, 'Target'), 'target_date' => in_opt_date('target_date'),
        'priority' => in_enum('priority', ['high', 'medium', 'low'], 'medium'), 'monthly_target' => in_money('monthly_target', false, false, true, 'Monthly contribution'), 'monthly_limit' => in_money('monthly_limit', false, false, true, 'Spending limit'), 'notes' => in_opt('notes')]);
    return 'Goal saved.';
}
function do_goal_delete(array $u): string { delete_goal((int) $u['id'], in_id('id')); return 'Goal deleted.'; }
function do_contribute(array $u): string {
    $amt = in_money('amount', true, true);
    add_contribution((int) $u['id'], in_enum('target_type', ['goal', 'purchase', 'bill']), in_id('target_id'), $amt, in_date('date'), in_opt_id('account_id'), in_opt('note', 200));
    return $amt > 0 ? 'Money set aside.' : 'Withdrawn from savings.';
}
function do_contribution_delete(array $u): string { delete_contribution((int) $u['id'], in_id('id')); return 'Entry removed.'; }
function do_purchase_save(array $u): string {
    save_purchase((int) $u['id'], in_opt_id('id'), ['kind' => in_enum('kind', ['house', 'big', 'occasional']), 'name' => in_str('name', 100), 'description' => in_opt('description', 300), 'group_label' => in_opt('group_label', 60),
        'estimated' => in_money('estimated', true, false, false, 'Estimated cost'), 'priority' => in_enum('priority', ['high', 'medium', 'low'], 'medium'), 'target_date' => in_opt_date('target_date'),
        'status' => in_enum('status', array_keys(PURCHASE_STATUS), 'planning'), 'monthly_setaside' => in_money('monthly_setaside', false, false, true, 'Monthly set-aside'), 'account_id' => in_opt_id('account_id')]);
    return 'Saved.';
}
function do_purchase_delete(array $u): string { delete_purchase((int) $u['id'], in_id('id')); return 'Deleted.'; }
function do_purchase_buy(array $u): string { mark_purchased((int) $u['id'], in_id('id'), in_money('actual', true, false, false, 'Price paid'), in_date('date'), in_id('account_id', 'account'), in_opt_id('category_id')); return 'Marked as purchased.'; }

// ---------------- bills ----------------
function do_bill_save(array $u): string {
    save_bill((int) $u['id'], in_opt_id('id'), ['kind' => in_enum('kind', ['bill', 'yearly']), 'name' => in_str('name', 100), 'amount' => in_money('amount'), 'category_id' => in_opt_id('category_id'), 'account_id' => in_opt_id('account_id'),
        'frequency' => in_enum('frequency', array_keys(FREQ_MONTHS)), 'due_date' => in_date('due_date', 'Due date'), 'reminder_days' => in_int('reminder_days', 0, 90, 7), 'active' => in_bool('active'), 'notes' => in_opt('notes')], today());
    return 'Saved.';
}
function do_bill_delete(array $u): string { delete_bill((int) $u['id'], in_id('id')); return 'Deleted.'; }
function do_bill_pay(array $u): string { pay_occurrence((int) $u['id'], in_id('occurrence_id'), in_money('amount'), in_date('date', 'Paid on'), in_id('account_id', 'account'), in_bool('from_fund')); return 'Payment recorded.'; }
function do_occurrence_skip(array $u): string { skip_occurrence((int) $u['id'], in_id('id'), true); return 'Skipped.'; }
function do_notifications_read(array $u): string { run('UPDATE {notifications} SET is_read=1 WHERE user_id=?', [$u['id']]); return 'Reminders dismissed.'; }

// ---------------- planning ----------------
function do_plan_save(array $u): string {
    $uid = (int) $u['id']; $month = in_str('month', 7); if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) { throw new UserError('Invalid month.'); }
    $status = in_enum('status', ['draft', 'confirmed']);
    $raw = json_decode((string) ($_POST['lines'] ?? ''), true);
    if (!is_array($raw) || count($raw) > 300) { throw new UserError('The plan could not be read. Reload the page and try again.'); }
    $lines = [];
    foreach ($raw as $l) {
        if (!is_array($l) || !isset(PLAN_SECTIONS[$l['section'] ?? ''])) { throw new UserError('The plan could not be read. Reload the page and try again.'); }
        $amt = $l['amount'] ?? null; if (!is_int($amt) || $amt < 0 || $amt > 10 ** 13) { throw new UserError('Plan amounts must be zero or more.'); }
        $lines[] = ['section' => $l['section'], 'refType' => in_array($l['refType'] ?? null, ['bill', 'goal', 'purchase', 'category'], true) ? $l['refType'] : null, 'refId' => isset($l['refId']) ? (int) $l['refId'] : null,
            'label' => (string) ($l['label'] ?? ''), 'amount' => $amt, 'priority' => in_array($l['priority'] ?? '', ['high', 'medium', 'low'], true) ? $l['priority'] : 'medium'];
    }
    // available funds are recomputed on the server; the client's number is never trusted
    $avail = planner_inputs(load_snapshot($uid), $month)['available'];
    save_plan($uid, $month, $lines, $status, $avail);
    return $status === 'confirmed' ? 'Plan confirmed.' : 'Draft saved.';
}
function do_plan_reset(array $u): string { reset_plan((int) $u['id'], in_str('month', 7)); return 'Saved plan discarded.'; }
function do_plan_apply(array $u): string { apply_plan_line((int) $u['id'], in_id('id'), in_opt_id('account_id')); return 'Moved to savings.'; }

// ---------------- settings ----------------
function do_prefs_save(array $u): string {
    $cur = in_enum('currency', array_keys(CURRENCIES)); $loc = in_enum('locale', array_keys(LOCALES));
    run('UPDATE {preferences} SET currency=?,locale=?,theme=?,reminder_days=?,big_purchase_threshold=? WHERE user_id=?', [$cur, $loc, in_enum('theme', ['system', 'light', 'dark']), in_int('reminder_days', 0, 60, 7), in_money('big_purchase_threshold', false, false, true, 'Big-purchase threshold'), $u['id']]);
    return 'Preferences saved.';
}
function do_profile_save(array $u): string { run('UPDATE {users} SET name=? WHERE id=?', [in_str('name', 80), $u['id']]); return 'Profile saved.'; }
function do_password_change(array $u): string {
    $row = one('SELECT password_hash FROM {users} WHERE id=?', [$u['id']]);
    if (!password_verify(in_str('current', 200, true, 'Current password'), $row['password_hash'])) { throw new UserError('Current password is incorrect.'); }
    $new = in_str('next', 200, true, 'New password');
    if (strlen($new) < 10 || strlen($new) > 72) { throw new UserError('Use between 10 and 72 characters.'); }
    run('UPDATE {users} SET password_hash=? WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
    session_regenerate_id(true);
    return 'Password changed.';
}
function do_demo_load(array $u): string { load_demo_data((int) $u['id']); return 'Sample data added.'; }
function do_data_wipe(array $u): string { if (in_str('confirm', 20) !== 'DELETE') { throw new UserError('Type DELETE to confirm.'); } wipe_financial_data((int) $u['id']); return 'All financial data deleted.'; }
function do_account_close(array $u): string {
    $row = one('SELECT password_hash FROM {users} WHERE id=?', [$u['id']]);
    if (!password_verify(in_str('password', 200, true, 'Password'), $row['password_hash'])) { throw new UserError('Incorrect password.'); }
    if ($u['role'] === 'admin' && (int) val("SELECT COUNT(*) FROM {users} WHERE role='admin' AND status='active'") <= 1) { throw new UserError('You are the only administrator. Make someone else an admin first.'); }
    run('DELETE FROM {users} WHERE id=?', [$u['id']]); logout_user(); $GLOBALS['__redirect'] = url('/register'); return 'Your account was deleted.';
}

// ---------------- admin ----------------
function admin_target(int $actor): array {
    $t = one('SELECT id,email,name,role,status FROM {users} WHERE id=?', [in_id('id', 'user')]);
    if (!$t) { throw new UserError('User not found.'); }
    return $t;
}
function admins_left_without(int $id): int { return (int) val("SELECT COUNT(*) FROM {users} WHERE role='admin' AND status='active' AND id<>?", [$id]); }
function do_admin_user_status(array $u): string {
    $t = admin_target((int) $u['id']); if ((int) $t['id'] === (int) $u['id']) { throw new UserError('You cannot disable your own account.'); }
    $new = in_enum('status', ['active', 'disabled']);
    if ($new === 'disabled' && $t['role'] === 'admin' && admins_left_without((int) $t['id']) < 1) { throw new UserError('That would leave no active administrator.'); }
    run('UPDATE {users} SET status=? WHERE id=?', [$new, $t['id']]); audit('admin.user.' . $new, $t['email']);
    return $t['email'] . ($new === 'disabled' ? ' was disabled.' : ' was enabled.');
}
function do_admin_user_role(array $u): string {
    $t = admin_target((int) $u['id']); if ((int) $t['id'] === (int) $u['id']) { throw new UserError('You cannot change your own role.'); }
    $new = in_enum('role', ['user', 'admin']);
    if ($new === 'user' && $t['role'] === 'admin' && admins_left_without((int) $t['id']) < 1) { throw new UserError('That would leave no active administrator.'); }
    run('UPDATE {users} SET role=? WHERE id=?', [$new, $t['id']]); audit('admin.user.role', $t['email'], $new);
    return $t['email'] . ' is now ' . ($new === 'admin' ? 'an administrator.' : 'a regular user.');
}
function do_admin_user_reset(array $u): string {
    $t = admin_target((int) $u['id']);
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $pw = ''; for ($i = 0; $i < 14; $i++) { $pw .= $chars[random_int(0, strlen($chars) - 1)]; }
    run('UPDATE {users} SET password_hash=? WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $t['id']]); run('DELETE FROM {login_attempts} WHERE email=?', [$t['email']]);
    audit('admin.user.reset_password', $t['email']);
    return "Temporary password for {$t['email']}: $pw (shown once. Ask them to change it after signing in).";
}
function do_admin_user_delete(array $u): string {
    $t = admin_target((int) $u['id']); if ((int) $t['id'] === (int) $u['id']) { throw new UserError('Use Settings to delete your own account.'); }
    if ($t['role'] === 'admin' && admins_left_without((int) $t['id']) < 1) { throw new UserError('That would leave no active administrator.'); }
    run('DELETE FROM {users} WHERE id=?', [$t['id']]); audit('admin.user.delete', $t['email']);
    return $t['email'] . ' and all of their data were deleted.';
}
function do_admin_user_create(array $u): string {
    $email = strtolower(in_str('email', 190, true, 'Email')); if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new UserError('Enter a valid email address.'); }
    if (val('SELECT id FROM {users} WHERE email=?', [$email])) { throw new UserError('An account with this email already exists.'); }
    $pw = in_str('password', 200, true, 'Password'); if (strlen($pw) < 10 || strlen($pw) > 72) { throw new UserError('Use between 10 and 72 characters.'); }
    create_user($email, in_str('name', 80), $pw, in_enum('role', ['user', 'admin'], 'user')); audit('admin.user.create', $email);
    return "Account created for $email.";
}
function do_admin_settings(array $u): string {
    $cur = in_enum('default_currency', array_keys(CURRENCIES)); $loc = in_enum('default_locale', array_keys(LOCALES));
    foreach (['registration_open' => in_bool('registration_open') ? '1' : '0', 'default_currency' => $cur, 'default_locale' => $loc, 'site_notice' => in_str('site_notice', 300, false)] as $k => $v) {
        run('INSERT INTO {settings} (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', [$k, $v]);
    }
    site_settings_flush(); audit('admin.settings', 'site'); return 'Site settings saved.';
}


// ---------------- dues & payments ----------------
function do_dues_pay(array $u): ?string {
    $url = start_payment((int) $u['id'], in_id('charge_id'));
    if (!preg_match('~^https?://~i', $url)) { throw new UserError('The payment page returned an invalid address. Please try again.'); }
    $GLOBALS['__redirect'] = $url; return null;
}
function do_admin_charge_create(array $u): string {
    admin_create_charge((int) $u['id'], in_id('user_id', 'person'), in_str('title', 160, true, 'What it is for'), in_opt('description', 300), in_money('amount'), in_date('due_date', 'Due date')); return 'Charge posted. They have been notified.';
}
function in_month(string $k, bool $required = true): ?string { $v = in_str($k, 7, $required, ucfirst(str_replace('_', ' ', $k))); if ($v === '') { return null; } if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v)) { throw new UserError('Use the format YYYY-MM for months, for example 2026-11.'); } return $v; }
function do_admin_plan_save(array $u): string {
    admin_save_plan((int) $u['id'], in_opt_id('id'), in_id('user_id', 'person'), in_str('title', 120, true, 'What it is for'), in_opt('description', 300), in_money('amount'), in_int('day_of_month', 1, 28, 5), (string) in_month('start_month'), in_month('end_month', false), in_bool('active'));
    return 'Monthly charge saved.';
}
function do_admin_plan_toggle(array $u): string { $id = in_id('id'); if (!one('SELECT id FROM {charge_plans} WHERE id=?', [$id])) { throw new UserError('Monthly charge not found.'); } run('UPDATE {charge_plans} SET active=? WHERE id=?', [in_bool('active') ? 1 : 0, $id]); audit('billing.plan.toggle', "plan#$id"); return 'Monthly charge updated.'; }
function do_admin_charge_action(array $u): string { return admin_charge_action(in_id('id'), in_enum('action', ['waive', 'cancel', 'paid', 'waive_fee']), in_opt('note', 300)); }
function do_admin_billing_settings(array $u): string {
    $enabled = in_bool('billing_enabled') ? '1' : '0'; $grace = in_int('grace_days', 0, 60, 5); $type = in_enum('late_fee_type', ['none', 'flat', 'percent']);
    $flat = in_money('late_fee_flat', false, false, true, 'Flat late fee'); $pctRaw = in_str('late_fee_percent', 8, false, 'Percent');
    $pct = $pctRaw === '' ? 0 : (preg_match('/^\d{1,3}(\.\d{1,2})?$/', $pctRaw) && (float) $pctRaw <= 100 ? (int) round((float) $pctRaw * 100) : throw new UserError('Percent must be a number between 0 and 100.'));
    $restrict = in_int('restrict_after_days', 0, 365, 14); $lock = in_int('lock_after_days', 0, 365, 30);
    if ($restrict > 0 && $lock > 0 && $lock <= $restrict) { throw new UserError('The lock must come later than the read-only stage.'); }
    if ($type === 'flat' && $flat <= 0) { throw new UserError('Enter the flat late fee amount, or choose another late fee type.'); } if ($type === 'percent' && $pct <= 0) { throw new UserError('Enter the late fee percent, or choose another late fee type.'); }
    foreach (['billing_enabled' => $enabled, 'grace_days' => (string) $grace, 'late_fee_type' => $type, 'late_fee_flat' => (string) $flat, 'late_fee_percent' => (string) $pct, 'restrict_after_days' => (string) $restrict, 'lock_after_days' => (string) $lock] as $k => $v) { run('INSERT INTO {settings} (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', [$k, $v]); }
    site_settings_flush(); audit('billing.rules', 'site'); return 'Rules saved.';
}
function do_admin_billing_test(array $u): string {
    try { $p = payu_request('GET', '/ping'); } catch (PayuError $e) { throw new UserError('Gateway test failed: ' . $e->getMessage() . ($e->apiCode ? ' (' . $e->apiCode . ')' : '')); }
    $msg = 'Connected to ' . ($p['app'] ?? 'the gateway') . (isset($p['build']) ? ' (build ' . $p['build'] . ')' : '') . '.';
    try { $d = payu_request('GET', '/diagnose'); $sum = $d['diagnosis']['summary'] ?? 'ok'; $bad = array_values(array_filter($d['diagnosis']['checks'] ?? [], fn($c) => in_array($c['status'] ?? '', ['warn', 'fail'], true)));
        $msg .= $sum === 'ok' ? ' Diagnosis: all checks passed.' : ' Diagnosis: ' . $sum . '. ' . implode(' ', array_map(fn($c) => ($c['title'] ?? '') . ($c['fix'] ?? '' ? ' Fix: ' . $c['fix'] : ''), array_slice($bad, 0, 2))); } catch (PayuError) { }
    return $msg;
}
function do_admin_user_billing(array $u): string { $id = in_id('id'); run('UPDATE {users} SET billing_enforced=? WHERE id=?', [in_bool('enforced') ? 1 : 0, $id]); audit('billing.exempt', "user#$id", in_bool('enforced') ? 'enforced' : 'exempt'); return in_bool('enforced') ? 'Dues are enforced for this person again.' : 'This person is now exempt from reminders, late fees and restrictions.'; }
function do_theme_quick(array $u): ?string { run('UPDATE {preferences} SET theme=? WHERE user_id=?', [in_enum('theme', ['light', 'dark', 'system']), $u['id']]); return null; }


// ---------------- offline sync ----------------
/** Applies queued offline operations. Each op carries a client uid; the unique (user, uid) row makes a retry harmless. */
function sync_apply_ops(array $u, array $ops): array {
    $out = []; $backup = $_POST;
    foreach ($ops as $op) {
        $uid = is_array($op) ? (string) ($op['uid'] ?? '') : '';
        if (!preg_match('/^[A-Za-z0-9-]{8,40}$/', $uid)) { $out[] = ['uid' => $uid, 'ok' => false, 'final' => true, 'error' => 'Invalid change id.']; continue; }
        if (($op['kind'] ?? '') !== 'transaction' || !is_array($op['data'] ?? null)) { $out[] = ['uid' => $uid, 'ok' => false, 'final' => true, 'error' => 'Unsupported change.']; continue; }
        if (run('INSERT IGNORE INTO {sync_ops} (user_id, client_uid, kind) VALUES (?,?,?)', [$u['id'], $uid, 'transaction']) === 0) { $out[] = ['uid' => $uid, 'ok' => true, 'duplicate' => true]; continue; }
        try {
            $_POST = []; foreach ($op['data'] as $k => $v) { if (is_scalar($v) && is_string($k)) { $_POST[$k] = (string) $v; } } unset($_POST['id']); // offline mode only creates; it can never overwrite
            do_transaction_save($u); $out[] = ['uid' => $uid, 'ok' => true];
        } catch (UserError $e) { run('DELETE FROM {sync_ops} WHERE user_id=? AND client_uid=?', [$u['id'], $uid]); $out[] = ['uid' => $uid, 'ok' => false, 'final' => true, 'error' => $e->getMessage()]; }
        catch (Throwable $e) { log_error($e); run('DELETE FROM {sync_ops} WHERE user_id=? AND client_uid=?', [$u['id'], $uid]); $out[] = ['uid' => $uid, 'ok' => false, 'final' => false, 'error' => 'Server error, will retry.']; }
    }
    $_POST = $backup; return $out;
}

function do_intro_done(array $u): ?string { start_session(); unset($_SESSION['intro_pending']); return null; }

function do_plan_future(array $u): string {
    $k = in_enum('kind', ['trip', 'occasion', 'big', 'house']); $map = ['trip' => ['occasional', 'Travel'], 'occasion' => ['occasional', 'Occasion'], 'big' => ['big', null], 'house' => ['house', 'Other']];
    $date = in_date('target_date', 'Needed by'); if ($date < today()) { throw new UserError('Pick a date in the future.'); }
    save_purchase((int) $u['id'], null, ['kind' => $map[$k][0], 'name' => in_str('name', 100, true, 'What it is'), 'description' => null, 'group_label' => $map[$k][1], 'estimated' => in_money('estimated', true, false, false, 'Estimated cost'), 'priority' => in_enum('priority', ['high', 'medium', 'low'], 'medium'),
        'target_date' => $date, 'status' => 'saving', 'monthly_setaside' => 0, 'account_id' => in_opt_id('account_id')]);
    return 'Added to your plans. See how much to save each month on the Accounts page.';
}

function do_passkey_delete(array $u): string {
    $id = in_id('id'); if (!one('SELECT id FROM {passkeys} WHERE id=? AND user_id=?', [$id, $u['id']])) { throw new UserError('Passkey not found.'); }
    run('DELETE FROM {passkeys} WHERE id=? AND user_id=?', [$id, $u['id']]); audit('passkey.removed', $u['email']); return 'Passkey removed.';
}
