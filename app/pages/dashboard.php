<?php
declare(strict_types=1);

function pct_change(int $now, int $prev): ?float { return $prev > 0 ? ($now - $prev) / $prev * 100 : null; }
function change_chip(?float $c, bool $upIsGood = true): string {
    if ($c === null) { return '<span class="text-xs text-mute">no earlier data</span>'; }
    $good = ($c >= 0) === $upIsGood;
    return '<span class="inline-flex items-center gap-1 text-xs ' . ($good ? 'text-lime-dk' : 'text-orange-dk') . '">' . icon($c >= 0 ? 'trending-up' : 'trending-down', 'size-3.5') . number_format(abs($c), 1) . '% <span class="text-mute">from last month</span></span>';
}

function page_dashboard(): void {
    $u = require_login(); $s = snap(); $month = month_of($s['today']);
    $d = dashboard_data($s, $month); $sum = $d['sum'];
    $series = $d['series']; $prev = $series[count($series) - 2] ?? null;
    $insights = array_values(array_filter(insights_for($s), fn($i) => $i['sev'] !== 'good'));
    $isEmpty = !$s['txs'] && !$s['goals'] && !$s['bills'];
    layout_start('Dashboard', 'dashboard', $s, ['hero' => true]);
    echo m_hero($s, $u, 'home');
    echo '<div class="m-sheet pb-6 lg:hidden">' . m_actions() . '<div class="flex items-center justify-between px-6 pb-1 pt-6"><h2 class="text-xl font-semibold tracking-tight">Recent Transactions</h2><a href="' . e(url('/transactions')) . '" class="text-sm text-mute">View All</a></div><div class="px-6">' . tx_list($s, array_slice($d['recent'], 0, 5), 'No transactions yet. Tap Income or Expense to add your first.') . '</div></div>';
    echo '<div class="mt-5 px-4 lg:mt-0 lg:px-0">';
    $hour = (int) date('G'); $hello = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
    echo '<div class="hidden lg:block">'; page_header($hello . ', ' . explode(' ', $u['name'])[0], 'Your money in ' . month_label($month)); echo '</div>';
    if ($isEmpty) { echo '<div class="mb-5">' . empty_state("Let's get started", 'Add your first income or expense with <strong>Add transaction</strong>, set up bills and savings goals, or load sample data from Settings to explore.') . '</div>'; }
    ?>
<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_370px]">
  <div class="min-w-0 space-y-5">

    <!-- Balance overview -->
    <section class="card grid gap-6 p-6 lg:grid-cols-[minmax(0,1fr)_230px]" aria-label="Balance overview">
      <div class="min-w-0">
        <p class="num text-4xl font-semibold tracking-tight"><?= e(fmt($d['cash'])) ?></p>
        <p class="text-sm text-mute">Balance overview · spendable <?= e(fmt($d['spendable'])) ?> · reserved <?= e(fmt($d['reserved'])) ?></p>
        <div class="mt-4"><?= chart_bars(array_map(fn($m) => ['label' => substr($m['label'], 0, 3), 'values' => [max($m['net'], 0), $m['income'], $m['expenses']]], $series),
            [['name' => 'Savings', 'color' => 'yellow'], ['name' => 'Income', 'color' => 'lime'], ['name' => 'Expenses', 'color' => 'orange']], 250) ?></div>
      </div>
      <div class="flex flex-col justify-between gap-5 border-t border-line pt-5 lg:border-l lg:border-t-0 lg:pl-6 lg:pt-0">
        <div><p class="text-sm text-mute">Income received</p><p class="num text-3xl font-semibold"><?= e(fmt($sum['income'])) ?></p><?= change_chip(pct_change($sum['income'], $prev['income'] ?? 0)) ?></div>
        <div><p class="text-sm text-mute">Total expenses</p><p class="num text-3xl font-semibold"><?= e(fmt($sum['expenses'])) ?></p><?= change_chip(pct_change($sum['expenses'], $prev['expenses'] ?? 0), false) ?></div>
        <div><p class="text-sm text-mute">Saved this month</p><p class="num text-3xl font-semibold <?= $sum['net'] < 0 ? 'text-neg' : '' ?>"><?= e(fmt($sum['net'])) ?></p><?= change_chip(isset($prev) && $prev['net'] > 0 ? pct_change($sum['net'], $prev['net']) : null) ?></div>
      </div>
    </section>

    <!-- At a glance -->
    <section class="grid grid-cols-2 gap-4 lg:grid-cols-4" aria-label="At a glance">
      <?= stat_card('Upcoming bills (30 days)', e(fmt($d['upcoming_total'])), $d['upcoming_count'] . ' bill' . ($d['upcoming_count'] === 1 ? '' : 's') . ' due', $d['upcoming_total'] ? 'warn' : '', '/bills') ?>
      <?= stat_card('Outstanding payments', e(fmt($d['outstanding'])), $d['overdue_count'] ? $d['overdue_count'] . ' overdue' : 'Nothing overdue', $d['outstanding'] ? 'neg' : '', '/bills') ?>
      <?= stat_card('Total savings', e(fmt($d['total_saved'])), 'Across ' . count($d['goals']) . ' goal' . (count($d['goals']) === 1 ? '' : 's') . ' · ' . e(fmt($d['reserved'])) . ' reserved overall', 'pos', '/goals') ?>
      <?= stat_card('Income expected', e(fmt($sum['income_expected'])), 'Not yet received', $sum['income_expected'] ? 'warn' : '', '/income') ?>
    </section>

    <!-- Spending limit + tip -->
    <div class="grid gap-5 lg:grid-cols-2">
      <?php card_open('Monthly spending limit', 'Remaining monthly budget', '<a href="' . e(url('/expenses')) . '" class="btn btn-icon btn-sm" aria-label="Edit budgets">' . icon('pencil', 'size-4') . '</a>'); ?>
        <?php if ($d['has_budgets']): $left = $d['total_budget'] - $d['budget_spent']; ?>
          <?= progress($d['total_budget'] ? $d['budget_spent'] / $d['total_budget'] * 100 : 0, $left < 0 ? 'neg' : 'lime', 'Budget used') ?>
          <div class="num mt-3 flex items-baseline justify-between"><span class="text-xl font-semibold"><?= e(fmt($d['budget_spent'])) ?></span><span class="text-sm text-mute">of <?= e(fmt($d['total_budget'])) ?></span></div>
          <p class="mt-1 text-sm <?= $left < 0 ? 'text-neg' : 'text-mute' ?>"><?= $left < 0 ? e(fmt(-$left)) . ' over your budgets' : e(fmt($left)) . ' left to spend' ?></p>
        <?php else: ?><p class="text-sm text-mute">Set a monthly budget for your categories to see how much you can still spend.</p><a class="btn btn-sm mt-3" href="<?= e(url('/expenses')) ?>">Set budgets</a><?php endif; ?>
      <?php card_close(); ?>
      <section class="card relative min-w-0 overflow-hidden p-6">
        <div class="max-w-[75%]"><h2 class="text-lg font-semibold tracking-tight"><?= $insights ? e($insights[0]['title']) : 'You are on top of things' ?></h2>
          <p class="mt-1 text-sm text-mute"><?= $insights ? e($insights[0]['detail']) : 'No budgets exceeded and nothing overdue. Review your monthly plan to keep it that way.' ?></p>
          <a href="<?= e(url($insights[0]['href'] ?? '/reports')) ?>" class="mt-4 inline-flex items-center gap-1 text-sm font-medium">Read more <?= icon('chevron-right', 'size-4') ?></a></div>
        <div class="pointer-events-none absolute -right-2 bottom-2 grid grid-cols-3 gap-1.5 opacity-90" aria-hidden="true"><?php foreach ([0, 1, 0, 1, 1, 0, 0, 1, 1] as $i => $on): ?><span class="size-9 rounded-lg <?= $on ? ($i % 2 ? 'hatch-orange' : 'hatch-lime') : 'bg-soft' ?>"></span><?php endforeach; ?></div>
      </section>
    </div>

    <!-- Cost analysis / health / goals -->
    <div class="grid gap-5 md:grid-cols-2 2xl:grid-cols-3">
      <?php card_open('Cost analysis', 'Spending overview · ' . month_label($month)); ?>
        <p class="num text-2xl font-semibold"><?= e(fmt($sum['expenses'])) ?></p><div class="mt-3"><?= chart_segbar($d['breakdown']) ?></div>
      <?php card_close(); ?>
      <?php card_open('Financial health', 'Current status'); ?>
        <?php $rate = $sum['rate']; ?>
        <?= chart_gauge($rate === null ? 0 : max($rate, 0), $rate === null ? '—' : round($rate) . '%', $rate === null ? 'No income received yet' : 'Of monthly income saved') ?>
        <p class="mt-3 text-center text-xs text-mute">Saved ÷ income received. Based on this month's completed transactions.</p>
      <?php card_close(); ?>
      <?php card_open('Goal tracker', $d['goals'] ? ($d['behind_count'] ? $d['behind_count'] . ' behind schedule' : 'All on track') : '', '<a href="' . e(url('/goals')) . '" class="btn btn-sm">' . icon('plus', 'size-4') . ' Goals</a>', 'md:col-span-2 2xl:col-span-1'); ?>
        <?php $lt = array_values(array_filter($d['goals'], fn($g) => $g['kind'] === 'long-term')); if (!$lt): ?><p class="py-4 text-sm text-mute">Create a savings goal to track progress.</p><?php else: ?>
        <ul class="space-y-4"><?php foreach (array_slice($lt, 0, 4) as $g): ?><li><div class="mb-1.5 flex items-center justify-between gap-2 text-sm"><span class="truncate font-medium"><?= e($g['name']) ?></span><span class="num shrink-0 text-xs text-mute"><?= e(fmt_short($g['saved'])) ?> / <?= e(fmt_short($g['target_amount'])) ?></span></div>
          <?= progress($g['percent'], $g['complete'] ? 'dark' : ($g['behind'] ? 'orange' : 'lime'), $g['name'] . ' progress') ?>
          <p class="mt-1 text-xs text-mute"><?= $g['complete'] ? 'Goal reached' : ($g['behind'] ? 'Behind schedule' : e(fmt($g['remaining'])) . ' to go') ?></p></li><?php endforeach; ?></ul><?php endif; ?>
      <?php card_close(); ?>
    </div>

    <!-- Trend / budget vs actual / obligations -->
    <div class="grid gap-5 md:grid-cols-2 2xl:grid-cols-3">
      <?php card_open('Monthly savings trend', 'Income − expenses'); echo chart_lines(array_column($series, 'label'), [['name' => 'Saved', 'color' => 'lime', 'values' => array_column($series, 'net')]], 200, true); card_close(); ?>
      <?php card_open('Budget vs actual', 'Top categories', '<a href="' . e(url('/expenses')) . '" class="text-xs text-mute underline">Edit</a>'); echo chart_hbars(array_map(fn($b) => ['name' => $b['name'], 'a' => $b['budget'], 'b' => $b['spent']], array_slice($d['bva'], 0, 5)), 'Budget', 'Spent'); card_close(); ?>
      <?php
        $open = array_values(array_filter(occurrence_views($s), fn($o) => !$o['skipped'] && $o['outstanding'] > 0)); $cols = [];
        for ($i = 0; $i < 6; $i++) { $m = add_months($month, $i); $t = 0; foreach ($open as $o) { if ($i === 0 ? month_of($o['due_date']) <= $m : month_of($o['due_date']) === $m) { $t += $o['outstanding']; } } $cols[] = ['label' => substr(month_label($m, true), 0, 3), 'values' => [$t]]; }
        card_open('Upcoming obligations', 'Unpaid, next 6 months', '', 'md:col-span-2 2xl:col-span-1'); echo chart_bars($cols, [['name' => 'Due', 'color' => 'orange']], 200); card_close(); ?>
    </div>
  </div>

  <!-- Right column -->
  <div class="min-w-0 space-y-5">
    <section class="card p-6" aria-label="Accounts">
      <div class="mb-4 flex items-start justify-between"><div><h2 class="text-lg font-semibold tracking-tight">My accounts</h2><p class="text-sm text-mute">Quick actions</p></div><a class="btn btn-sm" href="<?= e(url('/settings')) ?>"><?= icon('plus', 'size-4') ?> Account</a></div>
      <div class="relative overflow-hidden rounded-3xl bg-lime-card p-5 text-ink">
        <div class="hatch-overlay absolute inset-0"></div>
        <div class="relative"><div class="flex items-center justify-between text-xs"><span>Total available balance</span><span class="font-semibold tracking-wider">CASH + BANK</span></div>
          <p class="num mt-6 text-3xl font-semibold tracking-tight"><?= e(fmt($d['cash'])) ?></p>
          <p class="mt-1 text-xs">Spendable <?= e(fmt($d['spendable'])) ?> · Reserved <?= e(fmt($d['reserved'])) ?></p>
          <div class="mt-5 flex items-end justify-between text-xs"><span><?= e($u['name']) ?></span><span><?= e(date_label($s['today'])) ?></span></div></div>
      </div>
      <ul class="mt-3 space-y-1.5 text-sm"><?php foreach (active_accounts($s) as $a): ?><li class="flex justify-between"><span class="text-mute"><?= e($a['name']) ?></span><span class="num font-medium"><?= e(fmt($a['balance'])) ?></span></li><?php endforeach; ?></ul>
      <div class="mt-5 grid grid-cols-5 gap-2 text-center text-xs">
        <?php foreach ([['income', 'Income', 'circle-arrow-down'], ['expense', 'Expense', 'circle-arrow-up'], ['transfer', 'Transfer', 'arrow-left-right']] as $q): ?>
          <div><button type="button" data-tx-new="<?= $q[0] ?>" class="quick" aria-label="<?= $q[1] ?>"><?= icon($q[2], 'size-5') ?></button><span class="mt-1.5 block text-mute"><?= $q[1] ?></span></div><?php endforeach; ?>
        <div><a href="<?= e(url('/bills')) ?>" class="quick" aria-label="Pay a bill"><?= icon('receipt', 'size-5') ?></a><span class="mt-1.5 block text-mute">Pay bill</span></div>
        <div><a href="<?= e(url('/reports')) ?>" class="quick" aria-label="Reports"><?= icon('ellipsis', 'size-5') ?></a><span class="mt-1.5 block text-mute">More</span></div>
      </div>
      <div class="mt-6"><div class="mb-3 flex items-center justify-between"><h3 class="text-sm font-semibold">Quick payment</h3><a href="<?= e(url('/bills')) ?>" class="text-xs text-mute underline">All bills</a></div>
        <?php if (!$d['upcoming']): ?><p class="text-sm text-mute">No bills due soon.</p><?php else: ?>
        <div class="grid grid-cols-3 gap-3"><?php foreach (array_slice($d['upcoming'], 0, 6) as $o): ?>
          <a href="<?= e(url('/bills')) ?>" class="group text-center" title="<?= e($o['bill']['name'] . ' · ' . fmt($o['outstanding'])) ?>"><span class="mx-auto grid size-14 place-items-center rounded-2xl text-lg font-semibold <?= $o['overdue'] ? 'bg-neg/15 text-neg' : 'bg-lime/25' ?> transition group-hover:scale-105"><?= e(mb_strtoupper(mb_substr($o['bill']['name'], 0, 1))) ?></span>
            <span class="mt-1.5 block truncate text-xs"><?= e($o['bill']['name']) ?></span><span class="num block text-[11px] <?= $o['overdue'] ? 'text-neg' : 'text-mute' ?>"><?= $o['overdue'] ? 'Overdue' : e(date_short($o['due_date'])) ?></span></a><?php endforeach; ?></div><?php endif; ?></div>
    </section>

    <?php card_open('Transaction history', '', '<a href="' . e(url('/transactions')) . '" class="text-xs text-mute underline">View all</a>'); echo tx_list($s, $d['recent'], 'No transactions yet. Use Add transaction to record your first one.'); card_close(); ?>
    <?php card_open('Savings milestones'); ?>
      <?php if (!$d['milestones']): ?><p class="text-sm text-mute">Add target dates to goals and purchases to see milestones.</p><?php else: ?>
      <ul class="space-y-4"><?php foreach ($d['milestones'] as $m): ?><li class="text-sm"><div class="mb-1.5 flex justify-between gap-2"><span class="truncate font-medium"><?= e($m['name']) ?></span><span class="num shrink-0 text-xs text-mute"><?= e(date_label($m['date'])) ?></span></div>
        <?= progress($m['percent'], $m['behind'] ? 'orange' : 'lime', $m['name'] . ' progress') ?><p class="mt-1 text-xs text-mute"><?= e(fmt($m['remaining'])) ?> to go<?= $m['behind'] ? ' · behind schedule' : '' ?></p></li><?php endforeach; ?></ul><?php endif; ?>
    <?php card_close(); ?>
  </div>
</div>
</div>
<?php layout_end();
}
