<?php
declare(strict_types=1);

function qs_keep(array $f, array $extra = []): array {
    return array_merge(array_filter(['q' => $f['q'], 'type' => $f['type'], 'category' => $f['category'] ?: '', 'account' => $f['account'] ?: '', 'from' => $f['from'], 'to' => $f['to'], 'status' => $f['status'], 'sort' => $f['sort'], 'dir' => $f['dir']], fn($v) => $v !== '' && $v !== null), $extra);
}

function page_transactions(): void {
    require_login(); $s = snap(); $f = parse_tx_filter(); $rows = filter_txs($s, $f);
    $PAGE = 40; $page = max(1, (int) get_str('page', 5)); $pageRows = array_slice($rows, ($page - 1) * $PAGE, $PAGE);
    $acc = array_column($s['accounts'], 'name', 'id');
    $inc = $exp = 0; foreach ($rows as $t) { if ($t['status'] !== 'completed') { continue; } if ($t['type'] === 'income') { $inc += $t['amount']; } elseif ($t['type'] === 'expense') { $exp += $t['amount']; } elseif ($t['type'] === 'refund') { $exp -= $t['amount']; } }
    $view = get_str('summary', 5) === 'year' ? 'year' : 'month'; $sumRows = [];
    foreach ($s['txs'] as $t) {
        if ($t['status'] !== 'completed' || !in_array($t['type'], ['income', 'expense', 'refund'], true)) { continue; }
        $k = $view === 'year' ? substr($t['date'], 0, 4) : month_of($t['date']); $sumRows[$k] ??= ['i' => 0, 'e' => 0, 'n' => 0];
        if ($t['type'] === 'income') { $sumRows[$k]['i'] += $t['amount']; } else { $sumRows[$k]['e'] += $t['type'] === 'expense' ? $t['amount'] : -$t['amount']; } $sumRows[$k]['n']++;
    }
    krsort($sumRows); $sumRows = array_slice($sumRows, 0, $view === 'year' ? 10 : 12, true);
    $sortLink = fn($k) => url('/transactions', qs_keep($f, ['sort' => $k, 'dir' => $f['sort'] === $k && $f['dir'] === 'desc' ? 'asc' : 'desc']));
    $catOpts = []; foreach ($s['categories'] as $c) { if (!$c['parent_id']) { $catOpts[$c['id']] = $c['name']; foreach ($s['categories'] as $x) { if ((int) $x['parent_id'] === (int) $c['id']) { $catOpts[$x['id']] = "\u{00A0}\u{00A0}↳ " . $x['name']; } } } }
    $u = current_user();
    layout_start('Transactions', 'transactions', $s, ['hero' => true]);
    echo m_hero($s, $u, 'compact', 'Transactions');
    echo '<div class="m-sheet pb-6 lg:hidden">' . m_actions() . '<div class="flex items-center justify-between px-6 pb-1 pt-6"><h2 class="text-xl font-semibold tracking-tight">Recent Transactions</h2><a href="' . e(url('/analysis')) . '" class="inline-flex items-center gap-1 text-sm font-medium text-lime-dk">' . icon('chart-pie', 'size-4') . ' Analysis</a></div>';
    echo '<details class="mx-6 mb-2 rounded-2xl bg-soft px-4 py-3" ' . ($f['q'] || $f['type'] || $f['category'] || $f['account'] || $f['from'] || $f['to'] || $f['status'] ? 'open' : '') . '><summary class="flex cursor-pointer list-none items-center justify-between text-sm font-medium"><span class="inline-flex items-center gap-2">' . icon('sliders-horizontal', 'size-4') . ' Search & filter</span>' . icon('chevron-down', 'size-4') . '</summary>'
        . '<form method="get" class="mt-3 grid grid-cols-2 gap-3" role="search"><div class="col-span-2"><label class="label" for="mq">Search</label><input id="mq" name="q" value="' . e($f['q']) . '" placeholder="Description or notes" class="input"></div>'
        . '<div><label class="label" for="mt">Type</label><select id="mt" name="type" class="input"><option value="">All</option>' . implode('', array_map(fn($t) => '<option value="' . $t . '"' . ($f['type'] === $t ? ' selected' : '') . '>' . cap($t) . '</option>', ['income', 'expense', 'transfer', 'refund', 'adjustment'])) . '</select></div>'
        . '<div><label class="label" for="mc">Category</label><select id="mc" name="category" class="input"><option value="">All</option>' . implode('', array_map(fn($k, $v) => '<option value="' . $k . '"' . ($f['category'] === $k ? ' selected' : '') . '>' . e($v) . '</option>', array_keys($catOpts), $catOpts)) . '</select></div>'
        . '<div><label class="label" for="mf">From</label><input id="mf" type="date" name="from" value="' . e($f['from']) . '" class="input"></div><div><label class="label" for="mto">To</label><input id="mto" type="date" name="to" value="' . e($f['to']) . '" class="input"></div>'
        . '<div class="col-span-2 flex gap-2"><button class="btn btn-dark flex-1">Apply</button><a href="' . e(url('/transactions')) . '" class="btn">Clear</a></div></form></details>';
    echo '<div class="px-6">' . ($pageRows ? '' : '<p class="py-6 text-center text-sm text-mute">No transactions match.</p>') . '<ul class="divide-y divide-line">';
    foreach ($pageRows as $t) {
        $icn = ['income' => 'arrow-down-left', 'refund' => 'undo-2', 'transfer' => 'arrow-left-right', 'adjustment' => 'sliders-horizontal'][$t['type']] ?? cat_icon(cat_name($s, $t['category_id'] ? (int) $t['category_id'] : null));
        $tag = $t['occurrence_id'] ? '<span class="pointer-events-none">' : '';
        echo '<li class="py-1"><' . ($t['occurrence_id'] ? 'div' : 'button type="button" data-tx-edit="' . json_attr(tx_edit_json($s, $t)) . '"') . ' class="flex w-full items-center gap-3 rounded-2xl py-2.5 text-left"><span class="grid size-12 shrink-0 place-items-center rounded-full bg-soft">' . icon($icn, 'size-5') . '</span>'
            . '<span class="min-w-0 flex-1"><span class="block truncate font-medium">' . e(tx_label($s, $t)) . '</span><span class="block truncate text-xs text-mute">' . e($t['date'] === $s['today'] ? 'Today' : date_label($t['date'])) . ' · ' . e($t['type'] === 'transfer' ? 'Transfer' : cat_path($s, $t['category_id'] ? (int) $t['category_id'] : null)) . ($t['status'] === 'planned' ? ' · Planned' : '') . '</span></span>'
            . '<span class="num shrink-0 font-semibold ' . amount_class($t) . '">' . e(signed_amount($t)) . '</span></' . ($t['occurrence_id'] ? 'div' : 'button') . '></li>';
    }
    echo '</ul>';
    if (count($rows) > $PAGE) { echo '<div class="flex items-center justify-between pt-4 text-sm"><span class="text-mute">Page ' . $page . ' of ' . (int) ceil(count($rows) / $PAGE) . '</span><div class="flex gap-2">' . ($page > 1 ? '<a class="btn btn-sm" href="' . e(url('/transactions', qs_keep($f, ['page' => $page - 1]))) . '">Previous</a>' : '') . ($page * $PAGE < count($rows) ? '<a class="btn btn-sm" href="' . e(url('/transactions', qs_keep($f, ['page' => $page + 1]))) . '">Next</a>' : '') . '</div></div>'; }
    echo '</div></div><div class="hidden px-0 lg:block">';
    $import = '<button type="button" class="btn" data-open="#import-dialog">' . icon('upload', 'size-4') . ' Import CSV</button>';
    page_header('Transactions', 'Every rupee in and out, in one searchable ledger.', $import . btn('Export CSV', url('/transactions/export', qs_keep($f)), 'btn', 'download') . '<button type="button" class="btn btn-dark" data-tx-new>' . icon('plus', 'size-4') . ' Add transaction</button>');
    ?>
<dialog id="import-dialog" class="modal" aria-labelledby="import-t"><div class="modal-body"><div class="mb-4 flex items-start justify-between gap-4"><h2 id="import-t" class="text-xl font-semibold tracking-tight">Import transactions from CSV</h2><button type="button" class="btn btn-ghost btn-sm -mr-2" data-close aria-label="Close"><?= icon('x', 'size-4') ?></button></div>
  <?= simple_form('transactions-import', [['name' => 'file', 'label' => 'CSV file', 'type' => 'file', 'accept' => '.csv,text/csv', 'required' => true, 'hint' => 'Columns: date (YYYY-MM-DD), type, amount, category, account, description, payment_method, notes (optional: status, to_account). Every row is validated first; nothing is imported if any row is invalid.']], 'Import', 'btn btn-primary', true) ?></div></dialog>

<form method="get" class="card mb-5 grid gap-3 p-5 sm:grid-cols-2 lg:grid-cols-6" role="search" aria-label="Filter transactions">
  <div class="lg:col-span-2"><label class="label" for="fq">Search</label><input id="fq" name="q" value="<?= e($f['q']) ?>" placeholder="Description or notes" class="input"></div>
  <div><label class="label" for="ft">Type</label><select id="ft" name="type" class="input"><option value="">All</option><?php foreach (['income', 'expense', 'transfer', 'refund', 'adjustment'] as $t): ?><option value="<?= $t ?>" <?= $f['type'] === $t ? 'selected' : '' ?>><?= cap($t) ?></option><?php endforeach; ?></select></div>
  <div><label class="label" for="fc">Category</label><select id="fc" name="category" class="input"><option value="">All</option><?php foreach ($catOpts as $k => $v): ?><option value="<?= $k ?>" <?= $f['category'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
  <div><label class="label" for="fa">Account</label><select id="fa" name="account" class="input"><option value="">All</option><?php foreach ($acc as $k => $v): ?><option value="<?= $k ?>" <?= $f['account'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
  <div><label class="label" for="fs">Status</label><select id="fs" name="status" class="input"><option value="">All</option><option value="completed" <?= $f['status'] === 'completed' ? 'selected' : '' ?>>Completed</option><option value="planned" <?= $f['status'] === 'planned' ? 'selected' : '' ?>>Planned</option></select></div>
  <div><label class="label" for="ff">From</label><input id="ff" type="date" name="from" value="<?= e($f['from']) ?>" class="input"></div>
  <div><label class="label" for="fto">To</label><input id="fto" type="date" name="to" value="<?= e($f['to']) ?>" class="input"></div>
  <input type="hidden" name="sort" value="<?= e($f['sort']) ?>"><input type="hidden" name="dir" value="<?= e($f['dir']) ?>">
  <div class="flex items-end gap-2 lg:col-span-2"><button class="btn btn-dark">Apply filters</button><a href="<?= e(url('/transactions')) ?>" class="btn btn-ghost">Clear</a></div>
</form>

<section class="card min-w-0">
  <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-6 py-4 text-sm"><span class="text-mute"><?= count($rows) ?> transaction<?= count($rows) === 1 ? '' : 's' ?></span><span class="num"><span class="text-lime-dk">+<?= e(fmt($inc)) ?></span> <span class="mx-1 text-mute">/</span> −<?= e(fmt($exp)) ?></span></div>
  <?php if (!$rows): ?><div class="p-6"><?= empty_state('No transactions match', $s['txs'] ? 'Try clearing some filters.' : 'Add your first transaction to get started.') ?></div><?php else: ?>
  <div class="relative overflow-x-auto"><table class="w-full min-w-[640px] border-collapse">
    <thead><tr class="border-b border-line"><th class="th"><a href="<?= e($sortLink('date')) ?>" class="inline-flex items-center gap-1">Date <?= $f['sort'] === 'date' ? icon($f['dir'] === 'desc' ? 'arrow-down' : 'arrow-up', 'size-3') : '' ?></a></th><th class="th">Description</th><th class="th">Category</th><th class="th">Account</th>
      <th class="th text-right"><a href="<?= e($sortLink('amount')) ?>" class="inline-flex items-center gap-1">Amount <?= $f['sort'] === 'amount' ? icon($f['dir'] === 'desc' ? 'arrow-down' : 'arrow-up', 'size-3') : '' ?></a></th><th class="th"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody class="divide-y divide-line">
    <?php foreach ($pageRows as $t): ?>
      <tr><td class="td num whitespace-nowrap text-mute"><?= e(date_label($t['date'])) ?></td>
        <td class="td"><div class="font-medium"><?= e($t['description'] ?: '—') ?></div><div class="mt-0.5 flex flex-wrap gap-1"><?= $t['status'] === 'planned' ? badge('Planned', 'warn') : '' ?><?= !in_array($t['type'], ['income', 'expense'], true) ? badge($t['type']) : '' ?><?= $t['occurrence_id'] ? badge('Bill payment') : '' ?></div></td>
        <td class="td text-mute"><?= e($t['type'] === 'transfer' ? 'Transfer' : cat_path($s, $t['category_id'] ? (int) $t['category_id'] : null)) ?></td>
        <td class="td text-mute"><?= e($t['type'] === 'transfer' ? ($acc[$t['account_id']] ?? '') . ' → ' . ($acc[$t['to_account_id']] ?? '') : ($acc[$t['account_id']] ?? '')) ?></td>
        <td class="td num whitespace-nowrap text-right font-semibold <?= amount_class($t) ?>"><?= e(signed_amount($t)) ?></td>
        <td class="td whitespace-nowrap text-right"><div class="flex items-center justify-end gap-1">
          <?php if ($t['status'] === 'planned') { echo post_btn($t['type'] === 'income' ? 'Mark received' : 'Mark paid', 'transaction-complete', ['id' => $t['id']]); } ?>
          <?php if (!$t['occurrence_id']): ?><button type="button" class="btn btn-ghost btn-sm" data-tx-edit="<?= json_attr(tx_edit_json($s, $t)) ?>">Edit</button><?php endif; ?>
          <?= confirm_btn('Delete', 'transaction-delete', ['id' => $t['id']], 'Delete transaction?', 'This permanently removes “' . ($t['description'] ?: $t['type']) . '” (' . fmt(abs($t['amount'])) . ') and updates your balances.' . ($t['type'] === 'expense' ? ' Any refunds linked to it are removed too.' : '')) ?></div></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php if (count($rows) > $PAGE): ?><div class="flex items-center justify-between border-t border-line px-6 py-4 text-sm"><span class="text-mute">Page <?= $page ?> of <?= (int) ceil(count($rows) / $PAGE) ?></span><div class="flex gap-2">
    <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= e(url('/transactions', qs_keep($f, ['page' => $page - 1]))) ?>">Previous</a><?php endif; ?><?php if ($page * $PAGE < count($rows)): ?><a class="btn btn-sm" href="<?= e(url('/transactions', qs_keep($f, ['page' => $page + 1]))) ?>">Next</a><?php endif; ?></div></div><?php endif; ?>
  <?php endif; ?>
</section>

<section class="card mt-5 min-w-0"><header class="flex items-center justify-between gap-2 px-6 pt-5"><h2 class="text-lg font-semibold tracking-tight">Summary</h2><?= jelly(['month' => ['label' => 'Monthly', 'href' => url('/transactions', ['summary' => 'month'])], 'year' => ['label' => 'Yearly', 'href' => url('/transactions', ['summary' => 'year'])]], $view, ['size' => 'sm', 'label' => 'Summary period']) ?></header>
  <?php if (!$sumRows): ?><p class="p-6 text-sm text-mute">No completed income or expenses yet.</p><?php else: ?>
  <div class="relative mt-3 overflow-x-auto"><table class="w-full min-w-[480px] border-collapse"><thead><tr class="border-y border-line"><th class="th">Period</th><th class="th text-right">Income</th><th class="th text-right">Expenses</th><th class="th text-right">Net</th><th class="th text-right">Entries</th></tr></thead><tbody class="divide-y divide-line">
  <?php foreach ($sumRows as $k => $e): ?><tr><td class="td"><?= e($view === 'year' ? (string) $k : month_label((string) $k)) ?></td><td class="td num text-right text-lime-dk"><?= e(fmt($e['i'])) ?></td><td class="td num text-right"><?= e(fmt($e['e'])) ?></td><td class="td num text-right font-semibold <?= $e['i'] - $e['e'] < 0 ? 'text-neg' : 'text-lime-dk' ?>"><?= e(fmt($e['i'] - $e['e'])) ?></td><td class="td num text-right text-mute"><?= $e['n'] ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php echo '</div>'; layout_end();
}

function page_transactions_export(): void {
    $u = require_login(); $s = load_snapshot((int) $u['id']); $f = parse_tx_filter(); $acc = array_column($s['accounts'], 'name', 'id');
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="transactions-' . $s['today'] . '.csv"');
    $safe = function ($v) { $v = (string) ($v ?? ''); return ($v !== '' && (preg_match('/^[=+@]/', $v) || (str_starts_with($v, '-') && !preg_match('/^-\d/', $v)))) ? "'" . $v : $v; };
    $out = fopen('php://output', 'w'); fputcsv($out, ['date', 'type', 'amount', 'category', 'account', 'description', 'payment_method', 'notes', 'status', 'to_account'], ',', '"', '');
    foreach (filter_txs($s, $f) as $t) {
        $cat = $t['category_id'] ? explode(' › ', cat_path($s, (int) $t['category_id'])) : [];
        fputcsv($out, [$t['date'], $t['type'], money_input((int) $t['amount']), $cat ? end($cat) : '', $acc[$t['account_id']] ?? '', $safe($t['description']), $t['payment_method'], $safe($t['notes']), $t['status'], $t['to_account_id'] ? ($acc[$t['to_account_id']] ?? '') : ''], ',', '"', '');
    }
    fclose($out);
}

function page_income(): void {
    require_login(); $s = snap(); $month = month_param();
    $sum = summarize_month($s['accounts'], $s['txs'], $s['contribs'], $month);
    $rows = array_values(array_filter($s['txs'], fn($t) => $t['type'] === 'income' && month_of($t['date']) === $month));
    $series = month_series($s, 6, $month); $prev = $series[4] ?? null; $delta = $prev ? $sum['income'] - $prev['income'] : 0;
    $bySource = []; foreach ($s['txs'] as $t) { if ($t['type'] === 'income' && $t['status'] === 'completed' && $t['date'] >= month_start($series[0]['month'])) { $k = $t['description'] ?: cat_path($s, $t['category_id'] ? (int) $t['category_id'] : null); $bySource[$k] = ($bySource[$k] ?? 0) + $t['amount']; } }
    arsort($bySource);
    layout_start('Income', 'income', $s);
    page_header('Income', 'Salary, side income and one-time payments, received versus still expected.', month_nav($month, '/income') . '<button type="button" class="btn btn-dark" data-tx-new="income">' . icon('plus', 'size-4') . ' Add income</button>');
    echo '<div class="grid grid-cols-2 gap-4 lg:grid-cols-4">'
        . stat_card('Opening balance', e(fmt($sum['opening'])), 'Carried forward') . stat_card('Income received', e(fmt($sum['income'])), $prev ? ($delta >= 0 ? '+' : '−') . e(fmt(abs($delta))) . ' vs ' . e(month_label($prev['month'], true)) : '', 'pos')
        . stat_card('Expected, not yet received', e(fmt($sum['income_expected'])), '', $sum['income_expected'] ? 'warn' : '') . stat_card('Available funds', e(fmt($sum['available'])), 'Opening balance + income received') . '</div>';
    echo '<div class="mt-5 grid gap-5 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">';
    card_open('Income in ' . month_label($month));
    if (!$rows) { echo empty_state('No income recorded for this month', 'Add a salary or any one-time payment. Tick “not received yet” to track it as expected income.'); }
    else {
        echo '<ul class="divide-y divide-line">';
        foreach ($rows as $t) {
            echo '<li class="flex flex-wrap items-center justify-between gap-2 py-3"><div class="min-w-0"><p class="text-sm font-medium">' . e($t['description'] ?: cat_path($s, $t['category_id'] ? (int) $t['category_id'] : null)) . '</p><p class="text-xs text-mute">' . e(date_label($t['date'])) . ' · ' . e(cat_path($s, $t['category_id'] ? (int) $t['category_id'] : null)) . ' ' . ($t['status'] === 'planned' ? badge('Expected', 'warn') : badge('Received', 'pos')) . '</p></div>'
                . '<div class="flex items-center gap-1"><span class="num mr-2 text-sm font-semibold text-lime-dk">' . e(signed_amount($t)) . '</span>' . ($t['status'] === 'planned' ? post_btn('Mark received', 'transaction-complete', ['id' => $t['id']]) : '')
                . '<button type="button" class="btn btn-ghost btn-sm" data-tx-edit="' . json_attr(tx_edit_json($s, $t)) . '">Edit</button>' . confirm_btn('Delete', 'transaction-delete', ['id' => $t['id']], 'Delete income?', 'Remove ' . ($t['description'] ?: 'this income') . ' (' . fmt($t['amount']) . ')?') . '</div></li>';
        }
        echo '</ul>';
    }
    card_close();
    card_open('Income by month'); echo chart_bars(array_map(fn($m) => ['label' => substr($m['label'], 0, 3), 'values' => [$m['income']]], $series), [['name' => 'Income received', 'color' => 'lime']], 220); card_close();
    echo '</div><div class="mt-5 grid gap-5 lg:grid-cols-2">';
    card_open('Recurring income', 'Each due date creates an expected entry you confirm when the money arrives.', dialog_form('tpl-new', 'Add recurring', 'New recurring income', 'template-save', template_fields('income', $s), 'Save', 'btn btn-sm'));
    echo template_list($s, 'income') ?: '<p class="text-sm text-mute">Nothing set up yet. Add your salary once and forget about re-typing it.</p>'; card_close();
    card_open('Where income came from', 'Last 6 months');
    if (!$bySource) { echo '<p class="text-sm text-mute">No income yet.</p>'; } else { echo '<ul class="divide-y divide-line text-sm">'; foreach (array_slice($bySource, 0, 8, true) as $k => $v) { echo '<li class="flex justify-between py-2.5"><span class="truncate">' . e($k) . '</span><span class="num">' . e(fmt($v)) . '</span></li>'; } echo '</ul>'; }
    card_close();
    echo '</div>';
    layout_end();
}

function page_expenses(): void {
    require_login(); $s = snap(); $month = month_param();
    $sum = summarize_month($s['accounts'], $s['txs'], $s['contribs'], $month); $spend = spend_by_category($s['txs'], $month);
    $spentOf = function (int $id) use ($s, $spend) { $t = 0; foreach (cat_desc($s, $id) as $d) { $t += $spend[$d] ?? 0; } return $t; };
    $bva = budget_vs_actual($s, $month); $totalBudget = array_sum(array_column($bva, 'budget')); $budgetSpent = array_sum(array_column($bva, 'spent'));
    $occ = array_values(array_filter(purchase_views($s), fn($p) => $p['kind'] === 'occasional'));
    $blurb = ['fixed' => 'Bills that come around on a schedule. Track and pay them on the Bills page.', 'household' => 'Groceries, cleaning, maintenance and daily necessities.', 'personal' => 'Shopping, dining, travel, entertainment and everything else that is yours.', 'occasional' => 'Things that do not happen every month. Plan them below so they never come as a surprise.', 'custom' => 'Your own categories.'];
    layout_start('Expenses', 'expenses', $s);
    page_header('Expenses', 'Budgets and actual spending by category, plus the occasional costs you can see coming.', month_nav($month, '/expenses') . '<button type="button" class="btn btn-dark" data-tx-new="expense">' . icon('plus', 'size-4') . ' Add expense</button>');
    echo '<div class="grid grid-cols-2 gap-4 lg:grid-cols-4">' . stat_card('Spent', e(fmt($sum['expenses'])), e(month_label($month))) . stat_card('Budgeted', $totalBudget ? e(fmt($totalBudget)) : '—', $totalBudget ? 'Sum of category budgets' : 'No budgets set')
        . stat_card('Left in budgets', $totalBudget ? e(fmt($totalBudget - $budgetSpent)) : '—', 'Only budgeted categories', $totalBudget && $budgetSpent > $totalBudget ? 'neg' : '') . stat_card('Planned, not paid', e(fmt($sum['planned_expenses'])), 'Not counted as spent yet', $sum['planned_expenses'] ? 'warn' : '') . '</div>';
    echo '<div class="mt-5 space-y-5">';
    foreach (['fixed', 'household', 'personal', 'occasional', 'custom'] as $g) {
        $tops = array_values(array_filter($s['categories'], fn($c) => $c['grp'] === $g && !$c['parent_id']));
        $catFields = fn(string $grp, ?array $c = null) => array_merge($c ? [['name' => 'id', 'type' => 'hidden', 'value' => $c['id']]] : [], [
            ['name' => 'name', 'label' => 'Name', 'required' => true, 'value' => $c['name'] ?? ''],
            ['name' => 'grp', 'label' => 'Group', 'type' => 'select', 'required' => true, 'half' => true, 'value' => $grp, 'options' => array_diff_key(GROUP_LABELS, ['income' => 1])],
            ['name' => 'parent_id', 'label' => 'Subcategory of', 'type' => 'select', 'half' => true, 'value' => $c['parent_id'] ?? '', 'hint' => "Optional. A subcategory inherits its parent's group.",
                'options' => array_column(array_filter($s['categories'], fn($x) => !$x['parent_id'] && $x['grp'] !== 'income' && (int) $x['id'] !== (int) ($c['id'] ?? 0)), 'name', 'id')]]);
        card_open(GROUP_LABELS[$g], $blurb[$g], dialog_form("cat-new-$g", 'Add category', 'New category', 'category-save', $catFields($g), 'Save', 'btn btn-sm'));
        if (!$tops) { echo '<p class="text-sm text-mute">No categories yet.</p>'; }
        else {
            echo '<ul class="divide-y divide-line">';
            foreach ($tops as $top) {
                foreach (array_merge([$top], array_values(array_filter($s['categories'], fn($x) => (int) $x['parent_id'] === (int) $top['id']))) as $c) {
                    $id = (int) $c['id']; $budget = $s['budgets'][$id] ?? 0; $spent = $c['parent_id'] ? ($spend[$id] ?? 0) : $spentOf($id); $pct = $budget ? $spent / $budget * 100 : 0;
                    echo '<li class="grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-2 py-3.5 sm:grid-cols-[minmax(0,2fr)_minmax(0,3fr)_auto] ' . ($c['parent_id'] ? 'pl-6' : '') . '"><div class="min-w-0"><p class="truncate text-sm font-medium">' . ($c['parent_id'] ? '<span class="text-mute">↳ </span>' : '') . e($c['name']) . '</p><p class="num text-xs text-mute">' . e(fmt($spent)) . ' spent' . ($budget ? ' of ' . e(fmt($budget)) : '') . '</p></div>'
                        . '<div class="order-3 col-span-2 sm:order-none sm:col-span-1">' . ($budget ? progress($pct, $pct > 100 ? 'neg' : ($pct > 85 ? 'orange' : 'lime'), $c['name'] . ' budget used') : '<span class="text-xs text-mute">No budget</span>') . '</div><div class="flex gap-1">'
                        . dialog_form("bud-$id", $budget ? 'Budget' : 'Set budget', 'Monthly budget: ' . $c['name'], 'budget-set', [['name' => 'category_id', 'type' => 'hidden', 'value' => $id], ['name' => 'amount', 'label' => 'Monthly budget', 'type' => 'money', 'value' => $budget ? money_input($budget) : '', 'hint' => 'Enter 0 to remove the budget.']], 'Save', 'btn btn-sm')
                        . dialog_form("cat-$id", 'Edit', 'Edit category', 'category-save', $catFields($c['grp'], $c), 'Save changes', 'btn btn-ghost btn-sm')
                        . confirm_btn('Delete', 'category-delete', ['id' => $id], 'Delete “' . $c['name'] . '”?', 'Transactions in this category become uncategorised. Subcategories are deleted with it.') . '</div></li>';
                }
            }
            echo '</ul>';
        }
        card_close();
    }
    if (($spend[0] ?? 0) > 0) { echo '<div class="card p-5 text-sm">Uncategorised spending this month: <span class="num font-semibold">' . e(fmt($spend[0])) . '</span></div>'; }
    echo '</div>';
    echo '<div class="mt-8"><div class="mb-4 flex flex-wrap items-center justify-between gap-2"><div><h2 class="text-xl font-semibold tracking-tight">Occasional expenses</h2><p class="text-sm text-mute">Estimate the cost, pick a date and optionally set money aside every month.</p></div>'
        . dialog_form('occ-new', 'Plan an expense', 'Plan an occasional expense', 'purchase-save', purchase_fields('occasional'), 'Save', 'btn btn-dark') . '</div>';
    if (!$occ) { echo empty_state('No occasional expenses planned', 'Home repairs, festival shopping, medical costs, travel, annual subscriptions, emergencies. Add what you expect, even roughly.'); }
    else { echo '<div class="grid gap-4 md:grid-cols-2">'; foreach ($occ as $p) { echo purchase_card($s, $p, 'occasional'); } echo '</div>'; }
    echo '</div>';
    echo '<div class="mt-6">'; card_open('Recurring expenses', 'For repeating costs that are not bills, such as household help. Each due date creates a planned expense you confirm when paid.', dialog_form('tpl-new', 'Add recurring', 'New recurring expense', 'template-save', template_fields('expense', $s), 'Save', 'btn btn-sm'));
    echo template_list($s, 'expense') ?: '<p class="text-sm text-mute">Nothing set up yet. Bills with due dates live on the Bills page.</p>'; card_close(); echo '</div>';
    layout_end();
}
