<?php
declare(strict_types=1);
/**
 * AI finance advisor. Server-side only: the Anthropic API key never reaches the browser.
 *  - transport: the Messages API over curl, or a scripted fake when MFNC_AI_MOCK=1 (no key needed, used by the tests)
 *  - tools: read-only data tools scoped to the signed-in user, deterministic calculators, a `present` tool that renders UI blocks,
 *    and `propose_action` which only creates a PREVIEW; nothing is written until the person presses Apply
 *  - every write goes through ai_actions (before/after stored) so the AI actions page can undo it exactly
 *  - every API call is metered (tokens in/out x per-model price), checked against the person's monthly budget and wallet, and rate limited
 */

const AI_MODELS = ['claude-fable-5-1' => 'Claude Fable 5.1', 'claude-opus-5-5' => 'Claude Opus 5.5', 'claude-sonnet-5-5' => 'Claude Sonnet 5.5', 'claude-haiku-5-5' => 'Claude Haiku 5.5'];
/** Default list prices in rupees per million tokens [input, output]. Admin can change them; check Anthropic's pricing page. */
const AI_DEFAULT_PRICES = ['claude-fable-5-1' => [1250, 6250], 'claude-opus-5-5' => [1250, 6250], 'claude-sonnet-5-5' => [250, 1250], 'claude-haiku-5-5' => [85, 425]];
const AI_STYLES = ['bottts-neutral' => 'Robot', 'fun-emoji' => 'Emoji', 'thumbs' => 'Thumbs', 'big-smile' => 'Big smile', 'adventurer' => 'Adventurer', 'lorelei' => 'Lorelei', 'notionists' => 'Notionist', 'avataaars-neutral' => 'Friendly'];
const AI_QUESTIONS = [
    ['key' => 'household', 'q' => "Let's start with you. Who is in your household, and who earns? (For example: me, my husband and two kids, both of us work.)"],
    ['key' => 'income', 'q' => "Roughly how much comes in each month in total, and how steady is it? A range is fine. Salary, business, rent, whatever applies."],
    ['key' => 'worry', 'q' => "What is the one money thing that worries you most right now? Be honest, nobody is watching."],
    ['key' => 'debts', 'q' => "Do you have any loans or credit card balances? Tell me the type, the rough amount and the interest rate if you know it. If none, say none."],
    ['key' => 'savings', 'q' => "How much cash and savings do you have set aside for emergencies, and how many months of expenses does that cover?"],
    ['key' => 'goals', 'q' => "What do you want your money to do in the next 1 to 3 years? (A house, children's education, a wedding, retirement, a trip...) Give amounts and dates if you have them."],
    ['key' => 'tax', 'q' => "Tax situation: are you salaried, self-employed or both? Which regime do you file under, and what do you invest in (PPF, ELSS, NPS, insurance, FDs, mutual funds)? Say 'not sure' if you don't know."],
    ['key' => 'habit', 'q' => "Last one. Which money habit do you know you should fix but keep putting off? I'll hold you to it."],
];

// ============================ configuration ============================
function ai_mock(): bool { return (string) getenv('MFNC_AI_MOCK') === '1'; }
function ai_price(string $model): array {
    $d = AI_DEFAULT_PRICES[$model] ?? [250, 1250];
    return [max(0, (float) site_setting("ai_price_in_$model", (string) $d[0])), max(0, (float) site_setting("ai_price_out_$model", (string) $d[1]))];
}
function ai_cfg(): array {
    $model = site_setting('ai_model', 'claude-sonnet-5-5'); if (!isset(AI_MODELS[$model])) { $model = 'claude-sonnet-5-5'; }
    return ['enabled' => site_setting('ai_enabled', '0') === '1', 'key' => secret_get('ai_key', 'ANTHROPIC_API_KEY'), 'model' => $model, 'max_tokens' => max(200, min(8000, (int) site_setting('ai_max_tokens', '1500'))),
        'rate_min' => max(1, (int) site_setting('ai_rate_min', '6')), 'rate_day' => max(1, (int) site_setting('ai_rate_day', '200')), 'default_budget' => max(0, (int) site_setting('ai_default_budget_micro', '0')),
        'prompt' => site_setting('ai_system_prompt', ''), 'base' => rtrim((string) (getenv('MFNC_AI_BASE') ?: 'https://api.anthropic.com'), '/'), 'max_steps' => 7];
}
/** Whether the agent can run at all (switched on and a key or the mock transport). */
function ai_ready(?array $c = null): bool { $c ??= ai_cfg(); return $c['enabled'] && ($c['key'] !== '' || ai_mock()); }
function ai_not_ready_reason(?array $c = null): string {
    $c ??= ai_cfg(); if (!$c['enabled']) { return 'The AI advisor is switched off. Ask the administrator to turn it on.'; }
    return ($c['key'] === '' && !ai_mock()) ? 'The AI advisor is not configured yet: the administrator has not added an API key.' : '';
}
const AI_PROMPT_VARS = ['agent_name' => 'The name the person gave the agent', 'user_name' => "The person's first name", 'today' => "Today's date", 'context' => "What the person told the agent during setup (editable by them)", 'currency' => 'Currency code', 'app' => 'The app name'];
function ai_default_prompt(): string {
    return <<<'P'
You are {agent_name}, the personal finance advisor and chartered-accountant-grade tax guide inside {app}, speaking to {user_name}. Today is {today}. Money is in {currency} (Indian rupees, lakh/crore formatting).

HOW YOU TALK
- Blunt and brutally honest. No sugar-coating, no hedging filler, no "I'm just an AI", no motivational fluff. Say what the numbers say, then what to do about it.
- Short sentences. Lead with the verdict, then the evidence, then the fix. If a habit is bad, call it bad and say what it costs in rupees per year.
- Challenge excuses and bad money habits directly. Praise only what is actually good, in one line.
- If you lack a number, ask one precise question or fetch it with a tool. Never invent balances, transactions, tax rules or returns.

WHAT YOU KNOW
- Personal finance: budgeting, emergency funds, debt payoff (avalanche vs snowball), EMIs, SIPs, insurance, goal planning, inflation.
- Indian tax and CA matters for individuals: old vs new regime, standard deduction, 80C/80D/NPS/HRA/home-loan interest, 87A rebate, advance tax, ITR forms, TDS, capital gains basics, GST basics for small businesses. Tax rules change every Budget: state the financial year you are assuming and tell the person to confirm before filing. For anything complex or high-stakes (notices, assessments, large capital gains, business structuring) say plainly that they need a practising CA, and say what to ask them.

WHAT YOU WILL NOT DO
- You do not help evade tax, hide income, fake invoices or documents, launder money, or dodge a legal obligation. If asked, refuse in one blunt sentence and point to the lawful route (deductions, regime choice, timing, proper investments, filing correctly, voluntary disclosure with a CA). Tax planning is fine; tax evasion is not.

HOW YOU WORK
- Use the data tools to read the person's real accounts, transactions, bills, goals and budgets before giving advice about them.
- Never do arithmetic in your head. Use the calc_* tools for EMI, SIP, payoff and tax comparisons. Their steps are shown to the person automatically.
- Show results with the `present` tool: stats for headline numbers, table for lists, chart for trends or splits, goal_progress for goals, bill_list for bills, comparison for A-vs-B choices, callout for the one thing they must not miss. Keep your own text short and let the components carry the detail. Amounts passed to `present` are plain rupee numbers.
- To change anything in the app call `propose_action`. It only shows a preview. The person presses Apply; every applied action is logged and can be undone. Never claim something is done until the result says it was applied.
- Do not repeat what a component already shows.

WHAT YOU KNOW ABOUT {user_name}
{context}
P;
}
function ai_system_prompt(array $u, array $profile): string {
    $c = ai_cfg(); $tpl = trim($c['prompt']) !== '' ? $c['prompt'] : ai_default_prompt();
    $vars = ['agent_name' => $profile['agent_name'] ?: 'your advisor', 'user_name' => explode(' ', trim((string) $u['name']))[0] ?: 'there', 'today' => today(), 'context' => trim((string) ($profile['context'] ?? '')) ?: '(Nothing recorded yet. Ask when it matters.)', 'currency' => prefs()['currency'] ?? 'INR', 'app' => APP_NAME];
    return render_template($tpl, $vars);
}

// ============================ profile & usage ============================
function ai_profile(int $uid): array {
    $p = one('SELECT * FROM {ai_profiles} WHERE user_id=?', [$uid]);
    if (!$p) { run('INSERT IGNORE INTO {ai_profiles} (user_id) VALUES (?)', [$uid]); $p = one('SELECT * FROM {ai_profiles} WHERE user_id=?', [$uid]); }
    $p['qa_list'] = json_decode((string) $p['qa'], true) ?: []; return $p;
}
function ai_month_spend(int $uid): int { return (int) val("SELECT COALESCE(SUM(cost_micro),0) FROM {ai_usage} WHERE user_id=? AND created_at >= ?", [$uid, gmdate('Y-m-01 00:00:00')]); }
function ai_budget(int $uid): int { $b = val('SELECT ai_budget_micro FROM {users} WHERE id=?', [$uid]); return $b === null ? ai_cfg()['default_budget'] : (int) $b; }
function ai_cost_micro(string $model, int $in, int $out): int { [$pi, $po] = ai_price($model); return (int) ceil($in * $pi + $out * $po); } // tokens x (rupees per 1M tokens) = micro-rupees
/** Everything that can stop a message, in the order the person should hear about it. Returns null when allowed. */
function ai_gate(array $u, bool $rate = true): ?array {
    $c = ai_cfg(); if ($why = ai_not_ready_reason($c)) { return ['code' => 'not_configured', 'message' => $why]; }
    $fa = feature_access($u, 'ai'); if (!$fa['ok']) { return ['code' => $fa['code'] ?: 'plan', 'message' => $fa['reason']]; }
    $uid = (int) $u['id'];
    if (!is_exempt_user($u)) {
        $budget = ai_budget($uid); $spent = ai_month_spend($uid);
        if ($budget > 0 && $spent >= $budget) { return ['code' => 'budget', 'message' => 'You have used your AI budget for this month (' . micro_to_inr($spent) . ' of ' . micro_to_inr($budget) . '). It resets on the 1st, or ask the administrator to raise it.']; }
        if ($fa['mode'] === 'payg' && wallet_balance($uid) <= 0) { return ['code' => 'wallet', 'message' => 'Your wallet is empty. Top it up on the Plans page to keep chatting.']; }
    }
    if (!$rate) { return null; }
    if ((int) val("SELECT COUNT(*) FROM {ai_messages} WHERE user_id=? AND role='user' AND content NOT LIKE '%\"setup\":1%' AND created_at >= ?", [$uid, gmdate('Y-m-d H:i:s', time() - 60)]) >= $c['rate_min']) { return ['code' => 'rate', 'message' => 'Slow down: ' . $c['rate_min'] . ' messages a minute is the limit. Try again in a moment.']; }
    if ((int) val("SELECT COUNT(*) FROM {ai_messages} WHERE user_id=? AND role='user' AND content NOT LIKE '%\"setup\":1%' AND created_at >= ?", [$uid, gmdate('Y-m-d H:i:s', time() - 86400)]) >= $c['rate_day']) { return ['code' => 'rate', 'message' => 'You have reached the daily message limit (' . $c['rate_day'] . '). Try again tomorrow.']; }
    return null;
}
function ai_record_usage(array $u, string $model, int $in, int $out): int {
    $cost = ai_cost_micro($model, $in, $out); $uid = (int) $u['id']; $mode = feature_cfg('ai')['mode']; $src = ai_mock() ? 'mock' : (($mode === 'payg' && !is_exempt_user($u)) ? 'wallet' : 'budget');
    run('INSERT INTO {ai_usage} (user_id,model,input_tokens,output_tokens,cost_micro,source) VALUES (?,?,?,?,?,?)', [$uid, $model, $in, $out, $cost, $src]);
    if ($src === 'wallet' && $cost > 0) { wallet_add($uid, -$cost, 'usage', 'AI: ' . $model . ' ' . $in . '+' . $out . ' tokens'); }
    return $cost;
}

// ============================ tool definitions ============================
function ai_tools(): array {
    $o = fn(array $props = [], array $req = []) => ['type' => 'object', 'properties' => $props ?: new stdClass(), 'required' => $req];
    $n = fn($d) => ['type' => 'number', 'description' => $d]; $s = fn($d) => ['type' => 'string', 'description' => $d]; $i = fn($d) => ['type' => 'integer', 'description' => $d];
    return [
        ['name' => 'get_overview', 'description' => "The person's money at a glance: cash by account, this month's income/expenses/net, amounts reserved for goals and bills, overdue bills.", 'input_schema' => $o()],
        ['name' => 'list_transactions', 'description' => 'Search transactions (newest first). Amounts in rupees.', 'input_schema' => $o(['from' => $s('YYYY-MM-DD'), 'to' => $s('YYYY-MM-DD'), 'type' => ['type' => 'string', 'enum' => ['income', 'expense', 'transfer', 'refund', 'adjustment']], 'category' => $s('category name contains'), 'q' => $s('text in description'), 'min_amount' => $n('rupees'), 'limit' => $i('max 60, default 25')])],
        ['name' => 'spending_by_category', 'description' => 'Expense totals per category for a month (YYYY-MM, default this month) with the previous month for comparison.', 'input_schema' => $o(['month' => $s('YYYY-MM')])],
        ['name' => 'monthly_report', 'description' => 'Income, expenses and net savings for each of the last N months (default 6, max 24).', 'input_schema' => $o(['months' => $i('1-24')])],
        ['name' => 'list_bills', 'description' => 'Recurring bills with upcoming/overdue occurrences.', 'input_schema' => $o()],
        ['name' => 'list_goals', 'description' => 'Savings goals with saved amount, target, monthly plan and whether they are behind.', 'input_schema' => $o()],
        ['name' => 'get_budgets', 'description' => "Each budgeted category: budget, spent and remaining for a month.", 'input_schema' => $o(['month' => $s('YYYY-MM')])],
        ['name' => 'list_accounts', 'description' => 'Accounts (ids needed for actions) with balances, and categories (ids needed for budgets).', 'input_schema' => $o()],
        ['name' => 'calc_emi', 'description' => 'Exact EMI for a loan, with total interest.', 'input_schema' => $o(['principal' => $n('rupees'), 'annual_rate_pct' => $n('% per year'), 'months' => $i('tenure in months')], ['principal', 'annual_rate_pct', 'months'])],
        ['name' => 'calc_sip', 'description' => 'Future value of a monthly SIP (optional yearly step-up %), or the monthly SIP needed to reach a target if target is given.', 'input_schema' => $o(['monthly' => $n('rupees per month (ignored when target is given)'), 'annual_return_pct' => $n('expected % per year'), 'years' => $n('years'), 'step_up_pct' => $n('yearly increase of the SIP, %'), 'target' => $n('target corpus in rupees')], ['annual_return_pct', 'years'])],
        ['name' => 'calc_lumpsum', 'description' => 'Compound growth of a one-time investment.', 'input_schema' => $o(['amount' => $n('rupees'), 'annual_return_pct' => $n('%'), 'years' => $n('years')], ['amount', 'annual_return_pct', 'years'])],
        ['name' => 'calc_payoff', 'description' => 'Debt payoff plan: months and total interest for a fixed monthly payment, and the saving from paying extra.', 'input_schema' => $o(['balance' => $n('outstanding rupees'), 'annual_rate_pct' => $n('% per year'), 'monthly_payment' => $n('rupees'), 'extra' => $n('extra rupees per month to test')], ['balance', 'annual_rate_pct', 'monthly_payment'])],
        ['name' => 'calc_tax', 'description' => 'Compare India income tax under the new and old regimes (FY 2025-26 individual slabs).', 'input_schema' => $o(['annual_income' => $n('gross annual income in rupees'), 'old_regime_deductions' => $n('total 80C+80D+HRA+home-loan interest+NPS etc. claimable in the old regime'), 'salaried' => ['type' => 'boolean']], ['annual_income'])],
        ['name' => 'present', 'description' => 'Show components to the person (rendered in the chat). Use it for results instead of long text. blocks[] items by type: stats{items:[{label,value,sub?,tone?}]}, table{title?,columns:[string|{label,type:money|text|pct|number}],rows:[[...]]}, chart{title?,kind:bar|line|donut,labels:[..],series:[{name,values:[..]}],money?:bool}, goal_progress{items:[{name,saved,target,note?}]}, bill_list{items:[{name,due,amount,status:overdue|upcoming|paid}]}, comparison{title?,labels:[A,B],rows:[{label,values:[..],better?:0|1}],verdict?}, callout{tone:good|warn|bad,text}, text{text}. Money values are plain rupee numbers; a column or stat with type money is formatted as rupees.', 'input_schema' => $o(['blocks' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'components in display order']], ['blocks'])],
        ['name' => 'propose_action', 'description' => 'Propose a change. Shows the person a preview with Apply / Dismiss. Nothing changes until they apply it. kinds: create_transaction{type,amount,date?,account_id,category_id?,description?}, set_budget{category_id,amount} (0 removes), add_goal{name,target_amount,target_date?,monthly_target?}, pay_bill{occurrence_id,amount,account_id,date?}, contribute_goal{goal_id,amount,account_id?}. Get ids from list_accounts / list_bills / list_goals first.', 'input_schema' => $o(['kind' => ['type' => 'string', 'enum' => ['create_transaction', 'set_budget', 'add_goal', 'pay_bill', 'contribute_goal']], 'params' => ['type' => 'object'], 'reason' => $s('one short sentence: why')], ['kind', 'params'])],
    ];
}
function rup(int $minor): float { return round($minor / 100, 2); }

/** Runs one tool for the user. $ui collects blocks that must be shown to the person (calc steps, previews, presented components). */
function ai_run_tool(array $u, string $name, array $in, array &$ui, ?array &$snap = null): array {
    $uid = (int) $u['id']; $snap ??= load_snapshot($uid); $s = $snap; $today = $s['today'];
    $num = fn($k, $d = 0.0) => isset($in[$k]) && is_numeric($in[$k]) ? (float) $in[$k] : $d;
    switch ($name) {
        case 'get_overview': {
            $m = month_of($today); $sum = summarize_month($s['accounts'], $s['txs'], $s['contribs'], $m); $od = 0; $odAmt = 0;
            foreach (occurrence_views($s) as $o) { if ($o['overdue'] && !$o['skipped'] && $o['outstanding'] > 0) { $od++; $odAmt += $o['outstanding']; } }
            return ['month' => $m, 'total_cash' => rup(total_cash($s['accounts'], $s['txs'], $today)), 'reserved_for_goals_and_bills' => rup(reserved_total($s['contribs'])), 'income_this_month' => rup($sum['income']), 'expenses_this_month' => rup($sum['expenses']), 'net_this_month' => rup($sum['net']),
                'accounts' => array_map(fn($a) => ['id' => (int) $a['id'], 'name' => $a['name'], 'type' => $a['type'], 'balance' => rup((int) $a['balance'])], active_accounts($s)), 'overdue_bills' => $od, 'overdue_amount' => rup($odAmt), 'goals' => count($s['goals'])];
        }
        case 'list_accounts': {
            return ['accounts' => array_map(fn($a) => ['id' => (int) $a['id'], 'name' => $a['name'], 'type' => $a['type'], 'balance' => rup((int) $a['balance'])], active_accounts($s)),
                'expense_categories' => array_values(array_map(fn($c) => ['id' => (int) $c['id'], 'name' => cat_path($s, (int) $c['id'])], array_filter($s['categories'], fn($c) => $c['grp'] !== 'income'))),
                'income_categories' => array_values(array_map(fn($c) => ['id' => (int) $c['id'], 'name' => $c['name']], array_filter($s['categories'], fn($c) => $c['grp'] === 'income')))];
        }
        case 'list_transactions': {
            $lim = max(1, min(60, (int) ($in['limit'] ?? 25))); $from = (string) ($in['from'] ?? ''); $to = (string) ($in['to'] ?? ''); $min = $num('min_amount') * 100; $q = mb_strtolower((string) ($in['q'] ?? '')); $cat = mb_strtolower((string) ($in['category'] ?? '')); $ty = (string) ($in['type'] ?? '');
            $out = []; $by = array_column($s['accounts'], 'name', 'id');
            foreach ($s['txs'] as $t) {
                if ($t['status'] !== 'completed' || ($from !== '' && $t['date'] < $from) || ($to !== '' && $t['date'] > $to) || ($ty !== '' && $t['type'] !== $ty) || abs($t['amount']) < $min) { continue; }
                if ($q !== '' && !str_contains(mb_strtolower($t['description']), $q)) { continue; } $cp = cat_path($s, $t['category_id'] ? (int) $t['category_id'] : null); if ($cat !== '' && !str_contains(mb_strtolower($cp), $cat)) { continue; }
                $out[] = ['id' => (int) $t['id'], 'date' => $t['date'], 'type' => $t['type'], 'amount' => rup((int) $t['amount']), 'description' => $t['description'], 'category' => $cp, 'account' => $by[$t['account_id']] ?? ''];
                if (count($out) >= $lim) { break; }
            }
            return ['count' => count($out), 'transactions' => $out];
        }
        case 'spending_by_category': {
            $m = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($in['month'] ?? '')) ? $in['month'] : month_of($today); $cur = category_breakdown($s, $m); $prev = array_column(category_breakdown($s, add_months($m, -1)), 'value', 'name'); $tot = array_sum(array_column($cur, 'value'));
            return ['month' => $m, 'total' => rup($tot), 'categories' => array_map(fn($r) => ['category' => $r['name'], 'amount' => rup((int) $r['value']), 'share_pct' => $tot ? round($r['value'] / $tot * 100, 1) : 0, 'previous_month' => rup((int) ($prev[$r['name']] ?? 0))], array_slice($cur, 0, 15))];
        }
        case 'monthly_report': {
            $k = max(1, min(24, (int) ($in['months'] ?? 6))); return ['months' => array_map(fn($r) => ['month' => $r['month'], 'income' => rup((int) $r['income']), 'expenses' => rup((int) $r['expenses']), 'net' => rup((int) $r['net'])], month_series($s, $k))];
        }
        case 'list_bills': {
            $o = []; foreach (occurrence_views($s) as $v) { if ($v['skipped'] || $v['outstanding'] <= 0 || $v['days'] > 60) { continue; } $o[] = ['occurrence_id' => (int) $v['id'], 'bill' => $v['bill']['name'], 'due' => $v['due_date'], 'amount' => rup((int) $v['amount']), 'outstanding' => rup((int) $v['outstanding']), 'status' => $v['overdue'] ? 'overdue' : 'upcoming', 'days' => $v['days']]; }
            return ['bills' => $o, 'recurring' => array_map(fn($b) => ['name' => $b['name'], 'amount' => rup((int) $b['amount']), 'frequency' => $b['frequency'], 'kind' => $b['kind']], array_values(array_filter($s['bills'], fn($b) => $b['active'])))];
        }
        case 'list_goals': {
            return ['goals' => array_map(fn($g) => ['id' => (int) $g['id'], 'name' => $g['name'], 'kind' => $g['kind'], 'target' => rup((int) $g['target_amount']), 'saved' => rup((int) $g['saved']), 'remaining' => rup((int) $g['remaining']), 'percent' => round($g['percent'], 1), 'monthly_plan' => rup((int) $g['monthly_target']), 'target_date' => $g['target_date'], 'behind' => (bool) $g['behind']], goal_views($s))];
        }
        case 'get_budgets': {
            $m = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($in['month'] ?? '')) ? $in['month'] : month_of($today);
            return ['month' => $m, 'budgets' => array_map(fn($b) => ['category_id' => $b['id'], 'category' => $b['name'], 'budget' => rup($b['budget']), 'spent' => rup($b['spent']), 'remaining' => rup($b['remaining'])], budget_vs_actual($s, $m))];
        }
        case 'calc_emi': { $r = calc_emi($num('principal'), $num('annual_rate_pct'), (int) $num('months')); $ui[] = ['type' => 'calc', 'title' => 'EMI: ' . inr_s($num('principal')) . ' at ' . $num('annual_rate_pct') . '% for ' . (int) $num('months') . ' months', 'steps' => $r['steps'], 'result' => ['label' => 'Monthly EMI', 'value' => inr_s($r['emi'])]]; return $r; }
        case 'calc_sip': {
            $r = $num('target') > 0 ? calc_sip_needed($num('target'), $num('annual_return_pct'), $num('years')) : calc_sip($num('monthly'), $num('annual_return_pct'), $num('years'), $num('step_up_pct'));
            $ui[] = ['type' => 'calc', 'title' => $num('target') > 0 ? 'SIP needed for ' . inr_s($num('target')) : 'SIP of ' . inr_s($num('monthly')) . ' a month', 'steps' => $r['steps'], 'result' => isset($r['monthly_needed']) ? ['label' => 'Monthly SIP needed', 'value' => inr_s($r['monthly_needed'])] : ['label' => 'Value after ' . $num('years') . ' years', 'value' => inr_s($r['future_value'])]]; return $r;
        }
        case 'calc_lumpsum': { $r = calc_lumpsum($num('amount'), $num('annual_return_pct'), $num('years')); $ui[] = ['type' => 'calc', 'title' => 'Lump sum growth', 'steps' => $r['steps'], 'result' => ['label' => 'Future value', 'value' => inr_s($r['future_value'])]]; return $r; }
        case 'calc_payoff': { $r = calc_payoff($num('balance'), $num('annual_rate_pct'), $num('monthly_payment'), $num('extra')); $ui[] = ['type' => 'calc', 'title' => 'Debt payoff plan', 'steps' => $r['steps'], 'result' => ['label' => $r['possible'] ? 'Debt-free in' : 'Result', 'value' => $r['possible'] ? $r['months'] . ' months' : 'Never at this payment']]; return $r; }
        case 'calc_tax': { $r = calc_tax_regimes($num('annual_income'), $num('old_regime_deductions'), !isset($in['salaried']) || (bool) $in['salaried']); $ui[] = ['type' => 'calc', 'title' => 'Income tax: new vs old regime', 'steps' => $r['steps'], 'result' => ['label' => 'Cheaper', 'value' => $r[$r['better']]['name'] . ' by ' . inr_s($r['saving'])]]; return $r; }
        case 'present': {
            $blocks = is_array($in['blocks'] ?? null) ? $in['blocks'] : []; $n = 0; foreach ($blocks as $b) { if (is_array($b) && ($c = ai_clean_block($b))) { $ui[] = $c; $n++; } }
            return ['displayed' => $n, 'note' => $n ? 'Shown to the person.' : 'Nothing valid to show.'];
        }
        case 'propose_action': return ai_propose($u, $s, (string) ($in['kind'] ?? ''), is_array($in['params'] ?? null) ? $in['params'] : [], (string) ($in['reason'] ?? ''), $ui);
    }
    throw new UserError("Unknown tool $name.");
}

// ============================ UI blocks ============================
function ai_str($v, int $max = 200): string { return mb_substr(trim(is_scalar($v) ? (string) $v : ''), 0, $max); }
function ai_num($v): ?float { return is_numeric($v) ? (float) $v : null; }
/** Whitelists and clamps what the model asked us to show. The browser renders these with textContent only. */
function ai_clean_block(array $b): ?array {
    $t = ai_str($b['type'] ?? '', 20);
    switch ($t) {
        case 'text': $x = ai_str($b['text'] ?? '', 4000); return $x === '' ? null : ['type' => 'text', 'text' => $x];
        case 'callout': $x = ai_str($b['text'] ?? '', 600); return $x === '' ? null : ['type' => 'callout', 'tone' => in_array($b['tone'] ?? '', ['good', 'warn', 'bad'], true) ? $b['tone'] : 'warn', 'text' => $x];
        case 'stats': $it = []; foreach (array_slice((array) ($b['items'] ?? []), 0, 8) as $i) { if (!is_array($i)) { continue; } $v = $i['value'] ?? ''; $it[] = ['label' => ai_str($i['label'] ?? '', 40), 'value' => is_numeric($v) ? (float) $v : ai_str($v, 40), 'type' => ($i['type'] ?? '') === 'money' ? 'money' : (($i['type'] ?? '') === 'pct' ? 'pct' : 'text'), 'sub' => ai_str($i['sub'] ?? '', 80), 'tone' => in_array($i['tone'] ?? '', ['good', 'bad', 'warn'], true) ? $i['tone'] : '']; } return $it ? ['type' => 'stats', 'items' => $it] : null;
        case 'table':
            $cols = []; foreach (array_slice((array) ($b['columns'] ?? []), 0, 8) as $c) { $cols[] = is_array($c) ? ['label' => ai_str($c['label'] ?? '', 40), 'type' => in_array($c['type'] ?? '', ['money', 'pct', 'number', 'text'], true) ? $c['type'] : 'text'] : ['label' => ai_str($c, 40), 'type' => 'text']; }
            $rows = []; foreach (array_slice((array) ($b['rows'] ?? []), 0, 40) as $r) { if (!is_array($r)) { continue; } $rows[] = array_map(fn($v) => is_numeric($v) ? (float) $v : ai_str($v, 80), array_slice(array_values($r), 0, count($cols))); }
            return $cols && $rows ? ['type' => 'table', 'title' => ai_str($b['title'] ?? '', 80), 'columns' => $cols, 'rows' => $rows] : null;
        case 'chart':
            $labels = array_map(fn($v) => ai_str($v, 20), array_slice(array_values((array) ($b['labels'] ?? [])), 0, 36)); $ser = [];
            foreach (array_slice((array) ($b['series'] ?? []), 0, 4) as $sx) { if (is_array($sx)) { $ser[] = ['name' => ai_str($sx['name'] ?? '', 30), 'values' => array_map(fn($v) => ai_num($v) ?? 0.0, array_slice(array_values((array) ($sx['values'] ?? [])), 0, count($labels)))]; } }
            return $labels && $ser ? ['type' => 'chart', 'title' => ai_str($b['title'] ?? '', 80), 'kind' => in_array($b['kind'] ?? '', ['bar', 'line', 'donut'], true) ? $b['kind'] : 'bar', 'labels' => $labels, 'series' => $ser, 'money' => !empty($b['money'])] : null;
        case 'goal_progress': $it = []; foreach (array_slice((array) ($b['items'] ?? []), 0, 10) as $i) { if (is_array($i) && ($tg = ai_num($i['target'] ?? null)) !== null && $tg > 0) { $sv = max(0, ai_num($i['saved'] ?? 0) ?? 0); $it[] = ['name' => ai_str($i['name'] ?? '', 60), 'saved' => $sv, 'target' => $tg, 'pct' => round(min(100, $sv / $tg * 100), 1), 'note' => ai_str($i['note'] ?? '', 120)]; } } return $it ? ['type' => 'goal_progress', 'items' => $it] : null;
        case 'bill_list': $it = []; foreach (array_slice((array) ($b['items'] ?? []), 0, 15) as $i) { if (is_array($i)) { $it[] = ['name' => ai_str($i['name'] ?? '', 60), 'due' => ai_str($i['due'] ?? '', 20), 'amount' => ai_num($i['amount'] ?? 0) ?? 0, 'status' => in_array($i['status'] ?? '', ['overdue', 'upcoming', 'paid'], true) ? $i['status'] : 'upcoming']; } } return $it ? ['type' => 'bill_list', 'items' => $it] : null;
        case 'comparison':
            $labels = array_map(fn($v) => ai_str($v, 30), array_slice(array_values((array) ($b['labels'] ?? [])), 0, 3)); $rows = [];
            foreach (array_slice((array) ($b['rows'] ?? []), 0, 14) as $r) { if (is_array($r)) { $rows[] = ['label' => ai_str($r['label'] ?? '', 60), 'values' => array_map(fn($v) => is_numeric($v) ? (float) $v : ai_str($v, 60), array_slice(array_values((array) ($r['values'] ?? [])), 0, count($labels))), 'better' => isset($r['better']) && is_numeric($r['better']) ? (int) $r['better'] : null, 'money' => !empty($r['money'])]; } }
            return count($labels) >= 2 && $rows ? ['type' => 'comparison', 'title' => ai_str($b['title'] ?? '', 80), 'labels' => $labels, 'rows' => $rows, 'verdict' => ai_str($b['verdict'] ?? '', 300)] : null;
    }
    return null;
}

// ============================ actions (preview -> apply -> undo) ============================
function ai_log_action(int $uid, string $kind, string $summary, array $params, array $before, string $status = 'proposed'): int {
    return insert('INSERT INTO {ai_actions} (user_id,kind,summary,params,before_json,status) VALUES (?,?,?,?,?,?)', [$uid, $kind, mb_substr($summary, 0, 300), json_encode($params, JSON_UNESCAPED_UNICODE), json_encode($before, JSON_UNESCAPED_UNICODE), $status]);
}
/** Validates a proposed change and stores it as a preview. Never writes financial data. */
function ai_propose(array $u, array $s, string $kind, array $p, string $reason, array &$ui): array {
    $uid = (int) $u['id']; $money = fn($k) => (int) round(((float) ($p[$k] ?? 0)) * 100); $acct = array_column($s['accounts'], 'name', 'id'); $date = valid_date((string) ($p['date'] ?? '')) ? $p['date'] : $s['today'];
    $before = []; $after = []; $clean = [];
    switch ($kind) {
        case 'create_transaction':
            $type = in_array($p['type'] ?? '', ['income', 'expense'], true) ? $p['type'] : throw new UserError('Only income or expense transactions can be proposed.'); $amt = $money('amount'); if ($amt <= 0) { throw new UserError('The amount must be above zero.'); }
            $aid = (int) ($p['account_id'] ?? 0); if (!isset($acct[$aid])) { throw new UserError('Unknown account. Call list_accounts for valid ids.'); } $cid = !empty($p['category_id']) ? (int) $p['category_id'] : null; if ($cid && !isset(cat_by_id($s)[$cid])) { throw new UserError('Unknown category id.'); }
            $clean = ['type' => $type, 'amount' => $amt, 'date' => $date, 'account_id' => $aid, 'category_id' => $cid, 'description' => ai_str($p['description'] ?? '', 200)]; $bal = (int) array_column($s['accounts'], 'balance', 'id')[$aid];
            $before = ['account' => $acct[$aid], 'balance' => $bal / 100]; $after = ['balance' => ($bal + ($type === 'income' ? $amt : -$amt)) / 100]; $title = ucfirst($type) . ' ' . inr_s($amt / 100) . ' · ' . ($clean['description'] ?: ($cid ? cat_path($s, $cid) : 'no description')) . ' · ' . $acct[$aid]; break;
        case 'set_budget':
            $cid = (int) ($p['category_id'] ?? 0); $c = cat_by_id($s)[$cid] ?? throw new UserError('Unknown category id.'); $amt = $money('amount'); if ($amt < 0) { throw new UserError('Budget cannot be negative.'); }
            $clean = ['category_id' => $cid, 'amount' => $amt]; $old = $s['budgets'][$cid] ?? null; $before = ['budget' => $old === null ? null : $old / 100]; $after = ['budget' => $amt / 100]; $title = ($amt ? 'Set budget for ' . $c['name'] . ' to ' . inr_s($amt / 100) : 'Remove budget for ' . $c['name']) . ($old !== null ? ' (was ' . inr_s($old / 100) . ')' : ''); break;
        case 'add_goal':
            $name = ai_str($p['name'] ?? '', 80); $tg = $money('target_amount'); if ($name === '' || $tg <= 0) { throw new UserError('A goal needs a name and a target amount.'); }
            $td = valid_date((string) ($p['target_date'] ?? '')) ? $p['target_date'] : null; $clean = ['name' => $name, 'target_amount' => $tg, 'target_date' => $td, 'monthly_target' => $money('monthly_target')]; $before = ['goal' => null]; $after = ['goal' => $name, 'target' => $tg / 100];
            $title = 'New goal: ' . $name . ' · ' . inr_s($tg / 100) . ($td ? ' by ' . date_label($td) : '') . ($clean['monthly_target'] ? ' · ' . inr_s($clean['monthly_target'] / 100) . '/month' : ''); break;
        case 'pay_bill':
            $oid = (int) ($p['occurrence_id'] ?? 0); $ov = null; foreach (occurrence_views($s) as $v) { if ((int) $v['id'] === $oid) { $ov = $v; } } if (!$ov) { throw new UserError('Unknown bill occurrence. Call list_bills for valid ids.'); }
            $amt = $money('amount') ?: (int) $ov['outstanding']; if ($amt <= 0 || $amt > $ov['outstanding']) { throw new UserError('That is more than the amount still due (' . inr_s($ov['outstanding'] / 100) . ').'); } $aid = (int) ($p['account_id'] ?? 0); if (!isset($acct[$aid])) { throw new UserError('Unknown account.'); }
            $clean = ['occurrence_id' => $oid, 'amount' => $amt, 'account_id' => $aid, 'date' => $date]; $before = ['outstanding' => $ov['outstanding'] / 100]; $after = ['outstanding' => ($ov['outstanding'] - $amt) / 100]; $title = 'Pay ' . $ov['bill']['name'] . ' ' . inr_s($amt / 100) . ' from ' . $acct[$aid]; break;
        case 'contribute_goal':
            $gid = (int) ($p['goal_id'] ?? 0); $g = null; foreach (goal_views($s) as $x) { if ((int) $x['id'] === $gid) { $g = $x; } } if (!$g) { throw new UserError('Unknown goal id.'); } $amt = $money('amount'); if ($amt <= 0) { throw new UserError('The amount must be above zero.'); }
            $aid = !empty($p['account_id']) ? (int) $p['account_id'] : null; if ($aid && !isset($acct[$aid])) { throw new UserError('Unknown account.'); } $clean = ['goal_id' => $gid, 'amount' => $amt, 'account_id' => $aid, 'date' => $date]; $before = ['saved' => $g['saved'] / 100]; $after = ['saved' => ($g['saved'] + $amt) / 100]; $title = 'Add ' . inr_s($amt / 100) . ' to ' . $g['name']; break;
        default: throw new UserError('Unknown action kind.');
    }
    $id = ai_log_action($uid, $kind, $title, $clean, $before);
    $ui[] = ['type' => 'preview', 'action_id' => $id, 'kind' => $kind, 'title' => $title, 'reason' => ai_str($reason, 200), 'before' => $before, 'after' => $after, 'status' => 'proposed'];
    return ['status' => 'preview_shown', 'action_id' => $id, 'note' => 'The person sees a preview with Apply and Dismiss. It is NOT applied yet. Do not say it is done.'];
}
function ai_action_row(int $uid, int $id): array { $a = one('SELECT * FROM {ai_actions} WHERE id=? AND user_id=?', [$id, $uid]); if (!$a) { throw new UserError('Action not found.'); } return $a; }
/** Applies a previewed action exactly as proposed, records what it created, and returns a one-line result. */
function ai_apply(int $uid, int $id): string {
    $a = ai_action_row($uid, $id); if ($a['status'] !== 'proposed') { throw new UserError($a['status'] === 'applied' ? 'That change was already applied.' : 'That proposal is no longer open.'); }
    $p = json_decode((string) $a['params'], true) ?: []; $after = [];
    if ($a['kind'] === 'set_budget') { $cur = val('SELECT amount FROM {budgets} WHERE user_id=? AND category_id=?', [$uid, (int) $p['category_id']]); run('UPDATE {ai_actions} SET before_json=? WHERE id=?', [json_encode(['budget' => $cur === null ? null : (int) $cur / 100]), $id]); } // the value being replaced right now, so undo restores exactly that
    try {
        tx(function () use ($uid, $a, $p, &$after) {
            switch ($a['kind']) {
                case 'create_transaction': $tid = create_transaction($uid, ['type' => $p['type'], 'amount' => $p['amount'], 'date' => $p['date'], 'account_id' => $p['account_id'], 'category_id' => $p['category_id'], 'description' => $p['description'] ?: 'Added by advisor']); $after = ['transaction_id' => $tid]; break;
                case 'set_budget': set_budget($uid, (int) $p['category_id'], (int) $p['amount']); $after = ['category_id' => (int) $p['category_id']]; break;
                case 'add_goal': $gid = save_goal($uid, null, ['kind' => 'long-term', 'name' => $p['name'], 'target_amount' => $p['target_amount'], 'target_date' => $p['target_date'], 'priority' => 'medium', 'monthly_target' => $p['monthly_target'] ?? 0, 'monthly_limit' => 0, 'notes' => 'Added by advisor']); $after = ['goal_id' => $gid]; break;
                case 'pay_bill': pay_occurrence($uid, (int) $p['occurrence_id'], (int) $p['amount'], $p['date'], (int) $p['account_id'], false); $tid = (int) val('SELECT MAX(id) FROM {transactions} WHERE user_id=? AND occurrence_id=?', [$uid, $p['occurrence_id']]); $after = ['transaction_id' => $tid]; break;
                case 'contribute_goal': $cid = add_contribution($uid, 'goal', (int) $p['goal_id'], (int) $p['amount'], $p['date'], $p['account_id'] ?: null, 'Added by advisor'); $after = ['contribution_id' => $cid]; break;
                default: throw new UserError('Unknown action.');
            }
        });
    } catch (UserError $e) { run("UPDATE {ai_actions} SET status='failed', error=? WHERE id=?", [mb_substr($e->getMessage(), 0, 300), $id]); throw $e; }
    run("UPDATE {ai_actions} SET status='applied', after_json=? WHERE id=?", [json_encode($after), $id]);
    audit('ai.apply', "action#$id", $a['summary']); return 'Done: ' . $a['summary'];
}
function ai_dismiss(int $uid, int $id): void { $a = ai_action_row($uid, $id); if ($a['status'] === 'proposed') { run("UPDATE {ai_actions} SET status='dismissed' WHERE id=?", [$id]); } }
/** Reverses an applied action using the ids and values stored when it ran. */
function ai_undo(int $uid, int $id): string {
    $a = ai_action_row($uid, $id); if ($a['status'] !== 'applied') { throw new UserError('Only an applied action can be undone.'); }
    $after = json_decode((string) $a['after_json'], true) ?: []; $before = json_decode((string) $a['before_json'], true) ?: []; $p = json_decode((string) $a['params'], true) ?: [];
    tx(function () use ($uid, $a, $after, $before, $p) {
        switch ($a['kind']) {
            case 'create_transaction': case 'pay_bill': if (!empty($after['transaction_id']) && one('SELECT id FROM {transactions} WHERE id=? AND user_id=?', [$after['transaction_id'], $uid])) { delete_transaction($uid, (int) $after['transaction_id']); } break;
            case 'set_budget': $old = $before['budget'] ?? null; set_budget($uid, (int) $p['category_id'], $old === null ? 0 : (int) round($old * 100)); break;
            case 'add_goal': if (!empty($after['goal_id']) && one('SELECT id FROM {goals} WHERE id=? AND user_id=?', [$after['goal_id'], $uid])) {
                if ((int) val("SELECT COUNT(*) FROM {contributions} WHERE user_id=? AND target_type='goal' AND target_id=?", [$uid, $after['goal_id']]) > 0) { throw new UserError('Money was added to this goal after it was created. Remove those entries first, then undo.'); } delete_goal($uid, (int) $after['goal_id']); } break;
            case 'contribute_goal': if (!empty($after['contribution_id']) && one('SELECT id FROM {contributions} WHERE id=? AND user_id=?', [$after['contribution_id'], $uid])) { delete_contribution($uid, (int) $after['contribution_id']); } break;
        }
        run("UPDATE {ai_actions} SET status='undone', undone_at=UTC_TIMESTAMP() WHERE id=?", [$a['id']]);
    });
    audit('ai.undo', "action#$id", $a['summary']); return 'Undone: ' . $a['summary'];
}
