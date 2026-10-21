<?php
declare(strict_types=1);
/** Admin: AI agent settings, plans & usage dashboard; and the person-facing Plans & wallet page. */

function page_admin_ai(): void {
    $u = require_admin(); admin_start('AI agent', 'ai'); $c = ai_cfg();
    $stat = !$c['enabled'] ? badge('Off', 'warn') : (ai_ready($c) ? badge(ai_mock() && $c['key'] === '' ? 'On (mock mode)' : 'On', 'pos') : badge('No API key', 'neg'));
    $prompt = trim($c['prompt']) !== '' ? $c['prompt'] : ai_default_prompt(); $models = []; foreach (AI_MODELS as $k => $l) { $models[$k] = $l . ($k === 'claude-sonnet-5-5' ? ' (default)' : ''); }
    $prices = '<div class="col-span-2"><p class="label">Price per million tokens (₹) — used for metering, budgets and wallets</p><div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-left text-xs text-mute"><th class="py-1 pr-2">Model</th><th class="py-1 pr-2">Input ₹</th><th class="py-1">Output ₹</th></tr></thead><tbody>';
    foreach (AI_MODELS as $m => $l) { [$pi, $po] = ai_price($m); $prices .= '<tr><td class="py-1 pr-2">' . e($l) . '</td><td class="py-1 pr-2"><input class="input !h-10 w-24" inputmode="decimal" name="price_in_' . e($m) . '" value="' . e((string) $pi) . '" aria-label="' . e($l) . ' input price"></td><td class="py-1"><input class="input !h-10 w-24" inputmode="decimal" name="price_out_' . e($m) . '" value="' . e((string) $po) . '" aria-label="' . e($l) . ' output price"></td></tr>'; }
    $prices .= '</tbody></table></div><p class="mt-1 text-xs text-mute">Check Anthropic’s pricing page for the current USD rates and convert. Cost = tokens × price ÷ 1,000,000.</p></div>';
    echo '<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]"><div class="space-y-5">';
    card_open('AI finance advisor', 'A blunt personal finance and tax advisor that reads the person’s own data. ' . $stat);
    echo form_open('admin-ai-settings') . form_fields([
        ['name' => 'ai_enabled', 'label' => 'Enable the AI advisor for users', 'type' => 'checkbox', 'value' => $c['enabled']],
        secret_field('ai_key', 'Anthropic API key', $c['key'], 'Stored encrypted on the server and only ever used server-side. Or set ANTHROPIC_API_KEY in the environment.'),
        ['name' => 'ai_key_clear', 'label' => 'Remove the saved key', 'type' => 'checkbox', 'value' => false],
        ['name' => 'ai_model', 'label' => 'Model', 'type' => 'select', 'required' => true, 'options' => $models, 'value' => $c['model']],
        ['name' => 'ai_max_tokens', 'label' => 'Max output tokens per reply', 'type' => 'number', 'min' => 200, 'max' => 8000, 'half' => true, 'value' => $c['max_tokens']],
        ['name' => 'ai_default_budget', 'label' => 'Default monthly AI budget per user (₹, 0 = no cap)', 'type' => 'money', 'currency' => '₹', 'half' => true, 'value' => micro_input($c['default_budget'])],
        ['name' => 'ai_rate_min', 'label' => 'Messages per minute per user', 'type' => 'number', 'min' => 1, 'max' => 120, 'half' => true, 'value' => $c['rate_min']],
        ['name' => 'ai_rate_day', 'label' => 'Messages per day per user', 'type' => 'number', 'min' => 1, 'max' => 5000, 'half' => true, 'value' => $c['rate_day']],
    ]) . $prices . '<div class="col-span-2"><label class="label" for="sp">System prompt</label><textarea id="sp" name="ai_system_prompt" rows="16" class="input font-mono text-xs" maxlength="12000">' . e($prompt) . '</textarea>'
        . vars_help(AI_PROMPT_VARS) . '<p class="mt-1 text-xs text-mute">' . (trim($c['prompt']) === '' ? 'Using the built-in default.' : 'Customised.') . ' The advisor’s tools, safety rules about tax evasion and the “preview before apply” rule are described here: keep them if you edit.</p></div>'
        . '<div class="col-span-2 flex flex-wrap justify-end gap-2"><button class="btn btn-dark">Save AI settings</button></div></form>';
    echo '<div class="mt-3 flex flex-wrap gap-2 border-t border-line pt-4">' . post_btn('Reset system prompt to default', 'admin-ai-prompt-reset', [], 'btn btn-sm') . post_btn(icon('flask-conical', 'size-4') . ' Test connection', 'admin-ai-test', [], 'btn btn-sm') . '</div>';
    if (!ai_ready($c)) { echo '<p class="mt-3 rounded-2xl bg-soft p-3 text-sm text-mute">Not configured: users see “the AI advisor is not configured yet”. Set <code>MFNC_AI_MOCK=1</code> in the environment to try the whole flow with a fake model and no key.</p>'; }
    card_close(); echo '</div><div class="space-y-5">';
    card_flush_open('How it behaves', ''); echo '<ul class="space-y-2 px-6 pb-5 text-sm text-mute"><li>• On first use it interviews the person (8 questions), writes an editable profile, and asks them to name it.</li><li>• It reads data through server-side tools scoped to that person and shows results as tables, charts, stat cards, goal and bill lists and calculators.</li>'
        . '<li>• It can only <strong class="text-ink">propose</strong> changes. The person presses Apply; every applied change is logged with before/after on <em>AI actions</em> and can be undone.</li><li>• It refuses to help with tax evasion or fraud.</li><li>• Every call is metered; per-user budgets, wallets and rate limits are enforced on the server. See <a class="underline" href="' . e(url('/admin/features')) . '">Plans &amp; usage</a>.</li></ul></div></section>';
    card_flush_open('Recent AI usage', 'Latest 15 model calls'); $rows = all('SELECT a.created_at,a.model,a.input_tokens,a.output_tokens,a.cost_micro,a.source,u.email FROM {ai_usage} a JOIN {users} u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 15');
    if (!$rows) { echo '<p class="px-6 pb-6 text-sm text-mute">No usage yet.</p>'; } else { echo '<div class="relative overflow-x-auto"><table class="w-full min-w-[520px]"><thead><tr class="border-b border-line"><th class="th">When (UTC)</th><th class="th">User</th><th class="th">Model</th><th class="th text-right">In / out</th><th class="th text-right">Cost</th></tr></thead><tbody class="divide-y divide-line">';
        foreach ($rows as $r) { echo '<tr><td class="td num whitespace-nowrap text-mute">' . e($r['created_at']) . '</td><td class="td">' . e($r['email']) . '</td><td class="td">' . e($r['model']) . ($r['source'] === 'mock' ? ' ' . badge('mock') : '') . '</td><td class="td num text-right">' . number_format((int) $r['input_tokens']) . ' / ' . number_format((int) $r['output_tokens']) . '</td><td class="td num text-right">' . e(micro_to_inr((int) $r['cost_micro'])) . '</td></tr>'; }
        echo '</tbody></table></div>'; }
    echo '</div></section></div></div>'; admin_end();
}

function page_admin_features(): void {
    $me = require_admin(); admin_start('Plans & usage', 'features'); $monthStart = gmdate('Y-m-01 00:00:00');
    // ---- feature pricing
    $modes = ['free' => 'Free for everyone', 'paid' => 'Paid plan (time-limited access)', 'payg' => 'Pay as you go (wallet)']; $rows = '';
    foreach (FEATURES as $f => $m) { $c = feature_cfg($f);
        $opts = ''; foreach ($m['modes'] as $md) { $opts .= '<option value="' . $md . '"' . ($c['mode'] === $md ? ' selected' : '') . '>' . e($modes[$md]) . '</option>'; }
        $rows .= '<div class="rounded-2xl border border-line p-4"><div class="flex flex-wrap items-start justify-between gap-2"><div><p class="font-semibold">' . e($m['label']) . '</p><p class="text-xs text-mute">' . e($m['desc']) . '</p></div>' . ($c['mode'] === 'free' ? badge('Free', 'pos') : ($c['mode'] === 'paid' ? badge('Paid plan', 'warn') : badge('Pay as you go', 'dark'))) . '</div>'
            . '<div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4"><div class="col-span-2"><label class="label" for="m-' . $f . '">Mode</label><select class="input" id="m-' . $f . '" name="mode_' . $f . '">' . $opts . '</select></div>'
            . '<div><label class="label" for="p-' . $f . '">Plan price (₹)</label><input class="input" id="p-' . $f . '" name="price_' . $f . '" inputmode="decimal" value="' . ($c['price'] ? e(money_input($c['price'])) : '') . '" placeholder="0"></div>'
            . '<div><label class="label" for="d-' . $f . '">Plan days</label><input class="input" id="d-' . $f . '" name="days_' . $f . '" type="number" min="1" max="366" value="' . $c['days'] . '"></div>'
            . (in_array('payg', $m['modes'], true) && $f !== 'ai' ? '<div class="col-span-2"><label class="label" for="u-' . $f . '">Pay-as-you-go price per message (₹)</label><input class="input" id="u-' . $f . '" name="unit_' . $f . '" inputmode="decimal" value="' . e(micro_input($c['unit_micro'])) . '" placeholder="e.g. 0.50"></div>' : '')
            . ($f === 'ai' ? '<p class="col-span-2 text-xs text-mute sm:col-span-2">AI is metered by tokens: set the per-model prices on the <a class="underline" href="' . e(url('/admin/ai')) . '">AI agent</a> tab.</p>' : '') . '</div></div>'; }
    card_open('Features: free, paid or pay-as-you-go', 'Enforced on the server for every user. Admins are never charged. A paid plan is bought by the user as a normal charge through the payment gateway; pay-as-you-go draws from their wallet.');
    echo form_open('admin-features-save', 'space-y-3') . $rows . '<div class="flex justify-end"><button class="btn btn-dark">Save plans</button></div></form>'; card_close();
    // ---- dashboards
    $tot = one('SELECT COALESCE(SUM(cost_micro),0) c, COALESCE(SUM(input_tokens),0) i, COALESCE(SUM(output_tokens),0) o, COUNT(DISTINCT user_id) u, COUNT(*) n FROM {ai_usage} WHERE created_at >= ? AND source <> ?', [$monthStart, 'mock']) ?: ['c' => 0, 'i' => 0, 'o' => 0, 'u' => 0, 'n' => 0];
    $wallets = (int) val('SELECT COALESCE(SUM(amount_micro),0) FROM (SELECT user_id, SUM(amount_micro) amount_micro FROM {wallet_ledger} GROUP BY user_id HAVING SUM(amount_micro) > 0) w');
    $topups = (int) val("SELECT COALESCE(SUM(amount_micro),0) FROM {wallet_ledger} WHERE kind='topup' AND created_at >= ?", [$monthStart]);
    echo '<div class="mt-5 grid grid-cols-2 gap-4 lg:grid-cols-4">' . stat_card('AI spend this month', e(micro_to_inr((int) $tot['c'])), number_format((int) $tot['n']) . ' calls · ' . (int) $tot['u'] . ' user(s)') . stat_card('Tokens this month', e(number_format((int) $tot['i'] + (int) $tot['o'])), number_format((int) $tot['i']) . ' in · ' . number_format((int) $tot['o']) . ' out')
        . stat_card('Wallet balances held', e(micro_to_inr($wallets)), 'Credit users have not spent yet') . stat_card('Top-ups this month', e(micro_to_inr($topups)), '', 'pos') . '</div>';
    $days = []; foreach (all('SELECT DATE(created_at) d, SUM(cost_micro) c FROM {ai_usage} WHERE created_at >= ? GROUP BY DATE(created_at)', [gmdate('Y-m-d', time() - 29 * 86400)]) as $r) { $days[$r['d']] = (int) $r['c']; } $g = [];
    for ($i = 29; $i >= 0; $i--) { $d = gmdate('Y-m-d', time() - $i * 86400); $g[] = ['label' => substr($d, 8), 'values' => [intdiv($days[$d] ?? 0, 10000)]]; }
    echo '<div class="mt-5 grid gap-5 lg:grid-cols-2">'; card_open('AI spend, last 30 days', 'Cost of model calls per day'); echo array_sum(array_column(array_column($g, 'values'), 0)) > 0 ? chart_bars($g, [['name' => 'Spend', 'color' => 'lime']], 200) : chart_empty('No AI usage yet.'); card_close();
    card_flush_open('By model', 'This month'); $bm = all('SELECT model, COUNT(*) n, SUM(input_tokens) i, SUM(output_tokens) o, SUM(cost_micro) c FROM {ai_usage} WHERE created_at >= ? GROUP BY model ORDER BY c DESC', [$monthStart]);
    if (!$bm) { echo '<p class="px-6 pb-6 text-sm text-mute">No usage yet.</p>'; } else { echo '<table class="w-full"><thead><tr class="border-b border-line"><th class="th">Model</th><th class="th text-right">Calls</th><th class="th text-right">Tokens</th><th class="th text-right">Cost</th></tr></thead><tbody class="divide-y divide-line">'; foreach ($bm as $r) { echo '<tr><td class="td">' . e($r['model']) . '</td><td class="td num text-right">' . number_format((int) $r['n']) . '</td><td class="td num text-right">' . number_format((int) $r['i'] + (int) $r['o']) . '</td><td class="td num text-right">' . e(micro_to_inr((int) $r['c'])) . '</td></tr>'; } echo '</tbody></table>'; }
    echo '</div></section></div>';
    // ---- per user
    $users = all('SELECT u.id,u.name,u.email,u.role,u.ai_budget_micro,(SELECT COALESCE(SUM(cost_micro),0) FROM {ai_usage} a WHERE a.user_id=u.id AND a.created_at >= ?) AS spent,(SELECT COALESCE(SUM(amount_micro),0) FROM {wallet_ledger} w WHERE w.user_id=u.id) AS wallet FROM {users} u WHERE u.status=? ORDER BY spent DESC, u.name LIMIT 200', [$monthStart, 'active']);
    $ent = []; foreach (all('SELECT user_id,feature,valid_until FROM {entitlements} WHERE valid_until >= ?', [today()]) as $e) { $ent[$e['user_id']][] = FEATURES[$e['feature']]['label'] . ' until ' . date_label($e['valid_until']); }
    $defB = ai_cfg()['default_budget']; echo '<div class="mt-5">'; card_flush_open('People', 'AI spend this month, budget, wallet and plan access. Budget blank = default (' . ($defB ? micro_to_inr($defB) : 'no cap') . ').');
    echo '<div class="relative overflow-x-auto"><table class="w-full min-w-[820px]"><thead><tr class="border-b border-line"><th class="th">User</th><th class="th text-right">AI spend</th><th class="th text-right">Budget</th><th class="th text-right">Wallet</th><th class="th">Plans</th><th class="th"><span class="sr-only">Actions</span></th></tr></thead><tbody class="divide-y divide-line">';
    foreach ($users as $x) { $id = (int) $x['id']; $b = $x['ai_budget_micro'] === null ? null : (int) $x['ai_budget_micro'];
        $featOpts = []; foreach (FEATURES as $k => $m) { $featOpts[$k] = $m['label']; }
        echo '<tr><td class="td"><p class="font-medium">' . e($x['name']) . ($x['role'] === 'admin' ? ' ' . badge('Admin', 'dark') : '') . '</p><p class="text-xs text-mute">' . e($x['email']) . '</p></td><td class="td num text-right">' . e(micro_to_inr((int) $x['spent'])) . '</td><td class="td num text-right text-mute">' . ($b === null ? 'default' : ($b ? e(micro_to_inr($b)) : 'no cap')) . '</td><td class="td num text-right">' . e(micro_to_inr((int) $x['wallet'])) . '</td>'
            . '<td class="td text-xs text-mute">' . e(implode(', ', $ent[$id] ?? []) ?: '—') . '</td><td class="td"><div class="flex flex-wrap justify-end gap-1">'
            . dialog_form("bud-$id", 'Budget', 'AI budget for ' . $x['name'], 'admin-user-budget', [['name' => 'user_id', 'type' => 'hidden', 'value' => $id], ['name' => 'budget', 'label' => 'Monthly AI budget (₹). Blank = default, 0 = no cap', 'type' => 'money', 'currency' => '₹', 'value' => $b === null ? '' : micro_input($b)]], 'Save', 'btn btn-ghost btn-sm')
            . dialog_form("wal-$id", 'Wallet', 'Adjust wallet: ' . $x['name'], 'admin-wallet-adjust', [['name' => 'user_id', 'type' => 'hidden', 'value' => $id], ['name' => 'op', 'label' => 'Action', 'type' => 'select', 'required' => true, 'options' => ['credit' => 'Add credit', 'debit' => 'Remove credit']], ['name' => 'amount', 'label' => 'Amount (₹)', 'type' => 'money', 'currency' => '₹', 'required' => true], ['name' => 'note', 'label' => 'Note (optional)']], 'Apply', 'btn btn-ghost btn-sm')
            . dialog_form("gr-$id", 'Access', 'Plan access: ' . $x['name'], 'admin-user-grant', [['name' => 'user_id', 'type' => 'hidden', 'value' => $id], ['name' => 'feature', 'label' => 'Feature', 'type' => 'select', 'required' => true, 'options' => $featOpts], ['name' => 'op', 'label' => 'Action', 'type' => 'select', 'required' => true, 'options' => ['grant' => 'Grant / extend', 'revoke' => 'Revoke'], 'half' => true], ['name' => 'days', 'label' => 'Days', 'type' => 'number', 'min' => 1, 'max' => 3650, 'half' => true, 'value' => 30]], 'Apply', 'btn btn-ghost btn-sm') . '</div></td></tr>'; }
    echo '</tbody></table></div></div></section></div>'; admin_end();
}

function page_plans(): void {
    $u = require_login(); $s = snap(); $uid = (int) $u['id']; layout_start('Plans & wallet', 'plans', $s);
    page_header('Plans & wallet', 'What is free, what is a paid plan, and your pay-as-you-go balance.');
    $bal = wallet_balance($uid); $anyPayg = false; foreach (FEATURES as $f => $m) { if (feature_cfg($f)['mode'] === 'payg') { $anyPayg = true; } }
    echo '<div class="grid gap-5 lg:grid-cols-2"><div class="space-y-4">';
    foreach (FEATURES as $f => $m) { $c = feature_cfg($f); $fa = feature_access($u, $f); $until = entitlement_until($uid, $f);
        $state = is_exempt_user($u) ? badge('Included (admin)', 'dark') : ($c['mode'] === 'free' ? badge('Free', 'pos') : ($c['mode'] === 'paid' ? ($fa['ok'] ? badge('Active until ' . date_label((string) $until), 'pos') : badge('Not purchased', 'warn')) : ($fa['ok'] ? badge('Pay as you go', 'dark') : badge('Wallet empty', 'neg'))));
        $detail = match ($c['mode']) { 'paid' => fmt_inr($c['price']) . ' for ' . $c['days'] . ' days', 'payg' => $f === 'ai' ? 'Billed by usage (tokens), deducted from your wallet' : ($c['unit_micro'] ? micro_to_inr($c['unit_micro']) . ' per message, deducted from your wallet' : 'Deducted from your wallet'), default => 'Included for everyone' };
        echo '<article class="card p-5"><div class="flex flex-wrap items-start justify-between gap-2"><div><h3 class="font-semibold">' . e($m['label']) . '</h3><p class="text-sm text-mute">' . e($m['desc']) . '</p></div>' . $state . '</div><p class="mt-3 text-sm">' . e($detail) . '</p>'
            . ($c['mode'] === 'paid' && !is_exempt_user($u) && $c['price'] > 0 ? '<div class="mt-3">' . post_btn(($fa['ok'] ? 'Extend' : 'Get access') . ' · ' . e(fmt_inr($c['price'])), 'plan-buy', ['feature' => $f], 'btn btn-dark btn-sm') . '</div>' : '') . '</article>'; }
    echo '</div><div class="space-y-4">';
    card_open('Wallet', $anyPayg ? 'Credit for pay-as-you-go features. Usage is deducted automatically; at zero those features pause.' : 'No feature uses the wallet right now.');
    echo '<p class="num text-3xl font-semibold ' . ($bal <= 0 && $anyPayg ? 'text-neg' : '') . '">' . e(micro_to_inr($bal)) . '</p>';
    if (payu_configured() || true) { echo '<div class="mt-4">' . simple_form('wallet-topup', [['name' => 'amount', 'label' => 'Top up (₹)', 'type' => 'money', 'currency' => '₹', 'required' => true, 'value' => '500', 'hint' => 'You pay on the secure gateway page; the credit appears as soon as the payment is confirmed.']], 'Top up', 'btn btn-dark') . '</div>'; }
    card_close();
    $l = all('SELECT created_at,kind,amount_micro,note FROM {wallet_ledger} WHERE user_id=? AND NOT (kind=? AND amount_micro=0) ORDER BY id DESC LIMIT 15', [$uid, 'adjust']);
    if ($l) { card_flush_open('Wallet activity'); echo '<ul class="divide-y divide-line px-6 pb-3 text-sm">'; foreach ($l as $r) { echo '<li class="flex items-center justify-between gap-3 py-2.5"><span class="min-w-0"><span class="block truncate">' . e($r['note'] ?: ucfirst($r['kind'])) . '</span><span class="text-xs text-mute">' . e(substr($r['created_at'], 0, 16)) . ' UTC</span></span><span class="num font-medium ' . ($r['amount_micro'] < 0 ? '' : 'text-lime-dk') . '">' . ($r['amount_micro'] < 0 ? '−' : '+') . e(micro_to_inr(abs((int) $r['amount_micro']))) . '</span></li>'; } echo '</ul></div></section>'; }
    $sp = ai_month_spend($uid); $bu = ai_budget($uid); if ($sp > 0 || $bu > 0) { card_open('AI usage this month', ''); echo '<p class="num text-xl font-semibold">' . e(micro_to_inr($sp)) . '<span class="text-sm font-normal text-mute">' . ($bu ? ' of ' . e(micro_to_inr($bu)) . ' budget' : '') . '</span></p>' . ($bu ? '<div class="mt-2">' . progress(min(100, $sp / $bu * 100), $sp >= $bu ? 'neg' : 'lime', 'AI budget used') . '</div>' : ''); card_close(); }
    echo '</div></div>'; layout_end();
}
