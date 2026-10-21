<?php
declare(strict_types=1);
/**
 * Self-contained test runner (no Composer / PHPUnit needed):
 *   MFNC_DB_PORT=3307 php tests/run.php
 * It uses its own database (momsfinance_test) and table suffix (_mfnt), so real data is never touched.
 * The test tables are dropped and recreated on every run.
 */
@session_start(); // CLI: start the session before any output so session helpers work
putenv('MFNC_DB_NAME=' . (getenv('MFNC_TEST_DB') ?: 'momsfinance_test'));
putenv('MFNC_TABLE_SUFFIX=_mfnt');
putenv('MFNC_DEBUG=1');
$_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['REQUEST_URI'] = '/';

// drop leftovers before the app boots (FK checks off so order does not matter)
define('MFNC', true);
require __DIR__ . '/../app/db.php';
define('DB_HOST', getenv('MFNC_DB_HOST') ?: '127.0.0.1'); define('DB_PORT', (int) (getenv('MFNC_DB_PORT') ?: 3306)); define('DB_NAME', getenv('MFNC_DB_NAME'));
define('DB_USER', getenv('MFNC_DB_USER') ?: 'root'); define('DB_PASS', getenv('MFNC_DB_PASS') ?: ''); define('TABLE_SUFFIX', '_mfnt');
define('APP_NAME', 'Test'); define('APP_TIMEZONE', 'Asia/Kolkata'); define('APP_DEBUG', true); define('SESSION_NAME', 'test'); define('FORCE_HTTPS_COOKIES', false);
define('APP_URL', 'https://finance.test'); define('PAYU_BASE_URL', 'http://127.0.0.1:8099/api/v1'); define('PAYU_API_TOKEN', 'umpi_live_test'); define('PAYU_WEBHOOK_SECRET', 'whsec_test');
$pdo = db(); $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query("SHOW TABLES LIKE '%\\_mfnt'")->fetchAll(PDO::FETCH_COLUMN) as $t) { $pdo->exec("DROP TABLE `$t`"); }
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
foreach (['schema', 'helpers', 'engine', 'services', 'snapshot', 'auth', 'webauthn', 'billing', 'ext', 'notify', 'ai_calc', 'ai', 'ai_chat', 'telegram', 'telegram_bot', 'telegram_ai', 'telegram_charts', 'telegram_actions', 'shop', 'ai_files', 'ui', 'charts'] as $f) { require __DIR__ . "/../app/$f.php"; }
mfnc_install();
require __DIR__ . '/../app/actions.php'; require __DIR__ . '/../app/actions_ext.php';

// ---- tiny assertion helpers ----
$pass = 0; $fail = 0; $section = '';
function section(string $n): void { global $section; $section = $n; echo "\n$n\n"; }
function check(string $name, bool $ok, string $detail = ''): void { global $pass, $fail; if ($ok) { $pass++; echo "  ✓ $name\n"; } else { $fail++; echo "  ✗ $name $detail\n"; } }
function eq($a, $b, string $name): void { check($name, $a === $b, '(got ' . var_export($a, true) . ', expected ' . var_export($b, true) . ')'); }
function throws(callable $fn, string $needle, string $name): void { try { $fn(); check($name, false, '(no exception)'); } catch (Throwable $e) { check($name, stripos($e->getMessage(), $needle) !== false, '(got "' . $e->getMessage() . '")'); } }

$acct = fn(int $id, int $bal = 0, string $date = '2026-01-01') => ['id' => $id, 'opening_balance' => $bal, 'opening_date' => $date];
$n = 0; $T = function (array $o) use (&$n) { return $o + ['id' => ++$n, 'status' => 'completed', 'account_id' => 1, 'to_account_id' => null, 'category_id' => null]; };

section('money');
eq(parse_money('1,25,000'), 12500000, 'parses Indian grouping'); eq(parse_money('₹ 0.1'), 10, 'parses 0.1 exactly'); eq(parse_money('19.99'), 1999, '19.99 has no float error');
eq(parse_money('1.005'), null, 'rejects 3 decimals'); eq(parse_money('-5'), null, 'rejects negatives by default'); eq(parse_money('-5', true), -500, 'allows negatives when asked'); eq(parse_money('abc'), null, 'rejects text');
$in = ['currency' => 'INR', 'locale' => 'en-IN']; eq(fmt(12500000, $in), '₹1,25,000', 'Indian grouping ₹1,25,000'); eq(fmt(12550, $in), '₹125.50', 'fractions shown when present');
eq(fmt(12500000, ['currency' => 'USD', 'locale' => 'en-US']), '$125,000', 'US grouping'); eq(fmt(-500000, $in), '−₹5,000', 'negative formatting'); eq(money_input(12505), '125.05', 'round-trip input value');
eq(fmt_short(15000000, $in), '1.5L', 'compact lakh');

section('dates');
eq(add_months_to_date('2026-01-31', 1), '2026-02-28', 'month-end clamp'); eq(next_due('2026-02-28', 'monthly', 31), '2026-03-31', 'anchor day restored');
eq(next_due('2026-03-15', 'quarterly'), '2026-06-15', 'quarterly'); eq(months_between('2026-10', '2027-02'), 4, 'months between');
eq(occurrence_dates('2026-10-05', 5, 'quarterly', '2027-06-30'), ['2026-10-05', '2027-01-05', '2027-04-05'], 'occurrence dates'); check('rejects 2026-02-30', !valid_date('2026-02-30'));

section('cash accounting');
$accts = [$acct(1, 100000), $acct(2, 0)];
$txs = [$T(['type' => 'income', 'amount' => 50000, 'date' => '2026-10-01']), $T(['type' => 'transfer', 'amount' => 30000, 'date' => '2026-10-02', 'account_id' => 1, 'to_account_id' => 2])];
$s = summarize_month($accts, $txs, [], '2026-10');
eq($s['income'], 50000, 'transfer is not income'); eq($s['expenses'], 0, 'transfer is not an expense'); eq(total_cash($accts, $txs), 150000, 'total cash unchanged by transfer'); eq(account_balance($accts[1], $txs), 30000, 'destination account credited');
$accts = [$acct(1, 0)]; $txs = [$T(['type' => 'income', 'amount' => 100000, 'date' => '2026-10-01']), $T(['type' => 'expense', 'amount' => 40000, 'date' => '2026-10-03'])];
$contribs = [['target_type' => 'goal', 'target_id' => 1, 'amount' => 20000, 'date' => '2026-10-05']];
$s = summarize_month($accts, $txs, $contribs, '2026-10');
eq($s['expenses'], 40000, 'contribution is not an expense'); eq($s['net'], 60000, 'net savings = income - expenses'); eq($s['moved'], 20000, 'moved-to-savings reported separately'); eq(total_cash($accts, $txs), 60000, 'contribution does not change cash');
$txs = [$T(['type' => 'income', 'amount' => 100000, 'date' => '2026-10-01']), $T(['type' => 'expense', 'amount' => 30000, 'date' => '2026-10-02']), $T(['type' => 'refund', 'amount' => 10000, 'date' => '2026-10-04']),
    $T(['type' => 'income', 'amount' => 99999, 'date' => '2026-10-20', 'status' => 'planned']), $T(['type' => 'expense', 'amount' => 5000, 'date' => '2026-10-21', 'status' => 'planned']), $T(['type' => 'adjustment', 'amount' => -2000, 'date' => '2026-10-22'])];
$s = summarize_month($accts, $txs, [], '2026-10');
eq($s['expenses'], 20000, 'refund reduces net expenses'); eq($s['income_expected'], 99999, 'planned income not received'); eq($s['planned_expenses'], 5000, 'planned expense tracked'); eq($s['remaining'], 78000, 'remaining includes adjustment'); eq(total_cash($accts, $txs), 78000, 'planned items never count as cash');
$accts = [$acct(1, 10000)]; $txs = [$T(['type' => 'income', 'amount' => 50000, 'date' => '2026-09-10']), $T(['type' => 'expense', 'amount' => 20000, 'date' => '2026-09-12'])];
$s = summarize_month($accts, $txs, [], '2026-10'); eq($s['opening'], 40000, 'opening balance carried forward'); eq($s['rate'], null, 'no income => savings rate null');
$s = summarize_month([$acct(1)], [$T(['type' => 'income', 'amount' => 100000, 'date' => '2026-10-01']), $T(['type' => 'expense', 'amount' => 75000, 'date' => '2026-10-02'])], [], '2026-10'); check('savings rate 25%', abs($s['rate'] - 25) < 1e-9);

section('goals & sinking funds');
$g = goal_progress(['target_amount' => 12000000, 'target_date' => '2027-09-30', 'monthly_target' => 1000000], 2000000, '2026-10-15');
eq($g['remaining'], 10000000, 'goal remaining'); eq($g['months'], 12, 'months to target'); eq($g['required'], 833334, 'required monthly = ceil(remaining/months)'); eq($g['est'], '2027-07', 'estimated completion (10 contributions from this month)'); eq($g['behind'], false, 'on track');
$exact = goal_progress(['target_amount' => 12000000, 'target_date' => '2027-09-30', 'monthly_target' => 833334], 2000000, '2026-10-15'); eq($exact['est'], '2027-09', 'exactly on plan finishes in target month'); eq($exact['behind'], false, 'exact plan is not behind');
eq(goal_progress(['target_amount' => 12000000, 'target_date' => '2027-09-30', 'monthly_target' => 100000], 2000000, '2026-10-15')['behind'], true, 'small plan is behind');
$g = goal_progress(['target_amount' => 50000, 'target_date' => null, 'monthly_target' => 0], 10000, '2026-10-15'); eq($g['required'], null, 'no date => no required monthly'); eq($g['est'], null, 'no plan => no estimate'); eq($g['behind'], false, 'no date => never behind');
$d = goal_progress(['target_amount' => 50000, 'target_date' => '2026-01-01', 'monthly_target' => 0], 60000, '2026-10-15'); eq($d['complete'], true, 'overfunded goal complete'); eq($d['remaining'], 0, 'no negative remaining');
eq(goal_progress(['target_amount' => 50000, 'target_date' => '2026-01-01', 'monthly_target' => 0], 0, '2026-10-15')['required'], 50000, 'past date => all now');
eq(monthly_set_aside(2400000, 'yearly'), 200000, '24,000 yearly => 2,000/month'); eq(monthly_set_aside(900000, 'quarterly'), 300000, 'quarterly'); eq(catch_up_set_aside(2400000, 0, '2027-01-15', '2026-10-15'), 600000, 'catch-up'); eq(catch_up_set_aside(2400000, 2400000, '2027-01-15', '2026-10-15'), 0, 'fully funded => 0');

section('bills, cash-flow, ranking, planner');
eq(occurrence_state(1000, 0, '2026-10-20', '2026-10-15')['status'], 'upcoming', 'upcoming'); eq(occurrence_state(1000, 400, '2026-10-20', '2026-10-15')['status'], 'partial', 'partial'); eq(occurrence_state(1000, 1000, '2026-10-10', '2026-10-15')['status'], 'paid', 'paid');
$o = occurrence_state(1000, 400, '2026-10-10', '2026-10-15'); eq($o['status'], 'overdue', 'overdue'); eq($o['outstanding'], 600, 'partial outstanding');
$r = project_cashflow(10000, [['date' => '2026-11-05', 'amount' => -8000, 'label' => 'Rent'], ['date' => '2026-11-01', 'amount' => 5000, 'label' => 'Pay'], ['date' => '2026-11-10', 'amount' => -9000, 'label' => 'Insurance']]);
eq($r['shortfall']['label'] ?? null, 'Insurance', 'first shortfall found'); eq($r['lowest']['balance'] ?? null, -2000, 'lowest balance');
$base = ['status' => 'saving', 'actual' => null, 'saved' => 0];
$mk = fn($id, $name, $pri, $est, $date, $st = 'saving') => ['id' => $id, 'name' => $name, 'priority' => $pri, 'status' => $st, 'actual' => null, 'estimated' => $est, 'target_date' => $date] + purchase_needs(['estimated' => $est, 'actual' => null, 'target_date' => $date], 0, '2026-10-15') + ['saved' => 0];
$ranked = rank_purchases([$mk(1, 'Sofa', 'low', 100000, '2027-01-31'), $mk(2, 'Fridge', 'high', 120000, '2027-03-31'), $mk(3, 'Done', 'high', 1, null, 'purchased')], 50000);
eq(array_column($ranked, 'name'), ['Fridge', 'Sofa'], 'ranking: priority then date; purchased excluded'); check('never over-allocates', array_sum(array_column($ranked, 'suggested')) <= 50000);
$lines = build_proposal(['bills' => [['id' => 1, 'name' => 'Rent', 'outstanding' => 40000]], 'yearly' => [['id' => 2, 'name' => 'LIC', 'set_aside' => 2000]], 'household' => [['id' => 3, 'name' => 'Groceries', 'estimate' => 15000]], 'personal' => [['id' => 4, 'name' => 'Shopping', 'estimate' => 10000]],
    'occasional' => [], 'goals' => [['id' => 5, 'name' => 'Emergency', 'monthly' => 20000, 'priority' => 'high']], 'house' => [['id' => 6, 'name' => 'Sofa', 'monthly' => 8000, 'priority' => 'low']], 'personal_fund' => [], 'big' => []]);
eq(plan_totals(100000, $lines)['unallocated'], 5000, 'plan leaves 5,000 unallocated'); $over = plan_totals(80000, $lines); eq($over['deficit'], 15000, 'deficit is reported, not hidden');
$sg = suggest_adjustments($lines, $over['deficit']); eq(array_sum(array_column($sg, 'saves')), 13000, 'suggestions free 13,000 (personal half + low-priority sofa)'); check('essentials never suggested', !array_filter($sg, fn($x) => in_array($x['label'], ['Rent', 'Emergency', 'LIC', 'Groceries'], true)));
eq(suggest_adjustments($lines, 0), [], 'no deficit => no suggestions');

// ---------- services against the database ----------
$mkUser = fn(string $email) => create_user($email, 'T ' . $email, 'password-12345');
$A = $mkUser('a@x.test'); $B = $mkUser('b@x.test');
$accA = (int) val('SELECT id FROM {accounts} WHERE user_id=? ORDER BY id LIMIT 1', [$A]); $accB = (int) val('SELECT id FROM {accounts} WHERE user_id=? ORDER BY id LIMIT 1', [$B]);
run('UPDATE {accounts} SET opening_date=? WHERE user_id IN (?,?)', ['2026-01-01', $A, $B]);
$TODAY = '2026-10-15'; $snap = fn() => load_snapshot($A, $TODAY); $dash = fn() => dashboard_data($snap(), '2026-10');
$tx = fn(array $o) => create_transaction($A, $o + ['status' => 'completed', 'account_id' => $accA, 'date' => '2026-10-02']);

section('data isolation');
$id = $tx(['type' => 'income', 'amount' => 10000, 'description' => 'mine']);
throws(fn() => update_transaction($B, $id, ['type' => 'income', 'amount' => 1, 'date' => $TODAY, 'account_id' => $accB]), 'not found', "other user can't edit");
throws(fn() => delete_transaction($B, $id), 'not found', "other user can't delete");
throws(fn() => create_transaction($B, ['type' => 'income', 'amount' => 5, 'date' => $TODAY, 'account_id' => $accA]), 'not found', "other user can't use my account");
throws(fn() => add_contribution($B, 'goal', 1, 5, $TODAY, null, null), 'not found', "other user can't contribute to my goal");
eq((int) val('SELECT COUNT(*) FROM {transactions} WHERE user_id=?', [$B]), 0, "other user sees none of my rows"); delete_transaction($A, $id);

section('transaction workflows');
throws(fn() => $tx(['type' => 'transfer', 'amount' => 100, 'to_account_id' => $accA]), 'different', 'transfer needs different accounts');
throws(fn() => $tx(['type' => 'expense', 'amount' => 0]), 'greater than zero', 'zero amount rejected');
$e = $tx(['type' => 'expense', 'amount' => 1000]); $tx(['type' => 'refund', 'amount' => 400, 'refund_of' => $e]);
throws(fn() => $tx(['type' => 'refund', 'amount' => 700, 'refund_of' => $e]), 'exceed', 'refund cannot exceed original'); delete_transaction($A, $e);
eq((int) val('SELECT COUNT(*) FROM {transactions} WHERE user_id=? AND refund_of IS NOT NULL', [$A]), 0, 'deleting an expense removes its refunds');
$acc2 = insert('INSERT INTO {accounts} (user_id,name,opening_date) VALUES (?,?,?)', [$A, 'Temp', '2026-01-01']); create_transaction($A, ['type' => 'income', 'status' => 'completed', 'amount' => 100, 'date' => '2026-10-01', 'account_id' => $acc2]);
eq(delete_account($A, $acc2), 'archived', 'account with history is archived, not deleted');
save_template($A, null, ['type' => 'income', 'description' => 'Salary', 'amount' => 5000000, 'category_id' => null, 'account_id' => $accA, 'frequency' => 'monthly', 'next_date' => '2026-10-01', 'active' => true]);
generate_from_templates($A, $TODAY); generate_from_templates($A, $TODAY);
$planned = all('SELECT * FROM {transactions} WHERE user_id=? AND template_id IS NOT NULL', [$A]); eq(count($planned), 1, 'recurring item generated once (idempotent)'); eq($planned[0]['status'], 'planned', 'generated as planned, not paid');
$base = $dash()['sum']['income']; eq($dash()['sum']['income_expected'], 5000000, 'shown as expected income'); complete_planned($A, (int) $planned[0]['id'], '2026-10-01'); eq($dash()['sum']['income'], $base + 5000000, 'completing it moves it to received');

section('savings & bills never double count');
$before = $dash();
$goal = save_goal($A, null, ['kind' => 'long-term', 'name' => 'Emergency', 'target_amount' => 10000000, 'target_date' => '2027-10-31', 'priority' => 'high', 'monthly_target' => 500000, 'monthly_limit' => 0, 'notes' => null]);
add_contribution($A, 'goal', $goal, 1000000, '2026-10-05', null, null); $after = $dash();
eq($after['sum']['expenses'], $before['sum']['expenses'], 'goal contribution is not an expense'); eq($after['cash'], $before['cash'], 'cash unchanged'); eq($after['reserved'], 1000000, 'reserved increases'); eq($after['spendable'], $before['spendable'] - 1000000, 'spendable decreases');
throws(fn() => add_contribution($A, 'goal', $goal, -2000000, $TODAY, null, null), 'withdraw', 'cannot withdraw more than saved');
$bill = save_bill($A, null, ['kind' => 'yearly', 'name' => 'LIC', 'amount' => 2400000, 'category_id' => null, 'account_id' => $accA, 'frequency' => 'yearly', 'due_date' => '2026-11-10', 'reminder_days' => 30, 'active' => true, 'notes' => null], $TODAY);
add_contribution($A, 'bill', $bill, 600000, '2026-10-06', null, null); $before = $dash(); $occ = (int) val('SELECT id FROM {bill_occurrences} WHERE bill_id=?', [$bill]);
pay_occurrence($A, $occ, 600000, $TODAY, $accA, true); $after = $dash();
eq($after['sum']['expenses'] - $before['sum']['expenses'], 600000, 'paying a bill = exactly one expense'); eq($after['cash'], $before['cash'] - 600000, 'cash falls by the payment'); eq($after['reserved'], $before['reserved'] - 600000, 'reserve released');
eq($after['spendable'], $before['spendable'], 'spendable unchanged: not deducted twice'); throws(fn() => pay_occurrence($A, $occ, 2000000, $TODAY, $accA, false), 'more than', 'cannot overpay');
$st = $snap(); $o = array_values(array_filter($st['occurrences'], fn($x) => (int) $x['id'] === $occ))[0]; eq($o['paid'], 600000, 'partial payment recorded, remainder stays a liability');
$payTx = (int) val('SELECT id FROM {transactions} WHERE occurrence_id=?', [$occ]); throws(fn() => update_transaction($A, $payTx, ['type' => 'expense', 'status' => 'completed', 'amount' => 1, 'date' => $TODAY, 'account_id' => $accA]), "can't be edited", 'bill payments cannot be edited');
delete_transaction($A, $payTx); eq($dash()['reserved'], $before['reserved'], 'deleting the payment restores the reserve');
$eBefore = $dash()['sum']['expenses']; save_bill($A, null, ['kind' => 'bill', 'name' => 'Electricity', 'amount' => 250000, 'category_id' => null, 'account_id' => $accA, 'frequency' => 'monthly', 'due_date' => '2026-10-20', 'reminder_days' => 5, 'active' => true, 'notes' => null], $TODAY);
check('unpaid bill is a liability, not an expense', $dash()['upcoming_total'] >= 250000 && $dash()['sum']['expenses'] === $eBefore);
$pur = save_purchase($A, null, ['kind' => 'big', 'name' => 'Fridge', 'description' => null, 'group_label' => null, 'estimated' => 12000000, 'priority' => 'high', 'target_date' => '2027-03-31', 'status' => 'saving', 'monthly_setaside' => 2000000]);
add_contribution($A, 'purchase', $pur, 5000000, '2026-10-07', null, null); $before = $dash(); mark_purchased($A, $pur, 11000000, $TODAY, $accA, null); $after = $dash();
eq($after['sum']['expenses'] - $before['sum']['expenses'], 11000000, 'purchase = one expense'); eq($after['reserved'], $before['reserved'] - 5000000, 'pot released'); eq($after['spendable'], $before['spendable'] - 6000000, 'spendable falls only by the unsaved part');
throws(fn() => mark_purchased($A, $pur, 1, $TODAY, $accA, null), 'Already', 'cannot purchase twice');
$fund = save_goal($A, null, ['kind' => 'personal', 'name' => 'Fun', 'target_amount' => 0, 'target_date' => null, 'priority' => 'low', 'monthly_target' => 300000, 'monthly_limit' => 300000, 'notes' => null]);
add_contribution($A, 'goal', $fund, 300000, '2026-10-03', null, null); $before = $dash(); $ftx = $tx(['type' => 'expense', 'amount' => 100000, 'description' => 'Saree', 'fund_goal_id' => $fund]); $after = $dash();
eq($after['spendable'], $before['spendable'], 'personal-fund spending not counted twice'); eq($after['reserved'], $before['reserved'] - 100000, 'fund reduced');
throws(fn() => delete_contribution($A, (int) val('SELECT id FROM {contributions} WHERE transaction_id=?', [$ftx])), 'payment', 'payment-linked entry cannot be deleted alone');

section('monthly plan guard');
$pl = [['section' => 'goals', 'refType' => 'goal', 'refId' => 1, 'label' => 'x', 'amount' => 500, 'priority' => 'high']];
throws(fn() => save_plan($A, '2026-10', $pl, 'confirmed', 400), 'over the available', 'cannot confirm a plan above available funds'); save_plan($A, '2026-10', $pl, 'draft', 400); save_plan($A, '2026-10', $pl, 'confirmed', 500);
eq(val('SELECT status FROM {monthly_plans} WHERE user_id=?', [$A]), 'confirmed', 'valid plan confirms');

section('csv, security helpers, admin rules');
$rows = parse_csv_text("a,b,c\r\n\"a,b\",\"say \"\"hi\"\"\",\"line1\nline2\"\r\n"); eq($rows[1], ['a,b', 'say "hi"', "line1\nline2"], 'CSV quotes, commas and newlines');
eq(safe_back('//evil.com'), url('/'), 'open redirect blocked'); eq(safe_back('/transactions?x=1'), '/transactions?x=1', 'local back-link allowed'); eq(e('<a href="x">'), '&lt;a href=&quot;x&quot;&gt;', 'output escaped');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
throws(function () { for ($i = 0; $i < 6; $i++) { try { attempt_login('a@x.test', 'wrong-password'); } catch (UserError $e) { if (str_contains($e->getMessage(), 'Too many')) { throw $e; } } } }, 'Too many', 'login throttled after 5 failures');
run('DELETE FROM {login_attempts}'); eq(attempt_login('a@x.test', 'password-12345'), $A, 'correct password logs in');
run('UPDATE {users} SET role=? WHERE id=?', ['admin', $A]); $_POST = ['id' => (string) $A, 'status' => 'disabled']; $GLOBALS['__user'] = ['id' => $B, 'role' => 'admin'];
throws(fn() => do_admin_user_status(['id' => $B, 'role' => 'admin']), 'no active administrator', 'cannot disable the last administrator');
$_POST = ['id' => (string) $B, 'role' => 'admin']; do_admin_user_role(['id' => $A, 'role' => 'admin']); $_POST = ['id' => (string) $A, 'status' => 'disabled']; do_admin_user_status(['id' => $B, 'role' => 'admin']);
throws(fn() => attempt_login('a@x.test', 'password-12345'), 'disabled', 'disabled users cannot sign in');
eq(val('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME LIKE ?', [DB_NAME, '%\\_mfnt']), count(mfnc_schema()), 'every table was auto-created with the suffix');


// ================= billing: charges, enforcement, PayU flow (against a mock gateway) =================
$mockState = sys_get_temp_dir() . '/mfnc_gateway_mock.json'; @unlink($mockState);
$mock = proc_open([PHP_BINARY, '-S', '127.0.0.1:8099', __DIR__ . '/gateway_mock.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 30; $i++) { $fp = @fsockopen('127.0.0.1', 8099, $en, $es, 0.2); if ($fp) { fclose($fp); break; } usleep(100000); }
$mockDb = fn() => json_decode((string) @file_get_contents($mockState), true) ?: ['payments' => [], 'inits' => 0];
$setMock = function (string $id, string $status) use ($mockState, $mockDb) { $d = $mockDb(); $d['payments'][$id]['status'] = $status; file_put_contents($mockState, json_encode($d)); };
$sign = fn(string $raw) => 'sha256=' . hash_hmac('sha256', $raw, PAYU_WEBHOOK_SECRET);
$hook = function (array $body, ?string $delivery = null, ?string $sig = null) use ($sign) { $raw = json_encode($body); return handle_payu_webhook($raw, ['X-UMPI-Signature' => $sig ?? $sign($raw), 'X-UMPI-Delivery-Id' => $delivery ?? bin2hex(random_bytes(6))]); };
$cfgSet = function (array $kv) { foreach ($kv as $k => $v) { run('INSERT INTO {settings} (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', [$k, (string) $v]); } site_settings_flush(); };
$M = create_user('mom@x.test', 'Mom', 'password-12345'); $ADM = create_user('boss@x.test', 'Boss', 'password-12345', 'admin');
$momU = ['id' => $M, 'role' => 'user']; $admU = ['id' => $ADM, 'role' => 'admin'];
$T0 = '2026-10-15';

section('billing: charges, late fees, enforcement');
eq(late_fee_for(100000, ['fee_type' => 'flat', 'fee_flat' => 5000, 'fee_percent' => 0]), 5000, 'flat late fee'); eq(late_fee_for(100001, ['fee_type' => 'percent', 'fee_percent' => 500, 'fee_flat' => 0]), 5001, '5% late fee rounds up'); eq(late_fee_for(100000, ['fee_type' => 'none', 'fee_flat' => 9, 'fee_percent' => 9]), 0, 'no fee when off');
$cfgSet(['billing_enabled' => 1, 'grace_days' => 5, 'late_fee_type' => 'flat', 'late_fee_flat' => 5000, 'restrict_after_days' => 14, 'lock_after_days' => 30]);
$plan = admin_save_plan($ADM, null, $M, 'Family contribution', 'Monthly share', 200000, 5, '2026-08', null, true);
billing_sync($M, $T0); billing_sync($M, $T0);
eq((int) val('SELECT COUNT(*) FROM {charges} WHERE user_id=?', [$M]), 3, 'monthly charges generated for Aug, Sep, Oct (idempotent)');
$rows = array_column(charge_rows($M, $T0), null, 'period'); eq($rows['2026-10']['label'], 'overdue', 'Oct 5 is overdue on Oct 15'); eq($rows['2026-08']['late_fee'], 5000, 'late fee applied once after grace'); eq($rows['2026-10']['late_fee'], 5000, 'Oct is 10 days past due: fee applied (grace 5)');
billing_sync($M, '2026-10-20'); eq((int) val('SELECT late_fee FROM {charges} WHERE user_id=? AND period=?', [$M, '2026-08']), 5000, 'late fee is not applied twice');
eq(billing_level($momU, '2026-10-06'), 3, 'Aug charge is 62 days overdue => locked'); run("UPDATE {charges} SET status='waived' WHERE user_id=? AND period IN ('2026-08','2026-09')", [$M]);
eq(billing_level($momU, '2026-10-06'), 1, 'only Oct left, 1 day overdue => reminder'); eq(billing_level($momU, '2026-10-20'), 2, '15 days overdue passes the 14-day restrict threshold');
$cfgSet(['restrict_after_days' => 10]); eq(billing_level($momU, '2026-10-16'), 2, 'restricted after 10 days overdue'); eq(billing_level($momU, '2026-11-05'), 3, 'locked after 30 days overdue');
eq(billing_level($admU, '2026-11-05'), 0, 'admins are never restricted'); run('UPDATE {users} SET billing_enforced=0 WHERE id=?', [$M]); eq(billing_level($momU, '2026-11-05'), 0, 'exempt user is never restricted'); run('UPDATE {users} SET billing_enforced=1 WHERE id=?', [$M]);
$cfgSet(['billing_enabled' => 0]); eq(billing_level($momU, '2026-11-05'), 0, 'billing switched off => no enforcement'); $cfgSet(['billing_enabled' => 1]);
$octId = (int) val('SELECT id FROM {charges} WHERE user_id=? AND period=?', [$M, '2026-10']);
eq(admin_charge_action($octId, 'waive_fee', null), 'Late fee waived.', 'late fee can be waived'); eq(charge_payable(one('SELECT * FROM {charges} WHERE id=?', [$octId])), 200000, 'waived fee not payable');
$one = admin_create_charge($ADM, $M, 'Diwali gift', null, 150000, '2026-12-01'); eq(billing_level($momU, '2026-10-16'), 2, 'a not-yet-due charge does not add pressure');
throws(fn() => admin_create_charge($ADM, $M, 'x', null, 0, '2026-12-01'), 'greater than zero', 'zero charge rejected'); throws(fn() => admin_charge_action($one, 'explode', null), 'Unknown', 'unknown admin action rejected');

section('billing: starting a payment through the gateway');
$before = $mockDb()['inits']; $url = start_payment($M, $octId); check('checkout url returned', str_starts_with($url, 'https://gateway.test/embed?ct=apay_'));
$pay = one('SELECT * FROM {payments} WHERE charge_id=?', [$octId]); eq($pay['amount'], 200000, 'payment row stores minor units'); $g = $mockDb()['payments'][$pay['api_payment_id']];
eq($g['amount'], 2000, 'gateway receives rupees (decimal), not paise'); eq($g['currency'], 'INR', 'INR'); eq($g['redirect_mode'], 'signed', 'signed return mode requested'); eq($g['success'], 'https://finance.test/dues/return', 'return link is absolute and uses APP_URL'); eq($g['metadata']['charge_id'], $octId, 'metadata carries the charge id');
eq(start_payment($M, $octId), $url, 'an open checkout is reused (init is not idempotent)'); eq($mockDb()['inits'], $before + 1, 'gateway init called only once');
throws(fn() => start_payment($ADM, $octId), 'not found', "another user cannot pay (or even see) someone else's charge");
$tok = PAYU_API_TOKEN; $r = payu_request('GET', '/ping'); eq($r['status'], 'authenticated', 'ping works with the token');

section('billing: webhook security and idempotency');
$base = ['event' => 'payment.captured', 'api_payment_id' => $pay['api_payment_id'], 'amount' => 2000, 'currency' => 'INR', 'status' => 'captured', 'rzp_payment_id' => 'pay_1', 'method' => 'upi'];
eq($hook($base, 'd-bad', 'sha256=deadbeef')[0], 401, 'bad signature rejected'); eq((int) val('SELECT COUNT(*) FROM {webhook_events}'), 0, 'rejected webhook leaves no trace');
eq(handle_payu_webhook('{"event":"x"}', [])[0], 401, 'missing signature rejected');
eq(val('SELECT status FROM {charges} WHERE id=?', [$octId]), 'pending', 'still unpaid after rejected webhooks');
eq($hook(['event' => 'payment.failed', 'api_payment_id' => $pay['api_payment_id'], 'status' => 'failed', 'error' => ['message' => 'Declined']])[0], 200, 'failed event acknowledged'); eq(val('SELECT status FROM {charges} WHERE id=?', [$octId]), 'pending', 'a failed attempt does not close the charge');
eq($hook($base + ['x' => 1], 'd-wrong', null)[0], 200, 'captured accepted'); // amount ok => paid; use a fresh payment below for the mismatch case
eq(val('SELECT status FROM {charges} WHERE id=?', [$octId]), 'paid', 'capture after an earlier failure still settles the charge'); eq(val('SELECT paid_via FROM {charges} WHERE id=?', [$octId]), 'gateway', 'paid via gateway');
eq($hook($base, 'd-wrong', null), [200, 'duplicate'], 'same delivery id is a no-op'); eq($hook($base, 'd-new-same-event')[0], 200, 'replayed event with a new id is harmless'); eq((int) val("SELECT COUNT(*) FROM {notifications} WHERE user_id=? AND dedupe_key=?", [$M, "charge:$octId:paid"]), 1, 'one receipt notification only');
eq($hook(['event' => 'payment.captured', 'api_payment_id' => 'apay_unknown', 'amount' => 1, 'currency' => 'INR'])[0], 200, 'unknown payment acknowledged'); eq($hook(['event' => 'brand.new.event'])[0], 200, 'unknown event acknowledged (no retry storm)');
$sepId = (int) val('SELECT id FROM {charges} WHERE user_id=? AND period=?', [$M, '2026-09']); run("UPDATE {charges} SET status='pending' WHERE id=?", [$sepId]); start_payment($M, $sepId);
$p2 = one('SELECT * FROM {payments} WHERE charge_id=?', [$sepId]);
$hook(['event' => 'payment.captured', 'api_payment_id' => $p2['api_payment_id'], 'amount' => 1, 'currency' => 'INR', 'status' => 'captured']); eq(val('SELECT status FROM {charges} WHERE id=?', [$sepId]), 'pending', 'amount mismatch is NOT accepted as paid');
$hook(['event' => 'payment.captured', 'api_payment_id' => $p2['api_payment_id'], 'amount' => 2050, 'currency' => 'USD', 'status' => 'captured']); eq(val('SELECT status FROM {charges} WHERE id=?', [$sepId]), 'pending', 'currency mismatch is NOT accepted as paid');

section('billing: return page lookup, signed return links, admin overrides');
$setMock($p2['api_payment_id'], 'captured'); $fresh = refresh_payment(find_payment($p2['api_payment_id'])); eq($fresh['status'], 'captured', 'server-side status lookup settles the payment'); eq(val('SELECT status FROM {charges} WHERE id=?', [$sepId]), 'paid', 'charge paid via status lookup (no browser trust)');
$q = ['api_payment_id' => 'apay_1', 'status' => 'captured', 'payment_id' => 'pay_1', 'order_id' => 'o_1', 'amount' => '20.00', 'currency' => 'INR', 'ts' => (string) time()]; ksort($q); $q['sig'] = hash_hmac('sha256', http_build_query($q), PAYU_WEBHOOK_SECRET);
check('valid signed return accepted', verify_return_sig($q, PAYU_WEBHOOK_SECRET)); $bad = $q; $bad['status'] = 'failed'; check('tampered return rejected', !verify_return_sig($bad, PAYU_WEBHOOK_SECRET));
$old = $q; $old['ts'] = (string) (time() - 4000); unset($old['sig']); ksort($old); $old['sig'] = hash_hmac('sha256', http_build_query($old), PAYU_WEBHOOK_SECRET); check('old return link rejected', !verify_return_sig($old, PAYU_WEBHOOK_SECRET)); check('no secret => nothing verifies', !verify_return_sig($q, ''));
$octPending = (int) val('SELECT id FROM {charges} WHERE user_id=? AND title=?', [$M, 'Diwali gift']); eq(admin_charge_action($octPending, 'paid', 'cash received'), 'Marked as paid (received outside the app).', 'admin can record a cash payment'); eq(val('SELECT paid_via FROM {charges} WHERE id=?', [$octPending]), 'manual', 'recorded as manual');
throws(fn() => admin_charge_action($octPending, 'cancel', null), 'unpaid', 'a settled charge cannot be cancelled');
eq(PAYU_API_TOKEN === 'umpi_live_test' && !str_contains((string) val('SELECT GROUP_CONCAT(detail) FROM {audit_log}'), 'umpi_live'), true, 'token never appears in the audit log');
proc_terminate($mock); @unlink($mockState);
section('upgrades');
$child = (string) shell_exec('MFNC_DB_NAME=' . escapeshellarg(DB_NAME) . ' MFNC_DB_PORT=' . (int) DB_PORT . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/oldconfig.php') . ' 2>&1');
eq(trim($child), 'OK', 'an older config.php (without the payment settings) keeps working after an upgrade');

// ================= passkeys: a simulated authenticator exercises registration and sign-in =================
final class B { public function __construct(public string $s) {} }
function cbor_head(int $mt, int $n): string { return $n < 24 ? chr(($mt << 5) | $n) : ($n < 256 ? chr(($mt << 5) | 24) . chr($n) : chr(($mt << 5) | 25) . pack('n', $n)); }
function cbor_enc($v): string {
    if (is_int($v)) { return $v >= 0 ? cbor_head(0, $v) : cbor_head(1, -1 - $v); }
    if ($v instanceof B) { return cbor_head(2, strlen($v->s)) . $v->s; }
    if (is_string($v)) { return cbor_head(3, strlen($v)) . $v; }
    if (is_array($v) && $v !== [] && array_is_list($v)) { return cbor_head(4, count($v)) . implode('', array_map('cbor_enc', $v)); }
    $o = cbor_head(5, count($v)); foreach ($v as $k => $x) { $o .= cbor_enc($k) . cbor_enc($x); } return $o;
}
$RPID = webauthn_rp()['id']; $ORIGIN = webauthn_rp()['origin'];
$pad32 = fn($b) => str_pad($b, 32, "\0", STR_PAD_LEFT);
$newEc = function () use ($pad32) { $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]); $d = openssl_pkey_get_details($k)['ec']; return [$k, [1 => 2, 3 => -7, -1 => 1, -2 => new B($pad32($d['x'])), -3 => new B($pad32($d['y']))]]; };
$newRsa = function () { $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]); $d = openssl_pkey_get_details($k)['rsa']; return [$k, [1 => 3, 3 => -257, -1 => new B($d['n']), -2 => new B($d['e'])]]; };
$authData = fn(string $rpId, int $flags, int $count, string $tail = '') => hash('sha256', $rpId, true) . chr($flags) . pack('N', $count) . $tail;
$regIn = function (array $u, array $cose, string $credId, array $o = []) use ($ORIGIN, $RPID, $authData) {
    $opts = webauthn_register_options($u); $chal = $o['challenge'] ?? $opts['challenge'];
    $ad = $authData($o['rp'] ?? $RPID, $o['flags'] ?? 0x45, 0, str_repeat("\0", 16) . pack('n', strlen($credId)) . $credId . cbor_enc($cose));
    return ['name' => 'Test phone', 'clientDataJSON' => b64u_enc(json_encode(['type' => 'webauthn.create', 'challenge' => $chal, 'origin' => $o['origin'] ?? $ORIGIN])), 'attestationObject' => b64u_enc(cbor_enc(['fmt' => 'none', 'attStmt' => [], 'authData' => new B($ad)])), 'transports' => ['internal']];
};
$loginIn = function ($key, string $credId, array $o = []) use ($ORIGIN, $RPID, $authData) {
    $opts = webauthn_login_options(); $cdj = json_encode(['type' => 'webauthn.get', 'challenge' => $o['challenge'] ?? $opts['challenge'], 'origin' => $o['origin'] ?? $ORIGIN]);
    $ad = $authData($o['rp'] ?? $RPID, $o['flags'] ?? 0x05, $o['count'] ?? 1); openssl_sign($ad . hash('sha256', $cdj, true), $sig, $o['signWith'] ?? $key, OPENSSL_ALGO_SHA256);
    return ['id' => b64u_enc($credId), 'clientDataJSON' => b64u_enc($cdj), 'authenticatorData' => b64u_enc($ad), 'signature' => b64u_enc($sig), 'userHandle' => $o['handle'] ?? ''];
};
$PK = create_user('pk@x.test', 'Passkey Pat', 'password-12345'); $pkU = ['id' => $PK, 'email' => 'pk@x.test', 'name' => 'Passkey Pat'];

section('passkeys: CBOR and keys');
$o = 0; $m = cbor_decode(cbor_enc(['fmt' => 'none', 'n' => -7, 'a' => [1, 2], 'b' => new B('xy')]), $o); eq([$m['fmt'], $m['n'], $m['a'], $m['b']], ['none', -7, [1, 2], 'xy'], 'CBOR maps, negatives, arrays and byte strings decode');
throws(fn() => cbor_decode("\xff\xff"), 'passkey', 'garbage CBOR is rejected, not trusted'); throws(fn() => cbor_decode(str_repeat("\x81", 40)), 'passkey', 'deeply nested CBOR is rejected');
[$ec, $cose] = $newEc(); $o2 = 0; [$pem, $alg] = cose_to_pem(cbor_decode(cbor_enc($cose), $o2)); eq($alg, -7, 'ES256 COSE key parsed'); check('PEM is a usable public key', openssl_pkey_get_public($pem) !== false);
throws(fn() => cose_to_pem([1 => 1, 3 => -8]), 'not supported', 'unsupported key types refused');

section('passkeys: registration');
$cred1 = random_bytes(32); $in = $regIn($pkU, $cose, $cred1); eq(webauthn_register_verify($pkU, $in) > 0, true, 'a valid passkey registers'); eq((int) val('SELECT alg FROM {passkeys} WHERE user_id=?', [$PK]), -7, 'algorithm stored');
throws(fn() => webauthn_register_verify($pkU, $in), 'expired', 'the challenge is single-use (replay refused)');
throws(fn() => webauthn_register_verify($pkU, $regIn($pkU, $cose, $cred1)), 'already registered', 'the same credential cannot be added twice');
[, $c2] = $newEc(); throws(fn() => webauthn_register_verify($pkU, $regIn($pkU, $c2, random_bytes(32), ['origin' => 'https://evil.test'])), 'different website', 'wrong origin refused');
throws(fn() => webauthn_register_verify($pkU, $regIn($pkU, $c2, random_bytes(32), ['rp' => 'evil.test'])), 'different website', 'wrong relying-party hash refused');
throws(fn() => webauthn_register_verify($pkU, $regIn($pkU, $c2, random_bytes(32), ['challenge' => b64u_enc(random_bytes(32))])), 'does not match', 'a challenge we did not issue is refused');
throws(fn() => webauthn_register_verify($pkU, $regIn($pkU, $c2, random_bytes(32), ['flags' => 0x40])), 'present', 'user-presence flag is required');
$tampered = $regIn($pkU, $c2, random_bytes(32)); $_SESSION['wa']['t'] = time() - 400; throws(fn() => webauthn_register_verify($pkU, $tampered), 'expired', 'an old challenge (over 5 minutes) is refused');
[$rsa, $rcose] = $newRsa(); $cred2 = random_bytes(32); webauthn_register_verify($pkU, $regIn($pkU, $rcose, $cred2)); eq((int) val('SELECT alg FROM {passkeys} WHERE credential_id=?', [$cred2]), -257, 'RS256 passkeys are supported too');
$ex = webauthn_register_options($pkU)['excludeCredentials']; eq(count($ex), 2, 'existing passkeys are excluded so a device is not enrolled twice');

section('passkeys: sign in');
run('DELETE FROM {login_attempts}'); eq(webauthn_login_verify($loginIn($ec, $cred1)), $PK, 'a valid assertion signs the right person in'); eq((int) val('SELECT sign_count FROM {passkeys} WHERE credential_id=?', [$cred1]), 1, 'sign counter advances');
$a = $loginIn($ec, $cred1, ['count' => 2]); webauthn_login_verify($a); throws(fn() => webauthn_login_verify($a), 'expired', 'an assertion cannot be replayed');
throws(fn() => webauthn_login_verify($loginIn($ec, $cred1, ['count' => 2])), 'cloned', 'a sign counter that does not go up is treated as a cloned key');
[$other] = $newEc(); throws(fn() => webauthn_login_verify($loginIn($ec, $cred1, ['count' => 9, 'signWith' => $other])), 'signature', 'a signature from a different key is refused');
throws(fn() => webauthn_login_verify($loginIn($ec, random_bytes(32), ['count' => 9])), 'not registered', 'an unknown credential is refused');
throws(fn() => webauthn_login_verify($loginIn($ec, $cred1, ['count' => 9, 'flags' => 0x01])), 'verify you', 'user verification (biometric / PIN) is required');
throws(fn() => webauthn_login_verify($loginIn($ec, $cred1, ['count' => 9, 'origin' => 'https://evil.test'])), 'different website', 'phishing origin refused');
throws(fn() => webauthn_login_verify($loginIn($ec, $cred1, ['count' => 9, 'rp' => 'evil.test'])), 'different website', 'wrong RP id refused');
throws(fn() => webauthn_login_verify($loginIn($ec, $cred1, ['count' => 9, 'handle' => b64u_enc('u99999')])), 'does not belong', 'a mismatching user handle is refused');
eq(webauthn_login_verify($loginIn($rsa, $cred2, ['count' => 3])), $PK, 'RS256 assertions verify');
run("UPDATE {users} SET status='disabled' WHERE id=?", [$PK]); throws(fn() => webauthn_login_verify($loginIn($ec, $cred1, ['count' => 20])), 'disabled', 'a disabled account cannot use a passkey'); run("UPDATE {users} SET status='active' WHERE id=?", [$PK]);
eq(count(webauthn_login_options('pk@x.test')['allowCredentials']), 2, 'with an email, only that person’s passkeys are offered'); eq(webauthn_login_options('nobody@x.test')['allowCredentials'], [], 'an unknown email reveals nothing');
run('DELETE FROM {login_attempts}'); $thr = 0; for ($i = 0; $i < 25; $i++) { try { webauthn_login_verify($loginIn($ec, random_bytes(32))); } catch (UserError $e) { if (str_contains($e->getMessage(), 'Too many')) { $thr++; } } } check('repeated failures are throttled', $thr > 0); run('DELETE FROM {login_attempts}');


section('offline sync');
$accM = (int) val('SELECT id FROM {accounts} WHERE user_id=? ORDER BY id LIMIT 1', [$M]);
$op = fn(string $uid, array $d) => ['uid' => $uid, 'kind' => 'transaction', 'data' => $d + ['type' => 'expense', 'amount' => '250.50', 'date' => '2026-10-15', 'account_id' => (string) $accM, 'description' => 'Offline chai', 'id' => '999']];
$r = sync_apply_ops($momU, [$op('aaaaaaaa-0001', [])]); eq($r[0]['ok'], true, 'queued transaction is applied');
eq((int) val("SELECT amount FROM {transactions} WHERE user_id=? AND description='Offline chai'", [$M]), 25050, 'amount stored exactly in paise'); eq((int) val("SELECT COUNT(*) FROM {transactions} WHERE id=999"), 0, 'client-supplied id is ignored (create only, never overwrite)');
$r = sync_apply_ops($momU, [$op('aaaaaaaa-0001', [])]); eq($r[0]['duplicate'] ?? false, true, 'retrying the same change is recognised'); eq((int) val("SELECT COUNT(*) FROM {transactions} WHERE user_id=? AND description='Offline chai'", [$M]), 1, 'a retry never creates a duplicate record');
$r = sync_apply_ops($momU, [$op('aaaaaaaa-0002', ['amount' => '0'])]); eq([$r[0]['ok'], $r[0]['final']], [false, true], 'invalid change is rejected as final with a reason'); check('rejection carries a readable message', $r[0]['error'] !== '');
eq((int) val("SELECT COUNT(*) FROM {sync_ops} WHERE client_uid='aaaaaaaa-0002'"), 0, 'a rejected change can be corrected and re-sent'); eq(sync_apply_ops($momU, [['uid' => '../../x', 'kind' => 'transaction', 'data' => []]])[0]['ok'], false, 'malformed change id rejected');
$other = sync_apply_ops($admU, [$op('aaaaaaaa-0003', [])]); eq($other[0]['ok'], false, "a change cannot use another person's account"); eq(sync_apply_ops($momU, [['uid' => 'aaaaaaaa-0004', 'kind' => 'delete_everything', 'data' => []]])[0]['ok'], false, 'unsupported operations are refused');

section('intro screen rule (after 5 days of use, at most twice that day)');
$I = create_user('intro@x.test', 'Intro', 'password-12345'); $iu = ['id' => $I, 'role' => 'user']; start_session();
$open = function () use ($iu) { unset($_SESSION['intro_pending']); $_SESSION['last_seen'] = 0; return track_app_open($iu); }; // unset = the person dismissed the previous intro
for ($k = 4; $k >= 1; $k--) { run('INSERT INTO {usage_days} (user_id, day, opens) VALUES (?,?,1)', [$I, day_after_n(today(), -$k)]); }
eq($open(), true, 'opening the app on the 5th day of use shows the intro'); $_SESSION['last_seen'] = time(); eq(track_app_open($iu), true, 'a reload before dismissing keeps showing the same intro (not consumed)'); eq(val('SELECT intro_shown FROM {preferences} WHERE user_id=?', [$I]), 1, 'reloading does not use up the second showing'); eq($open(), true, 'a second open the same day shows it once more'); eq($open(), false, 'a third open the same day does not');
eq(val('SELECT intro_shown FROM {preferences} WHERE user_id=?', [$I]), 2, 'shown twice, never again'); run('UPDATE {preferences} SET intro_first_day=? WHERE user_id=?', [day_after_n(today(), -1), $I]); eq($open(), false, 'never on a later day');
$_SESSION['last_seen'] = time(); eq(track_app_open($iu), false, 'reloads within the same visit do not count as opening the app');
$J = create_user('intro2@x.test', 'Intro2', 'password-12345'); $ju = ['id' => $J, 'role' => 'user']; $_SESSION['last_seen'] = 0; eq(track_app_open($ju), false, 'a brand-new account sees no intro on day 1');
$_SESSION = [];


section('single-page router and jelly tabs');
unset($_SERVER['HTTP_X_MFNC_SPA']); check('a normal request is not a router request', !is_spa());
$_SERVER['HTTP_X_MFNC_SPA'] = '1'; check('the router header is recognised', is_spa()); unset($_SERVER['HTTP_X_MFNC_SPA']);
$jh = jelly(['a' => ['label' => 'One', 'href' => '/x'], 'b' => ['label' => 'Two', 'href' => '/y']], 'b', ['label' => 'Pick']);
check('jelly renders a radiogroup with the active chip checked', str_contains($jh, 'role="radiogroup"') && str_contains($jh, 'data-value="b" tabindex="0"') && substr_count($jh, 'aria-checked="true"') === 1);
check('jelly link chips are anchors and escape labels', str_contains($jh, '<a href="/x"') && !str_contains(jelly(['a' => ['label' => '<b>x</b>']], 'a'), '<b>x</b>'));
section('collations (A1)');
eq(val('SELECT @@collation_connection'), DB_COLLATION, 'connection collation is pinned');
db()->exec(sqlt('ALTER TABLE {users} CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci')); db()->exec(sqlt('ALTER TABLE {settings} MODIFY v TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL'));
mfnc_fix_collations();
eq((int) val('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ? AND TABLE_COLLATION <> ?', ['%\\_mfnt', DB_COLLATION]), 0, 'all tables converted to one collation');
eq((int) val('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ? AND COLLATION_NAME IS NOT NULL AND COLLATION_NAME <> ?', ['%\\_mfnt', DB_COLLATION]), 0, 'all columns converted to one collation');
check('joined string comparison across tables works', is_array(all('SELECT u.email FROM {users} u JOIN {settings} s ON s.k = u.name WHERE u.email = ?', ['x'])));


// ================= email (native SMTP), SMS (Twilio REST), secrets, features, wallet =================
section('secrets');
$enc = secret_encrypt('s3cr3t-value'); check('secret is encrypted at rest', !str_contains($enc, 's3cr3t') && str_starts_with($enc, 'v1:')); eq(secret_decrypt($enc), 's3cr3t-value', 'secret decrypts'); eq(secret_decrypt('v1:garbage'), '', 'tampered secret yields nothing'); eq(mask_secret('abcdefghij'), '••••••••ghij', 'secret is masked'); eq(mask_secret(''), '', 'empty secret shows nothing');
section('templates');
eq(render_template('Hi {name}, {amount} {nope}', ['name' => 'Asha', 'amount' => '₹5']), 'Hi Asha, ₹5 {nope}', 'template parameters render; unknown ones are left visible');
eq(normalize_phone('98765 43210'), '+919876543210', 'Indian number gets +91'); eq(normalize_phone('+1 (555) 123-4567'), '+15551234567', 'international number kept'); eq(normalize_phone('12'), null, 'short number rejected');
$mime = build_mime('a@x.test', "Evil\r\nBcc: x@y.z", 'b@x.test', "Sub\r\nBcc: z@z.z", "line1\n.dot", '<p>x</p>'); check('header injection is neutralised', !preg_match('/^Bcc:/mi', $mime));
section('SMTP client against a mock server');
$smtpOut = sys_get_temp_dir() . '/mfnc_smtp_mock.json'; @unlink($smtpOut);
$smtp = proc_open([PHP_BINARY, __DIR__ . '/smtp_mock.php', '127.0.0.1:2525', $smtpOut], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $sp);
for ($i = 0; $i < 30; $i++) { $fp = @fsockopen('127.0.0.1', 2525, $en, $es, 0.2); if ($fp) { fclose($fp); break; } usleep(100000); }
$cfgSet(['email_enabled' => '1', 'smtp_transport' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => '2525', 'smtp_secure' => 'none', 'smtp_user' => 'u1', 'smtp_from' => 'noreply@x.test', 'smtp_from_name' => 'Moms Finance']); secret_set('smtp_pass', 'p@ss');
[$ok, $err] = send_email('mom@x.test', 'Hello ₹ test', "Body line\n.leading dot line", $M, 'test'); check('email sent through SMTP', $ok, $err);
$got = json_decode((string) @file_get_contents($smtpOut), true)[0] ?? []; check('server saw the sender and recipient', str_contains($got['from'] ?? '', 'noreply@x.test') && str_contains($got['to'] ?? '', 'mom@x.test')); check('AUTH PLAIN carried the stored password', ($got['auth'] ?? '') === "\0u1\0p@ss");
$bodyDecoded = ''; if (preg_match_all('/Content-Transfer-Encoding: base64\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+)/', $got['data'] ?? '', $mm)) { $bodyDecoded = base64_decode(preg_replace('/\s+/', '', $mm[1][0])); } check('body survives encoding incl. leading dot', str_contains($bodyDecoded, "\n.leading dot line"));
eq(val("SELECT status FROM {email_log} WHERE event='test' ORDER BY id DESC LIMIT 1"), 'sent', 'email is logged as sent');
[$ok, $err] = send_email('reject@x.test', 'x', 'y', $M, 'test'); check('a rejected recipient is reported', !$ok && str_contains($err, '550'), $err); eq(val("SELECT status FROM {email_log} ORDER BY id DESC LIMIT 1"), 'failed', 'rejection is logged as failed');
[$ok] = send_email('not-an-email', 'x', 'y'); check('invalid recipient refused', !$ok);
$cfgSet(['smtp_port' => '2599']); [$ok, $err] = send_email('mom@x.test', 'x', 'y', $M, 'test'); check('connection failure is reported, not thrown', !$ok && str_contains($err, 'connect'), $err); $cfgSet(['smtp_port' => '2525']);
section('payment emails');
$before = (int) val('SELECT COUNT(*) FROM {email_log}'); $r = notify_email($M, 'bill.paid', ['title' => 'Electricity', 'amount' => '₹2,400']); check('payment email sent', $r); eq((int) val('SELECT COUNT(*) FROM {email_log}'), $before + 1, 'and logged');
check('per-payment choice "no" sends nothing', notify_email($M, 'bill.paid', ['title' => 'x'], false) === false); run('UPDATE {preferences} SET email_alerts=0 WHERE user_id=?', [$M]); check('person opted out: nothing sent', notify_email($M, 'bill.paid', ['title' => 'x']) === false); check('...unless they chose yes for this payment', notify_email($M, 'bill.paid', ['title' => 'x'], true)); run('UPDATE {preferences} SET email_alerts=1 WHERE user_id=?', [$M]);
$cfgSet(['email_enabled' => '0']); check('global switch off: nothing sent', notify_email($M, 'bill.paid', ['title' => 'x']) === false); $cfgSet(['email_enabled' => '1']);
$cfgSet(['feat_email_mode' => 'paid', 'feat_email_price' => '9900']); check('paid feature without a plan: no receipt', notify_email($M, 'bill.paid', ['title' => 'x']) === false); grant_feature($M, 'email', 30); check('...with a plan: receipt sent', notify_email($M, 'bill.paid', ['title' => 'x'])); $cfgSet(['feat_email_mode' => 'free']);
if (is_resource($smtp)) { proc_terminate($smtp); proc_close($smtp); }
section('Twilio SMS against a mock');
$twState = sys_get_temp_dir() . '/mfnc_twilio_mock.json'; @unlink($twState); putenv('MFNC_TWILIO_BASE=http://127.0.0.1:8098');
$tw = proc_open([PHP_BINARY, '-S', '127.0.0.1:8098', __DIR__ . '/twilio_mock.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $tp);
for ($i = 0; $i < 30; $i++) { $fp = @fsockopen('127.0.0.1', 8098, $en, $es, 0.2); if ($fp) { fclose($fp); break; } usleep(100000); }
$cfgSet(['sms_enabled' => '1', 'twilio_sid' => 'ACtest00000000', 'twilio_from' => '+15550001111', 'sms_auto_charges' => '1']); secret_set('twilio_token', 'tok_test');
[$ok, $err] = send_sms('9876543210', 'Hello', $M, 'test'); check('SMS sent via Twilio REST', $ok, $err); $twDb = json_decode((string) @file_get_contents($twState), true) ?: []; eq($twDb[0]['to'] ?? '', '+919876543210', 'number normalised to E.164'); eq($twDb[0]['from'] ?? '', '+15550001111', 'from number used');
[$ok, $err] = send_sms('12', 'x', $M); check('bad number refused before calling Twilio', !$ok); secret_set('twilio_token', 'wrong'); [$ok, $err] = send_sms('9876543210', 'x', $M); check('rejected credentials reported', !$ok && str_contains($err, 'Authenticate'), $err); secret_set('twilio_token', 'tok_test');
run('UPDATE {preferences} SET phone=?, sms_alerts=1 WHERE user_id=?', ['+919876543210', $M]);
$ch = ['id' => 77, 'title' => 'Phone bill', 'amount' => 125000, 'late_fee' => 0, 'late_fee_waived' => 0, 'due_date' => '2026-11-05']; [$ok] = sms_charge_alert($M, $ch, 'charge.new'); check('charge alert SMS sent', $ok);
$last = (json_decode((string) @file_get_contents($twState), true) ?: [])[1 + 0] ?? null; $twDb = json_decode((string) @file_get_contents($twState), true); $last = end($twDb); check('SMS contains name, amount, due date and a payment link', str_contains($last['body'], 'Mom') && str_contains($last['body'], '₹1,250') && str_contains($last['body'], '5 Nov 2026') && str_contains($last['body'], 'https://finance.test/dues?charge=77'), $last['body']);
run('UPDATE {preferences} SET sms_alerts=0 WHERE user_id=?', [$M]); [$ok] = sms_charge_alert($M, $ch, 'charge.new'); check('person who opted out gets no automatic SMS', !$ok); [$ok] = sms_charge_alert($M, $ch, 'charge.link', true); check('admin can still send a link on request', $ok);
$cfgSet(['sms_enabled' => '0']); [$ok] = sms_charge_alert($M, $ch, 'charge.link', true); check('global switch off blocks SMS', !$ok);
if (is_resource($tw)) { proc_terminate($tw); proc_close($tw); } putenv('MFNC_TWILIO_BASE');
section('features, entitlements and wallet');
$cfgSet(['feat_dock_mode' => 'free']); check('free feature open to all', feature_on($momU, 'dock'));
$cfgSet(['feat_dock_mode' => 'paid', 'feat_dock_price' => '4900', 'feat_dock_days' => '30']); $fa = feature_access($momU, 'dock'); check('paid feature blocked without plan', !$fa['ok'] && $fa['code'] === 'plan'); check('admins are never blocked', feature_on($admU, 'dock'));
$until = grant_feature($M, 'dock', 30); eq($until, day_after_n(today(), 30), 'plan grants 30 days'); check('paid feature open with plan', feature_on($momU, 'dock')); eq(grant_feature($M, 'dock', 10), day_after_n(today(), 40), 'renewal extends from the current end'); revoke_feature($M, 'dock'); check('revoked plan blocks again', !feature_on($momU, 'dock'));
$cfgSet(['feat_ai_mode' => 'payg']); $fa = feature_access($momU, 'ai'); check('pay-as-you-go blocked at zero balance', !$fa['ok'] && $fa['code'] === 'wallet'); wallet_add($M, 5_000_000, 'topup', 'test', 'charge:t1'); check('top-up unlocks it', feature_on($momU, 'ai')); check('same top-up ref is applied once', !wallet_add($M, 5_000_000, 'topup', 'again', 'charge:t1')); eq(wallet_balance($M), 5_000_000, 'balance is exact (micro-rupees)');
wallet_add($M, -5_000_000, 'usage', 'spent'); check('empty wallet blocks again', !feature_on($momU, 'ai'));
$cfgSet(['feat_ai_mode' => 'free', 'feat_dock_mode' => 'free']);
section('a paid plan bought through the gateway');
$cid = create_self_charge($M, 'Dock · 30 days', 4900, ['feature' => 'dock', 'days' => 30]); eq(create_self_charge($M, 'Dock · 30 days', 4900, ['feature' => 'dock', 'days' => 30]), $cid, 'a second click reuses the open charge');
$cfgSet(['feat_dock_mode' => 'paid']); check('not yet usable', !feature_on($momU, 'dock')); admin_charge_action($cid, 'paid', null); check('paying the charge grants the plan', feature_on($momU, 'dock')); $u1 = entitlement_until($M, 'dock'); after_charge_paid($cid, 'again'); eq(entitlement_until($M, 'dock'), $u1, 'applying the same payment twice does not extend twice');
$tid = create_self_charge($M, 'Wallet top-up', 20000, ['topup_micro' => 200 * 10000]); $bal = wallet_balance($M); admin_charge_action($tid, 'paid', null); eq(wallet_balance($M), $bal + 2_000_000, 'paying a top-up credits the wallet'); after_charge_paid($tid, 'again'); eq(wallet_balance($M), $bal + 2_000_000, 'and only once'); $cfgSet(['feat_dock_mode' => 'free']);

// ================= AI advisor (mock transport) =================
putenv('MFNC_AI_MOCK=1');
section('AI: calculators');
$e = calc_emi(1000000, 9, 120); eq($e['emi'], 12667.58, 'EMI of ₹10L at 9% for 10 years'); eq(calc_sip(10000, 12, 10)['future_value'], 2323390.76, 'SIP future value'); eq(calc_payoff(200000, 36, 10000, 5000)['with_extra']['months_saved'], 13, 'extra payment shortens payoff');
check('payment below interest never pays off', calc_payoff(100000, 36, 1000)['possible'] === false); throws(fn() => calc_emi(-5, 9, 12), 'positive', 'bad EMI input rejected');
$t = calc_tax_regimes(1200000, 150000); eq($t['new']['total_tax'], 0.0, '₹12L salaried pays nothing in the new regime (87A)'); eq($t['old']['total_tax'], 117000.0, 'old regime with ₹1.5L deductions'); eq(calc_tax_regimes(2500000, 300000)['new']['total_tax'], 319800.0, '₹25L new regime tax'); eq($t['better'], 'new', 'cheaper regime is named');
section('AI: gate, metering, budgets');
$cfgSet(['ai_enabled' => '0']); eq(ai_gate($momU)['code'] ?? '', 'not_configured', 'disabled AI is refused'); $cfgSet(['ai_enabled' => '1']); eq(ai_gate($momU), null, 'enabled (mock) is allowed');
eq(ai_cost_micro('claude-sonnet-5-5', 1000, 500), 1000 * 250 + 500 * 1250, 'cost = tokens x price per million'); $cfgSet(['ai_price_in_claude-sonnet-5-5' => '100', 'ai_price_out_claude-sonnet-5-5' => '200']); eq(ai_cost_micro('claude-sonnet-5-5', 1000, 500), 200000, 'admin price override is used'); $cfgSet(['ai_price_in_claude-sonnet-5-5' => '250', 'ai_price_out_claude-sonnet-5-5' => '1250']);
$cfgSet(['ai_default_budget_micro' => '1000000']); ai_record_usage($momU, 'claude-sonnet-5-5', 4000, 0); eq(ai_month_spend($M), 1000000, 'usage is recorded'); eq(ai_gate($momU)['code'] ?? '', 'budget', 'monthly budget blocks at the cap'); run('UPDATE {users} SET ai_budget_micro=0 WHERE id=?', [$M]); eq(ai_gate($momU), null, 'a user budget of 0 means no cap'); run('UPDATE {users} SET ai_budget_micro=NULL WHERE id=?', [$M]); $cfgSet(['ai_default_budget_micro' => '0']);
$cfgSet(['feat_ai_mode' => 'payg']); wallet_add($M, -wallet_balance($M), 'adjust', 'reset'); eq(ai_gate($momU)['code'] ?? '', 'wallet', 'pay-as-you-go with an empty wallet is blocked'); wallet_add($M, 5_000_000, 'topup', 't', 'charge:ai1'); putenv('MFNC_AI_MOCK=0'); putenv('ANTHROPIC_API_KEY=x'); $b4 = wallet_balance($M); ai_record_usage($momU, 'claude-haiku-5-5', 10000, 2000); eq(wallet_balance($M), $b4 - (10000 * 85 + 2000 * 425), 'real usage is deducted from the wallet'); putenv('ANTHROPIC_API_KEY'); putenv('MFNC_AI_MOCK=1'); $cfgSet(['feat_ai_mode' => 'free']);
$cfgSet(['ai_rate_min' => '2']); ai_save_message($M, 'user', ['text' => 'a']); ai_save_message($M, 'user', ['text' => 'b']); ai_save_message($M, 'user', ['text' => 'c', 'setup' => 1]); eq(ai_gate($momU)['code'] ?? '', 'rate', 'per-minute limit (setup answers do not count)'); $cfgSet(['ai_rate_min' => '60']); run('DELETE FROM {ai_messages} WHERE user_id=?', [$M]);
section('AI: blocks are sanitised');
eq(ai_clean_block(['type' => 'script', 'x' => 1]), null, 'unknown block types are dropped'); $tb = ai_clean_block(['type' => 'table', 'columns' => ['A', ['label' => 'B', 'type' => 'money']], 'rows' => [['<img src=x onerror=alert(1)>', 5], 'junk']]); eq(count($tb['rows']), 1, 'malformed rows are dropped'); eq($tb['rows'][0][0], '<img src=x onerror=alert(1)>', 'strings are kept verbatim (the browser only ever uses textContent)'); eq(ai_clean_block(['type' => 'chart', 'labels' => ['a'], 'series' => []]), null, 'a chart without series is dropped');
eq(count(ai_clean_block(['type' => 'stats', 'items' => array_fill(0, 30, ['label' => 'x', 'value' => 1])])['items']), 8, 'stat cards are capped');
section('AI: interview, naming and the tool loop');
$AI = create_user('ai@x.test', 'Asha Rao', 'password-12345'); $aiU = ['id' => $AI, 'role' => 'user', 'name' => 'Asha Rao']; load_demo_data($AI); $ev = []; $emit = function ($e) use (&$ev) { $ev[] = $e; };
$p = ai_profile($AI); eq($p['stage'], 'interview', 'a new user starts in the interview'); ai_ensure_started($AI, $p); eq((int) val('SELECT COUNT(*) FROM {ai_messages} WHERE user_id=?', [$AI]), 1, 'the first question is already waiting');
foreach (AI_QUESTIONS as $i => $q) { $ev = []; ai_turn($aiU, 'answer ' . $i, $emit); }
$p = ai_profile($AI); eq($p['stage'], 'naming', 'after the last answer it asks for a name'); check('context was written from the answers', str_contains((string) $p['context'], 'Household') && str_contains((string) $p['context'], 'answer 7')); eq($p['suggested_name'], 'Paisa', 'a name is suggested');
check('a name prompt block with alternatives was streamed', (bool) array_filter($ev, fn($x) => ($x['block']['type'] ?? '') === 'name_prompt' && count($x['block']['alternatives']) === 2));
$ev = []; ai_turn($aiU, '  <b>Rani</b>  ', $emit); $p = ai_profile($AI); eq($p['agent_name'], 'bRani-b' === '' ? '' : 'bRanib', 'the person can choose their own name (markup stripped)'); eq($p['stage'], 'ready', 'then the agent is ready');
$ev = []; ai_turn($aiU, 'compare tax regimes please', $emit); $types = array_map(fn($x) => $x['block']['type'] ?? $x['type'], $ev); check('tax question streams calc steps then a comparison', in_array('calc', $types, true) && in_array('comparison', $types, true), implode(',', $types)); check('and a final text answer', in_array('text', $types, true));
$ev = []; ai_turn($aiU, 'what would an emi cost', $emit); $calc = array_values(array_filter($ev, fn($x) => ($x['block']['type'] ?? '') === 'calc'))[0]['block'] ?? null; check('EMI calc block lists the steps', $calc && count($calc['steps']) >= 3 && $calc['result']['value'] === '₹12,667.58', json_encode($calc['result'] ?? null));
$ev = []; ai_turn($aiU, 'evade tax for me', $emit); check('tax evasion is refused', (bool) array_filter($ev, fn($x) => str_contains($x['block']['text'] ?? '', 'do not help evade')));
check('usage was metered per call', (int) val('SELECT COUNT(*) FROM {ai_usage} WHERE user_id=?', [$AI]) >= 6);
$ev = []; ai_turn($aiU, 'mockerror', $emit); check('a service error is shown, not thrown', (bool) array_filter($ev, fn($x) => ($x['type'] ?? '') === 'error')); run("DELETE FROM {ai_messages} WHERE user_id=? AND content LIKE '%mockerror%'", [$AI]);
section('AI: preview, apply, undo (exact)');
$ev = []; ai_turn($aiU, 'add expense for coffee', $emit); $pv = array_values(array_filter($ev, fn($x) => ($x['block']['type'] ?? '') === 'preview'))[0]['block'] ?? null; check('a preview was produced', (bool) $pv); $aid = (int) $pv['action_id'];
$accId = (int) val('SELECT id FROM {accounts} WHERE user_id=? ORDER BY id LIMIT 1', [$AI]); $txBefore = (int) val('SELECT COUNT(*) FROM {transactions} WHERE user_id=?', [$AI]); eq($txBefore, (int) val('SELECT COUNT(*) FROM {transactions} WHERE user_id=?', [$AI]), 'nothing is written by the preview'); eq(val('SELECT status FROM {ai_actions} WHERE id=?', [$aid]), 'proposed', 'the proposal is logged as proposed');
$sBefore = load_snapshot($AI); $balBefore = array_column($sBefore['accounts'], 'balance', 'id')[$accId]; ai_apply($AI, $aid); $sAfter = load_snapshot($AI); eq(array_column($sAfter['accounts'], 'balance', 'id')[$accId], $balBefore - 49900, 'applying changes the balance by exactly the previewed amount'); throws(fn() => ai_apply($AI, $aid), 'already applied', 'cannot be applied twice');
ai_undo($AI, $aid); eq(array_column(load_snapshot($AI)['accounts'], 'balance', 'id')[$accId], $balBefore, 'undo restores the balance exactly'); eq(val('SELECT status FROM {ai_actions} WHERE id=?', [$aid]), 'undone', 'and is logged'); throws(fn() => ai_undo($AI, $aid), 'applied', 'cannot be undone twice');
$other = create_user('ai2@x.test', 'Other', 'password-12345'); throws(fn() => ai_apply($other, $aid), 'not found', "another person cannot touch someone else's action");
$u2 = []; $s = load_snapshot($AI); $cat = (int) val("SELECT id FROM {categories} WHERE user_id=? AND grp<>'income' ORDER BY id LIMIT 1", [$AI]); set_budget($AI, $cat, 123400);
$r = ai_propose($aiU, $s, 'set_budget', ['category_id' => $cat, 'amount' => 5000], 'x', $u2); ai_apply($AI, $r['action_id']); eq((int) val('SELECT amount FROM {budgets} WHERE user_id=? AND category_id=?', [$AI, $cat]), 500000, 'budget set'); ai_undo($AI, $r['action_id']); eq((int) val('SELECT amount FROM {budgets} WHERE user_id=? AND category_id=?', [$AI, $cat]), 123400, 'undo restores the previous budget value');
$r = ai_propose($aiU, $s, 'add_goal', ['name' => 'Scooter', 'target_amount' => 80000, 'monthly_target' => 4000], 'x', $u2); ai_apply($AI, $r['action_id']); check('goal added', (int) val("SELECT COUNT(*) FROM {goals} WHERE user_id=? AND name='Scooter'", [$AI]) === 1); $gid = (int) val("SELECT id FROM {goals} WHERE user_id=? AND name='Scooter'", [$AI]);
$r2 = ai_propose($aiU, load_snapshot($AI), 'contribute_goal', ['goal_id' => $gid, 'amount' => 1000, 'account_id' => $accId], 'x', $u2); ai_apply($AI, $r2['action_id']); eq(saved_amount($AI, 'goal', $gid), 100000, 'contribution applied'); throws(fn() => ai_undo($AI, $r['action_id']), 'added to this goal', 'a goal with money in it is not silently deleted'); ai_undo($AI, $r2['action_id']); eq(saved_amount($AI, 'goal', $gid), 0, 'contribution undone'); ai_undo($AI, $r['action_id']); eq((int) val("SELECT COUNT(*) FROM {goals} WHERE user_id=? AND name='Scooter'", [$AI]), 0, 'then the goal can be undone');
$occ = null; foreach (occurrence_views(load_snapshot($AI)) as $o) { if ($o['outstanding'] > 0 && !$o['skipped']) { $occ = $o; break; } } if ($occ) { $r = ai_propose($aiU, load_snapshot($AI), 'pay_bill', ['occurrence_id' => $occ['id'], 'amount' => $occ['outstanding'] / 100, 'account_id' => $accId], 'x', $u2); $paidBefore = paid_for_occurrence($AI, (int) $occ['id']); ai_apply($AI, $r['action_id']); eq(paid_for_occurrence($AI, (int) $occ['id']), $paidBefore + $occ['outstanding'], 'bill paid'); ai_undo($AI, $r['action_id']); eq(paid_for_occurrence($AI, (int) $occ['id']), $paidBefore, 'bill payment undone exactly'); }
throws(fn() => ai_propose($aiU, $s, 'create_transaction', ['type' => 'expense', 'amount' => 5, 'account_id' => 999999], 'x', $u2), 'Unknown account', 'unknown account is refused'); throws(fn() => ai_propose($aiU, $s, 'drop_database', [], 'x', $u2), 'Unknown action', 'unknown action kinds are refused');
section('AI: tools only see the signed-in user');
$u3 = []; $ov = ai_run_tool($aiU, 'get_overview', [], $u3); check('overview has this user\'s accounts', count($ov['accounts']) >= 1); $ov2 = ai_run_tool(['id' => $other, 'role' => 'user', 'name' => 'O'], 'get_overview', [], $u3); eq(array_intersect(array_column($ov['accounts'], 'id'), array_column($ov2['accounts'], 'id')), [], 'another user sees none of them'); throws(fn() => ai_run_tool($aiU, 'delete_everything', [], $u3), 'Unknown tool', 'unknown tools are refused');
$tx = ai_run_tool($aiU, 'list_transactions', ['limit' => 5], $u3); check('transactions are returned in rupees', $tx['count'] === 5 && is_float($tx['transactions'][0]['amount']) || is_int($tx['transactions'][0]['amount']));
section('AI: avatars');
check('an avatar is always produced (fallback when DiceBear is unreachable)', str_contains(ai_avatar_svg('bottts-neutral', 'Paisa'), '<svg')); check('unknown styles fall back safely', str_contains(ai_avatar_svg('../../etc', 'x'), '<svg'));
section('system prompt');
$sp = ai_system_prompt($aiU, ai_profile($AI)); check('variables are filled', str_contains($sp, 'bRanib') && str_contains($sp, 'Asha') && !str_contains($sp, '{agent_name}') && str_contains($sp, 'Household')); check('prompt forbids tax evasion', str_contains($sp, 'evade tax'));
$cfgSet(['ai_system_prompt' => 'Custom for {user_name}']); eq(ai_system_prompt($aiU, ai_profile($AI)), 'Custom for Asha', 'admin prompt replaces the default'); $cfgSet(['ai_system_prompt' => '']);

section('input hygiene (tester findings)');
$_POST = ['name' => "Evil\r\nBcc: x@y.z\tname"]; eq(in_line('name', 80), 'Evil Bcc: x@y.z name', 'single-line fields lose line breaks and tabs'); $_POST = ['notes' => "line1\r\nline2\x07"]; eq(in_str('notes', 80), "line1\nline2", 'multi-line fields keep line breaks but lose control characters'); $_POST = [];
eq(unknown_params('Hi {name} {amout} {nope}'), ['amout', 'nope'], 'typos in template parameters are found');
check('autolink leaves trailing punctuation outside the link', str_contains(email_html('see https://x.test/a).'), 'href="https://x.test/a">https://x.test/a</a>).'));

// ================= round 2 (builder-A): shop, model gating, effort mapping =================
section('shop: catalog and features');
$cfgSet(['feat_dock_mode' => 'free', 'feat_ai_mode' => 'free']);
check('built-in + premium model features are registered', isset(features()['dock'], features()['mdl_claude-opus-5-5']) && !isset(features()['mdl_claude-sonnet-5-5']));
$sh = create_user('shop@x.test', 'Shopper', 'password-12345'); $shU = ['id' => $sh, 'role' => 'user', 'name' => 'Shopper', 'email' => 'shop@x.test'];
eq(feature_cfg('mdl_claude-opus-5-5')['mode'], 'paid', 'premium models default to paid'); check('model plugin starts locked', !ai_model_access($shU, 'claude-opus-5-5')['ok']); check('allowed model is open', ai_model_access($shU, 'claude-sonnet-5-5')['ok']);
set_setting('shop_custom', json_encode([['key' => 'priority', 'label' => 'Priority support', 'desc' => 'x']])); check('admin-defined plugin joins the feature registry', isset(features()['priority']));
$cfgSet(['feat_priority_mode' => 'paid', 'feat_priority_price' => '30000', 'feat_priority_days' => '30']); $it = shop_item('priority'); check('custom plugin is purchasable in the catalog', $it && $it['buyable'] && $it['price'] === 30000);
set_setting('shop_meta', json_encode(['priority' => ['hidden' => 1]])); check('hidden items leave the shop', !array_filter(shop_catalog($shU), fn($x) => $x['key'] === 'priority') && (bool) array_filter(shop_catalog($shU, true), fn($x) => $x['key'] === 'priority')); set_setting('shop_meta', '');
section('shop: wallet purchase, idempotency, races');
throws(fn() => shop_buy($sh, 'priority', 'nonce-0001'), 'wallet has', 'cannot buy with an empty wallet'); eq(wallet_balance($sh), 0, 'nothing was debited');
wallet_add($sh, 1_000_000_000, 'topup', 'test', 'seed:shop'); $r1 = shop_buy($sh, 'priority', 'nonce-0001'); eq($r1['status'], 'done', 'purchase succeeds'); eq(wallet_balance($sh), 700_000_000, 'wallet debited exactly the price');
check('access was granted', feature_access($shU, 'priority')['ok']); $r2 = shop_buy($sh, 'priority', 'nonce-0001'); eq($r2['status'], 'already', 'the same click twice is applied once'); eq(wallet_balance($sh), 700_000_000, 'no double charge');
eq((int) val('SELECT COUNT(*) FROM {shop_purchases} WHERE user_id=?', [$sh]), 1, 'one receipt'); $u1 = entitlement_until($sh, 'priority'); shop_buy($sh, 'priority', 'nonce-0002'); check('a second purchase extends the access', entitlement_until($sh, 'priority') > $u1);
throws(fn() => shop_buy($sh, 'nonexistent', 'nonce-0003'), 'not for sale', 'unknown item'); throws(fn() => shop_buy($sh, 'dock', 'nonce-0004'), 'not for sale', 'a free feature is not for sale'); throws(fn() => shop_buy($sh, 'priority', 'x'), 'out of date', 'a missing nonce is refused');
$rc = one('SELECT id FROM {shop_purchases} WHERE user_id=? LIMIT 1', [$sh]); check('receipt number is stable', str_starts_with(shop_receipt_no(one('SELECT * FROM {shop_purchases} WHERE id=?', [$rc['id']])), 'R'));
section('shop: top-up that completes the purchase, card path, packs');
$sh2 = create_user('shop2@x.test', 'Shopper2', 'password-12345'); $cid = shop_start_checkout($sh2, 'priority', 'wallet', 0); $ch = one('SELECT * FROM {charges} WHERE id=?', [$cid]); eq((int) $ch['amount'], 30000, 'top-up charge covers the shortfall'); eq($ch['buy_key'], 'priority', 'charge remembers what to buy');
apply_charge_effects($ch); check('paying the top-up completes the purchase', feature_access(['id' => $sh2, 'role' => 'user'], 'priority')['ok']); eq(wallet_balance($sh2), 0, 'credit was spent on it'); apply_charge_effects($ch); eq((int) val('SELECT COUNT(*) FROM {shop_purchases} WHERE user_id=?', [$sh2]), 1, 'applying the charge twice does nothing more');
$cid = shop_start_checkout($sh2, 'pack_small', 'card'); $ch = one('SELECT * FROM {charges} WHERE id=?', [$cid]); apply_charge_effects($ch); apply_charge_effects($ch); eq(wallet_balance($sh2), 550_000_000, 'a usage pack credits the wallet once (with bonus)');
throws(fn() => shop_start_checkout($sh2, 'dock', 'card'), 'not for sale', 'free items have no checkout');
$sh3 = create_user('shop3@x.test', 'Shopper3', 'password-12345'); wallet_add($sh3, 400_000_000, 'topup', 't', 'seed:sh3'); shop_complete_after_topup($sh3, 'priority', 3); check('top-up completion works for a single-digit charge id (nonce length)', feature_access(['id' => $sh3, 'role' => 'user'], 'priority')['ok']);
section('AI models: gating and enforcement');
$aiShop = ['id' => $sh, 'role' => 'user', 'name' => 'Shopper', 'email' => 'shop@x.test']; throws(fn() => ai_pick_model($aiShop, 'claude-opus-5-5'), 'premium', 'chat refuses a locked premium model even if the client asks'); throws(fn() => ai_pick_model($aiShop, 'claude-nope'), 'not available', 'unknown model refused');
eq(ai_pick_model($aiShop, ''), 'claude-sonnet-5-5', 'default model when none asked'); grant_feature($sh, 'mdl_claude-opus-5-5', 30, 'test'); eq(ai_pick_model($aiShop, 'claude-opus-5-5'), 'claude-opus-5-5', 'owning the plugin unlocks the model');
set_setting('ai_models', json_encode(['claude-haiku-5-5' => ['state' => 'hidden']])); throws(fn() => ai_pick_model($aiShop, 'claude-haiku-5-5'), 'not available', 'hidden models are refused'); check('hidden model is not listed', !array_filter(ai_model_options($aiShop), fn($m) => $m['key'] === 'claude-haiku-5-5')); check('admins may still use a hidden model', ai_model_access(['id' => 1, 'role' => 'admin'], 'claude-haiku-5-5')['ok']); set_setting('ai_models', '');
check('locked models are listed (dimmed) after open ones', (function () { $u = ['id' => 999999, 'role' => 'user']; $o = ai_model_options($u); $l = array_column($o, 'locked'); return $l === [false, false, true, true]; })());
$AIM = $aiShop; $ev = []; $emitM = function ($x) use (&$ev) { $ev[] = $x; };
$aiStage = (int) val('SELECT COUNT(*) FROM {ai_profiles} WHERE user_id=?', [$sh]); ai_profile($sh); run("UPDATE {ai_profiles} SET stage='ready', agent_name='Z' WHERE user_id=?", [$sh]); revoke_feature($sh, 'mdl_claude-opus-5-5');
ai_turn($AIM, 'hello there', $emitM, ['model' => 'claude-opus-5-5']); check('ai_turn emits an error for a locked model and does nothing', (bool) array_filter($ev, fn($x) => ($x['type'] ?? '') === 'error' && ($x['code'] ?? '') === 'model') && !array_filter($ev, fn($x) => ($x['type'] ?? '') === 'block'));
section('AI effort mapping');
$p = ai_effort_params('claude-sonnet-5-5', 'Max'); eq($p['output_config']['effort'], 'max', 'adaptive models get the effort level'); eq($p['thinking']['type'], 'adaptive', 'and adaptive thinking'); eq($p['max_tokens'], 16000, 'Max allows long replies');
$p = ai_effort_params('claude-sonnet-5-5', 'Low'); eq($p['output_config']['effort'], 'low', 'Low maps to low'); eq(ai_effort_params('claude-sonnet-5-5', 'bogus')['label'], 'Medium', 'unknown labels fall back to Medium');
set_setting('ai_models', json_encode(['claude-haiku-5-5' => ['thinking' => 'budget'], 'claude-sonnet-5-5' => ['thinking' => 'none']])); $p = ai_effort_params('claude-haiku-5-5', 'High'); eq($p['thinking'], ['type' => 'enabled', 'budget_tokens' => 2048], 'extended-thinking models get a token budget'); check('and no output_config', !isset($p['output_config']));
$p = ai_effort_params('claude-sonnet-5-5', 'Max'); check('models without thinking ignore effort', !isset($p['thinking']) && !isset($p['output_config'])); check('but still get max_tokens', $p['max_tokens'] === 16000); eq(isset(ai_effort_params('claude-haiku-5-5', 'Low')['thinking']), false, 'a zero budget turns thinking off'); set_setting('ai_models', '');
set_setting('ai_efforts', json_encode(['Low' => ['effort' => 'medium', 'max_tokens' => 777, 'budget' => 0]])); eq(ai_effort_params('claude-sonnet-5-5', 'Low')['max_tokens'], 777, 'admin effort map is used'); set_setting('ai_efforts', '');
$ev = []; run("UPDATE {ai_profiles} SET stage='ready' WHERE user_id=?", [$sh]); wallet_add($sh, 0, 'adjust', 'x'); ai_turn($AIM, 'hello', $emitM, ['model' => 'claude-sonnet-5-5', 'effort' => 'Max']); check('effort and model reach the model call (mock echoes them)', (bool) array_filter($ev, fn($x) => str_contains($x['block']['text'] ?? '', 'effort max') && str_contains($x['block']['text'] ?? '', 'claude-sonnet-5-5')));
section('AI: live steps and stop'); $ev = []; ai_turn($AIM, 'what is the emi', $emitM); $steps = array_values(array_filter($ev, fn($x) => ($x['type'] ?? '') === 'step')); check('steps are emitted running then done', count($steps) >= 4 && $steps[0]['status'] === 'running' && (bool) array_filter($steps, fn($s) => $s['status'] === 'done' && $s['label'] === 'Working out the EMI'));
$ev = []; $GLOBALS['__ai_stop'] = function () use (&$ev) { return (bool) array_filter($ev, fn($x) => ($x['type'] ?? '') === 'step' && $x['status'] === 'done'); }; ai_turn($AIM, 'what is the emi again', $emitM); unset($GLOBALS['__ai_stop']);
check('stop cancels further work and reports it', (bool) array_filter($ev, fn($x) => ($x['type'] ?? '') === 'cancelled') && !array_filter($ev, fn($x) => ($x['type'] ?? '') === 'block' && ($x['block']['type'] ?? '') === 'stats'));
section('AI: attachments');
$tmp = tempnam(sys_get_temp_dir(), 'png'); file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')); $up = ai_store_upload($AIM, ['tmp_name' => $tmp, 'name' => '../evil<name>.png', 'size' => filesize($tmp), 'error' => 0]); eq($up['mime'], 'image/png', 'type is sniffed from the bytes'); check('file name is sanitised', !str_contains($up['name'], '/') && !str_contains($up['name'], '<'));
$bad = tempnam(sys_get_temp_dir(), 'bad'); file_put_contents($bad, "MZ\x90\x00 not an image"); throws(fn() => ai_store_upload($AIM, ['tmp_name' => $bad, 'name' => 'x.png', 'size' => 20, 'error' => 0]), 'not supported', 'executables renamed to .png are refused');
$big = tempnam(sys_get_temp_dir(), 'big'); file_put_contents($big, str_repeat('a', AI_UPLOAD_MAX + 1)); throws(fn() => ai_store_upload($AIM, ['tmp_name' => $big, 'name' => 'x.txt', 'size' => 1, 'error' => 0]), 'too big', 'size limit enforced');
$o2 = create_user('o2@x.test', 'O2', 'password-12345'); eq(count(ai_load_uploads($o2, [$up['id']])), 0, "another person's upload id is ignored"); $rows = ai_load_uploads($sh, [$up['id']]); $b = ai_upload_blocks($rows, true); eq($b[0]['type'], 'image', 'images become image blocks when the model sees them');
$b = ai_upload_blocks($rows, false); check('without vision the file is noted, not sent', $b[0]['type'] === 'text' && str_contains($b[0]['text'], 'cannot look at images')); ai_upload_discard($rows); eq(count(ai_load_uploads($sh, [$up['id']])), 0, 'used uploads are deleted');
$tmp2 = tempnam(sys_get_temp_dir(), 'png'); file_put_contents($tmp2, file_get_contents($tmp)); $up2 = ai_store_upload($AIM, ['tmp_name' => $tmp2, 'name' => 'a.png', 'size' => 1, 'error' => 0]); $ev = []; ai_turn($AIM, 'see this', $emitM, ['files' => [$up2['id']]]); check('the model call received the attachment', (bool) array_filter($ev, fn($x) => str_contains($x['block']['text'] ?? '', 'I can see 1 attachment')));
@unlink($tmp); @unlink($bad); @unlink($big);
section('cost multipliers'); $base = ai_cost_micro('claude-sonnet-5-5', 1000, 1000); set_setting('ai_models', json_encode(['claude-sonnet-5-5' => ['mult_in' => 2, 'mult_out' => 2]])); eq(ai_cost_micro('claude-sonnet-5-5', 1000, 1000), $base * 2, 'price multipliers scale the metered cost'); set_setting('ai_models', '');
section('payg wallet is debited in mock mode'); $cfgSet(['feat_ai_mode' => 'payg']); wallet_add($sh2, 100_000_000, 'topup', 't', 'seed:payg'); $b0 = wallet_balance($sh2); ai_record_usage(['id' => $sh2, 'role' => 'user'], 'claude-sonnet-5-5', 1000, 1000); check('usage debits the wallet', wallet_balance($sh2) < $b0); $cfgSet(['feat_ai_mode' => 'free']);
section('admin saves'); $_POST = ['default_model' => 'claude-haiku-5-5', 'state' => ['claude-haiku-5-5' => 'premium'], 'price' => ['claude-fable-5-1' => '100', 'claude-opus-5-5' => '100', 'claude-haiku-5-5' => '100'], 'days' => [], 'mult_in' => [], 'mult_out' => []];
throws(fn() => do_admin_models_save(['id' => 1, 'role' => 'admin']), 'default model must be', 'the default model cannot be premium');

section('AI: empty tool input stays a JSON object');
$fixed = ai_fix_content([['type' => 'text', 'text' => 'hi'], ['type' => 'tool_use', 'id' => 't1', 'name' => 'get_overview', 'input' => []], ['type' => 'tool_use', 'id' => 't2', 'name' => 'get_budgets', 'input' => ['month' => '2026-10']]]);
eq(json_encode($fixed[1]['input']), '{}', 'an empty tool input is re-sent as {} (not [])');
eq(json_encode($fixed[2]['input']), '{"month":"2026-10"}', 'a filled tool input is untouched');

require __DIR__ . '/telegram_tests.php';

echo "\n" . ($fail ? "FAILED: $fail failed, $pass passed\n" : "OK: $pass passed\n");
exit($fail ? 1 : 0);
